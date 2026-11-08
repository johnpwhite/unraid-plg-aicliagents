#!/bin/bash
# PID 1 for one restricted workspace. It keeps tmux's PID namespace alive.
set -uo pipefail
umask 077

ready=${1:-}
log=${2:-}
mounts=${3:-}
run_uid=${4:-}
run_gid=${5:-}
shift 5 || true
[[ "$ready" == /var/run/aicli-view/*.ready && "$log" == /var/run/aicli-view/*.launch.log ]] || exit 64
[[ -x "$mounts" && $# -gt 0 ]] || exit 64
[[ "$run_uid" =~ ^[0-9]+$ && "$run_gid" =~ ^[0-9]+$ && "$run_uid" -ge 1000 && "$run_gid" -eq 100 ]] || exit 64
if [[ ! -p /proc/self/fd/9 ]]; then
    printf '70\n' >&9 2>/dev/null || true
    echo 'aicli-run-init: the root-opened readiness pipe was not inherited' >&2
    exec 9>&- 2>/dev/null || true
    exit 70
fi

# Bubblewrap's --disable-userns method sets this limit in the run-user-mapped
# user namespace. The command helper enters the one child namespace and checks
# that the run user cannot create another one before the agent starts.
userns_limit='/proc/sys/user/max_user_namespaces'
# CI only: a test stands in for the proc file (the CI marker is not set in production).
# The switch counts only for a process that is not root, or when the CI marker file exists (production never has it).
if [[ "${AICLI_TEST_TMP:-0}" == 1 && -n "${AICLI_RUN_INIT_LIMIT_FILE:-}" ]]; then
    if ((EUID != 0)) || [[ -f /run/aicli-ci-test-marker && ! -L /run/aicli-ci-test-marker && -O /run/aicli-ci-test-marker ]]; then
        userns_limit=$AICLI_RUN_INIT_LIMIT_FILE
    else
        echo 'aicli-run-init: the test switch is ignored for root without the CI marker' >&2
    fi
fi
if ! printf '1' > "$userns_limit"; then
    echo 'aicli-run-init: the per-run user namespace limit could not be set' >&2
    printf '70\n' >&9
    exec 9>&-
    exit 70
fi
limit_readback=$(cat "$userns_limit" 2>/dev/null) || {
    echo 'aicli-run-init: the per-run user namespace limit could not be read back' >&2
    printf '70\n' >&9
    exec 9>&-
    exit 70
}
if [[ "$limit_readback" != 1 ]]; then
    echo 'aicli-run-init: the per-run user namespace limit did not take effect' >&2
    printf '70\n' >&9
    exec 9>&-
    exit 70
fi

# #929: ask root for the per-workspace folders BEFORE the mount helper runs. Root enters only this mount space and binds this
# workspace's private, home and task folders into it, so the helper finds them while the host parents stay closed to the run
# user. The request names this mount space; root compares it with the space file of the view. fd 7 is the root-opened answer pipe.
if [[ ! -p /proc/self/fd/7 ]]; then
    echo 'aicli-run-init: the root-opened answer pipe was not inherited' >&2
    printf '70\n' >&9
    exec 9>&-
    exit 70
fi
printf 'private %s\n' "$(readlink /proc/self/ns/mnt)" >&9
answer=''
read -r -t 60 answer <&7 || true
exec 7<&-
if [[ "$answer" != ok ]]; then
    echo 'aicli-run-init: root did not bind the private folders into the view' >&2
    printf '70\n' >&9
    exec 9>&-
    exit 70
fi

# The root-only readiness pipe stays open while the view hides /var/run. The
# mount helper closes it for the agent command and the tmux server.
rc=70
if "$mounts" "$@" 9>&- 7<&-; then rc=0; else rc=$?; fi
printf '%s\n' "$rc" >&9
exec 9>&-
((rc == 0)) || exit "$rc"
exec >/dev/null 2>&1

# PID 1 must stay alive while the detached tmux server exists. wait -n reaps
# orphaned children in this PID namespace; the cgroup kill ends this keeper
# and the child user namespace that holds the per-run namespace limit.
sleep 2147483647 &
while :; do wait -n || true; done
