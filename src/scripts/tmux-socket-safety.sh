#!/bin/bash
# Return success when root must not send a tmux command to this socket.
# When the run account is missing, a root-owned socket remains usable. A socket
# with unknown or non-root ownership is skipped so a run user cannot control root.
aicli_tmux_socket_skip_for_root() {
    local socket="${1:-}" run_uid="${2:-}" current_uid owner path id
    [ -n "$socket" ] || return 0
    current_uid="$(command id -u 2>/dev/null)" || return 0

    if [ -z "$run_uid" ]; then
        # Other non-root users still inspect their own sockets. This branch fixes
        # root-only cleanup when the optional run account is not installed.
        [ "$current_uid" = 0 ] || return 1
        owner="$(stat -c %u "$socket" 2>/dev/null)" || return 0
        [ "$owner" != 0 ]
        return
    fi

    # The run user may use its own socket. Root must not trust sockets or parent
    # directories that the run user owns, because the server can run commands.
    [ "$current_uid" != "$run_uid" ] || return 1
    for path in "$socket" "$(dirname "$socket")" "$(dirname "$(dirname "$socket")")"; do
        owner="$(stat -c %u "$path" 2>/dev/null)" || continue
        [ "$owner" = "$run_uid" ] && return 0
    done
    case "$socket" in
        */s-*/*)
            id="${socket#*/s-}"
            id="${id%%/*}"
            [ -e "/var/run/aicli-view/$id.runas" ] && return 0
            ;;
    esac
    return 1
}
