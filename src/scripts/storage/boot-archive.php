<?php
/**
 * <module_context>
 * Description: Started detached by BootArchiveJobService::start() for "Archive…" and "Remove" on the
 *   Boot disk space card (docs/specs/BOOT_DISK_SPACE.md R8, #724). Usage: php boot-archive.php <jobId>.
 *   Reads the job options file, archives or removes the old data of one home, updates the Activity
 *   tray entry, writes a result file, then asks for a fresh background measurement.
 * Dependencies: BootArchiveJobService, BootArchiveService, BootSpaceService (through the manager include).
 * Constraints: Exit 0 when done, 1 when it failed, 2 on a bad call. It runs in the background only,
 *   never in a web request.
 * </module_context>
 */
$inc = getenv('AICLI_MANAGER_PHP') ?: dirname(__DIR__, 2) . '/includes/AICliAgentsManager.php';
require_once $inc;
require_once dirname(__DIR__, 2) . '/includes/services/BootArchiveJobService.php';

$jobId = (string)($argv[1] ?? '');
if ($jobId === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9._\-]{0,127}$/', $jobId) !== 1) {
    fwrite(STDERR, "usage: boot-archive.php <jobId>\n");
    exit(2);
}
$rc = \AICliAgents\Services\BootArchiveJobService::run($jobId);
// The card shows the new numbers: ask for a background measurement (best effort).
try { \AICliAgents\Services\BootSpaceService::startMeasure(); } catch (\Throwable $e) { /* the next card visit measures */ }
exit($rc);
