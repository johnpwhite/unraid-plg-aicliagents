<?php
/**
 * <module_context>
 *     <name>StorageTargetService</name>
 *     <description>
 *         S-11 (Feature #1355): the storage target picker's read-only enumeration
 *         engine. enumerateTargets(kind) discovers candidate persistence targets
 *         (appdata share pool, pools from disks.ini, /mnt/user, array disks, UD
 *         devices, network remotes, flash), probes each via FileStorage::probeTarget
 *         (S-01 capability probe — memoised per request) and returns a ranked list:
 *         appdata pool (ssd/nvme) > other posix pool > UD addons > UD disks >
 *         array direct disk > /mnt/user (FUSE warn) > flash > remotes (flagged).
 *         validateTarget(kind, path) is the shared per-kind policy verdict consumed
 *         by StorageHandler::preflightMigrate (refuse → error, warnings surfaced,
 *         exclusive-share /mnt/user paths resolved to the direct pool path).
 *     </description>
 *     <dependencies>FileStorage (probeTarget), StorageMountService (isPathAvailable)</dependencies>
 *     <constraints>READ-ONLY — never mutates config, mounts or files. Candidates
 *         capped at 12. Test-only env hooks: AICLI_DISKS_INI, AICLI_SHARES_INI,
 *         AICLI_MNT_ROOT, AICLI_ITEST_MOUNTED_PATHS (plus AICLI_STORAGECTL via
 *         FileStorage::probeTarget).</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

require_once __DIR__ . '/FileStorage.php';
require_once __DIR__ . '/StorageMountService.php';

class StorageTargetService {

    const DEFAULT_FLASH_PATH = '/boot/config/plugins/unraid-aicliagents/persistence';
    const MAX_CANDIDATES = 12;

    /**
     * Enumerate ranked persistence target candidates for one storage kind.
     *
     * @param string     $kind   'home' | 'agent' — drives the S-02 network policy
     *                           (remotes refused for home; warn-gated for agents
     *                           behind storage_allow_remote_agent=1).
     * @param array|null $config plugin config (defaults to getAICliConfig()).
     * @return array<int,array<string,mixed>> candidates, sorted best-first. Each:
     *   {path, label, source, mount_class, engine, upper_mode, rotational,
     *    free_bytes, recommendation_rank, warnings[], refuse, current,
     *    recommended, advanced [, original_path, note, picked_via]}
     */
    public static function enumerateTargets(string $kind, ?array $config = null): array {
        $kind = ($kind === 'agent') ? 'agent' : 'home';
        if ($config === null) {
            $config = \function_exists('getAICliConfig') ? getAICliConfig() : [];
        }
        $mnt     = rtrim(getenv('AICLI_MNT_ROOT') ?: '/mnt', '/');
        $current = (string)($config[$kind . '_storage_path'] ?? self::DEFAULT_FLASH_PATH);

        $cands   = [];
        $pools   = self::poolNames();
        $appPool = self::appdataPrimaryPool();

        // 1. The appdata share's primary pool — the recommended default location.
        if ($appPool !== null && in_array($appPool, $pools, true) && is_dir("$mnt/$appPool")) {
            $cands[] = self::candidate("$mnt/$appPool/appdata/aicliagents",
                "Appdata share pool: $appPool", 'appdata_pool', $kind, $config, $current);
        }
        // 2. Every pool from disks.ini (type="Cache" sections, trailing digits stripped).
        foreach ($pools as $pool) {
            if (!is_dir("$mnt/$pool")) continue;
            $cands[] = self::candidate("$mnt/$pool", "Pool: $pool", 'pool', $kind, $config, $current);
        }
        // 3. User shares (FUSE — probe warns user_share) when shfs is up.
        if (is_dir("$mnt/user") && self::mounted("$mnt/user")) {
            $cands[] = self::candidate("$mnt/user", 'User shares (/mnt/user)', 'user_share', $kind, $config, $current);
        }
        // 4. UD devices — /mnt/addons (UD-blessed plugin paths) before /mnt/disks.
        foreach (['addons' => 'ud_addons', 'disks' => 'ud_disk'] as $dir => $src) {
            foreach (self::subdirs("$mnt/$dir") as $d) {
                if (!self::mounted($d)) continue;
                $cands[] = self::candidate($d, 'UD device: ' . basename($d), $src, $kind, $config, $current);
            }
        }
        // 5. Array direct disks — advanced only (bypasses FUSE, pins one spindle).
        foreach (self::subdirs($mnt) as $d) {
            if (!preg_match('/^disk\d+$/', basename($d))) continue;
            if (!self::mounted($d)) continue;
            $cands[] = self::candidate($d, 'Array disk: ' . basename($d), 'array_disk', $kind, $config, $current, true);
        }
        // 6. Network remotes — listed but flagged per the S-02 kind policy.
        foreach (self::subdirs("$mnt/remotes") as $d) {
            if (!self::mounted($d)) continue;
            $cands[] = self::candidate($d, 'Network share: ' . basename($d), 'remote', $kind, $config, $current);
        }
        // 7. Flash — always present, always offered last among usable targets.
        $flashPath = (strpos($current, '/boot') === 0) ? $current : self::DEFAULT_FLASH_PATH;
        $flash = self::candidate($flashPath, '', 'flash', $kind, $config, $current);
        $flash['label'] = ($flash['mount_class'] === 'boot_internal')
            ? 'Internal boot (durable)'
            : 'USB flash (fallback — wear-limited)';
        $cands[] = $flash;

        // Current path not among the candidates (custom location) → surface it.
        $havePaths = array_column($cands, 'path');
        if (!in_array($current, $havePaths, true)) {
            $cands[] = self::candidate($current, 'Current location', 'current', $kind, $config, $current);
        }

        // Dedupe by path (first wins — list is built best-source-first), merging
        // the current marker and any exclusive-share resolution info from the
        // dropped duplicate (e.g. a /mnt/user current path that resolved onto
        // the appdata candidate).
        $seen = []; $unique = [];
        foreach ($cands as $c) {
            if (isset($seen[$c['path']])) {
                $i = $seen[$c['path']];
                if ($c['current']) $unique[$i]['current'] = true;
                foreach (['original_path', 'note', 'picked_via'] as $k) {
                    if (isset($c[$k]) && !isset($unique[$i][$k])) $unique[$i][$k] = $c[$k];
                }
                continue;
            }
            $seen[$c['path']] = count($unique);
            $unique[] = $c;
        }

        usort($unique, static function ($a, $b) {
            if ($a['recommendation_rank'] !== $b['recommendation_rank']) {
                return $a['recommendation_rank'] <=> $b['recommendation_rank'];
            }
            if ($a['free_bytes'] !== $b['free_bytes']) {
                return $b['free_bytes'] <=> $a['free_bytes'];
            }
            return strcmp($a['path'], $b['path']);
        });

        // Cap at MAX_CANDIDATES, never dropping the flash fallback or the current path.
        if (count($unique) > self::MAX_CANDIDATES) {
            $keep = []; $rest = [];
            foreach ($unique as $c) {
                if ($c['source'] === 'flash' || $c['current']) { $keep[] = $c; } else { $rest[] = $c; }
            }
            $unique = array_merge(array_slice($rest, 0, self::MAX_CANDIDATES - count($keep)), $keep);
            usort($unique, static fn($a, $b) => $a['recommendation_rank'] <=> $b['recommendation_rank']);
        }

        // Mark the single recommended candidate: best-ranked non-refused entry.
        foreach ($unique as $i => $c) {
            if (!$c['refuse']) { $unique[$i]['recommended'] = true; break; }
        }
        return $unique;
    }

    /**
     * Bug #1380: the QUALIFYING targets for a "move off USB flash drive"
     * graduation — the user-intent relocation policy (criterion b). Filters
     * enumerateTargets() to durable, NON-array, NON-flash, passthrough-capable,
     * NON-refused candidates the data can be moved TO. Reused by BOTH the gate
     * (FileStorage::canGraduate → StorageMetricsService `can_graduate`) and the
     * relocation action (the graduate-targets AJAX surface) so they can never
     * diverge. A target qualifies IFF:
     *   - engine      == passthrough  (a durable plain-directory target — the
     *                                  whole point of moving off the stick)
     *   - durability  == durable      (never volatile/network)
     *   - !refuse                     (the probe did not reject it)
     *   - mount_class NOT IN {array, boot_usb, boot_internal, remote, tmpfs,
     *                         user_share}  (non-array AND non-flash; the FUSE
     *                                       user_share and array spindle are
     *                                       excluded as relocation destinations)
     *   - not the entity's CURRENT flash location (can't move onto itself)
     *
     * @param string     $kind   'home' | 'agent'
     * @param array|null $config plugin config (defaults to getAICliConfig()).
     * @return array<int,array<string,mixed>> qualifying candidates, best-first.
     */
    public static function qualifyingGraduateTargets(string $kind, ?array $config = null): array {
        $kind = ($kind === 'agent') ? 'agent' : 'home';
        $all  = self::enumerateTargets($kind, $config);
        $excludedClasses = ['array', 'boot_usb', 'boot_internal', 'remote', 'tmpfs', 'user_share'];
        $out = [];
        foreach ($all as $t) {
            if (!empty($t['refuse'])) continue;
            if (!empty($t['current'])) continue;                 // can't move onto itself
            if (($t['engine'] ?? '') !== 'passthrough') continue; // durable plain-dir only
            if (($t['durability'] ?? 'durable') !== 'durable') continue;
            $mc = (string)($t['mount_class'] ?? '');
            // pool:<name> classes are durable non-flash non-array — keep them.
            if (in_array($mc, $excludedClasses, true)) continue;
            $out[] = $t;
        }
        return $out;
    }

    /**
     * Shared per-kind target validation — the preflight_migrate policy seam
     * (S-06/S-11). Routes through FileStorage::probeTarget; falls back to the
     * legacy #341 findmnt fstype check when the probe itself is unavailable.
     *
     * @return array{ok:bool, message:string, warnings:array<int,string>, resolved_path:?string}
     */
    public static function validateTarget(string $kind, string $path, array $config): array {
        $kind = ($kind === 'agent') ? 'agent' : 'home';
        // Probe the deepest existing ancestor — a not-yet-created subdirectory
        // of a valid mount is a valid target (execute_migrate mkdir -p's it).
        [$probePath, $suffix] = self::existingAncestor($path);
        $probe = FileStorage::probeTarget($probePath);
        if (in_array('probe_failed', (array)($probe['reasons'] ?? []), true)) {
            return self::legacyFstypeVerdict($path);
        }
        $warnings = array_values((array)($probe['warnings'] ?? []));
        if (!empty($probe['refuse'])) {
            return ['ok' => false, 'warnings' => $warnings, 'resolved_path' => null,
                'message' => "Persistence path '$path' is refused ("
                    . ($probe['fstype'] !== '' ? $probe['fstype'] . ', ' : '')
                    . $probe['mount_class'] . '): ' . implode(', ', $warnings ?: (array)$probe['reasons'])
                    . '. Choose a durable local path (pool, UD device, or flash).'];
        }
        if (($probe['mount_class'] ?? '') === 'remote' || ($probe['durability'] ?? '') === 'network') {
            if ($kind === 'home') {
                return ['ok' => false, 'warnings' => $warnings, 'resolved_path' => null,
                    'message' => "Persistence path '$path' is a network share — home storage must be on durable local storage (rejected_for_home)."];
            }
            if ((string)($config['storage_allow_remote_agent'] ?? '0') !== '1') {
                return ['ok' => false, 'warnings' => $warnings, 'resolved_path' => null,
                    'message' => "Persistence path '$path' is a network share. Remote agent storage requires storage_allow_remote_agent=\"1\" (not recommended)."];
            }
            $warnings[] = 'remote_agent_warn';
        }
        $resolved = null;
        if (!empty($probe['via_user_share'])
            && is_string($probe['realpath'] ?? null) && $probe['realpath'] !== ''
            && $probe['realpath'] . $suffix !== $path) {
            $resolved = $probe['realpath'] . $suffix;
        }
        return ['ok' => true, 'message' => '', 'warnings' => array_values(array_unique($warnings)),
                'resolved_path' => $resolved];
    }

    // -------------------------------------------------------------------------
    // internals
    // -------------------------------------------------------------------------

    /** Build one probed candidate entry (see enumerateTargets shape). */
    private static function candidate(string $path, string $label, string $source,
                                      string $kind, array $config, string $current,
                                      bool $advanced = false): array {
        // Probe the deepest EXISTING ancestor: the recommended targets (e.g.
        // /mnt/<pool>/appdata/aicliagents) usually don't exist yet, and findmnt
        // can't classify a non-existent path — the filesystem verdict is
        // mount-level, so the ancestor's probe is the target's probe.
        [$probePath, $suffix] = self::existingAncestor($path);
        $probe    = FileStorage::probeTarget($probePath);
        $warnings = array_values((array)($probe['warnings'] ?? []));
        $refuse   = !empty($probe['refuse']);
        $original = null; $note = null;

        // Exclusive-share resolution: surface the realpath as the actionable
        // value (this is what gets STORED); keep the picked /mnt/user path as info.
        if (!empty($probe['via_user_share'])
            && is_string($probe['realpath'] ?? null) && $probe['realpath'] !== ''
            && $probe['realpath'] . $suffix !== $path) {
            $original = $path;
            $path = $probe['realpath'] . $suffix;
            $note = "Resolves to $path (exclusive share) — the direct pool path is stored.";
        }

        // S-02 per-kind network policy (the probe has no kind axis — applied here).
        if (($probe['mount_class'] ?? '') === 'remote' || ($probe['durability'] ?? '') === 'network') {
            if ($kind === 'home') {
                $refuse = true;
                $warnings[] = 'rejected_for_home';
            } else {
                $warnings[] = 'remote_agent_warn';
                if ((string)($config['storage_allow_remote_agent'] ?? '0') !== '1') $refuse = true;
            }
        }

        $entry = [
            'path'        => $path,
            'label'       => $label,
            'source'      => $source,
            'mount_class' => (string)($probe['mount_class'] ?? 'other'),
            'engine'      => (string)($probe['engine'] ?? 'layering'),
            'upper_mode'  => (string)($probe['upper_mode'] ?? 'zram'),
            // Bug #1380: surface the probe durability axis so the graduate gate
            // (qualifyingGraduateTargets) can require durable, non-network targets.
            'durability'  => (string)($probe['durability'] ?? 'durable'),
            'rotational'  => !empty($probe['rotational']),
            'free_bytes'  => self::freeBytes($path),
            'warnings'    => array_values(array_unique($warnings)),
            'refuse'      => $refuse,
            'current'     => ($path === $current || $original === $current),
            'recommended' => false,
            'advanced'    => $advanced,
        ];
        if ($original !== null) {
            $entry['original_path'] = $original;
            $entry['note']          = $note;
            $entry['picked_via']    = $original;
        }
        $entry['recommendation_rank'] = self::rank($source, $probe, $refuse, $entry['mount_class']);
        return $entry;
    }

    /**
     * Ranking policy (see docs/specs/STORAGE_TARGET_PICKER.md):
     *   1 appdata pool (non-rotational posix) · 2 other posix pool / rotational
     *   appdata pool · 3 UD addons · 4 UD disks · 5 array direct disk ·
     *   6 /mnt/user · 7 flash · 8 remote · 9 other · 99 refused.
     * Non-posix pool/UD targets are demoted by +4 (below /mnt/user).
     */
    private static function rank(string $source, array $probe, bool $refuse, string $mountClass): int {
        if ($refuse) return 99;
        $posixFull = (($probe['posix'] ?? '') === 'posix_full');
        switch ($source) {
            case 'appdata_pool':
                if (!$posixFull) return 6;
                return !empty($probe['rotational']) ? 2 : 1;
            case 'pool':       $r = 2; break;
            case 'ud_addons':  $r = 3; break;
            case 'ud_disk':    $r = 4; break;
            case 'array_disk': return 5;
            case 'user_share': return 6;
            case 'flash':      return 7;
            case 'remote':     return 8;
            case 'current':    return self::rankFromClass($mountClass);
            default:           return 9;
        }
        return $posixFull ? $r : $r + 4;
    }

    /** Rank a custom 'current' candidate by its probed mount class. */
    private static function rankFromClass(string $class): int {
        if (strpos($class, 'pool:') === 0) return 2;
        switch ($class) {
            case 'ud_addons':     return 3;
            case 'ud_disk':       return 4;
            case 'array':         return 5;
            case 'user_share':    return 6;
            case 'boot_usb':
            case 'boot_internal': return 7;
            case 'remote':        return 8;
            default:              return 9;
        }
    }

    /**
     * Pool names from disks.ini — the PHP port of the proven classify-path.sh /
     * detect_backend.sh awk: sections are ["cache"] / ["cache2"], a pool member
     * has type="Cache", and the pool name is the section with trailing digits
     * stripped. AICLI_DISKS_INI overrides the path (same hook as the bash side).
     * @return array<int,string> sorted unique pool names
     */
    public static function poolNames(): array {
        $ini = getenv('AICLI_DISKS_INI') ?: '/var/local/emhttp/disks.ini';
        if (!is_file($ini)) return [];
        $pools = []; $section = '';
        foreach ((array)@file($ini, FILE_IGNORE_NEW_LINES) as $line) {
            if ($line !== '' && $line[0] === '[') {
                $section = str_replace(['[', ']', '"'], '', $line);
                continue;
            }
            if (strpos($line, 'type=') === 0 && trim(substr($line, 5)) === '"Cache"') {
                $name = preg_replace('/[0-9]+$/', '', $section);
                if ($name !== '') $pools[$name] = true;
            }
        }
        $names = array_keys($pools);
        sort($names);
        return $names;
    }

    /**
     * The appdata share's primary pool from shares.ini (["appdata"] section,
     * cachePool key; Unraid's default pool name 'cache' when the key is empty
     * but the share exists with a cache mode). null when no appdata share or
     * it does not use a pool (useCache="no"). AICLI_SHARES_INI overrides.
     */
    public static function appdataPrimaryPool(): ?string {
        $ini = getenv('AICLI_SHARES_INI') ?: '/var/local/emhttp/shares.ini';
        if (!is_file($ini)) return null;
        $in = false; $useCache = ''; $cachePool = ''; $found = false;
        foreach ((array)@file($ini, FILE_IGNORE_NEW_LINES) as $line) {
            if ($line !== '' && $line[0] === '[') {
                if ($in) break; // left the appdata section
                $in = (str_replace(['[', ']', '"'], '', $line) === 'appdata');
                if ($in) $found = true;
                continue;
            }
            if (!$in) continue;
            if (strpos($line, 'useCache=') === 0)  $useCache  = trim(substr($line, 9), '"');
            if (strpos($line, 'cachePool=') === 0) $cachePool = trim(substr($line, 10), '"');
        }
        if (!$found || $useCache === 'no') return null;
        return $cachePool !== '' ? $cachePool : 'cache';
    }

    /**
     * Split $path into [deepest existing ancestor, '/remaining/suffix'].
     * ['', $path] never happens — the walk stops at '/'. The probe targets the
     * ancestor (findmnt can't classify non-existent paths); the suffix is
     * re-appended after any exclusive-share realpath resolution.
     * @return array{0:string,1:string}
     */
    private static function existingAncestor(string $path): array {
        $p = $path; $suffix = '';
        while ($p !== '' && $p !== '/' && !file_exists($p)) {
            $parent = dirname($p);
            if ($parent === $p) break;
            $suffix = '/' . basename($p) . $suffix;
            $p = $parent;
        }
        return [$p, $suffix];
    }

    /** Immediate subdirectories of $dir (sorted), [] when absent. */
    private static function subdirs(string $dir): array {
        $out = glob(rtrim($dir, '/') . '/*', GLOB_ONLYDIR);
        if (!is_array($out)) return [];
        sort($out);
        return $out;
    }

    /**
     * Is $path backed by a real mount right now? UD/remote paths must BE a
     * mountpoint (their parents /mnt/disks etc. are tmpfs); /mnt/user and
     * /mnt/disk<N> reuse StorageMountService::isPathAvailable (shfs / per-disk
     * /proc/mounts checks). AICLI_ITEST_MOUNTED_PATHS (colon-separated) forces
     * the verdict for tests.
     */
    private static function mounted(string $path): bool {
        $hook = getenv('AICLI_ITEST_MOUNTED_PATHS');
        if ($hook !== false) {
            return in_array($path, array_filter(explode(':', $hook)), true);
        }
        if (preg_match('#^/mnt/(user0?|disk\d+)(/|$)#', $path)) {
            return StorageMountService::isPathAvailable($path);
        }
        return self::findmntTarget($path) === $path;
    }

    /** findmnt -rn -o TARGET --target <path> via injection-safe array proc_open. */
    private static function findmntTarget(string $path): string {
        $proc = @proc_open( // nosemgrep: php.lang.security.tainted-exec.tainted-exec — array-form proc_open, no shell interpolation
            ['findmnt', '-rn', '-o', 'TARGET', '--target', $path],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes
        );
        if (!is_resource($proc)) return '';
        $out = trim((string)stream_get_contents($pipes[1]));
        fclose($pipes[1]); fclose($pipes[2]); proc_close($proc);
        return $out;
    }

    /** Free bytes (statfs) at the nearest existing ancestor of $path. */
    private static function freeBytes(string $path): int {
        $p = $path;
        while ($p !== '' && $p !== '/' && !is_dir($p)) {
            $parent = dirname($p);
            if ($parent === $p) break;
            $p = $parent;
        }
        if ($p === '' || !is_dir($p)) return 0;
        $free = @disk_free_space($p);
        return ($free === false) ? 0 : (int)$free;
    }

    /**
     * Legacy #341 verdict — findmnt fstype check, used ONLY when the capability
     * probe itself is unavailable (probe_failed). Mirrors the pre-S-11 inline
     * preflightMigrate logic byte-for-byte in behaviour.
     * @return array{ok:bool, message:string, warnings:array<int,string>, resolved_path:?string}
     */
    private static function legacyFstypeVerdict(string $path): array {
        $proc = @proc_open( // nosemgrep: php.lang.security.tainted-exec.tainted-exec — array-form proc_open, no shell interpolation
            ['findmnt', '--noheadings', '--output', 'FSTYPE', '--target', $path],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes
        );
        $fstype = '';
        if (is_resource($proc)) {
            $fstype = trim((string)stream_get_contents($pipes[1]));
            fclose($pipes[1]); fclose($pipes[2]); proc_close($proc);
        }
        $blocked = ['tmpfs', 'ramfs', 'devtmpfs', 'overlay', 'zram', 'squashfs'];
        if (in_array($fstype, $blocked, true)) {
            return ['ok' => false, 'warnings' => [], 'resolved_path' => null,
                'message' => "Persistence path '$path' is on $fstype — not a durable filesystem. "
                    . 'Choose a path on ext4, xfs, btrfs, zfs, or vfat.'];
        }
        if ($fstype === '') {
            return ['ok' => false, 'warnings' => [], 'resolved_path' => null,
                'message' => "Cannot determine filesystem type for '$path'. "
                    . 'Ensure the path exists and is mounted before setting it as the persistence location.'];
        }
        return ['ok' => true, 'message' => '', 'warnings' => ['probe_unavailable'], 'resolved_path' => null];
    }
}
