#!/bin/bash
# AICliAgents: run one scheduled job, write its run record, and report it when the server has a job reporter.
#
#   cron-run.sh <job> [--frequent] [--interval=<seconds>] -- <command> [args...]
#
# Every cron line the plugin writes goes through this script (docs/specs/CRON_JOB_REPORTING.md).
# After each run this script writes ONE record, $AICLI_JOBS_DIR/<job>.json (default
# $AICLI_STATE_ROOT/jobs, from storage/resolve_paths.sh). Another tool can read the record.
# The record is a stable interface, versioned by its "schema" field. A failure to write the
# record never changes the job's exit code.
#
# The job can refine its record: the runner exports AICLI_JOB_RESULT, a path (child processes of the job see it too). The job may write
# there {"status":"warn|skipped|ok|fail","summary":"...","details":["..."]}.
#
# The reporter is OPTIONAL. The config key job_report_command (in unraid-aicliagents.cfg)
# holds the reporter command. The reporter must accept:
#     <reporter...> <job-id> [--frequent] -- <command> [args...]
# and must run the command and return its exit code. The job id is "<short hostname>.<job>".
#
# Rules this script keeps:
#   - key empty, or a path in the value is missing: the job runs and is recorded here.
#   - the reporter breaks before it starts the job: the job runs here, once.
#   - the job's exit code is always the exit code of this script.
#   - one run writes one record: the code that runs the job also writes the record.
set -u

SELF="$(readlink -f "${BASH_SOURCE[0]}" 2>/dev/null || echo "${BASH_SOURCE[0]}")"

# JSON string content from $1: printable ASCII only, backslash and quote escaped.
json_esc() {
    local s
    s="$(printf '%s' "$1" | LC_ALL=C tr -cd '\040-\176')"
    s="${s//\\/\\\\}"
    s="${s//\"/\\\"}"
    printf '%s' "$s"
}

# Read the job's result file into RES_STATUS, RES_SUMMARY, RES_DETAILS (array). Never fails.
parse_result() {
    RES_STATUS=""; RES_SUMMARY=""; RES_DETAILS=()
    local file="$1" text raw ph=$'\001' item
    [ -s "$file" ] || return 0
    text="$(head -c 8192 "$file" 2>/dev/null | LC_ALL=C tr '\n\r\t' '   ' | LC_ALL=C tr -cd '\040-\176')"
    RES_STATUS="$(printf '%s' "$text" | sed -n 's/.*"status"[[:space:]]*:[[:space:]]*"\(ok\|warn\|skipped\|fail\)".*/\1/p' | head -n1)"
    raw="$(printf '%s' "$text" | sed -n 's/.*"summary"[[:space:]]*:[[:space:]]*"\(\([^"\\]\|\\.\)*\)".*/\1/p' | head -n1)"
    RES_SUMMARY="$(unjson "$raw")"
    RES_SUMMARY="${RES_SUMMARY:0:200}"
    local body
    body="$(printf '%s' "$text" | sed -n 's/.*"details"[[:space:]]*:[[:space:]]*\[\(\([^]"]\|"\(\([^"\\]\|\\.\)*\)"\)*\)\].*/\1/p' | head -n1)"
    if [ -n "$body" ]; then
        while IFS= read -r item; do
            [ "${#RES_DETAILS[@]}" -ge 10 ] && break
            item="${item#\"}"; item="${item%\"}"
            item="$(unjson "$item")"
            [ -n "$item" ] && RES_DETAILS+=("${item:0:200}")
        done < <(printf '%s' "$body" | grep -o '"\([^"\\]\|\\.\)*"' 2>/dev/null)
    fi
    return 0
}

# Undo JSON string escapes in $1 (already printable ASCII). Unicode escapes become "?".
unjson() {
    local v="$1" ph=$'\001'
    v="${v//\\\\/$ph}"
    v="$(printf '%s' "$v" | sed -E 's/\\u[0-9a-fA-F]{4}/?/g')"
    v="${v//\\n/ }"; v="${v//\\t/ }"; v="${v//\\r/ }"
    v="${v//\\\"/\"}"; v="${v//\\\//\/}"
    v="${v//\\/}"
    v="${v//$ph/\\}"
    printf '%s' "$v"
}

# Write the run record. Arguments: job interval started_epoch started_iso rc result_file
write_record() {
    local job="$1" interval="$2" t0="$3" iso0="$4" rc="$5" resfile="$6"
    local t1 iso1 dir fname tmp host ver status summary details="" i
    t1="$(date +%s 2>/dev/null)" || t1="$t0"
    iso1="$(date +%Y-%m-%dT%H:%M:%S%:z 2>/dev/null)" || iso1=""
    parse_result "$resfile"
    status="ok"; summary="finished"
    if [ "$rc" -ne 0 ] 2>/dev/null; then
        status="fail"; summary="failed with exit code $rc"
    elif [ -n "$RES_STATUS" ]; then
        status="$RES_STATUS"
    fi
    [ -n "$RES_SUMMARY" ] && summary="$RES_SUMMARY"
    host="$(hostname -s 2>/dev/null || echo unknown)"
    ver=""
    ver="$(grep -o 'version="[^"]*"' "${AICLI_PLG_FILE:-/var/log/plugins/unraid-aicliagents.plg}" 2>/dev/null | head -n1 | cut -d'"' -f2)"
    if [ -n "$ver" ]; then ver="\"$(json_esc "$ver")\""; else ver="null"; fi
    [ -n "$interval" ] || interval="null"
    if [ "${#RES_DETAILS[@]}" -gt 0 ]; then
        for i in "${RES_DETAILS[@]}"; do details="${details:+$details,}\"$(json_esc "$i")\""; done
        details=",\"details\":[$details]"
    fi
    # The state root comes from storage/resolve_paths.sh (exported to a reporter child too). With
    # no state root (the resolver did not load) the job still runs, and no record is written.
    dir="${AICLI_JOBS_DIR:-${AICLI_STATE_ROOT:+$AICLI_STATE_ROOT/jobs}}"
    [ -n "$dir" ] || return 0
    fname="$(printf '%s' "$job" | LC_ALL=C tr -c 'A-Za-z0-9._-' '_')"
    while [ "${fname#.}" != "$fname" ]; do fname="${fname#.}"; done
    [ -n "$fname" ] || fname="job"
    mkdir -p "$dir" 2>/dev/null || return 0
    chmod 755 "$dir" 2>/dev/null
    tmp="$(mktemp "$dir/.${fname}.XXXXXX" 2>/dev/null)" || return 0
    printf '{"schema":1,"job":"%s","host":"%s","started_at":"%s","ended_at":"%s","duration_s":%s,"rc":%s,"status":"%s","summary":"%s","interval_s":%s,"plugin_version":%s%s}\n' \
        "$(json_esc "$job")" "$(json_esc "$host")" "$iso0" "$iso1" "$((t1 - t0))" "$rc" "$status" \
        "$(json_esc "$summary")" "$interval" "$ver" "$details" > "$tmp" 2>/dev/null && [ -s "$tmp" ] || { rm -f "$tmp" 2>/dev/null; return 0; }
    chmod 644 "$tmp" 2>/dev/null
    mv -f "$tmp" "$dir/$fname.json" 2>/dev/null || rm -f "$tmp" 2>/dev/null
    return 0
}

# Run the command once, write the record, return the command's exit code.
# Arguments: job interval command...
run_and_record() {
    local job="$1" interval="$2" t0 iso0 resfile rc
    shift 2
    [ "$#" -gt 0 ] || return 2   # no command: nothing ran, so write no record
    case "$interval" in ''|*[!0-9]*|0) interval="" ;; esac   # 0 means unknown
    t0="$(date +%s 2>/dev/null)" || t0=0
    iso0="$(date +%Y-%m-%dT%H:%M:%S%:z 2>/dev/null)" || iso0=""
    resfile="$(mktemp 2>/dev/null)" || resfile=""
    if [ -n "$resfile" ]; then
        AICLI_JOB_RESULT="$resfile" "$@"
    else
        "$@"
    fi
    rc=$?
    ( write_record "$job" "$interval" "$t0" "$iso0" "$rc" "$resfile" ) 2>/dev/null || true
    [ -n "$resfile" ] && rm -f "$resfile" 2>/dev/null
    return "$rc"
}

# The whole body is in main(), and the last line calls it. Bash parses main() whole before it
# runs, so an overwrite of this file during a long job cannot make the shell read wrong text.
main() {
    # Internal re-entry: the reporter runs this to start the job. The marker file proves the job started.
    # This path runs the job AND writes its record, so a reported run writes exactly one record.
    if [ "${1:-}" = "--mark" ]; then
        local marker="${2:-}" mjob minterval
        if [ "${3:-}" = "--" ]; then
            # Old shape (a runner from before the run records started this reporter run):
            # --mark <marker> -- <command>. Run the command as the old script did, write no record.
            shift 3
            [ -n "$marker" ] && : > "$marker" 2>/dev/null
            [ "$#" -gt 0 ] || return 2
            exec "$@"
        fi
        mjob="${3:-job}"
        minterval="${4:-0}"
        shift 4 2>/dev/null || shift $#
        [ "${1:-}" = "--" ] && shift
        [ -n "$marker" ] && : > "$marker" 2>/dev/null
        run_and_record "$mjob" "$minterval" "$@"
        return $?
    fi

    local job="${1:-}"
    [ -n "$job" ] || { echo "usage: cron-run.sh <job> [--frequent] [--interval=<seconds>] -- <command> [args...]" >&2; return 2; }
    shift
    local frequent=() interval=""
    while [ "$#" -gt 0 ]; do
        case "$1" in
            --frequent) frequent=(--frequent); shift ;;
            --interval=*) interval="${1#--interval=}"; shift ;;
            *) break ;;
        esac
    done
    [ "${1:-}" = "--" ] && shift
    [ "$#" -gt 0 ] || { echo "cron-run.sh: no command given" >&2; return 2; }

    # The shared resolver names the flash root. If it cannot load, the job runs here without a reporter.
    set +u
    # shellcheck source=/dev/null
    if ! source "$(dirname "$SELF")/storage/resolve_paths.sh" 2>/dev/null; then
        set -u
        run_and_record "$job" "$interval" "$@"
        return $?
    fi
    set -u
    local CFG="${AICLI_FLASH_ROOT:-}/unraid-aicliagents.cfg" reporter_line=""
    if [ -r "$CFG" ]; then
        reporter_line="$(sed -n 's/^job_report_command="\(.*\)"[[:space:]]*$/\1/p' "$CFG" 2>/dev/null | head -n1)"
    fi

    local reporter=() usable=1 word
    [ -n "$reporter_line" ] && read -r -a reporter <<< "$reporter_line"
    # No reporter configured, or a blank setting: run the job here.
    if [ "${#reporter[@]}" -eq 0 ]; then
        run_and_record "$job" "$interval" "$@"
        return $?
    fi
    command -v "${reporter[0]}" >/dev/null 2>&1 || usable=0
    for word in "${reporter[@]}"; do
        case "$word" in
            /*) [ -f "$word" ] || usable=0 ;;
        esac
    done
    if [ "$usable" -ne 1 ]; then
        run_and_record "$job" "$interval" "$@"
        return $?
    fi

    local marker host rc
    marker="$(mktemp 2>/dev/null)" || { run_and_record "$job" "$interval" "$@"; return $?; }
    rm -f "$marker"
    host="$(hostname -s 2>/dev/null || echo unknown)"

    # The interval goes to the re-entry as a number; 0 means unknown.
    local ivarg="$interval"
    case "$ivarg" in ''|*[!0-9]*) ivarg=0 ;; esac
    "${reporter[@]}" "$host.$job" ${frequent[@]+"${frequent[@]}"} -- /bin/bash "$SELF" --mark "$marker" "$job" "$ivarg" -- "$@"
    rc=$?

    if [ -e "$marker" ]; then
        rm -f "$marker"
        return "$rc"
    fi
    # The reporter did not start the job. Run it here so the job is never lost.
    run_and_record "$job" "$interval" "$@"
    return $?
}

main "$@"
exit $?
