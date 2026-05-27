<?php
/**
 * Background version check: queries npm for all agent versions.
 * Called by get_version_cache when cache is stale/empty.
 * Does NOT post notifications (that's agentcheck's job via cron).
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');

$pluginDir = "/usr/local/emhttp/plugins/unraid-aicliagents";
require_once "$pluginDir/src/includes/AICliAgentsManager.php";

try {
    \AICliAgents\Services\VersionCheckService::checkAllAgents(true);
} catch (\Throwable $e) {
    aicli_log("Background version check error: " . $e->getMessage(), AICLI_LOG_ERROR, "VersionCheckBG");
}
