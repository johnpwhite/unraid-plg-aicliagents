#!/bin/bash
# AICliAgents Installer: Runtime Dependencies (Node, tmux, fd, rg)

# 1. Ensure Node.js runtime (v22+ required)
NODE_TAR="node-v22.22.0-linux-x64.tar.gz"
NODE_URL="https://nodejs.org/dist/v22.22.0/node-v22.22.0-linux-x64.tar.gz"

USE_SYSTEM_NODE=0
if command -v node >/dev/null 2>&1; then
    NODE_VER=$(node -v | sed 's/v//' | cut -d. -f1)
    if [[ "$NODE_VER" =~ ^[0-9]+$ ]] && [ "$NODE_VER" -ge 22 ]; then
        status "System Node.js is v$NODE_VER. Using system runtime."
        USE_SYSTEM_NODE=1
    fi
fi

if [ "$USE_SYSTEM_NODE" -eq 1 ]; then
    mkdir -p "$EMHTTP_DEST/bin"
    ln -sf $(which node) "$EMHTTP_DEST/bin/node"
    if command -v npm >/dev/null 2>&1; then ln -sf $(which npm) "$EMHTTP_DEST/bin/npm"; fi
    if command -v npx >/dev/null 2>&1; then ln -sf $(which npx) "$EMHTTP_DEST/bin/npx"; fi
else
    if [ ! -f "$CONFIG_DIR/$NODE_TAR" ]; then
        status "Ensuring Node.js runtime..."
        wget -q --timeout=15 --tries=3 -O "$CONFIG_DIR/$NODE_TAR" "$NODE_URL"
    fi
    TMP_EXTRACT="/tmp/node-extract-$$"
    mkdir -p "$TMP_EXTRACT"
    if ! tar -xf "$CONFIG_DIR/$NODE_TAR" -C "$TMP_EXTRACT" --no-same-owner; then
        echo "ERROR: Node.js extraction failed. The download may be corrupted."
        rm -f "$CONFIG_DIR/$NODE_TAR"
        exit 1
    fi

    NODE_DIR=$(find "$TMP_EXTRACT" -maxdepth 1 -mindepth 1 -type d -name "node-*" | head -n 1)
    if [ -z "$NODE_DIR" ]; then
        echo "ERROR: Could not find extracted Node directory in $TMP_EXTRACT"
        exit 1
    fi

    mkdir -p "$EMHTTP_DEST/bin"
    mkdir -p "$EMHTTP_DEST/lib"
    status "  > Copying binaries..."
    cp -r "$NODE_DIR/bin/"* "$EMHTTP_DEST/bin/"
    status "  > Copying libraries..."
    if [ -d "$NODE_DIR/lib" ]; then
        cp -r "$NODE_DIR/lib/"* "$EMHTTP_DEST/lib/"
    fi
    if ls "$EMHTTP_DEST/bin/"* >/dev/null 2>&1; then
        chmod +x "$EMHTTP_DEST/bin/"*
    fi
    rm -rf "$TMP_EXTRACT"
fi

# Global symlinks for npm/npx
[ ! -e /usr/local/bin/npm ] && ln -sf "$EMHTTP_DEST/bin/npm" /usr/local/bin/npm
[ ! -e /usr/local/bin/npx ] && ln -sf "$EMHTTP_DEST/bin/npx" /usr/local/bin/npx

# 2. Ensure portable tmux (v3.6a)
TMUX_TAR="tmux-v3.6a.gz"
TMUX_URL="https://github.com/mjakob-gh/build-static-tmux/releases/download/v3.6a/tmux.linux-amd64.gz"

if [ ! -f "$CONFIG_DIR/$TMUX_TAR" ]; then
    status "Ensuring portable tmux..."
    wget -q --timeout=15 --tries=3 -O "$CONFIG_DIR/$TMUX_TAR" "$TMUX_URL"
fi

status "Installing tmux..."
if command -v tmux >/dev/null 2>&1 && [[ "$(which tmux)" != "$EMHTTP_DEST/bin/"* ]]; then
    status "  -> System tmux found. Skipping portable install."
    ln -sf $(which tmux) "$EMHTTP_DEST/bin/tmux"
else
    gunzip -c "$CONFIG_DIR/$TMUX_TAR" > "$EMHTTP_DEST/bin/tmux"
    chmod +x "$EMHTTP_DEST/bin/tmux"
fi
[ ! -e /usr/local/bin/tmux ] && ln -sf "$EMHTTP_DEST/bin/tmux" /usr/local/bin/tmux

# 3. fd and ripgrep
FD_TAR="fd-v10.3.0-x86_64-unknown-linux-musl.tar.gz"
FD_URL="https://github.com/sharkdp/fd/releases/download/v10.3.0/$FD_TAR"
RG_TAR="ripgrep-14.1.0-x86_64-unknown-linux-musl.tar.gz"
RG_URL="https://github.com/BurntSushi/ripgrep/releases/download/14.1.0/$RG_TAR"

install_tool() {
    local tar=$1
    local url=$2
    local name=$3
    if [ ! -f "$CONFIG_DIR/$tar" ]; then
        status "Ensuring portable $name..."
        wget -q --timeout=15 --tries=3 -O "$CONFIG_DIR/$tar" "$url"
    fi
    status "Extracting $name..."
    if command -v "$name" >/dev/null 2>&1 && [[ "$(which "$name")" != "$EMHTTP_DEST/bin/"* ]]; then
        ln -sf $(which "$name") "$EMHTTP_DEST/bin/$name"
    else
        local tmp="/tmp/$name-extract-$$"
        mkdir -p "$tmp"
        tar -xf "$CONFIG_DIR/$tar" -C "$tmp" --no-same-owner
        local bin=$(find "$tmp" -name "$name" -type f -executable | head -n 1)
        mv "$bin" "$EMHTTP_DEST/bin/$name"
        chmod +x "$EMHTTP_DEST/bin/$name"
        rm -rf "$tmp"
    fi
    [ ! -e "/usr/local/bin/$name" ] && ln -sf "$EMHTTP_DEST/bin/$name" "/usr/local/bin/$name"
}

install_tool "$FD_TAR" "$FD_URL" "fd"
install_tool "$RG_TAR" "$RG_URL" "rg"

# 4. Create aicli wrapper (Points to isolated Gemini CLI)
cat << 'EOF' > /usr/local/bin/aicli
#!/bin/bash
"/usr/local/emhttp/plugins/unraid-aicliagents/bin/node" "/usr/local/emhttp/plugins/unraid-aicliagents/agents/gemini-cli/node_modules/.bin/aicli" "$@"
EOF
chmod +x /usr/local/bin/aicli

# 5. Legacy Cleanup (Reclaim RAM from old co-located node_modules)
if [ -d "$EMHTTP_DEST/bin/node_modules" ]; then
    status "Cleaning up legacy shared node_modules to reclaim RAM..."
    rm -rf "$EMHTTP_DEST/bin/node_modules"
fi
