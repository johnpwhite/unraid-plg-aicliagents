#!/bin/bash
# Gemini CLI Persistent Shell Wrapper
SESSION="gemini-cli"
LOG="/tmp/gemini-shell.log"

export HOME="/boot/config/plugins/unraid-geminicli/home"
mkdir -p "$HOME"
cd /mnt || exit 1
export PATH=$PATH:/usr/local/bin:/boot/config/plugins/unraid-geminicli/bin

echo "$(date) - Requesting session $SESSION" >> "$LOG"

# Check for tmux
if ! command -v tmux >/dev/null 2>&1; then
    exec /bin/bash --restricted
fi

# Ensure session exists with a LARGE default size
if ! tmux has-session -t "$SESSION" 2>/dev/null; then
    echo "Creating new session with 200x80 size" >> "$LOG"
    # -x 200 -y 80: Forces a large initial buffer to avoid tiny height bug
    tmux new-session -d -s "$SESSION" -x 200 -y 80 "sh -c 'export HOME=\"$HOME\"; export PATH=\"$PATH\"; exec /bin/bash --restricted'"
fi

# Attach and force resize
# -d: detach other clients (CRITICAL for resizing to CURRENT iframe size)
exec tmux attach-session -t "$SESSION" -d
