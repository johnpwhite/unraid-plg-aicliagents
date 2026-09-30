<?php
/**
 * Cron-triggered version check: queries npm and posts Unraid notifications.
 * Called by the agentcheck bash wrapper via cron schedule.
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once dirname(__DIR__) . '/includes/AICliAgentsManager.php'; // #367: this generation

try {
    \AICliAgents\Services\VersionCheckService::checkAndNotify();
} catch (\Throwable $e) {
    aicli_log("Cron version check error: " . $e->getMessage(), AICLI_LOG_ERROR, "AgentCheck");
}
