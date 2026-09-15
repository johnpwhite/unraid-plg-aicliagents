<?php
/**
 * #110: resolve a /mnt/user/<share>/… path to its pool path when the share is
 * cache-only, so an agent session's working directory does not hold the
 * /mnt/user FUSE (shfs) mount open.
 *
 * Why: a process cwd inside /mnt/user is enough on its own to make
 * `umount /mnt/user` fail, so every open workspace blocked every array stop.
 * Routing an agent's git/build/test I/O through shfs is also the traffic that
 * has wedged hosts (a burst of blocking lock waiters starves the ~10-thread
 * worker pool). For a cache-only share, /mnt/<pool>/<share> is the very same
 * files (same inodes) without FUSE in the path.
 *
 * Opt-in (Configuration → Workspace working directory). Only the LAUNCH
 * directory changes; the workspace path stays the identity used by resume
 * records, per-workspace env/args/tmux deltas and the drawer.
 *
 * Spec: docs/specs/WORKSPACE_CWD_POOL_PATH.md
 */
namespace AICliAgents\Services;

class PoolPathService {
    /** Config value that keeps today's behaviour. */
    const MODE_SHARE = 'share';
    /** Config value that launches into the pool path when the share is cache-only. */
    const MODE_POOL  = 'pool';
    const CONFIG_KEY = 'workspace_cwd';

    /** Directory holding Unraid's per-share config (`<share>.cfg`). Test seam via env. */
    public static function sharesDir(): string {
        $env = getenv('AICLI_SHARES_DIR');
        return ($env !== false && $env !== '') ? rtrim($env, '/') : '/boot/config/shares';
    }

    /** Root under which pools are mounted (`/mnt/<pool>`). Test seam via env. */
    public static function mntRoot(): string {
        $env = getenv('AICLI_MNT_ROOT');
        return ($env !== false && $env !== '') ? rtrim($env, '/') : '/mnt';
    }

    /**
     * The directory a session should launch in for $workspacePath under $config.
     * Returns $workspacePath unchanged unless the operator chose MODE_POOL AND
     * the path resolves to a pool path (see resolvePoolPath).
     */
    public static function launchDirectory(string $workspacePath, array $config): string {
        if ((string)($config[self::CONFIG_KEY] ?? self::MODE_SHARE) !== self::MODE_POOL) return $workspacePath;
        return self::resolvePoolPath($workspacePath) ?? $workspacePath;
    }

    /**
     * /mnt/user/<share>/<rest> → /mnt/<pool>/<share>/<rest> when, and only when:
     *   - the share's cfg says shareUseCache="only" (the data lives on the pool
     *     alone, so the two paths are the same files), and
     *   - the pool directory for the share exists.
     * Any other path (a share on the array, "prefer"/"yes" caching where files
     * may live on either side, /mnt/user0, a pool path already) returns null:
     * fail safe, never rewrite to a directory that may not hold the files.
     */
    public static function resolvePoolPath(string $path): ?string {
        $path = rtrim($path, '/');
        if (!preg_match('#^/mnt/user/([^/]+)(/.*)?$#', $path, $m)) return null;
        $share = $m[1]; $rest = $m[2] ?? '';
        $cfg = self::readShareCfg($share);
        if ($cfg === null) return null;
        if (($cfg['shareUseCache'] ?? '') !== 'only') return null;
        $pool = (string)($cfg['shareCachePool'] ?? '');
        if ($pool === '') $pool = 'cache';
        if (!preg_match('/^[A-Za-z0-9_.-]+$/', $pool)) return null;
        $target = self::mntRoot() . "/$pool/$share";
        if (!is_dir($target)) return null;
        $resolved = $target . $rest;
        return is_dir($resolved) ? $resolved : null;
    }

    /** @return array<string,string>|null key=value pairs from `<share>.cfg`, null when absent. */
    public static function readShareCfg(string $share): ?array {
        if (!preg_match('/^[A-Za-z0-9_. -]+$/', $share)) return null;
        $file = self::sharesDir() . "/$share.cfg";
        if (!is_file($file)) return null;
        $out = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (!preg_match('/^([A-Za-z0-9_]+)="?([^"]*)"?$/', trim($line), $kv)) continue;
            $out[$kv[1]] = $kv[2];
        }
        return $out;
    }
}
