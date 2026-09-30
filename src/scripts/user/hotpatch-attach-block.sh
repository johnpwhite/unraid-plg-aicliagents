# shellcheck shell=bash
# NOT a standalone script — this is the block spliced into a retained
# generation's aicli-shell.sh by hotpatch-attach-generations.sh.
# --- Bug: two browsers evicted each other forever (hot-patched into a retained
# --- generation so already-open tabs pick it up on their next reconnect).
# Cap attached clients instead of `attach -d` (detach-others). -d evicted EVERY
# other client, so a phone and a desktop tab knocked each other off in an
# endless loop. Leaked clients are always OLDER than a live reconnect, so
# pruning oldest-first still fixes the 2026-06-06 reconnect leak.
# #219: detach any tmux client whose backing ttyd is DEAD, so `window-size
# latest` cannot size the window to a deploy leftover (the terminal renders
# shrunk with xterm's background dots). A deploy SIGTERMs the old ttyd but its
# tmux client lingers (setsid stops the SIGHUP). "Backing ttyd is dead" = no live
# `ttyd` process anywhere in the client process's ancestry; a genuine second live
# browser (its own live ttyd) is kept. If no live ttyd can be found at all
# (pgrep unavailable / renamed) the whole pass is skipped, so we never detach
# every client. Separate from prune_excess_tmux_clients (which caps by count) so
# each stays independently testable.
detach_dead_ttyd_clients() {
    local session="$1" live_ttyds cpid ctty walk hops alive
    live_ttyds="$(pgrep -x ttyd 2>/dev/null | tr '\n' ' ')"
    [ -n "$live_ttyds" ] || return 0
    while IFS= read -r line; do
        cpid="${line%% *}"; ctty="${line#* }"
        [ -n "$cpid" ] && [ -n "$ctty" ] && [ "$cpid" != "$ctty" ] || continue
        walk="$cpid"; hops=0; alive=0
        while [ -n "$walk" ] && [ "$walk" != "1" ] && [ "$hops" -lt 12 ]; do
            case " $live_ttyds " in *" $walk "*) alive=1; break ;; esac
            walk="$(ps -o ppid= -p "$walk" 2>/dev/null | tr -d ' ')"
            hops=$(( hops + 1 ))
        done
        [ "$alive" -eq 1 ] || tmux detach-client -t "$ctty" 2>/dev/null
    done <<< "$(tmux list-clients -t "$session" -F '#{client_pid} #{client_tty}' 2>/dev/null)"
    return 0
}

prune_excess_tmux_clients() {
    local session="$1" limit="${2:-4}" keep list total drop client_tty
    keep=$(( limit - 1 ))
    [ "$keep" -lt 0 ] && keep=0
    list="$(tmux list-clients -t "$session" -F '#{client_created} #{client_tty}' 2>/dev/null \
            | sort -n | awk 'NF==2 {print $2}')"
    [ -n "$list" ] || return 0
    total=$(printf '%s\n' "$list" | wc -l)
    drop=$(( total - keep ))
    [ "$drop" -gt 0 ] || return 0
    while IFS= read -r client_tty; do
        [ -n "$client_tty" ] && tmux detach-client -t "$client_tty" 2>/dev/null
    done <<< "$(printf '%s\n' "$list" | head -n "$drop")"
    return 0
}
# With several clients attached tmux sizes the window to the SMALLEST of them,
# so a phone would squash a desktop tab. 'latest' sizes to the client most
# recently used.
tmux set-option -t "$SESSION" window-size latest 2>/dev/null
detach_dead_ttyd_clients "$SESSION"
prune_excess_tmux_clients "$SESSION" "${AICLI_MAX_TMUX_CLIENTS:-4}"
exec tmux -u attach-session -t "$SESSION" 2>>"$DEBUG_LOG"
