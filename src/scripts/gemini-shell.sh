#!/bin/bash
# Gemini CLI Restricted Shell Wrapper
LOG="/tmp/unraid-geminicli/shell.log"
mkdir -p /tmp/unraid-geminicli
echo "$(date) - Shell started" >> "$LOG"

export HOME=/mnt
cd /mnt || { echo "Failed to cd to /mnt" >> "$LOG"; exit 1; }

# Ensure Node and Gemini are in PATH
export PATH=$PATH:/usr/local/bin:/boot/config/plugins/unraid-geminicli/bin

# Debug environment
echo "PATH: $PATH" >> "$LOG"
echo "USER: $(whoami)" >> "$LOG"

# Session name for tmux persistence
SESSION="gemini-cli"

if command -v tmux >/dev/null 2>&1; then
    echo "Using tmux for session $SESSION" >> "$LOG"
    tmux has-session -t "$SESSION" 2>/dev/null
    if [ $? != 0 ]; then
        tmux new-session -d -s "$SESSION" '/bin/bash --restricted'
        echo "Created new tmux session" >> "$LOG"
    fi
    exec tmux attach-session -t "$SESSION"
else
    echo "tmux not found, falling back to direct restricted bash" >> "$LOG"
    exec /bin/bash --restricted
fi
