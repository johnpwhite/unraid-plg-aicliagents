#!/bin/bash
# AICliAgents Uninstaller: Clean Removal of Assets & Processes

# Bug #1043: route tmux at the plugin-private socket dir (see aicli-shell.sh).
export TMUX_TMPDIR="/tmp/unraid-aicliagents/tmux"

# Safe PID filter — drops any pid whose /proc/PID/exe resolves to a
# hypervisor/init binary. Belt-and-braces guard against a kill pattern
# ever accidentally matching a running VM or system service.
_safe_filter_pids() {
    local out=""
    for pid in $1; do
        [ -z "$pid" ] && continue
        local exe
        exe=$(readlink "/proc/$pid/exe" 2>/dev/null)
        case "$exe" in
            */qemu*|*/libvirt*|*/virsh|*/kvm|*/systemd|*/init|/sbin/init) continue ;;
        esac
        out+="$pid "
    done
    echo "$out"
}
# Graceful process termination: SIGTERM first, wait, then SIGKILL if needed
graceful_kill() {
    local pattern="$1"
    local pids
    pids=$(pgrep -f "$pattern" 2>/dev/null | grep -v "$$" || true)
    pids=$(_safe_filter_pids "$pids")
    if [ -n "$pids" ]; then
        echo "$pids" | xargs -r kill -15 > /dev/null 2>&1 || true
        sleep 2
        pids=$(pgrep -f "$pattern" 2>/dev/null | grep -v "$$" || true)
        pids=$(_safe_filter_pids "$pids")
        if [ -n "$pids" ]; then
            echo "$pids" | xargs -r kill -9 > /dev/null 2>&1 || true
        fi
    fi
}

# R4 (CAPTURE_RESUME_ALL_CLOSE_PATHS): on uninstall/upgrade, capture resume ids
# for every live session BEFORE the graceful_kill/tmux-kill sweep below. Resume
# is moot for a true uninstall but matters for a PLUGIN UPGRADE with live
# sessions (the new version relaunches + resumes from the saved id). HYBRID
# entrypoint: disk fallback for all + best-effort full clean close within budget.
# The kill sweep below is UNCHANGED. Hard-ceilinged + `|| true` — never blocks.
source "/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/storage/capture_resume.sh" 2>/dev/null || true
if declare -f _capture_resume_before_stop >/dev/null 2>&1; then
    log_status "Capturing resume ids before process termination (budget 8s)..."
    _capture_resume_before_stop 8
fi

log_status "Terminating AI CLI Agents processes..."

# 1. Terminate all ttyd processes managing aicli sockets
log_status "Terminating ttyd listeners..."
graceful_kill "ttyd.*(aicliterm|geminiterm)-"

# 2. Terminate all AICli tmux sessions
log_status "Terminating tmux agent sessions..."
if command -v tmux >/dev/null 2>&1; then
    # Non-root audit: iterate every per-uid tmux socket so non-root sessions
    # get killed too.
    for _sock in /tmp/unraid-aicliagents/tmux/tmux-*/default; do
        [ -S "$_sock" ] || continue
        tmux -S "$_sock" ls -F '#S' 2>/dev/null | grep -E "^aicli-agent-" | xargs -r -I {} tmux -S "$_sock" kill-session -t "{}" > /dev/null 2>&1 || true
    done
fi

# 3. Terminate orphaned agent node processes
# Path-anchored pattern: requires a plugin-owned path in the cmdline. The old
# agent-name-only regex (node.*(gemini|claude|...)) could theoretically match
# unrelated host Node services or — in the wild — has correlated with VM
# shutdowns where a cmdline contained "gemini" as a substring. See memory
# feedback_tui_stderr_redirect and the safe_filter above.
log_status "Terminating orphaned node binaries..."
graceful_kill "node .*(unraid-aicliagents|/\\.aicli/)"

log_status "Cleaning up runtime files and locks..."
rm -f /var/run/aicliterm-*.sock
rm -f /var/run/unraid-aicliagents-*.pid
rm -f /var/run/unraid-aicliagents-*.lock
rm -f /var/run/unraid-aicliagents-*.chatid
rm -f /var/run/unraid-aicliagents-*.agentid
rm -rf /var/run/aicli-sessions
rm -f /tmp/aicli-run-*.sh
rm -f /tmp/aicli-install-status
rm -rf /tmp/unraid-aicliagents
rm -f /tmp/ttyd-aicli-*.log

log_status "Removal of runtime assets complete."
