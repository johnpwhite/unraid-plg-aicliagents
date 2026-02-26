#!/bin/bash
# Gemini CLI Restricted Shell Wrapper
SESSION="gemini-cli"
LOG="/tmp/gemini-shell.log"

# Load config from environment or use defaults
HOME_DIR="${GEMINI_HOME:-/boot/config/plugins/unraid-geminicli/home}"
TARGET_USER="${GEMINI_USER:-root}"
ROOT_DIR="${GEMINI_ROOT:-/mnt}"
HISTORY_LIMIT="${GEMINI_HISTORY:-4096}"

export HOME="$HOME_DIR"
mkdir -p "$HOME"
cd "$ROOT_DIR" || cd /mnt || exit 1
export PATH=$PATH:/usr/local/bin:/boot/config/plugins/unraid-geminicli/bin

echo "$(date) - Attaching as $TARGET_USER to session $SESSION (History: $HISTORY_LIMIT, Root: $ROOT_DIR)" >> "$LOG"

# 1. Fallback if no tmux
if ! command -v tmux >/dev/null 2>&1; then
    echo "$(date) - ERROR: tmux not found in PATH ($PATH)" >> "$LOG"
    echo "------------------------------------------------"
    echo " WARNING: tmux not found. Session sync disabled."
    echo " Please install tmux or ensure it is in your PATH."
    echo "------------------------------------------------"
    exec /bin/bash --restricted
fi

# 2. Ensure session exists
if ! tmux has-session -t "$SESSION" 2>/dev/null; then
    echo "Creating new session $SESSION" >> "$LOG"
    # Create session with a custom buffer size from config
    tmux new-session -d -s "$SESSION" -x 200 -y 80 "sh -c 'export HOME=\"$HOME\"; export PATH=\"$PATH\"; exec /bin/bash --restricted'"
    # Apply history limit
    tmux set-option -t "$SESSION" history-limit "$HISTORY_LIMIT" 2>/dev/null
fi

# 3. Aggressive resize and attach
# Ensure the session has the correct global settings
tmux set-option -g -t "$SESSION" window-size largest 2>/dev/null
# Attach without -d to allow multiple clients to share the view (standard Unraid terminal behavior)
exec tmux attach-session -t "$SESSION"
