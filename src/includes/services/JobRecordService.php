<?php
/**
 * <module_context>
 *     <name>JobRecordService</name>
 *     <description>CRON_JOB_REPORTING.md R9-R12: the PHP side of the job run record. cron-run.sh writes one record for each run of a scheduled job. This class gives a job two things. It turns a cron schedule into the interval that the record carries. It lets a job refine its own record (status, one summary line, short details) through the file named by the environment variable AICLI_JOB_RESULT.</description>
 *     <dependencies>None.</dependencies>
 *     <constraints>writeResult() writes nothing when AICLI_JOB_RESULT is not set, so a job run by hand is unchanged. It never throws and never changes the exit code of a job. The result file is small JSON: {"status","summary","details"}. The runner caps the values again; the caps here keep the file small.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class JobRecordService {
    /** The four status words a job may set. */
    public const STATUSES = ['ok', 'warn', 'fail', 'skipped'];

    /** Folder of the run records. AICLI_JOBS_DIR redirects it (tests). */
    public static function jobsDir(): string {
        $e = getenv('AICLI_JOBS_DIR');
        return ($e !== false && $e !== '') ? rtrim($e, '/') : SecretPaths::stateRoot() . '/jobs';
    }

    /** File name stem of a job's record. The same rule as cron-run.sh. Pure. */
    public static function recordName(string $job): string {
        $name = ltrim((string)preg_replace('/[^A-Za-z0-9._-]/', '_', $job), '.');
        return $name !== '' ? $name : 'job';
    }

    /**
     * Pure: how many seconds pass between two runs of a cron schedule, or null when it is not
     * simple. Five fields. A minute field of "star slash N" with every other field a star is N minutes. `M * * * *` is hourly. `M` with an hour of "star slash N" is N hours. `M H * * *` is
     * daily. `M H * * D` is weekly.
     */
    public static function intervalFromCron(string $schedule): ?int {
        $f = preg_split('/\s+/', trim($schedule));
        if (!is_array($f) || count($f) !== 5) return null;
        [$min, $hour, $dom, $mon, $dow] = $f;
        if ($dom !== '*' || $mon !== '*') return null;
        if (preg_match('#^\*/([0-9]{1,2})$#D', $min, $m) && $hour === '*' && $dow === '*') {
            $n = (int)$m[1];
            return ($n >= 1 && $n <= 59) ? $n * 60 : null;
        }
        if (!preg_match('/^[0-9]{1,2}$/D', $min) || (int)$min > 59) return null;
        if ($hour === '*' && $dow === '*') return 3600;
        if (preg_match('#^\*/([0-9]{1,2})$#D', $hour, $hm) && $dow === '*') {
            $n = (int)$hm[1];
            return ($n >= 1 && $n <= 23) ? $n * 3600 : null;
        }
        if (!preg_match('/^[0-9]{1,2}$/D', $hour) || (int)$hour > 23) return null;
        if ($dow === '*') return 86400;
        if (preg_match('/^[0-7]$/D', $dow)) return 604800;
        return null;
    }

    /**
     * Refine the record of the job that runs now. Returns true when a result file was written.
     * Does nothing when the job runs without cron-run.sh.
     *
     * @param string[] $details
     */
    public static function writeResult(string $status, string $summary, array $details = []): bool {
        try {
            $path = getenv('AICLI_JOB_RESULT');
            if ($path === false || $path === '') return false;
            if (!in_array($status, self::STATUSES, true)) $status = 'fail';
            $clean = static function (string $s): string {
                $s = (string)preg_replace('/[\x00-\x1f\x7f]+/', ' ', $s);
                return trim(mb_substr($s, 0, 200));
            };
            $list = [];
            foreach ($details as $d) {
                if (count($list) >= 10) break;
                $d = $clean((string)$d);
                if ($d !== '') $list[] = $d;
            }
            $payload = ['status' => $status, 'summary' => $clean($summary)];
            if ($list !== []) $payload['details'] = $list;
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($json === false) return false;
            return @file_put_contents($path, $json) !== false;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
