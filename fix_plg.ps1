$path = "c:\Users\john\unraid-extensions\unraid-plg-aicliagents\unraid-aicliagents.plg"
$content = [System.IO.File]::ReadAllText($path)

# 1. Node check
$oldNode = '# 2. Ensure Node.js runtime (v22.22.0)'
$newNode = @"
# 2. Ensure Node.js runtime (v22+ required)
NODE_TAR="node-v22.22.0-linux-x64.tar.gz"
NODE_URL="https://nodejs.org/dist/v22.22.0/node-v22.22.0-linux-x64.tar.gz"

USE_SYSTEM_NODE=0
if command -v node >/dev/null 2>&1; then
    NODE_VER=$(node -v | sed ''s/v//'' | cut -d. -f1)
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
"@

# Note: The search string must match the file's NEWLINES too. 
# ReadAllText keeps CRLFs.

# I'll use a more surgical replace for the whole block to avoid newline issues.
$targetBlock = @"
# 2. Ensure Node.js runtime (v22.22.0)
NODE_TAR="node-v22.22.0-linux-x64.tar.gz"
NODE_URL="https://nodejs.org/dist/v22.22.0/node-v22.22.0-linux-x64.tar.gz"

if [ ! -f "`$CONFIG_DIR/`$NODE_TAR" ]; then
    status "Ensuring Node.js runtime..."
    wget -q -O "`$CONFIG_DIR/`$NODE_TAR" "`$NODE_URL"
fi


status "Installing Node.js..."
"@

# We need to escape $ in the target block for PowerShell strings
$targetBlock = $targetBlock.Replace("`$CONFIG_DIR", "`$CONFIG_DIR").Replace("`$NODE_TAR", "`$NODE_TAR").Replace("`$NODE_URL", "`$NODE_URL")

# Actually, I'll just use a Python script. It's much easier to handle raw strings and CRLF in Python.
