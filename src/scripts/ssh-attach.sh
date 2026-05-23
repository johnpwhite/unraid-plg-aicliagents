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

_list_sessions() {
    tmux list-sessions -F '#{session_name}' 2>/dev/null \
        | grep -E '^aicli-agent-' \
        | sort
}

_attach_session() {
    local session="$1"
    exec tmux attach-session -t "$session" 2>/dev/null \
        || exec tmux new-session -s "$session"
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
