<?php
/**
 * <module_context>
 *     <name>DiagnosticsService</name>
 *     <description>Builds the redacted support bundle (R-08, Feature #1371) at
 *     /tmp/unraid-aicliagents/support/aicli-support-&lt;version&gt;-&lt;ts&gt;.zip, generates the ≤3KB
 *     share summary (markdown|bbcode), and matches the storefront known-issues feed against recent
 *     redacted log lines. Every bundle section is built from an explicit field ALLOWLIST (layer 1):
 *     raw .cfg / secrets.cfg / env files are never read into the bundle. All section content passes
 *     RedactionService layers 2–4, then the layer-5 self-test (verifyRedaction) — any surviving known
 *     secret value aborts the build (exception, no zip emitted).</description>
 *     <dependencies>RedactionService, ConfigService, FileStorage, LayerManifestService, BootIntegrityService, SupervisorService, ActivityService, StoragePathResolver, AtomicWriteService, TraceContext, HealthService</dependencies>
 *     <constraints>Uses ZipArchive (php-zip — Unraid ships php with zip; see docs/specs/DIAGNOSTICS_BUNDLE.md).
 *     Section collectors are individually fault-isolated (a failed collector yields an error stub, never
 *     a thrown bundle) but redaction failures are FAIL-CLOSED (throw, no zip). Known-issues feed is only
 *     fetched on explicit user action (never automatic), 24h cache. Test hooks: AICLI_SUPPORT_DIR,
 *     AICLI_KNOWN_ISSUES_URL, AICLI_KNOWN_ISSUES_CACHE (AICLI_MANIFEST_PATH precedent).</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

require_once __DIR__ . '/RedactionService.php';

class DiagnosticsService {

    public const SCHEMA_VERSION = 1;

    /** Public storefront repo (GitHub) — issue links + known-issues raw feed. */
    public const STOREFRONT_REPO_URL = 'https://github.com/johnpwhite/unraid-plg-aicliagents';
    public const KNOWN_ISSUES_URL    = 'https://raw.githubusercontent.com/johnpwhite/unraid-plg-aicliagents/main/src/known-issues.json';
    public const FORUM_URL           = 'https://forums.unraid.net/topic/197460-plugin-support-unraid-tab-for-ai-cli-coding-agents-gemini-cli-claude-code-opencode-kilo-code-pi-coder-codex-cli-factory-droid-cli-copilot-nano-coder/';

    private const SUPPORT_DIR        = '/tmp/unraid-aicliagents/support';
    private const KNOWN_ISSUES_CACHE = '/tmp/unraid-aicliagents/known-issues.cache.json';
    private const KNOWN_ISSUES_TTL   = 86400; // 24h
    private const SUMMARY_MAX_BYTES  = 3072;  // ≤3KB share summary
    private const BUNDLES_KEPT       = 5;     // tmpfs growth bound

    /** storage.json config-key ALLOWLIST — never the raw .cfg. No secret-bearing keys. */
    private const CONFIG_ALLOWLIST = [
        'root_path', 'user', 'theme', 'debug_logging',
        'home_storage_path', 'agent_storage_path',
        'write_protect_agents', 'enable_tab',
        'supervisor_enabled', 'supervisor_tick_seconds',
        'bake_schedule_minutes',
        'dirty_threshold_soft_mb', 'dirty_threshold_hard_mb', 'dirty_threshold_critical_mb',
        'consolidate_layer_threshold_flash', 'consolidate_layer_threshold_array', 'consolidate_max_layers',
        'boot_integrity_strict', 'verify_sha256_on_boot',
        'debug_log_format', 'debug_log_max_bytes', 'lifecycle_log_max_bytes',
        'version_check_schedule', 'graceful_close_timeout',
        'event_stopping_flush_timeout_seconds',
        'health_check_schedule',
    ];

    public static function supportDir(): string {
        $e = getenv('AICLI_SUPPORT_DIR');
        return ($e !== false && $e !== '') ? $e : self::SUPPORT_DIR;
    }

    // ==================================================================
    // Bundle build
    // ==================================================================

    /**
     * Build the support bundle zip. Returns ['file'=>basename, 'path'=>abs,
     * 'size'=>bytes, 'sections'=>[names]]. Throws on redaction self-test
     * failure or zip failure — the AJAX handler converts to an error status.
     *
     * @param array{anonymize?:bool} $opts
     */
    public static function createBundle(array $opts = []): array {
        $anonymize = !empty($opts['anonymize']);
        $secrets   = RedactionService::loadKnownSecrets();

        // Layer 1: every section below is an explicit allowlist build.
        $sections = self::buildSections();

        // Layers 2–4 + Layer 5 (fail-closed self-test over the FINAL bytes).
        $files = self::redactSections($sections, $secrets, $anonymize);
        self::verifyRedaction($files, $secrets);

        if (!class_exists('\ZipArchive')) {
            throw new \RuntimeException('php-zip (ZipArchive) is unavailable in this PHP runtime');
        }

        $dir = self::supportDir();
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        $version = (string)ConfigService::getVersion();
        $version = preg_replace('/[^A-Za-z0-9._-]/', '_', $version) ?: 'unknown';
        $name = 'aicli-support-' . $version . '-' . gmdate('Ymd\THis\Z') . '.zip';
        $path = rtrim($dir, '/') . '/' . $name;

        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Could not create bundle zip at $path");
        }
        foreach ($files as $fname => $content) {
            $zip->addFromString($fname, $content);
        }
        if (!$zip->close()) {
            @unlink($path);
            throw new \RuntimeException('Bundle zip finalisation failed');
        }

        self::pruneOldBundles($dir);
        LogService::log('Support bundle created: ' . $name . ' (' . count($files) . ' sections)', LogService::LOG_INFO, 'Diagnostics');

        return [
            'file'     => $name,
            'path'     => $path,
            'size'     => (int)(@filesize($path) ?: 0),
            'sections' => array_keys($files),
        ];
    }

    /**
     * Layer 5 gate, separated for testability: every bundle file's FINAL bytes
     * must contain zero known secret values. Throws (no zip) on any survivor.
     *
     * @param array<string,string> $files  fname => final content bytes
     * @throws \RuntimeException
     */
    public static function verifyRedaction(array $files, array $secrets): void {
        foreach ($files as $fname => $content) {
            RedactionService::assertClean($content, $secrets, $fname);
        }
    }

    /** @return array<string,string> redacted fname => content */
    private static function redactSections(array $sections, array $secrets, bool $anonymize): array {
        $out = [];
        foreach ($sections as $fname => $content) {
            $out[$fname] = RedactionService::redact((string)$content, $secrets, $anonymize);
        }
        return $out;
    }

    /**
     * Build all bundle sections (contents table — docs/specs/DIAGNOSTICS_BUNDLE.md §2).
     * Each collector is fault-isolated: a throwing collector yields an error stub.
     *
     * @return array<string,string> fname => raw (pre-redaction) content
     */
    private static function buildSections(): array {
        $collectors = [
            'meta.json'           => function () { return self::jsonEnc(self::collectMeta()); },
            'boot.json'           => function () { return self::jsonEnc(self::collectBoot()); },
            'storage.json'        => function () { return self::jsonEnc(self::collectStorage()); },
            'manifest.json'       => function () { return self::jsonEnc(self::collectManifestSummary()); },
            'boot-integrity.json' => function () { return self::jsonEnc(BootIntegrityService::readCachedSweep()); },
            'lifecycle.log'       => function () { return self::tailFile(StoragePathResolver::lifecycleLogPath(), 300); },
            'debug.log'           => function () { return self::tailFile(self::debugLogPath(), 800); },
            'supervisor.json'     => function () { return self::jsonEnc(self::collectSupervisor()); },
            'sessions.json'       => function () { return self::jsonEnc(self::collectSessions()); },
            'activity.json'       => function () { return self::jsonEnc(ActivityService::listAll()); },
            // R-09 (#1372): cached health state (recomputed only when stale).
            'health.json'         => function () { return self::jsonEnc(HealthService::get()); },
            // Feature #1382: programmatic state-invariant audit (orphan mounts,
            // manifest<->disk drift, stale markers, supervisor single-instance,
            // orphan loops). Same five invariants the bash ledger asserts in the
            // test layers. Detail strings are entity/file names + counts — no
            // secrets — but they still pass through the redaction layers below.
            'state-audit.json'    => function () { return self::jsonEnc(StorageStateAuditService::audit()); },
        ];
        $sections = [];
        foreach ($collectors as $fname => $fn) {
            try {
                $sections[$fname] = $fn();
            } catch (\Throwable $e) {
                $sections[$fname] = self::jsonEnc(['error' => 'collector failed: ' . $e->getMessage()]);
            }
        }
        return $sections;
    }

    // ------------------------------------------------------------------
    // Section collectors (explicit allowlists)
    // ------------------------------------------------------------------

    private static function collectMeta(): array {
        $unraid = null;
        if (is_readable('/etc/unraid-version')) {
            $ini = @parse_ini_file('/etc/unraid-version');
            $unraid = is_array($ini) ? ($ini['version'] ?? null) : null;
        }
        return [
            'schema'         => self::SCHEMA_VERSION,
            'plugin_version' => ConfigService::getVersion(),
            'unraid_version' => $unraid,
            'php_version'    => PHP_VERSION,
            'generated_at'   => gmdate('Y-m-d\TH:i:s\Z'),
            'trace'          => TraceContext::getId(),
        ];
    }

    private static function collectBoot(): array {
        $probe = FileStorage::probeTarget('/boot');
        // Allowlisted probe fields only (commit 569fc1d probe schema).
        $keep = ['schema', 'fstype', 'mount_class', 'durability', 'wear', 'engine', 'upper_mode', 'refuse', 'reasons'];
        $boot = ['probe' => array_intersect_key($probe, array_flip($keep))];
        $boot['findmnt'] = trim((string)@shell_exec('findmnt -no SOURCE,FSTYPE /boot 2>/dev/null'));
        return $boot;
    }

    private static function collectStorage(): array {
        $config = ConfigService::getConfig();
        $cfg = array_intersect_key($config, array_flip(self::CONFIG_ALLOWLIST));

        // Per-entity backend via the existing status surface, reduced to an
        // allowlisted per-entity shape (no free-form passthrough).
        $entities = ['agents' => [], 'homes' => []];
        $status = StorageMetricsService::getStatus();
        foreach (['agents', 'homes'] as $kind) {
            foreach (($status[$kind] ?? []) as $id => $st) {
                $entities[$kind][$id] = [
                    'mounted'    => $st['mounted'] ?? null,
                    'layers'     => $st['layers'] ?? null,
                    'backend'    => $st['backend'] ?? null,
                    'supportsBake' => $st['supportsBake'] ?? null,
                    'dirty_mb'   => $st['dirty_mb'] ?? ($st['dirtyMB'] ?? null),
                    'physical_mb'=> $st['physical_mb'] ?? ($st['physicalMB'] ?? null),
                ];
            }
        }

        $persistRoot = StoragePathResolver::agentPersistPath();
        $df = trim((string)@shell_exec('df -h /boot /tmp ' . escapeshellarg($persistRoot) . ' 2>/dev/null'));
        $zram = trim((string)@shell_exec('zramctl 2>/dev/null'));

        return [
            'config'        => $cfg,
            'entities'      => $entities,
            'home_available'   => $status['home_available'] ?? null,
            'agents_available' => $status['agents_available'] ?? null,
            'emergency_mode'   => $status['emergency_mode'] ?? null,
            'degraded'         => $status['degraded'] ?? null,
            'rootfs'           => $status['rootfs'] ?? null,
            'df'            => $df,
            'zram'          => $zram,
        ];
    }

    /** Manifest SUMMARY only — per-entity states/counts/sizes, no file-level listings. */
    private static function collectManifestSummary(): array {
        $out = [];
        foreach (LayerManifestService::getAllEntities() as $entity => $e) {
            $layers = is_array($e['expected_layers'] ?? null) ? $e['expected_layers'] : [];
            $bytes = 0;
            $kinds = [];
            foreach ($layers as $l) {
                $bytes += (int)($l['bytes'] ?? 0);
                $k = (string)($l['kind'] ?? 'unknown');
                $kinds[$k] = ($kinds[$k] ?? 0) + 1;
            }
            $out[$entity] = [
                'layer_count'        => count($layers),
                'total_bytes'        => $bytes,
                'kinds'              => $kinds,
                'state'              => $e['state'] ?? null,
                'last_known_good_at' => $e['last_known_good_at'] ?? null,
                'persistence_path'   => $e['current_persistence_path'] ?? null,
            ];
        }
        return $out;
    }

    /** Supervisor status + queue op kinds/ages — never queue payloads. */
    private static function collectSupervisor(): array {
        $queue = [];
        $now = time();
        foreach (glob(SupervisorService::QUEUE_DIR . '/*.req') ?: [] as $f) {
            $j = json_decode((string)@file_get_contents($f), true);
            $queue[] = [
                'op'       => is_array($j) ? ($j['op'] ?? null) : null,
                'type'     => is_array($j) ? ($j['type'] ?? null) : null,
                'priority' => is_array($j) ? ($j['priority'] ?? null) : null,
                'age_s'    => max(0, $now - (int)(@filemtime($f) ?: $now)),
            ];
        }
        return [
            'running'    => SupervisorService::isRunning(),
            'tick_age_s' => SupervisorService::getTickAge(),
            'status'     => SupervisorService::getStatus(),
            'work'       => SupervisorService::getWorkState(),
            'queue'      => $queue,
        ];
    }

    /** Tmux/session inventory: names/agent/uptime — NO pane contents, no paths. */
    private static function collectSessions(): array {
        $out = [];
        $now = time();
        foreach (glob('/var/run/aicliterm-*.sock') ?: [] as $sock) {
            if (!preg_match('/aicliterm-(.*)\.sock$/', $sock, $m)) continue;
            $id = $m[1];
            $agentFile = "/var/run/unraid-aicliagents-{$id}.agentid";
            $out[] = [
                'id'       => $id,
                'agent'    => is_file($agentFile) ? trim((string)@file_get_contents($agentFile)) : '',
                'uptime_s' => max(0, $now - (int)(@filemtime($sock) ?: $now)),
            ];
        }
        return $out;
    }

    // ==================================================================
    // Share summary (≤3KB, redacted server-side)
    // ==================================================================

    /**
     * Compact redacted summary for forum/GitHub sharing. $format: 'markdown'|'bbcode'.
     * Hard-capped at SUMMARY_MAX_BYTES.
     */
    public static function summary(string $format = 'markdown'): string {
        $secrets = RedactionService::loadKnownSecrets();
        $bb = ($format === 'bbcode');
        $b = function (string $s) use ($bb) { return $bb ? '[b]' . $s . '[/b]' : "**$s**"; };

        $meta = self::collectMeta();
        $bootMode = 'unknown';
        try {
            $probe = FileStorage::probeTarget('/boot');
            $bootMode = ($probe['fstype'] ?? '?') . ' / ' . ($probe['mount_class'] ?? '?') . ' / wear=' . ($probe['wear'] ?? '?');
        } catch (\Throwable $e) { /* keep 'unknown' */ }

        $agentBackend = 'unknown';
        $homeBackend  = 'unknown';
        try {
            $agentBackend = FileStorage::backendForPath(StoragePathResolver::agentPersistPath())['backend'];
            $homeBackend  = FileStorage::backendForPath(StoragePathResolver::homePersistPath(''))['backend'];
        } catch (\Throwable $e) { /* keep */ }

        $errors = [];
        try {
            foreach (array_reverse(LifecycleLogService::tail(300)) as $entry) {
                if (in_array($entry['level'] ?? '', ['error', 'critical'], true)) {
                    $errors[] = ($entry['ts'] ?? '') . ' ' . ($entry['component'] ?? '') . '/' . ($entry['event'] ?? '');
                    if (count($errors) >= 5) break;
                }
            }
        } catch (\Throwable $e) { /* none */ }

        // R-09 (#1372): cached health state + non-ok check names.
        $health = 'unknown';
        try {
            $h = HealthService::get();
            $health = (string)($h['overall'] ?? 'unknown');
            $bad = [];
            foreach (($h['checks'] ?? []) as $name => $c) {
                if (is_array($c) && ($c['status'] ?? '') !== 'ok') {
                    $bad[] = $name . '=' . ($c['status'] ?? '?');
                }
            }
            if ($bad !== []) {
                $health .= ' (' . implode(', ', $bad) . ')';
            }
        } catch (\Throwable $e) { /* keep 'unknown' */ }

        $lines = [
            $b('AI CLI Agents — support summary'),
            'Plugin: ' . ($meta['plugin_version'] ?? 'unknown')
                . ' | Unraid: ' . ($meta['unraid_version'] ?? 'unknown')
                . ' | PHP: ' . ($meta['php_version'] ?? 'unknown'),
            'Boot mode: ' . $bootMode,
            'Backend: agents=' . $agentBackend . ' homes=' . $homeBackend,
            'Health: ' . $health,
            $b('Last lifecycle errors') . ($errors === [] ? ': none' : ''),
        ];
        foreach ($errors as $e) {
            $lines[] = '- ' . $e;
        }
        $lines[] = '';
        $lines[] = ($bb ? '[i]' : '_') . 'Generated by the plugin diagnostics tool. Full details are in the support bundle zip.' . ($bb ? '[/i]' : '_');

        $text = RedactionService::redact(implode("\n", $lines), $secrets);
        RedactionService::assertClean($text, $secrets, 'summary');
        if (strlen($text) > self::SUMMARY_MAX_BYTES) {
            $text = substr($text, 0, self::SUMMARY_MAX_BYTES - 14) . "\n…(truncated)";
        }
        return $text;
    }

    // ==================================================================
    // Known issues (explicit action only — never auto-fetched)
    // ==================================================================

    /**
     * Fetch (24h-cached) the storefront known-issues feed and match it
     * server-side against the current version + the last 500 redacted
     * debug+lifecycle lines. Only ever invoked from the explicit
     * diag_known_issues action — never automatically.
     */
    public static function knownIssues(bool $forceRefresh = false): array {
        $feed = self::loadKnownIssuesFeed($forceRefresh);
        if (isset($feed['error'])) {
            return ['status' => 'error', 'message' => $feed['error']];
        }
        $issues = $feed['issues'];

        $secrets = RedactionService::loadKnownSecrets();
        $haystack = RedactionService::redact(
            self::tailFile(self::debugLogPath(), 500) . "\n" . self::tailFile(StoragePathResolver::lifecycleLogPath(), 500),
            $secrets
        );
        $lines = explode("\n", $haystack);
        $lines = array_slice($lines, -500);
        $version = (string)ConfigService::getVersion();

        $out = [];
        foreach ($issues as $issue) {
            if (!is_array($issue) || empty($issue['id'])) continue;
            $entry = [
                'id'                => (string)$issue['id'],
                'title'             => (string)($issue['title'] ?? ''),
                'affected_versions' => (array)($issue['affected_versions'] ?? []),
                'fixed_in'          => (string)($issue['fixed_in'] ?? ''),
                'forum_url'         => (string)($issue['forum_url'] ?? ''),
                'workaround'        => (string)($issue['workaround'] ?? ''),
                'matched'           => false,
                'matched_line'      => null,
            ];
            if (self::versionAffected($version, $entry['affected_versions'])) {
                $re = '/' . str_replace('/', '\/', (string)($issue['signature_regex'] ?? '')) . '/i';
                if ((string)($issue['signature_regex'] ?? '') !== '' && @preg_match($re, '') !== false) {
                    foreach ($lines as $line) {
                        if ($line !== '' && @preg_match($re, $line) === 1) {
                            $entry['matched'] = true;
                            $entry['matched_line'] = substr($line, 0, 200);
                            break;
                        }
                    }
                }
            }
            $out[] = $entry;
        }
        return [
            'status'     => 'ok',
            'version'    => $version,
            'fetched_at' => $feed['fetched_at'],
            'from_cache' => $feed['from_cache'],
            'issues'     => $out,
            'match_count'=> count(array_filter($out, function ($i) { return $i['matched']; })),
        ];
    }

    /** '*' or empty = all versions; otherwise exact or prefix match on the entry. */
    private static function versionAffected(string $version, array $affected): bool {
        if ($affected === []) return true;
        foreach ($affected as $a) {
            $a = (string)$a;
            if ($a === '*' || $a === $version) return true;
            if ($a !== '' && strpos($version, $a) === 0) return true;
        }
        return false;
    }

    /**
     * The known-issues feed bundled with the deployed plugin (src/known-issues.json,
     * three levels up from this service). The final offline fallback. Returns the
     * issues list or null when absent/unparseable. AICLI_KNOWN_ISSUES_LOCAL
     * overrides the path for tests.
     * @return array<int,mixed>|null
     */
    private static function localKnownIssuesFeed(): ?array {
        $path = getenv('AICLI_KNOWN_ISSUES_LOCAL') ?: (__DIR__ . '/../../known-issues.json');
        if (!is_file($path)) return null;
        $decoded = json_decode((string)@file_get_contents($path), true);
        if (!is_array($decoded)) return null;
        if (isset($decoded['issues'])) return (array)$decoded['issues'];
        if (array_is_list($decoded)) return $decoded;
        return null;
    }

    /** @return array{issues:array,fetched_at:?string,from_cache:bool}|array{error:string} */
    private static function loadKnownIssuesFeed(bool $forceRefresh): array {
        $cachePath = getenv('AICLI_KNOWN_ISSUES_CACHE') ?: self::KNOWN_ISSUES_CACHE;
        if (!$forceRefresh && is_file($cachePath) && (time() - (int)(@filemtime($cachePath) ?: 0)) < self::KNOWN_ISSUES_TTL) {
            $cached = json_decode((string)@file_get_contents($cachePath), true);
            if (is_array($cached) && isset($cached['issues'])) {
                return ['issues' => (array)$cached['issues'], 'fetched_at' => $cached['fetched_at'] ?? null, 'from_cache' => true];
            }
        }

        $url = getenv('AICLI_KNOWN_ISSUES_URL') ?: self::KNOWN_ISSUES_URL;
        $ctx = stream_context_create(['http' => ['timeout' => 10, 'user_agent' => 'unraid-aicliagents-diagnostics'],
                                      'https' => ['timeout' => 10]]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            // Stale cache is better than nothing when the fetch fails.
            $cached = is_file($cachePath) ? json_decode((string)@file_get_contents($cachePath), true) : null;
            if (is_array($cached) && isset($cached['issues'])) {
                return ['issues' => (array)$cached['issues'], 'fetched_at' => $cached['fetched_at'] ?? null, 'from_cache' => true];
            }
            // Final fallback: the feed bundled WITH the plugin (deployed at
            // src/known-issues.json). This makes "Check known issues" work even
            // before the storefront feed is published and when the box is offline
            // — the remote refresh only overlays newer issues when reachable.
            $local = self::localKnownIssuesFeed();
            if ($local !== null) {
                return ['issues' => $local, 'fetched_at' => null, 'from_cache' => true];
            }
            return ['error' => 'No known-issues feed is available (the published feed could not be reached and no bundled copy was found).'];
        }
        $decoded = json_decode($raw, true);
        $issues = is_array($decoded) ? (isset($decoded['issues']) ? (array)$decoded['issues'] : (array_is_list($decoded) ? $decoded : [])) : [];
        $fetchedAt = gmdate('Y-m-d\TH:i:s\Z');
        AtomicWriteService::writeJson($cachePath, ['fetched_at' => $fetchedAt, 'issues' => $issues]);
        return ['issues' => $issues, 'fetched_at' => $fetchedAt, 'from_cache' => false];
    }

    // ------------------------------------------------------------------
    // Internal helpers
    // ------------------------------------------------------------------

    private static function debugLogPath(): string {
        $dir = getenv('AICLI_DEBUG_LOG_DIR') ?: '/tmp/unraid-aicliagents';
        return rtrim($dir, '/') . '/debug.log';
    }

    /** Last N lines of a text file ('' when missing/unreadable). */
    private static function tailFile(string $path, int $lines): string {
        if ($path === '' || !is_file($path) || !is_readable($path)) return '';
        $content = (string)@file_get_contents($path);
        if ($content === '') return '';
        $all = explode("\n", rtrim($content, "\n"));
        return implode("\n", array_slice($all, -$lines));
    }

    private static function jsonEnc($data): string {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $json === false ? '{"error":"json_encode failed"}' : $json;
    }

    /** Keep only the newest BUNDLES_KEPT zips (tmpfs RAM bound). */
    private static function pruneOldBundles(string $dir): void {
        $zips = glob(rtrim($dir, '/') . '/aicli-support-*.zip') ?: [];
        if (count($zips) <= self::BUNDLES_KEPT) return;
        usort($zips, function ($a, $b) { return (@filemtime($b) ?: 0) <=> (@filemtime($a) ?: 0); });
        foreach (array_slice($zips, self::BUNDLES_KEPT) as $old) {
            @unlink($old);
        }
    }
}
