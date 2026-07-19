<?php
/**
 * apply-tmux-json.php — emit `tmux set-option` commands for an allow-listed
 * JSON settings file. Invoked by aicli-shell.sh's apply_tmux_json helper:
 *
 *   php apply-tmux-json.php <settings.json> <target-session> | bash
 *
 * T-03: the allowed/append key lists are single-sourced from
 * TmuxService::ALLOWED_KEYS / ::APPEND_KEYS — any future extension of the PHP
 * consts is automatically reflected in the shell tier. Lives in a script file
 * (not `php -r`) per the publish anti-pattern rule: backslash namespaces
 * inside a double-quoted `php -r` body are eaten by bash.
 *
 * Every option is targeted at one session. APPEND_KEYS emit `-a -t`; all other
 * keys emit `-t`. The tmux server is shared by every workspace, so `-g` would
 * allow the last attached workspace to overwrite all others (#67).
 */

declare(strict_types=1);

$srcRoot = dirname(__DIR__, 2);
require_once $srcRoot . '/includes/AICliAgentsManager.php';
require_once $srcRoot . '/includes/services/TmuxService.php';

$jsonfile = $argv[1] ?? '';
$target = $argv[2] ?? '';
if ($jsonfile === '' || !is_file($jsonfile) || !preg_match('/^[A-Za-z0-9_-]{1,160}$/', $target)) {
    exit(0); // nothing to apply — mirrors the shell helper's [ -f ] guard
}

$allowed = \AICliAgents\Services\TmuxService::ALLOWED_KEYS;
$append  = \AICliAgents\Services\TmuxService::APPEND_KEYS;

$s = json_decode((string)@file_get_contents($jsonfile), true);
if (!is_array($s)) {
    exit(0);
}

foreach ($s as $k => $v) {
    if (!is_string($k)) continue;
    if ($v === '' || $v === null) continue;
    $isAppend = in_array($k, $append, true);
    if (!in_array($k, $allowed, true) && !$isAppend) continue;
    $appendFlag = $isAppend ? '-a ' : '';
    echo 'tmux set-option ' . $appendFlag . '-t ' . escapeshellarg($target) . ' '
        . escapeshellarg($k) . ' ' . escapeshellarg((string)$v) . "\n";
}
