#!/bin/bash
# Gemini CLI Restricted Shell Wrapper
ID="${GEMINI_SESSION_ID:-default}"
SESSION="gemini-cli-$ID"
LOG="/tmp/gemini-shell-$ID.log"

# Load config from environment or use defaults
HOME_DIR="${GEMINI_HOME:-/boot/config/plugins/unraid-geminicli/home}"
TARGET_USER="${GEMINI_USER:-root}"
ROOT_DIR="${GEMINI_ROOT:-/mnt}"
HISTORY_LIMIT="${GEMINI_HISTORY:-4096}"

export HOME="$HOME_DIR"
mkdir -p "$HOME"
cd "$ROOT_DIR" || cd /mnt || exit 1
export PATH=$PATH:/usr/local/bin:/boot/config/plugins/unraid-geminicli/bin

# Force UTF-8 and 256 colors for modern terminal apps (Gemini, diffs, etc)
export TERM=xterm-256color
export LANG=en_US.UTF-8
export LC_ALL=en_US.UTF-8

# ENSURE CLEANUP: Kill tmux session when ttyd exits
trap "echo '$(date) - EXITING: Cleaning up tmux $SESSION' >> '$LOG'; tmux kill-session -t '$SESSION' 2>/dev/null" EXIT

echo "$(date) - Attaching as $TARGET_USER to session $SESSION (History: $HISTORY_LIMIT, Root: $ROOT_DIR)" >> "$LOG"

# 1. Fallback if no tmux
if ! command -v tmux >/dev/null 2>&1; then
    echo "$(date) - ERROR: tmux not found in PATH ($PATH)" >> "$LOG"
    # Auto-load gemini even in fallback
    while true; do
        gemini
        echo "Gemini exited. Press ENTER to reload..."
        read -r
    done
fi

# 2. Ensure session exists
if ! tmux has-session -t "$SESSION" 2>/dev/null; then
    echo "Creating new session $SESSION" >> "$LOG"
    
    # Create a dedicated run script to ensure perfect TTY inheritance for Node.js
    RUN_SCRIPT="/tmp/gemini-run-$ID.sh"
    cat << 'EOF' > "$RUN_SCRIPT"
#!/bin/bash
export HOME="$GEMINI_HOME"
export PATH="$PATH"
export TERM=xterm-256color
while true; do
    clear
    echo -e "\n\033[1;36mPlease wait, Gemini CLI loading...\033[0m\n"
    if [ -n "$GEMINI_CHAT_SESSION_ID" ]; then
        echo -e "\033[1;32mResuming chat session: $GEMINI_CHAT_SESSION_ID\033[0m\n"
        if ! gemini --resume "$GEMINI_CHAT_SESSION_ID"; then
            echo -e "\n\033[1;31m[Error] Failed to resume session $GEMINI_CHAT_SESSION_ID\033[0m"
            echo -e "\033[1;33mThis usually happens if the session files were deleted.\033[0m"
            echo -e "Starting a fresh session in 3 seconds...\n"
            sleep 3
            gemini
        fi
    else
        gemini
    fi
    echo -e "\n\033[1;33m[Gemini CLI Exited]\033[0m Press ENTER to reload, or wait 3 seconds..."
    read -t 3 -r
done
EOF
    chmod +x "$RUN_SCRIPT"

    # Create session with -u for UTF-8 and set TERM
    tmux -u new-session -d -s "$SESSION" -x 200 -y 80 "$RUN_SCRIPT"
fi

# 3. Apply settings EVERY time (not just on creation) so config changes take effect
tmux set-option -g history-limit "$HISTORY_LIMIT" 2>/dev/null
tmux set-option -g status off 2>/dev/null
echo "$(date) - Applied history-limit=$HISTORY_LIMIT, status=off" >> "$LOG"

# 4. Aggressive resize and attach
# Ensure the session has the correct global settings
tmux set-option -g -t "$SESSION" window-size largest 2>/dev/null
# Attach with -u for UTF-8
exec tmux -u attach-session -t "$SESSION"
