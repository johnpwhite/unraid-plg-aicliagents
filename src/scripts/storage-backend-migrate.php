#!/usr/bin/env php
<?php
/**
 * Detached worker for the storage migration of one kind (homes or agents).
 * Arguments: target (layering|passthrough), wear acknowledgement (0|1), operation (continue|rollback),
 * kind (homes|agents|all). The kind defaults to all for a worker that an older request queued.
 */

require_once dirname(__DIR__) . '/includes/AICliAgentsManager.php';

use AICliAgents\Services\LogService;
use AICliAgents\Services\StorageBackendMigrationService;

$target = (string)($argv[1] ?? '');
$acknowledgeWear = (string)($argv[2] ?? '0') === '1';
$operation = (string)($argv[3] ?? 'continue');
$kind = (string)($argv[4] ?? 'all');
if (!in_array($target, ['layering', 'passthrough'], true) || !in_array($kind, ['homes', 'agents', 'all'], true)) {
    aicli_log('Storage backend worker refused an invalid target.', AICLI_LOG_ERROR, 'StorageBackendMigration');
    exit(2);
}

try {
    $result = $operation === 'rollback'
        ? StorageBackendMigrationService::restoreInterrupted()
        : StorageBackendMigrationService::runQueuedMigration($target, $acknowledgeWear, $kind);
    if (($result['status'] ?? '') !== 'ok') {
        aicli_log('Storage backend migration worker finished with an error: ' . (string)($result['message'] ?? 'unknown error'), AICLI_LOG_ERROR, 'StorageBackendMigration');
        exit(1);
    }
} catch (Throwable $e) {
    aicli_log('Storage backend migration worker failed: ' . $e->getMessage(), AICLI_LOG_ERROR, 'StorageBackendMigration');
    exit(1);
}
