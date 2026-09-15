#!/usr/bin/env php
<?php

declare(strict_types=1);

$_SERVER['DOCUMENT_ROOT'] = '/usr/local/emhttp';
$manager = getenv('AICLI_REPAIR_MANAGER') ?: '/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/AICliAgentsManager.php';
require_once $manager;

// A source-tree release candidate can be checked against the installed
// manager before publishing. Deployed releases load this through the manager.
if (!class_exists(\AICliAgents\Services\RepairService::class, false)) {
    require_once dirname(__DIR__, 2) . '/includes/services/RepairService.php';
}

use AICliAgents\Services\RepairService;

$apply = !in_array('--check', $argv, true);
try {
    $result = RepairService::run($apply);
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_SLASHES) . "\n");
    exit($result['ok'] ? 0 : 2);
} catch (Throwable $e) {
    fwrite(STDOUT, json_encode([
        'ok' => false,
        'mode' => $apply ? 'apply' : 'check',
        'failures' => [['entity' => 'repair', 'reason' => $e->getMessage()]],
    ], JSON_UNESCAPED_SLASHES) . "\n");
    exit(3);
}
