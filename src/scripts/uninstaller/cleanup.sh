#!/bin/bash
# AICliAgents Uninstaller: Clean Removal of Assets & Processes

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

log_status "Terminating AI CLI Agents processes..."

# 1. Terminate all ttyd processes managing aicli sockets
log_status "Terminating ttyd listeners..."
graceful_kill "ttyd.*(aicliterm|geminiterm)-"

# 2. Terminate all AICli tmux sessions
log_status "Terminating tmux agent sessions..."
if command -v tmux >/dev/null 2>&1; then
    tmux ls -F '#S' 2>/dev/null | grep -E "^aicli-agent-" | xargs -I {} tmux kill-session -t "{}" > /dev/null 2>&1 || true
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
