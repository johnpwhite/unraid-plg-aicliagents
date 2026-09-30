<?php
/**
 * <module_context>
 *     <name>EventStream</name>
 *     <description>Emits the channel list and the script tag of the page's ONE
 *     live-update connection (assets/ui/aicli-events.js,
 *     docs/specs/EVENT_STREAM_MULTIPLEX.md R1). Both .page files require this
 *     BEFORE the tray, voice.js and the SPA bundle, so every consumer finds
 *     `window.aicliEvents` already there.</description>
 *     <dependencies>getAICliAgentsRegistry() (AICliAgentsManager.php) for the
 *     per-agent install channels.</dependencies>
 *     <constraints>The ORDER of the list is load-bearing: nchan marks the
 *     source channel of a multiplexed message by its position in the URL, and
 *     aicli-events.js maps that position back through this same list. Names
 *     are the short form (no `aicli_` prefix) and must match
 *     [A-Za-z0-9_.-]+ — an agent id that does not is left out, never
 *     escaped, because a comma would shift every later position.</constraints>
 * </module_context>
 */

if (!function_exists('aicli_event_stream_channels')) {
    /**
     * The ordered short channel names one page subscribes to.
     * Every registered agent gets its install channel, installed or not: a
     * first install is exactly when live progress matters.
     *
     * @param array<int,string> $agentIds
     * @return array<int,string>
     */
    function aicli_event_stream_channels(array $agentIds): array {
        $channels = [
            'activity', 'workspaces', 'storage_status', 'deploy',
            'favourites', 'voicemail', 'voice', 'migrate_progress',
        ];
        foreach ($agentIds as $agentId) {
            $agentId = (string) $agentId;
            if ($agentId === '' || !preg_match('/^[A-Za-z0-9_.\-]+$/', $agentId)) {
                continue;
            }
            $name = 'install_' . $agentId;
            if (!in_array($name, $channels, true)) {
                $channels[] = $name;
            }
        }
        return $channels;
    }
}

$aicli_es_agent_ids = [];
try {
    if (function_exists('getAICliAgentsRegistry')) {
        $aicli_es_registry = getAICliAgentsRegistry();
        if (is_array($aicli_es_registry)) {
            $aicli_es_agent_ids = array_keys($aicli_es_registry);
        }
    }
} catch (\Throwable $e) {
    // No install channels on a broken registry; the 30 s reconcile reads cover it.
    $aicli_es_agent_ids = [];
}
$aicli_es_file = '/usr/local/emhttp/plugins/unraid-aicliagents/assets/ui/aicli-events.js';
// Own mtime cache-buster: a session-safe overlay can replace this one file.
$aicli_es_ver = (string) (@filemtime($aicli_es_file) ?: time());
?>
<script>window.AICLI_EVENT_CHANNELS = <?= json_encode(aicli_event_stream_channels($aicli_es_agent_ids), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script src="/plugins/unraid-aicliagents/assets/ui/aicli-events.js?v=<?= htmlspecialchars($aicli_es_ver) ?>"></script>
