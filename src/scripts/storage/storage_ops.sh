#!/bin/bash
# storage_ops.sh -- Phase 5: the storage OPERATIONS library.
#
# Home/agent storage op bodies, extracted from the former standalone trio
# (mount_stack.sh / commit_stack.sh / consolidate_layers.sh) into SUBSHELL
# functions so storagectl.sh can dispatch to them in-process. Each op is
# `op_X() ( ... )`: the `( )` keeps the moved body's own `set -euo pipefail`,
# `exit` codes, EXIT traps and `flock` fds isolated -- so the extraction is
# behaviour-preserving (the 36-case L3.5 suite is the proof). The trio files
# become thin shims that exec `storagectl <verb>`.
#
# The shared libs (common.sh / resolve_paths.sh / boot_integrity.sh /
# atomic_write_layer.sh) are sourced by each op's body exactly as the original
# scripts did -- idempotent re-sourcing inside the subshell, with the original
# $(dirname "$0") rewritten to $_SO_DIR (this file's dir == the storage dir).
#
# GENERATED from the trio by gen_storage_ops.py, then curated. Re-run the L3.5
# suite after any edit to an op body.

_SO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")" 2>/dev/null && pwd)"
[ -d "$_SO_DIR" ] || _SO_DIR="/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/storage"

# ---- op_mount  (from mount_stack.sh) ----------------------------
op_mount() (
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
source "$_SO_DIR/common.sh"

# Source canonical path resolver and lifecycle log writer (Phase 1)
source "$_SO_DIR/resolve_paths.sh" 2>/dev/null || true

# Source Phase 4a boot integrity classifier (warn mode -- observation only, no halt)
source "$_SO_DIR/boot_integrity.sh" 2>/dev/null || true

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
_entity_paths "$TYPE" "$ID" "$PERSIST_PATH"   # sets UPPER_DIR/WORK_DIR/MNT_POINT/ENTITY_UPPER_MODE
if [ "$ENTITY_UPPER_MODE" = "zram" ]; then
    bash "$PLUGIN_ROOT/src/scripts/storage/initialize_zram.sh" || { error "ZRAM initialization failed"; exit 1; }
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

# 3. Discover Lower Layers (SquashFS volumes), newest-first.
# Ordering is by the per-entity monotonic seq (PRIMARY) then dt (tiebreak), via the
# single shared discovery helper in common.sh — so naming + sort never diverge
# across the writer, mount_stack and storagectl. The helper handles BOTH the
# seq-bearing canonical names and all legacy formats (legacy = seq 0, sorts below).
# See common.sh `_layer_discover_sorted` / `_layer_sort_key`.
FILES=()
mapfile -t FILES < <(_layer_discover_sorted "$PERSIST_PATH" "$TYPE" "$ID")

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

# WP #1263 (#7): serialise this overlay (re)mount against any other mount op for
# the same entity. The launch path (ensureHomeMounted -> op_mount), the post-bake
# reclaim refresh (op_bake -> op_mount) and the post-consolidate remount
# (op_consolidate -> op_mount) all pass through here, so one flock closes the
# launch-vs-reclaim race that the home_mount_in_use defer leaves open. flock -w 30
# bounds the wait so a wedged holder degrades to "proceed" rather than deadlocking
# a user launch (30s >> any real remount). The fd is held until this op_mount
# subshell exits, covering the umount+mount below. (mount_op_lock_path is from
# common.sh, sourced above.)
exec {_MOUNT_OP_LOCK_FD}>"$(mount_op_lock_path "$TYPE" "$ID")" 2>/dev/null || _MOUNT_OP_LOCK_FD=""
if [ -n "${_MOUNT_OP_LOCK_FD:-}" ]; then
    flock -w 30 "$_MOUNT_OP_LOCK_FD" || log "mount-op lock wait timed out after 30s — proceeding (possible wedged holder)"
fi

# Unmount if already mounted (defensive).
# Try synchronous umount first so upperdir is fully released before the new
# mount claims it. Sharing upperdir between two overlay mounts simultaneously
# causes kernel "undefined behavior" (broken readdir, failed copy-up). Lazy
# umount (-l) is kept as a fallback only when files are still open.
if mountpoint -q "$MNT_POINT"; then
    umount "$MNT_POINT" 2>/dev/null || umount -l "$MNT_POINT" || true
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
)

# ---- op_bake  (from commit_stack.sh) ----------------------------
op_bake() (
set -euo pipefail
# AICliAgents: Persistence Bake (ZRAM -> SquashFS)
# Usage: commit_stack.sh <type: agent|home> <id> <persistence_path>

TYPE="${1:-}"
ID="${2:-}"
PERSIST_PATH="${3:-}"

# Source shared storage functions (guard_path, check_disk_space, etc.)
source "$_SO_DIR/common.sh"

# WP #922: snapshot debug.log to Flash on non-zero exit. Skips on exit 2 (which
# commit_stack.sh uses for "baked but mount busy — ZRAM flush deferred").
install_failure_trap "$TYPE" "$ID" "commit_stack"

# Source canonical path resolver and lifecycle log writer (Phase 1)
source "$_SO_DIR/resolve_paths.sh" 2>/dev/null || true

# Source atomic layer writer (Phase 2)
source "$_SO_DIR/atomic_write_layer.sh" 2>/dev/null || {
    error "atomic_write_layer.sh missing — cannot bake safely"
    exit 1
}

# #342: derive UPPER_DIR from persistence fstype (vfat→ZRAM, else→disk direct).
# Must match the logic in mount_stack.sh so we bake from the correct upper layer.
_entity_paths "$TYPE" "$ID" "$PERSIST_PATH"   # sets UPPER_DIR/WORK_DIR/MNT_POINT/ENTITY_UPPER_MODE

# Bug #716: per-entity bake flock — serialise concurrent bakes of the same entity.
# All bake paths (InstallerService::commitChanges, supervisor _op_bake,
# event/stopping direct bake, installer/cleanup.sh pre-upgrade bake) funnel
# through this script, so the lock here covers every caller.
# Sanitise $ID to keep the lock filename shell-safe (alphanumeric + hyphen).
_LOCK_ID="${ID//[^a-zA-Z0-9_-]/_}"
_BAKE_LOCK="/var/run/aicli-bake-${TYPE}-${_LOCK_ID}.lock"
exec 9>"$_BAKE_LOCK"
if ! flock -n 9; then
    # Another bake for this entity is already running.  The in-progress bake
    # will capture the current upper-dir state when it finishes, so there is
    # nothing to do here.  Exit 0 so the caller doesn't treat this as an error.
    echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ 2>/dev/null || date)] [INFO] [COMMIT] bake for $TYPE/$ID already in progress — skipping; its changes will be captured by the in-progress bake"
    # Write lifecycle entry without sourcing common.sh (may not be available yet)
    lifecycle_log "info" "commit_stack" "bake_skipped_concurrent" "{\"type\":\"$TYPE\",\"id\":\"$ID\"}" 2>/dev/null || true
    exit 0
fi
# Lock is held on fd 9 for the remainder of the script (bake + remount +
# post-bake consolidate-threshold check).  The kernel releases it on exit
# (clean or crash) so there is no risk of a stale-lock deadlock.

# WP #1078: clear any stale defer-reason marker from a prior run so the
# reason read by TaskService.php / StorageMountService.php is THIS bake's
# truth, never a prior cycle's. Cheap rm at start; each exit-2 path writes
# the current cause via write_defer_reason; exit-0 leaves no marker.
rm -f "/tmp/unraid-aicliagents/.bake_defer_reason_${TYPE}_${_LOCK_ID}" 2>/dev/null || true

# WP #1277 (bake-confirmed reclaim): the layer writer records the files it
# actually captured into this manifest; we map them onto UPPER_DIR below and
# pass them to selective_upper_cleanup so the post-bake reclaim only wipes
# proven-baked files. Stable per-entity path (overwritten each bake); cleared
# now so a prior run's list can never be mistaken for this bake's truth.
_BAKE_MANIFEST="/tmp/unraid-aicliagents/.bake_manifest_${TYPE}_${_LOCK_ID}"
rm -f "$_BAKE_MANIFEST" "${_BAKE_MANIFEST}.abs" 2>/dev/null || true

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

lifecycle_log "info" "commit_stack" "bash_bake_start" "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"persist_path\":\"$PERSIST_PATH\"}" 2>/dev/null || true

# Busy-bake cooldown gate (home only). When a live session keeps the home busy,
# the post-bake reclaim defers (home_mount_in_use) so the ZRAM upper is never
# trimmed. Without this gate, every bake trigger (StorageMountService config
# reads, scheduled ticks, etc.) would re-bake the WHOLE untrimmed upper while a
# session is open -> redundant Flash writes + delta-layer bloat. So while busy we
# persist at most once per cooldown (crash-safety) and skip redundant bakes in
# between. Idle bakes are NEVER gated -- they reclaim and trim, clearing the
# marker. Override via AICLI_BUSY_BAKE_COOLDOWN_SEC (tests / tuning).
if [ "$TYPE" = "home" ] && home_mount_in_use "$MNT_POINT"; then
    _BUSY_COOLDOWN_SEC="${AICLI_BUSY_BAKE_COOLDOWN_SEC:-1800}"
    _COOLDOWN_MARKER="/tmp/unraid-aicliagents/.bake_busy_cooldown_${TYPE}_${_LOCK_ID}"
    _last_busy_bake=0
    [ -f "$_COOLDOWN_MARKER" ] && _last_busy_bake=$(cat "$_COOLDOWN_MARKER" 2>/dev/null || echo 0)
    case "$_last_busy_bake" in ''|*[!0-9]*) _last_busy_bake=0 ;; esac
    _now_cd=$(date +%s)
    if [ $(( _now_cd - _last_busy_bake )) -lt "$_BUSY_COOLDOWN_SEC" ]; then
        log "Home busy and last persist within ${_BUSY_COOLDOWN_SEC}s cooldown — skipping redundant bake (data already persisted; reclaim deferred to idle)."
        lifecycle_log "info" "commit_stack" "bash_bake_busy_cooldown" "{\"type\":\"$TYPE\",\"id\":\"$ID\"}" 2>/dev/null || true
        write_defer_reason "$TYPE" "$ID" "busy_cooldown"
        exit 2
    fi
fi

# 1. Prune caches before bake (H: emptiness check MUST come after this prune so a
#    prune that empties the upper correctly short-circuits the bake).
log "Pruning caches before bake..."
# D-317: Remove redundant caches from ZRAM upper layer to minimize Flash footprint
# IMPORTANT: Remove CONTENTS only, not the directory itself. Removing a directory in
# OverlayFS upper creates an opaque whiteout that permanently hides the lower layer's
# directory, preventing agents from creating new cache files after consolidation.
[ -d "$UPPER_DIR/.npm" ] && find "$UPPER_DIR/.npm" -mindepth 1 -delete 2>/dev/null
[ -d "$UPPER_DIR/.cache" ] && find "$UPPER_DIR/.cache" -mindepth 1 -delete 2>/dev/null
[ -d "$UPPER_DIR/tmp" ] && find "$UPPER_DIR/tmp" -mindepth 1 -delete 2>/dev/null

# WP #748 Phase 1 (F): expand pre-bake prune to additional regenerable dirs in home overlay.
# Only applies to home bakes — agent overlays don't have these dirs.
# HARD CONSTRAINT: never touch snap_*/migrated_legacy_data/*backup*/SAFE_BACKUP — those are
# user-owned recovery artefacts managed by the storage-tab UI, not ours to delete.
if [ "$TYPE" = "home" ]; then
    [ -d "$UPPER_DIR/.bun/install" ] && rm -rf "$UPPER_DIR/.bun/install" 2>/dev/null || true
    # WP #931: do NOT prune $UPPER_DIR/.gemini/tmp — it contains gemini-cli's
    # project-scoped session chat logs (.gemini/tmp/<projectId>/chats/session-*.jsonl),
    # which are durable user state, not regenerable cache. Previously this line
    # silently wiped users' chat histories on every consolidate. If gemini-cli
    # ever moves the chat logs out of tmp/, revisit.
    [ -d "$UPPER_DIR/.claude/cache" ] && rm -rf "$UPPER_DIR/.claude/cache" 2>/dev/null || true
    [ -d "$UPPER_DIR/.claude/shell-snapshots" ] && rm -rf "$UPPER_DIR/.claude/shell-snapshots" 2>/dev/null || true
    [ -d "$UPPER_DIR/.claude/telemetry" ] && rm -rf "$UPPER_DIR/.claude/telemetry" 2>/dev/null || true
fi

# H: Skip bake entirely if the upper is empty (or became empty after prune).
# This prevents writing a zero-content delta to Flash.
if [ ! -d "$UPPER_DIR" ] || [ -z "$(ls -A "$UPPER_DIR" 2>/dev/null)" ]; then
    log "No changes to commit for $TYPE $ID (upper empty after prune)"
    lifecycle_log "info" "commit_stack" "bash_bake_skipped_empty" "{\"type\":\"$TYPE\",\"id\":\"$ID\"}" 2>/dev/null || true
    exit 0
fi

# Validate persistence path before writing
guard_path "$PERSIST_PATH" "PERSIST_PATH" || { error "Persistence path failed validation: $PERSIST_PATH"; exit 1; }
_assert_persist_durable "$PERSIST_PATH" || { error "Persistence path is on a non-durable filesystem — bake refused"; exit 1; }

# Check disk space (need at least 100MB free for a delta)
check_disk_space "$PERSIST_PATH/.diskcheck" 100 || { error "Insufficient disk space on $PERSIST_PATH"; exit 1; }

# Record a marker timestamp BEFORE baking. Any writes to the upper dir after this
# point will not be in the delta and must NOT be flushed.
MARKER="/tmp/unraid-aicliagents/.commit_marker_${TYPE}_${ID}"
touch "$MARKER"
# M2 fix (v2026.05.18.08): 50ms gap separates the marker mtime from any
# write that could land at exactly the same tmpfs nanosecond timestamp.
# selective_upper_cleanup uses `find ! -newer $marker` which is mtime <= marker
# (inclusive). A write happening in the same nanosecond as `touch` would
# otherwise be admitted to the wipe set despite not necessarily being in the
# bake. The cost (50ms) is imperceptible relative to a bake (seconds).
sleep 0.05

# WP #935: detect SQLite DBs in UPPER and back them up via Online Backup API
# BEFORE the bake. SQLite's .backup is safe against concurrent writers
# (page-version-counter detection); the .backup output replaces the live DB
# via an overlay merge for the bake (see WP #1078). WAL/SHM/journal sidecars
# are excluded — SQLite reconstitutes them from the .db on next open.
#
# WP #1078 (2026-05-24): replaces the prior two-pass `wide-bake + mksquashfs
# -append <sqlite-stage>` protocol. mksquashfs's append mode does NOT merge
# new content into existing directories — when the source dir (sqlite stage)
# has top-level entries that already exist in the target squashfs, mksquashfs
# RENAMES the new ones with _N suffix (.copilot/ → .copilot_1/). The SQLite
# backups landed at unreachable paths, silently lost on next mount. The
# byte-growth defence-in-depth check (8b4c397) caught only the subset
# where the appended content was small enough that 4 KB block alignment
# masked the growth — when the append "succeeded" with bigger content, the
# DBs were stranded at .copilot_1/session-store.db etc., usable by nothing.
# New path: overlay-merge (lower=UPPER_DIR, upper=sqlite_stage) → single bake.
SQLITE_DBS=$(detect_sqlite_dbs "$UPPER_DIR" 2>/dev/null)
# Count non-empty lines. Previous `echo | grep -c -v` produced "0\n0" when
# SQLITE_DBS was empty (grep -c outputs "0" AND returns exit 1, triggering the
# `|| echo 0`), breaking the integer comparison below with a benign-but-noisy
# `[: integer expected` stderr warning. awk handles empty input cleanly.
SQLITE_DB_COUNT=$(printf '%s\n' "$SQLITE_DBS" | awk 'NF{c++}END{print c+0}')
SQLITE_STAGE=""

if [ "$SQLITE_DB_COUNT" -gt 0 ]; then
    log "Detected $SQLITE_DB_COUNT SQLite DB(s) in upper — backing up via Online Backup API"
    SQLITE_STAGE="/tmp/unraid-aicliagents/.sqlite_stage_${TYPE}_${ID}_$$"
    rm -rf "$SQLITE_STAGE" 2>/dev/null
    mkdir -p "$SQLITE_STAGE"

    # shellcheck disable=SC2086 — intentional word-splitting on the path list
    sqlite_backup_all "$UPPER_DIR" "$SQLITE_STAGE" $SQLITE_DBS
    _sba_rc=$?
    if [ "$_sba_rc" -ne 0 ]; then
        rm -rf "$SQLITE_STAGE" 2>/dev/null
        rm -f "$MARKER"
        # M3 fix (v2026.05.18.08): distinguish hard error (return 1 — staging
        # mkdir failed, sqlite3 missing, permission denied) from defer-eligible
        # (return 2 — DB locked, backup timeout). The previous code conflated
        # both as exit 2, leaving a hard error to defer indefinitely while
        # UPPER accumulated unbaked data — a power cycle during the stuck-defer
        # window would lose it all.
        if [ "$_sba_rc" -eq 1 ]; then
            error "SQLite backup hard error (mkdir / sqlite3 / permission) — failing bake"
            lifecycle_log "error" "commit_stack" "bash_bake_failed" \
                "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"reason\":\"sqlite_backup_hard_error\",\"db_count\":$SQLITE_DB_COUNT}" 2>/dev/null || true
            exit 1
        fi
        log "SQLite backup deferred (DB locked or backup timeout) — exiting 2 to retry"
        lifecycle_log "info" "commit_stack" "bash_bake_deferred" \
            "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"reason\":\"sqlite_backup_failed\",\"db_count\":$SQLITE_DB_COUNT}" 2>/dev/null || true
        # WP #1078: distinguish defer reasons for the UI (see TaskService.php).
        write_defer_reason "$TYPE" "$ID" "sqlite_backup_deferred"
        exit 2
    fi
fi

# 2. Atomic bake. Two paths:
#   (a) SQLite DBs detected → overlay-merge bake (single pass; sqlite_stage
#       shadows the live DBs in UPPER_DIR; WAL/SHM/journal excluded).
#   (b) No SQLite DBs → direct bake of UPPER_DIR.
# Apps continue writing freely; the bake reads at file level via the merged FS.
_AWL_DEFAULT_ARGS="-comp xz -Xbcj x86 -Xdict-size 100% -b 1M -no-exports -noappend"

# WP #1277: ask the layer writer (both the direct and overlay-merge paths run
# atomic_write_layer as a sourced function in THIS process) to record the files
# it captures. A plain shell var suffices — the command-substitution subshells
# inherit it without an export, and it does not leak into mksquashfs's env.
AICLI_BAKE_MANIFEST_OUT="$_BAKE_MANIFEST"

NEW_BASENAME=""
if [ "$SQLITE_DB_COUNT" -gt 0 ] && [ -n "$SQLITE_STAGE" ] && [ -d "$SQLITE_STAGE" ]; then
    log "Baking changes to $PERSIST_PATH/ (atomic delta, overlay-merge with $SQLITE_DB_COUNT SQLite backup(s))..."
    # shellcheck disable=SC2086 — intentional word-splitting on the DB path list
    if ! NEW_BASENAME=$(bake_via_overlay_merge "$TYPE" "$ID" "$PERSIST_PATH" "$UPPER_DIR" "$SQLITE_STAGE" "delta" $SQLITE_DBS); then
        error "Overlay-merge bake failed."
        rm -rf "$SQLITE_STAGE" 2>/dev/null
        rm -f "$MARKER"
        lifecycle_log "error" "commit_stack" "bash_bake_failed" "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"persist_path\":\"$PERSIST_PATH\",\"reason\":\"overlay_merge_bake_failed\"}" 2>/dev/null || true
        exit 1
    fi
    rm -rf "$SQLITE_STAGE" 2>/dev/null
    lifecycle_log "info" "commit_stack" "bash_bake_sqlite_merged" \
        "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"sqsh\":\"$NEW_BASENAME\",\"db_count\":$SQLITE_DB_COUNT}" 2>/dev/null || true
else
    log "Baking changes to $PERSIST_PATH/ (atomic delta, no SQLite content)..."
    if ! NEW_BASENAME=$(atomic_write_layer "$TYPE" "$ID" "$PERSIST_PATH" "$UPPER_DIR" "delta"); then
        error "Atomic bake failed."
        rm -f "$MARKER"
        lifecycle_log "error" "commit_stack" "bash_bake_failed" "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"persist_path\":\"$PERSIST_PATH\"}" 2>/dev/null || true
        exit 1
    fi
fi

# NEW_SQSH is the final path of the just-written layer
NEW_SQSH="$PERSIST_PATH/$NEW_BASENAME"
log "Bake complete: $NEW_BASENAME"

# 3. Check if ZRAM can be safely flushed
MNT_POINT="/usr/local/emhttp/plugins/unraid-aicliagents/agents/$ID"
[ "$TYPE" == "home" ] && MNT_POINT="/tmp/unraid-aicliagents/work/$ID/home"

log "Checking for active sessions on $MNT_POINT..."

# WP #1081 (2026-05-24): SINGLE fuser check immediately before any destructive op,
# then refresh FIRST and cleanup SECOND. The prior structure (WP #1080) added a
# second fuser check before the refresh but left selective_upper_cleanup BETWEEN
# the two checks — a 10-second window during which an agent could launch, then
# the cleanup would remove a file from upper that was in the new lower (but the
# mount wasn't yet refreshed to expose the new lower), causing the file to
# briefly vanish from the agent's view. Restructuring so cleanup runs AFTER
# refresh closes that race: removing files from upper after refresh is safe
# because the new lower (with those files) is exposed.
#
# Why refresh-first is safe: the bake already wrote the new sqsh atomically via
# atomic_write_layer.sh (tempfile + fsync + verify + rename). UPPER is unchanged
# by the bake. Refreshing the mount picks up the new lower without touching
# upper. Then cleanup runs on a mount where the new lower is exposed, so any
# file removed from upper falls through to the new lower (correct content).
#
# Residual race: the umount-remount inside mount_stack.sh has a brief window
# (microseconds) where the mountpoint is unmounted. An agent that launches in
# that exact window still dies with ENOENT. The fuser check below is the
# narrow-window mitigation. A full fix would require per-entity locks that
# agent launches respect; deferred as a future hardening pass.
SQSH_BYTES=$(stat -c '%s' "$NEW_SQSH" 2>/dev/null || echo 0)
# WP #1253-follow-up (ENOENT race): use home_mount_in_use, NOT a bare fuser test.
# fuser only sees open fds / cwd / exe on the fs; agent sessions hold HOME=<mount>
# as an env var with cwd in the workspace, touching HOME transiently — so fuser
# reports the home mount idle while a session is live, the refresh umount/remounts
# under it, and the agent's next HOME write hits the unmounted window (ENOENT,
# e.g. claude's mkdir ~/.claude/session-env/<uuid>). home_mount_in_use detects a
# live interactive session (a ttyd carrying AICLI_HOME=<mount>) so the refresh
# DEFERS while a user is connected and reclaim only runs when the home is idle.
# (It deliberately does NOT block on the plugin's permanent HOME=<mount> daemons —
# session dbus / secret-service — which would otherwise defer reclaim forever.)
# The bake above already persisted to Flash, so deferring loses no data — only
# ZRAM reclamation waits.
if home_mount_in_use "$MNT_POINT"; then
    log "Mount is BUSY (open fd or live HOME=$MNT_POINT session). Deferring refresh + ZRAM cleanup."
    log "Data persisted to Flash. ZRAM dirty stats remain until sessions close."
    rm -f "$MARKER"
    # Stamp the busy-bake cooldown: while a session keeps the home busy, the
    # upper is never trimmed (reclaim deferred), so without this every bake
    # trigger would re-bake the whole untrimmed upper. The pre-bake gate near
    # the top skips redundant bakes until this cooldown elapses. (home only.)
    [ "$TYPE" = "home" ] && date +%s > "/tmp/unraid-aicliagents/.bake_busy_cooldown_${TYPE}_${_LOCK_ID}" 2>/dev/null || true
    lifecycle_log "info" "commit_stack" "bash_bake_busy" "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"sqsh\":\"$(basename "$NEW_SQSH")\",\"bytes\":$SQSH_BYTES}" 2>/dev/null || true
    write_defer_reason "$TYPE" "$ID" "mount_busy"
    exit 2
fi

# Refresh mount stack to pick up the new lower layer. Done BEFORE selective
# cleanup so the new lower is exposed when we start removing files from upper.
log "Refreshing mount stack..."
op_mount "$TYPE" "$ID" "$PERSIST_PATH"

# WP #935: Selective UPPER cleanup — now safe to run because the refresh above
# exposed the new lower. Per-file: a file is wiped only if (a) its mtime is not
# newer than the marker AND (b) no process holds an open write fd to it. Bytes
# that satisfy the invariant are reclaimed; the rest stay in ZRAM safely.
log "Performing selective ZRAM cleanup (per-file mtime + open-fd + bake-confirmed invariant)..."
# WP #1277: build the confirmed-baked manifest of ABSOLUTE upper paths from the
# writer's relative file list, then pass it as the third arg so the reclaim only
# wipes files PROVEN in this layer. The manifest is passed UNCONDITIONALLY (even
# if empty) — never fall back to the legacy candidates−excludes wipe, which is
# the aggressive behaviour that risked loss. An empty manifest => wipe nothing
# (the upper is simply not trimmed this cycle; a later bake reclaims it).
CONFIRMED_MANIFEST="${_BAKE_MANIFEST}.abs"
: > "$CONFIRMED_MANIFEST"
if [ -s "$_BAKE_MANIFEST" ]; then
    while IFS= read -r _rel; do
        [ -n "$_rel" ] && printf '%s\n' "$UPPER_DIR/$_rel"
    done < "$_BAKE_MANIFEST" >> "$CONFIRMED_MANIFEST"
fi
CLEANUP_JSON=$(selective_upper_cleanup "$UPPER_DIR" "$MARKER" "$CONFIRMED_MANIFEST")
rm -f "$MARKER" "$_BAKE_MANIFEST" "$CONFIRMED_MANIFEST" 2>/dev/null || true
# Idle reclaim succeeded — the upper is trimmed, so clear any busy-bake cooldown
# left over from a prior in-session bake. The next session starts fresh.
[ "$TYPE" = "home" ] && rm -f "/tmp/unraid-aicliagents/.bake_busy_cooldown_${TYPE}_${_LOCK_ID}" 2>/dev/null || true

# WP #1081: WORK_DIR wipe REMOVED. Previously this swept overlayfs whiteout/work
# files after cleanup. But under the refresh-then-cleanup order, the overlay is
# already remounted with a live kernel fd on $WORK_DIR/work; wiping that subdir
# from userspace mid-mount breaks copy-up (smoke A10 reproduced: fopen on a new
# file in the merged view returned ENOENT until the next mount cycle). The wipe
# was purely cosmetic kernel-scratch reclamation — overlayfs clears workdir
# contents on its OWN mount, so leftover bytes don't accumulate across cycles.
sync

# Inline the selective-cleanup stats into the bake_ok event for observability.
# CLEANUP_JSON is a JSON object; splice into the outer event.
lifecycle_log "info" "commit_stack" "bash_bake_ok" \
    "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"sqsh\":\"$(basename "$NEW_SQSH")\",\"bytes\":$SQSH_BYTES,\"cleanup\":$CLEANUP_JSON}" 2>/dev/null || true
)

# ---- op_consolidate  (from consolidate_layers.sh) ---------------
op_consolidate() (
set -euo pipefail
# AICliAgents: Storage Consolidation & Volume Splitting
# Usage: consolidate_layers.sh <type: agent|home> <id> <persistence_path>

TYPE="${1:-}"
ID="${2:-}"
PERSIST_PATH="${3:-}"
MNT_POINT="/usr/local/emhttp/plugins/unraid-aicliagents/agents/$ID"

# Source shared storage functions (guard_path, check_disk_space, etc.)
source "$_SO_DIR/common.sh"

# WP #922: snapshot debug.log to Flash on non-zero exit. Survives /tmp rotation
# so the next investigator has actual evidence. Skips on exit 2 (deferred).
install_failure_trap "$TYPE" "$ID" "consolidate_layers"

# Source canonical path resolver and lifecycle log writer (Phase 1)
source "$_SO_DIR/resolve_paths.sh" 2>/dev/null || true

# Source atomic layer writer (Phase 2)
source "$_SO_DIR/atomic_write_layer.sh" 2>/dev/null || {
    echo "[CONSOLIDATE] FATAL: atomic_write_layer.sh missing — cannot consolidate safely" >&2
    exit 1
}

[ "$TYPE" == "home" ] && MNT_POINT="/tmp/unraid-aicliagents/work/$ID/home"
TASK_STATUS_FILE="/tmp/unraid-aicliagents/task-status-$ID"

# #342: derive UPPER_DIR from persistence fstype — must match mount_stack.sh.
_entity_paths "$TYPE" "$ID" "$PERSIST_PATH"   # sets UPPER_DIR/WORK_DIR/MNT_POINT/ENTITY_UPPER_MODE

log() {
    local msg="[$(get_ts)] [INFO] [CONSOLIDATE] $1"
    echo "$msg"
    echo "$msg" >> "$DEBUG_LOG"
}
error() {
    local msg="[$(get_ts)] [ERR!] [CONSOLIDATE] $1"
    echo "$msg"
    echo "$msg" >> "$DEBUG_LOG"
}

# D-280: Task Status Updater for Frontend Progress Bars
update_task_status() {
    local step="$1"
    local progress="$2"
    local reason="${3:-}"
    # Sanitize step/reason to prevent JSON injection (strip quotes and backslashes)
    step="${step//\"/}"
    step="${step//\\/}"
    reason="${reason//\"/}"
    reason="${reason//\\/}"
    local completed="false"
    [ "$progress" -ge 100 ] && completed="true"
    printf '{"step":"%s","progress":%d,"completed":%s,"timestamp":%d,"reason":"%s"}' \
        "$step" "$progress" "$completed" "$(date +%s)" "$reason" > "$TASK_STATUS_FILE"
}

# WP #1078: clear any stale defer-reason marker from a prior consolidate run
# so PHP reads THIS run's reason on any exit-2 path. Sanitise the ID the same
# way write_defer_reason does so the rm matches the eventual write.
_DEFER_REASON_ID="${ID//[^a-zA-Z0-9_-]/_}"
rm -f "/tmp/unraid-aicliagents/.bake_defer_reason_${TYPE}_${_DEFER_REASON_ID}" 2>/dev/null || true

# Validate paths before any mount or destructive operations
guard_path "$PERSIST_PATH" "PERSIST_PATH" || { error "Persistence path failed validation: $PERSIST_PATH"; update_task_status "Failed" 0 "Invalid path"; exit 1; }
_assert_persist_durable "$PERSIST_PATH" || { error "Persistence path is on a non-durable filesystem — consolidation refused"; update_task_status "Failed" 0 "Non-durable path"; exit 1; }
guard_path "$MNT_POINT" "MNT_POINT" || { error "Mount point failed validation: $MNT_POINT"; update_task_status "Failed" 0 "Invalid mount"; exit 1; }

# 1. Ensure stack is mounted to get merged view
lifecycle_log "info" "consolidate_layers" "bash_consolidate_start" "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"persist_path\":\"$PERSIST_PATH\"}" 2>/dev/null || true
update_task_status "Initializing..." 5 ""
if ! mountpoint -q "$MNT_POINT"; then
    log "Stack not mounted. Attempting remount..."
    update_task_status "Mounting stack..." 10 ""
    op_mount "$TYPE" "$ID" "$PERSIST_PATH" || {
        error "Failed to mount stack for consolidation";
        update_task_status "Failed" 0 "Mount failed";
        exit 1;
    }
fi

# WP #922: pre-bake busy check. Match commit_stack.sh's safety pattern — if any
# process has open files on the merged mount, defer the consolidate rather than
# charging in. Three things go wrong if we ignore this:
#   1. The aggressive pre-bake prune (next block: rm -rf .npm, .cache, .claude
#      caches, etc.) hits files that are open for write by an active agent,
#      tripping `set -euo pipefail` and exiting 1 with no useful diagnostic.
#   2. mksquashfs sees the merged view mid-write and bakes an inconsistent
#      consolidated layer.
#   3. The supervisor counts this as a real failure and after 2 fails halts
#      auto-consolidate with a notification — even though the failure was just
#      a session being active.
#
# Exit 2 signals "deferred / busy" — supervisor treats this as not-a-failure
# and retries on next tick without incrementing the failure counter.
if command -v fuser >/dev/null 2>&1; then
    if fuser -sm "$MNT_POINT" 2>/dev/null; then
        log "Mount is BUSY (open files detected by fuser -sm). Deferring consolidation — will retry when idle."
        lifecycle_log "info" "consolidate_layers" "bash_consolidate_deferred" "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"reason\":\"mount_busy\"}" 2>/dev/null || true
        update_task_status "Deferred (mount busy)" 0 "Sessions active — will retry when idle"
        write_defer_reason "$TYPE" "$ID" "mount_busy"
        exit 2
    fi
fi

# WP #1246: refresh the mount before reading the merged view for consolidation.
# If a prior bake's mount-refresh was interrupted (e.g. a daemon restart during
# `plugin install`), a baked delta can sit on flash WITHOUT being in the current
# overlay's lowerdir — and consolidating from that stale merged view bakes a
# consolidated layer that omits the delta's data, then deletes the orphan delta
# (permanent loss). op_mount re-discovers ALL on-disk layers (glob, not the live
# mount), so refreshing here guarantees consolidate reads a COMPLETE view. Safe:
# the fuser idle-check just above confirmed no open fds, so the umount-remount is
# clean (the same precondition op_bake's post-bake refresh relies on).
op_mount "$TYPE" "$ID" "$PERSIST_PATH" || {
    error "WP #1246: pre-consolidate mount refresh failed — aborting rather than bake a stale view."
    update_task_status "Failed" 0 "Mount refresh failed"
    exit 1
}

# WP #1278 (#3): lowerdir-completeness backstop. The refresh above re-discovers
# ALL on-disk layers and mounts them all-or-nothing, so in normal operation the
# live overlay's lowerdir count equals the on-disk layer count. Assert that
# BEFORE trusting the merged view enough to bake-and-delete: if the live overlay
# is SHORT (fewer lowers than layers on disk), some delta is absent from the
# view — consolidating would bake a lossy layer and the old-layer delete (step
# 4b below) would then destroy that delta permanently. This is the May-29 Tower
# vector (a stale/short consolidate lowerdir baked a consolidated missing the
# user's conversations). Abort with exit 2 (deferred — preserves the upper AND
# every delta; no marker/lock taken yet) so a later idle tick retries from a
# complete mount. Only enforced when >=1 layer exists on disk: the empty-stack
# mount uses a synthetic single EMPTY_LOWER which would otherwise read as a
# 1-vs-0 mismatch. A concurrent bake landing a delta between the refresh and this
# check trips it too — a benign deferral (retry picks up the new layer), never a
# loss, mirroring the bake-landed-during-consolidate guard further down.
_DISCOVERED_COUNT=$(_layer_discover_sorted "$PERSIST_PATH" "$TYPE" "$ID" | awk 'NF{c++}END{print c+0}')
_MOUNTED_COUNT=$(_mounted_lower_count "$MNT_POINT")
case "$_MOUNTED_COUNT" in ''|*[!0-9]*) _MOUNTED_COUNT=0 ;; esac
if [ "$_DISCOVERED_COUNT" -ge 1 ] && [ "$_MOUNTED_COUNT" -ne "$_DISCOVERED_COUNT" ]; then
    error "WP #1278: mounted lowerdir count ($_MOUNTED_COUNT) != on-disk layer count ($_DISCOVERED_COUNT) after refresh — refusing to consolidate from an incomplete view (would risk deleting un-captured deltas). Deferring."
    update_task_status "Deferred" 0 "Incomplete mount — retry when stack is complete"
    lifecycle_log "warn" "consolidate_layers" "bash_consolidate_deferred" \
        "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"reason\":\"lowerdir_incomplete\",\"mounted\":$_MOUNTED_COUNT,\"discovered\":$_DISCOVERED_COUNT}" 2>/dev/null || true
    write_defer_reason "$TYPE" "$ID" "consolidate_lowerdir_incomplete"
    exit 2
fi

# 2. Preparation: Pruning
if [ "$TYPE" == "agent" ]; then
    log "Pruning non-essential files..."
    update_task_status "Pruning non-essential files..." 20 ""
    cd "$MNT_POINT" && npm prune --production > /dev/null 2>&1 || true
    rm -rf "$MNT_POINT/tmp/npm_cache"/* 2>/dev/null || true
fi

# WP #748 Phase 1 (F): expand pre-consolidate prune for home overlays.
# Operates on the MERGED mount path ($MNT_POINT) so the consolidated layer
# doesn't carry regenerable caches from lower layers either.
# HARD CONSTRAINT: never touch snap_*/migrated_legacy_data/*backup*/SAFE_BACKUP.
ORPHAN_N_EXCLUDES=""
if [ "$TYPE" = "home" ]; then
    log "Pruning regenerable caches from merged home view before consolidation..."
    update_task_status "Pruning caches..." 18 ""
    [ -d "$MNT_POINT/.npm" ] && find "$MNT_POINT/.npm" -mindepth 1 -delete 2>/dev/null || true
    [ -d "$MNT_POINT/.cache" ] && find "$MNT_POINT/.cache" -mindepth 1 -delete 2>/dev/null || true
    [ -d "$MNT_POINT/.bun/install" ] && rm -rf "$MNT_POINT/.bun/install" 2>/dev/null || true
    # WP #931: do NOT prune $MNT_POINT/.gemini/tmp — it contains gemini-cli's
    # project-scoped session chat logs (.gemini/tmp/<projectId>/chats/session-*.jsonl),
    # which are durable user state, not regenerable cache. Previously this line
    # silently wiped users' chat histories on every consolidate.
    [ -d "$MNT_POINT/.claude/cache" ] && rm -rf "$MNT_POINT/.claude/cache" 2>/dev/null || true
    [ -d "$MNT_POINT/.claude/shell-snapshots" ] && rm -rf "$MNT_POINT/.claude/shell-snapshots" 2>/dev/null || true
    [ -d "$MNT_POINT/.claude/telemetry" ] && rm -rf "$MNT_POINT/.claude/telemetry" 2>/dev/null || true

    # WP #1078 one-shot cleanup: detect orphan ".<name>_<N>" top-level dirs that
    # contain SQLite .db files — these are stranded artifacts of the broken
    # mksquashfs-append protocol (v2026.05.18.06 → v2026.05.23.19), unreachable
    # by any agent. Exclude them from the next consolidated layer via -e so the
    # consolidated tree finally matches the canonical layout. They live in the
    # squashfs layers (not in UPPER), so we can't rm them from $MNT_POINT —
    # we exclude them from mksquashfs's read of the merged view instead.
    # Match e.g. ".copilot_1", ".local_2", ".codex_3"; the trailing _<digit>+
    # signature is mksquashfs's collision-rename marker.
    for _orphan_dir in "$MNT_POINT"/.*_[0-9]*; do
        [ -d "$_orphan_dir" ] || continue
        _orphan_name=$(basename "$_orphan_dir")
        # Pattern guard: only ".<letters>_<digits>" — leave any user dir with
        # an underscore-number suffix that does NOT start with "." alone.
        case "$_orphan_name" in
            .*_[0-9]*) ;;
            *) continue ;;
        esac
        # Signature confirmation: must contain a .db file or be an empty
        # parent of one (the consolidated layer on Tower has empty .local_1/
        # because earlier append-failure cleanups rm'd the wide-bake-only deltas). Both
        # cases are safe to exclude — empty shadow dirs are pure noise; .db-
        # bearing shadow dirs are unreachable data the user lost weeks ago.
        if find "$_orphan_dir" -maxdepth 8 -name '*.db' -type f 2>/dev/null | grep -q . \
           || [ -z "$(find "$_orphan_dir" -mindepth 1 -type f -print -quit 2>/dev/null)" ]; then
            log "WP #1078 cleanup: excluding orphan shadow dir from consolidate: $_orphan_name"
            ORPHAN_N_EXCLUDES="$ORPHAN_N_EXCLUDES -e $_orphan_name"
            lifecycle_log "info" "consolidate_layers" "wp1078_orphan_excluded" \
                "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"orphan\":\"$_orphan_name\"}" 2>/dev/null || true
        fi
    done
fi

# Check disk space in /tmp for the scratch verify mount used by atomic_write_layer (minimal — just a mountpoint)
check_disk_space "/tmp/unraid-aicliagents/" 10 || { error "Insufficient disk space in /tmp for verification scratch mount"; update_task_status "Failed" 0 "Low disk space"; exit 1; }

# WP #271 guard: detect legacy nested 'home/' directory inside the home mount.
# Some early plugin versions packaged user-home contents at sqsh-root/home/...
# instead of sqsh-root/... directly. If left in place, mksquashfs re-packages
# this nesting on every consolidate, and ConfigService (which reads from the
# correct shallower path) silently sees stale/empty data. We detect early so
# the user does not lose their workspaces / chat history to a silent re-pack.
if [ "$TYPE" == "home" ] && [ -d "$MNT_POINT/home" ]; then
    log "Detected legacy nested 'home/' directory at $MNT_POINT/home"
    if [ -z "$(ls -A "$MNT_POINT/home" 2>/dev/null)" ]; then
        log "Legacy 'home/' is empty — removing before bake"
        rmdir "$MNT_POINT/home" 2>/dev/null || true
    else
        # Non-empty: refuse rather than silently shuffle data. Show a sample of
        # the offending files so the user can manually merge if needed.
        SAMPLE=$(find "$MNT_POINT/home" -type f 2>/dev/null | head -5 | tr '\n' ',' | sed 's/,$//')
        error "Refusing to consolidate: legacy nested home/ contains files (e.g. $SAMPLE)."
        error "Manual cleanup required — move data up one level into $MNT_POINT/ then rmdir $MNT_POINT/home."
        update_task_status "Failed" 0 "Legacy nested home/ artifact detected — manual merge required"
        exit 1
    fi
fi

# 3. Bake Consolidated Volume (Phase 2 — atomic, closing findings D-partial and E)
#
# Step 3a: Snapshot existing layers BEFORE bake so we know exactly which files to remove
#          after the new consolidated volume lands. This is the manifest-driven explicit
#          cleanup that replaces the wildcard find-delete (finding D).
log "Baking consolidated volume (atomic)..."
update_task_status "Baking consolidated volume..." 40 ""

# Snapshot pre-bake layer list. nullglob ensures empty array when no layers present.
shopt -s nullglob
OLD_LAYERS=("$PERSIST_PATH/${TYPE}_${ID}_"*.sqsh)
shopt -u nullglob

# Record a marker BEFORE bake. Any writes to UPPER_DIR after this marker
# won't be included in the baked volume — if we wipe the upper afterwards,
# those writes are lost. Same protection pattern as commit_stack.sh.
# This was the cause of a silent resume-file loss when gracefulClose ran
# concurrently with auto-consolidation.
CONSOLIDATE_MARKER="/tmp/unraid-aicliagents/.consolidate_marker_${TYPE}_${ID}"
touch "$CONSOLIDATE_MARKER"
# M2 fix (v2026.05.18.08): 50ms gap separates marker mtime from any concurrent
# write hitting the same tmpfs nanosecond — the post-bake UPPER_CHANGED check
# uses `find -newer $CONSOLIDATE_MARKER` and would otherwise miss equality writes.
sleep 0.05

# Check disk space on persistence target (atomic_write_layer writes directly there, not /tmp)
check_disk_space "$PERSIST_PATH/.diskcheck" 200 || {
    error "Insufficient disk space on persistence path for consolidated volume"
    update_task_status "Failed" 0 "Low disk space on Flash"
    rm -f "$CONSOLIDATE_MARKER"
    exit 1
}

# WP #935: detect SQLite DBs in the merged view and back them up via Online
# Backup API before the bake.
# WP #1078 (2026-05-24): the bake now uses overlay-merge (lower=MNT_POINT,
# upper=sqlite_stage) → single-pass mksquashfs. The previous two-pass append
# protocol was broken — mksquashfs append renamed colliding top-level dirs
# with _N suffix, stranding the SQLite backups at unreachable paths. See
# docs/specs/SQLITE_APPEND_DATALOSS_BUG.md and commit_stack.sh for the
# detailed analysis. The .db sidecars (-wal/-shm/-journal) are excluded;
# SQLite reconstitutes them from the .db on next open.
SQLITE_DBS=$(detect_sqlite_dbs "$MNT_POINT" 2>/dev/null)
# Count non-empty lines via awk (handles empty input cleanly; the prior
# `echo | grep -c -v '^$' || echo 0` produced "0\n0" on empty input and
# tripped `[: integer expected` further down).
SQLITE_DB_COUNT=$(printf '%s\n' "$SQLITE_DBS" | awk 'NF{c++}END{print c+0}')
SQLITE_STAGE=""

if [ "$SQLITE_DB_COUNT" -gt 0 ]; then
    log "Detected $SQLITE_DB_COUNT SQLite DB(s) in merged view — backing up via Online Backup API"
    update_task_status "Backing up SQLite DBs..." 35 ""
    SQLITE_STAGE="/tmp/unraid-aicliagents/.sqlite_stage_consol_${TYPE}_${ID}_$$"
    rm -rf "$SQLITE_STAGE" 2>/dev/null
    mkdir -p "$SQLITE_STAGE"

    # shellcheck disable=SC2086
    sqlite_backup_all "$MNT_POINT" "$SQLITE_STAGE" $SQLITE_DBS
    _sba_rc=$?
    if [ "$_sba_rc" -ne 0 ]; then
        rm -rf "$SQLITE_STAGE" 2>/dev/null
        rm -f "$CONSOLIDATE_MARKER"
        # M3 fix (v2026.05.18.08): hard errors (return 1) must fail, not defer.
        if [ "$_sba_rc" -eq 1 ]; then
            error "SQLite backup hard error during consolidate — failing"
            update_task_status "Failed" 0 "SQLite backup hard error"
            lifecycle_log "error" "consolidate_layers" "bash_consolidate_failed" \
                "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"reason\":\"sqlite_backup_hard_error\",\"db_count\":$SQLITE_DB_COUNT}" 2>/dev/null || true
            exit 1
        fi
        log "SQLite backup deferred (DB locked or backup timeout) — exiting 2 to retry"
        update_task_status "Deferred" 0 "SQLite DB locked — retry later"
        lifecycle_log "info" "consolidate_layers" "bash_consolidate_deferred" \
            "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"reason\":\"sqlite_backup_failed\",\"db_count\":$SQLITE_DB_COUNT}" 2>/dev/null || true
        write_defer_reason "$TYPE" "$ID" "sqlite_backup_deferred"
        exit 2
    fi
fi

# Step 3b: Atomic bake. Two paths:
#   (a) SQLite DBs detected → overlay-merge bake (single pass; sqlite_stage
#       shadows the live DBs in MNT_POINT; WAL/SHM/journal excluded).
#   (b) No SQLite DBs → direct bake of MNT_POINT.
# Both go through atomic_write_layer (tempfile + fsync + verify + atomic rename).
# WP #1078 orphan excludes (if any were detected in the pre-bake prune block)
# are folded into MKSQUASHFS_ARGS so both paths honour them.
_AWL_DEFAULT_ARGS="-comp xz -Xbcj x86 -Xdict-size 100% -b 1M -no-exports -noappend"
if [ -n "$ORPHAN_N_EXCLUDES" ]; then
    export MKSQUASHFS_ARGS="${MKSQUASHFS_ARGS:-$_AWL_DEFAULT_ARGS} $ORPHAN_N_EXCLUDES"
fi

FINAL_NAME=""
if [ "$SQLITE_DB_COUNT" -gt 0 ] && [ -n "$SQLITE_STAGE" ] && [ -d "$SQLITE_STAGE" ]; then
    log "Consolidating via overlay-merge ($SQLITE_DB_COUNT SQLite backup(s) shadow live DBs)..."
    update_task_status "Baking consolidated volume..." 40 ""
    # shellcheck disable=SC2086 — intentional word-splitting on the DB path list
    if ! FINAL_NAME=$(bake_via_overlay_merge "$TYPE" "$ID" "$PERSIST_PATH" "$MNT_POINT" "$SQLITE_STAGE" "consolidated" $SQLITE_DBS); then
        error "Overlay-merge consolidation bake failed."
        update_task_status "Failed" 0 "Bake failed"
        rm -rf "$SQLITE_STAGE" 2>/dev/null
        rm -f "$CONSOLIDATE_MARKER"
        lifecycle_log "error" "consolidate_layers" "bash_consolidate_failed" "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"reason\":\"overlay_merge_bake_failed\"}" 2>/dev/null || true
        exit 1
    fi
    rm -rf "$SQLITE_STAGE" 2>/dev/null
    lifecycle_log "info" "consolidate_layers" "bash_consolidate_sqlite_merged" \
        "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"sqsh\":\"$FINAL_NAME\",\"db_count\":$SQLITE_DB_COUNT}" 2>/dev/null || true
else
    update_task_status "Baking consolidated volume..." 40 ""
    if ! FINAL_NAME=$(atomic_write_layer "$TYPE" "$ID" "$PERSIST_PATH" "$MNT_POINT" "consolidated"); then
        error "Atomic consolidation bake failed."
        update_task_status "Failed" 0 "Bake failed"
        rm -f "$CONSOLIDATE_MARKER"
        lifecycle_log "error" "consolidate_layers" "bash_consolidate_failed" "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"reason\":\"atomic_write_layer_failed\"}" 2>/dev/null || true
        exit 1
    fi
fi

log "Consolidated volume written: $FINAL_NAME"
update_task_status "Finalizing volume..." 80 ""

# Sanity: check FAT32 size limit on the final file
SQSH_SIZE=$(stat -c%s "$PERSIST_PATH/$FINAL_NAME" 2>/dev/null || echo 0)
MAX_SIZE=$((3900 * 1024 * 1024)) # 3.9GB
if [ "$SQSH_SIZE" -gt "$MAX_SIZE" ]; then
    error "Consolidated volume exceeds 3.9GB FAT32 limit ($SQSH_SIZE bytes). Removing and keeping old layers."
    update_task_status "Failed" 0 "Exceeds FAT32 limit"
    rm -f "$PERSIST_PATH/$FINAL_NAME"
    rm -f "$CONSOLIDATE_MARKER"
    exit 1
fi

# --- CRITICAL SECTION: take the shared per-entity storage lock ---------------
# Race fix: the supervisor's reconcile runs every ~7s and scans this directory.
# Between the manifest replace and the end of the old-layer delete loop below,
# the on-disk layer set legitimately differs from the manifest. If a reconcile
# tick lands in that window it mis-classifies the old layers as "untracked" and
# quarantines them to .untracked/ — actively losing baked data. The fix: hold
# the SAME per-entity lock commit_stack.sh uses, for the whole swap; the
# supervisor's reconcile skips any entity whose lock is held.
#
# The lock is taken HERE — after the (long) mksquashfs, which ran unlocked so it
# never blocks a bake. If a bake holds the lock right now, defer (exit 2).
_CONSOL_LOCK_ID="${ID//[^a-zA-Z0-9_-]/_}"
_CONSOL_LOCK="/var/run/aicli-bake-${TYPE}-${_CONSOL_LOCK_ID}.lock"
exec 8>"$_CONSOL_LOCK"
if ! flock -n 8; then
    error "Per-entity storage lock held (a bake is in flight) — deferring consolidate."
    rm -f "$PERSIST_PATH/$FINAL_NAME" 2>/dev/null
    rm -f "$CONSOLIDATE_MARKER"
    update_task_status "Deferred" 0 "Bake in progress — retry later"
    lifecycle_log "info" "consolidate_layers" "bash_consolidate_deferred" \
        "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"reason\":\"bake_lock_held\"}" 2>/dev/null || true
    write_defer_reason "$TYPE" "$ID" "bake_lock_held"
    exit 2
fi

# Re-validate: did a bake land a NEW delta since OLD_LAYERS was snapshotted
# (before the unlocked mksquashfs)? If so, the consolidated layer just built is
# already stale — it cannot contain that delta. Discard it (non-destructive —
# nothing else has been touched yet) and defer. The bake always wins.
shopt -s nullglob
_CURRENT_LAYERS=("$PERSIST_PATH/${TYPE}_${ID}_"*.sqsh)
shopt -u nullglob
for _cur in "${_CURRENT_LAYERS[@]}"; do
    _cur_base="$(basename "$_cur")"
    [ "$_cur_base" = "$FINAL_NAME" ] && continue
    _was_known=0
    for _old in "${OLD_LAYERS[@]}"; do
        [ "$(basename "$_old")" = "$_cur_base" ] && _was_known=1 && break
    done
    if [ "$_was_known" -eq 0 ]; then
        error "New layer '$_cur_base' appeared during consolidation — a bake landed. Discarding stale consolidated layer; deferring."
        rm -f "$PERSIST_PATH/$FINAL_NAME" 2>/dev/null
        rm -f "$CONSOLIDATE_MARKER"
        update_task_status "Deferred" 0 "A bake completed during consolidation — retry later"
        lifecycle_log "info" "consolidate_layers" "bash_consolidate_deferred" \
            "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"reason\":\"bake_landed_during_consolidate\",\"new_layer\":\"$_cur_base\"}" 2>/dev/null || true
        write_defer_reason "$TYPE" "$ID" "bake_landed_during_consolidate"
        exit 2
    fi
done
# Lock held on fd 8 + layer set validated — safe to swap the manifest and
# remove the old layers. Lock releases when this script exits.

# 4 (was 5). Update manifest BEFORE deleting old layer files (Fix #4a).
#
# Order matters for crash safety:
#   • manifest update first → if killed between manifest write and file deletes, the old
#     files linger on disk UNTRACKED. Reconcile finds them as "untracked" → recovers them
#     harmlessly (mounts RO, sample-reads, re-adds to manifest as 'recovered').
#   • file deletes first (old order) → if killed between deletes and manifest write, the
#     manifest still lists now-deleted layers → reconcile finds them as "missing" → halts
#     the entity with corrupt_layers. This is what caused the test-box corruption.
#
# The manifest is written atomically (tmp+fsync+rename inside LayerManifestService::replaceLayers).
log "Updating manifest to list only the new consolidated layer (before file deletes)..."
update_task_status "Updating manifest..." 85 ""
ENTITY="${TYPE}/${ID}"
NOW_TS=$(date -u '+%Y-%m-%dT%H:%M:%SZ' 2>/dev/null || date '+%Y-%m-%dT%H:%M:%SZ')
SQSH_SHA256=$(sha256sum "$PERSIST_PATH/$FINAL_NAME" 2>/dev/null | awk '{print $1}' || echo "")
SQSH_BYTES=$(stat -c '%s' "$PERSIST_PATH/$FINAL_NAME" 2>/dev/null || echo 0)
if command -v php >/dev/null 2>&1; then
    php -d display_errors=0 -r "
        \$_SERVER['DOCUMENT_ROOT']='/usr/local/emhttp';
        require_once '/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/AICliAgentsManager.php';
        \AICliAgents\Services\LayerManifestService::replaceLayers('$ENTITY', [
            [
                'filename'   => '$FINAL_NAME',
                'sha256'     => '$SQSH_SHA256',
                'bytes'      => (int)'$SQSH_BYTES',
                'kind'       => 'consolidated',
                'created_at' => '$NOW_TS',
            ],
        ], '$PERSIST_PATH');
    " 2>/dev/null || true
    lifecycle_log "info" "consolidate_layers" "manifest_updated_before_delete" "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"final\":\"$FINAL_NAME\"}" 2>/dev/null || true
else
    log "WARNING: php not available — manifest not updated before old-layer delete. Reconcile will recover."
fi

# 4b. Delete old layer files — now safe because manifest no longer references them.
#     If SIGTERM fires here, the old files are untracked (reconcile recovers),
#     not missing (reconcile halts).
log "Cleaning up old layers..."
update_task_status "Cleaning up old layers..." 90 ""
guard_path "$PERSIST_PATH" "PERSIST_PATH (cleanup)" || { error "Path guard failed before cleanup"; exit 1; }
for old_layer in "${OLD_LAYERS[@]}"; do
    [ -f "$old_layer" ] || continue
    # Belt-and-braces: never delete the new consolidated file
    [ "$(basename "$old_layer")" = "$FINAL_NAME" ] && continue
    rm -f "$old_layer"
    lifecycle_log "info" "consolidate_layers" "old_layer_removed" "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"file\":\"$(basename "$old_layer")\"}" 2>/dev/null || true
    log "Removed old layer: $(basename "$old_layer")"
done

# 6. Remount & Clear RAM
# WP #1080 + #1081 (2026-05-24): single fuser check, then check UPPER_CHANGED
# BEFORE the umount (find -newer reads $UPPER_DIR directly, doesn't need the
# mount), then refresh-and-wipe. The prior structure had umount → check →
# wipe → mount, leaving the mount torn down for the entire duration of the
# wipe (potentially seconds for big uppers). The new structure narrows the
# umount window to the mount_stack.sh refresh only.
#
# Why check UPPER_CHANGED before umount: `find $UPPER_DIR -newer $marker`
# operates on the raw ZRAM tmpfs upper, not through the overlay mount.
# Doing the check before umount means we don't add to the unmounted window.
if fuser -sm "$MNT_POINT" 2>/dev/null; then
    log "Mount became BUSY during consolidate (agent launched mid-bake). Deferring refresh+wipe — next mount cycle picks up $FINAL_NAME."
    rm -f "$CONSOLIDATE_MARKER"
    FINAL_BYTES=$(stat -c '%s' "$PERSIST_PATH/$FINAL_NAME" 2>/dev/null || echo 0)
    lifecycle_log "info" "consolidate_layers" "bash_consolidate_ok_refresh_deferred" \
        "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"final\":\"$FINAL_NAME\",\"bytes\":$FINAL_BYTES,\"reason\":\"mount_busy_at_refresh\"}" 2>/dev/null || true
    update_task_status "Consolidation complete (mount refresh deferred — sessions active)." 100 ""
    exit 0
fi

# Check UPPER_CHANGED before the umount window. find -newer operates on the
# raw ZRAM tmpfs, not through the overlay mount, so this is safe to do here.
# (D-353: skip the wipe if any writes arrived after the bake marker — those
# writes are NOT in the consolidated volume and would be silently lost.)
UPPER_CHANGED=""
if [ -f "$CONSOLIDATE_MARKER" ] && [ -d "$UPPER_DIR" ]; then
    UPPER_CHANGED=$(find "$UPPER_DIR" -newer "$CONSOLIDATE_MARKER" -type f 2>/dev/null | head -1)
fi
rm -f "$CONSOLIDATE_MARKER"

log "Finalizing stack and clearing RAM..."
log "Note: Active agent terminals may log transient I/O errors during remount. This is expected and resolves automatically."
update_task_status "Finalizing stack..." 95 ""

# Refresh mount FIRST (mount_stack.sh handles its own umount-then-mount cycle —
# the brief umount window inside it is the only unmounted gap, vs the prior
# code's umount + wipe + mount which kept it down for the full wipe).
op_mount "$TYPE" "$ID" "$PERSIST_PATH"

# Now safely wipe UPPER (if appropriate). The new consolidated lower is exposed
# via the just-refreshed mount, so removing files from upper falls through to
# the new lower (correct content).
if [ -d "$UPPER_DIR" ]; then
    if [ -n "$UPPER_CHANGED" ]; then
        log "Writes detected in upper layer during bake (e.g. $UPPER_CHANGED). Preserving upper to avoid data loss — next commit will delta them onto the consolidated base."
    else
        find "$UPPER_DIR" -mindepth 1 -delete
        # WP #1081: WORK_DIR wipe REMOVED — under the refresh-then-wipe order
        # the overlay holds a live kernel fd on $WORK_DIR/work; wiping it from
        # userspace mid-mount breaks copy-up. The wipe was cosmetic kernel-
        # scratch reclamation; overlayfs clears workdir on its own mount.
        sync
    fi
fi

FINAL_BYTES=$(stat -c '%s' "$PERSIST_PATH/$FINAL_NAME" 2>/dev/null || echo 0)
lifecycle_log "info" "consolidate_layers" "bash_consolidate_ok" "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"final\":\"$FINAL_NAME\",\"bytes\":$FINAL_BYTES}" 2>/dev/null || true
update_task_status "Consolidation complete." 100 ""
)
