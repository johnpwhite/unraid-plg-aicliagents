#!/usr/bin/env php
<?php

declare(strict_types=1);

$_SERVER['DOCUMENT_ROOT'] = '/usr/local/emhttp';
$manager = getenv('AICLI_REPAIR_MANAGER') ?: dirname(__DIR__, 2) . '/includes/AICliAgentsManager.php';
require_once $manager;

// A source-tree release candidate can be checked against the installed
// manager before publishing. Deployed releases load RepairService through the
// manager's class loader (Forgejo #835), so this asks the loader (no `false`).
// Only a manager that cannot find the class (an older installed one) falls back
// to the file of this script's own tree.
if (!class_exists(\AICliAgents\Services\RepairService::class)) {
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
