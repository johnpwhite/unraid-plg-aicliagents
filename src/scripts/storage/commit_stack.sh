#!/bin/bash
set -euo pipefail
# AICliAgents: Persistence Bake (ZRAM -> SquashFS)
# Usage: commit_stack.sh <type: agent|home> <id> <persistence_path>

TYPE="${1:-}"
ID="${2:-}"
PERSIST_PATH="${3:-}"

# Source shared storage functions (guard_path, check_disk_space, etc.)
source "$(dirname "$0")/common.sh"

# Source canonical path resolver and lifecycle log writer (Phase 1)
source "$(dirname "$0")/resolve_paths.sh" 2>/dev/null || true

# Source atomic layer writer (Phase 2)
source "$(dirname "$0")/atomic_write_layer.sh" 2>/dev/null || {
    error "atomic_write_layer.sh missing — cannot bake safely"
    exit 1
}

UPPER_DIR="$ZRAM_BASE/${TYPE}s/$ID/upper"
WORK_DIR="$ZRAM_BASE/${TYPE}s/$ID/work"

log() {
    local msg="[$(get_ts)] [INFO] [COMMIT] $1"
    echo "$msg"
    echo "$msg" >> "$DEBUG_LOG"
}
error() {
    local msg="[$(get_ts)] [ERR!] [COMMIT] $1"
    echo "$msg"
    echo "$msg" >> "$DEBUG_LOG"
}

if [ ! -d "$UPPER_DIR" ] || [ -z "$(ls -A "$UPPER_DIR" 2>/dev/null)" ]; then
    log "No changes to commit for $TYPE $ID"
    exit 0
fi

lifecycle_log "info" "commit_stack" "bash_bake_start" "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"persist_path\":\"$PERSIST_PATH\"}" 2>/dev/null || true

# 1. Prune caches before bake
log "Pruning caches before bake..."
# D-317: Remove redundant caches from ZRAM upper layer to minimize Flash footprint
# IMPORTANT: Remove CONTENTS only, not the directory itself. Removing a directory in
# OverlayFS upper creates an opaque whiteout that permanently hides the lower layer's
# directory, preventing agents from creating new cache files after consolidation.
[ -d "$UPPER_DIR/.npm" ] && find "$UPPER_DIR/.npm" -mindepth 1 -delete 2>/dev/null
[ -d "$UPPER_DIR/.cache" ] && find "$UPPER_DIR/.cache" -mindepth 1 -delete 2>/dev/null
[ -d "$UPPER_DIR/tmp" ] && find "$UPPER_DIR/tmp" -mindepth 1 -delete 2>/dev/null

# Validate persistence path before writing
guard_path "$PERSIST_PATH" "PERSIST_PATH" || { error "Persistence path failed validation: $PERSIST_PATH"; exit 1; }

# Check disk space (need at least 100MB free for a delta)
check_disk_space "$PERSIST_PATH/.diskcheck" 100 || { error "Insufficient disk space on $PERSIST_PATH"; exit 1; }

# Record a marker timestamp BEFORE baking. Any writes to the upper dir after this
# point will not be in the delta and must NOT be flushed.
MARKER="/tmp/unraid-aicliagents/.commit_marker_${TYPE}_${ID}"
touch "$MARKER"

# 2. Atomic bake via atomic_write_layer (Phase 2 — closing finding F)
# atomic_write_layer writes to a sibling tempfile, fsyncs, verifies, then renames atomically.
# mksquashfs never writes directly to the final path; a power loss cannot leave a partial layer.
log "Baking changes to $PERSIST_PATH/ (atomic delta)..."
NEW_BASENAME=""
if ! NEW_BASENAME=$(atomic_write_layer "$TYPE" "$ID" "$PERSIST_PATH" "$UPPER_DIR" "delta"); then
    error "Atomic bake failed."
    rm -f "$MARKER"
    lifecycle_log "error" "commit_stack" "bash_bake_failed" "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"persist_path\":\"$PERSIST_PATH\"}" 2>/dev/null || true
    exit 1
fi

# NEW_SQSH is the final path of the just-written layer
NEW_SQSH="$PERSIST_PATH/$NEW_BASENAME"
log "Bake complete: $NEW_BASENAME"

# 3. Check if ZRAM can be safely flushed
MNT_POINT="/usr/local/emhttp/plugins/unraid-aicliagents/agents/$ID"
[ "$TYPE" == "home" ] && MNT_POINT="/tmp/unraid-aicliagents/work/$ID/home"

log "Checking for active sessions on $MNT_POINT..."

# Check 1: Any process has open files on the mounted filesystem
if fuser -sm "$MNT_POINT" 2>/dev/null; then
    log "Mount is BUSY (open files detected). Skipping ZRAM flush."
    log "Data persisted to Flash. ZRAM dirty stats remain until sessions close."
    rm -f "$MARKER"
    SQSH_BYTES=$(stat -c '%s' "$NEW_SQSH" 2>/dev/null || echo 0)
    lifecycle_log "info" "commit_stack" "bash_bake_busy" "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"sqsh\":\"$(basename "$NEW_SQSH")\",\"bytes\":$SQSH_BYTES}" 2>/dev/null || true
    exit 2
fi

# Check 2: Were new writes made to the upper dir DURING the bake?
# If so, those writes are NOT in the delta — flushing would destroy them.
UPPER_CHANGED=$(find "$UPPER_DIR" -newer "$MARKER" -type f 2>/dev/null | head -1)
rm -f "$MARKER"

if [ -n "$UPPER_CHANGED" ]; then
    log "New writes detected in upper layer during bake. Skipping ZRAM flush to preserve data."
    log "Data persisted to Flash. New changes will be captured in next persist cycle."
    SQSH_BYTES=$(stat -c '%s' "$NEW_SQSH" 2>/dev/null || echo 0)
    lifecycle_log "info" "commit_stack" "bash_bake_busy" "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"sqsh\":\"$(basename "$NEW_SQSH")\",\"bytes\":$SQSH_BYTES,\"reason\":\"concurrent_write\"}" 2>/dev/null || true
    exit 2
fi

# Safe to flush: mount is idle and no new writes arrived during the bake
log "Mount is idle, no concurrent writes. Flushing ZRAM upper layer..."
umount "$MNT_POINT" 2>/dev/null || true
find "$UPPER_DIR" -mindepth 1 -delete
find "$WORK_DIR" -mindepth 1 -delete
sync

# 4. Remount Stack (Will pick up new delta)
log "Refreshing mount stack..."
bash "$(dirname "$0")/mount_stack.sh" "$TYPE" "$ID" "$PERSIST_PATH"
SQSH_BYTES=$(stat -c '%s' "$NEW_SQSH" 2>/dev/null || echo 0)
lifecycle_log "info" "commit_stack" "bash_bake_ok" "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"sqsh\":\"$(basename "$NEW_SQSH")\",\"bytes\":$SQSH_BYTES}" 2>/dev/null || true
