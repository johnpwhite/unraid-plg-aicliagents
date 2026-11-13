#!/usr/bin/env php
<?php
/**
 * Refusal event of the share-filesystem guard for the shell wrapper (docs/specs/SHFS_RECURSIVE_READ_GUARD.md R14, #972).
 * The wrapper (layer 2) starts this script in the background, detached, after it has printed the refusal, so the
 * refusal itself is not slowed. It raises the event `guard.refused` and one Activity tray row, at most one for each
 * workspace in 60 seconds (GuardEventService).
 *
 * Usage: aicli-guard-event.php --layer=shell --agent=<agent id> --kind=<pool|array|network|ancestor> --cmd=<text>
 * The workspace id comes from env AICLI_SESSION_ID. Never fails: exit code is always 0, no output.
 */

if (getenv('AICLI_GUARD_TEST') === '1') {
    exit(0);
}

try {
    $opt = ['layer' => 'shell', 'agent' => '', 'kind' => '', 'cmd' => ''];
    foreach (array_slice($argv ?? [], 1) as $a) {
        if (preg_match('/^--(layer|agent|kind|cmd)=(.*)$/sD', $a, $m)) {
            $opt[$m[1]] = $m[2];
        }
    }
    require_once __DIR__ . '/../includes/Autoload.php';
    \AICliAgents\Autoload::register();
    \AICliAgents\Services\GuardEventService::raiseRefusal($opt['layer'], $opt['agent'], $opt['kind'], $opt['cmd']);
} catch (\Throwable $e) {
    // The guard never fails because an event failed.
}
exit(0);
