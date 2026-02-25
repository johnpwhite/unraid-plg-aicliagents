#!/bin/bash
export HOME=/mnt
cd /mnt
export PATH=$PATH:/usr/local/bin

# Use tmux to provide persistence
# Session name: gemini-cli
SESSION="gemini-cli"

# Check if session exists
tmux has-session -t "$SESSION" 2>/dev/null

if [ $? != 0 ]; then
    # Create session and launch restricted bash
    # -d: start detached
    # -s: session name
    tmux new-session -d -s "$SESSION" '/bin/bash --restricted'
fi

# Attach to the session
# -u: force UTF-8
exec tmux attach-session -t "$SESSION"
