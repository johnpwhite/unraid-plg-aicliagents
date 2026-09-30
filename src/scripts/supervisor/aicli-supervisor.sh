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
GENERATION_SH="/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/installer/generation.sh"

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

# Bug #55: short-lived smoke-only gate used to observe a deliberately dead
# supervisor without racing page-load/health-poll self-healing. Exact content +
# a 120-second TTL ensure a leaked marker fails open automatically.
SUPPRESS_START_FILE="${AICLI_SUPERVISOR_START_SUPPRESS_FILE:-${SUPERVISOR_DIR}/.health-test-suppress-start}"
SUPPRESS_START_MAGIC="aicli-health-smoke-v1"
SUPPRESS_START_MAX_AGE=120

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
# #374 (HOME_STORAGE_LIFECYCLE.md "2026-09-30 — the SQLite stage folder"): an
# automatic bake (schedule, dirty pressure) of an entity whose last bake did
# not save waits before the next try: BAKE_BACKOFF_BASE_S after the first miss,
# doubled after each further miss, at most BAKE_BACKOFF_CAP_S. cfg keys:
# bake_backoff_base_seconds, bake_backoff_cap_seconds.
BAKE_BACKOFF_BASE_S="${bake_backoff_base_seconds:-120}"
BAKE_BACKOFF_CAP_S="${bake_backoff_cap_seconds:-1800}"
# PLUGIN_EVENT_LEDGER_AND_SUBSCRIPTIONS.md (2026-09-11): the event ledger's
# circular-log cap, in EVENTS (not chunks — the ledger writes 1 000-event
# chunks; see EventLedger::CHUNK_SIZE). Reaped by _op_reconcile via
# _events_ledger_sweep. cfg key: event_ledger_max_events.
EVENT_LEDGER_MAX_EVENTS="${event_ledger_max_events:-20000}"
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
    # SIDE_BY_SIDE_AGENT_INSTALLS.md Phase 3: aicli_gc_agent_generations, for the
    # periodic superseded-version sweep. It needs resolve_paths.sh's agent_*
    # helpers, so it is sourced here, after them, and never before.
    # shellcheck disable=SC1090
    [ -f "$GENERATION_SH" ] && source "$GENERATION_SH" 2>/dev/null || true
else
    lifecycle_log() { true; }
    agent_persist_path() { echo "/boot/config/plugins/unraid-aicliagents"; }
    home_persist_path() { echo "/boot/config/plugins/unraid-aicliagents/persistence"; }
    manifest_path() { echo "/boot/config/plugins/unraid-aicliagents/layer_manifest.json"; }
    zram_upper() { echo "/tmp/unraid-aicliagents/zram_upper/${1}s/${2}/upper"; }
    home_live_upper() { zram_upper home "${1:-root}"; }
    home_uppers_live() {
        local d u
        for d in /tmp/unraid-aicliagents/zram_upper/homes/*/upper; do
            [ -d "$d" ] || continue
            u="${d%/upper}"; printf '%s\t%s\n' "${u##*/}" "$d"
        done
    }
fi

# _entity_upper_for_supervisor <type> <id> — #372: the upper a bake of this
# entity will read. A home: the upper of its LIVE mount (zram or disk; the
# policy mode is only for a new mount). An agent: its zram upper, as before.
_entity_upper_for_supervisor() {
    if [ "${1:-}" = "home" ]; then
        home_live_upper "${2:-}"
    else
        zram_upper "${1:-}" "${2:-}"
    fi
}

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

# HOME_BACKUP.md: the home-backup op's step functions (pre-flight re-check,
# close/bake/rsync/verify/manifest/prune/relaunch) live in their own file —
# sourced here (not exec'd) so they share this process's SUPERVISOR_DIR,
# SUP_LOCK_FD, lifecycle_log, and queue_helpers.sh job_ledger_* functions
# without re-deriving any of them.
# shellcheck source=../storage/backup_home.sh
[ -f "$STORAGE_DIR/backup_home.sh" ] && { source "$STORAGE_DIR/backup_home.sh" 2>/dev/null || true; }

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

# _supervisor_start_suppressed — true only for a fresh, exact smoke marker.
# A stale/invalid marker is removed and cannot strand production self-healing.
_supervisor_start_suppressed() {
    [ -f "$SUPPRESS_START_FILE" ] || return 1
    local marker age now mtime
    marker="$(cat "$SUPPRESS_START_FILE" 2>/dev/null || true)"
    now=$(date +%s)
    mtime=$(stat -c '%Y' "$SUPPRESS_START_FILE" 2>/dev/null || echo 0)
    age=$(( now - mtime ))
    if [ "$marker" = "$SUPPRESS_START_MAGIC" ] && [ "$age" -ge 0 ] \
       && [ "$age" -le "$SUPPRESS_START_MAX_AGE" ]; then
        return 0
    fi
    rm -f "$SUPPRESS_START_FILE" 2>/dev/null || true
    return 1
}

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

# Does one immutable manifest snapshot still contain this exact entity?
# Reconcile captures its entity list before taking per-entity locks. A wipe may
# legitimately remove an entity while reconcile waits, so every entity must be
# re-confirmed from a fresh locked snapshot before expected layers are checked.
_manifest_has_entity() {
    local entity="${1:-}" manifest_json="${2:-}"
    [ -n "$entity" ] && [ -n "$manifest_json" ] || return 1
    printf '%s' "$manifest_json" | php -d display_errors=0 -r '
        $m = json_decode(stream_get_contents(STDIN), true);
        exit(is_array($m) && array_key_exists($argv[1], $m["entities"] ?? []) ? 0 : 1);
    ' "$entity" 2>/dev/null
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

# _manifest_entity_persist_path <entity> [manifest_json]
#
# When a snapshot is supplied, read from that exact snapshot. Reconcile takes one
# shared-locked snapshot per pass so the entity list, persistence path, filenames,
# and hashes can never come from different manifest generations.
_manifest_entity_persist_path() {
    local entity="${1:-}"
    local manifest_json="${2:-}"
    if [ -z "$manifest_json" ]; then
        local mpath
        mpath="$(manifest_path 2>/dev/null || echo '/boot/config/plugins/unraid-aicliagents/layer_manifest.json')"
        [ -f "$mpath" ] || return 0
        manifest_json="$(_manifest_read "$mpath")"
    fi
    [ -n "$manifest_json" ] || return 0
    printf '%s' "$manifest_json" | php -d display_errors=0 -r '
        $m = json_decode(stream_get_contents(STDIN), true);
        $path = $m["entities"][$argv[1]]["current_persistence_path"] ?? "";
        if (is_string($path)) echo $path;
    ' "$entity" 2>/dev/null || true
}

# _manifest_entity_layer_records <entity> <manifest_json>
# Emits filename<TAB>sha256 from one entity in one immutable manifest snapshot.
# This replaces the old regex lookup, which independently re-read the live
# manifest for every layer and could pair a disk hash with a different manifest
# generation.
_manifest_entity_layer_records() {
    local entity="${1:-}"
    local manifest_json="${2:-}"
    [ -n "$manifest_json" ] || return 0
    printf '%s' "$manifest_json" | php -d display_errors=0 -r '
        $m = json_decode(stream_get_contents(STDIN), true);
        foreach (($m["entities"][$argv[1]]["expected_layers"] ?? []) as $layer) {
            $filename = $layer["filename"] ?? "";
            $sha256 = $layer["sha256"] ?? "";
            if (is_string($filename) && $filename !== "" && strpos($filename, "\t") === false) {
                echo $filename, "\t", (is_string($sha256) ? $sha256 : ""), PHP_EOL;
            }
        }
    ' "$entity" 2>/dev/null || true
}

# _verify_layer_sha256 <path> <expected_sha256>
# Return 0=verified, 1=confirmed stable mismatch, 2=inconclusive/transient.
#
# A mismatch is destructive evidence: it creates a persistent storage halt. Do
# not accept one read as proof. Pin the file identity (device, inode, size,
# mtime, ctime) around the read, then repeat a mismatching hash. Only two equal
# mismatches against one stable identity are confirmed corruption. This filters
# transient reads and makes a concurrently replaced layer an explicit retry,
# rather than falsely accusing the replacement of corruption.
_verify_layer_sha256() {
    local path="${1:-}"
    local expected="${2:-}"
    _LAYER_VERIFY_ACTUAL=""
    _LAYER_VERIFY_IDENTITY=""

    [ -f "$path" ] && [ -n "$expected" ] || return 2

    local identity_before identity_after identity_final actual_first actual_second
    identity_before=$(stat -Lc '%d:%i:%s:%Y:%Z' "$path" 2>/dev/null) || return 2
    actual_first=$(sha256sum "$path" 2>/dev/null | awk '{print $1}') || return 2
    identity_after=$(stat -Lc '%d:%i:%s:%Y:%Z' "$path" 2>/dev/null) || return 2
    _LAYER_VERIFY_ACTUAL="$actual_first"
    _LAYER_VERIFY_IDENTITY="$identity_after"

    [ -n "$actual_first" ] || return 2
    [ "$identity_before" = "$identity_after" ] || return 2
    [ "$actual_first" = "$expected" ] && return 0

    actual_second=$(sha256sum "$path" 2>/dev/null | awk '{print $1}') || return 2
    identity_final=$(stat -Lc '%d:%i:%s:%Y:%Z' "$path" 2>/dev/null) || return 2
    _LAYER_VERIFY_ACTUAL="$actual_second"
    _LAYER_VERIFY_IDENTITY="$identity_final"

    [ -n "$actual_second" ] || return 2
    [ "$identity_after" = "$identity_final" ] || return 2
    [ "$actual_second" = "$expected" ] && return 2
    [ "$actual_first" = "$actual_second" ] || return 2
    return 1
}

# ---------------------------------------------------------------------------
# Verified-layer cache (#337 follow-up, smoke 49)
# ---------------------------------------------------------------------------
# _op_reconcile runs on EVERY 5 s tick, and it used to re-hash every flash layer
# every time. With 95 layers (7 GB) on .4 that took ~20 s, so the supervisor
# spent most of its life in sha256sum and a queued job waited ~20 s behind it —
# the release gate's 20 s drain window (smoke 49) failed. A layer is immutable
# once written: re-hash it only when its identity (device, inode, size, mtime,
# ctime) or its expected hash changed, or when its last full verification is
# older than LAYER_VERIFY_MAX_AGE (default 24 h, which still catches silent
# media corruption once a day). The cache lives on tmpfs (cleared at boot), so
# a supervisor restart does not re-hash everything either. Only a VERIFIED
# result (rc 0) is ever cached; a mismatch or an inconclusive read is re-checked
# on the next tick exactly as before.
LAYER_VERIFY_CACHE="${AICLI_LAYER_VERIFY_CACHE:-${SUPERVISOR_DIR}/layer-verify.cache}"
LAYER_VERIFY_MAX_AGE="${AICLI_LAYER_VERIFY_MAX_AGE:-86400}"
declare -A _LAYER_VERIFIED=()
_LAYER_VERIFY_CACHE_LOADED=0

_layer_verify_cache_load() {
    [ "$_LAYER_VERIFY_CACHE_LOADED" = "1" ] && return 0
    _LAYER_VERIFY_CACHE_LOADED=1
    [ -f "$LAYER_VERIFY_CACHE" ] || return 0
    local exp ident epoch path
    while IFS=$'\t' read -r exp ident epoch path; do
        [ -n "$path" ] && [ -n "$exp" ] || continue
        _LAYER_VERIFIED["$path"]="$exp"$'\t'"$ident"$'\t'"$epoch"
    done < "$LAYER_VERIFY_CACHE"
}

_layer_verify_cache_save() {
    local tmp="${LAYER_VERIFY_CACHE}.tmp.$$" p
    mkdir -p "$(dirname "$LAYER_VERIFY_CACHE")" 2>/dev/null || true
    {
        for p in "${!_LAYER_VERIFIED[@]}"; do
            printf '%s\t%s\n' "${_LAYER_VERIFIED[$p]}" "$p"
        done
    } > "$tmp" 2>/dev/null && mv -f "$tmp" "$LAYER_VERIFY_CACHE" 2>/dev/null || rm -f "$tmp" 2>/dev/null
    return 0
}

# _verify_layer_cached <path> <expected_sha256> — same return codes as
# _verify_layer_sha256; returns 0 without reading the file when a fresh
# verification of this exact identity and expected hash is cached.
_verify_layer_cached() {
    local path="${1:-}" expected="${2:-}" ident entry now
    _layer_verify_cache_load
    ident=$(stat -Lc '%d:%i:%s:%Y:%Z' "$path" 2>/dev/null) || ident=""
    now=$(date +%s)
    entry="${_LAYER_VERIFIED[$path]:-}"
    if [ -n "$ident" ] && [ -n "$entry" ]; then
        local c_exp c_ident c_epoch
        IFS=$'\t' read -r c_exp c_ident c_epoch <<<"$entry"
        if [ "$c_exp" = "$expected" ] && [ "$c_ident" = "$ident" ] \
           && [ $(( now - ${c_epoch:-0} )) -lt "$LAYER_VERIFY_MAX_AGE" ]; then
            _LAYER_VERIFY_ACTUAL="$expected"
            _LAYER_VERIFY_IDENTITY="$ident"
            return 0
        fi
    fi
    _verify_layer_sha256 "$path" "$expected"
    local rc=$?
    if [ "$rc" -eq 0 ] && [ -n "$_LAYER_VERIFY_IDENTITY" ]; then
        _LAYER_VERIFIED["$path"]="$expected"$'\t'"$_LAYER_VERIFY_IDENTITY"$'\t'"$now"
        _layer_verify_cache_save
    else
        unset '_LAYER_VERIFIED[$path]'
    fi
    return "$rc"
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
# ---------------------------------------------------------------------------
# #175: reap an op child without mistaking a trapped signal for its exit.
# The supervisor traps USR1 ("wake now" — SupervisorService::wake()). A signal
# arriving while an op handler is blocked in `wait` makes wait return
# 128+signum WITH THE CHILD STILL RUNNING. The handlers recorded that (138)
# as the child's exit code — on 2026-09-04 a live home bake was declared
# bake_failed while it completed minutes later, the fail counter moved toward
# auto-disabling consolidation, and the orphaned child ran on concurrently,
# invisible to the watchdog (the handler clears _CHILD_PID after "failure").
# Loop until the child is actually gone, and enforce the op ceiling INLINE via
# _watchdog_check_child — the tick-start watchdog can never fire while the
# loop is blocked here, so this is the only place the ceiling can act.
# Result lands in _WAIT_OP_RC (not stdout: a $() subshell could not `wait`
# for the parent's child).
_WAIT_OP_RC=0
_wait_op_child() {
    local child_pid="$1"
    local rc
    while :; do
        wait "$child_pid" 2>/dev/null
        rc=$?
        if [ "$rc" -le 128 ] || ! _pid_alive "$child_pid" 2>/dev/null; then
            _WAIT_OP_RC="$rc"
            return 0
        fi
        # Trapped-signal interruption; the child is still alive. Enforce the
        # ceiling (TERM→KILL + notify when exceeded; no-op below it), then
        # resume waiting for the real exit.
        _watchdog_check_child
    done
}

# ---------------------------------------------------------------------------
# #174: per-entity reconcile log de-duplication. The reconcile pass runs every
# tick (~7 s) and used to log an identical per-entity status line each time —
# thousands of reconcile_ok / reconcile_halt_persisted / reconcile_skipped_locked
# lines a day that buried real events. Emit only on STATE CHANGE per
# (event, entity), plus a proof-of-life re-emit at most hourly carrying a
# "repeats" count of the suppressed lines, so a silent log still reads as
# healthy-and-deduped rather than dead. Cache is in-memory (the reconcile pass
# runs in the long-lived supervisor process); a supervisor restart re-emits one
# line per entity, which is desirable.
declare -gA _RECON_LOG_STATE
_RECONCILE_REEMIT_S=3600

# _reconcile_log_dedup <level> <event> <entity> <state> <json>
_reconcile_log_dedup() {
    local level="$1" event="$2" entity="$3" state="$4" json="$5"
    local key="${event}|${entity}"
    local now
    now=$(date +%s)
    local prev="${_RECON_LOG_STATE[$key]:-}"
    local pstate="" pepoch=0 pcount=0
    if [ -n "$prev" ]; then
        pstate="${prev%%|*}"
        local _rest="${prev#*|}"
        pepoch="${_rest%%|*}"
        pcount="${_rest##*|}"
    fi
    if [ "$state" = "$pstate" ] && [ $(( now - pepoch )) -lt "$_RECONCILE_REEMIT_S" ]; then
        _RECON_LOG_STATE[$key]="${state}|${pepoch}|$(( pcount + 1 ))"
        return 0
    fi
    local suffix=""
    if [ "$state" = "$pstate" ] && [ "$pcount" -gt 0 ]; then
        suffix=",\"repeats\":$(( pcount + 1 ))"
    fi
    lifecycle_log "$level" "supervisor" "$event" "${json%\}}$suffix}" 2>/dev/null || true
    _RECON_LOG_STATE[$key]="${state}|${now}|0"
}

_op_reconcile() {
    local mpath
    mpath="$(manifest_path 2>/dev/null || echo '/boot/config/plugins/unraid-aicliagents/layer_manifest.json')"
    [ -f "$mpath" ] || return 0

    # One shared-locked manifest generation governs this whole pass. Previously
    # the entity list, persistence path, layer list, and each expected hash were
    # independent reads of the live file. A writer between those reads could
    # manufacture a mismatch from two individually valid generations.
    local manifest_snapshot=""
    manifest_snapshot="$(_manifest_read "$mpath")"
    [ -n "$manifest_snapshot" ] || return 0

    # Read entities from the captured snapshot using PHP to avoid bash JSON
    # parsing complexity. Fall back to empty if PHP is unavailable.
    local entities_json=""
    if command -v php >/dev/null 2>&1; then
        entities_json=$(printf '%s' "$manifest_snapshot" | php -d display_errors=0 -r '
            $m = json_decode(stream_get_contents(STDIN), true);
            if (!is_array($m)) exit;
            foreach (array_keys($m["entities"] ?? []) as $k) echo $k, PHP_EOL;
        ' 2>/dev/null || true)
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
            _reconcile_log_dedup info reconcile_skipped_locked "$entity" "locked" \
                "{\"entity\":\"$entity\"}"
            continue
        fi
        # #357: say who holds the lock. A bake that meets this lock reads the
        # owner record: "reconcile" is not a bake, so the bake WAITS for this
        # short check and then saves, instead of skipping and reporting success.
        # The check keeps the lock for the whole entity body (the untracked-layer
        # quarantine below must not see a bake's half-written layer set), so a
        # shorter or shared lock would reopen that race.
        printf 'op=reconcile pid=%s since=%s\n' "$$" "$(date +%s)" \
            > "/var/run/aicli-bake-${type}-${_rec_lock_id}.lock.owner" 2>/dev/null || true

        # #99: the pass-wide snapshot predates this entity lock. Refresh it
        # now, while holding the same lock used by bake/consolidate/wipe. This
        # prevents a completed deletion or layer change from being compared
        # against stale expected_layers and turned into a false durable halt.
        local entity_manifest_snapshot=""
        entity_manifest_snapshot="$(_manifest_read "$mpath")"
        if ! _manifest_has_entity "$entity" "$entity_manifest_snapshot"; then
            log_info "Reconcile: $entity disappeared before its locked check — skipping stale snapshot entry"
            lifecycle_log "info" "supervisor" "reconcile_skipped_removed" \
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
        manifest_stored_path=$(_manifest_entity_persist_path "$entity" "$entity_manifest_snapshot")
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

        # A corruption halt is durable until the user completes an explicit
        # verified repair. Do not repeatedly rewrite the marker, notify again,
        # and then claim reconcile_ok on later ticks.
        if _halt_exists "$entity" "corrupt_layers"; then
            _reconcile_log_dedup warn reconcile_halt_persisted "$entity" "halted:corrupt_layers" \
                "{\"entity\":\"$entity\",\"reason\":\"corrupt_layers\"}"
            continue
        fi

        # Get expected layers and their hashes from the same manifest snapshot.
        local expected_files=()
        local expected_sha256s=()
        if command -v php >/dev/null 2>&1; then
            local layer_filename layer_sha256
            while IFS=$'\t' read -r layer_filename layer_sha256; do
                [ -n "$layer_filename" ] || continue
                expected_files+=("$layer_filename")
                expected_sha256s+=("$layer_sha256")
            done < <(_manifest_entity_layer_records "$entity" "$entity_manifest_snapshot")
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

        # Sha256 verification for expected layers (budget-aware: only if time
        # permits). A corruption verdict requires two identical mismatches while
        # the file identity stays fixed. Changed identity or contradictory reads
        # are deferred to the next tick and are never reported as healthy.
        local integrity_verdict=0
        now=$(date +%s)
        if [ $(( now - reconcile_start )) -lt $(( max_budget - 5 )) ]; then
            local layer_index
            for layer_index in "${!expected_files[@]}"; do
                exp_file="${expected_files[$layer_index]}"
                [ -n "$exp_file" ] || continue
                local full_path="${persist_path}/${exp_file}"
                [ -f "$full_path" ] || continue

                local expected_sha256="${expected_sha256s[$layer_index]:-}"
                # Skip verification if sha256 in manifest is placeholder/smoke
                [ -n "$expected_sha256" ] || continue
                [ "$expected_sha256" = "smoke" ] && continue
                [ "${#expected_sha256}" -lt 32 ] && continue

                _verify_layer_cached "$full_path" "$expected_sha256"
                local verify_rc=$?
                if [ "$verify_rc" -eq 1 ]; then
                    integrity_verdict=1
                    log_error "Reconcile: confirmed sha256 mismatch for $exp_file (expected $expected_sha256, got $_LAYER_VERIFY_ACTUAL, identity $_LAYER_VERIFY_IDENTITY)"
                    _write_halt "$entity" "corrupt_layers" "confirmed sha256 mismatch for $exp_file"
                    _supervisor_notify "critical" "AICliAgents: Layer corruption detected" \
                        "Entity $entity: layer $exp_file has a confirmed sha256 mismatch. Storage halted." \
                        "sha256_mismatch_${entity}_${exp_file}" 3600
                    lifecycle_log "critical" "supervisor" "reconcile_halted_entity" \
                        "{\"entity\":\"$entity\",\"reason\":\"sha256_mismatch\",\"filename\":\"$exp_file\",\"expected_sha256\":\"$expected_sha256\",\"actual_sha256\":\"$_LAYER_VERIFY_ACTUAL\",\"identity\":\"$_LAYER_VERIFY_IDENTITY\"}" 2>/dev/null || true
                    break
                elif [ "$verify_rc" -eq 2 ]; then
                    integrity_verdict=2
                    log_warn "Reconcile: inconclusive sha256 verification for $exp_file; stable confirmation not obtained — retrying next tick"
                    lifecycle_log "warn" "supervisor" "reconcile_verify_inconclusive" \
                        "{\"entity\":\"$entity\",\"filename\":\"$exp_file\",\"expected_sha256\":\"$expected_sha256\",\"observed_sha256\":\"$_LAYER_VERIFY_ACTUAL\",\"identity\":\"$_LAYER_VERIFY_IDENTITY\"}" 2>/dev/null || true
                    break
                fi
            done
        fi

        [ "$integrity_verdict" -eq 0 ] || continue

        _reconcile_log_dedup info reconcile_ok "$entity" "ok:${#actual_files[@]}" \
            "{\"entity\":\"$entity\",\"active_count\":${#actual_files[@]}}"

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
                local _lp_name="${_lp_dev##*/}"
                local _lp_autoclear="/sys/block/${_lp_name}/loop/autoclear"
                if losetup "$_lp_dev" >/dev/null 2>&1 \
                    && [ -r "$_lp_autoclear" ] \
                    && [ "$(tr -d '[:space:]' < "$_lp_autoclear" 2>/dev/null)" = "1" ]; then
                    log_info "Reconcile: detach pending for busy loop $_lp_dev (kernel autoclear armed)"
                    lifecycle_log "info" "supervisor" "reconcile_orphan_loop_autoclear" \
                        "{\"loop\":\"$_lp_dev\"}" 2>/dev/null || true
                else
                    log_info "Reconcile: reaped abandoned loop $_lp_dev (deleted backing, not mounted)"
                    lifecycle_log "info" "supervisor" "reconcile_orphan_loop_reaped" \
                        "{\"loop\":\"$_lp_dev\"}" 2>/dev/null || true
                fi
            fi
        done < <(losetup -a 2>/dev/null || true)
    fi

    # S-08 (#1353): reap terminal job-ledger entries (done 1 h, failed/deferred 24 h)
    # + orphaned retry pen entries.
    _reap_job_ledger

    # 1a.7 (PLUGIN_EVENT_LEDGER_AND_SUBSCRIPTIONS.md): unlink event-ledger
    # chunks beyond the configured cap.
    _events_ledger_sweep

    # Forgejo #244: unlink expired busy-bake cooldown markers.
    _bake_cooldown_marker_sweep
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
    upper_dir="$(_entity_upper_for_supervisor "$type" "$id" 2>/dev/null)"   # #372: the live upper

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

    # Wait for child (#175: immune to trapped-signal interruption; ceiling
    # enforced inline via the watchdog while blocked here)
    _wait_op_child "$child_pid"
    local exit_code="$_WAIT_OP_RC"
    _OP_EXIT="$exit_code"   # S-08: recorded verbatim in the job ledger (if tracked)

    _CHILD_PID=""
    _CHILD_OP=""
    _CHILD_ENTITY=""

    local now
    now=$(date +%s)
    _LAST_COMPLETED_AT="$now"

    # #357: an exit 2 whose defer reason says no layer was written is NOT a
    # completed bake. Log it as bake_not_saved; _job_finalize reads the same
    # marker and requeues a user Persist (bake_lock_held is in its retry set).
    local _bk_reason=""
    if [ "$exit_code" -eq 2 ]; then
        local _bk_safe_id="${id//[^a-zA-Z0-9_-]/_}"
        _bk_reason="$(head -1 "/tmp/unraid-aicliagents/.bake_defer_reason_${type}_${_bk_safe_id}" 2>/dev/null | tr -d '\n')"
    fi
    # #374: record the result for the automatic-bake backoff (every caller;
    # a saved result clears it).
    _bake_backoff_record "$type" "$id" "$exit_code" "$_bk_reason"
    if [ "$exit_code" -eq 2 ] && ! _bake_result_saved 2 "$_bk_reason"; then
        log_warn "Bake NOT saved: $entity (exit=2, reason=$_bk_reason) — the changes stay in RAM until the next bake"
        lifecycle_log "warn" "supervisor" "bake_not_saved" \
            "{\"entity\":\"$entity\",\"exit_code\":$exit_code,\"defer_reason\":\"$_bk_reason\"}" 2>/dev/null || true
    elif [ "$exit_code" -eq 0 ] || [ "$exit_code" -eq 2 ]; then
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
# Only an explicit workflow may close sessions to consolidate. Policy maintenance
# must wait for natural idle; its storagectl call also has a mount-busy guard.
_consolidate_reason_closes_home() {
    case "${1:-}" in
        user_consolidate|pre_migrate|post_migrate) return 0 ;;
        *) return 1 ;;
    esac
}

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

    # User-requested consolidation and migration are explicit close/resume workflows.
    # Automatic policy work is maintenance and must never terminate live sessions.
    if [ "$type" = "home" ] && _consolidate_reason_closes_home "$reason"; then
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

    # Wait for child (#175: immune to trapped-signal interruption; ceiling
    # enforced inline via the watchdog while blocked here)
    _wait_op_child "$child_pid"
    local exit_code="$_WAIT_OP_RC"
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

# ---- #350: upgrade activation retry state ------------------------------------
# docs/specs/UPGRADE_ACTIVATION_WITHOUT_CLOSED_SET.md "2026-09-29".
# Before: an activation mount that FAILED (exit 1) parked no retry. The sweep
# (_check_pending_agent_upgrades) queued it again on the next tick, so it ran
# every 7-8 s with no backoff and logged three lines each time (codex-cli on
# .4: 717 failures in 90 minutes). Now every try that does not succeed parks a
# retry with a growing delay, the log shows one line per change of outcome, and
# after UPGRADE_ACTIVATION_MAX_FAILURES failures in a row the activation halts.
# A session close (UpgradeRelaunchService::nudgeActivation) or a new install
# (schedulePendingActivation) arms it again. A defer (exit 2: the old version
# is still held) never halts: holders can run for days.
UPGRADE_ACTIVATION_MAX_FAILURES="${UPGRADE_ACTIVATION_MAX_FAILURES:-6}"

# Delay before retry N: 15 → 30 → 60 → 120 → 300 → 600 s (capped). Pure.
_upgrade_activation_retry_delay() {
    case "${1:-1}" in
        1) echo 15 ;;
        2) echo 30 ;;
        3) echo 60 ;;
        4) echo 120 ;;
        5) echo 300 ;;
        *) echo 600 ;;
    esac
}

# The state file for one activation job: key=value lines (tries, fails,
# outcome, halted). It sits beside the job's .retry file; only *.retry files
# are re-enqueued, so the pen never reads it as a job.
_ua_state_file() { printf '%s/%s.activation' "$JOB_RETRY_DIR" "$1"; }
_ua_state_get() {
    local f; f="$(_ua_state_file "$1")"
    [ -f "$f" ] || return 0
    sed -n "s/^$2=//p" "$f" 2>/dev/null | head -1
}
# _ua_state_set <job_id> <tries> <fails> <outcome> <halted>
_ua_state_set() {
    local f tmp; f="$(_ua_state_file "$1")"; tmp="$f.tmp.$$"
    mkdir -p "$JOB_RETRY_DIR" 2>/dev/null || true
    printf 'tries=%s\nfails=%s\noutcome=%s\nhalted=%s\n' "$2" "$3" "$4" "$5" > "$tmp" 2>/dev/null \
        && mv -f "$tmp" "$f" 2>/dev/null
}
_ua_state_clear() { rm -f "$(_ua_state_file "$1")" 2>/dev/null || true; }

# _ua_outcome <exit_code> <defer_reason> — the outcome word for one try.
_ua_outcome() {
    case "$1" in
        0) echo "done" ;;
        2) echo "deferred:${2:-unknown}" ;;
        *) echo "failed:$1" ;;
    esac
}

# _finalize_upgrade_activation <job_id> <type> <id> <exit_code> <defer> <priority> <trace>
# Sets UA_STATE (done|deferred|failed) and UA_ATTEMPT for the job ledger.
_finalize_upgrade_activation() {
    local job_id="$1" type="$2" id="$3" rc="$4" defer="$5" priority="${6:-1}" trace="${7:-}"
    local entity="${type}/${id}" outcome prev tries fails now delay
    outcome="$(_ua_outcome "$rc" "$defer")"
    prev="$(_ua_state_get "$job_id" outcome)"
    tries="$(_ua_state_get "$job_id" tries)"; case "$tries" in ''|*[!0-9]*) tries=0 ;; esac
    fails="$(_ua_state_get "$job_id" fails)"; case "$fails" in ''|*[!0-9]*) fails=0 ;; esac
    tries=$(( tries + 1 ))
    UA_ATTEMPT="$tries"
    if [ "$rc" -eq 0 ]; then
        UA_STATE="done"
        _ua_state_clear "$job_id"
        return 0
    fi
    case "$outcome" in failed:*) fails=$(( fails + 1 )) ;; *) fails=0 ;; esac
    if [ "$fails" -ge "$UPGRADE_ACTIVATION_MAX_FAILURES" ]; then
        UA_STATE="failed"
        _ua_state_set "$job_id" "$tries" "$fails" "$outcome" 1
        rm -f "$JOB_RETRY_DIR/${job_id}.retry" 2>/dev/null || true
        log_warn "Upgrade activation for $entity failed $fails times in a row ($outcome) — halted until a session of the agent closes or the agent is installed again"
        lifecycle_log "warn" "supervisor" "upgrade_activation_halted" \
            "{\"entity\":\"$entity\",\"job_id\":\"$job_id\",\"attempt\":$tries,\"failures\":$fails,\"outcome\":\"$outcome\"}" 2>/dev/null || true
        _supervisor_notify "warning" "AICliAgents: Agent update not activated" \
            "The new version of $id is installed, but switching to it failed $fails times ($outcome). Workspaces keep the version they run. Close the workspaces of $id, or install the agent again, to try once more." \
            "upgrade_activation_halted_${entity}" 3600
        return 0
    fi
    [ "$rc" -eq 2 ] && UA_STATE="deferred" || UA_STATE="failed"
    delay="$(_upgrade_activation_retry_delay "$tries")"
    now=$(date +%s)
    mkdir -p "$JOB_RETRY_DIR" 2>/dev/null || true
    local trace_kv=""
    [ -n "$trace" ] && trace_kv=",\"trace\":\"$trace\""
    _atomic_json_write "$JOB_RETRY_DIR/${job_id}.retry" \
        "$(printf '{"job_id":"%s","type":"%s","id":"%s","op":"mount","reason":"upgrade_relaunch","priority":%d,"retry_at":%d%s}' \
            "$job_id" "$type" "$id" "$priority" "$(( now + delay ))" "$trace_kv")" || true
    _ua_state_set "$job_id" "$tries" "$fails" "$outcome" 0
    # One line per change of outcome, not one per try.
    if [ "$outcome" != "$prev" ]; then
        lifecycle_log "info" "supervisor" "upgrade_activation_requeued" \
            "{\"entity\":\"$entity\",\"job_id\":\"$job_id\",\"attempt\":$tries,\"retry_in_s\":$delay,\"outcome\":\"$outcome\",\"defer_reason\":\"$defer\"}" 2>/dev/null || true
        log_info "Upgrade activation for $entity: $outcome — retry #$(( tries + 1 )) in ${delay}s (the delay grows to 600s; later tries with the same result are not logged)"
    fi
    return 0
}

# _ua_quiet_retry <job_id> — true when this activation try is a retry: its
# start and a repeated outcome are then not logged again.
_ua_quiet_retry() {
    local t; t="$(_ua_state_get "$1" tries)"
    case "$t" in ''|*[!0-9]*|0) return 1 ;; esac
    return 0
}

# #350: the supervisor is a production daemon. A smoke test that restarts it
# (bash aicli-supervisor.sh start) passes on its own environment, and the smoke
# exports AICLI_ITEST_BACKEND=flash. effective_backend then read EVERY
# plain-folder agent as a layer stack, and the activation mount of codex-cli
# took the layer path ("No lower layers found ... empty stack") for hours.
# Test hooks never apply to the daemon: drop them at start.
# #353 (2026-09-29): the smoke harness also exports SMOKE_* (SMOKE_REPORT_JSON
# and others). No production script reads SMOKE_*, so drop them too.
# _SCRUBBED_TEST_HOOKS holds the number of variables dropped. The start command
# uses it to re-exec the script: `unset` changes only what children inherit,
# so /proc/<pid>/environ of the daemon, and every global that the script
# computed from the environment while it was sourced, still show the test
# values. A re-exec starts the daemon with a clean environment (same pid).
_SCRUBBED_TEST_HOOKS=0
_scrub_test_hook_env() {
    local v
    _SCRUBBED_TEST_HOOKS=0
    for v in $(compgen -e); do
        case "$v" in
            AICLI_ITEST_*|SMOKE_*)
                unset "$v"
                _SCRUBBED_TEST_HOOKS=$((_SCRUBBED_TEST_HOOKS + 1))
                ;;
        esac
    done
    return 0
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

    # A recovered workspace may supersede an old forced-upgrade closed set
    # while its durable mount retry is still queued. Never activate that stale
    # job after the manifest has been retired: doing so can disturb the healthy
    # replacement sessions that made the old job obsolete.
    if [ "$type" = "agent" ] && [ "$reason" = "upgrade_relaunch" ] \
        && ! _has_pending_agent_upgrade "$id"; then
        log_info "Skipping obsolete upgrade activation: $entity (manifest retired)"
        lifecycle_log "info" "supervisor" "upgrade_activation_superseded" \
            "{\"entity\":\"$entity\"}" 2>/dev/null || true
        _OP_EXIT=0
        return 0
    fi

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

    # #350: an activation retry logs its start and its outcome only when the
    # outcome changes (_finalize_upgrade_activation logs the change).
    local ua_job="" ua_quiet=0 ua_prev=""
    if [ "$type" = "agent" ] && [ "$reason" = "upgrade_relaunch" ]; then
        ua_job="upgrade-agent-${id//[^A-Za-z0-9._-]/_}"
        if _ua_quiet_retry "$ua_job"; then
            ua_quiet=1
            ua_prev="$(_ua_state_get "$ua_job" outcome)"
        fi
    fi

    if [ "$ua_quiet" -ne 1 ]; then
        log_info "Starting mount: $entity (reason=$reason)"
        lifecycle_log "info" "supervisor" "mount_start" \
            "{\"entity\":\"$entity\",\"reason\":\"$reason\"}" 2>/dev/null || true
    fi

    # Spawn child — subshell drops the inherited single-instance lock fd FIRST
    # (Bug #757); see _op_bake. op_mount defaults --owner to the id for homes,
    # so no owner threading is needed here.
    (
        [ -n "${SUP_LOCK_FD:-}" ] && exec {SUP_LOCK_FD}>&- 2>/dev/null
        # The probe above already found the lock free, and a mount job that
        # defers is requeued with backoff: do not also wait inside storagectl
        # and hold the work loop (AICLI_MOUNT_LOCK_WAIT_S=0 = probe once).
        AICLI_MOUNT_LOCK_WAIT_S=0 exec bash "${STORAGE_DIR}/storagectl.sh" mount --type "$type" --id "$id" --persist "$persist_path" >/dev/null 2>&1
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

    # Wait for child (#175: immune to trapped-signal interruption; ceiling
    # enforced inline via the watchdog while blocked here)
    _wait_op_child "$child_pid"
    local exit_code="$_WAIT_OP_RC"
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
        if [ "$type" = "agent" ] && [ "$reason" = "upgrade_relaunch" ]; then
            if ! _relaunch_pending_agent_upgrade "$id"; then
                exit_code=1
                _OP_EXIT=1
            fi
        fi
    fi

    if [ "$exit_code" -eq 0 ]; then
        log_info "Mount completed: $entity"
        lifecycle_log "info" "supervisor" "mount_ok" \
            "{\"entity\":\"$entity\"}" 2>/dev/null || true
    elif [ "$ua_quiet" -eq 1 ] && [ "$(_ua_outcome "$exit_code" "${_OP_DEFER_REASON:-}")" = "$ua_prev" ]; then
        :   # #350: the same outcome as the last try — already logged once
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

    # Wait for child (#175: immune to trapped-signal interruption; ceiling
    # enforced inline via the watchdog while blocked here)
    _wait_op_child "$child_pid"
    local exit_code="$_WAIT_OP_RC"
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

# Op handler: backup (HOME_BACKUP.md). The heavy lifting (close/bake/rsync/
# verify/manifest/prune/relaunch) lives in the sourced backup_home.sh
# function _backup_home_execute — this wrapper only owns the job-ledger/
# work-state/watchdog bookkeeping every op handler owns, mirroring _op_bake
# and _op_consolidate above. Only ever dispatched for type=home.
# ---------------------------------------------------------------------------
_op_backup_home() {
    local type="${1:-home}"
    local id="${2:-}"
    local reason="${3:-user_backup_home}"
    local job_id="${4:-}"

    local entity="home/${id}"
    _OP_EXIT=""
    _OP_DEFER_REASON=""

    if [ -z "$id" ] || ! declare -f _backup_home_execute >/dev/null 2>&1; then
        log_error "Home backup for $entity cannot run: missing id or backup_home.sh not loaded"
        _OP_EXIT=1
        return 0
    fi
    if [ -z "$job_id" ]; then
        # backup is always enqueued tracked (StorageHandler::backupHome mints
        # its own job id before enqueueing) — an untracked request has no
        # options sidecar to read and cannot run.
        log_error "Home backup for $entity has no job id — refusing (no options to read)"
        _OP_EXIT=1
        return 0
    fi

    local started_at
    started_at=$(date +%s)
    local max_dur="${BACKUP_MAX_DURATION_S:-3600}"

    log_info "Starting home backup: $entity (reason=$reason, job=$job_id)"
    lifecycle_log "info" "supervisor" "backup_start" \
        "{\"entity\":\"$entity\",\"reason\":\"$reason\",\"job_id\":\"$job_id\"}" 2>/dev/null || true

    # Background subshell (not exec — _backup_home_execute is a sourced bash
    # function, not a separate program) so the watchdog's kill -0/-TERM/-KILL
    # and _wait_op_child still target a real PID, and an orphaned run does not
    # keep the single-instance lock fd held (Bug #757 pattern; see _op_bake).
    (
        [ -n "${SUP_LOCK_FD:-}" ] && exec {SUP_LOCK_FD}>&- 2>/dev/null
        _backup_home_execute "$id" "$reason" "$job_id"
    ) &
    local child_pid=$!

    _CHILD_PID="$child_pid"
    _CHILD_OP="backup"
    _CHILD_ENTITY="$entity"
    _CHILD_STARTED_AT="$started_at"
    _CHILD_MAX_DURATION="$max_dur"

    local qdepth
    qdepth="$(queue_depth 2>/dev/null || echo 0)"
    _atomic_json_write "$WORKFILE" "$(_running_work_json "backup" "$entity" "$child_pid" "$started_at" "$max_dur" "$qdepth")" || true
    _atomic_json_write "$STATUSFILE" "$(_status_json running backup "$entity" "$qdepth" "$_LAST_COMPLETED_AT")" || true

    _wait_op_child "$child_pid"
    local exit_code="$_WAIT_OP_RC"
    _OP_EXIT="$exit_code"

    _CHILD_PID=""
    _CHILD_OP=""
    _CHILD_ENTITY=""

    local now
    now=$(date +%s)
    _LAST_COMPLETED_AT="$now"

    if [ "$exit_code" -eq 0 ]; then
        log_info "Home backup completed: $entity"
        lifecycle_log "info" "supervisor" "backup_ok" "{\"entity\":\"$entity\",\"job_id\":\"$job_id\"}" 2>/dev/null || true
    else
        log_error "Home backup failed: $entity (exit=$exit_code)"
        lifecycle_log "error" "supervisor" "backup_failed" \
            "{\"entity\":\"$entity\",\"job_id\":\"$job_id\",\"exit_code\":$exit_code}" 2>/dev/null || true
    fi
    # The options sidecar has done its job either way — never leak it.
    declare -f _backup_delete_options >/dev/null 2>&1 && _backup_delete_options "$job_id"
}

# Op handler: restore (HOME_RESTORE.md). The heavy lifting (close/safety-
# snapshot/rsync/verify/bake/relaunch) lives in the sourced backup_home.sh
# function _restore_home_execute — this wrapper only owns the job-ledger/
# work-state/watchdog bookkeeping every op handler owns, mirroring
# _op_backup_home just above. Only ever dispatched for type=home.
# ---------------------------------------------------------------------------
_op_restore_home() {
    local type="${1:-home}"
    local id="${2:-}"
    local reason="${3:-user_restore_home}"
    local job_id="${4:-}"

    local entity="home/${id}"
    _OP_EXIT=""
    _OP_DEFER_REASON=""

    if [ -z "$id" ] || ! declare -f _restore_home_execute >/dev/null 2>&1; then
        log_error "Home restore for $entity cannot run: missing id or backup_home.sh not loaded"
        _OP_EXIT=1
        return 0
    fi
    if [ -z "$job_id" ]; then
        # restore is always enqueued tracked (StorageHandler::restoreHome mints
        # its own job id before enqueueing) — an untracked request has no
        # options sidecar to read and cannot run.
        log_error "Home restore for $entity has no job id — refusing (no options to read)"
        _OP_EXIT=1
        return 0
    fi

    local started_at
    started_at=$(date +%s)
    local max_dur="${RESTORE_MAX_DURATION_S:-3600}"

    log_info "Starting home restore: $entity (reason=$reason, job=$job_id)"
    lifecycle_log "info" "supervisor" "restore_start" \
        "{\"entity\":\"$entity\",\"reason\":\"$reason\",\"job_id\":\"$job_id\"}" 2>/dev/null || true

    # Background subshell (not exec — _restore_home_execute is a sourced bash
    # function, not a separate program) so the watchdog's kill -0/-TERM/-KILL
    # and _wait_op_child still target a real PID, and an orphaned run does not
    # keep the single-instance lock fd held (Bug #757 pattern; see _op_bake).
    (
        [ -n "${SUP_LOCK_FD:-}" ] && exec {SUP_LOCK_FD}>&- 2>/dev/null
        _restore_home_execute "$id" "$reason" "$job_id"
    ) &
    local child_pid=$!

    _CHILD_PID="$child_pid"
    _CHILD_OP="restore"
    _CHILD_ENTITY="$entity"
    _CHILD_STARTED_AT="$started_at"
    _CHILD_MAX_DURATION="$max_dur"

    local qdepth
    qdepth="$(queue_depth 2>/dev/null || echo 0)"
    _atomic_json_write "$WORKFILE" "$(_running_work_json "restore" "$entity" "$child_pid" "$started_at" "$max_dur" "$qdepth")" || true
    _atomic_json_write "$STATUSFILE" "$(_status_json running restore "$entity" "$qdepth" "$_LAST_COMPLETED_AT")" || true

    _wait_op_child "$child_pid"
    local exit_code="$_WAIT_OP_RC"
    _OP_EXIT="$exit_code"

    _CHILD_PID=""
    _CHILD_OP=""
    _CHILD_ENTITY=""

    local now
    now=$(date +%s)
    _LAST_COMPLETED_AT="$now"

    if [ "$exit_code" -eq 0 ]; then
        log_info "Home restore completed: $entity"
        lifecycle_log "info" "supervisor" "restore_ok" "{\"entity\":\"$entity\",\"job_id\":\"$job_id\"}" 2>/dev/null || true
    else
        log_error "Home restore failed: $entity (exit=$exit_code)"
        lifecycle_log "error" "supervisor" "restore_failed" \
            "{\"entity\":\"$entity\",\"job_id\":\"$job_id\",\"exit_code\":$exit_code}" 2>/dev/null || true
    fi
    # The options sidecar has done its job either way — never leak it.
    declare -f _restore_delete_options >/dev/null 2>&1 && _restore_delete_options "$job_id"
}

# ---------------------------------------------------------------------------
# docs/specs/EVENT_FIRST_RECONCILIATION.md 1b.3/1b.4: the supervisor publishes
# storage figures and maintenance state, for the first time. Same pattern as
# sync-activity.php/activity-sweep.php (a short-lived PHP child, spawned
# in the background so it can never block the tick).
# ---------------------------------------------------------------------------
AICLI_PUBLISH_STORAGE_STATUS_PHP="${AICLI_PUBLISH_STORAGE_STATUS_PHP:-/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/supervisor/publish-storage-status.php}"

# _publish_storage_status — push the full aicli_storage_status snapshot
# (figures + `maintenance`) after a job finalizes or a maintenance marker
# changes. Gated exactly like _job_activity_push: skipped in a sandboxed/unit
# test run (AICLI_JOBS_DIR set), skipped if the script is absent (container
# tests) or php is missing. Backgrounded — never blocks the tick.
_publish_storage_status() {
    [ -z "${AICLI_JOBS_DIR:-}" ] || return 0
    [ -f "$AICLI_PUBLISH_STORAGE_STATUS_PHP" ] || return 0
    command -v php >/dev/null 2>&1 || return 0
    php -d display_errors=0 "$AICLI_PUBLISH_STORAGE_STATUS_PHP" >/dev/null 2>&1 &
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
    # HOME_STORAGE_CARD_JOB_STATE.md (Forgejo #247): the job just turned RUNNING.
    # Publish the snapshot now (not only at the outcome, 1b.3) so the home card
    # shows "Saving…" and locks its buttons at once, on every device — also for
    # a job nobody clicked (a scheduled or supervisor-started bake).
    _publish_storage_status
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
    if [ "$op" = "mount" ] && [ "$reason" = "upgrade_relaunch" ]; then
        # #350: every try that does not succeed parks a retry with a growing
        # delay; repeated failures halt. See _finalize_upgrade_activation.
        UA_STATE="failed"; UA_ATTEMPT="$attempt"
        _finalize_upgrade_activation "$job_id" "$type" "$id" "$exit_code" "$defer" "$priority" "$trace"
        state="$UA_STATE"; attempt="$UA_ATTEMPT"
    elif [ "$exit_code" -eq 0 ]; then
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
             && { [ "$defer" = "mount_busy" ] || [ "$defer" = "bake_lock_held" ] || [ "$defer" = "upper_not_empty" ] || [ "$defer" = "busy_cooldown" ]; }; then
            # Forgejo #233: busy_cooldown (storage_ops.sh's bash_bake_busy_cooldown
            # throttle — "still busy, and we already persisted within the last
            # 30 min, so skip a redundant bake") was missing from this list. A
            # job whose SECOND attempt deferred with busy_cooldown (common: the
            # first attempt's mount-refresh hit mount_busy, and by the time the
            # retry fires the cooldown window from that same persist is still
            # open) matched none of this function's branches, fell through with
            # no retry scheduled, and was stranded in state=deferred forever —
            # confirmed live 2026-09-16: job bake-home-root-*-cc3dff sat dead for
            # 35+ minutes, including a 9-minute stretch with every session on
            # the home closed, because nothing was ever going to re-enqueue it.
            # OP#1381: a USER-INITIATED consolidate/bake that deferred because the
            # overlay is still held open (mount_busy / a racing bake's lock /
            # writes-during-bake) is a one-shot the user explicitly asked for — it
            # must eventually LAND once the workspace closes, not die terminal as a
            # plain `deferred` record. Park it on the retry pen (same machinery as
            # mount/graduate): _check_job_retries re-enqueues it when retry_at
            # passes, and the session-close path re-enqueues immediately when the
            # last session for the entity closes. Backoff 15→60→120 s; total budget
            # USER_CONSOLIDATE_WAIT_CAP_S (3 min default — deliberately short,
            # #44/Fix 3b: a stuck consolidate gives up fast and restores
            # sessions headlessly rather than leaving the user stranded),
            # then FAILED + notify. A genuinely
            # still-busy entity just keeps deferring on each retry (no tight loop —
            # the backoff bounds the cadence).
            local uc_elapsed=$(( now - queued_at ))
            local uc_cap="$USER_CONSOLIDATE_WAIT_CAP_S"
            case "$uc_cap" in ''|*[!0-9]*) uc_cap=3600 ;; esac
            if [ "$uc_elapsed" -ge "$uc_cap" ] && [ "$op" = "bake" ] \
               && { [ "$defer" = "busy_cooldown" ] || [ "$defer" = "mount_busy" ]; }; then
                # Forgejo #248: a user Persist that kept deferring for these two
                # reasons did NOT fail. busy_cooldown = this home was saved less
                # than 30 min ago; mount_busy = the new layer is already on disk
                # (#231). Only the release of the old copy's RAM waits for the open
                # sessions, and the idle bake does that on its own. It used to end
                # as FAILED with a warning notice and a tray line reading
                # "storage job failed (exit 2), reason: busy_cooldown" (John,
                # 2026-09-18). End it as DONE; defer_reason stays on the record so
                # the tray can say, in plain words, that the data was already saved.
                state="done"
                exit_code=0
                log_info "User $op job $job_id for $entity: data already saved; RAM release waits for idle ($defer, ${uc_elapsed}s) — done"
                lifecycle_log "info" "supervisor" "user_persist_already_saved" \
                    "{\"entity\":\"$entity\",\"job_id\":\"$job_id\",\"elapsed_s\":$uc_elapsed,\"defer_reason\":\"$defer\"}" 2>/dev/null || true
            elif [ "$uc_elapsed" -ge "$uc_cap" ]; then
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

    # docs/specs/EVENT_FIRST_RECONCILIATION.md 1b.3: every job outcome — done,
    # failed, deferred, requeued — publishes the storage snapshot, not only a
    # user-clicked one. Figures move without waiting for a browser poll.
    _publish_storage_status
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

# OP#1381 / Bug #1384: self-contained overlay busy-arbiter for every supervisor
# policy and resume path.
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
_supervisor_overlay_busy() {
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

# Compatibility name retained for focused unit tests and older sourced callers.
_overlay_busy_for_resume() { _supervisor_overlay_busy "$@"; }

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
        # Eligible: user consolidate/bake retries (OP#1381) and parked upgrade
        # activations (UPGRADE_ACTIVATION_WITHOUT_CLOSED_SET.md §Event fallback —
        # the moment the agent mount goes idle the layer swap runs on THIS tick
        # instead of waiting out the 10→30→60 s backoff).
        local r_kind=""
        case "$r_op:$r_reason" in
            consolidate:user_consolidate|consolidate:user_persist|bake:user_consolidate|bake:user_persist) r_kind="user" ;;
            mount:upgrade_relaunch) r_kind="upgrade" ;;
            *) continue ;;
        esac
        r_type="$(queue_read_field "$f" type 2>/dev/null)"
        r_id="$(queue_read_field "$f" id 2>/dev/null)"
        [ -n "$r_type" ] && [ -n "$r_id" ] || continue
        # #350: only a WAIT (the old version still held) ends when the mount
        # goes idle. A try that FAILED keeps its backoff: an idle mount does not
        # make it succeed, and pulling it forward on every tick was the 7-8 s loop.
        if [ "$r_kind" = "upgrade" ]; then
            case "$(_ua_state_get "$(basename "$f" .retry)" outcome)" in failed:*) continue ;; esac
        fi

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
        _supervisor_overlay_busy "$mnt" && continue

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
        if [ "$r_kind" = "upgrade" ]; then
            lifecycle_log "info" "supervisor" "upgrade_activation_resume_on_idle" \
                "{\"entity\":\"${r_type}/${r_id}\",\"job_id\":\"$r_job\"}" 2>/dev/null || true
        else
            lifecycle_log "info" "supervisor" "user_consolidate_resume_on_idle" \
                "{\"entity\":\"${r_type}/${r_id}\",\"job_id\":\"$r_job\",\"op\":\"$r_op\"}" 2>/dev/null || true
        fi
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

# _bake_cooldown_marker_sweep — Forgejo #244. storage_ops.sh writes
# `.bake_busy_cooldown_<type>_<id>` (an epoch) when it persists a BUSY home, and
# removes it only on that home's next idle bake. A home that is deleted first
# (every smoke-test home, or an operator's deleted user home) never bakes again,
# so its marker stayed until reboot — 52 of them on .4 on 2026-09-17.
# A marker whose epoch is older than the cooldown means exactly the same to
# storage_ops.sh as no marker at all (`now - last >= cooldown` either way), so
# unlinking an EXPIRED one can never change a bake decision. A live one is kept.
# $1 (test seam) overrides the directory. Called from _op_reconcile.
_bake_cooldown_marker_sweep() {
    local dir="${1:-/tmp/unraid-aicliagents}"
    [ -d "$dir" ] || return 0
    local cooldown="${AICLI_BUSY_BAKE_COOLDOWN_SEC:-1800}"
    case "$cooldown" in ''|*[!0-9]*) cooldown=1800 ;; esac
    local now f last
    now=$(date +%s)
    for f in "$dir"/.bake_busy_cooldown_*; do
        [ -f "$f" ] || continue
        last=$(cat "$f" 2>/dev/null || echo 0)
        # storage_ops.sh reads a non-numeric marker as 0, i.e. as expired.
        case "$last" in ''|*[!0-9]*) last=0 ;; esac
        if [ $(( now - last )) -ge "$cooldown" ]; then
            rm -f "$f"
        fi
    done
    return 0
}

# _events_ledger_sweep — 1a.7 (PLUGIN_EVENT_LEDGER_AND_SUBSCRIPTIONS.md):
# unlink the oldest event-ledger chunk(s) while more than the configured cap
# remain. EventLedger writes 1 000-event chunks named by the seq of their
# first event (see EventLedger::CHUNK_SIZE); this never rewrites a chunk,
# only unlinks whole ones, so the overshoot is at most one chunk. Cheap: a
# sorted glob and a count, no PHP child. Called from _op_reconcile.
# $1 (test seam) overrides the ledger directory; default is the real one.
_events_ledger_sweep() {
    local dir="${1:-/tmp/unraid-aicliagents/events}"
    [ -d "$dir" ] || return 0

    local max_events="${EVENT_LEDGER_MAX_EVENTS:-20000}"
    case "$max_events" in ''|*[!0-9]*) max_events=20000 ;; esac
    local chunk_size=1000
    local max_chunks=$(( (max_events + chunk_size - 1) / chunk_size ))
    [ "$max_chunks" -ge 1 ] || max_chunks=1

    local sorted f base
    sorted=$(
        for f in "$dir"/[0-9]*.jsonl; do
            [ -f "$f" ] || continue
            base="$(basename "$f" .jsonl)"
            case "$base" in ''|*[!0-9]*) continue ;; esac
            printf '%s %s\n' "$base" "$f"
        done | sort -n -k1,1
    )
    [ -n "$sorted" ] || return 0

    local count
    count=$(printf '%s\n' "$sorted" | grep -c .)
    local excess=$(( count - max_chunks ))
    [ "$excess" -gt 0 ] || return 0

    local _seq path
    while IFS=' ' read -r _seq path; do
        [ -n "$path" ] || continue
        rm -f "$path" 2>/dev/null || true
        log_info "Events ledger sweep: unlinked $path (over cap: $count chunks > $max_chunks)"
    done < <(printf '%s\n' "$sorted" | head -n "$excess")
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
    # #372 (HOME_STORAGE_LIFECYCLE.md "2026-09-30 — the live mount wins"): walk
    # the upper each home REALLY uses (home_uppers_live: the live mount's own
    # upper, then unmounted zram uppers), not every zram folder. A zram folder
    # whose home is mounted on a disk upper is not the live one, and measuring
    # it read 0 while the real changes sat elsewhere. This is RAM pressure, so
    # only an upper in zram counts; a disk upper uses no RAM and the scheduled
    # bake (_check_schedule_trigger) saves it.
    local entity_type="home" entity_id upper_dir du_bytes
    while IFS=$'\t' read -r entity_id upper_dir; do
        [ -n "$entity_id" ] && [ -n "$upper_dir" ] || continue
        case "$upper_dir" in "$zram_base"/*) : ;; *) continue ;; esac
        [ -d "$upper_dir" ] || continue
        du_bytes=$(du -sb "$upper_dir" 2>/dev/null | awk '{print $1}' || echo 0)
        [ "${du_bytes:-0}" -gt 0 ] || continue
        total_bytes=$(( total_bytes + du_bytes ))
        entity_sizes+=("${du_bytes}:${entity_type}:${entity_id}")
    done < <(home_uppers_live 2>/dev/null)

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
            _auto_bake_enqueue 50 "$etype" "$eid" "dirty_pressure_critical" || true
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
            _auto_bake_enqueue 50 "$etype" "$eid" "dirty_pressure_hard" || true
        done <<< "$sorted_entities"

    elif [ "$total_bytes" -ge "$soft_bytes" ]; then
        # Soft: enqueue bake for every dirty entity, largest first, lz4 compression
        lifecycle_log "info" "supervisor" "dirty_pressure_soft" \
            "{\"total_bytes\":$total_bytes,\"threshold_bytes\":$soft_bytes}" 2>/dev/null || true

        local sorted_entities
        sorted_entities=$(printf '%s\n' "${entity_sizes[@]:-}" | sort -t: -k1 -rn 2>/dev/null || true)
        while IFS=: read -r du_b etype eid; do
            [ -n "$etype" ] && [ -n "$eid" ] || continue
            _auto_bake_enqueue 50 "$etype" "$eid" "dirty_pressure_soft" || true
        done <<< "$sorted_entities"
    fi
}

# ---------------------------------------------------------------------------
# #374 (HOME_STORAGE_LIFECYCLE.md "2026-09-30 — the SQLite stage folder"):
# backoff for automatic bakes that do not save.
#
# On .4 (2026-09-30) the root home's scheduled bake ended without a save every
# time, and the schedule enqueued it again on each tick (every 30 s to 3 min),
# so each try copied 2.5 GB of databases again. Now a bake result that did not
# save (a failure, or an exit 2 that wrote no layer) makes the AUTOMATIC
# triggers (schedule, dirty pressure) wait: BAKE_BACKOFF_BASE_S, doubled after
# each further miss, at most BAKE_BACKOFF_CAP_S. A saved result clears the
# wait. The log says it once per state change: when the wait starts or its
# reason changes, and when a save clears it. A person's Persist and the other
# explicit callers are never held back.
# ---------------------------------------------------------------------------

# The bake defer reasons that wrote NO layer. KEEP IN SYNC with
# AICLI_NOT_SAVED_DEFER_REASONS in src/scripts/storage/common.sh (the
# supervisor does not source common.sh; tests/unit/stage_leak_test.sh checks).
SUP_NOT_SAVED_DEFER_REASONS="bake_lock_held sqlite_backup_deferred no_space"

# _bake_result_saved <exit> <defer_reason> — 0 when the changes are on durable
# storage: exit 0, or exit 2 with a reason that still wrote the layer.
_bake_result_saved() {
    local ex="${1:-1}" reason="${2:-}"
    [ "$ex" = "0" ] && return 0
    [ "$ex" = "2" ] || return 1
    case " $SUP_NOT_SAVED_DEFER_REASONS " in *" $reason "*) return 1 ;; esac
    return 0
}

# _bake_backoff_file <type> <id> — the backoff state file ("<next_epoch>
# <misses> <reason>"). Read at call time: tests move SUPERVISOR_DIR.
_bake_backoff_file() {
    printf '%s/bake-backoff/%s_%s\n' "$SUPERVISOR_DIR" "${1:-}" "$(printf '%s' "${2:-}" | tr -c 'A-Za-z0-9_.-' '_')"
}

# _bake_backoff_active <type> <id> [now] — 0 while the entity waits out a backoff.
_bake_backoff_active() {
    local f next now="${3:-}"
    [ -n "$now" ] || now="$(date +%s)"
    f="$(_bake_backoff_file "$1" "$2")"
    [ -f "$f" ] || return 1
    read -r next _ _ < "$f" 2>/dev/null || return 1
    case "$next" in ''|*[!0-9]*) return 1 ;; esac
    [ "$now" -lt "$next" ]
}

# _bake_backoff_record <type> <id> <exit> <defer_reason> [now] — after each bake.
_bake_backoff_record() {
    local type="${1:-}" id="${2:-}" ex="${3:-1}" reason="${4:-}" now="${5:-}"
    local f entity prev_misses=0 prev_reason="" misses wait base cap i
    [ -n "$type" ] && [ -n "$id" ] || return 0
    [ -n "$now" ] || now="$(date +%s)"
    entity="$type/$id"
    f="$(_bake_backoff_file "$type" "$id")"
    [ -f "$f" ] && read -r _ prev_misses prev_reason < "$f" 2>/dev/null
    case "$prev_misses" in ''|*[!0-9]*) prev_misses=0 ;; esac
    if _bake_result_saved "$ex" "$reason"; then
        if [ -f "$f" ]; then
            rm -f "$f" 2>/dev/null || true
            log_info "Automatic saves of $entity run on the normal schedule again: this save completed after $prev_misses save(s) that did not."
            lifecycle_log "info" "supervisor" "bake_backoff_cleared" \
                "{\"entity\":\"$entity\",\"misses\":$prev_misses}" 2>/dev/null || true
        fi
        return 0
    fi
    [ -n "$reason" ] || reason="exit_$ex"
    base="$BAKE_BACKOFF_BASE_S"; cap="$BAKE_BACKOFF_CAP_S"
    case "$base" in ''|*[!0-9]*) base=120 ;; esac
    case "$cap" in ''|*[!0-9]*) cap=1800 ;; esac
    [ "$base" -ge 1 ] || base=1
    [ "$cap" -ge "$base" ] || cap="$base"
    misses=$((prev_misses + 1))
    wait="$base"
    i=1
    while [ "$i" -lt "$misses" ] && [ "$wait" -lt "$cap" ]; do
        wait=$((wait * 2)); i=$((i + 1))
    done
    [ "$wait" -le "$cap" ] || wait="$cap"
    mkdir -p "${f%/*}" 2>/dev/null || true
    printf '%s %s %s\n' "$((now + wait))" "$misses" "$reason" > "$f" 2>/dev/null || true
    if [ "$prev_misses" -eq 0 ] || [ "$reason" != "$prev_reason" ]; then
        log_warn "Automatic saves of $entity wait ${wait}s: the last save did not complete (reason=$reason). While this repeats, the wait doubles up to ${cap}s."
        lifecycle_log "warn" "supervisor" "bake_backoff" \
            "{\"entity\":\"$entity\",\"reason\":\"$reason\",\"misses\":$misses,\"wait_s\":$wait,\"cap_s\":$cap}" 2>/dev/null || true
    fi
    return 0
}

# _auto_bake_queued <type> <id> — 0 when a bake of this entity is in the queue.
_auto_bake_queued() {
    local safe_id f
    safe_id="$(printf '%s' "${2:-}" | tr '/ ' '__')"
    for f in "${QUEUE_DIR}"/[0-9]*_[0-9]*_"${1:-}"_"${safe_id}"_bake.req; do
        [ -f "$f" ] && return 0
    done
    return 1
}

# _auto_bake_enqueue <priority> <type> <id> <reason> — enqueue an AUTOMATIC
# bake. Returns 1 (and enqueues nothing) while the entity waits out a backoff
# or already has a bake in the queue.
_auto_bake_enqueue() {
    local prio="${1:-99}" type="${2:-}" id="${3:-}" reason="${4:-schedule}"
    [ -n "$type" ] && [ -n "$id" ] || return 1
    _bake_backoff_active "$type" "$id" && return 1
    _auto_bake_queued "$type" "$id" && return 1
    queue_enqueue "$prio" "$type" "$id" "bake" "$reason" 2>/dev/null || true
    return 0
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
            upper_dir="$(_entity_upper_for_supervisor "$type" "$id" 2>/dev/null || true)"   # #372: the live upper (zram or disk)
            # #374: _auto_bake_enqueue skips a home that waits out a backoff
            # (its last bake did not save) or already has a bake in the queue.
            if [ -d "$upper_dir" ] && [ -n "$(ls -A "$upper_dir" 2>/dev/null)" ]; then
                if _auto_bake_enqueue 99 "$type" "$id" "schedule"; then
                    lifecycle_log "info" "supervisor" "schedule_bake_enqueued" \
                        "{\"entity\":\"$entity\",\"age_s\":$age,\"threshold_s\":$schedule_interval}" 2>/dev/null || true
                fi
            fi
        fi
    done <<< "$entity_data"
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
_policy_consolidate_home_is_idle() {
    local id="${1:-}" mnt
    mnt="$(home_mount "$id" 2>/dev/null || true)"
    ! _supervisor_overlay_busy "$mnt"
}

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

        # Policy maintenance waits for natural idle. Explicit user consolidation
        # has its own confirmed close/resume path and does not come through here.
        if ! _policy_consolidate_home_is_idle "$id"; then
            log_info "Consolidate policy: waiting for home/$id to become idle"
            continue
        fi

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
# WP #1262 / issue #44: reclaim-wait notification.
#
# The reclaim/consolidate-at-idle path only runs when a home is idle (the
# post-bake reclaim and _check_consolidate_policy both defer on a live session).
# A permanently-connected session would therefore defer reclaim FOREVER, letting
# the ZRAM upper grow unbounded and the delta stack march to the hard ceiling.
# When a home is BUSY (live session) AND storagectl recommends consolidation,
# notify the user and wait. Automatic maintenance never closes sessions; the home
# reaches idle only through a user close or an explicit confirmed workflow.
# Below threshold or while idle, no warning is needed. This path is only active
# when consolidation is actually recommended, never for a routine bake.
# ---------------------------------------------------------------------------

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

# Compatibility close bridge retained for explicit callers and its injection
# contract. Automatic maintenance never invokes it (issue #44).
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
    # docs/specs/EVENT_FIRST_RECONCILIATION.md 1b.4: the consolidate marker
    # just cleared — publish so the maintenance banner drops without a poll.
    _publish_storage_status
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
            // CONSOLIDATE_RELAUNCH_CLOSED_SET.md (2026-09-17): send one Continue to
            // each resumed session that was working, exactly as an upgrade/reload
            // relaunch does, when auto_continue_on_restart is on. Mirrors the
            // backup relaunch (_backup_relaunch_home).
            $nudger = null; $ready = null;
            $cfg = function_exists("getAICliConfig") ? getAICliConfig() : [];
            $continueOn = ((string)($cfg["auto_continue_on_restart"] ?? "1")) !== "0";
            if ($continueOn && class_exists("\AICliAgents\Services\TmuxService")) {
                $nudger = ["\AICliAgents\Services\TmuxService", "submitContinueNudge"];
                $ready  = ["\AICliAgents\Services\TmuxService", "paneAcceptsInput"];
            }
            \AICliAgents\Services\UpgradeRelaunchService::relaunchHomeSet(
                (string)getenv("AICLI_RELAUNCH_USER"), null, null, null, null, $nudger, $ready, null, "consolidate_relaunch"
            );
        }
    ' ) 2>/dev/null || true
    # docs/specs/EVENT_FIRST_RECONCILIATION.md 1b.4: the consolidate marker
    # just cleared here too — publish so the maintenance banner drops without
    # waiting for a poll.
    _publish_storage_status
}

# The wait-state machine. See block comment above. Stub-overridable helpers:
# _now_epoch, _manifest_home_ids, home_persist_path, home_mount,
# _home_reclaim_recommended and _supervisor_overlay_busy.
_check_force_reclaim_escalation() {
    local esc_dir="${SUPERVISOR_DIR}/escalation"

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
        if [ -n "$mnt" ] && _supervisor_overlay_busy "$mnt"; then busy=1; fi

        if [ "$recommended" -eq 1 ] && [ "$busy" -eq 1 ]; then
            if [ ! -f "$state_file" ]; then
                # Record the wait + notify the user once.
                mkdir -p "$esc_dir" 2>/dev/null || true
                _atomic_json_write "$state_file" \
                    "$(printf '{"entity":"home/%s","reason":"%s","started_at":%d,"state":"waiting"}' \
                        "$id" "$_RECLAIM_REASON" "$now")" || true
                _supervisor_notify "warning" "AICliAgents: storage maintenance waiting" \
                    "Home '$id' needs storage maintenance but has live sessions. Maintenance will wait; close those sessions when convenient." \
                    "force_reclaim_${id}" 3600
                lifecycle_log "warn" "supervisor" "reclaim_waiting_for_idle" \
                    "{\"entity\":\"home/$id\",\"reason\":\"$_RECLAIM_REASON\"}" 2>/dev/null || true
                log_info "Storage maintenance waiting for home/$id to become idle (reason=$_RECLAIM_REASON)"
                # docs/specs/EVENT_FIRST_RECONCILIATION.md 1b.4: the maintenance
                # marker just appeared — publish it on aicli_storage_status.
                _publish_storage_status
            else
                # Normalize an old countdown/closing state left in /tmp by a
                # previous plugin version. It must not retain auto-close semantics.
                local prior_state
                prior_state="$(sed -n 's/.*"state":"\([^"]*\)".*/\1/p' "$state_file" 2>/dev/null | head -1)"
                if [ "$prior_state" != "waiting" ]; then
                    _atomic_json_write "$state_file" \
                        "$(printf '{"entity":"home/%s","reason":"%s","started_at":%d,"state":"waiting"}' \
                            "$id" "$_RECLAIM_REASON" "$now")" || true
                    _publish_storage_status
                fi
            fi
        else
            # Condition no longer holds (home went idle, or pressure relieved):
            # Stand down once the home is idle or pressure has cleared.
            if [ -f "$state_file" ]; then
                rm -f "$state_file" 2>/dev/null || true
                lifecycle_log "info" "supervisor" "force_reclaim_cleared" \
                    "{\"entity\":\"home/$id\",\"recommended\":$recommended,\"busy\":$busy}" 2>/dev/null || true
                log_info "Force-reclaim stood down for home/$id (recommended=$recommended busy=$busy)"
                # docs/specs/EVENT_FIRST_RECONCILIATION.md 1b.4: the marker just
                # cleared — publish so a stale "waiting" banner does not outlive it.
                _publish_storage_status
            fi
        fi
    done <<< "$ids"
}

# #72 — complete a deferred agent upgrade only after storagectl has activated
# the newly baked layer. The relaunch service consumes the exact closed set and
# removes its manifest; setInstallStatus(100) is deliberately last so browsers
# cannot reopen a retired ttyd during the activation gap.
# True while ANY activation barrier exists for the agent: a retained closed set
# (sessions to relaunch) OR a pending-activation record (a baked layer that is
# not live yet, written by install-bg when nothing was closed — see
# UPGRADE_ACTIVATION_WITHOUT_CLOSED_SET.md). Before that record existed an
# upgrade with no session to close was dropped here as "superseded" and its new
# layer never activated.
_has_pending_agent_upgrade() {
    local agent_id="$1"
    case "$agent_id" in ''|*[!a-z0-9-]*) return 1 ;; esac
    [ "$(command -v php)" ] || return 1
    AICLI_RELAUNCH_AGENT="$agent_id" php -d display_errors=0 -r '
        $_SERVER["DOCUMENT_ROOT"]="/usr/local/emhttp";
        require_once "/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/AICliAgentsManager.php";
        exit(\AICliAgents\Services\UpgradeRelaunchService::activationPending(
            (string)getenv("AICLI_RELAUNCH_AGENT")
        ) ? 0 : 1);
    ' >/dev/null 2>&1
}

_relaunch_pending_agent_upgrade() {
    local agent_id="$1"
    case "$agent_id" in ''|*[!a-z0-9-]*) return 1 ;; esac
    [ "$(command -v php)" ] || return 1

    ( [ -n "${SUP_LOCK_FD:-}" ] && exec {SUP_LOCK_FD}>&- 2>/dev/null
      AICLI_RELAUNCH_AGENT="$agent_id" php -d display_errors=0 -r '
        $_SERVER["DOCUMENT_ROOT"]="/usr/local/emhttp";
        require_once "/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/AICliAgentsManager.php";
        $agent = (string)getenv("AICLI_RELAUNCH_AGENT");
        $hasClosedSet = \AICliAgents\Services\UpgradeRelaunchService::hasPendingAgentUpgrade($agent);
        $hasPending   = \AICliAgents\Services\UpgradeRelaunchService::hasPendingActivation($agent);
        if (!$hasClosedSet && !$hasPending) exit(0);
        // 2026-09-03 wedge: the manifest is written at session-close time, so it
        // exists while install-bg is still running. Consuming it then relaunches
        // the closed set onto the OLD binary mid-install and strands the upgrade
        // at 99%. Leave the manifest for the post-install activation instead.
        if (\AICliAgents\Services\UpgradeRelaunchService::activationBlocked($agent)) {
            \AICliAgents\Services\LifecycleLogService::log(
                \AICliAgents\Services\LifecycleLogService::LEVEL_INFO,
                "supervisor", "upgrade_activation_deferred_install_running",
                ["agent" => $agent]
            );
            exit(0);
        }
        // The mount succeeded, so the newest layer is live now. Relaunch the
        // closed set when there is one, then publish 100% exactly once and clear
        // the pending-activation record (UPGRADE_ACTIVATION_WITHOUT_CLOSED_SET.md:
        // an upgrade that closed nothing has nothing to relaunch but still needs
        // this completion — it finishes the tray entry parked in `waiting`).
        if ($hasClosedSet) {
            $result = \AICliAgents\Services\UpgradeRelaunchService::relaunchClosedSet($agent);
            \AICliAgents\Services\LifecycleLogService::log(
                \AICliAgents\Services\LifecycleLogService::LEVEL_INFO,
                "installer", "deferred_upgrade_relaunch_complete",
                ["agent"=>$agent, "relaunched"=>$result["relaunched"], "skipped"=>$result["skipped"]]
            );
        }
        \AICliAgents\Services\UpgradeRelaunchService::completeActivation($agent);
    ' ) 2>/dev/null
}

# #71: start safely queued upgrades only after all sessions have closed on
# their own. Run in a subshell and close the supervisor lifetime lock there so
# a spawned install job can never inherit and pin the singleton lock.
_check_queued_agent_upgrades() {
    [ "$(command -v php)" ] || return 0
    (
        if [ -n "${SUP_LOCK_FD:-}" ]; then
            exec {SUP_LOCK_FD}>&-
        fi
        timeout 15 php -d display_errors=0 -r '
            $_SERVER["DOCUMENT_ROOT"]="/usr/local/emhttp";
            require_once "/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/AICliAgentsManager.php";
            \AICliAgents\Services\PendingAgentUpgradeService::processAllReady();
        ' >/dev/null 2>&1
    ) || true
}

# Self-heal the crash window between install-bg retaining a manifest and
# enqueuing its activation job. A stable job id plus queue/retry checks makes
# this idempotent; mount_busy retries live in the supervisor retry pen.
# 2026-09-03 wedge: a manifest also exists while its install-bg is still
# RUNNING (it is written at session-close time). activationBlocked() filters
# those out so a live install is never mistaken for crash residue — enqueueing
# activation then relaunches the closed set onto the old binary mid-install.
_check_pending_agent_upgrades() {
    [ "$(command -v php)" ] || return 0
    local ids
    ids="$(php -d display_errors=0 -r '
        $_SERVER["DOCUMENT_ROOT"]="/usr/local/emhttp";
        require_once "/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/AICliAgentsManager.php";
        // Closed-set manifests, plus pending-activation records without a closed
        // set (UPGRADE_ACTIVATION_WITHOUT_CLOSED_SET.md). The reconcile completes
        // any record whose layer is already live instead of queueing a mount
        // that a live session would make defer forever.
        $ids = \AICliAgents\Services\UpgradeRelaunchService::pendingAgentIds();
        foreach (\AICliAgents\Services\UpgradeRelaunchService::reconcilePendingActivations() as $id) $ids[] = $id;
        foreach (array_values(array_unique($ids)) as $id) {
            if (\AICliAgents\Services\UpgradeRelaunchService::activationBlocked($id)) continue;
            echo $id, PHP_EOL;
        }
    ' 2>/dev/null)"
    [ -n "$ids" ] || return 0

    local id safe job_id
    while IFS= read -r id; do
        case "$id" in ''|*[!a-z0-9-]*) continue ;; esac
        safe="${id//[^A-Za-z0-9._-]/_}"
        job_id="upgrade-agent-$safe"
        [ -f "$JOB_RETRY_DIR/${job_id}.retry" ] && continue
        # #350: a halted activation waits for a session close or a new install.
        [ "$(_ua_state_get "$job_id" halted)" = "1" ] && continue
        if ls "$QUEUE_DIR"/*_agent_${safe}_mount.req >/dev/null 2>&1; then
            continue
        fi
        queue_enqueue 1 agent "$id" mount upgrade_relaunch "" "$job_id" >/dev/null 2>&1 || true
    done <<< "$ids"
}

# #86: browser-independent restart of saved drawer workspaces. The PHP service
# owns the grace period, upgrade barriers and bounded retry state.
_check_saved_workspace_restarts() {
    [ "$(command -v php)" ] || return 0
    local reconciler="/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/supervisor/reconcile-autolaunch.php"
    [ -f "$reconciler" ] || return 0
    (
        if [ -n "${SUP_LOCK_FD:-}" ]; then
            exec {SUP_LOCK_FD}>&-
        fi
        timeout 20 php -d display_errors=0 "$reconciler" >/dev/null 2>&1
    ) || true
}

# docs/specs/EVENT_FIRST_RECONCILIATION.md "Rules for the supervisor tick":
# a 30 s sweep runs ActivityService::sweep() through a PHP child (same pattern
# as _check_saved_workspace_restarts above) — the watchdog (stall/timeout/
# done-pruning), the orphan storage-job finish, and the stale install-marker
# reap now all run with no browser open at all. Rate-limited with a last-run
# epoch, like _relay_drain_tick; bounded with `timeout` so it can never stall
# the work tick.
_ACTIVITY_SWEEP_LAST=0
_activity_sweep_tick() {
    [ "$(command -v php)" ] || return 0
    local now; now=$(date +%s)
    [ $(( now - _ACTIVITY_SWEEP_LAST )) -ge 30 ] || return 0
    _ACTIVITY_SWEEP_LAST="$now"
    local sweeper="/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/supervisor/activity-sweep.php"
    [ -f "$sweeper" ] || return 0
    (
        if [ -n "${SUP_LOCK_FD:-}" ]; then
            exec {SUP_LOCK_FD}>&-
        fi
        timeout 20 php -d display_errors=0 "$sweeper" >/dev/null 2>&1
    ) || true
}

# SCHEDULED_CONTINUE.md (#234): fire due scheduled Continues. Same 30s cadence and
# lock-drop + timeout wrapper as _activity_sweep_tick (never stall the queue).
_SCHEDULED_CONTINUE_LAST=0
_scheduled_continue_tick() {
    [ "$(command -v php)" ] || return 0
    local now; now=$(date +%s)
    [ $(( now - _SCHEDULED_CONTINUE_LAST )) -ge 30 ] || return 0
    _SCHEDULED_CONTINUE_LAST="$now"
    local ticker="/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/supervisor/scheduled-continue.php"
    [ -f "$ticker" ] || return 0
    (
        if [ -n "${SUP_LOCK_FD:-}" ]; then
            exec {SUP_LOCK_FD}>&-
        fi
        timeout 20 php -d display_errors=0 "$ticker" >/dev/null 2>&1
    ) || true
}

# SCHEDULED_CONTINUE.md / Forgejo #288: inspect every running terminal for a
# verified provider quota message, not only the browser's selected workspace.
# The PHP service has its own last-poll guard as well, so a duplicate wake or a
# second supervisor invocation cannot turn this into a faster capture loop.
_QUOTA_DETECT_LAST=0
_quota_detect_tick() {
    [ "$(command -v php)" ] || return 0
    local now; now=$(date +%s)
    [ $(( now - _QUOTA_DETECT_LAST )) -ge 30 ] || return 0
    _QUOTA_DETECT_LAST="$now"
    local detector="/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/supervisor/quota-detect.php"
    [ -f "$detector" ] || return 0
    (
        if [ -n "${SUP_LOCK_FD:-}" ]; then
            exec {SUP_LOCK_FD}>&-
        fi
        timeout 20 php -d display_errors=0 "$detector" >/dev/null 2>&1
    ) || true
}

# ---------------------------------------------------------------------------
# Superseded agent version sweep (SIDE_BY_SIDE_AGENT_INSTALLS.md Phase 3, R4)
# ---------------------------------------------------------------------------
# Since Phase 3 an agent upgrade leaves the previous version mounted for as long
# as a session is still running it. op_mount sweeps on every activation and on
# every launch of an already-current agent, which covers the common case — but
# both of those need SOMETHING to happen. A box where the last workspace of an
# upgraded agent is closed and nothing is opened afterwards would hold that
# version's overlay and its writable layer indefinitely, and on this hardware
# that is 255-450 MB of a flash device with roughly 6 GB free.
#
# So the supervisor sweeps too, every 5 minutes, with no browser open and
# nothing else happening. The sweep only ever releases a version that no live
# process names, so an idle tick on a healthy box does nothing and costs one
# /proc scan per installed agent.
_AGENT_GEN_SWEEP_LAST=0
_AGENT_GEN_SWEEP_PID=""
# The sweep runs in the BACKGROUND (2026-09-24). Since #318 it also covers
# plain-folder agents, whose release step is a real umount with its own
# timeout, and it scans /proc once per agent. Run inline, the first sweep —
# which fires on the first tick after a supervisor start — held the work loop
# long enough that a queued job was not picked up within 20 s (release-gate
# smoke 49, twice). A sweep still running when the next one is due is skipped.
_agent_generation_sweep_tick() {
    local now; now=$(date +%s)
    [ $(( now - _AGENT_GEN_SWEEP_LAST )) -ge 300 ] || return 0
    if [ -n "$_AGENT_GEN_SWEEP_PID" ] && kill -0 "$_AGENT_GEN_SWEEP_PID" 2>/dev/null; then
        return 0
    fi
    _AGENT_GEN_SWEEP_LAST="$now"
    _agent_generation_sweep_run &
    _AGENT_GEN_SWEEP_PID=$!
    return 0
}

_agent_generation_sweep_run() {
    # A background child must not keep the supervisor's singleton lock alive.
    [ -n "${SUP_LOCK_FD:-}" ] && exec {SUP_LOCK_FD}>&- 2>/dev/null

    declare -f aicli_gc_agent_generations >/dev/null 2>&1 || return 0
    declare -f agent_base >/dev/null 2>&1 || return 0

    local versions_root agent_dir agent_id persist seen=" "
    versions_root="$(agent_base)/.versions"
    persist="$(agent_persist_path 2>/dev/null)" || return 0
    [ -n "$persist" ] || return 0

    # 2026-09-24 (#318): a plain-directory agent on the old layout has version
    # directories on flash but no agents/.versions/<id> yet; list both roots.
    for agent_dir in "$versions_root"/*/ "$persist/passthrough/agents/.versions"/*/; do
        [ -d "$agent_dir" ] || continue
        agent_id="$(basename -- "${agent_dir%/}")"
        case "$agent_id" in .*) continue ;; esac
        case "$seen" in *" $agent_id "*) continue ;; esac
        seen="$seen$agent_id "
        # Called directly, in THIS shell. `timeout bash -c '...'` would start a
        # new shell with none of these functions defined: it would exit 127 and,
        # with the failure swallowed, the sweep would quietly never run. The
        # umount inside the reaper carries its own timeout instead, which is the
        # only step that can block.
        aicli_gc_agent_generations "$agent_id" "$persist" 0 >/dev/null 2>&1 || true
    done

    # #338 (SIDE_BY_SIDE_AGENT_INSTALLS.md "2026-09-26 — #338 agent layer
    # retention", migration 2): the one-time consolidation of an IDLE layered
    # agent's long stack into one base. At most one agent per sweep — each is a
    # full package write — and a failed one waits 6 hours before it is tried
    # again (aicli_agent_rebase_wanted reads the marker).
    declare -f aicli_agent_rebase_wanted >/dev/null 2>&1 || return 0
    # Smallest package first (the largest layer of its stack estimates it), so
    # each pack frees room for the next; storagectl itself refuses a pack whose
    # base would not fit (exit 2, reason no_space) — that is not a failure.
    local due="" est rc f b
    for agent_dir in "$versions_root"/*/; do
        [ -d "$agent_dir" ] || continue
        agent_id="$(basename -- "${agent_dir%/}")"
        case "$agent_id" in .*) continue ;; esac
        aicli_agent_rebase_wanted "$agent_id" "$persist" >/dev/null 2>&1 || continue
        est=0
        for f in "$persist"/agent_"${agent_id}"_*.sqsh; do
            [ -f "$f" ] || continue
            b="$(stat -c '%s' "$f" 2>/dev/null || echo 0)"
            [ "$b" -gt "$est" ] 2>/dev/null && est="$b"
        done
        due="$due$est $agent_id"$'\n'
    done
    agent_id="$(printf '%s' "$due" | grep . | sort -n | head -1 | awk '{print $2}')"
    [ -n "$agent_id" ] || return 0
    timeout 1800 bash "${STORAGE_DIR}/storagectl.sh" rebase --type agent --id "$agent_id" --persist "$persist" >/dev/null 2>&1
    rc=$?
    if [ "$rc" -ne 0 ] && [ "$rc" -ne 2 ]; then
        touch "/tmp/unraid-aicliagents/.agent_rebase_failed_${agent_id}" 2>/dev/null || true
    fi
    return 0
}

# ---------------------------------------------------------------------------
# Relay DM re-drain — deliver any deferred direct-message notice to live sessions
# ---------------------------------------------------------------------------
# The plugin queues a Relay DM notice when the recipient pane is mid-decision (a
# menu / unsent input), then re-delivers it on the drawer poll. That poll only
# runs while a Manager browser tab is FOCUSED — background tabs throttle their
# timers — so a notice to a live but backgrounded workspace waited until the user
# opened it. This server-side tick re-drains regardless of any browser.
# docs/specs/EVENT_FIRST_RECONCILIATION.md 1b.7: rate-limited to the tick rate
# (5 s) while any pending queue file exists, 30 s otherwise — enqueuePendingRelay
# also wakes the supervisor on arrival, so this cadence is the backstop, not the
# only path to a fast drain. Runs the plugin's own relay-agent.php drain-all
# (readiness-gated per session); best-effort with a hard timeout so it can
# NEVER stall the work tick.

# _relay_pending_exists — true when at least one session has a non-empty
# deferred-relay queue file. Mirrors TmuxService::relayPendingDir(): a
# per-user path under AICLI_RELAY_STATE_DIR when the test/CLI hook is set,
# else a glob across every user's own relay/pending dir (this box is normally
# single-tenant, but the glob costs nothing when there is only one match).
_relay_pending_exists() {
    local f
    if [ -n "${AICLI_RELAY_STATE_DIR:-}" ]; then
        for f in "${AICLI_RELAY_STATE_DIR}"/pending/*.json; do
            [ -f "$f" ] && return 0
        done
        return 1
    fi
    for f in /tmp/unraid-aicliagents/work/*/home/relay/pending/*.json; do
        [ -f "$f" ] && return 0
    done
    return 1
}

_RELAY_DRAIN_LAST=0
_relay_drain_tick() {
    [ "$(command -v php)" ] || return 0
    local now interval; now=$(date +%s); interval=30
    _relay_pending_exists && interval=5
    [ $(( now - _RELAY_DRAIN_LAST )) -ge "$interval" ] || return 0
    _RELAY_DRAIN_LAST="$now"
    timeout 20 php -d display_errors=0 \
        "/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/relay-agent.php" drain-all \
        >/dev/null 2>&1 || true
}

# ---------------------------------------------------------------------------
# Relay linked boxes — send each peer's outbox, refresh hello + contacts
# ---------------------------------------------------------------------------
# docs/specs/RELAY_LINKED_BOXES.md §3. Does nothing until a box is paired: the
# peer file on flash holds no "peer_" row. Network calls can take up to 10 s
# each, so the sync runs in the background with a hard timeout, and a new run
# starts only when the previous one has ended. It never stalls the work tick.
_RELAY_PEER_LAST=0
_RELAY_PEER_PID=""
_relay_peer_tick() {
    [ "$(command -v php)" ] || return 0
    local peers="${AICLI_RELAY_PEERS_FILE:-/boot/config/plugins/unraid-aicliagents/relay-peers.json}"
    [ -s "$peers" ] || return 0
    grep -q '"peer_' "$peers" 2>/dev/null || return 0
    local now; now=$(date +%s)
    [ $(( now - _RELAY_PEER_LAST )) -ge 15 ] || return 0
    if [ -n "$_RELAY_PEER_PID" ] && kill -0 "$_RELAY_PEER_PID" 2>/dev/null; then return 0; fi
    _RELAY_PEER_LAST="$now"
    timeout 60 php -d display_errors=0 \
        "/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/relay-agent.php" peer-sync \
        >/dev/null 2>&1 &
    _RELAY_PEER_PID=$!
}

# ---------------------------------------------------------------------------
# Relay HTTP listener ownership audit (#331)
# ---------------------------------------------------------------------------
# A listener from an older plugin generation kept 0.0.0.0:8237 open for 8 days
# on .4 while the setting said "off", because only the listener named in the
# pidfile was ever stopped. Every 30 s, relay-http-up.sh audit scans /proc for
# this plugin's relay-http-server.php processes (any generation) and stops each
# one that is not the current, wanted listener. It is bash only: with no
# listener running it is one /proc scan. #337: when the listener is turned on
# and none runs (it crashed, or someone stopped it), the audit starts it again,
# detached, and logs the restart; a failed restart backs off for 5 minutes.
# Exit 4 means the wanted state is not known yet (first tick after a boot or an
# update from an older version), so the PHP reconcile reads the settings once
# and records it. The lock fd is dropped first, as in
# _check_saved_workspace_restarts; the spawn helper also closes every other
# inherited fd, so a listener this starts holds none of the supervisor's.
_RELAY_HTTP_AUDIT_LAST=0
_relay_http_audit_tick() {
    local now; now=$(date +%s)
    [ $(( now - _RELAY_HTTP_AUDIT_LAST )) -ge 30 ] || return 0
    _RELAY_HTTP_AUDIT_LAST="$now"
    local up="${AICLI_RELAY_HTTP_UP_SCRIPT:-/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/relay-http-up.sh}"
    [ -f "$up" ] || return 0
    (
        if [ -n "${SUP_LOCK_FD:-}" ]; then
            exec {SUP_LOCK_FD}>&-
        fi
        timeout 20 bash "$up" audit >/dev/null 2>&1
        if [ "$?" = "4" ] && command -v php >/dev/null 2>&1; then
            timeout 30 php -d display_errors=0 \
                "/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/relay-agent.php" http-listener-reconcile \
                >/dev/null 2>&1
        fi
    ) || true
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

    # Step 1a (#71): admit non-destructive queued upgrades at a session-free
    # boundary. This raises the install barrier before its second empty check.
    _check_queued_agent_upgrades

    # Step 1b (#72): ensure every retained agent-upgrade closed set has one
    # backoff-managed activation job, even if install-bg crashed after writing it.
    _check_pending_agent_upgrades

    # Step 1c (#86): recreate crashed saved workspaces without needing a browser.
    _check_saved_workspace_restarts

    # Step 1d: EVENT_FIRST_RECONCILIATION.md — the activity watchdog sweep,
    # rate-limited to 30 s internally, runs with no browser open.
    _activity_sweep_tick
    _scheduled_continue_tick
    _quota_detect_tick

    # Step 1e: SIDE_BY_SIDE_AGENT_INSTALLS.md Phase 3 — release superseded agent
    # versions once nothing is running them, rate-limited to 5 min internally.
    _agent_generation_sweep_tick

    # Step 2: Check dirty-pressure thresholds (may enqueue bakes)
    _check_dirty_pressure

    # Step 3: Check schedule trigger (may enqueue bakes)
    _check_schedule_trigger

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
                    backup)
                        _op_backup_home "$req_type" "$req_id" "$req_reason" "$req_job"
                        ;;
                    restore)
                        _op_restore_home "$req_type" "$req_id" "$req_reason" "$req_job"
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
    if _supervisor_start_suppressed; then
        log_info "Supervisor start suppressed by fresh health-smoke marker"
        return 0
    fi

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
        _v=$(grep -oP '^event_ledger_max_events="?\K[^"]*(?="?$)' "$cfg_file" 2>/dev/null | head -1)
        [ -n "$_v" ] && EVENT_LEDGER_MAX_EVENTS="$_v"
        _v=$(grep -oP '^bake_backoff_base_seconds="?\K[^"]*(?="?$)' "$cfg_file" 2>/dev/null | head -1)
        [ -n "$_v" ] && BAKE_BACKOFF_BASE_S="$_v"
        _v=$(grep -oP '^bake_backoff_cap_seconds="?\K[^"]*(?="?$)' "$cfg_file" 2>/dev/null | head -1)
        [ -n "$_v" ] && BAKE_BACKOFF_CAP_S="$_v"
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
        _relay_drain_tick
        _relay_peer_tick
        _relay_http_audit_tick

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

    # #372: a home is flushed from the upper its mount REALLY uses (zram or
    # disk), so a home mounted on a disk upper is not skipped as "clean".
    local _fl_id _fl_upper
    while IFS=$'\t' read -r _fl_id _fl_upper; do
        [ -n "$_fl_id" ] && [ -d "$_fl_upper" ] || continue
        [ -n "$(ls -A "$_fl_upper" 2>/dev/null)" ] || continue
        queue_enqueue 0 "home" "$_fl_id" "bake" "clean_shutdown" 2>/dev/null || true
        flushed_count=$(( flushed_count + 1 ))
        log_info "Flush: enqueued bake for home/${_fl_id} (upper $_fl_upper)"
    done < <(home_uppers_live 2>/dev/null)

    # shellcheck disable=SC2066
    for upper_root in "$zram_base/agents"; do
        [ -d "$upper_root" ] || continue
        local entity_type="agent"

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
            _scrub_test_hook_env
            # #353: exec again without the test variables (see above).
            [ "$_SCRUBBED_TEST_HOOKS" -gt 0 ] && exec bash "$0" start
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
