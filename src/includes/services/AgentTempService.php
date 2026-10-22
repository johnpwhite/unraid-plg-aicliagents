<?php
/**
 * <module_context>
 * Description: The setting "Agent temporary folder" (docs/specs/AGENT_TEMP_FOLDER.md, #554).
 *   Agents write temporary files to /tmp, and /tmp is RAM on Unraid. This class holds
 *   (1) the rules for the setting: location ram, pool or boot, and the folder of a pool;
 *   (2) the measure of the agent temporary folders in RAM, for the Boot disk space card;
 *   (3) the clean-up of the temporary folders of sessions that no longer run.
 * Dependencies: ConfigService, StoragePathResolver, AgentStorageService (deviceClass),
 *   BootSpaceService (duBytes).
 * Constraints: measure(), sessions() and cleanup() run only in the background measure or in a
 *   supervisor job, never in a web request. cleanup() never removes a folder of a session that
 *   runs, never follows a symbolic link, and never leaves /tmp/claude-<uid>/<project>/<session>.
 *   Every path comes from a seam in tests (AICLI_AGENT_TMP_ROOT, AICLI_AGENT_TMP_PROC,
 *   AICLI_AGENT_TMP_MNT); production passes none.
 * </module_context>
 */

namespace AICliAgents\Services;

class AgentTempService
{
    public const LOCATIONS = ['ram', 'pool', 'boot'];
    /** A session folder is removed only when nothing under it changed for this many seconds (24 hours). */
    public const IDLE_SECONDS = 86400;

    /** @var (callable(string):string)|null test seam: path -> 'internal' | 'usb' | 'other'. */
    public static $deviceClassProvider = null;

    // ---- places -------------------------------------------------------------------

    /** The folder that holds the RAM temporary folders of the agents (/tmp). */
    public static function tmpRoot(): string
    {
        $e = getenv('AICLI_AGENT_TMP_ROOT');
        return ($e !== false && $e !== '') ? rtrim($e, '/') : '/tmp';
    }

    private static function procRoot(): string
    {
        $e = getenv('AICLI_AGENT_TMP_PROC');
        return ($e !== false && $e !== '') ? rtrim($e, '/') : '/proc';
    }

    /** The mount root of the pools and disks (/mnt). */
    private static function mntRoot(): string
    {
        $e = getenv('AICLI_AGENT_TMP_MNT');
        return ($e !== false && $e !== '') ? rtrim($e, '/') : '/mnt';
    }

    /** The folder of the choice "boot": next to the persistence data on the boot disk. */
    public static function bootFolder(): string
    {
        require_once __DIR__ . '/StoragePathResolver.php';
        return rtrim(StoragePathResolver::pluginBase(), '/') . '/agent-tmp';
    }

    // ---- the rules of the setting ---------------------------------------------------

    public static function normaliseLocation($value): string
    {
        $v = is_scalar($value) ? strtolower(trim((string)$value)) : '';
        return in_array($v, self::LOCATIONS, true) ? $v : 'ram';
    }

    /** The warning limit in MB: a whole number from 64 to 1048576; default 1024. */
    public static function normaliseWarnMb($value): string
    {
        if (!is_scalar($value) || preg_match('/^\d{1,8}$/D', trim((string)$value)) !== 1) return '1024';
        return (string)max(64, min(1048576, (int)trim((string)$value)));
    }

    /**
     * True when the boot device is an internal disk (not a USB stick). Uses the same check as the
     * rule for agent storage: AgentStorageService::deviceClass() on the plugin folder. A web
     * request may call it: it runs one read-only probe, no size measurement.
     */
    public static function bootAllowed(): bool
    {
        try {
            $path = rtrim(StoragePathResolver::pluginBase(), '/');
            if (self::$deviceClassProvider !== null) {
                return (self::$deviceClassProvider)($path) === 'internal';
            }
            require_once __DIR__ . '/AgentStorageService.php';
            return AgentStorageService::deviceClass($path) === 'internal';
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Resolve a path with its symbolic links. The folder may not exist yet: the deepest existing
     * parent is resolved and the rest is added. Returns '' for a path that is not absolute.
     */
    public static function resolve(string $path): string
    {
        if ($path === '' || $path[0] !== '/') return '';
        $parts = [];
        foreach (explode('/', $path) as $p) {
            if ($p === '' || $p === '.') continue;
            if ($p === '..') { array_pop($parts); continue; }
            $parts[] = $p;
        }
        $tail = [];
        while ($parts) {
            $try = '/' . implode('/', $parts);
            $real = @realpath($try);
            if ($real !== false) {
                return rtrim($real, '/') . ($tail ? '/' . implode('/', array_reverse($tail)) : '');
            }
            array_unshift($tail, array_pop($parts));
        }
        return '/' . implode('/', $tail);
    }

    /** True when $dir is a mount point: its device differs from the device of its parent. */
    public static function isMountPoint(string $dir): bool
    {
        $a = @stat($dir);
        $b = @stat(dirname($dir));
        return is_array($a) && is_array($b) && $a['dev'] !== $b['dev'];
    }

    /**
     * Check a folder for the choice "pool". A refusal carries a plain reason.
     * @return array{ok:bool,path:string,reason:string}
     */
    public static function checkPoolPath(string $path, ?callable $isMount = null): array
    {
        $path = trim($path);
        if ($path === '' || $path[0] !== '/') {
            return ['ok' => false, 'path' => '', 'reason' => 'Enter a full folder path on a pool or a disk, for example /mnt/cache/agent-tmp.'];
        }
        if (preg_match('#(^|/)\.\.(/|$)#', $path) === 1) {
            // A .. part after a symbolic link leads somewhere else than the text says. Refuse it.
            return ['ok' => false, 'path' => '', 'reason' => 'The folder path must not contain ".." parts. Give the full path.'];
        }
        $mnt = self::mntRoot();
        $mntReal = (string)(@realpath($mnt) ?: $mnt);   // /mnt itself may be a link in a test
        $isUser = static function (string $p) use ($mnt, $mntReal): bool {
            foreach (array_unique([$mnt, $mntReal]) as $r) {
                if (preg_match('#^' . preg_quote($r, '#') . '/user0?(/|$)#', $p) === 1) return true;
            }
            return false;
        };
        $real = self::resolve($path);
        if ($isUser($path) || $isUser($real)) {
            return ['ok' => false, 'path' => '', 'reason' => 'A folder under /mnt/user or /mnt/user0 is not accepted. Those paths use the user-share file system, and heavy work with many small files there can freeze the server. Use the path of a pool, for example /mnt/cache/agent-tmp.'];
        }
        if (preg_match('#^' . preg_quote($mntReal, '#') . '/([A-Za-z0-9._-]+)(/.*)?$#', $real, $m) !== 1) {
            return ['ok' => false, 'path' => '', 'reason' => 'The folder must be on a pool or a disk, under /mnt/<name>, for example /mnt/cache/agent-tmp.'];
        }
        $top = $mntReal . '/' . $m[1];
        $mounted = $isMount !== null ? (bool)$isMount($top) : self::isMountPoint($top);
        if (!$mounted) {
            return ['ok' => false, 'path' => '', 'reason' => 'The folder is not on a mounted pool or disk (' . $top . ' is not mounted).'];
        }
        return ['ok' => true, 'path' => $real, 'reason' => ''];
    }

    /**
     * Check the pair (location, path) of a save. Never throws.
     * @return array{ok:bool,location:string,path:string,reason:string}
     */
    public static function checkSetting($location, $path, ?callable $isMount = null): array
    {
        $loc = self::normaliseLocation($location);
        if ($loc === 'ram') return ['ok' => true, 'location' => 'ram', 'path' => (string)$path, 'reason' => ''];
        if ($loc === 'boot') {
            if (!self::bootAllowed()) {
                return ['ok' => false, 'location' => 'ram', 'path' => (string)$path,
                    'reason' => 'The boot disk is not an internal disk. A USB stick wears out with many small writes, so the boot disk is not accepted for agent temporary files. Choose RAM or a folder on a pool.'];
            }
            return ['ok' => true, 'location' => 'boot', 'path' => (string)$path, 'reason' => ''];
        }
        $r = self::checkPoolPath((string)$path, $isMount);
        return ['ok' => $r['ok'], 'location' => 'pool', 'path' => $r['ok'] ? $r['path'] : (string)$path, 'reason' => $r['reason']];
    }

    // ---- sessions in RAM ------------------------------------------------------------

    /**
     * The session folders in RAM: /tmp/claude-<uid>/<project>/<session>. Each row: path, bytes, mtime.
     * Background only.
     * @return array<int,array{path:string,bytes:int,mtime:int}>
     */
    public static function sessions(?callable $du = null): array
    {
        $du = $du ?? [BootSpaceService::class, 'duBytes'];
        $rows = [];
        foreach (self::claudeRoots() as $root) {
            foreach (self::subdirs($root) as $project) {
                foreach (self::subdirs($project) as $session) {
                    $rows[] = ['path' => $session, 'bytes' => (int)$du($session), 'mtime' => (int)@filemtime($session)];
                }
            }
        }
        return $rows;
    }

    /** @return string[] /tmp/claude-<uid> folders (real folders, not links). */
    private static function claudeRoots(): array
    {
        $out = [];
        foreach ((array)glob(self::tmpRoot() . '/claude-*', GLOB_ONLYDIR) as $d) {
            if (!is_link($d)) $out[] = $d;
        }
        return $out;
    }

    /** @return string[] the sub-folders of $dir that are not links. */
    private static function subdirs(string $dir): array
    {
        $out = [];
        foreach ((array)glob($dir . '/*', GLOB_ONLYDIR) as $d) {
            if (!is_link($d)) $out[] = $d;
        }
        return $out;
    }

    /**
     * True when the session of folder $dir still runs: a live process has a file, its working
     * folder or its root open under it, or its id (the folder name) is in the command line of a
     * live process. (An idle session has none of these: see isClosed().)
     */
    public static function isRunning(string $dir): bool
    {
        $id = basename($dir);
        $dir = rtrim($dir, '/');
        foreach ((array)glob(self::procRoot() . '/[0-9]*', GLOB_ONLYDIR) as $p) {
            $cmd = @file_get_contents($p . '/cmdline');
            if (is_string($cmd) && $id !== '' && strpos($cmd, $id) !== false) return true;
            foreach (['cwd', 'root'] as $l) {
                $t = @readlink($p . '/' . $l);
                if (is_string($t) && ($t === $dir || strpos($t, $dir . '/') === 0)) return true;
            }
            foreach ((array)glob($p . '/fd/*') as $fd) {
                $t = @readlink($fd);
                if (is_string($t) && ($t === $dir || strpos($t, $dir . '/') === 0)) return true;
            }
        }
        return false;
    }

    /**
     * The folders where work happens now: the working folder of every live process, and the path
     * of every running workspace of the plugin (the paths of the live agent tmux sessions).
     * @return string[]
     */
    public static function livePaths(): array
    {
        $out = [];
        foreach ((array)glob(self::procRoot() . '/[0-9]*', GLOB_ONLYDIR) as $p) {
            $t = @readlink($p . '/cwd');
            if (is_string($t) && $t !== '' && $t[0] === '/') $out[$t] = true;
        }
        try {
            if (self::$workspacePathsProvider !== null) {
                $ws = (array)(self::$workspacePathsProvider)();
            } else {
                $ws = array_column(ProcessManager::listAgentTmuxSessions(), 'path');
            }
            foreach ($ws as $w) {
                if (is_string($w) && $w !== '' && $w[0] === '/') $out[$w] = true;
            }
        } catch (\Throwable $e) { /* the process list still protects the projects */ }
        return array_keys($out);
    }

    /** @var (callable():array)|null test seam: the paths of the running workspaces. */
    public static $workspacePathsProvider = null;

    /** The two ways Claude Code may turn a working path into a project folder name. */
    private static function encodings(string $path): array
    {
        return [preg_replace('/[^A-Za-z0-9]/', '-', $path), str_replace('/', '-', $path)];
    }

    /**
     * True when the project folder $project (a name such as -mnt-cache-myproj) belongs to a live
     * path: the working path of the project is one of $paths or a parent of one of them. The name is
     * not decoded. It is compared with the encoded form of each path and of each parent folder.
     * @param string[] $paths
     */
    public static function projectKept(string $project, array $paths): bool
    {
        foreach ($paths as $p) {
            $p = rtrim((string)$p, '/');
            while ($p !== '') {
                if (in_array($project, self::encodings($p), true)) return true;
                $cut = strrpos($p, '/');
                if ($cut === false || $cut === 0) break;
                $p = substr($p, 0, $cut);
            }
        }
        return false;
    }

    /** True when nothing under $dir (a link is not followed) changed since $cutoff (Unix time). */
    public static function unchangedSince(string $dir, int $cutoff): bool
    {
        $mt = @filemtime($dir);
        if ($mt === false || $mt >= $cutoff) return false;
        $names = @scandir($dir);
        if ($names === false) return false;
        foreach ($names as $n) {
            if ($n === '.' || $n === '..') continue;
            $p = $dir . '/' . $n;
            if (is_link($p)) {
                $lm = @lstat($p);
                if ($lm === false || $lm['mtime'] >= $cutoff) return false;
            } elseif (is_dir($p)) {
                if (!self::unchangedSince($p, $cutoff)) return false;
            } else {
                $fm = @filemtime($p);
                if ($fm === false || $fm >= $cutoff) return false;
            }
        }
        return true;
    }

    /**
     * True when the session folder may be removed: its project is not kept by a live path, no live
     * process holds it or names its id, and nothing under it changed for IDLE_SECONDS (24 hours).
     * @param string[] $paths from livePaths()
     */
    public static function isClosed(string $session, array $paths, ?int $now = null): bool
    {
        $now = $now ?? time();
        if (self::projectKept(basename(dirname($session)), $paths)) return false;
        if (self::isRunning($session)) return false;
        return self::unchangedSince($session, $now - self::IDLE_SECONDS);
    }

    /**
     * The answer the background measure keeps for the card: ram_bytes (all session folders),
     * closed_bytes (the folders a clean-up removes), warn_mb and warn (ram_bytes over the limit).
     * @return array<string,mixed>
     */
    public static function measure(array $seams = []): array
    {
        $rows = $seams['sessions'] ?? self::sessions($seams['du'] ?? null);
        $now = (int)($seams['now'] ?? time());
        $paths = $seams['live_paths'] ?? (isset($seams['is_running']) ? [] : self::livePaths());
        $inUse = $seams['is_running'] ?? static fn(string $d): bool => !self::isClosed($d, $paths, $now);
        $ram = 0;
        $closed = 0;
        $count = 0;
        foreach ($rows as $r) {
            $ram += (int)$r['bytes'];
            if (!$inUse((string)$r['path'])) { $closed += (int)$r['bytes']; $count++; }
        }
        $warnMb = (int)($seams['warn_mb'] ?? self::normaliseWarnMb(ConfigService::getConfig()['agent_tmp_warn_mb'] ?? '1024'));
        return [
            'ram_bytes' => $ram, 'closed_bytes' => $closed, 'closed_sessions' => $count,
            'sessions' => count($rows), 'warn_mb' => $warnMb,
            'warn' => $ram > $warnMb * 1048576,
        ];
    }

    /**
     * Remove the folders of the sessions that ended at least a day ago and whose project has no live
     * path. Checks each folder again right before it removes it. Returns {removed, freed_bytes, skipped}.
     * @param array<string,mixed> $seams test seams: sessions (rows), is_running (fn(path):bool, true = keep),
     *        live_paths (array), now (int), du
     * @return array{removed:int,freed_bytes:int,skipped:int}
     */
    public static function cleanup(array $seams = []): array
    {
        $rows = $seams['sessions'] ?? self::sessions($seams['du'] ?? null);
        $now = (int)($seams['now'] ?? time());
        $paths = $seams['live_paths'] ?? (isset($seams['is_running']) ? [] : self::livePaths());
        $inUse = $seams['is_running'] ?? static fn(string $d): bool => !self::isClosed($d, $paths, $now);
        $removed = 0;
        $freed = 0;
        $skipped = 0;
        foreach ($rows as $r) {
            $path = (string)$r['path'];
            if (!self::isSessionPath($path) || $inUse($path)) { $skipped++; continue; }
            if (self::removeTree($path)) {
                $removed++;
                $freed += (int)$r['bytes'];
                @rmdir(dirname($path));   // the project folder, only when it is empty now
            } else {
                $skipped++;
            }
        }
        return ['removed' => $removed, 'freed_bytes' => $freed, 'skipped' => $skipped];
    }

    /** True for <tmp root>/claude-<x>/<project>/<session> with no link and no `..`. */
    public static function isSessionPath(string $path): bool
    {
        $root = preg_quote(self::tmpRoot(), '#');
        if (preg_match('#^' . $root . '/claude-[A-Za-z0-9._-]+/[^/]+/[^/]+$#', $path) !== 1) return false;
        if (strpos($path, '..') !== false) return false;
        return is_dir($path) && !is_link($path) && !is_link(dirname($path)) && !is_link(dirname($path, 2));
    }

    /** Remove a folder tree. Never follows a symbolic link: a link is removed, not entered. */
    private static function removeTree(string $dir): bool
    {
        if (is_link($dir) || !is_dir($dir)) return false;
        $ok = true;
        $it = @scandir($dir);
        if ($it === false) return false;
        foreach ($it as $n) {
            if ($n === '.' || $n === '..') continue;
            $p = $dir . '/' . $n;
            if (is_link($p) || !is_dir($p)) {
                if (!@unlink($p)) $ok = false;
            } elseif (!self::removeTree($p)) {
                $ok = false;
            }
        }
        return @rmdir($dir) && $ok;
    }
}
