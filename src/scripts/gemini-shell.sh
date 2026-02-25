#!/bin/bash
# Gemini CLI Restricted Shell Wrapper with robust TMUX persistence
LOG="/tmp/gemini-shell.log"
echo "$(date) - Shell session requested" >> "$LOG"

export HOME=/mnt
cd /mnt || { echo "Failed to cd to /mnt" >> "$LOG"; exit 1; }

# Ensure Node and Gemini are in PATH
export PATH=$PATH:/usr/local/bin:/boot/config/plugins/unraid-geminicli/bin

SESSION="gemini-cli"

# Check if tmux is available
if ! command -v tmux >/dev/null 2>&1; then
    echo "tmux not found, falling back to direct bash" >> "$LOG"
    exec /bin/bash --restricted
fi

# Ensure session exists
tmux has-session -t "$SESSION" 2>/dev/null

if [ $? != 0 ]; then
    echo "Creating new tmux session: $SESSION" >> "$LOG"
    # Create session and run restricted bash
    tmux new-session -d -s "$SESSION" '/bin/bash --restricted'
fi

# Attach to the session
# -A: attach to existing if it exists
# -D: detach any other clients (ensures single active view)
echo "Attaching to tmux session: $SESSION" >> "$LOG"
exec tmux attach-session -t "$SESSION"
