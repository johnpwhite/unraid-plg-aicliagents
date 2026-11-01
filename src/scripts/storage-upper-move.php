#!/usr/bin/env php
<?php
/**
 * Detached worker for the move of the writable layer of the homes (RAM or disk), #880.
 * Arguments: target (zram|disk), job id (hex; the worker runs only the queued record that carries it). Started by UpperPlacementService::startAsync() through the shared
 * launcher (the command-line PHP, never the PHP of the web server).
 */

require_once dirname(__DIR__) . '/includes/AICliAgentsManager.php';

use AICliAgents\Services\UpperPlacementService;

$target = (string)($argv[1] ?? '');
$jobId = preg_replace('/[^a-f0-9]/', '', (string)($argv[2] ?? '')); // the job this worker was started for
if (!in_array($target, UpperPlacementService::MODES, true)) {
    aicli_log('Writable-layer move worker refused an invalid target.', AICLI_LOG_ERROR, 'UpperPlacement');
    exit(2);
}

try {
    $result = UpperPlacementService::runQueuedMove($target, [], $jobId);
    if (($result['status'] ?? '') !== 'ok') {
        aicli_log('Writable-layer move worker finished with an error: ' . (string)($result['message'] ?? 'unknown error'), AICLI_LOG_ERROR, 'UpperPlacement');
        exit(1);
    }
} catch (Throwable $e) {
    aicli_log('Writable-layer move worker failed: ' . $e->getMessage(), AICLI_LOG_ERROR, 'UpperPlacement');
    exit(1);
}
