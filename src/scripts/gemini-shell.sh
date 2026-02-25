#!/bin/bash
# Gemini CLI Restricted Shell Wrapper
LOG="/tmp/gemini-shell.log"
echo "$(date) - Shell attempt" >> "$LOG"

export HOME=/mnt
cd /mnt || { echo "Failed to cd to /mnt" >> "$LOG"; exit 1; }

# Ensure Node and Gemini are in PATH
export PATH=$PATH:/usr/local/bin:/boot/config/plugins/unraid-geminicli/bin

# Session name for tmux persistence
SESSION="gemini-cli"

if command -v tmux >/dev/null 2>&1; then
    echo "Using tmux" >> "$LOG"
    tmux has-session -t "$SESSION" 2>/dev/null
    if [ $? != 0 ]; then
        tmux new-session -d -s "$SESSION" '/bin/bash --restricted'
    fi
    exec tmux attach-session -t "$SESSION"
else
    echo "No tmux, direct bash" >> "$LOG"
    exec /bin/bash --restricted
fi
