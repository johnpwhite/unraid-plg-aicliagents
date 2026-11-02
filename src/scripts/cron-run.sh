#!/bin/bash
# AICliAgents: run one scheduled job, and report it when the server has a job reporter.
#
#   cron-run.sh <job> [--frequent] -- <command> [args...]
#
# Every cron line the plugin writes goes through this script (docs/specs/CRON_JOB_REPORTING.md).
# The reporter is OPTIONAL. The config key job_report_command (in unraid-aicliagents.cfg)
# holds the reporter command. The reporter must accept:
#     <reporter...> <job-id> [--frequent] -- <command> [args...]
# and must run the command and return its exit code. The job id is "<short hostname>.<job>".
#
# Rules this script keeps:
#   - key empty, or a path in the value is missing: the job runs exactly as before.
#   - the reporter breaks before it starts the job: the job runs here, once.
#   - the job's exit code is always the exit code of this script.
set -u

SELF="$(readlink -f "${BASH_SOURCE[0]}" 2>/dev/null || echo "${BASH_SOURCE[0]}")"

# Internal re-entry: the reporter runs this to start the job. The marker file proves the job started.
if [ "${1:-}" = "--mark" ]; then
    marker="${2:-}"
    shift 2
    [ "${1:-}" = "--" ] && shift
    [ -n "$marker" ] && : > "$marker" 2>/dev/null
    exec "$@"
fi

job="${1:-}"
[ -n "$job" ] || { echo "usage: cron-run.sh <job> [--frequent] -- <command> [args...]" >&2; exit 2; }
shift
frequent=()
if [ "${1:-}" = "--frequent" ]; then frequent=(--frequent); shift; fi
[ "${1:-}" = "--" ] && shift
[ "$#" -gt 0 ] || { echo "cron-run.sh: no command given" >&2; exit 2; }

# The shared resolver names the flash root. If it cannot load, the job runs as before.
set +u
# shellcheck source=/dev/null
source "$(dirname "$SELF")/storage/resolve_paths.sh" 2>/dev/null || exec "$@"
set -u
CFG="${AICLI_FLASH_ROOT:-}/unraid-aicliagents.cfg"
reporter_line=""
if [ -r "$CFG" ]; then
    reporter_line="$(sed -n 's/^job_report_command="\(.*\)"[[:space:]]*$/\1/p' "$CFG" 2>/dev/null | head -n1)"
fi

# No reporter configured: run the job as before.
[ -n "$reporter_line" ] || exec "$@"

read -r -a reporter <<< "$reporter_line"
usable=1
command -v "${reporter[0]}" >/dev/null 2>&1 || usable=0
for word in "${reporter[@]}"; do
    case "$word" in
        /*) [ -f "$word" ] || usable=0 ;;
    esac
done
[ "$usable" -eq 1 ] || exec "$@"

marker="$(mktemp 2>/dev/null)" || exec "$@"
rm -f "$marker"
host="$(hostname -s 2>/dev/null || echo unknown)"

"${reporter[@]}" "$host.$job" ${frequent[@]+"${frequent[@]}"} -- /bin/bash "$SELF" --mark "$marker" -- "$@"
rc=$?

if [ -e "$marker" ]; then
    rm -f "$marker"
    exit "$rc"
fi
# The reporter did not start the job. Run it here so the job is never lost.
"$@"
exit $?
