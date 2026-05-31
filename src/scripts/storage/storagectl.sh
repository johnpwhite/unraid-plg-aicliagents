#!/bin/bash
# storagectl.sh — Single entry point ("seam") for all home/agent storage operations.
#
# Usage:
#   storagectl.sh <verb> --type <home|agent> --id <ID> --persist <PATH> [flags]
#
# Verbs:
#   mount        Assemble the overlay stack for an entity.
#   unmount      Tear down the entity's merged overlay mount.    [--lazy]
#   bake         Commit the upper layer to a durable delta squashfs.
#   consolidate  Merge all layers into a single consolidated squashfs.
#   wipe         Remove an entity's upper + layers + manifest entry. [--upper --layers --manifest]
#   status       Read-only: report mount + layer + lock state.       [--json]
#
# Exit-code contract (STABLE — tests bind to this):
#   0   ok (includes no-ops: mount-when-mounted, bake-when-empty, etc.)
#   2   deferred (a transient condition; reason in JSON .defer_reason + marker file)
#   3   invalid arguments / guard rejection
#   4   precondition failed
#   64  usage error (unknown verb / missing required flag)
#   other non-zero — hard failure
#
# stdout: a single JSON object (the contract). All human/diagnostic logging → stderr.
# stderr: free-form delegate logs.
#
# DESIGN — "the seam":
#   TODAY this script DELEGATES to mount_stack.sh / commit_stack.sh /
#   consolidate_layers.sh and SYNTHESISES the JSON from the delegate's exit code,
#   the defer-reason marker, an on-disk layer scan, and the lifecycle.log tail.
#   AFTER the Phase-5 consolidation refactor this script BECOMES the dispatcher and
#   emits the same JSON natively. Because callers (the integration test pack) bind
#   only to the verb set + exit contract + JSON shape below, that refactor is
#   invisible to them. See docs/specs/HOME_STORAGE_LIFECYCLE.md.
#
# ITEST SAFETY:
#   When AICLI_ITEST_GUARD=1 (set by the integration harness, NEVER in production)
#   every verb refuses unless the entity id matches ^it[0-9] AND the persist path
#   resolves under /tmp/unraid-aicliagents/itest/. This makes destructive verbs
#   impossible to aim at real data during a test run. Unset in production → no
#   restriction, so the seam works for real ids/paths.

set -uo pipefail

_SC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")" 2>/dev/null && pwd)"
PLUGIN_ROOT="/usr/local/emhttp/plugins/unraid-aicliagents"
[ -d "$_SC_DIR" ] || _SC_DIR="$PLUGIN_ROOT/src/scripts/storage"

# Source path/lifecycle helpers (best-effort; storagectl must run even if absent).
# shellcheck source=/dev/null
source "$_SC_DIR/resolve_paths.sh" 2>/dev/null || true
# Source common.sh for the SHARED layer discovery/ordering helpers
# (_layer_discover_sorted) so `status`/`bake` JSON reflects exactly what a mount
# would see — same seq-then-dt order as mount_stack. Best-effort; _layers_json
# falls back to a plain glob if the helper is unavailable.
# shellcheck source=/dev/null
source "$_SC_DIR/common.sh" 2>/dev/null || true
# Source the Phase-5 ops library: op_mount / op_bake / op_consolidate are the
# former trio bodies as in-process SUBSHELL functions. storagectl dispatches to
# them instead of shelling out to the standalone trio scripts (which are now shims).
# shellcheck source=/dev/null
source "$_SC_DIR/storage_ops.sh" 2>/dev/null || true

ZRAM_BASE="/tmp/unraid-aicliagents/zram_upper"
ITEST_ROOT="/tmp/unraid-aicliagents/itest"
LIFECYCLE_LOG="/boot/config/plugins/unraid-aicliagents/lifecycle.log"

_sc_err() { printf '[storagectl] %s\n' "$*" >&2; }

# ---- argument parsing -------------------------------------------------------
VERB="${1:-}"; shift || true
TYPE=""; ID=""; PERSIST=""; OWNER=""; LAZY=0; WANT_UPPER=0; WANT_LAYERS=0; WANT_MANIFEST=0
while [ $# -gt 0 ]; do
    case "$1" in
        --type)     TYPE="${2:-}"; shift 2 ;;
        --id)       ID="${2:-}"; shift 2 ;;
        --persist)  PERSIST="${2:-}"; shift 2 ;;
        --owner)    OWNER="${2:-}"; shift 2 ;;   # Bug #1054: chown home overlay to this user (mount only)
        --lazy)     LAZY=1; shift ;;
        --upper)    WANT_UPPER=1; shift ;;
        --layers)   WANT_LAYERS=1; shift ;;
        --manifest) WANT_MANIFEST=1; shift ;;
        --json)     shift ;;   # status emits JSON unconditionally; flag accepted for clarity
        *)          _sc_err "unknown flag: $1"; shift ;;
    esac
done

# Sanitised id for lock/marker filenames (mirrors commit_stack.sh).
_LOCK_ID="${ID//[^a-zA-Z0-9_-]/_}"

# ---- JSON helpers (no jq dependency) ----------------------------------------
# _json_str <s> — emit a JSON-escaped string value (without surrounding quotes).
_json_str() {
    local s="$1"
    s="${s//\\/\\\\}"; s="${s//\"/\\\"}"
    s="${s//$'\n'/\\n}"; s="${s//$'\t'/\\t}"; s="${s//$'\r'/}"
    printf '%s' "$s"
}

# ---- entity-derived paths — single-sourced via common.sh _entity_paths --------
# Phase 5: storagectl's private copy of the fstype->upper/mount derivation is gone.
# These thin accessors delegate to the shared helper (which sets UPPER_DIR / WORK_DIR
# / MNT_POINT / ENTITY_UPPER_MODE) so the dispatcher and the ops can never diverge.
# Existing call sites (emit_json, do_wipe, …) are unchanged.
_upper_mode() { _entity_paths "$TYPE" "$ID" "$PERSIST"; printf '%s' "$ENTITY_UPPER_MODE"; }
_mnt_point()  { _entity_paths "$TYPE" "$ID" "$PERSIST"; printf '%s' "$MNT_POINT"; }
_upper_dir()  { _entity_paths "$TYPE" "$ID" "$PERSIST"; printf '%s' "$UPPER_DIR"; }

# ---- itest guard ------------------------------------------------------------
_itest_guard() {
    [ "${AICLI_ITEST_GUARD:-0}" = "1" ] || return 0
    local rp root_rp
    case "$ID" in
        it[0-9]*) : ;;
        *) _sc_err "ITEST GUARD: id '$ID' is not an itest id (^it[0-9]) — refusing"; return 1 ;;
    esac
    # Canonicalise BOTH sides: on Unraid /tmp is a symlink to /var/tmp, so a raw
    # string compare of realpath(persist) against the literal ITEST_ROOT would
    # always miss. Resolve the root through the same realpath so the prefix match
    # holds regardless of how /tmp is linked. mkdir the root first so realpath
    # can resolve it even on a fresh box.
    mkdir -p "$ITEST_ROOT" 2>/dev/null
    root_rp="$(realpath "$ITEST_ROOT" 2>/dev/null || echo "$ITEST_ROOT")"
    rp="$(realpath "$PERSIST" 2>/dev/null || echo "$PERSIST")"
    case "$rp" in
        "$ITEST_ROOT"/*|"$root_rp"/*) : ;;
        *) _sc_err "ITEST GUARD: persist '$rp' not under $ITEST_ROOT ($root_rp) — refusing"; return 1 ;;
    esac
    return 0
}

# ---- defer-reason marker ----------------------------------------------------
_read_defer_reason() {
    local m="/tmp/unraid-aicliagents/.bake_defer_reason_${TYPE}_${_LOCK_ID}"
    [ -f "$m" ] && head -1 "$m" 2>/dev/null | tr -d '\n' || true
}

# ---- layer scan -------------------------------------------------------------
# Emits a JSON array of {file,bytes,kind} newest-first. Ordering comes from the
# SHARED common.sh helper (_layer_discover_sorted: per-entity seq desc, then dt),
# so `status` reflects EXACTLY what a mount would stack. Falls back to a plain
# reverse glob if common.sh wasn't sourced (degraded but functional).
_layers_json() {
    local arr="[" first=1 f bn bytes kind
    local files=()
    if declare -f _layer_discover_sorted >/dev/null 2>&1; then
        mapfile -t files < <(_layer_discover_sorted "$PERSIST" "$TYPE" "$ID")
    else
        shopt -s nullglob
        for f in "$PERSIST"/${TYPE}_${ID}_*.sqsh; do [ -e "$f" ] && files+=("$f"); done
        shopt -u nullglob
        IFS=$'\n' files=($(printf '%s\n' "${files[@]}" | sort -r)); unset IFS
    fi
    for f in "${files[@]}"; do
        [ -n "$f" ] || continue
        bn="$(basename "$f")"
        bytes="$(stat -c '%s' "$f" 2>/dev/null || echo 0)"
        case "$bn" in
            *_consolidated_*) kind="consolidated" ;;
            *_delta_*)        kind="delta" ;;
            *_vol1.sqsh)      kind="legacy_consolidated" ;;
            *)                kind="unknown" ;;
        esac
        [ $first -eq 1 ] && first=0 || arr="$arr,"
        arr="$arr{\"file\":\"$(_json_str "$bn")\",\"bytes\":$bytes,\"kind\":\"$kind\"}"
    done
    echo "$arr]"
}

# ---- consolidate policy verdict (homes only; constants in common.sh) --------
# Emits {"recommended":bool,"reason":"layers_near_max|space_pressure|none",
#        "layers":n,"max":n,"free_bytes":n}. Computed purely from on-disk state
# so `status` is a deterministic, integration-testable policy oracle. Recommends
# consolidation when the stack nears the overlay ceiling OR persist is under space
# pressure (free < summed layer sizes + SCRATCH_MARGIN). Falls back gracefully if
# common.sh wasn't sourced (default max 30, glob layer discovery).
_consolidate_json() {
    local files=() layers=0 max free_bytes sum_bytes=0 margin recommended reason f b
    if declare -f _layer_discover_sorted >/dev/null 2>&1; then
        mapfile -t files < <(_layer_discover_sorted "$PERSIST" "$TYPE" "$ID")
    else
        shopt -s nullglob
        for f in "$PERSIST"/${TYPE}_${ID}_*.sqsh; do [ -e "$f" ] && files+=("$f"); done
        shopt -u nullglob
    fi
    for f in "${files[@]}"; do [ -n "$f" ] && layers=$((layers + 1)); done

    if declare -f _consolidate_max_layers >/dev/null 2>&1; then
        max="$(_consolidate_max_layers)"
    else
        max=30
    fi

    free_bytes="$(df -P -B1 "$PERSIST" 2>/dev/null | awk 'NR==2 {print $4}')"
    case "$free_bytes" in ''|*[!0-9]*) free_bytes=0 ;; esac

    for f in "${files[@]}"; do
        [ -n "$f" ] || continue
        b="$(stat -c '%s' "$f" 2>/dev/null || echo 0)"
        sum_bytes=$((sum_bytes + b))
    done
    margin="${SCRATCH_MARGIN:-$((200 * 1024 * 1024))}"

    recommended=false; reason="none"
    if [ "$layers" -ge "$((max - 2))" ]; then
        recommended=true; reason="layers_near_max"
    elif [ "$free_bytes" -lt "$((sum_bytes + margin))" ]; then
        recommended=true; reason="space_pressure"
    fi
    printf '{"recommended":%s,"reason":"%s","layers":%d,"max":%d,"free_bytes":%d}' \
        "$recommended" "$reason" "$layers" "$max" "$free_bytes"
}

_lock_held() {
    local lock="/var/run/aicli-bake-${TYPE}-${_LOCK_ID}.lock"
    [ -e "$lock" ] || { echo false; return; }
    # If we can take it non-blocking, nobody holds it.
    if ( exec 7>"$lock"; flock -n 7 ) 2>/dev/null; then echo false; else echo true; fi
}

# ---- lifecycle event capture (since a marker line count) --------------------
_lifecycle_count() { [ -f "$LIFECYCLE_LOG" ] && wc -l < "$LIFECYCLE_LOG" 2>/dev/null | tr -d ' ' || echo 0; }

# _lifecycle_events_json <start_line> — JSON array of event names appended since start_line
#   that mention this entity's id.
_lifecycle_events_json() {
    local start="${1:-0}" arr="[" first=1 ev
    [ -f "$LIFECYCLE_LOG" ] || { echo "[]"; return; }
    while IFS= read -r line; do
        case "$line" in *"\"$ID\""*) : ;; *) continue ;; esac
        # field 4 (pipe-delimited) is the event name
        ev="$(printf '%s' "$line" | awk -F' \\| ' '{print $4}' | tr -d ' ')"
        [ -n "$ev" ] || continue
        [ $first -eq 1 ] && first=0 || arr="$arr,"
        arr="$arr\"$(_json_str "$ev")\""
    done < <(tail -n +"$((start + 1))" "$LIFECYCLE_LOG" 2>/dev/null)
    echo "$arr]"
}

# ---- result emitter ---------------------------------------------------------
# emit_json <exit> <outcome> <defer_reason> <events_json>
emit_json() {
    local ex="$1" outcome="$2" reason="$3" events="${4:-[]}"
    local mnt; mnt="$(_mnt_point)"
    local mounted=false
    mountpoint -q "$mnt" 2>/dev/null && mounted=true
    local reason_field="null"
    [ -n "$reason" ] && reason_field="\"$(_json_str "$reason")\""
    printf '{'
    printf '"schema":1,'
    printf '"verb":"%s",' "$(_json_str "$VERB")"
    printf '"type":"%s",' "$(_json_str "$TYPE")"
    printf '"id":"%s",' "$(_json_str "$ID")"
    printf '"persist":"%s",' "$(_json_str "$PERSIST")"
    printf '"exit":%s,' "$ex"
    printf '"outcome":"%s",' "$(_json_str "$outcome")"
    printf '"defer_reason":%s,' "$reason_field"
    printf '"upper_mode":"%s",' "$(_upper_mode)"
    printf '"mount":{"merged":"%s","mounted":%s},' "$(_json_str "$mnt")" "$mounted"
    printf '"layers":%s,' "$(_layers_json)"
    printf '"lock_held":%s,' "$(_lock_held)"
    printf '"lifecycle_events":%s,' "$events"
    # Consolidate-policy verdict: homes only, on `status` only (the policy oracle).
    if [ "$VERB" = "status" ] && [ "$TYPE" = "home" ]; then
        printf '"consolidate":%s,' "$(_consolidate_json)"
    fi
    printf '"raw_exit":%s' "$ex"
    printf '}\n'
}

# Map a delegate exit code to an outcome string.
_outcome_for() {
    case "$1" in
        0) echo "ok" ;;
        2) echo "deferred" ;;
        *) echo "failed" ;;
    esac
}

# ---- validation -------------------------------------------------------------
require_entity_args() {
    if [ -z "$TYPE" ] || [ -z "$ID" ] || [ -z "$PERSIST" ]; then
        _sc_err "missing required: --type --id --persist"
        VERB="${VERB:-?}"; TYPE="${TYPE:-?}"; ID="${ID:-?}"; PERSIST="${PERSIST:-?}"
        emit_json 64 "failed" "usage"
        exit 64
    fi
    case "$TYPE" in home|agent) : ;; *) _sc_err "invalid --type: $TYPE"; emit_json 3 "failed" "invalid_type"; exit 3 ;; esac
}

# ---- verbs ------------------------------------------------------------------
do_mount() {
    require_entity_args
    _itest_guard || { emit_json 3 "failed" "guard_reject"; exit 3; }
    local start; start="$(_lifecycle_count)"
    op_mount "$TYPE" "$ID" "$PERSIST" "$OWNER" >&2
    local ex=$?
    local reason=""; [ "$ex" -eq 2 ] && reason="$(_read_defer_reason)"
    emit_json "$ex" "$(_outcome_for "$ex")" "$reason" "$(_lifecycle_events_json "$start")"
    exit "$ex"
}

do_unmount() {
    require_entity_args
    _itest_guard || { emit_json 3 "failed" "guard_reject"; exit 3; }
    local mnt; mnt="$(_mnt_point)"
    if ! mountpoint -q "$mnt" 2>/dev/null; then
        emit_json 0 "noop" ""        # unmount-when-unmounted is a clean no-op (I04)
        exit 0
    fi
    if [ "$LAZY" -eq 1 ]; then
        umount -l "$mnt" 2>/dev/null
    else
        umount "$mnt" 2>/dev/null || umount -l "$mnt" 2>/dev/null
    fi
    local ex=$?
    emit_json "$ex" "$(_outcome_for "$ex")" ""
    exit "$ex"
}

do_bake() {
    require_entity_args
    _itest_guard || { emit_json 3 "failed" "guard_reject"; exit 3; }
    local start; start="$(_lifecycle_count)"
    op_bake "$TYPE" "$ID" "$PERSIST" >&2
    local ex=$?
    local reason=""; [ "$ex" -eq 2 ] && reason="$(_read_defer_reason)"
    local events; events="$(_lifecycle_events_json "$start")"
    local outcome; outcome="$(_outcome_for "$ex")"
    # Distinguish a successful no-op (empty upper) from a real bake for the contract.
    if [ "$ex" -eq 0 ]; then
        case "$events" in *bash_bake_skipped_empty*|*bake_skipped_concurrent*) outcome="noop" ;; esac
    fi
    emit_json "$ex" "$outcome" "$reason" "$events"
    exit "$ex"
}

do_consolidate() {
    require_entity_args
    _itest_guard || { emit_json 3 "failed" "guard_reject"; exit 3; }
    local start; start="$(_lifecycle_count)"
    op_consolidate "$TYPE" "$ID" "$PERSIST" >&2
    local ex=$?
    local reason=""; [ "$ex" -eq 2 ] && reason="$(_read_defer_reason)"
    local events; events="$(_lifecycle_events_json "$start")"
    local outcome; outcome="$(_outcome_for "$ex")"
    if [ "$ex" -eq 0 ]; then
        case "$events" in *consolidate_skipped_locked*|*consolidate_skipped_single*) outcome="noop" ;; esac
    fi
    emit_json "$ex" "$outcome" "$reason" "$events"
    exit "$ex"
}

do_wipe() {
    require_entity_args
    # wipe is destructive — guard hard even outside itest mode against the real
    # persist default, but the harness path is the only sanctioned caller today.
    _itest_guard || { emit_json 3 "failed" "guard_reject"; exit 3; }
    # Default: wipe everything if no specific flag given.
    if [ "$WANT_UPPER" -eq 0 ] && [ "$WANT_LAYERS" -eq 0 ] && [ "$WANT_MANIFEST" -eq 0 ]; then
        WANT_UPPER=1; WANT_LAYERS=1; WANT_MANIFEST=1
    fi
    local mnt; mnt="$(_mnt_point)"
    # Refuse to wipe a live entity (P12): defer if the mount is busy.
    if mountpoint -q "$mnt" 2>/dev/null && fuser -sm "$mnt" 2>/dev/null; then
        emit_json 2 "deferred" "mount_busy"
        exit 2
    fi
    mountpoint -q "$mnt" 2>/dev/null && { umount "$mnt" 2>/dev/null || umount -l "$mnt" 2>/dev/null; }
    if [ "$WANT_UPPER" -eq 1 ]; then
        local up; up="$(_upper_dir)"
        case "$up" in /tmp/unraid-aicliagents/*|"$PERSIST"/_upper/*) rm -rf "$up" 2>/dev/null ;; esac
    fi
    if [ "$WANT_LAYERS" -eq 1 ]; then
        shopt -s nullglob
        local f; for f in "$PERSIST"/${TYPE}_${ID}_*.sqsh; do rm -f "$f" 2>/dev/null; done
        shopt -u nullglob
    fi
    if [ "$WANT_MANIFEST" -eq 1 ]; then
        php -d display_errors=0 -r '
            $_SERVER["DOCUMENT_ROOT"]="/usr/local/emhttp";
            require_once "/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/AICliAgentsManager.php";
            \AICliAgents\Services\LayerManifestService::removeEntitiesMatching("/" . preg_quote($argv[1]."/".$argv[2], "/") . "/");
        ' "$TYPE" "$ID" 2>/dev/null || true
    fi
    emit_json 0 "ok" ""
    exit 0
}

do_status() {
    require_entity_args
    emit_json 0 "ok" ""
    exit 0
}

case "$VERB" in
    mount)       do_mount ;;
    unmount)     do_unmount ;;
    bake)        do_bake ;;
    consolidate) do_consolidate ;;
    wipe)        do_wipe ;;
    status)      do_status ;;
    ""|-h|--help|help)
        _sc_err "usage: storagectl.sh <mount|unmount|bake|consolidate|wipe|status> --type <home|agent> --id <ID> --persist <PATH> [flags]"
        exit 64 ;;
    *)
        _sc_err "unknown verb: $VERB"
        exit 64 ;;
esac
