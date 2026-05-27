#!/bin/bash
# AICliAgents Self-Healing & Repair Utility
# Goal: Restore storage, fix mounts, and re-install agent binaries WITHOUT losing user sessions.

STATUS_FILE="/tmp/unraid-aicliagents/repair-status"
mkdir -p "$(dirname "$STATUS_FILE")"

# Bug #1043: route tmux at the plugin-private socket dir (see aicli-shell.sh).
export TMUX_TMPDIR="/tmp/unraid-aicliagents/tmux"

log_progress() {
    local pct=$1 msg=$2
    # D-170: Use timestamped logging for professional appearance
    echo "[$(date +'%Y-%m-%d %H:%M:%S')] PROGRESS: $pct% | $msg"
    echo "{\"progress\": $pct, \"message\": \"$msg\", \"timestamp\": $(date +%s)}" > "$STATUS_FILE"
}

echo "--- AICliAgents Repair Engine Start ---"
log_progress 5 "Initializing Repair Engine..."

# 1. Force-Kill Active Processes
# Safe-kill helper: exe-path filter drops any pid under qemu/libvirt/kvm/init.
# Belt-and-braces guard so a pattern cross-match (e.g. a VM whose cmdline
# happens to contain "gemini") can never take down user VMs.
_rp_safe_kill() {
    local pattern="$1"
    local pid exe
    for pid in $(pgrep -f "$pattern" 2>/dev/null | grep -v "$$" || true); do
        exe=$(readlink "/proc/$pid/exe" 2>/dev/null)
        case "$exe" in
            */qemu*|*/libvirt*|*/virsh|*/kvm|*/systemd|*/init|/sbin/init) continue ;;
        esac
        kill -9 "$pid" >/dev/null 2>&1 || true
    done
}
log_progress 10 "Stopping active agent processes..."
_rp_safe_kill 'install-bg.php'
_rp_safe_kill 'ttyd.*aicliterm-'
if command -v tmux >/dev/null 2>&1; then
    # Non-root audit: iterate every per-uid tmux socket so non-root sessions
    # get killed too.
    for _sock in /tmp/unraid-aicliagents/tmux/tmux-*/default; do
        [ -S "$_sock" ] || continue
        tmux -S "$_sock" ls -F '#S' 2>/dev/null | grep -E '^aicli-agent-' | xargs -r -I {} tmux -S "$_sock" kill-session -t {} >/dev/null 2>&1 || true
    done
fi
# Path-anchored: only match node processes carrying a plugin-owned path.
_rp_safe_kill 'node .*(unraid-aicliagents|/\.aicli/)'

# 2. Storage Layer Reset
log_progress 20 "Clearing stale loopback states..."
IMAGE_PATH="/boot/config/plugins/unraid-aicliagents/aicli-agents.img"
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
         log_progress 30 "Storage already mounted. Proceeding..."
    else
        mount -o loop,compress=zstd:1,noatime,nodiratime,autodefrag "$IMAGE_PATH" "$AGENT_MNT"
        if [ $? -ne 0 ]; then
            log_progress 30 "ERROR: Mount failed. Attempting deep rescue..."
            btrfs rescue zero-log "$IMAGE_PATH" >/dev/null 2>&1
            btrfs rescue super-recover "$IMAGE_PATH" -y >/dev/null 2>&1
            mount -o loop,compress=zstd:1,noatime,nodiratime,autodefrag "$IMAGE_PATH" "$AGENT_MNT"
            
            # D-170: NUCLEAR OPTION - Recreate image if corrupted beyond repair
            if [ $? -ne 0 ]; then
                log_progress 30 "CRITICAL: Image unrecoverable. Recreating fresh 512MB volume..."
                rm -f "$IMAGE_PATH"
                truncate -s 512M "$IMAGE_PATH"
                mkfs.btrfs -f "$IMAGE_PATH" >/dev/null 2>&1
                mount -o loop,compress=zstd:1,noatime,nodiratime,autodefrag "$IMAGE_PATH" "$AGENT_MNT"
            fi
        fi
    fi
) 200>"$MOUNT_LOCK"

# 3. Native Runtime Restoration
log_progress 40 "Restoring Node.js and system symlinks..."
bash /usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/installer/runtime.sh >/dev/null 2>&1

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
        /usr/bin/php /usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/install-bg.php "$agent" --repair >/dev/null 2>&1
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
