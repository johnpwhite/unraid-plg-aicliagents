#!/bin/bash
# boot_integrity.sh -- Phase 4a boot-time integrity classifier (bash mirror of BootIntegrityService).
#
# Usage: source this file, then call boot_integrity_classify <type> <id> <persist_path>
#
# boot_integrity_classify <type> <id> <persist_path>
#   Echoes one of: genuine_fresh, healthy, legacy_unmanaged, partial_loss,
#                  path_drift, total_loss, untracked, host_mismatch, unknown.
#   Side-effect: writes one lifecycle log line via lifecycle_log().
#
# In Phase 4a warn mode this does NOT block mounts. Callers continue regardless.
# Phase 4b adds the halt-and-ask gate by checking the returned state and acting on it.
#
# Dependencies: resolve_paths.sh must have been sourced first.

# Source resolve_paths if not already sourced (idempotent guard via PLUGIN_BASE var check).
if [ -z "${PLUGIN_BASE:-}" ]; then
    source "$(dirname "${BASH_SOURCE[0]}")/resolve_paths.sh" 2>/dev/null || true
fi

# ---------------------------------------------------------------------------
# boot_integrity_classify <type> <id> <persist_path>
#
# type: 'home' or 'agent'
# id:   username or agent id
#
# Echoes the state string. Writes a lifecycle log entry.
# ---------------------------------------------------------------------------
boot_integrity_classify() {
    local type="${1:-}"
    local id="${2:-}"
    # Follow-on 1b / L2 (WP#1333): the persist path is REQUIRED — the caller (op_mount)
    # always passes the exact directory it is mounting, so the classifier's active-layer
    # + legacy-data verdict can never diverge from the dir being mounted (and the itest
    # harness points it at its loopback persist). The old "optional 2-arg" form that
    # re-resolved the path independently was DEAD (no 2-arg caller) and was precisely
    # the caller-vs-classifier divergence Follow-on 1b exists to eliminate, so it is
    # removed; an empty path is a usage error → 'unknown'.
    local persist_path="${3:-}"

    if [ -z "$type" ] || [ -z "$id" ] || [ -z "$persist_path" ]; then
        echo "unknown"
        return 0
    fi

    local manifest_json
    manifest_json="$(manifest_path 2>/dev/null)"

    # ---------- Read expected layers from manifest ---------------------------
    local expected_count=0
    local manifest_persist_path=""
    local manifest_host_id=""

    # F2/F7 (WP#1326/#1329): read the manifest with PHP, NOT python3 — Unraid ships
    # php but NOT python3, so the old python3 path always yielded expected_count=0 on
    # the real box, silently disabling total_loss/path_drift detection (op_mount would
    # then mount an empty stack over manifest-expected-but-missing layers). Single-
    # quoted php -r with $argv (no backslash-namespace anti-pattern) + a direct
    # json_decode of the manifest file (no 36-require bootstrap). Pure-bash grep
    # fallback keeps expected_count a safe floor (>=1 when the entity is recorded) if
    # php is somehow absent, so the loss is never masked.
    if [ -f "$manifest_json" ]; then
        local entity="${type}/${id}"
        if command -v php >/dev/null 2>&1; then
            local manifest_data
            manifest_data="$(php -d display_errors=0 -r '
                $m = json_decode(@file_get_contents($argv[1]), true);
                $e = (is_array($m) && isset($m["entities"][$argv[2]]) && is_array($m["entities"][$argv[2]]))
                    ? $m["entities"][$argv[2]] : [];
                echo count((array)($e["expected_layers"] ?? [])), "\n";
                echo (string)($e["current_persistence_path"] ?? ""), "\n";
                echo (string)($m["host_id"] ?? ""), "\n";
            ' "$manifest_json" "$entity" 2>/dev/null)"
            expected_count=$(echo "$manifest_data" | sed -n '1p')
            manifest_persist_path=$(echo "$manifest_data" | sed -n '2p')
            manifest_host_id=$(echo "$manifest_data" | sed -n '3p')
            expected_count="${expected_count:-0}"
        else
            # php-less fallback: the manifest records an entity ONLY with baked layers,
            # so its key appearing means expected>0. Fail-closed floor of 1; the exact
            # persist-path/host-id refinements degrade to empty (no path_drift/host
            # check) but the total_loss-vs-genuine_fresh decision stays safe.
            if grep -q "\"${entity}\"[[:space:]]*:" "$manifest_json" 2>/dev/null; then
                expected_count=1
            fi
        fi
    fi

    # ---------- Active layer count ------------------------------------------
    local active_count=0
    local active_files=()
    if [ -d "$persist_path" ]; then
        shopt -s nullglob
        active_files=("$persist_path"/${type}_${id}_*.sqsh)
        shopt -u nullglob
        active_count="${#active_files[@]}"
    fi

    # ---------- Sibling discovery -------------------------------------------
    local sibling_count=0
    local sibling_sample=""
    local plugin_base_dir="$PLUGIN_BASE"
    local parent_dir
    parent_dir="$(dirname "$plugin_base_dir")"

    if [ -d "$plugin_base_dir" ]; then
        local sibling_dirs=()
        for d in "$plugin_base_dir"/*/; do
            [ -d "$d" ] || continue
            local dname
            dname="$(basename "$d")"
            case "$dname" in
                *backup*|*BACKUP*|migrated_legacy_data|*_old|*.bak|*.backup)
                    sibling_dirs+=("${d%/}")
                    ;;
            esac
        done
        for d in "$parent_dir"/unraid-aicliagents*/; do
            [ -d "$d" ] || continue
            [ "${d%/}" = "$plugin_base_dir" ] && continue
            sibling_dirs+=("${d%/}")
        done

        shopt -s nullglob
        for sd in "${sibling_dirs[@]}"; do
            for f in "$sd"/${type}_${id}_*.sqsh; do
                [ -f "$f" ] || continue
                sibling_count=$((sibling_count + 1))
                [ -z "$sibling_sample" ] && sibling_sample="$f"
            done
        done
        shopt -u nullglob
    fi

    # ---------- Host ID mismatch check --------------------------------------
    local current_host_id=""
    if [ -f /etc/machine-id ]; then
        current_host_id="$(tr -d '[:space:]' < /etc/machine-id)"
    fi
    if [ -n "$manifest_host_id" ] && [ -n "$current_host_id" ] && \
       [ "$manifest_host_id" != "$current_host_id" ]; then
        _boot_integrity_log "critical" "${type}/${id}" "host_mismatch" \
            "manifest_host=$manifest_host_id current_host=$current_host_id"
        echo "host_mismatch"
        return 0
    fi

    # ---------- Classification ----------------------------------------------
    local state="unknown"

    if [ "$expected_count" -eq 0 ]; then
        if [ "$active_count" -eq 0 ] && [ "$sibling_count" -eq 0 ]; then
            # Follow-on 1b: fold op_mount's LEGACY_FOUND probe into the single
            # classifier — legacy .img/raw-folder data with no managed layers must
            # HALT (legacy_unmanaged → recovery) not read as genuine_fresh + mount
            # an empty stack over the unmigrated data.
            if _boot_integrity_has_legacy_data "$persist_path" "$type" "$id"; then
                state="legacy_unmanaged"
            else
                state="genuine_fresh"
            fi
        elif [ "$active_count" -eq 0 ] && [ "$sibling_count" -gt 0 ]; then
            state="legacy_unmanaged"
        else
            state="untracked"
        fi
    else
        if [ "$active_count" -gt 0 ]; then
            # Phase 4a: assume healthy if active layers exist (sha256 check deferred to Phase 4b)
            state="healthy"
        elif [ "$active_count" -eq 0 ]; then
            # Check path drift
            local path_drift=0
            if [ -n "$manifest_persist_path" ] && \
               [ "$(echo "$manifest_persist_path" | sed 's|/$||')" != "$(echo "$persist_path" | sed 's|/$||')" ]; then
                path_drift=1
            fi
            if [ "$path_drift" -eq 1 ] || [ "$sibling_count" -gt 0 ]; then
                state="path_drift"
            else
                state="total_loss"
            fi
        fi
    fi

    _boot_integrity_log "$([ "$state" = "total_loss" ] || [ "$state" = "partial_loss" ] \
        && echo "critical" || echo "info")" \
        "${type}/${id}" "$state" \
        "expected=$expected_count active=$active_count siblings=$sibling_count persist_path=$persist_path"

    echo "$state"
    return 0
}

# ---------------------------------------------------------------------------
# _boot_integrity_has_legacy_data <persist> <type> <id>
# Follow-on 1b: pure predicate mirroring BootIntegrityService::hasLegacyData.
# Returns 0 (true) if the persist dir holds UNMIGRATED legacy data — pre-squashfs
# *.img images or raw legacy home folders — with no managed .sqsh layers. Folds the
# old op_mount LEGACY_FOUND probe (D-298 / D-342) into the single classifier. The
# raw-folder signals are home-only (an agent persist dir legitimately has subdirs).
# ---------------------------------------------------------------------------
_boot_integrity_has_legacy_data() {
    local persist="${1:-}" type="${2:-}" id="${3:-}"
    [ -z "$persist" ] && return 1
    [ -f "$persist/aicli-agents.img" ] && return 0
    [ -f "$persist/persistence/home_$id.img" ] && return 0
    [ -f "$persist/home_$id.img" ] && return 0
    if [ "$type" = "home" ]; then
        [ -d "$persist/persistence/$id" ] && return 0
        [ -d "$persist/$id" ] && return 0
    fi
    return 1
}

# ---------------------------------------------------------------------------
# _boot_integrity_log <level> <entity> <state> <details>
# Internal helper -- writes one lifecycle log line.
# ---------------------------------------------------------------------------
_boot_integrity_log() {
    local level="${1:-info}"
    local entity="${2:-unknown}"
    local state="${3:-unknown}"
    local details="${4:-}"

    # Escape for JSON
    local safe_entity
    safe_entity="${entity//\"/\\\"}"
    local safe_state
    safe_state="${state//\"/\\\"}"
    local safe_details
    safe_details="${details//\"/\\\"}"

    lifecycle_log "$level" "boot_integrity" "boot_integrity_entity" \
        "{\"entity\":\"$safe_entity\",\"state\":\"$safe_state\",\"details\":\"$safe_details\"}" \
        2>/dev/null || true
}