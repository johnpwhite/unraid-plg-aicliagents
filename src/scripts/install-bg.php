<?php
/**
 * <module_context>
 *     <name>install-bg</name>
 *     <description>Background Install Wrapper for AI CLI Agents</description>
 *     <dependencies>AICliAgentsManager</dependencies>
 *     <constraints>Detached from WebUI process.</constraints>
 * </module_context>
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');

// Background Fatal Error Handler
register_shutdown_function(function() use ($argv) {
    $error = error_get_last();
    $agentId = $argv[1] ?? 'unknown';
    if ($error !== NULL && ($error['type'] === E_ERROR || $error['type'] === E_PARSE)) {
        $msg = "FATAL INSTALL ERROR for $agentId: {$error['message']} in {$error['file']} on line {$error['line']}";
        require_once "/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/AICliAgentsManager.php";
        aicli_log($msg, AICLI_LOG_ERROR, "InstallBG");
        setInstallStatus("Fatal Error: Check logs", 0, $agentId, $msg);
    }
});

// 1. Define base path
$pluginDir = "/usr/local/emhttp/plugins/unraid-aicliagents";

// 2. Include the manager logic
require_once "$pluginDir/includes/AICliAgentsManager.php";

// 3. Get agent ID, optional target version, optional backup dest from argv.
// argv[2] (version) and argv[3] (backup dest) are always passed by
// AgentHandler::install — empty string means "unset".
$agentId = $argv[1] ?? '';
$targetVersion = (($argv[2] ?? '') !== '') ? $argv[2] : null;
$backupDest = ($argv[3] ?? '');

if (empty($agentId)) {
    aicli_log("Background Install Job aborted: Missing Agent ID", AICLI_LOG_ERROR);
    exit(1);
}

$verLabel = $targetVersion ? " (version: $targetVersion)" : " (latest)";
aicli_log("Background Install Job Started for: $agentId$verLabel (PID: " . getmypid() . ")", AICLI_LOG_INFO);

// T-08 (ACTIVITY_TRAY.md): (re-)register the install activity with this worker's
// pid/pgid so cancel_activity can kill the whole process group (npm children
// included). Every setInstallStatus call from here on refreshes heartbeatAt via
// the UtilityService choke point; the lazy watchdog in ActivityService marks the
// op stalled at 120s of silence and failed at the 20-min hard cap.
\AICliAgents\Services\ActivityService::register(
    "install_$agentId",
    $targetVersion !== null ? 'upgrade' : 'install',
    ($targetVersion !== null ? "Upgrading $agentId to $targetVersion" : "Installing $agentId"),
    [
        'step'     => 'Starting installation job...',
        'progress' => 5,
        'pid'      => getmypid(),
        'pgid'     => function_exists('posix_getpgid') ? @posix_getpgid(getmypid()) : null,
    ]
);

// WP #859: gate the binary swap on the pre-install home bake completing.
// AgentHandler::install enqueued a home bake before spawning this script;
// wait for the supervisor to pick it up and drain. Bounded by 30s; on
// timeout we proceed anyway (commit_stack.sh's marker-timestamp guard
// still protects against data loss — see AGENT_UPGRADE_HOME_BAKE_SYNC.md).
$cfg = function_exists('getAICliConfig') ? getAICliConfig() : [];
$user = $cfg['user'] ?? 'root';
if (empty($user)) $user = 'root';
$wait = \AICliAgents\Services\SupervisorService::waitForOpsToDrain('home', $user, 30, 6);
aicli_log("Pre-install bake wait: {$wait['status']} (waited {$wait['waited_s']}s, user=$user)", AICLI_LOG_INFO);
\AICliAgents\Services\LifecycleLogService::log(
    \AICliAgents\Services\LifecycleLogService::LEVEL_INFO,
    'installer',
    'pre_install_bake_wait',
    ['agent' => $agentId, 'user' => $user, 'status' => $wait['status'], 'waited_s' => $wait['waited_s']]
);

// WP #964 (slice): pre-upgrade backup. Runs BEFORE installAgent touches the
// binary, so the current version is still intact. The overlay already
// space-checked (backup + new version + 10%); if the copy still fails here
// we ABORT the upgrade — the user asked for a rollback copy, so silently
// proceeding without one would defeat the point, and the current install is
// untouched and usable.
if ($backupDest !== '') {
    setInstallStatus("Backing up current version...", 3, $agentId);
    aicli_log("Pre-upgrade backup of $agentId requested → $backupDest", AICLI_LOG_INFO);
    $backup = \AICliAgents\Services\InstallerService::backupAgentVersion($agentId, $backupDest);
    if (($backup['status'] ?? '') !== 'ok') {
        $bmsg = $backup['message'] ?? 'unknown error';
        aicli_log("Pre-upgrade backup FAILED for $agentId: $bmsg — upgrade aborted", AICLI_LOG_ERROR);
        setInstallStatus("Backup failed — upgrade aborted (current version intact)", 0, $agentId, $bmsg);
        \AICliAgents\Services\LifecycleLogService::log(
            \AICliAgents\Services\LifecycleLogService::LEVEL_ERROR, 'installer',
            'agent_install_aborted_backup_failed', ['agent' => $agentId, 'error' => $bmsg]);
        exit(1);
    }
    aicli_log("Pre-upgrade backup OK for $agentId → " . ($backup['dir'] ?? '?'), AICLI_LOG_INFO);
}

// 4. Run the install
try {
    $result = \AICliAgents\Services\InstallerService::installAgent($agentId, $targetVersion);
    if (isset($result['status']) && $result['status'] === 'error') {
        aicli_log("Background Install Job FAILED for $agentId: " . ($result['message'] ?? $result['error'] ?? 'Unknown Error'), AICLI_LOG_ERROR);
        \AICliAgents\Services\LifecycleLogService::log(\AICliAgents\Services\LifecycleLogService::LEVEL_ERROR, 'installer', 'agent_install_failed', ['agent' => $agentId, 'version' => $targetVersion ?? 'latest', 'error' => $result['message'] ?? $result['error'] ?? 'Unknown Error']);
    } else {
        aicli_log("Background Install Job Complete for: $agentId", AICLI_LOG_INFO);
        \AICliAgents\Services\LifecycleLogService::log(\AICliAgents\Services\LifecycleLogService::LEVEL_INFO, 'installer', 'agent_installed', ['agent' => $agentId, 'version' => $targetVersion ?? 'latest']);

        // Bug #716: do NOT enqueue a second bake here.
        // InstallerService::installAgent already calls StorageMountService::commitChanges
        // (see InstallerService.php ~line 108), which runs commit_stack.sh synchronously.
        // A redundant SupervisorService::enqueue('bake') was the second concurrent bake
        // that raced the install bake's remount step, causing "Install Failed (Bake Error)".

        // R3: verify the freshly-baked agent layer is actually LIVE before we
        // relaunch. After the bake a new layer is written to the persistence
        // dir; if a session pinned the agent mount the refresh deferred and the
        // OLD binary is still live. Sessions are closed now, so force the refresh.
        // $layerLive stays true when there is no layer file to check (fresh
        // install with no persistence layer) so that normal relaunch proceeds.
        $layerLive = true;
        $persistDir = '/boot/config/plugins/unraid-aicliagents/persistence';
        $newest = \AICliAgents\Services\InstallerService::newestAgentLayer($agentId, $persistDir);
        if ($newest !== null) {
            $mounts = (string)@file_get_contents('/proc/mounts');
            if (!\AICliAgents\Services\InstallerService::isAgentLayerLive($agentId, $newest, $mounts)) {
                \AICliAgents\Services\LifecycleLogService::log(
                    \AICliAgents\Services\LifecycleLogService::LEVEL_INFO, 'installer',
                    'agent_refresh_forced', ['agent' => $agentId, 'layer' => $newest]);
                $ok = \AICliAgents\Services\InstallerService::forceAgentRefresh($agentId);
                $mounts2 = (string)@file_get_contents('/proc/mounts');
                $layerLive = $ok && \AICliAgents\Services\InstallerService::isAgentLayerLive($agentId, $newest, $mounts2);
                if (!$layerLive) {
                    \AICliAgents\Services\LifecycleLogService::log(
                        \AICliAgents\Services\LifecycleLogService::LEVEL_WARN, 'installer',
                        'agent_refresh_still_deferred', ['agent' => $agentId, 'layer' => $newest]);
                }
            }
        }

        // R4: relaunch EXACTLY the sessions we closed for this upgrade (resumed),
        // independent of the autoLaunch flag. Falls back cleanly (no-op) when no
        // sessions were open. Replaces the autoLaunch-keyed launchAllPending here.
        // Gate the entire relaunch on $layerLive: if the forced refresh still
        // left the old binary mounted, relaunching would re-pin the stale layer
        // and reproduce the original bug (spec: AGENT_UPGRADE_SESSION_RELAUNCH.md
        // Edge Cases). Leave the manifest in place so a later retry can pick it up.
        require_once "$pluginDir/includes/services/UpgradeRelaunchService.php";
        if ($layerLive) {
            if (!empty(\AICliAgents\Services\UpgradeRelaunchService::readManifest($agentId))) {
                $rl = \AICliAgents\Services\UpgradeRelaunchService::relaunchClosedSet($agentId);
                aicli_log("Upgrade relaunch for $agentId: relaunched={$rl['relaunched']} skipped={$rl['skipped']}", AICLI_LOG_INFO);
            } else {
                // No upgrade manifest (e.g. fresh install with no prior session):
                // keep the original autoLaunch sweep so configured workspaces start.
                \AICliAgents\Services\AutoLaunchService::launchAllPending($agentId, 'agent_install');
            }
        } else {
            // Layer not live after forced refresh — do NOT relaunch (would pin the
            // stale binary). Manifest intentionally left in place for a later retry.
            aicli_log("Upgrade: agent layer for $agentId not live after forced refresh — skipping relaunch to avoid pinning the stale binary; manifest retained", AICLI_LOG_WARN);
            \AICliAgents\Services\LifecycleLogService::log(
                \AICliAgents\Services\LifecycleLogService::LEVEL_WARN, 'installer',
                'relaunch_skipped_layer_stale', ['agent' => $agentId]);
        }
    }
} catch (\Throwable $e) {
    aicli_log("Background Install Job EXCEPTION for $agentId: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine(), AICLI_LOG_ERROR);
    setInstallStatus("Fatal Error: " . $e->getMessage(), 0, $agentId, $e->getTraceAsString());
}
