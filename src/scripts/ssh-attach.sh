#!/bin/bash
# AICliAgents SSH Forced-Command Attach Script — chmod 0755
#
# Installed as the forced-command in authorized_keys for SSH launch links (#747).
# When invoked with SSH_ORIGINAL_COMMAND set to a valid aicli session name,
# attaches to that tmux session (or creates it if missing).
# When invoked interactively (no SSH_ORIGINAL_COMMAND), lists all aicli-agent-*
# sessions for the user to pick from.
#
# authorized_keys entry format (written by SshKeyService::addKey):
#   command="<this script>",no-port-forwarding,no-X11-forwarding,no-agent-forwarding,no-pty <pubkey>
#
# Forced-command usage (ssh:// click path):
#   ssh <user>@<host> aicli-agent-gemini-cli-a3f9
#
# Manual fallback (without forced-command):
#   ssh <user>@<host> -t 'tmux attach -t aicli-agent-gemini-cli-a3f9'

# Force a UTF-8 locale for the attaching tmux client. Unraid's /etc/profile
# doesn't export LANG, so SSH sessions arrive with LANG= and LC_CTYPE=POSIX,
# which causes tmux to render multi-byte UTF-8 chars (❄ ✨ ⏺ é └─) as ASCII
# fallbacks (typically '_'). The web terminal works because it spawns the
# shell with an explicit LANG env var; SSH had no such injection point until
# now. en_US.utf8 is the locale Unraid ships with (verified via `locale -a`).
export LANG=en_US.utf8
export LC_ALL=en_US.utf8

# Bug #1043: route tmux at the plugin-private socket dir (see aicli-shell.sh).
export TMUX_TMPDIR="/tmp/unraid-aicliagents/tmux"

SESSION_PATTERN='^aicli-agent-[A-Za-z0-9_.-]+$'

# Bug #141: each session now owns a private tmux server under
# s-<sid>/tmux-<uid>/default, so a single `tmux list-sessions` against the
# shared socket no longer sees them. Enumerate every socket instead — the
# legacy shared path stays in the list so a pre-#141 session is still
# attachable until it ends.
_socket_paths() {
    local _s
    for _s in "$TMUX_TMPDIR"/s-*/tmux-*/default "$TMUX_TMPDIR"/tmux-*/default; do
        [ -S "$_s" ] && printf '%s\n' "$_s"
    done
}

_list_sessions() {
    local _s
    while IFS= read -r _s; do
        tmux -S "$_s" list-sessions -F '#{session_name}' 2>/dev/null
    done < <(_socket_paths) | grep -E '^aicli-agent-' | sort -u
}

# Print the socket hosting $1, or nothing when no server has it.
_socket_for_session() {
    local session="$1" _s
    while IFS= read -r _s; do
        if tmux -S "$_s" has-session -t "$session" 2>/dev/null; then
            printf '%s\n' "$_s"
            return 0
        fi
    done < <(_socket_paths)
    return 1
}

_attach_session() {
    local session="$1" sock
    sock="$(_socket_for_session "$session")" || sock=""
    if [ -n "$sock" ]; then
        exec tmux -S "$sock" attach-session -t "$session"
    fi
    # No live server owns it — create it on this session's own private socket
    # so the new server is never shared with another workspace.
    local sid="${session##*-}"
    local newsock="$TMUX_TMPDIR/s-$sid"
    mkdir -p "$newsock" 2>/dev/null
    chmod 0700 "$newsock" 2>/dev/null
    exec env TMUX_TMPDIR="$newsock" tmux new-session -s "$session"
}

# ── Forced-command path ──────────────────────────────────────────────────────
if [ -n "$SSH_ORIGINAL_COMMAND" ]; then
    session="$SSH_ORIGINAL_COMMAND"
    if printf '%s' "$session" | grep -qE "$SESSION_PATTERN"; then
        _attach_session "$session"
    else
        echo "ssh-attach: invalid session name: '$session'" >&2
        echo "Session names must match: aicli-agent-<alphanumeric/underscore/dot/dash>" >&2
        exit 1
    fi
fi

# ── Interactive path (no SSH_ORIGINAL_COMMAND) ───────────────────────────────
sessions=$(_list_sessions)

if [ -z "$sessions" ]; then
    echo "No active aicli-agent-* tmux sessions found."
    echo "Start a workspace from the AICliAgents plugin UI first."
    exec "${SHELL:-/bin/bash}" --login
fi

session_count=$(printf '%s\n' "$sessions" | wc -l | tr -d ' ')

if [ "$session_count" -eq 1 ]; then
    echo "Auto-attaching to single session: $sessions"
    _attach_session "$sessions"
fi

# Multiple sessions — numbered menu
echo ""
echo "  AICliAgents — active tmux sessions"
echo "  ─────────────────────────────────"
i=1
while IFS= read -r name; do
    printf "  [%d] %s\n" "$i" "$name"
    i=$((i + 1))
done << SESSIONS
$sessions
SESSIONS
echo ""
printf "  Select session [1-%d], or press Enter to open a shell: " "$session_count"
read -r choice

if [ -z "$choice" ]; then
    exec "${SHELL:-/bin/bash}" --login
fi

if ! printf '%s' "$choice" | grep -qE '^[0-9]+$'; then
    echo "Invalid choice." >&2
    exit 1
fi

selected_name=$(printf '%s\n' "$sessions" | sed -n "${choice}p")

if [ -z "$selected_name" ]; then
    echo "Choice out of range." >&2
    exit 1
fi

_attach_session "$selected_name"
