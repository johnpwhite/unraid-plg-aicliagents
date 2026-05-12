#!/bin/bash
set -euo pipefail
# AICliAgents: OverlayFS Stack Assembly
# Usage: mount_stack.sh <type: agent|home> <id> <persistence_path>

TYPE="${1:-}"
ID="${2:-}"
PERSIST_PATH="${3:-}"
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

# 1. Ensure ZRAM is ready
bash "$PLUGIN_ROOT/src/scripts/storage/initialize_zram.sh" || { error "ZRAM initialization failed"; exit 1; }

# 2. Define Paths
MNT_POINT="/usr/local/emhttp/plugins/unraid-aicliagents/agents/$ID"
[ "$TYPE" == "home" ] && MNT_POINT="/tmp/unraid-aicliagents/work/$ID/home"

UPPER_DIR="$ZRAM_BASE/${TYPE}s/$ID/upper"
WORK_DIR="$ZRAM_BASE/${TYPE}s/$ID/work"

# Remove stale emergency symlink if present (emergency mode leaves a symlink at the mount point)
[ -L "$MNT_POINT" ] && rm -f "$MNT_POINT"
mkdir -p "$UPPER_DIR" "$WORK_DIR" "$MNT_POINT"

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
for sqsh in "${FILES[@]}"; do
    # Mount each squashfs to a temporary loop mount if not already done
    SQSH_NAME=$(basename "$sqsh" .sqsh)
    SQSH_MNT="/tmp/unraid-aicliagents/mnt/$SQSH_NAME"
    mkdir -p "$SQSH_MNT"
    if ! mountpoint -q "$SQSH_MNT"; then
        mount -o loop,ro "$sqsh" "$SQSH_MNT" || { error "Failed to mount $sqsh"; continue; }
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
    exit 1
fi
