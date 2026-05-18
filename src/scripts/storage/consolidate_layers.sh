#!/bin/bash
set -euo pipefail
# AICliAgents: Storage Consolidation & Volume Splitting
# Usage: consolidate_layers.sh <type: agent|home> <id> <persistence_path>

TYPE="${1:-}"
ID="${2:-}"
PERSIST_PATH="${3:-}"
MNT_POINT="/usr/local/emhttp/plugins/unraid-aicliagents/agents/$ID"

# Source shared storage functions (guard_path, check_disk_space, etc.)
source "$(dirname "$0")/common.sh"

# WP #922: snapshot debug.log to Flash on non-zero exit. Survives /tmp rotation
# so the next investigator has actual evidence. Skips on exit 2 (deferred).
install_failure_trap "$TYPE" "$ID" "consolidate_layers"

# Source canonical path resolver and lifecycle log writer (Phase 1)
source "$(dirname "$0")/resolve_paths.sh" 2>/dev/null || true

# Source atomic layer writer (Phase 2)
source "$(dirname "$0")/atomic_write_layer.sh" 2>/dev/null || {
    echo "[CONSOLIDATE] FATAL: atomic_write_layer.sh missing — cannot consolidate safely" >&2
    exit 1
}

[ "$TYPE" == "home" ] && MNT_POINT="/tmp/unraid-aicliagents/work/$ID/home"
TASK_STATUS_FILE="/tmp/unraid-aicliagents/task-status-$ID"

# #342: derive UPPER_DIR from persistence fstype — must match mount_stack.sh.
_CL_FSTYPE=$(findmnt --noheadings --output FSTYPE --target "$PERSIST_PATH" 2>/dev/null || echo '')
if [ "$_CL_FSTYPE" = "vfat" ] || [ -z "$_CL_FSTYPE" ]; then
    UPPER_DIR="$ZRAM_BASE/${TYPE}s/$ID/upper"
    WORK_DIR="$ZRAM_BASE/${TYPE}s/$ID/work"
else
    UPPER_DIR="$PERSIST_PATH/_upper/${TYPE}s/$ID"
    WORK_DIR="$PERSIST_PATH/_work/${TYPE}s/$ID"
fi

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
    bash "$(dirname "$0")/mount_stack.sh" "$TYPE" "$ID" "$PERSIST_PATH" || {
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
        exit 2
    fi
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
# Backup API before the wide bake (same pattern as commit_stack.sh).
SQLITE_DBS=$(detect_sqlite_dbs "$MNT_POINT" 2>/dev/null)
SQLITE_DB_COUNT=$(echo "$SQLITE_DBS" | grep -c -v '^$' 2>/dev/null || echo 0)
SQLITE_STAGE=""
MKSQUASHFS_EXTRA_EXCLUDES=""

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
        exit 2
    fi

    MKSQUASHFS_EXTRA_EXCLUDES=$(build_mksquashfs_sqlite_excludes "$MNT_POINT" $SQLITE_DBS)
fi

# Step 3b: Atomic bake — writes sibling tempfile, fsyncs, verifies, renames atomically.
# We bake from the mounted stack (MNT_POINT = merged view of all layers).
# atomic_write_layer returns the basename on stdout; all progress goes to stderr.
_AWL_DEFAULT_ARGS="-comp xz -Xbcj x86 -Xdict-size 100% -b 1M -no-exports -noappend"
if [ -n "$MKSQUASHFS_EXTRA_EXCLUDES" ]; then
    export MKSQUASHFS_ARGS="${MKSQUASHFS_ARGS:-$_AWL_DEFAULT_ARGS} $MKSQUASHFS_EXTRA_EXCLUDES"
fi

FINAL_NAME=""
if ! FINAL_NAME=$(atomic_write_layer "$TYPE" "$ID" "$PERSIST_PATH" "$MNT_POINT" "consolidated"); then
    error "Atomic consolidation bake failed."
    update_task_status "Failed" 0 "Bake failed"
    rm -rf "$SQLITE_STAGE" 2>/dev/null
    rm -f "$CONSOLIDATE_MARKER"
    lifecycle_log "error" "consolidate_layers" "bash_consolidate_failed" "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"reason\":\"atomic_write_layer_failed\"}" 2>/dev/null || true
    exit 1
fi

# WP #935 Pass 3: append SQLite backups to the consolidated layer.
#
# C2 fix (v2026.05.18.08): MUST be atomic and MUST hard-fail. The previous
# code mutated the just-renamed final in place and on failure just logged
# and continued — which then proceeded to delete the old layers and wipe
# UPPER, permanently losing the DBs. New protocol: append onto a TEMPFILE
# copy of the final, verify, atomic-rename. On ANY failure, delete the
# tempfile AND the partially-written final, restore the marker, exit 2
# (defer). The old layers stay; UPPER stays; next consolidate retries.
if [ -n "$SQLITE_STAGE" ] && [ -d "$SQLITE_STAGE" ] && [ "$SQLITE_DB_COUNT" -gt 0 ]; then
    log "Appending $SQLITE_DB_COUNT SQLite backup(s) to $FINAL_NAME (via tempfile)"
    update_task_status "Appending SQLite snapshots..." 70 ""
    APPEND_ARGS=$(echo "$_AWL_DEFAULT_ARGS" | sed 's/-noappend//g')
    APPENDING_TMP="$PERSIST_PATH/.${FINAL_NAME}.appending.$$"
    PRE_APPEND_BYTES=$(stat -c '%s' "$PERSIST_PATH/$FINAL_NAME" 2>/dev/null || echo 0)

    _consol_append_fail() {
        local reason="$1"
        error "Append failed during consolidate: $reason — old layers preserved, deferring"
        rm -f "$APPENDING_TMP" 2>/dev/null
        # The wide-bake-only final is missing SQLite content. Deleting it
        # ensures the consolidate is fully reverted: old layers will still
        # be present (manifest update + old-layer-delete haven't run yet).
        rm -f "$PERSIST_PATH/$FINAL_NAME" 2>/dev/null
        rm -rf "$SQLITE_STAGE" 2>/dev/null
        # Don't remove CONSOLIDATE_MARKER — needed by UPPER_CHANGED check.
        update_task_status "Deferred" 0 "SQLite append failed — retry later"
        lifecycle_log "error" "consolidate_layers" "bash_consolidate_sqlite_append_failed" \
            "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"sqsh\":\"$FINAL_NAME\",\"reason\":\"$reason\"}" 2>/dev/null || true
        exit 2
    }

    if ! cp "$PERSIST_PATH/$FINAL_NAME" "$APPENDING_TMP" 2>/dev/null; then
        _consol_append_fail "cp_to_tempfile_failed"
    fi
    # shellcheck disable=SC2086
    if ! mksquashfs "$SQLITE_STAGE" "$APPENDING_TMP" $APPEND_ARGS > /dev/null 2>&1; then
        _consol_append_fail "mksquashfs_append_returned_nonzero"
    fi
    POST_APPEND_BYTES=$(stat -c '%s' "$APPENDING_TMP" 2>/dev/null || echo 0)
    if [ "$POST_APPEND_BYTES" -le "$PRE_APPEND_BYTES" ]; then
        _consol_append_fail "append_zero_bytes_added"
    fi
    sync
    if ! mv -f "$APPENDING_TMP" "$PERSIST_PATH/$FINAL_NAME" 2>/dev/null; then
        _consol_append_fail "atomic_rename_appended_failed"
    fi
    rm -rf "$SQLITE_STAGE" 2>/dev/null
    lifecycle_log "info" "consolidate_layers" "bash_consolidate_sqlite_appended" \
        "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"sqsh\":\"$FINAL_NAME\",\"db_count\":$SQLITE_DB_COUNT,\"pre_bytes\":$PRE_APPEND_BYTES,\"post_bytes\":$POST_APPEND_BYTES}" 2>/dev/null || true
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
log "Finalizing stack and clearing RAM..."
log "Note: Active agent terminals may log transient I/O errors during remount. This is expected and resolves automatically."
update_task_status "Finalizing stack..." 95 ""
umount -l "$MNT_POINT" 2>/dev/null || true

# D-353: Reset RAM layer after consolidation (since data is now in base volume)
# BUT: skip the wipe if any writes arrived AFTER the bake marker — those
# writes are NOT in the consolidated volume and would be silently lost.
# Leave them in place; the next commit_stack will pick them up as a delta
# on top of the new consolidated base.
UPPER_CHANGED=""
if [ -f "$CONSOLIDATE_MARKER" ] && [ -d "$UPPER_DIR" ]; then
    UPPER_CHANGED=$(find "$UPPER_DIR" -newer "$CONSOLIDATE_MARKER" -type f 2>/dev/null | head -1)
fi
rm -f "$CONSOLIDATE_MARKER"

if [ -d "$UPPER_DIR" ]; then
    if [ -n "$UPPER_CHANGED" ]; then
        log "Writes detected in upper layer during bake (e.g. $UPPER_CHANGED). Preserving upper to avoid data loss — next commit will delta them onto the consolidated base."
    else
        find "$UPPER_DIR" -mindepth 1 -delete
        find "$WORK_DIR" -mindepth 1 -delete
        sync
    fi
fi

bash "$(dirname "$0")/mount_stack.sh" "$TYPE" "$ID" "$PERSIST_PATH"

FINAL_BYTES=$(stat -c '%s' "$PERSIST_PATH/$FINAL_NAME" 2>/dev/null || echo 0)
lifecycle_log "info" "consolidate_layers" "bash_consolidate_ok" "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"final\":\"$FINAL_NAME\",\"bytes\":$FINAL_BYTES}" 2>/dev/null || true
update_task_status "Consolidation complete." 100 ""
