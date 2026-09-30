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
    /** One file, like the health-check and agent-check crons. */
    private const CRON_FILE = '/etc/cron.d/unraid-aicliagents.backup-home';

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
        if (preg_match('/^daily:([01]\d|2[0-3]):([0-5]\d)$/', $schedule, $m)) {
            $cron = "{$m[2]} {$m[1]} * * *";
        } elseif (preg_match('/^weekly:([0-6]):([01]\d|2[0-3]):([0-5]\d)$/', $schedule, $m)) {
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
            $status = StorageMetricsService::getStatus();
            $homes = is_array($status['homes'] ?? null) ? $status['homes'] : [];
            return array_keys($homes);
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
            @unlink(self::CRON_FILE);
            LogService::log('Home backup schedule: no home has a schedule — cron not installed.', LogService::LOG_INFO, 'BackupCronService');
            @exec('/usr/local/sbin/update_cron 2>/dev/null');
            return;
        }

        $content = "# AICliAgents: home backup schedule (one line per home, each with its own schedule)\n" . implode('', $lines);
        @file_put_contents(self::CRON_FILE, $content);
        @exec('/usr/local/sbin/update_cron 2>/dev/null');
        LogService::log('Home backup cron updated for ' . count($lines) . ' home(s).', LogService::LOG_INFO, 'BackupCronService');
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
            if (!preg_match('/^[A-Za-z0-9_.-]+$/', $user)) continue;
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
            $lines[] = "$cronExpr /usr/bin/php " . self::ADMIN_AGENT_SCRIPT . " backup-home --user=$user --scheduled &> /dev/null\n";
        }
        return $lines;
    }
}
