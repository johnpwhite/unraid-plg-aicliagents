$file = "unraid-aicliagents.plg"
$lines = Get-Content $file -Encoding UTF8
$startLine = 1263
$endLine = 1309

$newContent = @'
status "Initializing configuration and hybrid storage..."
# D-26: Migration for Hybrid Storage (RAM-Flash)
CONFIG_FILE="$CONFIG_DIR/unraid-aicliagents.cfg"
if [ -f "$CONFIG_FILE" ]; then
    # Robust extraction of USER_NAME (handles quotes and spaces)
    USER_NAME=$(grep "user=" "$CONFIG_FILE" | sed -e 's/user=//' -e 's/"//g' -e "s/'//g")
    [ -z "$USER_NAME" ] && USER_NAME="root"
    
    echo "  > Target user: $USER_NAME"
    
    # 1. Flash-to-Flash Migration: Legacy /home -> /persistence/<user>/home
    LEGACY_HOME="$CONFIG_DIR/home"
    NEW_PERSIST="$CONFIG_DIR/persistence/$USER_NAME/home"
    if [ -d "$LEGACY_HOME" ] && [ "$LEGACY_HOME" != "$NEW_PERSIST" ] && [ "$LEGACY_HOME" != "$NEW_PERSIST/" ]; then
        status "Migrating legacy Home content on Flash to $USER_NAME persistence..."
        mkdir -p "$NEW_PERSIST"
        # Use rsync to ensure hidden files are moved correctly
        if rsync -a "$LEGACY_HOME/" "$NEW_PERSIST/"; then
            echo "  > Legacy migration successful. Cleaning up legacy folder..."
            rm -rf "$LEGACY_HOME"
        else
            echo "  ! Legacy migration failed. Keeping legacy folder."
        fi
    fi
    
    # 2. Flash-to-RAM Pre-population: Ensure /tmp is ready (FORCED SYNC)
    # We remove the [ ! -d "$RAM_HOME" ] check to ensure RAM is always in sync with Flash during install
    RAM_WORK_BASE="/tmp/unraid-aicliagents/work"
    RAM_HOME="$RAM_WORK_BASE/$USER_NAME/home"
    if [ -d "$NEW_PERSIST" ]; then
        status "Pre-populating RAM Home for $USER_NAME from persistence..."
        mkdir -p "$RAM_HOME"
        if rsync -a --delete "$NEW_PERSIST/" "$RAM_HOME/"; then
             echo "  > RAM pre-population successful."
        else
             echo "  ! RAM pre-population failed."
        fi
        
        # Ensure ownership is correct for the terminal user
        echo "  > Setting ownership to $USER_NAME:users..."
        chown -R "$USER_NAME":users "$RAM_WORK_BASE" 2>/dev/null || true
        chmod 0755 "$RAM_WORK_BASE" 2>/dev/null || true
        chmod -R 0700 "$RAM_WORK_BASE/$USER_NAME" 2>/dev/null || true
    else
        echo "  > No persistence found for $USER_NAME. Skipping pre-population."
    fi
fi

php -r "require_once '/usr/local/emhttp/plugins/unraid-aicliagents/includes/AICliAgentsManager.php'; aicli_migrate_home_path(); aicli_cleanup_legacy(); `$config = getAICliConfig(); updateAICliMenuVisibility(`$config['enable_tab']); saveAICliConfig(['version' => '&version;']);"

status "Setting permissions..."
find "$EMHTTP_DEST" -type d -exec chmod 755 {} \;
find "$EMHTTP_DEST" -type f -exec chmod 644 {} \;
chmod -R 755 "$EMHTTP_DEST/scripts" 2>/dev/null || true
chmod -R 755 "$EMHTTP_DEST/bin" 2>/dev/null || true

# Cleanup extraction temp dirs
rm -rf /tmp/node-extract-* /tmp/fd-extract-* /tmp/ripgrep-extract-*

status "--- INSTALL COMPLETE ---"
'@

# Replace the lines. Note: Get-Content returns an array (1-indexed in human terms, 0-indexed in PS)
# lines[1262] is line 1263
$before = $lines[0..($startLine - 2)]
$after = $lines[($endLine)..$lines.Length]

$final = $before + $newContent.Split("`n").Trim("`r") + $after
$final | Set-Content $file -Encoding UTF8
Write-Host "Repaired lines $startLine to $endLine in $file"
