#!/bin/bash
# AICliAgents Self-Healing & Repair Utility
# Goal: Restore storage, fix mounts, and re-install agent binaries WITHOUT losing user sessions.

STATUS_FILE="/tmp/unraid-aicliagents/repair-status"
mkdir -p "$(dirname "$STATUS_FILE")"

log_progress() {
    local pct=$1 msg=$2
    echo "PROGRESS: $pct | $msg"
    echo "{\"progress\": $pct, \"message\": \"$msg\", \"timestamp\": $(date +%s)}" > "$STATUS_FILE"
}

echo "--- AICliAgents Repair Engine Start ---"
log_progress 5 "Initializing Repair Engine..."

# 1. Force-Kill Active Processes
log_progress 10 "Stopping active agent processes..."
pkill -9 -f 'Periodic sync triggered' >/dev/null 2>&1
pkill -9 -f 'sync-daemon-.*\.sh' >/dev/null 2>&1
pkill -9 -f 'install-bg.php' >/dev/null 2>&1
pkill -9 -f 'ttyd.*aicliterm-' >/dev/null 2>&1
if command -v tmux >/dev/null 2>&1; then
    tmux ls -F '#S' 2>/dev/null | grep -E '^aicli-agent-' | xargs -I {} tmux kill-session -t {} >/dev/null 2>&1
fi
pkill -9 -f 'node.*(gemini|opencode|nanocoder|claude|kilo|pi|codex|factory)' >/dev/null 2>&1

# 2. Storage Layer Reset
log_progress 20 "Clearing stale loopback states..."
# D-180: Resolve image path from config (defaults off-Flash)
CONFIG_FILE="/boot/config/plugins/unraid-aicliagents/unraid-aicliagents.cfg"
AGENT_STORAGE_PATH="/mnt/user/appdata/aicliagents"
if [ -f "$CONFIG_FILE" ]; then
    TMP_PATH=$(grep -oP '^agent_storage_path="\K[^"]+' "$CONFIG_FILE" || true)
    [ -n "$TMP_PATH" ] && AGENT_STORAGE_PATH="$TMP_PATH"
fi
IMAGE_PATH="$AGENT_STORAGE_PATH/aicli-agents.img"
AGENT_MNT="/usr/local/emhttp/plugins/unraid-aicliagents/agents"

# D-166: Use a lock to prevent race conditions with the PHP stabilizer
MOUNT_LOCK="/tmp/unraid-aicliagents/mount.lock"
mkdir -p "$(dirname "$MOUNT_LOCK")"

(
    # Wait for up to 30s for any concurrent mount operations to finish
    flock -x -w 30 200 || {
        log_progress 20 "ERROR: Could not acquire mount lock. Please try again later."
        exit 1
    }
    
    # D-121: Robust Loopback Cleanup
    # Kill all loops pointing to this image, including ghost/zombie mappings
    umount -l "$AGENT_MNT" 2>/dev/null || true
    for loop in $(losetup -j "$IMAGE_PATH" 2>/dev/null | cut -d: -f1); do
        losetup -d "$loop" 2>/dev/null
    done
    umount -l "$AGENT_MNT" 2>/dev/null || true
    sleep 1

    log_progress 30 "Re-mounting Persistent Agent Storage..."
    mkdir -p "$AGENT_MNT"
    
    # D-168: If already mounted (by PHP race), skip and log
    if mountpoint -q "$AGENT_MNT"; then
         log_progress 30 "Storage already mounted by Stabilizer. Proceeding..."
    else
        mount -o loop,compress=zstd:1,noatime,nodiratime,autodefrag "$IMAGE_PATH" "$AGENT_MNT"
        if [ $? -ne 0 ]; then
            log_progress 30 "ERROR: Mount failed. Attempting deep rescue..."
            btrfs rescue zero-log "$IMAGE_PATH" >/dev/null 2>&1
            btrfs rescue super-recover "$IMAGE_PATH" -y >/dev/null 2>&1
            mount -o loop,compress=zstd:1,noatime,nodiratime,autodefrag "$IMAGE_PATH" "$AGENT_MNT"
        fi
    fi
) 200>"$MOUNT_LOCK"

# 3. Native Runtime Restoration
log_progress 40 "Restoring Node.js and system symlinks..."
bash /usr/local/emhttp/plugins/unraid-aicliagents/scripts/installer/runtime.sh >/dev/null 2>&1

# 4. Agent Re-Installation
log_progress 50 "Re-synchronizing AI Agent binaries..."
# Identify all previously installed agents by looking at subdirs
AGENT_DIRS=$(ls -d "$AGENT_MNT"/*/ 2>/dev/null || echo "")
if [ -n "$AGENT_DIRS" ]; then
    INSTALLED_AGENTS=$(echo "$AGENT_DIRS" | xargs -n1 basename)
    TOTAL=$(echo "$INSTALLED_AGENTS" | wc -w)
else
    TOTAL=0
fi
COUNT=0

if [ "$TOTAL" -gt 0 ]; then
    for agent in $INSTALLED_AGENTS; do
        COUNT=$((COUNT+1))
        # Map progress from 50 to 90
        CURRENT_PCT=$((50 + (COUNT * 40 / TOTAL)))
        log_progress "$CURRENT_PCT" "Re-installing: $agent..."
        /usr/bin/php /usr/local/emhttp/plugins/unraid-aicliagents/scripts/install-bg.php "$agent" --repair >/dev/null 2>&1
    done
else
    log_progress 90 "No previously installed agents found. Skipping re-deploy."
fi

# 5. Permission Enforcement & Nginx Recovery
log_progress 95 "Finalizing permissions and Proxy config..."
chmod -R 755 "$AGENT_MNT" 2>/dev/null

# D-126: Self-Heal Nginx config for Unix sockets
CONF="/etc/nginx/conf.d/unraid-aicliagents.conf"
cat <<EOF > "$CONF"
location ~ ^/webterminal/aicliterm-([^/]+)/ {
    proxy_pass http://unix:/var/run/aicliterm-\$1.sock:/;
    proxy_http_version 1.1;
    proxy_set_header Upgrade \$http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host \$host;
    proxy_read_timeout 86400;
}
EOF
/usr/sbin/nginx -s reload >/dev/null 2>&1

rm -f /tmp/unraid-aicliagents/init_done_*

log_progress 100 "REPAIR COMPLETE. Refreshing system state."
sleep 2
rm -f "$STATUS_FILE"

echo "==========================================================="
echo "                REPAIR PROCESS COMPLETE                    "
echo "==========================================================="
