#!/bin/bash
# Close root-owned control descriptors before the restricted command starts.
set -euo pipefail
# CI test switch: a non-root process honours it; root honours it only with the CI marker file (a root-owned regular file that production never has).
if [[ "${AICLI_TEST_TMP:-}" == 1 ]] && ((EUID == 0)) && ! [[ -f /run/aicli-ci-test-marker && ! -L /run/aicli-ci-test-marker && -O /run/aicli-ci-test-marker ]]; then
    unset AICLI_TEST_TMP
    printf "%s\n" "aicli-run-command: the test switch is ignored for root without the CI marker" >&2
fi
run_uid=${1:-}
run_gid=${2:-}
shift 2 || true
[[ "$run_uid" =~ ^[0-9]+$ && "$run_uid" -ge 1000 && "$run_gid" == 100 ]] || exit 64
[[ "${1:-}" == -- ]] || exit 64
shift
exec >/dev/null 2>&1
for entry in /proc/$$/fd/*; do
    fd=${entry##*/}
    [[ "$fd" =~ ^[0-9]+$ ]] || continue
    ((fd > 2)) || continue
    eval "exec ${fd}>&-"
done
(($# > 0)) || exit 64
if [[ "${AICLI_TEST_TMP:-0}" == 1 ]]; then exec "$@"; fi

# This persistent namespace maps only its root identity to the run user's host
# UID and GID. Do not accept a mapping for host root: its capabilities would
# then bypass DAC checks on host-root-owned files.
uid_map=$(</proc/self/uid_map)
gid_map=$(</proc/self/gid_map)
[[ "$uid_map" =~ ^[[:space:]]*0[[:space:]]+"$run_uid"[[:space:]]+1[[:space:]]*$ ]] || exit 70
[[ "$gid_map" =~ ^[[:space:]]*0[[:space:]]+"$run_gid"[[:space:]]+1[[:space:]]*$ ]] || exit 70

# Create the one child user namespace as the mapped run user. It consumes the
# per-user slot left by max_user_namespaces=1 in the parent view. The agent
# cannot raise that parent limit or return to the parent user namespace.
exec /usr/bin/setpriv --clear-groups --no-new-privs \
    --bounding-set=-all --inh-caps=-all -- \
    /usr/bin/unshare --user --map-user="$run_uid" --map-group="$run_gid" --fork \
        /bin/bash -c '
set -euo pipefail
if /usr/bin/unshare --user --fork /bin/true >/dev/null 2>&1; then exit 70; fi
# The outer setpriv drops the host bounding and inheritable sets before entry.
# The kernel gives the child namespace a full bounding set, but the mapped run
# user has no effective or permitted capabilities. Keep no-new-privs on command.
exec /usr/bin/setpriv --no-new-privs -- "$@"
' aicli-run-command-inside "$@"
