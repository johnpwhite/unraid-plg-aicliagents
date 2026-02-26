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
    echo "tmux not found" >> "$LOG"
    exec /bin/bash --restricted
fi

# 2. Ensure session exists
if ! tmux has-session -t "$SESSION" 2>/dev/null; then
    echo "Creating new session $SESSION" >> "$LOG"
    # Create session with a large buffer
    tmux new-session -d -s "$SESSION" -x 200 -y 80 "sh -c 'export HOME=\"$HOME\"; export PATH=\"$PATH\"; exec /bin/bash --restricted'"
fi

# 3. Aggressive resize and attach
# window-size largest: prevents shrinking to smallest client
tmux set-option -g window-size largest 2>/dev/null
# Attach and detach others to force resize to CURRENT window
exec tmux attach-session -t "$SESSION" -d
