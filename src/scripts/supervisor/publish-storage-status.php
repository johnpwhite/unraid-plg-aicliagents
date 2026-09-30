<?php
/**
 * Supervisor-tick storage-status publisher (docs/specs/EVENT_FIRST_RECONCILIATION.md
 * 1b.3/1b.4). The supervisor is the ONE process that sees a bake/consolidate/
 * mount/graduate finish and a maintenance-wait marker come and go, but it
 * cannot call PHP in-process — so it spawns this script (same pattern as
 * sync-activity.php) after each such change.
 *
 * Publishes the FULL `aicli_storage_status` snapshot — the same object
 * `get_storage_status` answers with — plus a `maintenance` key that is EXACTLY
 * the object `get_force_reclaim_state` answers with, minus its `status`/`now`
 * envelope. Both sides read the SAME shared static
 * (StorageHandler::forceReclaimState()), so the pushed shape can never drift
 * from what a browser's own reconcile read sees.
 *
 * Best-effort: this script always exits 0, even on a fault, so a publish miss
 * can never wedge the supervisor tick. The browser's own 30 s reconcile read
 * (`get_storage_status` / `get_force_reclaim_state`) is the source of truth
 * and carries the fact even when this push is lost.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/AICliAgentsManager.php';
require_once dirname(__DIR__, 2) . '/includes/handlers/StorageHandler.php';

\AICliAgents\Services\EventActor::$override = ['type' => 'system'];

try {
    $snapshot = \AICliAgents\Services\StorageMetricsService::getStatus();
    if (!is_array($snapshot)) {
        $snapshot = [];
    }
    $maintenance = \AICliAgents\Handlers\StorageHandler::forceReclaimState();
    unset($maintenance['status'], $maintenance['now']);
    $snapshot['maintenance'] = $maintenance;

    \AICliAgents\Services\EventBus::publish('storage.status', [], $snapshot);
} catch (\Throwable $e) {
    // Best-effort — never fail the supervisor tick that spawned this script.
}
exit(0);
