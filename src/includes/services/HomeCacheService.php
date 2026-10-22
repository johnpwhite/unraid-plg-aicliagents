<?php
namespace AICliAgents\Services;

/**
 * <module_context>
 * Description: Cache folders of a plain-folder home (docs/specs/BOOT_DISK_SPACE.md R5,
 *   #537, fixes #533). A plain home keeps its caches (npm, pip, tool downloads) in RAM
 *   or in a pool folder, not on the boot disk. The shell side does the mounts
 *   (src/scripts/storage/home_cache.sh). This class gives the Storage tab the cache
 *   list, the status of one home, the "Clear caches" action, and the checks for the
 *   three settings.
 * Dependencies: src/scripts/storage/home-cache.sh, TerminalService.
 * Constraints: Pure functions take their input as arguments, so tests need no host.
 * </module_context>
 */
class HomeCacheService
{
    private const SCRIPT = '/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/storage/home-cache.sh';

    /**
     * A pinned copy of home_cache_dirs (src/scripts/storage/common.sh), the one shell
     * list. HomeCacheTest compares the two, so they cannot drift.
     * Not in the list: .claude/plugins (installed plugins) and .gemini/tmp (chat logs).
     */
    private const CACHE_DIRS = [
        '.npm', '.cache', '.bun/install/cache', '.claude/cache',
        '.claude/shell-snapshots', '.claude/telemetry', 'tmp',
    ];

    public const RAM_MB_MIN = 256;
    public const RAM_MB_MAX = 8192;
    public const RAM_MB_DEFAULT = 1024;

    /** The cache folders of a home, relative to the home. @return string[] */
    public static function cacheDirs(): array
    {
        return self::CACHE_DIRS;
    }

    // ---- settings checks (pure) -------------------------------------------------

    /** "ram" or "pool". Anything else is "ram". */
    public static function normaliseLocation($value): string
    {
        return is_string($value) && trim($value) === 'pool' ? 'pool' : 'ram';
    }

    /** An integer from 256 to 8192. A value that is not a number gives 1024. */
    public static function normaliseRamMb($value): string
    {
        $v = is_scalar($value) ? trim((string)$value) : '';
        if ($v === '' || preg_match('/^\d+$/D', $v) !== 1) return (string)self::RAM_MB_DEFAULT;
        return (string)max(self::RAM_MB_MIN, min(self::RAM_MB_MAX, (int)$v));
    }

    /**
     * "Cache folder on a pool": '' (none) or an absolute folder under a pool, /mnt/<pool>/...
     * Not accepted: the user shares (/mnt/user, /mnt/user0: they go through FUSE and can
     * freeze the host), array disks, unassigned and remote disks, a `..` part, odd
     * characters. A bad value returns $stored. The shell side checks again that the
     * pool is mounted.
     */
    public static function normalisePath($value, string $stored): string
    {
        $v = is_string($value) ? trim($value) : '';
        if ($v === '') return '';
        $v = rtrim($v, '/');
        if ($v === ''
            || preg_match('#^/mnt/[A-Za-z0-9._-]+(/[A-Za-z0-9._ +-]+)*$#D', $v) !== 1
            || strpos($v, '..') !== false
            || preg_match('#(^|/)\.(/|$)#', $v) === 1   // R-123 (#662): a "." segment hides "user" from the next check
            || preg_match('#^/mnt/(user|user0|disks|remotes|rootshare|addons|disk[0-9]+)(/|$)#', $v) === 1) {
            return $stored;
        }
        return $v;
    }

    /**
     * Health: the RAM caps of the mounted plain homes together. Over 25% of the RAM is a
     * warning. Pure. $capsBytes is home id => cap in bytes.
     * @param array<string,int> $capsBytes
     * @return array{status:string,message:string}
     */
    public static function evalRamCaps(array $capsBytes, int $ramBytes): array
    {
        $sum = (int)array_sum($capsBytes);
        $mb = static function (int $b): string { return (string)(int)round($b / 1048576); };
        if ($ramBytes <= 0 || $sum <= 0) {
            return ['status' => 'ok', 'message' => 'No RAM cache is set.'];
        }
        $pct = $sum * 100.0 / $ramBytes;
        if ($pct > 25.0) {
            return ['status' => 'warn', 'message' => sprintf(
                'The RAM caches of %d plain-folder home(s) can use up to %s MB together: %.0f%% of the RAM (over 25%%). Lower "Memory for caches, per home" in the Configuration tab, or move the caches to a pool.',
                count($capsBytes), $mb($sum), $pct)];
        }
        return ['status' => 'ok', 'message' => sprintf('RAM caches may use up to %s MB together (%.0f%% of the RAM).', $mb($sum), $pct)];
    }

    // ---- the host (shell) --------------------------------------------------------

    /**
     * The state of the caches of one home.
     * location: ram | pool (caches are bound there), boot (a plain home whose caches are
     * still on the boot disk), none (not a plain home).
     * @return array{location:string,path:string,bytes:int,cap_bytes:int,on_boot_bytes:int}
     */
    public static function status(string $user): array
    {
        $none = ['location' => 'none', 'path' => '', 'bytes' => 0, 'cap_bytes' => 0, 'on_boot_bytes' => 0];
        if (preg_match('/^[A-Za-z0-9._-]+$/D', $user) !== 1) return $none;
        // Background measurement only (#552). The time left on the shared deadline caps the script.
        require_once __DIR__ . '/MeasureDeadline.php';
        $left = MeasureDeadline::remaining(120);
        if ($left < 1) { MeasureDeadline::markPartial(); return $none; }
        $out = self::run(['status', $user], $left);
        $j = json_decode($out, true);
        if (!is_array($j) || !isset($j['location'])) return $none;
        $loc = in_array($j['location'], ['ram', 'pool', 'boot', 'none'], true) ? $j['location'] : 'none';
        return [
            'location'      => $loc,
            'path'          => (string)($j['path'] ?? ''),
            'bytes'         => (int)($j['bytes'] ?? 0),
            'cap_bytes'     => (int)($j['cap_bytes'] ?? 0),
            'on_boot_bytes' => (int)($j['on_boot_bytes'] ?? 0),
        ];
    }

    /**
     * "Clear caches": empty the cache folders of a home. Refused while a workspace of the
     * home runs. $sessionsOf is a test seam (default: TerminalService::listActiveSessionsForHome).
     * @return array{status:string,message:string,freed_bytes?:int}
     */
    public static function clear(string $user, ?callable $sessionsOf = null): array
    {
        if (preg_match('/^[A-Za-z0-9._-]+$/D', $user) !== 1) {
            return ['status' => 'error', 'message' => 'invalid_user'];
        }
        $sessionsOf = $sessionsOf ?? static function (string $u): array {
            return TerminalService::listActiveSessionsForHome($u);
        };
        $running = $sessionsOf($user);
        if (!empty($running)) {
            return ['status' => 'error', 'message' => 'Close the workspaces of this home first. A running workspace uses its caches.'];
        }
        // No measuring in a web request (#552): a plain home is a folder under the persistence
        // folder. The script runs with AICLI_HC_NO_MEASURE=1, so it runs no du. The caller
        // reads the freed size from the last background measurement.
        if (!is_dir(self::plainHomeDir($user))) {
            return ['status' => 'error', 'message' => 'This home is not a plain-folder home.'];
        }
        $out = self::run(['clear', $user], 120, true);
        $j = json_decode($out, true);
        if (!is_array($j) || ($j['status'] ?? '') !== 'ok') {
            $msg = is_array($j) && !empty($j['message']) ? (string)$j['message'] : 'The caches could not be cleared.';
            return ['status' => 'error', 'message' => $msg];
        }
        return ['status' => 'ok', 'message' => 'Caches cleared.', 'freed_bytes' => (int)($j['freed_bytes'] ?? 0)];
    }

    /**
     * Health input: the RAM caps (bytes) of the plain homes whose caches are in RAM, read
     * from the state files the mount step writes. @return array<string,int>
     */
    public static function ramCaps(): array
    {
        $base = getenv('AICLI_HOME_CACHE_BASE') ?: SecretPaths::stateRoot() . '/cache';
        $mb = (int)self::normaliseRamMb(ConfigService::getConfig()['home_cache_ram_mb'] ?? '');
        $caps = [];
        foreach (@glob($base . '/.state/*') ?: [] as $f) {
            $kv = @parse_ini_file($f);
            if (is_array($kv) && ($kv['location'] ?? '') === 'ram') {
                $caps[basename($f)] = $mb * 1048576;
            }
        }
        return $caps;
    }

    /** Total RAM in bytes (0 when unknown). */
    public static function totalRamBytes(): int
    {
        $mi = is_readable('/proc/meminfo') ? (string)@file_get_contents('/proc/meminfo') : '';
        return preg_match('/^MemTotal:\s+(\d+)\s+kB/m', $mi, $m) === 1 ? (int)$m[1] * 1024 : 0;
    }

    /** The folder of a plain-folder home (a cheap is_dir check, no measuring). */
    private static function plainHomeDir(string $user): string
    {
        $base = getenv('AICLI_PERSIST_PATH');
        if ($base === false || $base === '') {
            // R-046 (#624): the one resolver, with the same fall-through as the shell.
            $base = StoragePathResolver::homePersistPath($user);
        }
        return rtrim($base, '/') . '/passthrough/homes/' . $user;
    }

    /**
     * @param string[] $args
     * @param int  $timeout the longest the script may run (seconds); the background
     *        measurement passes the time left on its shared deadline
     * @param bool $noMeasure true: the script runs no du (AICLI_HC_NO_MEASURE=1)
     */
    private static function run(array $args, int $timeout = 120, bool $noMeasure = false): string
    {
        $timeout = max(1, $timeout);
        $cmd = ($noMeasure ? 'AICLI_HC_NO_MEASURE=1 ' : '') . 'timeout ' . $timeout . ' bash ' . escapeshellarg(self::SCRIPT);
        foreach ($args as $a) $cmd .= ' ' . escapeshellarg($a);
        return trim((string)@shell_exec($cmd . ' 2>/dev/null'));
    }
}
