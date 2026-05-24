#!/bin/bash
set -euo pipefail
# AICliAgents: Persistence Bake (ZRAM -> SquashFS)
# Usage: commit_stack.sh <type: agent|home> <id> <persistence_path>

TYPE="${1:-}"
ID="${2:-}"
PERSIST_PATH="${3:-}"

# Source shared storage functions (guard_path, check_disk_space, etc.)
source "$(dirname "$0")/common.sh"

# WP #922: snapshot debug.log to Flash on non-zero exit. Skips on exit 2 (which
# commit_stack.sh uses for "baked but mount busy — ZRAM flush deferred").
install_failure_trap "$TYPE" "$ID" "commit_stack"

# Source canonical path resolver and lifecycle log writer (Phase 1)
source "$(dirname "$0")/resolve_paths.sh" 2>/dev/null || true

# Source atomic layer writer (Phase 2)
source "$(dirname "$0")/atomic_write_layer.sh" 2>/dev/null || {
    error "atomic_write_layer.sh missing — cannot bake safely"
    exit 1
}

# #342: derive UPPER_DIR from persistence fstype (vfat→ZRAM, else→disk direct).
# Must match the logic in mount_stack.sh so we bake from the correct upper layer.
_CS_FSTYPE=$(findmnt --noheadings --output FSTYPE --target "$PERSIST_PATH" 2>/dev/null || echo '')
if [ "$_CS_FSTYPE" = "vfat" ] || [ -z "$_CS_FSTYPE" ]; then
    UPPER_DIR="$ZRAM_BASE/${TYPE}s/$ID/upper"
    WORK_DIR="$ZRAM_BASE/${TYPE}s/$ID/work"
else
    UPPER_DIR="$PERSIST_PATH/_upper/${TYPE}s/$ID"
    WORK_DIR="$PERSIST_PATH/_work/${TYPE}s/$ID"
fi

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
if fuser -sm "$MNT_POINT" 2>/dev/null; then
    log "Mount is BUSY (open files detected). Deferring refresh + ZRAM cleanup."
    log "Data persisted to Flash. ZRAM dirty stats remain until sessions close."
    rm -f "$MARKER"
    lifecycle_log "info" "commit_stack" "bash_bake_busy" "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"sqsh\":\"$(basename "$NEW_SQSH")\",\"bytes\":$SQSH_BYTES}" 2>/dev/null || true
    write_defer_reason "$TYPE" "$ID" "mount_busy"
    exit 2
fi

# Refresh mount stack to pick up the new lower layer. Done BEFORE selective
# cleanup so the new lower is exposed when we start removing files from upper.
log "Refreshing mount stack..."
bash "$(dirname "$0")/mount_stack.sh" "$TYPE" "$ID" "$PERSIST_PATH"

# WP #935: Selective UPPER cleanup — now safe to run because the refresh above
# exposed the new lower. Per-file: a file is wiped only if (a) its mtime is not
# newer than the marker AND (b) no process holds an open write fd to it. Bytes
# that satisfy the invariant are reclaimed; the rest stay in ZRAM safely.
log "Performing selective ZRAM cleanup (per-file mtime + open-fd invariant)..."
CLEANUP_JSON=$(selective_upper_cleanup "$UPPER_DIR" "$MARKER")
rm -f "$MARKER"

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
