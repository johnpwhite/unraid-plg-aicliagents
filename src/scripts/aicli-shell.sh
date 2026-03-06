#!/bin/bash
# AICliAgents CLI Restricted Shell Wrapper
ID="${AICLI_SESSION_ID:-default}"
AGENT_ID="${AGENT_ID:-gemini-cli}"
SESSION="aicli-agent-$AGENT_ID-$ID"
LOG="/tmp/aicli-shell-$ID.log"
DEBUG_LOG="/boot/config/plugins/unraid-aicliagents/debug.log"

# Function to log if debug is enabled
log_debug() {
    # Check config for debug_logging="1"
    if grep -q 'debug_logging="1"' "/boot/config/plugins/unraid-aicliagents/unraid-aicliagents.cfg" 2>/dev/null; then
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] [SHELL-$ID] $1" >> "$DEBUG_LOG"
    fi
}

log_debug "Shell wrapper started for session $ID (Agent: $AGENT_ID)"

# Load config from environment or use defaults
HOME_DIR="${AICLI_HOME:-/boot/config/plugins/unraid-aicliagents/home}"
TARGET_USER="${AICLI_USER:-root}"
ROOT_DIR="${AICLI_ROOT:-/mnt}"
HISTORY_LIMIT="${AICLI_HISTORY:-4096}"

# Capture agent variables NOW to freeze them into the run script
# This prevents tmux environment inheritance issues
frozen_binary="$BINARY"
frozen_resume_cmd="$RESUME_CMD"
frozen_resume_latest="$RESUME_LATEST"
frozen_agent_name="${AGENT_NAME:-$AGENT_ID}"
frozen_chat_id="$AICLI_CHAT_SESSION_ID"
frozen_env_prefix="$ENV_PREFIX"

export HOME="$HOME_DIR"
mkdir -p "$HOME"
cd "$ROOT_DIR" || cd /mnt || exit 1

# Prioritize the plugin's RAM bin folder
export PATH="/usr/local/emhttp/plugins/unraid-aicliagents/bin:$PATH"

# Force UTF-8 and 256 colors for modern terminal apps
export TERM=xterm-256color
export LANG=en_US.UTF-8
export LC_ALL=en_US.UTF-8

# ENSURE CLEANUP: Kill tmux session when ttyd exits
trap "echo '$(date) - EXITING: Cleaning up tmux $SESSION' >> '$LOG'; log_debug 'Cleaning up tmux $SESSION'; tmux kill-session -t '$SESSION' 2>/dev/null" EXIT

log_debug "Attaching to session $SESSION (Root: $ROOT_DIR)"

# 1. Fallback if no tmux
if ! command -v tmux >/dev/null 2>&1; then
    log_debug "ERROR: tmux not found in PATH"
    echo "$(date) - ERROR: tmux not found in PATH ($PATH)" >> "$LOG"
    while true; do
        eval "$frozen_binary"
        echo "Agent exited. Press ENTER to reload..."
        read -r
    done
fi

# 2. Ensure session exists
if ! tmux has-session -t "$SESSION" 2>/dev/null; then
    echo "Creating new session $SESSION" >> "$LOG"
    
    # Create a dedicated run script to ensure perfect TTY inheritance
    RUN_SCRIPT="/tmp/aicli-run-$ID.sh"
    
    # We use a heredoc WITHOUT quotes to inject the frozen variables, 
    # but we must escape $ characters we want to keep literal.
    cat << EOF > "$RUN_SCRIPT"
#!/bin/bash
export HOME="$HOME_DIR"
export PATH="\$PATH"
export TERM=xterm-256color
export PI_OFFLINE=1

# 1. Export keys from vault
VAULT="/boot/config/plugins/unraid-aicliagents/secrets.cfg"
if [ -f "\$VAULT" ]; then
    # Extract value between single quotes precisely
    KEY_VAL=\$(sed -n "s/^${frozen_env_prefix}_API_KEY='\(.*\)'\$/\1/p" "\$VAULT")
    if [ -n "\$KEY_VAL" ]; then
        export "${frozen_env_prefix}_API_KEY"="\$KEY_VAL"
    fi
fi

# 2. Execution Loop
while true; do
    clear

    # 2a. Validate binary existence before starting
    # Handle both direct paths and 'node path/to/file' patterns
    bin_path=\$(echo "$frozen_binary" | awk '{print \$NF}')
    if [[ "$frozen_binary" == *"node "* ]] && [ ! -f "\$bin_path" ]; then
        echo -e "\033[1;31mERROR: Agent binary not found at \$bin_path\033[0m"
        echo -e "Please ensure the agent is installed in Settings."
        read -t 10 -r
        exit 1
    fi

    if [ -n "$frozen_chat_id" ] && [ "$frozen_chat_id" != "none" ]; then
        echo -e "\033[1;36mResuming $frozen_agent_name session: $frozen_chat_id...\033[0m\n"
        # Use manifest-driven resume command
        FINAL_CMD="${frozen_resume_cmd//\{chatId\}/$frozen_chat_id}"
        if ! eval "$FINAL_CMD"; then
             echo -e "\n\033[1;33mSession resume failed, attempting to find latest session...\033[0m\n"
             sleep 1
             if ! eval "$frozen_resume_latest"; then
                 echo -e "\n\033[1;33mNo previous sessions found, starting fresh instance...\033[0m\n"
                 sleep 1
                 eval "$frozen_binary"
             fi
        fi
    else
        # Try to resume latest, or fallback to fresh start
        if [ -n "$frozen_resume_latest" ] && [ "$frozen_resume_latest" != "none" ]; then
            echo -e "\033[1;36mResuming latest $frozen_agent_name session...\033[0m\n"
            if ! eval "$frozen_resume_latest"; then
                echo -e "\n\033[1;33mNo previous session found, starting fresh instance...\033[0m\n"
                sleep 1
                eval "$frozen_binary"
            fi
        else
            echo -e "\033[1;36mStarting fresh $frozen_agent_name instance...\033[0m\n"
            eval "$frozen_binary"
        fi
    fi

    
    echo -e "\n\033[1;33m[Agent Exited]\033[0m Press ENTER to reload, or wait 3 seconds..."
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

# 4. Aggressive resize and attach
tmux set-option -g -t "$SESSION" window-size largest 2>/dev/null
exec tmux -u attach-session -t "$SESSION"
