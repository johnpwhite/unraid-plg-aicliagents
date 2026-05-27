<?php
/**
 * Background Emergency Install: npm install agent directly to RAM (no SquashFS).
 * Used when agent storage is unavailable (array stopped, both paths on array).
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');

register_shutdown_function(function() use ($argv) {
    $error = error_get_last();
    $agentId = $argv[1] ?? 'unknown';
    if ($error !== NULL && ($error['type'] === E_ERROR || $error['type'] === E_PARSE)) {
        $msg = "FATAL EMERGENCY INSTALL ERROR for $agentId: {$error['message']} in {$error['file']} on line {$error['line']}";
        require_once "/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/AICliAgentsManager.php";
        aicli_log($msg, AICLI_LOG_ERROR, "EmergencyInstallBG");
        setInstallStatus("Fatal Error: Check logs", 0, $agentId, $msg);
    }
});

$pluginDir = "/usr/local/emhttp/plugins/unraid-aicliagents";
require_once "$pluginDir/includes/AICliAgentsManager.php";

$agentId = $argv[1] ?? '';
if (empty($agentId)) {
    aicli_log("Emergency Install Job aborted: Missing Agent ID", AICLI_LOG_ERROR);
    exit(1);
}

aicli_log("Emergency Install Job Started for: $agentId (PID: " . getmypid() . ")", AICLI_LOG_INFO);

try {
    $result = \AICliAgents\Services\InstallerService::emergencyInstallAgent($agentId);
    if (isset($result['status']) && $result['status'] === 'error') {
        aicli_log("Emergency Install Job FAILED for $agentId: " . ($result['message'] ?? 'Unknown Error'), AICLI_LOG_ERROR);
    } else {
        aicli_log("Emergency Install Job Complete for: $agentId", AICLI_LOG_INFO);
    }
} catch (\Throwable $e) {
    aicli_log("Emergency Install Job EXCEPTION for $agentId: " . $e->getMessage(), AICLI_LOG_ERROR);
    setInstallStatus("Fatal Error: " . $e->getMessage(), 0, $agentId, $e->getTraceAsString());
}
