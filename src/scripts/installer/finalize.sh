#!/bin/bash
# AICliAgents Installer: Permissions, Shell Integration & Finalization

# --- Permissions ---
log_step "Setting file permissions..."
# D-80: Prune the 'agents' mount point to avoid thrashing Btrfs loopback metadata.
find "$EMHTTP_DEST" -path "$EMHTTP_DEST/agents" -prune -o -type d -exec chmod 755 {} \;
# D-50: Never blindly reset all files to 644 -- it kills executable bits for agent binaries.
chmod -R 755 "$EMHTTP_DEST/src/scripts" 2>/dev/null || true
chmod -R 755 "$EMHTTP_DEST/src/includes" 2>/dev/null || true
chmod -R 755 "$EMHTTP_DEST/src/assets" 2>/dev/null || true
chmod -R 755 "$EMHTTP_DEST/bin" 2>/dev/null || true
chmod 755 "$EMHTTP_DEST/agents" 2>/dev/null || true
chmod 755 "$EMHTTP_DEST/src/event"/* 2>/dev/null || true
log_ok "Permissions applied."

# --- Cleanup ---
rm -rf /tmp/node-extract-* /tmp/fd-extract-* /tmp/ripgrep-extract-*

# --- Unraid Event Hooks ---
# Unraid fires events via run-parts into /usr/local/emhttp/plugins/dynamix/events/<event>/.
# The full list (from emhttpd binary): stopping, stopping_array, stopping_svcs,
# stopping_docker, stopping_libvirt, disks_mounted, svcs_restarting, svcs_restarted.
#
# We need `stopping_array` — fires RIGHT BEFORE the user-share unmount, which is
# exactly when our tmux/ttyd sessions (CWDs under /mnt/user) would block the array.
# `stopping` only fires at full server shutdown (wrong for array-stop case), so we
# hook both for belt-and-suspenders: array-stop uses stopping_array, server-shutdown
# still works via stopping.
#
# run-parts skips symlinks, so we write real wrapper scripts that exec our handler.
log_step "Registering Unraid event hooks..."
EVENT_DIR_STOPPING="/usr/local/emhttp/plugins/dynamix/events/stopping"
EVENT_DIR_STOPPING_ARRAY="/usr/local/emhttp/plugins/dynamix/events/stopping_array"
EVENT_DIR_MOUNTED="/usr/local/emhttp/plugins/dynamix/events/disks_mounted"
mkdir -p "$EVENT_DIR_STOPPING" "$EVENT_DIR_STOPPING_ARRAY" "$EVENT_DIR_MOUNTED"
# Remove old hooks if present (including legacy symlinks)
rm -f "$EVENT_DIR_STOPPING/aicli_sync" \
      "$EVENT_DIR_STOPPING_ARRAY/aicli_sync" \
      "$EVENT_DIR_MOUNTED/aicli_restore"
# Write the dispatcher — same handler script, same logic; it detects scenario internally.
for dir in "$EVENT_DIR_STOPPING" "$EVENT_DIR_STOPPING_ARRAY"; do
    cat > "$dir/aicli_sync" <<HOOK
#!/bin/bash
exec bash "$EMHTTP_DEST/src/event/stopping"
HOOK
    chmod 755 "$dir/aicli_sync"
done
cat > "$EVENT_DIR_MOUNTED/aicli_restore" <<HOOK
#!/bin/bash
exec bash "$EMHTTP_DEST/src/event/disks_mounted"
HOOK
chmod 755 "$EVENT_DIR_MOUNTED/aicli_restore"
log_ok "Event hooks registered (stopping + stopping_array + disks_mounted)."

# --- Global Shell Integration ---
log_step "Applying global shell integration (aliases & PATH)..."
ln -sf "$EMHTTP_DEST/src/scripts/installer/shell-integration.sh" "/etc/profile.d/aicliagents.sh"
chmod 755 "$EMHTTP_DEST/src/scripts/installer/shell-integration.sh"
log_ok "Shell integration applied (/etc/profile.d/aicliagents.sh)."

# --- Manual Management Scripts ---
log_step "Deploying management scripts..."
if [ -f "$EMHTTP_DEST/src/scripts/user/repair-plugin.sh" ]; then
    chmod +x "$EMHTTP_DEST/src/scripts/user/repair-plugin.sh"
    ln -sf "$EMHTTP_DEST/src/scripts/user/repair-plugin.sh" "/usr/local/bin/aicli-repair"
    log_ok "aicli-repair command available."
fi

# --- PHP Post-Install Tasks ---
log_step "Initializing plugin services..."
php -r "
require_once '/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/AICliAgentsManager.php';
aicli_migrate_home_path();
aicli_cleanup_legacy();
aicli_boot_resurrection();
saveAICliConfig(['version' => '$VERSION']);
" > /dev/null 2>&1
log_ok "Services initialized. Plugin updated to v$VERSION."

# --- Agent Version Check Cron ---
log_step "Registering agent version check schedule..."
CRON_FILE="/etc/cron.d/unraid-aicliagents.agent-check"
AGENT_CHECK_SCRIPT="$EMHTTP_DEST/src/scripts/agentcheck"
chmod 755 "$AGENT_CHECK_SCRIPT" 2>/dev/null
# Read schedule from config, default to daily at 6am
SCHEDULE=$(grep -oP 'version_check_schedule="\K[^"]+' /boot/config/plugins/unraid-aicliagents/unraid-aicliagents.cfg 2>/dev/null || echo "0 6 * * *")
[ -z "$SCHEDULE" ] && SCHEDULE="0 6 * * *"
cat > "$CRON_FILE" <<CRON
# AICliAgents: Agent version check schedule
$SCHEDULE $AGENT_CHECK_SCRIPT &> /dev/null
CRON
/usr/local/sbin/update_cron 2>/dev/null || true
log_ok "Agent version check scheduled ($SCHEDULE)."

# Verify UI entry points (D-186: Ensure entry points exist for emhttp)
cd "$EMHTTP_DEST"
MISSING_ENTRY=0
for f in AICliAgents.page AICliAgentsManager.page AICliAjax.php ArrayStopWarning.page; do
    if [ ! -f "$f" ]; then
        cp -f "src/$f" "$f"
        MISSING_ENTRY=$((MISSING_ENTRY + 1))
    fi
done
[ "$MISSING_ENTRY" -gt 0 ] && log_status "  > Restored $MISSING_ENTRY missing UI entry point(s)."
cd - > /dev/null

