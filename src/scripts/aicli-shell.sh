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
EMHTTP_DEST="/usr/local/emhttp/plugins/unraid-aicliagents"


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
    RUN_SCRIPT="$USER_WORK_DIR/aicli-run-$ID.sh"

    # 1. Minimal Header (Safe injections only)
    echo "#!/bin/bash" > "$RUN_SCRIPT"
    printf 'export AGENT_ID=%q\n' "$AGENT_ID" >> "$RUN_SCRIPT"
    printf 'export HOME=%q\n' "$HOME_DIR" >> "$RUN_SCRIPT"
    printf 'export PATH=%q\n' "$PATH" >> "$RUN_SCRIPT"
    printf 'export frozen_binary=%q\n' "$frozen_binary" >> "$RUN_SCRIPT"
    printf 'export frozen_resume_cmd=%q\n' "$frozen_resume_cmd" >> "$RUN_SCRIPT"
    printf 'export frozen_resume_latest=%q\n' "$frozen_resume_latest" >> "$RUN_SCRIPT"
    printf 'export frozen_chat_id=%q\n' "$frozen_chat_id" >> "$RUN_SCRIPT"

    # 2. Minimalist Logic (No DB repair for this test)
    cat << 'EOF' >> "$RUN_SCRIPT"
export TERM=xterm-256color
export LC_ALL=en_US.UTF-8
stty sane 2>/dev/null

while true; do
    clear

    # D-52: SURGICAL DB REPAIR (Safe Version)
    # We only nuke the WAL/SHM for OpenCode to break the lock loop.
    if [[ "$AGENT_ID" == "opencode" ]]; then
       db_file="$HOME/.local/share/opencode/opencode.db"
       rm -f "$db_file-wal" "$db_file-shm" 2>/dev/null
    fi

    # D-49: Minimalist launch logic (Safe Execution)
    if [ -n "$frozen_chat_id" ] && [ "$frozen_chat_id" != "none" ]; then
        FINAL_CMD="${frozen_resume_cmd//\{chatId\}/$frozen_chat_id}"
        cmd_args=($FINAL_CMD)
        latest_args=($frozen_resume_latest)
        bin_args=($frozen_binary)
        "${cmd_args[@]}" || "${latest_args[@]}" || "${bin_args[@]}"
    else
        latest_args=($frozen_resume_latest)
        bin_args=($frozen_binary)
        "${latest_args[@]}" || "${bin_args[@]}"
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
