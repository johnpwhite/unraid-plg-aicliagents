#!/bin/bash
# Gemini CLI Persistent Shell Wrapper
SESSION="gemini-cli"
LOG="/tmp/gemini-shell.log"

export HOME="/boot/config/plugins/unraid-geminicli/home"
mkdir -p "$HOME"
cd /mnt || exit 1
export PATH=$PATH:/usr/local/bin:/boot/config/plugins/unraid-geminicli/bin

echo "$(date) - Attaching to session $SESSION" >> "$LOG"

# 1. Check if tmux exists
if ! command -v tmux >/dev/null 2>&1; then
    exec /bin/bash --restricted
fi

# 2. Global tmux settings for better resizing
# window-size largest: ensure we don't get stuck in a tiny view
tmux set-option -g window-size largest 2>/dev/null

# 3. Create or Attach
if ! tmux has-session -t "$SESSION" 2>/dev/null; then
    # Create new session
    # We use sh -c to ensure environment variables are set inside the session
    tmux new-session -d -s "$SESSION" "sh -c 'export HOME=$HOME; export PATH=$PATH; exec /bin/bash --restricted'"
fi

# Attach and detach others (-d) to force local window resizing
# -A: attach to existing session
# -D: detach other clients
exec tmux attach-session -t "$SESSION" -d
