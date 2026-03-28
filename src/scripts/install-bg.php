<?php
/**
 * Background Install Wrapper for AI CLI Agents
 * This script is called via CLI to perform installations detached from the WebUI process.
 */

// 1. Define base path
$pluginDir = "/usr/local/emhttp/plugins/unraid-aicliagents";

// 2. Include the manager logic
require_once "$pluginDir/includes/AICliAgentsManager.php";

// 3. Get agent ID from CLI arguments
$agentId = $argv[1] ?? '';

if (empty($agentId)) {
    aicli_debug("Background Install Error: No AgentID provided.");
    exit(1);
}

aicli_debug("Background Install Job Started for: $agentId (PID: " . getmypid() . ")");

// 4. Run the install
// installAgent already handles its own logging and status updates
try {
    installAgent($agentId);
    aicli_debug("Background Install Job Complete for: $agentId");
} catch (Exception $e) {
    aicli_debug("Background Install Job EXCEPTION for $agentId: " . $e->getMessage());
    setInstallStatus("Fatal Error: " . $e->getMessage(), 0, $agentId);
}
