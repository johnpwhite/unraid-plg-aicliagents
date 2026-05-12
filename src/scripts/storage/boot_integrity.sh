#!/bin/bash
# boot_integrity.sh -- Phase 4a boot-time integrity classifier (bash mirror of BootIntegrityService).
#
# Usage: source this file, then call boot_integrity_classify <type> <id>
#
# boot_integrity_classify <type> <id>
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
# boot_integrity_classify <type> <id>
#
# type: 'home' or 'agent'
# id:   username or agent id
#
# Echoes the state string. Writes a lifecycle log entry.
# ---------------------------------------------------------------------------
boot_integrity_classify() {
    local type="${1:-}"
    local id="${2:-}"

    if [ -z "$type" ] || [ -z "$id" ]; then
        echo "unknown"
        return 0
    fi

    local persist_path
    if [ "$type" = "home" ]; then
        persist_path="$(home_persist_path "$id" 2>/dev/null)"
    else
        persist_path="$(agent_persist_path 2>/dev/null)"
    fi

    [ -z "$persist_path" ] && persist_path="$PLUGIN_BASE"

    local manifest_json
    manifest_json="$(manifest_path 2>/dev/null)"

    # ---------- Read expected layers from manifest ---------------------------
    local expected_count=0
    local manifest_persist_path=""
    local manifest_host_id=""

    if [ -f "$manifest_json" ] && command -v python3 >/dev/null 2>&1; then
        local entity="${type}/${id}"
        local manifest_data
        manifest_data="$(python3 -c "
import json, sys
try:
    m = json.load(open('$manifest_json'))
    e = m.get('entities', {}).get('$entity', {})
    layers = e.get('expected_layers', [])
    path   = e.get('current_persistence_path', '')
    hid    = m.get('host_id', '')
    print(len(layers))
    print(path)
    print(hid)
except Exception as ex:
    print(0)
    print('')
    print('')
" 2>/dev/null)"
        expected_count=$(echo "$manifest_data" | sed -n '1p')
        manifest_persist_path=$(echo "$manifest_data" | sed -n '2p')
        manifest_host_id=$(echo "$manifest_data" | sed -n '3p')
        expected_count="${expected_count:-0}"
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
            state="genuine_fresh"
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