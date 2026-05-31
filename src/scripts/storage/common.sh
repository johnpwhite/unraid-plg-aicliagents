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

# ---------------------------------------------------------------------------
# Layer identity & ordering (WP: D01/D02/D03 fix — monotonic per-entity seq).
#
# Canonical layer name (current writer):
#   ${type}_${id}_${kind}_${seq10}_${dt}.sqsh
#     kind  = delta | consolidated
#     seq10 = 10-digit zero-padded per-entity monotonic counter (PRIMARY identity
#             + sort key) — immune to wall-clock collisions / NTP step-back.
#     dt    = YYYYMMDDTHHMMSSZ (kept human-readable; secondary sort tiebreak).
#
# Legacy names (still valid lowers; never produced by the current writer) carry
# NO seq and are treated as seq 0 so they always sort BELOW any seq>=1 layer:
#   ${type}_${id}_delta_${dt}.sqsh          (ISO, pre-seq)
#   ${type}_${id}_consolidated_${dt}.sqsh   (ISO, pre-seq)
#   ${type}_${id}_delta_${epoch}.sqsh       (legacy epoch)
#   ${type}_${id}_v${epoch}_vol1.sqsh       (legacy consolidated)
#
# These helpers are the SINGLE SOURCE of layer discovery/ordering — mount_stack.sh,
# storagectl.sh and atomic_write_layer.sh all call them so naming + sort never diverge.

# _layer_parse_seq <name-or-path> -> integer seq (legacy / unparseable = 0).
_layer_parse_seq() {
    local bn seq
    bn="$(basename -- "$1" .sqsh)"
    # Only the new format carries a 10-digit seq between kind and dt.
    seq="$(printf '%s' "$bn" | sed -n -E 's/.*_(delta|consolidated)_([0-9]{10})_[0-9]{8}T[0-9]{6}Z$/\2/p')"
    # 10#-prefix forces base-10 (avoid octal on leading zeros); guard empty -> 0.
    printf '%d' "$((10#${seq:-0}))"
}

# _layer_sort_key <name-or-path> -> "<seq10> <ts>" — newest-first via `LC_ALL=C sort -r`.
# seq dominates, ts (dt/epoch) breaks ties; both fixed-shape so a byte-order reverse
# sort yields seq-desc then dt-desc.
_layer_sort_key() {
    local bn seq ts
    bn="$(basename -- "$1" .sqsh)"
    seq="$(printf '%s' "$bn" | sed -n -E 's/.*_(delta|consolidated)_([0-9]{10})_[0-9]{8}T[0-9]{6}Z$/\2/p')"
    if [ -n "$seq" ]; then
        ts="$(printf '%s' "$bn" | sed -n -E 's/.*_(delta|consolidated)_[0-9]{10}_([0-9]{8}T[0-9]{6}Z)$/\2/p')"
    else
        seq="0000000000"
        ts="$(printf '%s' "$bn" | sed -n -E 's/.*_(delta|consolidated)_([0-9]{8}T[0-9]{6}Z)$/\2/p')"
        [ -z "$ts" ] && ts="$(printf '%s' "$bn" | sed -n -E 's/.*_delta_([0-9]{10,})$/\1/p')"
        [ -z "$ts" ] && ts="$(printf '%s' "$bn" | sed -n -E 's/.*_v([0-9]{10,})_vol1$/\1/p')"
        [ -z "$ts" ] && ts="00000000T000000Z"
    fi
    printf '%s %s' "$seq" "$ts"
}

# _layer_next_seq <persist> <type> <id> -> next per-entity seq (max existing + 1).
# Fresh OR all-legacy (seq 0) entity -> 1, so the first new-format layer sorts above
# any pre-seq layers. Callers hold the per-entity bake lock, so this scan is race-free.
_layer_next_seq() {
    local persist="$1" type="$2" id="$3" max=0 f s
    shopt -s nullglob
    for f in "$persist"/"${type}_${id}_"*.sqsh; do
        [ -e "$f" ] || continue
        s="$(_layer_parse_seq "$f")"
        [ "$s" -gt "$max" ] && max="$s"
    done
    shopt -u nullglob
    printf '%d' "$((max + 1))"
}

# _layer_discover_sorted <persist> <type> <id> -> newest-first FULL PATHS, one/line.
# Single source of discovery ordering (seq desc, then dt desc). A tab separates the
# sort key from the path so `cut -f2-` recovers the path verbatim (paths have no tabs).
_layer_discover_sorted() {
    local persist="$1" type="$2" id="$3" f
    shopt -s nullglob
    for f in "$persist"/"${type}_${id}_"*.sqsh; do
        [ -e "$f" ] || continue
        printf '%s\t%s\n' "$(_layer_sort_key "$f")" "$f"
    done | LC_ALL=C sort -r | cut -f2-
    shopt -u nullglob
}

# ---------------------------------------------------------------------------
# Phase 5: homes-only consolidate policy constants + the effective-MAX helper.
#
# Consolidation is the most expensive + most data-loss-prone storage op, so for
# HOMES it should fire only when absolutely necessary: when the layer stack nears
# the overlay ceiling, or persist is under space pressure, or the user triggers it
# manually. The verdict is computed in storagectl `status` (so it's deterministically
# integration-testable); these constants + helper are the single source of its bounds.
#
# Constants are MEASURED, not guessed (probe on .4, 2026-05-30 — see
# docs/specs/PHASE5_STORAGECTL_DISPATCHER.md "The MAX setting"): up to 45 prod-shaped
# layers mounted with no kernel ceiling and ~2 ms (flat) overlay assembly; the
# `lowerdir=` option string grows ~83 B/layer (3382 B at k=40, under one 4096-B page).
DEFAULT_MAX_LAYERS=30          # home layer ceiling default; consolidate triggers at -2 (28)
CONSOLIDATE_FLOOR=4            # clamp floor (keeps max-2 >= 2)
CONSOLIDATE_HARD_CEILING=40    # clamp ceiling (option string ~3382 B, conservatively < 1 page)
# SCRATCH_MARGIN — bytes of headroom the space-pressure rule requires beyond the
# summed layer sizes (consolidated tempfile + verify scratch + fragmentation). 200 MB.
SCRATCH_MARGIN=$((200 * 1024 * 1024))

# _consolidate_max_layers -> effective home overlay layer ceiling.
# Precedence: AICLI_CONSOLIDATE_MAX_LAYERS env (test / emergency override, mirrors
# the AICLI_ITEST_GUARD test-hook precedent) > cfg key consolidate_max_layers >
# DEFAULT_MAX_LAYERS. Always clamped to [CONSOLIDATE_FLOOR, CONSOLIDATE_HARD_CEILING].
# Self-contained cfg read (common.sh may be sourced WITHOUT resolve_paths.sh, so it
# does not rely on _rp_read_cfg).
_consolidate_max_layers() {
    local raw="" cfg="/boot/config/plugins/unraid-aicliagents/unraid-aicliagents.cfg"
    if [ -n "${AICLI_CONSOLIDATE_MAX_LAYERS:-}" ]; then
        raw="$AICLI_CONSOLIDATE_MAX_LAYERS"
    elif [ -f "$cfg" ]; then
        raw="$(grep -oP '^consolidate_max_layers="?\K[0-9]+' "$cfg" 2>/dev/null | head -1)"
    fi
    # Non-numeric / empty -> default.
    case "$raw" in
        ''|*[!0-9]*) raw="$DEFAULT_MAX_LAYERS" ;;
    esac
    # Clamp to the measured bounds.
    [ "$raw" -lt "$CONSOLIDATE_FLOOR" ] && raw="$CONSOLIDATE_FLOOR"
    [ "$raw" -gt "$CONSOLIDATE_HARD_CEILING" ] && raw="$CONSOLIDATE_HARD_CEILING"
    printf '%d' "$raw"
}

# _entity_paths <type> <id> <persist> — single source of the fstype->upper/work
# derivation + mount-point template shared by the trio (now storage_ops.sh) and
# storagectl. Sets globals: UPPER_DIR, WORK_DIR, MNT_POINT, ENTITY_UPPER_MODE.
# vfat / unknown persist fstype -> ZRAM upper (buffers writes off Flash); any
# durable fstype -> direct disk upper. PURE path computation — no dir creation,
# no zram init (callers do those side-effects). Must match mount_stack's historic
# logic exactly so a bake/consolidate writes from the same upper a mount reads.
_entity_paths() {
    local _ep_type="$1" _ep_id="$2" _ep_persist="$3" _ep_fst
    _ep_fst=$(findmnt --noheadings --output FSTYPE --target "$_ep_persist" 2>/dev/null || echo '')
    if [ "$_ep_fst" = "vfat" ] || [ -z "$_ep_fst" ]; then
        ENTITY_UPPER_MODE="zram"
        UPPER_DIR="$ZRAM_BASE/${_ep_type}s/$_ep_id/upper"
        WORK_DIR="$ZRAM_BASE/${_ep_type}s/$_ep_id/work"
    else
        ENTITY_UPPER_MODE="disk"
        UPPER_DIR="$_ep_persist/_upper/${_ep_type}s/$_ep_id"
        WORK_DIR="$_ep_persist/_work/${_ep_type}s/$_ep_id"
    fi
    if [ "$_ep_type" = "home" ]; then
        MNT_POINT="/tmp/unraid-aicliagents/work/$_ep_id/home"
    else
        MNT_POINT="/usr/local/emhttp/plugins/unraid-aicliagents/agents/$_ep_id"
    fi
}

# home_mount_in_use <mount_point>
# True (0) if a LIVE INTERACTIVE agent/terminal session is using this home mount
# — i.e. one a umount/remount would disrupt with ENOENT. Used to gate the
# post-bake reclaim (refresh + ZRAM cleanup) so it never remounts under a live
# session, only when the home is genuinely idle.
#
# WHY NOT a bare `HOME=<mount>` environ scan (the obvious approach, and what an
# earlier cut of this did): the plugin's OWN permanent daemons run with
# HOME=<mount> for the plugin's whole lifetime — the per-user session
# `dbus-daemon --session` and the `secret-service-daemon` (its keyring store
# lives under <mount>/.local/share). A HOME= scan matches those, so the home
# would read "busy" FOREVER and reclaim would never run (zram never reclaimed).
# Orphaned detached `tmux` from an unreaped close has the same effect. Neither is
# an interactive session a user is connected to.
#
# WHY ttyd: every interactive workspace/terminal session is fronted by a live
# `ttyd` whose child env (on its argv: `... env AICLI_HOME=<mount> ... aicli-shell.sh`)
# carries AICLI_HOME=<mount>. ttyd presence = a user is actually connected and the
# agent may touch HOME at any moment (e.g. claude's mkdir ~/.claude/session-env/
# <uuid>) — exactly the op that ENOENTs in the unmounted window. When all such
# sessions close, no ttyd carries this mount, so reclaim proceeds (daemons persist
# but only touch HOME rarely; that residual matches pre-fix behaviour).
#
# fuser -sm is kept as a fast path for any process holding a real open fd / cwd /
# exe on the fs (covers agent overlays and any directly-held handle).
#
# Best-effort: runs as root. Transient PIDs vanishing mid-scan are ignored.
home_mount_in_use() {
    local mnt="$1"
    [ -n "$mnt" ] || return 1
    # Fast path: any open fd / cwd / exe / mmap physically on the fs.
    fuser -sm "$mnt" 2>/dev/null && return 0
    # Live interactive session: a ttyd whose argv carries AICLI_HOME=<mount>.
    local _pid
    for _pid in $(pgrep -x ttyd 2>/dev/null); do
        if tr '\0' '\n' < "/proc/$_pid/cmdline" 2>/dev/null | grep -qxF "AICLI_HOME=$mnt"; then
            return 0
        fi
    done
    return 1
}

# WP #1263 (#7): canonical per-entity overlay-mount-op lock path.
#
# Every overlay (re)mount of an entity routes through op_mount — the launch path
# (StorageMountService::ensureHomeMounted -> storagectl mount -> op_mount), the
# post-bake reclaim refresh (op_bake -> op_mount), and the post-consolidate
# remount (op_consolidate -> op_mount) all call it. Taking this flock INSIDE
# op_mount therefore serialises a launch's stack assembly against a concurrent
# reclaim/consolidate remount, closing the residual race that the
# home_mount_in_use defer leaves open: a launch begins before its ttyd exists, so
# the home momentarily reads "idle" and a supervisor reclaim could umount/remount
# the overlay out from under the assembling launch.
#
# It is a SEPARATE lock from the per-entity bake lock
# (/var/run/aicli-bake-<type>-<id>.lock): the bake lock guards the whole
# squashfs write, which is safe during a live session and must NOT block a launch
# for minutes — only the brief remount needs mutual exclusion. It is also
# separate from PHP's home_mount lock (which serialises PHP-vs-PHP launches);
# making op_mount block on THAT file would deadlock (PHP holds it across the
# op_mount exec). Sanitisation matches storage_ops.sh _LOCK_ID ([^a-zA-Z0-9_-] -> _).
mount_op_lock_path() {
    local _type="${1:-home}" _id="${2:-}"
    local _safe="${_id//[^a-zA-Z0-9_-]/_}"
    echo "/var/run/aicli-mount-op-${_type}-${_safe}.lock"
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

    # (b1.6) Chat/session-store defence-in-depth (DATA-LOSS guard).
    # Agent conversation history (Claude .jsonl transcripts, Antigravity .pb
    # conversations + index, gemini chat logs, factory/codex/pi sessions, kilo/
    # opencode prompt history) is durable USER state — the same class as SQLite
    # above and the .gemini/tmp chats WP #931 carved out of the prune. Once an
    # agent closes the file it has no open write fd, and with mtime <= marker it
    # would otherwise be admitted to the wipe set. If the just-run bake/consolidate
    # failed to durably capture it first (stale lowerdir, or a busy-cooldown
    # skipped/deferred bake), reclaiming it is PERMANENT loss — the bug that
    # destroyed a user's Claude + Antigravity conversations across an upgrade.
    # Protect these paths unconditionally; a never-reclaimed conversation merely
    # lingers in zram until a later bake captures it (KB-sized; negligible).
    # NOTE: when adding a new agent, add its conversation/session store here.
    while IFS= read -r _chat_f; do
        [ -n "$_chat_f" ] && printf '%s\n' "$_chat_f" >> "$tmpdir/excludes"
    done < <(find "$upper" -type f \( \
        -path '*/.claude/projects/*' -o \
        -path '*/.claude/sessions/*' -o \
        -path '*/.claude/history.jsonl' -o \
        -path '*/.gemini/antigravity-cli/conversations/*' -o \
        -path '*/.gemini/antigravity-cli/cache/last_conversations.json' -o \
        -path '*/.gemini/tmp/*/chats/*' -o \
        -path '*/.gemini/projects.json' -o \
        -path '*/.gemini/config/projects/*' -o \
        -path '*/.factory/sessions/*' -o \
        -path '*/.codex/sessions/*' -o \
        -path '*/.pi/agent/sessions/*' -o \
        -path '*/.local/state/*/prompt-history.jsonl' \
        \) 2>/dev/null)

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

    # Sweep newly-empty directories. -mindepth 1 is critical: without it, find
    # will delete $upper itself when it becomes empty after a complete bake —
    # the kernel overlay stays mounted but the directory entry is gone, causing
    # all subsequent writes (including token refresh write-back) to fail ENOENT.
    # See WP #1224 for the incident post-mortem.
    find "$upper" -mindepth 1 -type d -empty -delete 2>/dev/null

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

# build_mksquashfs_sqlite_sidecar_excludes <root> <db-paths...>
# Echoes `-e <relpath>-wal -e <relpath>-shm -e <relpath>-journal` arguments —
# the SIDECAR files only, NOT the .db itself. Used by the overlay-merge bake
# path (WP #1078): the .db is provided via the overlay upper from the
# sqlite3 .backup snapshot, so it doesn't need exclusion; the sidecars are
# transient and reconstructed by SQLite on next open.
build_mksquashfs_sqlite_sidecar_excludes() {
    local root="$1"; shift
    local db rel
    for db in "$@"; do
        rel="${db#$root/}"
        printf -- '-e %s -e %s -e %s ' "${rel}-wal" "${rel}-shm" "${rel}-journal"
    done
}

# bake_via_overlay_merge <type> <id> <persist_path> <lower_dir> <sqlite_stage> <kind> <db-paths...>
#
# Bakes a SquashFS layer from the merged view of <lower_dir> + <sqlite_stage>.
# Stdout: final basename on success. Stderr: progress + diagnostics.
# Returns 0 on success, non-zero on any failure (overlay mount, atomic_write_layer, umount).
#
# WP #1078 fix: replaces the broken two-pass `wide-bake + mksquashfs -append`
# protocol. mksquashfs's append mode does NOT merge new content into existing
# directories — it RENAMES them with _N suffix, stranding the SQLite backups
# at unreachable paths (.copilot_1/session-store.db etc.). This helper uses
# overlayfs to merge the lower (UPPER_DIR for commit, MNT_POINT for consolidate)
# with the SQLite backup stage, then bakes the merged view in ONE atomic pass.
# The .db sidecar files (-wal/-shm/-journal) are excluded — SQLite reconstructs
# them from the .db on next open.
#
# Caller must have already populated $sqlite_stage via sqlite_backup_all.
# Caller is responsible for `rm -rf "$sqlite_stage"` after this returns.
bake_via_overlay_merge() {
    local type="${1:-}"; shift
    local id="${1:-}"; shift
    local persist_path="${1:-}"; shift
    local lower_dir="${1:-}"; shift
    local sqlite_stage="${1:-}"; shift
    local kind="${1:-delta}"; shift
    # remaining positional args are the detected SQLite DB paths (absolute, under $lower_dir)

    if [ -z "$type" ] || [ -z "$id" ] || [ -z "$persist_path" ] || [ -z "$lower_dir" ] || [ -z "$sqlite_stage" ]; then
        echo "[bake_via_overlay_merge] ERROR: missing required arguments" >&2
        return 1
    fi
    if [ ! -d "$lower_dir" ] || [ ! -d "$sqlite_stage" ]; then
        echo "[bake_via_overlay_merge] ERROR: lower_dir or sqlite_stage missing" >&2
        return 1
    fi

    # Per-invocation working dirs on tmpfs (overlay requires upper + work on same fs).
    local merge_root="/tmp/unraid-aicliagents/.bake_merge_${type}_${id}_$$"
    local merge_work="$merge_root/work"
    local merge_out="$merge_root/merged"
    rm -rf "$merge_root" 2>/dev/null
    mkdir -p "$merge_work" "$merge_out" 2>/dev/null || {
        echo "[bake_via_overlay_merge] ERROR: cannot create merge dirs under $merge_root" >&2
        return 1
    }

    # Mount overlay: lower=lower_dir (read-only logically), upper=sqlite_stage (shadows
    # the live DBs at their original paths), work=fresh tmpfs dir.
    if ! mount -t overlay overlay \
        -o "lowerdir=${lower_dir},upperdir=${sqlite_stage},workdir=${merge_work}" \
        "$merge_out" 2>/dev/null; then
        echo "[bake_via_overlay_merge] ERROR: overlay mount failed: lower=$lower_dir upper=$sqlite_stage" >&2
        rm -rf "$merge_root" 2>/dev/null
        return 1
    fi

    # Build sidecar excludes (relative to lower_dir, which is the overlay's effective root).
    local sidecar_excludes
    # shellcheck disable=SC2086 — intentional word-split on positional args
    sidecar_excludes=$(build_mksquashfs_sqlite_sidecar_excludes "$lower_dir" "$@")

    # Compose final mksquashfs args. Preserve any caller-supplied MKSQUASHFS_ARGS
    # (e.g. compression override from supervisor), append sidecar excludes.
    local base_args="${MKSQUASHFS_ARGS:-${_AWL_DEFAULT_ARGS:--comp xz -Xbcj x86 -Xdict-size 100% -b 1M -no-exports -noappend}}"
    local final_args="$base_args $sidecar_excludes"

    local result=""
    if ! result=$(MKSQUASHFS_ARGS="$final_args" atomic_write_layer "$type" "$id" "$persist_path" "$merge_out" "$kind"); then
        umount "$merge_out" 2>/dev/null || umount -l "$merge_out" 2>/dev/null || true
        rm -rf "$merge_root" 2>/dev/null
        echo "[bake_via_overlay_merge] ERROR: atomic_write_layer failed" >&2
        return 1
    fi

    # Cleanup overlay. Lazy umount is the safety net for cases where a stray fd
    # held the merge_out path open during bake (shouldn't happen, but harmless).
    umount "$merge_out" 2>/dev/null || umount -l "$merge_out" 2>/dev/null || true
    rm -rf "$merge_root" 2>/dev/null

    printf '%s\n' "$result"
    return 0
}

# write_defer_reason <type> <id> <reason>
# Writes a single-line reason marker for callers (StorageMountService / TaskService)
# to disambiguate the three legitimate exit-2 paths (mount_busy / sqlite_backup_deferred
# / consolidate_lock_held). Best-effort — failure to write does not abort the bake.
# Path: /tmp/unraid-aicliagents/.bake_defer_reason_${type}_${id}
# PHP side is responsible for unlink-after-read so stale reasons don't bleed across runs.
write_defer_reason() {
    local type="${1:-}"
    local id="${2:-}"
    local reason="${3:-unknown}"
    [ -n "$type" ] && [ -n "$id" ] || return 0
    local sanitised_id="${id//[^a-zA-Z0-9_-]/_}"
    local marker="/tmp/unraid-aicliagents/.bake_defer_reason_${type}_${sanitised_id}"
    # Truncate to a single short line; reason should be a stable identifier.
    printf '%s\n' "$reason" > "$marker" 2>/dev/null || true
}
