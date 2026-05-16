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
CONSOLIDATE_THRESHOLD_FLASH="${consolidate_layer_threshold_flash:-30}"
CONSOLIDATE_THRESHOLD_ARRAY="${consolidate_layer_threshold_array:-5}"

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

# _manifest_get_entities
# Echoes a newline-separated list of entity keys from the manifest.
_manifest_get_entities() {
    local mpath
    mpath="$(manifest_path 2>/dev/null || echo '/boot/config/plugins/unraid-aicliagents/layer_manifest.json')"
    [ -f "$mpath" ] || return 0
    # Extract entity keys from JSON: "home/root": { ... }
    grep -oP '(?<="entities"\s{0,4}:\s{0,4}\{)[^}]*' "$mpath" 2>/dev/null | \
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
    grep -oP "\"filename\":\s*\"\K[^\"]*" "$mpath" 2>/dev/null | head -100 || true
}

# _manifest_entity_persist_path <entity>
_manifest_entity_persist_path() {
    local entity="${1:-}"
    local mpath
    mpath="$(manifest_path 2>/dev/null || echo '/boot/config/plugins/unraid-aicliagents/layer_manifest.json')"
    [ -f "$mpath" ] || return 0
    grep -A5 "\"${entity}\"" "$mpath" 2>/dev/null | \
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
    grep -A3 "\"filename\":\s*\"${filename}\"" "$mpath" 2>/dev/null | \
        grep -oP '"sha256":\s*"\K[^"]*' | head -1 || true
}

# _manifest_last_known_good <entity>
_manifest_last_known_good() {
    local entity="${1:-}"
    local mpath
    mpath="$(manifest_path 2>/dev/null || echo '/boot/config/plugins/unraid-aicliagents/layer_manifest.json')"
    [ -f "$mpath" ] || return 0
    grep -A10 "\"${entity}\"" "$mpath" 2>/dev/null | \
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
_HEARTBEAT_PID=""
_CHILD_PID=""
_CHILD_OP=""
_CHILD_ENTITY=""
_CHILD_STARTED_AT=0
_CHILD_MAX_DURATION=0
_LAST_COMPLETED_AT="null"

_on_term() {
    _STOPPING=1
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

        # Check manifest path against current config (path drift)
        local manifest_stored_path
        manifest_stored_path=$(_manifest_entity_persist_path "$entity")
        if [ -n "$manifest_stored_path" ] && [ "$manifest_stored_path" != "$persist_path" ]; then
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

        # Check for files in manifest but not on disk (missing layers)
        local has_missing=0
        for exp_file in "${expected_files[@]:-}"; do
            [ -n "$exp_file" ] || continue
            local found=0
            for act_file in "${actual_files[@]:-}"; do
                [ "$act_file" = "$exp_file" ] && found=1 && break
            done
            if [ "$found" -eq 0 ]; then
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
                    local now_ts
                    now_ts=$(date -u '+%Y-%m-%dT%H:%M:%SZ' 2>/dev/null || date '+%Y-%m-%dT%H:%M:%SZ')

                    # Register via PHP
                    if command -v php >/dev/null 2>&1; then
                        php -d display_errors=0 -r "
                            \$_SERVER['DOCUMENT_ROOT']='/usr/local/emhttp';
                            require_once '/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/AICliAgentsManager.php';
                            \AICliAgents\Services\LayerManifestService::addLayer('$entity', [
                                'filename'   => '$act_file',
                                'sha256'     => '$sha256',
                                'bytes'      => $file_bytes,
                                'kind'       => 'recovered',
                                'created_at' => '$now_ts',
                                'recovered_at' => '$now_ts',
                            ]);
                        " 2>/dev/null || true
                    fi
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
    # replaces the subshell with commit_stack.sh, so $! and the watchdog's
    # kill -0/-TERM/-KILL still target the right PID.
    (
        [ -n "${SUP_LOCK_FD:-}" ] && exec {SUP_LOCK_FD}>&- 2>/dev/null
        export MKSQUASHFS_ARGS="-comp $compression"
        exec bash "${STORAGE_DIR}/commit_stack.sh" "$type" "$id" "$persist_path" >/dev/null 2>&1
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
        log_error "Bake failed: $entity (exit=$exit_code)"
        lifecycle_log "error" "supervisor" "bake_failed" \
            "{\"entity\":\"$entity\",\"exit_code\":$exit_code}" 2>/dev/null || true
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

    # Spawn child — subshell drops the inherited single-instance lock fd FIRST
    # (Bug #757); see _op_bake. A consolidate can run for many minutes; an
    # orphaned one must not block a fresh supervisor.
    (
        [ -n "${SUP_LOCK_FD:-}" ] && exec {SUP_LOCK_FD}>&- 2>/dev/null
        exec bash "${STORAGE_DIR}/consolidate_layers.sh" "$type" "$id" "$persist_path" >/dev/null 2>&1
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
    else
        # Increment failure counter
        local fail_count
        fail_count=$(_consolidate_fail_increment "$entity")
        log_error "Consolidate failed: $entity (exit=$exit_code, fail_count=$fail_count)"
        lifecycle_log "error" "supervisor" "consolidate_failed" \
            "{\"entity\":\"$entity\",\"exit_code\":$exit_code,\"fail_count\":$fail_count}" 2>/dev/null || true

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
# Work loop — one iteration, called every tick
# ---------------------------------------------------------------------------
_work_tick() {
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

    # Step 4: Pop and process one queue item
    local qdepth
    qdepth="$(queue_depth 2>/dev/null || echo 0)"

    local next_req
    next_req="$(queue_pop_next 2>/dev/null || true)"

    if [ -n "$next_req" ] && [ -f "$next_req" ]; then
        local req_type req_id req_op req_reason
        req_type="$(queue_read_field "$next_req" "type" 2>/dev/null || true)"
        req_id="$(queue_read_field "$next_req" "id" 2>/dev/null || true)"
        req_op="$(queue_read_field "$next_req" "op" 2>/dev/null || true)"
        req_reason="$(queue_read_field "$next_req" "reason" 2>/dev/null || true)"

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

                case "$req_op" in
                    bake)
                        _op_bake "$req_type" "$req_id" "$req_reason" "$compression"
                        ;;
                    consolidate)
                        _op_consolidate "$req_type" "$req_id" "$req_reason"
                        ;;
                    *)
                        log_warn "Unknown op: $req_op (ignored)"
                        ;;
                esac
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
    printf '%d\n' "$$" > "$PIDFILE" 2>/dev/null || true

    trap '_on_term' TERM INT

    # ---- Reap orphaned children of a crashed predecessor (Bug #513/#578/#757)
    # A LIVE full supervisor cannot coexist (it would hold the lock above, so we
    # would be the loser and have exited). The only things to clean up are
    # ORPHANS of a *crashed* predecessor: its heartbeat subshell (its cmdline
    # still shows "...aicli-supervisor.sh start") and any in-flight
    # commit_stack.sh / consolidate_layers.sh work child. We identify orphans by
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
          pgrep -f "/src/scripts/storage/commit_stack.sh " 2>/dev/null
          pgrep -f "/src/scripts/storage/consolidate_layers.sh " 2>/dev/null
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
        _v=$(grep -oP '^consolidate_layer_threshold_flash="?\K[^"]*(?="?$)' "$cfg_file" 2>/dev/null | head -1)
        [ -n "$_v" ] && CONSOLIDATE_THRESHOLD_FLASH="$_v"
        _v=$(grep -oP '^consolidate_layer_threshold_array="?\K[^"]*(?="?$)' "$cfg_file" 2>/dev/null | head -1)
        [ -n "$_v" ] && CONSOLIDATE_THRESHOLD_ARRAY="$_v"
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

        local slept=0
        while [ "$slept" -lt "$tick_sec" ] && [ "$_STOPPING" -eq 0 ]; do
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
