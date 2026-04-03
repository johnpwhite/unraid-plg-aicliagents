#!/bin/bash
# AICliAgents Installer: Pre-Upgrade Process Eviction & Session Backup

# D-60: Path Readiness Helper (Array state awareness)
is_path_ready() {
    local test_path="$1"
    if [[ "$test_path" != /mnt/* ]]; then return 0; fi
    local mnt_pt=$(echo "$test_path" | cut -d'/' -f3)
    local mdstate=$(grep "mdState=" /var/local/emhttp/var.ini 2>/dev/null | cut -d'=' -f2 | tr -d '"')
    if [[ "$mnt_pt" == "user" ]] || [[ "$mnt_pt" == "user0" ]] || [[ "$mnt_pt" =~ ^disk[0-9]+$ ]]; then
        [ "$mdstate" == "STARTED" ] && return 0 || return 1
    fi
}

# Helper to kill processes safely (suppresses output; errors are non-fatal)
safe_kill() {
    local pattern="$1"
    local pids=$(pgrep -f "$pattern" | grep -v "$$")
    if [ -n "$pids" ]; then
        echo "$pids" | xargs -r kill -9 > /dev/null 2>&1 || true
    fi
}

step "Evicting legacy processes for a clean upgrade..."

# --- 0. SQLite WAL Checkpoint ---
# Merge WAL journals into main .db files before killing agent processes.
if command -v sqlite3 > /dev/null 2>&1; then
    step "Checkpointing active SQLite databases..."
    for db in $(find /tmp/unraid-aicliagents/work -name "*.db" -o -name "*.sqlite" 2>/dev/null); do
        if [ -f "$db" ]; then
            echo "    > $(basename $db)..." >&3
            sqlite3 "$db" "PRAGMA wal_checkpoint(TRUNCATE);" > /dev/null 2>&1 || true
        fi
    done
    ok "Database checkpoint complete."
fi

# --- 1. Kill Sync Heartbeats ---
safe_kill "sync-daemon-.*\.sh"
safe_kill "Periodic sync triggered"

# --- 2. Kill Terminal Listeners (ttyd) ---
safe_kill "ttyd.*aicliterm-"

# --- 3. Kill Active Agent tmux Sessions & Node Binaries ---
if command -v tmux > /dev/null 2>&1; then
    tmux ls -F '#S' 2>/dev/null | grep "^aicli-agent-" | xargs -r -I {} tmux kill-session -t "{}" > /dev/null 2>&1 || true
fi
safe_kill "node.*(gemini|opencode|nanocoder|claude|kilo|pi|codex|factory)"
ok "Active processes terminated."

# --- 4. Clear Runtime Locks & Temporary Scripts ---
rm -f /tmp/unraid-aicliagents/sync-daemon-*.sh
rm -rf /var/run/aicli-sessions
rm -f /var/run/aicliterm-*.sock

# --- 5. Final Upgrade Sync: RAM -> Flash (Multi-User Support) ---
# Preserves all users' latest work sessions before plugin files are swapped.
if [ "$UPGRADE_MODE" = "1" ] && [ -d "/tmp/unraid-aicliagents/work" ]; then
    step "Backing up active RAM sessions to persistence (multi-user sync)..."

    PERSIST_BASE=$(grep "persistence_base=" "$CONFIG_DIR/unraid-aicliagents.cfg" | sed -e 's/persistence_base=//' -e 's/\"//g' -e "s/'//g" 2>/dev/null)
    [ -z "$PERSIST_BASE" ] && PERSIST_BASE="$CONFIG_DIR/persistence"

    SESSION_COUNT=0
    for user_work in /tmp/unraid-aicliagents/work/*; do
        [ -d "$user_work" ] || continue
        USER_NAME=$(basename "$user_work")
        RAM_HOME="$user_work/home"
        PERSIST_HOME="$PERSIST_BASE/$USER_NAME/home"

        if [ -d "$RAM_HOME" ]; then
            SESSION_COUNT=$((SESSION_COUNT + 1))
            echo "    > Syncing $USER_NAME..." >&3
            
            PERSIST_IMG="$PERSIST_BASE/home_$USER_NAME.img"
            # D-106: If we have a Btrfs image, ALWAYS use the delta-sync engine for speed/stability
            if [ -f "$PERSIST_IMG" ] && [ -x "/tmp/aicli-btrfs.sh" ]; then
                echo "      [BTRFS] Delta sync -> $(basename $PERSIST_IMG)" >&3
                bash /tmp/aicli-btrfs.sh sync "$USER_NAME" "$PERSIST_IMG"
            else
                if is_path_ready "$PERSIST_HOME"; then
                    mkdir -p "$PERSIST_HOME"
                    EXCLUDES="--exclude='.npm' --exclude='.bun' --exclude='.cache' --exclude='node_modules' --exclude='.opencode/node_modules' --exclude='.opencode/bin' --exclude='.ssh/agent' --exclude='*.log' --exclude='log/' --exclude='*.sock' --exclude='.sock'"
                    if rsync -avcL --delete --no-p --no-g --no-o $EXCLUDES "$RAM_HOME/" "$PERSIST_HOME/"; then
                        echo "      [OK] rsync backup successful for $USER_NAME." >&3
                    else
                        echo "      [!!] rsync backup failed for $USER_NAME (may be empty/partial)." >&3
                    fi
                else
                    echo "      [SKIP] Persistence path not ready: $PERSIST_HOME" >&3
                fi
            fi
        fi
    done

    if [ "$SESSION_COUNT" -eq 0 ]; then
        ok "No active RAM sessions found. Skipping sync."
    else
        ok "$SESSION_COUNT session(s) backed up to persistence."
    fi
fi

# --- 6. Mass Migration: Directory-based Home -> Btrfs Images (One-Time) ---
if [ "$UPGRADE_MODE" = "1" ] && [ -d "$PERSIST_BASE" ]; then
    MIGRATED=0
    for user_item in "$PERSIST_BASE"/*; do
        if [ -d "$user_item" ] && [[ ! "$user_item" =~ \.img$ ]]; then
            USER_NAME=$(basename "$user_item")
            USER_HOME="$user_item/home"
            TARGET_IMG="$PERSIST_BASE/home_$USER_NAME.img"

            if [ -d "$USER_HOME" ] && [ ! -f "$TARGET_IMG" ]; then
                [ "$MIGRATED" -eq 0 ] && step "Modernizing home storage to Btrfs images..."
                echo "    > Migrating $USER_NAME home..." >&3
                if [ $? -eq 0 ] && [ -f "$TARGET_IMG" ]; then
                    echo "      [OK] $USER_NAME -> $(basename $TARGET_IMG)" >&3
                    # D-110: After migration, run a one-time prune inside the new image
                    bash "/tmp/aicli-btrfs.sh" prune "$USER_NAME" "$TARGET_IMG"
                    # D-105: CRITICAL - DO NOT rm -rf legacy folders from USB during install (causes hang)
                    # Instead, rename them for background cleanup later.
                    mv "$user_item" "$user_item.migrated.$(date +%s)" 2>/dev/null
                    MIGRATED=$((MIGRATED + 1))
                else
                    echo "      [FAIL] Migration failed for $USER_NAME." >&3
                fi
            elif [ -d "$user_item" ] && [ ! -d "$USER_HOME" ]; then
                mv "$user_item" "$user_item.migrated.$(date +%s)" 2>/dev/null
            fi
        fi
    done
    [ "$MIGRATED" -gt 0 ] && ok "$MIGRATED home(s) modernized to Btrfs images."
fi

# --- 6. Runtime Cleanup (D-170) ---
# Remove the legacy .runtime directory inside the /agents mount point (now moved to plugin root)
if [ -d "$EMHTTP_DEST/agents/.runtime" ]; then
    step "Removing legacy runtime from agent storage..."
    rm -rf "$EMHTTP_DEST/agents/.runtime"
fi

ok "Pre-upgrade cleanup complete."
