<?php
/**
 * <module_context>
 *     <name>BootArchiveHandler</name>
 *     <description>AJAX handler for "Archive…" and "Remove" on the Boot disk space card
 *     (docs/specs/BOOT_DISK_SPACE.md R8, #724).
 *     boot_archive_plan (read): the line of the last measurement for one kind and home, the pools
 *     a person may pick, and the archive folder rule. It measures nothing.
 *     boot_archive_start (POST): checks the request against the last measurement and the target
 *     rules, then queues a detached job (BootArchiveJobService). It copies and removes nothing.</description>
 *     <dependencies>BootSpaceService, BootArchiveService, BootArchiveJobService, HistoryMoveService.</dependencies>
 *     <constraints>CSRF is checked once by the AJAX dispatcher. The changing action refuses a GET
 *     request and refuses during a storage migration. No folder walk, no du, no find in a request.</constraints>
 * </module_context>
 */

namespace AICliAgents\Handlers;

use AICliAgents\Services\BootArchiveJobService;
use AICliAgents\Services\BootArchiveService;
use AICliAgents\Services\BootSpaceService;
use AICliAgents\Services\HistoryMoveService;

require_once __DIR__ . '/../services/BootSpaceService.php';
require_once __DIR__ . '/../services/BootArchiveService.php';
require_once __DIR__ . '/../services/BootArchiveJobService.php';

class BootArchiveHandler
{
    public static function handle($action, $id): ?array
    {
        switch ($action) {
            case 'boot_archive_plan':  return self::plan();
            case 'boot_archive_start': return self::start();
            case 'boot_restore_list':   return self::restoreList();
            case 'boot_restore_plan':   return self::restoreQueue('restore_plan');
            case 'boot_restore_result': return self::restoreResult();
            case 'boot_restore_start':  return self::restoreQueue('restore');
            default:                   return null;
        }
    }

    /** Actions handled by this handler. */
    public static function actions(): array
    {
        return ['boot_archive_plan', 'boot_archive_start', 'boot_restore_list', 'boot_restore_plan', 'boot_restore_result', 'boot_restore_start'];
    }

    // ---- restore from an archive (R8, #755) ---------------------------------------------------

    /**
     * boot_restore_list (read): the archives in a folder that a person picked. The folder must pass the
     * same rules as the target of an archive. One glob of two fixed levels; no manifest is opened.
     * $seams (tests): mounted.
     * @return array<string,mixed>
     */
    public static function restoreList(?string $folder = null, array $seams = []): array
    {
        $folder = trim($folder ?? (string)($_REQUEST['folder'] ?? ''));
        $pools = $seams['pools'] ?? HistoryMoveService::mountedPools();
        $saved = (string)($seams['dest'] ?? (getAICliConfig()['history_move_path'] ?? ''));
        // No folder yet: the dialog asks for the pools it may offer.
        if ($folder === '') return ['status' => 'ok', 'folder' => '', 'archives' => [], 'pools' => $pools, 'saved' => $saved];
        $chk = BootArchiveService::checkTarget($folder, $seams);
        if ($chk['error'] !== null) return ['status' => 'error', 'message' => (string)$chk['error']];
        $out = [];
        foreach (BootArchiveService::listManifests($chk['folder']) as $m) {
            $d = BootArchiveService::describe($m['kind']);
            $out[] = ['path' => $m['path'], 'host' => $m['host'], 'date' => $m['date'], 'kind' => $m['kind'], 'user' => $m['user'],
                      'label' => $d['label'], 'size' => $m['size']];
        }
        return ['status' => 'ok', 'folder' => $chk['folder'], 'archives' => $out, 'pools' => $pools, 'saved' => $saved];
    }

    /**
     * boot_restore_plan and boot_restore_start (POST). The plan job compares the archive with the home and
     * changes nothing. The start job copies, and only after a finished plan for the same manifest
     * (the person saw the result). Both run as a detached background job.
     * $seams (tests): method, manifest, policy, spawn, no_track, job_id, mounted, home_persist.
     * @return array<string,mixed>
     */
    public static function restoreQueue(string $mode, array $seams = []): array
    {
        if (strtoupper((string)($seams['method'] ?? $_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            return ['status' => 'error', 'message' => 'This action needs a POST request.'];
        }
        require_once __DIR__ . '/../services/StorageBackendMigrationService.php';
        if (\AICliAgents\Services\StorageBackendMigrationService::isMigrationInProgress()) {
            return ['status' => 'error', 'reason' => 'storage_migration', 'message' => 'Wait for the storage migration to finish before starting another storage action.'];
        }
        $manifest = trim((string)($seams['manifest'] ?? $_REQUEST['manifest'] ?? ''));
        $policy = trim((string)($seams['policy'] ?? $_REQUEST['policy'] ?? 'skip'));
        $chk = BootArchiveService::checkManifest($manifest, $seams);
        if ($chk['error'] !== null) return ['status' => 'error', 'message' => (string)$chk['error']];
        if (!is_dir(BootArchiveService::homeDir($chk['user'], $seams))) {
            return ['status' => 'error', 'message' => 'The home "' . $chk['user'] . '" of this archive is not on this server.'];
        }
        if ($mode === 'restore') {
            if (!in_array($policy, BootArchiveService::POLICIES, true)) return ['status' => 'error', 'message' => 'Choose what to do with different files.'];
            if (!BootArchiveJobService::planned($chk['path'])) {
                return ['status' => 'error', 'message' => 'Check the archive first. The check shows what a restore would change.'];
            }
        }
        aicli_log("AJAX Request: Boot space $mode of " . $chk['kind'] . ' for ' . $chk['user'], AICLI_LOG_INFO);
        [$jobId, $err] = BootArchiveJobService::start(['kind' => $chk['kind'], 'user' => $chk['user'], 'mode' => $mode,
            'manifest' => $chk['path'], 'policy' => $policy], $seams);
        if ($jobId === null) return ['status' => 'error', 'message' => (string)$err];
        return ['status' => 'queued', 'job_id' => $jobId,
                'message' => $mode === 'restore' ? 'Queued. Watch the activity tray for progress.' : 'Checking the archive.'];
    }

    /**
     * boot_restore_result (read): the result file of a restore job. `state` is `pending` while the job
     * runs. It reads one small file.
     * @return array<string,mixed>
     */
    public static function restoreResult(?string $jobId = null): array
    {
        $jobId = trim($jobId ?? (string)($_REQUEST['job_id'] ?? ''));
        if (preg_match('/^bootarchive-(?:restore|restore_plan)-[A-Za-z0-9._-]{1,100}$/D', $jobId) !== 1) {
            return ['status' => 'error', 'message' => 'This job name is not valid.'];
        }
        $res = BootArchiveJobService::readResult($jobId);
        if (!$res) return ['status' => 'ok', 'state' => 'pending'];
        return ['status' => 'ok', 'state' => 'done', 'result' => $res];
    }

    /**
     * The facts the dialog shows. $seams (tests): data (the last measurement), pools, dest (saved folder), host.
     * @return array<string,mixed>
     */
    public static function plan(?string $kind = null, ?string $user = null, array $seams = []): array
    {
        $kind = trim($kind ?? (string)($_REQUEST['kind'] ?? ''));
        $user = trim($user ?? (string)($_REQUEST['user'] ?? ''));
        $data = $seams['data'] ?? BootSpaceService::read();
        $line = self::line($kind, $user, $data);
        if (is_string($line)) return ['status' => 'error', 'message' => $line];
        $saved = (string)($seams['dest'] ?? (getAICliConfig()['history_move_path'] ?? ''));
        return [
            'status' => 'ok', 'kind' => $kind, 'user' => $user,
            'title' => BootArchiveService::describe($kind)['title'], 'info' => BootArchiveService::describe($kind)['info'],
            'bytes' => (int)$line['bytes'], 'units' => (int)($line['units'] ?? 0), 'files' => (int)($line['files'] ?? 0),
            'pools' => $seams['pools'] ?? HistoryMoveService::mountedPools(),
            'folder' => $saved,
            'archive_folder' => BootArchiveService::ARCHIVE_FOLDER,
            'host' => (string)($seams['host'] ?? (gethostname() ?: 'host')),
            'date' => date('Y-m-d'),
        ] + BootSpaceService::meta($data);
    }

    /**
     * Check a request against the last measurement. Returns the line, or an error sentence.
     * @return array<string,mixed>|string
     */
    public static function line(string $kind, string $user, ?array $data)
    {
        if (!BootArchiveService::isKind($kind)) return 'This kind of data is not known.';
        if (!BootArchiveService::isUser($user)) return 'This home name is not valid.';
        $line = BootArchiveService::lineFor($data, $kind, $user);
        if ($line === null || (int)$line['bytes'] < 1) {
            return 'The last measurement lists nothing to do here. Measure again, then try once more.';
        }
        return $line;
    }

    /**
     * @param array $seams tests: data, spawn, no_track, job_id, mounted
     * @return array<string,mixed>
     */
    public static function start(?string $method = null, array $seams = []): array
    {
        if (strtoupper($method ?? (string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            return ['status' => 'error', 'message' => 'This action needs a POST request.'];
        }
        require_once __DIR__ . '/../services/StorageBackendMigrationService.php';
        if (\AICliAgents\Services\StorageBackendMigrationService::isMigrationInProgress()) {
            return ['status' => 'error', 'reason' => 'storage_migration', 'message' => 'Wait for the storage migration to finish before starting another storage action.'];
        }
        $kind = trim((string)($seams['kind'] ?? $_REQUEST['kind'] ?? ''));
        $user = trim((string)($seams['user'] ?? $_REQUEST['user'] ?? ''));
        $mode = trim((string)($seams['mode'] ?? $_REQUEST['mode'] ?? ''));
        $folder = trim((string)($seams['folder'] ?? $_REQUEST['folder'] ?? ''));
        return self::queue($kind, $user, $mode, $folder, $seams);
    }

    /**
     * The shared check and queue step. The handler and the admin tool both call it.
     * @return array<string,mixed>
     */
    public static function queue(string $kind, string $user, string $mode, string $folder, array $seams = []): array
    {
        if (!in_array($mode, ['archive', 'remove'], true)) return ['status' => 'error', 'message' => 'The mode must be archive or remove.'];
        $line = self::line($kind, $user, $seams['data'] ?? BootSpaceService::read());
        if (is_string($line)) return ['status' => 'error', 'message' => $line];
        if ($mode === 'archive') {
            $chk = BootArchiveService::checkTarget($folder, $seams);
            if ($chk['error'] !== null) return ['status' => 'error', 'message' => (string)$chk['error']];
        }
        aicli_log("AJAX Request: Boot space $mode $kind of $user", AICLI_LOG_INFO);
        [$jobId, $err] = BootArchiveJobService::start(['kind' => $kind, 'user' => $user, 'mode' => $mode, 'folder' => $folder], $seams);
        if ($jobId === null) return ['status' => 'error', 'message' => (string)$err];
        return ['status' => 'queued', 'job_id' => $jobId, 'bytes' => (int)$line['bytes'], 'message' => 'Queued. Watch the activity tray for progress.'];
    }
}
