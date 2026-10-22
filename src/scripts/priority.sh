#!/bin/bash
source "$(dirname "${BASH_SOURCE[0]}")/storage/resolve_paths.sh" || { echo 'resolve_paths.sh missing' >&2; exit 1; }
# priority.sh — "Keep the terminal responsive" (docs/specs/RESPONSIVE_PRIORITY.md).
#
# Usage:
#   priority.sh apply    raise the typing path, lower the plugin's background work
#   priority.sh reset    put every process this script may have changed back to nice 0
#   priority.sh auto     apply when the setting responsive_priority is 1; do nothing when it is 0
#   priority.sh status   print JSON: the setting and the processes apply would touch
#
# The script changes only an allow-list of processes. It never touches docker,
# containerd, qemu, libvirt, shfs, mdcmd, kernel threads or any process in a container.
# It is idempotent: a process that is already at the target value is left alone.
#
# Raised (nice -5, ionice best-effort 0), the typing path:
#   nginx, php-fpm, emhttpd, this plugin's ttyd (-i /var/run/aicliterm-<id>.sock)
#   and this plugin's tmux servers (session name aicli-*).
# Lowered (nice 10, ionice best-effort 7), the plugin's background work:
#   the supervisor daemon and its child processes (except the raised ones above).
# Agents stay at nice 0. The generated aicli-run-<id>.sh sets the agent back to nice 0.
#
# Test overrides (environment): AICLI_PRIORITY_PROC (the /proc folder),
# AICLI_PRIORITY_CFG (the plugin cfg file), AICLI_PRIORITY_LOG (the log file).

set -u

PROC="${AICLI_PRIORITY_PROC:-/proc}"
CFG="${AICLI_PRIORITY_CFG:-${PLUGIN_BASE}/unraid-aicliagents.cfg}"
LOG="${AICLI_PRIORITY_LOG:-${MOUNT_ROOT}/debug.log}"

UP_NICE=-5
UP_IONICE=0
DOWN_NICE=10
DOWN_IONICE=7
RESET_NICE=0
RESET_IONICE=4

# Names that this script never touches, even as a child of the supervisor.
DENY_RE='^(docker|dockerd|containerd|containerd-shim|runc|qemu|qemu-system|libvirtd|virtlogd|shfs|mdcmd|emhttp|init|systemd|kthreadd)'

_log() {
    mkdir -p "$(dirname "$LOG")" 2>/dev/null || true
    printf '[%s] [Priority] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" >> "$LOG" 2>/dev/null || true
}

# _setting — print 1 or 0 (the saved value of responsive_priority; default 0).
_setting() {
    local v=""
    [ -r "$CFG" ] && v="$(sed -nE 's/^responsive_priority="?([01])"?[[:space:]]*$/\1/p' "$CFG" 2>/dev/null | tail -n 1)"
    [ "$v" = "1" ] && echo 1 || echo 0
}

# Per-process facts, filled by _read_proc.
P_COMM=""; P_CMD=""; P_PPID=""; P_NICE=""

# _in_container <pid> — true when the process belongs to a container, a VM or another PID namespace.
_in_container() {
    local pid="$1" a b
    if grep -qE 'docker|containerd|libvirt|machine\.slice|lxc|kubepods' "$PROC/$pid/cgroup" 2>/dev/null; then
        return 0
    fi
    a="$(readlink "$PROC/$pid/ns/pid" 2>/dev/null)"
    b="$(readlink "$PROC/1/ns/pid" 2>/dev/null)"
    if [ -n "$a" ] && [ -n "$b" ] && [ "$a" != "$b" ]; then
        return 0
    fi
    return 1
}

# _read_proc <pid> — fill P_*; return 1 when the process is gone, a kernel thread or unreadable.
_read_proc() {
    local pid="$1" stat rest
    local -a f
    P_COMM="$(cat "$PROC/$pid/comm" 2>/dev/null)" || return 1
    [ -n "$P_COMM" ] || return 1
    P_CMD="$(tr '\0' ' ' < "$PROC/$pid/cmdline" 2>/dev/null)"
    P_CMD="${P_CMD% }"
    [ -n "$P_CMD" ] || return 1
    stat="$(cat "$PROC/$pid/stat" 2>/dev/null)" || return 1
    rest="${stat##*) }"
    read -ra f <<< "$rest"
    P_PPID="${f[1]:-}"
    P_NICE="${f[16]:-}"
    case "$P_PPID" in ''|*[!0-9]*) return 1 ;; esac
    case "$P_NICE" in ''|-|*[!0-9-]*) return 1 ;; esac
    return 0
}

# _classify — read P_COMM / P_CMD, print "up", "super" or nothing.
_classify() {
    case "$P_COMM" in
        nginx)
            case "$P_CMD" in "nginx: "*) echo up ;; esac ;;
        php-fpm|php-fpm[0-9]*)
            case "$P_CMD" in "php-fpm: "*) echo up ;; esac ;;
        emhttpd)
            echo up ;;
        ttyd)
            if [[ "$P_CMD" =~ (^|[[:space:]])-i[[:space:]]/var/run/aicliterm-[A-Za-z0-9_-]+\.sock([[:space:]]|$) ]]; then echo up; fi ;;
        "tmux: server")
            if [[ "$P_CMD" =~ [[:space:]]-s[[:space:]]aicli-[A-Za-z0-9_-]+ ]]; then echo up; fi ;;
        bash)
            if [[ "$P_CMD" =~ ^(/bin/)?bash[[:space:]]+[^[:space:]]*/supervisor/aicli-supervisor\.sh([[:space:]]+start)?$ ]]; then echo super; fi ;;
    esac
}

declare -A KIND NICE_OF COMM_OF PPID_OF
UP_PIDS=()
DOWN_PIDS=()

# _scan — fill the arrays above from the proc folder.
_scan() {
    local d pid k
    KIND=(); NICE_OF=(); COMM_OF=(); PPID_OF=(); UP_PIDS=(); DOWN_PIDS=()
    for d in "$PROC"/[0-9]*; do
        pid="${d##*/}"
        case "$pid" in ''|*[!0-9]*) continue ;; esac
        [ "$pid" = "$$" ] && continue
        _read_proc "$pid" || continue
        NICE_OF[$pid]="$P_NICE"; COMM_OF[$pid]="$P_COMM"; PPID_OF[$pid]="$P_PPID"
        k="$(_classify)"
        [ -n "$k" ] || continue
        _in_container "$pid" && continue
        KIND[$pid]="$k"
    done
    # Raised group.
    for pid in "${!KIND[@]}"; do
        [ "${KIND[$pid]}" = "up" ] && UP_PIDS+=("$pid")
    done
    # Lowered group: each supervisor and its descendants, not going into a raised process.
    local -a queue=() next
    local c
    for pid in "${!KIND[@]}"; do
        [ "${KIND[$pid]}" = "super" ] && { DOWN_PIDS+=("$pid"); queue+=("$pid"); }
    done
    local guard=0
    while [ "${#queue[@]}" -gt 0 ] && [ "$guard" -lt 50 ]; do
        guard=$((guard + 1))
        next=()
        for c in "${!PPID_OF[@]}"; do
            local par="${PPID_OF[$c]}" q
            for q in "${queue[@]}"; do
                [ "$par" = "$q" ] || continue
                [ "${KIND[$c]:-}" = "up" ] && break
                [[ "${COMM_OF[$c]}" =~ $DENY_RE ]] && break
                _in_container "$c" && break
                local dup=0 e
                for e in "${DOWN_PIDS[@]}"; do [ "$e" = "$c" ] && dup=1 && break; done
                [ "$dup" = 1 ] && break
                DOWN_PIDS+=("$c"); next+=("$c")
                break
            done
        done
        queue=("${next[@]+"${next[@]}"}")
    done
}

# _tids <pid> — print the thread ids of the process (the pid itself when no task folder).
_tids() {
    local pid="$1" t found=0
    for t in "$PROC/$pid"/task/[0-9]*; do
        [ -e "$t" ] || continue
        printf '%s\n' "${t##*/}"; found=1
    done
    [ "$found" = 1 ] || printf '%s\n' "$pid"
}

CHANGED=0

# _set <pid> <nice> <ionice-prio> — set nice and ionice on every thread of the process.
_set() {
    local pid="$1" n="$2" io="$3" t
    while read -r t; do
        renice -n "$n" -p "$t" >/dev/null 2>&1 || true
        ionice -c2 -n "$io" -p "$t" >/dev/null 2>&1 || true
    done < <(_tids "$pid")
    CHANGED=$((CHANGED + 1))
}

do_apply() {
    local pid raised=0 lowered=0 before
    _scan
    for pid in "${UP_PIDS[@]+"${UP_PIDS[@]}"}"; do
        if [ "${NICE_OF[$pid]}" -gt "$UP_NICE" ]; then
            before="$CHANGED"; _set "$pid" "$UP_NICE" "$UP_IONICE"; raised=$((raised + CHANGED - before))
        fi
    done
    for pid in "${DOWN_PIDS[@]+"${DOWN_PIDS[@]}"}"; do
        if [ "${NICE_OF[$pid]}" -lt "$DOWN_NICE" ]; then
            before="$CHANGED"; _set "$pid" "$DOWN_NICE" "$DOWN_IONICE"; lowered=$((lowered + CHANGED - before))
        fi
    done
    if [ "$CHANGED" -gt 0 ] || [ "${1:-}" = "explicit" ]; then
        _log "apply: raised=$raised lowered=$lowered (nice $UP_NICE for the typing path, nice $DOWN_NICE for plugin background work)"
    fi
}

do_reset() {
    local pid n=0 before
    _scan
    for pid in "${UP_PIDS[@]+"${UP_PIDS[@]}"}"; do
        if [ "${NICE_OF[$pid]}" = "$UP_NICE" ]; then
            before="$CHANGED"; _set "$pid" "$RESET_NICE" "$RESET_IONICE"; n=$((n + CHANGED - before))
        fi
    done
    for pid in "${DOWN_PIDS[@]+"${DOWN_PIDS[@]}"}"; do
        if [ "${NICE_OF[$pid]}" = "$DOWN_NICE" ]; then
            before="$CHANGED"; _set "$pid" "$RESET_NICE" "$RESET_IONICE"; n=$((n + CHANGED - before))
        fi
    done
    _log "reset: restored=$n (nice $RESET_NICE, ionice best-effort $RESET_IONICE)"
}

_json_list() {
    local first=1 pid c
    printf '['
    for pid in "$@"; do
        c="${COMM_OF[$pid]//[^A-Za-z0-9_.: -]/_}"
        [ "$first" = 1 ] || printf ','
        first=0
        printf '{"pid":%s,"comm":"%s","nice":%s}' "$pid" "$c" "${NICE_OF[$pid]}"
    done
    printf ']'
}

do_status() {
    _scan
    printf '{"enabled":%s,"raise":' "$(_setting)"
    _json_list "${UP_PIDS[@]+"${UP_PIDS[@]}"}"
    printf ',"lower":'
    _json_list "${DOWN_PIDS[@]+"${DOWN_PIDS[@]}"}"
    printf '}\n'
}

case "${1:-}" in
    apply)  do_apply explicit ;;
    reset)  do_reset ;;
    auto)   [ "$(_setting)" = "1" ] && do_apply auto ;;
    status) do_status ;;
    *) echo "usage: priority.sh apply|reset|auto|status" >&2; exit 2 ;;
esac
exit 0
