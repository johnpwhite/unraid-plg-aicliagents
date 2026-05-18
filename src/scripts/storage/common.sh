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
