#!/bin/bash
# AICliAgents Installer: Permissions & Finalization

status "Setting permissions..."
find "$EMHTTP_DEST" -type d -exec chmod 755 {} \;
# D-50: Do NOT blindly reset all files to 644 as it kills executable bits for agent binaries!
# Instead, ensure the key directories are recursively executable.
chmod -R 755 "$EMHTTP_DEST/scripts" 2>/dev/null || true
chmod -R 755 "$EMHTTP_DEST/bin" 2>/dev/null || true
chmod -R 755 "$EMHTTP_DEST/agents" 2>/dev/null || true


# Cleanup extraction temp dirs
rm -rf /tmp/node-extract-* /tmp/fd-extract-* /tmp/ripgrep-extract-*

# Last hook into Unraid events
EVENT_DIR="/usr/local/emhttp/plugins/dynamix/events/stopping"
mkdir -p "$EVENT_DIR"
ln -sf "$EMHTTP_DEST/event/stopping" "$EVENT_DIR/aicli_sync"

# Global Shell Integration (Aliases for SSH/Terminal)
status "Applying global shell integration (aliases & PATH)..."
ln -sf "$EMHTTP_DEST/scripts/installer/shell-integration.sh" "/etc/profile.d/aicliagents.sh"
chmod 755 "$EMHTTP_DEST/scripts/installer/shell-integration.sh"


# PHP internal updates
php -r "require_once '/usr/local/emhttp/plugins/unraid-aicliagents/includes/AICliAgentsManager.php'; aicli_migrate_home_path(); aicli_cleanup_legacy(); \$config = getAICliConfig(); updateAICliMenuVisibility(\$config['enable_tab']); saveAICliConfig(['version' => '$VERSION']);"

status "--- INSTALL COMPLETE ---"
