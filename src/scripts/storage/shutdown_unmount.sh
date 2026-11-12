#!/bin/bash
source "$(dirname "${BASH_SOURCE[0]}")/resolve_paths.sh" || { echo 'resolve_paths.sh missing' >&2; exit 1; }
# shutdown_unmount.sh — the squashfs loop-mount teardown that frees /mnt/cache
# at shutdown, factored out so the shutdown handler can GUARANTEE it runs within
# a reserved slice of the budget (GH #10 / Forgejo #228).
#
# The full stop (stop-plugin.sh) runs under one budget. A shutdown that has to
# bake many dirty home layers can consume that budget before it reaches its own
# unmount step, leaving squashfs loop mounts holding /mnt/cache open and forcing
# an unclean shutdown — the original GitHub #10 report. The shutdown handler now
# caps the main stop and calls this function under a reserved timeout afterwards,
# so the loop teardown is never starved.
#
# Idempotent and scoped to our own paths: an already-unmounted mount and an
# already-detached loop are both no-ops, so running it after stop-plugin.sh's own
# Step 8 (which does the same thing) is harmless. Mirrors stop-plugin.sh 8c/8d.

# Free the individual squashfs loop mounts and detach the loop devices backing our
# .sqsh files. $1 is the mount base (${MOUNT_ROOT}/mnt); tests pass their own.
# event/stopping runs this function in a `bash -c` child. MOUNT_ROOT is a plain
# (not exported) variable of resolve_paths.sh, so the child had no MOUNT_ROOT and
# the base became "/mnt": the loop below unmounted every disk, pool and share
# mounted under /mnt (/mnt/user, /mnt/cache, /mnt/disks, ...), lazily when busy,
# while Docker and the VMs still ran. The caller passes the base now, and the
# system /mnt is never a base.
_reserve_unmount_squashfs_loops() {
    local base="${1:-${MOUNT_ROOT:+${MOUNT_ROOT}/mnt}}"
    case "$base" in
        ''|/|/mnt|/mnt/) base="" ;;
    esac
    if [ -n "$base" ] && [ -d "$base" ]; then
        local mnt
        for mnt in "$base"/*; do
            mountpoint -q "$mnt" 2>/dev/null || continue
            # #512: a real umount first. The lazy detach is the last resort here
            # only, because Unraid must still unmount the pool at shutdown.
            umount "$mnt" 2>/dev/null || { sleep "${AICLI_UMOUNT_RETRY_S:-1}"; umount "$mnt" 2>/dev/null; } \
                || umount -l "$mnt" 2>/dev/null
        done
    fi
    local loop
    for loop in $(losetup -a 2>/dev/null | grep 'unraid-aicliagents' | cut -d: -f1); do
        losetup -d "$loop" 2>/dev/null
    done
}
