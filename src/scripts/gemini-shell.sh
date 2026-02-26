#!/bin/bash
# Gemini CLI Restricted Shell Wrapper
SESSION="gemini-cli"
LOG="/tmp/gemini-shell.log"

export HOME="/boot/config/plugins/unraid-geminicli/home"
mkdir -p "$HOME"
cd /mnt || exit 1
export PATH=$PATH:/usr/local/bin:/boot/config/plugins/unraid-geminicli/bin

echo "$(date) - Attaching to session $SESSION" >> "$LOG"

# 1. Fallback if no tmux
if ! command -v tmux >/dev/null 2>&1; then
    echo "$(date) - ERROR: tmux not found in PATH ($PATH)" >> "$LOG"
    echo "------------------------------------------------"
    echo " WARNING: tmux not found. Session sync disabled."
    echo " Please install tmux or ensure it is in your PATH."
    echo "------------------------------------------------"
    exec /bin/bash --restricted
fi

# 2. Ensure session exists
if ! tmux has-session -t "$SESSION" 2>/dev/null; then
    echo "Creating new session $SESSION" >> "$LOG"
    # Create session with a large buffer
    tmux new-session -d -s "$SESSION" -x 200 -y 80 "sh -c 'export HOME=\"$HOME\"; export PATH=\"$PATH\"; exec /bin/bash --restricted'"
fi

# 3. Aggressive resize and attach
# Ensure the session has the correct global settings
tmux set-option -g -t "$SESSION" window-size largest 2>/dev/null
# Attach without -d to allow multiple clients to share the view (standard Unraid terminal behavior)
exec tmux attach-session -t "$SESSION"
