#!/bin/bash
# aicli-detach.sh — start a long-lived process fully detached from its caller
# (Forgejo #337, docs/specs/RELAY_LINKED_BOXES.md "Install-time spawns detach").
#
# Why. Unraid's `plugin install` reads its child's output until EOF. A process
# that the install starts in the background and that keeps ANY descriptor of
# that output open holds the install forever, even after the install shell has
# exited. Bash `&` and nohup keep every inherited descriptor: the Relay
# listener kept the installer's progress descriptor (fd 4) open, and a
# 2026.09.25.01 update on a production box never finished.
#
# What it does. The started process gets:
#   - a new session (setsid): no controlling terminal, no SIGHUP from it;
#   - stdin from /dev/null;
#   - stdout and stderr to its own log file (default /dev/null);
#   - no other descriptor of the caller: every fd above 2 is closed first;
#   - SIGHUP ignored (as nohup did) and the working directory "/".
# It prints the pid of the started process. The pid is the command itself:
# a background child of a non-interactive shell is not a process-group
# leader, so setsid execs in place instead of forking.
#
# Usage, as a command (from PHP, a .plg block or any script):
#   aicli-detach.sh [--log FILE] [--truncate] [--pidfile FILE] [--cwd DIR] -- CMD [ARG...]
#   aicli-detach.sh --run -- CMD [ARG...]
#       --run: run CMD in the FOREGROUND with stdin from /dev/null and every
#       fd above 2 closed; stdout/stderr stay the caller's. Returns CMD's
#       exit code. Use it for a short synchronous step that may itself start
#       a daemon (dbus-daemon --fork, a PHP reconcile that spawns a listener).
# Usage, sourced:  . aicli-detach.sh; aicli_detach [options] -- CMD...;
#                  aicli_run_closed CMD...; aicli_close_fds (in a subshell only)

# Close every descriptor above 2 of the CURRENT shell. Call it only in a
# subshell or immediately before an exec: bash reads a script through fd 255.
aicli_close_fds() {
    local f fd
    for f in /proc/"${BASHPID:-$$}"/fd/*; do
        fd="${f##*/}"
        case "$fd" in ''|*[!0-9]*) continue ;; esac
        [ "$fd" -gt 2 ] || continue
        eval "exec $fd>&-" 2>/dev/null
    done
    return 0
}

aicli_detach() {
    local log=/dev/null append=1 pidfile="" cwd=/ pid
    while [ $# -gt 0 ]; do
        case "$1" in
            --log) log="${2:-/dev/null}"; shift 2 ;;
            --truncate) append=0; shift ;;
            --pidfile) pidfile="${2:-}"; shift 2 ;;
            --cwd) cwd="${2:-/}"; shift 2 ;;
            --) shift; break ;;
            -*) echo "aicli-detach: unknown option: $1" >&2; return 2 ;;
            *) break ;;
        esac
    done
    [ $# -gt 0 ] || { echo "aicli-detach: no command given" >&2; return 2; }
    local setsid_bin=""
    command -v setsid >/dev/null 2>&1 && setsid_bin="setsid"
    (
        aicli_close_fds
        cd "$cwd" 2>/dev/null || cd / 2>/dev/null
        if [ "$append" = "1" ]; then
            exec </dev/null >>"$log" 2>&1
        else
            exec </dev/null >"$log" 2>&1
        fi
        trap '' HUP
        exec $setsid_bin "$@"
    ) &
    pid=$!
    [ -n "$pidfile" ] && echo "$pid" > "$pidfile"
    echo "$pid"
    return 0
}

aicli_run_closed() {
    ( aicli_close_fds; exec "$@" </dev/null )
}

if [ "${BASH_SOURCE[0]}" = "$0" ]; then
    if [ "${1:-}" = "--run" ]; then
        shift
        [ "${1:-}" = "--" ] && shift
        [ $# -gt 0 ] || { echo "aicli-detach: no command given" >&2; exit 2; }
        aicli_run_closed "$@"
        exit $?
    fi
    aicli_detach "$@"
    exit $?
fi
