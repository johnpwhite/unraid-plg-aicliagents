<?php
/**
 * <module_context>
 *     <name>HealthService</name>
 *     <description>Proactive health surface (R-09/R-14, Feature #1372). Runs nine CHEAP checks
 *     (supervisor heartbeat, manifest sanity, mount/manifest drift, defer-marker age, /boot + /tmp
 *     space, boot-integrity cached sweep, debug.log rotation bound, client-error budget, storage
 *     job ledger), each returning ok|warn|fail + message. Overall = worst-of. Result cached at
 *     /tmp/unraid-aicliagents/health.json (TTL 60s — recompute only when stale). Degradation
 *     notify is deduped via a fingerprint file, cleared on recovery (healthcheck.php cron).</description>
 *     <dependencies>ConfigService, SupervisorService, BootIntegrityService, LayerManifestService, StoragePathResolver, StorageMountService, AtomicWriteService</dependencies>
 *     <constraints>Checks reuse cached sweeps/ledgers/tick files — no heavy scans (the 60s cache is
 *     the budget guard). compute() never throws (per-check fault isolation -> warn). Pure eval*
 *     methods carry the decision logic so PHPUnit needs no real daemon/mounts. Test hooks:
 *     AICLI_RUNTIME_DIR (cache + notify fingerprint + defer markers), AICLI_HEALTH_TTL_S,
 *     AICLI_NOTIFY_SCRIPT, plus the existing AICLI_DEBUG_LOG_DIR / AICLI_DEBUG_LOG_MAX_BYTES /
 *     AICLI_MANIFEST_PATH / AICLI_JOBS_DIR hooks. See docs/specs/HEALTH_MONITORING.md.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class HealthService {

    public const SCHEMA_VERSION = 1;

    public const STATUS_OK   = 'ok';
    public const STATUS_WARN = 'warn';
    public const STATUS_FAIL = 'fail';

    /** Cache TTL (seconds) — the cost budget guard for every consumer. */
    private const CACHE_TTL_S = 60;
    /** Defer markers older than this are a warn (R-09 table: > 6h). */
    private const DEFER_WARN_AGE_S = 21600;
    /** Client-error budget (R-14): warn above this many entries in the last hour. */
    private const CLIENT_ERROR_BUDGET_PER_H = 10;
    /** debug.log tail window scanned for client errors (bytes — bounded read). */
    private const CLIENT_ERROR_TAIL_BYTES = 262144;
    private const NOTIFY_SCRIPT = '/usr/local/emhttp/plugins/dynamix/scripts/notify';

    // ==================================================================
    // Public surface
    // ==================================================================

    /**
     * Cached health result; recomputes only when the cache is stale (TTL 60s)
     * or $forceRefresh. Never throws.
     *
     * @return array{schema:int,overall:string,checks:array<string,array{status:string,message:string}>,generated_at:string,generated_at_epoch:int}
     */
    public static function get(bool $forceRefresh = false): array {
        $cachePath = self::cachePath();
        if (!$forceRefresh && is_file($cachePath)) {
            $age = time() - (int)(@filemtime($cachePath) ?: 0);
            if ($age >= 0 && $age < self::ttl()) {
                $cached = json_decode((string)@file_get_contents($cachePath), true);
                if (is_array($cached) && isset($cached['overall'], $cached['checks'])) {
                    return $cached;
                }
            }
        }
        $result = self::compute();
        AtomicWriteService::writeJson($cachePath, $result);
        return $result;
    }

    /**
     * Run all checks now (no cache read). Each collector is fault-isolated:
     * a throwing collector yields a warn entry, never an exception.
     */
    public static function compute(): array {
        $collectors = [
            'supervisor'     => function () { return self::collectSupervisor(); },
            'manifest'       => function () { return self::collectManifest(); },
            'mounts'         => function () { return self::collectMounts(); },
            'defer_markers'  => function () { return self::collectDeferMarkers(); },
            'disk_space'     => function () { return self::collectDiskSpace(); },
            'boot_integrity' => function () { return self::evalBootIntegrity(BootIntegrityService::readCachedSweep()); },
            'debug_log'      => function () { return self::collectDebugLog(); },
            'client_errors'  => function () { return self::collectClientErrors(); },
            'storage_jobs'   => function () { return self::collectStorageJobs(); },
        ];
        $checks = [];
        foreach ($collectors as $name => $fn) {
            try {
                $checks[$name] = $fn();
            } catch (\Throwable $e) {
                $checks[$name] = self::result(self::STATUS_WARN, 'check errored: ' . $e->getMessage());
            }
        }
        return [
            'schema'             => self::SCHEMA_VERSION,
            'overall'            => self::aggregate($checks),
            'checks'             => $checks,
            'generated_at'       => gmdate('Y-m-d\TH:i:s\Z'),
            'generated_at_epoch' => time(),
        ];
    }

    /** Worst-of aggregation: any fail -> fail, else any warn -> warn, else ok. Pure. */
    public static function aggregate(array $checks): string {
        $overall = self::STATUS_OK;
        foreach ($checks as $c) {
            $s = is_array($c) ? ($c['status'] ?? self::STATUS_WARN) : self::STATUS_WARN;
            if (self::rank($s) > self::rank($overall)) {
                $overall = $s;
            }
        }
        return $overall;
    }

    /**
     * Cron entrypoint body (healthcheck.php): force-refresh the cache, then
     * fire ONE deduped Unraid notify per distinct degradation; recovery clears
     * the fingerprint so the next degradation notifies again.
     */
    public static function checkAndNotify(): array {
        $result = self::get(true);
        self::maybeNotify($result);
        return $result;
    }

    /**
     * Dedup seam (unit-testable with crafted results): notify only when the
     * degradation fingerprint differs from the recorded one; clear on recovery.
     * Returns true if a notification was fired.
     */
    public static function maybeNotify(array $result): bool {
        $path = self::notifiedPath();
        $overall = (string)($result['overall'] ?? self::STATUS_OK);
        if ($overall === self::STATUS_OK) {
            if (is_file($path)) {
                @unlink($path); // recovery: re-arm the notifier
            }
            return false;
        }
        $fingerprint = self::fingerprint($result);
        $existing = is_file($path) ? trim((string)@file_get_contents($path)) : null;
        if ($existing === $fingerprint) {
            return false; // this exact degradation was already notified
        }
        $bad = self::nonOkSummaries($result);
        $severity = ($overall === self::STATUS_FAIL) ? 'alert' : 'warning';
        self::notify(
            'AI CLI Agents plugin health: ' . strtoupper($overall),
            ($bad === [] ? 'degraded' : implode('; ', $bad)) . ' — see the plugin Debug Console.',
            $severity
        );
        AtomicWriteService::write($path, $fingerprint);
        return true;
    }

    /** Stable id of a degradation: overall + sorted non-ok check names/statuses. Pure. */
    public static function fingerprint(array $result): string {
        $parts = [];
        foreach (($result['checks'] ?? []) as $name => $c) {
            $s = is_array($c) ? ($c['status'] ?? '') : '';
            if ($s !== self::STATUS_OK) {
                $parts[] = "$name=$s";
            }
        }
        sort($parts);
        return md5(($result['overall'] ?? '') . '|' . implode(',', $parts));
    }

    // ==================================================================
    // Pure evaluators (decision logic — unit-tested without real state)
    // ==================================================================

    /** Supervisor: pidfile-verified process alive + heartbeat tick < 3× tick interval. */
    public static function evalSupervisor(bool $enabled, bool $running, ?int $tickAge, int $tickSeconds): array {
        if (!$enabled) {
            return self::result(self::STATUS_OK, 'supervisor disabled by config');
        }
        if (!$running) {
            return self::result(self::STATUS_FAIL, 'supervisor not running (pidfile dead/stale)');
        }
        $budget = 3 * max(1, $tickSeconds);
        if ($tickAge === null) {
            return self::result(self::STATUS_FAIL, 'supervisor running but no heartbeat tick file');
        }
        if ($tickAge > $budget) {
            return self::result(self::STATUS_FAIL, "supervisor heartbeat stale ({$tickAge}s > {$budget}s)");
        }
        return self::result(self::STATUS_OK, "running, tick {$tickAge}s ago");
    }

    /**
     * Manifest: parses, schema sane, and no entity classified total_loss /
     * legacy_unmanaged by the cached boot-integrity sweep.
     *
     * @param array<string,string> $sweepStates entity => state (cached sweep)
     */
    public static function evalManifest(bool $exists, ?array $decoded, array $sweepStates): array {
        if (!$exists) {
            return self::result(self::STATUS_OK, 'no manifest yet (no baked entities)');
        }
        if (!is_array($decoded)) {
            return self::result(self::STATUS_FAIL, 'manifest exists but does not parse');
        }
        $schema = $decoded['schema_version'] ?? null;
        if (!is_int($schema) && !ctype_digit((string)$schema)) {
            return self::result(self::STATUS_FAIL, 'manifest schema_version missing/non-numeric');
        }
        if ((int)$schema > LayerManifestService::SCHEMA_VERSION) {
            return self::result(self::STATUS_WARN, "manifest schema v$schema newer than supported v" . LayerManifestService::SCHEMA_VERSION);
        }
        if (!is_array($decoded['entities'] ?? null)) {
            return self::result(self::STATUS_FAIL, 'manifest entities map missing');
        }
        $bad = [];
        foreach ($sweepStates as $entity => $state) {
            if (in_array($state, [BootIntegrityService::STATE_TOTAL_LOSS, BootIntegrityService::STATE_LEGACY_UNMANAGED], true)) {
                $bad[] = "$entity=$state";
            }
        }
        if ($bad !== []) {
            return self::result(self::STATUS_FAIL, 'entities need recovery: ' . implode(', ', $bad));
        }
        return self::result(self::STATUS_OK, count($decoded['entities']) . ' entities tracked');
    }

    /**
     * Mounts: every ACTIVE (mounted) entity that has persisted layers on disk
     * must be manifest-tracked — a layered-but-untracked mount is drift.
     *
     * @param array<string,bool> $mounted   entity => has persisted layer files
     * @param string[]           $manifestEntities manifest entity keys
     */
    public static function evalMounts(array $mounted, array $manifestEntities): array {
        if ($mounted === []) {
            return self::result(self::STATUS_OK, 'no active entity mounts');
        }
        $drift = [];
        foreach ($mounted as $entity => $hasLayers) {
            if ($hasLayers && !in_array($entity, $manifestEntities, true)) {
                $drift[] = $entity;
            }
        }
        if ($drift !== []) {
            return self::result(self::STATUS_FAIL, 'mounted with layers but not in manifest: ' . implode(', ', $drift));
        }
        return self::result(self::STATUS_OK, count($mounted) . ' active mounts consistent with manifest');
    }

    /** Defer markers: any marker older than 6h -> warn (S-03 TTL reaping should have cleared it). @param array<string,int> $ages name => age seconds */
    public static function evalDeferMarkers(array $ages): array {
        $old = [];
        foreach ($ages as $name => $age) {
            if ($age >= self::DEFER_WARN_AGE_S) {
                $old[] = $name . ' (' . round($age / 3600, 1) . 'h)';
            }
        }
        if ($old !== []) {
            return self::result(self::STATUS_WARN, 'stale defer markers: ' . implode(', ', $old));
        }
        return self::result(self::STATUS_OK, $ages === [] ? 'no defer markers' : count($ages) . ' fresh marker(s)');
    }

    /** /boot free <10% warn, <5% fail; /tmp tmpfs >80% used warn. Null = not measurable (ok). */
    public static function evalDiskSpace(?float $bootFreePct, ?float $tmpUsedPct): array {
        if ($bootFreePct !== null && $bootFreePct < 5.0) {
            return self::result(self::STATUS_FAIL, sprintf('/boot only %.1f%% free (< 5%%)', $bootFreePct));
        }
        $warns = [];
        if ($bootFreePct !== null && $bootFreePct < 10.0) {
            $warns[] = sprintf('/boot %.1f%% free (< 10%%)', $bootFreePct);
        }
        if ($tmpUsedPct !== null && $tmpUsedPct > 80.0) {
            $warns[] = sprintf('/tmp %.1f%% used (> 80%%)', $tmpUsedPct);
        }
        if ($warns !== []) {
            return self::result(self::STATUS_WARN, implode('; ', $warns));
        }
        return self::result(self::STATUS_OK, sprintf(
            '/boot %s free, /tmp %s used',
            $bootFreePct === null ? '?' : sprintf('%.0f%%', $bootFreePct),
            $tmpUsedPct === null ? '?' : sprintf('%.0f%%', $tmpUsedPct)
        ));
    }

    /** Boot-integrity cached sweep: any_critical -> fail, any_warning -> warn, none yet -> ok. */
    public static function evalBootIntegrity(?array $sweep): array {
        if ($sweep === null) {
            return self::result(self::STATUS_OK, 'no sweep cached yet (pre-first-sweep)');
        }
        if (!empty($sweep['any_critical'])) {
            return self::result(self::STATUS_FAIL, 'boot-integrity sweep reports critical entity state');
        }
        if (!empty($sweep['any_warning'])) {
            return self::result(self::STATUS_WARN, 'boot-integrity sweep reports warning entity state');
        }
        $h = (int)($sweep['summary']['healthy'] ?? 0);
        $f = (int)($sweep['summary']['fresh'] ?? 0);
        return self::result(self::STATUS_OK, "sweep clean ($h healthy, $f fresh)");
    }

    /** debug.log > 2× rotation cap -> warn (rotation broken). */
    public static function evalDebugLog(int $sizeBytes, int $maxBytes): array {
        $cap = 2 * max(1, $maxBytes);
        if ($sizeBytes > $cap) {
            return self::result(self::STATUS_WARN, sprintf(
                'debug.log %.1f MB exceeds 2x rotation cap (%.1f MB) — rotation broken?',
                $sizeBytes / 1048576, $cap / 1048576
            ));
        }
        return self::result(self::STATUS_OK, sprintf('debug.log %.1f MB within bounds', $sizeBytes / 1048576));
    }

    /** Client-error budget (R-14): > 10 entries in the last hour -> warn. */
    public static function evalClientErrors(int $countLastHour): array {
        if ($countLastHour > self::CLIENT_ERROR_BUDGET_PER_H) {
            return self::result(self::STATUS_WARN, "$countLastHour client JS errors in the last hour (budget " . self::CLIENT_ERROR_BUDGET_PER_H . '/h)');
        }
        return self::result(self::STATUS_OK, "$countLastHour client error(s) in the last hour");
    }

    /** Storage job ledger: any failed job -> warn. @param string[] $states ledger job states */
    public static function evalStorageJobs(array $states): array {
        $failed = count(array_keys($states, 'failed', true));
        if ($failed > 0) {
            return self::result(self::STATUS_WARN, "$failed failed storage job(s) in the ledger");
        }
        return self::result(self::STATUS_OK, count($states) . ' ledger job(s), none failed');
    }

    /**
     * Count ClientError log entries newer than $sinceEpoch in a debug.log tail.
     * Handles both text ("[ts] [ERR!] [ClientError] ...") and JSONL formats. Pure.
     */
    public static function countClientErrors(string $tail, int $sinceEpoch): int {
        $count = 0;
        foreach (explode("\n", $tail) as $line) {
            if (strpos($line, '[ClientError]') === false && strpos($line, '"ctx":"ClientError"') === false) {
                continue;
            }
            $ts = null;
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $m)) {
                $ts = strtotime($m[1]);
            } elseif (preg_match('/"ts":"([^"]+)"/', $line, $m)) {
                $ts = strtotime($m[1]);
            }
            if ($ts !== false && $ts !== null && $ts >= $sinceEpoch) {
                $count++;
            }
        }
        return $count;
    }

    // ==================================================================
    // Collectors (cheap I/O — cached files, /proc/mounts, statvfs, globs)
    // ==================================================================

    private static function collectSupervisor(): array {
        $config = ConfigService::getConfig();
        $enabled = (string)($config['supervisor_enabled'] ?? '1') === '1';
        $tick = (int)($config['supervisor_tick_seconds'] ?? 5);
        return self::evalSupervisor($enabled, SupervisorService::isRunning(), SupervisorService::getTickAge(), max(1, $tick));
    }

    private static function collectManifest(): array {
        $path = StoragePathResolver::manifestPath();
        $exists = file_exists($path);
        $decoded = null;
        if ($exists) {
            // Mirror readManifest()'s torn-read tolerance (vfat transient zero-byte
            // window) so a concurrent atomic write can't show up as a false FAIL.
            for ($i = 0; $i < 3; $i++) {
                if ($i > 0) usleep(50000);
                $raw = @file_get_contents($path);
                if ($raw === false || $raw === '') continue;
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) break;
            }
        }
        $sweepStates = [];
        $sweep = BootIntegrityService::readCachedSweep();
        foreach (($sweep['sweep'] ?? []) as $entry) {
            if (is_array($entry) && isset($entry['entity'], $entry['state'])) {
                $sweepStates[(string)$entry['entity']] = (string)$entry['state'];
            }
        }
        return self::evalManifest($exists, is_array($decoded) ? $decoded : null, $sweepStates);
    }

    private static function collectMounts(): array {
        // One /proc/mounts read (the cheap findmnt equivalent StorageMountService::
        // isMounted uses) -> active overlay entities; per-entity a single glob
        // answers "has persisted layers".
        $mounts = is_readable('/proc/mounts') ? (string)@file_get_contents('/proc/mounts') : '';
        $mounted = [];
        if (preg_match_all('#^\S+\s+/tmp/unraid-aicliagents/work/([^/\s]+)/home\s+overlay\b#m', $mounts, $m)) {
            foreach ($m[1] as $user) {
                $mounted["home/$user"] = self::hasLayers('home', $user, StoragePathResolver::homePersistPath(''));
            }
        }
        $agentBase = preg_quote(StorageMountService::AGENT_MNT_BASE, '#');
        if (preg_match_all('#^\S+\s+' . $agentBase . '/([^/\s]+)\s+overlay\b#m', $mounts, $m)) {
            foreach ($m[1] as $id) {
                $mounted["agent/$id"] = self::hasLayers('agent', $id, StoragePathResolver::agentPersistPath());
            }
        }
        return self::evalMounts($mounted, array_keys(LayerManifestService::getAllEntities()));
    }

    private static function hasLayers(string $type, string $id, string $persistPath): bool {
        $files = @glob("$persistPath/{$type}_{$id}_*.sqsh");
        return is_array($files) && $files !== [];
    }

    private static function collectDeferMarkers(): array {
        $ages = [];
        $now = time();
        foreach (@glob(self::runtimeDir() . '/.bake_defer_reason_*') ?: [] as $f) {
            $mtime = @filemtime($f);
            if ($mtime !== false) {
                $ages[basename($f)] = max(0, $now - $mtime);
            }
        }
        return self::evalDeferMarkers($ages);
    }

    private static function collectDiskSpace(): array {
        return self::evalDiskSpace(self::freePct('/boot'), self::usedPct('/tmp'));
    }

    private static function freePct(string $path): ?float {
        $total = @disk_total_space($path);
        $free  = @disk_free_space($path);
        if ($total === false || $free === false || $total <= 0) return null;
        return ($free / $total) * 100.0;
    }

    private static function usedPct(string $path): ?float {
        $free = self::freePct($path);
        return $free === null ? null : (100.0 - $free);
    }

    private static function collectDebugLog(): array {
        $logFile = (getenv('AICLI_DEBUG_LOG_DIR') ?: '/tmp/unraid-aicliagents') . '/debug.log';
        $size = is_file($logFile) ? (int)(@filesize($logFile) ?: 0) : 0;
        return self::evalDebugLog($size, self::debugLogMaxBytes());
    }

    private static function debugLogMaxBytes(): int {
        $env = getenv('AICLI_DEBUG_LOG_MAX_BYTES');
        if ($env !== false && is_numeric($env) && (int)$env > 0) {
            return (int)$env;
        }
        $raw = ConfigService::getConfig()['debug_log_max_bytes'] ?? LogService::DEFAULT_MAX_BYTES;
        return (is_numeric($raw) && (int)$raw > 0) ? (int)$raw : LogService::DEFAULT_MAX_BYTES;
    }

    private static function collectClientErrors(): array {
        $logFile = (getenv('AICLI_DEBUG_LOG_DIR') ?: '/tmp/unraid-aicliagents') . '/debug.log';
        $tail = self::tailBytes($logFile, self::CLIENT_ERROR_TAIL_BYTES);
        return self::evalClientErrors(self::countClientErrors($tail, time() - 3600));
    }

    /** Last $bytes of a file via fseek — never loads the full log. */
    private static function tailBytes(string $path, int $bytes): string {
        if (!is_file($path) || !is_readable($path)) return '';
        $size = (int)(@filesize($path) ?: 0);
        if ($size === 0) return '';
        $fh = @fopen($path, 'r');
        if ($fh === false) return '';
        if ($size > $bytes) {
            @fseek($fh, $size - $bytes);
        }
        $data = (string)@stream_get_contents($fh);
        @fclose($fh);
        return $data;
    }

    private static function collectStorageJobs(): array {
        $states = [];
        foreach (SupervisorService::listJobs(false) as $job) {
            $states[] = (string)($job['state'] ?? '');
        }
        return self::evalStorageJobs($states);
    }

    // ==================================================================
    // Paths / notify / helpers
    // ==================================================================

    /** Runtime dir for cache + fingerprint + defer markers. AICLI_RUNTIME_DIR test hook. */
    public static function runtimeDir(): string {
        $e = getenv('AICLI_RUNTIME_DIR');
        return ($e !== false && $e !== '') ? rtrim($e, '/') : '/tmp/unraid-aicliagents';
    }

    public static function cachePath(): string {
        return self::runtimeDir() . '/health.json';
    }

    public static function notifiedPath(): string {
        return self::runtimeDir() . '/health.notified';
    }

    private static function ttl(): int {
        $e = getenv('AICLI_HEALTH_TTL_S');
        return ($e !== false && is_numeric($e) && (int)$e >= 0) ? (int)$e : self::CACHE_TTL_S;
    }

    /** @return string[] "name: status (message)" for every non-ok check */
    private static function nonOkSummaries(array $result): array {
        $out = [];
        foreach (($result['checks'] ?? []) as $name => $c) {
            if (!is_array($c) || ($c['status'] ?? '') === self::STATUS_OK) continue;
            $out[] = $name . ': ' . ($c['status'] ?? '?') . ' (' . ($c['message'] ?? '') . ')';
        }
        return $out;
    }

    /** Unraid notify (UtilityService::notify pattern + severity level, like BootIntegrityService). */
    private static function notify(string $subject, string $description, string $severity): void {
        $script = getenv('AICLI_NOTIFY_SCRIPT') ?: self::NOTIFY_SCRIPT;
        if (!file_exists($script)) {
            return;
        }
        $cmd = escapeshellcmd($script)
            . " -e 'AICliAgents' -s " . escapeshellarg($subject)
            . ' -d ' . escapeshellarg($description)
            . " -i 'tasks' -l " . escapeshellarg($severity);
        exec($cmd . ' 2>/dev/null');
    }

    private static function result(string $status, string $message): array {
        return ['status' => $status, 'message' => $message];
    }

    private static function rank(string $status): int {
        switch ($status) {
            case self::STATUS_FAIL: return 2;
            case self::STATUS_WARN: return 1;
            default:                return 0;
        }
    }
}
