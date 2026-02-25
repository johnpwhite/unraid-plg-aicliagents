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

# 2. Aggressive session setup
# Use -A to attach if exists, or create if missing
# Use -D to detach others and force resize
# We wrap in sh -c to ensure the environment is rock solid
exec tmux new-session -A -D -s "$SESSION" "sh -c 'export HOME=\"$HOME\"; export PATH=\"$PATH\"; exec /bin/bash --restricted'"
