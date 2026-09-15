#!/bin/bash
# voice-hook.sh — Claude Code Notification/Stop hook that turns the hook's
# stdin JSON into one short sentence and speaks it through this plugin's
# `speak` verb (docs/specs/AGENT_VOICE.md R10).
#
# Hook JSON fields (https://docs.anthropic.com/en/docs/claude-code/hooks):
#   Notification: {session_id, transcript_path, cwd, hook_event_name,
#                  message}
#   Stop:         {session_id, transcript_path, cwd, hook_event_name,
#                  stop_hook_active}
#
# This hook must never fail or block the agent: every path exits 0, and the
# whole run is bounded by `timeout 5` on each admin call.
#
# Wire it in ~/.claude/settings.json — see the "Speak" section of the
# aicli-admin skill (AdminSkillProjector.php) for the exact JSON.

set -u

_ADMIN_CMD="${AICLI_ADMIN_COMMAND:-}"
if [ -z "$_ADMIN_CMD" ]; then
    _ADMIN_CMD="php /usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/admin-agent.php"
fi
# $AICLI_ADMIN_COMMAND is a two-word string ("php <path>") when the CLI
# fallback applies — split it into an array so each word passes through
# unquoted, exec-style, rather than through a shell re-parse.
IFS=' ' read -r -a _ADMIN_ARR <<< "$_ADMIN_CMD"

_HOOK_JSON="$(cat)"

# Pull one top-level string field out of the hook JSON. php is always present
# next to $AICLI_ADMIN_COMMAND's own PHP fallback above, so this never needs
# jq. A field that is absent or the JSON failing to parse both yield "".
_hook_field() {
    php -r '
        $j = json_decode(file_get_contents("php://stdin"), true);
        echo is_array($j) ? (string)($j[$argv[1]] ?? "") : "";
    ' "$1" <<< "$_HOOK_JSON" 2>/dev/null
}

_EVENT="$(_hook_field hook_event_name)"
_MESSAGE="$(_hook_field message)"

# Best-effort workspace name. A lookup failure must never block the
# sentence, so a blank name falls back to a plain "This workspace".
_WS_NAME="$(timeout 5 "${_ADMIN_ARR[@]}" workspace 2>/dev/null | php -r '
    $j = json_decode(file_get_contents("php://stdin"), true);
    $w = is_array($j) ? ($j["workspace"] ?? $j) : [];
    echo is_array($w) ? (string)($w["name"] ?? "") : "";
' 2>/dev/null)"
[ -n "$_WS_NAME" ] || _WS_NAME="This workspace"

case "$_EVENT" in
    Notification)
        [ -n "$_MESSAGE" ] || _MESSAGE="wants your attention"
        _SENTENCE="${_WS_NAME} needs you: ${_MESSAGE}"
        ;;
    Stop)
        _SENTENCE="${_WS_NAME} has finished."
        ;;
    *)
        # An unknown or missing hook_event_name has nothing safe to say.
        exit 0
        ;;
esac

timeout 5 "${_ADMIN_ARR[@]}" speak "$_SENTENCE" > /dev/null 2>&1
exit 0
