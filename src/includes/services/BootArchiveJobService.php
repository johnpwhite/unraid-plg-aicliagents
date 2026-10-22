<?php
/**
 * <module_context>
 * Description: The background job behind "Archive…" and "Remove" on the Boot disk space card
 *   (docs/specs/BOOT_DISK_SPACE.md R8, #724). The web request calls start(): it checks nothing on
 *   disk, writes an options file, puts one entry in the Activity tray and starts the detached
 *   script scripts/storage/boot-archive.php. That script calls run(), which does the work with
 *   BootArchiveService::process(), keeps the tray entry up to date, writes a result file and asks
 *   for a fresh background measurement.
 * Dependencies: BootArchiveService, ActivityService, UtilityService (spawnDetached), BootSpaceService.
 * Constraints: The request never copies, removes or measures. State files live in
 *   /tmp/unraid-aicliagents/boot-archive (AICLI_BOOT_ARCHIVE_STATE_DIR in tests). One job at a time
 *   for the same kind and home. The tray entry carries no secret and no full path of a person's file.
 * </module_context>
 */

namespace AICliAgents\Services;

require_once __DIR__ . '/BootArchiveService.php';

class BootArchiveJobService
{
    public static function stateDir(): string
    {
        $env = getenv('AICLI_BOOT_ARCHIVE_STATE_DIR');
        return ($env !== false && $env !== '') ? rtrim($env, '/') : SecretPaths::stateRoot() . '/boot-archive';
    }

    private static function path(string $jobId, string $suffix): string
    {
        return self::stateDir() . '/' . preg_replace('/[^A-Za-z0-9._\-]/', '_', $jobId) . '.' . $suffix;
    }

    public static function trayId(string $jobId): string
    {
        return 'boot_archive_' . preg_replace('/[^A-Za-z0-9_]/', '_', $jobId);
    }

    /** The modes of a job. restore_plan only compares (no tray entry); restore copies (R8, #755). */
    public const MODES = ['archive', 'remove', 'restore_plan', 'restore'];

    /** Plain tray title: "Archive quarantine of home root". */
    public static function title(string $mode, string $kind, string $user): string
    {
        $d = BootArchiveService::describe($kind);
        $verb = ['archive' => 'Archive ', 'remove' => 'Remove ', 'restore' => 'Restore ', 'restore_plan' => 'Check the archive of '][$mode] ?? 'Remove ';
        return $verb . $d['label'] . ' of home ' . $user;
    }

    /**
     * Queue a job. Returns [jobId|null, error|null]. $seams (tests): spawn (fn(array $argv): int),
     * no_track (bool), job_id.
     * @param array{kind:string,user:string,mode:string,folder?:string,manifest?:string,policy?:string} $opts
     * @return array{0:?string,1:?string}
     */
    public static function start(array $opts, array $seams = []): array
    {
        $kind = (string)($opts['kind'] ?? ''); $user = (string)($opts['user'] ?? ''); $mode = (string)($opts['mode'] ?? '');
        if (!BootArchiveService::isKind($kind) || !BootArchiveService::isUser($user) || !in_array($mode, self::MODES, true)) {
            return [null, 'The request is not valid.'];
        }
        $isRestore = in_array($mode, ['restore', 'restore_plan'], true);
        $manifest = $isRestore ? (string)($opts['manifest'] ?? '') : '';
        $policy = $mode === 'restore' ? (string)($opts['policy'] ?? 'skip') : '';
        if ($isRestore && ($manifest === '' || $manifest[0] !== '/' || strpos($manifest, "\0") !== false)) {
            return [null, 'The request is not valid.'];
        }
        if ($mode === 'restore' && !in_array($policy, BootArchiveService::POLICIES, true)) {
            return [null, 'The request is not valid.'];
        }
        if (self::running($kind, $user)) {
            return [null, 'Another job for this data is running. Wait until it ends.'];
        }
        $jobId = (string)($seams['job_id'] ?? ('bootarchive-' . $mode . '-' . $kind . '-' . date('His') . '-' . bin2hex(random_bytes(3))));
        $folder = $mode === 'archive' ? (string)($opts['folder'] ?? '') : '';
        if (!self::writeJson(self::path($jobId, 'opts.json'), ['kind' => $kind, 'user' => $user, 'mode' => $mode, 'folder' => $folder,
                'manifest' => $manifest, 'policy' => $policy, 'queued_at' => time()])) {
            return [null, 'The job could not be queued.'];
        }
        if (empty($seams['no_track']) && $mode !== 'restore_plan') {
            ActivityService::register(self::trayId($jobId), 'storage', self::title($mode, $kind, $user), [
                'step' => 'Queued', 'progress' => 5, 'meta' => ['jobId' => $jobId, 'cancel' => 'none'],
            ]);
        }
        $script = dirname(__DIR__, 2) . '/scripts/storage/boot-archive.php';
        $argv = ['nice', '-n', '19', 'php', '-d', 'display_errors=0', $script, $jobId];
        if (isset($seams['spawn'])) {
            ($seams['spawn'])($argv);
        } else {
            require_once __DIR__ . '/UtilityService.php';
            UtilityService::spawnDetached($argv);
        }
        return [$jobId, null];
    }

    /** True while a job of the same kind and home has no result file. */
    public static function running(string $kind, string $user): bool
    {
        foreach (glob(self::stateDir() . '/bootarchive-*.opts.json') ?: [] as $f) {
            $o = self::readJson($f);
            if (($o['kind'] ?? '') !== $kind || ($o['user'] ?? '') !== $user) continue;
            $id = basename($f, '.opts.json');
            if (is_file(self::path($id, 'result.json'))) continue;
            // A job that has no result after 6 hours is dead.
            if (time() - (int)($o['queued_at'] ?? 0) < 21600) return true;
        }
        return false;
    }

    /**
     * True when a plan job for this manifest ended well in the last 6 hours. A restore starts only
     * after that: the person has seen what it would change (R8, #755).
     */
    public static function planned(string $manifest): bool
    {
        foreach (glob(self::stateDir() . '/bootarchive-*.opts.json') ?: [] as $f) {
            $o = self::readJson($f);
            if (($o['mode'] ?? '') !== 'restore_plan' || ($o['manifest'] ?? '') !== $manifest) continue;
            if (time() - (int)($o['queued_at'] ?? 0) > 21600) continue;
            $r = self::readJson(self::path(basename($f, '.opts.json'), 'result.json'));
            if (($r['status'] ?? '') === 'ok') return true;
        }
        return false;
    }

    /** @return array<string,mixed> */
    public static function readResult(string $jobId): array
    {
        return self::readJson(self::path($jobId, 'result.json'));
    }

    /**
     * Run a queued job. Exit code: 0 done, 1 failed. $seams: process (fn(home,user,kind,mode,folder,seams):array),
     * home (string), no_track, plus the seams of BootArchiveService::process().
     */
    public static function run(string $jobId, array $seams = []): int
    {
        $o = self::readJson(self::path($jobId, 'opts.json'));
        $kind = (string)($o['kind'] ?? ''); $user = (string)($o['user'] ?? ''); $mode = (string)($o['mode'] ?? '');
        // A plan only compares files: it has no tray entry. A restore has one.
        $tray = (empty($seams['no_track']) && $mode !== 'restore_plan') ? self::trayId($jobId) : '';
        if (!BootArchiveService::isKind($kind) || !BootArchiveService::isUser($user) || !in_array($mode, self::MODES, true)) {
            self::writeJson(self::path($jobId, 'result.json'), ['status' => 'error', 'message' => 'The job has no valid options.']);
            if ($tray !== '') ActivityService::fail($tray, 'The job has no valid options.');
            return 1;
        }
        $title = self::title($mode, $kind, $user);
        try {
            if ($tray !== '') ActivityService::update($tray, ['step' => 'Looking for the items', 'progress' => 10]);
            $home = (string)($seams['home'] ?? BootArchiveService::homeDir($user, $seams));
            $inner = $seams;
            $inner['progress'] = static function (array $p) use ($tray, $mode): void {
                if ($tray === '') return;
                $total = max(1, (int)($p['total'] ?? 1));
                $pct = 10 + (int)round(85 * ((int)($p['done'] ?? 0) / $total));
                $verb = ['archive' => 'Archiving', 'remove' => 'Removing', 'restore' => 'Restoring'][$mode] ?? 'Working on';
                ActivityService::update($tray, ['step' => $verb . ' ' . min($total, (int)($p['done'] ?? 0) + 1) . ' of ' . $total, 'progress' => min(95, $pct)]);
            };
            if ($mode === 'restore_plan') {
                $res = BootArchiveService::restorePlan($home, (string)($o['manifest'] ?? ''), $inner);
            } elseif ($mode === 'restore') {
                $res = BootArchiveService::restore($home, (string)($o['manifest'] ?? ''), (string)($o['policy'] ?? 'skip'), $inner);
            } else {
                $res = isset($seams['process'])
                    ? ($seams['process'])($home, $user, $kind, $mode, (string)($o['folder'] ?? ''), $inner)
                    : BootArchiveService::process($home, $user, $kind, $mode, (string)($o['folder'] ?? ''), $inner);
            }
        } catch (\Throwable $e) {
            LogService::log('Job ' . $jobId . ' stopped: ' . $e->getMessage(), LogService::LOG_ERROR, 'BootArchiveJob');
            $res = ['status' => 'error', 'message' => 'The job stopped with an error. Details are in the Debug Console.'];
        }
        self::writeJson(self::path($jobId, 'result.json'), $res);
        $ok = ($res['status'] ?? '') === 'ok';
        if ($tray !== '') {
            if ($ok) ActivityService::finish($tray, (string)($res['message'] ?? ''));
            else ActivityService::fail($tray, mb_substr((string)($res['message'] ?? $title . ' failed.'), 0, 200));
        }
        try {
            LifecycleLogService::log($ok ? LifecycleLogService::LEVEL_INFO : LifecycleLogService::LEVEL_WARN, 'storage', 'boot_archive_' . $mode,
                ['kind' => $kind, 'user' => $user, 'done' => (int)($res['done'] ?? 0), 'bytes' => (int)($res['bytes'] ?? 0), 'ok' => $ok]);
        } catch (\Throwable $e) { /* a log must never break the job */ }
        return $ok ? 0 : 1;
    }

    /** @param array<string,mixed> $data */
    private static function writeJson(string $path, array $data): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return false;
        $tmp = $path . '.tmp.' . getmypid();
        if (@file_put_contents($tmp, json_encode($data)) === false) return false;
        return @rename($tmp, $path);
    }

    /** @return array<string,mixed> */
    private static function readJson(string $path): array
    {
        $raw = @file_get_contents($path);
        $d = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($d) ? $d : [];
    }
}
