#!/bin/bash
# AICliAgents CLI Restricted Shell Wrapper
# v2026.03.17.13 - Pure Terminal Wrapper (No Heartbeats)
ID="${AICLI_SESSION_ID:-default}"
AGENT_ID="${AGENT_ID:-gemini-cli}"
SESSION="aicli-agent-$AGENT_ID-$ID"
TMP_DIR="/tmp/unraid-aicliagents"

# Ensure log directory exists
# Ensure base directories exist with global write access to handle root/non-root transitions
[ ! -d "$TMP_DIR" ] && mkdir -p "$TMP_DIR" && chmod 0777 "$TMP_DIR"
[ ! -d "$TMP_DIR/work" ] && mkdir -p "$TMP_DIR/work" && chmod 0777 "$TMP_DIR/work"

USER_NAME=$(whoami)
USER_WORK_DIR="$TMP_DIR/work/$USER_NAME"
DEBUG_LOG="/tmp/unraid-aicliagents/debug.log"
EMHTTP_DEST="/usr/local/emhttp/plugins/unraid-aicliagents"

# Unified terminal output logging
log_aicli() {
    local level_name="$1"
    local level_val="$2"
    local msg="$3"
    # Unified PHP logging call
    php -r "require_once '/usr/local/emhttp/plugins/unraid-aicliagents/includes/AICliAgentsManager.php'; aicli_log('[SHELL-$USER_NAME-$ID] $msg', $level_val);" 2>/dev/null
}

log_aicli "DEBUG" 3 "Shell wrapper started for session $ID (Agent: $AGENT_ID). Running as $USER_NAME."

# Environment Setup
HOME_DIR="${AICLI_HOME:-$USER_WORK_DIR/home}"
TARGET_USER="${AICLI_USER:-$USER_NAME}"
ROOT_DIR="${AICLI_ROOT:-/mnt}"
HISTORY_LIMIT="${AICLI_HISTORY:-4096}"

log_aicli "DEBUG" 3 "Env Setup: HOME=$HOME_DIR USER=$TARGET_USER ROOT=$ROOT_DIR"

# Freeze variables for tmux
frozen_binary="$BINARY"
frozen_resume_cmd="$RESUME_CMD"
frozen_resume_latest="$RESUME_LATEST"
frozen_agent_name="${AGENT_NAME:-$AGENT_ID}"
frozen_chat_id="$AICLI_CHAT_SESSION_ID"
frozen_env_prefix="$ENV_PREFIX"

# D-154: Lazy-Mount Recovery Pre-flight
# Ensure ~/home is valid and writable for this user context before starting terminal. 
# Only attempt PHP-based repairs IF we are still root; otherwise, just warn and proceed
# as we can no longer perform administrative mounts.
if ! mountpoint -q "$HOME_DIR" || [ ! -w "$HOME_DIR" ]; then
    if [ "$USER_NAME" = "root" ]; then
        log_aicli "WARN" 1 "Mount invalid or read-only. Triggering self-healing for $USER_NAME..."
        php -r "require_once '/usr/local/emhttp/plugins/unraid-aicliagents/includes/AICliAgentsManager.php'; aicli_init_working_dir('$USER_NAME', true);" > /dev/null 2>&1
    else
        log_aicli "WARN" 1 "Mount invalid for $USER_NAME. Cannot repair from non-root context. Proceeding with caution."
    fi
fi

export HOME="$HOME_DIR"
mkdir -p "$HOME" 2>/dev/null
cd "$ROOT_DIR" || cd /mnt || echo "Warning: Could not enter $ROOT_DIR"
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
if ! command -v tmux >/dev/null 2>&1; then
    echo -e "\n\n\033[1;31m[FATAL ERROR]\033[0m Required dependency 'tmux' is missing or not executable."
    echo -e "\033[0;33mThis usually happens if the Agent Storage image failed to mount,\033[0m"
    echo -e "\033[0;33mor if the plugin needs to be repaired.\033[0m\n"
    echo -e "Please check the settings page and click 'Repair Plugin'.\n"
    read -t 8 -p "Press ENTER to exit..."
    exit 1
fi

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

    # D-59: Prevent Auto-Updates on Launch
    # We want the user to manage updates through the plugin UI, not randomly on terminal load.
    printf 'export DISABLE_AUTOUPDATER=1\n' >> "$RUN_SCRIPT"
    printf 'export GEMINI_CLI_DISABLE_AUTO_UPDATE=true\n' >> "$RUN_SCRIPT"
    printf 'export OPENCODE_DISABLE_AUTOUPDATE=true\n' >> "$RUN_SCRIPT"
    printf 'export NANOCODER_DISABLE_AUTO_UPDATE=1\n' >> "$RUN_SCRIPT"
    printf 'export KILO_DISABLE_AUTO_UPDATE=1\n' >> "$RUN_SCRIPT"
    printf 'export PI_CODER_DISABLE_AUTO_UPDATE=1\n' >> "$RUN_SCRIPT"
    printf 'export DISABLE_UPDATE_CHECK=1\n' >> "$RUN_SCRIPT"

    # 2. Minimalist Logic (Atomic Environment Injection)
    # D-159: Variables are injected PRIOR to the here-doc to prevent environmental leakage
    printf 'export USER_NAME=%q\n' "$USER_NAME" >> "$RUN_SCRIPT"
    printf 'export HOME_DIR=%q\n' "$HOME_DIR" >> "$RUN_SCRIPT"

    cat << 'EOF' >> "$RUN_SCRIPT"
export TERM=xterm-256color
export LC_ALL=en_US.UTF-8
stty sane 2>/dev/null

while true; do
    clear

    # D-52: SURGICAL DB REPAIR (Safe Version)
    if [[ "$AGENT_ID" == "opencode" ]]; then
       db_file="$HOME_DIR/.local/share/opencode/opencode.db"
       rm -f "$db_file-wal" "$db_file-shm" 2>/dev/null
    fi

    # D-159: Metadata Alignment - Ensure the .gemini directory is accessible
    if [ -d "$HOME_DIR/.gemini" ]; then
        # This part runs within the user script (aicliagent), so we just ensure permissions
        chmod -R 775 "$HOME_DIR/.gemini" > /dev/null 2>&1
    fi

    # D-49: Minimalist launch logic (Safe Execution with Fallback)
    if [ "$AGENT_ID" == "terminal" ]; then
        /bin/bash
    elif [ -n "$frozen_chat_id" ] && [ "$frozen_chat_id" != "none" ]; then
        FINAL_CMD="${frozen_resume_cmd//\{chatId\}/$frozen_chat_id}"
        cmd_args=($FINAL_CMD)
        latest_args=($frozen_resume_latest)
        bin_args=($frozen_binary)
        # Attempt Resume -> Attempt Latest -> Fallback to Fresh
        "${cmd_args[@]}" || "${latest_args[@]}" || "${bin_args[@]}"
    else
        latest_args=($frozen_resume_latest)
        bin_args=($frozen_binary)
        # Attempt Latest -> Fallback to Fresh
        "${latest_args[@]}" || "${bin_args[@]}"
    fi
    if [ "$AGENT_ID" == "terminal" ]; then exit 0; fi
    echo -e "\n\033[1;33m[Agent Exited]\033[0m Press ENTER to reload..."
    read -t 3 -r
done
EOF




    chmod +x "$RUN_SCRIPT"
    log_aicli "DEBUG" 3 "Launching tmux session $SESSION for script $RUN_SCRIPT"
    tmux -u new-session -d -s "$SESSION" "$RUN_SCRIPT" 2>>"$DEBUG_LOG"
fi

log_aicli "DEBUG" 3 "Attaching to tmux session $SESSION..."

tmux set-option -g history-limit "$HISTORY_LIMIT" 2>/dev/null
tmux set-option -g status off 2>/dev/null
tmux set-option -g set-clipboard on 2>/dev/null
tmux set-option -g allow-passthrough on 2>/dev/null
tmux set-option -ag terminal-overrides ",xterm-256color:Ms=\\E]52;c;%p2%s\\7" 2>/dev/null
exec tmux -u attach-session -t "$SESSION"
