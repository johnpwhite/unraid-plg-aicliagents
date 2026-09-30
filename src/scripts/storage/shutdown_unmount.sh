#!/bin/bash
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
# .sqsh files. $1 (optional) overrides the mount base — for tests only; production
# always uses the real path.
_reserve_unmount_squashfs_loops() {
    local base="${1:-/tmp/unraid-aicliagents/mnt}"
    if [ -d "$base" ]; then
        local mnt
        for mnt in "$base"/*; do
            mountpoint -q "$mnt" 2>/dev/null && umount -l "$mnt" 2>/dev/null
        done
    fi
    local loop
    for loop in $(losetup -a 2>/dev/null | grep 'unraid-aicliagents' | cut -d: -f1); do
        losetup -d "$loop" 2>/dev/null
    done
}
