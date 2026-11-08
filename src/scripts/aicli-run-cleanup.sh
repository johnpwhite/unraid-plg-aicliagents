#!/bin/bash
# Stop every restricted runtime before an array stop or plugin removal.
set -uo pipefail
# CI test switch: a non-root process honours it; root honours it only with the CI marker file (a root-owned regular file that production never has).
if [[ "${AICLI_TEST_TMP:-}" == 1 ]] && ((EUID == 0)) && ! [[ -f /run/aicli-ci-test-marker && ! -L /run/aicli-ci-test-marker && -O /run/aicli-ci-test-marker ]]; then
    unset AICLI_TEST_TMP
    printf "%s\n" "aicli-run-cleanup: the test switch is ignored for root without the CI marker" >&2
fi
if [[ "${AICLI_TEST_TMP:-0}" != 1 ]]; then PATH='/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'; fi
export PATH

mode=${1:-}
[[ "$mode" == stop || "$mode" == uninstall ]] || { echo 'usage: aicli-run-cleanup.sh {stop|uninstall}' >&2; exit 64; }

# The state root comes from the shared resolver (AICLI_STATE_ROOT, or the production default), as in the other scripts.
source "$(dirname "${BASH_SOURCE[0]}")/storage/resolve_paths.sh" || { echo 'resolve_paths.sh missing' >&2; exit 1; }

view_dir=/var/run/aicli-view
state_root=$AICLI_STATE_ROOT
cgroup_script=/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/aicli-run-cgroup.sh
network_script=/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/aicli-run-network.sh
if [[ "${AICLI_TEST_TMP:-0}" == 1 ]]; then
    view_dir=${AICLI_RUN_VIEW_DIR:-$view_dir}
    state_root=${AICLI_RUN_STATE_ROOT:-$state_root}
    cgroup_script=${AICLI_RUN_CGROUP_SCRIPT:-$cgroup_script}
    network_script=${AICLI_RUN_NETWORK_SCRIPT:-$network_script}
fi

log() { printf '[aicli-run-cleanup] %s\n' "$*" >&2; }
if [[ "${AICLI_TEST_TMP:-0}" == 1 && "${AICLI_TEST_NO_RUN_UID:-0}" == 1 ]]; then
    uid=''
elif [[ "${AICLI_TEST_TMP:-0}" == 1 && "${AICLI_TEST_RUN_UID+x}" == x ]]; then
    uid=$AICLI_TEST_RUN_UID
else
    uid=$(id -u aicli-run 2>/dev/null || true)
fi
[[ "$uid" =~ ^[0-9]+$ ]] || uid=''
ids=()
for mark in "$view_dir"/*.runas; do
    [[ -f "$mark" && ! -L "$mark" ]] || continue
    id=${mark##*/}
    id=${id%.runas}
    [[ "$id" =~ ^[A-Za-z0-9_-]{1,64}$ ]] || continue
    ids+=("$id")
    [[ -x "$cgroup_script" ]] && /bin/bash "$cgroup_script" kill "$id" >/dev/null 2>&1 || true
done
if ((${#ids[@]} > 0)) && [[ -z "$uid" ]]; then
    log 'the run user is missing; the active restricted views cannot be cleaned safely'
    exit 1
fi

# Kill scheduler or detached processes that may not be in a workspace cgroup.
if [[ -n "$uid" ]]; then
    pkill -KILL -U "$uid" 2>/dev/null || true
    pkill -KILL -u "$uid" 2>/dev/null || true
    if pgrep -U "$uid" >/dev/null 2>&1 || pgrep -u "$uid" >/dev/null 2>&1; then
        log 'a process of the run user is still alive; its network and view stay in place'
        exit 1
    fi
fi

for id in "${ids[@]}"; do
    [[ -x "$cgroup_script" && -x "$network_script" ]] || { log 'a runtime cleanup tool is missing'; exit 1; }
    empty=$(/bin/bash "$cgroup_script" empty "$id" 2>/dev/null) || { log "the process group for $id cannot be checked"; exit 1; }
    [[ "$empty" == empty ]] || { log "the process group for $id is still active"; exit 1; }
    /bin/bash "$network_script" remove "$id" || { log "the network guard for $id stays in place"; exit 1; }
    /bin/bash "$cgroup_script" remove "$id" || { log "the process group for $id could not be removed"; exit 1; }
    for suffix in childusr pid usr ipc uts mnt ready supervisor launch.log go; do
        path="$view_dir/$id.$suffix"
        if [[ "$suffix" == childusr || "$suffix" == pid || "$suffix" == usr || "$suffix" == ipc || "$suffix" == uts || "$suffix" == mnt ]]; then umount -- "$path" 2>/dev/null || true; fi
        rm -f -- "$path"
    done
    session_root="$state_root/work/aicli-run-sessions"
    session_dir="$session_root/$id"
    if [[ -e "$session_dir" || -L "$session_dir" ]]; then
        if [[ -L "$session_root" || ! -d "$session_root" || -L "$session_dir" || ! -d "$session_dir" ]]; then
            log "the private home for $id is not a plain directory; runtime cleanup stops"
            exit 1
        fi
        rm -rf --one-file-system -- "$session_dir" || { log "the private home for $id could not be removed"; exit 1; }
    fi
    rmdir -- "$view_dir/$id.privstage" 2>/dev/null || true
    # #929: the tmux folders of the workspace: the root-only folder with the sockets, and the empty mount point in the shared folder
    # (or the folder that an older release kept there). No process of the run user is left here.
    tmux_root="$state_root/run-tmux"
    for tmux_dir in "$tmux_root/s-$id" "$state_root/tmux/s-$id"; do
        if [[ -L "$tmux_dir" ]]; then rm -f -- "$tmux_dir"; continue; fi
        [[ -d "$tmux_dir" ]] || continue
        [[ ! -L "$tmux_root" ]] || { log "the tmux parent is a link; runtime cleanup stops"; exit 1; }
        rm -rf --one-file-system -- "$tmux_dir" || { log "the tmux folder for $id could not be removed"; exit 1; }
    done
    if [[ "$mode" == uninstall ]]; then rm -f -- "$view_dir/$id.runas"; fi
done

# The global local-address guard is no longer needed after every process has stopped.
if [[ -n "$uid" ]]; then
    for tool in iptables ip6tables; do
        command -v "$tool" >/dev/null 2>&1 || continue
        while "$tool" -w 5 -C OUTPUT -m owner --uid-owner "$uid" -m addrtype --dst-type LOCAL -j REJECT 2>/dev/null; do
            "$tool" -w 5 -D OUTPUT -m owner --uid-owner "$uid" -m addrtype --dst-type LOCAL -j REJECT || {
                log "the $tool run-user guard could not be removed"
                exit 1
            }
        done
    done
fi
exit 0
