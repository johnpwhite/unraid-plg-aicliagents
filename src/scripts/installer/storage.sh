#!/bin/bash
# AICliAgents Installer: Storage & Persistence Migration

# Restore Cached Agents to RAM
CACHE_DIR="$CONFIG_DIR/pkg-cache"
RAM_BIN="$EMHTTP_DEST/bin"
mkdir -p "$RAM_BIN"
if [ -d "$CACHE_DIR" ]; then
    status "Restoring cached agents to RAM..."
    shopt -s nullglob
    for pkg in "$CACHE_DIR"/*.tar.gz; do
        PKG_NAME=$(basename "$pkg" .tar.gz)
        echo "  > Unpacking $PKG_NAME..."
        tar -xzf "$pkg" -C "$RAM_BIN/" --no-same-owner || echo "    ! Failed to unpack $pkg"
    done
    shopt -u nullglob
fi

status "Initializing configuration and hybrid storage..."
CONFIG_FILE="$CONFIG_DIR/unraid-aicliagents.cfg"
if [ -f "$CONFIG_FILE" ]; then
    # Robust extraction of USER_NAME
    USER_NAME=$(grep "user=" "$CONFIG_FILE" | sed -e 's/user=//' -e 's/"//g' -e "s/'//g")
    [ -z "$USER_NAME" ] && USER_NAME="root"
    echo "  > Target user: $USER_NAME"
    
    # 1. Flash-to-Flash Migration
    LEGACY_HOME="$CONFIG_DIR/home"
    NEW_PERSIST="$CONFIG_DIR/persistence/$USER_NAME/home"
    if [ -d "$LEGACY_HOME" ] && [ "$LEGACY_HOME" != "$NEW_PERSIST" ] && [ "$LEGACY_HOME" != "$NEW_PERSIST/" ]; then
        status "Migrating legacy Home content on Flash to $USER_NAME persistence..."
        mkdir -p "$NEW_PERSIST"
        if rsync -a "$LEGACY_HOME/" "$NEW_PERSIST/"; then
            echo "  > Legacy migration successful. Cleaning up legacy folder..."
            rm -rf "$LEGACY_HOME"
        else
            echo "  ! Legacy migration failed."
        fi
    fi
    
    # 2. Flash-to-RAM Pre-population (FORCED SYNC)
    RAM_WORK_BASE="/tmp/unraid-aicliagents/work"
    RAM_HOME="$RAM_WORK_BASE/$USER_NAME/home"
    if [ -d "$NEW_PERSIST" ]; then
        status "Pre-populating RAM Home for $USER_NAME from persistence..."
        mkdir -p "$RAM_HOME"
        if rsync -a --delete "$NEW_PERSIST/" "$RAM_HOME/"; then
             echo "  > RAM pre-population successful."
        else
             echo "  ! RAM pre-population failed."
        fi
        
        # Ownership
        echo "  > Setting ownership to $USER_NAME:users..."
        chown -R "$USER_NAME":users "$RAM_WORK_BASE" 2>/dev/null || true
        chmod 0755 "$RAM_WORK_BASE" 2>/dev/null || true
        chmod -R 0700 "$RAM_WORK_BASE/$USER_NAME" 2>/dev/null || true
    fi
fi
