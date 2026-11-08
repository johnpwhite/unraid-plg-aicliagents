#!/bin/bash
# Start persistent restricted user, PID and mount namespaces, then build the view.
set -euo pipefail
# CI test switch: a non-root process honours it; root honours it only with the CI marker file (a root-owned regular file that production never has).
if [[ "${AICLI_TEST_TMP:-}" == 1 ]] && ((EUID == 0)) && ! [[ -f /run/aicli-ci-test-marker && ! -L /run/aicli-ci-test-marker && -O /run/aicli-ci-test-marker ]]; then
    unset AICLI_TEST_TMP
    printf "%s\n" "aicli-run-view: the test switch is ignored for root without the CI marker" >&2
fi
umask 077
if [[ "${AICLI_TEST_TMP:-0}" != 1 ]]; then PATH='/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'; fi
export PATH

id=${1:-}
mnt=${2:-}
pidns=${3:-}
ipcns=${4:-}
utsns=${5:-}
userns=${6:-}
netns=${7:-}
cgroup_id=${8:-}
run_uid=${9:-}
run_gid=${10:-}
# #929: the folders of this workspace that root binds into the view (device and inode as PHP read them). The task pair is - when the run has no task folder.
private_dir=${11:-}
private_id=${12:-}
home_dir=${13:-}
home_id=${14:-}
task_dir=${15:-}
task_id=${16:-}
tmux_dir=${17:-}   # <state>/run-tmux/s-<id>: the root-only host folder of the tmux sockets
tmux_id=${18:-}
shift 18 || true
[[ "$id" =~ ^[A-Za-z0-9_-]{1,64}$ ]] || { echo 'aicli-run-view: invalid workspace id' >&2; exit 64; }
[[ "$mnt" == /var/run/aicli-view/"$id".mnt && "$pidns" == /var/run/aicli-view/"$id".pid \
    && "$ipcns" == /var/run/aicli-view/"$id".ipc && "$utsns" == /var/run/aicli-view/"$id".uts \
    && "$userns" == /var/run/aicli-view/"$id".usr ]] || { echo 'aicli-run-view: invalid namespace file' >&2; exit 64; }
[[ "$netns" =~ ^aicli-[a-f0-9]{10}$ && "$cgroup_id" == "$id" ]] || { echo 'aicli-run-view: invalid runtime id' >&2; exit 64; }
[[ "$run_uid" =~ ^[0-9]+$ && "$run_gid" =~ ^[0-9]+$ && "$run_uid" -ge 1000 && "$run_gid" -eq 100 ]] || { echo 'aicli-run-view: invalid run identity' >&2; exit 64; }
[[ "$private_dir" == /* && "$private_dir" == */run-private/"$id" && "$private_dir" != *'/../'* && ! "$private_dir" =~ [[:cntrl:]] \
    && "$private_id" =~ ^[0-9]+:[0-9]+$ && "$home_dir" == /* && "$home_dir" == */work/aicli-run-sessions/"$id"/home && "$home_dir" != *'/../'* \
    && ! "$home_dir" =~ [[:cntrl:]] && "$home_id" =~ ^[0-9]+:[0-9]+$ ]] || { echo 'aicli-run-view: invalid private folder' >&2; exit 64; }
if [[ "$task_dir" == - ]]; then
    [[ "$task_id" == - ]] || { echo 'aicli-run-view: invalid task folder' >&2; exit 64; }
else
    [[ "$task_dir" == /* && "$task_dir" == */work/aicli-run-tasks/* && "$task_dir" != *'/../'* && ! "$task_dir" =~ [[:cntrl:]] \
        && "$task_id" =~ ^[0-9]+:[0-9]+$ ]] || { echo 'aicli-run-view: invalid task folder' >&2; exit 64; }
fi
[[ "$tmux_dir" == /* && "$tmux_dir" == */run-tmux/s-"$id" && "$tmux_dir" != *'/../'* && ! "$tmux_dir" =~ [[:cntrl:]] && "$tmux_id" =~ ^[0-9]+:[0-9]+$ ]] || { echo 'aicli-run-view: invalid tmux folder' >&2; exit 64; }
(($# > 0)) || { echo 'aicli-run-view: mount command is missing' >&2; exit 64; }

here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)
view_dir='/var/run/aicli-view'
ready="$view_dir/$id.ready"
supervisor_file="$view_dir/$id.supervisor"
log="$view_dir/$id.launch.log"
child_userns="$view_dir/$id.childusr"
go="$view_dir/$id.go"          # root-only pipe: the answer of root to the private-folder request of init
stage="$view_dir/$id.privstage"  # root-only empty folder: the staging place of the bind script
[[ -f "$mnt" && ! -L "$mnt" && -f "$pidns" && ! -L "$pidns" && -f "$ipcns" && ! -L "$ipcns" \
    && -f "$utsns" && ! -L "$utsns" && -f "$userns" && ! -L "$userns" \
    && -f "$child_userns" && ! -L "$child_userns" ]] || { echo 'aicli-run-view: namespace files are missing' >&2; exit 70; }
[[ ! -e "$ready" && ! -e "$supervisor_file" && ! -e "$go" && ! -e "$stage" && ! -L "$go" && ! -L "$stage" ]] || { echo 'aicli-run-view: an earlier launch is still present' >&2; exit 70; }
mkfifo -m 0600 "$ready" || { echo 'aicli-run-view: the ready pipe could not be made' >&2; exit 70; }
supervisor=''
ready_ok=0
cleanup_view() {
    local rc=$?
    trap - EXIT
    if ((ready_ok == 0)); then
        /bin/bash "$here/aicli-run-cgroup.sh" kill "$cgroup_id" >/dev/null 2>&1 || true
        rm -f -- "$supervisor_file"
    fi
    rm -f -- "$ready" "$go"
    rmdir -- "$stage" 2>/dev/null || true
    exit "$rc"
}
trap cleanup_view EXIT
exec 8<>"$ready"
mkfifo -m 0600 "$go" || { echo 'aicli-run-view: the go pipe could not be made' >&2; exit 70; }
mkdir -m 0700 -- "$stage" || { echo 'aicli-run-view: the staging folder could not be made' >&2; exit 70; }
exec 6<>"$go"

# The cgroup join is before every fork, so the PID namespace, tmux server and
# every child inherit the CPU, memory and process limits.
/bin/bash "$here/aicli-run-cgroup.sh" join "$cgroup_id" \
    nsenter --net="/var/run/netns/$netns" -- \
    unshare --user="$userns" --map-users="0:$run_uid:1" \
        --map-groups="0:$run_gid:1" --setgroups allow \
        --mount="$mnt" --pid="$pidns" --ipc="$ipcns" --uts="$utsns" --fork --mount-proc=/proc \
    /bin/bash "$here/aicli-run-init.sh" "$ready" "$log" "$here/aicli-run-mounts.sh" "$run_uid" "$run_gid" "$@" \
    9>&8 8>&- 7<&6 6>&- >"$log" 2>&1 &
supervisor=$!
printf '%s\n' "$supervisor" > "$supervisor_file"
chmod 0600 "$supervisor_file"

stop_view() {
    echo "aicli-run-view: $1" >&2
    printf 'stop\n' >&6 2>/dev/null || true
    /bin/bash "$here/aicli-run-cgroup.sh" kill "$cgroup_id" >/dev/null 2>&1 || true
    exit 70
}

# Handshake (#929). Init asks root for the private folders BEFORE the mount helper runs. Root enters ONLY the new mount space and
# binds this workspace's folders into it. The text of the request must name the mount space file of this view, so root never
# works in a space that this launch did not make.
answer_private_request() {
    local request=$1 inode_text current
    inode_text=${request#private mnt:[}
    inode_text=${inode_text%]}
    [[ "$inode_text" =~ ^[0-9]+$ ]] || stop_view 'the private folder request is not valid'
    [[ "$(findmnt --noheadings --output FSTYPE --target "$mnt" 2>/dev/null || true)" == nsfs ]] || stop_view 'the mount space file is not an nsfs mount'
    current=$(stat -L -c %i -- "$mnt" 2>/dev/null || true)
    [[ "$current" == "$inode_text" ]] || stop_view 'the request does not come from the mount space of this view'
    if ! timeout --signal=KILL 30 nsenter --mount="$mnt" -- /bin/bash "$here/aicli-run-private-bind.sh" "$id" "$stage" "$run_uid" \
        "$private_dir" "$private_id" "$home_dir" "$home_id" "$task_dir" "$task_id" "$tmux_dir" "$tmux_id"; then
        stop_view 'the private folders could not be bound into the view'
    fi
    printf 'ok\n' >&6
}

ready_rc=''
ready_line=''
private_answered=0
ready_deadline=$((SECONDS + 180))
while :; do
    if IFS= read -r -t 1 ready_line <&8; then
        if [[ "$ready_line" == 'private mnt:['* ]]; then
            ((private_answered == 0)) || stop_view 'init asked for the private folders twice'
            private_answered=1
            answer_private_request "$ready_line"
            continue
        fi
        ready_rc=$ready_line
        break
    fi
    if ! kill -0 "$supervisor" 2>/dev/null; then
        wait "$supervisor" 2>/dev/null || true
        echo 'aicli-run-view: the isolated launch exited before reporting readiness' >&2
        /bin/bash "$here/aicli-run-cgroup.sh" kill "$cgroup_id" >/dev/null 2>&1 || true
        exit 70
    fi
    if ((SECONDS >= ready_deadline)); then
        echo 'aicli-run-view: the isolated launch did not become ready in time' >&2
        /bin/bash "$here/aicli-run-cgroup.sh" kill "$cgroup_id" >/dev/null 2>&1 || true
        exit 70
    fi
done
if [[ "$ready_rc" != 0 || "$private_answered" != 1 ]]; then
    echo 'aicli-run-view: the private process, network or mount view could not be made' >&2
    /bin/bash "$here/aicli-run-cgroup.sh" kill "$cgroup_id" >/dev/null 2>&1 || true
    exit 70
fi
for ns_file in "$userns" "$mnt" "$pidns" "$ipcns" "$utsns"; do
    fs=$(findmnt --noheadings --output FSTYPE --target "$ns_file" 2>/dev/null || true)
    if [[ "$fs" != nsfs ]]; then
        echo "aicli-run-view: namespace file is not an nsfs mount: $ns_file" >&2
        /bin/bash "$here/aicli-run-cgroup.sh" kill "$cgroup_id" >/dev/null 2>&1 || true
        exit 70
    fi
done
/bin/bash "$here/aicli-run-pin-userns.sh" "$id" "$run_uid" "$run_gid" || {
    echo 'aicli-run-view: the child user namespace could not be pinned safely' >&2
    /bin/bash "$here/aicli-run-cgroup.sh" kill "$cgroup_id" >/dev/null 2>&1 || true
    exit 70
}
fs=$(findmnt --noheadings --output FSTYPE --target "$child_userns" 2>/dev/null || true)
if [[ "$fs" != nsfs ]]; then
    echo 'aicli-run-view: the child user namespace file is not an nsfs mount' >&2
    /bin/bash "$here/aicli-run-cgroup.sh" kill "$cgroup_id" >/dev/null 2>&1 || true
    exit 70
fi
ready_ok=1
exit 0
