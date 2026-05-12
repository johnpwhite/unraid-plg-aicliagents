#!/bin/bash
# AICliAgents: Path Classification Utility
# Classifies a filesystem path by its storage type.
#
# Usage: classify-path.sh <path>
# Output: One of: flash, array, pool:<name>, unassigned, ram, unknown
#
# Classification rules:
#   /boot/...                    → flash (always available)
#   /tmp/...                     → ram (always available)
#   /mnt/user/... /mnt/user0/..  → array (depends on array)
#   /mnt/disk[0-9]*/...          → array (depends on array)
#   /mnt/disks/... /mnt/remotes/ → unassigned (UD plugin, independent)
#   /mnt/<name>/... where <name> matches a pool in disks.ini → pool:<name>
#   anything else                → unknown

PATH_TO_CHECK="${1:-}"

if [ -z "$PATH_TO_CHECK" ]; then
    echo "unknown"
    exit 0
fi

# Flash drive
case "$PATH_TO_CHECK" in
    /boot|/boot/*)
        echo "flash"
        exit 0
        ;;
esac

# RAM / tmpfs
case "$PATH_TO_CHECK" in
    /tmp|/tmp/*)
        echo "ram"
        exit 0
        ;;
esac

# Must be under /mnt/ for remaining classifications
case "$PATH_TO_CHECK" in
    /mnt/*)
        ;;
    *)
        echo "unknown"
        exit 0
        ;;
esac

# Extract the first path component after /mnt/
MNT_NAME=$(echo "$PATH_TO_CHECK" | sed 's#^/mnt/##' | cut -d'/' -f1)

# Array paths
case "$MNT_NAME" in
    user|user0)
        echo "array"
        exit 0
        ;;
esac

# Array disk paths (disk1, disk2, etc.)
if echo "$MNT_NAME" | grep -qE '^disk[0-9]+$'; then
    echo "array"
    exit 0
fi

# Unassigned devices
case "$MNT_NAME" in
    disks|remotes)
        echo "unassigned"
        exit 0
        ;;
esac

# Check if it's a known pool by reading disks.ini
DISKS_INI="/var/local/emhttp/disks.ini"
if [ -f "$DISKS_INI" ]; then
    # Extract pool names: entries with type="Cache", strip trailing digits for pool name.
    # disks.ini section headers are ["cache"] / ["storage"] etc. (Unraid quotes the
    # name inside the brackets) so the gsub strips ALL of [, ], and " — leaving
    # bare names that match $MNT_NAME from /mnt/<name>/...
    POOL_NAMES=$(awk -F'=' '
        /^\[/ { section=$0; gsub(/[\[\]"]/, "", section) }
        /^type=/ && $2 == "\"Cache\"" {
            name=section; gsub(/[0-9]+$/, "", name); print name
        }
    ' "$DISKS_INI" | sort -u)

    for pool in $POOL_NAMES; do
        if [ "$MNT_NAME" = "$pool" ]; then
            echo "pool:$pool"
            exit 0
        fi
    done
fi

echo "unknown"
