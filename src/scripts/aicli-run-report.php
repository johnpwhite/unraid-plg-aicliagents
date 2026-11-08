#!/usr/bin/env php
<?php
// aicli-run-report: the agent of a restricted script run calls this to report its result (spec R18).
// It runs as the run user aicli-run, not as root. It loads only the class loader, never the whole Manager,
// because the run user cannot read the plugin state.
require_once dirname(__DIR__) . '/includes/Autoload.php';
\AICliAgents\Autoload::register();

if ($argc < 3) {
    fwrite(STDERR, "Usage: aicli-run-report <done|failed|blocked> <summary>\n");
    exit(2);
}

$result = \AICliAgents\Services\RunUserService::writeRunUserReport((string)$argv[1], implode(' ', array_slice($argv, 2)));
if (($result['status'] ?? '') !== 'ok') {
    fwrite(STDERR, (string)($result['error'] ?? 'The run report could not be saved.') . "\n");
    exit(1);
}
fwrite(STDOUT, "Report saved.\n");
