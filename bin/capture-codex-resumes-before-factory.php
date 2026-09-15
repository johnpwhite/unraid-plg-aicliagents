#!/usr/bin/env php
<?php

declare(strict_types=1);

// The Factory worker runs this from the source checkout before invoking the
// currently installed plugin's stop path. That installed version may predate
// Codex disk discovery, so load its services/config but deliberately load the
// source checkout's TerminalHandler before the shutdown bridge asks for it.
$manager = getenv('AICLI_FACTORY_LIVE_MANAGER')
    ?: '/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/AICliAgentsManager.php';
$handler = getenv('AICLI_FACTORY_SOURCE_HANDLER')
    ?: dirname(__DIR__) . '/src/includes/handlers/TerminalHandler.php';

if (!is_file($manager) || !is_file($handler)) {
    fwrite(STDERR, "Factory resume capture prerequisites are missing.\n");
    exit(2);
}

$_SERVER['DOCUMENT_ROOT'] = '/usr/local/emhttp';
require_once $manager;
require_once $handler;

use AICliAgents\Services\TerminalService;

try {
    $codexSessionIds = [];
    foreach (TerminalService::enumerateAllLiveSessions() as $session) {
        if (($session['agentId'] ?? '') === 'codex-cli' && !empty($session['id'])) {
            $codexSessionIds[] = (string)$session['id'];
        }
    }

    if ($codexSessionIds === []) {
        fwrite(STDOUT, "No live Codex sessions require pre-capture.\n");
        exit(0);
    }

    // A zero budget deliberately runs only pass 1: workspace-scoped disk
    // discovery + durable save. The installed stop path remains responsible
    // for quiescing and closing all agents immediately afterwards.
    $result = TerminalService::captureResumeForShutdown($codexSessionIds, 0);
    $saved = (int)($result['fallback_saved'] ?? 0);
    $expected = count($codexSessionIds);
    if ($saved !== $expected) {
        fwrite(STDERR, "Captured $saved/$expected live Codex resume identifiers; refusing shutdown.\n");
        exit(3);
    }

    fwrite(STDOUT, "Captured $saved/$expected live Codex resume identifiers.\n");
} catch (Throwable $e) {
    fwrite(STDERR, "Factory Codex resume capture failed: {$e->getMessage()}\n");
    exit(4);
}
