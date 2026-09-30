<?php
/**
 * Boot storage-consolidation worker (#129).
 *
 * Spawned detached (nohup) by AutoLaunchService::planBootConsolidation during the
 * first boot autolaunch sweep. For each home flagged as recommended-and-idle, it
 * consolidates the OverlayFS layer stack while the home is guaranteed idle (its
 * sessions were held back from the sweep), then relaunches just that home's
 * sessions. Fine homes were already launched by the sweep, so this runs off the
 * critical path and never blocks boot.
 *
 * Argument: path to a JSON job file: {"users": {"<user>": ["<sid>", ...], ...}}.
 * The job file is consumed (deleted) on start.
 *
 * Safety: consolidation only proceeds while the home is still idle. If a
 * concurrent trigger already brought a session up, StorageMountService::consolidate
 * defers on the busy overlay anyway — so the worst case is "no consolidation this
 * boot", never a closed live session. Sessions are always relaunched regardless of
 * the consolidate outcome.
 */

require_once dirname(__DIR__) . '/includes/AICliAgentsManager.php';

use AICliAgents\Services\StorageMountService;
use AICliAgents\Services\AutoLaunchService;
use AICliAgents\Services\ProcessManager;
use AICliAgents\Services\ConsolidateState;
use AICliAgents\Services\LogService;

require_once dirname(__DIR__) . '/includes/services/ConsolidateState.php';

/**
 * #131: raise an Unraid notification (dynamix) so the user is told a large
 * storage tidy-up is underway even if they are not on the plugin page — it
 * persists in the notification centre and is therefore visible on next login.
 * One at start, one at finish; never per-tick.
 */
function bcr_notify(string $subject, string $message, string $icon = 'normal'): void {
    $notify = '/usr/local/emhttp/plugins/dynamix/scripts/notify';
    if (!is_executable($notify)) return;
    @exec($notify . ' -e ' . escapeshellarg('AI CLI Agents')
        . ' -s ' . escapeshellarg($subject)
        . ' -m ' . escapeshellarg($message)
        . ' -i ' . escapeshellarg($icon));
}

$jobFile = $argv[1] ?? '';
if ($jobFile === '' || !is_file($jobFile)) {
    fwrite(STDERR, "boot-consolidate-relaunch: missing job file\n");
    exit(2);
}
$raw = (string)@file_get_contents($jobFile);
@unlink($jobFile);
$job = json_decode($raw, true);
if (!is_array($job) || empty($job['users']) || !is_array($job['users'])) {
    exit(0);
}

foreach ($job['users'] as $user => $sids) {
    $user = (string)$user;
    if ($user === '') continue;
    $sids = array_values(array_map('strval', (array)$sids));

    // Defensive idle re-check: a concurrent trigger may have launched one of this
    // home's sessions between the sweep and now. If so, skip consolidation (it
    // would only defer on the busy overlay) and go straight to relaunch.
    $idle = true;
    foreach ($sids as $sid) {
        if (ProcessManager::isRunning($sid)) { $idle = false; break; }
    }

    if ($idle) {
        // Mark the home as consolidating BEFORE the bake so every relaunch path
        // (the supervisor's crash-reconcile, array-start sweeps, interactive start)
        // holds off until it finishes — otherwise a racing relaunch re-opens the
        // overlay and the consolidate's reclaim/swap defers, leaving the layers
        // untouched (#129 follow-up). Epoch-safe clear so we only clear our own.
        $epoch = ConsolidateState::markHomeConsolidating($user);
        $deferred = false;
        LogService::log("Boot consolidate: home/$user recommended and idle — consolidating before relaunch (#129)", LogService::LOG_INFO, 'BootConsolidate');
        bcr_notify('Storage tidy-up started',
            "Consolidating the $user home to reclaim disk space. This can take a few minutes; your sessions will start automatically when it finishes.",
            'normal');
        try {
            StorageMountService::consolidate('home', $user, $deferred);
            if ($deferred) {
                LogService::log("Boot consolidate: home/$user deferred (overlay busy) — supervisor will retry; relaunching sessions now", LogService::LOG_WARN, 'BootConsolidate');
            }
            bcr_notify('Storage tidy-up finished',
                "The $user home storage was tidied up. Your workspaces are starting now.",
                'normal');
        } catch (\Throwable $e) {
            LogService::log("Boot consolidate: home/$user failed (" . $e->getMessage() . ") — relaunching sessions anyway", LogService::LOG_ERROR, 'BootConsolidate');
        } finally {
            // Clear before relaunch so this home's sessions can come back up.
            ConsolidateState::clearHomeConsolidating($user, $epoch);
        }
    } else {
        LogService::log("Boot consolidate: home/$user no longer idle — skipping consolidation, relaunching sessions", LogService::LOG_INFO, 'BootConsolidate');
    }

    // Relaunch this home's sessions regardless of the consolidate outcome. The
    // 'boot_post_consolidate' reason is not a BOOT_REASON, so this does not
    // re-enter the split.
    AutoLaunchService::launchAllPending(null, 'boot_post_consolidate', $sids);
}

exit(0);
