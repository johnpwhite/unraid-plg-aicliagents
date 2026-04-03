#!/bin/bash
# AICliAgents Installer: UI & Static Assets

step "Syncing UI components and assets..."

DOWNLOAD_CACHE="$CONFIG_DIR/pkg-cache/source"
mkdir -p "$DOWNLOAD_CACHE"

# Download a file from the plugin's GitLab repository.
# Upgrade mode: always fetches from network (ensures latest). Boot mode: uses cache first.
get_file() {
    local src=$1
    local dest=$2
    local dest_path="$EMHTTP_DEST/$dest"
    local cache_path="$DOWNLOAD_CACHE/$(basename "$dest")"

    mkdir -p "$(dirname "$dest_path")"

    if [ "$UPGRADE_MODE" -eq 1 ]; then
        if wget -q --timeout=15 --tries=3 -O "$dest_path.tmp" "$GIT_URL/$src"; then
            if [[ "$dest" == *.sh || "$dest" == *.php || "$dest" == *.page || "$dest" == *.css || "$dest" == *.js || "$dest" == *.md ]]; then
                tr -d '\r' < "$dest_path.tmp" > "$dest_path"
                rm -f "$dest_path.tmp"
            else
                mv "$dest_path.tmp" "$dest_path"
            fi
            cp "$dest_path" "$cache_path"
        elif [ -f "$cache_path" ]; then
            echo "    [!!] Network failed for $dest -- using cache." >&3
            cp "$cache_path" "$dest_path"
        else
            echo "    [FAIL] Cannot retrieve $dest (network and cache both failed)." >&3
            return 1
        fi
    else
        if [ -f "$cache_path" ]; then
            cp "$cache_path" "$dest_path"
        elif wget -q --timeout=15 --tries=3 -O "$dest_path.tmp" "$GIT_URL/$src"; then
            if [[ "$dest" == *.sh || "$dest" == *.php || "$dest" == *.page || "$dest" == *.css || "$dest" == *.js || "$dest" == *.md ]]; then
                tr -d '\r' < "$dest_path.tmp" > "$dest_path"
                rm -f "$dest_path.tmp"
            else
                mv "$dest_path.tmp" "$dest_path"
            fi
            cp "$dest_path" "$cache_path"
        else
            echo "    [FAIL] Cannot retrieve $dest." >&3
            return 1
        fi
    fi
    return 0
}

# -- Core Scripts --
get_file "src/scripts/aicli-shell.sh"             "scripts/aicli-shell.sh"
chmod +x "$EMHTTP_DEST/scripts/aicli-shell.sh"

get_file "src/scripts/install-bg.php"             "scripts/install-bg.php"
chmod +x "$EMHTTP_DEST/scripts/install-bg.php"

get_file "src/scripts/installer/shell-integration.sh" "scripts/installer/shell-integration.sh"
chmod +x "$EMHTTP_DEST/scripts/installer/shell-integration.sh"

# -- Plugin Pages & PHP --
get_file "src/AICliAgents.page"                   "AICliAgents.page"
get_file "src/AICliAgentsManager.page"             "AICliAgentsManager.page"
get_file "src/AICliAjax.php"                       "AICliAjax.php"
get_file "src/includes/AICliAgentsManager.php"     "includes/AICliAgentsManager.php"

# -- React UI Bundle --
get_file "src/assets/ui/index.js"                 "assets/ui/index.js"
get_file "src/assets/ui/index.css"                "assets/ui/index.css"

# -- Agent Icons --
get_file "src/assets/icons/google-gemini.png"     "assets/icons/google-gemini.png"
get_file "src/assets/icons/claude.ico"            "assets/icons/claude.ico"
get_file "src/assets/icons/opencode.ico"          "assets/icons/opencode.ico"
get_file "src/assets/icons/kilocode.ico"          "assets/icons/kilocode.ico"
get_file "src/assets/icons/picoder.png"           "assets/icons/picoder.png"
get_file "src/assets/icons/codex.png"             "assets/icons/codex.png"
get_file "src/assets/icons/factory.png"           "assets/icons/factory.png"
get_file "src/assets/icons/nanocoder.png"         "assets/icons/nanocoder.png"

# -- Events & Docs --
get_file "src/event/stopping"                     "event/stopping"
chmod +x "$EMHTTP_DEST/event/stopping"

get_file "src/README.md"                          "README.md"

# -- Uninstaller (persisted to emhttp for use during future removal) --
get_file "src/scripts/uninstaller/cleanup.sh"    "scripts/uninstall.sh"
chmod +x "$EMHTTP_DEST/scripts/uninstall.sh"

ok "UI components and assets deployed."
