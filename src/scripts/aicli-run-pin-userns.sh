#!/bin/bash
# Bind the active run's child user namespace to a stable, root-owned nsfs file.
set -euo pipefail
# CI test switch: a non-root process honours it; root honours it only with the CI marker file (a root-owned regular file that production never has).
if [[ "${AICLI_TEST_TMP:-}" == 1 ]] && ((EUID == 0)) && ! [[ -f /run/aicli-ci-test-marker && ! -L /run/aicli-ci-test-marker && -O /run/aicli-ci-test-marker ]]; then
    unset AICLI_TEST_TMP
    printf "%s\n" "aicli-run-pin-userns: the test switch is ignored for root without the CI marker" >&2
fi
if [[ "${AICLI_TEST_TMP:-0}" != 1 ]]; then PATH='/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'; fi
export PATH

id=${1:-}
run_uid=${2:-}
run_gid=${3:-}
[[ "$id" =~ ^[A-Za-z0-9_-]{1,64}$ ]] || { echo 'aicli-run-pin-userns: invalid workspace id' >&2; exit 64; }
[[ "$run_uid" =~ ^[0-9]+$ && "$run_uid" -ge 1000 && "$run_gid" == 100 ]] || { echo 'aicli-run-pin-userns: invalid run identity' >&2; exit 64; }

view_dir='/var/run/aicli-view'
cgroup_root='/sys/fs/cgroup'
proc_root='/proc'
netns_root='/var/run/netns'
if [[ -n "${AICLI_RUN_VIEW_ROOT:-}" || -n "${AICLI_RUN_CGROUP_ROOT:-}" || -n "${AICLI_RUN_PROC_ROOT:-}" || -n "${AICLI_RUN_NETNS_ROOT:-}" ]]; then
    [[ "${AICLI_TEST_TMP:-0}" == 1 ]] || { echo 'aicli-run-pin-userns: test paths need the CI test marker' >&2; exit 64; }
    view_dir=${AICLI_RUN_VIEW_ROOT:-$view_dir}
    cgroup_root=${AICLI_RUN_CGROUP_ROOT:-$cgroup_root}
    proc_root=${AICLI_RUN_PROC_ROOT:-$proc_root}
    netns_root=${AICLI_RUN_NETNS_ROOT:-$netns_root}
fi

group="$cgroup_root/aicli-run-$id"
child_userns="$view_dir/$id.childusr"
parent_userns="$view_dir/$id.usr"
mnt="$view_dir/$id.mnt"
pidns="$view_dir/$id.pid"
ipcns="$view_dir/$id.ipc"
utsns="$view_dir/$id.uts"
net_name=$(printf '%s' "$id" | sha256sum | cut -c1-10)
netns="$netns_root/aicli-$net_name"
[[ -f "$group/cgroup.procs" && ! -L "$group/cgroup.procs" ]] || { echo 'aicli-run-pin-userns: workspace cgroup is missing' >&2; exit 70; }
for file in "$child_userns" "$parent_userns" "$mnt" "$pidns" "$ipcns" "$utsns" "$netns"; do
    [[ -f "$file" && ! -L "$file" ]] || { echo "aicli-run-pin-userns: namespace file is missing: $file" >&2; exit 70; }
done
target_owner=$(stat -Lc '%u:%g:%a' -- "$child_userns") || exit 70
[[ "$target_owner" == '0:0:600' ]] || { echo 'aicli-run-pin-userns: child namespace target is not root-only' >&2; exit 70; }
target_fs=$(findmnt --noheadings --output FSTYPE --target "$child_userns" 2>/dev/null || true)
[[ "$target_fs" != nsfs ]] || { echo 'aicli-run-pin-userns: child namespace is already mounted' >&2; exit 70; }

expected_user=$(stat -Lc '%i' -- "$parent_userns") || exit 70
expected_mnt=$(stat -Lc '%i' -- "$mnt") || exit 70
expected_pid=$(stat -Lc '%i' -- "$pidns") || exit 70
expected_ipc=$(stat -Lc '%i' -- "$ipcns") || exit 70
expected_uts=$(stat -Lc '%i' -- "$utsns") || exit 70
expected_net=$(stat -Lc '%i' -- "$netns") || exit 70

namespace_names=(user mnt pid ipc uts net)
declare -A namespace_fds=()
close_namespace_fds() {
    local name fd
    for name in "${!namespace_fds[@]}"; do
        fd=${namespace_fds[$name]}
        exec {fd}<&-
    done
    namespace_fds=()
}
read_start_time() {
    local stat_line
    IFS= read -r stat_line < "$1/stat" || return 1
    stat_line=${stat_line##*) }
    set -- $stat_line
    printf '%s\n' "${20:-}"
}

while IFS= read -r pid; do
    [[ "$pid" =~ ^[0-9]+$ ]] || continue
    proc="$proc_root/$pid"
    exec {proc_fd}<"$proc" 2>/dev/null || continue
    proc_ref="/proc/$BASHPID/fd/$proc_fd"
    start_before=$(read_start_time "$proc_ref" 2>/dev/null) || { exec {proc_fd}<&-; continue; }
    uid_line=$(awk '/^Uid:/ {print $2, $3, $4, $5; exit}' "$proc_ref/status" 2>/dev/null || true)
    if [[ "$uid_line" != "$run_uid $run_uid $run_uid $run_uid" ]]; then exec {proc_fd}<&-; continue; fi
    namespace_fds=()
    opened=1
    for name in "${namespace_names[@]}"; do
        if ! exec {ns_fd}<"$proc_ref/ns/$name" 2>/dev/null; then
            opened=0
            break
        fi
        namespace_fds[$name]=$ns_fd
    done
    if ((opened == 0)); then
        close_namespace_fds
        exec {proc_fd}<&-
        continue
    fi
    user_inode=$(stat -Lc '%i' -- "/proc/$BASHPID/fd/${namespace_fds[user]}" 2>/dev/null || true)
    mnt_inode=$(stat -Lc '%i' -- "/proc/$BASHPID/fd/${namespace_fds[mnt]}" 2>/dev/null || true)
    pid_inode=$(stat -Lc '%i' -- "/proc/$BASHPID/fd/${namespace_fds[pid]}" 2>/dev/null || true)
    ipc_inode=$(stat -Lc '%i' -- "/proc/$BASHPID/fd/${namespace_fds[ipc]}" 2>/dev/null || true)
    uts_inode=$(stat -Lc '%i' -- "/proc/$BASHPID/fd/${namespace_fds[uts]}" 2>/dev/null || true)
    net_inode=$(stat -Lc '%i' -- "/proc/$BASHPID/fd/${namespace_fds[net]}" 2>/dev/null || true)
    uid_check=$(awk '/^Uid:/ {print $2, $3, $4, $5; exit}' "$proc_ref/status" 2>/dev/null || true)
    start_after=$(read_start_time "$proc_ref" 2>/dev/null || true)
    still_listed=0
    grep -Fxq -- "$pid" "$group/cgroup.procs" && still_listed=1
    if [[ "$start_before" != '' && "$start_after" == "$start_before" \
        && "$user_inode" != '' && "$user_inode" != "$expected_user" \
        && "$mnt_inode" == "$expected_mnt" && "$pid_inode" == "$expected_pid" \
        && "$ipc_inode" == "$expected_ipc" && "$uts_inode" == "$expected_uts" \
        && "$net_inode" == "$expected_net" && "$uid_check" == "$run_uid $run_uid $run_uid $run_uid" \
        && "$still_listed" == 1 ]]; then
        if ! mount --bind "/proc/$BASHPID/fd/${namespace_fds[user]}" "$child_userns"; then
            close_namespace_fds
            exec {proc_fd}<&-
            echo 'aicli-run-pin-userns: the child user namespace could not be mounted' >&2
            exit 70
        fi
        pinned_inode=$(stat -Lc '%i' -- "$child_userns" 2>/dev/null || true)
        pinned_fs=$(findmnt --noheadings --output FSTYPE --target "$child_userns" 2>/dev/null || true)
        close_namespace_fds
        exec {proc_fd}<&-
        if [[ "$pinned_inode" != "$user_inode" || "$pinned_fs" != nsfs ]]; then
            umount -- "$child_userns" >/dev/null 2>&1 || true
            echo 'aicli-run-pin-userns: the pinned child user namespace failed verification' >&2
            exit 70
        fi
        exit 0
    fi
    close_namespace_fds
    exec {proc_fd}<&-
done < "$group/cgroup.procs"

echo 'aicli-run-pin-userns: no matching child user namespace belongs to this run' >&2
exit 70
