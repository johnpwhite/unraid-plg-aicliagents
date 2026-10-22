#!/bin/bash
source "$(dirname "${BASH_SOURCE[0]}")/resolve_paths.sh" || { echo 'resolve_paths.sh missing' >&2; exit 1; }
set -euo pipefail
# AICliAgents: ZRAM Initialization Script
# Goal: Setup a 4GB compressed RAM block device for OverlayFS upperdir.

ZRAM_SIZE="4G"
ZRAM_MNT="${MOUNT_ROOT}/zram_upper"
ZRAM_ALGO="zstd"
ZRAM_LABEL="AICLI_ZRAM"
DEBUG_LOG="${MOUNT_ROOT}/debug.log"

mkdir -p "$(dirname "$DEBUG_LOG")"

get_ts() { date '+%Y-%m-%d %H:%M:%S'; }
log() {
    local msg="[$(get_ts)] [INFO] [ZRAM] $1"
    echo "$msg"
    echo "$msg" >> "$DEBUG_LOG"
}
error() {
    local msg="[$(get_ts)] [ERR!] [ZRAM] $1"
    echo "$msg"
    echo "$msg" >> "$DEBUG_LOG"
}

# R-087: two first mounts must not race. Without a lock both can pass the "not
# mounted" check, both allocate a zram device (hot_add) and one device leaks.
# The lock is non-blocking with a deadline (never a blocking flock). It is held
# on its own descriptor until this script exits.
_zram_take_lock() {
    local _lock="${AICLI_ZRAM_LOCK:-/var/run/aicli-zram-init.lock}"
    local _wait="${AICLI_ZRAM_LOCK_WAIT_S:-60}" _deadline
    case "$_wait" in ''|*[!0-9]*) _wait=60 ;; esac
    command -v flock >/dev/null 2>&1 || return 0
    { exec {ZRAM_LOCK_FD}>"$_lock"; } 2>/dev/null || return 0
    _deadline=$((SECONDS + _wait))
    until flock -n "$ZRAM_LOCK_FD"; do
        if [ "$SECONDS" -ge "$_deadline" ]; then
            error "Another ZRAM initialization still holds $_lock after ${_wait}s."
            return 1
        fi
        sleep 0.2
    done
    return 0
}

# R-087: format the device. A failed mkfs is logged with its own message and
# fails the script. The old code sent the message to /dev/null and exited
# silently through set -e.
_zram_format() {
    local _out
    modprobe ext4 2>/dev/null || true
    if command -v mkfs.ext4 >/dev/null 2>&1 && grep -qw ext4 /proc/filesystems 2>/dev/null; then
        log "Formatting ZRAM as ext4..."
        _out="$(mkfs.ext4 -m 0 -L "$ZRAM_LABEL" "$ZRAM_DEV" 2>&1)" || {
            error "mkfs.ext4 failed on $ZRAM_DEV: $(printf '%s' "$_out" | tr '\n' ' ' | cut -c1-300)"
            return 1
        }
    else
        log "ext4 unavailable on this kernel — formatting ZRAM as xfs..."
        _out="$(mkfs.xfs -f -L "$ZRAM_LABEL" "$ZRAM_DEV" 2>&1)" || {
            error "mkfs.xfs failed on $ZRAM_DEV: $(printf '%s' "$_out" | tr '\n' ' ' | cut -c1-300)"
            return 1
        }
    fi
    return 0
}

_zram_take_lock || exit 1

# 1. Check if already initialized (our mount point is active)
if grep -q "$ZRAM_MNT" /proc/mounts 2>/dev/null; then
    exit 0
fi

# 2. Ensure the zram interface is available.
# Probe for the *capability* (control node / device), not the module: on kernels
# where zram is built into the image there is no zram.ko under /lib/modules, so
# `modprobe` fails even though zram works fine — see GitHub issue #5. Only
# modprobe when neither the control node nor a device exists, and never treat a
# modprobe failure as fatal — re-probe instead.
if [ ! -d /sys/class/zram-control ] && [ ! -b /dev/zram0 ]; then
    log "zram interface not present — attempting modprobe..."
    modprobe zram num_devices=1 2>/dev/null || true
fi
if [ ! -d /sys/class/zram-control ] && [ ! -b /dev/zram0 ]; then
    error "zram unavailable: no /sys/class/zram-control and no /dev/zram0 after modprobe. Kernel lacks ZRAM support."
    exit 1
fi

# 3. Find or allocate a ZRAM device
# Scan ALL existing zram devices for our label before allocating a new one
ZRAM_DEV=""
for zdev in /sys/block/zram*; do
    [ -d "$zdev" ] || continue
    ZID=$(basename "$zdev")
    EXISTING_LABEL=$(blkid -s LABEL -o value "/dev/$ZID" 2>/dev/null || true)
    if [ "$EXISTING_LABEL" = "$ZRAM_LABEL" ]; then
        ZRAM_DEV="/dev/$ZID"
        log "Found existing $ZRAM_LABEL on $ZID."
        break
    fi
done

# If not found by label, try claiming an unconfigured device or allocate new
if [ -z "$ZRAM_DEV" ]; then
    if [ -f "/sys/block/zram0/disksize" ]; then
        DISKSIZE=$(cat /sys/block/zram0/disksize)
        if [ "$DISKSIZE" = "0" ]; then
            ZRAM_DEV="/dev/zram0"
            log "Claiming unconfigured zram0..."
        else
            # zram0 in use - allocate a new device
            if [ -f "/sys/class/zram-control/hot_add" ]; then
                NEW_ID=$(cat /sys/class/zram-control/hot_add)
                ZRAM_DEV="/dev/zram${NEW_ID}"
                log "zram0 in use by another plugin. Allocated zram${NEW_ID}."
            else
                error "zram0 in use and dynamic allocation unavailable. Cannot initialize ZRAM."
                exit 1
            fi
        fi
    else
        error "No ZRAM device found in /sys/block/"
        exit 1
    fi
fi

# 4. Configure ZRAM device if not already sized
ZRAM_ID="${ZRAM_DEV#/dev/zram}"
DISKSIZE=$(cat "/sys/block/zram${ZRAM_ID}/disksize")
if [ "$DISKSIZE" = "0" ]; then
    log "Initializing ZRAM device ${ZRAM_DEV} (4GB, zstd)..."
    echo "$ZRAM_ALGO" > "/sys/block/zram${ZRAM_ID}/comp_algorithm" 2>/dev/null || true
    echo "$ZRAM_SIZE" > "/sys/block/zram${ZRAM_ID}/disksize" 2>/dev/null

    # 5. Format with our label. Prefer ext4 (supports -m 0, low overhead); fall
    # back to XFS when the kernel can't mount ext4 on a block device — seen on
    # some stripped Unraid configs as `mount: unknown filesystem type 'ext4'`
    # (GitHub issue #5). XFS is always present on Unraid; the mount options in
    # step 6 (noatime,nodiratime,discard) are valid for both, and that mount has
    # no -t so a reused device's filesystem is auto-detected.
    _zram_format || exit 1
fi

# 6. Mount
mkdir -p "$ZRAM_MNT"
if ! mountpoint -q "$ZRAM_MNT"; then
    log "Mounting ZRAM ($ZRAM_DEV) to $ZRAM_MNT..."
    mount -o noatime,nodiratime,discard "$ZRAM_DEV" "$ZRAM_MNT" || { error "Failed to mount ZRAM"; exit 1; }
fi

log "ZRAM ready ($ZRAM_DEV)."
