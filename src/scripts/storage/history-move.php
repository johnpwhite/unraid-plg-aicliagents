<?php
/**
 * <module_context>
 * Description: Started by the supervisor for the ops `historymove` and
 *   `historyback` (docs/specs/BOOT_DISK_SPACE.md R6). Usage:
 *   php history-move.php <jobId>. Reads the job options file, moves or brings
 *   back the sessions, writes a progress file and a result file.
 * Dependencies: HistoryJobService, HistoryMoveService, HistoryStoreService.
 * Constraints: Exit 0 when done, 1 when refused or failed. Loads no plugin config.
 * </module_context>
 */
require_once __DIR__ . '/../../includes/services/HistoryStoreService.php';
require_once __DIR__ . '/../../includes/services/HistoryMoveService.php';
require_once __DIR__ . '/../../includes/services/HistoryJobService.php';

$jobId = (string)($argv[1] ?? '');
if ($jobId === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9._\-]{0,127}$/D', $jobId) !== 1) {
    fwrite(STDERR, "usage: history-move.php <jobId>\n");
    exit(2);
}
$rc = \AICliAgents\Services\HistoryJobService::run($jobId);
// The card shows the new numbers: ask for a background measurement (best effort, #552).
try {
    require_once __DIR__ . '/../../includes/services/BootSpaceService.php';
    \AICliAgents\Services\BootSpaceService::startMeasure();
} catch (\Throwable $e) { /* the next card visit measures */ }
exit($rc);
