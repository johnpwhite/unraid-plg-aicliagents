<?php
/**
 * <module_context>
 * Description: Started by the supervisor for the op `cleantmp` (docs/specs/AGENT_TEMP_FOLDER.md R5,
 *   #554). Usage: php clean-agent-tmp.php <jobId>. Removes the temporary folders of agent sessions
 *   that no longer run, writes a progress file and a result file.
 * Dependencies: TempCleanupJobService, AgentTempService, BootSpaceService (through the manager include).
 * Constraints: Exit 0 when done, 1 when it failed, 2 on a bad call. It runs only under the
 *   supervisor, never in a web request.
 * </module_context>
 */
$inc = getenv('AICLI_MANAGER_PHP') ?: dirname(__DIR__, 2) . '/includes/AICliAgentsManager.php';
require_once $inc;
require_once dirname(__DIR__, 2) . '/includes/services/AgentTempService.php';
require_once dirname(__DIR__, 2) . '/includes/services/TempCleanupJobService.php';

$jobId = (string)($argv[1] ?? '');
if ($jobId === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9._\-]{0,127}$/D', $jobId) !== 1) {
    fwrite(STDERR, "usage: clean-agent-tmp.php <jobId>\n");
    exit(2);
}
$rc = \AICliAgents\Services\TempCleanupJobService::run($jobId);
// The card shows the new numbers: ask for a background measurement (best effort).
try { \AICliAgents\Services\BootSpaceService::startMeasure(); } catch (\Throwable $e) { /* the next card visit measures */ }
exit($rc);
