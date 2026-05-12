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
    const CACHE_FILE = '/tmp/unraid-aicliagents/version-cache.json';
    const LOCK_FILE  = '/tmp/unraid-aicliagents/.version-check.lock';
    const LOCK_MAX_AGE = 600; // 10 minutes — stale lock threshold
    const PER_AGENT_TIMEOUT = 15; // seconds per npm view call
    const TOTAL_TIMEOUT = 120; // seconds total for all agents
    const MAX_DROPDOWN_ENTRIES = 20;

    /**
     * Check all installed agents for available versions.
     * Returns cached results if lock is held by another process.
     * @param bool $force Skip cache freshness check
     * @return array Version data keyed by agent ID
     */
    public static function checkAllAgents(bool $force = false): array {
        $config = ConfigService::getConfig();
        $maxAge = 3600; // 1 hour default TTL

        // Return cache if fresh and not forced
        if (!$force && self::isCacheFresh($maxAge)) {
            return self::getCachedResults();
        }

        // Acquire lock (non-blocking)
        self::recoverStaleLock();
        $lockDir = dirname(self::LOCK_FILE);
        if (!is_dir($lockDir)) @mkdir($lockDir, 0755, true);

        $fp = @fopen(self::LOCK_FILE, 'c+');
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

        $startTime = time();
        LogService::log("Starting version check for all agents...", LogService::LOG_INFO, "VersionCheck");

        try {
            $registry = AgentRegistry::getRegistry();
            $pluginDir = "/usr/local/emhttp/plugins/unraid-aicliagents";
            $cache = self::getCachedResults();
            $updated = false;

            foreach ($registry as $id => $agent) {
                // Total timeout guard
                if ((time() - $startTime) > self::TOTAL_TIMEOUT) {
                    LogService::log("Version check total timeout reached ({self::TOTAL_TIMEOUT}s). Remaining agents skipped.", LogService::LOG_WARN, "VersionCheck");
                    break;
                }

                $pkg = $agent['npm_package'] ?? '';
                if (empty($pkg) || $id === 'terminal') continue;

                LogService::log("Checking versions for $id ($pkg)...", LogService::LOG_DEBUG, "VersionCheck");

                $cmd = "export PATH=$pluginDir/bin:\$PATH; timeout " . self::PER_AGENT_TIMEOUT
                     . " npm view " . escapeshellarg($pkg) . " --json 2>/dev/null";
                $output = shell_exec($cmd);

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
                $distTags = $data['dist-tags'] ?? [];

                // Extract versions with timestamps
                $allVersions = $data['versions'] ?? [];
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
                if (!empty($agent['npm_package'])) continue;  // npm already handled above
                if ((time() - $startTime) > self::TOTAL_TIMEOUT) break;

                $source = \AICliAgents\Services\Sources\SourceResolver::resolve($agent);
                if ($source === null || !method_exists($source, 'populateCache')) continue;

                LogService::log("Populating cache for $id (non-npm source)...", LogService::LOG_DEBUG, "VersionCheck");
                try {
                    $entry = $source->populateCache($id, $agent);
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
            return $cache;

        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
            @unlink(self::LOCK_FILE);
        }
    }

    /**
     * Get filtered version list for dropdown display.
     * @param string      $agentId
     * @param string|null $channel  'latest' (default — stable) or 'beta'. Other values map to 'latest'.
     * @param int         $monthsBack  How far back to include untagged stable releases.
     * @return array Sorted versions for the dropdown (max MAX_DROPDOWN_ENTRIES)
     */
    public static function getAvailableVersions(string $agentId, ?string $channel = null, int $monthsBack = 3): array {
        $cache = self::getCachedResults();
        $agentCache = $cache[$agentId] ?? null;
        if (!$agentCache || empty($agentCache['versions'])) return [];

        $channel = ($channel === 'beta') ? 'beta' : 'latest';
        $cutoff   = strtotime("-{$monthsBack} months");
        $distTags = $agentCache['dist_tags'] ?? [];
        $versions = $agentCache['versions'];

        $result = [];

        foreach (array_reverse($versions) as $entry) { // newest first (npm returns ascending)
            $class = VersionClassifier::classify($entry, $distTags, $agentId);

            if ($class === 'platform_variant') continue;

            if ($channel === 'beta') {
                if ($class !== 'prerelease') continue;
                $result[] = $entry;
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

        return array_slice($result, 0, self::MAX_DROPDOWN_ENTRIES);
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
            $channel = 'latest';
            $pinned = null;
        } else {
            $installed = $agentVer['installed'] ?? '0.0.0';
            $channel = $agentVer['channel'] ?? 'latest';
            $pinned = $agentVer['pinned'] ?? null;
        }

        // Can't determine updates if installed version is unknown
        if ($installed === 'unknown' || $installed === '0.0.0' || $installed === 'installed') return null;

        // Pinned versions suppress update notifications
        if ($pinned !== null) return null;

        $cache = self::getCachedResults();
        $agentCache = $cache[$agentId] ?? null;
        if (!$agentCache || empty($agentCache['dist_tags'])) return null;

        $channelVersion = $agentCache['dist_tags'][$channel] ?? null;
        if (!$channelVersion) return null;

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
        if (!file_exists(self::LOCK_FILE)) return false;
        $content = @file_get_contents(self::LOCK_FILE);
        if (empty($content)) return false;
        [$pid, $ts] = explode(':', $content, 2) + [0, 0];
        return UtilityService::isPidRunning((int)$pid);
    }

    // --- Private helpers ---

    public static function getCachedResults(): array {
        if (!file_exists(self::CACHE_FILE)) return [];
        $data = @json_decode(@file_get_contents(self::CACHE_FILE), true);
        return is_array($data) ? $data : [];
    }

    public static function isCacheFresh(int $maxAge = 3600): bool {
        if (!file_exists(self::CACHE_FILE)) return false;
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
        $dir = dirname(self::CACHE_FILE);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $tmp = self::CACHE_FILE . '.tmp.' . getmypid();
        if (@file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT)) !== false) {
            @rename($tmp, self::CACHE_FILE);
        } else {
            @unlink($tmp);
            LogService::log("Failed to write version cache (disk full?)", LogService::LOG_WARN, "VersionCheck");
        }
    }

    private static function recoverStaleLock(): void {
        if (!file_exists(self::LOCK_FILE)) return;
        $content = @file_get_contents(self::LOCK_FILE);
        if (empty($content)) { @unlink(self::LOCK_FILE); return; }

        $parts = explode(':', $content, 2);
        // explode always returns a non-empty list for a non-empty input, so
        // $parts[0] is defined. $parts[1] may be missing for a 1-field input.
        $pid = (int)$parts[0];
        $ts = (int)($parts[1] ?? 0);

        // Stale if PID is dead or lock is older than threshold
        $pidDead = ($pid > 0 && !UtilityService::isPidRunning($pid));
        $tooOld = ($ts > 0 && (time() - $ts) > self::LOCK_MAX_AGE);

        if ($pidDead || $tooOld) {
            LogService::log("Recovering stale version check lock (PID=$pid, age=" . (time() - $ts) . "s).", LogService::LOG_WARN, "VersionCheck");
            @unlink(self::LOCK_FILE);
        }
    }
}
