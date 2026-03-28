import os

path = r"c:\Users\john\unraid-extensions\unraid-plg-aicliagents\unraid-aicliagents.plg"
with open(path, 'rb') as f:
    content = f.read().decode('utf-8')

# 1. Fix tmux
old_tmux = """    gunzip -c "$CONFIG_DIR/$TMUX_TAR" &gt; "$EMHTTP_DEST/bin/tmux"
chmod +x "$EMHTTP_DEST/bin/tmux\""""

new_tmux = """    gunzip -c "$CONFIG_DIR/$TMUX_TAR" &gt; "$EMHTTP_DEST/bin/tmux"
    chmod +x "$EMHTTP_DEST/bin/tmux"
fi"""

# 2. Fix fd
old_fd = """    mv "$FD_BIN" "$EMHTTP_DEST/bin/fd"
chmod +x "$EMHTTP_DEST/bin/fd"
rm -rf "$TMP_EXTRACT_FD\""""

new_fd = """    mv "$FD_BIN" "$EMHTTP_DEST/bin/fd"
    chmod +x "$EMHTTP_DEST/bin/fd"
    rm -rf "$TMP_EXTRACT_FD"
fi"""

# 3. Fix rg
old_rg = """    mv "$RG_BIN" "$EMHTTP_DEST/bin/rg"
chmod +x "$EMHTTP_DEST/bin/rg"
rm -rf "$TMP_EXTRACT_RG\""""

new_rg = """    mv "$RG_BIN" "$EMHTTP_DEST/bin/rg"
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
    
    print(f"Warning: Could not find block starting with: {old.splitlines()[0]}")
    return text

content = smart_replace(content, old_tmux, new_tmux)
content = smart_replace(content, old_fd, new_fd)
content = smart_replace(content, old_rg, new_rg)

with open(path, 'wb') as f:
    f.write(content.encode('utf-8'))
