<?php
/**
 * Health check entrypoint (R-09, Feature #1372). Cron-able:
 * /etc/cron.d/unraid-aicliagents.health-check (config key health_check_schedule,
 * default every 30 min, empty disables — installed like version_check_schedule).
 *
 * Force-refreshes /tmp/unraid-aicliagents/health.json and, on degradation,
 * fires ONE deduped Unraid notification per distinct degradation fingerprint
 * (health.notified), cleared on recovery. Prints the overall state.
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once dirname(__DIR__) . '/includes/AICliAgentsManager.php'; // #367: this generation

try {
    $result = \AICliAgents\Services\HealthService::checkAndNotify();
    echo "health: " . ($result['overall'] ?? 'unknown') . "\n";
} catch (\Throwable $e) {
    aicli_log("Health check error: " . $e->getMessage(), AICLI_LOG_ERROR, "HealthCheck");
    exit(1);
}
