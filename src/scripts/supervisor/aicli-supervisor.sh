#!/bin/bash
# aicli-supervisor.sh — Storage Durability Supervisor daemon
#
# Phase 3.2: reconcile + drain + bake triggers wired.
#
# Usage:
#   aicli-supervisor.sh              — start daemon (fork-ready; caller should nohup+disown)
#   aicli-supervisor.sh start        — explicit start alias (same behaviour)
#   aicli-supervisor.sh stop         — send TERM to running instance; wait; KILL if needed
#   aicli-supervisor.sh status       — print status JSON to stdout; exit 0=running 1=stopped
#   aicli-supervisor.sh --status     — alias for status

set -u

# ---------------------------------------------------------------------------
# Paths
# ---------------------------------------------------------------------------
SCRIPT_PATH="/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/supervisor/aicli-supervisor.sh"
RESOLVE_SH="/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/storage/resolve_paths.sh"
QUEUE_HELPERS_SH="/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/supervisor/queue_helpers.sh"
STORAGE_DIR="/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/storage"

PIDFILE="/var/run/aicli-supervisor.pid"
TICKFILE="/var/run/aicli-supervisor.tick"
WORKFILE="/var/run/aicli-supervisor.work.json"

# Bug #757: dedicated single-instance lock file. NEVER unlinked (it's on tmpfs,
# so a reboot clears it — which is correct). flock-on-an-fd of this file is the
# ONLY mutex; the pidfile is just the "who is the active PID" record. The lock fd
# ($SUP_LOCK_FD, assigned by `exec {SUP_LOCK_FD}>"$LOCKFILE"` in _do_start) is
# inherited by forked children — the heartbeat subshell and the spawned
# commit_stack.sh/consolidate_layers.sh work children MUST close it (else an
# orphaned child of a crashed supervisor keeps the lock held and no new
# supervisor can take over — the deadlock that v2026.05.12.04 hit and got
# reverted for).
LOCKFILE="/var/run/aicli-supervisor.lock"
SUP_LOCK_FD=""

# Status file lives in tmpfs — read by React UI.
STATUS_DIR="/tmp/unraid-aicliagents"
STATUSFILE="${STATUS_DIR}/supervisor.status.json"

# Supervisor-specific directories under tmpfs
SUPERVISOR_DIR="${STATUS_DIR}/supervisor"
HALTS_DIR="${SUPERVISOR_DIR}/halts"
CONSOLIDATE_FAILS_DIR="${SUPERVISOR_DIR}/consolidate-fails"
QUEUE_DIR="${SUPERVISOR_DIR}/queue"
# S-08 (#1353): job ledger + deferred-retry holding pen. JOBS_DIR is consumed by
# queue_helpers.sh (sourced below — it honours a pre-set value, same as QUEUE_DIR).
JOBS_DIR="${SUPERVISOR_DIR}/jobs"
JOB_RETRY_DIR="${SUPERVISOR_DIR}/jobs-retry"
ORPHAN_LOCK_DIR="/var/run"

DAEMON_VERSION="3.2.0"

# Config key — defaults (overridden by sourcing the cfg below)
# WP #748 Phase 1 (A/B/C): raised cadence defaults to reduce Flash wear.
SUPERVISOR_TICK="${supervisor_tick_seconds:-5}"
BAKE_SCHEDULE_MINUTES="${bake_schedule_minutes:-120}"
DIRTY_SOFT_MB="${dirty_threshold_soft_mb:-1024}"
DIRTY_SOFT_PCT="${dirty_threshold_soft_pct:-12.5}"
DIRTY_HARD_MB="${dirty_threshold_hard_mb:-2048}"
DIRTY_HARD_PCT="${dirty_threshold_hard_pct:-25}"
DIRTY_CRITICAL_MB="${dirty_threshold_critical_mb:-4096}"
DIRTY_CRITICAL_PCT="${dirty_threshold_critical_pct:-50}"
EMERGENCY_BAKE_COMP="${emergency_bake_compression:-lz4}"
# S-08 (#1353): total wall-clock budget for a deferred (target_not_mounted /
# bake_lock_held) mount job's requeue-with-backoff before it FAILS + notifies.
# UD devices mount udev-driven up to ~2 min after array start; 300 s covers
# that with margin. cfg key: storage_target_wait_s.
STORAGE_TARGET_WAIT_S="${storage_target_wait_s:-300}"
# S-10 (#1354): retention window for retired layers under <persist>/.graduated/
# after a graduate-to-passthrough migration (the rollback copies). Reaped by
# _op_reconcile once older than this. cfg key: graduated_retention_days.
GRADUATED_RETENTION_DAYS="${graduated_retention_days:-14}"
# S-10: total wall-clock budget for a deferred graduate job's requeue-with-backoff
# (60→300→600 s capped) before it FAILS + notifies. A graduate legitimately waits
# out long busy sessions, so the cap is 24 h — storage_target_wait_s does NOT apply.
GRADUATE_WAIT_CAP_S=86400
# OP#1381: total wall-clock budget for a deferred USER-INITIATED consolidate/bake
# (reason user_consolidate / user_persist) that keeps deferring mount_busy — the
# overlay is still held open by a live session. Requeued with 15→60→120 s backoff
# until the overlay frees (the last session closes → reconcile/session-close path
# re-enqueues, and this matures the parked retry) or this budget elapses, then it
# FAILS + notifies AND auto-relaunches the closed sessions in the BACKGROUND so the
# user is never stranded waiting for the UI (the close phase already closes the
# sessions itself — it no longer waits for the user to close the workspace — so the
# old "generous 1 h" rationale is obsolete; a stuck consolidate gives up fast and
# restores the user's sessions headlessly). cfg key: user_consolidate_wait_cap_s.
USER_CONSOLIDATE_WAIT_CAP_S="${user_consolidate_wait_cap_s:-180}"
# Phase 5: the old count-based consolidate thresholds (consolidate_layer_threshold_*)
# are gone — home consolidation is now driven by the storagectl `status` policy
# (layers >= consolidate_max_layers-2, or space pressure). See _check_consolidate_policy.

# Notify script path
NOTIFY_SCRIPT="/usr/local/emhttp/plugins/dynamix/scripts/notify"

# Notify rate-limit state (in-memory per boot)
_LAST_HARD_NOTIFY=0
_LAST_CRITICAL_NOTIFY=0

# ---------------------------------------------------------------------------
# Source canonical path resolver (provides lifecycle_log, path functions)
# ---------------------------------------------------------------------------
# shellcheck source=../storage/resolve_paths.sh
if [ -f "$RESOLVE_SH" ]; then
    # shellcheck disable=SC1090
    source "$RESOLVE_SH" 2>/dev/null || true
else
    lifecycle_log() { true; }
    agent_persist_path() { echo "/boot/config/plugins/unraid-aicliagents"; }
    home_persist_path() { echo "/boot/config/plugins/unraid-aicliagents/persistence"; }
    manifest_path() { echo "/boot/config/plugins/unraid-aicliagents/layer_manifest.json"; }
    zram_upper() { echo "/tmp/unraid-aicliagents/zram_upper/${1}s/${2}/upper"; }
fi

# F6 (WP#1331): the SINGLE manifest writer (reconcile's recovered-layer record).
# shellcheck source=../storage/manifest_write.sh
[ -f "$STORAGE_DIR/manifest_write.sh" ] && { source "$STORAGE_DIR/manifest_write.sh" 2>/dev/null || true; }

# ---------------------------------------------------------------------------
# Source queue helpers
# ---------------------------------------------------------------------------
# shellcheck source=queue_helpers.sh
if [ -f "$QUEUE_HELPERS_SH" ]; then
    # shellcheck disable=SC1090
    source "$QUEUE_HELPERS_SH" 2>/dev/null || true
fi

# ---------------------------------------------------------------------------
# Logging helpers (stderr only — stdout is reserved for --status JSON)
# ---------------------------------------------------------------------------
_log() {
    local level="$1"; shift
    printf '%s [%s] [Supervisor] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$level" "$*" >&2
}
log_info()  { _log INFO  "$@"; }
log_warn()  { _log WARN  "$@"; }
log_error() { _log ERROR "$@"; }

# ---------------------------------------------------------------------------
# Atomic JSON write helper
# Writes content atomically: tmp file -> fsync -> rename
# Usage: _atomic_json_write <dest_path> <json_content>
# ---------------------------------------------------------------------------
_atomic_json_write() {
    local dest="$1"
    local json="$2"
    local tmp="${dest}.tmp.$$.$(date +%s)"
    local dir
    dir="$(dirname "$dest")"
    [ -d "$dir" ] || mkdir -p "$dir" 2>/dev/null

    printf '%s\n' "$json" > "$tmp" 2>/dev/null || return 1
    sync "$tmp" 2>/dev/null || sync 2>/dev/null || true
    mv -f "$tmp" "$dest" 2>/dev/null || { rm -f "$tmp" 2>/dev/null; return 1; }
    return 0
}

# ---------------------------------------------------------------------------
# Build the idle work-state JSON
# ---------------------------------------------------------------------------
_idle_work_json() {
    local queue_depth="${1:-0}"
    local last_completed="${2:-null}"
    printf '{"state":"idle","op":null,"op_kind":null,"entity":null,"op_started_at":null,"op_max_duration_s":null,"child_pid":null,"queue_depth":%d,"last_completed_at":%s,"errors":[]}' \
        "$queue_depth" "$last_completed"
}

# ---------------------------------------------------------------------------
# Build the running work-state JSON
# ---------------------------------------------------------------------------
_running_work_json() {
    local op="$1"
    local entity="$2"
    local child_pid="$3"
    local started_at="$4"
    local max_dur="$5"
    local queue_depth="${6:-0}"
    printf '{"state":"running","op":"%s","op_kind":null,"entity":"%s","op_started_at":%d,"op_max_duration_s":%d,"child_pid":%s,"queue_depth":%d,"last_completed_at":null,"errors":[]}' \
        "$op" "$entity" "$started_at" "$max_dur" "$child_pid" "$queue_depth"
}

# ---------------------------------------------------------------------------
# Build the supervisor status JSON (written to STATUSFILE on state changes)
# ---------------------------------------------------------------------------
_status_json() {
    local state="${1:-idle}"
    local op="${2:-null}"
    local entity="${3:-null}"
    local queue="${4:-0}"
    local last_completed="${5:-null}"
    local now
    now="$(date +%s)"

    local op_json="null"
    [ "$op" != "null" ] && op_json="\"${op}\""
    local entity_json="null"
    [ "$entity" != "null" ] && entity_json="\"${entity}\""

    printf '{"daemon_version":"%s","state":"%s","op":%s,"entity":%s,"queue_depth":%d,"last_completed_at":%s,"tick_at":%d,"errors":[]}' \
        "$DAEMON_VERSION" "$state" "$op_json" "$entity_json" "$queue" "$last_completed" "$now"
}

# ---------------------------------------------------------------------------
# PID file helpers
# ---------------------------------------------------------------------------
_read_pidfile() {
    [ -f "$PIDFILE" ] || return 1
    local pid
    pid="$(cat "$PIDFILE" 2>/dev/null)" || return 1
    [ -n "$pid" ] || return 1
    echo "$pid"
}

_pid_alive() {
    local pid="$1"
    kill -0 "$pid" 2>/dev/null
}

_pidfile_valid() {
    local pid
    pid="$(_read_pidfile)" || return 1
    _pid_alive "$pid" || return 1
    local cmdline
    cmdline="$(tr '\0' ' ' < "/proc/$pid/cmdline" 2>/dev/null)" || return 1
    echo "$cmdline" | grep -qF "$SCRIPT_PATH" || return 1
    return 0
}

# ---------------------------------------------------------------------------
# Halt file helpers
# ---------------------------------------------------------------------------
# _halt_path <entity> [kind]
_halt_path() {
    local entity="${1:-}"
    local kind="${2:-}"
    local safe_entity
    safe_entity=$(printf '%s' "$entity" | tr '/ ' '__')
    if [ -n "$kind" ]; then
        echo "${HALTS_DIR}/${safe_entity}:${kind}"
    else
        echo "${HALTS_DIR}/${safe_entity}"
    fi
}

_write_halt() {
    local entity="${1:-}"
    local kind="${2:-}"
    local reason="${3:-unknown}"
    local path
    path="$(_halt_path "$entity" "$kind")"
    mkdir -p "$HALTS_DIR" 2>/dev/null || true
    printf '%s\n' "$reason" > "$path" 2>/dev/null || true
}

_halt_exists() {
    local entity="${1:-}"
    local kind="${2:-}"
    local path
    path="$(_halt_path "$entity" "$kind")"
    [ -f "$path" ]
}

# ---------------------------------------------------------------------------
# Notification helper
# Rate-limit: one notification per (entity, event) per boot.
# The rate-limit file is /tmp/unraid-aicliagents/supervisor/.notified/<key>
# ---------------------------------------------------------------------------
_NOTIFIED_DIR="${SUPERVISOR_DIR}/.notified"

_supervisor_notify() {
    local severity="${1:-warning}"  # warning or critical
    local subject="${2:-AICliAgents Supervisor}"
    local message="${3:-}"
    local rate_key="${4:-}"         # unique key for per-boot rate-limit
    local rate_limit_seconds="${5:-3600}"  # default: 1 per hour

    mkdir -p "$_NOTIFIED_DIR" 2>/dev/null || true

    if [ -n "$rate_key" ]; then
        # Sanitise: replace '/' with '_' so entity IDs (e.g. home/smokeuser)
        # don't create sub-directories under _NOTIFIED_DIR that were never mkdir'd.
        local sanitised_key="${rate_key//\//_}"
        local notified_file="${_NOTIFIED_DIR}/${sanitised_key}"
        if [ -f "$notified_file" ]; then
            local last_notify
            last_notify=$(cat "$notified_file" 2>/dev/null || echo 0)
            local now
            now=$(date +%s)
            local age=$(( now - last_notify ))
            if [ "$age" -lt "$rate_limit_seconds" ]; then
                return 0  # rate-limited
            fi
        fi
        date +%s > "$notified_file" || _log "WARN" "_supervisor_notify: failed to write rate-limit file $notified_file"
    fi

    if [ -x "$NOTIFY_SCRIPT" ]; then
        "$NOTIFY_SCRIPT" -e "AICliAgents" -s "$subject" -d "$message" -i "$severity" 2>/dev/null || true
    fi

    lifecycle_log "info" "supervisor" "notification_fired" \
        "{\"severity\":\"$severity\",\"subject\":\"$subject\",\"rate_key\":\"$rate_key\"}" 2>/dev/null || true
}

# ---------------------------------------------------------------------------
# Consolidate failure counter helpers
# ---------------------------------------------------------------------------
_consolidate_fail_path() {
    local entity="${1:-}"
    local safe_entity
    safe_entity=$(printf '%s' "$entity" | tr '/ ' '__')
    echo "${CONSOLIDATE_FAILS_DIR}/${safe_entity}"
}

_consolidate_fail_count() {
    local entity="${1:-}"
    local path
    path="$(_consolidate_fail_path "$entity")"
    if [ -f "$path" ]; then
        cat "$path" 2>/dev/null || echo 0
    else
        echo 0
    fi
}

_consolidate_fail_increment() {
    local entity="${1:-}"
    local path
    path="$(_consolidate_fail_path "$entity")"
    mkdir -p "$CONSOLIDATE_FAILS_DIR" 2>/dev/null || true
    local count
    count=$(_consolidate_fail_count "$entity")
    count=$(( count + 1 ))
    printf '%d\n' "$count" > "$path" 2>/dev/null || true
    echo "$count"
}

_consolidate_fail_reset() {
    local entity="${1:-}"
    local path
    path="$(_consolidate_fail_path "$entity")"
    rm -f "$path" 2>/dev/null || true
}

# ---------------------------------------------------------------------------
# Manifest reader helpers (bash — reads JSON without jq)
# ---------------------------------------------------------------------------

# _manifest_read <mpath>
# S-04 (#1352): single read seam for the grep-based helpers below — prefers the
# SHARED-locked read (manifest_read_locked from manifest_write.sh, sourced above)
# so reconcile/escalation never observe a torn mid-write manifest; plain cat
# fallback when the helper is unavailable (degraded source) — never blocking.
_manifest_read() {
    local mpath="${1:-}"
    [ -f "$mpath" ] || return 0
    if declare -f manifest_read_locked >/dev/null 2>&1; then
        manifest_read_locked "$mpath" 2>/dev/null || true
    else
        cat "$mpath" 2>/dev/null || true
    fi
}

# _manifest_get_entities
# Echoes a newline-separated list of entity keys from the manifest.
_manifest_get_entities() {
    local mpath
    mpath="$(manifest_path 2>/dev/null || echo '/boot/config/plugins/unraid-aicliagents/layer_manifest.json')"
    [ -f "$mpath" ] || return 0
    # Extract entity keys from JSON: "home/root": { ... }
    _manifest_read "$mpath" | grep -oP '(?<="entities"\s{0,4}:\s{0,4}\{)[^}]*' 2>/dev/null | \
        grep -oP '"[^"]+"\s*:' 2>/dev/null | \
        tr -d '":' | \
        tr -d ' ' | \
        grep -v '^$' || true
}

# _manifest_entity_layers <entity>
# Echoes a newline-separated list of filenames from the manifest for the entity.
_manifest_entity_layers() {
    local entity="${1:-}"
    local mpath
    mpath="$(manifest_path 2>/dev/null || echo '/boot/config/plugins/unraid-aicliagents/layer_manifest.json')"
    [ -f "$mpath" ] || return 0
    # Extract filenames from expected_layers for this entity
    # Look for the entity block and extract filenames
    local in_entity=0
    local depth=0
    # Simple extraction: after entity key, grab filename values
    _manifest_read "$mpath" | grep -oP "\"filename\":\s*\"\K[^\"]*" 2>/dev/null | head -100 || true
}

# _manifest_entity_persist_path <entity>
_manifest_entity_persist_path() {
    local entity="${1:-}"
    local mpath
    mpath="$(manifest_path 2>/dev/null || echo '/boot/config/plugins/unraid-aicliagents/layer_manifest.json')"
    [ -f "$mpath" ] || return 0
    _manifest_read "$mpath" | grep -A5 "\"${entity}\"" 2>/dev/null | \
        grep -oP '"current_persistence_path":\s*"\K[^"]*' | head -1 || true
}

# _manifest_entity_sha256 <filename>
# Extracts sha256 for a filename from the manifest
_manifest_sha256() {
    local filename="${1:-}"
    local mpath
    mpath="$(manifest_path 2>/dev/null || echo '/boot/config/plugins/unraid-aicliagents/layer_manifest.json')"
    [ -f "$mpath" ] || return 0
    # Find sha256 near filename — look for the filename line and the sha256 after it
    _manifest_read "$mpath" | grep -A3 "\"filename\":\s*\"${filename}\"" 2>/dev/null | \
        grep -oP '"sha256":\s*"\K[^"]*' | head -1 || true
}

# _manifest_last_known_good <entity>
_manifest_last_known_good() {
    local entity="${1:-}"
    local mpath
    mpath="$(manifest_path 2>/dev/null || echo '/boot/config/plugins/unraid-aicliagents/layer_manifest.json')"
    [ -f "$mpath" ] || return 0
    _manifest_read "$mpath" | grep -A10 "\"${entity}\"" 2>/dev/null | \
        grep -oP '"last_known_good_at":\s*"\K[^"]*' | head -1 || true
}

# ---------------------------------------------------------------------------
# Layer file glob helper
# ---------------------------------------------------------------------------
_glob_layers() {
    local type="${1:-}"
    local id="${2:-}"
    local persist_path="${3:-}"
    [ -d "$persist_path" ] || return 0
    local safe_id
    safe_id=$(printf '%s' "$id" | sed 's/[^A-Za-z0-9._-]/_/g')
    shopt -s nullglob
    local files=("$persist_path/${type}_${safe_id}_"*.sqsh)
    shopt -u nullglob
    for f in "${files[@]:-}"; do
        [ -f "$f" ] && echo "$f"
    done
}

# F1 (WP#1325): echo ONLY the contents of the intent's "delete":[ ... ] array, so a
# membership test can't match the "keep" field. The supervisor does not source
# common.sh; this MUST mirror common.sh _intent_delete_segment. Layer basenames
# never contain '[' or ']'.
_intent_delete_segment() {
    case "$1" in
        *'"delete":['*) : ;;
        *) return 0 ;;
    esac
    local _seg="${1#*\"delete\":[}"
    printf '%s' "${_seg%%]*}"
}

# ---------------------------------------------------------------------------
# Op max duration calculation
# ---------------------------------------------------------------------------
_bake_max_duration() {
    local upper_dir="${1:-}"
    local du_bytes=0
    if [ -d "$upper_dir" ]; then
        du_bytes=$(du -sb "$upper_dir" 2>/dev/null | awk '{print $1}' || echo 0)
    fi
    local threshold=$(( 100 * 1024 * 1024 ))  # 100 MB
    if [ "$du_bytes" -ge "$threshold" ]; then
        echo 1800  # 30 min for large delta
    else
        echo 600   # 10 min for small delta
    fi
}

_consolidate_max_duration() {
    local persist_path="${1:-}"
    local type="${2:-}"
    local id="${3:-}"
    local du_bytes=0
    if [ -d "$persist_path" ]; then
        du_bytes=$(du -sb "$persist_path" 2>/dev/null | awk '{print $1}' || echo 0)
    fi
    local threshold=$(( 500 * 1024 * 1024 ))  # 500 MB
    if [ "$du_bytes" -ge "$threshold" ]; then
        echo 7200  # 2 h for large
    else
        echo 3600  # 1 h for small
    fi
}

# ---------------------------------------------------------------------------
# TERM/INT signal handler
# ---------------------------------------------------------------------------
_STOPPING=0
# Wake flag — set by SIGUSR1 (SupervisorService::wake(), sent from the session-
# close path) so the inter-tick sleep breaks early and the deferred-consolidate
# resume check runs the MOMENT a workspace closes instead of up to one full tick
# (5 s) later. Idempotent: many closes in a row just keep it set.
_WAKE=0
_HEARTBEAT_PID=""
_CHILD_PID=""
_CHILD_OP=""
_CHILD_ENTITY=""
_CHILD_STARTED_AT=0
_CHILD_MAX_DURATION=0
_LAST_COMPLETED_AT="null"
# S-08 (#1353): the LAST op handler's child exit code + (mount) defer reason —
# consumed by the job-ledger finaliser in _work_tick. The handlers keep their
# own logging/escalation behaviour unchanged; these are additive records.
_OP_EXIT=""
_OP_DEFER_REASON=""
# H1 fix: carries req_job from the dispatch loop into _op_consolidate's
# success/failure callsites so _clear/_relaunch can read the epoch from the
# ledger (keyed by job_id) instead of a per-entity sidecar.
_CURRENT_JOB_ID=""

_on_term() {
    _STOPPING=1
}

# SIGUSR1 = "wake now". Sent to the MAIN supervisor pid only (never the process
# group — the heartbeat subshell has no trap and would take the default-kill).
_on_wake() {
    _WAKE=1
}

# Re-assert pidfile ownership. The live supervisor holds the single-instance
# lock, so $$ is the canonical owner. If the pidfile was removed or now names a
# different PID (observed when a hot-swap upgrade's "stop old supervisor" step
# raced this instance's start and deleted the pidfile), rewrite it. Without this
# the pidfile-based backstop (SupervisorService::isRunning + the _do_start fast
# path) can't see the live supervisor and respawns it endlessly — a respawn
# storm, amplified by every open workspace's status poll. Called each work tick
# so a transient loss self-corrects within one tick instead of becoming a storm.
# Test: tests/unit/supervisor_pidfile_selfheal_test.sh.
# _write_pidfile — atomically (tmp + rename) record $$ as the pidfile owner.
# OP#1381: a plain `printf >$PIDFILE` truncates-then-writes; if the process is
# killed between truncate and write the pidfile is left EMPTY — the exact wedged
# state observed on .4 (2 procs, empty pidfile, empty status). rename is atomic on
# the same tmpfs, so a reader never sees a half-written/empty pidfile. We hold the
# single-instance lock, so we are the only legitimate writer; the .tmp name is
# PID-suffixed so even a racing loser (which never reaches here) couldn't collide.
_write_pidfile() {
    local tmp="${PIDFILE}.tmp.$$"
    printf '%d\n' "$$" > "$tmp" 2>/dev/null || return 1
    mv -f "$tmp" "$PIDFILE" 2>/dev/null || { rm -f "$tmp" 2>/dev/null; return 1; }
    return 0
}

_ensure_pidfile() {
    if [ ! -f "$PIDFILE" ] || [ "$(cat "$PIDFILE" 2>/dev/null)" != "$$" ]; then
        _write_pidfile
    fi
}

# ---------------------------------------------------------------------------
# Heartbeat loop — runs as a background subshell, independent of work loop
# ---------------------------------------------------------------------------
_run_heartbeat() {
    # CRITICAL (Bug #757): drop the inherited single-instance lock fd so an
    # orphaned heartbeat (its parent supervisor crashed) does NOT keep the lock
    # held — a fresh `start` must be able to acquire it and take over. This is
    # exactly what the reverted v2026.05.12.04 flock attempt missed.
    [ -n "${SUP_LOCK_FD:-}" ] && exec {SUP_LOCK_FD}>&- 2>/dev/null
    while true; do
        touch "$TICKFILE" 2>/dev/null || true
        sleep 5
    done
}

# ---------------------------------------------------------------------------
# Watchdog: check if current child has exceeded its duration ceiling
# ---------------------------------------------------------------------------
_watchdog_check_child() {
    [ -n "$_CHILD_PID" ] || return 0
    _pid_alive "$_CHILD_PID" || return 0
    [ "$_CHILD_MAX_DURATION" -gt 0 ] || return 0

    local now
    now=$(date +%s)
    local elapsed=$(( now - _CHILD_STARTED_AT ))

    if [ "$elapsed" -gt "$_CHILD_MAX_DURATION" ]; then
        log_warn "Op $_CHILD_OP for $_CHILD_ENTITY exceeded ceiling (${elapsed}s > ${_CHILD_MAX_DURATION}s). Sending TERM."
        lifecycle_log "warn" "supervisor" "op_exceeded_ceiling" \
            "{\"op\":\"$_CHILD_OP\",\"entity\":\"$_CHILD_ENTITY\",\"elapsed\":$elapsed,\"ceiling\":$_CHILD_MAX_DURATION}" 2>/dev/null || true

        kill -TERM "$_CHILD_PID" 2>/dev/null || true
        local waited=0
        while [ "$waited" -lt 10 ]; do
            _pid_alive "$_CHILD_PID" || break
            sleep 1
            waited=$(( waited + 1 ))
        done
        if _pid_alive "$_CHILD_PID" 2>/dev/null; then
            kill -KILL "$_CHILD_PID" 2>/dev/null || true
        fi

        _supervisor_notify "critical" "AICliAgents: Op timeout" \
            "Operation $_CHILD_OP for $_CHILD_ENTITY exceeded time ceiling (${elapsed}s). The supervisor killed the worker." \
            "op_timeout_${_CHILD_ENTITY}_${_CHILD_OP}" 3600

        if [ "$_CHILD_OP" = "consolidate" ]; then
            local count
            count=$(_consolidate_fail_increment "$_CHILD_ENTITY")
            lifecycle_log "warn" "supervisor" "consolidate_kill_counted" \
                "{\"entity\":\"$_CHILD_ENTITY\",\"fail_count\":$count}" 2>/dev/null || true
            if [ "$count" -ge 2 ]; then
                _write_halt "$_CHILD_ENTITY" "consolidate-disabled" "Two consecutive consolidate kills"
                _supervisor_notify "critical" "AICliAgents: Consolidation disabled" \
                    "Consolidation for $_CHILD_ENTITY has failed twice. Auto-consolidation is paused. Use the Storage tab to resume manually." \
                    "consolidate_disabled_${_CHILD_ENTITY}" 86400
                lifecycle_log "critical" "supervisor" "consolidate_auto_disabled" \
                    "{\"entity\":\"$_CHILD_ENTITY\",\"fail_count\":$count}" 2>/dev/null || true
            fi
        fi

        _CHILD_PID=""
        _CHILD_OP=""
        _CHILD_ENTITY=""
    fi
}

# ---------------------------------------------------------------------------
# Reconcile handler
# Runs unconditionally at the top of every tick.
# Budget: op_max_duration_s=30 (we don't spawn a child — must complete inline)
# ---------------------------------------------------------------------------
_op_reconcile() {
    local mpath
    mpath="$(manifest_path 2>/dev/null || echo '/boot/config/plugins/unraid-aicliagents/layer_manifest.json')"
    [ -f "$mpath" ] || return 0

    # Read entities from manifest using PHP to avoid bash JSON parsing complexity
    # Fall back to empty if PHP unavailable
    local entities_json=""
    if command -v php >/dev/null 2>&1; then
        local plugin_dir="/usr/local/emhttp/plugins/unraid-aicliagents"
        entities_json=$(php -d display_errors=0 -r "
            \$m = json_decode(@file_get_contents('$mpath'), true);
            if (!is_array(\$m)) exit;
            foreach (array_keys(\$m['entities'] ?? []) as \$k) echo \$k . PHP_EOL;
        " 2>/dev/null || true)
    fi

    [ -n "$entities_json" ] || return 0

    local reconcile_start
    reconcile_start=$(date +%s)
    local max_budget=30

    local entity
    while IFS= read -r entity; do
        [ -n "$entity" ] || continue

        # Check budget
        local now
        now=$(date +%s)
        if [ $(( now - reconcile_start )) -ge "$max_budget" ]; then
            log_warn "Reconcile budget exhausted (${max_budget}s). Resuming next tick."
            break
        fi

        # Parse type and id
        local type id
        type=$(echo "$entity" | cut -d/ -f1)
        id=$(echo "$entity" | cut -d/ -f2-)
        [ -n "$type" ] && [ -n "$id" ] || continue

        # Determine persist path
        local persist_path=""
        if [ "$type" = "home" ]; then
            persist_path="$(home_persist_path "$id" 2>/dev/null)"
        else
            persist_path="$(agent_persist_path 2>/dev/null)"
        fi
        [ -n "$persist_path" ] || continue

        # Race guard: hold the shared per-entity storage lock for this entity's
        # reconcile pass. A bake (commit_stack.sh) or consolidate
        # (consolidate_layers.sh) takes the same lock while it mutates the
        # persistence directory + manifest — and mid-operation the on-disk
        # layer set legitimately differs from the manifest. Without this guard
        # the untracked-layer logic below quarantines those in-flight layers
        # to .untracked/, actively losing freshly-baked data. flock -n: if a
        # bake/consolidate holds it, skip this entity this tick. Holding it
        # (not just probing) for the whole entity body closes the TOCTOU.
        # fd 8 is reassigned each iteration; the previous entity's lock is
        # released by this exec, and the post-loop `exec 8>&-` closes the last.
        local _rec_lock_id="${id//[^a-zA-Z0-9_-]/_}"
        exec 8>"/var/run/aicli-bake-${type}-${_rec_lock_id}.lock"
        if ! flock -n 8; then
            log_info "Reconcile: $entity — storage lock held (bake/consolidate in flight), skipping this tick"
            lifecycle_log "info" "supervisor" "reconcile_skipped_locked" \
                "{\"entity\":\"$entity\"}" 2>/dev/null || true
            continue
        fi

        # Check manifest path against current config (path drift). Follow-on 1b:
        # an EXPLICIT migration (FileStorage::migratePath) transiently changes the
        # config path while the manifest still records the old one — that drift is
        # expected and bracketed by .migration_inprogress.json, so the supervisor must
        # NOT discover-and-halt on it mid-migration. The classifier remains the
        # drift authority for UNexpected runtime drift (no marker present).
        # F4 (WP#1327): the marker is mtime-BOUNDED — a leaked marker (kill-mid-
        # migration; not a `finally`) must NOT disable path_drift protection for the
        # rest of the uptime. A marker older than the window is treated as stale/gone.
        local _mig_marker="/tmp/unraid-aicliagents/.migration_inprogress.json"
        local _mig_fresh=0
        if [ -f "$_mig_marker" ] && [ -n "$(find "$_mig_marker" -mmin -30 2>/dev/null)" ]; then
            _mig_fresh=1
        fi
        local manifest_stored_path
        manifest_stored_path=$(_manifest_entity_persist_path "$entity")
        if [ -n "$manifest_stored_path" ] && [ "$manifest_stored_path" != "$persist_path" ] \
           && [ "$_mig_fresh" = "0" ]; then
            _write_halt "$entity" "path_drift" "Manifest path $manifest_stored_path != current $persist_path"
            _supervisor_notify "critical" "AICliAgents: Storage path drift detected" \
                "Entity $entity: manifest records path '$manifest_stored_path' but current config resolves to '$persist_path'. Storage halted pending migration." \
                "path_drift_${entity}" 3600
            lifecycle_log "critical" "supervisor" "reconcile_halted_entity" \
                "{\"entity\":\"$entity\",\"reason\":\"path_drift\",\"manifest_path\":\"$manifest_stored_path\",\"current_path\":\"$persist_path\"}" 2>/dev/null || true
            continue
        fi

        # Get expected layers from manifest
        local expected_files=()
        if command -v php >/dev/null 2>&1; then
            local plugin_dir="/usr/local/emhttp/plugins/unraid-aicliagents"
            while IFS= read -r line; do
                [ -n "$line" ] && expected_files+=("$line")
            done < <(php -d display_errors=0 -r "
                \$m = json_decode(@file_get_contents('$mpath'), true);
                \$layers = \$m['entities']['$entity']['expected_layers'] ?? [];
                foreach (\$layers as \$l) echo (\$l['filename'] ?? '') . PHP_EOL;
            " 2>/dev/null || true)
        fi

        # Get actual files on disk
        local actual_files=()
        while IFS= read -r f; do
            [ -n "$f" ] && actual_files+=("$(basename "$f")")
        done < <(_glob_layers "$type" "$id" "$persist_path" 2>/dev/null)

        # Follow-on 4: read the write-ahead INTENT once (if a crashed consolidate left
        # one on the persist path) so a missing layer that was an INTENTIONAL prune is
        # not halted on. Membership is anchored to the intent's "delete" array via
        # _intent_delete_segment (F1/WP#1325) — mirrors common.sh; the supervisor does
        # not source common.sh.
        local _intent_json=""
        local _intent_file="${persist_path%/}/.aicli-intent-${type}-${id}.json"
        [ -f "$_intent_file" ] && _intent_json="$(cat "$_intent_file" 2>/dev/null || true)"

        # S-10 (#1354): graduate-intent crash recovery. op_graduate's write-ahead
        # intent ({"op":"graduate",...}) survives a crash in one of two windows
        # around the manifest authority flip; resolve it deterministically here
        # (we hold the per-entity bake lock for this entity, so no op can race):
        #   • retired layer(s) STILL in the persist root → the move never happened:
        #     layers stay authoritative; just clear the stale intent.
        #   • layers gone + passthrough dir POPULATED (the rsync was verified
        #     BEFORE the intent was written) → complete FORWARD: flip the manifest
        #     backend (one locked write) + clear the intent.
        #   • layers gone + passthrough dir empty + .graduated/ holds the copy →
        #     roll BACK: restore the layer(s) from .graduated/ + clear the intent.
        case "$_intent_json" in
            *'"op":"graduate"'*)
                local _gr_safe_id="${id//[^a-zA-Z0-9_-]/_}"
                local _gr_pt_dir="${persist_path%/}/passthrough/${type}s/${id}"
                local _gr_retire_dir="${persist_path%/}/.graduated/${type}_${_gr_safe_id}"
                local _gr_seg _gr_bn _gr_present=0
                _gr_seg="$(_intent_delete_segment "$_intent_json")"
                while IFS= read -r _gr_bn; do
                    [ -n "$_gr_bn" ] || continue
                    [ -f "${persist_path%/}/${_gr_bn}" ] && _gr_present=1
                done < <(printf '%s' "$_gr_seg" | grep -oE '"[^"]+"' | tr -d '"')
                if [ "$_gr_present" -eq 1 ]; then
                    log_info "Reconcile: graduate intent for $entity but layers still on disk — move never happened; clearing stale intent (flash authoritative)"
                    rm -f "$_intent_file" 2>/dev/null || true
                    lifecycle_log "info" "supervisor" "graduate_intent_cleared_flash" \
                        "{\"entity\":\"$entity\"}" 2>/dev/null || true
                elif [ -d "$_gr_pt_dir" ] && [ -n "$(find "$_gr_pt_dir" -mindepth 1 -print -quit 2>/dev/null)" ]; then
                    log_info "Reconcile: graduate intent for $entity with populated passthrough dir — completing the migration forward"
                    if declare -f manifest_set_backend >/dev/null 2>&1 \
                        && manifest_set_backend "$type" "$id" "passthrough"; then
                        rm -f "$_intent_file" "${persist_path%/}/.graduate_staging_${type}_${_gr_safe_id}" 2>/dev/null || true
                        lifecycle_log "info" "supervisor" "graduate_intent_completed_forward" \
                            "{\"entity\":\"$entity\",\"pt_dir\":\"$_gr_pt_dir\"}" 2>/dev/null || true
                    else
                        log_warn "Reconcile: graduate forward-completion manifest write failed for $entity — will retry next tick"
                    fi
                elif [ -d "$_gr_retire_dir" ] && [ -n "$(find "$_gr_retire_dir" -name '*.sqsh' -print -quit 2>/dev/null)" ]; then
                    log_warn "Reconcile: graduate intent for $entity with empty passthrough dir — rolling back layers from .graduated/"
                    mv -f "$_gr_retire_dir"/*.sqsh "${persist_path%/}/" 2>/dev/null || true
                    rm -f "$_intent_file" 2>/dev/null || true
                    lifecycle_log "warn" "supervisor" "graduate_intent_rolled_back" \
                        "{\"entity\":\"$entity\"}" 2>/dev/null || true
                else
                    log_error "Reconcile: graduate intent for $entity but no layers, no passthrough copy, no .graduated copy — leaving intent for manual diagnosis"
                    lifecycle_log "error" "supervisor" "graduate_intent_unresolvable" \
                        "{\"entity\":\"$entity\"}" 2>/dev/null || true
                fi
                # The manifest/disk state changed (or needs another tick) — re-run
                # this entity's missing/untracked checks against fresh state next tick.
                continue
                ;;
        esac

        # Check for files in manifest but not on disk (missing layers)
        local has_missing=0
        for exp_file in "${expected_files[@]:-}"; do
            [ -n "$exp_file" ] || continue
            local found=0
            for act_file in "${actual_files[@]:-}"; do
                [ "$act_file" = "$exp_file" ] && found=1 && break
            done
            if [ "$found" -eq 0 ]; then
                # Intentional prune from an interrupted consolidate → benign (the kept
                # consolidated layer holds its data). The intent is the primary signal;
                # the boot-integrity heuristics are the backstop. Anchored to the
                # "delete" plan so the kept layer's own loss is never masked (F1).
                case "$(_intent_delete_segment "$_intent_json")" in
                    *"\"$exp_file\""*)
                        log_info "Reconcile: missing layer $exp_file for $entity is an intentional prune (write-ahead intent) — benign, not halting"
                        lifecycle_log "info" "supervisor" "reconcile_intent_pruned" \
                            "{\"entity\":\"$entity\",\"filename\":\"$exp_file\"}" 2>/dev/null || true
                        continue
                        ;;
                esac
                has_missing=1
                log_error "Reconcile: missing layer $exp_file for entity $entity"
                _write_halt "$entity" "corrupt_layers" "Expected layer $exp_file not found on disk"
                _supervisor_notify "critical" "AICliAgents: Missing layer detected" \
                    "Entity $entity is missing expected layer $exp_file. Storage halted." \
                    "missing_layer_${entity}_${exp_file}" 3600
                lifecycle_log "critical" "supervisor" "reconcile_halted_entity" \
                    "{\"entity\":\"$entity\",\"reason\":\"missing_layer\",\"filename\":\"$exp_file\"}" 2>/dev/null || true
            fi
        done
        [ "$has_missing" -eq 0 ] || continue

        # Check for files on disk but not in manifest (untracked — attempt recovery)
        for act_file in "${actual_files[@]:-}"; do
            [ -n "$act_file" ] || continue
            local in_manifest=0
            for exp_file in "${expected_files[@]:-}"; do
                [ "$act_file" = "$exp_file" ] && in_manifest=1 && break
            done
            if [ "$in_manifest" -eq 0 ]; then
                local full_path="${persist_path}/${act_file}"
                log_info "Reconcile: untracked layer $act_file for $entity — attempting recovery"
                # Try to mount RO and sample-read
                local scratch_mnt
                scratch_mnt="/tmp/unraid-aicliagents/.reconcile_verify_$$"
                mkdir -p "$scratch_mnt" 2>/dev/null || true
                local sample_ok=0
                if mount -o loop,ro "$full_path" "$scratch_mnt" 2>/dev/null; then
                    find "$scratch_mnt" -type f 2>/dev/null | head -5 | while IFS= read -r sample_f; do
                        head -c 1 "$sample_f" >/dev/null 2>&1 || true
                    done
                    sample_ok=1
                    umount "$scratch_mnt" 2>/dev/null || true
                fi
                rmdir "$scratch_mnt" 2>/dev/null || true

                if [ "$sample_ok" -eq 1 ]; then
                    # Compute sha256
                    local sha256=""
                    sha256=$(sha256sum "$full_path" 2>/dev/null | awk '{print $1}' || echo "")
                    local file_bytes=0
                    file_bytes=$(stat -c '%s' "$full_path" 2>/dev/null || echo 0)
                    # F6 (WP#1331): register via the SINGLE manifest writer (kind=recovered;
                    # sha256/bytes recomputed PHP-side). Replaces the inline double-quoted
                    # php -r addLayer copy (anti-pattern + 4th drifting writer).
                    manifest_record_layer "$type" "$id" "$persist_path" "$act_file" "recovered" || true
                    lifecycle_log "info" "supervisor" "reconcile_recovered_layer" \
                        "{\"entity\":\"$entity\",\"filename\":\"$act_file\",\"sha256\":\"$sha256\",\"bytes\":$file_bytes}" 2>/dev/null || true
                else
                    # Sample-read failed — quarantine
                    local untracked_dir="${persist_path}/.untracked"
                    mkdir -p "$untracked_dir" 2>/dev/null || true
                    mv -f "$full_path" "$untracked_dir/" 2>/dev/null || true
                    log_error "Reconcile: quarantined unreadable layer $act_file to .untracked/"
                    lifecycle_log "critical" "supervisor" "reconcile_quarantined_layer" \
                        "{\"entity\":\"$entity\",\"filename\":\"$act_file\",\"quarantine_dir\":\"$untracked_dir\"}" 2>/dev/null || true
                fi
            fi
        done

        # Sha256 verification for expected layers (budget-aware: only if time permits)
        now=$(date +%s)
        if [ $(( now - reconcile_start )) -lt $(( max_budget - 5 )) ]; then
            for exp_file in "${expected_files[@]:-}"; do
                [ -n "$exp_file" ] || continue
                local full_path="${persist_path}/${exp_file}"
                [ -f "$full_path" ] || continue

                local expected_sha256
                expected_sha256=$(_manifest_sha256 "$exp_file")
                # Skip verification if sha256 in manifest is placeholder/smoke
                [ -n "$expected_sha256" ] || continue
                [ "$expected_sha256" = "smoke" ] && continue
                [ "${#expected_sha256}" -lt 32 ] && continue

                local actual_sha256
                actual_sha256=$(sha256sum "$full_path" 2>/dev/null | awk '{print $1}' || echo "")
                if [ -n "$actual_sha256" ] && [ "$actual_sha256" != "$expected_sha256" ]; then
                    log_error "Reconcile: sha256 mismatch for $exp_file (expected $expected_sha256, got $actual_sha256)"
                    _write_halt "$entity" "corrupt_layers" "sha256 mismatch for $exp_file"
                    _supervisor_notify "critical" "AICliAgents: Layer corruption detected" \
                        "Entity $entity: layer $exp_file has sha256 mismatch. Storage halted." \
                        "sha256_mismatch_${entity}_${exp_file}" 3600
                    lifecycle_log "critical" "supervisor" "reconcile_halted_entity" \
                        "{\"entity\":\"$entity\",\"reason\":\"sha256_mismatch\",\"filename\":\"$exp_file\"}" 2>/dev/null || true
                fi
            done
        fi

        lifecycle_log "info" "supervisor" "reconcile_ok" \
            "{\"entity\":\"$entity\",\"active_count\":${#actual_files[@]}}" 2>/dev/null || true

    done <<< "$entities_json"

    # Release the last entity's per-entity storage lock (fd 8 was reassigned
    # per iteration; close it so the final entity's lock isn't held until the
    # next reconcile tick).
    exec 8>&- 2>/dev/null || true

    # Cleanup: orphaned .tmp.* tempfiles in any persist path (older than 1 hour)
    local persist_dirs=()
    persist_dirs+=("$(agent_persist_path 2>/dev/null || true)")
    persist_dirs+=("$(home_persist_path root 2>/dev/null || true)")

    local now_epoch
    now_epoch=$(date +%s)
    for pdir in "${persist_dirs[@]:-}"; do
        [ -d "$pdir" ] || continue
        while IFS= read -r tmp_file; do
            [ -f "$tmp_file" ] || continue
            local file_mtime
            file_mtime=$(stat -c '%Y' "$tmp_file" 2>/dev/null || echo "$now_epoch")
            local age=$(( now_epoch - file_mtime ))
            if [ "$age" -gt 3600 ]; then
                rm -f "$tmp_file" 2>/dev/null || true
                log_info "Reconcile: cleaned orphan tempfile $(basename "$tmp_file") (age ${age}s)"
                lifecycle_log "info" "supervisor" "reconcile_orphan_tmp_cleaned" \
                    "{\"file\":\"$tmp_file\",\"age_s\":$age}" 2>/dev/null || true
            fi
        done < <(find "$pdir" -maxdepth 1 -name '.*.tmp.*' -o -name '*.tmp.*' 2>/dev/null | grep '\.tmp\.' || true)
    done

    # S-10 (#1354): reap retired layer copies under <persist>/.graduated/ once
    # older than the retention window (cfg graduated_retention_days, default 14).
    # These are the post-graduation rollback copies — moved there, never deleted,
    # by op_graduate; after the window they are genuinely garbage.
    local _gr_ret_days="$GRADUATED_RETENTION_DAYS"
    case "$_gr_ret_days" in ''|*[!0-9]*) _gr_ret_days=14 ;; esac
    local _gr_old
    for pdir in "${persist_dirs[@]:-}"; do
        [ -d "$pdir/.graduated" ] || continue
        while IFS= read -r _gr_old; do
            [ -n "$_gr_old" ] || continue
            case "$_gr_old" in */.graduated/*) : ;; *) continue ;; esac   # belt-and-braces
            rm -rf "$_gr_old" 2>/dev/null || true
            log_info "Reconcile: reaped graduated-layer retention copy $(basename "$_gr_old") (older than ${_gr_ret_days}d)"
            lifecycle_log "info" "supervisor" "graduated_retention_reaped" \
                "{\"path\":\"$_gr_old\",\"retention_days\":$_gr_ret_days}" 2>/dev/null || true
        done < <(find "$pdir/.graduated" -mindepth 1 -maxdepth 1 -mtime "+$_gr_ret_days" 2>/dev/null)
        rmdir "$pdir/.graduated" 2>/dev/null || true
    done

    # S-03 (#1352): reap EXPIRED defer-reason markers (older than the TTL —
    # cfg defer_marker_ttl_h, default 24 h). Readers already ignore stale markers;
    # this removes the on-disk residue so it can't outlive the diagnostic window.
    local ttl_h
    ttl_h=""
    if declare -f _rp_read_cfg >/dev/null 2>&1; then
        ttl_h="$(_rp_read_cfg 'defer_marker_ttl_h' 2>/dev/null)"
    fi
    [ -n "${AICLI_DEFER_MARKER_TTL_H:-}" ] && ttl_h="$AICLI_DEFER_MARKER_TTL_H"
    case "$ttl_h" in ''|*[!0-9]*) ttl_h=24 ;; esac
    find /tmp/unraid-aicliagents -maxdepth 1 -name '.bake_defer_reason_*' \
        -mmin "+$(( ttl_h * 60 ))" -delete 2>/dev/null || true

    # Feature #1382 (finding 4): reap GENUINELY-abandoned loop devices — a loop
    # bound to a DELETED plugin .sqsh backing file that is NOT a mount source in
    # /proc/mounts. This is the residue left by the now-fixed do_wipe loop-
    # teardown bug: after a consolidate/bake the old layer .sqsh is deleted, but
    # if its loop was never detached it lingers on the deleted inode.
    #
    # SAFETY: NEVER detach a MOUNTED loop — an in-use deleted lower is benign
    # deleted-but-open Unix semantics (the layer .sqsh is still squashfs-mounted
    # on the loop and an agent overlay references that mountpoint as a lower; it
    # clears on the overlay's next remount/close). Those are skipped here and
    # (correctly) no longer flagged by the refined I5 invariant. The mounted test
    # is EXACT-TOKEN on /proc/mounts field 1 (awk $1==dev) — a bare substring
    # grep would false-match /dev/loop12 inside /dev/loop124 and wrongly spare a
    # genuine orphan. Best-effort, idempotent, fault-isolated; needs losetup.
    if command -v losetup >/dev/null 2>&1; then
        local _lp_line _lp_dev
        while IFS= read -r _lp_line; do
            [ -n "$_lp_line" ] || continue
            case "$_lp_line" in *unraid-aicliagents*) : ;; *) continue ;; esac
            case "$_lp_line" in *'(deleted)'*) : ;; *) continue ;; esac
            _lp_dev="${_lp_line%%:*}"
            [ -n "$_lp_dev" ] || continue
            # Skip if the loop device is a mount SOURCE (field 1) in /proc/mounts.
            if awk -v d="$_lp_dev" '$1==d{f=1} END{exit !f}' /proc/mounts 2>/dev/null; then
                continue
            fi
            if losetup -d "$_lp_dev" 2>/dev/null; then
                log_info "Reconcile: reaped abandoned loop $_lp_dev (deleted backing, not mounted)"
                lifecycle_log "info" "supervisor" "reconcile_orphan_loop_reaped" \
                    "{\"loop\":\"$_lp_dev\"}" 2>/dev/null || true
            fi
        done < <(losetup -a 2>/dev/null || true)
    fi

    # S-08 (#1353): reap terminal job-ledger entries (done 1 h, failed/deferred 24 h)
    # + orphaned retry pen entries.
    _reap_job_ledger
}

# ---------------------------------------------------------------------------
# Op handler: bake
# Spawns a child process running commit_stack.sh. Writes work.json.
# ---------------------------------------------------------------------------
_op_bake() {
    local type="${1:-}"
    local id="${2:-}"
    local reason="${3:-scheduled}"
    local compression="${4:-xz}"

    local persist_path=""
    if [ "$type" = "home" ]; then
        persist_path="$(home_persist_path "$id" 2>/dev/null)"
    else
        persist_path="$(agent_persist_path 2>/dev/null)"
    fi

    local upper_dir
    upper_dir="$(zram_upper "$type" "$id" 2>/dev/null)"

    local max_dur
    max_dur=$(_bake_max_duration "$upper_dir")

    local entity="${type}/${id}"
    local started_at
    started_at=$(date +%s)

    log_info "Starting bake: $entity (reason=$reason, compression=$compression)"
    lifecycle_log "info" "supervisor" "bake_start" \
        "{\"entity\":\"$entity\",\"reason\":\"$reason\",\"compression\":\"$compression\"}" 2>/dev/null || true

    # Spawn child — in a subshell that drops the inherited single-instance lock
    # fd FIRST (Bug #757): if the supervisor crashes mid-bake, the orphaned bake
    # must not keep the lock held (a bake can run for minutes). The `exec`
    # replaces the subshell with storagectl (Phase 5: it dispatches to op_bake),
    # so $! and the watchdog's kill -0/-TERM/-KILL still target the right PID.
    (
        [ -n "${SUP_LOCK_FD:-}" ] && exec {SUP_LOCK_FD}>&- 2>/dev/null
        export MKSQUASHFS_ARGS="-comp $compression"
        exec bash "${STORAGE_DIR}/storagectl.sh" bake --type "$type" --id "$id" --persist "$persist_path" >/dev/null 2>&1
    ) &
    local child_pid=$!

    _CHILD_PID="$child_pid"
    _CHILD_OP="bake"
    _CHILD_ENTITY="$entity"
    _CHILD_STARTED_AT="$started_at"
    _CHILD_MAX_DURATION="$max_dur"

    local qdepth
    qdepth="$(queue_depth 2>/dev/null || echo 0)"
    local work_json
    work_json="$(_running_work_json "bake" "$entity" "$child_pid" "$started_at" "$max_dur" "$qdepth")"
    _atomic_json_write "$WORKFILE" "$work_json" || true
    _atomic_json_write "$STATUSFILE" "$(_status_json running bake "$entity" "$qdepth" "$_LAST_COMPLETED_AT")" || true

    # Wait for child
    wait "$child_pid" 2>/dev/null
    local exit_code=$?
    _OP_EXIT="$exit_code"   # S-08: recorded verbatim in the job ledger (if tracked)

    _CHILD_PID=""
    _CHILD_OP=""
    _CHILD_ENTITY=""

    local now
    now=$(date +%s)
    _LAST_COMPLETED_AT="$now"

    if [ "$exit_code" -eq 0 ] || [ "$exit_code" -eq 2 ]; then
        log_info "Bake completed: $entity (exit=$exit_code)"
        lifecycle_log "info" "supervisor" "bake_ok" \
            "{\"entity\":\"$entity\",\"exit_code\":$exit_code}" 2>/dev/null || true
    else
        # WP #922: splice debug.log tail into the failure event for diagnostics
        # that survive /tmp rotation. commit_stack.sh's failure trap (in
        # common.sh) writes the tail to a per-entity file we drain here.
        local safe_type safe_id stderr_tail tail_path
        safe_type=$(printf '%s' "$type" | tr -c 'A-Za-z0-9_.-' '_')
        safe_id=$(printf '%s' "$id"     | tr -c 'A-Za-z0-9_.-' '_')
        tail_path="/tmp/unraid-aicliagents/.stderr_tail_${safe_type}_${safe_id}.txt"
        stderr_tail=""
        if [ -f "$tail_path" ]; then
            stderr_tail=$(head -c 2000 "$tail_path" 2>/dev/null \
                | tr -d '\r' \
                | sed 's/\\/\\\\/g; s/"/\\"/g; s/\t/    /g' \
                | tr '\n' ' ')
            rm -f "$tail_path" 2>/dev/null || true
        fi

        log_error "Bake failed: $entity (exit=$exit_code)"
        lifecycle_log "error" "supervisor" "bake_failed" \
            "{\"entity\":\"$entity\",\"exit_code\":$exit_code,\"stderr_tail\":\"$stderr_tail\"}" 2>/dev/null || true
    fi
}

# ---------------------------------------------------------------------------
# Op handler: consolidate
# Spawns a child process running consolidate_layers.sh.
# ---------------------------------------------------------------------------
_op_consolidate() {
    local type="${1:-}"
    local id="${2:-}"
    local reason="${3:-threshold}"

    local entity="${type}/${id}"

    # Check if consolidate is disabled for this entity
    if _halt_exists "$entity" "consolidate-disabled"; then
        log_warn "Consolidate disabled for $entity — skipping. Use 'Resume' in the UI to re-enable."
        return 0
    fi

    local persist_path=""
    if [ "$type" = "home" ]; then
        persist_path="$(home_persist_path "$id" 2>/dev/null)"
    else
        persist_path="$(agent_persist_path 2>/dev/null)"
    fi

    local max_dur
    max_dur=$(_consolidate_max_duration "$persist_path" "$type" "$id")

    local started_at
    started_at=$(date +%s)

    log_info "Starting consolidate: $entity (reason=$reason)"
    lifecycle_log "info" "supervisor" "consolidate_start" \
        "{\"entity\":\"$entity\",\"reason\":\"$reason\"}" 2>/dev/null || true

    # R2.2 (M1 fix): pre-bake close phase — close the user's sessions + ttyds before
    # EVERY bake attempt (not once per job — close is idempotent: no sessions → cheap
    # no-op; re-pinned mount → re-freed). Phase guard (once-flag) REMOVED.
    if [ "$type" = "home" ]; then
        log_info "Consolidate close phase: closing sessions for home/$id"
        _close_home_for_consolidate "$id"
    fi

    # Spawn child — subshell drops the inherited single-instance lock fd FIRST
    # (Bug #757); see _op_bake. A consolidate can run for many minutes; an
    # orphaned one must not block a fresh supervisor.
    (
        [ -n "${SUP_LOCK_FD:-}" ] && exec {SUP_LOCK_FD}>&- 2>/dev/null
        exec bash "${STORAGE_DIR}/storagectl.sh" consolidate --type "$type" --id "$id" --persist "$persist_path" >/dev/null 2>&1
    ) &
    local child_pid=$!

    _CHILD_PID="$child_pid"
    _CHILD_OP="consolidate"
    _CHILD_ENTITY="$entity"
    _CHILD_STARTED_AT="$started_at"
    _CHILD_MAX_DURATION="$max_dur"

    local qdepth
    qdepth="$(queue_depth 2>/dev/null || echo 0)"
    local work_json
    work_json="$(_running_work_json "consolidate" "$entity" "$child_pid" "$started_at" "$max_dur" "$qdepth")"
    _atomic_json_write "$WORKFILE" "$work_json" || true
    _atomic_json_write "$STATUSFILE" "$(_status_json running consolidate "$entity" "$qdepth" "$_LAST_COMPLETED_AT")" || true

    # Wait for child
    wait "$child_pid" 2>/dev/null
    local exit_code=$?
    _OP_EXIT="$exit_code"   # S-08: recorded verbatim in the job ledger (if tracked)

    _CHILD_PID=""
    _CHILD_OP=""
    _CHILD_ENTITY=""

    local now
    now=$(date +%s)
    _LAST_COMPLETED_AT="$now"

    if [ "$exit_code" -eq 0 ]; then
        # Reset failure counter on success
        _consolidate_fail_reset "$entity"
        log_info "Consolidate completed: $entity"
        lifecycle_log "info" "supervisor" "consolidate_ok" \
            "{\"entity\":\"$entity\"}" 2>/dev/null || true
        # HOME_CONSOLIDATE_CLOSE_RELAUNCH R3: a successful home consolidate
        # auto-relaunches the sessions the manual consolidate closed (resumed,
        # across all the user's agents). Only on success (not exit 2 defer / not
        # failure), keyed by the consolidate id = the user. No-op if no manifest.
        if [ "$type" = "home" ]; then
            _relaunch_home_sessions "$id" "$_CURRENT_JOB_ID"
        fi
    elif [ "$exit_code" -eq 2 ]; then
        # WP #922: exit 2 = deferred (mount busy / writes during bake). Not a
        # failure — the script declined to proceed because a session was holding
        # the merged mount open. Don't increment fail count, don't escalate.
        # Reset the counter too — a clean defer should clear any prior counter
        # state since "we couldn't try" is different from "we tried and failed".
        _consolidate_fail_reset "$entity"
        log_info "Consolidate deferred (busy): $entity — will retry next tick"
        lifecycle_log "info" "supervisor" "consolidate_deferred" \
            "{\"entity\":\"$entity\"}" 2>/dev/null || true
    else
        # Increment failure counter
        local fail_count
        fail_count=$(_consolidate_fail_increment "$entity")

        # WP #922: splice the script's debug.log tail into the lifecycle event
        # so the cause survives even if /tmp/.../debug.log rotates before
        # someone investigates. The tail was written by common.sh's failure
        # trap. We escape for JSON and cap at 2KB to keep the lifecycle log
        # compact.
        local safe_type safe_id stderr_tail tail_path
        safe_type=$(printf '%s' "$type" | tr -c 'A-Za-z0-9_.-' '_')
        safe_id=$(printf '%s' "$id"     | tr -c 'A-Za-z0-9_.-' '_')
        tail_path="/tmp/unraid-aicliagents/.stderr_tail_${safe_type}_${safe_id}.txt"
        stderr_tail=""
        if [ -f "$tail_path" ]; then
            stderr_tail=$(head -c 2000 "$tail_path" 2>/dev/null \
                | tr -d '\r' \
                | sed 's/\\/\\\\/g; s/"/\\"/g; s/\t/    /g' \
                | tr '\n' ' ')
            rm -f "$tail_path" 2>/dev/null || true
        fi

        log_error "Consolidate failed: $entity (exit=$exit_code, fail_count=$fail_count)"
        lifecycle_log "error" "supervisor" "consolidate_failed" \
            "{\"entity\":\"$entity\",\"exit_code\":$exit_code,\"fail_count\":$fail_count,\"stderr_tail\":\"$stderr_tail\"}" 2>/dev/null || true

        # HOME_CONSOLIDATE_INPROGRESS_GUARD R4: a FAILED home consolidate is a
        # terminal outcome (unlike exit 2 = deferred, which retries) — clear the
        # per-user start guard so the user can launch sessions again. We do NOT
        # relaunch on failure (the home may be in a bad state); the user starts
        # manually. id == the user for a home consolidate.
        if [ "$type" = "home" ]; then
            _clear_home_consolidating "$id" "$_CURRENT_JOB_ID"
        fi

        if [ "$fail_count" -ge 2 ]; then
            _write_halt "$entity" "consolidate-disabled" "Two consecutive consolidate failures"
            _supervisor_notify "critical" "AICliAgents: Consolidation disabled" \
                "Consolidation for $entity has failed twice. Auto-consolidation is paused. Use the Storage tab to resume." \
                "consolidate_disabled_${entity}" 86400
            lifecycle_log "critical" "supervisor" "consolidate_auto_disabled" \
                "{\"entity\":\"$entity\",\"fail_count\":$fail_count}" 2>/dev/null || true
        fi
    fi

}

# ---------------------------------------------------------------------------
# S-08 (#1353): Op handler: mount — async storage job model.
#
# Mirrors _op_bake's child pattern (subshell drops the single-instance lock fd,
# exec's storagectl, watchdog ceiling) for the `mount` verb. Mounts are user-
# facing (a workspace open is waiting on them) so they arrive at priority 05
# (user-click tier). Watchdog ceiling: 120 s (a cold mount assembles the lower
# stack + zram; 10-30 s typical, 120 s is the hard cap from the S-08 report).
#
# Entity-lock discipline: a queued mount must not race a bake/consolidate of
# the same entity. The supervisor itself runs ONE op at a time, so the only
# raceable writer is an out-of-band bake (shutdown fallback, install-bg). We
# PROBE the shared per-entity bake lock (/var/run/aicli-bake-<type>-<id>.lock,
# same probe storagectl's _lock_held uses) and defer-requeue when held —
# never grab it (op_bake's own post-bake op_mount remount would self-deadlock
# if op_mount required the bake lock; op_mount's mount-op lock + busy-arbiter
# already make the remount itself safe by construction).
#
# Sets _OP_EXIT + _OP_DEFER_REASON for the job-ledger finaliser.
# ---------------------------------------------------------------------------
_MOUNT_MAX_DURATION_S=120

# Backoff schedule for deferred mount requeues: attempt 1 → 10 s, 2 → 30 s,
# 3+ → 60 s (capped), until STORAGE_TARGET_WAIT_S total elapsed. Pure.
_mount_retry_delay() {
    case "${1:-1}" in
        1) echo 10 ;;
        2) echo 30 ;;
        *) echo 60 ;;
    esac
}

_op_mount() {
    local type="${1:-}"
    local id="${2:-}"
    local reason="${3:-workspace_open}"

    local persist_path=""
    if [ "$type" = "home" ]; then
        persist_path="$(home_persist_path "$id" 2>/dev/null)"
    else
        persist_path="$(agent_persist_path 2>/dev/null)"
    fi

    local entity="${type}/${id}"
    _OP_EXIT=""
    _OP_DEFER_REASON=""

    # Entity-lock probe: bake/consolidate in flight for this entity → defer.
    local _lock_id="${id//[^a-zA-Z0-9_-]/_}"
    local bake_lock="/var/run/aicli-bake-${type}-${_lock_id}.lock"
    if [ -e "$bake_lock" ] && ! ( exec 7>"$bake_lock"; flock -n 7 ) 2>/dev/null; then
        log_info "Mount deferred for $entity — entity bake lock held (bake/consolidate in flight)"
        lifecycle_log "info" "supervisor" "mount_deferred_bake_lock" \
            "{\"entity\":\"$entity\"}" 2>/dev/null || true
        _OP_EXIT=2
        _OP_DEFER_REASON="bake_lock_held"
        return 0
    fi

    local started_at
    started_at=$(date +%s)

    log_info "Starting mount: $entity (reason=$reason)"
    lifecycle_log "info" "supervisor" "mount_start" \
        "{\"entity\":\"$entity\",\"reason\":\"$reason\"}" 2>/dev/null || true

    # Spawn child — subshell drops the inherited single-instance lock fd FIRST
    # (Bug #757); see _op_bake. op_mount defaults --owner to the id for homes,
    # so no owner threading is needed here.
    (
        [ -n "${SUP_LOCK_FD:-}" ] && exec {SUP_LOCK_FD}>&- 2>/dev/null
        exec bash "${STORAGE_DIR}/storagectl.sh" mount --type "$type" --id "$id" --persist "$persist_path" >/dev/null 2>&1
    ) &
    local child_pid=$!

    _CHILD_PID="$child_pid"
    _CHILD_OP="mount"
    _CHILD_ENTITY="$entity"
    _CHILD_STARTED_AT="$started_at"
    _CHILD_MAX_DURATION="$_MOUNT_MAX_DURATION_S"

    local qdepth
    qdepth="$(queue_depth 2>/dev/null || echo 0)"
    _atomic_json_write "$WORKFILE" "$(_running_work_json "mount" "$entity" "$child_pid" "$started_at" "$_MOUNT_MAX_DURATION_S" "$qdepth")" || true
    _atomic_json_write "$STATUSFILE" "$(_status_json running mount "$entity" "$qdepth" "$_LAST_COMPLETED_AT")" || true

    # Wait for child
    wait "$child_pid" 2>/dev/null
    local exit_code=$?
    _OP_EXIT="$exit_code"

    _CHILD_PID=""
    _CHILD_OP=""
    _CHILD_ENTITY=""

    local now
    now=$(date +%s)
    _LAST_COMPLETED_AT="$now"

    # On a defer (exit 2) read the op's reason marker (written by _op_defer in
    # the ops library; head -1, never reinterpreted).
    if [ "$exit_code" -eq 2 ]; then
        # Marker path mirrors storagectl's _read_defer_reason (type is home|agent,
        # id sanitised exactly like commit_stack's _LOCK_ID).
        local safe_id marker
        safe_id="${id//[^a-zA-Z0-9_-]/_}"
        marker="/tmp/unraid-aicliagents/.bake_defer_reason_${type}_${safe_id}"
        [ -f "$marker" ] && _OP_DEFER_REASON="$(head -1 "$marker" 2>/dev/null | tr -d '\n')"
    fi

    if [ "$exit_code" -eq 0 ]; then
        log_info "Mount completed: $entity"
        lifecycle_log "info" "supervisor" "mount_ok" \
            "{\"entity\":\"$entity\"}" 2>/dev/null || true
    elif [ "$exit_code" -eq 2 ]; then
        log_info "Mount deferred: $entity (reason=${_OP_DEFER_REASON:-unknown})"
        lifecycle_log "info" "supervisor" "mount_deferred" \
            "{\"entity\":\"$entity\",\"defer_reason\":\"${_OP_DEFER_REASON:-}\"}" 2>/dev/null || true
    else
        log_error "Mount failed: $entity (exit=$exit_code)"
        lifecycle_log "error" "supervisor" "mount_failed" \
            "{\"entity\":\"$entity\",\"exit_code\":$exit_code}" 2>/dev/null || true
    fi
}

# ---------------------------------------------------------------------------
# S-10 (#1354): Op handler: graduate — migrate a flash entity to passthrough.
#
# Mirrors _op_bake's child pattern (subshell drops the single-instance lock fd,
# exec's storagectl graduate, watchdog ceiling). User-initiated from the Storage
# tab recommendation → priority 05 (user tier). Ceiling 7200 s: the op chains
# flush + consolidate + rsync of a whole home. op_graduate serialises itself on
# the per-entity bake lock for its destructive phase (and defers exit-2 when a
# bake holds it), so no lock probe is needed here.
# Sets _OP_EXIT + _OP_DEFER_REASON for the job-ledger finaliser.
# ---------------------------------------------------------------------------
_GRADUATE_MAX_DURATION_S=7200

# Backoff schedule for deferred graduate requeues: attempt 1 → 60 s, 2 → 300 s,
# 3+ → 600 s (capped), until GRADUATE_WAIT_CAP_S (24 h) total elapsed. Pure.
_graduate_retry_delay() {
    case "${1:-1}" in
        1) echo 60 ;;
        2) echo 300 ;;
        *) echo 600 ;;
    esac
}

# OP#1381: backoff schedule for a deferred user-initiated consolidate/bake whose
# overlay is still busy. Shorter than graduate's — a user who clicked Consolidate
# wants it to land as soon as the workspace closes, so the early retries are tight;
# the cap (USER_CONSOLIDATE_WAIT_CAP_S) bounds the total wait.
_user_consolidate_retry_delay() {
    case "${1:-1}" in
        1) echo 15 ;;
        2) echo 60 ;;
        *) echo 120 ;;
    esac
}

_op_graduate() {
    local type="${1:-}"
    local id="${2:-}"
    local reason="${3:-user_graduate}"

    local persist_path=""
    if [ "$type" = "home" ]; then
        persist_path="$(home_persist_path "$id" 2>/dev/null)"
    else
        persist_path="$(agent_persist_path 2>/dev/null)"
    fi

    local entity="${type}/${id}"
    _OP_EXIT=""
    _OP_DEFER_REASON=""

    local started_at
    started_at=$(date +%s)

    log_info "Starting graduate: $entity (reason=$reason)"
    lifecycle_log "info" "supervisor" "graduate_start" \
        "{\"entity\":\"$entity\",\"reason\":\"$reason\"}" 2>/dev/null || true

    # Spawn child — subshell drops the inherited single-instance lock fd FIRST
    # (Bug #757); see _op_bake.
    (
        [ -n "${SUP_LOCK_FD:-}" ] && exec {SUP_LOCK_FD}>&- 2>/dev/null
        exec bash "${STORAGE_DIR}/storagectl.sh" graduate --type "$type" --id "$id" --persist "$persist_path" >/dev/null 2>&1
    ) &
    local child_pid=$!

    _CHILD_PID="$child_pid"
    _CHILD_OP="graduate"
    _CHILD_ENTITY="$entity"
    _CHILD_STARTED_AT="$started_at"
    _CHILD_MAX_DURATION="$_GRADUATE_MAX_DURATION_S"

    local qdepth
    qdepth="$(queue_depth 2>/dev/null || echo 0)"
    _atomic_json_write "$WORKFILE" "$(_running_work_json "graduate" "$entity" "$child_pid" "$started_at" "$_GRADUATE_MAX_DURATION_S" "$qdepth")" || true
    _atomic_json_write "$STATUSFILE" "$(_status_json running graduate "$entity" "$qdepth" "$_LAST_COMPLETED_AT")" || true

    # Wait for child
    wait "$child_pid" 2>/dev/null
    local exit_code=$?
    _OP_EXIT="$exit_code"

    _CHILD_PID=""
    _CHILD_OP=""
    _CHILD_ENTITY=""

    local now
    now=$(date +%s)
    _LAST_COMPLETED_AT="$now"

    # Read the op's reason marker on a defer (exit 2) or precondition (exit 4).
    if [ "$exit_code" -eq 2 ] || [ "$exit_code" -eq 4 ]; then
        local safe_id marker
        safe_id="${id//[^a-zA-Z0-9_-]/_}"
        marker="/tmp/unraid-aicliagents/.bake_defer_reason_${type}_${safe_id}"
        [ -f "$marker" ] && _OP_DEFER_REASON="$(head -1 "$marker" 2>/dev/null | tr -d '\n')"
    fi

    if [ "$exit_code" -eq 0 ]; then
        log_info "Graduate completed: $entity"
        lifecycle_log "info" "supervisor" "graduate_ok" \
            "{\"entity\":\"$entity\"}" 2>/dev/null || true
        _supervisor_notify "normal" "AICliAgents: Storage graduated" \
            "$entity has moved off the layering engine onto the passthrough backend. Old layers are retained in .graduated/ for ${GRADUATED_RETENTION_DAYS} days." \
            "graduate_ok_${entity}" 3600
    elif [ "$exit_code" -eq 2 ]; then
        log_info "Graduate deferred: $entity (reason=${_OP_DEFER_REASON:-unknown})"
        lifecycle_log "info" "supervisor" "graduate_deferred" \
            "{\"entity\":\"$entity\",\"defer_reason\":\"${_OP_DEFER_REASON:-}\"}" 2>/dev/null || true
    elif [ "$exit_code" -eq 4 ]; then
        log_warn "Graduate precondition failed: $entity (reason=${_OP_DEFER_REASON:-unknown})"
        lifecycle_log "warn" "supervisor" "graduate_precondition_failed" \
            "{\"entity\":\"$entity\",\"defer_reason\":\"${_OP_DEFER_REASON:-}\"}" 2>/dev/null || true
    else
        log_error "Graduate failed: $entity (exit=$exit_code)"
        lifecycle_log "error" "supervisor" "graduate_failed" \
            "{\"entity\":\"$entity\",\"exit_code\":$exit_code}" 2>/dev/null || true
        _supervisor_notify "warning" "AICliAgents: Storage graduation failed" \
            "Graduating $entity to the passthrough backend failed (exit $exit_code). The layered data is untouched. Check the lifecycle log." \
            "graduate_failed_${entity}" 3600
    fi
}

# ---------------------------------------------------------------------------
# S-08 (#1353): job-ledger transitions + deferred-retry pen.
# ---------------------------------------------------------------------------

# _job_mark_running <job_id> <op> <type> <id> <reason> <trace>
_job_mark_running() {
    local job_id="$1" op="$2" type="$3" id="$4" reason="$5" trace="$6"
    declare -f job_ledger_write >/dev/null 2>&1 || return 0
    job_ledger_write "$job_id" "$op" "$type" "$id" "running" "" "" \
        "$(job_ledger_field "$job_id" attempt 2>/dev/null)" \
        "$(job_ledger_field "$job_id" queued_at 2>/dev/null)" \
        "$(date +%s)" "" "$reason" "$trace" || true
}

# _job_finalize <job_id> <op> <type> <id> <reason> <trace> <priority>
# Terminal/retry transition after the op handler ran. Reads _OP_EXIT /
# _OP_DEFER_REASON. Mount jobs deferred for a TRANSIENT target condition
# (target_not_mounted: UD device not mounted yet; bake_lock_held: entity busy)
# are re-queued with 10→30→60 s backoff until STORAGE_TARGET_WAIT_S total
# elapsed, then FAILED + dynamix notify (S-02's deferred re-queue half).
# Any other defer is recorded as state=deferred (a mount_busy defer kept the
# live overlay — the entity IS usable; the PHP/UI side resolves via status).
_job_finalize() {
    local job_id="$1" op="$2" type="$3" id="$4" reason="$5" trace="$6" priority="${7:-5}"
    declare -f job_ledger_write >/dev/null 2>&1 || return 0

    local exit_code="${_OP_EXIT:-1}"
    case "$exit_code" in ''|*[!0-9]*) exit_code=1 ;; esac
    local defer="${_OP_DEFER_REASON:-}"
    local entity="${type}/${id}"

    # bake/consolidate defers also leave a reason marker — record it.
    if [ -z "$defer" ] && { [ "$exit_code" -eq 2 ] || [ "$exit_code" -eq 4 ]; }; then
        local safe_id="${id//[^a-zA-Z0-9_-]/_}"
        local marker="/tmp/unraid-aicliagents/.bake_defer_reason_${type}_${safe_id}"
        [ -f "$marker" ] && defer="$(head -1 "$marker" 2>/dev/null | tr -d '\n')"
    fi

    local attempt queued_at started_at now
    attempt="$(job_ledger_field "$job_id" attempt 2>/dev/null)"
    case "$attempt" in ''|*[!0-9]*) attempt=1 ;; esac
    queued_at="$(job_ledger_field "$job_id" queued_at 2>/dev/null)"
    started_at="$(job_ledger_field "$job_id" started_at 2>/dev/null)"
    # H1' fix: read the epoch written at enqueue time so the defer-finalize ledger
    # rewrite preserves it; without this the 13-arg write emits no 14th arg so the
    # next queue_enqueue re-enqueue (7-arg) finds no epoch and
    # clearHomeConsolidating(user, null) clears unconditionally (cross-clear).
    local prev_epoch
    prev_epoch="$(job_ledger_field "$job_id" consolidate_epoch 2>/dev/null)"
    now=$(date +%s)
    case "$queued_at" in ''|*[!0-9]*) queued_at="$now" ;; esac

    local state="failed"
    if [ "$exit_code" -eq 0 ]; then
        state="done"
    elif [ "$exit_code" -eq 2 ]; then
        state="deferred"
        if [ "$op" = "graduate" ]; then
            # S-10 (#1354): EVERY graduate defer is requeued — the op is a one-shot
            # migration that should eventually complete once the entity goes idle
            # (mount_busy / bake_lock_held / upper_not_empty / bake_landed…).
            # Backoff 60→300→600 s capped; total budget GRADUATE_WAIT_CAP_S (24 h),
            # NOT storage_target_wait_s, then failed + notify.
            local g_elapsed=$(( now - queued_at ))
            local g_cap="$GRADUATE_WAIT_CAP_S"
            case "$g_cap" in ''|*[!0-9]*) g_cap=86400 ;; esac
            if [ "$g_elapsed" -ge "$g_cap" ]; then
                state="failed"
                log_warn "Graduate job $job_id for $entity exhausted its 24 h wait budget (${g_elapsed}s >= ${g_cap}s) — failing"
                _supervisor_notify "warning" "AICliAgents: Storage graduation timed out" \
                    "Graduating $entity to the passthrough backend kept deferring for 24 h (last reason: ${defer}). The layered data is untouched — retry from the Storage tab when the home is idle." \
                    "graduate_wait_timeout_${entity}" 3600
                lifecycle_log "warn" "supervisor" "graduate_job_wait_exhausted" \
                    "{\"entity\":\"$entity\",\"job_id\":\"$job_id\",\"elapsed_s\":$g_elapsed,\"cap_s\":$g_cap,\"defer_reason\":\"$defer\"}" 2>/dev/null || true
            else
                local g_delay g_retry_at
                g_delay="$(_graduate_retry_delay "$attempt")"
                g_retry_at=$(( now + g_delay ))
                mkdir -p "$JOB_RETRY_DIR" 2>/dev/null || true
                local g_trace_kv=""
                [ -n "$trace" ] && g_trace_kv=",\"trace\":\"$trace\""
                _atomic_json_write "$JOB_RETRY_DIR/${job_id}.retry" \
                    "$(printf '{"job_id":"%s","type":"%s","id":"%s","op":"%s","reason":"%s","priority":%d,"retry_at":%d%s}' \
                        "$job_id" "$type" "$id" "$op" "$reason" "$priority" "$g_retry_at" "$g_trace_kv")" || true
                attempt=$(( attempt + 1 ))
                lifecycle_log "info" "supervisor" "graduate_job_requeued" \
                    "{\"entity\":\"$entity\",\"job_id\":\"$job_id\",\"attempt\":$attempt,\"retry_in_s\":$g_delay,\"defer_reason\":\"$defer\"}" 2>/dev/null || true
                log_info "Graduate job $job_id deferred ($defer) — retry #$attempt in ${g_delay}s"
            fi
        elif { [ "$op" = "consolidate" ] || [ "$op" = "bake" ]; } \
             && { [ "$reason" = "user_consolidate" ] || [ "$reason" = "user_persist" ]; } \
             && { [ "$defer" = "mount_busy" ] || [ "$defer" = "bake_lock_held" ] || [ "$defer" = "upper_not_empty" ]; }; then
            # OP#1381: a USER-INITIATED consolidate/bake that deferred because the
            # overlay is still held open (mount_busy / a racing bake's lock /
            # writes-during-bake) is a one-shot the user explicitly asked for — it
            # must eventually LAND once the workspace closes, not die terminal as a
            # plain `deferred` record. Park it on the retry pen (same machinery as
            # mount/graduate): _check_job_retries re-enqueues it when retry_at
            # passes, and the session-close path re-enqueues immediately when the
            # last session for the entity closes. Backoff 15→60→120 s; total budget
            # USER_CONSOLIDATE_WAIT_CAP_S (1 h), then FAILED + notify. A genuinely
            # still-busy entity just keeps deferring on each retry (no tight loop —
            # the backoff bounds the cadence).
            local uc_elapsed=$(( now - queued_at ))
            local uc_cap="$USER_CONSOLIDATE_WAIT_CAP_S"
            case "$uc_cap" in ''|*[!0-9]*) uc_cap=3600 ;; esac
            if [ "$uc_elapsed" -ge "$uc_cap" ]; then
                state="failed"
                log_warn "User $op job $job_id for $entity kept deferring ($defer) past its ${uc_cap}s wait budget (${uc_elapsed}s) — failing"
                _supervisor_notify "warning" "AICliAgents: Consolidation could not complete" \
                    "Your requested ${op} of $entity kept deferring because the workspace stayed busy (last reason: ${defer}). The layered data is untouched — close the workspace and retry from the Storage tab." \
                    "user_consolidate_wait_timeout_${entity}" 3600
                lifecycle_log "warn" "supervisor" "user_consolidate_wait_exhausted" \
                    "{\"entity\":\"$entity\",\"job_id\":\"$job_id\",\"op\":\"$op\",\"elapsed_s\":$uc_elapsed,\"cap_s\":$uc_cap,\"defer_reason\":\"$defer\"}" 2>/dev/null || true
                # HOME_CONSOLIDATE give-up: the user must NEVER be stranded waiting
                # for the UI. Relaunch the closed sessions in the BACKGROUND
                # (headless) AND clear the per-user start guard — _relaunch_home_sessions
                # clears the marker (epoch-aware) then resumes the closed set, exactly
                # like the success path (~line 1204). The consolidate didn't land
                # (data is untouched; it will retry on the next trigger), but the
                # user gets their sessions back without opening the tab. Only home
                # consolidates set the marker / have a closed-set manifest; the retry
                # path (else below) must NOT relaunch — sessions must stay closed so
                # the bake can land on the next idle window.
                if [ "$op" = "consolidate" ] && [ "$type" = "home" ]; then
                    _relaunch_home_sessions "$id" "$job_id"
                fi
            else
                local uc_delay uc_retry_at
                uc_delay="$(_user_consolidate_retry_delay "$attempt")"
                uc_retry_at=$(( now + uc_delay ))
                mkdir -p "$JOB_RETRY_DIR" 2>/dev/null || true
                local uc_trace_kv=""
                [ -n "$trace" ] && uc_trace_kv=",\"trace\":\"$trace\""
                _atomic_json_write "$JOB_RETRY_DIR/${job_id}.retry" \
                    "$(printf '{"job_id":"%s","type":"%s","id":"%s","op":"%s","reason":"%s","priority":%d,"retry_at":%d%s}' \
                        "$job_id" "$type" "$id" "$op" "$reason" "$priority" "$uc_retry_at" "$uc_trace_kv")" || true
                attempt=$(( attempt + 1 ))
                lifecycle_log "info" "supervisor" "user_consolidate_requeued" \
                    "{\"entity\":\"$entity\",\"job_id\":\"$job_id\",\"op\":\"$op\",\"attempt\":$attempt,\"retry_in_s\":$uc_delay,\"defer_reason\":\"$defer\"}" 2>/dev/null || true
                log_info "User $op job $job_id deferred ($defer) — retry #$attempt in ${uc_delay}s (until overlay frees or ${uc_cap}s cap)"
            fi
        elif [ "$op" = "mount" ] && { [ "$defer" = "target_not_mounted" ] || [ "$defer" = "bake_lock_held" ]; }; then
            local elapsed=$(( now - queued_at ))
            local wait_cap="$STORAGE_TARGET_WAIT_S"
            case "$wait_cap" in ''|*[!0-9]*) wait_cap=300 ;; esac
            if [ "$elapsed" -ge "$wait_cap" ]; then
                state="failed"
                log_warn "Mount job $job_id for $entity exhausted storage_target_wait_s (${elapsed}s >= ${wait_cap}s) — failing"
                _supervisor_notify "warning" "AICliAgents: Storage target wait timed out" \
                    "The persistence target for $entity did not become available within ${wait_cap}s (last reason: ${defer}). Check that the device/pool is mounted, then reopen the workspace." \
                    "mount_wait_timeout_${entity}" 3600
                lifecycle_log "warn" "supervisor" "mount_job_wait_exhausted" \
                    "{\"entity\":\"$entity\",\"job_id\":\"$job_id\",\"elapsed_s\":$elapsed,\"cap_s\":$wait_cap,\"defer_reason\":\"$defer\"}" 2>/dev/null || true
            else
                # Park a retry: re-enqueued by _check_job_retries once retry_at passes.
                local delay retry_at
                delay="$(_mount_retry_delay "$attempt")"
                retry_at=$(( now + delay ))
                mkdir -p "$JOB_RETRY_DIR" 2>/dev/null || true
                local trace_kv=""
                [ -n "$trace" ] && trace_kv=",\"trace\":\"$trace\""
                _atomic_json_write "$JOB_RETRY_DIR/${job_id}.retry" \
                    "$(printf '{"job_id":"%s","type":"%s","id":"%s","op":"%s","reason":"%s","priority":%d,"retry_at":%d%s}' \
                        "$job_id" "$type" "$id" "$op" "$reason" "$priority" "$retry_at" "$trace_kv")" || true
                attempt=$(( attempt + 1 ))
                lifecycle_log "info" "supervisor" "mount_job_requeued" \
                    "{\"entity\":\"$entity\",\"job_id\":\"$job_id\",\"attempt\":$attempt,\"retry_in_s\":$delay,\"defer_reason\":\"$defer\"}" 2>/dev/null || true
                log_info "Mount job $job_id deferred ($defer) — retry #$attempt in ${delay}s"
            fi
        fi
    fi

    job_ledger_write "$job_id" "$op" "$type" "$id" "$state" "$exit_code" "$defer" \
        "$attempt" "$queued_at" "$started_at" "$now" "$reason" "$trace" "$prev_epoch" || true
}

# _check_job_retries — re-enqueue parked deferred jobs whose retry_at passed.
# Crash-safe: the .retry file is removed BEFORE the enqueue; a crash between
# the two loses one retry tick, never duplicates a job.
_check_job_retries() {
    [ -d "$JOB_RETRY_DIR" ] || return 0
    local f now
    now=$(date +%s)
    for f in "$JOB_RETRY_DIR"/*.retry; do
        [ -f "$f" ] || continue
        local retry_at
        retry_at="$(queue_read_field "$f" retry_at 2>/dev/null)"
        case "$retry_at" in ''|*[!0-9]*) retry_at=0 ;; esac
        [ "$now" -ge "$retry_at" ] || continue
        local r_job r_type r_id r_op r_reason r_trace r_prio
        r_job="$(queue_read_field "$f" job_id 2>/dev/null)"
        r_type="$(queue_read_field "$f" type 2>/dev/null)"
        r_id="$(queue_read_field "$f" id 2>/dev/null)"
        r_op="$(queue_read_field "$f" op 2>/dev/null)"
        r_reason="$(queue_read_field "$f" reason 2>/dev/null)"
        r_trace="$(queue_read_field "$f" trace 2>/dev/null)"
        r_prio="$(queue_read_field "$f" priority 2>/dev/null)"
        case "$r_prio" in ''|*[!0-9]*) r_prio=5 ;; esac
        rm -f "$f" 2>/dev/null || true
        [ -n "$r_type" ] && [ -n "$r_id" ] && [ -n "$r_op" ] || continue
        queue_enqueue "$r_prio" "$r_type" "$r_id" "$r_op" "$r_reason" "$r_trace" "$r_job" 2>/dev/null || true
        log_info "Job retry matured: re-enqueued $r_op for $r_type/$r_id (job=$r_job)"
    done
}

# OP#1381: self-contained overlay busy-arbiter for the supervisor scope.
#
# The supervisor does NOT source common.sh (see the _intent_delete_segment note),
# so home_mount_in_use is not reliably in scope here — calling it bare would error
# (127) and READ AS IDLE, which would let a parked user-consolidate resume while a
# session is still live (exactly the data-unsafe remount-under-session the storage
# layer guards against). This mirrors common.sh::home_mount_in_use EXACTLY (fuser
# fast-path + the ttyd AICLI_HOME=<mount> argv scan) but stands alone here. If a
# real home_mount_in_use IS in scope (e.g. a test stubs it), prefer that.
#
# Returns 0 (busy) / 1 (idle). Best-effort; a probe error is treated as BUSY
# (fail-safe: never resume on an indeterminate result).
_overlay_busy_for_resume() {
    local mnt="$1"
    [ -n "$mnt" ] || return 0   # no mount -> can't prove idle -> treat as busy
    if declare -f home_mount_in_use >/dev/null 2>&1; then
        home_mount_in_use "$mnt" && return 0
        return 1
    fi
    # Fast path: any open fd / cwd / exe / mmap physically on the fs.
    fuser -sm "$mnt" 2>/dev/null && return 0
    # Live interactive session: a ttyd whose argv carries AICLI_HOME=<mount>.
    local _pid
    for _pid in $(pgrep -x ttyd 2>/dev/null); do
        if tr '\0' '\n' < "/proc/$_pid/cmdline" 2>/dev/null | grep -qxF "AICLI_HOME=$mnt"; then
            return 0
        fi
    done
    return 1
}

# OP#1381: overlay-free early-resume for parked user consolidate/bake retries.
#
# The backoff timer in _check_job_retries is the SAFETY NET — it will eventually
# re-enqueue a deferred user consolidate/bake. This function is the FAST PATH: the
# moment the entity's overlay goes idle (the last live session for it closed → no
# fuser handle, no ttyd carrying its mount), there is no reason to keep waiting out
# the backoff. We pull such a parked retry's retry_at forward to now so the very
# next _check_job_retries (same tick, called right after this) matures it. We do
# NOT enqueue here ourselves — keeping a SINGLE enqueue path (the crash-safe
# rm-before-enqueue in _check_job_retries) avoids any double-enqueue race.
#
# Only USER-INITIATED consolidate/bake parked retries are eligible (reason
# user_consolidate / user_persist); mount/graduate retries keep their own cadence.
# A still-busy entity is left alone (its timer keeps it on backoff — no tight loop).
_check_deferred_consolidate_resume() {
    [ -d "$JOB_RETRY_DIR" ] || return 0
    local f now
    now=$(date +%s)
    for f in "$JOB_RETRY_DIR"/*.retry; do
        [ -f "$f" ] || continue
        local r_op r_reason r_type r_id
        r_op="$(queue_read_field "$f" op 2>/dev/null)"
        r_reason="$(queue_read_field "$f" reason 2>/dev/null)"
        case "$r_op" in consolidate|bake) : ;; *) continue ;; esac
        case "$r_reason" in user_consolidate|user_persist) : ;; *) continue ;; esac
        r_type="$(queue_read_field "$f" type 2>/dev/null)"
        r_id="$(queue_read_field "$f" id 2>/dev/null)"
        [ -n "$r_type" ] && [ -n "$r_id" ] || continue

        # Already mature? Leave it for _check_job_retries — nothing to pull forward.
        local r_retry_at
        r_retry_at="$(queue_read_field "$f" retry_at 2>/dev/null)"
        case "$r_retry_at" in ''|*[!0-9]*) r_retry_at=0 ;; esac
        [ "$now" -lt "$r_retry_at" ] || continue

        # Idle probe via the self-contained supervisor-scope arbiter (mirrors
        # common.sh::home_mount_in_use — fuser + ttyd AICLI_HOME scan). Homes probe
        # the overlay mount; agents probe the agent mount base. If the probe can't
        # prove idle, it returns BUSY (fail-safe) and we leave the backoff intact —
        # never a tight loop, never a resume-under-session.
        local mnt=""
        if [ "$r_type" = "home" ]; then
            mnt="$(home_mount "$r_id" 2>/dev/null)"
        elif declare -f agent_mount >/dev/null 2>&1; then
            mnt="$(agent_mount "$r_id" 2>/dev/null)"
        fi
        _overlay_busy_for_resume "$mnt" && continue

        # Overlay is idle — pull the retry forward so the next _check_job_retries
        # (this same tick) matures it. Rewrite the .retry with retry_at=now,
        # preserving every other field. Atomic (tmp + rename).
        local r_job r_trace r_prio
        r_job="$(queue_read_field "$f" job_id 2>/dev/null)"
        r_trace="$(queue_read_field "$f" trace 2>/dev/null)"
        r_prio="$(queue_read_field "$f" priority 2>/dev/null)"
        case "$r_prio" in ''|*[!0-9]*) r_prio=5 ;; esac
        local rt_trace_kv=""
        [ -n "$r_trace" ] && rt_trace_kv=",\"trace\":\"$r_trace\""
        _atomic_json_write "$f" \
            "$(printf '{"job_id":"%s","type":"%s","id":"%s","op":"%s","reason":"%s","priority":%d,"retry_at":%d%s}' \
                "$r_job" "$r_type" "$r_id" "$r_op" "$r_reason" "$r_prio" "$now" "$rt_trace_kv")" || true
        lifecycle_log "info" "supervisor" "user_consolidate_resume_on_idle" \
            "{\"entity\":\"${r_type}/${r_id}\",\"job_id\":\"$r_job\",\"op\":\"$r_op\"}" 2>/dev/null || true
        log_info "Overlay idle for ${r_type}/${r_id} — pulling parked $r_op (job=$r_job) forward for immediate retry"
    done
}

# _reap_job_ledger — prune terminal ledger entries: done after 1 h,
# failed/deferred after 24 h (mtime of the last transition). Orphaned .retry
# files whose ledger entry is gone are dropped too. Called from _op_reconcile.
_reap_job_ledger() {
    [ -d "$JOBS_DIR" ] || return 0
    local f state now mtime age
    now=$(date +%s)
    for f in "$JOBS_DIR"/*.json; do
        [ -f "$f" ] || continue
        state="$(queue_read_field "$f" state 2>/dev/null)"
        mtime=$(stat -c '%Y' "$f" 2>/dev/null || echo "$now")
        age=$(( now - mtime ))
        case "$state" in
            done)              [ "$age" -gt 3600 ]  && rm -f "$f" 2>/dev/null ;;
            failed|deferred)   [ "$age" -gt 86400 ] && rm -f "$f" 2>/dev/null ;;
            queued|running)    : ;;  # active — never reaped here
            *)                 [ "$age" -gt 86400 ] && rm -f "$f" 2>/dev/null ;;
        esac
    done
    if [ -d "$JOB_RETRY_DIR" ]; then
        for f in "$JOB_RETRY_DIR"/*.retry; do
            [ -f "$f" ] || continue
            local jid
            jid="$(queue_read_field "$f" job_id 2>/dev/null)"
            if [ -n "$jid" ] && [ ! -f "$JOBS_DIR/${jid}.json" ]; then
                rm -f "$f" 2>/dev/null || true
            fi
        done
    fi
}

# ---------------------------------------------------------------------------
# Dirty-pressure watchdog
# Called every tick. Computes total dirty bytes; enqueues bakes at thresholds.
# ---------------------------------------------------------------------------
_check_dirty_pressure() {
    local zram_base
    zram_base="${ZRAM_BASE:-/tmp/unraid-aicliagents/zram_upper}"
    [ -d "$zram_base" ] || return 0

    local total_bytes=0
    local entity_sizes=()  # "bytes:type:id" tuples

    # WP #748 J — agents are single-layer-per-install under J; their ZRAM upper
    # is always empty outside an install (which bakes via consolidate, not the
    # supervisor). So the dirty-pressure walk is home-only — if dirt ever does
    # appear in an agent's upper, the supervisor should NOT paper over it by
    # enqueueing a delta-bake (that would re-introduce multi-layer agents).
    local upper_root
    for upper_root in "$zram_base/homes"; do
        [ -d "$upper_root" ] || continue
        local entity_type="home"

        local entity_dir
        for entity_dir in "$upper_root"/*/; do
            [ -d "$entity_dir" ] || continue
            local entity_id
            entity_id=$(basename "$entity_dir")
            local upper_dir="${entity_dir}upper"
            [ -d "$upper_dir" ] || continue
            local du_bytes
            du_bytes=$(du -sb "$upper_dir" 2>/dev/null | awk '{print $1}' || echo 0)
            [ "$du_bytes" -gt 0 ] || continue
            total_bytes=$(( total_bytes + du_bytes ))
            entity_sizes+=("${du_bytes}:${entity_type}:${entity_id}")
        done
    done

    [ "$total_bytes" -gt 0 ] || return 0

    # Convert thresholds to bytes
    local soft_bytes=$(( DIRTY_SOFT_MB * 1024 * 1024 ))
    local hard_bytes=$(( DIRTY_HARD_MB * 1024 * 1024 ))
    local critical_bytes=$(( DIRTY_CRITICAL_MB * 1024 * 1024 ))

    local now
    now=$(date +%s)

    if [ "$total_bytes" -ge "$critical_bytes" ]; then
        # Critical: all of hard + write global halt + persistent notification
        lifecycle_log "warn" "supervisor" "dirty_pressure_critical" \
            "{\"total_bytes\":$total_bytes,\"threshold_bytes\":$critical_bytes}" 2>/dev/null || true

        # Write global halt
        local global_halt="${HALTS_DIR}/_global:critical_pressure"
        mkdir -p "$HALTS_DIR" 2>/dev/null || true
        printf '%d\n' "$now" > "$global_halt" 2>/dev/null || true

        # Rate-limited notification (once per 30 min)
        if [ $(( now - _LAST_CRITICAL_NOTIFY )) -ge 1800 ]; then
            _LAST_CRITICAL_NOTIFY=$now
            local total_mb=$(( total_bytes / 1024 / 1024 ))
            _supervisor_notify "critical" "AICliAgents: Critical storage pressure" \
                "Critical: ${total_mb}MB of unflushed changes in ZRAM. New sessions are suspended. Existing sessions continue but no new agent starts allowed." \
                "_global_critical_pressure" 1800
        fi

        # Sort by size descending, enqueue bake for each
        local sorted_entities
        sorted_entities=$(printf '%s\n' "${entity_sizes[@]:-}" | sort -t: -k1 -rn 2>/dev/null || true)
        while IFS=: read -r du_b etype eid; do
            [ -n "$etype" ] && [ -n "$eid" ] || continue
            queue_enqueue 50 "$etype" "$eid" "bake" "dirty_pressure_critical" 2>/dev/null || true
        done <<< "$sorted_entities"

    elif [ "$total_bytes" -ge "$hard_bytes" ]; then
        # Hard: all of soft + Unraid notification
        lifecycle_log "warn" "supervisor" "dirty_pressure_hard" \
            "{\"total_bytes\":$total_bytes,\"threshold_bytes\":$hard_bytes}" 2>/dev/null || true

        if [ $(( now - _LAST_HARD_NOTIFY )) -ge 1800 ]; then
            _LAST_HARD_NOTIFY=$now
            local total_mb=$(( total_bytes / 1024 / 1024 ))
            _supervisor_notify "warning" "AICliAgents: High storage pressure" \
                "Warning: ${total_mb}MB of unflushed changes. The supervisor is flushing now; consider closing non-critical sessions." \
                "_global_hard_pressure" 1800
        fi

        local sorted_entities
        sorted_entities=$(printf '%s\n' "${entity_sizes[@]:-}" | sort -t: -k1 -rn 2>/dev/null || true)
        while IFS=: read -r du_b etype eid; do
            [ -n "$etype" ] && [ -n "$eid" ] || continue
            queue_enqueue 50 "$etype" "$eid" "bake" "dirty_pressure_hard" 2>/dev/null || true
        done <<< "$sorted_entities"

    elif [ "$total_bytes" -ge "$soft_bytes" ]; then
        # Soft: enqueue bake for every dirty entity, largest first, lz4 compression
        lifecycle_log "info" "supervisor" "dirty_pressure_soft" \
            "{\"total_bytes\":$total_bytes,\"threshold_bytes\":$soft_bytes}" 2>/dev/null || true

        local sorted_entities
        sorted_entities=$(printf '%s\n' "${entity_sizes[@]:-}" | sort -t: -k1 -rn 2>/dev/null || true)
        while IFS=: read -r du_b etype eid; do
            [ -n "$etype" ] && [ -n "$eid" ] || continue
            queue_enqueue 50 "$etype" "$eid" "bake" "dirty_pressure_soft" 2>/dev/null || true
        done <<< "$sorted_entities"
    fi
}

# ---------------------------------------------------------------------------
# Schedule trigger: enqueue bakes for entities past their schedule window
# ---------------------------------------------------------------------------
_check_schedule_trigger() {
    local mpath
    mpath="$(manifest_path 2>/dev/null || echo '/boot/config/plugins/unraid-aicliagents/layer_manifest.json')"
    [ -f "$mpath" ] || return 0

    local now
    now=$(date +%s)
    local schedule_interval=$(( BAKE_SCHEDULE_MINUTES * 60 ))

    # Read all entities and their last_known_good_at via PHP
    [ "$(command -v php)" ] || return 0

    local entity_data
    entity_data=$(php -d display_errors=0 -r "
        \$m = json_decode(@file_get_contents('$mpath'), true);
        if (!is_array(\$m)) exit;
        foreach (\$m['entities'] ?? [] as \$k => \$v) {
            \$lkg = \$v['last_known_good_at'] ?? '';
            echo \$k . \"\t\" . \$lkg . PHP_EOL;
        }
    " 2>/dev/null || true)

    [ -n "$entity_data" ] || return 0

    while IFS=$'\t' read -r entity last_known_good; do
        [ -n "$entity" ] || continue

        local last_epoch=0
        if [ -n "$last_known_good" ] && [ "$last_known_good" != "null" ]; then
            last_epoch=$(date -d "$last_known_good" +%s 2>/dev/null || echo 0)
        fi

        local age=$(( now - last_epoch ))
        if [ "$age" -ge "$schedule_interval" ]; then
            local type id
            type=$(echo "$entity" | cut -d/ -f1)
            id=$(echo "$entity" | cut -d/ -f2-)
            [ -n "$type" ] && [ -n "$id" ] || continue

            # WP #748 J — schedule-bake is home-only. Agents bake on install
            # /upgrade via consolidate; the scheduled cadence is a safety-net for
            # the home overlay's accumulated dirty writes, not for immutable
            # agent layers. Skipping agents structurally (vs. relying on the
            # empty-upper guard below) prevents an out-of-band agent-dirty state
            # from ever triggering a delta-bake.
            [ "$type" = "home" ] || continue

            # Check if there are any dirty bytes for this entity (only bake if needed)
            local upper_dir
            upper_dir="$(zram_upper "$type" "$id" 2>/dev/null || true)"
            if [ -d "$upper_dir" ] && [ -n "$(ls -A "$upper_dir" 2>/dev/null)" ]; then
                queue_enqueue 99 "$type" "$id" "bake" "schedule" 2>/dev/null || true
                lifecycle_log "info" "supervisor" "schedule_bake_enqueued" \
                    "{\"entity\":\"$entity\",\"age_s\":$age,\"threshold_s\":$schedule_interval}" 2>/dev/null || true
            fi
        fi
    done <<< "$entity_data"
}

# ---------------------------------------------------------------------------
# WP #748 Phase 1 (E): wants-bake flag check
# gracefulClose writes /tmp/unraid-aicliagents/supervisor/wants-bake/home_<user>
# instead of enqueuing an immediate bake. This function consumes those flags on
# every tick — enqueuing a bake for each flagged home entity (bypassing the
# schedule-window check so the next tick bakes even if recently baked) — then
# deletes each flag atomically. Missed ticks are fine: the flag just accumulates
# until the next tick; the bake deduplicates via the queue.
# ---------------------------------------------------------------------------
_check_wants_bake_flags() {
    local wants_bake_dir="${STATUS_DIR}/supervisor/wants-bake"
    [ -d "$wants_bake_dir" ] || return 0

    local flag_file
    for flag_file in "$wants_bake_dir"/home_*; do
        [ -f "$flag_file" ] || continue
        local fname
        fname="$(basename "$flag_file")"
        # Extract id: flag name is home_<safeUser>
        local user_id="${fname#home_}"
        [ -n "$user_id" ] || continue

        # Consume the flag first (atomic remove) so we don't double-enqueue on crash
        rm -f "$flag_file" 2>/dev/null || true

        # Only enqueue if there are dirty bytes — same guard as schedule trigger
        local upper_dir
        upper_dir="$(zram_upper "home" "$user_id" 2>/dev/null || true)"
        if [ -d "$upper_dir" ] && [ -n "$(ls -A "$upper_dir" 2>/dev/null)" ]; then
            queue_enqueue 20 "home" "$user_id" "bake" "workspace_close" 2>/dev/null || true
            lifecycle_log "info" "supervisor" "wants_bake_flag_consumed" \
                "{\"entity\":\"home/$user_id\",\"reason\":\"workspace_close\"}" 2>/dev/null || true
            log_info "wants-bake flag consumed for home/$user_id — bake enqueued"
        else
            lifecycle_log "info" "supervisor" "wants_bake_flag_skipped_empty" \
                "{\"entity\":\"home/$user_id\"}" 2>/dev/null || true
        fi
    done
}

# ---------------------------------------------------------------------------
# Phase 5: homes-only policy-driven consolidate enqueue.
#
# Replaces the old count>=5 auto-consolidate that lived in PHP
# StorageMountService::commitChanges. Each tick, for every HOME entity in the
# manifest, ask storagectl for the consolidate verdict and enqueue a low-priority
# consolidate when the policy recommends it (stack near the overlay ceiling, or
# persist under space pressure). Agents are excluded — storagectl omits the verdict
# for them and they collapse to one layer per install anyway.
#
# queue_enqueue does NOT dedup (filenames carry a unique epoch), so we skip the
# enqueue when a consolidate for this entity is already pending — otherwise a home
# parked at the threshold would pile up one .req per tick until the op drains.
# ---------------------------------------------------------------------------
_check_consolidate_policy() {
    local mpath
    mpath="$(manifest_path 2>/dev/null || echo '/boot/config/plugins/unraid-aicliagents/layer_manifest.json')"
    [ -f "$mpath" ] || return 0
    [ "$(command -v php)" ] || return 0

    local home_ids
    home_ids=$(php -d display_errors=0 -r "
        \$m = json_decode(@file_get_contents('$mpath'), true);
        if (!is_array(\$m)) exit;
        foreach (\$m['entities'] ?? [] as \$k => \$v) {
            if (strpos(\$k, 'home/') === 0) echo substr(\$k, 5) . PHP_EOL;
        }
    " 2>/dev/null || true)
    [ -n "$home_ids" ] || return 0

    local id persist json reason safe_id existing
    while IFS= read -r id; do
        [ -n "$id" ] || continue
        persist="$(home_persist_path "$id" 2>/dev/null || true)"
        [ -n "$persist" ] || continue

        json="$(bash "${STORAGE_DIR}/storagectl.sh" status --type home --id "$id" --persist "$persist" 2>/dev/null)"
        # The consolidate object is emitted only for homes; "recommended":true appears
        # nowhere else in the status JSON, so a substring test is unambiguous.
        case "$json" in
            *'"recommended":true'*) : ;;
            *) continue ;;
        esac

        # Skip if a consolidate is already queued for this entity (no per-tick pile-up).
        safe_id="$(printf '%s' "$id" | tr '/ ' '__')"
        existing="$(ls "${QUEUE_DIR}"/*_home_"${safe_id}"_consolidate.req 2>/dev/null | head -1)"
        [ -n "$existing" ] && continue

        # `defer_reason` can't false-match: its key is `defer_reason`, not `reason`.
        reason="$(printf '%s' "$json" | grep -oP '"reason":"\K[^"]+' | head -1)"
        [ -n "$reason" ] || reason="policy"
        queue_enqueue 90 "home" "$id" "consolidate" "policy:${reason}" 2>/dev/null || true
        lifecycle_log "info" "supervisor" "consolidate_policy_enqueued" \
            "{\"entity\":\"home/$id\",\"reason\":\"$reason\"}" 2>/dev/null || true
        log_info "Consolidate policy: enqueued home/$id (reason=$reason)"
    done <<< "$home_ids"
}

# ---------------------------------------------------------------------------
# WP #1262 (#6): force-reclaim escalation.
#
# The reclaim/consolidate-at-idle path only runs when a home is idle (the
# post-bake reclaim and _check_consolidate_policy both defer on a live session).
# A permanently-connected session would therefore defer reclaim FOREVER, letting
# the ZRAM upper grow unbounded and the delta stack march to the hard ceiling.
# This is the bounded escape valve: when a home is BUSY (live session) AND
# storagectl recommends consolidation (layers >= ceiling-2, or space pressure),
# arm a user-warned countdown; at the deadline gracefully force-close the home's
# sessions (resume-id preserved — TerminalHandler::gracefulClose) so the home
# reaches idle and the already-deferred consolidate runs. Below threshold OR
# idle, do nothing and let natural idle drive reclaim. NEVER fires for a routine
# bake — only when consolidation is actually recommended.
# ---------------------------------------------------------------------------

# Countdown duration (seconds). Precedence: env (tests/tuning) > cfg > 300 (5min).
_force_reclaim_countdown_sec() {
    echo "${AICLI_FORCE_RECLAIM_COUNTDOWN_SEC:-${force_reclaim_countdown_sec:-300}}"
}

# Injectable clock so the state machine is unit-testable without faking date(1).
_now_epoch() { date +%s; }

# Echo newline-separated home ids from the manifest (homes only).
_manifest_home_ids() {
    local mpath
    mpath="$(manifest_path 2>/dev/null || echo '/boot/config/plugins/unraid-aicliagents/layer_manifest.json')"
    [ -f "$mpath" ] || return 0
    [ "$(command -v php)" ] || return 0
    php -d display_errors=0 -r "
        \$m = json_decode(@file_get_contents('$mpath'), true);
        if (!is_array(\$m)) exit;
        foreach (\$m['entities'] ?? [] as \$k => \$v) {
            if (strpos(\$k, 'home/') === 0) echo substr(\$k, 5) . PHP_EOL;
        }
    " 2>/dev/null || true
}

# _home_reclaim_recommended <id> <persist> — return 0 if storagectl recommends
# consolidate/reclaim for this home; sets _RECLAIM_REASON to the verdict reason.
# Same signal _check_consolidate_policy uses (consolidate.recommended), so the
# escalation arms on exactly the same threshold that the (deferred) consolidate
# is already waiting on.
_home_reclaim_recommended() {
    local id="$1" persist="$2" json
    _RECLAIM_REASON=""
    json="$(bash "${STORAGE_DIR}/storagectl.sh" status --type home --id "$id" --persist "$persist" 2>/dev/null)"
    case "$json" in
        *'"recommended":true'*) : ;;
        *) return 1 ;;
    esac
    _RECLAIM_REASON="$(printf '%s' "$json" | grep -oP '"reason":"\K[^"]+' | head -1)"
    [ -n "$_RECLAIM_REASON" ] || _RECLAIM_REASON="policy"
    return 0
}

# _force_close_home_sessions <user> — close every live session on this home,
# preserving resume ids. Reuses the proven PHP gracefulClose path (flush +
# resume-id scrape + ttyd/tmux teardown) headlessly via a CLI bridge, so it works
# whether or not a browser is connected. Best-effort; never aborts the tick.
_force_close_home_sessions() {
    local user="$1"
    [ -n "$user" ] || return 0
    case "$user" in ''|*[!A-Za-z0-9._-]*) return 0 ;; esac
    case "$user" in *..*) return 0 ;; esac
    [ "$(command -v php)" ] || return 0
    AICLI_CLOSE_USER="$user" php -d display_errors=0 -r '
        $_SERVER["DOCUMENT_ROOT"]="/usr/local/emhttp";
        require_once "/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/AICliAgentsManager.php";
        if (method_exists("\AICliAgents\Services\TerminalService","forceCloseHome")) {
            \AICliAgents\Services\TerminalService::forceCloseHome((string)getenv("AICLI_CLOSE_USER"));
        }
    ' 2>/dev/null || true
}

# _close_home_for_consolidate <user> — run TerminalService::forceCloseHome for the
# given user. Called ONCE per home consolidate job (before the mount-busy check) so
# the home's ttyds and all sessions (including unregistered reconnect sessions) are
# torn down before storagectl consolidate runs. User travels via env — SECURITY:
# never splice $user into the php -r script body. Best-effort; never aborts the tick.
_close_home_for_consolidate() {
    local user="$1"
    [ -n "$user" ] || return 0
    case "$user" in ''|*[!A-Za-z0-9._-]*) return 0 ;; esac
    case "$user" in *..*) return 0 ;; esac
    [ "$(command -v php)" ] || return 0
    AICLI_CLOSE_USER="$user" php -d display_errors=1 -r '
        $_SERVER["DOCUMENT_ROOT"]="/usr/local/emhttp";
        require_once "/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/AICliAgentsManager.php";
        $th="/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/handlers/TerminalHandler.php";
        if (file_exists($th)) { require_once $th; }
        if (method_exists("\AICliAgents\Services\TerminalService","forceCloseHome")) {
            \AICliAgents\Services\TerminalService::forceCloseHome((string)getenv("AICLI_CLOSE_USER"));
        }
    ' || true
}

# _clear_home_consolidating <user> [job_id] — clear the HOME_CONSOLIDATE_INPROGRESS_GUARD
# (R4) per-user marker so TerminalHandler::start unblocks once the consolidate is
# DONE — whether it succeeded, failed, or finally gave up. Without this, the start
# guard would only unwedge via the staleness fallback (600 s). The user travels via
# env (no interpolation into php -r) — SECURITY: never splice $user into the script.
# R3.2 (H1 fix): reads the epoch from the job ledger (keyed by job_id — per-job, not
# per-entity), so each job reads its own epoch back; sidecar removed. Best-effort;
# never aborts the tick.
_clear_home_consolidating() {
    local user="$1"
    local job_id="${2:-}"
    [ -n "$user" ] || return 0
    case "$user" in ''|*[!A-Za-z0-9._-]*) return 0 ;; esac
    case "$user" in *..*) return 0 ;; esac
    [ "$(command -v php)" ] || return 0
    local epoch=""
    if [ -n "$job_id" ] && declare -f job_ledger_field >/dev/null 2>&1; then
        epoch="$(job_ledger_field "$job_id" consolidate_epoch 2>/dev/null)"
    fi
    AICLI_CLEAR_USER="$user" AICLI_CONSOLIDATE_EPOCH="$epoch" php -d display_errors=0 -r '
        $_SERVER["DOCUMENT_ROOT"]="/usr/local/emhttp";
        require_once "/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/AICliAgentsManager.php";
        if (method_exists("\AICliAgents\Services\ConsolidateState","clearHomeConsolidating")) {
            $epoch = (string)getenv("AICLI_CONSOLIDATE_EPOCH");
            \AICliAgents\Services\ConsolidateState::clearHomeConsolidating(
                (string)getenv("AICLI_CLEAR_USER"),
                $epoch !== "" ? $epoch : null
            );
        }
    ' 2>/dev/null || true
}

# _relaunch_home_sessions <user> [job_id] — relaunch exactly the sessions that the manual
# home consolidate closed (per-user manifest, per-entry agentId), resumed. Fired
# ONLY from the consolidate-success path (HOME_CONSOLIDATE_CLOSE_RELAUNCH R3).
# relaunchHomeSet deletes the manifest, so a repeat tick is a no-op. Best-effort.
# Also clears the HOME_CONSOLIDATE_INPROGRESS_GUARD marker (R4) so `start`
# unblocks at the same instant the relaunch fires.
# R3.2 (H1 fix): reads the epoch from the job ledger (per-job — not per-entity sidecar).
_relaunch_home_sessions() {
    local user="$1"
    local job_id="${2:-}"
    [ -n "$user" ] || return 0
    case "$user" in ''|*[!A-Za-z0-9._-]*) return 0 ;; esac
    case "$user" in *..*) return 0 ;; esac
    [ "$(command -v php)" ] || return 0
    local epoch=""
    if [ -n "$job_id" ] && declare -f job_ledger_field >/dev/null 2>&1; then
        epoch="$(job_ledger_field "$job_id" consolidate_epoch 2>/dev/null)"
    fi
    # R3.5: Drop the single-instance lock fd BEFORE spawning php (which calls
    # UpgradeRelaunchService::relaunchHomeSet → spawns long-lived ttyd + tmux).
    # Must run in a subshell so the supervisor's own flock is NOT released.
    # Without this, orphaned ttyd/tmux inherit $SUP_LOCK_FD and hold the OFD
    # alive after the supervisor exits — every subsequent `start` blocks for 10s
    # then exits as a loser, writing no pidfile (smoke symptom: pidfile not found).
    ( [ -n "${SUP_LOCK_FD:-}" ] && exec {SUP_LOCK_FD}>&- 2>/dev/null
      AICLI_RELAUNCH_USER="$user" AICLI_CONSOLIDATE_EPOCH="$epoch" php -d display_errors=0 -r '
        $_SERVER["DOCUMENT_ROOT"]="/usr/local/emhttp";
        require_once "/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/AICliAgentsManager.php";
        if (method_exists("\AICliAgents\Services\ConsolidateState","clearHomeConsolidating")) {
            $epoch = (string)getenv("AICLI_CONSOLIDATE_EPOCH");
            \AICliAgents\Services\ConsolidateState::clearHomeConsolidating(
                (string)getenv("AICLI_RELAUNCH_USER"),
                $epoch !== "" ? $epoch : null
            );
        }
        if (method_exists("\AICliAgents\Services\UpgradeRelaunchService","relaunchHomeSet")) {
            \AICliAgents\Services\UpgradeRelaunchService::relaunchHomeSet((string)getenv("AICLI_RELAUNCH_USER"));
        }
    ' ) 2>/dev/null || true
}

# The state machine. See block comment above. Stub-overridable boundary helpers:
# _now_epoch, _manifest_home_ids, home_persist_path, home_mount,
# _home_reclaim_recommended, home_mount_in_use, _force_close_home_sessions.
_check_force_reclaim_escalation() {
    local esc_dir="${SUPERVISOR_DIR}/escalation"
    local countdown
    countdown="$(_force_reclaim_countdown_sec)"

    local ids
    ids="$(_manifest_home_ids)"
    [ -n "$ids" ] || return 0

    local now
    now="$(_now_epoch)"

    local id
    while IFS= read -r id; do
        [ -n "$id" ] || continue
        local safe_id state_file persist mnt
        safe_id="$(printf '%s' "$id" | tr '/ ' '__')"
        state_file="${esc_dir}/home_${safe_id}.json"
        persist="$(home_persist_path "$id" 2>/dev/null)"
        mnt="$(home_mount "$id" 2>/dev/null)"

        local recommended=0 busy=0
        if [ -n "$persist" ] && _home_reclaim_recommended "$id" "$persist"; then recommended=1; fi
        if [ -n "$mnt" ] && home_mount_in_use "$mnt"; then busy=1; fi

        if [ "$recommended" -eq 1 ] && [ "$busy" -eq 1 ]; then
            if [ ! -f "$state_file" ]; then
                # Arm: write countdown state + notify the user once.
                mkdir -p "$esc_dir" 2>/dev/null || true
                local deadline=$(( now + countdown ))
                _atomic_json_write "$state_file" \
                    "$(printf '{"entity":"home/%s","reason":"%s","started_at":%d,"deadline_epoch":%d,"state":"countdown"}' \
                        "$id" "$_RECLAIM_REASON" "$now" "$deadline")" || true
                local mins=$(( countdown / 60 ))
                _supervisor_notify "warning" "AICliAgents: storage reclaim scheduled" \
                    "Home '$id' needs storage reclamation but has a live session. Sessions will close in ${mins} min to free memory; you can resume exactly where you left off." \
                    "force_reclaim_${id}" 3600
                lifecycle_log "warn" "supervisor" "force_reclaim_armed" \
                    "{\"entity\":\"home/$id\",\"reason\":\"$_RECLAIM_REASON\",\"deadline_epoch\":$deadline}" 2>/dev/null || true
                log_info "Force-reclaim armed for home/$id (reason=$_RECLAIM_REASON, deadline in ${countdown}s)"
            else
                # Countdown in flight — fire at/after the deadline. Read the
                # deadline with sed (POSIX, no PCRE) so the state machine is
                # unit-testable on any grep build, not just .4's UTF-8 locale.
                local deadline
                deadline="$(sed -n 's/.*"deadline_epoch":\([0-9]*\).*/\1/p' "$state_file" 2>/dev/null | head -1)"
                [ -n "$deadline" ] || deadline=0
                if [ "$now" -ge "$deadline" ]; then
                    log_warn "Force-reclaim deadline reached for home/$id — closing sessions"
                    lifecycle_log "warn" "supervisor" "force_reclaim_firing" \
                        "{\"entity\":\"home/$id\"}" 2>/dev/null || true
                    # Flip to "closing" BEFORE the close so the UI renders the
                    # terminal state even if the close takes a moment.
                    _atomic_json_write "$state_file" \
                        "$(printf '{"entity":"home/%s","reason":"%s","state":"closing","fired_at":%d}' \
                            "$id" "$_RECLAIM_REASON" "$now")" || true
                    _force_close_home_sessions "$id"
                fi
            fi
        else
            # Condition no longer holds (home went idle, or pressure relieved):
            # stand down — drop any countdown so we never force-close a user who
            # already closed naturally.
            if [ -f "$state_file" ]; then
                rm -f "$state_file" 2>/dev/null || true
                lifecycle_log "info" "supervisor" "force_reclaim_cleared" \
                    "{\"entity\":\"home/$id\",\"recommended\":$recommended,\"busy\":$busy}" 2>/dev/null || true
                log_info "Force-reclaim stood down for home/$id (recommended=$recommended busy=$busy)"
            fi
        fi
    done <<< "$ids"
}

# ---------------------------------------------------------------------------
# Work loop — one iteration, called every tick
# ---------------------------------------------------------------------------
_work_tick() {
    # Step 0 (storm guard): re-assert the pidfile if it was lost or stolen, so the
    # pidfile-based backstop always sees us and never respawn-storms the supervisor.
    _ensure_pidfile

    # Step 0a (Bug #757): respawn the heartbeat if it died — otherwise the main
    # loop stays alive but $TICKFILE goes stale and the box looks supervisor-less.
    if [ -n "${_HEARTBEAT_PID:-}" ] && ! _pid_alive "$_HEARTBEAT_PID" 2>/dev/null; then
        _run_heartbeat &
        _HEARTBEAT_PID="$!"
        log_warn "Heartbeat process died — respawned (pid $_HEARTBEAT_PID)."
        lifecycle_log "warn" "supervisor" "heartbeat_respawned" "{\"pid\":$_HEARTBEAT_PID}" 2>/dev/null || true
    fi

    # Step 0: Check for wedged child (watchdog)
    _watchdog_check_child

    # Step 1: Reconcile manifest with filesystem (unconditional, every tick)
    _op_reconcile

    # Step 2: Check dirty-pressure thresholds (may enqueue bakes)
    _check_dirty_pressure

    # Step 3: Check schedule trigger (may enqueue bakes)
    _check_schedule_trigger

    # Step 3a: WP #748 Phase 1 (E) — consume wants-bake flags from workspace closes
    _check_wants_bake_flags

    # Step 3b: Phase 5 — homes-only policy-driven consolidate enqueue (replaces the
    # old count>=5 PHP trigger). Enqueues a consolidate when storagectl recommends it.
    _check_consolidate_policy

    # Step 3c: WP #1262 (#6) — force-reclaim escalation. When a home is busy AND
    # consolidation is recommended (so the Step-3b consolidate keeps deferring on
    # the live session), arm a user-warned countdown; at the deadline force-close
    # the home's sessions so it reaches idle and the deferred consolidate runs.
    _check_force_reclaim_escalation

    # Step 3c2: OP#1381 — overlay-free early-resume. Pull any parked USER
    # consolidate/bake retry forward to now if its entity overlay has gone idle,
    # so the _check_job_retries below matures it THIS tick (the moment the
    # workspace closed) instead of waiting out the backoff. The backoff timer in
    # _check_job_retries remains the safety net for a still-busy entity.
    _check_deferred_consolidate_resume

    # Step 3d: S-08 (#1353) — mature parked job retries (deferred mounts with
    # backoff) back into the queue.
    _check_job_retries

    # Step 4: Pop and process one queue item
    local qdepth
    qdepth="$(queue_depth 2>/dev/null || echo 0)"

    local next_req
    next_req="$(queue_pop_next 2>/dev/null || true)"

    if [ -n "$next_req" ] && [ -f "$next_req" ]; then
        local req_type req_id req_op req_reason req_trace req_job req_prio
        req_type="$(queue_read_field "$next_req" "type" 2>/dev/null || true)"
        req_id="$(queue_read_field "$next_req" "id" 2>/dev/null || true)"
        req_op="$(queue_read_field "$next_req" "op" 2>/dev/null || true)"
        req_reason="$(queue_read_field "$next_req" "reason" 2>/dev/null || true)"
        # S-08: additive "job" key — ledger transitions only when present + valid.
        req_job="$(queue_read_field "$next_req" "job" 2>/dev/null || true)"
        if declare -f job_id_valid >/dev/null 2>&1; then
            job_id_valid "$req_job" || req_job=""
        else
            req_job=""
        fi
        # Original priority (2-digit filename prefix) — preserved on requeue.
        req_prio="$(basename "$next_req" 2>/dev/null | cut -c1-2)"
        case "$req_prio" in ''|*[!0-9]*) req_prio=5 ;; esac
        # R-06 (#1370): adopt the enqueuer's trace id (additive "trace" key in the
        # entry JSON); generate a per-op 8-hex id when absent so supervisor-
        # originated ops (schedule / dirty-pressure) are joinable too.
        req_trace="$(queue_read_field "$next_req" "trace" 2>/dev/null || true)"
        case "$req_trace" in *[!a-z0-9]*) req_trace="" ;; esac
        if [ -z "$req_trace" ] || [ "${#req_trace}" -lt 4 ] || [ "${#req_trace}" -gt 16 ]; then
            req_trace="$(tr -dc 'a-f0-9' < /dev/urandom 2>/dev/null | head -c 8)"
            [ -n "$req_trace" ] || req_trace="$(printf 'op%06x' $(( $(date +%s) % 16777216 )))"
        fi

        # Delete the queue file before processing (prevents double-processing on crash)
        rm -f "$next_req" 2>/dev/null || true

        if [ -n "$req_type" ] && [ -n "$req_id" ] && [ -n "$req_op" ]; then
            local entity="${req_type}/${req_id}"

            # Check for entity halt before processing (skip if halted, unless user-clicked)
            local is_user_click=0
            [ "$req_reason" = "user_consolidate" ] && is_user_click=1
            [ "$req_reason" = "user_persist" ] && is_user_click=1

            if _halt_exists "$entity" 2>/dev/null && [ "$is_user_click" -eq 0 ]; then
                log_warn "Skipping $req_op for $entity (entity is halted)"
                lifecycle_log "warn" "supervisor" "op_skipped_halted" \
                    "{\"entity\":\"$entity\",\"op\":\"$req_op\"}" 2>/dev/null || true
                # S-08: a tracked job skipped on halt is terminal-failed (the halt
                # itself is the diagnosis; the ledger records why nothing ran).
                if [ -n "$req_job" ]; then
                    _OP_EXIT=1; _OP_DEFER_REASON=""
                    _job_finalize "$req_job" "$req_op" "$req_type" "$req_id" "halted" "$req_trace" "$req_prio"
                fi
            else
                # User-clicked consolidate resets failure counter
                if [ "$req_reason" = "user_consolidate" ]; then
                    _consolidate_fail_reset "$entity"
                    rm -f "$(_halt_path "$entity" "consolidate-disabled")" 2>/dev/null || true
                fi

                # Determine compression for this op
                local compression="xz"
                if [ "$req_reason" = "dirty_pressure_soft" ] || \
                   [ "$req_reason" = "dirty_pressure_hard" ] || \
                   [ "$req_reason" = "dirty_pressure_critical" ]; then
                    compression="$EMERGENCY_BAKE_COMP"
                fi

                # S-08: tracked job → ledger goes running before the op spawns.
                if [ -n "$req_job" ]; then
                    _job_mark_running "$req_job" "$req_op" "$req_type" "$req_id" "$req_reason" "$req_trace"
                fi
                _OP_EXIT=""
                _OP_DEFER_REASON=""

                # R-06: exported for the spawned storagectl child (subshell env is
                # inherited through the exec); cleared right after the op so ids
                # never bleed into the next tick's op or the reconcile pass.
                export AICLI_TRACE_ID="$req_trace"
                case "$req_op" in
                    bake)
                        _op_bake "$req_type" "$req_id" "$req_reason" "$compression"
                        ;;
                    consolidate)
                        _CURRENT_JOB_ID="$req_job"
                        _op_consolidate "$req_type" "$req_id" "$req_reason"
                        _CURRENT_JOB_ID=""
                        ;;
                    mount)
                        _op_mount "$req_type" "$req_id" "$req_reason"
                        ;;
                    graduate)
                        _op_graduate "$req_type" "$req_id" "$req_reason"
                        ;;
                    *)
                        log_warn "Unknown op: $req_op (ignored)"
                        _OP_EXIT=64
                        ;;
                esac
                unset AICLI_TRACE_ID

                # S-08: terminal/retry ledger transition (records the exit verbatim).
                if [ -n "$req_job" ]; then
                    _job_finalize "$req_job" "$req_op" "$req_type" "$req_id" "$req_reason" "$req_trace" "$req_prio"
                fi
            fi
        fi
    fi

    # Write idle work state
    qdepth="$(queue_depth 2>/dev/null || echo 0)"
    local last_c="null"
    [ "$_LAST_COMPLETED_AT" != "null" ] && [ -n "$_LAST_COMPLETED_AT" ] && last_c="$_LAST_COMPLETED_AT"

    local idle_json
    idle_json="$(_idle_work_json "$qdepth" "$last_c")"
    _atomic_json_write "$WORKFILE" "$idle_json" || true

    local status_json
    status_json="$(_status_json idle null null "$qdepth" "$last_c")"
    _atomic_json_write "$STATUSFILE" "$status_json" || true
}

# ---------------------------------------------------------------------------
# start — acquire lock, run heartbeat + work loop
# ---------------------------------------------------------------------------
_do_start() {
    mkdir -p "$STATUS_DIR" 2>/dev/null || true
    mkdir -p "$SUPERVISOR_DIR" 2>/dev/null || true
    mkdir -p "$HALTS_DIR" 2>/dev/null || true
    mkdir -p "$CONSOLIDATE_FAILS_DIR" 2>/dev/null || true
    mkdir -p "$QUEUE_DIR" 2>/dev/null || true
    mkdir -p "$JOBS_DIR" 2>/dev/null || true
    mkdir -p "$JOB_RETRY_DIR" 2>/dev/null || true

    # ---- Single-instance mutex (Bug #757) ---------------------------------
    # The dedicated lock file ($LOCKFILE) is the ONLY mutex. flock-on-an-fd is
    # auto-released by the kernel when this process AND every fd-inheriting
    # descendant exits — that is why the heartbeat subshell and the spawned
    # commit_stack.sh/consolidate_layers.sh work children close $SUP_LOCK_FD
    # (see _run_heartbeat / _op_bake / _op_consolidate). A previous attempt that
    # forgot that deadlocked the stop->start cycle (v2026.05.12.04, reverted).
    #
    # Fast path: if the pidfile already names a live supervisor, skip even
    # opening the lock (no pointless 10 s flock -w wait when a backstop fires
    # while a supervisor is obviously up). A live supervisor ALWAYS holds the
    # lock, so once past the flock below, any pidfile we find is from a DEAD
    # predecessor.
    if _pidfile_valid 2>/dev/null; then
        local existing_pid
        existing_pid="$(_read_pidfile)"
        log_warn "Another supervisor instance is running (pid $existing_pid). Exiting."
        lifecycle_log "info" "supervisor" "supervisor_lock_held" "{\"existing_pid\":$existing_pid}" 2>/dev/null || true
        exit 0
    fi

    exec {SUP_LOCK_FD}>"$LOCKFILE" 2>/dev/null || {
        log_error "Cannot open lock file $LOCKFILE — exiting (degraded)."
        exit 1
    }
    # flock -w 10 (blocking, 10 s) not -n: if the holder is a live supervisor
    # that is staying up, we give up after 10 s and exit 0 (a harmless,
    # backgrounded loser). If the holder is being stopped (cleanup.sh stop, then
    # a racing PLG-INLINE start), it releases within a few seconds and we take
    # over — this is what makes the upgrade hand-off and stop->start race robust.
    if ! flock -w 10 "$SUP_LOCK_FD" 2>/dev/null; then
        log_warn "Another supervisor holds the lock (waited 10 s). Exiting cleanly."
        lifecycle_log "info" "supervisor" "supervisor_lock_held" "{\"reason\":\"flock_busy\"}" 2>/dev/null || true
        exit 0
    fi
    # --- We are THE supervisor from here. $SUP_LOCK_FD stays open for life. --

    # Stale pidfile: if present it is from a DEAD supervisor (a live one would
    # hold the lock above, so we would have exited). Just take ownership.
    if [ -f "$PIDFILE" ]; then
        local stale_pid
        stale_pid="$(cat "$PIDFILE" 2>/dev/null || echo 0)"
        log_warn "Stale pidfile (pid $stale_pid). Taking ownership."
        lifecycle_log "info" "supervisor" "supervisor_pidfile_stale" "{\"stale_pid\":$stale_pid}" 2>/dev/null || true
    fi
    _write_pidfile || log_warn "Initial pidfile write failed — _ensure_pidfile will retry each tick."

    trap '_on_term' TERM INT
    trap '_on_wake' USR1

    # ---- Reap orphaned children of a crashed predecessor (Bug #513/#578/#757)
    # A LIVE full supervisor cannot coexist (it would hold the lock above, so we
    # would be the loser and have exited). The only things to clean up are
    # ORPHANS of a *crashed* predecessor: its heartbeat subshell (its cmdline
    # still shows "...aicli-supervisor.sh start") and any in-flight
    # storagectl bake/consolidate work child (Phase 5: the supervisor now execs
    # storagectl directly, so the work-child cmdline is storagectl.sh, not the old
    # commit_stack.sh / consolidate_layers.sh). We identify orphans by
    # PPid==1 (reparented to init) — never touch a process with a live parent.
    # Path-anchored cmdline match only (VM-safety guard: never a bare agent-name
    # pgrep that could match a qemu cmdline). This REPLACES the old "kill every
    # ...aicli-supervisor.sh start that is not me" reaper, whose dueling-reaper
    # failure class (two starters SIGKILLing each other) is now impossible. Benign
    # side-effect: a sibling `start` currently blocked in `flock -w 10` is also
    # PPid==1 and may be reaped here instead of timing out — fine, it was going to
    # exit as a loser anyway.
    local _self_script="/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/supervisor/aicli-supervisor.sh"
    _find_orphan_children() {
        { pgrep -f "$_self_script start" 2>/dev/null
          pgrep -f "/src/scripts/storage/storagectl.sh bake " 2>/dev/null
          pgrep -f "/src/scripts/storage/storagectl.sh consolidate " 2>/dev/null
          pgrep -f "/src/scripts/storage/storagectl.sh mount " 2>/dev/null
        } | sort -un | while read -r _pid; do
            [ -n "$_pid" ] || continue
            [ "$_pid" = "$$" ] && continue
            local _ppid _state
            _ppid="$(awk '/^PPid:/{print $2; exit}' "/proc/$_pid/status" 2>/dev/null || echo '')"
            [ "$_ppid" = "1" ] || continue          # only TRUE orphans
            _state="$(awk '/^State:/{print $2; exit}' "/proc/$_pid/status" 2>/dev/null || echo '')"
            [ "$_state" = "Z" ] && continue
            echo "$_pid"
        done
    }
    local _orphans
    _orphans="$(_find_orphan_children)"
    if [ -n "$_orphans" ]; then
        log_warn "Orphan supervisor child process(es) detected: $_orphans. Reaping before start."
        lifecycle_log "warn" "supervisor" "supervisor_orphan_detected" \
            "{\"orphan_pids\":\"$(echo "$_orphans" | tr '\n' ' ')\"}" 2>/dev/null || true
        echo "$_orphans" | xargs -r kill -15 2>/dev/null || true
        local _waited=0
        while [ "$_waited" -lt 5 ]; do
            [ -z "$(_find_orphan_children)" ] && break
            sleep 1
            _waited=$((_waited + 1))
        done
        local _stubborn
        _stubborn="$(_find_orphan_children)"
        if [ -n "$_stubborn" ]; then
            echo "$_stubborn" | xargs -r kill -9 2>/dev/null || true
            sleep 1
        fi
        local _final
        _final="$(_find_orphan_children)"
        if [ -n "$_final" ]; then
            log_warn "Stubborn orphan child(ren) survived SIGKILL: $_final. Continuing as registered owner."
            lifecycle_log "warn" "supervisor" "supervisor_orphan_unkillable" \
                "{\"stubborn_pids\":\"$(echo "$_final" | tr '\n' ' ')\"}" 2>/dev/null || true
        fi
    fi

    # Load config overrides if available
    local cfg_file="/boot/config/plugins/unraid-aicliagents/unraid-aicliagents.cfg"
    if [ -f "$cfg_file" ]; then
        # Read specific keys safely
        local _v
        _v=$(grep -oP '^supervisor_tick_seconds="?\K[^"]*(?="?$)' "$cfg_file" 2>/dev/null | head -1)
        [ -n "$_v" ] && SUPERVISOR_TICK="$_v"
        _v=$(grep -oP '^bake_schedule_minutes="?\K[^"]*(?="?$)' "$cfg_file" 2>/dev/null | head -1)
        [ -n "$_v" ] && BAKE_SCHEDULE_MINUTES="$_v"
        _v=$(grep -oP '^dirty_threshold_soft_mb="?\K[^"]*(?="?$)' "$cfg_file" 2>/dev/null | head -1)
        [ -n "$_v" ] && DIRTY_SOFT_MB="$_v"
        _v=$(grep -oP '^dirty_threshold_hard_mb="?\K[^"]*(?="?$)' "$cfg_file" 2>/dev/null | head -1)
        [ -n "$_v" ] && DIRTY_HARD_MB="$_v"
        _v=$(grep -oP '^dirty_threshold_critical_mb="?\K[^"]*(?="?$)' "$cfg_file" 2>/dev/null | head -1)
        [ -n "$_v" ] && DIRTY_CRITICAL_MB="$_v"
        _v=$(grep -oP '^emergency_bake_compression="?\K[^"]*(?="?$)' "$cfg_file" 2>/dev/null | head -1)
        [ -n "$_v" ] && EMERGENCY_BAKE_COMP="$_v"
        _v=$(grep -oP '^storage_target_wait_s="?\K[^"]*(?="?$)' "$cfg_file" 2>/dev/null | head -1)
        [ -n "$_v" ] && STORAGE_TARGET_WAIT_S="$_v"
        _v=$(grep -oP '^graduated_retention_days="?\K[^"]*(?="?$)' "$cfg_file" 2>/dev/null | head -1)
        [ -n "$_v" ] && GRADUATED_RETENTION_DAYS="$_v"
    fi

    log_info "Supervisor starting (pid $$, version $DAEMON_VERSION)"
    lifecycle_log "info" "supervisor" "supervisor_started" "{\"pid\":$$,\"version\":\"$DAEMON_VERSION\"}" 2>/dev/null || true

    # Write initial status and work state
    local status_json
    status_json="$(_status_json idle null null 0 null)"
    _atomic_json_write "$STATUSFILE" "$status_json" || true

    local idle_json
    idle_json="$(_idle_work_json 0 null)"
    _atomic_json_write "$WORKFILE" "$idle_json" || true

    touch "$TICKFILE" 2>/dev/null || true

    # Start heartbeat in background
    _run_heartbeat &
    _HEARTBEAT_PID="$!"

    local tick_sec="$SUPERVISOR_TICK"

    # Main work loop
    while [ "$_STOPPING" -eq 0 ]; do
        _work_tick

        # Reset the wake flag for THIS sleep window; a SIGUSR1 arriving during a
        # `sleep 1` runs the trap when that second elapses, sets _WAKE, and the
        # condition breaks — so a workspace close resumes a deferred consolidate
        # within ≤1 s instead of waiting out the full tick.
        _WAKE=0
        local slept=0
        while [ "$slept" -lt "$tick_sec" ] && [ "$_STOPPING" -eq 0 ] && [ "$_WAKE" -eq 0 ]; do
            sleep 1
            slept=$((slept + 1))
        done
    done

    # --- Shutdown sequence ---
    log_info "Supervisor stopping (pid $$)"
    lifecycle_log "info" "supervisor" "supervisor_stopping" "{\"pid\":$$}" 2>/dev/null || true

    # Stop heartbeat
    if [ -n "$_HEARTBEAT_PID" ] && _pid_alive "$_HEARTBEAT_PID" 2>/dev/null; then
        kill "$_HEARTBEAT_PID" 2>/dev/null || true
        wait "$_HEARTBEAT_PID" 2>/dev/null || true
    fi

    # Kill any running child
    if [ -n "$_CHILD_PID" ] && _pid_alive "$_CHILD_PID" 2>/dev/null; then
        log_warn "Stopping child pid $_CHILD_PID (op=$_CHILD_OP)"
        kill -TERM "$_CHILD_PID" 2>/dev/null || true
        wait "$_CHILD_PID" 2>/dev/null || true
    fi

    local stopping_json
    stopping_json='{"state":"stopping","op":null,"op_kind":null,"entity":null,"op_started_at":null,"op_max_duration_s":null,"child_pid":null,"queue_depth":0,"last_completed_at":null,"errors":[]}'
    _atomic_json_write "$WORKFILE" "$stopping_json" || true

    local stop_status
    stop_status="$(_status_json stopping null null 0 null)"
    _atomic_json_write "$STATUSFILE" "$stop_status" || true

    lifecycle_log "info" "supervisor" "supervisor_stopped" "{\"pid\":$$}" 2>/dev/null || true

    rm -f "$PIDFILE" 2>/dev/null || true
    # $SUP_LOCK_FD (the single-instance lock) is released automatically when this
    # process exits — closing it explicitly here just makes intent clear.
    [ -n "${SUP_LOCK_FD:-}" ] && exec {SUP_LOCK_FD}>&- 2>/dev/null

    log_info "Supervisor stopped cleanly."
    exit 0
}

# ---------------------------------------------------------------------------
# stop — send TERM to running instance; wait; KILL if still alive
# ---------------------------------------------------------------------------
_do_stop() {
    local timeout_sec="${1:-10}"

    if ! _pidfile_valid 2>/dev/null; then
        log_info "No running supervisor found (pidfile absent or stale)."
        rm -f "$PIDFILE" 2>/dev/null || true
        exit 0
    fi

    local pid
    pid="$(_read_pidfile)"
    log_info "Sending TERM to supervisor pid $pid..."
    kill -TERM "$pid" 2>/dev/null || true

    local waited=0
    while [ "$waited" -lt "$timeout_sec" ]; do
        _pid_alive "$pid" || break
        sleep 1
        waited=$((waited + 1))
    done

    if _pid_alive "$pid" 2>/dev/null; then
        log_warn "Supervisor pid $pid did not exit within ${timeout_sec}s — sending KILL."
        kill -KILL "$pid" 2>/dev/null || true
        sleep 1
    fi

    rm -f "$PIDFILE" 2>/dev/null || true
    log_info "Supervisor stopped."
    exit 0
}

# ---------------------------------------------------------------------------
# status — print JSON to stdout; exit 0 if running, 1 if not
# ---------------------------------------------------------------------------
_do_status() {
    local is_running=false
    local pid="null"

    if _pidfile_valid 2>/dev/null; then
        is_running=true
        pid="$(_read_pidfile)"
    fi

    local status_content="{}"
    if [ -f "$STATUSFILE" ]; then
        status_content="$(cat "$STATUSFILE" 2>/dev/null || echo '{}')"
    fi

    local out
    out="$(printf '%s' "$status_content" | sed 's/}$//')"
    printf '%s,"is_running":%s,"pid":%s}\n' "$out" "$is_running" "$pid"

    if [ "$is_running" = "true" ]; then
        exit 0
    else
        exit 1
    fi
}

# ---------------------------------------------------------------------------
# flush — synchronously drain all dirty entities with a time budget.
#
# Usage: aicli-supervisor.sh flush --all [--timeout=N]
#
# For each dirty entity (non-empty ZRAM upper dir), enqueues a priority-0
# bake, then polls supervisor.status.json until state=idle AND queue_depth=0
# or the timeout is reached.  Writes a lifecycle log line on outcome.
# Exits 0 on clean flush, 1 on timeout.
# ---------------------------------------------------------------------------
_do_flush() {
    local timeout_sec=60
    local arg
    for arg in "$@"; do
        case "$arg" in
            --timeout=*) timeout_sec="${arg#--timeout=}" ;;
        esac
    done

    log_info "Flush requested (timeout=${timeout_sec}s)"
    lifecycle_log "info" "supervisor" "flush_start" "{\"timeout_s\":$timeout_sec}" 2>/dev/null || true

    # Enumerate dirty entities and enqueue priority-0 bakes
    local zram_base="${ZRAM_BASE:-/tmp/unraid-aicliagents/zram_upper}"
    local flushed_count=0

    for upper_root in "$zram_base/homes" "$zram_base/agents"; do
        [ -d "$upper_root" ] || continue
        local entity_type="home"
        [ "$(basename "$upper_root")" = "agents" ] && entity_type="agent"

        for entity_dir in "$upper_root"/*/; do
            [ -d "$entity_dir" ] || continue
            local entity_id
            entity_id=$(basename "$entity_dir")
            local upper_dir="${entity_dir}upper"
            [ -d "$upper_dir" ] || continue
            [ -n "$(ls -A "$upper_dir" 2>/dev/null)" ] || continue

            queue_enqueue 0 "$entity_type" "$entity_id" "bake" "clean_shutdown" 2>/dev/null || true
            flushed_count=$(( flushed_count + 1 ))
            log_info "Flush: enqueued bake for ${entity_type}/${entity_id}"
        done
    done

    if [ "$flushed_count" -eq 0 ]; then
        lifecycle_log "info" "supervisor" "flush_complete" "{\"result\":\"nothing_dirty\",\"timeout_s\":$timeout_sec}" 2>/dev/null || true
        log_info "Flush: no dirty entities found. Done."
        exit 0
    fi

    # Poll until idle + queue_depth=0 or timeout
    local deadline
    deadline=$(( $(date +%s) + timeout_sec ))
    local clean=0

    while [ "$(date +%s)" -lt "$deadline" ]; do
        local status_state=""
        local status_qdepth=1

        if [ -f "$STATUSFILE" ]; then
            status_state=$(grep -oP '"state"\s*:\s*"\K[^"]*' "$STATUSFILE" 2>/dev/null | head -1 || true)
            status_qdepth=$(grep -oP '"queue_depth"\s*:\s*\K[0-9]+' "$STATUSFILE" 2>/dev/null | head -1 || echo 1)
        fi

        if [ "$status_state" = "idle" ] && [ "${status_qdepth:-1}" -eq 0 ]; then
            clean=1
            break
        fi

        sleep 2
    done

    if [ "$clean" -eq 1 ]; then
        lifecycle_log "info" "supervisor" "flush_complete" "{\"result\":\"clean_shutdown_reached\",\"timeout_s\":$timeout_sec}" 2>/dev/null || true
        log_info "Flush: clean shutdown reached."
        exit 0
    else
        lifecycle_log "warn" "supervisor" "flush_complete" "{\"result\":\"shutdown_timeout_reached\",\"timeout_s\":$timeout_sec}" 2>/dev/null || true
        log_warn "Flush: timeout reached (${timeout_sec}s). Some entities may not be fully baked."
        exit 1
    fi
}

# ---------------------------------------------------------------------------
# cleanup-phantoms — one-shot prune of smoke-test entity IDs from manifest.
#
# Usage: aicli-supervisor.sh cleanup-phantoms
#
# Scans the layer manifest for entities whose ID matches the smoke-test naming
# pattern (smoke[a-z]*[0-9]* in either type or id component, e.g. home/smokeuser,
# home/smokeuser123456, agent/smokepressure52) AND whose expected_layers all
# reference a future timestamp (9999999999) or no on-disk file exists at the
# persist path. Removes those entities from the manifest and writes a lifecycle
# entry. Safe to run while the supervisor daemon is not running; uses the same
# flock path as the daemon so concurrent runs are serialised.
# ---------------------------------------------------------------------------
_do_cleanup_phantoms() {
    local mpath
    mpath="$(manifest_path 2>/dev/null || echo '/boot/config/plugins/unraid-aicliagents/layer_manifest.json')"

    if [ ! -f "$mpath" ]; then
        log_info "cleanup-phantoms: manifest not found at $mpath — nothing to do"
        return 0
    fi

    if ! command -v php >/dev/null 2>&1; then
        log_warn "cleanup-phantoms: php not available — skipping"
        return 0
    fi

    local plugin_dir="/usr/local/emhttp/plugins/unraid-aicliagents"
    local removed
    removed=$(php -d display_errors=0 -r "
        \$_SERVER['DOCUMENT_ROOT'] = '/usr/local/emhttp';
        require_once '$plugin_dir/src/includes/AICliAgentsManager.php';
        // Smoke entity pattern: id portion matches ^smoke[a-z]*[0-9]*\$
        // Covers: smokeuser, smokeuser123456, smokequeue48, smokepressure52, etc.
        \$pattern = '/(?:^|\\/)(smoke[a-z]*[0-9]*)\$/';
        \$n = \AICliAgents\Services\LayerManifestService::removeEntitiesMatching(\$pattern);
        echo \$n;
    " 2>/dev/null || echo "error")

    if [ "$removed" = "error" ]; then
        log_error "cleanup-phantoms: PHP prune failed"
        return 1
    fi

    log_info "cleanup-phantoms: removed $removed phantom smoke-test entities from manifest"
    lifecycle_log "info" "supervisor" "cleanup_phantoms_done" "{\"removed\":${removed:-0}}" 2>/dev/null || true

    # Also remove any halt markers for smoke entities from tmpfs
    if [ -d "$HALTS_DIR" ]; then
        find "$HALTS_DIR" -name 'smoke*' -type f -delete 2>/dev/null || true
        find "$HALTS_DIR" -path '*/smoke*' -type f -delete 2>/dev/null || true
    fi

    # Sweep work + zram_upper trees for smoke-namespaced entries (OP #428).
    # StorageMetricsService::getStorageStats falls back to scanning these dirs
    # for OFFLINE-card rendering, so leftover smoke fixtures appear as ghost
    # users in the Storage tab UI. Match smoke[a-z]*[0-9]+ ONLY — never touch
    # anything that could be a real user.
    local work_base="/tmp/unraid-aicliagents/work"
    local zram_homes="/tmp/unraid-aicliagents/zram_upper/homes"
    local zram_agents="/tmp/unraid-aicliagents/zram_upper/agents"
    local fs_pruned=0
    for base in "$work_base" "$zram_homes" "$zram_agents"; do
        [ -d "$base" ] || continue
        for entry in "$base"/smoke*; do
            [ -e "$entry" ] || continue
            local name
            name="$(basename "$entry")"
            # Strict: must match ^smoke[a-z]*[0-9]+$ (numeric tail required so
            # we never sweep a literal "smoketest" used by a real user).
            case "$name" in
                smoke*[!0-9]*[0-9]) ;;
                smoke[a-z]*[0-9]*) ;;
                *) continue ;;
            esac
            # Final guard: the name must contain a digit
            case "$name" in
                *[0-9]*) ;;
                *) continue ;;
            esac
            # Unmount if mounted, then remove
            if mountpoint -q "$entry/home" 2>/dev/null; then
                umount -l "$entry/home" 2>/dev/null || true
            fi
            rm -rf "$entry" 2>/dev/null && fs_pruned=$((fs_pruned + 1))
        done
    done
    if [ "$fs_pruned" -gt 0 ]; then
        log_info "cleanup-phantoms: pruned $fs_pruned smoke fixture dirs from work/zram trees"
        lifecycle_log "info" "supervisor" "cleanup_phantoms_fs_pruned" \
            "{\"removed\":${fs_pruned}}" 2>/dev/null || true
    fi

    log_info "cleanup-phantoms: done"
    return 0
}

# ---------------------------------------------------------------------------
# Entry point — dispatch on first argument
# ---------------------------------------------------------------------------
# Source guard: only dispatch when EXECUTED (`bash aicli-supervisor.sh <cmd>`),
# not when SOURCED. Sourcing the script (BASH_SOURCE[0] != $0) loads every
# function for unit testing WITHOUT starting the daemon. Production always
# execs it (SupervisorService.php, event/ scripts) so $0 == BASH_SOURCE[0]
# and the dispatch runs exactly as before.
if [ "${BASH_SOURCE[0]}" = "${0}" ]; then
    CMD="${1:-start}"

    case "$CMD" in
        start|"")
            _do_cleanup_phantoms
            _do_start
            ;;
        stop)
            _do_stop "${2:-10}"
            ;;
        flush)
            shift
            _do_flush "$@"
            ;;
        status|--status)
            _do_status
            ;;
        cleanup-phantoms)
            _do_cleanup_phantoms
            exit $?
            ;;
        *)
            log_error "Unknown command: $CMD. Use: start | stop | flush | status | cleanup-phantoms"
            exit 1
            ;;
    esac
fi
