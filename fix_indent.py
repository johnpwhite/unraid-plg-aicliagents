import re
import os

path = r"c:\Users\john\unraid-extensions\unraid-plg-aicliagents\unraid-aicliagents.plg"
with open(path, 'rb') as f:
    content = f.read().decode('utf-8')

# Fix fd indentation in else block
fd_block = r"""else
    TMP_EXTRACT_FD="/tmp/fd-extract-\$\$"
    mkdir -p "\$TMP_EXTRACT_FD"
tar -xf "\$CONFIG_DIR/\$FD_TAR" -C "\$TMP_EXTRACT_FD" --no-same-owner
FD_BIN=\$\(find "\$TMP_EXTRACT_FD" -name fd -type f -executable \| head -n 1\)
mv "\$FD_BIN" "\$EMHTTP_DEST/bin/fd"
chmod \+x "\$EMHTTP_DEST/bin/fd"
rm -rf "\$TMP_EXTRACT_FD\"
fi"""

# I'll just use simple regex search/replace with groups
pattern_fd = r'(else\s+TMP_EXTRACT_FD="/tmp/fd-extract-\$\$"\s+mkdir -p "\$TMP_EXTRACT_FD"\s+)(tar -xf.*?\n)(FD_BIN=.*?\n)(mv.*?\n)(chmod.*?\n)(rm -rf.*?\n)(fi)'
def fix_fd(m):
    return f"{m.group(1)}    {m.group(2)}    {m.group(3)}    {m.group(4)}    {m.group(5)}    {m.group(6)}{m.group(7)}"

# Actually, it's safer to just do a multi-line literal replace.
old_fd = """else
    TMP_EXTRACT_FD="/tmp/fd-extract-$$"
    mkdir -p "$TMP_EXTRACT_FD"
tar -xf "$CONFIG_DIR/$FD_TAR" -C "$TMP_EXTRACT_FD" --no-same-owner
FD_BIN=$(find "$TMP_EXTRACT_FD" -name fd -type f -executable | head -n 1)
mv "$FD_BIN" "$EMHTTP_DEST/bin/fd"
chmod +x "$EMHTTP_DEST/bin/fd"
rm -rf "$TMP_EXTRACT_FD"
fi"""

new_fd = """else
    TMP_EXTRACT_FD="/tmp/fd-extract-$$"
    mkdir -p "$TMP_EXTRACT_FD"
    tar -xf "$CONFIG_DIR/$FD_TAR" -C "$TMP_EXTRACT_FD" --no-same-owner
    FD_BIN=$(find "$TMP_EXTRACT_FD" -name fd -type f -executable | head -n 1)
    mv "$FD_BIN" "$EMHTTP_DEST/bin/fd"
    chmod +x "$EMHTTP_DEST/bin/fd"
    rm -rf "$TMP_EXTRACT_FD"
fi"""

old_rg = """else
    TMP_EXTRACT_RG="/tmp/ripgrep-extract-$$"
    mkdir -p "$TMP_EXTRACT_RG"
tar -xf "$CONFIG_DIR/$RG_TAR" -C "$TMP_EXTRACT_RG" --no-same-owner
RG_BIN=$(find "$TMP_EXTRACT_RG" -name rg -type f -executable | head -n 1)
mv "$RG_BIN" "$EMHTTP_DEST/bin/rg"
chmod +x "$EMHTTP_DEST/bin/rg"
rm -rf "$TMP_EXTRACT_RG"
fi"""

new_rg = """else
    TMP_EXTRACT_RG="/tmp/ripgrep-extract-$$"
    mkdir -p "$TMP_EXTRACT_RG"
    tar -xf "$CONFIG_DIR/$RG_TAR" -C "$TMP_EXTRACT_RG" --no-same-owner
    RG_BIN=$(find "$TMP_EXTRACT_RG" -name rg -type f -executable | head -n 1)
    mv "$RG_BIN" "$EMHTTP_DEST/bin/rg"
    chmod +x "$EMHTTP_DEST/bin/rg"
    rm -rf "$TMP_EXTRACT_RG"
fi"""

def smart_replace(text, old, new):
    if old in text:
        return text.replace(old, new)
    old_norm = old.replace('\n', '\r\n')
    new_norm = new.replace('\n', '\r\n')
    if old_norm in text:
         return text.replace(old_norm, new_norm)
    old_lf = old.replace('\r\n', '\n')
    new_lf = new.replace('\r\n', '\n')
    if old_lf in text:
         return text.replace(old_lf, new_lf)
    print(f"Warning: Could not find block.")
    return text

content = smart_replace(content, old_fd, new_fd)
content = smart_replace(content, old_rg, new_rg)

with open(path, 'wb') as f:
    f.write(content.encode('utf-8'))
