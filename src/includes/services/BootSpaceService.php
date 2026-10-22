<?php
/**
 * <module_context>
 * Description: The Boot disk space breakdown (docs/specs/BOOT_DISK_SPACE.md R1, #538).
 *   It measures where the space of the boot disk goes and writes the answer to
 *   /tmp/unraid-aicliagents/.boot_space.json. The Storage tab card shows that file.
 *   Sizes are allocated bytes (du -x -s -B1), so compression is counted.
 *   A measure runs in the background (src/scripts/storage/boot-space.php), never in a page
 *   request. get_boot_space reads the file and starts a new measure when the file is older
 *   than 10 minutes or missing. removeLeftover() removes one item of the "Left over" line.
 *   The file also holds what the action dialogs show (#552): the agents with their sizes, the
 *   old versions, the convert estimate, the conversation history of each plain home and the
 *   cache status of each plain home. A dialog reads these from the file and never measures.
 *   One run has one overall deadline (MeasureDeadline, 300 s). When it passes, the run writes
 *   what it has with "partial": true.
 * Dependencies: ConfigService, AgentStorageService, AgentVersionCleanupService, HomeCacheService,
 *   HistoryStoreService, HistoryMoveService, FileStorage (home layers), StoragePathResolver, UtilityService.
 * Constraints: Never measures in the page request. The read functions (snapshot, cachedOldVersions,
 *   cachedConvert, cachedHistory, cachedCache) read the file only: no du, no find, no tree walk. removeLeftover() removes only items that the
 *   last measurement listed under "Left over", re-checks right before it removes, and never
 *   touches a path outside the persistence folder. Every seam is optional; production code
 *   passes none.
 * </module_context>
 */
namespace AICliAgents\Services;

require_once __DIR__ . '/HistoryStoreService.php';
require_once __DIR__ . '/HistoryMoveService.php';
require_once __DIR__ . '/MeasureDeadline.php';

class BootSpaceService
{
    /** A measurement older than this (seconds) is replaced by a new one. */
    public const MAX_AGE = 600;
    /** A lock file younger than this (seconds) means a measure is running. */
    public const LOCK_TTL = 900;
    /** The longest one du call may run (seconds). */
    private const DU_TIMEOUT = 120;
    /** Same patterns as _smoke_is_test_id in tests/smoke/_common.sh. */
    public const TEST_ID_PATTERNS = ['smktest_*', 'smoketest_*', 'smokeuser*', 'smoke*', 'it[0-9]*', 'soak*', 'realuser*[0-9]*'];

    // ---- places ------------------------------------------------------------------

    public static function stateFile(): string
    {
        $e = getenv('AICLI_BOOT_SPACE_FILE');
        return ($e !== false && $e !== '') ? $e : SecretPaths::stateRoot() . '/.boot_space.json';
    }

    private static function lockFile(): string
    {
        return self::stateFile() . '.lock';
    }

    public static function persistPath(): string
    {
        $e = getenv('AICLI_BOOT_SPACE_PERSIST');
        if ($e !== false && $e !== '') return rtrim($e, '/');
        return rtrim(AgentStorageService::persistPath(), '/');
    }

    private static function retiredListPath(): string
    {
        $e = getenv('AICLI_RETIRED_LIST');
        return ($e !== false && $e !== '') ? $e : SecretPaths::flashRoot() . '/retired_dirs.list';
    }

    /** The plugin's folder on the boot disk. */
    public static function pluginDir(): string
    {
        $e = getenv('AICLI_BOOT_SPACE_PLUGIN_DIR');
        return ($e !== false && $e !== '') ? rtrim($e, '/') : SecretPaths::flashRoot();
    }

    // ---- pure helpers -------------------------------------------------------------

    /** True for the ids that the smoke tests create. */
    public static function isTestId(string $id): bool
    {
        if ($id === '' || strpbrk($id, '/') !== false || $id[0] === '.') return false;
        foreach (self::TEST_ID_PATTERNS as $p) {
            if (fnmatch($p, $id)) return true;
        }
        return false;
    }

    /** True when $path is $root or lies inside it (lexical check; no `..` part allowed). */
    public static function isInside(string $path, string $root): bool
    {
        $root = rtrim($root, '/');
        if ($root === '' || $path === '' || $path[0] !== '/') return false;
        if (preg_match('#(^|/)\.\.(/|$)#', $path) === 1) return false;
        return $path === $root || strpos($path, $root . '/') === 0;
    }

    /** Sort parts: biggest first. Equal sizes keep their order. */
    public static function sortParts(array $parts): array
    {
        $idx = 0;
        foreach ($parts as &$p) { $p['_i'] = $idx++; }
        unset($p);
        usort($parts, static function ($a, $b) {
            return ($b['bytes'] <=> $a['bytes']) ?: ($a['_i'] <=> $b['_i']);
        });
        foreach ($parts as &$p) { unset($p['_i']); }
        unset($p);
        return $parts;
    }

    private static function action(string $id, string $label, bool $enabled = true, string $reason = ''): array
    {
        return ['id' => $id, 'label' => $label, 'enabled' => $enabled, 'reason' => $enabled ? '' : $reason];
    }

    /** Plain reason texts for a convert offer that is not made. */
    public static function convertReason(string $reason): string
    {
        $map = [
            'usb_boot' => 'Agents on a USB boot stick keep compressed layers.',
            'keep_agents_compressed' => 'Agent storage is set to SquashFS layers (Configuration tab).',
            'no_layered_agents' => 'Every agent already uses plain folders.',
            'not_boot_disk' => 'The agents are not on the boot disk.',
        ];
        return $map[$reason] ?? 'Not offered on this system.';
    }

    // ---- measuring -----------------------------------------------------------------

    /**
     * Allocated bytes of a file or folder (du -x -s -B1). 0 when it is missing, du fails or the
     * shared deadline has passed. BACKGROUND MEASUREMENT ONLY (#552): no web request calls it.
     * The command runs at the lowest CPU priority and in the best-effort I/O class at level 7.
     * (The idle I/O class is not used: on a busy disk it can starve for ever and hold the lock.)
     * The timeout is the smaller of DU_TIMEOUT and the time left on the deadline.
     */
    public static function duBytes(string $path): int
    {
        if (!file_exists($path) && !is_link($path)) return 0;
        $t = MeasureDeadline::remaining(self::DU_TIMEOUT);
        if ($t < 1) { MeasureDeadline::markPartial(); return 0; }
        $out = (string)@shell_exec('timeout ' . $t . ' ' . self::lowPriorityPrefix() . 'du -x -s -B1 -- ' . escapeshellarg($path) . ' 2>/dev/null');
        return preg_match('/^(\d+)\s/', $out, $m) === 1 ? (int)$m[1] : 0;
    }

    /** "nice -n 19 ionice -c2 -n7 " (ionice only when the host has it). */
    public static function lowPriorityPrefix(): string
    {
        return 'nice -n 19 ' . (self::ioniceBinary() !== '' ? 'ionice -c2 -n7 ' : '');
    }

    private static function ioniceBinary(): string
    {
        foreach (['/usr/bin/ionice', '/bin/ionice', '/usr/sbin/ionice', '/sbin/ionice'] as $b) {
            if (is_executable($b)) return $b;
        }
        return '';
    }

    /** Size, use, free space, mount and file system of the disk that holds $path. */
    public static function diskInfo(string $path): array
    {
        $size = (int)@disk_total_space($path);
        $free = (int)@disk_free_space($path);
        $mount = ''; $fstype = '';
        $best = -1;
        foreach (preg_split('/\R/', (string)@file_get_contents('/proc/mounts')) ?: [] as $line) {
            $f = preg_split('/\s+/', $line);
            if (count($f) < 3) continue;
            $mp = str_replace('\040', ' ', $f[1]);
            if ($mp === '/' || $path === $mp || strpos($path, rtrim($mp, '/') . '/') === 0) {
                if (strlen($mp) > $best) { $best = strlen($mp); $mount = $mp; $fstype = $f[2]; }
            }
        }
        $ratio = 1.0;
        try { $ratio = (float)AgentStorageService::compressRatio($path); } catch (\Throwable $e) { /* keep 1.0 */ }
        $used = max(0, $size - $free);
        return ['size' => $size, 'used' => $used, 'free' => $free, 'mount' => $mount, 'fstype' => $fstype,
                'ratio' => $size > 0 ? round($used / $size, 4) : 0.0, 'compress_ratio' => $ratio];
    }

    /**
     * R-117 (#662): ids that are real homes even when the name fits a smoke-test pattern: the
     * configured user and every Unraid user (/etc/passwd, uid 1000 and up). Seam: unraid_users.
     * @return string[]
     */
    public static function protectedHomeIds(string $configUser, array $seams = []): array
    {
        $ids = [$configUser];
        if (isset($seams['unraid_users'])) {
            foreach ($seams['unraid_users'] as $u) $ids[] = (string)$u;
        } else {
            foreach (preg_split('/\R/', (string)@file_get_contents('/etc/passwd')) ?: [] as $line) {
                $f = explode(':', $line);
                if (count($f) >= 3 && $f[0] !== '' && (int)$f[2] >= 1000 && (int)$f[2] < 60000) $ids[] = $f[0];
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * Where the data sits: the homes, and the left-over items.
     * @return array{layered:string[],plain:string[],unused_layers:string[]}
     */
    public static function discoverHomes(string $persist, string $configUser, array $protected = []): array
    {
        $layered = [];
        foreach (glob($persist . '/home_*.sqsh') ?: [] as $f) {
            if (preg_match('/^home_([A-Za-z0-9._-]+?)_(?:delta|consolidated|v\d+_vol\d+|vol\d+)/', basename($f), $m) === 1) {
                $layered[$m[1]] = true;
            }
        }
        $plain = [];
        foreach (glob($persist . '/passthrough/homes/*', GLOB_ONLYDIR | GLOB_NOSORT) ?: [] as $d) {
            $id = basename($d);
            if ((self::isTestId($id) && !in_array($id, array_merge($protected, [$configUser]), true)) || $id[0] === '.') continue;
            $plain[$id] = true;
        }
        // The home of the configured user is always in use.
        $inUse = $plain + [$configUser => true];
        $usedLayers = []; $unused = [];
        foreach (array_keys($layered) as $u) {
            if (isset($inUse[$u])) $usedLayers[] = $u; else $unused[] = $u;
        }
        sort($usedLayers); sort($unused);
        // A user with layers AND a plain folder counts as plain (the layers wait in .graduated/ or are old).
        $plainUsers = array_keys($plain);
        sort($plainUsers);
        return ['layered' => array_values(array_diff($usedLayers, $plainUsers)), 'plain' => $plainUsers, 'unused_layers' => $unused];
    }

    /**
     * The "Left over" items: [{key,label,bytes,path}]. Most paths are inside $persist.
     * Keys: graduated:<name>, retired:<hash>, homelayers:<user>, testhome:<id>, unfinished:<type>_<id>, backendarchive:<name>.
     */
    public static function leftOverItems(string $persist, array $homes, callable $du, ?string $retiredList = null): array
    {
        $items = [];
        $protectedRetirePaths = self::openConversionRetirePaths($persist);
        foreach (glob($persist . '/.graduated/*', GLOB_NOSORT) ?: [] as $p) {
            $name = basename($p);
            if (preg_match('/^[A-Za-z0-9._-]{1,128}$/D', $name) !== 1) continue;
            $item = ['key' => 'graduated:' . $name, 'label' => 'Old layers of a converted home or agent (' . $name . ')',
                     'bytes' => $du($p), 'path' => $p];
            $items[] = self::protectOpenConversionItem($item, $protectedRetirePaths);
        }
        foreach (glob($persist . '/.graduate_staging_*', GLOB_NOSORT) ?: [] as $marker) {
            if (is_link($marker) || !is_file($marker)) continue;
            $markerName = substr(basename($marker), strlen('.graduate_staging_'));
            $path = self::unfinishedCopyPath($persist, $markerName, $marker);
            if ($path === null) continue;
            $items[] = ['key' => 'unfinished:' . $markerName,
                        'label' => 'Unfinished conversion copy (' . basename($path) . ')',
                        'bytes' => $du($path), 'path' => $path];
        }
        $archiveRoot = rtrim($persist, '/') . '/.backend-migration-archive';
        foreach (glob($archiveRoot . '/*', GLOB_ONLYDIR | GLOB_NOSORT) ?: [] as $archive) {
            if (is_link($archive)) continue;
            $name = basename($archive);
            if (preg_match('/^(\d{8}T\d{6}Z)_(agent|home)_([A-Za-z0-9._-]{1,128})$/D', $name, $match) !== 1) continue;
            $archivedAt = \DateTimeImmutable::createFromFormat('!Ymd\\THis\\Z', $match[1], new \DateTimeZone('UTC'));
            if ($archivedAt === false) continue;
            $age = max(0, time() - $archivedAt->getTimestamp());
            $ageText = self::archiveAgeText($age);
            $locked = self::backendArchiveHasOpenIntent($persist, $name);
            $label = 'Archived copy from an engine change (' . $match[2] . '/' . $match[3] . ') — ' . $ageText;
            if ($locked) $label .= ' — needed to finish or put back a conversion';
            $items[] = [
                'key' => 'backendarchive:' . $name,
                'label' => $label,
                'bytes' => $du($archive),
                'path' => $archive,
                'locked' => $locked,
                'age_seconds' => $age,
            ];
        }
        $list = $retiredList ?? self::retiredListPath();
        $seen = [];
        if (is_file($list)) {
            foreach (preg_split('/\R/', (string)@file_get_contents($list)) ?: [] as $d) {
                $d = trim($d);
                if ($d === '' || isset($seen[$d]) || !self::isInside($d, $persist) || !is_dir($d)) continue;
                $seen[$d] = true;
                $item = ['key' => 'retired:' . substr(sha1($d), 0, 12),
                         'label' => 'Retired layers (' . basename($d) . ')', 'bytes' => $du($d), 'path' => $d];
                $items[] = self::protectOpenConversionItem($item, $protectedRetirePaths);
            }
        }
        foreach ($homes['unused_layers'] as $u) {
            $files = glob($persist . '/home_' . $u . '_*.sqsh') ?: [];
            $b = 0; foreach ($files as $f) $b += $du($f);
            $items[] = ['key' => 'homelayers:' . $u, 'label' => 'Saved layers of the home "' . $u . '" (no workspace uses it)',
                        'bytes' => $b, 'path' => $persist . '/home_' . $u . '_*.sqsh'];
        }
        foreach (glob($persist . '/passthrough/homes/*', GLOB_ONLYDIR | GLOB_NOSORT) ?: [] as $d) {
            $id = basename($d);
            // A name that fits a test pattern but is a real home (listed in 'plain') is never offered.
            if (!self::isTestId($id) || in_array($id, $homes['plain'], true)) continue;
            $items[] = ['key' => 'testhome:' . $id, 'label' => 'Test home ' . $id, 'bytes' => $du($d), 'path' => $d];
        }
        return $items;
    }

    /**
     * The rollback copy of a converted home (docs/specs/CONVERT_LAYERED_TO_PLAIN_FOLDERS.md R22).
     * Convert to plain folders moves the old layers to <persist>/.graduated/home_<user>. The home
     * card shows them while they exist. Returns null when the home has no such copy, or when an
     * open conversion still needs it.
     *
     * key: the item key of the "Left over" line (graduated:<folder name>), so the card and that
     * line remove the copy through the same action (remove_leftover).
     * expires_at: the supervisor removes an entry when `find -mtime +N` matches. That means an age
     * of N+1 whole days, so the date is the folder's change time plus N+1 days.
     * The size comes from one bounded `du` (a status request must stay short).
     *
     * @param array{du?:callable,locked?:list<string>} $seams tests
     * @return array{key:string,bytes:int,expires_at:int}|null
     */
    public static function graduatedCopyForHome(string $persist, string $user, int $retentionDays, array $seams = []): ?array
    {
        $persist = rtrim($persist, '/');
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $user) ?? $user;
        if ($safe === '' || !is_dir($persist . '/.graduated')) return null;
        // A second conversion inside the window uses "home_<user>.<timestamp>" (storage_ops.sh).
        $best = null; $bestTime = -1;
        foreach (array_merge(glob($persist . '/.graduated/home_' . $safe, GLOB_NOSORT) ?: [],
                             glob($persist . '/.graduated/home_' . $safe . '.*', GLOB_NOSORT) ?: []) as $p) {
            if (is_link($p) || !is_dir($p)) continue;
            if (preg_match('/^[A-Za-z0-9._-]{1,128}$/D', basename($p)) !== 1) continue;
            $t = (int)@filemtime($p);
            if ($t > $bestTime) { $best = $p; $bestTime = $t; }
        }
        if ($best === null) return null;
        $locked = $seams['locked'] ?? self::openConversionRetirePaths($persist);
        if (in_array(self::normalizePathForMatch($best), $locked, true)) return null;
        if ($retentionDays < 0) $retentionDays = 14;
        if (isset($seams['du'])) {
            $bytes = (int)($seams['du'])($best);
        } else {
            $out = (string)@shell_exec('timeout 3 du -x -s -B1 -- ' . escapeshellarg($best) . ' 2>/dev/null');
            $bytes = preg_match('/^(\d+)\s/', $out, $m) === 1 ? (int)$m[1] : 0;
        }
        return ['key' => 'graduated:' . basename($best), 'bytes' => $bytes,
                'expires_at' => $bestTime + ($retentionDays + 1) * 86400];
    }

    private static function archiveAgeText(int $ageSeconds): string
    {
        if ($ageSeconds < 60) return 'less than a minute old';
        if ($ageSeconds < 3600) {
            $minutes = intdiv($ageSeconds, 60);
            return $minutes . ' ' . ($minutes === 1 ? 'minute' : 'minutes') . ' old';
        }
        if ($ageSeconds < 86400) {
            $hours = intdiv($ageSeconds, 3600);
            return $hours . ' ' . ($hours === 1 ? 'hour' : 'hours') . ' old';
        }
        $days = intdiv($ageSeconds, 86400);
        return $days . ' ' . ($days === 1 ? 'day' : 'days') . ' old';
    }

    private static function backendArchiveHasOpenIntent(string $persist, string $name): bool
    {
        if (preg_match('/^\d{8}T\d{6}Z_(agent|home)_([A-Za-z0-9._-]{1,128})$/D', $name, $match) !== 1) return true;
        // Intent names use the original validated entity id, including dots.
        $intent = rtrim($persist, '/') . '/.aicli-intent-' . $match[1] . '-' . $match[2] . '.json';
        return is_file($intent) || is_link($intent);
    }

    /** @return list<string> normalized paths that an open conversion intent still needs */
    private static function openConversionRetirePaths(string $persist): array
    {
        $paths = [];
        foreach (glob(rtrim($persist, '/') . '/.aicli-intent-*.json', GLOB_NOSORT) ?: [] as $intentPath) {
            if (is_link($intentPath) || !is_file($intentPath)) continue;
            if (preg_match('/^\.aicli-intent-(home|agent)-([A-Za-z0-9._-]{1,128})\.json$/D', basename($intentPath), $match) !== 1) {
                continue;
            }

            $type = $match[1];
            $id = $match[2];
            $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '_', $id) ?? $id;
            $paths[] = rtrim($persist, '/') . '/.graduated/' . $type . '_' . $safeId;

            $intent = json_decode((string)@file_get_contents($intentPath), true);
            $retire = is_array($intent) ? ($intent['retire'] ?? null) : null;
            if (is_string($retire) && $retire !== '') $paths[] = $retire;
        }

        return array_values(array_unique(array_map([self::class, 'normalizePathForMatch'], $paths)));
    }

    /** Add a visible lock when an open conversion still needs this retired folder. */
    private static function protectOpenConversionItem(array $item, array $protectedPaths): array
    {
        if (in_array(self::normalizePathForMatch((string)$item['path']), $protectedPaths, true)) {
            $item['locked'] = true;
            $item['label'] .= ' — needed to finish or roll back a conversion';
        }
        return $item;
    }

    private static function normalizePathForMatch(string $path): string
    {
        $real = realpath($path);
        return rtrim($real === false ? $path : $real, '/') ?: '/';
    }

    /** Return a marked partial copy only when its path has a plugin-owned shape. */
    private static function unfinishedCopyPath(string $persist, string $markerName, string $marker): ?string
    {
        if (preg_match('/^(home|agent)_([A-Za-z0-9_-]{1,128})$/D', $markerName, $m) !== 1) return null;
        $type = $m[1];
        $id = $m[2];
        $lines = preg_split('/\R/', (string)@file_get_contents($marker)) ?: [];
        $path = trim((string)($lines[0] ?? ''));
        if ($path === '' || $path[0] !== '/') {
            foreach ($lines as $line) {
                $line = trim((string)$line);
                if (strpos($line, 'copy=') === 0) {
                    $path = substr($line, strlen('copy='));
                    break;
                }
            }
        }
        if ($path === '' || $path[0] !== '/' || strpos($path, "\0") !== false || is_link($path) || !is_dir($path)) return null;
        if ($path === rtrim($persist, '/') . '/passthrough/' . $type . 's/' . $id) return $path;

        // Staged copies use the configured work folder or the standard pool path.
        // Resolve the parent so a symlink cannot redirect the removal elsewhere.
        $parent = realpath(dirname($path));
        if ($parent === false || is_link(dirname($path))) return null;
        if ($type === 'agent') {
            $versionRoot = rtrim($persist, '/') . '/agents/.versions';
            $versionParent = $versionRoot . '/' . $id;
            if (!is_link(rtrim($persist, '/') . '/agents') && !is_link($versionRoot) && !is_link($versionParent)
                && realpath($versionParent) === $parent
                && preg_match('/^[A-Za-z0-9_-]{1,128}$/D', basename($path)) === 1) {
                return $path;
            }
        }
        if (basename($path) !== $type . '_' . $id) return null;
        $config = ConfigService::getConfig();
        $stageRoot = rtrim((string)($config['consolidate_stage_path'] ?? ''), '/');
        if ($stageRoot !== '') {
            $configuredCopyRoot = realpath($stageRoot . '/copy');
            if ($configuredCopyRoot !== false && $parent === $configuredCopyRoot) return $path;
        }
        if (preg_match('#^/mnt/[A-Za-z0-9._-]+/aicliagents/work/copy$#D', $parent) === 1) return $path;
        return null;
    }

    /** Ages (days) of the history figures that the Move old sessions dialog reads from the file. */
    public const HISTORY_AGES = [30, 90, 180];

    /**
     * Measure everything. Slow: du on several GB. Run it in the background only.
     * $seams (tests): persist, home_persist, plugin_dir, du (callable path→bytes), config_user, disk (array),
     * agent_sizes (array), old_versions (array), convert (array), cache_status (callable user→array),
     * history (callable homeDir→array), moved (callable user→array), retired_list, now (int),
     * deadline_at (Unix time; the measure then runs under its own deadline).
     *
     * One deadline covers all parts (MeasureDeadline). When it passes, the parts that are not
     * measured yet are skipped and the answer carries "partial": true.
     */
    public static function measure(array $seams = []): array
    {
        $ownDeadline = false;
        if (isset($seams['deadline_at'])) {
            MeasureDeadline::start((int)$seams['deadline_at']);
            $ownDeadline = true;
        }
        try {
            return self::measureParts($seams);
        } finally {
            if ($ownDeadline) MeasureDeadline::clear();
        }
    }

    /**
     * True (and the answer becomes partial) when the shared deadline has passed. The answer
     * changes with the clock, so two calls in a row can differ.
     *
     * @phpstan-impure
     */
    private static function outOfTime(): bool
    {
        if (MeasureDeadline::expired()) { MeasureDeadline::markPartial(); return true; }
        return false;
    }

    private static function measureParts(array $seams): array
    {
        $persist = rtrim((string)($seams['persist'] ?? self::persistPath()), '/');
        $homePersist = rtrim((string)($seams['home_persist'] ?? (isset($seams['persist'])
            ? $persist
            : StoragePathResolver::homePersistPathFromConfig(ConfigService::getConfig()))), '/');
        if ($homePersist === '') $homePersist = $persist;
        $pluginDir = rtrim((string)($seams['plugin_dir'] ?? self::pluginDir()), '/');
        $du = $seams['du'] ?? [self::class, 'duBytes'];
        $now = (int)($seams['now'] ?? time());
        $configUser = (string)($seams['config_user'] ?? (ConfigService::getConfig()['user'] ?? 'root'));
        if ($configUser === '') $configUser = 'root';

        $disk = $seams['disk'] ?? self::diskInfo($persist);
        $homes = self::discoverHomes($homePersist, $configUser, self::protectedHomeIds($configUser, $seams));
        $parts = [];
        $insideTotal = 0;   // bytes of the parts that lie inside the plugin folder
        $history = [];      // user => agentId => figures (the Move old sessions dialog)
        $caches = [];       // user => cache status (the Clear caches dialog)
        $agentRows = []; $oldVersions = null; $convert = null;
        $complete = true;   // false as soon as a part is skipped because the time is gone

        // ---- homes ----
        foreach ($homes['layered'] as $u) {
            if (self::outOfTime()) { $complete = false; break; }
            $files = glob($homePersist . '/home_' . $u . '_*.sqsh') ?: [];
            $b = 0; foreach ($files as $f) $b += (int)$du($f);
            $b += (int)$du($homePersist . '/_upper/homes/' . $u);
            if (self::isInside($homePersist, $pluginDir)) $insideTotal += $b;
            $parts[] = ['key' => 'home:' . $u . ':layers', 'group' => 'home', 'user' => $u,
                'label' => 'Home ' . $u . ': saved layers', 'bytes' => $b,
                'note' => 'Saved layers and the writable layer of the home.',
                'action' => self::action('tidy_up', 'Close all and tidy up')];
        }
        foreach ($homes['plain'] as $u) {
            if (!$complete || self::outOfTime()) { $complete = false; break; }
            $home = $homePersist . '/passthrough/homes/' . $u;
            $total = (int)$du($home);
            $histBytes = 0;
            try {
                $m = isset($seams['history'])
                    ? ($seams['history'])($home)
                    : HistoryStoreService::measure($home, self::HISTORY_AGES, null, MeasureDeadline::remaining(120));
                foreach ($m as $row) $histBytes += (int)($row['bytes'] ?? 0);
                $history[$u] = $m;
            } catch (\Throwable $e) { $histBytes = 0; }
            $cache = ['location' => 'none', 'on_boot_bytes' => 0, 'bytes' => 0];
            try {
                $cache = isset($seams['cache_status']) ? ($seams['cache_status'])($u) : HomeCacheService::status($u);
            } catch (\Throwable $e) { /* keep the empty answer */ }
            $caches[$u] = $cache;
            $cacheBoot = (int)($cache['on_boot_bytes'] ?? 0);
            if (($cache['location'] ?? '') === 'boot') $cacheBoot = max($cacheBoot, (int)($cache['bytes'] ?? 0));
            $histBytes = min($histBytes, $total);
            $cacheBoot = min($cacheBoot, max(0, $total - $histBytes));
            $other = max(0, $total - $histBytes - $cacheBoot);
            if (self::isInside($homePersist, $pluginDir)) $insideTotal += $total;

            $hist = ['key' => 'home:' . $u . ':history', 'group' => 'home', 'user' => $u,
                'label' => 'Home ' . $u . ': conversation history', 'bytes' => $histBytes,
                'action' => self::action('move_sessions', 'Move old sessions…')];
            try {
                $dest = (string)(ConfigService::getConfig()['history_move_path'] ?? '');
                $moved = isset($seams['moved']) ? ($seams['moved'])($u)
                    : ($dest !== '' && HistoryMoveService::destinationMounted($dest) ? HistoryMoveService::movedSummary($dest, $u) : []);
                if ($moved) $hist['extra_actions'] = [self::action('bring_back_sessions', 'Bring back…')];
            } catch (\Throwable $e) { /* no extra action */ }
            $parts[] = $hist;

            $loc = (string)($cache['location'] ?? 'none');
            $cacheNote = '';
            if ($loc === 'ram') $cacheNote = 'The cache lives in RAM (' . self::human((int)($cache['bytes'] ?? 0)) . ' used now). Only content still on the boot disk is counted here.';
            elseif ($loc === 'pool') $cacheNote = 'The cache lives on a pool (' . self::human((int)($cache['bytes'] ?? 0)) . ' used now). Only content still on the boot disk is counted here.';
            $cacheAction = $loc === 'none'
                ? self::action('clear_caches', 'Clear caches', false, 'This home does not use plain folders.')
                : self::action('clear_caches', 'Clear caches');
            $parts[] = ['key' => 'home:' . $u . ':caches', 'group' => 'home', 'user' => $u,
                'label' => 'Home ' . $u . ': caches', 'bytes' => $cacheBoot, 'note' => $cacheNote,
                'cache_location' => $loc, 'action' => $cacheAction, 'setting_link' => 'home_cache_location'];
            $parts[] = ['key' => 'home:' . $u . ':other', 'group' => 'home', 'user' => $u,
                'label' => 'Home ' . $u . ': other', 'bytes' => $other,
                'note' => 'Your own files and agent settings.', 'action' => null];
        }

        // ---- agents ----
        if ($complete && !self::outOfTime()) {
            $sizes = $seams['agent_sizes'] ?? AgentStorageService::agentSizes($persist);
            $agentRows = array_values($sizes);
            $cur = 0;
            foreach ($sizes as $s) $cur += (int)($s['current_bytes'] ?? 0);
            $old = $seams['old_versions'] ?? (self::outOfTime() ? null : AgentVersionCleanupService::oldVersions(null));
            if ($old === null) { $complete = false; $old = ['items' => [], 'skipped' => [], 'total_bytes' => 0]; }
            else $oldVersions = $old;
            $oldBytes = (int)($old['total_bytes'] ?? 0);
            $est = $seams['convert'] ?? (self::outOfTime() ? null
                : AgentStorageService::convertEstimate(['persist' => $persist, 'agent_sizes' => $sizes]));
            if ($est === null) { $complete = false; $est = ['offered' => false, 'reason' => '']; }
            else $convert = self::enrichConvert($est);
            $convAction = !empty($est['offered'])
                ? self::action('convert_agents', 'Convert to plain folders')
                : self::action('convert_agents', 'Convert to plain folders', false, self::convertReason((string)($est['reason'] ?? '')));
            if (self::isInside($persist, $pluginDir)) $insideTotal += $cur + $oldBytes;
            $names = [];
            foreach ($sizes as $s) $names[] = ($s['name'] ?? $s['id']) . ' (' . (($s['storage'] ?? '') === 'layers' ? 'layers' : 'plain folders') . ')';
            $parts[] = ['key' => 'agents:current', 'group' => 'agents', 'label' => 'Agents: current versions', 'bytes' => $cur,
                'note' => $names ? implode(', ', $names) : '', 'action' => $convAction];
            $parts[] = ['key' => 'agents:old', 'group' => 'agents', 'label' => 'Agents: old versions', 'bytes' => $oldBytes,
                'items' => count($old['items'] ?? []),
                'action' => $oldBytes > 0 ? self::action('remove_old_versions', 'Remove old versions')
                    : self::action('remove_old_versions', 'Remove old versions', false, 'No old version to remove.')];
        } else {
            $complete = false;
        }

        // ---- left over ----
        if ($complete && !self::outOfTime()) {
            $items = self::leftOverItems($persist, $homes, $du, $seams['retired_list'] ?? null);
            $lo = 0; foreach ($items as $it) $lo += $it['bytes'];
            if (self::isInside($persist, $pluginDir)) $insideTotal += $lo;
            $parts[] = ['key' => 'leftover', 'group' => 'leftover', 'label' => 'Left over', 'bytes' => $lo,
                'items' => array_map(static function ($it) {
                    $out = ['key' => $it['key'], 'label' => $it['label'], 'bytes' => $it['bytes']];
                    if (isset($it['locked'])) $out['locked'] = (bool)$it['locked'];
                    if (isset($it['age_seconds'])) $out['age_seconds'] = (int)$it['age_seconds'];
                    return $out;
                }, $items),
                'action' => array_filter($items, static fn(array $it): bool => empty($it['locked'])) !== []
                    ? self::action('remove_leftover', 'Remove')
                    : self::action('remove_leftover', 'Remove', false, 'Nothing is left over.')];
        } else {
            $complete = false;
        }

        // ---- plugin files: the rest of the plugin folder ----
        if ($complete && !self::outOfTime()) {
            $pluginTotal = (int)$du($pluginDir);
            $persistInPlugin = self::isInside($persist, $pluginDir) || self::isInside($homePersist, $pluginDir);
            $rest = $persistInPlugin ? max(0, $pluginTotal - $insideTotal) : $pluginTotal;
            $parts[] = ['key' => 'plugin', 'group' => 'plugin', 'label' => 'Plugin files', 'bytes' => $rest,
                'note' => 'Program files, saved copies of earlier versions, settings and state.', 'action' => null];
        } else {
            $complete = false;
        }

        $out = ['measured_at' => $now, 'persist' => $persist, 'home_persist' => $homePersist,
            'disk' => $disk, 'parts' => self::sortParts($parts),
            'agents' => $agentRows, 'history' => $history, 'history_ages' => self::HISTORY_AGES, 'caches' => $caches,
            'partial' => !$complete || MeasureDeadline::wasPartial()];
        // AGENT_TEMP_FOLDER.md R4: the agent temporary folders in RAM. Background measure only.
        // A run that passes its own du (a test) never reads the real /tmp unless it passes agent_tmp too.
        if (isset($seams['agent_tmp'])) {
            $out['agent_tmp'] = $seams['agent_tmp'];
        } elseif (!isset($seams['du']) && !self::outOfTime()) {
            try {
                $out['agent_tmp'] = AgentTempService::measure(['du' => $du, 'now' => $now]);
            } catch (\Throwable $e) { /* the card shows no warning */ }
        }
        if ($oldVersions !== null) $out['old_versions'] = $oldVersions;
        if ($convert !== null) $out['convert'] = $convert;
        return $out;
    }

    /**
     * The convert estimate as the file keeps it: each agent also carries `extra_bytes` (what the
     * conversion adds for that agent) and `fits` ('ok' or 'no_room:<missing bytes>' for that agent
     * alone). The sizes of a layered agent are estimates (AgentStorageService::UNPACKED_RATIO).
     */
    public static function enrichConvert(array $est): array
    {
        $free = (int)($est['free_bytes'] ?? 0);
        $disk = (int)($est['disk_bytes'] ?? 0);
        $agents = [];
        foreach (($est['agents'] ?? []) as $a) {
            $extra = max(0, (int)($a['plain_bytes'] ?? 0) - (int)($a['now_bytes'] ?? 0));
            $a['extra_bytes'] = $extra;
            $a['fits'] = AgentStorageService::convertFits($free, $disk, $extra);
            $a['estimated'] = true;
            $agents[] = $a;
        }
        $est['agents'] = $agents;
        return $est;
    }

    public static function human(int $b): string
    {
        if ($b >= 1073741824) return number_format($b / 1073741824, 1) . ' GB';
        return max($b > 0 ? 1 : 0, (int)round($b / 1048576)) . ' MB';
    }

    // ---- the file ------------------------------------------------------------------

    /** Write the file atomically (temp file in the same folder, then rename). */
    public static function write(array $data): bool
    {
        $file = self::stateFile();
        $dir = dirname($file);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $tmp = $file . '.tmp.' . getmypid();
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($json === false || @file_put_contents($tmp, $json) === false) { @unlink($tmp); return false; }
        if (!@rename($tmp, $file)) { @unlink($tmp); return false; }
        return true;
    }

    /** The last measurement, or null when there is none or the file is damaged. */
    public static function read(): ?array
    {
        $raw = @file_get_contents(self::stateFile());
        if ($raw === false || $raw === '') return null;
        $j = json_decode($raw, true);
        return (is_array($j) && isset($j['measured_at'], $j['parts'])) ? $j : null;
    }

    public static function isStale(?array $data, ?int $now = null): bool
    {
        if ($data === null) return true;
        return ($now ?? time()) - (int)$data['measured_at'] >= self::MAX_AGE;
    }

    public static function isMeasuring(?int $now = null): bool
    {
        $m = @filemtime(self::lockFile());
        return $m !== false && ($now ?? time()) - $m < self::LOCK_TTL;
    }

    /**
     * Run a measure now, in this process, under the lock. Returns false when another measure
     * runs. Called by the background script, and by tests.
     */
    public static function run(array $seams = []): bool
    {
        $lock = self::lockFile();
        $dir = dirname($lock);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $fh = @fopen($lock, 'c');
        if ($fh === false) return false;
        if (!flock($fh, LOCK_EX | LOCK_NB)) { fclose($fh); return false; }
        try {
            @ftruncate($fh, 0); fwrite($fh, (string)getmypid()); fflush($fh); @touch($lock);
            // ONE deadline for all parts (#552). A seam 'deadline_at' replaces it in tests.
            $at = (int)($seams['deadline_at'] ?? (time() + MeasureDeadline::seconds()));
            unset($seams['deadline_at']);
            MeasureDeadline::start($at);
            $data = self::measure($seams);
            $data['deadline_s'] = max(0, $at - time());   // seconds that were left when it ended
            self::write($data);
        } finally {
            MeasureDeadline::clear();
            flock($fh, LOCK_UN); fclose($fh);
            @unlink($lock);
        }
        return true;
    }

    /**
     * Start a measure in the background. Does nothing when one runs. A test replaces the
     * launcher with $launcher (callable, gets the argv array).
     */
    public static function startMeasure(?callable $launcher = null): bool
    {
        if (self::isMeasuring()) return false;
        $script = dirname(__DIR__, 2) . '/scripts/storage/boot-space.php';
        // The whole run has one deadline (default 300 s). `timeout` is a hard stop a little after it.
        // nice and ionice (best-effort class, level 7; NOT the idle class) keep it from starving
        // the web server and the workspaces on the same disk.
        $argv = ['timeout', (string)(MeasureDeadline::seconds() + 60), 'nice', '-n', '19'];
        $io = self::ioniceBinary();
        if ($io !== '') { array_push($argv, $io, '-c2', '-n7'); }
        array_push($argv, 'php', '-d', 'display_errors=0', $script);
        if (!is_dir(dirname(self::lockFile()))) @mkdir(dirname(self::lockFile()), 0755, true);
        @touch(self::lockFile());   // mark it at once, so a second request does not start a second one
        if ($launcher !== null) { $launcher($argv); return true; }
        require_once __DIR__ . '/UtilityService.php';
        UtilityService::spawnDetached($argv);
        return true;
    }

    /**
     * The answer of get_boot_space: the file, `measuring`, and an `age` in seconds.
     * Starts a measure when the file is stale or missing.
     */
    public static function status(?callable $launcher = null, ?int $now = null): array
    {
        $now = $now ?? time();
        $data = self::read();
        $started = false;
        require_once __DIR__ . '/StorageBackendMigrationService.php';
        $migration = StorageBackendMigrationService::status();
        $migrationActive = in_array((string)($migration['status'] ?? ''), ['queued', 'running'], true);
        if (!$migrationActive && self::isStale($data, $now)) $started = self::startMeasure($launcher);
        $measuring = $started || self::isMeasuring($now);
        $out = $data ?? ['measured_at' => 0, 'disk' => null, 'parts' => []];
        $out['measuring'] = $measuring;
        $out['storage_migration'] = $migration;
        $out['has_data'] = $data !== null;
        $out['age'] = $data !== null ? max(0, $now - (int)$data['measured_at']) : null;
        $out['stale'] = self::isStale($data, $now);
        return $out;
    }

    // ---- reading the file for the dialogs (#552) ---------------------------------------
    // These functions read the file and nothing else. They never measure and never start a
    // measure: a dialog shows the numbers with their age and offers "Measure now".

    /**
     * The facts about the file that every dialog answer carries: measured_at, age (seconds),
     * stale (older than MAX_AGE or missing), has_data, measuring (a measure runs now) and
     * partial (the last run stopped at its deadline).
     * @return array{has_data:bool,measured_at:int,age:?int,stale:bool,measuring:bool,partial:bool}
     */
    public static function meta(?array $data = null, ?int $now = null): array
    {
        $now = $now ?? time();
        require_once __DIR__ . '/StorageBackendMigrationService.php';
        $migration = StorageBackendMigrationService::status();
        return [
            'has_data'    => $data !== null,
            'measured_at' => $data !== null ? (int)$data['measured_at'] : 0,
            'age'         => $data !== null ? max(0, $now - (int)$data['measured_at']) : null,
            'stale'       => self::isStale($data, $now),
            'measuring'   => self::isMeasuring($now),
            'partial'     => $data !== null && !empty($data['partial']),
            'storage_migration' => $migration,
        ];
    }

    /**
     * The answer of agent_old_versions: the old versions of the last measurement, for one agent
     * or for 'all'. `status` is 'error' with message 'invalid_agent' for a bad id.
     */
    public static function cachedOldVersions(string $agent, ?array $data = null, ?int $now = null): array
    {
        if (!AgentVersionCleanupService::isValidTarget($agent)) return ['status' => 'error', 'message' => 'invalid_agent'];
        $data = $data ?? self::read();
        $all = $agent === '' || $agent === 'all';
        $ov = is_array($data['old_versions'] ?? null) ? $data['old_versions'] : ['items' => [], 'skipped' => [], 'total_bytes' => 0];
        $items = []; $skipped = []; $total = 0;
        foreach (($ov['items'] ?? []) as $it) {
            if (!$all && ($it['agent'] ?? '') !== $agent) continue;
            $items[] = $it; $total += (int)($it['bytes'] ?? 0);
        }
        foreach (($ov['skipped'] ?? []) as $sk) {
            if (!$all && ($sk['agent'] ?? '') !== $agent) continue;
            $skipped[] = $sk;
        }
        return ['status' => 'ok', 'agent' => $all ? 'all' : $agent,
                'keep_previous' => AgentVersionCleanupService::keepPrevious(),
                'items' => $items, 'skipped' => $skipped, 'total_bytes' => $total] + self::meta($data, $now);
    }

    /**
     * The answer of agent_convert_estimate: the estimate of the last measurement. The free space
     * is read again now (one cheap call), so the room check uses the live number. `estimate` is
     * null when there is no measurement yet.
     */
    public static function cachedConvert(?array $data = null, ?int $now = null, ?int $freeBytes = null): array
    {
        $data = $data ?? self::read();
        $est = is_array($data['convert'] ?? null) ? $data['convert'] : null;
        if ($est !== null && !empty($est['offered'])) {
            $free = $freeBytes ?? (int)@disk_free_space(self::persistPath());
            if ($free > 0 || $freeBytes !== null) {
                $est['free_bytes'] = $free;
                $est = self::enrichConvert($est);
                $est['fits'] = AgentStorageService::convertFits($free, (int)($est['disk_bytes'] ?? 0), (int)($est['extra_bytes'] ?? 0));
            }
        }
        return ['status' => 'ok', 'estimate' => $est] + self::meta($data, $now);
    }

    /**
     * The conversation history of one plain home from the last measurement:
     * agentId => {bytes, files, sessions, index_bytes, older_than{30,90,180}, partial}. Null when the
     * file has no figures for the home.
     * @return array<string,array<string,mixed>>|null
     */
    public static function cachedHistory(string $user, ?array $data = null): ?array
    {
        $data = $data ?? self::read();
        $h = $data['history'][$user] ?? null;
        return is_array($h) ? $h : null;
    }

    /**
     * The cache status of one plain home from the last measurement, in the shape of
     * HomeCacheService::status(). Null when the file has no figures for the home.
     * @return array<string,mixed>|null
     */
    /**
     * The agent temporary folders in RAM, as the last measurement kept them (AGENT_TEMP_FOLDER.md R4):
     * ram_bytes, closed_bytes, closed_sessions, warn_mb, warn. Null when the file has none. Reads the file only.
     * @return array<string,mixed>|null
     */
    public static function cachedAgentTmp(?array $data = null): ?array
    {
        $data = $data ?? self::read();
        $t = $data['agent_tmp'] ?? null;
        return is_array($t) ? $t : null;
    }

    public static function cachedCache(string $user, ?array $data = null): ?array
    {
        $data = $data ?? self::read();
        $c = $data['caches'][$user] ?? null;
        return is_array($c) ? $c : null;
    }

    // ---- removing a left-over item ---------------------------------------------------

    /**
     * Remove one item of the "Left over" line. Only an item that the last measurement lists is
     * accepted. The item is looked up again right before it is removed.
     *
     * $seams (tests): persist, in_use (callable path→bool), delete_home (callable user→array{ok,message}),
     * retired_list, data (the last measurement instead of the file).
     * @return array{status:string,message?:string,freed_bytes?:int}
     */
    public static function removeLeftover(string $key, array $seams = []): array
    {
        if (preg_match('/^(graduated|retired|homelayers|testhome|unfinished|backendarchive):[A-Za-z0-9._-]{1,128}$/D', $key) !== 1) {
            return ['status' => 'error', 'message' => 'This item is not valid.'];
        }
        $data = $seams['data'] ?? self::read();
        // The old layers of a converted home are also offered on the home card, which reads
        // the folder itself and not the last measurement. The measurement may be old or
        // missing, so this one kind needs no listing. The live check below is the guard.
        $listed = strpos($key, 'graduated:') === 0;
        foreach (($data['parts'] ?? []) as $p) {
            if (($p['key'] ?? '') !== 'leftover') continue;
            foreach (($p['items'] ?? []) as $it) {
                if (($it['key'] ?? '') === $key) { $listed = true; break 2; }
            }
        }
        if (!$listed) {
            return ['status' => 'error', 'message' => 'This item is not in the last measurement. Measure again, then try once more.'];
        }

        $persist = rtrim((string)($seams['persist'] ?? self::persistPath()), '/');
        // No measuring in a web request (#552): the freed size is the size that the last
        // measurement listed for the item.
        $listedBytes = 0;
        foreach (($data['parts'] ?? []) as $p) {
            if (($p['key'] ?? '') !== 'leftover') continue;
            foreach (($p['items'] ?? []) as $it) {
                if (($it['key'] ?? '') === $key) { $listedBytes = (int)($it['bytes'] ?? 0); break 2; }
            }
        }
        $inUse = $seams['in_use'] ?? [self::class, 'pathInUse'];
        $configUser = (string)($seams['config_user'] ?? (ConfigService::getConfig()['user'] ?? 'root'));
        if ($configUser === '') $configUser = 'root';

        // Re-check right before removal: find the item again with the live rules.
        $homes = self::discoverHomes($persist, $configUser, self::protectedHomeIds($configUser, $seams));
        $live = self::leftOverItems($persist, $homes, static function () { return 0; }, $seams['retired_list'] ?? null);   // no du: the callable returns 0
        $item = null;
        foreach ($live as $it) { if ($it['key'] === $key) { $item = $it; break; } }
        if ($item === null) {
            return ['status' => 'error', 'message' => 'This item is no longer left over, so nothing was removed.'];
        }
        if (!empty($item['locked'])) {
            return ['status' => 'error', 'message' => 'These layers are needed to finish or roll back a conversion, so they were not removed.'];
        }

        [$type, $name] = explode(':', $key, 2);
        $freed = 0;
        if ($type === 'homelayers') {
            $files = glob($persist . '/home_' . $name . '_*.sqsh') ?: [];
            foreach ($files as $f) { if (!self::isInside($f, $persist)) return ['status' => 'error', 'message' => 'A path is outside the persistence folder.']; }
            if (isset($seams['delete_home'])) {
                $r = ($seams['delete_home'])($name);
            } else {
                require_once __DIR__ . '/FileStorage.php';
                $r = FileStorage::deleteHomeEntity($name);
            }
            $ok = !empty($r['ok']);
            $freed = $listedBytes;
            if ($ok) { self::logRemoved($key, $freed); return ['status' => 'ok', 'freed_bytes' => $freed]; }
            return ['status' => 'error', 'message' => (string)($r['message'] ?? 'The layers could not be removed.')];
        }

        if ($type === 'backendarchive') {
            $path = (string)($item['path'] ?? '');
            $archiveRoot = $persist . '/.backend-migration-archive';
            if (preg_match('/^\d{8}T\d{6}Z_(agent|home)_[A-Za-z0-9._-]{1,128}$/D', $name) !== 1
                || $path !== $archiveRoot . '/' . $name || is_link($path)
                || !is_dir($path) || !self::isInside($path, $archiveRoot)) {
                return ['status' => 'error', 'message' => 'This archived copy is no longer safe to remove.'];
            }
            if (self::backendArchiveHasOpenIntent($persist, $name)) {
                return ['status' => 'error', 'message' => 'A conversion still needs this archive to finish or put back the storage.'];
            }
            if ($inUse($path)) {
                return ['status' => 'error', 'message' => 'The archived copy is in use, so it was not removed.'];
            }
            $freed = $listedBytes;
            $out = []; $rc = 0;
            @exec('rm -rf -- ' . escapeshellarg($path) . ' 2>&1', $out, $rc);
            clearstatcache(true, $path);
            if (@lstat($path) !== false) {
                return ['status' => 'error', 'message' => 'The archived copy could not be removed completely.'];
            }
            self::logRemoved($key, $freed);
            return ['status' => 'ok', 'freed_bytes' => $freed];
        }

        $path = $item['path'];
        if ($type === 'unfinished') {
            $marker = $persist . '/.graduate_staging_' . $name;
            if (self::unfinishedCopyPath($persist, $name, $marker) !== $path) {
                return ['status' => 'error', 'message' => 'The unfinished conversion copy is no longer safe to remove.'];
            }
            if ($inUse($path)) {
                return ['status' => 'error', 'message' => 'The item is in use (a mount or a process holds it), so it was not removed.'];
            }
            $freed = $listedBytes;
            $out = []; $rc = 0;
            @exec('rm -rf -- ' . escapeshellarg($path) . ' 2>&1', $out, $rc);
            if (file_exists($path) || is_link($path)) {
                return ['status' => 'error', 'message' => 'The item could not be removed completely.'];
            }
            @unlink($marker);
            self::logRemoved($key, $freed);
            return ['status' => 'ok', 'freed_bytes' => $freed];
        }
        if (!self::isInside($path, $persist) || $path === $persist) {
            return ['status' => 'error', 'message' => 'The item lies outside the persistence folder, so it was not removed.'];
        }
        $real = realpath($path);
        $realRoot = realpath($persist);
        if ($real === false && is_link($path)) {
            // A dangling link: its target is gone, so realpath() cannot resolve the link. The link
            // itself is the item, so check the folder that holds the link instead.
            $parent = realpath(dirname($path));
            $real = $parent === false ? false : $parent . '/' . basename($path);
        }
        if ($real === false || $realRoot === false || !self::isInside($real, $realRoot) || $real === $realRoot) {
            return ['status' => 'error', 'message' => 'The item lies outside the persistence folder, so it was not removed.'];
        }
        if ($type === 'testhome' && !self::isTestId($name)) {
            return ['status' => 'error', 'message' => 'This is not a test home.'];
        }
        if ($inUse($path)) {
            return ['status' => 'error', 'message' => 'The item is in use (a mount or a process holds it), so it was not removed.'];
        }
        $freed = $listedBytes;
        $out = []; $rc = 0;
        @exec('rm -rf -- ' . escapeshellarg($path) . ' 2>&1', $out, $rc);
        if (file_exists($path) || is_link($path)) {
            return ['status' => 'error', 'message' => 'The item could not be removed completely.'];
        }
        self::logRemoved($key, $freed);
        return ['status' => 'ok', 'freed_bytes' => $freed];
    }

    private static function logRemoved(string $key, int $freed): void
    {
        try {
            LifecycleLogService::log(LifecycleLogService::LEVEL_INFO, 'storage', 'boot_space_leftover_removed',
                ['key' => $key, 'freed_bytes' => $freed]);
        } catch (\Throwable $e) { /* a log must never break the action */ }
    }

    /** True when a mount lists the path, or a process has it as its working folder. */
    public static function pathInUse(string $path): bool
    {
        $real = realpath($path) ?: $path;
        foreach (preg_split('/\R/', (string)@file_get_contents('/proc/mounts')) ?: [] as $line) {
            $f = preg_split('/\s+/', $line);
            if (count($f) < 2) continue;
            $mp = str_replace('\040', ' ', $f[1]);
            if ($mp === $real || strpos($mp, $real . '/') === 0 || $f[0] === $real || strpos($f[0], $real . '/') === 0) return true;
        }
        foreach (glob('/proc/[0-9]*/cwd', GLOB_NOSORT) ?: [] as $c) {
            $t = @readlink($c);
            if ($t !== false && ($t === $real || strpos($t, $real . '/') === 0)) return true;
        }
        return false;
    }
}
