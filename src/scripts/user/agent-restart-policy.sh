#!/bin/bash
# Restart policy for the agent relaunch loop (#888). Pure function: no I/O, no globals.
# Usage: aicli_restart_policy <duration_s> <rc> <prev_short_failures>
# Prints "<mode> <new_short_failures>". Modes:
#   enter-fast   run under 3 s: the existing fast-exit path (count is unchanged here)
#   enter-repeat 3 or more short failures in a row: wait for ENTER, no timeout
#   auto         the existing 10 s reload
# A short failure runs 3 s to under 60 s and ends with an rc other than 0, 130 or 143.
aicli_restart_policy() {
    local _dur="${1:-0}" _rc="${2:-0}" _prev="${3:-0}"
    case "$_dur" in ''|*[!0-9]*) _dur=0 ;; esac
    case "$_rc" in ''|*[!0-9]*) _rc=1 ;; esac
    case "$_prev" in ''|*[!0-9]*) _prev=0 ;; esac
    if [ "$_dur" -lt 3 ]; then
        echo "enter-fast $_prev"
        return 0
    fi
    if [ "$_dur" -lt 60 ] && [ "$_rc" -ne 0 ] && [ "$_rc" -ne 130 ] && [ "$_rc" -ne 143 ]; then
        local _new=$((_prev + 1))
        if [ "$_new" -ge 3 ]; then echo "enter-repeat $_new"; else echo "auto $_new"; fi
        return 0
    fi
    echo "auto 0"
}
