#!/bin/bash
# shutdown_installs.sh — #824: settle agent installs at array stop / shutdown.
#
# On 2026-10-28 John rebooted prod01 while a Claude Code upgrade was still
# writing its new layer. The stopping event logged "shutdown_complete" 8 s later
# and never looked at the install; the layer was finished 2 s before the forced
# shutdown. Had it come first, the upgrade would have been lost with no word:
# /tmp (and the tray with it) is gone after a reboot.
#
# _shutdown_settle_agent_installs <wait_s> <activity_dir> <state_dir>
#   1. No install worker running -> nothing to do.
#   2. Wait up to <wait_s> for the workers to finish (a bake near its end lands).
#   3. Still running: copy each running install tray entry to
#      <state_dir>/interrupted-<opId>.json (on flash, read back at the next
#      boot by ActivityService), then stop the workers' process groups (TERM,
#      then KILL) so the half-written layer is not left on the flash and the
#      loop mounts can be freed.
# Bounded and best effort: it never fails and never blocks the stop.
# AICLI_INSTALL_WORKER_PATTERN is a test seam (default: install-bg.php).

_si_log() {
    if declare -f lifecycle_log >/dev/null 2>&1; then
        lifecycle_log "$1" "event_stopping" "$2" "$3" 2>/dev/null || true
    fi
}

_si_worker_pids() {
    pgrep -f "${AICLI_INSTALL_WORKER_PATTERN:-scripts/install-bg\.php}" 2>/dev/null | grep -vx "$$" || true
}

_shutdown_settle_agent_installs() {
    local wait_s="${1:-30}" act_dir="${2:-}" state_dir="${3:-}"
    local pids waited=0
    pids="$(_si_worker_pids)"
    [ -z "$pids" ] && return 0

    _si_log info shutdown_install_wait "{\"wait_s\":$wait_s,\"workers\":$(printf '%s\n' "$pids" | grep -c .)}"
    while [ "$waited" -lt "$wait_s" ]; do
        pids="$(_si_worker_pids)"
        [ -z "$pids" ] && break
        sleep 1
        waited=$((waited + 1))
    done
    pids="$(_si_worker_pids)"
    if [ -z "$pids" ]; then
        _si_log info shutdown_install_finished "{\"waited_s\":$waited}"
        return 0
    fi

    # Record each running install so the next boot can say what was stopped.
    local f base saved=0
    if [ -n "$act_dir" ] && [ -n "$state_dir" ]; then
        mkdir -p "$state_dir" 2>/dev/null || true
        for f in "$act_dir"/install_*.json; do
            [ -f "$f" ] || continue
            grep -q '"status": *"running"' "$f" 2>/dev/null || continue
            base="$(basename "$f")"
            if cp -f "$f" "$state_dir/interrupted-$base.tmp" 2>/dev/null \
                && mv -f "$state_dir/interrupted-$base.tmp" "$state_dir/interrupted-$base" 2>/dev/null; then
                saved=$((saved + 1))
            fi
        done
        sync 2>/dev/null || true
    fi

    # Stop the workers' process groups (install-bg.php runs in its own group with
    # its bake children). Never our own group.
    local pid pg own_pg pgs=""
    own_pg="$(ps -o pgid= -p $$ 2>/dev/null | tr -d ' ')"
    for pid in $pids; do
        pg="$(ps -o pgid= -p "$pid" 2>/dev/null | tr -d ' ')"
        [[ "$pg" =~ ^[0-9]+$ ]] && [ "$pg" -gt 1 ] && [ "$pg" != "$own_pg" ] || pg=""
        if [ -n "$pg" ]; then
            kill -TERM -- "-$pg" 2>/dev/null || true
            pgs="$pgs $pg"
        else
            kill -TERM "$pid" 2>/dev/null || true
        fi
    done
    sleep 3
    for pg in $pgs; do kill -KILL -- "-$pg" 2>/dev/null || true; done
    for pid in $(_si_worker_pids); do kill -KILL "$pid" 2>/dev/null || true; done
    _si_log warn shutdown_install_interrupted "{\"waited_s\":$waited,\"recorded\":$saved}"
    return 0
}
