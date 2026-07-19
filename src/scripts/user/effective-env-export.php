<?php
/** Emit the effective agent environment as shell-safe export statements. */

$manager = $argv[1] ?? '';
$mode = $argv[2] ?? '';
$rootDir = $argv[3] ?? '';
$agentId = $argv[4] ?? '';
$tracker = $argv[5] ?? '';

if (!is_file($manager) || !in_array($mode, ['startup', 'reload'], true)) {
    fwrite(STDERR, "usage: effective-env-export.php <manager> <startup|reload> <root> <agent> [tracker]\n");
    exit(2);
}

require_once $manager;

$env = \AICliAgents\Services\EnvService::buildEffectiveEnv($rootDir !== '' ? $rootDir : null, $agentId);
$names = [];
foreach ($env as $key => $value) {
    if (\AICliAgents\Services\EnvService::isReservedKey($key)) {
        continue;
    }
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
        continue;
    }
    echo 'export ' . $key . '=' . escapeshellarg($value) . PHP_EOL;
    $names[] = $key;
}

if ($mode === 'startup' && $tracker !== '') {
    @file_put_contents($tracker, implode(PHP_EOL, $names) . PHP_EOL);
}
if ($mode === 'reload') {
    echo '_AICLI_NEW_KEYS=' . escapeshellarg(implode(' ', $names)) . PHP_EOL;
}
