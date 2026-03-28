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

# 0. Clean Shutdown: Checkpoint SQLite databases before killing
# This ensures journals/WAL are merged into the main .db for a clean sync.
if command -v sqlite3 >/dev/null 2>&1; then
    status "Performing clean shutdown for SQLite databases..."
    # Find all agent DBs in RAM work areas
    shopt -s globstar
    for db in /tmp/unraid-aicliagents/work/**/home/.local/share/*/opencode.db /tmp/unraid-aicliagents/work/**/home/.local/share/*/claude-code.db; do
        if [ -f "$db" ]; then
            echo "  > Checkpointing $db..."
            sqlite3 "$db" "PRAGMA wal_checkpoint(TRUNCATE);" > /dev/null 2>&1 || true
        fi
    done
    shopt -u globstar
fi

# 1. Kill every variant of sync heartbeat
safe_kill "sync-daemon-.*\.sh"
safe_kill "Periodic sync triggered"

# 2. Kill all terminal listeners
safe_kill "ttyd.*aicliterm-"

# 3. Kill all active agent tmux sessions & node binaries
if command -v tmux >/dev/null 2>&1; then
    tmux ls -F '#S' 2>/dev/null | grep "^aicli-agent-" | xargs -r -I {} tmux kill-session -t "{}" > /dev/null 2>&1 || true
fi
safe_kill "node.*(gemini|opencode|nanocoder|claude|kilo|pi|codex|factory)"

# 4. Clear runtime locks and temporary scripts
rm -f /tmp/unraid-aicliagents/sync-daemon-*.sh
rm -rf /var/run/aicli-sessions
rm -f /var/run/aicliterm-*.sock

# 5. Final Upgrade Sync: RAM -> Flash
# This preserves the user's latest work session before the plugin files are swapped.
if [ "$UPGRADE_MODE" = "1" ] && [ -f "$CONFIG_DIR/unraid-aicliagents.cfg" ]; then
    status "Backing up latest RAM data to Flash (Final Upgrade Sync)..."
    
    # Robust extraction of USER_NAME
    USER_NAME=$(grep "user=" "$CONFIG_DIR/unraid-aicliagents.cfg" | sed -e 's/user=//' -e 's/"//g' -e "s/'//g")
    [ -z "$USER_NAME" ] && USER_NAME="root"
    
    RAM_HOME="/tmp/unraid-aicliagents/work/$USER_NAME/home"
    PERSIST_HOME="$CONFIG_DIR/persistence/$USER_NAME/home"
    
    if [ -d "$RAM_HOME" ]; then
        mkdir -p "$PERSIST_HOME"
        # D-51/D-52/D-54: Use -L, --size-only and EXCLUDE heavy caches/logs for performance.
        EXCLUDES="--exclude='.npm' --exclude='.bun' --exclude='.cache' --exclude='node_modules' --exclude='*.log' --exclude='log/' --exclude='.opencode/node_modules' --exclude='.opencode/bin'"
        if rsync -avcL --delete --size-only --no-p --no-g --no-o $EXCLUDES "$RAM_HOME/" "$PERSIST_HOME/"; then
             echo "  > RAM backup successful."
        else
             echo "  ! RAM backup failed."
        fi
    fi
fi

status "System prepared for clean upgrade."
