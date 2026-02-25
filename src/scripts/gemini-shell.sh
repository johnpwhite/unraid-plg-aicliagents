#!/bin/bash
# Gemini CLI Restricted Shell Wrapper
LOG="/tmp/gemini-shell.log"
SESSION="gemini-cli"

echo "$(date) - Connection attempt" >> "$LOG"

export HOME=/mnt
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
# -A: attach to session, or create if not exists
# -D: detach other clients (optional, but good for single user view)
# We use a simple logic to ensure we don't loop
if tmux has-session -t "$SESSION" 2>/dev/null; then
    echo "Attaching to existing session" >> "$LOG"
    exec tmux attach-session -t "$SESSION"
else
    echo "Creating new session" >> "$LOG"
    exec tmux new-session -s "$SESSION" '/bin/bash --restricted'
fi
