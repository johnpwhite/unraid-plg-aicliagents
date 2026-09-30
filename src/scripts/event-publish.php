#!/usr/bin/env php
<?php
/**
 * <module_context>
 *     <name>event-publish</name>
 *     <description>CLI entry so a shell publisher (src/event/stopping,
 *     src/event/stopping_array, generation.sh, migrate-btrfs-to-squashfs.sh)
 *     can publish through the SAME EventBus::publish() every PHP publisher
 *     uses (docs/specs/EVENT_STREAM_MULTIPLEX.md R4), instead of curling
 *     Nchan directly (docs/specs/REVIEW_2026-09-13_EVENTS_AND_SECURITY.md,
 *     finding E2). A raw shell curl carries no `ts` and, for `storage_status`,
 *     only the handful of fields the shell script already happened to know —
 *     this entry always stamps `ts` (NchanService::publish(), EventBus's
 *     transport, does it) and, for `storage_status`, builds the FULL
 *     snapshot the browser's own reconcile read sees, the same way
 *     src/scripts/supervisor/publish-storage-status.php does. The CLI
 *     contract (channel name as argv[1], same exit codes, same stdout JSON)
 *     is unchanged from before EventBus existed — only what happens
 *     internally moved from NchanService::publish() to
 *     EventBus::publish().</description>
 *     <dependencies>AICliAgentsManager.php (pulls in EventBus, NchanService,
 *     EventActor); StorageHandler.php + StorageMetricsService (the
 *     storage_status snapshot, EVENT_FIRST_RECONCILIATION.md 1b.4 — the SAME
 *     shared statics publish-storage-status.php calls, so this never
 *     re-derives a second copy).</dependencies>
 *     <constraints>CLI-only. Every field of the payload passed on the command
 *     line arrives as a JSON string ($argv), never string-interpolated into a
 *     shell command — the caller (nchan_notify() in the event/stopping
 *     scripts) builds it with a JSON literal, not user input. Unlike
 *     event-append.php's always-exit-0 "best-effort" convention, this script
 *     reports its own outcome on stdout/exit code: the selective-kill path
 *     wants to know a `stopped` event actually went out, and an unknown
 *     channel or a malformed payload is a caller bug worth surfacing, not
 *     swallowing.</constraints>
 * </module_context>
 *
 * Usage: php event-publish.php <channel> [json-or-'storage_status']
 *
 *   <channel>   Required. Must be a channel NchanService recognises
 *               (NchanService::CHANNELS, exact key or `prefix_` match),
 *               else exit 2.
 *
 *   [payload]   Optional, meaning depends on <channel>:
 *     - 'storage_status': ignored — the full snapshot
 *       (StorageMetricsService::getStatus() plus the forceReclaimState()
 *       `maintenance` key) is always rebuilt fresh, the same shape
 *       publish-storage-status.php pushes on a supervisor tick. Pass the
 *       literal word `storage_status` for a self-documenting call site.
 *     - 'workspaces': a JSON object shaped like
 *       {"type":"stopped","id":"<workspaceId>"} — ProcessManager::
 *       publishStoppedEvent()'s own shape, with `type` becoming the
 *       payload's `event` key. Required; missing or malformed JSON exits 2.
 *     - any other channel: a JSON object, published as-is (NchanService
 *       still overwrites `ts`). Omit for an empty payload.
 *
 * Prints one JSON line to stdout:
 *   {"status":"ok","channel":"<channel>"}                on success
 *   {"status":"error","message":"..."}                    on a refusal/fault
 * Exit codes: 0 success, 2 a refused/malformed argument, 1 an exception
 * while building or publishing the payload.
 */

require_once __DIR__ . '/../includes/AICliAgentsManager.php';
require_once __DIR__ . '/../includes/handlers/StorageHandler.php';

use AICliAgents\Handlers\StorageHandler;
use AICliAgents\Services\EventActor;
use AICliAgents\Services\EventBus;
use AICliAgents\Services\NchanService;
use AICliAgents\Services\StorageMetricsService;

// This script has no AICLI_SESSION_ID and is never called from a browser —
// every shell event hook that spawns it runs as the array-stop/shutdown
// event itself, attributable to 'system' the same way event-append.php's
// shell callers already are.
EventActor::$override = ['type' => 'system'];

/** True when $channel is registered in NchanService::CHANNELS (exact or prefix). */
function aicliEventPublishChannelKnown(string $channel): bool {
    if (array_key_exists($channel, NchanService::CHANNELS)) {
        return true;
    }
    foreach (array_keys(NchanService::CHANNELS) as $key) {
        if (substr($key, -1) === '_' && strncmp($channel, $key, strlen($key)) === 0) {
            return true;
        }
    }
    return false;
}

$channel = (string)($argv[1] ?? '');
$raw     = (string)($argv[2] ?? '');

if ($channel === '') {
    echo json_encode(['status' => 'error', 'message' => "Usage: event-publish.php <channel> [json-or-'storage_status']"]), PHP_EOL;
    exit(2);
}
if (!aicliEventPublishChannelKnown($channel)) {
    echo json_encode(['status' => 'error', 'message' => "Unknown channel: $channel"]), PHP_EOL;
    exit(2);
}

try {
    if ($channel === 'storage_status') {
        // EVENT_FIRST_RECONCILIATION.md 1b.4: the SAME shared statics the
        // supervisor-tick publisher and the get_storage_status/
        // get_force_reclaim_state AJAX actions call — never a second,
        // shell-driven guess at home_available/agents_available/emergency_mode.
        $snapshot = StorageMetricsService::getStatus();
        if (!is_array($snapshot)) {
            $snapshot = [];
        }
        $maintenance = StorageHandler::forceReclaimState();
        unset($maintenance['status'], $maintenance['now']);
        $snapshot['maintenance'] = $maintenance;
        EventBus::publish('storage.status', [], $snapshot);
    } elseif ($channel === 'workspaces') {
        $data = $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($data) || !isset($data['type'], $data['id']) || (string)$data['type'] === '' || (string)$data['id'] === '') {
            echo json_encode(['status' => 'error', 'message' => 'workspaces payload needs {"type":...,"id":...}']), PHP_EOL;
            exit(2);
        }
        $payload = ['event' => (string)$data['type'], 'id' => (string)$data['id']];
        if (isset($data['reason'])) {
            $payload['reason'] = (string)$data['reason'];
        }
        EventBus::publish('workspace', [], $payload);
    } else {
        $data = $raw !== '' ? json_decode($raw, true) : [];
        if (!is_array($data)) {
            echo json_encode(['status' => 'error', 'message' => 'Payload must be a JSON object.']), PHP_EOL;
            exit(2);
        }
        // The channel was already validated by aicliEventPublishChannelKnown()
        // above (registered in NchanService::CHANNELS / EventBus::CHANNEL_DEPTHS),
        // so kindForChannel() should always resolve here — but never publish
        // blind if the two lists ever disagreed.
        $kind = EventBus::kindForChannel($channel);
        if ($kind === null) {
            echo json_encode(['status' => 'error', 'message' => "Unknown channel: $channel"]), PHP_EOL;
            exit(2);
        }
        $subject = ($kind === 'install') ? ['agentId' => substr($channel, strlen('install_'))] : [];
        EventBus::publish($kind, $subject, $data);
    }
} catch (\Throwable $e) {
    echo json_encode(['status' => 'error', 'message' => 'event-publish failed: ' . $e->getMessage()]), PHP_EOL;
    exit(1);
}

echo json_encode(['status' => 'ok', 'channel' => $channel]), PHP_EOL;
exit(0);
