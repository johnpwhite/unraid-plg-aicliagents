#!/bin/bash
# AICliAgents CLI Restricted Shell Wrapper
# v2026.03.17.13 - Pure Terminal Wrapper (No Heartbeats)
ID="${AICLI_SESSION_ID:-default}"
AGENT_ID="${AGENT_ID:-gemini-cli}"
SESSION="aicli-agent-$AGENT_ID-$ID"
TMP_DIR="/tmp/unraid-aicliagents"

# Ensure log directory exists
[ ! -d "$TMP_DIR" ] && mkdir -p "$TMP_DIR" && chmod 0777 "$TMP_DIR"

USER_NAME=$(whoami)
USER_WORK_DIR="$TMP_DIR/work/$USER_NAME"
[ ! -d "$USER_WORK_DIR" ] && mkdir -p "$USER_WORK_DIR"

DEBUG_LOG="/tmp/unraid-aicliagents/debug.log"

log_aicli() {
    local level_name="$1"
    local level_val="$2"
    local msg="$3"
    # Unified PHP logging call
    php -r "require_once '/usr/local/emhttp/plugins/unraid-aicliagents/includes/AICliAgentsManager.php'; aicli_log('[SHELL-$USER_NAME-$ID] $msg', $level_val);" 2>/dev/null
}

log_aicli "DEBUG" 3 "Shell wrapper started for session $ID (Agent: $AGENT_ID)"

# Environment Setup
HOME_DIR="${AICLI_HOME:-$USER_WORK_DIR/home}"
TARGET_USER="${AICLI_USER:-$USER_NAME}"
ROOT_DIR="${AICLI_ROOT:-/mnt}"
HISTORY_LIMIT="${AICLI_HISTORY:-4096}"

# Freeze variables for tmux
frozen_binary="$BINARY"
frozen_resume_cmd="$RESUME_CMD"
frozen_resume_latest="$RESUME_LATEST"
frozen_agent_name="${AGENT_NAME:-$AGENT_ID}"
frozen_chat_id="$AICLI_CHAT_SESSION_ID"
frozen_env_prefix="$ENV_PREFIX"

export HOME="$HOME_DIR"
mkdir -p "$HOME" 2>/dev/null
cd "$ROOT_DIR" || cd /mnt || exit 1
export PATH="/usr/local/emhttp/plugins/unraid-aicliagents/bin:$PATH"
export TERM=xterm-256color
export COLORTERM=truecolor
export LANG=en_US.UTF-8
export LC_ALL=en_US.UTF-8

# Cleanup on exit
cleanup() {
    log_aicli "DEBUG" 3 "Cleaning up tmux session $SESSION"
    tmux kill-session -t "$SESSION" 2>/dev/null
}

# Trap exit to ensure sync happens on last session close (Managed by PHP counting)
trap_exit() {
    log_aicli "DEBUG" 3 "Terminal session $ID closing."
    # We call PHP to handle the reference decrement and potential final sync
    php -r "require_once '/usr/local/emhttp/plugins/unraid-aicliagents/includes/AICliAgentsManager.php'; stopAICliTerminal('$ID', true);" 2>/dev/null
    cleanup
}
trap trap_exit EXIT

# TMUX EXECUTION
if ! tmux has-session -t "$SESSION" 2>/dev/null; then
    # Capture ALL exported variables from the current environment
    # We filter out internal bash vars and common system vars that change per session
    ENV_EXPORTS=$(export -p | grep -vE ' (BASH_.*|SHELLOPTS|PWD|SHLVL|_)=' | grep -v "declare -fr")

    RUN_SCRIPT="$USER_WORK_DIR/aicli-run-$ID.sh"
    cat << EOF > "$RUN_SCRIPT"
#!/bin/bash
# Propagate environment from parent shell
$ENV_EXPORTS

export HOME="$HOME_DIR"
export PATH="$PATH"
export TERM=xterm-256color
export COLORTERM=truecolor
stty sane 2>/dev/null
export PI_OFFLINE=1

while true; do
    clear
    bin_path=\$(echo "$frozen_binary" | awk '{print \$NF}')
    if [[ "$frozen_binary" == *"node "* ]] && [ ! -f "\$bin_path" ]; then
        echo -e "\033[1;31mERROR: Agent binary not found at \$bin_path\033[0m"
        read -t 10 -r
        exit 1
    fi

    if [ -n "$frozen_chat_id" ] && [ "$frozen_chat_id" != "none" ]; then
        FINAL_CMD="${frozen_resume_cmd//\{chatId\}/$frozen_chat_id}"
        eval "\$FINAL_CMD" || eval "$frozen_resume_latest" || eval "$frozen_binary"
    else
        eval "$frozen_resume_latest" || eval "$frozen_binary"
    fi
    echo -e "\n\033[1;33m[Agent Exited]\033[0m Press ENTER to reload..."
    read -t 3 -r
done
EOF
    chmod +x "$RUN_SCRIPT"
    tmux -u new-session -d -s "$SESSION" -x 200 -y 80 "$RUN_SCRIPT"
fi

tmux set-option -g history-limit "$HISTORY_LIMIT" 2>/dev/null
tmux set-option -g status off 2>/dev/null
tmux set-option -g set-clipboard on 2>/dev/null
tmux set-option -g allow-passthrough on 2>/dev/null
tmux set-option -ag terminal-overrides ",xterm-256color:Ms=\\E]52;c;%p2%s\\7" 2>/dev/null
exec tmux -u attach-session -t "$SESSION"
