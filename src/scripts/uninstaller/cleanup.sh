#!/bin/bash
# AICliAgents Uninstaller: Clean Removal of Assets & Processes
# v2026.03.17.15 - Aggressive Ghost Hunting

status "Aggressively hunting for legacy AI CLI Agents processes..."

# 1. Kill standalone sync daemons
status "Terminating standalone sync daemons..."
pkill -9 -f "sync-daemon-.*\.sh" || true

# 2. Kill detached background sync subshells (The 'Ghost' sources)
status "Terminating detached background heartbeat loops..."
pkill -9 -f "Periodic sync triggered" || true

# 3. Kill all ttyd processes managing aicli sockets
status "Terminating ttyd listeners..."
pgrep -f "ttyd.*(aicliterm|geminiterm)-" | xargs kill -9 > /dev/null 2>&1 || true

# 4. Kill all AICli tmux sessions
status "Terminating tmux agent sessions..."
if command -v tmux >/dev/null 2>&1; then
    tmux ls -F '#S' 2>/dev/null | grep -E "^aicli-agent-" | xargs -I {} tmux kill-session -t "{}" > /dev/null 2>&1 || true
fi

# 5. Kill orphaned agent node processes
status "Terminating orphaned node binaries..."
pgrep -f "node.*(gemini|opencode|nanocoder|claude|kilo|pi|codex|factory)" | xargs kill -9 > /dev/null 2>&1 || true

status "Cleaning up runtime files and locks..."
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
rm -f /tmp/unraid-aicliagents/sync-daemon-*.sh

status "Removal of runtime assets complete."
