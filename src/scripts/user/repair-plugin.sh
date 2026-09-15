#!/bin/bash
# AICliAgents current-architecture repair utility (#96).
#
# This command is deliberately fail-closed. It never formats, deletes, migrates,
# consolidates, recreates, or reinstalls storage. Repair classifies durable state
# first, then routes known-good entities through the authoritative FileStorage
# facade. Unsafe entities are reported for explicit recovery instead of guessed at.
set -u

PLUGIN_DIR="${AICLI_REPAIR_PLUGIN_DIR:-/usr/local/emhttp/plugins/unraid-aicliagents}"
PHP_BIN="${AICLI_REPAIR_PHP:-php}"
STATUS_FILE="${AICLI_REPAIR_STATUS_FILE:-/tmp/unraid-aicliagents/repair-status}"
MODE="apply"
[ "${1:-}" = "--check" ] && MODE="check"
mkdir -p "$(dirname "$STATUS_FILE")"

json_escape() {
    printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g'
}

log_progress() {
    local pct="$1" msg="$2" state="${3:-running}"
    printf '[%s] PROGRESS: %s%% | %s\n' "$(date +'%Y-%m-%d %H:%M:%S')" "$pct" "$msg"
    printf '{"progress":%s,"message":"%s","state":"%s","timestamp":%s}\n' \
        "$pct" "$(json_escape "$msg")" "$state" "$(date +%s)" > "$STATUS_FILE"
}

echo "--- AICliAgents Fail-Closed Repair Start ---"
log_progress 5 "Validating current repair components..."

HELPER="$PLUGIN_DIR/src/scripts/user/repair-current.php"
MANAGER="$PLUGIN_DIR/src/includes/AICliAgentsManager.php"
if [ ! -f "$HELPER" ] || [ ! -f "$MANAGER" ] || ! command -v "$PHP_BIN" >/dev/null 2>&1; then
    log_progress 100 "Repair components are unavailable; no changes were made." "failed"
    exit 1
fi

log_progress 20 "Classifying manifest and storage integrity..."
set +e
if [ "$MODE" = "check" ]; then
    RESULT="$("$PHP_BIN" -d display_errors=0 "$HELPER" --check 2>/dev/null)"
else
    RESULT="$("$PHP_BIN" -d display_errors=0 "$HELPER" --apply 2>/dev/null)"
fi
RC=$?
set -e

if [ "$RC" -ne 0 ]; then
    log_progress 100 "Repair stopped safely; durable storage was not deleted or reformatted. Result: $RESULT" "failed"
    printf '%s\n' "$RESULT"
    exit "$RC"
fi

if [ "$MODE" = "check" ]; then
    log_progress 100 "Repair check passed; no storage mutations were requested." "complete"
else
    log_progress 100 "Repair completed through current FileStorage services." "complete"
fi
printf '%s\n' "$RESULT"
echo "--- AICliAgents Fail-Closed Repair Complete ---"
