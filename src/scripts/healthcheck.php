<?php
/**
 * Health check entrypoint (R-09, Feature #1372). Cron-able:
 * /etc/cron.d/unraid-aicliagents.health-check (config key health_check_schedule,
 * default every 30 min, empty disables — installed like version_check_schedule).
 *
 * Force-refreshes /tmp/unraid-aicliagents/health.json and, on degradation,
 * fires ONE deduped Unraid notification per distinct degradation fingerprint
 * (health.notified), cleared on recovery. Prints the overall state.
 *
 * #925: under cron-run.sh the run record carries the result: "ok", or "warn" with the
 * name of each check that is not fine.
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
    $result = \AICliAgents\Services\HealthService::checkAndNotify();
    echo "health: " . ($result['overall'] ?? 'unknown') . "\n";
    // #925: judge like the notifier does: a check flagged test_run (#892) does not count.
    $bad = [];
    $overall = 'ok';
    foreach (($result['checks'] ?? []) as $name => $c) {
        if (!is_array($c) || !empty($c['test_run'])) continue;
        $st = (string)($c['status'] ?? 'ok');
        if ($st === 'ok') continue;
        $bad[] = $name . ': ' . $st . ' (' . ($c['message'] ?? '') . ')';
        if ($st === 'fail') $overall = 'fail';
        elseif ($overall === 'ok') $overall = 'warn';
    }
    if ($bad === []) {
        aicli_job_result('ok', 'Health is ok.');
    } else {
        $names = implode(', ', array_map(static fn(string $b): string => explode(':', $b, 2)[0], $bad));
        aicli_job_result($overall === 'fail' ? 'fail' : 'warn', 'Health is ' . $overall . ': ' . $names . '.', $bad);
    }
} catch (\Throwable $e) {
    aicli_log("Health check error: " . $e->getMessage(), AICLI_LOG_ERROR, "HealthCheck");
    aicli_job_result('fail', 'The health check stopped with an error: ' . $e->getMessage());
    exit(1);
}
