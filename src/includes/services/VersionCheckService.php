<?php
/**
 * <module_context>
 *     <name>VersionCheckService</name>
 *     <description>Agent version checking with lock-based concurrency, caching, and Unraid notifications.</description>
 *     <dependencies>LogService, ConfigService, AgentRegistry, NchanService</dependencies>
 *     <constraints>Single check at a time via lock file. Per-agent 15s timeout. Atomic cache writes.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class VersionCheckService {
    const LOCK_MAX_AGE = 600; // 10 minutes — stale lock threshold
    const PER_AGENT_TIMEOUT = 15; // seconds per npm view call
    const TOTAL_TIMEOUT = 120; // seconds total for all agents
    const MAX_DROPDOWN_ENTRIES = 20;
    /** #828: the Beta list shows at most this many builds, newest first. */
    const MAX_BETA_ENTRIES = 8;

    /**
     * R-171: a version string from a registry reaches the Store page. Only the characters of a
     * real version (digits, letters, dot, plus, underscore, hyphen) are accepted, so markup in
     * a hostile registry answer is never cached.
     */
    public static function isSafeVersion($v): bool {
        return is_string($v) && preg_match('/^[0-9A-Za-z][0-9A-Za-z.+_-]{0,63}$/D', $v) === 1;
    }

    /** R-171: keep only the dist-tags whose name and version are safe. */
    public static function cleanDistTags($tags): array {
        $clean = [];
        if (!is_array($tags)) return $clean;
        foreach ($tags as $tag => $ver) {
            if (is_string($tag) && preg_match('/^[0-9A-Za-z][0-9A-Za-z._-]{0,63}$/D', $tag) === 1 && self::isSafeVersion($ver)) {
                $clean[$tag] = $ver;
            }
        }
        return $clean;
    }

    /** Activity tray op id of a running check (Forgejo #447). */
    public const TRAY_OP_ID = 'version_check';

    /**
     * One summary of the cache for the Store status line (Forgejo #447):
     * the newest checked_at over $agentIds, how many of them carry a check_error,
     * and the first such error. Pure: no I/O.
     * @param array<string,array> $cache
     * @param string[] $agentIds
     * @return array{checked_at:int,errors:int,first_error:?string}
     */
    public static function summariseCache(array $cache, array $agentIds): array {
        $checkedAt = 0; $errors = 0; $first = null;
        foreach ($agentIds as $id) {
            $e = $cache[$id] ?? null;
            if (!is_array($e)) continue;
            $ts = (int)($e['checked_at'] ?? 0);
            if ($ts > $checkedAt) $checkedAt = $ts;
            $err = $e['check_error'] ?? null;
            if (is_string($err) && $err !== '') {
                $errors++;
                if ($first === null) $first = $err;
            }
        }
        return ['checked_at' => $checkedAt, 'errors' => $errors, 'first_error' => $first];
    }

    /**
     * Check all installed agents for available versions.
     * Returns cached results if lock is held by another process.
     * @param bool $force Skip cache freshness check
     * @return array Version data keyed by agent ID
     */
    public static function checkAllAgents(bool $force = false, array $seams = []): array {
        $config = ConfigService::getConfig();
        $maxAge = 3600; // 1 hour default TTL
        $now = $seams['now'] ?? static fn(): int => time();

        // Return cache if fresh and not forced
        if (!$force && self::isCacheFresh($maxAge)) {
            return self::getCachedResults();
        }

        // Acquire lock (non-blocking)
        self::recoverStaleLock();
        $lockPath = self::lockPath();
        $lockDir = dirname($lockPath);
        if (!is_dir($lockDir)) @mkdir($lockDir, 0755, true);

        $fp = @fopen($lockPath, 'c+');
        if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
            // Another check is running — return cached results
            LogService::log("Version check skipped: another check is in progress.", LogService::LOG_DEBUG, "VersionCheck");
            if ($fp) fclose($fp);
            return self::getCachedResults();
        }

        // Write PID + timestamp for staleness detection
        ftruncate($fp, 0);
        fwrite($fp, getmypid() . ':' . time());
        fflush($fp);

        $startTime = $now();
        // Forgejo #447: the check is visible while it runs (Activity tray entry
        // `version_check`), and its end reaches every open Store page on the event
        // stream, so the cards refresh the moment the figures are real. Best effort.
        $trayOpen = false;
        try {
            if (class_exists('\\AICliAgents\\Services\\ActivityService')) {
                ActivityService::register(self::TRAY_OP_ID, 'system', self::TRAY_TITLE_RUNNING, [
                    'step' => 'Asking the package registries for the newest versions',
                    // ACTIVITY_TRAY_PLAIN_NOTICES.md R9: Cancel would only flip the row.
                    'meta' => ['cancel' => 'none'],
                ]);
                $trayOpen = true;
            }
        } catch (\Throwable $e) { /* the tray is a courtesy */ }
        LogService::log("Starting version check for all agents...", LogService::LOG_INFO, "VersionCheck");

        try {
            $registry = isset($seams['registry']) ? $seams['registry']() : AgentRegistry::getRegistry();
            $pluginDir = $seams['plugin_dir'] ?? "/usr/local/emhttp/plugins/unraid-aicliagents";
            $cache = self::getCachedResults();
            $updated = false;

            $agentNo = 0; $agentTotal = count($registry);
            // Agents the check was meant to ask, and how many of them the total
            // timeout left unasked. They decide the end row (trayOutcome()).
            $checkable = 0; $checkIds = []; $skipped = 0; $timedOut = false;
            foreach ($registry as $id => $agent) {
                $agentNo++;
                if ($trayOpen) {
                    // #447: a heartbeat per agent, so a slow registry never reads as "stalled".
                    try { ActivityService::update(self::TRAY_OP_ID, ['step' => self::trayAgentStep($agent, (string)$id, $agentNo, $agentTotal), 'progress' => (int)floor(100 * ($agentNo - 1) / max(1, $agentTotal))]); } catch (\Throwable $e) {}
                }
                $pkg = self::npmPackageOf($agent);
                if (empty($pkg) || $id === 'terminal') continue;
                $checkable++; $checkIds[] = (string)$id;

                // Total timeout guard: an agent left unasked is counted, not forgotten.
                if ($timedOut || ($now() - $startTime) > self::TOTAL_TIMEOUT) {
                    if (!$timedOut) {
                        $timedOut = true;
                        LogService::log("Version check total timeout reached (" . self::TOTAL_TIMEOUT . "s). Remaining agents skipped.", LogService::LOG_WARN, "VersionCheck");
                    }
                    $skipped++;
                    continue;
                }

                LogService::log("Checking versions for $id ($pkg)...", LogService::LOG_DEBUG, "VersionCheck");

                // #549: a cache folder of its own, removed after the call (not ~/.npm in RAM).
                $npmCache = isset($seams['npm_cache_dir'])
                    ? $seams['npm_cache_dir']((string)$id)
                    : UtilityService::npmCacheDir("view-$id");
                $cmd = "export PATH=$pluginDir/bin:\$PATH; timeout " . self::PER_AGENT_TIMEOUT
                     . " npm view " . escapeshellarg($pkg) . " --json --cache " . escapeshellarg($npmCache) . " 2>/dev/null";
                $output = isset($seams['npm_view']) ? $seams['npm_view']((string)$id, $pkg, $cmd) : shell_exec($cmd);
                if (isset($seams['remove_npm_cache'])) $seams['remove_npm_cache']($npmCache);
                else UtilityService::removeNpmCacheDir($npmCache);

                if (empty($output)) {
                    LogService::log("Version check failed for $id: npm returned empty (timeout or registry unreachable).", LogService::LOG_WARN, "VersionCheck");
                    // Keep previous cache entry if it exists
                    if (!isset($cache[$id])) {
                        $cache[$id] = ['checked_at' => time(), 'check_error' => 'npm timeout or registry unreachable', 'dist_tags' => [], 'versions' => []];
                    } else {
                        $cache[$id]['check_error'] = 'npm timeout or registry unreachable (using cached data)';
                    }
                    continue;
                }

                $data = @json_decode($output, true);
                if (!is_array($data)) {
                    LogService::log("Version check failed for $id: malformed JSON response.", LogService::LOG_WARN, "VersionCheck");
                    if (!isset($cache[$id])) {
                        $cache[$id] = ['checked_at' => time(), 'check_error' => 'malformed npm response', 'dist_tags' => [], 'versions' => []];
                    }
                    continue;
                }

                // Extract dist-tags
                $distTags = self::cleanDistTags($data['dist-tags'] ?? []);

                // Extract versions with timestamps (R-171: only safe version strings are cached)
                $allVersions = array_values(array_filter(
                    is_array($data['versions'] ?? null) ? $data['versions'] : [],
                    [self::class, 'isSafeVersion']
                ));
                $timeMap = $data['time'] ?? [];
                if (is_array($allVersions) && !empty($allVersions)) {
                    // npm view --json returns versions as array of strings
                    $versions = [];
                    // Build reverse tag map: version → [tag1, tag2, ...]
                    $tagMap = [];
                    foreach ($distTags as $tag => $ver) {
                        $tagMap[$ver][] = $tag;
                    }

                    foreach ($allVersions as $ver) {
                        $date = $timeMap[$ver] ?? null;
                        $versions[] = [
                            'version' => $ver,
                            'date' => $date ? substr($date, 0, 10) : null,
                            'timestamp' => $date ? strtotime($date) : 0,
                            'tags' => $tagMap[$ver] ?? [],
                        ];
                    }

                    $cache[$id] = [
                        'checked_at' => time(),
                        'check_error' => null,
                        'dist_tags' => $distTags,
                        'versions' => $versions,
                    ];
                    $updated = true;
                    LogService::log("Version check OK for $id: " . count($versions) . " versions, " . count($distTags) . " tags.", LogService::LOG_DEBUG, "VersionCheck");
                }
            }

            // Populate cache for non-npm agents that implement populateCache (e.g.
            // github_release source for goose). NpmSource handles npm-package
            // agents above; this second pass picks up the rest.
            foreach ($registry as $id => $agent) {
                if ($id === 'terminal') continue;
                if (self::npmPackageOf($agent) !== '') continue;  // npm already handled above

                $source = \AICliAgents\Services\Sources\SourceResolver::resolve($agent);
                if ($source === null || !method_exists($source, 'populateCache')) continue;
                $checkable++; $checkIds[] = (string)$id;
                if ($timedOut || ($now() - $startTime) > self::TOTAL_TIMEOUT) {
                    $timedOut = true;
                    $skipped++;
                    continue;
                }

                LogService::log("Populating cache for $id (non-npm source)...", LogService::LOG_DEBUG, "VersionCheck");
                try {
                    $entry = $source->populateCache($id, $agent);
                    if (is_array($entry)) {
                        // R-171: the same shape check as the npm path.
                        $entry['dist_tags'] = self::cleanDistTags($entry['dist_tags'] ?? []);
                        $entry['versions'] = array_values(array_filter(
                            is_array($entry['versions'] ?? null) ? $entry['versions'] : [],
                            static fn($row) => is_array($row) ? self::isSafeVersion($row['version'] ?? null) : self::isSafeVersion($row)
                        ));
                    }
                    if (!empty($entry['versions'])) {
                        // Caller metadata last so it can't be overwritten by a future
                        // populateCache that accidentally emits checked_at/check_error.
                        $cache[$id] = array_merge(
                            $entry,
                            ['checked_at' => time(), 'check_error' => null]
                        );
                        $updated = true;
                    }
                } catch (\Throwable $e) {
                    LogService::log("populateCache for $id threw: " . $e->getMessage(), LogService::LOG_WARN, "VersionCheck");
                }
            }

            // Atomic cache write
            if ($updated || empty(self::getCachedResults())) {
                self::writeCacheAtomic($cache);
            }

            LogService::log("Version check complete. " . count($cache) . " agents processed in " . (time() - $startTime) . "s.", LogService::LOG_INFO, "VersionCheck");
            if ($trayOpen) {
                $trayOpen = false;
                try {
                    $sum = self::summariseCache($cache, $checkIds);
                    if ($skipped > 0) {
                        LogService::log("Version check: $skipped of $checkable agents were skipped because the total timeout ran out.", LogService::LOG_WARN, "VersionCheck");
                    }
                    if ($sum['errors'] > 0) {
                        // The first raw error stays in the log (one warning per agent above).
                        LogService::log("Version check: {$sum['errors']} of " . count($cache) . " agents could not be checked. First error: " . $sum['first_error'], LogService::LOG_WARN, "VersionCheck");
                    }
                    self::closeTray((int)$sum['errors'], $checkable, time() - $startTime, $skipped);
                } catch (\Throwable $e) { /* best effort */ }
            }
            return $cache;

        } finally {
            if ($trayOpen) {
                try { self::closeTrayStoppedEarly(); } catch (\Throwable $e) {}
            }
            flock($fp, LOCK_UN);
            fclose($fp);
            @unlink($lockPath);
        }
    }

    /**
     * Get filtered version list for dropdown display.
     * @param string      $agentId
     * @param string|null $channel  'stable' (default) or 'beta'. Legacy 'latest' maps to stable.
     * @param int         $monthsBack  How far back to include untagged stable releases.
     * @return array Sorted versions for the dropdown. Stable is capped at
     * MAX_DROPDOWN_ENTRIES to keep its long history manageable; Beta keeps the
     * newest build of each kind of dated snapshot and at most MAX_BETA_ENTRIES (#828).
     */
    public static function getAvailableVersions(string $agentId, ?string $channel = null, int $monthsBack = 3): array {
        $cache = self::getCachedResults();
        $agentCache = $cache[$agentId] ?? null;
        if (!$agentCache || empty($agentCache['versions'])) return [];

        $channel = AgentRegistry::normalizeChannel((string)($channel ?? 'stable'));
        $channel = ($channel === 'beta') ? 'beta' : 'stable';
        $cutoff   = strtotime("-{$monthsBack} months");
        $distTags = $agentCache['dist_tags'] ?? [];
        $versions = $agentCache['versions'];

        $result = [];

        foreach (array_reverse($versions) as $entry) { // newest first (npm returns ascending)
            $class = VersionClassifier::classify($entry, $distTags, $agentId);

            if ($class === 'platform_variant') continue;

            if ($channel === 'beta') {
                // Some vendors, including Claude Code, publish a beta release
                // train as ordinary semver builds and move `next` over it. The
                // entries between the last explicit stable marker and that
                // beta marker are part of the selectable beta catalogue too;
                // otherwise only the one tagged endpoint is visible.
                if ($class === 'prerelease' || self::isBetaReleaseTrainEntry($entry, $distTags)) {
                    $result[] = $entry;
                }
                continue;
            }

            // Stable channel.
            if ($class !== 'stable') continue;

            if (!empty($entry['tags'])) {
                $result[] = $entry;
                continue;
            }

            $ts = $entry['timestamp'] ?? 0;
            if ($ts >= $cutoff) $result[] = $entry;
        }

        usort($result, function($a, $b) {
            return ($b['timestamp'] ?? 0) - ($a['timestamp'] ?? 0);
        });

        // Stable history is bounded because many packages have hundreds of
        // ordinary releases. #828 (John, 2026-10-28): Beta is bounded too. It was
        // full of dated snapshot builds (OpenCode: dozens of 0.0.0-dev-<stamp>), so
        // each kind of snapshot keeps only its newest build, and the list keeps the
        // MAX_BETA_ENTRIES newest entries.
        return $channel === 'beta'
            ? array_slice(self::newestSnapshotPerKind($result), 0, self::MAX_BETA_ENTRIES)
            : array_slice($result, 0, self::MAX_DROPDOWN_ENTRIES);
    }

    /**
     * #828: of a newest-first list, keep only the newest build of each kind of
     * dated snapshot. The kind is the version up to its date stamp (a run of 8 or
     * more digits): 0.0.0-dev-202610260443 and 0.0.0-dev-202610250607 are one kind,
     * 0.41.0-nightly.20260427.g42587de73 is its own. A version with no stamp is
     * always kept. Pure.
     *
     * @param array<int,array<string,mixed>> $newestFirst
     * @return array<int,array<string,mixed>>
     */
    public static function newestSnapshotPerKind(array $newestFirst): array {
        $seen = [];
        $out = [];
        foreach ($newestFirst as $entry) {
            $v = (string)($entry['version'] ?? '');
            if (preg_match('/^(.*?)\d{8,}/', $v, $m)) {
                if (isset($seen[$m[1]])) continue;
                $seen[$m[1]] = true;
            }
            $out[] = $entry;
        }
        return $out;
    }

    /**
     * Does a normal release belong to the currently published beta train?
     *
     * A vendor may hold its tested baseline at `stable`, then publish ordinary
     * numeric releases before moving `beta` or `next` to the train's current
     * endpoint. Include the open/closed range (stable, beta-target]. Tagged
     * prereleases are handled separately by VersionClassifier.
     */
    private static function isBetaReleaseTrainEntry(array $entry, array $distTags): bool {
        $stable = (string)($distTags['stable'] ?? '');
        $version = (string)($entry['version'] ?? '');
        if ($stable === '' || $version === '') return false;

        foreach (['beta', 'next'] as $tag) {
            $target = (string)($distTags[$tag] ?? '');
            if ($target === '' || version_compare($target, $stable) <= 0) continue;
            if (version_compare($version, $stable) > 0 && version_compare($version, $target) <= 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Pure release-channel resolver used by every install entry point.
     * It returns an exact cached version when possible, preventing npm's
     * moving `latest` tag from bypassing the user's Stable selection.
     *
     * @param array<string,string> $distTags
     * @return array{channel:string,target:?string,resolved_tag:?string,fallback:bool,error:?string}
     */
    public static function resolveChannelTarget(string $channel, ?string $pinned, array $distTags): array
    {
        $channel = AgentRegistry::normalizeChannel($channel);
        if ($channel === 'pinned') {
            $target = ($pinned !== null && $pinned !== '') ? $pinned : null;
            if ($target !== null && !AgentRegistry::isSafeVersionString($target)) {
                return [
                    'channel' => 'pinned', 'target' => null, 'resolved_tag' => null,
                    'fallback' => false, 'error' => 'invalid_version',
                ];
            }
            return [
                'channel' => 'pinned',
                'target' => $target,
                'resolved_tag' => $target !== null ? 'pinned' : null,
                'fallback' => false,
                'error' => $target === null ? 'missing_pinned_version' : null,
            ];
        }

        $candidates = $channel === 'beta' ? ['beta', 'next'] : ['stable', 'latest'];
        foreach ($candidates as $index => $tag) {
            $version = isset($distTags[$tag]) ? (string)$distTags[$tag] : '';
            if ($version === '') continue;
            if (!AgentRegistry::isSafeVersionString($version)) {
                return [
                    'channel' => $channel, 'target' => null, 'resolved_tag' => null,
                    'fallback' => false, 'error' => 'invalid_version',
                ];
            }
            return [
                'channel' => $channel,
                'target' => $version,
                'resolved_tag' => $tag,
                'fallback' => $index > 0,
                'error' => null,
            ];
        }

        // With an empty/stale cache, preserve source-native tag semantics.
        // Stable sources conventionally call this `latest`; beta sources call
        // it `beta`. A later fetch failure stays visible instead of silently
        // crossing from beta to stable.
        $tag = $channel === 'beta' ? 'beta' : 'latest';
        return [
            'channel' => $channel,
            'target' => $tag,
            'resolved_tag' => $tag,
            'fallback' => $channel === 'stable',
            'error' => null,
        ];
    }

    /**
     * Resolve an optional install target against the agent's persisted channel.
     * Explicit version selections always win; omitted targets use Stable/Beta/
     * Pinned semantics from the current version cache.
     *
     * @return array{channel:string,target:?string,resolved_tag:?string,fallback:bool,error:?string,explicit:bool}
     */
    public static function resolveInstallTarget(string $agentId, ?string $explicitTarget): array
    {
        if ($explicitTarget !== null && $explicitTarget !== '') {
            $target = $explicitTarget;
            if (!AgentRegistry::isSafeVersionString($target)) {
                return [
                    'channel' => 'explicit', 'target' => null, 'resolved_tag' => null,
                    'fallback' => false, 'error' => 'invalid_version', 'explicit' => true,
                ];
            }
            return [
                'channel' => 'explicit',
                'target' => $target,
                'resolved_tag' => null,
                'fallback' => false,
                'error' => null,
                'explicit' => true,
            ];
        }

        $channel = AgentRegistry::getChannel($agentId);
        $pinned = AgentRegistry::getPinned($agentId);
        $cache = self::getCachedResults();
        $tags = (array)($cache[$agentId]['dist_tags'] ?? []);
        $resolved = self::resolveChannelTarget($channel, $pinned, $tags);
        $resolved['explicit'] = false;
        return $resolved;
    }

    /** ACTIVITY_TRAY_PLAIN_NOTICES.md rows 14-17: the titles of the one `version_check` tray row. */
    public const TRAY_TITLE_RUNNING = 'Checking for agent updates';
    public const TRAY_TITLE_DONE    = 'Agent updates checked';
    public const TRAY_TITLE_FAILED  = 'Some agents could not be checked';

    /**
     * R-119 (#662): the npm package an agent is installed from, or '' when it is not an npm agent.
     * Reads the source descriptor (type=npm, package) and falls back to the old npm_package field.
     * Pure.
     */
    public static function npmPackageOf($agent): string
    {
        if (!is_array($agent)) return '';
        $d = \AICliAgents\Services\Sources\SourceResolver::descriptor($agent);
        if (!is_array($d) || ($d['type'] ?? '') !== 'npm') return '';
        return trim((string)($d['package'] ?? ''));
    }

    /** The grey line while the check runs: "Claude Code (3 of 14)". Pure. */
    public static function trayAgentStep($agent, string $id, int $no, int $total): string
    {
        $name = is_array($agent) ? trim((string)($agent['name'] ?? '')) : '';
        return ($name !== '' ? $name : $id) . " ($no of $total)";
    }

    /** The grey line of a finished check: "14 agents in 6 s". Pure. */
    public static function trayDoneStep(int $agents, int $seconds): string
    {
        return $agents . ' agent' . ($agents === 1 ? '' : 's') . ' in ' . max(0, $seconds) . ' s';
    }

    /** The red line of a check where some agents did not answer: "3 of 14 agents did not answer". Pure. */
    public static function trayFailedLine(int $errors, int $total): string
    {
        return $errors . ' of ' . $total . ' agent' . ($total === 1 ? '' : 's') . ' did not answer';
    }

    /**
     * The red line of a check that left agents unasked: "8 of 14 agents checked; 6 skipped — run it
     * again". When some agents also gave no answer, that count joins the line. Pure.
     */
    public static function traySkippedLine(int $errors, int $skipped, int $total): string
    {
        $checked = max(0, $total - $skipped - $errors);
        $line = $checked . ' of ' . $total . ' agent' . ($total === 1 ? '' : 's') . ' checked; ' . $skipped . ' skipped';
        if ($errors > 0) $line .= ', ' . $errors . ' did not answer';
        return $line . ' — run it again';
    }

    /**
     * Decide the end row of a check. Pure, so a test needs no npm call.
     * A check that left agents unasked (the total timeout ran out), or that had no
     * agent to ask, is never a plain success: the person would trust old figures.
     * @return array{status:string,title:string,step:string,error:string,recovery:string}
     */
    public static function trayOutcome(int $errors, int $skipped, int $total, int $seconds): array
    {
        $again = 'The cards keep the last good figures. Press "Check for Updates" to run it again.';
        if ($total <= 0) {
            return ['status' => 'failed', 'title' => self::TRAY_TITLE_FAILED, 'step' => '',
                'error' => 'No agents were checked — run it again', 'recovery' => $again];
        }
        if ($skipped > 0) {
            return ['status' => 'failed', 'title' => self::TRAY_TITLE_FAILED, 'step' => '',
                'error' => self::traySkippedLine($errors, $skipped, $total), 'recovery' => $again];
        }
        if ($errors > 0) {
            return ['status' => 'failed', 'title' => self::TRAY_TITLE_FAILED, 'step' => '',
                'error' => self::trayFailedLine($errors, $total),
                'recovery' => 'The cards keep the last good figures. Try again when the network is back.'];
        }
        return ['status' => 'done', 'title' => self::TRAY_TITLE_DONE,
            'step' => self::trayDoneStep($total, $seconds), 'error' => '', 'recovery' => ''];
    }

    /**
     * Close the `version_check` tray row with the outcome (rows 15 and 16). The
     * label is a creation-only default of ActivityService::update(), so the row is
     * registered again under its end-state title. Cancel stays off (R9).
     */
    public static function closeTray(int $errors, int $total, int $seconds, int $skipped = 0): void
    {
        $o = self::trayOutcome($errors, $skipped, $total, $seconds);
        $opts = ['status' => $o['status'], 'progress' => 100, 'step' => $o['step'], 'meta' => ['cancel' => 'none']];
        if ($o['status'] === 'failed') {
            $opts['error']    = $o['error'];
            $opts['recovery'] = $o['recovery'];
        }
        ActivityService::register(self::TRAY_OP_ID, 'system', $o['title'], $opts);
    }

    /** The check code stopped early (the finally block): row 17. */
    public static function closeTrayStoppedEarly(): void
    {
        ActivityService::register(self::TRAY_OP_ID, 'system', self::TRAY_TITLE_FAILED, [
            'status'   => 'failed',
            'progress' => 100,
            'step'     => '',
            'error'    => 'The check stopped before it finished.',
            'recovery' => 'Press "Check for Updates" to run it again.',
            'meta'     => ['cancel' => 'none'],
        ]);
    }

    /**
     * Check if an agent has an update available relative to its selected channel.
     */
    public static function hasUpdate(string $agentId): ?array {
        $versions = AgentRegistry::getVersions();
        $agentVer = $versions[$agentId] ?? null;
        if (!$agentVer) return null;

        // Support both old format (string) and new format (object)
        if (is_string($agentVer)) {
            $installed = $agentVer;
            $channel = 'stable';
            $pinned = null;
        } else {
            $installed = $agentVer['installed'] ?? '0.0.0';
            $channel = AgentRegistry::normalizeChannel((string)($agentVer['channel'] ?? 'stable'));
            $pinned = $agentVer['pinned'] ?? null;
        }

        // Can't determine updates if installed version is unknown
        if ($installed === 'unknown' || $installed === '0.0.0' || $installed === 'installed') return null;

        // Pinned versions suppress update notifications
        if ($pinned !== null) return null;

        $cache = self::getCachedResults();
        $agentCache = $cache[$agentId] ?? null;
        if (!$agentCache || empty($agentCache['dist_tags'])) return null;

        $resolution = self::resolveChannelTarget($channel, $pinned, (array)$agentCache['dist_tags']);
        $channelVersion = $resolution['target'];
        if (!$channelVersion || $resolution['error'] !== null) return null;

        $cmp = version_compare($channelVersion, $installed);
        if ($cmp <= 0) return null;

        return [
            'installed' => $installed,
            'available' => $channelVersion,
            'channel' => $channel,
        ];
    }

    /**
     * Check all agents and post Unraid notifications for available updates.
     * Called by cron script and can be called manually.
     */
    public static function checkAndNotify(): void {
        $cache = self::checkAllAgents(true);
        $registry = AgentRegistry::getRegistry();
        $notifyScript = '/usr/local/emhttp/webGui/scripts/notify';
        if (!file_exists($notifyScript)) return;

        $server = @trim(file_get_contents('/etc/hostname')) ?: 'Unraid';

        foreach ($registry as $id => $agent) {
            if (!($agent['is_installed'] ?? false)) continue;
            $update = self::hasUpdate($id);
            if (!$update) continue;

            $name = $agent['name'] ?? $id;
            $ver = $update['available'];
            $channel = $update['channel'];
            $channelLabel = ($channel !== 'latest') ? " [$channel]" : '';

            $event = "AI Agent Update - $id [$ver]";
            $subject = "Agent Update [$server] - $name $ver$channelLabel available";
            $description = "A new version of $name is available ($ver$channelLabel)";

            exec(escapeshellarg($notifyScript)
                . " -e " . escapeshellarg($event)
                . " -s " . escapeshellarg($subject)
                . " -d " . escapeshellarg($description)
                . " -i " . escapeshellarg("normal")
                . " -l '/Settings/AICliAgentsManager' -x 2>/dev/null");

            LogService::log("Notification posted: $name update $ver available ($channel channel).", LogService::LOG_INFO, "VersionCheck");
        }
    }

    /**
     * Clear update notification for an agent (after install/upgrade).
     */
    public static function clearNotification(string $agentId): void {
        // Remove matching unread notification files
        foreach (glob("/tmp/notifications/unread/*.notify") as $file) {
            $content = @file_get_contents($file);
            if ($content && strpos($content, "AI Agent Update - $agentId") !== false) {
                @unlink($file);
            }
        }
    }

    /**
     * Mark an agent's cache entry as stale (after install/uninstall).
     * Preserves the version list so the dropdown still works — only the
     * checked_at timestamp is zeroed to trigger a background refresh on next page load.
     */
    public static function invalidateAgent(string $agentId): void {
        $cache = self::getCachedResults();
        if (isset($cache[$agentId])) {
            $cache[$agentId]['checked_at'] = 0; // Mark stale, but keep version list
            self::writeCacheAtomic($cache);
        }
    }

    /**
     * Returns true if a check is currently running.
     */
    public static function isCheckRunning(): bool {
        $lockPath = self::lockPath();
        if (!file_exists($lockPath)) return false;
        $content = @file_get_contents($lockPath);
        if (empty($content)) return false;
        [$pid, $ts] = explode(':', $content, 2) + [0, 0];
        return UtilityService::isPidRunning((int)$pid);
    }

    // --- Private helpers ---

    /**
     * Production always uses the shared tmpfs cache. Tests can supply an
     * isolated path so their fixtures never overwrite the Manager's live data.
     */
    private static function cachePath(): string {
        return getenv('AICLI_VERSION_CACHE_FILE') ?: (SecretPaths::stateRoot() . '/version-cache.json');
    }

    private static function lockPath(): string {
        return getenv('AICLI_VERSION_LOCK_FILE') ?: (SecretPaths::stateRoot() . '/.version-check.lock');
    }

    public static function getCachedResults(): array {
        $path = self::cachePath();
        if (!file_exists($path)) return [];
        $data = @json_decode(@file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    public static function isCacheFresh(int $maxAge = 3600): bool {
        if (!file_exists(self::cachePath())) return false;
        $cache = self::getCachedResults();
        if (empty($cache)) return false;
        // Fresh if ANY agent was checked within maxAge
        foreach ($cache as $entry) {
            if (isset($entry['checked_at']) && (time() - $entry['checked_at']) < $maxAge) {
                return true;
            }
        }
        return false;
    }

    private static function writeCacheAtomic(array $data): void {
        $path = self::cachePath();
        $dir = dirname($path);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $tmp = $path . '.tmp.' . getmypid();
        if (@file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT)) !== false) {
            @rename($tmp, $path);
        } else {
            @unlink($tmp);
            LogService::log("Failed to write version cache (disk full?)", LogService::LOG_WARN, "VersionCheck");
        }
    }

    public static function recoverStaleLock(?string $lockPath = null, ?callable $pidRunning = null, ?callable $now = null): bool {
        $lockPath = $lockPath ?? self::lockPath();
        $pidRunning = $pidRunning ?? [UtilityService::class, 'isPidRunning'];
        $now = $now ?? static fn(): int => time();
        if (!file_exists($lockPath)) return false;
        $content = @file_get_contents($lockPath);
        if (empty($content)) { @unlink($lockPath); return true; }

        $parts = explode(':', $content, 2);
        // explode always returns a non-empty list for a non-empty input, so
        // $parts[0] is defined. $parts[1] may be missing for a 1-field input.
        $pid = (int)$parts[0];
        $ts = (int)($parts[1] ?? 0);

        // Stale if PID is dead or lock is older than threshold
        $pidDead = ($pid > 0 && !$pidRunning($pid));
        $tooOld = ($ts > 0 && ($now() - $ts) > self::LOCK_MAX_AGE);

        if ($pidDead || $tooOld) {
            LogService::log("Recovering stale version check lock (PID=$pid, age=" . ($now() - $ts) . "s).", LogService::LOG_WARN, "VersionCheck");
            @unlink($lockPath);
            return true;
        }
        return false;
    }
}
