<?php
/**
 * <module_context>
 *     <name>BackupCronService</name>
 *     <description>HOME_BACKUP.md R1: owns the plugin's home-backup cron file,
 *       the same way ConfigService owns the agent-check and health-check
 *       crons. Turns each home's OWN `schedule` (HomeBackupSettingsService,
 *       #287; 'off' | 'daily:HH:MM' | 'weekly:D:HH:MM') into one cron line per home, each
 *       calling `admin-agent.php backup-home --user=<user> --scheduled`.</description>
 *     <dependencies>ConfigService, StorageMetricsService, HomeBackupSettingsService, LogService</dependencies>
 *     <constraints>Read-only against ConfigService/StorageMetricsService — this
 *       class never edits those files, only calls their public methods (both are
 *       owned by concurrent HOME_BACKUP.md backend work).</constraints>
 * </module_context>
 *
 * Why a separate file instead of a new branch in ConfigService::saveConfig()
 * (which already does this for version_check_schedule/health_check_schedule):
 * ConfigService.php is owned by the concurrent HOME_BACKUP.md backend change
 * (job/StorageHandler/defaults). This class is called instead from the two
 * places a backup setting can change — AdminService::setSetting() (Tier 2
 * MCP/CLI path) and UtilityHandler::save() (the Manager UI's own settings
 * form) — and from the installer finalize path via sync-backup-cron.php, the
 * same three call sites ConfigService's own cron updaters cover for their
 * schedules.
 */

namespace AICliAgents\Services;

class BackupCronService {
    /**
     * The cron file path: backup-home.cron next to the plugin config, where Unraid's update_cron
     * reads it, like the health-check and agent-check crons (#885). AICLI_BACKUP_CRON_FILE is a
     * test-only override. Production never sets it.
     */
    private static function cronFile(): string {
        $e = getenv('AICLI_BACKUP_CRON_FILE');
        return ($e !== false && $e !== '') ? $e : ConfigService::pluginCronPath('backup-home');
    }

    /** The CLI entry point every cron line calls — never a proposal, per HOME_BACKUP.md's Tier row: `--scheduled` is the operator's own schedule, not an agent action. */
    private const ADMIN_AGENT_SCRIPT = '/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/admin-agent.php';

    /**
     * Turn `backup_schedule` into a 5-field cron expression, or null when the
     * schedule is off/empty/malformed. Never trusts its own regex alone — the
     * result is re-checked by ConfigService::safeCronSchedule() (already
     * public, already proven for the other two plugin-owned crons) before
     * this class treats it as safe to write to a root-owned cron.d file.
     */
    public static function parseScheduleToCron(string $schedule): ?string {
        $schedule = trim($schedule);
        if ($schedule === '' || $schedule === 'off') return null;

        $cron = null;
        if (preg_match('/^daily:([01]\d|2[0-3]):([0-5]\d)$/D', $schedule, $m)) {
            $cron = "{$m[2]} {$m[1]} * * *";
        } elseif (preg_match('/^weekly:([0-6]):([01]\d|2[0-3]):([0-5]\d)$/D', $schedule, $m)) {
            $cron = "{$m[3]} {$m[2]} * * {$m[1]}";
        } else {
            return null;
        }

        return ConfigService::safeCronSchedule($cron) !== '' ? $cron : null;
    }

    /**
     * Every user this cron may back up — the SAME set the Storage tab draws
     * Home cards for (HOME_BACKUP.md R12; each card has its own Backup
     * button since #287), so the cron file never drifts from what the
     * operator sees.
     *
     * @return string[]
     */
    private static function usersWithHome(): array {
        try {
            // R-134: the id list only. getStatus() runs du and df for every home and
            // this runs on every settings save.
            return StorageMetricsService::homeUserIds();
        } catch (\Throwable $e) {
            LogService::log('BackupCronService could not enumerate home users: ' . $e->getMessage(), LogService::LOG_ERROR, 'BackupCronService');
            return [];
        }
    }

    /**
     * Reconcile the home-backup cron file with every home's OWN schedule
     * (HOME_BACKUP.md #287: per-home settings). Idempotent and safe to call on
     * every settings save, plugin install, and finalize — it always rewrites
     * (or removes) the whole file rather than patching it. A home whose
     * schedule is 'off' gets no line; a home with no settings file yet
     * inherits the old global backup_schedule once (HomeBackupSettingsService).
     *
     * @param array<string,mixed>|null $config Kept for the existing callers;
     *                                          the schedule now comes per home.
     */
    public static function sync(?array $config = null): void {
        $users = array_values(array_filter(array_map(
            static fn($u): string => (string)preg_replace('/[^A-Za-z0-9_.-]/', '', (string)$u),
            self::usersWithHome()
        ), static fn(string $u): bool => $u !== ''));

        $lines = self::cronLines($users, static function (string $user): string {
            return (string)(HomeBackupSettingsService::get($user)['schedule'] ?? 'off');
        });

        if (empty($lines)) {
            ConfigService::removePluginCron('backup-home');
            @unlink(self::cronFile());
            LogService::log('Home backup schedule: no home has a schedule — cron not installed.', LogService::LOG_INFO, 'BackupCronService');
            @exec('/usr/local/sbin/update_cron 2>/dev/null');
            return;
        }

        if (!self::writeCronFile($lines, self::cronFile())) {
            LogService::log('Home backup schedule: the cron file could not be written, so the schedule is NOT active.', LogService::LOG_ERROR, 'BackupCronService');
            return;
        }
        @exec('/usr/local/sbin/update_cron 2>/dev/null');
        LogService::log('Home backup cron updated for ' . count($lines) . ' home(s).', LogService::LOG_INFO, 'BackupCronService');
    }

    /**
     * R-134: write the cron file and say whether it really landed (the write used
     * to be ignored, and the log said "updated" after a full disk or a read-only mount).
     *
     * @param string[] $lines
     */
    public static function writeCronFile(array $lines, string $file): bool {
        $content = "# AICliAgents: home backup schedule (one line per home, each with its own schedule)\n" . implode('', $lines);
        $written = @file_put_contents($file, $content);
        ConfigService::removeLegacyCronFile('backup-home'); // the old inert file (#885)
        return $written !== false && $written === strlen($content);
    }

    /**
     * Pure: the cron lines for $users, each from its own schedule. A home
     * whose schedule is off, malformed or unsafe gets no line.
     *
     * @param string[] $users
     * @param callable(string):string $scheduleFor
     * @return string[]
     */
    public static function cronLines(array $users, callable $scheduleFor): array {
        $lines = [];
        foreach ($users as $user) {
            if (!preg_match('/^[A-Za-z0-9_.-]+$/D', $user)) continue;
            try {
                $schedule = trim((string)$scheduleFor($user));
            } catch (\Throwable $e) {
                continue;
            }
            $cronExpr = self::parseScheduleToCron($schedule);
            if ($cronExpr === null) {
                if ($schedule !== '' && $schedule !== 'off') {
                    LogService::log("Refused an unsafe or malformed backup schedule ('$schedule') for $user; no cron line for this home.", LogService::LOG_ERROR, 'BackupCronService');
                }
                continue;
            }
            $job = $user === 'root' ? 'aicli-backup-home' : 'aicli-backup-home-' . $user;
            $lines[] = "$cronExpr " . ConfigService::cronRunCommand($job, '/usr/bin/php ' . self::ADMIN_AGENT_SCRIPT . " backup-home --user=$user --scheduled") . " &> /dev/null\n";
        }
        return $lines;
    }

    /** #891: the longest a scheduled backup run waits for its job: 6 hours, so a very large home can finish. */
    public const WAIT_DEADLINE_SEC = 21600;
    /** #891: seconds between two looks at the job and its result record. */
    public const WAIT_POLL_SEC = 5;

    /**
     * #891: the one plain line that ends the scheduled job's output. A job report page or a phone
     * push shows the last line of output as it is. On a failure it says what failed and what to do.
     */
    public static function plainResultLine(array $result, string $user): string {
        $msg = trim((string)preg_replace('/\s+/', ' ', (string)($result['message'] ?? '')));
        if (($result['status'] ?? '') === 'ok') {
            return $msg !== '' ? $msg : "Home backup of $user finished.";
        }
        $why = $msg !== '' ? rtrim($msg, '.') : 'unknown error';
        return "Home backup of $user FAILED: $why. What to do: read the plugin Debug Console, fix the cause, then run the backup again by hand.";
    }

    /**
     * #891: wait for the queued backup job of $user to end, and answer its real result.
     * The result is the last-run record (backup-last-<user>.json) of THIS job: its
     * data.job_id equals $jobId. A record with no job id counts only when it is newer
     * than the job's queue time. A job that ended with no record is an error. At the
     * deadline the answer is an error that says the job is still running.
     * No lock: it reads files, sleeps, and reads again.
     *
     * @param (callable(int):void)|null $sleep Injected in tests; default sleep().
     * @param (callable():int)|null $clock Injected in tests; default time().
     * @return array{status:string,message:string}
     */
    public static function awaitResult(string $user, string $jobId, int $deadlineSec = self::WAIT_DEADLINE_SEC, int $pollSec = self::WAIT_POLL_SEC, ?callable $sleep = null, ?callable $clock = null): array {
        require_once __DIR__ . '/SupervisorService.php';
        $sleep = $sleep ?? static function (int $s): void { sleep($s); };
        $clock = $clock ?? static fn(): int => time();
        $safeUser = (string)preg_replace('/[^A-Za-z0-9._-]/', '', $user);
        $dir = getenv('AICLI_BACKUP_LAST_DIR') ?: SecretPaths::flashRoot();
        $path = rtrim((string)$dir, '/') . "/backup-last-$safeUser.json";
        $end = $clock() + max(0, $deadlineSec);
        while (true) {
            $job = SupervisorService::getJob($jobId);
            $ended = is_array($job) && !SupervisorService::isJobActive($job);
            $rec = is_file($path) ? json_decode((string)@file_get_contents($path), true) : null;
            if (is_array($rec)) {
                $data = is_array($rec['data'] ?? null) ? $rec['data'] : [];
                $recJob = (string)($data['job_id'] ?? '');
                $at = (int)strtotime((string)($rec['at'] ?? ''));
                $mine = $recJob === $jobId
                    || ($recJob === '' && $ended && $at >= (int)($job['queued_at'] ?? 0));
                if ($mine) {
                    $summary = trim((string)($rec['summary'] ?? ''));
                    if (!empty($rec['ok'])) {
                        return ['status' => 'ok', 'message' => $summary !== '' ? $summary : "home backup of $user finished"];
                    }
                    return ['status' => 'error', 'message' => $summary !== '' ? $summary : "home backup of $user failed"];
                }
            }
            if ($ended) {
                $code = (int)($job['exit'] ?? 0);
                return ['status' => 'error', 'message' => "The backup job of $user ended (state " . (string)($job['state'] ?? '?') . ", exit code $code) and left no result record."];
            }
            if ($clock() >= $end) {
                return ['status' => 'error', 'message' => "Timed out after {$deadlineSec} s: the backup job $jobId of $user has not ended."];
            }
            $sleep(max(1, $pollSec));
        }
    }
}
