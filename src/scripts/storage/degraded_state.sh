#!/bin/bash
# degraded_state.sh — S-05 (#1352): durable degraded flag.
#
# The emergency/degraded flag used to live ONLY at the tmpfs path
# /tmp/unraid-aicliagents/.emergency_mode — gone at reboot, so the boot-time
# integrity sweep could not know the previous session ended degraded (audit
# finding O / S-05). This helper DUAL-WRITES:
#
#   tmpfs   /tmp/unraid-aicliagents/.emergency_mode          (existing consumers
#           — StorageMountService::isEmergencyMode, event handlers — unchanged)
#   durable <boot-config>/state/degraded.json                (survives reboot)
#
# degraded.json shape: {"reason": "...", "set_at": "ISO8601Z", "host": "..."}
# Written atomically (tmp + rename) with a sync — tiny and rare, acceptable on
# USB flash.
#
# Lifecycle:
#   degraded_set <reason>   — set both flags (event/stopping, event/stopping_array)
#   degraded_clear [event]  — remove both; lifecycle log (event/disks_mounted;
#                             boot reconciliation passes 'degraded_autocleared_boot')
#   degraded_get            — print the current reason ('' if not degraded)
#
# Test hooks (mirror AICLI_FAILURES_DIR / AICLI_MANIFEST_PATH precedent; unset in
# production): AICLI_DEGRADED_STATE_DIR redirects the durable dir,
# AICLI_EMERGENCY_FLAG redirects the tmpfs flag.
#
# Sourceable standalone: lifecycle_log is optional (guarded), no other deps.

_DS_EMERGENCY_FLAG="${AICLI_EMERGENCY_FLAG:-/tmp/unraid-aicliagents/.emergency_mode}"
_DS_STATE_DIR="${AICLI_DEGRADED_STATE_DIR:-/boot/config/plugins/unraid-aicliagents/state}"
_DS_DEGRADED_JSON="$_DS_STATE_DIR/degraded.json"

# _ds_lifecycle <level> <event> <payload> — best-effort lifecycle log (the writer
# lives in resolve_paths.sh; a standalone source of this file degrades silently).
_ds_lifecycle() {
    declare -f lifecycle_log >/dev/null 2>&1 || return 0
    lifecycle_log "$1" "degraded_state" "$2" "$3" 2>/dev/null || true
}

# _ds_json_escape <s> — minimal JSON string escaping for reason/host values.
_ds_json_escape() {
    local s="$1"
    s="${s//\\/\\\\}"; s="${s//\"/\\\"}"
    printf '%s' "$s"
}

# degraded_set <reason>
# Sets BOTH the tmpfs flag and the durable degraded.json. Best-effort — never
# fails the caller (shutdown paths must not abort on a flag write).
degraded_set() {
    local reason="${1:-unknown}"
    local now host
    now="$(date -u '+%Y-%m-%dT%H:%M:%SZ' 2>/dev/null || echo '1970-01-01T00:00:00Z')"
    host="$(hostname 2>/dev/null || echo unknown)"

    # tmpfs flag — the existing consumers' contract, unchanged.
    mkdir -p "$(dirname "$_DS_EMERGENCY_FLAG")" 2>/dev/null || true
    : > "$_DS_EMERGENCY_FLAG" 2>/dev/null || true

    # Durable flag — atomic tmp + rename, then sync (vfat /boot: tiny + rare).
    mkdir -p "$_DS_STATE_DIR" 2>/dev/null || true
    {
        printf '{"reason":"%s","set_at":"%s","host":"%s"}\n' \
            "$(_ds_json_escape "$reason")" "$now" "$(_ds_json_escape "$host")" \
            > "$_DS_DEGRADED_JSON.tmp" \
        && mv -f "$_DS_DEGRADED_JSON.tmp" "$_DS_DEGRADED_JSON" \
        && sync "$_DS_DEGRADED_JSON" 2>/dev/null
    } 2>/dev/null || true

    _ds_lifecycle "warn" "degraded_set" "{\"reason\":\"$(_ds_json_escape "$reason")\"}"
    return 0
}

# degraded_clear [lifecycle_event]
# Removes BOTH flags. The optional event name lets the boot-reconciliation caller
# log 'degraded_autocleared_boot' instead of the default 'degraded_cleared'.
degraded_clear() {
    local event="${1:-degraded_cleared}"
    local had=0
    { [ -f "$_DS_EMERGENCY_FLAG" ] || [ -f "$_DS_DEGRADED_JSON" ]; } && had=1
    rm -f "$_DS_EMERGENCY_FLAG" 2>/dev/null || true
    rm -f "$_DS_DEGRADED_JSON" "$_DS_DEGRADED_JSON.tmp" 2>/dev/null || true
    [ "$had" -eq 1 ] && _ds_lifecycle "info" "$event" "{}"
    return 0
}

# degraded_get
# Prints the current degraded reason, or nothing if not degraded. The durable
# record is authoritative (it carries the reason); a tmpfs-only flag (legacy
# writer, e.g. an older event handler) reports 'emergency_mode'.
degraded_get() {
    if [ -f "$_DS_DEGRADED_JSON" ]; then
        # Simple extractor (POSIX sed — no jq/PCRE): stops at the first unescaped
        # quote, which is fine for the enum-style reasons this plugin writes.
        sed -n 's/.*"reason"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' \
            "$_DS_DEGRADED_JSON" 2>/dev/null | head -1
        return 0
    fi
    [ -f "$_DS_EMERGENCY_FLAG" ] && printf 'emergency_mode'
    return 0
}
