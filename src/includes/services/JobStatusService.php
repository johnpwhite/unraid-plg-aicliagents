<?php
/**
 * <module_context>
 *     <name>JobStatusService</name>
 *     <description>CRON_JOB_REPORTING.md R13-R15: the read-only view of the scheduled jobs. It joins the cron files of the plugin (which jobs run, how often) with the run records that cron-run.sh writes (what the last run did). It answers one state for each job: ok, warn, fail, skipped, late, missing, pending or unscheduled. Another tool can use the result to check the jobs. The plugin does not depend on that tool.</description>
 *     <dependencies>SecretPaths, JobRecordService</dependencies>
 *     <constraints>Read-only. It never throws: a corrupt record gives the state "fail" with an error note. The output is a stable interface, versioned by "schema". The records live in RAM (/tmp), so after a reboot a job has no record until its first run: the state is "pending" until twice its interval has passed since boot, then "missing" (the wait counts from the later of the boot and the last change of the cron file). A record of a job that is no longer scheduled has the state "unscheduled". AICLI_FLASH_ROOT, AICLI_JOBS_DIR and AICLI_UPTIME_S redirect the inputs in tests.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class JobStatusService {
    public const SCHEMA = 1;
    /** The interval used for the missing/pending decision when a job has no known interval. */
    private const DEFAULT_INTERVAL_S = 86400;

    /** Seconds since boot. AICLI_UPTIME_S overrides it (tests). */
    public static function uptimeSeconds(): int {
        $e = getenv('AICLI_UPTIME_S');
        if ($e !== false && $e !== '' && ctype_digit($e)) return (int)$e;
        $raw = @file_get_contents('/proc/uptime');
        return $raw === false ? 0 : (int)floor((float)strtok($raw, ' '));
    }

    /**
     * Pure: the jobs that cron text schedules, as job => interval seconds or null.
     * A job is a line that holds "cron-run.sh <job> ...".
     *
     * @return array<string,int|null>
     */
    public static function parseCronText(string $text): array {
        $jobs = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            if (!preg_match('/cron-run\.sh\s+([A-Za-z0-9._-]+)((?:\s+--[^\s]+)*)\s+--(?:\s|$)/', $line, $m)) continue;
            $interval = null;
            if (preg_match('/--interval=([0-9]+)/', $m[2], $im)) {
                $interval = (int)$im[1];
                if ($interval <= 0) $interval = null;
            }
            $jobs[$m[1]] = $interval;
        }
        return $jobs;
    }

    /** @return array<string,int|null> every scheduled job of the plugin cron files. */
    public static function scheduledJobs(): array {
        return array_map(static fn(array $j): ?int => $j[0], self::scheduledJobsWithTime());
    }

    /**
     * Every scheduled job with its interval and the change time of the cron file that schedules it.
     *
     * @return array<string,array{0:?int,1:int}>
     */
    public static function scheduledJobsWithTime(): array {
        $jobs = [];
        $files = glob(SecretPaths::flashRoot() . '/*.cron') ?: [];
        sort($files);
        foreach ($files as $file) {
            $text = @file_get_contents($file);
            if ($text === false) continue;
            $mtime = (int)@filemtime($file);
            foreach (self::parseCronText($text) as $job => $iv) {
                if (!isset($jobs[$job])) $jobs[$job] = [$iv, $mtime];
            }
        }
        return $jobs;
    }

    /**
     * Read one record file. Returns [record array|null, error string]. A missing file is [null, ''].
     *
     * @return array{0:?array,1:string}
     */
    private static function readRecord(string $file): array {
        if (!is_file($file)) return [null, ''];
        $raw = @file_get_contents($file);
        if ($raw === false) return [null, 'record not readable'];
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['status'])) return [null, 'record is corrupt'];
        return [$data, ''];
    }

    /** The state of a record that was read. Pure. */
    private static function recordState(array $rec): string {
        $status = (string)($rec['status'] ?? '');
        if (!in_array($status, JobRecordService::STATUSES, true)) return 'fail';
        if ((int)($rec['rc'] ?? 0) !== 0) return 'fail';
        return $status;
    }

    /**
     * The report for every scheduled job, and for every record whose job is not scheduled now.
     * State "late" applies to a record with a state other than "fail": the last run ended more
     * than twice the interval ago. A failed last run stays "fail".
     *
     * @return array<string,mixed>
     */
    public static function report(?int $now = null, ?int $uptime = null): array {
        $now = $now ?? time();
        $uptime = $uptime ?? self::uptimeSeconds();
        $dir = JobRecordService::jobsDir();
        $jobs = [];
        $seen = [];
        foreach (self::scheduledJobsWithTime() as $job => [$interval, $cronTime]) {
            $file = $dir . '/' . JobRecordService::recordName($job) . '.json';
            $seen[$file] = true;
            [$rec, $err] = self::readRecord($file);
            $entry = ['job' => $job, 'interval_s' => $interval, 'scheduled' => true, 'record' => $rec];
            if ($err !== '') {
                $entry['record'] = ['error' => $err];
                $entry['state'] = 'fail';
            } elseif ($rec === null) {
                // The wait starts at the later of the boot and the last change of the cron file,
                // so a job that was just installed or changed is pending, not missing.
                $limit = 2 * ($interval ?? self::DEFAULT_INTERVAL_S);
                $since = $cronTime > 0 ? min($uptime, max(0, $now - $cronTime)) : $uptime;
                $entry['state'] = $since > $limit ? 'missing' : 'pending';
            } else {
                $state = self::recordState($rec);
                $ended = strtotime((string)($rec['ended_at'] ?? ''));
                if ($state !== 'fail' && $interval !== null && $ended !== false && $now > $ended + 2 * $interval) {
                    $state = 'late';
                }
                $entry['state'] = $state;
            }
            $jobs[] = $entry;
        }
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            if (isset($seen[$file])) continue;
            [$rec, $err] = self::readRecord($file);
            $name = (string)($rec['job'] ?? basename($file, '.json'));
            $ri = isset($rec['interval_s']) && is_int($rec['interval_s']) ? $rec['interval_s'] : null;
            $entry = ['job' => $name, 'interval_s' => $ri, 'scheduled' => false, 'record' => $rec];
            if ($err !== '') $entry['record'] = ['error' => $err];
            $entry['state'] = 'unscheduled';
            $jobs[] = $entry;
        }
        $host = @gethostname();
        return [
            'schema'   => self::SCHEMA,
            'host'     => is_string($host) && $host !== '' ? explode('.', $host)[0] : 'unknown',
            'now'      => $now,
            'uptime_s' => $uptime,
            'jobs'     => $jobs,
        ];
    }

    /**
     * One plain line for each job. Pure.
     *
     * @param array<string,mixed> $report
     * @return string[]
     */
    public static function plainLines(array $report): array {
        $lines = [];
        foreach (($report['jobs'] ?? []) as $j) {
            $rec = is_array($j['record'] ?? null) ? $j['record'] : [];
            $tail = (string)($rec['error'] ?? $rec['summary'] ?? '');
            $lines[] = trim(sprintf(
                '%s %s%s%s%s %s',
                $j['job'],
                strtoupper((string)$j['state']),
                $j['interval_s'] !== null ? ' every ' . $j['interval_s'] . 's' : '',
                isset($rec['ended_at']) ? ', last run ' . $rec['ended_at'] : '',
                empty($j['scheduled']) ? ', not scheduled now' : '',
                $tail !== '' ? '- ' . $tail : ''
            ));
        }
        return $lines;
    }
}
