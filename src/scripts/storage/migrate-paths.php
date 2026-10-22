<?php
/**
 * <module_context>
 * Description: Started by the supervisor for the op `migratepath` (docs/specs/STORAGE_TARGET_PICKER.md,
 *   issue #652). Usage: php migrate-paths.php <jobId>. Reads the job options file, takes the move lock,
 *   checks that the pre-move saves finished, copies the layers to the new path, saves the new path
 *   in the settings and re-points the manifest. The Activity tray row `storage_migrate` shows the steps.
 * Dependencies: StoragePathMoveJobService, StorageHandler::runPathMove (through the manager include).
 * Constraints: Exit 0 when done, 1 when it failed or was refused, 2 on a bad call. It runs only under
 *   the supervisor, never in a web request, so closing the browser does not stop it.
 * </module_context>
 */
$inc = getenv('AICLI_MANAGER_PHP') ?: dirname(__DIR__, 2) . '/includes/AICliAgentsManager.php';
require_once $inc;
require_once dirname(__DIR__, 2) . '/includes/services/StoragePathMoveJobService.php';
require_once dirname(__DIR__, 2) . '/includes/handlers/StorageHandler.php';

$jobId = (string)($argv[1] ?? '');
if ($jobId === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9._\-]{0,127}$/D', $jobId) !== 1) {
    fwrite(STDERR, "usage: migrate-paths.php <jobId>\n");
    exit(2);
}
set_time_limit(0);
$rc = \AICliAgents\Services\StoragePathMoveJobService::run($jobId);
\AICliAgents\Services\StoragePathMoveJobService::cleanup($jobId);
exit($rc);
