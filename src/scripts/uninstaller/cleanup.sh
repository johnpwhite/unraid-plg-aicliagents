#!/bin/bash
# AICliAgents uninstaller residual process/runtime sweep.
#
# The primary uninstaller calls stop-plugin.sh first so live home data is baked
# before storage is detached.  This script is the independent belt-and-braces
# sweep: it stops a lost-pidfile supervisor and every remaining plugin runtime
# process before the deployed PHP/source tree can be removed.

EMHTTP_DEST="${EMHTTP_DEST:-/usr/local/emhttp/plugins/unraid-aicliagents}"
RUNTIME_DIR="${AICLI_RUNTIME_DIR:-/tmp/unraid-aicliagents}"
RUN_DIR="${AICLI_RUN_DIR:-/var/run}"
TMP_DIR="${AICLI_TMP_DIR:-/tmp}"
SUPERVISOR_SH="${AICLI_SUPERVISOR_SH:-$EMHTTP_DEST/src/scripts/supervisor/aicli-supervisor.sh}"
SUPERVISOR_PIDFILE="${AICLI_SUPERVISOR_PIDFILE:-$RUN_DIR/aicli-supervisor.pid}"
SUPERVISOR_PATTERN="$SUPERVISOR_SH start"
export TMUX_TMPDIR="${AICLI_TMUX_TMPDIR:-$RUNTIME_DIR/tmux}"

if ! declare -F log_status >/dev/null 2>&1; then
    log_status() { printf '%s\n' "$1"; }
fi

# Never signal hypervisors/init even if a future expression broadens.
_safe_filter_pids() {
    local out="" pid exe
    for pid in $1; do
        [ -n "$pid" ] || continue
        [ "$pid" = "$$" ] && continue
        exe=$(readlink "/proc/$pid/exe" 2>/dev/null || true)
        case "$exe" in
            */qemu*|*/libvirt*|*/virsh|*/kvm|*/systemd|*/init|/sbin/init) continue ;;
        esac
        out+="$pid "
    done
    printf '%s\n' "$out"
}

_matching_pids() {
    local pattern="$1" pids
    pids=$(pgrep -f "$pattern" 2>/dev/null || true)
    _safe_filter_pids "$pids"
}

graceful_kill() {
    local pattern="$1" wait_secs="${2:-2}" pids waited=0
    pids=$(_matching_pids "$pattern")
    [ -n "$pids" ] || return 0
    echo "$pids" | xargs -r kill -TERM >/dev/null 2>&1 || true
    while [ "$waited" -lt "$wait_secs" ]; do
        pids=$(_matching_pids "$pattern")
        [ -z "$pids" ] && return 0
        sleep 1
        waited=$((waited + 1))
    done
    pids=$(_matching_pids "$pattern")
    [ -z "$pids" ] || echo "$pids" | xargs -r kill -KILL >/dev/null 2>&1 || true
}

log_status "Stopping the storage/workspace supervisor..."
# Use the daemon's own bounded stop path first. It validates pidfile ownership,
# stops heartbeat/work children, and releases the singleton lock cleanly.
if [ -f "$SUPERVISOR_SH" ]; then
    bash "$SUPERVISOR_SH" stop 10 >/dev/null 2>&1 || true
fi
# A missing/stale pidfile must not hide a resident daemon. The absolute script
# path plus trailing `start` is deliberately narrow and VM-safe.
graceful_kill "$SUPERVISOR_PATTERN" 3

if [ "${AICLI_CLEANUP_TEST_MODE:-0}" != "1" ]; then
    log_status "Terminating residual AI agent processes..."
    # Retry wrappers first so they cannot respawn an agent during this sweep.
    graceful_kill "$RUNTIME_DIR/.*/aicli-run-[^/]*\\.sh" 1
    graceful_kill "$EMHTTP_DEST/src/scripts/aicli-shell.sh" 1
    graceful_kill 'node .*(unraid-aicliagents|/\.aicli/)' 5
    graceful_kill 'ttyd.*(aicliterm|temp-terminal|geminiterm)-' 2
    graceful_kill 'secret-service-daemon' 2
    graceful_kill 'dbus-daemon .*unraid-aicliagents/secret-service' 2
    graceful_kill "$EMHTTP_DEST/src/scripts/(install-bg|emergency-install-bg|version-check-bg|healthcheck|agentcheck)" 2

    if command -v tmux >/dev/null 2>&1; then
        for sock in "$TMUX_TMPDIR"/tmux-*/default; do
            [ -S "$sock" ] || continue
            tmux -S "$sock" ls -F '#S' 2>/dev/null \
                | grep -E '^aicli-agent-' \
                | xargs -r -I {} tmux -S "$sock" kill-session -t "{}" >/dev/null 2>&1 || true
        done
    fi
fi

# A surviving supervisor would immediately resume PHP calls after source
# deletion. Fail before the caller removes the deployed tree.
if [ -n "$(_matching_pids "$SUPERVISOR_PATTERN")" ]; then
    log_status "ERROR: storage/workspace supervisor is still running; refusing source removal."
    exit 1
fi

log_status "Removing residual runtime files..."
rm -f "$RUN_DIR"/aicliterm-*.sock
rm -f "$RUN_DIR"/unraid-aicliagents-*.pid
rm -f "$RUN_DIR"/unraid-aicliagents-*.lock
rm -f "$RUN_DIR"/unraid-aicliagents-*.chatid
rm -f "$RUN_DIR"/unraid-aicliagents-*.agentid
rm -rf "$RUN_DIR/aicli-sessions"
rm -f "$SUPERVISOR_PIDFILE"
rm -f "$RUN_DIR/aicli-supervisor.tick" "$RUN_DIR/aicli-supervisor.work.json"
rm -f "$TMP_DIR"/aicli-run-*.sh "$TMP_DIR/aicli-install-status" "$TMP_DIR"/ttyd-aicli-*.log

# The engine normally removes this after its residual mount sweep. Standalone
# callers retain it until then when AICLI_CLEANUP_KEEP_RUNTIME=1.
if [ "${AICLI_CLEANUP_KEEP_RUNTIME:-0}" != "1" ]; then
    rm -rf "$RUNTIME_DIR"
fi

log_status "Residual process/runtime sweep complete."
exit 0
