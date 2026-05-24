#!/bin/bash
set -euo pipefail
# AICliAgents: OverlayFS Stack Assembly
# Usage: mount_stack.sh <type: agent|home> <id> <persistence_path> [owner]
#
# Bug #1054: optional 4th arg `owner` — for non-root home overlays, chown the
# UPPER_DIR / WORK_DIR / MNT_POINT to this user so OverlayFS writes inherit
# the user's ownership and the agent can actually write to its $HOME. Empty
# OWNER (the default, used by agent mounts) preserves root-write semantics.

TYPE="${1:-}"
ID="${2:-}"
PERSIST_PATH="${3:-}"
OWNER="${4:-}"

# Bug #1054 v.06: for TYPE=home, default OWNER to $ID. Home overlays use the
# username as the ID, so this auto-applies the OWNER chown for every caller
# (commit_stack.sh remount-after-bake, consolidate_layers.sh remount, future
# callers) without each one having to thread the user arg through. Agent
# mounts (TYPE=agent, ID=claude-code etc.) aren't users; the `id "$OWNER"`
# check below filters them out without further handling.
if [ -z "$OWNER" ] && [ "$TYPE" = "home" ]; then
    OWNER="$ID"
fi

PLUGIN_ROOT="/usr/local/emhttp/plugins/unraid-aicliagents"

# Source shared storage functions (guard_path, check_disk_space, etc.)
source "$(dirname "$0")/common.sh"

# Source canonical path resolver and lifecycle log writer (Phase 1)
source "$(dirname "$0")/resolve_paths.sh" 2>/dev/null || true

# Source Phase 4a boot integrity classifier (warn mode -- observation only, no halt)
source "$(dirname "$0")/boot_integrity.sh" 2>/dev/null || true

log() {
    local msg="[$(get_ts)] [INFO] [MOUNT] $1"
    echo "$msg"
    echo "$msg" >> "$DEBUG_LOG"
}
error() {
    local msg="[$(get_ts)] [ERR!] [MOUNT] $1"
    echo "$msg"
    echo "$msg" >> "$DEBUG_LOG"
}

if [ -z "$TYPE" ] || [ -z "$ID" ] || [ -z "$PERSIST_PATH" ]; then
    echo "Usage: $0 <agent|home> <id> <persistence_path>"
    exit 1
fi

# Validate persistence path
guard_path "$PERSIST_PATH" "PERSIST_PATH" || { error "Persistence path failed validation: $PERSIST_PATH"; exit 1; }
_assert_persist_durable "$PERSIST_PATH" || { error "Persistence path is on a non-durable filesystem — mount refused"; exit 1; }

# 1. Detect persistence fstype and derive upper-layer location (#342: auto-detect).
# vfat (USB flash) → ZRAM upper (buffers writes, prevents flash wear).
# Any other durable fstype (ext4/xfs/btrfs/…) → direct disk upper (no RAM cost).
_PERSIST_FSTYPE=$(findmnt --noheadings --output FSTYPE --target "$PERSIST_PATH" 2>/dev/null || echo '')
if [ "$_PERSIST_FSTYPE" = "vfat" ] || [ -z "$_PERSIST_FSTYPE" ]; then
    bash "$PLUGIN_ROOT/src/scripts/storage/initialize_zram.sh" || { error "ZRAM initialization failed"; exit 1; }
    UPPER_DIR="$ZRAM_BASE/${TYPE}s/$ID/upper"
    WORK_DIR="$ZRAM_BASE/${TYPE}s/$ID/work"
else
    UPPER_DIR="$PERSIST_PATH/_upper/${TYPE}s/$ID"
    WORK_DIR="$PERSIST_PATH/_work/${TYPE}s/$ID"
fi

# 2. Define mount point
MNT_POINT="/usr/local/emhttp/plugins/unraid-aicliagents/agents/$ID"
[ "$TYPE" == "home" ] && MNT_POINT="/tmp/unraid-aicliagents/work/$ID/home"

# Remove stale emergency symlink if present (emergency mode leaves a symlink at the mount point)
[ -L "$MNT_POINT" ] && rm -f "$MNT_POINT"
mkdir -p "$UPPER_DIR" "$WORK_DIR" "$MNT_POINT"

# Bug #1054: for non-root home overlays, chown the upperdir + workdir + mount
# point (and its parent) to the agent user. OverlayFS inherits write semantics
# from the upperdir owner — a root-owned upper means the agent gets EACCES on
# every write into the merged view even though `mountpoint -q` reports healthy.
# Empty OWNER (agent overlays) skips this block and preserves root-write.
if [ -n "$OWNER" ] && [ "$OWNER" != "root" ] && id "$OWNER" >/dev/null 2>&1; then
    chown -R "$OWNER" "$UPPER_DIR" "$WORK_DIR" 2>/dev/null || true
    chown "$OWNER" "$MNT_POINT" 2>/dev/null || true
    # Parent of MNT_POINT (e.g. /tmp/unraid-aicliagents/work/<user>/) holds
    # per-session run scripts written by aicli-shell.sh — chown so the agent
    # can mkdir/write alongside its home mount.
    PARENT_DIR="$(dirname "$MNT_POINT")"
    [ -d "$PARENT_DIR" ] && chown "$OWNER" "$PARENT_DIR" 2>/dev/null || true
fi

# 3. Discover Lower Layers (SquashFS volumes)
# Sort newest-first using lexicographic order on the embedded timestamp.
#
# Canonical naming (per spec):
#   delta:        ${TYPE}_${ID}_delta_<dt>.sqsh           dt = YYYYMMDDTHHMMSSZ
#   consolidated: ${TYPE}_${ID}_consolidated_<dt>.sqsh
# Legacy naming (still mounted, never produced by current writer):
#   delta:        ${TYPE}_${ID}_delta_<epoch>.sqsh
#   consolidated: ${TYPE}_${ID}_v<epoch>_vol1.sqsh
#
# Lex sort works across both formats: every legacy epoch from before 2025 starts
# with "1..." (or earlier), every new dt from 2025+ starts with "2..." — so legacy
# layers correctly sort below new ones, matching their chronological order.
shopt -s nullglob
FILES=()
for f in "$PERSIST_PATH"/${TYPE}_${ID}_*.sqsh; do
    [ -e "$f" ] && FILES+=("$f")
done

if [ ${#FILES[@]} -gt 1 ]; then
    IFS=$'\n' FILES=($(for f in "${FILES[@]}"; do
        bname=$(basename "$f" .sqsh)
        # New canonical: ..._delta_<dt> or ..._consolidated_<dt>
        ts=$(echo "$bname" | sed -n 's/.*_\(delta\|consolidated\)_\([0-9]\{8\}T[0-9]\{6\}Z\)$/\2/p')
        # Legacy delta: ..._delta_<epoch>
        [ -z "$ts" ] && ts=$(echo "$bname" | sed -n 's/.*_delta_\([0-9]\{10,\}\)$/\1/p')
        # Legacy consolidated: ..._v<epoch>_vol1
        [ -z "$ts" ] && ts=$(echo "$bname" | sed -n 's/.*_v\([0-9]\{10,\}\)_vol1$/\1/p')
        [ -z "$ts" ] && ts="00000000T000000Z"
        echo "$ts $f"
    done | sort -rk1 | awk '{print $2}'))
    unset IFS
fi
shopt -u nullglob

LOWERS=""
# WP #1084: track loop mounts created during THIS invocation so the overlay-
# mount failure path below can unmount them. Pre-existing loop mounts (skipped
# at line `if ! mountpoint -q`) are NOT in this list — they may belong to a
# concurrent / prior mount_stack run.
_NEW_LOOP_MOUNTS=()
for sqsh in "${FILES[@]}"; do
    # Mount each squashfs to a temporary loop mount if not already done
    SQSH_NAME=$(basename "$sqsh" .sqsh)
    SQSH_MNT="/tmp/unraid-aicliagents/mnt/$SQSH_NAME"
    mkdir -p "$SQSH_MNT"
    if ! mountpoint -q "$SQSH_MNT"; then
        if ! mount -o loop,ro "$sqsh" "$SQSH_MNT"; then
            error "Failed to mount $sqsh"
            # WP #1084: clean up any loop mounts we created earlier in this
            # loop so they don't leak when the layer mount fails mid-stack.
            for _lm in "${_NEW_LOOP_MOUNTS[@]}"; do
                umount "$_lm" 2>/dev/null || umount -l "$_lm" 2>/dev/null || true
            done
            exit 1
        fi
        _NEW_LOOP_MOUNTS+=("$SQSH_MNT")
    fi
    [ -n "$LOWERS" ] && LOWERS="$LOWERS:"
    LOWERS="$LOWERS$SQSH_MNT"
done

# 4. Mount OverlayFS
if [ -z "$LOWERS" ]; then
    # Fresh entity or migration failed. 
    # D-298: If a legacy image or folder still exists, it means migration hasn't finished or failed.
    # We should NOT mount an empty stack in this case.
    LEGACY_FOUND=0
    [ -f "$PERSIST_PATH/aicli-agents.img" ] && LEGACY_FOUND=1
    [ -f "$PERSIST_PATH/persistence/home_$ID.img" ] && LEGACY_FOUND=1
    [ -f "$PERSIST_PATH/home_$ID.img" ] && LEGACY_FOUND=1
    
    # D-342: Check for raw legacy folders to prevent mounting an empty OverlayFS over unmigrated data
    if [ "$TYPE" == "home" ]; then
        [ -d "$PERSIST_PATH/persistence/$ID" ] && LEGACY_FOUND=1
        [ -d "$PERSIST_PATH/$ID" ] && LEGACY_FOUND=1
    fi

    if [ $LEGACY_FOUND -eq 1 ]; then
        error "No SquashFS layers found but legacy data (IMG/Folder) exists. Migration pending or failed."
        exit 1
    fi

    # Phase 4a/4b: classify the empty-glob case before proceeding.
    # In strict mode (boot_integrity_strict=1) halt on non-healthy states.
    # In warn mode (boot_integrity_strict=0) log and proceed (Phase 4a behaviour).
    _INTEGRITY_STATE="genuine_fresh"
    if type boot_integrity_classify >/dev/null 2>&1; then
        _INTEGRITY_STATE="$(boot_integrity_classify "$TYPE" "$ID" 2>/dev/null || echo 'unknown')"
    fi

    # Read strict mode from config (default 1).
    _BOOT_INTEGRITY_STRICT="$(_rp_read_cfg "boot_integrity_strict" 2>/dev/null)"
    _BOOT_INTEGRITY_STRICT="${_BOOT_INTEGRITY_STRICT:-1}"

    case "$_INTEGRITY_STATE" in
        healthy|genuine_fresh)
            log "No lower layers found for ${TYPE} ${ID}. Mounting empty stack (state: ${_INTEGRITY_STATE})."
            ;;
        legacy_unmanaged|path_drift|partial_loss|total_loss|corrupt_layers|host_mismatch)
            if [ "$_BOOT_INTEGRITY_STRICT" = "1" ]; then
                # Write the halt marker (state-only file) so the supervisor and UI pick it up.
                # Atomic temp+rename — concurrent halts (multi-tab triggers) cannot leave a partial file.
                _HALT_PARENT="/tmp/unraid-aicliagents/supervisor/halts/${TYPE}"
                mkdir -p "$_HALT_PARENT" 2>/dev/null
                printf '%s' "$_INTEGRITY_STATE" > "${_HALT_PARENT}/${ID}.tmp.$$" && mv "${_HALT_PARENT}/${ID}.tmp.$$" "${_HALT_PARENT}/${ID}"

                lifecycle_log "critical" "mount_stack" "mount_stack_halted" \
                    "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"state\":\"$_INTEGRITY_STATE\"}" \
                    2>/dev/null || true

                error "Boot integrity: ${_INTEGRITY_STATE} for ${TYPE}/${ID}. Strict mode active -- mount halted. Open Settings > Storage to recover."
                exit 1
            fi
            # Strict disabled: warn and proceed (Phase 4a behaviour).
            error "Boot integrity: ${_INTEGRITY_STATE} for ${TYPE}/${ID}. Mounting empty stack in warn mode (strict disabled)."
            ;;
        untracked)
            # Spec: quarantine + mount empty + warn -- never halt.
            log "Boot integrity: ${_INTEGRITY_STATE} for ${TYPE}/${ID}. Supervisor will attempt recovery. Mounting empty stack."
            ;;
        *)
            # Unknown classification -- conservative halt in strict mode.
            if [ "$_BOOT_INTEGRITY_STRICT" = "1" ]; then
                lifecycle_log "critical" "mount_stack" "mount_stack_halted_unknown" \
                    "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"state\":\"$_INTEGRITY_STATE\"}" \
                    2>/dev/null || true
                error "Boot integrity: unknown state '${_INTEGRITY_STATE}' for ${TYPE}/${ID}. Strict mode active -- halting."
                exit 1
            fi
            log "Boot integrity: ${_INTEGRITY_STATE} for ${TYPE}/${ID}. Mounting empty stack."
            ;;
    esac

    lifecycle_log "warn" "mount_stack" "mount_stack_fresh_install" \
        "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"persist_path\":\"$PERSIST_PATH\",\"integrity_state\":\"$_INTEGRITY_STATE\"}" \
        2>/dev/null || true
    EMPTY_LOWER="/tmp/unraid-aicliagents/mnt/empty"
    mkdir -p "$EMPTY_LOWER"
    LOWERS="$EMPTY_LOWER"
fi

# Unmount if already mounted (defensive)
if mountpoint -q "$MNT_POINT"; then
    umount -l "$MNT_POINT" || true
fi

LAYER_COUNT=$(echo "$LOWERS" | tr ':' '\n' | wc -l)
if mount -t overlay overlay -o lowerdir="$LOWERS",upperdir="$UPPER_DIR",workdir="$WORK_DIR" "$MNT_POINT"; then
    log "Stack mounted at $MNT_POINT (Layers: $LAYER_COUNT)"
    lifecycle_log "info" "mount_stack" "mount_stack_assembled" "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"layer_count\":$LAYER_COUNT,\"mount_point\":\"$MNT_POINT\"}" 2>/dev/null || true
else
    error "Failed to mount OverlayFS stack at $MNT_POINT"
    # WP #1084: overlay mount failed — clean up the loop mounts we just made
    # so they don't leak as orphans pointing at (potentially since-deleted)
    # sqsh files. Pre-existing loop mounts are left alone.
    for _lm in "${_NEW_LOOP_MOUNTS[@]}"; do
        umount "$_lm" 2>/dev/null || umount -l "$_lm" 2>/dev/null || true
    done
    lifecycle_log "error" "mount_stack" "mount_stack_failed_loops_cleaned" "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"new_loops_cleaned\":${#_NEW_LOOP_MOUNTS[@]}}" 2>/dev/null || true
    exit 1
fi
