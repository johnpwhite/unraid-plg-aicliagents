#!/bin/bash
# AICliAgents Installer: Legacy Gemini CLI Migration

OLD_CONFIG="/boot/config/plugins/unraid-geminicli"

if [ -d "$OLD_CONFIG" ]; then
    status "Migrating legacy Gemini CLI configuration..."
    
    # Copy existing config/secrets if they don't exist in the new location
    mkdir -p "$CONFIG_DIR"
    if [ ! -f "$CONFIG_DIR/unraid-aicliagents.cfg" ] && [ -f "$OLD_CONFIG/unraid-geminicli.cfg" ]; then
        cp "$OLD_CONFIG/unraid-geminicli.cfg" "$CONFIG_DIR/unraid-aicliagents.cfg"
    fi
    if [ ! -f "$CONFIG_DIR/secrets.cfg" ] && [ -f "$OLD_CONFIG/secrets.cfg" ]; then
        cp "$OLD_CONFIG/secrets.cfg" "$CONFIG_DIR/secrets.cfg"
    fi
    
    # Migrate home directory contents (history, cache, etc.)
    if [ -d "$OLD_CONFIG/home" ]; then
        mkdir -p "$CONFIG_DIR/home"
        # Use rsync to be safe and include hidden files
        rsync -a "$OLD_CONFIG/home/" "$CONFIG_DIR/home/"
    fi
    
    status "Removing legacy Gemini CLI folders..."
    rm -rf "$OLD_CONFIG"
fi

# Thorough legacy registration cleanup to force removal from plugin list
status "Purging legacy registrations..."
rm -f /boot/config/plugins/unraid-geminicli.plg
rm -f /boot/config/plugins/geminicli.plg
rm -f /var/log/plugins/unraid-geminicli.plg
rm -f /var/log/plugins/geminicli.plg
rm -rf /usr/local/emhttp/plugins/unraid-geminicli
rm -rf /usr/local/emhttp/plugins/geminicli
rm -f /usr/local/bin/gemini
