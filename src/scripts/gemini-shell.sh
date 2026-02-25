#!/bin/bash
# Gemini CLI Restricted Shell Wrapper
LOG="/tmp/gemini-shell.log"
SESSION="gemini-cli"

# Persistence Setup
export HOME="/boot/config/plugins/unraid-geminicli/home"
mkdir -p "$HOME"
cd /mnt || exit 1
export PATH=$PATH:/usr/local/bin:/boot/config/plugins/unraid-geminicli/bin

echo "$(date) - Connection attempt for session $SESSION" >> "$LOG"

# Check for tmux on the server
if ! command -v tmux >/dev/null 2>&1; then
    echo "tmux not found on server, falling back to direct bash" >> "$LOG"
    exec /bin/bash --restricted
fi

# Ensure session exists
if ! tmux has-session -t "$SESSION" 2>/dev/null; then
    echo "Creating new tmux session: $SESSION" >> "$LOG"
    # Create session and run restricted bash
    # Use -d to start detached
    tmux new-session -d -s "$SESSION" '/bin/bash --restricted'
fi

# Attach to the session
# -A: attach to existing
# -D: detach other clients (CRITICAL: This forces the session to resize to the NEW window height)
echo "Attaching to session: $SESSION" >> "$LOG"
exec tmux attach-session -t "$SESSION" -d
