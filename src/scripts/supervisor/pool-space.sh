#!/bin/bash
source "$(dirname "${BASH_SOURCE[0]}")/../storage/resolve_paths.sh" || { echo 'resolve_paths.sh missing' >&2; exit 1; }
# pool-space.sh — warn early when the file system that holds the persistence
# folder (the boot disk) fills up (Forgejo #393,
# docs/specs/HARD_STOP_RESILIENCE.md R9).
#
# Sourced by aicli-supervisor.sh. The supervisor calls _pool_space_tick on
# every tick. The function acts only every 300 seconds:
#   - At or above AICLI_POOL_WARN_PCT (default 85): it sends ONE warning per
#     6 hours that names the free space and the biggest users, and it keeps
#     one Activity tray entry (pool_space_<mount>, status waiting).
#   - Below AICLI_POOL_CLEAR_PCT (default 80): it finishes the tray entry.
#   - Between the two limits: it changes nothing (hysteresis).
# Every step is best effort. The function always returns 0.
#
# Test overrides (environment): AICLI_POOL_PERSIST_PATH, AICLI_POOL_STATE_DIR,
# AICLI_POOL_NOW (epoch seconds), AICLI_POOL_DF_CMD (replaces df),
# AICLI_POOL_WARN_PCT, AICLI_POOL_CLEAR_PCT, AICLI_POOL_INTERVAL, AICLI_BOOT_SPACE_FILE.

_POOL_SPACE_LAST=0   # epoch of the last check in this process

# _pool_space_php <open|refresh|close> <op> <label> — make or finish the tray
# entry. Best effort. Skipped in a sandboxed test run (AICLI_JOBS_DIR set),
# without php, or without the manager include. A test replaces this function.
_pool_space_php() {
    [ -z "${AICLI_JOBS_DIR:-}" ] || return 0
    local inc="${AICLI_MANAGER_PHP:-/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/AICliAgentsManager.php}"
    [ -f "$inc" ] || return 0
    command -v php >/dev/null 2>&1 || return 0
    (
        # Do not pass the supervisor lock to the php child.
        if [ -n "${SUP_LOCK_FD:-}" ]; then
            exec {SUP_LOCK_FD}>&-
        fi
        timeout 20 php -d display_errors=0 -r '
            require_once $argv[1];
            \AICliAgents\Services\EventActor::$override = ["type" => "system"];
            $op = $argv[3];
            if ($argv[2] === "close") {
                \AICliAgents\Services\ActivityService::finish($op);
            } else {
                \AICliAgents\Services\ActivityService::register($op, "storage", $argv[4],
                    ["meta" => ["entity" => "pool/" . $op]]);
                \AICliAgents\Services\ActivityService::wait($op, "Waiting for free space");
            }' -- "$inc" "$1" "$2" "${3:-}" >/dev/null 2>&1
    ) || true
}

# _pool_space_dir_mb <path>... — on-disk size in MB of all given existing
# paths together ("" when none exists or du fails).
_pool_space_dir_mb() {
    local out
    [ "$#" -gt 0 ] || return 0
    out="$(timeout 60 du -smc -- "$@" 2>/dev/null | tail -n 1 | awk '{print $1}')"
    case "$out" in ''|*[!0-9]*) return 0 ;; esac
    printf '%s\n' "$out"
}

# _pool_space_cached_old_mb <persist dir> — MB of old agent versions from the last
# measurement of the Boot disk space card (BOOT_DISK_SPACE.md R1), when that file is
# fresh (under one hour old). Empty when the file is missing, old or damaged. The
# check reads the file with grep and sed, so it needs no jq and no php.
_pool_space_cached_old_mb() {
    local f="${AICLI_BOOT_SPACE_FILE:-${MOUNT_ROOT}/.boot_space.json}" now at b
    [ -f "$f" ] || return 0
    now="${AICLI_POOL_NOW:-$(date +%s)}"
    at="$(grep -o '"measured_at":[0-9]*' "$f" 2>/dev/null | head -n 1 | sed 's/[^0-9]//g')"
    case "$at" in ''|*[!0-9]*) return 0 ;; esac
    [ $(( now - at )) -lt 3600 ] || return 0
    b="$(grep -o '"key":"agents:old"[^}]*' "$f" 2>/dev/null | head -n 1 | grep -o '"bytes":[0-9]*' | head -n 1 | sed 's/[^0-9]//g')"
    case "$b" in ''|*[!0-9]*) return 0 ;; esac
    printf '%s\n' $(( b / 1048576 ))
}

# _pool_space_scan <persist dir> — measure the biggest users. It sets two variables:
#   _POOL_USERS  one line, largest first, like "the plain-folder homes 4200 MB, the home layers 900 MB"
#   _POOL_TOP    the kind of the biggest user: layers | plainhome | oldagents | agents | "" (unknown)
# It reports homes in plain folders and agents in plain folders as two lines (#531).
# When the card's last measurement is fresh, the agents split into current and old versions.
_pool_space_scan() {
    local p="$1" mb lines="" f old plain_ag=0 layer_ag=0 sum_ag cur
    local -a files
    _POOL_USERS=""; _POOL_TOP=""
    if [ -d "$p/_upper" ]; then
        mb="$(_pool_space_dir_mb "$p/_upper")"
        [ -z "$mb" ] || lines+="$mb|the writable layer|layers"$'\n'
    fi
    files=(); for f in "$p"/home_*.sqsh; do [ -e "$f" ] && files+=("$f"); done
    if [ "${#files[@]}" -gt 0 ]; then
        mb="$(_pool_space_dir_mb "${files[@]}")"
        [ -z "$mb" ] || lines+="$mb|the home layers|layers"$'\n'
    fi
    if [ -d "$p/passthrough/homes" ]; then
        mb="$(_pool_space_dir_mb "$p/passthrough/homes")"
        [ -z "$mb" ] || lines+="$mb|the homes in plain folders|plainhome"$'\n'
    fi
    if [ -d "$p/passthrough/agents" ]; then
        mb="$(_pool_space_dir_mb "$p/passthrough/agents")"
        case "$mb" in ''|*[!0-9]*) : ;; *) plain_ag="$mb" ;; esac
    fi
    files=(); for f in "$p"/agent_*.sqsh; do [ -e "$f" ] && files+=("$f"); done
    if [ "${#files[@]}" -gt 0 ]; then
        mb="$(_pool_space_dir_mb "${files[@]}")"
        case "$mb" in ''|*[!0-9]*) : ;; *) layer_ag="$mb" ;; esac
    fi
    sum_ag=$(( plain_ag + layer_ag ))
    old="$(_pool_space_cached_old_mb "$p")"
    if [ -n "$old" ] && [ "$old" -gt 0 ] && [ "$old" -le "$sum_ag" ]; then
        cur=$(( sum_ag - old ))
        [ "$cur" -le 0 ] || lines+="$cur|the current agent versions|agents"$'\n'
        lines+="$old|the old agent versions|oldagents"$'\n'
    else
        [ "$plain_ag" -le 0 ] || lines+="$plain_ag|the agents in plain folders|agents"$'\n'
        [ "$layer_ag" -le 0 ] || lines+="$layer_ag|the agent layers|agents"$'\n'
    fi
    [ -n "$lines" ] || return 0
    lines="$(printf '%s' "$lines" | sort -t'|' -k1,1nr)"
    _POOL_TOP="$(printf '%s\n' "$lines" | head -n 1 | awk -F'|' '{print $3}')"
    _POOL_USERS="$(printf '%s\n' "$lines" | awk -F'|' 'NF==3 { printf "%s%s %s MB", (n++ ? ", " : ""), $2, $1 } END { print "" }')"
}

# _pool_space_users <persist dir> — print the biggest users (see _pool_space_scan).
_pool_space_users() {
    _pool_space_scan "$1"
    printf '%s\n' "$_POOL_USERS"
}

# _pool_space_advice <kind> — the sentence that fits the biggest user, then the pointer to
# the card. The kind comes from _POOL_TOP. Every warning ends with the pointer (#531).
_pool_space_advice() {
    local a
    case "${1:-}" in
        layers)    a="Close all and tidy up merges the layers and frees space." ;;
        plainhome) a="Conversation history and caches use most of it. Move old sessions or clear caches." ;;
        oldagents) a="Remove old agent versions frees space." ;;
        agents)    a="The agents use most of it. Remove old agent versions to free space." ;;
        *)         a="" ;;
    esac
    [ -z "$a" ] || a+=" "
    printf '%sOpen Storage, Boot disk space.' "$a"
}

_pool_space_tick() {
    local persist="${AICLI_POOL_PERSIST_PATH:-}"
    if [ -z "$persist" ]; then
        if declare -f home_persist_path >/dev/null 2>&1; then
            persist="$(home_persist_path 2>/dev/null)"
        fi
        [ -n "$persist" ] || persist="${PLUGIN_BASE}/persistence"
    fi
    local state="${AICLI_POOL_STATE_DIR:-${MOUNT_ROOT}}"
    local interval="${AICLI_POOL_INTERVAL:-300}"
    local warn="${AICLI_POOL_WARN_PCT:-85}" clear="${AICLI_POOL_CLEAR_PCT:-80}"
    local now line size used avail mount pct free_mb safe op marker last_pct users msg label d advice
    now="${AICLI_POOL_NOW:-$(date +%s)}"
    case "$interval" in ''|*[!0-9]*) interval=300 ;; esac
    case "$warn" in ''|*[!0-9]*) warn=85 ;; esac
    case "$clear" in ''|*[!0-9]*) clear=80 ;; esac
    [ $(( now - _POOL_SPACE_LAST )) -ge "$interval" ] || return 0
    _POOL_SPACE_LAST="$now"

    mkdir -p "$state" 2>/dev/null || return 0
    # df -P -m: Filesystem 1M-blocks Used Available Capacity Mounted-on
    if [ -n "${AICLI_POOL_DF_CMD:-}" ]; then
        line="$($AICLI_POOL_DF_CMD "$persist" 2>/dev/null | awk 'NR==2')" || return 0
    else
        line="$(df -P -m "$persist" 2>/dev/null | awk 'NR==2')" || return 0
    fi
    [ -n "$line" ] || return 0
    read -r _ size used avail _ mount <<< "$line"
    case "$size" in ''|*[!0-9]*) return 0 ;; esac
    case "$used" in ''|*[!0-9]*) return 0 ;; esac
    case "$avail" in ''|*[!0-9]*) return 0 ;; esac
    [ "$size" -gt 0 ] || return 0
    free_mb="$avail"
    pct=$(( (used * 100 + size / 2) / size ))
    [ -n "$mount" ] || mount="boot"
    safe="${mount//[^a-zA-Z0-9_-]/_}"
    op="pool_space_${safe}"
    marker="$state/.${op}.tray"

    if [ "$pct" -ge "$warn" ]; then
        _pool_space_scan "$persist"
        users="$_POOL_USERS"
        advice="$(_pool_space_advice "$_POOL_TOP")"
        msg="The boot disk is ${pct}% full and only ${free_mb} MB is free."
        [ -z "$users" ] || msg+=" The biggest users are: ${users}."
        msg+=" ${advice}"
        # One warning per 6 hours. Our own stamp makes this testable; the
        # supervisor rate key is a second guard.
        d="$state/.${op}.notified"
        last_pct=0; [ -f "$d" ] && read -r last_pct < "$d" 2>/dev/null
        case "$last_pct" in ''|*[!0-9]*) last_pct=0 ;; esac
        if [ $(( now - last_pct )) -ge 21600 ]; then
            echo "$now" > "$d" 2>/dev/null || true
            if declare -f _supervisor_notify >/dev/null 2>&1; then
                _supervisor_notify "warning" "AICliAgents: the boot disk is ${pct}% full" "$msg" "$op" 21600 "/AICliAgentsManager#boot-space" || true
            fi
        fi
        # Tray entry: made once, text refreshed when the percent moves 2+ points.
        label="The boot disk is ${pct}% full (${free_mb} MB free)."
        [ -z "$users" ] || label+=" Biggest users: ${users}."
        label+=" ${advice}"
        last_pct=""; [ -f "$marker" ] && read -r last_pct < "$marker" 2>/dev/null
        case "$last_pct" in
            ''|*[!0-9]*)
                echo "$pct" > "$marker" 2>/dev/null || true
                _pool_space_php open "$op" "$label" || true ;;
            *)
                d=$(( pct - last_pct )); [ "$d" -ge 0 ] || d=$(( -d ))
                if [ "$d" -ge 2 ]; then
                    echo "$pct" > "$marker" 2>/dev/null || true
                    _pool_space_php refresh "$op" "$label" || true
                fi ;;
        esac
    elif [ "$pct" -lt "$clear" ]; then
        if [ -f "$marker" ]; then
            rm -f "$marker" 2>/dev/null || true
            rm -f "$state/.${op}.notified" 2>/dev/null || true
            _pool_space_php close "$op" "" || true
        fi
    fi
    return 0
}
