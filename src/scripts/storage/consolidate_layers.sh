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
        write_defer_reason "$TYPE" "$ID" "mount_busy"
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
bash "$(dirname "$0")/mount_stack.sh" "$TYPE" "$ID" "$PERSIST_PATH"

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
