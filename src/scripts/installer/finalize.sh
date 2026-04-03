#!/bin/bash
# AICliAgents Installer: Permissions, Shell Integration & Finalization

# --- Permissions ---
step "Setting file permissions..."
# D-80: Prune the 'agents' mount point to avoid thrashing Btrfs loopback metadata.
find "$EMHTTP_DEST" -path "$EMHTTP_DEST/agents" -prune -o -type d -exec chmod 755 {} \;
# D-50: Never blindly reset all files to 644 -- it kills executable bits for agent binaries.
chmod -R 755 "$EMHTTP_DEST/scripts" 2>/dev/null || true
chmod -R 755 "$EMHTTP_DEST/bin" 2>/dev/null || true
chmod 755 "$EMHTTP_DEST/agents" 2>/dev/null || true
ok "Permissions applied."

# --- Cleanup ---
rm -rf /tmp/node-extract-* /tmp/fd-extract-* /tmp/ripgrep-extract-*

# --- Unraid Event Hook ---
step "Registering Unraid stopping event hook..."
EVENT_DIR="/usr/local/emhttp/plugins/dynamix/events/stopping"
mkdir -p "$EVENT_DIR"
ln -sf "$EMHTTP_DEST/event/stopping" "$EVENT_DIR/aicli_sync"
ok "Event hook registered."

# --- Global Shell Integration ---
step "Applying global shell integration (aliases & PATH)..."
ln -sf "$EMHTTP_DEST/scripts/installer/shell-integration.sh" "/etc/profile.d/aicliagents.sh"
chmod 755 "$EMHTTP_DEST/scripts/installer/shell-integration.sh"
ok "Shell integration applied (/etc/profile.d/aicliagents.sh)."

# --- Manual Management Scripts ---
step "Deploying management scripts..."
if [ -f "$EMHTTP_DEST/scripts/user/repair-plugin.sh" ]; then
    chmod +x "$EMHTTP_DEST/scripts/user/repair-plugin.sh"
    ln -sf "$EMHTTP_DEST/scripts/user/repair-plugin.sh" "/usr/local/bin/aicli-repair"
    ok "aicli-repair command available."
fi

# --- PHP Post-Install Tasks ---
step "Running post-install PHP migrations..."
php -r "
require_once '/usr/local/emhttp/plugins/unraid-aicliagents/includes/AICliAgentsManager.php';
echo '  [>] Migrating home paths (if needed)...' . PHP_EOL;
aicli_migrate_home_path();
echo '  [>] Clearing legacy registrations...' . PHP_EOL;
aicli_cleanup_legacy();
\$config = getAICliConfig();
if (isset(\$config['enable_tab'])) {
    echo '  [>] Syncing menu visibility...' . PHP_EOL;
    updateAICliMenuVisibility(\$config['enable_tab']);
}
echo '  [>] Finalizing configuration...' . PHP_EOL;
saveAICliConfig(['version' => '$VERSION']);
"
ok "PHP migrations complete. Plugin config updated to v$VERSION."
