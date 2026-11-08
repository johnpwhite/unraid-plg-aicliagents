#!/bin/bash
# Create and control one restricted-run cgroup (docs/specs/AGENT_RUN_ENTRY_POINT.md R30).
set -euo pipefail
# CI test switch: a non-root process honours it; root honours it only with the CI marker file (a root-owned regular file that production never has).
if [[ "${AICLI_TEST_TMP:-}" == 1 ]] && ((EUID == 0)) && ! [[ -f /run/aicli-ci-test-marker && ! -L /run/aicli-ci-test-marker && -O /run/aicli-ci-test-marker ]]; then
    unset AICLI_TEST_TMP
    printf "%s\n" "aicli-run-cgroup: the test switch is ignored for root without the CI marker" >&2
fi
if [[ "${AICLI_TEST_TMP:-0}" != 1 ]]; then PATH='/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'; fi
export PATH

action=${1:-}
id=${2:-}
shift 2 || true
[[ "$id" =~ ^[A-Za-z0-9_-]{1,64}$ ]] || { echo 'aicli-run-cgroup: invalid workspace id' >&2; exit 64; }
root=/sys/fs/cgroup
if [[ -n "${AICLI_RUN_CGROUP_ROOT:-}" ]]; then
    [[ "${AICLI_TEST_TMP:-0}" == 1 ]] || fail 'the cgroup test root needs the CI test marker'
    root=$AICLI_RUN_CGROUP_ROOT
fi
group="$root/aicli-run-$id"
cleanup_active=0

fail() { echo "aicli-run-cgroup: $*" >&2; exit 70; }

wait_empty() {
    local wait_seconds=${1:-15}
    [[ "$wait_seconds" =~ ^[0-9]{1,2}$ ]] && ((wait_seconds >= 1 && wait_seconds <= 60)) || fail 'the empty wait limit is not valid'
    local deadline=$((SECONDS + wait_seconds))
    while [[ -d "$group" ]]; do
        if grep -qx 'populated 0' "$group/cgroup.events" 2>/dev/null; then return 0; fi
        if ((SECONDS >= deadline)); then return 1; fi
        sleep 0.1
    done
    return 0
}

cleanup_partial() {
    local rc=$?
    trap - EXIT
    if ((rc != 0)) && [[ "$cleanup_active" == 1 && -d "$group" ]]; then
        if grep -qx 'populated 0' "$group/cgroup.events" 2>/dev/null; then rmdir "$group" 2>/dev/null || true; fi
    fi
    exit "$rc"
}

case "$action" in
    create)
        [[ -f "$root/cgroup.controllers" && -f "$root/cgroup.subtree_control" ]] || fail 'cgroup v2 is not mounted'
        for controller in cpu memory pids; do
            grep -qw "$controller" "$root/cgroup.controllers" || fail "cgroup controller is missing: $controller"
            grep -qw "$controller" "$root/cgroup.subtree_control" || fail "cgroup controller is not enabled: $controller"
        done
        [[ ! -e "$group" ]] || fail 'the workspace cgroup already exists'
        cleanup_active=1
        trap cleanup_partial EXIT
        mkdir "$group" || fail 'the workspace cgroup could not be made'
        printf '200000 100000\n' > "$group/cpu.max" || fail 'the CPU limit could not be set'
        printf '8589934592\n' > "$group/memory.max" || fail 'the memory limit could not be set'
        printf '0\n' > "$group/memory.swap.max" || fail 'the swap limit could not be set'
        printf '256\n' > "$group/pids.max" || fail 'the process limit could not be set'
        cleanup_active=0
        trap - EXIT
        printf 'ready\n'
        ;;
    join)
        [[ -d "$group" && -w "$group/cgroup.procs" ]] || fail 'the workspace cgroup is not ready'
        printf '%s\n' "$$" > "$group/cgroup.procs" || fail 'the process could not join the workspace cgroup'
        (($# > 0)) || fail 'the command to start is missing'
        prlimit=$(command -v prlimit) || fail 'prlimit is missing, so per-process limits cannot be set'
        exec "$prlimit" --nofile=4096:4096 --core=0:0 --fsize=1073741824:1073741824 --cpu=3600:3600 -- "$@"
        ;;
    kill)
        [[ -d "$group" ]] || exit 0
        [[ -w "$group/cgroup.kill" ]] || fail 'the workspace cgroup cannot be stopped safely'
        printf '1\n' > "$group/cgroup.kill" || fail 'the workspace cgroup could not be stopped'
        ;;
    empty)
        [[ -d "$group" ]] || { printf 'empty\n'; exit 0; }
        if wait_empty "${3:-15}"; then printf 'empty\n'; else printf 'busy\n'; fi
        ;;
    remove)
        [[ -d "$group" ]] || exit 0
        wait_empty "${3:-15}" || fail 'the workspace cgroup still has processes'
        rmdir "$group" || fail 'the empty workspace cgroup could not be removed'
        ;;
    *) echo 'usage: aicli-run-cgroup.sh {create|join|kill|empty|remove} <workspace-id> [command...]' >&2; exit 64 ;;
esac
