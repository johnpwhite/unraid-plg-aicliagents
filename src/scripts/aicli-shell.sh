#!/bin/bash
# AICliAgents CLI Restricted Shell Wrapper
# v2026.03.17.13 - Pure Terminal Wrapper (No Heartbeats)
ID="${AICLI_SESSION_ID:-default}"
AGENT_ID="${AGENT_ID:-gemini-cli}"
SESSION="aicli-agent-$AGENT_ID-$ID"
TMP_DIR="/tmp/unraid-aicliagents"

# Performance tracing — lightweight timestamp log for optimizing cold-start latency.
# Each perf_log call writes: epoch_ms stage agent session_id → /tmp/unraid-aicliagents/perf.log
# Read the last launch's breakdown:
#   ssh root@<host> "awk -v s=\$(awk '/shell.start/{last=NR} END{print last}' /tmp/unraid-aicliagents/perf.log) \
#     'NR>=s {if(prev) printf \"%-30s +%5d ms\\n\", \$2, \$1-prev; prev=\$1}' /tmp/unraid-aicliagents/perf.log"
PERF_LOG="/tmp/unraid-aicliagents/perf.log"
perf_log() {
    printf '%s %s %s %s\n' "$(date +%s%N | cut -c1-13)" "$1" "$AGENT_ID" "$ID" >> "$PERF_LOG" 2>/dev/null
}
# Rotate if log exceeds 256 KB (cheap, avoids growth across many sessions)
[ -f "$PERF_LOG" ] && [ "$(stat -c %s "$PERF_LOG" 2>/dev/null || echo 0)" -gt 262144 ] && \
    tail -c 131072 "$PERF_LOG" > "$PERF_LOG.tmp" 2>/dev/null && mv "$PERF_LOG.tmp" "$PERF_LOG" 2>/dev/null
perf_log shell.start

# Ensure log directory exists
# Ensure base directories exist with global write access to handle root/non-root transitions
[ ! -d "$TMP_DIR" ] && mkdir -p "$TMP_DIR" && chmod 0755 "$TMP_DIR"
[ ! -d "$TMP_DIR/work" ] && mkdir -p "$TMP_DIR/work" && chmod 0755 "$TMP_DIR/work"

USER_NAME=$(whoami)
USER_WORK_DIR="$TMP_DIR/work/$USER_NAME"
DEBUG_LOG="/tmp/unraid-aicliagents/debug.log"
EMHTTP_DEST="/usr/local/emhttp/plugins/unraid-aicliagents"

# Unified terminal output logging via safe PHP bridge (no string interpolation)
BRIDGE_SCRIPT="/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/log-bridge.php"
log_aicli() {
    local level_name="$1"
    local level_val="$2"
    local msg="$3"

    # D-201: Fallback to file-based logging if the bridge is missing (e.g. during uninstall)
    if [ ! -f "$BRIDGE_SCRIPT" ]; then
        echo "[$(date "+%Y-%m-%d %H:%M:%S")] [SHELL-$USER_NAME-$ID] $msg" >> "$DEBUG_LOG" 2>/dev/null
        return
    fi

    # Safe PHP bridge: arguments via $argv, no string interpolation
    php "$BRIDGE_SCRIPT" log "$msg" "$level_val" "SHELL-$USER_NAME-$ID" 2>/dev/null
}

log_aicli "DEBUG" 3 "Shell wrapper started for session $ID (Agent: $AGENT_ID). Running as $USER_NAME."

# Environment Setup
HOME_DIR="${AICLI_HOME:-$USER_WORK_DIR/home}"
TARGET_USER="${AICLI_USER:-$USER_NAME}"
ROOT_DIR="${AICLI_ROOT:-/mnt}"
HISTORY_LIMIT="${AICLI_HISTORY:-4096}"

# Node.js V8 bytecode cache (Node 22+). All nine agents are Node-based, so enabling
# this shaves roughly 20% off startup by skipping require()-time re-parsing.
# Cache is pinned to a pure-tmpfs path (NOT under HOME_DIR, which is ZRAM+SquashFS
# backed and consolidates to Flash). Losing the cache on reboot is fine — same
# cost as today's cold start. Matches the existing npm_cache pruning policy in
# consolidate_layers.sh: caches never reach Flash.
export NODE_COMPILE_CACHE="/tmp/unraid-aicliagents/cache/node-compile"
mkdir -p "$NODE_COMPILE_CACHE" 2>/dev/null
# Hash identifies per-workspace+agent config files (envs, tmux settings). Used by both the
# new-session init block and the launch-time tmux apply step below.
ENV_HASH=$(echo -n "${ROOT_DIR}${AGENT_ID}" | md5sum | cut -d' ' -f1)

log_aicli "DEBUG" 3 "Env Setup: HOME=$HOME_DIR USER=$TARGET_USER ROOT=$ROOT_DIR"
perf_log env.setup.done

# Freeze variables for tmux
frozen_binary="$BINARY"
# Fix A: freeze the two RAW binary paths (primary + fallback) so the run-loop
# can re-resolve on every relaunch. BINARY is the *already-resolved* effective
# path (primary if it existed at session-open, else fallback). An in-place
# upgrade that drops cli.js and adds bin/claude.exe would leave frozen_binary
# pointing at the deleted file; re-resolving from BINARY_PRIMARY / BINARY_FALLBACK
# picks up the new layout without closing the workspace.
frozen_binary_primary="${BINARY_PRIMARY:-$BINARY}"
frozen_binary_fallback="${BINARY_FALLBACK:-}"
frozen_resume_cmd="$RESUME_CMD"
frozen_resume_latest="$RESUME_LATEST"
frozen_agent_name="${AGENT_NAME:-$AGENT_ID}"
frozen_chat_id="$AICLI_CHAT_SESSION_ID"
frozen_env_prefix="$ENV_PREFIX"

# Load effective CLI args (workspace → agent-level → "").
# Mirrors ArgsService::getEffectiveArgs() without namespaced PHP classes.
# Bug #536: when the JSON file exists but is corrupt (truncated, invalid UTF-8,
# concurrent write torn open before AtomicWriteService landed in v04+), php's
# json_decode silently returns null and frozen_effective_args becomes empty.
# The user has no idea their args file is bad. Tee a WARN to debug.log.
frozen_effective_args=""
_ARGS_WS_HASH=$(echo -n "$ROOT_DIR" | md5sum | cut -d' ' -f1)
_ARGS_WS_FILE="$HOME_DIR/.aicli/args/args_ws_${_ARGS_WS_HASH}_${AGENT_ID}.json"
_ARGS_AGENT_FILE="$HOME_DIR/.aicli/args/args_agent_${AGENT_ID}.json"
_load_args_with_warn() {
    local _path="$1"
    php -r "
        \$raw = @file_get_contents('$_path');
        if (\$raw === false) { fwrite(STDERR, 'unreadable'); exit; }
        \$d = json_decode(\$raw, true);
        if (\$d === null && trim(\$raw) !== '') { fwrite(STDERR, 'parse_error: ' . json_last_error_msg()); exit; }
        if (!is_array(\$d)) { fwrite(STDERR, 'not_array'); exit; }
        echo \$d['args'] ?? '';
    " 2>/tmp/unraid-aicliagents/.args-load-err.$$
    local _err
    _err="$(cat "/tmp/unraid-aicliagents/.args-load-err.$$" 2>/dev/null)"
    rm -f "/tmp/unraid-aicliagents/.args-load-err.$$"
    [ -n "$_err" ] && log_aicli "WARN" 1 "Failed to load args from $_path: $_err — proceeding with empty args"
}
if [ -f "$_ARGS_WS_FILE" ]; then
    frozen_effective_args=$(_load_args_with_warn "$_ARGS_WS_FILE")
elif [ -f "$_ARGS_AGENT_FILE" ]; then
    frozen_effective_args=$(_load_args_with_warn "$_ARGS_AGENT_FILE")
fi

# D-154: Lazy-Mount Recovery Pre-flight
# Ensure ~/home is valid and writable for this user context before starting terminal. 
# Only attempt PHP-based repairs IF we are still root; otherwise, just warn and proceed
# as we can no longer perform administrative mounts.
if ! mountpoint -q "$HOME_DIR" || [ ! -w "$HOME_DIR" ]; then
    if [ "$USER_NAME" = "root" ]; then
        log_aicli "WARN" 1 "Mount invalid or read-only. Triggering self-healing for $USER_NAME..."
        php "$BRIDGE_SCRIPT" init "$USER_NAME" true > /dev/null 2>&1
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
    # Safe PHP bridge: handle reference decrement and potential final sync
    php "$BRIDGE_SCRIPT" stop "$ID" true 2>/dev/null
    cleanup
}
trap trap_exit EXIT

# Bug #1043: point tmux at a plugin-private socket directory instead of the
# shared /tmp/tmux-<uid>. Other processes on the box (a user's own SSH tmux, a
# container bind-mounting /tmp, another plugin) can leave /tmp/tmux-<uid> with
# permissions tmux rejects ("unsafe permissions"), and the terminal then fails
# with "Terminal session not found". /tmp/unraid-aicliagents/tmux is the
# plugin's alone, so tmux's tmux-<uid> dir inside it cannot be corrupted from
# outside. Exported so the tmux server and every child inherit the same socket
# location; every other plugin script that calls tmux sets it identically.
export TMUX_TMPDIR="/tmp/unraid-aicliagents/tmux"
mkdir -p "$TMUX_TMPDIR" 2>/dev/null
chmod 0777 "$TMUX_TMPDIR" 2>/dev/null

# Bug #1054 root cause: tmux uses the user's login SHELL (from /etc/passwd, or
# $SHELL env if set) to run the session's command. System users created with
# `useradd -r` (typical for sandboxed agent accounts like `aicliagent`) have
# /bin/false or /sbin/nologin as their login shell -- tmux then invokes
# RUN_SCRIPT via /bin/false, which exits immediately, the session terminates,
# and the tmux server exits with "no server running" before aicli-shell.sh's
# has-session check fires. The fix: force $SHELL to /bin/bash before any tmux
# call so the session command runs under bash regardless of /etc/passwd shell.
# This is the in-flight fix that works for any sandbox user the admin picks.
export SHELL=/bin/bash

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
    
    # Inject logging function into the child script for tmux diagnostics
    cat << 'FUNCOEF' >> "$RUN_SCRIPT"
log_aicli() {
    local level_name="$1"
    local level_val="$2"
    local msg="$3"
    local bridge="/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/log-bridge.php"
    local debug_log="/tmp/unraid-aicliagents/debug.log"

    if [ ! -f "$bridge" ]; then
        echo "[$(date "+%Y-%m-%d %H:%M:%S")] [SHELL-CHILD] $msg" >> "$debug_log" 2>/dev/null
        return
    fi
    php "$bridge" log "$msg" "$level_val" "SHELL-CHILD" 2>/dev/null
}
perf_log() {
    printf '%s %s %s %s\n' "$(date +%s%N | cut -c1-13)" "$1" "$AGENT_ID" "$AICLI_SESSION_ID" >> /tmp/unraid-aicliagents/perf.log 2>/dev/null
}
FUNCOEF

    printf 'export AICLI_SESSION_ID=%q\n' "$ID" >> "$RUN_SCRIPT"
    printf 'export AGENT_ID=%q\n' "$AGENT_ID" >> "$RUN_SCRIPT"
    printf 'export HOME=%q\n' "$HOME_DIR" >> "$RUN_SCRIPT"

    # Bug #1042: bring up the per-user Secret Service (org.freedesktop.secrets)
    # so keyring-using agents — Antigravity CLI today — can persist their auth
    # token instead of re-prompting every session. Idempotent and reused per
    # user; best-effort — on failure the agent just falls back to interactive
    # auth. See docs/specs/SECRET_SERVICE.md.
    _SS_ADDR=$(bash "$EMHTTP_DEST/src/secret-service/secret-service-up.sh" "$USER_NAME" "$HOME_DIR" 2>>"$DEBUG_LOG")
    if [ -n "$_SS_ADDR" ]; then
        printf 'export DBUS_SESSION_BUS_ADDRESS=%q\n' "$_SS_ADDR" >> "$RUN_SCRIPT"
        log_aicli "DEBUG" 3 "Secret Service ready for $USER_NAME: $_SS_ADDR"
    else
        log_aicli "WARN" 1 "Secret Service unavailable — keyring-using agents may re-prompt for auth"
    fi
    
    # D-170: Ensure agent binaries are in the PATH for the child shell.
    # D-404 (#55): The "terminal" (drop-in shell) workspace has no single frozen
    # binary, so we previously shipped the shell without any agent on PATH. That
    # made `goose --help`, `gemini -p ...`, `qwen configure` etc. fail with
    # "command not found" even though the binaries were installed. For the
    # terminal case we now union every installed agent's bin/ and
    # node_modules/.bin/ into PATH so the user can run any agent ad-hoc.
    if [ -n "$frozen_binary" ]; then
        AGENT_BIN_DIR=$(dirname "$frozen_binary")
        printf 'export PATH=%q\n' "$AGENT_BIN_DIR:$PATH" >> "$RUN_SCRIPT"
    else
        AGENT_PATH_EXTRA=""
        for d in /usr/local/emhttp/plugins/unraid-aicliagents/agents/*/bin \
                 /usr/local/emhttp/plugins/unraid-aicliagents/agents/*/node_modules/.bin; do
            [ -d "$d" ] && AGENT_PATH_EXTRA="$AGENT_PATH_EXTRA:$d"
        done
        printf 'export PATH=%q\n' "${AGENT_PATH_EXTRA:+${AGENT_PATH_EXTRA#:}:}$PATH" >> "$RUN_SCRIPT"
    fi
    
    printf 'export frozen_binary=%q\n' "$frozen_binary" >> "$RUN_SCRIPT"
    # Fix A: export both raw paths so the run-loop can re-resolve frozen_binary
    # on every iteration (survives cli.js → native-binary in-place upgrades).
    printf 'export frozen_binary_primary=%q\n' "$frozen_binary_primary" >> "$RUN_SCRIPT"
    printf 'export frozen_binary_fallback=%q\n' "$frozen_binary_fallback" >> "$RUN_SCRIPT"
    printf 'export frozen_resume_cmd=%q\n' "$frozen_resume_cmd" >> "$RUN_SCRIPT"
    printf 'export frozen_resume_latest=%q\n' "$frozen_resume_latest" >> "$RUN_SCRIPT"
    printf 'export frozen_chat_id=%q\n' "$frozen_chat_id" >> "$RUN_SCRIPT"
    printf 'export AICLI_ENV_HASH=%q\n'  "$ENV_HASH"       >> "$RUN_SCRIPT"
    printf 'export frozen_effective_args=%q\n' "$frozen_effective_args" >> "$RUN_SCRIPT"
    # WP #273: export the args file paths too so the run-loop can re-read
    # them on every Ctrl-C relaunch without re-computing the md5(path) hash.
    # Lets the user change workspace args from the UI and have them apply on
    # the next agent launch (no workspace destroy needed).
    printf 'export AICLI_ARGS_WS_FILE=%q\n' "$_ARGS_WS_FILE" >> "$RUN_SCRIPT"
    printf 'export AICLI_ARGS_AGENT_FILE=%q\n' "$_ARGS_AGENT_FILE" >> "$RUN_SCRIPT"
    printf 'export DEBUG_LOG=%q\n' "$DEBUG_LOG" >> "$RUN_SCRIPT"
    
    # D-204: Pass Node Memory limits to the child script
    MEM_LIMIT="${AICLI_NODE_MEMORY:-4096}"
    printf 'export NODE_OPTIONS=%q\n' "--max-old-space-size=$MEM_LIMIT" >> "$RUN_SCRIPT"

    # D-59: Prevent Auto-Updates on Launch (agent-specific)
    # Only set the disable variable for the agent actually being launched.
    printf 'export DISABLE_AUTOUPDATER=1\n' >> "$RUN_SCRIPT"
    printf 'export DISABLE_UPDATE_CHECK=1\n' >> "$RUN_SCRIPT"
    case "$AGENT_ID" in
        gemini-cli)  printf 'export GEMINI_CLI_DISABLE_AUTO_UPDATE=true\n' >> "$RUN_SCRIPT" ;;
        opencode)    printf 'export OPENCODE_DISABLE_AUTOUPDATE=true\n' >> "$RUN_SCRIPT" ;;
        nanocoder)   printf 'export NANOCODER_DISABLE_AUTO_UPDATE=1\n' >> "$RUN_SCRIPT" ;;
        kilocode)    printf 'export KILO_DISABLE_AUTO_UPDATE=1\n' >> "$RUN_SCRIPT" ;;
        pi-coder)    printf 'export PI_CODER_DISABLE_AUTO_UPDATE=1\n' >> "$RUN_SCRIPT" ;;
    esac

    # 2. Minimalist Logic (Atomic Environment Injection)
    # D-159: Variables are injected PRIOR to the here-doc to prevent environmental leakage
    printf 'export USER_NAME=%q\n' "$USER_NAME" >> "$RUN_SCRIPT"
    printf 'export HOME_DIR=%q\n' "$HOME_DIR" >> "$RUN_SCRIPT"

    # WP #736: Inject the full 5-tier effective env from EnvService — single
    # source of truth (TerminalService merges the same map for its $envStr).
    # Tier order, later wins per key:
    #   source.env < secrets.cfg < workspace secrets < agent envs < workspace envs
    # Reserved names (PATH, HOME, AICLI_*, frozen_*, …) are filtered out so a
    # user setting can't clobber plugin-internal state. Closes the latent
    # dead-write gap where secrets.cfg was never sourced at launch.
    EXPORTED_KEYS_FILE="$HOME_DIR/.aicli/.exported_keys_${ENV_HASH}"
    mkdir -p "$(dirname "$EXPORTED_KEYS_FILE")" 2>/dev/null
    php -r '
        require_once "/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/AICliAgentsManager.php";
        $rootDir = $argv[1] ?? "";
        $agentId = $argv[2] ?? "";
        $tracker = $argv[3] ?? "";
        $env = \AICliAgents\Services\EnvService::buildEffectiveEnv($rootDir !== "" ? $rootDir : null, $agentId);
        $names = [];
        foreach ($env as $k => $v) {
            if (\AICliAgents\Services\EnvService::isReservedKey($k)) continue;
            if (!preg_match("/^[A-Za-z_][A-Za-z0-9_]*$/", $k)) continue;
            echo "export " . $k . "=" . escapeshellarg($v) . PHP_EOL;
            $names[] = $k;
        }
        if ($tracker !== "") {
            @file_put_contents($tracker, implode(PHP_EOL, $names) . PHP_EOL);
        }
    ' -- "$ROOT_DIR" "$AGENT_ID" "$EXPORTED_KEYS_FILE" >> "$RUN_SCRIPT" 2>/dev/null
    log_aicli "INFO" 2 "Injected effective env (5-tier merge via EnvService)"

    # Export the tracker path + workspace path so the run-loop's
    # _aicli_load_envs can hot-apply changes without needing to recompute
    # them. Per WP #736 hot-apply.
    printf 'export AICLI_WORKSPACE_PATH=%q\n' "$ROOT_DIR" >> "$RUN_SCRIPT"
    printf 'export AICLI_EXPORTED_KEYS_FILE=%q\n' "$EXPORTED_KEYS_FILE" >> "$RUN_SCRIPT"

    cat << 'EOF' >> "$RUN_SCRIPT"
# Bug #1054 diagnostic: log VERY early so we can pin down where RUN_SCRIPT
# dies in failing non-root launches. The previous death pre-empted any
# perf_log/log_aicli call, so the FIRST line of the heredoc body is now
# a direct debug.log write -- bypasses log_aicli (which calls php) so it
# works even if php is broken in this context.
echo "[$(date '+%Y-%m-%d %H:%M:%S')] [DIAG-1054-A] RUN_SCRIPT entered (uid=$(id -u) sess=$AICLI_SESSION_ID agent=$AGENT_ID home=$HOME tty=$(tty 2>&1) shell=$0)" >> /tmp/unraid-aicliagents/debug.log
export TERM=xterm-256color
export LC_ALL=en_US.UTF-8
echo "[$(date '+%Y-%m-%d %H:%M:%S')] [DIAG-1054-B] before stty sane" >> /tmp/unraid-aicliagents/debug.log
stty sane 2>/dev/null
echo "[$(date '+%Y-%m-%d %H:%M:%S')] [DIAG-1054-C] after stty sane (rc=$?)" >> /tmp/unraid-aicliagents/debug.log
perf_log run.start
echo "[$(date '+%Y-%m-%d %H:%M:%S')] [DIAG-1054-D] after perf_log run.start" >> /tmp/unraid-aicliagents/debug.log

# WP #275c: install a no-op SIGINT trap on the RUN_SCRIPT itself.
# Why: in a non-interactive bash script with no SIGINT trap, receiving SIGINT
# (Ctrl-C from the terminal) terminates the script immediately. That's the
# default behaviour and it's what was killing the wrapper when the UI's
# Apply-now-args flow sent Ctrl-C while bash was sitting at a `read -r` prompt
# (e.g. after a previous bad-args launch parked us in the fast-exit guard).
# Tmux died with bash, ttyd showed "Press ⏎ to Reconnect".
#
# Setting `trap : INT` makes bash run a no-op (`:`) on SIGINT. Bash builtins
# like `read` get interrupted (return 130) and the script CONTINUES. The next
# loop iteration then fires the auto-reload sentinel check and relaunches the
# agent with the new args — which is exactly what the user asked for.
#
# Important: bash traps DON'T propagate to children (only signal dispositions
# do, and only SIG_IGN inherits — `trap : ...` sets a function, child gets
# SIG_DFL). So the running agent still receives Ctrl-C normally and exits as
# expected. This trap only protects bash itself in the gaps between agent
# launches.
trap : INT

# WP #736 hot-apply: re-read the 5-tier effective env via EnvService on every
# loop iteration. Diffs against $AICLI_EXPORTED_KEYS_FILE: unsets any key we
# previously exported but is no longer present; re-exports the current set.
# Single source of truth shared with the startup path and TerminalService.
# Single PHP invocation per iteration (~50 ms — negligible against agent
# startup cost).
_aicli_load_envs() {
    local script="/tmp/aicli-envreload-$$-$RANDOM.sh"
    php -r '
        require_once "/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/AICliAgentsManager.php";
        $rootDir = $argv[1] ?? "";
        $agentId = $argv[2] ?? "";
        $env = \AICliAgents\Services\EnvService::buildEffectiveEnv($rootDir !== "" ? $rootDir : null, $agentId);
        $names = [];
        foreach ($env as $k => $v) {
            if (\AICliAgents\Services\EnvService::isReservedKey($k)) continue;
            if (!preg_match("/^[A-Za-z_][A-Za-z0-9_]*$/", $k)) continue;
            echo "export " . $k . "=" . escapeshellarg($v) . PHP_EOL;
            $names[] = $k;
        }
        echo "_AICLI_NEW_KEYS=" . escapeshellarg(implode(" ", $names)) . PHP_EOL;
    ' -- "$AICLI_WORKSPACE_PATH" "$AGENT_ID" > "$script" 2>/dev/null

    # Source it: exports the new env AND sets _AICLI_NEW_KEYS in our scope.
    # shellcheck disable=SC1090
    source "$script"
    rm -f "$script"

    # Unset previously-exported keys that aren't in the new set.
    if [ -f "$AICLI_EXPORTED_KEYS_FILE" ]; then
        while IFS= read -r _old_key; do
            [ -z "$_old_key" ] && continue
            case " $_AICLI_NEW_KEYS " in
                *" $_old_key "*) : ;;            # still set — keep
                *) unset "$_old_key" ;;          # removed — drop
            esac
        done < "$AICLI_EXPORTED_KEYS_FILE"
    fi

    # Rewrite the tracker for the next iteration.
    : > "$AICLI_EXPORTED_KEYS_FILE"
    for _k in $_AICLI_NEW_KEYS; do
        echo "$_k" >> "$AICLI_EXPORTED_KEYS_FILE"
    done
    unset _AICLI_NEW_KEYS _old_key _k
}

while true; do
    perf_log agent.exec.begin
    clear

    # WP #273: refresh effective args from disk on every iteration. The user
    # may have changed workspace-level args from the UI while the agent was
    # running; on the next Ctrl-C the relaunch should pick them up. We re-read
    # the same files the OUTER script seeded at startup (paths exported above
    # as AICLI_ARGS_WS_FILE / AICLI_ARGS_AGENT_FILE — md5 hash already baked
    # in, so this is one cheap PHP -r call, not a re-hash). Falls back to
    # empty when the workspace override is removed (correct: args cleared).
    _refresh_args=""
    if [ -n "$AICLI_ARGS_WS_FILE" ] && [ -f "$AICLI_ARGS_WS_FILE" ]; then
        _refresh_args=$(php -r "\$d=json_decode(file_get_contents('$AICLI_ARGS_WS_FILE'),true);echo is_array(\$d)?(\$d['args']??''):'';" 2>/dev/null)
    elif [ -n "$AICLI_ARGS_AGENT_FILE" ] && [ -f "$AICLI_ARGS_AGENT_FILE" ]; then
        _refresh_args=$(php -r "\$d=json_decode(file_get_contents('$AICLI_ARGS_AGENT_FILE'),true);echo is_array(\$d)?(\$d['args']??''):'';" 2>/dev/null)
    fi
    if [ "$_refresh_args" != "$frozen_effective_args" ]; then
        log_aicli "INFO" 2 "Args changed since last launch: '$frozen_effective_args' -> '$_refresh_args' (applying on this relaunch)"
        frozen_effective_args="$_refresh_args"
    fi

    # WP #736 hot-apply: refresh env from disk on every iteration. After a
    # Ctrl-C-driven reload (UI's agent_signal_reload + Apply now), the new
    # env values are picked up here before the agent execs below.
    _aicli_load_envs

    # D-52: SURGICAL DB REPAIR (Safe Version)
    if [[ "$AGENT_ID" == "opencode" ]]; then
       db_file="$HOME_DIR/.local/share/opencode/opencode.db"
       rm -f "$db_file-wal" "$db_file-shm" 2>/dev/null
    fi

    # D-159: Metadata Alignment - Ensure agent config directories are accessible
    case "$AGENT_ID" in
        gemini-cli)  [ -d "$HOME_DIR/.gemini" ] && chmod -R 775 "$HOME_DIR/.gemini" > /dev/null 2>&1 ;;
        claude-code) [ -d "$HOME_DIR/.claude" ] && chmod -R 775 "$HOME_DIR/.claude" > /dev/null 2>&1 ;;
        opencode)    [ -d "$HOME_DIR/.local/share/opencode" ] && chmod -R 775 "$HOME_DIR/.local/share/opencode" > /dev/null 2>&1 ;;
    esac

    # D-170: Ensure HOME is present and writable for this user
    mkdir -p "$HOME_DIR" 2>/dev/null
    chmod 0755 "$HOME_DIR" 2>/dev/null

    # Pre-create the per-agent PARENT dirs only — not their leaf subdirs.
    # Opencode's bun init does a non-recursive mkdir '.cache/opencode/bin'
    # which ENOENTs when '.cache/opencode' doesn't exist (happens on first
    # run after install, OR after a persistence bake because storage/
    # commit_stack.sh wipes .cache/* on every sync to keep Flash deltas
    # small). Creating just the agent's parent dir lets that mkdir succeed
    # AND lets opencode's init own its own leaf creation — pre-creating
    # .cache/opencode/bin ourselves short-circuited opencode's init which
    # then blew up on the NEXT step (open '.cache/opencode/version') because
    # opencode assumed that if bin exists, version must too. Parent-only is
    # the right depth.
    mkdir -p "$HOME_DIR/.cache/${AGENT_ID}" \
             "$HOME_DIR/.config/${AGENT_ID}" \
             "$HOME_DIR/.local/share/${AGENT_ID}" \
             2>/dev/null
    
    # Fix A: Re-resolve the effective binary on every relaunch iteration.
    # An in-place agent upgrade (e.g. claude-code 2.1.x dropping cli.js and
    # adding bin/claude.exe) leaves frozen_binary pointing at the deleted file.
    # Re-checking primary → fallback each loop picks up the new layout without
    # closing the workspace. Only re-resolves path-style binaries (contains /).
    # Proxy commands (no /) are stable by name and need no re-resolution.
    if [ -n "$frozen_binary_primary" ] && [[ "$frozen_binary_primary" == *"/"* ]]; then
        if [ -f "$frozen_binary_primary" ] && [ -x "$frozen_binary_primary" ]; then
            frozen_binary="$frozen_binary_primary"
        elif [ -n "$frozen_binary_fallback" ] && [ -f "$frozen_binary_fallback" ]; then
            frozen_binary="$frozen_binary_fallback"
            log_aicli "WARN" 1 "Primary binary missing (${frozen_binary_primary}); using fallback (${frozen_binary_fallback})"
        elif [ ! -f "$frozen_binary_primary" ]; then
            log_aicli "ERROR" 1 "Agent binary not found at '${frozen_binary_primary}' or '${frozen_binary_fallback}' — the agent may need reinstalling via the Store card"
            echo -e "\n\033[1;31m[Agent Binary Missing]\033[0m The agent binary was not found."
            echo -e "Expected: ${frozen_binary_primary}"
            [ -n "$frozen_binary_fallback" ] && echo -e "Fallback:  ${frozen_binary_fallback}"
            echo -e "The agent may need reinstalling via the Store card."
            echo -e "Press ENTER to retry..."
            read -r
        fi
    fi

    # D-348: Modern Launch Logic (Proxy-Aware)
    # 1. We prefer the proxy command (e.g. 'gemini') stored in frozen_binary.
    # 2. If it's not a path (no /), it's a proxy. If it is a path, we check health.
    if [ -n "$frozen_binary" ]; then
        if [[ "$frozen_binary" == *"/"* ]]; then
            link_target=$(readlink -f "$frozen_binary" 2>&1)
            target_info=$(ls -la "$link_target" 2>&1)
            # Native binaries (e.g. Claude Code 2.1.x bin/claude.exe) contain NUL
            # bytes; bash warns when command substitution strips them. Detect ELF
            # up front and skip the shebang read for native binaries.
            if [ "$(head -c 4 "$link_target" 2>/dev/null | tr -d '\0' | od -An -c | tr -d ' \n')" = "177ELF" ]; then
                shebang="(ELF native binary)"
            else
                shebang=$(head -n 1 "$link_target" 2>/dev/null | tr -d '\0')
            fi
            log_aicli "INFO" 2 "Link Health: ${frozen_binary} -> ${link_target} | Shebang: ${shebang}"
            log_aicli "INFO" 2 "Target Info: ${target_info}"
        else
            log_aicli "INFO" 2 "Proxy Launch: ${frozen_binary} (System PATH)"
        fi
    else
        # D-318: Terminals don't have binaries, only log at DEBUG
        if [ "$AGENT_ID" == "terminal" ]; then
            log_aicli "DEBUG" 3 "terminal agent has no frozen_binary (standard behavior)"
        else
            log_aicli "ERROR" 1 "CRITICAL: frozen_binary is EMPTY for $AGENT_ID"
        fi
    fi
    
    # Resume decision:
    # 1. '_fresh_' sentinel = user clicked "Start New Session" — skip all fallbacks.
    # 2. If the UI handed us an explicit chat id (from graceful-close capture),
    #    that is ALWAYS the strongest signal — trust it regardless of agent.
    # 3. Otherwise, fall back to the legacy per-agent history-dir scan so the
    #    "resume-latest" path still works for agents we've had wired up for
    #    a while (gemini-cli, claude-code, pi-coder).
    can_resume=0
    if [ "$frozen_chat_id" = "_fresh_" ]; then
        can_resume=0
        log_aicli "INFO" 2 "Fresh-start sentinel: skipping all history fallbacks"
    elif [ -n "$frozen_chat_id" ] && [ "$frozen_chat_id" != "none" ]; then
        can_resume=1
        log_aicli "INFO" 2 "Resume requested with explicit chat id: $frozen_chat_id"
    elif [ "$AGENT_ID" == "gemini-cli" ]; then
        if [ -d "$HOME_DIR/.gemini/history" ] && [ -n "$(find "$HOME_DIR/.gemini/history" -type f 2>/dev/null)" ]; then
            can_resume=1
        fi
    elif [ "$AGENT_ID" == "claude-code" ]; then
        if [ -d "$HOME_DIR/.claude/sessions" ] && [ -n "$(find "$HOME_DIR/.claude/sessions" -type f 2>/dev/null)" ]; then
            can_resume=1
        fi
    elif [ "$AGENT_ID" == "pi-coder" ]; then
        if [ -d "$HOME_DIR/.pi/agent/sessions" ] && [ -n "$(find "$HOME_DIR/.pi/agent/sessions" -type f 2>/dev/null)" ]; then
            can_resume=1
        fi
    fi

    # D-400: Safe command execution (no eval). Uses bash -c with validated commands.
    # Critical: stderr is NOT redirected. TUI libraries used by several agents
    # (Goose uses Rust's inquire / dialoguer; those probe isatty(stderr) to decide
    # whether to draw the full-screen prompt). Sending stderr to a log file makes
    # the probe fail, the prompt is skipped, and the agent exits immediately — which
    # then trips the fast-exit guard with "Press ENTER to reload" before the user
    # ever sees the Y/N card. Letting stderr flow to the pane's TTY keeps TUIs
    # alive and also makes genuine errors visible directly to the user.
    safe_exec() {
        local cmd="$1"
        log_aicli "INFO" 2 "Executing: $cmd"
        bash -c "$cmd"
    }

    # D-402 (#54): Detect whether the resolved binary is a native ELF executable.
    # If it is, the "fall back to node" branches below are meaningless (node cannot
    # run ELF) and actively harmful — a legitimate non-zero exit from the native
    # binary (e.g. Goose exiting because no provider is configured) was being
    # masked as "binary corrupted" when node then failed to parse the ELF bytes.
    is_elf_binary() {
        local target="$1"
        local resolved
        resolved=$(readlink -f "$target" 2>/dev/null || echo "$target")
        [ -f "$resolved" ] || return 1
        [ "$(head -c 4 "$resolved" 2>/dev/null | tr -d '\0' | od -An -c | tr -d ' \n')" = "177ELF" ]
    }

    status="fail"
    launch_start_ts=$(date +%s)
    if [ "$AGENT_ID" == "terminal" ]; then
        /bin/bash
        status="ok"
    elif [ "$can_resume" == "1" ] && [ -n "$frozen_chat_id" ] && [ "$frozen_chat_id" != "none" ]; then
        # Substitute both {chatId} and {binary}. Short command names (claude,
        # copilot, etc) don't resolve when agents ship their binary under a
        # different filename (e.g. Claude Code 2.1 ships bin/claude.exe, not
        # claude) — so registry templates use {binary} for an absolute path.
        #
        # Shell-escape the chat id before interpolation. Claude Code supports
        # renaming a session (/rename) and prints the name on exit instead of
        # a UUID — if the name contains spaces or apostrophes, a raw substitute
        # produces a broken command line. printf %q yields a safely-quoted
        # token that bash re-parses back to the literal. Bare-UUID ids round-
        # trip unchanged, so existing agents are unaffected.
        escaped_chat_id=$(printf '%q' "$frozen_chat_id")
        FINAL_CMD="${frozen_resume_cmd//\{chatId\}/$escaped_chat_id}"
        FINAL_CMD="${FINAL_CMD//\{binary\}/$frozen_binary}"
        FINAL_CMD="${FINAL_CMD//\{args\}/$frozen_effective_args}"
        FINAL_CMD="${FINAL_CMD//  / }"
        log_aicli "INFO" 2 "Attempting Resume: $FINAL_CMD"

        # 1. Command-based Execution (Uses PATH or absolute path)
        if safe_exec "$FINAL_CMD"; then
            status="ok"
        else
            # 2. Deep Fallback: Explicit node invocation — only for JS targets.
            if [[ "$frozen_binary" == *"/"* ]] && ! is_elf_binary "$frozen_binary"; then
                log_aicli "INFO" 2 "Native launch failed. Trying explicit node launch on $frozen_binary..."
                if node "$frozen_binary"; then
                    status="ok"
                fi
            fi
        fi
    elif [ "$can_resume" == "1" ]; then
        LATEST_CMD="${frozen_resume_latest//\{binary\}/$frozen_binary}"
        LATEST_CMD="${LATEST_CMD//\{args\}/$frozen_effective_args}"
        LATEST_CMD="${LATEST_CMD//  / }"
        log_aicli "INFO" 2 "Attempting Latest Resume: $LATEST_CMD"
        if safe_exec "$LATEST_CMD"; then
            status="ok"
        fi
    fi

    # Fallback to Fresh Launch if resume failed or wasn't applicable
    if [ "$status" == "fail" ]; then
        FRESH_CMD="$frozen_binary${frozen_effective_args:+ $frozen_effective_args}"
        log_aicli "INFO" 2 "Attempting Fresh Launch: $FRESH_CMD"

        # 1. Command-based Execution
        if safe_exec "$FRESH_CMD"; then
            status="ok"
        else
            # 2. Deep Fallback — only meaningful when the binary is actually a JS file.
            if [[ "$frozen_binary" == *"/"* ]] && ! is_elf_binary "$frozen_binary"; then
                log_aicli "INFO" 2 "Native launch failed. Trying explicit node launch on $frozen_binary..."
                if node "$frozen_binary"; then
                    status="ok"
                fi
            fi
        fi
    fi
    
    if [ "$status" != "ok" ]; then
        log_aicli "ERROR" 1 "All launch attempts failed for $AGENT_ID. Binary corrupted or Node error. Check $DEBUG_LOG"
    fi
    
    if [ "$AGENT_ID" == "terminal" ]; then exit 0; fi

    # Graceful-close sentinel: if the UI requested a clean shutdown (Ctrl-C x2
    # then touch of close-<id>.flag), the agent has already flushed its state
    # and emitted its resume id. Break out of the relaunch loop so the user
    # isn't greeted by "Press ENTER to reload" on a closed workspace.
    if [ -f "/tmp/unraid-aicliagents/close-$AICLI_SESSION_ID.flag" ]; then
        rm -f "/tmp/unraid-aicliagents/close-$AICLI_SESSION_ID.flag" 2>/dev/null
        log_aicli "INFO" 2 "Graceful close sentinel observed — exiting relaunch loop."
        break
    fi

    # WP #275: Auto-reload sentinel. When the UI's "Apply now" button (workspace
    # args modal) sends Ctrl-C to apply args changes, it also touches this flag
    # BEFORE the Ctrl-C. The user shouldn't see a "Press ENTER to reload" prompt
    # in that case — they explicitly asked for an immediate restart. Skip the
    # prompt and relaunch immediately. The args refresh at the top of the next
    # iteration picks up the new value.
    if [ -f "/tmp/unraid-aicliagents/auto-reload-$AICLI_SESSION_ID.flag" ]; then
        rm -f "/tmp/unraid-aicliagents/auto-reload-$AICLI_SESSION_ID.flag" 2>/dev/null
        log_aicli "INFO" 2 "Auto-reload sentinel observed — skipping reload prompt, relaunching immediately."
        continue
    fi

    # Post-exit GUID sync: when the user /resume's inside the TUI and selects a
    # different chat, the agent's on-disk session file changes but the shell's
    # frozen_chat_id (baked at session start) doesn't. Scan the agent's session
    # store after every exit and update both frozen_chat_id (for the next relaunch)
    # and the durable resume file (for the next workspace open). Only runs when
    # neither sentinel fired — graceful-close is handled by PHP, auto-reload does
    # not need a GUID update.
    if [ -n "$AICLI_ENV_HASH" ]; then
        _discovered_chat=""
        if [ "$AGENT_ID" == "claude-code" ]; then
            for _cc_sessions_dir in "$HOME_DIR/.claude/projects" "$HOME_DIR/.claude/sessions"; do
                if [ -d "$_cc_sessions_dir" ]; then
                    _newest_jsonl=$(find "$_cc_sessions_dir" -name "*.jsonl" -type f -printf '%T@ %p\n' 2>/dev/null | sort -rn | head -1 | cut -d' ' -f2-)
                    if [ -n "$_newest_jsonl" ]; then
                        _discovered_chat=$(basename "$_newest_jsonl" .jsonl)
                        break
                    fi
                fi
            done
        fi
        if [ -n "$_discovered_chat" ] && [ "$_discovered_chat" != "$frozen_chat_id" ]; then
            log_aicli "INFO" 2 "Post-exit GUID sync: $frozen_chat_id -> $_discovered_chat"
            frozen_chat_id="$_discovered_chat"
            _resume_dir="$HOME_DIR/.aicli/resumes"
            _resume_file="${_resume_dir}/resume_${AICLI_ENV_HASH}.json"
            mkdir -p "$_resume_dir" 2>/dev/null
            printf '{"chat_id":"%s","saved_at":%d}\n' "$_discovered_chat" "$(date +%s)" > "${_resume_file}.tmp" \
                && mv "${_resume_file}.tmp" "$_resume_file" 2>/dev/null
        fi
    fi

    # D-403 (#55): Fast-exit guard. Agents that exit in under 3 seconds are almost
    # always bailing on first-launch setup (e.g. Goose with no GOOSE_PROVIDER set
    # prints "Error: not connected" and exits immediately). Auto-relaunching in
    # that state produces an infinite loop of the same first-run prompt. When a
    # fast exit is detected, require an explicit ENTER instead of timing out after
    # 10s so the user has a chance to read the message and close the workspace.
    launch_duration=$(( $(date +%s) - launch_start_ts ))
    if [ "$launch_duration" -lt 3 ]; then
        echo -e "\n\033[1;31m[Agent Exited Immediately]\033[0m The agent exited after ${launch_duration}s — likely missing configuration."
        echo -e "Press ENTER to retry, or close this workspace from the UI."
        read -r
    else
        echo -e "\n\033[1;33m[Agent Exited]\033[0m Press ENTER to reload..."
        read -t 10 -r
    fi
done
EOF




    chmod +x "$RUN_SCRIPT"
    log_aicli "DEBUG" 3 "Launching tmux session $SESSION for script $RUN_SCRIPT"
    perf_log tmux.new.begin
    # Bug #1054 diagnostic: capture caller env + cwd so we can compare working
    # vs failing launches. Removed once root cause is fixed.
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] [DIAG-1054-PRE-TMUX] sess=$SESSION uid=$(id -u) cwd=$(pwd) TMUX_TMPDIR=$TMUX_TMPDIR tmux_v=$(tmux -V) script_size=$(wc -c < "$RUN_SCRIPT")" >> "$DEBUG_LOG"
    tmux -u new-session -d -s "$SESSION" "$RUN_SCRIPT" 2>>"$DEBUG_LOG"
    _tmux_rc=$?
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] [DIAG-1054-POST-TMUX] new-session rc=$_tmux_rc has-session-now=$(tmux has-session -t "$SESSION" 2>&1 && echo YES || echo NO)" >> "$DEBUG_LOG"
    if [ $? -ne 0 ]; then
        log_aicli "ERROR" 1 "Failed to create tmux session $SESSION. Check $DEBUG_LOG"
    fi
    perf_log tmux.new.done
fi

log_aicli "DEBUG" 3 "Attaching to tmux session $SESSION..."
perf_log tmux.options.begin

# ---------- Four-tier tmux configuration ----------
# Tier 1 — Built-in defaults (safety net; also mirrored in TmuxService::BUILTIN).
tmux set-option -g history-limit "$HISTORY_LIMIT" 2>/dev/null
tmux set-option -g status off 2>/dev/null
tmux set-option -g allow-passthrough on 2>/dev/null
tmux set-option -g focus-events on 2>/dev/null

# Helper: apply a JSON file of allow-listed tmux options via a single PHP pass.
apply_tmux_json() {
    local jsonfile="$1"
    [ -f "$jsonfile" ] || return 0
    log_aicli "DEBUG" 3 "Applying tmux settings from $jsonfile"
    php -r "
        \$allowed = ['status','mouse','history-limit','prefix','base-index','bell-action','default-terminal','focus-events','allow-passthrough'];
        \$s = json_decode(@file_get_contents('$jsonfile'), true);
        if (is_array(\$s)) {
            foreach (\$s as \$k => \$v) {
                if (in_array(\$k, \$allowed, true) && \$v !== '' && \$v !== null) {
                    echo 'tmux set-option -g ' . escapeshellarg(\$k) . ' ' . escapeshellarg((string)\$v) . \"\n\";
                }
            }
        }
    " 2>>"$DEBUG_LOG" | bash 2>>"$DEBUG_LOG"
}

# Legacy migration: rename pre-4-tier per-(ws,agent) hash files to .legacy so the old
# layout doesn't silently override the new tiers. Idempotent. Runs cheap after the
# first launch because there's nothing left to match.
for _legacy in "$HOME_DIR/.aicli/tmux/"tmux_*.json; do
    [ -e "$_legacy" ] || continue
    _legacy_base=$(basename "$_legacy")
    case "$_legacy_base" in
        tmux_agent_*.json|tmux_ws_*.json) continue ;;
    esac
    if [[ "$_legacy_base" =~ ^tmux_[a-f0-9]{32}\.json$ ]]; then
        mv "$_legacy" "$_legacy.legacy" 2>/dev/null && \
            log_aicli "WARN" 2 "Renamed legacy tmux config: $_legacy_base -> $_legacy_base.legacy (reconfigure via Store card / workspace drawer)"
    fi
done
unset _legacy _legacy_base

# Tier 2 — Agent-level defaults (edited via the Store card's Terminal panel).
apply_tmux_json "$HOME_DIR/.aicli/tmux/tmux_agent_${AGENT_ID}.json"

# Tier 3 — Workspace-level overrides (edited via the drawer tmux tab). Keyed by
# md5(ROOT_DIR) so the same agent in different workspaces has isolated deltas.
if [ -n "$ROOT_DIR" ]; then
    WS_HASH=$(printf %s "$ROOT_DIR" | md5sum | cut -d' ' -f1)
    apply_tmux_json "$HOME_DIR/.aicli/tmux/tmux_ws_${WS_HASH}_${AGENT_ID}.json"
fi

# Tier 4 — Power-user workspace .conf (raw tmux syntax, escape hatch — wins all).
WS_TMUX_CONF="$ROOT_DIR/.aicli/tmux/${AGENT_ID}.conf"
if [ -n "$ROOT_DIR" ] && [ -f "$WS_TMUX_CONF" ] && [ -r "$WS_TMUX_CONF" ]; then
    log_aicli "DEBUG" 3 "Sourcing workspace tmux conf: $WS_TMUX_CONF"
    if ! tmux source-file "$WS_TMUX_CONF" 2>>"$DEBUG_LOG"; then
        log_aicli "WARN" 2 "Workspace tmux conf had errors (see $DEBUG_LOG) - prior tiers remain active"
    fi
fi
perf_log tmux.options.done
# D-400: exec replaces this process - error handling must happen before exec
if ! tmux has-session -t "$SESSION" 2>/dev/null; then
    log_aicli "ERROR" 1 "tmux session $SESSION not found before attach. Check $DEBUG_LOG"
    echo "Terminal session not found. Check debug log."
    sleep 5
    exit 1
fi
perf_log tmux.attach.exec
exec tmux -u attach-session -t "$SESSION" 2>>"$DEBUG_LOG"
