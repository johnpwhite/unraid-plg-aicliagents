<?php
/**
 * Where a home's writable layer lives (RAM or disk), and the move between the two.
 *
 * Spec: docs/specs/HOME_UPPER_PLACEMENT_SETTING.md (Forgejo #456).
 *
 * The setting `storage_upper_mode` ('zram' | 'disk') is the policy for a NEW home
 * mount (bash `entity_upper_mode <path> home`). A home that is already mounted keeps
 * the layer it has (the live mount wins, #372), so changing the setting alone would
 * change nothing for a running home. `migrate()` is the part that makes the change
 * real: it closes the workspaces, saves each home that is on the other layer into a
 * verified squashfs layer (the bake reclaims the old writable layer and remounts the
 * home on the policy layer), then VERIFIES the result from the kernel mount table.
 * When any home did not move, the old policy is put back, so the setting never
 * claims a placement the data does not have. Nothing is deleted: the old layer holds
 * only what the verified save already contains.
 *
 * Every side effect goes through an optional $seams array so the logic is unit
 * tested without mounts: mounts, homes, zram_total_mb, free_mb, size_mb, holds_data,
 * evict, persist, save_config, lock.
 */

namespace AICliAgents\Services;

final class UpperPlacementService
{
    public const MODES = ['zram', 'disk'];
    /** The lock file: under the state root (the sandbox under test). */
    public static function lockFile(): string { return SecretPaths::stateRoot() . '/.upper_placement.lock'; }
    /** A home larger than this share of the zram device is refused as a zram target. */
    public const ZRAM_MAX_SHARE = 0.8;

    public static function normalize($mode): string
    {
        return ((string)$mode === 'disk') ? 'disk' : 'zram';
    }

    public static function currentMode(?array $config = null): string
    {
        $config = $config ?? ConfigService::getConfig();
        return self::normalize($config['storage_upper_mode'] ?? 'zram');
    }

    public static function label(string $mode): string
    {
        return $mode === 'disk' ? 'the persistence drive (disk)' : 'RAM (zram)';
    }

    /** 'zram' | 'disk' for the layer a mounted home uses now; null when it is not mounted. */
    public static function liveMode(string $user, array $seams = []): ?string
    {
        $mnt = rtrim(UtilityService::getWorkDir($user) . '/home', '/');
        $mounts = $seams['mounts'] ?? (is_readable('/proc/mounts') ? (string)@file_get_contents('/proc/mounts') : '');
        if (is_callable($mounts)) $mounts = $mounts();
        $upper = StorageMountService::overlayOptionAt((string)$mounts, $mnt, 'upperdir');
        if ($upper === null) return null;
        return strpos($upper, StoragePathResolver::zramBase() . '/') === 0 ? 'zram' : 'disk';
    }

    /**
     * One row per home: where its writable layer is, how big, and whether it must move.
     * @return array<int,array{id:string,mounted:bool,live:?string,mb:int,needs_move:bool}>
     */
    public static function homeStates(string $target, array $config, array $seams = []): array
    {
        $target = self::normalize($target);
        $other = $target === 'zram' ? 'disk' : 'zram';
        $ids = isset($seams['homes'])
            ? array_values($seams['homes'])
            : array_values(array_map(static fn(array $e): string => $e['id'],
                array_filter(StorageBackendMigrationService::entities($config), static fn(array $e): bool => $e['type'] === 'home')));
        $holds = $seams['holds_data'] ?? static fn(string $dir): bool => StorageMountService::upperHoldsData($dir);
        $size = $seams['size_mb'] ?? static function (string $dir): int {
            if (!is_dir($dir)) return 0;
            return (int)trim((string)@shell_exec('du -sm ' . escapeshellarg($dir) . ' 2>/dev/null | cut -f1'));
        };
        $out = [];
        foreach ($ids as $id) {
            $persist = (string)($seams['persist_path'] ?? StoragePathResolver::homePersistPath($id));
            $safe = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $id) ?: 'unknown';
            $live = self::liveMode($id, $seams);
            $otherUpper = StorageMountService::homeUpperForMode($safe, $persist, $other);
            if ($live !== null) {
                $needs = ($live !== $target);
                $mb = $needs ? (int)$size(StorageMountService::homeUpperForMode($safe, $persist, $live)) : 0;
            } else {
                $needs = (bool)$holds($otherUpper);
                $mb = $needs ? (int)$size($otherUpper) : 0;
            }
            $out[] = ['id' => $id, 'mounted' => $live !== null, 'live' => $live, 'mb' => $mb, 'needs_move' => $needs];
        }
        return $out;
    }

    /**
     * R-130: size in MB of the zram device mounted at ZRAM_BASE; 0 when nothing is
     * mounted there. Before the device is mounted, ZRAM_BASE is a plain folder on
     * the RAM root, and disk_total_space() of that folder reports the whole RAM
     * disk, not the zram device. Seam: 'mounts' (a /proc/mounts-style table),
     * 'zram_disk_total' (callable returning bytes).
     */
    public static function zramDeviceMb(array $seams = []): int
    {
        $mounts = $seams['mounts'] ?? (is_readable('/proc/mounts') ? (string)@file_get_contents('/proc/mounts') : '');
        if (is_callable($mounts)) $mounts = $mounts();
        // zramBase(), not the ZRAM_BASE constant: the state root can be redirected (#646), and the
        // rest of this service (liveMode, homeStates) already reads zramBase(). Production: the same path.
        $zramBase = StoragePathResolver::zramBase();
        if (!StorageMountService::mountTableHasTarget((string)$mounts, $zramBase)) return 0;
        $bytes = isset($seams['zram_disk_total'])
            ? (float)$seams['zram_disk_total']()
            : (float)@disk_total_space($zramBase);
        return (int)floor($bytes / 1048576);
    }

    /** Read-only: what a change to $target would do, and whether it is allowed. */
    public static function preflight(string $target, ?array $config = null, array $seams = []): array
    {
        $config = $config ?? ConfigService::getConfig();
        if (!in_array($target, self::MODES, true)) {
            return ['status' => 'error', 'message' => 'Unknown writable-layer placement.'];
        }
        $current = self::currentMode($config);
        if (StorageBackendPolicyService::kindMode($config, StorageBackendPolicyService::KIND_HOMES) !== StorageBackendPolicyService::LAYERING) {
            return ['status' => 'error', 'message' => 'The writable layer applies to homes on compressed layers. Homes on plain folders have none. Change Home storage first.'];
        }
        $usb = $seams['usb'] ?? (static function () use ($config): bool {
            $v = StorageBackendPolicyService::targetVerdicts($config);
            return !empty($v['home']['is_usb_or_removable']);
        })();
        if ($target === 'disk' && $usb) {
            return ['status' => 'error', 'message' => 'The home storage target is removable or USB flash. A writable layer on it would wear it out, so RAM (zram) stays the only choice here.'];
        }
        $homes = self::homeStates($target, $config, $seams);
        $moves = array_values(array_filter($homes, static fn(array $h): bool => $h['needs_move']));
        $totalMb = array_sum(array_map(static fn(array $h): int => $h['mb'], $moves));

        if ($moves !== []) {
            if ($target === 'zram') {
                $zramMb = isset($seams['zram_total_mb']) ? (int)$seams['zram_total_mb'] : self::zramDeviceMb($seams);
                if ($zramMb <= 0) {
                    return ['status' => 'error', 'message' => 'The RAM layer device is not mounted yet, so there is no way to check that the writable layers fit in RAM. Start the array (or save a home once) so the device exists, then try again.'];
                }
                if ($totalMb > (int)floor($zramMb * self::ZRAM_MAX_SHARE)) {
                    return ['status' => 'error', 'message' => "The writable layers hold $totalMb MB and the RAM layer device holds $zramMb MB. Moving them to RAM would not fit (limit "
                        . (int)floor(self::ZRAM_MAX_SHARE * 100) . ' % of the device). Save and merge the homes first, or keep the disk layer.'];
                }
            } else {
                // R-046 (#624): the one resolver (blank home path falls through like the shell).
                $persist = StoragePathResolver::homePersistPathFromConfig($config);
                $freeMb = (int)($seams['free_mb'] ?? (int)floor((float)@disk_free_space($persist) / 1048576));
                if ($freeMb < $totalMb + 512) {
                    return ['status' => 'error', 'message' => "The persistence drive has $freeMb MB free and the move needs about " . ($totalMb + 512) . ' MB (the layers plus a margin). Free some space first.'];
                }
            }
        }

        if ($target === 'disk') {
            $what = 'Each home\'s writable layer will live on the persistence drive. It survives a crash or power cut, and uses space on that drive.';
        } else {
            $what = 'Each home\'s writable layer will live in RAM. It is faster and wears no disk, but changes made since the last save are LOST if the server crashes or loses power. The plugin saves homes on a schedule and before shutdown.';
        }
        $message = $moves === []
            ? $what . ' No home needs to move; only the setting changes.'
            : $what . ' ' . count($moves) . ' home' . (count($moves) === 1 ? '' : 's') . ' (' . $totalMb . ' MB in the old layer) will move: every open workspace is closed first (each can be reopened and continues where it was), the changes are saved into a verified layer, then the home is mounted again on the new placement.';
        return [
            'status' => 'ok',
            'target' => $target,
            'current' => $current,
            // Only the homes that matter to the person: one in use, or one that must move.
            // (An Unraid user with no mounted home and no data is not a row.)
            'homes' => array_values(array_filter($homes, static fn(array $h): bool => $h['mounted'] || $h['needs_move'])),
            'moves' => count($moves),
            'total_mb' => $totalMb,
            'message' => $message,
        ];
    }

    /**
     * #565: why a home did not move after a good save. The bake script writes the
     * reason token (live_upper_holds_data, home_in_use) to a marker file. Without a
     * marker the old general text stays.
     */
    public static function convergeReason(string $id, array $seams = []): string
    {
        $token = isset($seams['converge_reason'])
            ? (string)$seams['converge_reason']($id)
            : (static function (string $id): string {
                $f = SecretPaths::stateRoot() . '/.upper_converge_reason_home_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $id);
                $raw = is_file($f) ? @file_get_contents($f) : false;
                return $raw === false ? '' : trim($raw);
            })($id);
        switch ($token) {
            case 'live_upper_holds_data':
                return 'the old writable layer still holds changes that the save did not capture (files that changed during the save, or files the save could not store). Nothing was deleted. Try again when the home is idle';
            case 'home_in_use':
                return 'a workspace or a process is still using the home, so it could not be mounted again on the new placement';
            default:
                return 'the home is still in use, so it could not be mounted again on the new placement';
        }
    }

    // ---- The move as a background job (#880) ----------------------------------------------------
    // docs/specs/GLOBAL_STORAGE_BACKEND_MIGRATION.md "Apply flow rules (#880)". The move used to hold a
    // request open and a whole-screen overlay shut until it ended. It is now a job like the engine change
    // of a kind: the request reserves a record under the migration lock, starts the detached worker with the
    // shared launcher, and returns. The card polls the record. The tray item follows the record. While the job
    // is queued or running, every other storage job, workspace start and storage action is refused
    // (StorageBackendMigrationService::isMigrationInProgress() reads isJobActive()), and the worker holds the
    // exclusive launch guard for the whole move, so no workspace can start after the close.

    public const ACTIVITY_ID = 'storage_upper_move';
    private const STALE_QUEUED_SECONDS = 60;
    private const STALE_RUNNING_SECONDS = 90;
    private const GUARD_WAIT_SECONDS = 60;

    /** True only inside the move worker: its own process must not be held back by its own job. */
    private static bool $selfWorker = false;

    /** The job record in RAM (the state root under test is the sandbox). */
    public static function statusPath(): string
    {
        $e = getenv('AICLI_UPPER_PLACEMENT_STATUS');
        return ($e !== false && $e !== '') ? $e : SecretPaths::stateRoot() . '/upper-placement-status.json';
    }

    /** The in-flight copy on flash: it survives a reboot, so a move that died can be settled and the setting put back. */
    public static function durablePath(): string
    {
        $e = getenv('AICLI_UPPER_PLACEMENT_DURABLE');
        return ($e !== false && $e !== '') ? $e : SecretPaths::flashRoot() . '/upper-placement-status.json';
    }

    private static function readFile(string $path): ?array
    {
        $json = @file_get_contents($path);
        $data = is_string($json) ? json_decode($json, true) : null;
        return is_array($data) ? $data : null;
    }

    /** RAM record first; after a reboot the flash copy of a job that was in flight. */
    private static function readRecord(): array
    {
        $ram = self::readFile(self::statusPath());
        if ($ram !== null && (string)($ram['status'] ?? 'idle') !== 'idle') return $ram;
        $durable = self::readFile(self::durablePath());
        if ($durable !== null && in_array((string)($durable['status'] ?? ''), ['queued', 'running'], true)) return $durable;
        return $ram ?? ['status' => 'idle'];
    }

    private static function writeFile(string $path, array $record): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $json = json_encode($record, JSON_UNESCAPED_SLASHES);
        if ($json !== false && @file_put_contents($tmp, $json, LOCK_EX) !== false && @rename($tmp, $path)) return true;
        @unlink($tmp);
        return false;
    }

    private static function writeRecord(array $record, bool $durable = false): bool
    {
        $ok = self::writeFile(self::statusPath(), $record);
        if ($durable) self::writeFile(self::durablePath(), $record);
        return $ok;
    }

    private static function clearDurable(): void
    {
        $p = self::durablePath();
        if (is_file($p)) @unlink($p);
    }

    /** True while a move is queued or running, except inside the move worker itself. */
    public static function isJobActive(array $seams = []): bool
    {
        if (self::$selfWorker) return false;
        return in_array((string)(self::status($seams)['status'] ?? 'idle'), ['queued', 'running'], true);
    }

    /** The job record: idle | queued | running | complete | error. A dead worker is settled here. */
    public static function status(array $seams = []): array
    {
        $s = self::readRecord();
        $state = (string)($s['status'] ?? 'idle');
        if (!in_array($state, ['queued', 'running'], true)) return $s;
        $pidAlive = static function (array $rec) use ($seams): bool {
            $pid = (int)($rec['worker_pid'] ?? 0);
            if ($pid < 1) return false;
            return isset($seams['pid_alive']) ? (bool)$seams['pid_alive']($pid) : UtilityService::isPidRunning($pid);
        };
        $age = time() - (int)($s['updated_at'] ?? 0);
        if ($state === 'queued' && $age > self::STALE_QUEUED_SECONDS && !$pidAlive($s)) {
            return self::settle($s, 'The move of the writable layer did not start. Nothing was changed.', false, $seams);
        }
        if ($state === 'running' && (int)($s['worker_pid'] ?? 0) > 0) {
            // The worker holds the exclusive launch guard for the whole move. A free guard together with a dead
            // process (or an old record, which covers a recycled process id) means the worker is gone.
            $guardFree = isset($seams['guard_free'])
                ? (bool)$seams['guard_free']()
                : (static function (): bool {
                    $probe = StorageBackendMigrationService::acquireLaunchGuard(false);
                    if ($probe === false) return false;
                    StorageBackendMigrationService::releaseLaunchGuard($probe);
                    return true;
                })();
            if ($guardFree && (!$pidAlive($s) || $age > self::STALE_RUNNING_SECONDS)) {
                return self::settle($s, 'The move of the writable layer stopped before it finished. Nothing was deleted.', true, $seams);
            }
        }
        return $s;
    }

    /**
     * End a record as an error and fail its tray item in the same step. $restore puts the saved setting back to the
     * value before the move: the worker saves the new value early, so a worker that died would leave the setting
     * on a placement that the data does not have.
     */
    private static function settle(array $record, string $message, bool $restore, array $seams = []): array
    {
        if ($restore && isset($record['previous']) && in_array((string)$record['previous'], self::MODES, true)) {
            $config = $seams['config'] ?? ConfigService::getConfig();
            if (self::currentMode($config) !== (string)$record['previous']) {
                $save = $seams['save_config'] ?? static fn(array $c): bool => (bool)ConfigService::saveConfig($c, false);
                if ($save(['storage_upper_mode' => (string)$record['previous']])) {
                    $message .= ' The setting is back to ' . self::label((string)$record['previous']) . '.';
                }
            }
        }
        $record['status'] = 'error';
        $record['message'] = $message;
        $record['current'] = '';
        $record['updated_at'] = time();
        self::writeRecord($record);
        self::clearDurable();
        self::trayFail($message);
        return $record;
    }

    /** The plain refusal while a move is queued or running, or null. */
    public static function jobRefusal(array $seams = []): ?array
    {
        $s = $seams['upper_status'] ?? (self::$selfWorker ? ['status' => 'idle'] : self::status($seams));
        $state = (string)($s['status'] ?? 'idle');
        if (!in_array($state, ['queued', 'running'], true)) return null;
        return ['status' => 'error', 'reason' => 'storage_job_active',
            'message' => 'A move of the writable layer is already ' . ($state === 'queued' ? 'waiting to start' : 'running') . '. Wait for it to finish, then press Apply again.'];
    }

    private static function trayUpdate(array $record): void
    {
        if (!class_exists(ActivityService::class)) return;
        $total = max(0, (int)($record['total'] ?? 0));
        $done = max(0, (int)($record['completed'] ?? 0));
        ActivityService::update(self::ACTIVITY_ID, [
            'type' => 'migrate',
            'label' => 'Moving the writable layer to ' . ((string)($record['target'] ?? '') === 'disk' ? 'disk' : 'RAM'),
            'status' => 'running',
            'step' => (string)($record['message'] ?? 'Moving the writable layer.'),
            'progress' => $total > 0 ? min(99, (int)floor(100 * $done / $total)) : 1,
            'meta' => ['kind' => 'job'],
        ]);
    }

    private static function trayFail(string $message): void
    {
        if (class_exists(ActivityService::class)) ActivityService::fail(self::ACTIVITY_ID, $message, 'Check the Storage card, then try again.', [
            'type' => 'migrate', 'label' => 'Writable-layer move needs attention', 'meta' => ['kind' => 'notice'],
        ]);
    }

    private static function trayFinish(string $step): void
    {
        if (class_exists(ActivityService::class)) ActivityService::finish(self::ACTIVITY_ID, $step);
    }

    /** The plain reason why a path move blocks this move, or null. Seam: path_move_active (bool). */
    private static function pathMoveRefusal(array $seams): ?string
    {
        require_once __DIR__ . '/FileStorage.php';
        require_once __DIR__ . '/StoragePathMoveJobService.php';
        $active = isset($seams['path_move_active'])
            ? (bool)$seams['path_move_active']
            : (FileStorage::isMigrationInProgress() || StoragePathMoveJobService::hasPending());
        return $active ? 'A storage path move is queued or running. Wait for it to finish before you move the writable layer.' : null;
    }

    /**
     * Start the move in the background. Answers `saved` when no home has to move (only the setting changes),
     * `accepted` when the worker runs, or an error with a plain reason. It never holds the request open.
     *
     * The reservation happens under FileStorage::acquireMigrationLock(), the lock that the engine change of a kind
     * also takes, and the refusal is repeated after the slow check and just before the record is written, so the
     * two job kinds cannot both start. Seams (tests): those of preflight()/migrate(), plus job_refusal (array|null),
     * path_move_active, after_preflight (callable), spawn_detached, php, wait_worker (callable), worker_wait_seconds,
     * clock, worker_sleep.
     */
    public static function startAsync(string $target, ?array $config = null, array $seams = []): array
    {
        $config = $config ?? ConfigService::getConfig();
        require_once __DIR__ . '/FileStorage.php';
        $lock = FileStorage::acquireMigrationLock(FileStorage::MIGRATION_LOCK_WAIT_S);
        if ($lock === null) {
            return StorageBackendMigrationService::refusalFor(StorageBackendPolicyService::KIND_ALL, false, $seams)
                ?? ['status' => 'error', 'message' => 'Another storage operation is already running. Wait for it to finish, then try again.'];
        }
        try {
            $conflict = self::pathMoveRefusal($seams);
            if ($conflict !== null) return ['status' => 'error', 'reason' => 'storage_job_active', 'message' => $conflict];
            $check = self::preflight($target, $config, $seams);
            if (($check['status'] ?? '') !== 'ok') return $check;
            if (isset($seams['after_preflight']) && is_callable($seams['after_preflight'])) ($seams['after_preflight'])();
            $refusal = array_key_exists('job_refusal', $seams)
                ? $seams['job_refusal']
                : StorageBackendMigrationService::refusalFor(StorageBackendPolicyService::KIND_ALL, false, $seams);
            if ($refusal !== null) return $refusal;
            if ((int)$check['moves'] === 0) {
                // Only the setting changes: no job, no workspace is closed.
                $save = $seams['save_config'] ?? static fn(array $c): bool => (bool)ConfigService::saveConfig($c, false);
                if (!$save(['storage_upper_mode' => $target])) return ['status' => 'error', 'message' => 'The setting could not be saved.'];
                return ['status' => 'saved', 'target' => $target, 'message' => 'Writable layer set to ' . self::label($target) . '. No home needed to move.'];
            }
            $now = time();
            $jobId = bin2hex(random_bytes(6));
            $record = [
                'status' => 'queued', 'job_id' => $jobId, 'target' => $target, 'previous' => self::currentMode($config),
                'started_at' => $now, 'updated_at' => $now, 'worker_pid' => 0,
                'completed' => 0, 'total' => (int)$check['moves'], 'current' => '',
                'message' => 'The move is queued. The open workspaces are closed when it starts.',
            ];
            if (!self::writeRecord($record, true)) return ['status' => 'error', 'message' => 'The move could not be saved. Nothing was changed.'];
            if (class_exists(ActivityService::class)) ActivityService::dismiss(self::ACTIVITY_ID); // a finished item of an earlier move must not carry over
            self::trayUpdate($record);
        } finally {
            FileStorage::releaseMigrationLock($lock);
        }
        $script = (string)($seams['worker_script'] ?? dirname(__DIR__, 2) . '/scripts/storage-upper-move.php');
        $pid = UtilityService::launchPhpWorker($script, [$target, $jobId], self::statusPath() . '.log', $seams);
        $started = $pid > 0 && (
            isset($seams['wait_worker']) && is_callable($seams['wait_worker'])
                ? (bool)$seams['wait_worker']()
                : UtilityService::awaitWorkerStart(static function (): bool {
                    $r = self::readRecord();
                    return (int)($r['worker_pid'] ?? 0) > 0 || (string)($r['status'] ?? 'queued') !== 'queued';
                }, $seams)
        );
        if (!$started) {
            $message = 'The move of the writable layer did not start. Nothing was changed. Check the Debug Console, then try again.';
            self::settle($record, $message, false, $seams);
            return ['status' => 'error', 'message' => $message];
        }
        return ['status' => 'accepted', 'target' => $target, 'worker_pid' => $pid,
            'message' => 'The move started. Your open workspaces are closed, then each home is saved and mounted again.'];
    }

    /** Claim the queued record for this worker under a short lock (same rule as the engine change). */
    private static function claim(string $jobId): ?array
    {
        $fh = @fopen(self::statusPath() . '.claim', 'c');
        $locked = false;
        if ($fh !== false) {
            $deadline = microtime(true) + 3.0;
            while (!($locked = @flock($fh, LOCK_EX | LOCK_NB)) && microtime(true) < $deadline) usleep(50000);
        }
        try {
            if (!$locked) return null;
            $record = self::readFile(self::statusPath()) ?? self::readRecord();
            if ((string)($record['status'] ?? '') !== 'queued') return null;
            if ($jobId !== '' && (string)($record['job_id'] ?? '') !== $jobId) return null;
            $record['status'] = 'running';
            $record['worker_pid'] = getmypid();
            $record['updated_at'] = time();
            $record['message'] = 'Waiting for workspace starts to finish.';
            self::writeRecord($record, true);
            return $record;
        } finally {
            if ($fh !== false) { if ($locked) @flock($fh, LOCK_UN); @fclose($fh); }
        }
    }

    /**
     * The detached worker: claim the queued record, hold the exclusive launch guard for the whole move, run the
     * move, and keep the record and the tray item in step. A late worker, or one that meets a newer job, stops.
     */
    public static function runQueuedMove(string $target, array $seams = [], string $jobId = ''): array
    {
        $record = self::claim($jobId);
        if ($record === null) {
            return ['status' => 'error', 'message' => 'The queued move is no longer waiting, so this worker stopped without changing anything.'];
        }
        self::$selfWorker = true;
        $guard = false;
        try {
            self::trayUpdate($record);
            $conflict = self::pathMoveRefusal($seams);
            if ($conflict !== null) {
                self::settle($record, $conflict . ' Nothing was changed.', false, $seams);
                return ['status' => 'error', 'message' => $conflict];
            }
            // No workspace may start between the close and the remount: hold the exclusive guard, as the engine change does.
            $acquire = $seams['acquire_guard'] ?? static fn() => StorageBackendMigrationService::acquireLaunchGuard(true);
            $wait = $seams['guard_wait'] ?? static function (int $microseconds): void { usleep($microseconds); };
            $started = microtime(true);
            while (($guard = $acquire()) === false) {
                if (microtime(true) - $started >= (float)($seams['guard_wait_seconds'] ?? self::GUARD_WAIT_SECONDS)) {
                    $message = 'A workspace start did not finish in time. Nothing was changed. Try again.';
                    self::settle($record, $message, false, $seams);
                    return ['status' => 'error', 'message' => $message];
                }
                $wait(100000);
            }
            $record['message'] = 'Checking the homes.';
            $record['updated_at'] = time();
            self::writeRecord($record, true);
            self::trayUpdate($record);
            $seams['progress'] = $seams['progress'] ?? static function (int $done, int $total, string $current, string $message) use (&$record): void {
                $record['completed'] = $done;
                $record['total'] = $total;
                $record['current'] = $current;
                $record['message'] = $message;
                $record['updated_at'] = time();
                self::writeRecord($record);
                self::trayUpdate($record);
            };
            try {
                $result = self::migrate($target, $seams['config'] ?? null, $seams);
            } catch (\Throwable $e) {
                self::settle($record, 'The move stopped on an error. Nothing was deleted. Check the Debug Console.', true, $seams);
                throw $e;
            }
            $record['updated_at'] = time();
            $record['message'] = (string)($result['message'] ?? '');
            $record['current'] = '';
            if (($result['status'] ?? '') === 'ok') {
                $record['status'] = 'complete';
                $record['completed'] = (int)($record['total'] ?? 0);
                self::writeRecord($record);
                self::clearDurable();
                self::trayFinish('Writable layer moved to ' . self::label($target) . '.');
            } else {
                $record['status'] = 'error';
                self::writeRecord($record);
                self::clearDurable();
                self::trayFail($record['message']);
            }
            return $result;
        } finally {
            if ($guard !== false && $guard !== null) StorageBackendMigrationService::releaseLaunchGuard($guard);
            self::$selfWorker = false;
        }
    }

    /** #882: the plain sentence about homes after a failed move. Never claims that a home which moved is where it was. */
    private static function movedHomesSentence(array $movedIds): string
    {
        if ($movedIds === []) return 'No home moved, so your data is where it was and nothing was deleted.';
        return 'These homes had already moved and stay moved: ' . implode(', ', $movedIds) . '. The other homes are where they were, and nothing was deleted.';
    }

    /**
     * Change the placement and move the data. When it ends, the setting matches the data: a failure puts the old value
     * back. A worker that DIES mid-move cannot do that itself; status() settles such a record and puts the old value back.
     */
    public static function migrate(string $target, ?array $config = null, array $seams = []): array
    {
        $config = $config ?? ConfigService::getConfig();
        $check = self::preflight($target, $config, $seams);
        if (($check['status'] ?? '') !== 'ok') return $check;

        $lock = $seams['lock'] ?? static function (): mixed {
            @mkdir(dirname(self::lockFile()), 0755, true);
            $fp = @fopen(self::lockFile(), 'c');
            return ($fp && flock($fp, LOCK_EX | LOCK_NB)) ? $fp : false;
        };
        $fp = $lock();
        if ($fp === false) {
            return ['status' => 'error', 'message' => 'Another writable-layer move is already running.'];
        }
        $save = $seams['save_config'] ?? static fn(array $c): bool => (bool)ConfigService::saveConfig($c, false);
        try {
            $prev = self::currentMode($config);
            $moves = array_values(array_filter($check['homes'], static fn(array $h): bool => $h['needs_move']));
            if ($moves === []) {
                if (!$save(['storage_upper_mode' => $target])) {
                    return ['status' => 'error', 'message' => 'The setting could not be saved.'];
                }
                return ['status' => 'ok', 'message' => 'Writable layer set to ' . self::label($target) . '. No home needed to move.', 'moved' => []];
            }

            // No workspace may keep writing while its writable layer changes.
            $evict = $seams['evict'] ?? static function (): void {
                if (class_exists(TerminalService::class) && method_exists(TerminalService::class, 'captureResumeForShutdown')) {
                    TerminalService::captureResumeForShutdown(null, 60);
                }
                ProcessManager::evictAll();
            };
            $progress = $seams['progress'] ?? static function (int $done, int $total, string $current, string $message): void {};
            $progress(0, count($moves), '', 'Closing the open workspaces.');
            $evict();

            // The bake decides the layer of the remount from the saved policy, so save it first
            // and put the old value back if any home does not move.
            if (!$save(['storage_upper_mode' => $target])) {
                return ['status' => 'error', 'message' => 'The setting could not be saved. Nothing was moved.'];
            }
            $persist = $seams['persist'] ?? static function (string $id): array {
                $r = FileStorage::persist('home/' . $id);
                return ['saved' => (bool)$r->saved || (bool)$r->ok, 'exit' => $r->exit, 'reason' => $r->deferReason];
            };
            $reasons = [];
            $savedIds = [];
            try {
                $doneCount = 0;
                foreach ($moves as $h) {
                    $progress($doneCount, count($moves), (string)$h['id'], 'Saving and mounting the home of ' . $h['id'] . ' again.');
                    $r = $persist($h['id']);
                    if (empty($r['saved'])) $reasons[$h['id']] = ($r['reason'] ?? '') ?: ('save exit ' . ($r['exit'] ?? '?'));
                    else $savedIds[] = (string)$h['id'];
                    $progress(++$doneCount, count($moves), (string)$h['id'], 'Saved the home of ' . $h['id'] . '.');
                }
                $after = self::homeStates($target, $config, $seams);
            } catch (\Throwable $e) {
                // R-130: the setting was saved early (the bake reads it). An exception must
                // not leave it out of step with the data, so put the old value back.
                $save(['storage_upper_mode' => $prev]);
                LogService::log('Writable-layer move failed: ' . $e->getMessage(), LogService::LOG_ERROR, 'UpperPlacementService');
                return ['status' => 'error', 'message' => 'The move stopped on an error. The setting is back to ' . self::label($prev) . '. ' . self::movedHomesSentence($savedIds), 'moved' => $savedIds];
            }
            $failed = [];
            foreach ($after as $h) {
                if ($h['needs_move']) $failed[$h['id']] = $reasons[$h['id']] ?? self::convergeReason($h['id'], $seams);
            }
            if ($failed !== []) {
                $save(['storage_upper_mode' => $prev]);
                $list = [];
                foreach ($failed as $id => $why) $list[] = "$id: $why";
                // #882 (item 9): a home that moved before another one failed stays moved. Name it.
                $movedIds = array_values(array_filter(array_map(static fn(array $h): string => (string)$h['id'], $moves), static fn(string $id): bool => !isset($failed[$id])));
                return ['status' => 'error', 'message' => 'Not moved (' . implode('; ', $list) . '). The setting is back to ' . self::label($prev) . '. ' . self::movedHomesSentence($movedIds), 'moved' => $movedIds];
            }
            return ['status' => 'ok',
                'message' => 'Moved ' . count($moves) . ' home' . (count($moves) === 1 ? '' : 's') . ' to ' . self::label($target) . '. Reopen your workspaces to continue.',
                'moved' => array_map(static fn(array $h): string => $h['id'], $moves)];
        } finally {
            if (is_resource($fp)) { @flock($fp, LOCK_UN); @fclose($fp); }
        }
    }
}
