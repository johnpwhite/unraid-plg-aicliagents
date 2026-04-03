#!/bin/bash
# AICliAgents Installer: Runtime Dependencies (Node, tmux, fd, rg)

# D-170: Move Runtime to plugin root (Flash/RAM) instead of Btrfs agents image.
# This ensures that if the agent image is corrupted or being replaced during a repair,
# the core system tools (Node, tmux, etc.) remain available to the repair engine.
RUNTIME_BASE="$EMHTTP_DEST/.runtime"
BIN_DEST="$EMHTTP_DEST/bin"
mkdir -p "$RUNTIME_BASE"
mkdir -p "$BIN_DEST"

# --- 1. Node.js Runtime (v22+ required) ---
NODE_TAR="node-v22.22.0-linux-x64.tar.gz"
NODE_URL="https://nodejs.org/dist/v22.22.0/node-v22.22.0-linux-x64.tar.gz"

USE_SYSTEM_NODE=0
if command -v node > /dev/null 2>&1; then
    NODE_VER=$(node -v | sed 's/v//' | cut -d. -f1)
    if [[ "$NODE_VER" =~ ^[0-9]+$ ]] && [ "$NODE_VER" -ge 22 ]; then
        USE_SYSTEM_NODE=1
    fi
fi

step "Node.js runtime..."
if [ "$USE_SYSTEM_NODE" -eq 1 ]; then
    ok "System Node.js v$NODE_VER -- using system runtime."
    ln -sf $(which node) "$BIN_DEST/node"
    if command -v npm > /dev/null 2>&1; then ln -sf $(which npm) "$BIN_DEST/npm"; fi
    if command -v npx > /dev/null 2>&1; then ln -sf $(which npx) "$BIN_DEST/npx"; fi
else
    if [ ! -f "$RUNTIME_BASE/node/bin/node" ]; then
        echo "    > Downloading portable Node.js..." >&3
        if [ ! -f "$CONFIG_DIR/$NODE_TAR" ]; then
            wget -q --timeout=15 --tries=3 -O "$CONFIG_DIR/$NODE_TAR" "$NODE_URL"
        fi
        TMP_EXTRACT="/tmp/node-extract-$$"
        mkdir -p "$TMP_EXTRACT"
        tar -xf "$CONFIG_DIR/$NODE_TAR" -C "$TMP_EXTRACT" --no-same-owner
        NODE_DIR=$(find "$TMP_EXTRACT" -maxdepth 1 -mindepth 1 -type d -name "node-*" | head -n 1)
        mkdir -p "$RUNTIME_BASE/node"
        cp -r "$NODE_DIR/"* "$RUNTIME_BASE/node/"
        chmod +x "$RUNTIME_BASE/node/bin/"*
        rm -rf "$TMP_EXTRACT"
        rm -f "$CONFIG_DIR/$NODE_TAR"
        ok "Portable Node.js installed to Btrfs storage."
    else
        ok "Portable Node.js found in Btrfs storage."
    fi
    ln -sf "$RUNTIME_BASE/node/bin/node" "$BIN_DEST/node"
    ln -sf "$RUNTIME_BASE/node/bin/npm" "$BIN_DEST/npm"
    ln -sf "$RUNTIME_BASE/node/bin/npx" "$BIN_DEST/npx"
fi

# Global npm/npx symlinks
[ ! -e /usr/local/bin/npm ] && ln -sf "$BIN_DEST/npm" /usr/local/bin/npm
[ ! -e /usr/local/bin/npx ] && ln -sf "$BIN_DEST/npx" /usr/local/bin/npx

# --- 2. tmux (v3.6a) ---
TMUX_TAR="tmux-v3.6a.gz"
TMUX_URL="https://github.com/mjakob-gh/build-static-tmux/releases/download/v3.6a/tmux.linux-amd64.gz"

step "tmux..."
# Always remove stale global symlinks first to prevent circular references on upgrades.
# (Prior installs leave /usr/local/bin/tmux -> bin/tmux; without this, `which tmux`
#  resolves to our own symlink and we create a loop: bin/tmux -> /usr/local/bin/tmux -> bin/tmux)
rm -f /usr/local/bin/tmux "$BIN_DEST/tmux"

# Resolve tmux to a real binary — not a symlink into our own paths
REAL_TMUX=$(command -v tmux 2>/dev/null)
if [ -n "$REAL_TMUX" ] && [ -f "$REAL_TMUX" ] && [[ "$REAL_TMUX" != "$BIN_DEST"* ]] && [[ "$REAL_TMUX" != "/usr/local/bin/tmux" ]]; then
    ok "System tmux found at $REAL_TMUX."
    ln -sf "$REAL_TMUX" "$BIN_DEST/tmux"
else
    if [ ! -f "$RUNTIME_BASE/bin/tmux" ]; then
        echo "    > Downloading portable tmux..." >&3
        if [ ! -f "$CONFIG_DIR/$TMUX_TAR" ]; then
            wget -q --timeout=15 --tries=3 -O "$CONFIG_DIR/$TMUX_TAR" "$TMUX_URL"
        fi
        mkdir -p "$RUNTIME_BASE/bin"
        gunzip -c "$CONFIG_DIR/$TMUX_TAR" > "$RUNTIME_BASE/bin/tmux"
        chmod +x "$RUNTIME_BASE/bin/tmux"
        rm -f "$CONFIG_DIR/$TMUX_TAR"
        ok "Portable tmux installed."
    else
        ok "Portable tmux found in plugin root."
    fi
    ln -sf "$RUNTIME_BASE/bin/tmux" "$BIN_DEST/tmux"
fi
ln -sf "$BIN_DEST/tmux" /usr/local/bin/tmux

# --- 3. fd and ripgrep ---
FD_TAR="fd-v10.3.0-x86_64-unknown-linux-musl.tar.gz"
FD_URL="https://github.com/sharkdp/fd/releases/download/v10.3.0/$FD_TAR"
RG_TAR="ripgrep-14.1.0-x86_64-unknown-linux-musl.tar.gz"
RG_URL="https://github.com/BurntSushi/ripgrep/releases/download/14.1.0/$RG_TAR"

install_tool() {
    local tar=$1 url=$2 name=$3
    step "$name..."
    if command -v "$name" > /dev/null 2>&1; then
        ok "System $name found."
        ln -sf $(which "$name") "$BIN_DEST/$name"
    else
        if [ ! -f "$RUNTIME_BASE/bin/$name" ]; then
            echo "    > Downloading portable $name..." >&3
            if [ ! -f "$CONFIG_DIR/$tar" ]; then
                wget -q --timeout=15 --tries=3 -O "$CONFIG_DIR/$tar" "$url"
            fi
            local tmp="/tmp/$name-extract-$$"
            mkdir -p "$tmp"
            tar -xf "$CONFIG_DIR/$tar" -C "$tmp" --no-same-owner
            local bin=$(find "$tmp" -name "$name" -type f -executable | head -n 1)
            mkdir -p "$RUNTIME_BASE/bin"
            mv "$bin" "$RUNTIME_BASE/bin/$name"
            chmod +x "$RUNTIME_BASE/bin/$name"
            rm -rf "$tmp"
            rm -f "$CONFIG_DIR/$tar"
            ok "Portable $name installed."
        else
            ok "$name found in plugin root."
        fi
        ln -sf "$RUNTIME_BASE/bin/$name" "$BIN_DEST/$name"
    fi
    [ ! -e "/usr/local/bin/$name" ] && ln -sf "$BIN_DEST/$name" "/usr/local/bin/$name"
}

install_tool "$FD_TAR" "$FD_URL" "fd"
install_tool "$RG_TAR" "$RG_URL" "rg"

# --- 4. aicli Wrapper ---
NODE_EXEC="$BIN_DEST/node"
cat <<EOF > /usr/local/bin/aicli
#!/bin/bash
"$NODE_EXEC" "$EMHTTP_DEST/agents/gemini-cli/node_modules/.bin/aicli" "\$@"
EOF
chmod +x /usr/local/bin/aicli

# --- 5. Legacy Cleanup ---
if [ -d "$EMHTTP_DEST/lib" ]; then
    warn "Removing legacy RAM libraries..."
    rm -rf "$EMHTTP_DEST/lib"
fi
if [ -d "$BIN_DEST/node_modules" ]; then
    warn "Removing legacy RAM node_modules..."
    rm -rf "$BIN_DEST/node_modules"
fi

ok "All runtime dependencies ready."
