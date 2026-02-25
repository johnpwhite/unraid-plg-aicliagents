#!/bin/bash
# Gemini CLI Restricted Shell Wrapper
LOG="/tmp/gemini-shell.log"
SESSION="gemini-cli"

echo "$(date) - Connection attempt" >> "$LOG"

# PERSISTENCE FIX: Map HOME to the flash drive config folder
# This ensures ~/.gemini/ (settings, oauth, etc) persists across reboots
export HOME="/boot/config/plugins/unraid-geminicli/home"
mkdir -p "$HOME"

cd /mnt || exit 1
export PATH=$PATH:/usr/local/bin:/boot/config/plugins/unraid-geminicli/bin

# Function to start restricted bash
run_shell() {
    exec /bin/bash --restricted
}

# Check for tmux
if ! command -v tmux >/dev/null 2>&1; then
    echo "No tmux found, running direct shell" >> "$LOG"
    run_shell
fi

# Try to attach, or create if missing
if tmux has-session -t "$SESSION" 2>/dev/null; then
    echo "Attaching to existing session" >> "$LOG"
    exec tmux attach-session -t "$SESSION"
else
    echo "Creating new session" >> "$LOG"
    # Ensure HOME is preserved inside tmux
    exec tmux new-session -s "$SESSION" "export HOME=$HOME; /bin/bash --restricted"
fi
