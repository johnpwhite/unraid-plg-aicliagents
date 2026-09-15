#!/bin/bash
# AICliAgents CLI Restricted Shell Wrapper
# v2026.03.17.13 - Pure Terminal Wrapper (No Heartbeats)
#
# Modes (R-B1, CLAUDE_RELAUNCH_SURVIVAL):
#   (default)         interactive — ensure the detached tmux session exists, then
#                     `exec tmux attach`. This is how ttyd invokes us when a
#                     browser connects.
#   --ensure-session  HEADLESS — ensure the detached tmux session + agent exist,
#                     then RETURN 0 WITHOUT attaching. Run server-side at launch
#                     (TerminalService) so the agent comes up in a browser-less,
#                     detached tmux session that survives a deploy and the
#                     orphan-sweep. A later browser attach (default mode) just
#                     connects to the already-running session. Idempotent: if the
#                     session already exists this is a no-op success.
AICLI_ENSURE_ONLY=0
case "$1" in
    --ensure-session) AICLI_ENSURE_ONLY=1 ;;
esac

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
# Capture the immutable generation containing this launcher. TerminalService
# resolves the active src symlink before starting ttyd, so this path remains
# valid across later plugin activations.
PLUGIN_SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." 2>/dev/null && pwd -P)"
[ -n "$PLUGIN_SRC" ] || PLUGIN_SRC="$EMHTTP_DEST/src"

# Unified terminal output logging via safe PHP bridge (no string interpolation)
BRIDGE_SCRIPT="$PLUGIN_SRC/scripts/log-bridge.php"
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

# Issue #56: fail closed if this wrapper is invoked directly or by an older
# PHP caller while an Unraid user-share path is only a rootfs stub. Nothing
# below this point may read workspace state, cd there, or pass it to tmux -c
# until the exact backing mount exists. Exit 75 means temporary failure; the
# normal auto-launch/retry reconciliation can try again after array start.
case "$ROOT_DIR" in
    /mnt/user|/mnt/user/*)   _AICLI_REQUIRED_MOUNT="/mnt/user" ;;
    /mnt/user0|/mnt/user0/*) _AICLI_REQUIRED_MOUNT="/mnt/user0" ;;
    *)                       _AICLI_REQUIRED_MOUNT="" ;;
esac
if [ -n "$_AICLI_REQUIRED_MOUNT" ] && ! mountpoint -q "$_AICLI_REQUIRED_MOUNT" 2>/dev/null; then
    log_aicli "WARN" 1 "Workspace deferred: $_AICLI_REQUIRED_MOUNT is not mounted ($ROOT_DIR)."
    exit 75
fi

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

# SIDE_BY_SIDE_AGENT_INSTALLS.md Phase 3 (2026-09-09): resolve the agent's
# STABLE directory (agents/<id> — a plain directory today, a symlink to
# agents/.versions/<id>/<gen> once Phase 2 migrates it) to its REAL target
# exactly once, here, at session launch. BINARY_PRIMARY / BINARY_FALLBACK
# arrive from TerminalService built on the stable name (AgentRegistry::
# agentPath()), so freezing them verbatim — as the two lines below used to —
# freezes a path whose LAST COMPONENT can still move if the stable symlink is
# flipped later. Substituting the resolved directory in NOW turns that path
# pin into a version pin (R2): every later relaunch iteration re-checks these
# same frozen strings for existence (never re-reads BINARY_PRIMARY/FALLBACK),
# so once they name a concrete generation directory, nothing that happens to
# the stable symlink afterward can move this session.
#
# Both layouts behave identically: pre-migration, agents/<id> is a real
# directory and agent_mount_real() (readlink -f) resolves it to itself, so
# _AICLI_AGENT_REAL_DIR == _AICLI_AGENT_STABLE_DIR and the substitution below
# is a byte-for-byte no-op — the expected, unchanged state on this box today.
_AICLI_RESOLVE_PATHS_SH="$PLUGIN_SRC/scripts/storage/resolve_paths.sh"
# shellcheck source=./storage/resolve_paths.sh
[ -f "$_AICLI_RESOLVE_PATHS_SH" ] && source "$_AICLI_RESOLVE_PATHS_SH" 2>/dev/null
_AICLI_AGENT_STABLE_DIR=""
_AICLI_AGENT_REAL_DIR=""
if command -v agent_mount_real >/dev/null 2>&1 && [ -n "$AGENT_ID" ] && [ "$AGENT_ID" != "terminal" ]; then
    _AICLI_AGENT_STABLE_DIR="$(agent_mount "$AGENT_ID" 2>/dev/null)"
    _AICLI_AGENT_REAL_DIR="$(agent_mount_real "$AGENT_ID" 2>/dev/null)"
fi

# _aicli_pin_to_real_generation <raw-path>
# Rewrites <raw-path> to route through the resolved generation directory when
# it is rooted under the stable agent directory; echoes it unchanged otherwise
# (empty, a bare proxy command with no '/', or already outside the agent
# tree). Pure string substitution — no filesystem access beyond what already
# happened above, so it is cheap to call for all three frozen binaries.
_aicli_pin_to_real_generation() {
    local raw="$1"
    if [ -n "$raw" ] && [ -n "$_AICLI_AGENT_STABLE_DIR" ] && [ -n "$_AICLI_AGENT_REAL_DIR" ] \
       && [ "$_AICLI_AGENT_STABLE_DIR" != "$_AICLI_AGENT_REAL_DIR" ] \
       && [[ "$raw" == "$_AICLI_AGENT_STABLE_DIR"/* ]]; then
        printf '%s' "${_AICLI_AGENT_REAL_DIR}${raw#"$_AICLI_AGENT_STABLE_DIR"}"
    else
        printf '%s' "$raw"
    fi
}

# Freeze variables for tmux
# Fix A: freeze the two RAW binary paths (primary + fallback) so the run-loop
# can re-resolve on every relaunch. BINARY is the *already-resolved* effective
# path (primary if it existed at session-open, else fallback). An in-place
# upgrade that drops cli.js and adds bin/claude.exe would leave frozen_binary
# pointing at the deleted file; re-resolving from BINARY_PRIMARY / BINARY_FALLBACK
# picks up the new layout without closing the workspace. This survival
# mechanism is preserved UNCHANGED and stays scoped to the one generation
# pinned above — it re-resolves the binary LAYOUT inside that generation, it
# never re-resolves the generation itself.
#
frozen_binary_primary="${BINARY_PRIMARY:-$BINARY}"
frozen_binary_fallback="${BINARY_FALLBACK:-}"
# _stable variants keep the ORIGINAL (un-pinned) paths, still expressed
# through the stable name, captured BEFORE the pin substitution below
# overwrites frozen_binary_primary/frozen_binary_fallback in place. They are
# exported below too, and used ONLY as a degrade path in the run-loop if the
# pinned generation directory itself is ever reaped out from under a
# still-open session (R4's "missing pinned version" case) — never for
# ordinary layout re-resolution.
frozen_binary_primary_stable="$frozen_binary_primary"
frozen_binary_fallback_stable="$frozen_binary_fallback"
frozen_binary_primary="$(_aicli_pin_to_real_generation "$frozen_binary_primary")"
frozen_binary_fallback="$(_aicli_pin_to_real_generation "$frozen_binary_fallback")"
frozen_binary="$(_aicli_pin_to_real_generation "$BINARY")"
# The pinned generation directory itself (empty when this agent has no
# resolvable stable/real split, e.g. "terminal" or an uninstalled agent) —
# the run-loop's degrade check below tests this directory's continued
# existence, not the individual binary files, so a mid-generation shape
# change (cli.js -> bin/claude.exe) is never mistaken for the whole
# generation vanishing.
frozen_agent_generation_dir="$_AICLI_AGENT_REAL_DIR"
# The stable dir too, so the run-loop's degrade check can tell "this agent was
# never installed / has no versioned split" (generation dir == stable dir,
# always the case pre-migration) apart from "the pinned generation was reaped
# out from under a live session" (generation dir != stable dir, and the
# generation dir is gone) — only the latter should log R4's degrade WARN.
frozen_agent_stable_dir="$_AICLI_AGENT_STABLE_DIR"
frozen_resume_cmd="$RESUME_CMD"
frozen_resume_latest="$RESUME_LATEST"
frozen_agent_name="${AGENT_NAME:-$AGENT_ID}"
frozen_chat_id="$AICLI_CHAT_SESSION_ID"
frozen_env_prefix="$ENV_PREFIX"
# Plugin-level default flags for this agent (e.g. codex sandbox overrides).
# These are NOT user-editable workspace args — they are always prepended to the
# launch command and survive fresh + resume + resume-latest paths.
frozen_plugin_args="${PLUGIN_ARGS:-}"

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

# Kimi Code defaults to replacing its own executable during update checks.
# The plugin owns agent versions, so enforce that one vendor setting while
# preserving every unrelated user setting in tui.toml.
_aicli_set_toml_bool() {
    local file="$1" section="$2" key="$3" value="$4"
    local dir tmp
    dir=$(dirname "$file")
    mkdir -p "$dir" 2>/dev/null || return 1
    tmp="${file}.aicli.$$"
    if [ ! -f "$file" ] || ! grep -Eq "^[[:space:]]*\\[${section}\\][[:space:]]*$" "$file"; then
        [ -s "$file" ] && printf '\n' >> "$file"
        printf '[%s]\n%s = %s\n' "$section" "$key" "$value" >> "$file"
        return 0
    fi
    awk -v section="$section" -v key="$key" -v value="$value" '
        BEGIN { inside=0; written=0 }
        /^\[[^]]+\][[:space:]]*$/ {
            if (inside && !written) { print key " = " value; written=1 }
            inside=($0 == "[" section "]")
        }
        inside && $0 ~ "^[[:space:]]*" key "[[:space:]]*=" {
            if (!written) print key " = " value
            written=1
            next
        }
        { print }
        END { if (inside && !written) print key " = " value }
    ' "$file" > "$tmp" && mv -f "$tmp" "$file"
}

if [ "$AGENT_ID" = "kimi-code" ]; then
    _aicli_set_toml_bool "$HOME_DIR/.kimi-code/tui.toml" upgrade auto_install false || \
        log_aicli "WARN" 1 "Could not disable Kimi Code self-update; plugin-managed version may drift"
fi
mkdir -p "$HOME" 2>/dev/null
# #110: WORK_CWD is where the agent RUNS (the pool path when the operator opted in
# for a cache-only share); ROOT_DIR stays the workspace identity for hashes/resume.
WORK_CWD="${AICLI_CWD:-$ROOT_DIR}"
[ -d "$WORK_CWD" ] || WORK_CWD="$ROOT_DIR"
cd "$WORK_CWD" || cd "$ROOT_DIR" || cd /mnt || echo "Warning: Could not enter $WORK_CWD"
export PATH="$HOME/.local/bin:/usr/local/emhttp/plugins/unraid-aicliagents/bin:$PATH"
export TERM=xterm-256color
export COLORTERM=truecolor
export LANG=en_US.UTF-8
export LC_ALL=en_US.UTF-8

# Bundled terminfo: Unraid ships a minimal terminfo db (xterm* only) with no
# tmux/tmux-256color entry, so default-terminal=tmux-256color would fail with
# "missing or unsuitable terminal". Compile our bundled source once into a tmpfs
# dir and expose it via TERMINFO_DIRS (trailing ':' keeps the system db, where
# xterm-256color lives). Guarded: only if tic exists and it isn't already built.
_AICLI_TI_DIR="/tmp/unraid-aicliagents/terminfo"
_AICLI_TI_SRC="$PLUGIN_SRC/terminfo/tmux.terminfo"
if [ ! -e "$_AICLI_TI_DIR/t/tmux-256color" ] && command -v tic >/dev/null 2>&1 && [ -f "$_AICLI_TI_SRC" ]; then
    mkdir -p "$_AICLI_TI_DIR" 2>/dev/null
    if tic -x -o "$_AICLI_TI_DIR" "$_AICLI_TI_SRC" >/dev/null 2>&1; then
        log_aicli "DEBUG" 3 "compiled bundled tmux terminfo -> $_AICLI_TI_DIR"
    else
        log_aicli "WARN" 1 "tic failed to compile bundled tmux terminfo (tmux-256color unavailable)"
    fi
fi
[ -d "$_AICLI_TI_DIR" ] && export TERMINFO_DIRS="${_AICLI_TI_DIR}:${TERMINFO_DIRS:-}"

# Default tmux TERM type: prefer tmux-256color (bundled terminfo — enables
# italics) when it actually resolves; fall back to the always-present
# xterm-256color if the terminfo couldn't be compiled (e.g. tic missing). This
# is the built-in default for new/uncustomised agents; a user override via
# apply-tmux-json still wins. Mirrors TmuxService::BUILTIN['default-terminal']
# (the parity test treats this key's value as runtime-variable, like history-limit).
_AICLI_DEFTERM="xterm-256color"
infocmp tmux-256color >/dev/null 2>&1 && _AICLI_DEFTERM="tmux-256color"

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
# R-B1: in --ensure-session (headless) mode this wrapper process exits the moment
# the DETACHED tmux session is created. We must NOT run trap_exit then — cleanup()
# kills the very session we just spawned (the agent would die headless and the
# orphan-sweep would later reap the ttyd). The trap is only meaningful for the
# interactive attach path, where the wrapper lives for the duration of the client.
if [ "$AICLI_ENSURE_ONLY" -eq 0 ]; then
    trap trap_exit EXIT
fi

# Bug #1043: point tmux at a plugin-private socket directory instead of the
# shared /tmp/tmux-<uid>. Other processes on the box (a user's own SSH tmux, a
# container bind-mounting /tmp, another plugin) can leave /tmp/tmux-<uid> with
# permissions tmux rejects ("unsafe permissions"), and the terminal then fails
# with "Terminal session not found". /tmp/unraid-aicliagents/tmux is the
# plugin's alone, so tmux's tmux-<uid> dir inside it cannot be corrupted from
# outside. Exported so the tmux server and every child inherit the same socket
# location; every other plugin script that calls tmux sets it identically.
# Bug #141: ONE tmux server per session, not one shared by the whole fleet.
# The shared server inherited the AICLI_SESSION_ID of whichever session forked
# it, and tmux copies its environment into every session created afterwards —
# so gracefulClose's environment-matched reap SIGKILLed the shared server and
# every other workspace died with it (twice on 2026-08-11). A per-session
# TMUX_TMPDIR gives each session a private server, so a close can only ever
# reach its own processes. Setting TMUX_TMPDIR (rather than passing -S) means
# every bare `tmux` call in this script, in RUN_SCRIPT, and in any child
# automatically targets the right server with no call-site changes.
#
# AICLI_TMUX_ROOT stays shared: PHP writes per-agent quirk files there, and
# socket discovery (ProcessManager::tmuxSocketPaths) scans it for both layouts.
export AICLI_TMUX_ROOT="/tmp/unraid-aicliagents/tmux"
mkdir -p "$AICLI_TMUX_ROOT" 2>/dev/null
# Sticky world-writable (like /tmp) so multiple agent uids can each create their
# own subtree but cannot delete or replace another uid's.
chmod 1777 "$AICLI_TMUX_ROOT" 2>/dev/null

# Keyed on the session id ONLY (not the full session name), so it matches
# ProcessManager::sessionTmuxTmpdir() exactly — TmuxSocketLayoutTest pins both.
export TMUX_TMPDIR="$AICLI_TMUX_ROOT/s-$ID"
mkdir -p "$TMUX_TMPDIR" 2>/dev/null
# Owner-only: this directory belongs to exactly one session's server.
chmod 0700 "$TMUX_TMPDIR" 2>/dev/null

# An inherited $TMUX PINS every tmux call to the server named in it and silently
# overrides TMUX_TMPDIR — which would put this session back on a shared server
# and re-open the exact cross-workspace kill this change exists to prevent. The
# normal launch path (php-fpm → ttyd → runuser) has no $TMUX, but any invocation
# from inside a pane does, and that failure is silent. Drop it unconditionally;
# nothing in this script reads $TMUX.
unset TMUX TMUX_PANE

# ADOPT an existing server before creating one here.
#
# The socket location moved in v2026.08.11.02 (shared -> per-session). A session
# launched by an older generation therefore lives somewhere this script would
# not look, and every existence check below ("does my session exist?") consults
# only $TMUX_TMPDIR. The result is a FORK: the script sees nothing on the
# per-session socket, starts a second agent there, and the real one is orphaned
# on the old socket, still running, invisible to the browser.
#
# That happened for real on 2026-08-11: reconnecting one workspace onto a newer
# generation produced two tmux sessions of the same name — the original agent
# from 10:47 stranded on the shared socket, and a fresh empty agent on the
# per-session socket that the browser attached to.
#
# So: look for this session on EVERY plugin socket first and adopt the server
# that already has it. Only when it exists nowhere do we use the per-session
# default set above. Ordering matters — the per-session location is preferred,
# so a session already migrated is never dragged back to the shared server.
adopt_existing_session_socket() {
    local uid sock dir
    uid="$(id -u)"
    for sock in "$AICLI_TMUX_ROOT/s-$ID/tmux-$uid/default" \
                "$AICLI_TMUX_ROOT"/s-*/tmux-"$uid"/default \
                "$AICLI_TMUX_ROOT/tmux-$uid/default"; do
        [ -S "$sock" ] || continue
        tmux -S "$sock" has-session -t "$SESSION" 2>/dev/null || continue
        # TMUX_TMPDIR is the grandparent of the socket (<dir>/tmux-<uid>/default).
        dir="$(dirname "$(dirname "$sock")")"
        if [ "$dir" != "$TMUX_TMPDIR" ]; then
            export TMUX_TMPDIR="$dir"
            echo "[$(date '+%Y-%m-%d %H:%M:%S')] [ADOPT-SOCKET] sess=$SESSION adopted existing server at $sock (TMUX_TMPDIR=$dir)" >> "$DEBUG_LOG"
        fi
        return 0
    done
    return 0
}
adopt_existing_session_socket

# Bug #1054 follow-up: tmux 3.4+ hard-refuses its per-uid socket dir unless it is
# mode 0700 AND owned by the running uid — it aborts new-session with
# "directory <TMUX_TMPDIR>/tmux-<uid> has unsafe permissions", which surfaces to
# the user as "Terminal session not found". The world-writable parent lets another
# uid create tmux-<uid> first, and an overlay/tmpfs restore can bring the dir back
# with loose perms. Force it ours-and-0700 before tmux ever looks at it; if it is
# owned by a uid we cannot chown and is still not 0700, remove it so tmux recreates
# it cleanly (a directory tmux rejects can hold no live server anyway).
sanitize_tmux_uid_dir() {
    local dir="$TMUX_TMPDIR/tmux-$(id -u)"
    [ -e "$dir" ] || return 0
    chown "$(id -u):$(id -g)" "$dir" 2>/dev/null
    chmod 0700 "$dir" 2>/dev/null
    local perm own
    perm="$(stat -c '%a' "$dir" 2>/dev/null)"
    own="$(stat -c '%u' "$dir" 2>/dev/null)"
    if [ "$perm" != "700" ] || [ "$own" != "$(id -u)" ]; then
        rm -rf "$dir" 2>/dev/null
    fi
}
sanitize_tmux_uid_dir

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
    printf 'export PLUGIN_SRC=%q\n' "$PLUGIN_SRC" >> "$RUN_SCRIPT"
    
    # Inject logging function into the child script for tmux diagnostics
    cat << 'FUNCOEF' >> "$RUN_SCRIPT"
log_aicli() {
    local level_name="$1"
    local level_val="$2"
    local msg="$3"
    local bridge="$PLUGIN_SRC/scripts/log-bridge.php"
    local debug_log="/tmp/unraid-aicliagents/debug.log"

    if [ ! -f "$bridge" ]; then
        echo "[$(date "+%Y-%m-%d %H:%M:%S")] [SHELL-CHILD] $msg" >> "$debug_log" 2>/dev/null
        return
    fi
    php "$bridge" log "$msg" "$level_val" "SHELL-CHILD" 2>/dev/null
}
perf_log() {
    # Optional $2 is appended as a free-form extra field (issue #71 uses it for
    # `run.exit ... rc=<code>`). AICLI_PERF_LOG override exists for unit tests
    # only — production always writes the shared perf.log.
    printf '%s %s %s %s%s\n' "$(date +%s%N | cut -c1-13)" "$1" "$AGENT_ID" "$AICLI_SESSION_ID" "${2:+ $2}" >> "${AICLI_PERF_LOG:-/tmp/unraid-aicliagents/perf.log}" 2>/dev/null
}
FUNCOEF

    printf 'export AICLI_SESSION_ID=%q\n' "$ID" >> "$RUN_SCRIPT"
    # Relay's MCP child is launched by the agent from this generated script,
    # not from the original ttyd command. Preserve the launch-bound command
    # here so reconnects and shell relaunches retain the complete Relay context.
    # Pin Relay to this immutable launcher generation. A later hot-swap may
    # advance the mutable src link while this workspace is still running.
    printf 'export AICLI_RELAY_COMMAND=%q\n' "${AICLI_RELAY_COMMAND:-php $PLUGIN_SRC/scripts/relay-agent.php}" >> "$RUN_SCRIPT"
    # Plugin management (read-only) self-service, pinned the same way and for the
    # same reason. This is the fallback for an agent with no MCP support — pi-coder
    # is HubProjector::MCP_EXEMPT and will never read an MCP config file — and it is
    # also what a user's own script calls. Spec: docs/specs/PLUGIN_MANAGEMENT_TOOLS.md
    printf 'export AICLI_ADMIN_COMMAND=%q\n' "${AICLI_ADMIN_COMMAND:-php $PLUGIN_SRC/scripts/admin-agent.php}" >> "$RUN_SCRIPT"
    # (Relay 'watch' mode retired 2026-08-22: every agent now receives the
    # readiness-gated paste, so no per-session watcher command is injected here.
    # The old watcher env var + relay-watch.sh were removed — the watcher died on
    # every resume and duplicated the paste. History in git.)
    # Bind the remaining identity variables to THIS session. Without these two the
    # run script never sets them, so they arrive only by inheritance — and a tmux
    # session started later picks up whatever the tmux server was launched with,
    # i.e. the first workspace to start. A Codex workspace in saas-businessOS was
    # observed running with AICLI_ROOT=/mnt/user/DevelopmentProjects/mvp-dmoe and
    # AICLI_CHAT_SESSION_ID=DMoE. AICLI_ROOT is used as the cd fallback below, so a
    # leaked value lands the agent in another workspace's directory. Issue #124.
    printf 'export AICLI_ROOT=%q\n' "${AICLI_ROOT:-/mnt}" >> "$RUN_SCRIPT"
    printf 'export AICLI_CHAT_SESSION_ID=%q\n' "$frozen_chat_id" >> "$RUN_SCRIPT"
    printf 'export AGENT_ID=%q\n' "$AGENT_ID" >> "$RUN_SCRIPT"
    printf 'export HOME=%q\n' "$HOME_DIR" >> "$RUN_SCRIPT"

    # Bug #1042: bring up the per-user Secret Service (org.freedesktop.secrets)
    # so keyring-using agents — Antigravity CLI today — can persist their auth
    # token instead of re-prompting every session. Idempotent and reused per
    # user; best-effort — on failure the agent just falls back to interactive
    # auth. See docs/specs/SECRET_SERVICE.md.
    _SS_ADDR=$(bash "$PLUGIN_SRC/secret-service/secret-service-up.sh" "$USER_NAME" "$HOME_DIR" 2>>"$DEBUG_LOG")
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
    # SIDE_BY_SIDE_AGENT_INSTALLS.md Phase 3: export the un-pinned stable paths
    # and the pinned generation directory too, so the run-loop's degrade check
    # (R4) can fall back to the stable name if the pinned generation is ever
    # reaped out from under this session.
    printf 'export frozen_binary_primary_stable=%q\n' "$frozen_binary_primary_stable" >> "$RUN_SCRIPT"
    printf 'export frozen_binary_fallback_stable=%q\n' "$frozen_binary_fallback_stable" >> "$RUN_SCRIPT"
    printf 'export frozen_agent_generation_dir=%q\n' "$frozen_agent_generation_dir" >> "$RUN_SCRIPT"
    printf 'export frozen_agent_stable_dir=%q\n' "$frozen_agent_stable_dir" >> "$RUN_SCRIPT"
    printf 'export frozen_resume_cmd=%q\n' "$frozen_resume_cmd" >> "$RUN_SCRIPT"
    printf 'export frozen_resume_latest=%q\n' "$frozen_resume_latest" >> "$RUN_SCRIPT"
    printf 'export frozen_plugin_args=%q\n' "$frozen_plugin_args" >> "$RUN_SCRIPT"
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
    
    # D-204: Pass Node Memory limits to the child script.
    # Issue #71: claude-code gets an 8 GB old-space default — multi-subagent
    # sessions inside one claude process are the suspected JS-heap OOM behind
    # the 2026-07-16/17 unexplained process deaths, and the host has 62 GB.
    # Other agents keep the 4 GB default. AICLI_NODE_MEMORY still overrides
    # either default when a caller sets it; NODE_OPTIONS itself is a RESERVED
    # key in EnvService, so the 5-tier user env merge can never clobber this.
    if [ "$AGENT_ID" = "claude-code" ]; then
        MEM_LIMIT="${AICLI_NODE_MEMORY:-8192}"
    else
        MEM_LIMIT="${AICLI_NODE_MEMORY:-4096}"
    fi
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
    # effective-env-export.php invokes EnvService::buildEffectiveEnv for both
    # this initial export and the hot-reload function embedded below.
    # Tier order, later wins per key:
    #   source.env < secrets.cfg < workspace secrets < agent envs < workspace envs
    # Reserved names (PATH, HOME, AICLI_*, frozen_*, …) are filtered out so a
    # user setting can't clobber plugin-internal state. Closes the latent
    # dead-write gap where secrets.cfg was never sourced at launch.
    EXPORTED_KEYS_FILE="$HOME_DIR/.aicli/.exported_keys_${ENV_HASH}"
    mkdir -p "$(dirname "$EXPORTED_KEYS_FILE")" 2>/dev/null
    php "$PLUGIN_SRC/scripts/user/effective-env-export.php" \
        "$PLUGIN_SRC/includes/AICliAgentsManager.php" startup \
        "$ROOT_DIR" "$AGENT_ID" "$EXPORTED_KEYS_FILE" >> "$RUN_SCRIPT" 2>/dev/null
    log_aicli "INFO" 2 "Injected effective env (5-tier merge via EnvService)"

    # Export the tracker path + workspace path so the run-loop's
    # _aicli_load_envs can hot-apply changes without needing to recompute
    # them. Per WP #736 hot-apply.
    printf 'export AICLI_WORKSPACE_PATH=%q\n' "$ROOT_DIR" >> "$RUN_SCRIPT"
    printf 'export AICLI_CWD=%q\n' "${WORK_CWD:-$ROOT_DIR}" >> "$RUN_SCRIPT"
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
# Ensure the agent (and every child) runs in the WORKSPACE, not the tmux server's
# cwd. `new-session -c "$ROOT_DIR"` already pins the pane dir; this is a
# self-contained belt-and-braces guard so the agent process is in the workspace
# even if a Tier-4 user .conf overrides tmux options. AICLI_WORKSPACE_PATH is
# exported just above (= the resolved workspace root).
cd "${AICLI_CWD:-$AICLI_WORKSPACE_PATH}" 2>/dev/null || cd "$AICLI_WORKSPACE_PATH" 2>/dev/null || cd "${AICLI_ROOT:-/mnt}" 2>/dev/null || true
# WP #1259: non-interactive defaults for the agent shell + every child it spawns.
# A TUI agent (notably agy/antigravity) runs child commands with NO controlling
# TTY and does NOT forward keystrokes to them -- a regression from gemini-cli's
# node-pty shell, which gave children a PTY and relayed input. So any child that
# opens an editor or pager, or waits on a y/N prompt, HANGS forever: the prompt
# re-renders but can never be answered. (Verified on .4: ssh will read a piped
# password fine even with no TTY -- the block is that agy sends the child no
# input at all.) These defaults make the common offenders non-interactive so they
# COMPLETE instead of hanging. Each is a DEFAULT (:-) so an explicit value from
# the 5-tier env (applied later by _aicli_load_envs) still wins. NOTE: ssh/sudo
# *password* prompts can't be fixed here -- use key-based ssh; sudo is moot since
# the agent already runs as root. Harmless to gemini-cli (it forwards input, and
# the :- defaults respect any editor/pager it was configured with).
export GIT_PAGER="${GIT_PAGER:-cat}"
export PAGER="${PAGER:-cat}"
export SYSTEMD_PAGER="${SYSTEMD_PAGER:-}"
export LESS="${LESS:-FRX}"
export GIT_EDITOR="${GIT_EDITOR:-true}"
export GIT_SEQUENCE_EDITOR="${GIT_SEQUENCE_EDITOR:-true}"
export EDITOR="${EDITOR:-true}"
export VISUAL="${VISUAL:-true}"
export GIT_TERMINAL_PROMPT="${GIT_TERMINAL_PROMPT:-0}"
export GIT_SSH_COMMAND="${GIT_SSH_COMMAND:-ssh -o BatchMode=yes}"
export DEBIAN_FRONTEND="${DEBIAN_FRONTEND:-noninteractive}"
echo "[$(date '+%Y-%m-%d %H:%M:%S')] [DIAG-1054-B] before stty sane" >> /tmp/unraid-aicliagents/debug.log
stty sane 2>/dev/null
echo "[$(date '+%Y-%m-%d %H:%M:%S')] [DIAG-1054-C] after stty sane (rc=$?)" >> /tmp/unraid-aicliagents/debug.log
perf_log run.start
echo "[$(date '+%Y-%m-%d %H:%M:%S')] [DIAG-1054-D] after perf_log run.start" >> /tmp/unraid-aicliagents/debug.log

# WP #1253: keep opost+onlcr asserted on this pane for the life of the session.
# A TUI agent (e.g. agy) cfmakeraw's the pane (turning opost OFF) for its own UI.
# Any INTERACTIVE child it then spawns (an ssh password prompt, sudo, etc.)
# inherits that raw pane and emits bare LF without CR; tmux emulates the bare LF
# into a diagonal "staircase" (the cursor drops a row but never returns to col 0).
# Re-asserting opost+onlcr makes the kernel translate LF->CRLF before tmux reads
# it, so the staircase can never form. This is harmless to the agent's own UI:
# its explicit \r\n just gains a redundant (no-op) CR, and cursor-positioning
# escape sequences are unaffected by opost. A low-rate keeper is required because
# the agent re-raws the pane on (re)launch and on resize; it self-terminates when
# this run-script (the session) exits. Confirmed live on .4 (WP #1253).
_AICLI_PANE_TTY="$(tty 2>/dev/null)"
case "$_AICLI_PANE_TTY" in
    /dev/*)
        ( _rs_pid=$$
          while kill -0 "$_rs_pid" 2>/dev/null; do
              stty -F "$_AICLI_PANE_TTY" opost onlcr 2>/dev/null
              sleep 0.5
          done ) >/dev/null 2>&1 &
        ;;
esac

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

# Pure helper: assemble the fresh-launch command from parts.
# plugin_args is appended AFTER user effective_args so codex's last-`-c`-wins
# makes the plugin sandbox_mode override any user workspace arg.
# Arguments: binary effective_args plugin_args
# Outputs the assembled command string to stdout.
_build_fresh_cmd() {
    local _binary="$1" _eff="$2" _plugin="$3"
    local _cmd="$_binary"
    [ -n "$_eff" ]    && _cmd="$_cmd $_eff"
    [ -n "$_plugin" ] && _cmd="$_cmd $_plugin"
    printf '%s' "$_cmd"
}

# Return the newest Claude conversation id for THIS workspace only. Claude's
# modern session store is partitioned by its dasherised cwd under
# ~/.claude/projects; scanning all projects can select another workspace's chat
# and turn the subsequent failed resume into a blank conversation.
_aicli_discover_claude_chat() {
    [ "$AGENT_ID" = "claude-code" ] || return 0
    [ -n "$AICLI_WORKSPACE_PATH" ] || return 0

    local _project_key _project_dir _newest_jsonl
    _project_key="-$(printf '%s' "${AICLI_WORKSPACE_PATH#/}" | tr '/' '-')"
    _project_dir="$HOME_DIR/.claude/projects/$_project_key"
    [ -d "$_project_dir" ] || return 0

    # Issue #71: exclude subagent transcripts (`agent-<id>.jsonl`). They are
    # not resumable conversations — resuming from one opens the wrong
    # transcript. Only main-session GUIDs may be discovered.
    _newest_jsonl=$(find "$_project_dir" -maxdepth 1 -name '*.jsonl' ! -name 'agent-*' -type f \
        -printf '%T@ %p\n' 2>/dev/null | sort -rn | head -1 | cut -d' ' -f2-)
    [ -n "$_newest_jsonl" ] && basename "$_newest_jsonl" .jsonl
}

# Refresh the in-memory and durable resume ids after Claude has had a chance to
# write its active conversation. This must run before an automatic relaunch so
# /resume switches and a newly-created fresh chat both resume the correct
# workspace conversation.
_aicli_sync_claude_chat() {
    [ "$AGENT_ID" = "claude-code" ] || return 0
    [ -n "$AICLI_ENV_HASH" ] || return 0

    local _discovered_chat _resume_dir _resume_file
    _discovered_chat=$(_aicli_discover_claude_chat)
    if [ -n "$_discovered_chat" ] && [ "$_discovered_chat" != "$frozen_chat_id" ]; then
        log_aicli "INFO" 2 "Workspace-scoped Claude GUID sync: $frozen_chat_id -> $_discovered_chat"
        frozen_chat_id="$_discovered_chat"
        _resume_dir="$HOME_DIR/.aicli/resumes"
        _resume_file="${_resume_dir}/resume_${AICLI_ENV_HASH}.json"
        mkdir -p "$_resume_dir" 2>/dev/null
        printf '{"chat_id":"%s","saved_at":%d}\n' "$_discovered_chat" "$(date +%s)" > "${_resume_file}.tmp" \
            && mv "${_resume_file}.tmp" "$_resume_file" 2>/dev/null
    fi
}

# Preserve the actual process result before deciding whether anything should be
# relaunched. Bash encodes signal termination as 128+signal; retain both forms
# and the runtime so diagnostics can distinguish startup rejection from a crash
# or kill after a healthy, long-running session.
_aicli_record_agent_exit() {
    local _attempt="$1" _rc="$2" _started="$3"
    local _dynamic_recorder="$PLUGIN_SRC/scripts/user/agent-exit-recorder.sh"
    if [ -f "$_dynamic_recorder" ]; then
        unset -f aicli_dynamic_record_agent_exit 2>/dev/null || true
        # shellcheck source=/dev/null -- release symlink intentionally resolves at call time
        source "$_dynamic_recorder"
        if declare -F aicli_dynamic_record_agent_exit >/dev/null 2>&1; then
            aicli_dynamic_record_agent_exit "$_attempt" "$_rc" "$_started"
            return
        fi
    fi
    local _ended _termination="exit"
    _ended=$(date +%s)
    last_launch_duration=$((_ended - _started))
    last_launch_rc="$_rc"

    if [ "$_rc" -ge 129 ] && [ "$_rc" -le 192 ]; then
        local _signal=$((_rc - 128)) _signal_name
        _signal_name=$(kill -l "$_signal" 2>/dev/null || printf 'UNKNOWN')
        _termination="signal ${_signal} (${_signal_name})"
    fi
    log_aicli "INFO" 2 "Agent process ended: attempt=$_attempt rc=$_rc termination=$_termination duration=${last_launch_duration}s"
    # Issue #71: every child exit also emits a machine-readable perf event
    # (`<epoch_ms> run.exit <agent> <session> rc=<code>`) so unexplained agent
    # deaths (2026-07-17: two organic claude-code exits under heavy subagent
    # load) can be correlated without parsing debug.log prose. A non-zero exit
    # additionally snapshots crash forensics BEFORE the relaunch loop clears
    # the pane.
    perf_log run.exit "rc=$_rc"
    if [ "$_rc" -ne 0 ]; then
        _aicli_capture_crash "$_attempt" "$_rc"
    fi
}

# Issue #71: crash forensics. The agent's stderr deliberately flows to the pane
# TTY (see safe_exec / D-400 — redirecting stderr to a file breaks TUI isatty
# probes and is NOT an option), so on an unexplained exit the pane scrollback
# IS the stderr record. Snapshot the last ~80 pane lines plus launch context
# into /tmp/unraid-aicliagents/crash-<agent>-<session>-<timestamp>.log before
# the relaunch loop repaints the screen. Bounded: only the 10 newest crash
# files per agent are kept. AICLI_CRASH_DIR override exists for unit tests.
_aicli_capture_crash() {
    local _attempt="$1" _rc="$2"
    local _dir="${AICLI_CRASH_DIR:-/tmp/unraid-aicliagents}"
    local _crash_file="$_dir/crash-${AGENT_ID}-${AICLI_SESSION_ID}-$(date +%Y%m%d-%H%M%S).log"
    {
        printf 'agent=%s session=%s attempt=%s rc=%s captured_at=%s\n' \
            "$AGENT_ID" "$AICLI_SESSION_ID" "$_attempt" "$_rc" "$(date '+%Y-%m-%d %H:%M:%S')"
        printf 'node_options=%s\n' "${NODE_OPTIONS:-<unset>}"
        printf -- '---- pane tail (last 80 lines) ----\n'
        tmux capture-pane -p -S -80 2>/dev/null || printf '(tmux pane capture unavailable)\n'
    } > "$_crash_file" 2>/dev/null
    log_aicli "WARN" 1 "Crash forensics captured: $_crash_file (attempt=$_attempt rc=$_rc)"
    # Prune: keep only the 10 newest crash files for this agent.
    ls -1t "$_dir"/crash-"${AGENT_ID}"-*.log 2>/dev/null | tail -n +11 | while IFS= read -r _old; do
        rm -f "$_old" 2>/dev/null
    done
}

# A resume process that survives the existing fast-exit window reached its TUI
# lifecycle; a later non-zero exit must not be reclassified as startup failure.
_aicli_resume_was_established() {
    [ "$1" -ge 3 ]
}

# WP #736 hot-apply: re-read the 5-tier effective env via EnvService on every
# loop iteration. Diffs against $AICLI_EXPORTED_KEYS_FILE: unsets any key we
# previously exported but is no longer present; re-exports the current set.
# Single source of truth shared with the startup path and TerminalService.
# Single PHP invocation per iteration (~50 ms — negligible against agent
# startup cost).
_aicli_load_envs() {
    local script="/tmp/aicli-envreload-$$-$RANDOM.sh"
    php "$PLUGIN_SRC/scripts/user/effective-env-export.php" \
        "$PLUGIN_SRC/includes/AICliAgentsManager.php" reload \
        "$AICLI_WORKSPACE_PATH" "$AGENT_ID" > "$script" 2>/dev/null

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

# #37: consecutive immediate-crash counter. A broken/incompatible agent binary
# exits in <3s every launch (opencode rc=1 on Tower). After a few in a row we
# escalate the message from "missing configuration" to "binary is broken, upgrade
# it" so the operator gets an actionable diagnosis instead of a confusing loop of
# retry prompts. Reset on any launch that survived >=3s.
consecutive_fast_crashes=0

while true; do
    # Graceful-close sentinel — TOP-of-loop re-check (close-race fix 2026-06-07).
    # gracefulClose keeps this session alive (so it can scrape the exit screen for a
    # resume id, ~4s) and only THEN touches the flag + sends Enter to wake the
    # post-exit "Press ENTER to reload" read below. A fast-exiting agent (a fresh
    # claude exits instantly on Ctrl-C) has already passed the post-exit flag check
    # and is parked on that read; waking it returns HERE. Without this check we would
    # re-exec the agent — relaunching a workspace the user just closed and forcing
    # gracefulClose into its 3s hard-stop fallback. Checking the flag before the
    # (re)launch breaks cleanly while still preserving the scrape window.
    if [ -f "/tmp/unraid-aicliagents/close-$AICLI_SESSION_ID.flag" ]; then
        rm -f "/tmp/unraid-aicliagents/close-$AICLI_SESSION_ID.flag" 2>/dev/null
        log_aicli "INFO" 2 "Graceful close sentinel observed at loop top — exiting before relaunch."
        break
    fi
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

    # #144: OpenCode single-instance DB safety + per-workspace isolation.
    # OpenCode keeps ONE sqlite DB per data dir; two server processes writing it at
    # once (Reconnect / generation-drift double-spawn, or two OpenCode workspaces)
    # corrupt it ("database disk image is malformed"). Three defences below:
    #   C) isolate the DB per workspace so distinct workspaces never share a store;
    #   A) before launching, gracefully stop any OTHER process still holding THIS
    #      workspace's DB (so it flushes its working copy back to disk first);
    #   B) only then run the D-52 WAL/SHM cleanup — provably single-instance, so it
    #      can never delete a live WAL out from under a running server.
    # See docs/specs/OPENCODE_DB_SINGLE_INSTANCE.md.
    if [[ "$AGENT_ID" == "opencode" ]]; then
       # --- C: per-workspace DB isolation (XDG_DATA_HOME relocates opencode.db;
       #        config/plugins/relay stay in ~/.config/opencode, untouched). ---
       # The store is keyed by the WORKSPACE PATH (per user), not the session id:
       # a workspace that is closed and re-added gets a NEW session id, so the
       # session-keyed dir of #144 started from the stale shared seed and the
       # `-s <chatId>` resume failed with "Session not found" (2026-09-07). The
       # first launch under the path key ADOPTS the session-keyed store that holds
       # the resume id (or, without one, this workspace's most recent store).
       _oc_ws_path="${_oc_ws_path:-$PWD}"
       _oc_ws_key="ws-$(printf '%s:%s' "${USER_NAME:-${AICLI_USER:-$(id -un)}}" "$_oc_ws_path" | sha1sum | cut -c1-16)"
       _oc_root="$HOME_DIR/.local/share/aicli-opencode"
       export XDG_DATA_HOME="$_oc_root/$_oc_ws_key"
       _oc_data="$XDG_DATA_HOME/opencode"
       _oc_legacy="$HOME_DIR/.local/share/opencode"
       db_file="$_oc_data/opencode.db"
       mkdir -p "$_oc_data" 2>/dev/null

       # Adopt a legacy session-keyed store for THIS workspace (exactly once: only
       # while the path-keyed DB does not exist). Preference: the newest store whose
       # DB holds the session we are about to resume; else the newest store whose DB
       # names this workspace directory. Moved when idle, copied when a live process
       # still holds it (never yank a DB out from under a running server).
       if [ ! -f "$db_file" ] && [ -d "$_oc_root" ]; then
           _oc_pick=""; _oc_pick_why=""; _oc_pick_mtime=0
           for _cand in "$_oc_root"/*/opencode/opencode.db; do
               [ -f "$_cand" ] || continue
               case "$_cand" in "$_oc_root/ws-"*) continue ;; esac
               _why=""
               if [ -n "${frozen_chat_id:-}" ] && grep -q -a -F -- "$frozen_chat_id" "$_cand" 2>/dev/null; then
                   _why="resume-id"
               elif [ -z "${frozen_chat_id:-}" ] && grep -q -a -F -- "$_oc_ws_path" "$_cand" 2>/dev/null; then
                   _why="workspace-path"
               fi
               [ -n "$_why" ] || continue
               _m=$(stat -c %Y "$_cand" 2>/dev/null || echo 0)
               if [ "$_m" -gt "$_oc_pick_mtime" ]; then _oc_pick="$_cand"; _oc_pick_why="$_why"; _oc_pick_mtime="$_m"; fi
           done
           if [ -n "$_oc_pick" ]; then
               _oc_src_dir="$(dirname "$(dirname "$_oc_pick")")"
               _oc_src_sid="$(basename "$_oc_src_dir")"
               _held=""
               for _p in $(pgrep -f 'opencode-ai/bin/opencode.exe' 2>/dev/null); do
                   [ "$_p" = "$$" ] && continue
                   for _l in /proc/"$_p"/fd/*; do
                       _t=$(readlink "$_l" 2>/dev/null) || continue
                       case "${_t% (deleted)}" in "$_oc_pick"|"$_oc_pick-wal") _held="$_p"; break 2 ;; esac
                   done
               done
               rmdir "$_oc_data" 2>/dev/null; rmdir "$XDG_DATA_HOME" 2>/dev/null
               if [ -z "$_held" ] && mv "$_oc_src_dir" "$XDG_DATA_HOME" 2>/dev/null; then
                   log_aicli "INFO" 1 "OpenCode #144: adopted store $_oc_src_sid -> $_oc_ws_key for $_oc_ws_path (moved; reason=$_oc_pick_why)"
               else
                   mkdir -p "$XDG_DATA_HOME" 2>/dev/null
                   cp -a "$_oc_src_dir/." "$XDG_DATA_HOME/" 2>/dev/null
                   rm -f "$db_file-wal" "$db_file-shm" 2>/dev/null
                   log_aicli "INFO" 1 "OpenCode #144: adopted store $_oc_src_sid -> $_oc_ws_key for $_oc_ws_path (copied; held by pid ${_held:-?}; reason=$_oc_pick_why)"
               fi
               mkdir -p "$_oc_data" 2>/dev/null
               touch "$_oc_data/.aicli-migrated" 2>/dev/null
           fi
       fi

       # One-time migration: seed this workspace's store from the legacy shared DB
       # so existing history carries over. Sentinel + non-blocking flock => exactly
       # once, never mid-write. OpenCode namespaces sessions by project internally,
       # so carrying the whole DB is safe (unrelated rows are inert).
       if [ ! -f "$_oc_data/.aicli-migrated" ] && [ ! -f "$db_file" ] && [ -f "$_oc_legacy/opencode.db" ]; then
           (
               if flock -n 9; then
                   log_aicli "INFO" 2 "OpenCode #144: migrating shared DB -> per-workspace store ($AICLI_SESSION_ID)"
                   cp -a "$_oc_legacy/opencode.db" "$db_file" 2>/dev/null
                   [ -d "$_oc_legacy/snapshot" ] && cp -a "$_oc_legacy/snapshot" "$_oc_data/" 2>/dev/null
                   [ -d "$_oc_legacy/repos" ]    && cp -a "$_oc_legacy/repos"    "$_oc_data/" 2>/dev/null
                   [ -f "$_oc_legacy/auth.json" ] && ln -sf "$_oc_legacy/auth.json" "$_oc_data/auth.json" 2>/dev/null
                   rm -f "$db_file-wal" "$db_file-shm" 2>/dev/null
                   touch "$_oc_data/.aicli-migrated"
               fi
           ) 9>"$_oc_data/.aicli-migrate.lock"
       fi

       # Print PIDs of OTHER opencode processes holding THIS workspace's DB (or its
       # WAL) open — matching a deleted inode too (a mid-session working copy shows
       # "<path> (deleted)"). Excludes our own launcher pid.
       _oc_db_holders() {
           local _p _l _t
           for _p in $(pgrep -f 'opencode-ai/bin/opencode.exe' 2>/dev/null); do
               [ "$_p" = "$$" ] && continue
               for _l in /proc/"$_p"/fd/*; do
                   _t=$(readlink "$_l" 2>/dev/null) || continue
                   _t=${_t% (deleted)}
                   if [ "$_t" = "$db_file" ] || [ "$_t" = "$db_file-wal" ]; then
                       echo "$_p"; break
                   fi
               done
           done | sort -u
       }

       # --- A: single-instance guard — stop any competing holder gracefully. ---
       _oc_grace="${AICLI_OPENCODE_STOP_GRACE:-8}"
       _oc_hold=$(_oc_db_holders)
       if [ -n "$_oc_hold" ]; then
           log_aicli "WARN" 1 "OpenCode #144: DB held by other pid(s) [$(echo $_oc_hold)] — stopping gracefully before launch"
           for _p in $_oc_hold; do kill -TERM "$_p" 2>/dev/null; done
           _oc_deadline=$(( $(date +%s) + _oc_grace ))
           while [ "$(date +%s)" -lt "$_oc_deadline" ]; do
               [ -z "$(_oc_db_holders)" ] && break
               sleep 0.3
           done
           _oc_hold=$(_oc_db_holders)
           if [ -n "$_oc_hold" ]; then
               log_aicli "WARN" 1 "OpenCode #144: pid(s) [$(echo $_oc_hold)] survived ${_oc_grace}s SIGTERM — SIGKILL"
               for _p in $_oc_hold; do kill -KILL "$_p" 2>/dev/null; done
               sleep 0.5
           fi
       fi

       # --- B: guarded D-52 WAL/SHM cleanup — only when the DB is unheld. ---
       if [ -z "$(_oc_db_holders)" ]; then
           rm -f "$db_file-wal" "$db_file-shm" 2>/dev/null
       else
           log_aicli "WARN" 1 "OpenCode #144: DB still held after guard — SKIPPING WAL cleanup to avoid corruption"
       fi
    fi

    # D-159: Metadata Alignment - Ensure agent config directories are accessible.
    #
    # Do not recursively chmod a persisted agent home here.  On an overlay home,
    # chmod -R copy-ups every lower-layer file into the writable layer.  Claude's
    # history/plugins can contain tens of thousands of files, so that operation
    # can block startup indefinitely while ZFS commits the copy-ups.  The agent
    # runs as the workspace user and needs its config-root directory traversable;
    # content permissions are owned by the agent that created them.
    case "$AGENT_ID" in
        gemini-cli)  [ -d "$HOME_DIR/.gemini" ] && chmod 775 "$HOME_DIR/.gemini" > /dev/null 2>&1 ;;
        claude-code) [ -d "$HOME_DIR/.claude" ] && chmod 775 "$HOME_DIR/.claude" > /dev/null 2>&1 ;;
        opencode)    [ -d "${XDG_DATA_HOME:-$HOME_DIR/.local/share}/opencode" ] && chmod 775 "${XDG_DATA_HOME:-$HOME_DIR/.local/share}/opencode" > /dev/null 2>&1 ;;
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
             "$HOME_DIR/.local/share/aicli-keyring" \
             2>/dev/null

    # WP #1227: Antigravity CLI (agy) unconditionally tries to open a log file
    # in $HOME_DIR/.gemini/antigravity-cli/log/ at startup. When the directory
    # is absent, agy's log-redirect fails and Go's glog library falls back to
    # writing verbose info messages directly to stderr — which is the same TTY
    # as the terminal, so log lines interleave with TUI rendering and corrupt
    # the display. Pre-creating the directory here (on every loop iteration,
    # same as the .cache/.config/.local dirs above) ensures the redirect
    # succeeds on first launch and after any bake/snapshot cycle that wipes the
    # delta layer.
    if [[ "$AGENT_ID" == "antigravity-cli" ]]; then
        mkdir -p "$HOME_DIR/.gemini/antigravity-cli/log" 2>/dev/null
    fi

    # SIDE_BY_SIDE_AGENT_INSTALLS.md Phase 3, R4: degrade sanely if the pinned
    # generation DIRECTORY itself has vanished (reaped by GC once a later phase
    # ships concurrent generations, or the agent was uninstalled). This is
    # keyed on the generation ROOT, not on frozen_binary_primary/fallback
    # individually — a single missing file inside a still-present generation
    # is the ordinary cli.js -> bin/claude.exe shape change the block below
    # already handles; only a missing generation root means this session's
    # pinned version is gone, not just reshaped. Guarded on
    # frozen_agent_generation_dir != frozen_agent_stable_dir so a not-yet-
    # migrated or never-installed agent (the two are always equal there) never
    # takes this path — that case already falls through to the ordinary
    # "binary not found" handling below, unchanged. Falls back to the STABLE
    # (un-pinned) paths, which resolve through whatever the stable name
    # currently names — the session keeps running on a different version
    # rather than failing to relaunch. Logged once, not every loop iteration,
    # to avoid spamming a crash-loop.
    if [ -n "$frozen_agent_generation_dir" ] && [ "$frozen_agent_generation_dir" != "$frozen_agent_stable_dir" ] \
       && [ ! -d "$frozen_agent_generation_dir" ] && [ "${_aicli_degraded_to_stable:-0}" != "1" ]; then
        log_aicli "WARN" 1 "Pinned agent generation vanished (${frozen_agent_generation_dir}); falling back to the current stable agent path"
        frozen_binary_primary="$frozen_binary_primary_stable"
        frozen_binary_fallback="$frozen_binary_fallback_stable"
        _aicli_degraded_to_stable=1
    fi

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
    # 3. Otherwise, use agent-specific history discovery. Claude is special:
    #    resolve its modern ~/.claude/projects/<workspace> store to an explicit
    #    id rather than using a global-newest or obsolete ~/.claude/sessions scan.
    can_resume=0
    if [ "$frozen_chat_id" = "_fresh_" ]; then
        can_resume=0
        log_aicli "INFO" 2 "Fresh-start sentinel: skipping all history fallbacks"
    elif [ -n "$frozen_chat_id" ] && [ "$frozen_chat_id" != "none" ]; then
        can_resume=1
        log_aicli "INFO" 2 "Resume requested with explicit chat id: $frozen_chat_id"
    elif [ "$AGENT_ID" == "claude-code" ]; then
        _workspace_claude_chat=$(_aicli_discover_claude_chat)
        if [ -n "$_workspace_claude_chat" ]; then
            frozen_chat_id="$_workspace_claude_chat"
            can_resume=1
            log_aicli "INFO" 2 "Workspace-scoped Claude resume discovered: $frozen_chat_id"
        fi
    elif [ "$AGENT_ID" == "gemini-cli" ]; then
        if [ -d "$HOME_DIR/.gemini/history" ] && [ -n "$(find "$HOME_DIR/.gemini/history" -type f 2>/dev/null)" ]; then
            can_resume=1
        fi
    elif [ "$AGENT_ID" == "pi-coder" ]; then
        if [ -d "$HOME_DIR/.pi/agent/sessions" ] && [ -n "$(find "$HOME_DIR/.pi/agent/sessions" -type f 2>/dev/null)" ]; then
            can_resume=1
        fi
    elif [ "$AGENT_ID" == "antigravity-cli" ]; then
        # No explicit chat id was handed in, but if agy has any stored conversation
        # for this home, resume-latest (--continue) so a reopened/relaunched
        # workspace returns to the last chat instead of a blank session. (The
        # workspace-correct id normally arrives via getResumeId/findSession ->
        # frozen_chat_id; this is the safety net when it doesn't.)
        if [ -d "$HOME_DIR/.gemini/antigravity-cli/conversations" ] && [ -n "$(find "$HOME_DIR/.gemini/antigravity-cli/conversations" -name '*.pb' -type f 2>/dev/null)" ]; then
            can_resume=1
        fi
    elif [ "$AGENT_ID" == "grok-build" ]; then
        if [ -d "$HOME_DIR/.grok/sessions" ] && [ -n "$(find "$HOME_DIR/.grok/sessions" -type f 2>/dev/null)" ]; then
            can_resume=1
        fi
    elif [ "$AGENT_ID" == "kimi-code" ]; then
        if [ -s "$HOME_DIR/.kimi-code/session_index.jsonl" ]; then
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
        # Issue #71: log the effective NODE_OPTIONS alongside every launch so
        # heap-limit forensics never depend on reconstructing the environment.
        log_aicli "INFO" 2 "Executing: $cmd (NODE_OPTIONS=${NODE_OPTIONS:-<unset>})"
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
    last_launch_duration=0
    last_launch_rc=1
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
        _attempt_started=$(date +%s)
        safe_exec "$FINAL_CMD"
        _attempt_rc=$?
        _aicli_record_agent_exit "explicit-resume" "$_attempt_rc" "$_attempt_started"
        if [ "$_attempt_rc" -eq 0 ]; then
            status="ok"
        elif _aicli_resume_was_established "$last_launch_duration"; then
            # The resume DID start and owned the terminal for a meaningful
            # period. Its later non-zero exit is a process failure/termination,
            # not evidence that the resume command was invalid. Never replace
            # that conversation with a silent fresh launch.
            status="ok"
            log_aicli "WARN" 1 "Resumed agent exited non-zero after ${last_launch_duration}s (rc=$_attempt_rc); preserving conversation for resume-first relaunch"
        else
            log_aicli "WARN" 1 "Resume command failed during startup after ${last_launch_duration}s (rc=$_attempt_rc); preserving continuity and refusing fresh fallback"
            status="resume_failed"
        fi
    elif [ "$can_resume" == "1" ]; then
        LATEST_CMD="${frozen_resume_latest//\{binary\}/$frozen_binary}"
        LATEST_CMD="${LATEST_CMD//\{args\}/$frozen_effective_args}"
        LATEST_CMD="${LATEST_CMD//  / }"
        log_aicli "INFO" 2 "Attempting Latest Resume: $LATEST_CMD"
        _attempt_started=$(date +%s)
        safe_exec "$LATEST_CMD"
        _attempt_rc=$?
        _aicli_record_agent_exit "latest-resume" "$_attempt_rc" "$_attempt_started"
        if [ "$_attempt_rc" -eq 0 ]; then
            status="ok"
        elif [ -z "$frozen_chat_id" ] && ! _aicli_resume_was_established "$last_launch_duration"; then
            # 2026-09-10: a SPECULATIVE latest-resume that never started must fall through
            # to a fresh launch, not park.
            #
            # can_resume is decided per agent from a HOME-WIDE store (grok-build looks at
            # ~/.grok/sessions, and so on), but '--continue' resolves a session for the
            # CURRENT DIRECTORY. So a brand-new workspace in a new folder sets can_resume=1
            # off another workspace's sessions, runs '--continue', and the agent exits with
            # "No session found for current directory" — the workspace then parked on the
            # [Agent Exited Immediately] banner and could never be used. Observed on .4 for
            # grok-build and kilocode by creating a fresh workspace for each.
            #
            # Parking exists to protect a KNOWN conversation. frozen_chat_id is empty here,
            # so there is nothing to lose: no id was handed in and getResumeId/findSession
            # found nothing for this workspace. The explicit-chatId branch above is
            # untouched and still parks.
            log_aicli "WARN" 1 "Speculative latest-resume failed at startup (rc=$_attempt_rc) and this workspace has no known conversation; starting fresh instead of parking"
            status="fail"
        elif _aicli_resume_was_established "$last_launch_duration"; then
            status="ok"
            log_aicli "WARN" 1 "Resumed agent exited non-zero after ${last_launch_duration}s (rc=$_attempt_rc); preserving conversation for resume-first relaunch"
        else
            log_aicli "WARN" 1 "Resume command failed during startup after ${last_launch_duration}s (rc=$_attempt_rc); preserving continuity and refusing fresh fallback"
            status="resume_failed"
        fi
    fi

    # Fresh launch is allowed only when no resumable conversation was selected.
    # A failed explicit/latest resume must park visibly and retry resume after
    # confirmation; silently replacing it with a blank conversation loses the
    # very context this wrapper exists to preserve.
    if [ "$status" == "fail" ]; then
        # Append plugin_args (e.g. codex sandbox overrides) AFTER user workspace
        # args so codex's last-`-c`-wins makes the plugin value override any
        # user workspace arg. These are plugin-injected and not user-editable.
        FRESH_CMD=$(_build_fresh_cmd "$frozen_binary" "$frozen_effective_args" "$frozen_plugin_args")
        log_aicli "INFO" 2 "Attempting Fresh Launch: $FRESH_CMD"

        # 1. Command-based Execution
        _attempt_started=$(date +%s)
        safe_exec "$FRESH_CMD"
        _attempt_rc=$?
        _aicli_record_agent_exit "fresh" "$_attempt_rc" "$_attempt_started"
        if [ "$_attempt_rc" -eq 0 ]; then
            status="ok"
        elif _aicli_resume_was_established "$last_launch_duration"; then
            # The fresh command also genuinely started. A later Ctrl-C/signal
            # must return to the sentinel/session-sync logic, not invoke the JS
            # binary again as a second blank process.
            status="ok"
            log_aicli "WARN" 1 "Fresh agent exited non-zero after ${last_launch_duration}s (rc=$_attempt_rc); treating it as an established process exit"
        else
            # 2. Deep Fallback — only meaningful for a JS file that failed
            # during startup, before it established an interactive lifecycle.
            if [[ "$frozen_binary" == *"/"* ]] && ! is_elf_binary "$frozen_binary"; then
                log_aicli "INFO" 2 "Native launch failed. Trying explicit node launch on $frozen_binary..."
                _attempt_started=$(date +%s)
                node "$frozen_binary"
                _attempt_rc=$?
                _aicli_record_agent_exit "fresh-node-fallback" "$_attempt_rc" "$_attempt_started"
                if [ "$_attempt_rc" -eq 0 ]; then
                    status="ok"
                fi
            fi
        fi
    fi
    
    if [ "$status" = "resume_failed" ]; then
        log_aicli "ERROR" 1 "Resume failed for $AGENT_ID (rc=$last_launch_rc); refusing silent fresh fallback"
    elif [ "$status" != "ok" ]; then
        log_aicli "ERROR" 1 "Agent launch attempts ended before an interactive session was established (agent=$AGENT_ID rc=$last_launch_rc). Check $DEBUG_LOG and the captured crash record."
    fi

    # Restore PTY line discipline after every agent exit. TUI agents (agy,
    # gemini-cli, claude-code) call cfmakeraw() which disables ONLCR; on exit
    # they should restore it via tcsetattr but often don't, leaving the PTY in
    # raw mode — the next prompt or clear then renders as a diagonal staircase.
    # Also re-assert TERM in case a dumb parent environment leaked through.
    stty sane 2>/dev/null
    { [ "$TERM" = "dumb" ] || [ -z "$TERM" ]; } && export TERM=xterm-256color

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

    # Refresh Claude's active workspace conversation BEFORE any automatic
    # relaunch path. In particular, Apply-now's auto-reload used to skip the
    # post-exit GUID sync and could relaunch a stale or blank conversation.
    _aicli_sync_claude_chat

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
        if [ "$AGENT_ID" == "antigravity-cli" ]; then
            # After an in-TUI conversation switch, agy writes the active chat's
            # <uuid>.pb. Newest .pb by mtime = the just-active conversation; sync it
            # so the next Ctrl-C relaunch resumes THAT chat, not the baked-in one.
            _acd="$HOME_DIR/.gemini/antigravity-cli/conversations"
            if [ -d "$_acd" ]; then
                _newest_pb=$(find "$_acd" -name "*.pb" -type f -printf '%T@ %p\n' 2>/dev/null | sort -rn | head -1 | cut -d' ' -f2-)
                if [ -n "$_newest_pb" ]; then
                    _discovered_chat=$(basename "$_newest_pb" .pb)
                fi
            fi
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
    launch_duration="$last_launch_duration"
    if [ "$launch_duration" -lt 3 ]; then
        consecutive_fast_crashes=$((consecutive_fast_crashes + 1))
        # #37: after repeated immediate crashes, stop blaming "configuration" — the
        # binary itself is almost certainly broken/incompatible. Give the operator the
        # real remedy (upgrade/reinstall) instead of a bare retry that just crashes again.
        if [ "$consecutive_fast_crashes" -ge 3 ]; then
            log_aicli "WARN" 1 "Agent $AGENT_ID exited immediately $consecutive_fast_crashes times in a row — binary likely broken; not auto-retrying"
            echo -e "\n\033[1;31m[Agent Keeps Crashing]\033[0m $AGENT_ID has exited immediately ${consecutive_fast_crashes} times in a row — its binary is likely broken or incompatible (not a config problem)."
            echo -e "Upgrade or reinstall $AGENT_ID from Settings, then reopen this workspace. Press ENTER to try again, or close this workspace from the UI."
        else
            echo -e "\n\033[1;31m[Agent Exited Immediately]\033[0m The agent exited after ${launch_duration}s — likely missing configuration."
            echo -e "Press ENTER to retry, or close this workspace from the UI."
        fi
        read -r
    else
        consecutive_fast_crashes=0
        echo -e "\n\033[1;33m[Agent Exited]\033[0m Press ENTER to reload..."
        read -t 10 -r
    fi
done
EOF




    chmod +x "$RUN_SCRIPT"
    log_aicli "DEBUG" 3 "Launching tmux session $SESSION for script $RUN_SCRIPT"
    perf_log tmux.new.begin
    # Bug #1054 follow-up: re-sanitize the per-uid socket dir immediately before
    # new-session, in case anything since the early setup left it with perms tmux
    # rejects. Cheap and idempotent.
    sanitize_tmux_uid_dir
    # Bug #1054 diagnostic: capture caller env + cwd so we can compare working
    # vs failing launches. Removed once root cause is fixed.
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] [DIAG-1054-PRE-TMUX] sess=$SESSION uid=$(id -u) cwd=$(pwd) TMUX_TMPDIR=$TMUX_TMPDIR tmux_v=$(tmux -V) script_size=$(wc -c < "$RUN_SCRIPT")" >> "$DEBUG_LOG"
    # Pin the new session's start dir to the workspace. Without -c, new-session
    # takes the invoking client / tmux-server cwd, so a headless --ensure-session
    # relaunch landed the agent in the server's /mnt instead of $ROOT_DIR (agy
    # exposed this; cwd-sensitive agents masked it on browser starts). $ROOT_DIR
    # is ${AICLI_ROOT:-/mnt} so it's never empty.
    tmux -u new-session -d -s "$SESSION" -c "${WORK_CWD:-$ROOT_DIR}" "$RUN_SCRIPT" 2>>"$DEBUG_LOG"
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
# T-01: every key in TmuxService::BUILTIN must have a matching line here — the
#        parity is enforced by TmuxBaselineParityTest.php in CI.
tmux set-option -t "$SESSION" history-limit "$HISTORY_LIMIT" 2>/dev/null
tmux set-option -t "$SESSION" status off 2>/dev/null
tmux set-option -t "$SESSION" mouse off 2>/dev/null
tmux set-option -t "$SESSION" prefix C-b 2>/dev/null
tmux set-option -t "$SESSION" base-index 0 2>/dev/null
tmux set-option -t "$SESSION" bell-action any 2>/dev/null
tmux set-option -t "$SESSION" allow-passthrough on 2>/dev/null
tmux set-option -t "$SESSION" focus-events on 2>/dev/null
# WP #1253: escape-time 0 — tmux's 500ms default makes a lone ESC ambiguous (key
# vs. start-of-sequence). When a fast TUI streamer (e.g. antigravity) emits an
# escape sequence whose bytes arrive SPLIT across reads over the ttyd/websocket
# pipe, tmux waits then mis-parses the ESC, emitting literal bytes / dropping the
# sequence -> intermittent blank/garble of streamed output. 0 is the canonical
# TUI-app setting and is harmless on a fast local-socket terminal.
tmux set-option -t "$SESSION" escape-time 0 2>/dev/null
# Two clients may now be attached at once (desktop + phone). tmux's default
# sizes the window to the SMALLEST attached client, so a phone would squash a
# desktop tab down to its width for as long as it stayed connected. 'latest'
# sizes to the most recently used client, so whichever device you are actually
# typing on gets the correct geometry.
tmux set-option -t "$SESSION" window-size latest 2>/dev/null
# xterm-256color: tells apps inside tmux the correct terminal type (not screen),
# avoiding line-translation issues when TUI agents (agy, gemini-cli) set raw PTY mode.
# The terminal-overrides appends enable 24-bit colour pass-through from ttyd/the browser.
# T-02: set-clipboard on is required for OSC 52 clipboard pass-through (WP#964/T-05).
# T-02: extended-keys off globally; per-agent quirk profiles (T-07) enable it for Claude Code.
# T-02: RGB is the modern synonym for Tc; xterm.js 5.x recognises both. Both can coexist
#        as accumulated -ga entries; the existing Tc entry stays for older clients.
tmux set-option -t "$SESSION" default-terminal "$_AICLI_DEFTERM" 2>/dev/null
tmux set-option -t "$SESSION" set-clipboard on 2>/dev/null
tmux set-option -t "$SESSION" extended-keys off 2>/dev/null
tmux set-option -a -t "$SESSION" terminal-overrides ",xterm-256color:Tc" 2>/dev/null
tmux set-option -a -t "$SESSION" terminal-overrides ",xterm-256color:RGB" 2>/dev/null
# tmux-256color (bundled terminfo, opt-in via default-terminal): same truecolor
# pass-through, and propagate TERMINFO_DIRS into the server env so panes find it.
tmux set-option -a -t "$SESSION" terminal-overrides ",tmux-256color:Tc" 2>/dev/null
tmux set-option -a -t "$SESSION" terminal-overrides ",tmux-256color:RGB" 2>/dev/null
[ -n "${TERMINFO_DIRS:-}" ] && tmux set-environment -g TERMINFO_DIRS "$TERMINFO_DIRS" 2>/dev/null

# Helper: apply a JSON file of allow-listed tmux options via a single PHP pass.
# T-03: allowed/append key lists are single-sourced from TmuxService::ALLOWED_KEYS and
#        TmuxService::APPEND_KEYS rather than duplicated here as string literals, so any
#        future extension to the PHP const is automatically reflected in the shell tier.
# Lives in a script file (apply-tmux-json.php), NOT `php -r`: backslash namespaces in a
# double-quoted -r body are eaten by bash (publish anti-pattern rule, exit 3). The
# script also accepts APPEND_KEYS entries (quirk files carry terminal-features) which
# the old inline filter accidentally dropped.
apply_tmux_json() {
    local jsonfile="$1"
    [ -f "$jsonfile" ] || return 0
    log_aicli "DEBUG" 3 "Applying tmux settings from $jsonfile"
    php "$PLUGIN_SRC/scripts/user/apply-tmux-json.php" \
        "$jsonfile" "$SESSION" 2>>"$DEBUG_LOG" | bash 2>>"$DEBUG_LOG"
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

# Tier 1.5 — Agent quirk profile (not user-editable; written by TerminalService
# before launch). Sits between BUILTIN and user-editable tiers so structural
# requirements like Claude Code's extended-keys + terminal-features are applied
# after the safety-net defaults but can still be overridden by the user.
# apply_tmux_json supports APPEND_KEYS (-ga semantics) so terminal-features
# accumulates rather than clobbers the BUILTIN terminal-overrides entries.
# Bug #141: quirk files are written by PHP (TmuxService::quirkPath) into the
# SHARED tmux root, not the per-session TMUX_TMPDIR — read them from there.
apply_tmux_json "$AICLI_TMUX_ROOT/quirks_${AGENT_ID}.json"

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
    if ! tmux source-file -t "$SESSION" "$WS_TMUX_CONF" 2>>"$DEBUG_LOG"; then
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
# R-B1: headless ensure-session mode — the detached session now exists (verified
# by the has-session check above) with the agent running inside it, and all four
# tmux config tiers have been applied. Return success WITHOUT attaching; the
# session lives on independent of any browser. A later interactive invocation
# (ttyd on browser-connect) re-enters this script, finds the session already
# present (the new-session block is skipped), re-applies options idempotently,
# and falls through to the attach below.
if [ "$AICLI_ENSURE_ONLY" -eq 1 ]; then
    log_aicli "INFO" 2 "ensure-session: detached session $SESSION is live — returning without attach."
    perf_log ensure.session.done
    exit 0
fi

# Bound the number of simultaneously attached clients, keeping the NEWEST.
#
# This replaces `attach-session -d` (detach-others). -d was introduced for the
# reconnect leak (2026-06-06): ttyd's web client reconnects on ANY websocket
# drop — background-tab throttling, proxy idle timeout, a network blip — and
# each reconnect attaches a NEW client while the previous one is NOT reaped
# (runuser/setsid puts each attach in its own session, so ttyd can't SIGHUP it
# on disconnect). Clients accumulated without bound; tmux mirrors the agent TUI
# to every one of them on every redraw, so CPU compounded (7 clients on one
# session, load avg ~5).
#
# But -d evicts EVERY other client, which is fine for one browser and broken
# for two. Opening the same workspace on a phone while a desktop tab is open
# put them in an eviction loop: the phone's attach evicted the desktop, the
# desktop's terminal auto-reconnected and evicted the phone, and so on without
# end — each side seeing a permanent reconnect churn.
#
# A cap fixes both. Leaked clients are ALWAYS older than a live reconnect, so
# pruning oldest-first removes exactly the stale ones while letting genuine
# concurrent viewers coexist. Set AICLI_MAX_TMUX_CLIENTS=1 to restore the old
# evict-everyone behaviour.
prune_excess_tmux_clients() {
    local session="$1" limit="${2:-4}" keep list total drop client_tty
    # We are about to attach as one more client, so at most limit-1 may remain.
    keep=$(( limit - 1 ))
    [ "$keep" -lt 0 ] && keep=0
    list="$(tmux list-clients -t "$session" -F '#{client_created} #{client_tty}' 2>/dev/null \
            | sort -n | awk 'NF==2 {print $2}')"
    [ -n "$list" ] || return 0
    total=$(printf '%s\n' "$list" | wc -l)
    drop=$(( total - keep ))
    [ "$drop" -gt 0 ] || return 0
    # Feed the loop from a here-string, NOT `... | while`: a piped while runs in
    # a subshell, so anything it changes is discarded when the pipeline ends.
    while IFS= read -r client_tty; do
        [ -n "$client_tty" ] && tmux detach-client -t "$client_tty" 2>/dev/null
    done <<< "$(printf '%s\n' "$list" | head -n "$drop")"
    return 0
}

prune_excess_tmux_clients "$SESSION" "${AICLI_MAX_TMUX_CLIENTS:-4}"

perf_log tmux.attach.exec
exec tmux -u attach-session -t "$SESSION" 2>>"$DEBUG_LOG"
