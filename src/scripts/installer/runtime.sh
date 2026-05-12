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

log_step "Node.js runtime..."
if [ "$USE_SYSTEM_NODE" -eq 1 ]; then
    log_ok "System Node.js v$NODE_VER -- using system runtime."
    ln -sf "$(which node)" "$BIN_DEST/node"
    if command -v npm > /dev/null 2>&1; then ln -sf "$(which npm)" "$BIN_DEST/npm"; fi
    if command -v npx > /dev/null 2>&1; then ln -sf "$(which npx)" "$BIN_DEST/npx"; fi
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
        log_ok "Portable Node.js installed to Btrfs storage."
    else
        log_ok "Portable Node.js found in Btrfs storage."
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

log_step "tmux..."
# Always remove stale global symlinks first to prevent circular references on upgrades.
# (Prior installs leave /usr/local/bin/tmux -> bin/tmux; without this, `which tmux`
#  resolves to our own symlink and we create a loop: bin/tmux -> /usr/local/bin/tmux -> bin/tmux)
rm -f /usr/local/bin/tmux "$BIN_DEST/tmux"

# Resolve tmux to a real binary — not a symlink into our own paths
REAL_TMUX=$(command -v tmux 2>/dev/null)
if [ -n "$REAL_TMUX" ] && [ -f "$REAL_TMUX" ] && [[ "$REAL_TMUX" != "$BIN_DEST"* ]] && [[ "$REAL_TMUX" != "/usr/local/bin/tmux" ]]; then
    log_ok "System tmux found at $REAL_TMUX."
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
        log_ok "Portable tmux installed."
    else
        log_ok "Portable tmux found in plugin root."
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
    log_step "$name..."
    if command -v "$name" > /dev/null 2>&1; then
        log_ok "System $name found."
        ln -sf "$(which "$name")" "$BIN_DEST/$name"
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
            log_ok "Portable $name installed."
        else
            log_ok "$name found in plugin root."
        fi
        ln -sf "$RUNTIME_BASE/bin/$name" "$BIN_DEST/$name"
    fi
    [ ! -e "/usr/local/bin/$name" ] && ln -sf "$BIN_DEST/$name" "/usr/local/bin/$name"
}

install_tool "$FD_TAR" "$FD_URL" "fd"
install_tool "$RG_TAR" "$RG_URL" "rg"

# --- 4. squashfs-tools (mksquashfs/unsquashfs) ---
# D-304: Ensure SquashFS tools are available for the new storage architecture.
# UPDATED: Slackware 15.0 moved tools to 'ap' series and updated to -2 rebuild.
SQUASH_TAR="squashfs-tools-4.5-x86_64-2.txz"
SQUASH_URL="https://mirrors.slackware.com/slackware/slackware64-15.0/slackware64/ap/$SQUASH_TAR"
SQUASH_URL_ALT="https://slackware.uk/slackware/slackware64-15.0/slackware64/ap/$SQUASH_TAR"

log_step "squashfs-tools..."

# D-307: Prevent circular symlinks. 
# If 'which' returns our own BIN_DEST or /usr/local/bin symlink, it's not a 'System' tool.
SYS_MKSQUASHFS=$(which mksquashfs 2>/dev/null)
IS_SYSTEM=0
if [ -n "$SYS_MKSQUASHFS" ] && [ -f "$SYS_MKSQUASHFS" ]; then
    # If it's not a symlink to our own bin, it's a real system tool
    if [[ "$SYS_MKSQUASHFS" != "$BIN_DEST"* ]] && [[ "$SYS_MKSQUASHFS" != "/usr/local/bin/mksquashfs" ]]; then
        IS_SYSTEM=1
    fi
fi

if [ "$IS_SYSTEM" -eq 1 ]; then
    log_ok "System mksquashfs found at $SYS_MKSQUASHFS."
    rm -f "$BIN_DEST/mksquashfs" "$BIN_DEST/unsquashfs"
    ln -sf "$SYS_MKSQUASHFS" "$BIN_DEST/mksquashfs"
    SYS_UNSQUASHFS=$(which unsquashfs 2>/dev/null)
    [ -n "$SYS_UNSQUASHFS" ] && ln -sf "$SYS_UNSQUASHFS" "$BIN_DEST/unsquashfs"
else
    if [ ! -f "$RUNTIME_BASE/bin/mksquashfs" ]; then
        if [ ! -f "$CONFIG_DIR/$SQUASH_TAR" ]; then
            echo "    > Downloading squashfs-tools..."
            wget -q --timeout=15 --tries=3 -O "$CONFIG_DIR/$SQUASH_TAR" "$SQUASH_URL" || \
            wget -q --timeout=15 --tries=3 -O "$CONFIG_DIR/$SQUASH_TAR" "$SQUASH_URL_ALT"
        fi
        
        if [ ! -f "$CONFIG_DIR/$SQUASH_TAR" ] || [ ! -s "$CONFIG_DIR/$SQUASH_TAR" ]; then
            log_fail "Failed to download squashfs-tools from all mirrors."
            rm -f "$CONFIG_DIR/$SQUASH_TAR"
            exit 1
        fi

        TMP_EXTRACT="/tmp/squash-extract-$$"
        mkdir -p "$TMP_EXTRACT"
        
        echo "    > Extracting squashfs-tools..."
        if ! tar -xf "$CONFIG_DIR/$SQUASH_TAR" -C "$TMP_EXTRACT" --no-same-owner 2>&1; then
            log_fail "Extraction of squashfs-tools failed. The download may be corrupted."
            rm -rf "$TMP_EXTRACT"
            rm -f "$CONFIG_DIR/$SQUASH_TAR"
            exit 1
        fi

        mkdir -p "$RUNTIME_BASE/bin"
        
        # Verify files exist in extraction before copying
        if [ ! -f "$TMP_EXTRACT/usr/bin/mksquashfs" ]; then
            log_fail "mksquashfs binary missing from extracted package."
            rm -rf "$TMP_EXTRACT"
            exit 1
        fi

        cp "$TMP_EXTRACT/usr/bin/mksquashfs" "$RUNTIME_BASE/bin/mksquashfs"
        cp "$TMP_EXTRACT/usr/bin/unsquashfs" "$RUNTIME_BASE/bin/unsquashfs"
        chmod +x "$RUNTIME_BASE/bin/mksquashfs" "$RUNTIME_BASE/bin/unsquashfs"
        
        rm -rf "$TMP_EXTRACT"
        rm -f "$CONFIG_DIR/$SQUASH_TAR"
        
        if [ -x "$RUNTIME_BASE/bin/mksquashfs" ]; then
            log_ok "Portable squashfs-tools installed successfully."
        else
            log_fail "Failed to finalize squashfs-tools installation."
            exit 1
        fi
    else
        log_ok "squashfs-tools found in plugin root."
    fi
    rm -f "$BIN_DEST/mksquashfs" "$BIN_DEST/unsquashfs"
    ln -sf "$RUNTIME_BASE/bin/mksquashfs" "$BIN_DEST/mksquashfs"
    ln -sf "$RUNTIME_BASE/bin/unsquashfs" "$BIN_DEST/unsquashfs"
fi

# Final Global Symlinks (Ensure they point to our clean BIN_DEST proxy)
rm -f /usr/local/bin/mksquashfs /usr/local/bin/unsquashfs
ln -sf "$BIN_DEST/mksquashfs" /usr/local/bin/mksquashfs
ln -sf "$BIN_DEST/unsquashfs" /usr/local/bin/unsquashfs

# --- 5. Agent Proxy Wrappers ---
# D-346: Create robust proxy scripts in BIN_DEST that point to the actual entry points.
# This bypasses broken NPM symlinks in SquashFS and ensures they are in the PATH.
log_step "Agent proxy wrappers..."

create_proxy() {
    local cmd=$1 pkg_path=$2
    local wrapper="$BIN_DEST/$cmd"
    local target="$EMHTTP_DEST/agents/$pkg_path"
    local bin_dest="$BIN_DEST"

    cat <<'PROXYEOF' | sed -e "s|__BIN_DEST__|${bin_dest}|g" -e "s|__TARGET__|${target}|g" -e "s|__CMD__|${cmd}|g" -e "s|__PKG_PATH__|${pkg_path}|g" > "$wrapper"
#!/bin/bash
# AICliAgents Proxy Wrapper for __CMD__
export PATH="__BIN_DEST__:$PATH"
if [[ "__PKG_PATH__" == *.js ]]; then
    # Direct JS entry point
    "__BIN_DEST__/node" "__TARGET__" "$@"
elif [ -f "__TARGET__" ] && head -c 4 "__TARGET__" | grep -q 'ELF'; then
    # ELF Binary
    "__TARGET__" "$@"
else
    # Script or symlink - try native execution first, fallback to node
    if ! "__TARGET__" "$@" 2>/dev/null; then
        "__BIN_DEST__/node" "__TARGET__" "$@"
    fi
fi
PROXYEOF
    chmod +x "$wrapper"
    ln -sf "$wrapper" "/usr/local/bin/$cmd"
}

# Proxy mapping: cmd -> relative path from /agents/ to entry
create_proxy "gemini" "gemini-cli/node_modules/@google/gemini-cli/bundle/gemini.js"
create_proxy "copilot" "gh-copilot/node_modules/@github/copilot/index.js"
create_proxy "claude" "claude-code/node_modules/@anthropic-ai/claude-code/cli.js"
create_proxy "opencode" "opencode/node_modules/opencode-ai/bin/opencode"
create_proxy "kilo" "kilocode/node_modules/@kilocode/cli/bin/kilo"
create_proxy "pi" "pi-coder/node_modules/@mariozechner/pi-coding-agent/dist/cli.js"
create_proxy "codex" "codex-cli/node_modules/.bin/codex"
create_proxy "droid" "factory-cli/node_modules/.bin/droid"
create_proxy "nanocoder" "nanocoder/node_modules/.bin/nanocoder"
create_proxy "goose" "goose/bin/goose"
create_proxy "qwen" "qwen-code/node_modules/@qwen-code/qwen-code/cli.js"

log_ok "Agent proxies established (gemini, copilot, claude, opencode, kilo, pi, codex, droid, nanocoder, goose, qwen)."

# --- 6. Docker Tool Wrappers ---
# Tools that require Docker containers. The wrapper either proxies to the container
# or returns a helpful error message telling the user/agent how to install it.
log_step "Docker tool wrappers..."

create_docker_proxy() {
    local cmd=$1 image=$2 docker_cmd=$3 description=$4
    local wrapper="$BIN_DEST/$cmd"
    local log_file="/tmp/unraid-aicliagents/debug.log"

    cat > "$wrapper" <<'DOCKEREOF'
#!/bin/bash
# AICliAgents Docker Proxy: __CMD__
CMD="__CMD__"
IMAGE="__IMAGE__"
DOCKER_CMD="__DOCKER_CMD__"
DESCRIPTION="__DESCRIPTION__"
LOG="/tmp/unraid-aicliagents/debug.log"

log_proxy() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] [INFO] [DockerProxy] $1" >> "$LOG" 2>/dev/null
}

log_proxy "$CMD invoked with args: $*"

if ! command -v docker >/dev/null 2>&1; then
    MSG="$CMD requires Docker, which is not available on this system. To use $CMD: 1) Enable Docker in Unraid Settings > Docker. 2) Pull the image: docker pull $IMAGE"
    log_proxy "BLOCKED: Docker not available"
    echo "$MSG" >&2
    exit 1
fi

if ! docker image inspect "$IMAGE" >/dev/null 2>&1; then
    MSG="$CMD requires the Docker image '$IMAGE' which is not yet pulled. To install, run: docker pull $IMAGE — or search '$CMD' in Unraid's Apps tab. $DESCRIPTION"
    log_proxy "BLOCKED: Docker image $IMAGE not found"
    echo "$MSG" >&2
    exit 1
fi

log_proxy "Executing via Docker: $IMAGE $DOCKER_CMD $*"
exec docker run --rm -v "$(pwd)":/src -w /src "$IMAGE" $DOCKER_CMD "$@"
DOCKEREOF

    # Replace placeholders with actual values
    sed -i "s|__CMD__|${cmd}|g" "$wrapper"
    sed -i "s|__IMAGE__|${image}|g" "$wrapper"
    sed -i "s|__DOCKER_CMD__|${docker_cmd}|g" "$wrapper"
    sed -i "s|__DESCRIPTION__|${description}|g" "$wrapper"

    chmod +x "$wrapper"
    ln -sf "$wrapper" "/usr/local/bin/$cmd"
}

create_docker_proxy "semgrep" "semgrep/semgrep" "semgrep" \
    "Semgrep is a static analysis tool for finding bugs and security issues. Used by Claude Code for Python linting."

DOCKER_TOOLS_MSG=""
if command -v docker >/dev/null 2>&1; then
    if docker image inspect "semgrep/semgrep" >/dev/null 2>&1; then
        log_ok "Docker tool wrappers ready (semgrep: container available)."
    else
        DOCKER_TOOLS_MSG="semgrep"
        log_ok "Docker tool wrappers ready (semgrep: wrapper installed, container not yet pulled)."
    fi
else
    DOCKER_TOOLS_MSG="semgrep"
    log_ok "Docker tool wrappers ready (semgrep: wrapper installed, Docker not running)."
fi

if [ -n "$DOCKER_TOOLS_MSG" ]; then
    log_status "  > NOTE: For full linting support, install the Docker container for: $DOCKER_TOOLS_MSG"
    log_status "  >   Pull manually: docker pull semgrep/semgrep"
    log_status "  >   Or search 'semgrep' in Unraid's Apps tab."
fi

# --- 7. Legacy Cleanup ---
# Remove old runtime artifacts from previous plugin architectures.
if [ -d "$EMHTTP_DEST/lib" ]; then
    log_warn "Removing legacy RAM libraries..."
    rm -rf "$EMHTTP_DEST/lib"
fi
if [ -d "$BIN_DEST/node_modules" ]; then
    log_warn "Removing legacy RAM node_modules..."
    rm -rf "$BIN_DEST/node_modules"
fi

log_ok "All runtime dependencies ready."
