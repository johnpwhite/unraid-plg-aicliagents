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
# Bug #1042: the Secret Service daemon binary + launcher must be executable.
chmod -R 755 "$EMHTTP_DEST/src/secret-service" 2>/dev/null || true
chmod -R 755 "$EMHTTP_DEST/bin" 2>/dev/null || true
chmod 755 "$EMHTTP_DEST/agents" 2>/dev/null || true
chmod 755 "$EMHTTP_DEST/src/event"/* 2>/dev/null || true
log_ok "Permissions applied."

# --- Cleanup ---
rm -rf /tmp/node-extract-* /tmp/fd-extract-* /tmp/ripgrep-extract-*

# --- Unraid Event Hooks ---
# emhttpd calls /usr/local/sbin/emhttp_event <event> which loops over
# /usr/local/emhttp/plugins/*/event/<event> — the plugin's OWN event/ directory.
# It does NOT use /usr/local/emhttp/plugins/dynamix/events/.
#
# Event timing (from emhttp_event):
#   stopping          — beginning of cmdStop (before any unmounting) ← our hook point
#   unmounting_disks  — about to unmount disks and user shares
#   stopping_array    — AFTER user shares already unmounted (too late to prevent EBUSY)
#   disks_mounted     — user shares mounted (array start)
#
# We hook `stopping` (fires first, before shares unmount) so we can kill agent sessions
# whose CWDs are under /mnt/user before emhttpd tries umount. stopping_array is too late.
# The src/event/stopping handler internally detects IS_SHUTDOWN to distinguish a full
# server shutdown (kill everything) from an array-only stop (selective kill).
log_step "Registering Unraid event hooks..."

# Create event/ → src/event/ symlink so emhttp_event can find our scripts.
# emhttp_event checks for $Dir/event/<name> as a file or $Dir/event/<name>/ as a dir.
rm -f "$EMHTTP_DEST/event" 2>/dev/null || true
ln -sf "$EMHTTP_DEST/src/event" "$EMHTTP_DEST/event"

# Clean up the legacy dynamix hooks (wrong path — emhttpd never called them).
rm -f "/usr/local/emhttp/plugins/dynamix/events/stopping/aicli_sync" \
      "/usr/local/emhttp/plugins/dynamix/events/stopping_array/aicli_sync" \
      "/usr/local/emhttp/plugins/dynamix/events/disks_mounted/aicli_restore" 2>/dev/null || true

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
# Follow-on #1: the plugin VERSION upgrade is the explicit format-migration
# trigger. The version-gated FileStorage::migrateFormat call lives in a SCRIPT
# FILE (not an inline php -r) so its namespaced facade calls aren't mangled by
# bash backslash handling (publish anti-pattern check). It MUST run BEFORE the
# version is saved below so it can read the OLD version. (Today migrateFormat is a
# no-op beyond the gate; the slow btrfs→squashfs conversion stays BACKGROUNDED —
# see D-308.)
php /usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/installer/format-migrate.php "$VERSION" > /dev/null 2>&1

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

# --- Plugin Health Check Cron (R-09, Feature #1372) ---
log_step "Registering plugin health check schedule..."
HEALTH_CRON_FILE="/etc/cron.d/unraid-aicliagents.health-check"
HEALTH_SCRIPT="$EMHTTP_DEST/src/scripts/healthcheck.php"
# Key absent -> default every 30 min; key present but EMPTY -> user disabled it
# (mirrors ConfigService::updateHealthCheckCron semantics).
if grep -q '^health_check_schedule=' /boot/config/plugins/unraid-aicliagents/unraid-aicliagents.cfg 2>/dev/null; then
    HEALTH_SCHEDULE=$(grep -oP 'health_check_schedule="\K[^"]*' /boot/config/plugins/unraid-aicliagents/unraid-aicliagents.cfg 2>/dev/null | head -n1)
else
    HEALTH_SCHEDULE="*/30 * * * *"
fi
if [ -n "$HEALTH_SCHEDULE" ]; then
    cat > "$HEALTH_CRON_FILE" <<CRON
# AICliAgents: plugin health check schedule
$HEALTH_SCHEDULE /usr/bin/php $HEALTH_SCRIPT &> /dev/null
CRON
    log_ok "Plugin health check scheduled ($HEALTH_SCHEDULE)."
else
    rm -f "$HEALTH_CRON_FILE"
    log_ok "Plugin health check disabled by config."
fi
/usr/local/sbin/update_cron 2>/dev/null || true

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

