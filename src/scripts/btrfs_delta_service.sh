# v2026.04.02.38 - Intelligent Floor-Aware Shrink
# D-119: Path to /tmp/sync

# 0. Global Setup
ACTION="$1"
USER_NAME="$2"
RAM_IMG="/tmp/unraid-aicliagents/work/home_$USER_NAME.img"
RAM_MNT="/tmp/unraid-aicliagents/work/$USER_NAME/home"
# D-119: Moved from /var/run to /tmp for absolute reliability on Unraid rootfs
SYNC_BASE="/tmp/unraid-aicliagents/sync/$USER_NAME"
mkdir -p "$SYNC_BASE"

status() {
    local timestamp=$(date "+%Y-%m-%d %H:%M:%S")
    echo "[$timestamp] [AICli Storage] $1"
}
error() {
    local timestamp=$(date "+%Y-%m-%d %H:%M:%S")
    echo "[$timestamp] [AICli ERROR] $1" >&2
}

# D-110: Standard excludes for Home persistence to prevent loopback bloat
HOME_EXCLUDES="--exclude='.npm' --exclude='.bun' --exclude='.cache' --exclude='.node-gyp' --exclude='node_modules' --exclude='log' --exclude='*.log' --exclude='*.sock' --exclude='.sock'"

case "$ACTION" in
    "init")
        # Initialize a new sparse Btrfs image
        TARGET_IMG="$3"
        # D-180: Default 128MB sparse (grows on demand via sync_delta expansion)
        SIZE="${4:-128M}"
        [ -f "$TARGET_IMG" ] && exit 0
        status "Creating sparse Btrfs home image for $USER_NAME at $TARGET_IMG ($SIZE)..."
        truncate -s "$SIZE" "$TARGET_IMG"
        mkfs.btrfs -f "$TARGET_IMG" > /dev/null 2>&1
        ;;

    "migrate")
        # Migrate a directory into a Btrfs image
        SRC_DIR="$3"
        DEST_IMG="$4"
        [ ! -d "$SRC_DIR" ] && exit 1
        [ -f "$DEST_IMG" ] && exit 0
        
        status "Migrating $USER_NAME home folder into Btrfs image..."
        # D-180: 128MB starting size (sparse, grows on demand)
        truncate -s 128M "$DEST_IMG"
        mkfs.btrfs -f "$DEST_IMG" > /dev/null 2>&1
        
        MNT="/tmp/aicli_mig_$USER_NAME"
        mkdir -p "$MNT"
        mount -o loop,compress-force=zstd:3 "$DEST_IMG" "$MNT"
        # Apply standard excludes to prevent legacy garbage from entering the image
        rsync -avcL $HOME_EXCLUDES "$SRC_DIR/" "$MNT/"
        umount "$MNT"
        rmdir "$MNT"
        status "Migration complete for $USER_NAME."
        ;;

    "mount_ram")
        # Mount the RAM-based loopback image
        mkdir -p "$RAM_MNT"
        if ! mountpoint -q "$RAM_MNT"; then
            mount -o loop,compress-force=zstd:3,noatime "$RAM_IMG" "$RAM_MNT"
        fi
        ;;

    "snapshot")
        # Create a read-only snapshot for syncing
        SNAP_NAME="snap_$(date +%Y%m%d_%H%M%S)"
        SNAP_PATH="$RAM_MNT/.sync_snaps/$SNAP_NAME"
        mkdir -p "$RAM_MNT/.sync_snaps"
        btrfs subvolume snapshot -r "$RAM_MNT" "$SNAP_PATH" > /dev/null 2>&1
        echo "$SNAP_NAME"
        ;;

    "sync")
        # Convenient wrapper for high-level sync (Handles its own state)
        # Usage: btrfs_delta_service.sh sync <user> <persist_img>
        PERSIST_IMG="$3"
        # D-180: Default persistence off-Flash. Read from config if available.
        if [ -z "$PERSIST_IMG" ]; then
            CONFIG_FILE="/boot/config/plugins/unraid-aicliagents/unraid-aicliagents.cfg"
            PERSIST_BASE=""
            [ -f "$CONFIG_FILE" ] && PERSIST_BASE=$(grep -oP '^persistence_base="\K[^"]+' "$CONFIG_FILE" || true)
            [ -z "$PERSIST_BASE" ] && PERSIST_BASE="/mnt/user/appdata/aicliagents/persistence"
            PERSIST_IMG="$PERSIST_BASE/home_$USER_NAME.img"
        fi
        
        STATE_FILE="/tmp/unraid-aicliagents/work/$USER_NAME/.last_sync_snap"
        LAST_SNAP=""
        [ -f "$STATE_FILE" ] && LAST_SNAP=$(cat "$STATE_FILE")
        
        # D-107: Ensure RAM image is mounted before attempting snapshot
        bash "$0" mount_ram "$USER_NAME"
        
        CURR_SNAP=$(bash "$0" snapshot "$USER_NAME")
        if [ -n "$CURR_SNAP" ]; then
            bash "$0" sync_delta "$USER_NAME" "$PERSIST_IMG" "$LAST_SNAP" "$CURR_SNAP"
            if [ $? -eq 0 ]; then
                echo "$CURR_SNAP" > "$STATE_FILE"
                status "Sync successful for $USER_NAME."
            else
                error "Sync failed for $USER_NAME."
                exit 1
            fi
        fi
        ;;

    "sync_delta")
        # Perform btrfs send/receive delta sync
        PERSIST_IMG="$3"
        LAST_SNAP="$4"
        CURR_SNAP="$5"
        
        # D-115: ALWAYS prune RAM before syncing to slow Flash
        # This keeps the snapshot lean and prevents AJAX timeouts on slow USB sticks.
        bash "$0" prune "$USER_NAME" "$RAM_MNT"
        
        TEMP_MNT="$SYNC_BASE/mnt"
        mkdir -p "$TEMP_MNT"
        
        # 1. Mount Persistent Image (with Stale Cleanup)
        # Also check for any existing loopback or mount associated with PERSIST_IMG
        existing_loop=$(losetup -j "$PERSIST_IMG" 2>/dev/null | cut -d: -f1 | head -n1 || echo "")
        if [ -n "$existing_loop" ]; then
            # D-121: If the loop is mounted somewhere else, we MUST unmount it first or 'mount' will fail later
            existing_mnt=$(grep "$existing_loop " /proc/mounts | awk '{print $2}' | head -n1 || echo "")
            if [ -n "$existing_mnt" ]; then
                status "Target: Unmounting active mount from $existing_mnt..."
                umount -f "$existing_mnt" > /dev/null 2>&1 || umount -l "$existing_mnt" > /dev/null 2>&1
            fi
            status "Target: Detaching loop device $existing_loop..."
            losetup -d "$existing_loop" > /dev/null 2>&1 || true
        fi

        # D-117: Existence and Size Validation (Fail-Safe)
        if [ ! -f "$PERSIST_IMG" ]; then
            error "Fatal: Persistent image NOT found at $PERSIST_IMG"
            exit 1
        fi
        
        img_size=$(du -sh "$PERSIST_IMG" 2>/dev/null | awk '{print $1}' || echo "0")
        status "Target: Image found ($img_size). Mounting to $TEMP_MNT..."
        
        # Capture mount stderr for logging
        mount_error=$(mount -o loop,noatime,compress=zstd:1 "$PERSIST_IMG" "$TEMP_MNT" 2>&1)
        if [ $? -ne 0 ]; then
            error "Mount failed: $mount_error"
            status "Recovery: Attempting Btrfs log restoration..."
            # Try to mount in recovery mode (ro) to see if it's alive
            if mount -o loop,ro,usebackuproot "$PERSIST_IMG" "$TEMP_MNT" 2>&1; then
                status "Recovery: Found reachable backup root. Flushing log..."
                umount "$TEMP_MNT"
                btrfs rescue zero-log "$PERSIST_IMG" > /dev/null 2>&1
                mount -o loop,noatime,compress=zstd:1 "$PERSIST_IMG" "$TEMP_MNT" || { error "Fatal: Mount still failing after rescue."; exit 1; }
                status "Recovery: Succeeded. Resuming sync."
            else
                error "Fatal: Persistent image corrupted or inaccessible."
                exit 1
            fi
        fi
        
        # 1.5 D-112: Pre-Sync Purge for Full Sync fallbacks
        if [ -n "$LAST_SNAP" ] && [ ! -d "$TEMP_MNT/$LAST_SNAP" ]; then
            status "DEBUG: Parent snapshot $LAST_SNAP missing on target."
            LAST_SNAP=""
        fi

        if [ -z "$LAST_SNAP" ]; then
            status "DEBUG: Entering Full Sync Fallback..."
            local current_bytes=$(stat -c %s "$PERSIST_IMG")

            # D-180: Expansion limit depends on storage location.
            # Flash (FAT32): 2GB ceiling. Disk (XFS/Btrfs/ZFS): 10GB ceiling.
            local MAX_BYTES=10737418240  # 10GB for disk storage
            if [[ "$PERSIST_IMG" == /boot/* ]]; then
                MAX_BYTES=2147483648     # 2GB for Flash
            fi

            # D-170: Intelligent Expansion - Only expand if we have less than 100MB free
            if [ "$current_bytes" -lt "$MAX_BYTES" ]; then
                local mnt_stats=$(df -m --output=avail "$TEMP_MNT" | tail -n 1 | tr -dc '0-9')
                [ -z "$mnt_stats" ] && mnt_stats=0

                if [ "$mnt_stats" -lt 100 ]; then
                    status "Headroom: Expansion required ($mnt_stats MiB free). Expanding (+512M)..."
                    umount "$TEMP_MNT"
                    truncate -s "+512M" "$PERSIST_IMG"
                    mount -o loop,noatime,compress=zstd:1 "$PERSIST_IMG" "$TEMP_MNT"
                    btrfs filesystem resize max "$TEMP_MNT" > /dev/null 2>&1
                else
                    status "Headroom: Sufficient space ($mnt_stats MiB free). Skipping expansion."
                fi
            else
                status "Headroom: Persistent image at 2GB safe-limit. Proceeding with sync."
            fi
            
            status "Cleanup: Clearing stale snapshots on target (Sync Flush)..."
            for snap in "$TEMP_MNT"/snap_*; do
                [ ! -d "$snap" ] && continue
                btrfs subvolume delete -c "$snap" > /dev/null 2>&1
            done
            btrfs filesystem sync "$TEMP_MNT" > /dev/null 2>&1
        fi
        
        # 2. Report Source Size
        source_size=$(du -sh "$RAM_MNT/.sync_snaps/$CURR_SNAP" | awk '{print $1}')
        status "Source: $CURR_SNAP is approximately $source_size."
        
        # 3. Send Delta
        if [ -n "$LAST_SNAP" ] && [ -d "$RAM_MNT/.sync_snaps/$LAST_SNAP" ]; then
            status "Sync: Starting Incremental Delta (from $LAST_SNAP)..."
            btrfs send -p "$RAM_MNT/.sync_snaps/$LAST_SNAP" "$RAM_MNT/.sync_snaps/$CURR_SNAP" | btrfs receive "$TEMP_MNT/"
        else
            status "Sync: Starting Initial Full Send (this may take a few minutes on slow USB)..."
            btrfs send "$RAM_MNT/.sync_snaps/$CURR_SNAP" | btrfs receive "$TEMP_MNT/"
        fi
        
        status "Sync: Completed Transfer."

        # 4. Cleanup old snapshots on target (Keep only current and last)
        status "Cleanup: Refreshing target snapshots..."
        for snap in "$TEMP_MNT"/snap_*; do
            [ ! -d "$snap" ] && continue
            base=$(basename "$snap")
            if [ "$base" != "$CURR_SNAP" ] && [ "$base" != "$LAST_SNAP" ]; then
                # D-113: Use Commit (-c) to ensure space is reclaimed on target immediately
                btrfs subvolume delete -c "$snap" > /dev/null 2>&1
            fi
        done

        # 5. Cleanup old snapshots in RAM
        status "Cleanup: Refreshing RAM snapshots..."
        for snap in "$RAM_MNT"/.sync_snaps/snap_*; do
            [ ! -d "$snap" ] && continue
            base=$(basename "$snap")
            if [ "$base" != "$CURR_SNAP" ] && [ "$base" != "$LAST_SNAP" ]; then
                # Synchronous deletion in RAM (Fast, reduces peak RAM usage)
                btrfs subvolume delete -c "$snap" > /dev/null 2>&1
            fi
        done
        
        # 6. Unmount
        status "Target: Unmounting $TEMP_MNT..."
        umount "$TEMP_MNT"
        ;;

    "expand")
        # Grow the image and resize the filesystem online
        SIZE_ADD="${3:-512M}"
        if [ ! -f "$RAM_IMG" ]; then error "Image not found"; exit 1; fi
        
        # 1. Grow backfile
        truncate -s "+$SIZE_ADD" "$RAM_IMG"
        
        # 2. Online resize
        if mountpoint -q "$RAM_MNT"; then
            status "Resizing $USER_NAME home filesystem..."
            btrfs filesystem resize max "$RAM_MNT" > /dev/null 2>&1
        fi
        ;;

    "shrink")
        # Shrink the filesystem first, then the backfile
        SIZE_SUB="${3:-250M}"
        if [ ! -f "$RAM_IMG" ]; then error "Image not found"; exit 1; fi
        if mountpoint -q "$RAM_MNT"; then
            # D-154: Robust Capacity Parsing - Use GNU df --output for predictable column indexing
            # D-170: Removed 'local' as this is not in a function.
            df_stats=$(df -m --output=size,used,avail "$RAM_MNT" | tail -n 1)
            curr_mib=$(echo "$df_stats" | awk '{print $1}' | tr -dc '0-9')
            used_mib=$(echo "$df_stats" | awk '{print $2}' | tr -dc '0-9')
            sub_mib=$(echo "$SIZE_SUB" | tr -dc '0-9')

            # Ensure we have valid numbers
            [ -z "$curr_mib" ] && curr_mib=0
            [ -z "$used_mib" ] && used_mib=0
            [ -z "$sub_mib" ] && sub_mib=0

            target_mib=$((curr_mib - sub_mib))

            # D-135: Intelligent Floor-Aware Shrink (Min 256MB for Btrfs stability)
            if [ "$target_mib" -lt 256 ]; then
                sub_mib=$((curr_mib - 256))
                if [ "$sub_mib" -le 0 ]; then
                    error "Filesystem is already at the minimum safe size (256MiB)."
                    exit 1
                fi
                SIZE_SUB="${sub_mib}M"
                status "Clamping shrink to floor (256MiB). Real reduction: $SIZE_SUB"
                target_mib=256
            fi

            # D-151: Logical-Aware Guard (Prevent I/O Errors on compressed drives)
            if [ "$target_mib" -lt "$((used_mib + 128))" ]; then
                safe_sub=$((curr_mib - (used_mib + 128)))
                [ "$safe_sub" -lt 0 ] && safe_sub=0
                status "Clamping shrink to protect logical data. Target ${target_mib}MiB too small for ${used_mib}MiB data. Safe reduction: ${safe_sub}MiB"
                sub_mib=$safe_sub
                SIZE_SUB="${sub_mib}M"
            fi
            
            if [ "$sub_mib" -le 0 ]; then
                error "Filesystem cannot be shrunk further. Current Logical Usage (${used_mib}MiB) plus buffer exceeds target."
                exit 1
            fi

            # D-134: Pre-Shrink cleanup to reclaim logical space
            status "Aggressively pruning and defragmenting $USER_NAME home before shrink..."
            bash "$0" prune "$USER_NAME" "$RAM_MNT"
            btrfs filesystem defragment -r -czstd "$RAM_MNT" > /dev/null 2>&1
            
            # D-163: AGGRESSIVE SHRINK STABILIZER
            # Evacuate blocks from device tail before resize
            status "STABILIZER: Evacuating blocks from device tail (Aggressive Balance)..."
            btrfs balance start -dusage=100 -musage=100 "$RAM_MNT" > /dev/null 2>&1

            status "Shrinking $USER_NAME home filesystem by $SIZE_SUB..."
            btrfs filesystem resize "-$SIZE_SUB" "$RAM_MNT" 2>&1
            if [ $? -eq 0 ]; then
                # Only shrink backfile if FS resize succeeded
                truncate -s "-$SIZE_SUB" "$RAM_IMG"
                status "Shrink successful."
            else
                error "FS shrink failed. Try a smaller shrink amount."
                exit 1
            fi
        else
            error "Image not mounted, cannot shrink safely."
            exit 1
        fi
        ;;

    "cleanup_snaps")
        # Remove old snapshots to save space
        KEEP="$3"
        # ... logic to keep only $KEEP snapshots ...
        ;;

    "prune")
        # Recursively remove non-essential cache/log dirs from a mounted home image
        # Usage: btrfs_delta_service.sh prune <user> [target_mnt]
        TARGET_MNT="${3:-$RAM_MNT}"
        if [ -d "$TARGET_MNT" ]; then
            status "Pruning non-essential caches and logs from $USER_NAME home..."
            # D-117: Aggressive pruning of node-based agent caches to keep images lean
            # D-120: Specifically prune .sync_snaps from find to avoid "Read-only file system" errors
            find "$TARGET_MNT" -path "*/.sync_snaps" -prune -o -maxdepth 4 -type d \( -name ".npm" -o -name ".cache" -o -name ".bun" -o -name ".node-gyp" -o -name "log" -o -name "npm-cache" -o -name ".bin" \) -exec rm -rf {} +
            find "$TARGET_MNT" -path "*/.sync_snaps" -prune -o -maxdepth 4 -type f -name "*.log" -delete
            status "Pruning complete for $USER_NAME."
        fi
        ;;

    *)
        error "Unknown action: $ACTION"
        exit 1
        ;;
esac
