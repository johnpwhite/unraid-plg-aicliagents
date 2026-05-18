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
# BEFORE the wide bake. SQLite's .backup is safe against concurrent writers
# (page-version-counter detection); the WAL/SHM siblings are excluded from
# the mksquashfs scan (their content is reconstituted by SQLite from the .db
# on next open). After the wide bake, the SQLite backups are appended to the
# layer via `mksquashfs -append`. The bake stays atomic — atomic_write_layer
# handles tempfile + verify + rename for the wide pass, and the append step
# happens only after rename has succeeded (worst-case: missing SQLite content
# in this layer, which the next bake captures cleanly).
SQLITE_DBS=$(detect_sqlite_dbs "$UPPER_DIR" 2>/dev/null)
SQLITE_DB_COUNT=$(echo "$SQLITE_DBS" | grep -c -v '^$' 2>/dev/null || echo 0)
SQLITE_STAGE=""
MKSQUASHFS_EXTRA_EXCLUDES=""

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
        exit 2
    fi

    # Build the -e exclude list for the wide bake.
    MKSQUASHFS_EXTRA_EXCLUDES=$(build_mksquashfs_sqlite_excludes "$UPPER_DIR" $SQLITE_DBS)
fi

# 2. Atomic bake (Pass 1 — wide). Excludes SQLite DBs + their WAL/SHM siblings
# if any were detected. Apps continue writing freely; the bake reads at file
# level via the merged FS.
log "Baking changes to $PERSIST_PATH/ (atomic delta, $SQLITE_DB_COUNT SQLite path(s) excluded)..."

# Inject the SQLite excludes into mksquashfs args via the MKSQUASHFS_ARGS env
# var that atomic_write_layer respects. Compose with the default args.
_AWL_DEFAULT_ARGS="-comp xz -Xbcj x86 -Xdict-size 100% -b 1M -no-exports -noappend"
if [ -n "$MKSQUASHFS_EXTRA_EXCLUDES" ]; then
    export MKSQUASHFS_ARGS="${MKSQUASHFS_ARGS:-$_AWL_DEFAULT_ARGS} $MKSQUASHFS_EXTRA_EXCLUDES"
fi

NEW_BASENAME=""
if ! NEW_BASENAME=$(atomic_write_layer "$TYPE" "$ID" "$PERSIST_PATH" "$UPPER_DIR" "delta"); then
    error "Atomic bake failed."
    rm -rf "$SQLITE_STAGE" 2>/dev/null
    rm -f "$MARKER"
    lifecycle_log "error" "commit_stack" "bash_bake_failed" "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"persist_path\":\"$PERSIST_PATH\"}" 2>/dev/null || true
    exit 1
fi

# 2b. Pass 3 (append): if we backed up any SQLite DBs, fold them into the layer
# at their relative paths.
#
# C2 fix (v2026.05.18.08): the append used to mutate the just-renamed final
# file in place. If mksquashfs failed (disk full, OOM-kill, signal),
# the on-disk final.sqsh was partially corrupted AND the script fell through
# to selective_upper_cleanup which wiped the live DBs from UPPER (mtime old,
# no write fd between SQLite transactions). Net: silent permanent data loss.
#
# New protocol: append onto a TEMPFILE copy. Verify the append actually added
# bytes. Only if everything succeeds, atomic-rename over the final. On ANY
# failure, delete the tempfile and exit 2 (defer) — leave UPPER intact so the
# next bake retries. The wide-bake-only final is harmless because the C1
# SQLite-aware exclusion in selective_upper_cleanup keeps the DBs in UPPER
# regardless; we delete it anyway to avoid manifest pollution with a layer
# that's missing SQLite content. Don't run selective cleanup on defer.
if [ -n "$SQLITE_STAGE" ] && [ -d "$SQLITE_STAGE" ] && [ "$SQLITE_DB_COUNT" -gt 0 ]; then
    log "Appending $SQLITE_DB_COUNT SQLite backup(s) to $NEW_BASENAME (via tempfile)"
    APPEND_ARGS=$(echo "$_AWL_DEFAULT_ARGS" | sed 's/-noappend//g')
    APPENDING_TMP="$PERSIST_PATH/.${NEW_BASENAME}.appending.$$"
    PRE_APPEND_BYTES=$(stat -c '%s' "$PERSIST_PATH/$NEW_BASENAME" 2>/dev/null || echo 0)

    _append_fail() {
        local reason="$1"
        error "Append failed: $reason — deferring bake (UPPER preserved for retry)"
        rm -f "$APPENDING_TMP" 2>/dev/null
        rm -f "$PERSIST_PATH/$NEW_BASENAME" 2>/dev/null
        rm -rf "$SQLITE_STAGE" 2>/dev/null
        rm -f "$MARKER"
        lifecycle_log "error" "commit_stack" "bash_bake_sqlite_append_failed" \
            "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"sqsh\":\"$NEW_BASENAME\",\"reason\":\"$reason\"}" 2>/dev/null || true
        exit 2
    }

    if ! cp "$PERSIST_PATH/$NEW_BASENAME" "$APPENDING_TMP" 2>/dev/null; then
        _append_fail "cp_to_tempfile_failed"
    fi
    # shellcheck disable=SC2086
    if ! mksquashfs "$SQLITE_STAGE" "$APPENDING_TMP" $APPEND_ARGS > /dev/null 2>&1; then
        _append_fail "mksquashfs_append_returned_nonzero"
    fi
    POST_APPEND_BYTES=$(stat -c '%s' "$APPENDING_TMP" 2>/dev/null || echo 0)
    if [ "$POST_APPEND_BYTES" -le "$PRE_APPEND_BYTES" ]; then
        # Append "succeeded" but added zero bytes — defence-in-depth check that
        # would have caught the same-day -e leak bug if it ever recurs.
        _append_fail "append_zero_bytes_added"
    fi
    sync
    if ! mv -f "$APPENDING_TMP" "$PERSIST_PATH/$NEW_BASENAME" 2>/dev/null; then
        _append_fail "atomic_rename_appended_failed"
    fi
    rm -rf "$SQLITE_STAGE" 2>/dev/null
    lifecycle_log "info" "commit_stack" "bash_bake_sqlite_appended" \
        "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"sqsh\":\"$NEW_BASENAME\",\"db_count\":$SQLITE_DB_COUNT,\"pre_bytes\":$PRE_APPEND_BYTES,\"post_bytes\":$POST_APPEND_BYTES}" 2>/dev/null || true
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

# WP #935: Selective UPPER cleanup. Previously we ran an all-or-nothing
# `find $UPPER -mindepth 1 -delete` gated by "any file newer than marker?".
# That gate was too coarse — one actively-written file (a SQLite WAL, a log
# tail) pinned the ENTIRE upper in ZRAM until the agent went idle. The new
# logic is per-file: a file is wiped only if (a) its mtime is not newer than
# the marker AND (b) no process holds an open write fd to it. Bytes that
# satisfy the invariant are reclaimed; the rest stay in ZRAM safely.
log "Performing selective ZRAM cleanup (per-file mtime + open-fd invariant)..."
CLEANUP_JSON=$(selective_upper_cleanup "$UPPER_DIR" "$MARKER")
rm -f "$MARKER"

# Sweep the WORK_DIR — overlayfs whiteout/work files for files we just wiped
# are no longer needed; safe to remove unconditionally (they're never
# user data, just kernel-managed overlayfs bookkeeping).
find "$WORK_DIR" -mindepth 1 -delete 2>/dev/null || true
sync

# Refresh mount stack to pick up the new lower layer.
log "Refreshing mount stack..."
bash "$(dirname "$0")/mount_stack.sh" "$TYPE" "$ID" "$PERSIST_PATH"
SQSH_BYTES=$(stat -c '%s' "$NEW_SQSH" 2>/dev/null || echo 0)
# Inline the selective-cleanup stats into the bake_ok event for observability.
# CLEANUP_JSON is a JSON object; splice into the outer event.
lifecycle_log "info" "commit_stack" "bash_bake_ok" \
    "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"sqsh\":\"$(basename "$NEW_SQSH")\",\"bytes\":$SQSH_BYTES,\"cleanup\":$CLEANUP_JSON}" 2>/dev/null || true
