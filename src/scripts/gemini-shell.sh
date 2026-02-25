#!/bin/bash
# Gemini CLI Restricted Shell Wrapper
SESSION="gemini-cli"
LOG="/tmp/gemini-shell.log"

export HOME="/boot/config/plugins/unraid-geminicli/home"
mkdir -p "$HOME"
cd /mnt || exit 1
export PATH=$PATH:/usr/local/bin:/boot/config/plugins/unraid-geminicli/bin

echo "$(date) - Attaching to session $SESSION" >> "$LOG"

# 1. Check for tmux
if ! command -v tmux >/dev/null 2>&1; then
    exec /bin/bash --restricted
fi

# 2. Ensure session exists
if ! tmux has-session -t "$SESSION" 2>/dev/null; then
    # Create session and run restricted bash
    # We use sh -c to ensure environment variables are exported correctly inside the session
    tmux new-session -d -s "$SESSION" "sh -c 'export HOME=\"$HOME\"; export PATH=\"$PATH\"; exec /bin/bash --restricted'"
fi

# 3. Aggressive attachment logic
# -d: Detach other clients (CRITICAL for forcing resize to current client window height)
# -A: Attach if exists
exec tmux attach-session -t "$SESSION" -d
