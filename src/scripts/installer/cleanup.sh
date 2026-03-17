#!/bin/bash
# AICliAgents Installer Cleanup: Evict legacy processes before upgrade
# v2026.03.17.17 - Surgical Process Management

status "Evicting legacy AI CLI Agents processes to ensure a clean upgrade..."

# Helper to kill processes safely
safe_kill() {
    local pattern="$1"
    local pids=$(pgrep -f "$pattern" | grep -v "$$")
    if [ -n "$pids" ]; then
        echo "$pids" | xargs -r kill -9 > /dev/null 2>&1 || true
    fi
}

# 1. Kill every variant of sync heartbeat
safe_kill "sync-daemon-.*\.sh"
safe_kill "Periodic sync triggered"

# 2. Kill all terminal listeners
safe_kill "ttyd.*aicliterm-"

# 3. Kill all active agent tmux sessions
if command -v tmux >/dev/null 2>&1; then
    tmux ls -F '#S' 2>/dev/null | grep "^aicli-agent-" | xargs -r -I {} tmux kill-session -t "{}" > /dev/null 2>&1 || true
fi

# 4. Clear runtime locks and temporary scripts
rm -f /tmp/unraid-aicliagents/sync-daemon-*.sh
rm -rf /var/run/aicli-sessions
rm -f /var/run/aicliterm-*.sock

status "System prepared for clean upgrade."
