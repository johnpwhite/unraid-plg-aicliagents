#!/bin/bash
# AICliAgents Installer: Persistent Btrfs Storage Initialization
# D-180: Defaults to /mnt/user/appdata/aicliagents to minimize USB Flash wear.
# Falls back to /boot only if the array is not started and no config exists.

AGENT_BASE="/usr/local/emhttp/plugins/unraid-aicliagents/agents"
CONFIG_FILE="/boot/config/plugins/unraid-aicliagents/unraid-aicliagents.cfg"
LEGACY_CACHE="/boot/config/plugins/unraid-aicliagents/pkg-cache"

# --- Read Config Options ---
# D-180: New default is off-Flash. Only use /boot as last resort.
AGENT_STORAGE_PATH="/mnt/user/appdata/aicliagents"
LOAD_AGENTS_RAM=0

if [ -f "$CONFIG_FILE" ]; then
    TMP_AGENT_PATH=$(grep -oP '^agent_storage_path="\K[^"]+' "$CONFIG_FILE" || true)
    TMP_RAM=$(grep -oP '^load_agents_ram="\K[^"]+' "$CONFIG_FILE" || true)
    [ -n "$TMP_AGENT_PATH" ] && AGENT_STORAGE_PATH="$TMP_AGENT_PATH"
    [ -n "$TMP_RAM" ] && LOAD_AGENTS_RAM="$TMP_RAM"
fi

# --- D-180: Array Readiness Check ---
# If the configured path is under /mnt/, verify the array is started.
# If not, fall back to /boot for this install cycle only (will migrate on next boot with array).
ORIGINAL_STORAGE_PATH="$AGENT_STORAGE_PATH"
if [[ "$AGENT_STORAGE_PATH" == /mnt/* ]]; then
    # Check if Unraid array is started
    ARRAY_STATE=$(grep -oP '^mdState="\K[^"]+' /var/local/emhttp/var.ini 2>/dev/null || echo "")
    if [ "$ARRAY_STATE" != "STARTED" ]; then
        warn "Array not started. Agent storage path ($AGENT_STORAGE_PATH) not available."
        warn "Falling back to /boot for this install cycle. Storage will migrate when array starts."
        AGENT_STORAGE_PATH="/boot/config/plugins/unraid-aicliagents"
    fi
fi

PERSIST_IMAGE_PATH="$AGENT_STORAGE_PATH/aicli-agents.img"
RAM_IMAGE_PATH="/tmp/unraid-aicliagents/aicli-agents.img"

if [ "$LOAD_AGENTS_RAM" == "1" ]; then
    IMAGE_PATH="$RAM_IMAGE_PATH"
else
    IMAGE_PATH="$PERSIST_IMAGE_PATH"
fi

# --- 1. Mount Point Readiness ---
step "Preparing storage layer..."
mkdir -p "$AGENT_BASE"
mkdir -p "$AGENT_STORAGE_PATH"

# --- 2. Persistent Image Initialization ---
if [ ! -f "$PERSIST_IMAGE_PATH" ]; then
    # D-180: Check if there's an existing image on /boot that should be migrated
    BOOT_IMAGE="/boot/config/plugins/unraid-aicliagents/aicli-agents.img"
    if [ "$AGENT_STORAGE_PATH" != "/boot/config/plugins/unraid-aicliagents" ] && [ -f "$BOOT_IMAGE" ]; then
        echo "    > Migrating agent storage from Flash to $AGENT_STORAGE_PATH..." >&3
        cp --sparse=always "$BOOT_IMAGE" "$PERSIST_IMAGE_PATH"
        if [ $? -eq 0 ]; then
            ok "Agent storage migrated off Flash. Removing old Flash image..."
            rm -f "$BOOT_IMAGE"
        else
            warn "Migration failed. Using existing Flash image."
            PERSIST_IMAGE_PATH="$BOOT_IMAGE"
            IMAGE_PATH="$PERSIST_IMAGE_PATH"
        fi
    else
        echo "    > Creating new Btrfs storage container..." >&3
        MNT_POINT=$(df -P "$AGENT_STORAGE_PATH" | tail -1 | awk '{print $6}')
        AVAIL_FREE=$(df -m "$MNT_POINT" | tail -1 | awk '{print $4}')
        if [ "$AVAIL_FREE" -lt 200 ]; then
            fail "Insufficient space on target volume (${AVAIL_FREE} MB free). Aborting."
            exit 1
        fi
        truncate -s 2G "$PERSIST_IMAGE_PATH"
        mkfs.btrfs -m single -L AICLI_AGENTS "$PERSIST_IMAGE_PATH"
        ok "Btrfs storage container created (2GB sparse)."
    fi
else
    echo "    > Existing storage container found." >&3
fi

# --- 2.5 RAM Mode: Copy Persistent Image to tmpfs ---
if [ "$LOAD_AGENTS_RAM" == "1" ] && [ ! -f "$RAM_IMAGE_PATH" ]; then
    echo "    > Copying agents image to RAM ($RAM_IMAGE_PATH)..." >&3
    cp "$PERSIST_IMAGE_PATH" "$RAM_IMAGE_PATH"
    ok "Agents image loaded into RAM."
fi

# --- 3. Mount with Optimization Flags ---
if ! mountpoint -q "$AGENT_BASE"; then
    echo "    > Cleaning stale loopbacks for agent storage..." >&3
    # D-121: Ensure any existing loop associations are cleared to avoid 'busy' errors
    EXISTING_LOOP=$(losetup -j "$IMAGE_PATH" 2>/dev/null | cut -d: -f1 | head -n1 || echo "")
    if [ -n "$EXISTING_LOOP" ]; then
        EXISTING_MNT=$(grep "$EXISTING_LOOP " /proc/mounts | awk '{print $2}' | head -n1 || echo "")
        if [ -n "$EXISTING_MNT" ]; then
            umount -f "$EXISTING_MNT" > /dev/null 2>&1 || umount -l "$EXISTING_MNT" > /dev/null 2>&1
        fi
        losetup -d "$EXISTING_LOOP" > /dev/null 2>&1 || true
    fi

    echo "    > Mounting Btrfs agent storage (zstd:1, noatime)..." >&3
    # zstd:1=fast compression, noatime=skip read writes, autodefrag=small file opt
    MOUNT_ERR=$(mount -o loop,compress=zstd:1,noatime,nodiratime,autodefrag "$IMAGE_PATH" "$AGENT_BASE" 2>&1)
    if [ $? -ne 0 ]; then
        fail "Failed to mount agent storage image: $MOUNT_ERR"
        exit 1
    fi
    ok "Agent storage mounted at $AGENT_BASE."
else
    echo "    > Agent storage already mounted." >&3
fi

# --- 4. One-Time Legacy Cache Migration ---
if [ -d "$LEGACY_CACHE" ]; then
    ITEM_COUNT=$(ls -A "$AGENT_BASE" | wc -l)
    if [ "$ITEM_COUNT" -le 1 ] || [ ! -d "$AGENT_BASE/gemini-cli" ]; then
        step "Migrating legacy agent cache to Btrfs storage..."
        shopt -s nullglob
        MIGRATED=0
        for pkg in "$LEGACY_CACHE"/*.tar.gz; do
            PKG_NAME=$(basename "$pkg" .tar.gz)
            AGENT_DIR="$AGENT_BASE/$PKG_NAME"
            echo "    > $PKG_NAME..." >&3
            mkdir -p "$AGENT_DIR"
            tar -xzf "$pkg" -C "$AGENT_DIR/" --no-same-owner \
                && MIGRATED=$((MIGRATED + 1)) \
                || echo "      [!!] Failed to unpack $PKG_NAME" >&3
        done
        shopt -u nullglob
        ok "$MIGRATED package(s) migrated. Reclaiming USB Flash space..."
        rm -rf "$LEGACY_CACHE"
    fi
fi

# Home persistence is handled by the PHP backend via atomic Btrfs subvolume clones.
ok "Storage engine ready."
