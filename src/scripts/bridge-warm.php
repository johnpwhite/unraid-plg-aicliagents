<?php

declare(strict_types=1);

/**
 * bridge-warm.php — TERMINAL_BACKGROUND_WARM.md R2 (2026-09-29).
 *
 * Spawned detached by `reconnect_stale_bridges` right after its bridge refresh
 * sweep (#142 / AUTO_RECONNECT_ALL_ON_DEPLOY.md) SIGTERMed the ttyd of each
 * listed workspace. For each id, one after the other: wait for the old ttyd to
 * exit, then attach a new bridge to the still-running tmux session. It never
 * starts an agent (BridgeWarmService::attach checks the session under the
 * workspace lock and skips --ensure-session).
 *
 * Usage: php bridge-warm.php <sessionId> [<sessionId> ...]
 */

$_SERVER['DOCUMENT_ROOT'] = '/usr/local/emhttp';
require_once dirname(__DIR__) . '/includes/AICliAgentsManager.php';

use AICliAgents\Services\BridgeWarmService;
use AICliAgents\Services\EventActor;

if (class_exists('\AICliAgents\Services\EventActor')) {
    EventActor::$override = ['type' => 'system'];
}

try {
    $ids = array_slice($argv ?? [], 1);
    BridgeWarmService::respawnAfterRefresh($ids);
} catch (\Throwable $e) {
    fwrite(STDERR, 'bridge-warm: ' . $e->getMessage() . "\n");
}
exit(0);
