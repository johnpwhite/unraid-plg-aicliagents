#!/bin/bash
# AICliAgents: Shared Storage Functions
# Sourced by all storage scripts for consistent validation and logging.

PLUGIN_BIN="/usr/local/emhttp/plugins/unraid-aicliagents/bin"
ZRAM_BASE="/tmp/unraid-aicliagents/zram_upper"
DEBUG_LOG="/tmp/unraid-aicliagents/debug.log"

# Ensure debug log directory exists
mkdir -p "$(dirname "$DEBUG_LOG")"

export PATH="$PLUGIN_BIN:$PATH"

get_ts() { date '+%Y-%m-%d %H:%M:%S'; }

# guard_path: Validate a path before destructive operations.
# Usage: guard_path "/some/path" "description"
# Returns 1 if the path is unsafe (empty, root, or outside allowed prefixes).
guard_path() {
    local path="$1"
    local label="${2:-path}"

    # Reject empty paths
    if [ -z "$path" ]; then
        echo "[$(get_ts)] [ERR!] [guard_path] $label is empty" >> "$DEBUG_LOG"
        return 1
    fi

    # Reject root or near-root paths
    if [ "$path" = "/" ] || [ "$path" = "/tmp" ] || [ "$path" = "/mnt" ] || [ "$path" = "/usr" ]; then
        echo "[$(get_ts)] [ERR!] [guard_path] $label is a system root: $path" >> "$DEBUG_LOG"
        return 1
    fi

    # Allowlist of plugin-internal prefixes (work / cache / config / staging dirs).
    # These are hardcoded because they must always be writable and the user can't
    # relocate them.
    local allowed=0
    case "$path" in
        /tmp/unraid-aicliagents/zram_upper|/tmp/unraid-aicliagents/zram_upper/*) allowed=1 ;;
        /tmp/unraid-aicliagents|/tmp/unraid-aicliagents/*) allowed=1 ;;
        /usr/local/emhttp/plugins/unraid-aicliagents|/usr/local/emhttp/plugins/unraid-aicliagents/*) allowed=1 ;;
        /boot/config/plugins/unraid-aicliagents|/boot/config/plugins/unraid-aicliagents/*) allowed=1 ;;
    esac

    # User-configurable persistence paths under /mnt/. Allow any pool except the
    # known-system tmpfs mounts that aren't real persistence (sized 1024k by
    # Unraid for plugins like unassigned-devices). Rejecting only the explicit
    # system mounts means Unraid user-pools (e.g. /mnt/cache, /mnt/cache_nvme,
    # /mnt/scratch_old, /mnt/zfs_pool, custom names from disks.ini) all work.
    if [ "$allowed" -ne 1 ]; then
        case "$path" in
            /mnt/disks|/mnt/disks/*)       : ;;  # unassigned-devices tmpfs
            /mnt/remotes|/mnt/remotes/*)   : ;;  # network shares tmpfs
            /mnt/rootshare|/mnt/rootshare/*) : ;;
            /mnt/addons|/mnt/addons/*)     : ;;
            /mnt/*/*)                       allowed=1 ;;  # any pool: /mnt/<name>/<sub>
            /mnt/disk[0-9]*)                allowed=1 ;;  # array disks: /mnt/disk1, /mnt/disk2, ...
        esac
    fi

    if [ "$allowed" -ne 1 ]; then
        echo "[$(get_ts)] [ERR!] [guard_path] $label outside allowed prefixes: $path" >> "$DEBUG_LOG"
        return 1
    fi

    return 0
}

# _assert_persist_durable: Validate that a persistence path is on a durable filesystem.
# Uses findmnt to resolve the actual fstype — immune to tmpfs bind-mounts that look like
# valid paths. Spec #341.
# Usage: _assert_persist_durable "/some/persist/path"
# Returns 1 (and logs) if non-durable or findmnt fails; caller must exit on failure.
_assert_persist_durable() {
    local path="$1"
    if ! command -v findmnt >/dev/null 2>&1; then
        echo "[$(get_ts)] [ERR!] [_assert_persist_durable] findmnt not found — cannot validate fstype for: $path" >> "$DEBUG_LOG"
        return 1
    fi
    local fstype
    fstype=$(findmnt --noheadings --output FSTYPE --target "$path" 2>/dev/null) || {
        echo "[$(get_ts)] [ERR!] [_assert_persist_durable] findmnt failed or path not mounted: $path" >> "$DEBUG_LOG"
        return 1
    }
    case "$fstype" in
        ext4|xfs|btrfs|vfat|exfat|f2fs|ntfs|fuseblk)
            return 0 ;;
        tmpfs|ramfs|devtmpfs|overlay|zram|squashfs)
            echo "[$(get_ts)] [ERR!] [_assert_persist_durable] path '$path' is on $fstype (non-durable) — bake refused" >> "$DEBUG_LOG"
            return 1 ;;
        *)
            echo "[$(get_ts)] [WARN] [_assert_persist_durable] unknown fstype '$fstype' for '$path' — treating as durable" >> "$DEBUG_LOG"
            return 0 ;;
    esac
}

# check_disk_space: Ensure sufficient space before writing.
# Usage: check_disk_space "/target/path" <required_mb>
# Returns 1 if insufficient space.
check_disk_space() {
    local target_path="$1"
    local required_mb="${2:-100}"

    # Get available space in MB on the filesystem containing target_path
    local avail_mb
    avail_mb=$(df -Pm "$(dirname "$target_path")" 2>/dev/null | awk 'NR==2 {print $4}')

    if [ -z "$avail_mb" ]; then
        echo "[$(get_ts)] [WARN] [check_disk_space] Cannot determine free space for $target_path" >> "$DEBUG_LOG"
        return 0
    fi

    if [ "$avail_mb" -lt "$required_mb" ]; then
        echo "[$(get_ts)] [ERR!] [check_disk_space] Only ${avail_mb}MB free on $(dirname "$target_path"), need ${required_mb}MB" >> "$DEBUG_LOG"
        return 1
    fi

    return 0
}

# WP #922: Flash-backed failure-snapshot helpers.
#
# When a storage script (consolidate_layers.sh, commit_stack.sh, …) exits
# non-zero, capture the last 50 lines of DEBUG_LOG to a Flash-backed file so
# the cause survives /tmp rotation. Also drop a short stderr-tail snippet the
# supervisor can splice into its lifecycle event for at-a-glance diagnosis
# without opening the per-failure file.
#
# Flash wear footprint: ~5 KB per failure, capped at 50 files. Trivial.
FAILURE_DIR="/boot/config/plugins/unraid-aicliagents/failures"

# snapshot_failure <type> <id> <exit_code> <source>
# Best-effort — never fails, always returns 0.
snapshot_failure() {
    local f_type="${1:-unknown}"
    local f_id="${2:-unknown}"
    local f_exit="${3:-1}"
    local f_source="${4:-storage_script}"
    local ts
    ts=$(date -u '+%Y%m%dT%H%M%SZ' 2>/dev/null || date '+%Y%m%dT%H%M%S')

    mkdir -p "$FAILURE_DIR" 2>/dev/null || return 0

    local safe_type safe_id
    safe_type=$(printf '%s' "$f_type" | tr -c 'A-Za-z0-9_.-' '_')
    safe_id=$(printf '%s' "$f_id"   | tr -c 'A-Za-z0-9_.-' '_')

    local out="$FAILURE_DIR/${f_source}_${safe_type}_${safe_id}_${ts}.log"
    {
        echo "# AICliAgents failure snapshot"
        echo "# source: $f_source"
        echo "# entity: $f_type/$f_id"
        echo "# exit_code: $f_exit"
        echo "# captured_at: $(date -u '+%Y-%m-%dT%H:%M:%SZ' 2>/dev/null || date)"
        echo "# debug.log tail (last 50 lines):"
        echo "# ----"
        [ -f "$DEBUG_LOG" ] && tail -n 50 "$DEBUG_LOG" 2>/dev/null
    } > "$out" 2>/dev/null || true

    # stderr_tail for the supervisor's lifecycle event — per-entity so
    # concurrent failures don't collide.
    local tail_path="/tmp/unraid-aicliagents/.stderr_tail_${safe_type}_${safe_id}.txt"
    [ -f "$DEBUG_LOG" ] && tail -n 10 "$DEBUG_LOG" 2>/dev/null > "$tail_path" 2>/dev/null || true

    # Rotate the failures dir — keep the 50 most-recent snapshots; older ones
    # are unlinked. Flash-cheap, avoids unbounded growth.
    ls -1t "$FAILURE_DIR" 2>/dev/null | tail -n +51 | while read -r oldfile; do
        [ -n "$oldfile" ] && rm -f "$FAILURE_DIR/$oldfile" 2>/dev/null || true
    done

    return 0
}

# install_failure_trap <type> <id> <source>
# Registers an EXIT trap that calls snapshot_failure on non-zero exit, except
# for exit code 2 (which means "deferred — busy", not a failure). Call near
# the top of any storage script that wants the snapshot behaviour.
install_failure_trap() {
    local t_type="${1:-unknown}"
    local t_id="${2:-unknown}"
    local t_source="${3:-storage_script}"
    # shellcheck disable=SC2064 — intentional eager expansion of $t_* here.
    trap "ec=\$?; if [ \$ec -ne 0 ] && [ \$ec -ne 2 ]; then snapshot_failure '$t_type' '$t_id' \$ec '$t_source'; fi" EXIT
}

# ----------------------------------------------------------------------------
# WP #935: SQLite live-backup helpers + selective UPPER cleanup.
# See docs/specs/CONSOLIDATE_LIVE_BACKUP.md for the design.
# ----------------------------------------------------------------------------

# detect_sqlite_dbs <root>
# Echoes one absolute path per line for every SQLite-format-3 file found under
# $root. M1 fix (v2026.05.18.08): magic-byte sniffs EVERY regular file rather
# than only those matching *.db/*.sqlite/*.sqlite3 — third-party agents may use
# non-standard extensions (.db3, .s3db, no extension). The sniff cost is one
# 16-byte read per file; UPPER is typically <100K files on tmpfs, so well under
# a second. We still skip files >2GB (SQLite header is always within the first
# 16 bytes and large non-DB files like archives would be wasteful to sniff).
# WAL/SHM siblings (*.db-wal, *.db-shm) intentionally NOT included — they have
# their own magic bytes ("ZP" for WAL, internal for SHM) and the caller's
# build_mksquashfs_sqlite_excludes generates -wal/-shm exclusions from each
# parent .db automatically.
detect_sqlite_dbs() {
    local root="$1"
    [ -d "$root" ] || return 0
    find "$root" -type f -size -2147483648c 2>/dev/null \
        | while IFS= read -r f; do
            if head -c 16 "$f" 2>/dev/null | grep -q "SQLite format 3"; then
                printf '%s\n' "$f"
            fi
        done
}

# sqlite_backup_all <root> <staging> <db-paths...>
# For each db path, runs `sqlite3 X ".backup STAGING/relative/X"`. Online Backup
# API works on a live DB without preventing concurrent writers. Returns:
#   0 — all DBs backed up successfully
#   2 — at least one backup failed (locked / disk full / timeout); caller should defer
sqlite_backup_all() {
    local root="$1"; shift
    local staging="$1"; shift
    if ! command -v sqlite3 >/dev/null 2>&1; then
        echo "[sqlite_backup_all] WARN: sqlite3 not on PATH; cannot back up SQLite DBs" >&2
        return 2
    fi
    mkdir -p "$staging" 2>/dev/null || return 1
    local db rel dest
    for db in "$@"; do
        [ -f "$db" ] || continue
        rel="${db#$root/}"
        dest="$staging/$rel"
        mkdir -p "$(dirname "$dest")" 2>/dev/null
        # 30 s timeout — if a writer holds an exclusive lock that long, defer.
        if ! timeout 30 sqlite3 "$db" ".timeout 30000
.backup '$dest'" 2>/dev/null; then
            echo "[sqlite_backup_all] backup failed: $db (locked / disk full / timeout)" >&2
            return 2
        fi
    done
    return 0
}

# selective_upper_cleanup <upper_dir> <marker_file>
# Wipes files in upper_dir that satisfy BOTH:
#   (a) mtime not newer than the marker (no writes during bake), AND
#   (b) no process holds an open write fd to the file
# Prints a JSON status line to stdout for the caller to inline into a
# lifecycle event. Best-effort — never fails the caller.
selective_upper_cleanup() {
    local upper="$1"
    local marker="$2"
    if [ ! -d "$upper" ] || [ ! -f "$marker" ]; then
        echo '{"wiped_bytes":0,"wiped_count":0,"residual_bytes":0,"residual_files":0}'
        return 0
    fi

    local tmpdir
    tmpdir=$(mktemp -d 2>/dev/null) || tmpdir="/tmp/.suc-$$"
    mkdir -p "$tmpdir" 2>/dev/null

    # (a) Candidate set: files older-or-equal mtime to marker.
    find "$upper" -type f ! -newer "$marker" 2>/dev/null | sort -u > "$tmpdir/candidates"

    # (b1) SQLite-aware exclusion (C1 fix, v2026.05.18.08): SQLite WAL-mode
    # readers hold the .db open as O_RDONLY + mmap. Our /proc/*/fdinfo flags
    # check only catches write-mode fds, so a read-only mmap'd .db with old
    # mtime would otherwise be admitted to the wipe set. If sqlite_backup_all
    # deferred this cycle OR the append failed silently, the bake doesn't
    # contain the .db — wiping it would be permanent loss. Add every
    # detected SQLite .db (plus its -wal and -shm siblings) to excludes
    # unconditionally. This is defence in depth: the canonical preservation
    # path is the three-pass bake (detect → backup → append), but if anything
    # in that chain breaks, this stops the wipe.
    : > "$tmpdir/excludes"
    local _sqlite_db
    while IFS= read -r _sqlite_db; do
        [ -n "$_sqlite_db" ] || continue
        printf '%s\n' "$_sqlite_db" >> "$tmpdir/excludes"
        printf '%s\n' "${_sqlite_db}-wal" >> "$tmpdir/excludes"
        printf '%s\n' "${_sqlite_db}-shm" >> "$tmpdir/excludes"
        printf '%s\n' "${_sqlite_db}-journal" >> "$tmpdir/excludes"
    done < <(detect_sqlite_dbs "$upper" 2>/dev/null)

    # (b2) Open-fd exclusion: files with an open write fd anywhere in /proc.
    # Walk /proc/*/fd, resolve each symlink, check fdinfo flags for write access.
    # flags is an octal string ending in 1 (O_WRONLY) or 2 (O_RDWR) means write.
    local fd_dir fd target pid_fdinfo flags last_char
    for fd_dir in /proc/[0-9]*/fd; do
        [ -d "$fd_dir" ] || continue
        for fd in "$fd_dir"/*; do
            [ -L "$fd" ] || continue
            target=$(readlink "$fd" 2>/dev/null) || continue
            case "$target" in
                "$upper"/*) ;;
                *) continue ;;
            esac
            pid_fdinfo="${fd_dir%/fd}/fdinfo/$(basename "$fd")"
            [ -f "$pid_fdinfo" ] || continue
            flags=$(awk '/^flags:/ {print $2}' "$pid_fdinfo" 2>/dev/null)
            # Octal flag's last digit: 0=O_RDONLY, 1=O_WRONLY, 2=O_RDWR, 3=O_RDWR|O_NONBLOCK, etc.
            last_char="${flags: -1}"
            case "$last_char" in
                1|2|3) printf '%s\n' "$target" >> "$tmpdir/excludes" ;;
            esac
        done
    done 2>/dev/null
    sort -u "$tmpdir/excludes" -o "$tmpdir/excludes" 2>/dev/null

    # Wipe set = candidates - excludes
    comm -23 "$tmpdir/candidates" "$tmpdir/excludes" > "$tmpdir/wipe" 2>/dev/null

    local wiped_bytes=0 wiped_count=0 bytes
    while IFS= read -r f; do
        [ -n "$f" ] && [ -f "$f" ] || continue
        bytes=$(stat -c '%s' "$f" 2>/dev/null || echo 0)
        if rm -f "$f" 2>/dev/null; then
            wiped_bytes=$((wiped_bytes + bytes))
            wiped_count=$((wiped_count + 1))
        fi
    done < "$tmpdir/wipe"

    # Sweep newly-empty directories.
    find "$upper" -type d -empty -delete 2>/dev/null

    local residual_bytes residual_files
    residual_bytes=$(du -sb "$upper" 2>/dev/null | awk '{print $1}')
    residual_bytes="${residual_bytes:-0}"
    residual_files=$(find "$upper" -type f 2>/dev/null | wc -l | tr -d ' ')
    residual_files="${residual_files:-0}"

    rm -rf "$tmpdir" 2>/dev/null

    printf '{"wiped_bytes":%d,"wiped_count":%d,"residual_bytes":%s,"residual_files":%s}\n' \
        "$wiped_bytes" "$wiped_count" "$residual_bytes" "$residual_files"
    return 0
}

# build_mksquashfs_sqlite_excludes <root> <db-paths...>
# Echoes the `-e <relpath>` arguments to pass to mksquashfs for excluding each
# SQLite DB and its WAL/SHM siblings. Caller splits the output on whitespace
# and passes to mksquashfs.
build_mksquashfs_sqlite_excludes() {
    local root="$1"; shift
    local db rel
    for db in "$@"; do
        rel="${db#$root/}"
        printf -- '-e %s -e %s -e %s ' "$rel" "${rel}-wal" "${rel}-shm"
    done
}
