#!/bin/bash
source "$(dirname "${BASH_SOURCE[0]}")/storage/resolve_paths.sh" || { echo 'resolve_paths.sh missing' >&2; exit 1; }
# tmux "session-closed" hook (docs/specs/WORKSPACE_SESSION_END_TRACE.md, Forgejo #433).
# A workspace's tmux session ended while its tmux server was still running: the agent process exited, or
# something killed the session. Write one line to the plugin debug log so a vanished session can be explained
# later. This hook cannot run when the tmux server itself is killed; the next start reports that case.
# Usage: tmux-session-closed.sh <session-name> <debug-log>
SESSION="${1:-unknown}"
LOG="${2:-${MOUNT_ROOT}/debug.log}"
case "$SESSION" in *[!A-Za-z0-9._-]*) SESSION="invalid" ;; esac
[ -n "$LOG" ] || exit 0
printf '[%s] [INFO] [TmuxLifecycle] tmux session closed: %s (the agent process ended, or the session was killed; the tmux server was still running)\n' \
    "$(date '+%Y-%m-%d %H:%M:%S')" "$SESSION" >> "$LOG" 2>/dev/null
exit 0
