<?php
/**
 * <module_context>
 * Description: The supervisor job "Move the storage to a new path" (op `migratepath`;
 *   docs/specs/STORAGE_TARGET_PICKER.md, issue #652). The web request (execute_migrate)
 *   validates the new paths, closes the sessions, queues the saves and then queues this
 *   job. The supervisor starts scripts/storage/migrate-paths.php, which calls run().
 *   run() takes the move lock, checks that the saves finished, and copies the layers
 *   (StorageHandler::runPathMove). The copy goes on when the browser closes. The Activity
 *   tray row `storage_migrate` shows the progress; run() writes a result file for the log.
 * Dependencies: StorageHandler (the copy), SupervisorService (the ledger), FileStorage (lock, marker).
 * Constraints: The options file carries everything. State files live in
 *   /tmp/unraid-aicliagents/path-move (AICLI_PATH_MOVE_STATE_DIR in tests). The op name has
 *   lower-case letters only, because SupervisorService::enqueue() removes every other character.
 * </module_context>
 */

namespace AICliAgents\Services;

class StoragePathMoveJobService
{
    public const OP = 'migratepath';
    /** Entity of the job in the ledger and the queue: type "storage", id "paths". */
    public const TYPE = 'storage';
    public const ID = 'paths';
    /** The job runs after the pre-move saves (priority 5): a larger number runs later. */
    public const PRIORITY = 6;
    /** A claim older than this is a crash residue and is replaced. */
    public const CLAIM_MAX_AGE_S = 21600;

    public static function isOp(string $op): bool
    {
        return $op === self::OP;
    }

    public static function stateDir(): string
    {
        $env = getenv('AICLI_PATH_MOVE_STATE_DIR');
        return ($env !== false && $env !== '') ? rtrim($env, '/') : SecretPaths::stateRoot() . '/path-move';
    }

    private static function path(string $jobId, string $suffix): string
    {
        return self::stateDir() . '/' . preg_replace('/[^A-Za-z0-9._\-]/', '_', $jobId) . '.' . $suffix;
    }

    private static function claimPath(): string
    {
        return self::stateDir() . '/pending.claim';
    }

    /**
     * Is a move queued or running? The claim file is made when a move is queued and removed
     * when the job ends. A claim older than CLAIM_MAX_AGE_S is residue of a crash.
     */
    public static function hasPending(): bool
    {
        $f = self::claimPath();
        if (!is_file($f)) return false;
        return (time() - (int)@filemtime($f)) < self::CLAIM_MAX_AGE_S;
    }

    /**
     * Take the right to queue a move. False when another move holds it. The file is made
     * with mode "x", so two requests cannot both get it.
     */
    public static function claim(string $jobId): bool
    {
        $dir = self::stateDir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return false;
        $f = self::claimPath();
        if (is_file($f) && !self::hasPending()) @unlink($f);   // stale residue
        $fh = @fopen($f, 'x');
        if ($fh === false) return false;
        fwrite($fh, $jobId);
        fclose($fh);
        return true;
    }

    /** Give the claim back (the job ended, or it could not be queued). */
    public static function release(): void
    {
        @unlink(self::claimPath());
    }

    /**
     * @param array{new_agent:string,new_home:string,pre_jobs:array<int,string>,label:string} $opts
     */
    public static function writeOptions(string $jobId, array $opts): bool
    {
        return self::writeJson(self::path($jobId, 'opts.json'), $opts);
    }

    /** @return array<string,mixed> */
    public static function readOptions(string $jobId): array
    {
        return self::readJson(self::path($jobId, 'opts.json'));
    }

    /** @param array<string,mixed> $r */
    public static function writeResult(string $jobId, array $r): void
    {
        self::writeJson(self::path($jobId, 'result.json'), $r);
    }

    /** @return array<string,mixed> */
    public static function readResult(string $jobId): array
    {
        return self::readJson(self::path($jobId, 'result.json'));
    }

    /** Remove the state files of an ended job. The claim goes with them. */
    public static function cleanup(string $jobId): void
    {
        foreach (['opts.json', 'result.json'] as $s) @unlink(self::path($jobId, $s));
        self::release();
    }

    /**
     * The pre-move saves that did not finish, as plain names ("home root"). Empty means all
     * finished. A job that left the ledger is finished (the ledger reaps only ended jobs).
     * The jobs ran before this one because the move has a later queue priority.
     *
     * @param array<int,string> $jobIds ledger ids of the tracked pre-move saves
     * @param callable|null $getJob test seam: fn(string $jobId): ?array
     * @return array<int,string>
     */
    public static function unfinishedSaves(array $jobIds, ?callable $getJob = null): array
    {
        $getJob = $getJob ?? static fn(string $id): ?array => SupervisorService::getJob($id);
        $bad = [];
        foreach ($jobIds as $id) {
            $job = $getJob((string)$id);
            if ($job === null) continue;
            $state = (string)($job['state'] ?? '');
            $entity = (string)($job['entity'] ?? '');
            $name = $entity !== '' ? str_replace('/', ' ', $entity) : (string)$id;
            if ($state === 'queued' || $state === 'running' || $state === 'failed') {
                $bad[] = $name;
            } elseif ($state === 'deferred') {
                // exit 2 with a reason that wrote the layer (mount busy) is a save; the others are not.
                require_once __DIR__ . '/FileStorage.php';
                $reason = isset($job['defer_reason']) ? (string)$job['defer_reason'] : null;
                if (!FileStorage::bakeResultSaved(2, $reason)) $bad[] = $name;
            }
        }
        return $bad;
    }

    /**
     * Run a queued job. Returns the process exit code: 0 done, 1 refused or failed.
     * @param array<string,callable> $seams test seams: lock (fn(): resource|null), marker (fn(): bool), backend_active (fn(): bool),
     *        get_job (fn(string): ?array), move (fn(string $newAgent, string $newHome): array),
     *        fail_tray (fn(string $message): void)
     */
    public static function run(string $jobId, array $seams = []): int
    {
        $opts = self::readOptions($jobId);
        if ($opts === [] || !isset($opts['new_agent'], $opts['new_home'])) {
            return self::refuse($jobId, 'The move has no saved options, so nothing was moved.', $seams);
        }
        require_once __DIR__ . '/FileStorage.php';
        require_once __DIR__ . '/StorageBackendMigrationService.php';
        // Probe the marker before taking the shared lock. If the lock is free,
        // FileStorage clears the orphaned marker. Once this job owns the lock,
        // a second marker probe would mistake this job's own lock for another move.
        $inProgress = isset($seams['marker'])
            ? (bool)($seams['marker'])()
            : FileStorage::isMigrationInProgress();
        if ($inProgress) {
            return self::refuse($jobId, 'An earlier storage move is still running. Nothing was moved.', $seams);
        }
        $lock = isset($seams['lock']) ? ($seams['lock'])() : FileStorage::acquireMigrationLock(FileStorage::MIGRATION_LOCK_WAIT_S);
        if ($lock === null || $lock === false) {
            if (FileStorage::isMigrationInProgress()) {
                return self::refuse($jobId, 'Another storage move is already running. Nothing was moved.', $seams);
            }
            if (FileStorage::isBackendMigrationInProgress() || StorageBackendMigrationService::isMigrationInProgress()) {
                return self::refuse($jobId, 'A storage-engine migration is running. Wait for it to finish before moving storage.', $seams);
            }
            return self::refuse($jobId, 'Home storage is busy. Try again in a moment.', $seams);
        }
        $trayHandled = false;
        try {
            $backendInProgress = isset($seams['backend_active'])
                ? (bool)($seams['backend_active'])()
                : (FileStorage::isBackendMigrationInProgress() || StorageBackendMigrationService::isMigrationInProgress());
            if ($backendInProgress) {
                $res = ['status' => 'error', 'message' => 'A storage-engine migration is running. Wait for it to finish before moving storage.'];
            } else {
                $unfinished = self::unfinishedSaves((array)($opts['pre_jobs'] ?? []), $seams['get_job'] ?? null);
                if ($unfinished !== []) {
                    // The copy starts only after the saves are done, or the last changes stay in the old folder.
                    $res = ['status' => 'error', 'message' => 'The save of unsaved changes for ' . implode(', ', $unfinished) . ' did not finish. Nothing was moved. Try again when the server is quiet.'];
                } else {
                    try {
                        $move = $seams['move'] ?? static function (string $a, string $h): array {
                            require_once __DIR__ . '/../handlers/StorageHandler.php';
                            return \AICliAgents\Handlers\StorageHandler::runPathMove($a, $h);
                        };
                        $res = $move((string)$opts['new_agent'], (string)$opts['new_home']);
                        $trayHandled = true;   // runPathMove ends the tray row itself, done or failed
                    } catch (\Throwable $e) {
                        LogService::log('Storage move stopped: ' . $e->getMessage(), LogService::LOG_ERROR, 'StoragePathMoveJobService');
                        $res = ['status' => 'error', 'message' => 'The move stopped on an error. The details are in the log.'];
                    }
                }
            }
        } finally {
            FileStorage::releaseMigrationLock($lock);
        }
        if (($res['status'] ?? '') !== 'ok' && !$trayHandled) {
            self::failTray((string)$res['message'], $seams);
        }
        self::writeResult($jobId, $res);
        self::release();
        return (($res['status'] ?? '') === 'ok') ? 0 : 1;
    }

    /** End a job that never started the copy: result file, tray row, claim. */
    private static function refuse(string $jobId, string $message, array $seams): int
    {
        self::failTray($message, $seams);
        self::writeResult($jobId, ['status' => 'error', 'message' => $message]);
        self::release();
        return 1;
    }

    /** Show the failure in the tray row `storage_migrate`, with the recovery text of the move. */
    private static function failTray(string $message, array $seams): void
    {
        if (isset($seams['fail_tray'])) {
            ($seams['fail_tray'])($message);
            return;
        }
        require_once __DIR__ . '/../handlers/StorageHandler.php';
        require_once __DIR__ . '/ActivityService.php';
        // The sessions were closed and the saves were queued before this job was queued.
        $row = \AICliAgents\Handlers\StorageHandler::migrationFailureRow($message, 22, false);
        ActivityService::fail('storage_migrate', $row['error'], $row['recovery'], [
            'type' => 'migrate', 'step' => $row['step'], 'meta' => ['cancel' => 'none'],
        ]);
    }

    /** @param array<string,mixed> $data */
    private static function writeJson(string $path, array $data): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return false;
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $json = json_encode($data, JSON_UNESCAPED_SLASHES);
        if ($json === false || @file_put_contents($tmp, $json) === false) { @unlink($tmp); return false; }
        return @rename($tmp, $path);
    }

    /** @return array<string,mixed> */
    private static function readJson(string $path): array
    {
        $raw = @file_get_contents($path);
        $d = $raw === false ? null : json_decode($raw, true);
        return is_array($d) ? $d : [];
    }
}
