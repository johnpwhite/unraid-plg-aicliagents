#!/bin/bash
# AICliAgents Installer: Legacy Gemini CLI Migration

OLD_CONFIG="/boot/config/plugins/unraid-geminicli"

if [ -d "$OLD_CONFIG" ]; then
    step "Migrating legacy Gemini CLI configuration..."

    mkdir -p "$CONFIG_DIR"

    # Migrate config files if not already present in the new location
    if [ ! -f "$CONFIG_DIR/unraid-aicliagents.cfg" ] && [ -f "$OLD_CONFIG/unraid-geminicli.cfg" ]; then
        cp "$OLD_CONFIG/unraid-geminicli.cfg" "$CONFIG_DIR/unraid-aicliagents.cfg"
        echo "    > Config migrated: unraid-aicliagents.cfg" >&3
    fi
    if [ ! -f "$CONFIG_DIR/secrets.cfg" ] && [ -f "$OLD_CONFIG/secrets.cfg" ]; then
        cp "$OLD_CONFIG/secrets.cfg" "$CONFIG_DIR/secrets.cfg"
        echo "    > Secrets migrated: secrets.cfg" >&3
    fi

    # Migrate home directory contents (history, cache, etc.)
    if [ -d "$OLD_CONFIG/home" ]; then
        mkdir -p "$CONFIG_DIR/home"
        rsync -a "$OLD_CONFIG/home/" "$CONFIG_DIR/home/"
        echo "    > Home directory migrated." >&3
    fi

    echo "    > Renaming legacy plugin directory for later cleanup..." >&3
    mv "$OLD_CONFIG" "$OLD_CONFIG.migrated.$(date +%s)" 2>/dev/null
    ok "Legacy Gemini CLI migration complete."
fi

# --- Purge Legacy Plugin Registrations ---
step "Purging legacy registrations..."
rm -f /boot/config/plugins/unraid-geminicli.plg
rm -f /boot/config/plugins/geminicli.plg
rm -f /var/log/plugins/unraid-geminicli.plg
rm -f /var/log/plugins/geminicli.plg
rm -rf /usr/local/emhttp/plugins/unraid-geminicli
rm -rf /usr/local/emhttp/plugins/geminicli
rm -f /usr/local/bin/gemini
ok "Legacy registrations cleared."
