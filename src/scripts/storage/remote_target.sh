#!/bin/bash
# remote_target.sh — HOME_BACKUP.md "Network backup places" (#749).
# A home backup can go to a network share, for example /mnt/remotes/stor01-Backup-Apps.
# A network share can be gone: the server is off, or the share is not mounted.
# Then the folder under /mnt/remotes is an empty folder in RAM, or a soft mount
# (a mount that gives up after a time) that returns an error on each write.
# A backup must never go to that empty folder, and an empty folder must never
# lead to a decision about old snapshots.
#
# Two ways to use this file:
#   source it:  _backup_target_check <path> <backup|restore>   (sets RT_REASON)
#   run it:     remote_target.sh check <path> <backup|restore>
#               (prints the reason and exits 1 when the place is not ready)
#
# What it checks, for a place on a network share only (a local place passes at once):
#   1. The nearest mount of the path is a real mount of a network share. A path under
#      /mnt/remotes must have its own mount below /mnt/remotes.
#   2. The place answers. A backup writes a small temp file and removes it.
#      A restore only reads the folder (a restore with a safety snapshot also writes,
#      and the caller asks for "backup" for that place).
#   Each probe has a time limit. A share that does not answer is "not ready".
#
# This file reads the mount table and nothing else until step 2. It never lists
# or stats a folder below a mount before step 1 has passed (a dead mount can block).
#
# Test seams (production leaves them unset):
#   AICLI_PROC_MOUNTS   a file in /proc/mounts format
#   AICLI_REMOTES_ROOT  the folder of the remote shares (default /mnt/remotes)
#   AICLI_REMOTE_PROBE_SECS  time limit of the probe in seconds (default 20)

RT_REASON=""

# Mount types that are network shares.
_rt_net_fs() {
    case "${1:-}" in
        nfs|nfs4|cifs|smb3|smbfs|fuse.sshfs|fuse.rclone|davfs|fuse.davfs|9p) return 0 ;;
    esac
    return 1
}

# _rt_norm <path> — the path without "." and ".." parts and without a trailing slash.
# It does not follow links and does not touch the disk.
_rt_norm() {
    realpath -ms -- "${1:-}" 2>/dev/null || printf '%s' "${1%/}"
}

# _rt_nearest_mount <path> — sets RT_MOUNT and RT_FSTYPE to the mount with the longest
# mount point that holds <path>. Both are empty when the table has no such mount.
_rt_nearest_mount() {
    local p dev mp fs rest best="" bestfs=""
    p="$(_rt_norm "${1:-}")"
    RT_MOUNT=""; RT_FSTYPE=""
    while read -r dev mp fs rest; do
        mp="${mp//\\040/ }"
        mp="${mp//\\011/	}"
        [ -n "$mp" ] || continue
        if [ "$mp" = "/" ] || [ "$p" = "$mp" ] || [ "${p#"$mp"/}" != "$p" ]; then
            if [ "${#mp}" -ge "${#best}" ]; then best="$mp"; bestfs="$fs"; fi
        fi
    done < "${AICLI_PROC_MOUNTS:-/proc/mounts}"
    RT_MOUNT="$best"; RT_FSTYPE="$bestfs"
}

# _rt_remotes_root — the folder of the remote shares, no trailing slash.
_rt_remotes_root() { local r="${AICLI_REMOTES_ROOT:-/mnt/remotes}"; printf '%s' "${r%/}"; }

# _rt_under_remotes <path> — 0 when the path is below the remotes folder.
_rt_under_remotes() {
    local p root; p="$(_rt_norm "${1:-}")"; root="$(_rt_remotes_root)"
    [ "${p#"$root"/}" != "$p" ]
}

# _backup_target_is_network <path> — 0 when the place is on a network share, or where
# a network share should be (below the remotes folder).
_backup_target_is_network() {
    _rt_under_remotes "${1:-}" && return 0
    _rt_nearest_mount "${1:-}"
    _rt_net_fs "$RT_FSTYPE"
}

# _backup_target_mounted <path> — 0 when the place is local, or on a real network mount.
# Reads the mount table only.
_backup_target_mounted() {
    local root; root="$(_rt_remotes_root)"
    _backup_target_is_network "${1:-}" || return 0
    _rt_nearest_mount "${1:-}"
    if _rt_under_remotes "${1:-}"; then
        # The mount point must be below the remotes folder: "/mnt/remotes" itself
        # and "/" are not the share.
        [ -n "$RT_MOUNT" ] && [ "${RT_MOUNT#"$root"/}" != "$RT_MOUNT" ] || return 1
    fi
    _rt_net_fs "$RT_FSTYPE" || _rt_under_remotes "${1:-}"
}

# _rt_probe_dir <path> — the nearest folder of <path> that exists, found with a
# time limit. Prints it. Returns 1 when nothing answers.
_rt_probe_dir() {
    local p="$1" secs="${AICLI_REMOTE_PROBE_SECS:-20}" mp="$2"
    while [ -n "$p" ] && [ "$p" != "/" ]; do
        if _rt_run_limited "$secs" test -d "$p"; then printf '%s' "$p"; return 0; fi
        [ "$p" = "$mp" ] && break
        p="$(dirname "$p")"
    done
    return 1
}

# _rt_run_limited <secs> <command...> — run with a time limit when `timeout` exists.
_rt_run_limited() {
    local secs="$1"; shift
    if command -v timeout >/dev/null 2>&1; then timeout "$secs" "$@"; else "$@"; fi
}

# _backup_target_check <path> <backup|restore>
# Returns 0 when the place is ready. Otherwise it sets RT_REASON to one plain sentence
# and returns 1. A local place always passes (the callers check it in their own way).
_backup_target_check() {
    local path mode="${2:-backup}" secs="${AICLI_REMOTE_PROBE_SECS:-20}" dir
    RT_REASON=""
    path="$(_rt_norm "${1:-}")"
    [ -n "$path" ] || { RT_REASON="The backup place is empty."; return 1; }
    _backup_target_is_network "$path" || return 0
    if ! _backup_target_mounted "$path"; then
        RT_REASON="The backup place $path is on a network share, and the share is not mounted. Mount the share, then try again."
        return 1
    fi
    dir="$(_rt_probe_dir "$path" "$RT_MOUNT")" || dir=""
    if [ -z "$dir" ]; then
        RT_REASON="The backup place $path is on a network share that does not answer. Check that the share is online, then try again."
        return 1
    fi
    if [ "$mode" = "backup" ]; then
        # A small write test: make a temp file, write one byte, remove it.
        if ! _rt_run_limited "$secs" bash -c 'f="$(mktemp "$1/.aicli-probe.XXXXXX")" && printf x > "$f" && rm -f "$f"' _ "$dir" >/dev/null 2>&1; then
            RT_REASON="The backup place $path is mounted, but the plugin cannot write to it. Check that the share is online and not read-only."
            return 1
        fi
    else
        # A read test: list the folder.
        if ! _rt_run_limited "$secs" ls -- "$dir" >/dev/null 2>&1; then
            RT_REASON="The backup place $path is mounted, but it does not answer. Check that the share is online, then try again."
            return 1
        fi
    fi
    return 0
}

# Run mode: remote_target.sh check <path> <backup|restore>
if [ "${BASH_SOURCE[0]}" = "$0" ]; then
    if [ "${1:-}" = "check" ]; then
        if _backup_target_check "${2:-}" "${3:-backup}"; then exit 0; fi
        printf '%s\n' "$RT_REASON"
        exit 1
    fi
    echo "usage: remote_target.sh check <path> <backup|restore>" >&2
    exit 2
fi
