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

// 3. Get agent ID and optional target version from CLI arguments
$agentId = $argv[1] ?? '';
$targetVersion = $argv[2] ?? null;

if (empty($agentId)) {
    aicli_log("Background Install Job aborted: Missing Agent ID", AICLI_LOG_ERROR);
    exit(1);
}

$verLabel = $targetVersion ? " (version: $targetVersion)" : " (latest)";
aicli_log("Background Install Job Started for: $agentId$verLabel (PID: " . getmypid() . ")", AICLI_LOG_INFO);

// 4. Run the install
try {
    $result = \AICliAgents\Services\InstallerService::installAgent($agentId, $targetVersion);
    if (isset($result['status']) && $result['status'] === 'error') {
        aicli_log("Background Install Job FAILED for $agentId: " . ($result['message'] ?? $result['error'] ?? 'Unknown Error'), AICLI_LOG_ERROR);
    } else {
        aicli_log("Background Install Job Complete for: $agentId", AICLI_LOG_INFO);

        // Enqueue a high-priority bake so the agent layer is durable on Flash
        // before the user can launch a session against it (spec S0, step 2).
        // No post-install consolidate enqueue — supervisor decides on its own
        // schedule via the post-bake threshold trigger (Bug #512).
        \AICliAgents\Services\SupervisorService::enqueue('agent', $agentId, 'bake', 'agent_install', 0);
        aicli_log("Enqueued post-install bake for $agentId", AICLI_LOG_INFO, "InstallBG");

        // After successful install: auto-launch any workspaces configured for this agent.
        // Errors here are non-fatal — install itself already succeeded. The service
        // wraps each per-workspace launch so one failure can't skip subsequent ones.
        \AICliAgents\Services\AutoLaunchService::launchAllPending($agentId, 'agent_install');
    }
} catch (\Throwable $e) {
    aicli_log("Background Install Job EXCEPTION for $agentId: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine(), AICLI_LOG_ERROR);
    setInstallStatus("Fatal Error: " . $e->getMessage(), 0, $agentId, $e->getTraceAsString());
}
