<?php
/**
 * <module_context>
 *     <name>StorageJobProgressService</name>
 *     <description>What a running save or merge of a home does now: the status line, the
 *     progress value, the byte count and the estimate from the last run (issue #443).</description>
 *     <dependencies>SecretPaths, SupervisorService</dependencies>
 *     <constraints>The text builders are pure. Only forJob, previousRunSec and inputBytes read files.</constraints>
 * </module_context>
 *
 * Spec: docs/specs/UPGRADE_PROGRESS_PHASES.md (sections "Persist and Merge" and "Estimate and bytes").
 */

namespace AICliAgents\Services;

require_once __DIR__ . '/SecretPaths.php';

class StorageJobProgressService {
    /** A run shorter than this many seconds is not kept as history: it gives no useful estimate. */
    public const MIN_HISTORY_SEC = 15;

    /** An estimate longer than this many seconds is not shown (a day: it is a wrong figure). */
    private const MAX_LEFT_SEC = 86400;

    /**
     * "120 MB of 340 MB". Empty when the total is not known or is not above zero.
     * The done figure is the share of the total that the percentage reports.
     */
    public static function bytesText(?int $totalBytes, ?int $pct): string {
        if ($totalBytes === null || $totalBytes <= 0 || $pct === null) {
            return '';
        }
        $pct = max(0, min(100, $pct));
        $done = (int)round($totalBytes * $pct / 100);
        return self::sizeText($done) . ' of ' . self::sizeText($totalBytes);
    }

    /** A size in MB, or in GB with one decimal from 1 GB. */
    public static function sizeText(int $bytes): string {
        $bytes = max(0, $bytes);
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 1, '.', '') . ' GB';
        }
        return (int)round($bytes / 1048576) . ' MB';
    }

    /**
     * The time that is left, in seconds, from the last run of the same job.
     *
     * The last duration is scaled by the percentage that is left. A run that is slower than
     * the last one (the time so far is more than 25 % above the share of the last duration
     * that the percentage stands for) uses its own speed instead, so the figure does not
     * stay too small. Null means "show nothing": no history, no percentage, 100 %, or a
     * figure that is zero, negative or longer than a day.
     *
     * @param ?int $elapsedSec seconds so far, or null when they do not match the percentage
     *                         (a merge: the time of the whole job covers more than the write)
     */
    public static function estimateLeftSec(?int $pct, ?int $elapsedSec, ?int $previousSec): ?int {
        if ($previousSec === null || $previousSec <= 0 || $pct === null) {
            return null;
        }
        $pct = max(0, min(100, $pct));
        if ($pct >= 100) {
            return null;
        }
        $left = $previousSec * (100 - $pct) / 100;
        if ($elapsedSec !== null && $elapsedSec > 0 && $pct >= 5) {
            $expectedSoFar = $previousSec * $pct / 100;
            if ($elapsedSec > $expectedSoFar * 1.25) {
                $left = $elapsedSec * (100 - $pct) / $pct;
            }
        }
        if ($left <= 0 || $left > self::MAX_LEFT_SEC) {
            return null;
        }
        return (int)ceil($left);
    }

    /** "about 4 min left", "about 2 h left" or "less than 1 min left". */
    public static function leftText(int $leftSec): string {
        $leftSec = max(1, $leftSec);
        if ($leftSec < 60) {
            return 'less than 1 min left';
        }
        $min = (int)ceil($leftSec / 60);
        if ($min >= 120) {
            return 'about ' . (int)round($min / 60) . ' h left';
        }
        return "about $min min left";
    }

    /** "45 s" or "3 min". */
    public static function elapsedText(int $elapsedSec): string {
        $elapsedSec = max(0, $elapsedSec);
        return $elapsedSec < 60 ? "$elapsedSec s" : intdiv($elapsedSec, 60) . ' min';
    }

    /**
     * The line for a save that writes its layer. Shared by the upgrade card, the Activity
     * tray and the home card.
     *   "Saving workspace data (home root), 42 % done (120 MB of 340 MB), 3 min so far, about 4 min left..."
     * With no percentage the line has the time only. $stalledSec of 120 or more adds the slow note.
     *
     * @return array{text:string,progress:int}  progress is 6 to 9 (the upgrade bar)
     */
    public static function saveRunningStatus(string $user, ?int $pct, int $elapsedSec, int $stalledSec = 0, ?int $totalBytes = null, ?int $previousSec = null): array {
        $home = 'Saving workspace data (home ' . $user . ')';
        $time = self::elapsedText($elapsedSec);
        if ($pct === null) {
            return ['text' => $home . ", $time so far...", 'progress' => 6];
        }
        $pct = max(0, min(100, $pct));
        $bytes = self::bytesText($totalBytes, $pct);
        $left = self::estimateLeftSec($pct, $elapsedSec, $previousSec);
        $text = $home . ", $pct % done" . ($bytes !== '' ? " ($bytes)" : '') . ", $time so far"
            . ($left !== null ? ', ' . self::leftText($left) : '');
        $text .= $stalledSec >= 120 ? '. The save is slow. It is still working.' : '...';
        return ['text' => $text, 'progress' => 6 + (int)floor($pct * 3 / 100)];
    }

    /** The part of the home card badge after the verb: "120 MB of 340 MB · about 4 min left". */
    public static function detailText(?int $pct, ?int $totalBytes, ?int $leftSec): string {
        $parts = [];
        $bytes = self::bytesText($totalBytes, $pct);
        if ($bytes !== '') $parts[] = $bytes;
        if ($leftSec !== null) $parts[] = self::leftText($leftSec);
        return implode(' · ', $parts);
    }

    // ---- files --------------------------------------------------------------

    /** The folder of the run history. It sits on the flash drive, so a restart keeps it. */
    public static function historyDir(): string {
        return SecretPaths::flashRoot() . '/run-history';
    }

    /**
     * The file of one kind of run. KEEP IN SYNC with _run_history_file in aicli-supervisor.sh.
     * Content: one line, "<duration_s> <bytes or 0> <finished_at>".
     */
    public static function historyPath(string $op, string $entity): string {
        $safeOp = preg_replace('/[^a-z]/', '', $op);
        $safeEntity = preg_replace('/[^A-Za-z0-9._-]/', '_', $entity);
        return self::historyDir() . '/' . $safeOp . '_' . $safeEntity;
    }

    /** Seconds the last completed run of $op for $entity (for example "home/root") took, or null. */
    public static function previousRunSec(string $op, string $entity): ?int {
        $raw = @file_get_contents(self::historyPath($op, $entity));
        if (!is_string($raw) || preg_match('/^(\d{1,9}) (\d{1,15}) (\d{1,12})\s*$/D', $raw, $m) !== 1) {
            return null;
        }
        $sec = (int)$m[1];
        return $sec > 0 ? $sec : null;
    }

    /**
     * The file in which the supervisor keeps the size of the input of the running save.
     * KEEP IN SYNC with _op_bake in aicli-supervisor.sh.
     */
    public static function inputBytesPath(string $type, string $id): string {
        $safeType = preg_replace('/[^a-z]/', '', $type);
        $safeId   = preg_replace('/[^A-Za-z0-9._-]/', '_', $id);
        return SecretPaths::stateRoot() . '/bake-bytes-' . $safeType . '-' . $safeId;
    }

    /** The bytes the running save packs, or null when they were not measured (the measure is optional). */
    public static function inputBytes(string $type, string $id): ?int {
        $raw = trim((string)@file_get_contents(self::inputBytesPath($type, $id)));
        if ($raw === '' || preg_match('/^\d{1,15}$/D', $raw) !== 1) {
            return null;
        }
        $n = (int)$raw;
        return $n > 0 ? $n : null;
    }

    /**
     * The `job` field of a home on the storage snapshot (SupervisorService::activeJobFor),
     * with a `progress` field added while its save or merge runs. The card reads that field.
     * Any other job, and no job, come back as they are.
     *
     * @param ?array<string,mixed>            $card the field from activeJobFor
     * @param array<int,array<string,mixed>>  $jobs the supervisor jobs (for the phase of a merge)
     * @return ?array<string,mixed>
     */
    public static function withProgress(?array $card, string $entity, array $jobs, ?int $now = null): ?array {
        if ($card === null) {
            return null;
        }
        $raw = $card;
        foreach ($jobs as $j) {
            if ((string)($j['job_id'] ?? '') === (string)($card['jobId'] ?? '')) {
                $raw = $j + $card;
                break;
            }
        }
        $p = self::forJob($raw, $entity, $now);
        if ($p !== null) {
            $card['progress'] = [
                'pct'         => $p['pct'],
                'elapsed_s'   => $p['elapsed_s'],
                'bytes_total' => $p['bytes_total'],
                'left_s'      => $p['left_s'],
                'text'        => $p['text'],
                'detail'      => $p['detail'],
            ];
        }
        return $card;
    }

    /**
     * What the running save or merge of a home does now. Null for any other job.
     *
     * @param array<string,mixed> $job  the job (op, state, started_at)
     * @param string              $entity "home/root"
     * @return array{pct:?int,elapsed_s:int,bytes_total:?int,left_s:?int,text:string,detail:string,progress:int}|null
     */
    public static function forJob(array $job, string $entity, ?int $now = null): ?array {
        $op = (string)($job['op'] ?? '');
        if (($job['state'] ?? '') !== 'running' || ($op !== 'bake' && $op !== 'consolidate') || strpos($entity, 'home/') !== 0) {
            return null;
        }
        $id = substr($entity, 5);
        $now = $now ?? time();
        $since = (int)($job['started_at'] ?? 0);
        $elapsed = $since > 0 ? max(0, $now - $since) : 0;
        $pct = SupervisorService::bakePercent('home', $id);
        $prev = self::previousRunSec($op, $entity);
        if ($op === 'bake') {
            $bytes = self::inputBytes('home', $id);
            $st = self::saveRunningStatus($id, $pct, $elapsed, 0, $bytes, $prev);
            $left = self::estimateLeftSec($pct, $elapsed, $prev);
            // The tray bar for a save: 10 at the start, 90 at 100 %.
            $progress = 10 + ($pct === null ? 0 : (int)floor($pct * 80 / 100));
            return ['pct' => $pct, 'elapsed_s' => $elapsed, 'bytes_total' => $bytes, 'left_s' => $left,
                    'text' => $st['text'], 'detail' => self::detailText($pct, $bytes, $left), 'progress' => $progress];
        }
        // A merge: the percent is the write of the new layer. The time of the whole job does
        // not match it, so the estimate uses the last duration only.
        $phase = (string)($job['phase'] ?? '');
        if ($pct === null) {
            $pct = SupervisorService::consolidateWritePercent($entity);
        }
        // Only the write phase has a percentage; the other phases show no estimate.
        $left = $phase === 'writing' ? self::estimateLeftSec($pct, null, $prev) : null;
        return ['pct' => $pct, 'elapsed_s' => $elapsed, 'bytes_total' => null, 'left_s' => $left,
                'text' => SupervisorService::consolidatePhaseStep($phase, $pct, $left),
                'detail' => self::detailText($pct, null, $left),
                'progress' => SupervisorService::consolidatePhaseProgress($phase, $pct)];
    }
}
