<?php
/**
 * run-user-prepare.php — prepare the home of the restricted run user for one launch (docs/specs/AGENT_RUN_ENTRY_POINT.md R16, finding 4).
 *
 *   php run-user-prepare.php <agentId> <home> <signin-home>
 *   php run-user-prepare.php --sync-signin <session-home> <signin-home>
 *
 * It runs AS THE RUN USER (aicli-run), started with a clean environment. It projects the Config Hub choices into a new home,
 * repairs the Claude plugin paths, or saves known sign-in files from a closed workspace.
 * Root never writes in this home, so a link that the run user plants there is followed only by a process that has the rights of the run
 * user, and no file of root can change owner or content through it.
 */

declare(strict_types=1);

$mode = (string)($argv[1] ?? '');
$agentId = $mode === '--sync-signin' ? '' : $mode;
$home = rtrim((string)($argv[2] ?? ''), '/');
$authHome = rtrim((string)($argv[3] ?? ''), '/');
if ($mode === '--sync-signin') {
    $sourceHome = $home;
    $targetHome = $authHome;
    if ($sourceHome === '' || $sourceHome[0] !== '/' || $targetHome === '' || $targetHome[0] !== '/' || $sourceHome === $targetHome) {
        fwrite(STDERR, "usage: run-user-prepare.php --sync-signin <session-home> <signin-home>\n");
        exit(2);
    }
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        fwrite(STDERR, "run-user-prepare.php refuses to run as root\n");
        exit(3);
    }
    require_once dirname(__DIR__, 2) . '/includes/AICliAgentsManager.php';
    $saved = \AICliAgents\Services\RunUserService::copySigninFiles($sourceHome, $targetHome, true);
    if (empty($saved['ok'])) {
        fwrite(STDERR, 'run-user-prepare.php: could not save sign-in files (' . (string)($saved['reason'] ?? 'unknown') . ")\n");
        exit(7);
    }
    exit(0);
}
if (preg_match('/^[a-z0-9][a-z0-9_-]{0,40}$/D', $agentId) !== 1 || $home === '' || $home[0] !== '/' || $authHome === '' || $authHome[0] !== '/') {
    fwrite(STDERR, "usage: run-user-prepare.php <agentId> <home> <signin-home>\n");
    exit(2);
}
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    // This job must never run as root: that would bring back the root write through a link.
    fwrite(STDERR, "run-user-prepare.php refuses to run as root\n");
    exit(3);
}

require_once dirname(__DIR__, 2) . '/includes/AICliAgentsManager.php';

$copied = \AICliAgents\Services\RunUserService::copySigninFiles($authHome, $home);
if (empty($copied['ok'])) {
    fwrite(STDERR, 'run-user-prepare.php: could not copy sign-in files (' . (string)($copied['reason'] ?? 'unknown') . ")\n");
    exit(4);
}

// The home starts empty for each run. Remove command hooks from any copied sign-in data before Config Hub adds the user's
// approved settings. The projection never writes a command hook.
$neutral = \AICliAgents\Services\RunUserService::neutraliseHome($home);
if (!$neutral['ok']) {
    fwrite(STDERR, 'run-user-prepare.php: ' . $neutral['reason'] . "\n");
    exit(4);
}
$result = \AICliAgents\Services\Hub\HubProjector::projectRunUserForLaunch($agentId, null, $home);
if (($result['status'] ?? '') !== 'ok' && !($agentId === 'terminal' && ($result['status'] ?? '') === 'skipped')) {
    fwrite(STDERR, 'run-user-prepare.php: Config Hub projection failed (' . (string)($result['reason'] ?? 'unknown') . ")\n");
    exit(5);
}
if ($agentId === 'claude-code') {
    $repair = \AICliAgents\Services\ClaudePluginPathService::repairHome($home);
    if (is_array($repair) && isset($repair['ok']) && empty($repair['ok'])) {
        fwrite(STDERR, "run-user-prepare.php: Claude plugin paths could not be prepared\n");
        exit(6);
    }
}
exit(0);
