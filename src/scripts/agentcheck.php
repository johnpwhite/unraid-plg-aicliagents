<?php
/**
 * Cron-triggered version check: queries npm and posts Unraid notifications.
 * Called by the agentcheck bash wrapper via cron schedule.
 * #925: under cron-run.sh the run record says how many agent updates the check found.
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once dirname(__DIR__) . '/includes/AICliAgentsManager.php'; // #367: this generation

/** #925: write the run record result. A missing helper or a failed write never changes the job. */
function aicli_job_result(string $status, string $summary, array $details = []): void {
    if (class_exists('\\AICliAgents\\Services\\JobRecordService')) {
        \AICliAgents\Services\JobRecordService::writeResult($status, $summary, $details);
    }
}

try {
    \AICliAgents\Services\VersionCheckService::checkAndNotify();
    $found = 0;
    foreach (\AICliAgents\Services\AgentRegistry::getRegistry() as $id => $agent) {
        if (($agent['is_installed'] ?? false) && \AICliAgents\Services\VersionCheckService::hasUpdate((string)$id)) $found++;
    }
    aicli_job_result('ok', $found === 0 ? 'No agent updates found.' : "$found agent update(s) found.");
} catch (\Throwable $e) {
    aicli_log("Cron version check error: " . $e->getMessage(), AICLI_LOG_ERROR, "AgentCheck");
    aicli_job_result('fail', 'The version check stopped with an error: ' . $e->getMessage());
}
