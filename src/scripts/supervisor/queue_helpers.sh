#!/bin/bash
# queue_helpers.sh — Flat-file queue protocol for the Storage Durability Supervisor.
#
# Queue directory: /tmp/unraid-aicliagents/supervisor/queue/
# File naming:    <priority>_<epoch>_<type>_<id>_<op>.req
#   priority: 2-digit, 00 = highest (install), 05 = user-click, 10 = event,
#             50 = dirty-pressure, 99 = schedule.
# File content:   one-liner JSON with op details.
# Enqueue:        write .tmp + rename (atomic).
# Pop:            lex-sort gives priority order; lowest file = next op.
#
# Usage (source this file):
#   source queue_helpers.sh
#   queue_enqueue 10 home root bake workspace_close
#   path=$(queue_pop_next)
#   depth=$(queue_depth)

QUEUE_DIR="${QUEUE_DIR:-/tmp/unraid-aicliagents/supervisor/queue}"

# S-08 (#1353): job ledger directory — one <job_id>.json per tracked supervisor
# job, written atomically at every state transition (queued by queue_enqueue;
# running/done/failed/deferred by the supervisor). PHP reads it via
# SupervisorService::getJob/listJobs. Reaped by the supervisor's reconcile pass
# (done after 1 h, failed/deferred after 24 h).
JOBS_DIR="${JOBS_DIR:-/tmp/unraid-aicliagents/supervisor/jobs}"
# Real-time activity-tray push bridge (see _job_activity_push). Overridable so a
# sandbox/test can point it at a non-existent path to disable the push.
AICLI_SYNC_ACTIVITY_PHP="${AICLI_SYNC_ACTIVITY_PHP:-/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/supervisor/sync-activity.php}"

# _job_activity_push <reason> <state> — push a USER-initiated job's transition to
# the activity tray in REAL TIME (Nchan via SupervisorService::syncJobActivities)
# instead of waiting for the tray's next list_activities poll, which is why the
# pill used to freeze at "queued". Heavily gated so it only fires in the live
# deployed supervisor:
#   - reason must be a user-click op (untracked jobs have no tray entry);
#   - the initial 'queued' is skipped (the PHP handler already registered+published it);
#   - skipped whenever AICLI_JOBS_DIR is set (sandbox/L3.5/unit isolation) so it
#     never writes to the real Nchan/activity store during tests;
#   - skipped if the bridge script is absent (container tests) or php is missing.
# Backgrounded with output discarded — never blocks or breaks the supervisor.
_job_activity_push() {
    local reason="$1" state="$2"
    case "$reason" in user_consolidate|user_persist|user_graduate) : ;; *) return 0 ;; esac
    [ "$state" = "queued" ] && return 0
    [ -z "${AICLI_JOBS_DIR:-}" ] || return 0
    [ -f "$AICLI_SYNC_ACTIVITY_PHP" ] || return 0
    command -v php >/dev/null 2>&1 || return 0
    php -d display_errors=0 "$AICLI_SYNC_ACTIVITY_PHP" >/dev/null 2>&1 &
}

# Ensure queue directory exists
_queue_ensure_dir() {
    [ -d "$QUEUE_DIR" ] || mkdir -p "$QUEUE_DIR" 2>/dev/null || true
}

_jobs_ensure_dir() {
    [ -d "$JOBS_DIR" ] || mkdir -p "$JOBS_DIR" 2>/dev/null || true
}

# job_id_valid <job_id> — 0 when the id is filename-safe ([A-Za-z0-9._-]{1,128}).
# A malformed id is DROPPED by callers (the job just goes untracked), never
# quoted into JSON or a path.
job_id_valid() {
    case "${1:-}" in
        ''|*[!A-Za-z0-9._-]*) return 1 ;;
    esac
    [ "${#1}" -le 128 ] || return 1
    return 0
}

# job_ledger_path <job_id>
job_ledger_path() { printf '%s/%s.json' "$JOBS_DIR" "$1"; }

# job_ledger_field <job_id> <field> — read one top-level field (string/int).
job_ledger_field() { queue_read_field "$(job_ledger_path "$1")" "$2"; }

# job_ledger_write <job_id> <op> <type> <id> <state> <exit> <defer_reason> \
#                  <attempt> <queued_at> <started_at> <finished_at> <reason> [trace]
# Writes the full ledger JSON atomically (tmp + rename). Empty <exit> /
# <defer_reason> / <started_at> / <finished_at> emit JSON null. The ledger
# RECORDS the op's exit code verbatim — it never reinterprets the exit-code
# contract (0 ok / 2 deferred / 4 precondition / other fail).
job_ledger_write() {
    local job_id="${1:-}" op="${2:-}" type="${3:-}" id="${4:-}" state="${5:-queued}"
    local exit_code="${6:-}" defer="${7:-}" attempt="${8:-1}"
    local queued_at="${9:-}" started_at="${10:-}" finished_at="${11:-}"
    local reason="${12:-}" trace="${13:-}"

    job_id_valid "$job_id" || return 1
    _jobs_ensure_dir

    local now; now=$(date +%s)
    case "$attempt"   in ''|*[!0-9]*) attempt=1 ;; esac
    case "$queued_at" in ''|*[!0-9]*) queued_at="$now" ;; esac

    local exit_json="null";     case "$exit_code"   in *[!0-9]*|'') : ;; *) exit_json="$exit_code" ;; esac
    local started_json="null";  case "$started_at"  in *[!0-9]*|'') : ;; *) started_json="$started_at" ;; esac
    local finished_json="null"; case "$finished_at" in *[!0-9]*|'') : ;; *) finished_json="$finished_at" ;; esac
    local defer_json="null"
    case "$defer" in
        '') : ;;
        *[!a-z0-9_]*) : ;;   # defer reasons are lower_snake enum tokens — drop anything else
        *) defer_json="\"$defer\"" ;;
    esac
    local trace_json="null"
    case "$trace" in
        '') : ;;
        *[!a-z0-9]*) : ;;
        *) [ "${#trace}" -ge 4 ] && [ "${#trace}" -le 16 ] && trace_json="\"$trace\"" ;;
    esac
    # reason is recorded for diagnostics; restrict to the enqueue-safe charset.
    reason=$(printf '%s' "$reason" | tr -cd 'A-Za-z0-9_:.-')

    local json
    json=$(printf '{"job_id":"%s","op":"%s","entity":"%s/%s","state":"%s","exit":%s,"defer_reason":%s,"phase":null,"attempt":%d,"queued_at":%d,"started_at":%s,"finished_at":%s,"reason":"%s","trace":%s,"updated_at":%d}' \
        "$job_id" "$op" "$type" "$id" "$state" "$exit_json" "$defer_json" \
        "$attempt" "$queued_at" "$started_json" "$finished_json" "$reason" "$trace_json" "$now")

    local dest tmp
    dest="$(job_ledger_path "$job_id")"
    tmp="${dest}.tmp.$$"
    printf '%s\n' "$json" > "$tmp" 2>/dev/null || return 1
    mv -f "$tmp" "$dest" 2>/dev/null || { rm -f "$tmp" 2>/dev/null; return 1; }
    # Real-time push so the activity tray reflects this transition immediately
    # instead of only on its next poll (gated — see _job_activity_push).
    _job_activity_push "$reason" "$state"
    return 0
}

# queue_enqueue <priority> <type> <id> <op> <reason> [trace] [job_id]
# priority is a number 0-99; padded to 2 digits for lex sort.
# Writes an atomic req file to the queue directory.
# Returns 0 on success, 1 on failure.
#
# R-06 (#1370): [trace] is the originating request's trace-correlation id
# (defaults to $AICLI_TRACE_ID when exported by the caller). When present and
# well-formed ([a-z0-9]{4,16}) it is recorded as an ADDITIVE "trace" key in the
# entry JSON; the supervisor re-exports it while executing the op so the
# AJAX → queue → supervisor → storage-script chain shares one join key.
#
# S-08 (#1353): [job_id] is the optional job-ledger key. When present and valid
# (job_id_valid), the entry JSON gains an ADDITIVE "job" key AND a ledger entry
# is written at state=queued. A re-enqueue of an EXISTING job (the supervisor's
# defer-requeue path) preserves the ledger's attempt counter and original
# queued_at, so backoff/elapsed-wait maths survive the round trip.
queue_enqueue() {
    local priority="${1:-99}"
    local type="${2:-}"
    local id="${3:-}"
    local op="${4:-bake}"
    local reason="${5:-manual}"
    local trace="${6:-${AICLI_TRACE_ID:-}}"
    local job_id="${7:-}"

    _queue_ensure_dir

    # Pad priority to 2 digits
    local prio_padded
    prio_padded=$(printf '%02d' "$priority")

    local epoch
    epoch=$(date +%s)

    # Sanitize id for safe filename (replace / and space with _)
    local safe_id
    safe_id=$(printf '%s' "$id" | tr '/ ' '__')

    local filename="${prio_padded}_${epoch}_${type}_${safe_id}_${op}.req"
    local tmp_path="${QUEUE_DIR}/.${filename}.tmp.$$"
    local final_path="${QUEUE_DIR}/${filename}"

    # Validate trace shape — a malformed id is dropped, never quoted into JSON.
    case "$trace" in
        *[!a-z0-9]*) trace="" ;;
    esac
    if [ -n "$trace" ] && { [ "${#trace}" -lt 4 ] || [ "${#trace}" -gt 16 ]; }; then
        trace=""
    fi

    # Validate job_id shape — malformed ids are dropped (untracked job), never
    # quoted into JSON or used as a ledger filename.
    job_id_valid "$job_id" || job_id=""

    # Build JSON payload (trace / job keys are additive — absent when unknown)
    local extra=""
    [ -n "$trace" ]  && extra="${extra},\"trace\":\"${trace}\""
    [ -n "$job_id" ] && extra="${extra},\"job\":\"${job_id}\""
    local json
    json=$(printf '{"type":"%s","id":"%s","op":"%s","reason":"%s"%s,"queued_at":%d}' \
        "$type" "$id" "$op" "$reason" "$extra" "$epoch")

    # Atomic write: tmp -> rename
    printf '%s\n' "$json" > "$tmp_path" 2>/dev/null || return 1
    mv -f "$tmp_path" "$final_path" 2>/dev/null || { rm -f "$tmp_path" 2>/dev/null; return 1; }

    # S-08: ledger entry at state=queued. A re-enqueue (defer-requeue) preserves
    # the existing attempt counter and the ORIGINAL queued_at so the supervisor's
    # backoff/elapsed-wait maths hold across requeues.
    if [ -n "$job_id" ]; then
        local prev_attempt prev_queued
        prev_attempt="$(job_ledger_field "$job_id" attempt 2>/dev/null)"
        prev_queued="$(job_ledger_field "$job_id" queued_at 2>/dev/null)"
        job_ledger_write "$job_id" "$op" "$type" "$id" "queued" "" "" \
            "${prev_attempt:-1}" "${prev_queued:-$epoch}" "" "" "$reason" "$trace" || true
    fi
    return 0
}

# queue_pop_next
# Echoes the path of the next (lowest-priority) queue file, or nothing if empty.
# Does NOT delete the file — caller must delete after processing.
queue_pop_next() {
    _queue_ensure_dir
    # lex sort; first non-tmp .req file is the highest priority
    local f
    for f in "$QUEUE_DIR"/*.req; do
        [ -f "$f" ] || continue
        echo "$f"
        return 0
    done
    return 1
}

# queue_depth
# Echoes the number of pending .req files in the queue.
queue_depth() {
    _queue_ensure_dir
    local count=0
    local f
    for f in "$QUEUE_DIR"/*.req; do
        [ -f "$f" ] && count=$((count + 1))
    done
    echo "$count"
}

# queue_read_field <path> <field>
# Reads a single JSON field from a queue file (bash-only, no jq dependency).
# Only handles simple string and integer values at top level.
queue_read_field() {
    local path="$1"
    local field="$2"
    [ -f "$path" ] || return 1
    # Extract "field":"value" or "field":number
    local raw
    raw=$(grep -oP "\"${field}\":\s*\K(\"[^\"]*\"|[0-9]+)" "$path" 2>/dev/null | head -1)
    # Strip surrounding quotes if present
    raw="${raw#\"}"
    raw="${raw%\"}"
    echo "$raw"
}
