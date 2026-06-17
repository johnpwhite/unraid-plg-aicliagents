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
#   probe        Read-only: capability probe for a persist PATH (S-01 #1351).
#                `storagectl.sh probe --persist <PATH>` → probe JSON, exit 0.
#                Path-scoped (no --type/--id); see STORAGE_BACKEND_DETECTION_V2.md.
#   graduate     Migrate a flash (layering) entity to the passthrough backend on
#                a capable device (S-10 #1354). Exit 0 ok / 2 deferred / 4
#                precondition failed. See STORAGE_BACKEND_GRADUATION.md.
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
# Epic #1310 Step 2: the genuine backend device test (is_flash_device/backend_for).
# Best-effort — emit_json falls back to "flash" if unavailable.
# shellcheck source=/dev/null
source "$_SC_DIR/detect_backend.sh" 2>/dev/null || true

# F6 (WP#1331): the SINGLE manifest writer (do_wipe's manifest removal).
# shellcheck source=/dev/null
source "$_SC_DIR/manifest_write.sh" 2>/dev/null || true

ZRAM_BASE="/tmp/unraid-aicliagents/zram_upper"
ITEST_ROOT="/tmp/unraid-aicliagents/itest"
# #1254: honour the test-only AICLI_LIFECYCLE_LOG redirect so the lifecycle-event
# READER (here) stays aligned with the WRITER (resolve_paths.sh lifecycle_log_path).
LIFECYCLE_LOG="${AICLI_LIFECYCLE_LOG:-/boot/config/plugins/unraid-aicliagents/lifecycle.log}"

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
# S-03 (#1352): a marker older than the TTL (cfg defer_marker_ttl_h, default 24 h)
# is STALE — silently ignored, exactly as if absent. _defer_marker_fresh is in
# common.sh; if common.sh wasn't sourced (degraded), fall back to a plain read.
_read_defer_reason() {
    local m="/tmp/unraid-aicliagents/.bake_defer_reason_${TYPE}_${_LOCK_ID}"
    if declare -f _defer_marker_fresh >/dev/null 2>&1; then
        _defer_marker_fresh "$m" || return 0
    elif [ ! -f "$m" ]; then
        return 0
    fi
    head -1 "$m" 2>/dev/null | tr -d '\n' || true
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
    # S-09 (#1352): per-file cap for the UI meter — populated on vfat targets ONLY
    # (the cap is derived from the real fstype via findmnt, never a path prefix).
    if [ "$VERB" = "status" ]; then
        local _pfst
        _pfst="${AICLI_ITEST_PERSIST_FSTYPE:-$(findmnt --noheadings --output FSTYPE --target "$PERSIST" 2>/dev/null || echo '')}"
        if [ "$_pfst" = "vfat" ]; then
            printf '"persist_cap_bytes":%d,' "${FAT32_MAX_FILE_BYTES:-4294967295}"
        fi
    fi
    # Epic #1310: emit the EFFECTIVE backend capabilities for the UI. F9 (WP#1332):
    # this is NOT "status-only" — _passthrough_guard runs effective_backend on every
    # MUTATING verb too (it is the backend router that short-circuits bake/consolidate
    # on a passthrough entity). The cost is bounded by effective_backend's layers-first
    # short-circuit: a layered entity returns flash from the zero-subprocess glob, so
    # the expensive device probe only runs for layer-free entities.
    if [ "$VERB" = "status" ]; then
        # EFFECTIVE backend (Step 6): an entity that still has .sqsh layers reports
        # flash (it's bakeable) even on a passthrough device, so the UI keeps its
        # Bake/Consolidate controls until the entity is migrated. Only a layer-free
        # entity on a passthrough device reports passthrough.
        local _bk="flash" _sb="true" _sc="true"
        if declare -f effective_backend >/dev/null 2>&1; then
            _bk="$(effective_backend "$TYPE" "$ID" "$PERSIST" 2>/dev/null)"; [ -n "$_bk" ] || _bk="flash"
        fi
        [ "$_bk" = "flash" ] || { _sb="false"; _sc="false"; }
        printf '"backend":"%s","supportsBake":%s,"supportsConsolidate":%s,' "$(_json_str "$_bk")" "$_sb" "$_sc"
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
    # S-03 (#1352): clear any stale defer-reason from a prior bake/consolidate cycle
    # so the reason read by PHP is THIS mount's truth, never a prior op's. op_bake /
    # op_consolidate already clear on entry; this is the missing op_mount half.
    rm -f "/tmp/unraid-aicliagents/.bake_defer_reason_${TYPE}_${_LOCK_ID}" 2>/dev/null || true
    _passthrough_guard mount   # Step 6: plain-dir bind + exit if effective backend is passthrough
    # Bake-lock probe (symmetric with the supervisor's _op_mount, aicli-supervisor.sh):
    # if a bake/consolidate holds this entity's per-entity lock, DEFER this
    # user-initiated mount (exit 2 — the caller retries with backoff) instead of
    # racing the consolidate's non-atomic old-layer delete loop. op_mount discovers
    # layers by on-disk glob (_layer_discover_sorted), so mounting mid-delete can hit
    # a just-removed .sqsh and hard-fail (exit 1, "Failed to mount"). The supervisor
    # mount path already probes this; do_mount (the PHP-direct path) was the gap.
    local _bake_lock="/var/run/aicli-bake-${TYPE}-${_LOCK_ID}.lock"
    if [ -e "$_bake_lock" ] && ! ( exec 7>"$_bake_lock"; flock -n 7 ) 2>/dev/null; then
        write_defer_reason "$TYPE" "$ID" "bake_lock_held"
        emit_json 2 "deferred" "bake_lock_held" "[]"
        exit 2
    fi
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
    _passthrough_guard bake   # Step 6: no-op (data is already durable) if passthrough
    local start; start="$(_lifecycle_count)"
    op_bake "$TYPE" "$ID" "$PERSIST" >&2
    local ex=$?
    # S-09 (#1352): exit 4 (precondition failed, e.g. fat32_size_cap) also writes a
    # reason marker — surface it in the JSON exactly like a defer's.
    local reason=""; { [ "$ex" -eq 2 ] || [ "$ex" -eq 4 ]; } && reason="$(_read_defer_reason)"
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
    _passthrough_guard consolidate   # Step 6: no-op if passthrough
    local start; start="$(_lifecycle_count)"
    op_consolidate "$TYPE" "$ID" "$PERSIST" >&2
    local ex=$?
    # S-09 (#1352): exit 4 (precondition failed, e.g. fat32_size_cap) also carries a reason.
    local reason=""; { [ "$ex" -eq 2 ] || [ "$ex" -eq 4 ]; } && reason="$(_read_defer_reason)"
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
    _passthrough_guard wipe   # Step 6: rm the plain dir if passthrough
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
    # Feature #1382 / Bug #1379+#1381: BEFORE removing the .sqsh files, tear down
    # this entity's per-layer squashfs LOOP mounts. Each layer is loop-mounted at
    # /tmp/unraid-aicliagents/mnt/<type>_<id>_<layer> as the overlay's lowerdir;
    # if we rm the .sqsh while that mount is still up, the loop device is left
    # bound to a (deleted) backing file forever — the orphan-loop residue the
    # state-invariant harness flags (I5). Unmount each (releasing the loop) so a
    # wipe never leaks an orphan loop. The merge mount above already dropped the
    # only reference to these lowers.
    local _wl
    for _wl in /tmp/unraid-aicliagents/mnt/${TYPE}_${ID}_*; do
        [ -d "$_wl" ] || continue
        mountpoint -q "$_wl" 2>/dev/null && { umount "$_wl" 2>/dev/null || umount -l "$_wl" 2>/dev/null; }
        rmdir "$_wl" 2>/dev/null || true
    done
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
        # F6 (WP#1331): single writer, EXACT entity (the prior removeEntitiesMatching
        # regex was unanchored — a prefix-sibling id like home/it12 could also wipe
        # home/it123; removeEntity targets exactly this entity).
        manifest_remove_entity "$TYPE" "$ID" || true
    fi
    emit_json 0 "ok" ""
    exit 0
}

do_status() {
    require_entity_args
    emit_json 0 "ok" ""
    exit 0
}

# graduate — S-10 (#1354): migrate a flash (layering) entity to the passthrough
# backend. Supervisor-owned in production (enqueued as op `graduate`); the verb
# is also directly callable for the L3.5 harness. Exit contract: 0 ok / 2
# deferred (transient; reason marker) / 4 precondition failed (reason marker
# graduate_precondition). No _passthrough_guard: graduate REQUIRES a flash
# entity (the guard would short-circuit an already-passthrough entity; the
# precondition inside op_graduate refuses that case with exit 4 instead).
do_graduate() {
    require_entity_args
    _itest_guard || { emit_json 3 "failed" "guard_reject"; exit 3; }
    local start; start="$(_lifecycle_count)"
    op_graduate "$TYPE" "$ID" "$PERSIST" >&2
    local ex=$?
    local reason=""; { [ "$ex" -eq 2 ] || [ "$ex" -eq 4 ]; } && reason="$(_read_defer_reason)"
    emit_json "$ex" "$(_outcome_for "$ex")" "$reason" "$(_lifecycle_events_json "$start")"
    exit "$ex"
}

# probe — S-01 (#1351) DARK phase: read-only capability probe for a persist PATH.
# Path-scoped (no --type/--id), never mutates, ALWAYS exit 0 (the probe itself
# errs toward flash internally). stdout is the probe JSON from detect_backend.sh
# probe_target; if detect_backend.sh failed to source (best-effort sourcing
# above), emit the safe layering/zram fallback so callers always get valid JSON.
do_probe() {
    if [ -z "$PERSIST" ]; then
        _sc_err "probe requires --persist <PATH>"
        _sc_err "usage: storagectl.sh probe --persist <PATH>"
        exit 64
    fi
    if declare -f probe_target >/dev/null 2>&1; then
        probe_target "$PERSIST"
    else
        printf '{"schema":1,"path":"%s","realpath":"%s","fstype":"","mount_class":"other","via_user_share":false,"durability":"durable","wear":"wear_sensitive","posix":"posix_none","rotational":false,"max_file_bytes":0,"engine":"layering","upper_mode":"zram","refuse":false,"warnings":[],"reasons":["probe_unavailable"]}\n' \
            "$(_json_str "$PERSIST")" "$(_json_str "$PERSIST")"
    fi
    exit 0
}

# release — Epic #1310: tear down for shutdown/close = FLUSH (bake) then unmount.
# The single intent verb the facade's FileStorage::release routes through, so the
# central manifest-under-lock bake path is the only writer (op_bake records the
# manifest). On passthrough the flush is a no-op (data already durable) + unbind.
do_release() {
    require_entity_args
    _itest_guard || { emit_json 3 "failed" "guard_reject"; exit 3; }
    _passthrough_guard release
    local start; start="$(_lifecycle_count)"
    op_bake "$TYPE" "$ID" "$PERSIST" >&2
    local ex=$?
    local mnt; mnt="$(_mnt_point)"
    # F5 (WP#1328): exit 2 = the bake DEFERRED because the home is busy → the upper is
    # NOT flushed. The old code unconditionally umount/umount -l'd here, dropping the
    # unflushed upper (and "deferred → retry" doesn't hold once the overlay is gone).
    # KEEP the live overlay on a defer; only after a successful flush (exit != 2) tear
    # down, and through the teardown arbiter (a REAL non-lazy umount that keeps a still-
    # busy overlay live) instead of an ad-hoc umount -l that bypasses it (also L4).
    if [ "$ex" -ne 2 ]; then
        _mount_teardown_arbiter "$mnt" || true
    fi
    local reason=""; [ "$ex" -eq 2 ] && reason="$(_read_defer_reason)"
    emit_json "$ex" "$(_outcome_for "$ex")" "$reason" "$(_lifecycle_events_json "$start")"
    exit "$ex"
}

# ---- Step 6: passthrough backend (plain directory, no layering) -------------
# An entity whose EFFECTIVE backend (detect_backend.sh) is passthrough is written
# DIRECTLY to a durable plain dir — no overlay, no zram, no SquashFS, no bake.
# effective_backend already keeps any entity that still has .sqsh layers on the
# flash engine, so this NEVER strands existing layered data (the .4-safety
# invariant). The plain dir is bind-mounted at the entity's normal mount point so
# consumers see no difference.
_pt_dir() { printf '%s' "$PERSIST/passthrough/${TYPE}s/$ID"; }

_pt_mount() {
    local mnt pdir; mnt="$(_mnt_point)"; pdir="$(_pt_dir)"
    guard_path "$PERSIST" "PERSIST" || { emit_json 1 "failed" "guard_reject"; exit 1; }
    mkdir -p "$pdir" 2>/dev/null
    [ -L "$mnt" ] && rm -f "$mnt"
    mkdir -p "$mnt" 2>/dev/null
    # Bug #1054: own the plain dir + mount point so a non-root agent can write.
    if [ -n "$OWNER" ] && [ "$OWNER" != "root" ] && id "$OWNER" >/dev/null 2>&1; then
        chown -R "$OWNER" "$pdir" 2>/dev/null || true
        chown "$OWNER" "$mnt" 2>/dev/null || true
    fi
    if mountpoint -q "$mnt" 2>/dev/null; then emit_json 0 "ok" ""; exit 0; fi
    if mount --bind "$pdir" "$mnt" 2>/dev/null; then
        lifecycle_log "info" "storagectl" "passthrough_mount" "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"dir\":\"$pdir\"}" 2>/dev/null || true
        emit_json 0 "ok" ""; exit 0
    fi
    _sc_err "passthrough bind failed: $pdir -> $mnt"
    emit_json 1 "failed" "passthrough_bind_failed"; exit 1
}

# bake/consolidate on passthrough are no-ops: writes already landed durably in the
# plain dir. fsync for stable storage, then a clean exit-0 no-op (the seam contract).
_pt_persist_noop() {
    sync 2>/dev/null || true
    emit_json 0 "noop" ""; exit 0
}

_pt_wipe() {
    local mnt pdir; mnt="$(_mnt_point)"; pdir="$(_pt_dir)"
    mountpoint -q "$mnt" 2>/dev/null && { umount "$mnt" 2>/dev/null || umount -l "$mnt" 2>/dev/null; }
    case "$pdir" in "$PERSIST"/passthrough/*) rm -rf "$pdir" 2>/dev/null ;; esac
    emit_json 0 "ok" ""; exit 0
}

# _passthrough_guard <verb> — if the entity's effective backend is passthrough,
# handle the verb with the plain-dir backend and EXIT. No-op for flash entities.
_passthrough_guard() {
    declare -f effective_backend >/dev/null 2>&1 || return 0
    [ "$(effective_backend "$TYPE" "$ID" "$PERSIST" 2>/dev/null)" = "passthrough" ] || return 0
    case "$1" in
        mount)            _pt_mount ;;
        bake|consolidate) _pt_persist_noop ;;
        wipe)             _pt_wipe ;;
        release)          _pt_release ;;
    esac
}

# passthrough release: fsync the durable plain dir, then unbind the mount.
_pt_release() {
    local mnt; mnt="$(_mnt_point)"
    sync 2>/dev/null || true
    mountpoint -q "$mnt" 2>/dev/null && { umount "$mnt" 2>/dev/null || umount -l "$mnt" 2>/dev/null; }
    emit_json 0 "ok" ""; exit 0
}

case "$VERB" in
    mount)       do_mount ;;
    unmount)     do_unmount ;;
    bake)        do_bake ;;
    consolidate) do_consolidate ;;
    wipe)        do_wipe ;;
    release)     do_release ;;
    status)      do_status ;;
    probe)       do_probe ;;
    graduate)    do_graduate ;;
    ""|-h|--help|help)
        _sc_err "usage: storagectl.sh <mount|unmount|bake|consolidate|wipe|status|graduate> --type <home|agent> --id <ID> --persist <PATH> [flags]"
        _sc_err "       storagectl.sh probe --persist <PATH>   (read-only capability probe, JSON)"
        exit 64 ;;
    *)
        _sc_err "unknown verb: $VERB"
        exit 64 ;;
esac
