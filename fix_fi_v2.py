import re
import os

path = r"c:\Users\john\unraid-extensions\unraid-plg-aicliagents\unraid-aicliagents.plg"
with open(path, 'rb') as f:
    content = f.read().decode('utf-8')

# Fix tmux block
# Search for: gunzip -c ... > ... tmux followed by chmod +x ... tmux NOT followed by fi
tmux_pattern = r'(gunzip -c "\$CONFIG_DIR/\$TMUX_TAR" &gt; "\$EMHTTP_DEST/bin/tmux"\s+chmod \+x "\$EMHTTP_DEST/bin/tmux")(\s+# 4\.)'
content = re.sub(tmux_pattern, r'\1\nfi\2', content)

# Fix fd block
# Search for: rm -rf "$TMP_EXTRACT_FD" NOT followed by fi
fd_pattern = r'(rm -rf "\$TMP_EXTRACT_FD")(\s+# 4b\.)'
content = re.sub(fd_pattern, r'\1\nfi\2', content)

# Fix rg block
# Search for: rm -rf "$TMP_EXTRACT_RG" NOT followed by fi
rg_pattern = r'(rm -rf "\$TMP_EXTRACT_RG")(\s+# 5\.)'
content = re.sub(rg_pattern, r'\1\nfi\2', content)

with open(path, 'wb') as f:
    f.write(content.encode('utf-8'))
