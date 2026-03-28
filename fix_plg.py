import os

path = r"c:\Users\john\unraid-extensions\unraid-plg-aicliagents\unraid-aicliagents.plg"
with open(path, 'rb') as f:
    content = f.read().decode('utf-8')

# 1. Node.js Smart Check
old_node = """# 2. Ensure Node.js runtime (v22.22.0)
NODE_TAR="node-v22.22.0-linux-x64.tar.gz"
NODE_URL="https://nodejs.org/dist/v22.22.0/node-v22.22.0-linux-x64.tar.gz"

if [ ! -f "$CONFIG_DIR/$NODE_TAR" ]; then
    status "Ensuring Node.js runtime..."
    wget -q -O "$CONFIG_DIR/$NODE_TAR" "$NODE_URL"
fi


status "Installing Node.js..."
"""

new_node = """# 2. Ensure Node.js runtime (v22+ required)
NODE_TAR="node-v22.22.0-linux-x64.tar.gz"
NODE_URL="https://nodejs.org/dist/v22.22.0/node-v22.22.0-linux-x64.tar.gz"

USE_SYSTEM_NODE=0
if command -v node &gt;/dev/null 2&gt;&amp;1; then
    NODE_VER=$(node -v | sed 's/v//' | cut -d. -f1)
    if [[ "$NODE_VER" =~ ^[0-9]+$ ]] &amp;&amp; [ "$NODE_VER" -ge 22 ]; then
        status "System Node.js is v$NODE_VER. Using system runtime."
        USE_SYSTEM_NODE=1
    fi
fi

if [ "$USE_SYSTEM_NODE" -eq 1 ]; then
    mkdir -p "$EMHTTP_DEST/bin"
    ln -sf $(which node) "$EMHTTP_DEST/bin/node"
    if command -v npm &gt;/dev/null 2&gt;&amp;1; then ln -sf $(which npm) "$EMHTTP_DEST/bin/npm"; fi
    if command -v npx &gt;/dev/null 2&gt;&amp;1; then ln -sf $(which npx) "$EMHTTP_DEST/bin/npx"; fi
else
    if [ ! -f "$CONFIG_DIR/$NODE_TAR" ]; then
        status "Ensuring Node.js runtime..."
        wget -q -O "$CONFIG_DIR/$NODE_TAR" "$NODE_URL"
    fi
    status "Installing Node.js..."
"""

# 2. Node.js Cleanup Wrap
old_node_cleanup = """rm -rf "$TMP_EXTRACT\""""
new_node_cleanup = """rm -rf "$TMP_EXTRACT"\nfi"""

# 3. npm/npx symlinks
old_npm = """# Create global symlinks for npm/npx if they don't exist
if [ ! -L /usr/local/bin/npm ]; then
    ln -sf "$EMHTTP_DEST/bin/npm" /usr/local/bin/npm
fi
if [ ! -L /usr/local/bin/npx ]; then
    ln -sf "$EMHTTP_DEST/bin/npx" /usr/local/bin/npx
fi"""

new_npm = """# Create global symlinks for npm/npx ONLY if they don't exist in system
if [ ! -e /usr/local/bin/npm ]; then
    ln -sf "$EMHTTP_DEST/bin/npm" /usr/local/bin/npm
fi
if [ ! -e /usr/local/bin/npx ]; then
    ln -sf "$EMHTTP_DEST/bin/npx" /usr/local/bin/npx
fi"""

# 4. tmux
old_tmux = """status "Installing tmux..."
gunzip -c "$CONFIG_DIR/$TMUX_TAR" &gt; "$EMHTTP_DEST/bin/tmux\""""

new_tmux = """status "Installing tmux..."
if command -v tmux &gt;/dev/null 2&gt;&amp;1 &amp;&amp; [[ "$(which tmux)" != "$EMHTTP_DEST/bin/"* ]]; then
    status "  -&gt; System tmux found. Skipping portable install."
    ln -sf $(which tmux) "$EMHTTP_DEST/bin/tmux"
else
    gunzip -c "$CONFIG_DIR/$TMUX_TAR" &gt; "$EMHTTP_DEST/bin/tmux\""""

# 5. fd
old_fd = """status "Extracting fd..."
TMP_EXTRACT_FD="/tmp/fd-extract-$$"
mkdir -p "$TMP_EXTRACT_FD\""""

new_fd = """status "Extracting fd..."
if command -v fd &gt;/dev/null 2&gt;&amp;1 &amp;&amp; [[ "$(which fd)" != "$EMHTTP_DEST/bin/"* ]]; then
    status "  -&gt; System fd found. Skipping portable install."
    ln -sf $(which fd) "$EMHTTP_DEST/bin/fd"
else
    TMP_EXTRACT_FD="/tmp/fd-extract-$$"
    mkdir -p "$TMP_EXTRACT_FD\""""

# 6. rg
old_rg = """status "Extracting ripgrep..."
TMP_EXTRACT_RG="/tmp/ripgrep-extract-$$"
mkdir -p "$TMP_EXTRACT_RG\""""

new_rg = """status "Extracting ripgrep..."
if command -v rg &gt;/dev/null 2&gt;&amp;1 &amp;&amp; [[ "$(which rg)" != "$EMHTTP_DEST/bin/"* ]]; then
    status "  -&gt; System ripgrep (rg) found. Skipping portable install."
    ln -sf $(which rg) "$EMHTTP_DEST/bin/rg"
else
    TMP_EXTRACT_RG="/tmp/ripgrep-extract-$$"
    mkdir -p "$TMP_EXTRACT_RG\""""

# 7. Symlink cleanup at end
old_final = """if [ ! -f /usr/local/bin/tmux ]; then
    ln -sf "$EMHTTP_DEST/bin/tmux" /usr/local/bin/tmux
fi

# Global symlinks for our new tools
ln -sf "$EMHTTP_DEST/bin/fd" /usr/local/bin/fd
ln -sf "$EMHTTP_DEST/bin/rg" /usr/local/bin/rg"""

new_final = """if [ ! -e /usr/local/bin/tmux ]; then
    ln -sf "$EMHTTP_DEST/bin/tmux" /usr/local/bin/tmux
fi

# Global symlinks for our new tools ONLY if they don't exist
if [ ! -e /usr/local/bin/fd ]; then
    ln -sf "$EMHTTP_DEST/bin/fd" /usr/local/bin/fd
fi
if [ ! -e /usr/local/bin/rg ]; then
    ln -sf "$EMHTTP_DEST/bin/rg" /usr/local/bin/rg
fi"""

# Normalize all to CRLF for the search/replace since the file likely has them
# Actually, I'll allow both to handle inconsistencies
def smart_replace(text, old, new):
    # Try literal
    if old in text:
        return text.replace(old, new)
    # Try normalized newlines
    old_norm = old.replace('\n', '\r\n')
    new_norm = new.replace('\n', '\r\n')
    if old_norm in text:
         return text.replace(old_norm, new_norm)
    # Try LF normalized
    old_lf = old.replace('\r\n', '\n')
    new_lf = new.replace('\r\n', '\n')
    if old_lf in text:
         return text.replace(old_lf, new_lf)
    
    print(f"Warning: Could not find block starting with: {old.splitlines()[0]}")
    return text

content = smart_replace(content, old_node, new_node)
content = smart_replace(content, old_node_cleanup, new_node_cleanup)
content = smart_replace(content, old_npm, new_npm)
content = smart_replace(content, old_tmux, new_tmux)
content = smart_replace(content, old_fd, new_fd)
content = smart_replace(content, old_rg, new_rg)
content = smart_replace(content, old_final, new_final)

with open(path, 'wb') as f:
    f.write(content.encode('utf-8'))
