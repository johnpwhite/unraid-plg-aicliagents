#!/bin/bash
# aicli-clean-exec.sh — start a command without what the caller left behind (#781).
#
# PHP exec() gives a child every open descriptor and the umask of the PHP process.
# Under aicli-run that process runs below a user's script, so the script's open files
# (a lock on descriptor 9, a log on descriptor 7) and its umask reached the tmux server
# of the workspace and the agent in it, and stayed there as long as the workspace lived.
#
# This helper closes every descriptor above 2, sets the umask to 022, and then replaces
# itself with the command. Usage: aicli-clean-exec.sh <command> [args...]
#
# Descriptor 255 is the script file that bash reads; bash marks it close-on-exec, so the
# final exec drops it. The body is one function, so bash has read all of it before it runs.
aicli_clean_exec() {
    [ $# -gt 0 ] || { echo "aicli-clean-exec: no command given" >&2; return 2; }
    local f fd
    for f in /proc/$$/fd/*; do
        fd="${f##*/}"
        case "$fd" in ''|*[!0-9]*) continue ;; esac
        [ "$fd" -gt 2 ] && [ "$fd" -ne 255 ] || continue
        eval "exec $fd>&-" 2>/dev/null
    done
    umask 022
    exec "$@"
}
aicli_clean_exec "$@"
