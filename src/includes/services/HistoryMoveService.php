<?php
/**
 * <module_context>
 * Description: Moves old conversation sessions out of a home to a folder on a
 *   pool, and brings them back (docs/specs/BOOT_DISK_SPACE.md R6, issue #539).
 *   Each file is copied, checked (size and SHA-256), and only then removed from
 *   the home. A write-ahead log makes a crash safe: after any crash each file is
 *   in the home, or checked at the destination, never in neither place.
 * Dependencies: HistoryStoreService (what to move). No plugin config, no
 *   AJAX context: the supervisor job runs it from scripts/storage/history-move.php.
 * Constraints: Never deletes data that is not verified at the destination.
 *   Never overwrites a file in the home when it brings a session back.
 *   Refuses when the destination is not on a mounted pool.
 * </module_context>
 *
 * Files in <dest>/<user>/ :
 *   <agent>/<home-relative path>   the moved files
 *   MOVED.wal                      the write-ahead log (one JSON object per line)
 *   MOVED.json                     the list a person reads: each file, size, sha256, moved_at
 *
 * Log records (field "t"):
 *   begin     a copy starts (file, agent, size, sha256, mtime, mode)
 *   copied    the copy is checked; the source may go
 *   removed   the source is gone; the file is moved
 *   restoring a bring back starts for the file
 *   restored  the file is back in the home; the stored copy may go
 *   abort     the move of this file stopped; nothing was lost
 *
 * The state of a file is the "t" of its last record. Recovery reads the log
 * before every run (see recover()).
 */

namespace AICliAgents\Services;

class HistoryMoveService
{
    public const REFUSE_ARRAY_STOPPED = 'The array is stopped. Start the array, then try again.';

    public const REFUSE_BUSY = 'Another move of old sessions is running. Wait until it ends, then try again.';

    /** Seconds a second job polls for the run lock before it gives up. */
    private const LOCK_WAIT_SECS = 3.0;

    /**
     * Take the run lock of one store (<base>/MOVED.lock) for a whole move, bring back or
     * recovery. Non-blocking, polled to a deadline: a blocking flock can hang on a busy
     * disk. The lock goes with the process, so a crashed job never leaves it held.
     * $opts['lock_wait'] (seconds) is a test seam. Returns the handle, or null when busy.
     *
     * @return resource|null
     */
    private static function lockStore(string $base, array $opts)
    {
        $fh = @fopen($base . '/MOVED.lock', 'c');
        if ($fh === false) return null;
        $deadline = microtime(true) + (float)($opts['lock_wait'] ?? self::LOCK_WAIT_SECS);
        do {
            if (@flock($fh, LOCK_EX | LOCK_NB)) return $fh;
            usleep(100000);
        } while (microtime(true) < $deadline);
        fclose($fh);
        return null;
    }

    /** @param resource $fh */
    private static function unlockStore($fh): void
    {
        @flock($fh, LOCK_UN);
        fclose($fh);
    }

    /** MOVED.json is rewritten after this many files, and at the end. */
    private const LEDGER_FLUSH_EVERY = 25;

    /**
     * Is $dest on a mounted pool? Default check: the path is /mnt/<name>/...,
     * <name> is not a share view (user, user0), and /mnt/<name> is a mount point.
     * AICLI_MNT_ROOT redirects /mnt for tests.
     */
    public static function destinationMounted(string $dest): bool
    {
        $root = getenv('AICLI_MNT_ROOT');
        $root = ($root !== false && $root !== '') ? rtrim($root, '/') : '/mnt';
        $d = rtrim($dest, '/');
        if (strncmp($d, $root . '/', strlen($root) + 1) !== 0) return false;
        $name = explode('/', substr($d, strlen($root) + 1))[0];
        if ($name === '' || in_array($name, ['user', 'user0', 'remotes', 'rootshare', 'addons'], true)) return false;
        $pool = $root . '/' . $name;
        if (!is_dir($pool)) return false;
        $ex = getenv('AICLI_ITEST_MOUNTED_PATHS');
        if ($ex !== false && $ex !== '') return in_array($pool, explode(':', $ex), true);
        return self::isMountPoint($pool);
    }

    /**
     * The mounted pools and disks a person may pick as the destination.
     * @return array<int,array{path:string,name:string,free_bytes:int}>
     */
    public static function mountedPools(): array
    {
        $root = getenv('AICLI_MNT_ROOT');
        $root = ($root !== false && $root !== '') ? rtrim($root, '/') : '/mnt';
        $out = [];
        foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $name = basename($dir);
            if (in_array($name, ['user', 'user0', 'remotes', 'rootshare', 'addons'], true)) continue;
            if (!self::destinationMounted($dir . '/x')) continue;
            $out[] = ['path' => $dir, 'name' => $name, 'free_bytes' => (int)@disk_free_space($dir)];
        }
        return $out;
    }

    private static function isMountPoint(string $path): bool
    {
        $mi = @file('/proc/self/mountinfo', FILE_IGNORE_NEW_LINES);
        if ($mi === false) return false;
        $real = @realpath($path) ?: $path;
        foreach ($mi as $line) {
            $f = explode(' ', $line);
            if (isset($f[4]) && str_replace('\040', ' ', $f[4]) === $real) return true;
        }
        return false;
    }

    /** True when $dest is an absolute path of plain characters with no `..`. The pool check is destinationMounted(); the setting must also pass normaliseSetting(). */
    public static function destinationShapeOk(string $dest): bool
    {
        $d = rtrim(trim($dest), '/');
        return $d !== '' && preg_match('#^/[A-Za-z0-9._ /+-]+$#D', $d) === 1 && strpos($d, '..') === false;
    }

    /**
     * The value stored for "Folder for moved sessions" (history_move_path):
     * empty, or an absolute folder under /mnt/<pool>. Not /mnt/user (the
     * share view is FUSE), not the boot disk, not RAM. A bad value gives $stored.
     */
    public static function normaliseSetting(string $value, string $stored): string
    {
        $v = rtrim(trim($value), '/');
        if ($v === '') return '';
        if (!self::destinationShapeOk($v)) return $stored;
        $root = getenv('AICLI_MNT_ROOT');
        $root = ($root !== false && $root !== '') ? rtrim($root, '/') : '/mnt';
        if (strncmp($v, $root . '/', strlen($root) + 1) !== 0) return $stored;
        if (preg_match('#^([A-Za-z0-9._-]+)(/|$)#D', substr($v, strlen($root) + 1), $m) !== 1) return $stored;
        if (in_array($m[1], ['user', 'user0', 'remotes', 'rootshare', 'addons'], true)) return $stored;
        return $v;
    }

    /**
     * Move the old sessions of one agent.
     *
     * $opts (all optional, test seams in brackets):
     *   'now' int; 'open' callable (open files provider); 'mounted' callable(dest):bool;
     *   'copy' callable(src,dst):bool (the copier); 'free' callable(dir):int bytes;
     *   'progress' callable(array) called after each file; 'files' array (paths to move,
     *   instead of selecting).
     *
     * @return array{status:string,message:string,moved:int,bytes:int,skipped:array<int,array{file:string,reason:string}>}
     */
    public static function move(string $homeDir, string $user, string $agentId, int $days, string $dest, array $opts = []): array
    {
        $res = ['status' => 'ok', 'message' => '', 'moved' => 0, 'bytes' => 0, 'skipped' => []];
        if (!HistoryStoreService::isMovable($agentId)) {
            return self::fail($res, 'This agent keeps its history in a database, so its sessions cannot move.');
        }
        if (!self::destinationShapeOk($dest)) {
            return self::fail($res, 'The folder for moved sessions is not valid.');
        }
        $mounted = $opts['mounted'] ?? [self::class, 'destinationMounted'];
        if (!$mounted($dest)) return self::fail($res, self::REFUSE_ARRAY_STOPPED);

        $base = rtrim($dest, '/') . '/' . $user;
        if (!is_dir($base) && !@mkdir($base, 0755, true) && !is_dir($base)) {
            return self::fail($res, 'The folder for moved sessions cannot be created.');
        }
        // #587: one move or bring back at a time on one log. An orphaned job of a killed
        // supervisor can still run; a second job would read its live log in recover().
        $lock = self::lockStore($base, $opts);
        if ($lock === null) return self::fail($res, self::REFUSE_BUSY);
        try {
            return self::moveLocked($homeDir, $agentId, $days, $base, $opts, $res);
        } finally {
            self::unlockStore($lock);
        }
    }

    /** @param array<string,mixed> $res */
    private static function moveLocked(string $homeDir, string $agentId, int $days, string $base, array $opts, array $res): array
    {
        self::recover($homeDir, $base, $opts);

        $paths = $opts['files'] ?? HistoryStoreService::selectOld($homeDir, $agentId, $days, $opts['open'] ?? null, $opts['now'] ?? null);
        $total = 0;
        foreach ($paths as $rel) $total += HistoryStoreService::allocated(rtrim($homeDir, '/') . '/' . $rel);
        $free = isset($opts['free']) ? (int)$opts['free']($base) : (int)@disk_free_space($base);
        if ($free > 0 && $total + 64 * 1048576 > $free) {
            return self::fail($res, 'The destination has not enough free space for these sessions.');
        }

        $log = self::walOpen($base);
        $done = 0;
        $movedRels = [];
        $count = count($paths);
        $copier = $opts['copy'] ?? [self::class, 'copyFile'];
        $openNow = $opts['open'] ?? null;
        foreach ($paths as $rel) {
            $src = rtrim($homeDir, '/') . '/' . $rel;
            $dst = $base . '/' . $agentId . '/' . $rel;
            $why = self::moveOne($src, $dst, $rel, $agentId, $log, $copier, $openNow, $homeDir, $opts['now'] ?? null);
            if ($why === null) {
                $res['moved']++;
                $movedRels[] = $rel;
                $res['bytes'] += (int)(@filesize($dst) ?: 0);
            } else {
                $res['skipped'][] = ['file' => $rel, 'reason' => $why];
            }
            $done++;
            if ($done % self::LEDGER_FLUSH_EVERY === 0) self::flushLedger($base, false);
            if (isset($opts['progress'])) {
                $opts['progress'](['phase' => 'moving', 'done' => $done, 'total' => $count,
                                   'bytes' => $res['bytes'], 'skipped' => count($res['skipped'])]);
            }
        }
        fclose($log);
        self::flushLedger($base, true);
        self::pruneEmptyDirs($homeDir, $agentId, $movedRels);
        $res['message'] = sprintf('Moved %d files. Skipped %d.', $res['moved'], count($res['skipped']));
        return $res;
    }

    /**
     * One file. Returns null when the file is moved, or the reason it stayed.
     */
    private static function moveOne(string $src, string $dst, string $rel, string $agentId, $log, callable $copier, ?callable $openProvider, string $homeDir, ?int $now): ?string
    {
        if (is_link($src) || !is_file($src)) return 'The file is gone or is a link.';
        $st = @stat($src);
        if ($st === false) return 'The file cannot be read.';
        $sha = @hash_file('sha256', $src);
        if ($sha === false) return 'The file cannot be read.';
        $size = (int)$st['size'];
        $mtime = (int)$st['mtime'];
        $mode = (int)$st['mode'] & 07777;

        self::walWrite($log, ['t' => 'begin', 'file' => $rel, 'agent' => $agentId, 'size' => $size,
                              'sha256' => $sha, 'mtime' => $mtime, 'mode' => $mode, 'ts' => time()]);

        $dir = dirname($dst);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            self::walWrite($log, ['t' => 'abort', 'file' => $rel]);
            return 'The destination folder cannot be created.';
        }
        $part = $dst . '.part';
        $ok = $copier($src, $part);
        if (!$ok || !is_file($part) || (int)@filesize($part) !== $size || @hash_file('sha256', $part) !== $sha) {
            @unlink($part);
            self::walWrite($log, ['t' => 'abort', 'file' => $rel]);
            return 'The copy did not match the original, so the original stays.';
        }
        @chmod($part, $mode);
        @touch($part, $mtime);
        if (!@rename($part, $dst)) {
            @unlink($part);
            self::walWrite($log, ['t' => 'abort', 'file' => $rel]);
            return 'The copy could not be finished.';
        }
        self::walWrite($log, ['t' => 'copied', 'file' => $rel]);

        // The file must not change or open while we copy. Check again before the source goes.
        clearstatcache(true, $src);
        $st2 = @stat($src);
        if ($st2 === false && @lstat($src) === false) {
            // #587: the source is gone and the checked copy is now the only one. Keep it.
            // Removing it here lost the file when another job had already finished the move.
            self::walWrite($log, ['t' => 'removed', 'file' => $rel]);
            return null;
        }
        $open = self::isOpenNow($src, $homeDir, $openProvider);
        if ($st2 === false || (int)$st2['size'] !== $size || (int)$st2['mtime'] !== $mtime || $open) {
            @unlink($dst);
            self::walWrite($log, ['t' => 'abort', 'file' => $rel]);
            return $open ? 'A process opened the file during the move.' : 'The file changed during the move.';
        }
        if (!@unlink($src)) {
            @unlink($dst);
            self::walWrite($log, ['t' => 'abort', 'file' => $rel]);
            return 'The original could not be removed.';
        }
        self::walWrite($log, ['t' => 'removed', 'file' => $rel]);
        return null;
    }

    private static function isOpenNow(string $src, string $homeDir, ?callable $provider): bool
    {
        if ($provider !== null) return in_array($src, (array)$provider($homeDir), true);
        // The default scan walks /proc: keep its answer for one second.
        static $at = 0.0, $set = [];
        if (microtime(true) - $at > 1.0) { $set = HistoryStoreService::procOpenFiles($homeDir); $at = microtime(true); }
        return in_array($src, $set, true);
    }

    /** Default copier: copy bytes, then flush to disk. */
    public static function copyFile(string $src, string $dst): bool
    {
        $in = @fopen($src, 'rb');
        $out = @fopen($dst, 'wb');
        if ($in === false || $out === false) { if ($in) fclose($in); if ($out) fclose($out); return false; }
        $ok = @stream_copy_to_stream($in, $out) !== false;
        $ok = $ok && fflush($out);
        if ($ok && function_exists('fsync')) @fsync($out);
        fclose($in);
        $closed = fclose($out);
        return $ok && $closed;
    }

    /**
     * Bring moved sessions back into the home. $agentId may be 'all'.
     * Never overwrites a file that exists in the home: a file that differs stays
     * in the store and is reported as skipped.
     *
     * @return array{status:string,message:string,restored:int,bytes:int,skipped:array<int,array{file:string,reason:string}>}
     */
    public static function bringBack(string $homeDir, string $user, string $agentId, string $dest, array $opts = []): array
    {
        $res = ['status' => 'ok', 'message' => '', 'restored' => 0, 'bytes' => 0, 'skipped' => []];
        $mounted = $opts['mounted'] ?? [self::class, 'destinationMounted'];
        if (!$mounted($dest)) return self::fail($res, self::REFUSE_ARRAY_STOPPED);
        $base = rtrim($dest, '/') . '/' . $user;
        if (!is_dir($base)) return self::fail($res, 'No moved sessions are in this folder.');
        $lock = self::lockStore($base, $opts);
        if ($lock === null) return self::fail($res, self::REFUSE_BUSY);
        try {
            return self::bringBackLocked($homeDir, $agentId, $base, $opts, $res);
        } finally {
            self::unlockStore($lock);
        }
    }

    /** @param array<string,mixed> $res */
    private static function bringBackLocked(string $homeDir, string $agentId, string $base, array $opts, array $res): array
    {
        self::recover($homeDir, $base, $opts);

        $state = self::readState($base);
        $todo = [];
        foreach ($state as $file => $e) {
            if ($e['t'] !== 'removed') continue;
            if ($agentId !== 'all' && $e['agent'] !== $agentId) continue;
            $todo[$file] = $e;
        }
        ksort($todo);
        if (!$todo) {
            $res['message'] = 'No moved sessions to bring back.';
            return $res;
        }
        $need = 0;
        foreach ($todo as $e) $need += (int)$e['size'];
        $free = isset($opts['free']) ? (int)$opts['free']($homeDir) : (int)@disk_free_space($homeDir);
        if ($free > 0 && $need + 64 * 1048576 > $free) {
            return self::fail($res, 'The boot disk has not enough free space to bring these sessions back.');
        }
        // R8 (#724): a large write to the boot disk must not push it over 85 %.
        if (!class_exists(BootSpaceService::class, false)) require_once __DIR__ . '/BootSpaceService.php';
        $refused = BootSpaceService::writeGuard($homeDir, $need, 'bringing the sessions back', $opts);
        if ($refused !== null) return self::fail($res, $refused);

        $log = self::walOpen($base);
        $copier = $opts['copy'] ?? [self::class, 'copyFile'];
        $done = 0;
        $count = count($todo);
        try {
            foreach ($todo as $rel => $e) {
                $stored = $base . '/' . $e['agent'] . '/' . $rel;
                $home = rtrim($homeDir, '/') . '/' . $rel;
                $why = self::restoreOne($stored, $home, $rel, $e, $log, $copier);
                if ($why === null) {
                    $res['restored']++;
                    $res['bytes'] += (int)$e['size'];
                } else {
                    $res['skipped'][] = ['file' => $rel, 'reason' => $why];
                }
                $done++;
                if ($done % self::LEDGER_FLUSH_EVERY === 0) self::flushLedger($base, false);
                if (isset($opts['progress'])) {
                    $opts['progress'](['phase' => 'restoring', 'done' => $done, 'total' => $count,
                                       'bytes' => $res['bytes'], 'skipped' => count($res['skipped'])]);
                }
            }
        } catch (\Throwable $ex) {
            // #679: the files already brought back stay counted, so the result reports them.
            // The stop is logged here; the caller gets the plain sentence and the totals.
            LogService::log('Bring back stopped after ' . $res['restored'] . ' files: ' . $ex->getMessage(), LogService::LOG_ERROR, 'HistoryJob');
            $res = self::fail($res, 'The job stopped with an error. Details are in the Debug Console.');
        }
        fclose($log);
        self::flushLedger($base, true);
        if ($res['status'] === 'ok') {
            $res['message'] = sprintf('Brought back %d files. Skipped %d.', $res['restored'], count($res['skipped']));
        }
        return $res;
    }

    private static function restoreOne(string $stored, string $home, string $rel, array $e, $log, callable $copier): ?string
    {
        if (!is_file($stored)) return 'The stored copy is missing.';
        if ((int)@filesize($stored) !== (int)$e['size'] || @hash_file('sha256', $stored) !== $e['sha256']) {
            return 'The stored copy does not match its record, so it stays in the store.';
        }
        if (file_exists($home) || is_link($home)) {
            if (is_file($home) && !is_link($home) && @hash_file('sha256', $home) === $e['sha256']) {
                // Already identical in the home: the move is undone; the stored copy may go.
                self::walWrite($log, ['t' => 'restored', 'file' => $rel]);
                @unlink($stored);
                return null;
            }
            return 'A file with this name exists in the home, so it was not replaced.';
        }
        $dir = dirname($home);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return 'The folder in the home cannot be created.';
        self::walWrite($log, ['t' => 'restoring', 'file' => $rel]);
        $part = $home . '.part';
        if (!$copier($stored, $part) || (int)@filesize($part) !== (int)$e['size'] || @hash_file('sha256', $part) !== $e['sha256']) {
            @unlink($part);
            self::walWrite($log, ['t' => 'removed', 'file' => $rel]);
            return 'The copy did not match the stored file, so the stored file stays.';
        }
        @chmod($part, (int)$e['mode']);
        @touch($part, (int)$e['mtime']);
        // rename() over an existing file would replace it; link() fails if the name exists.
        if (@link($part, $home)) {
            @unlink($part);
        } elseif (@lstat($home) !== false || !@rename($part, $home)) {
            // lstat sees a file, a folder and a dangling link alike: never replace any of them.
            @unlink($part);
            self::walWrite($log, ['t' => 'removed', 'file' => $rel]);
            return 'A file with this name appeared in the home, so it was not replaced.';
        }
        self::walWrite($log, ['t' => 'restored', 'file' => $rel]);
        @unlink($stored);
        return null;
    }

    /**
     * Finish what a crash left. Called before every move and bring back.
     *  - begin    : the copy may be partial. Remove the copy. The source stays.
     *  - copied   : the copy is checked. If the source is still there and equals
     *               the record, remove the source (the move completes). If the
     *               source changed, remove the copy (the move is cancelled).
     *  - restoring: if the home file is complete and equals the record, remove the stored
     *               copy (the bring back completes). Otherwise remove the partial home file.
     */
    public static function recover(string $homeDir, string $base, array $opts = []): void
    {
        $state = self::readState($base);
        $log = null;
        foreach ($state as $rel => $e) {
            $stored = $base . '/' . $e['agent'] . '/' . $rel;
            $src = rtrim($homeDir, '/') . '/' . $rel;
            $t = $e['t'];
            if ($t === 'begin') {
                @unlink($stored . '.part');
                if (is_file($stored) && !is_file($src)) {
                    // Destination complete only if it matches; the source is gone, so keep a good copy.
                    $good = (int)@filesize($stored) === (int)$e['size'] && @hash_file('sha256', $stored) === $e['sha256'];
                    $log = $log ?? self::walOpen($base);
                    self::walWrite($log, ['t' => $good ? 'removed' : 'abort', 'file' => $rel]);
                    continue;
                }
                @unlink($stored);
                $log = $log ?? self::walOpen($base);
                self::walWrite($log, ['t' => 'abort', 'file' => $rel]);
            } elseif ($t === 'copied') {
                $log = $log ?? self::walOpen($base);
                $copyGood = is_file($stored) && (int)@filesize($stored) === (int)$e['size'] && @hash_file('sha256', $stored) === $e['sha256'];
                if (!is_file($src)) {
                    // Source already gone: the copy is the only one. Keep it if good.
                    self::walWrite($log, ['t' => $copyGood ? 'removed' : 'abort', 'file' => $rel]);
                } elseif ($copyGood && (int)@filesize($src) === (int)$e['size'] && @hash_file('sha256', $src) === $e['sha256']) {
                    @unlink($src);
                    self::walWrite($log, ['t' => 'removed', 'file' => $rel]);
                } else {
                    @unlink($stored);
                    self::walWrite($log, ['t' => 'abort', 'file' => $rel]);
                }
            } elseif ($t === 'restoring') {
                $log = $log ?? self::walOpen($base);
                @unlink($src . '.part');
                if (is_file($src) && (int)@filesize($src) === (int)$e['size'] && @hash_file('sha256', $src) === $e['sha256']) {
                    self::walWrite($log, ['t' => 'restored', 'file' => $rel]);
                    @unlink($stored);
                } else {
                    self::walWrite($log, ['t' => 'removed', 'file' => $rel]);
                }
            }
        }
        if ($log !== null) { fclose($log); self::flushLedger($base, true); }
    }

    /**
     * The state of every file in the log: file => record of "begin" plus 't' (the last record type).
     * @return array<string,array<string,mixed>>
     */
    public static function readState(string $base): array
    {
        $state = [];
        $path = $base . '/MOVED.wal';
        $lines = is_file($path) ? @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
        foreach ($lines ?: [] as $line) {
            $r = json_decode($line, true);
            if (!is_array($r) || !isset($r['t'], $r['file'])) continue; // a torn last line is ignored
            $f = (string)$r['file'];
            if ($r['t'] === 'begin') {
                $state[$f] = $r;
            } elseif (isset($state[$f])) {
                $state[$f]['t'] = $r['t'];
            }
        }
        foreach ($state as $f => $e) {
            if (in_array($e['t'], ['abort', 'restored'], true)) unset($state[$f]);
        }
        return $state;
    }

    /**
     * Write MOVED.json from the log, and compact the log so it holds only the
     * files that are moved now.
     */
    public static function flushLedger(string $base, bool $compact = true): void
    {
        $state = self::readState($base);
        $files = [];
        $keep = [];
        foreach ($state as $rel => $e) {
            if ($e['t'] === 'removed') {
                $files[] = ['file' => $rel, 'agent' => $e['agent'], 'size' => (int)$e['size'], 'sha256' => $e['sha256'],
                            'mtime' => (int)$e['mtime'], 'mode' => (int)$e['mode'], 'moved_at' => (int)$e['ts']];
            }
            // An unfinished file keeps its records, so recovery still sees it.
            $keep[$rel] = $e;
        }
        usort($files, static function ($a, $b) { return strcmp($a['file'], $b['file']); });
        $json = json_encode(['version' => 1, 'updated_at' => time(), 'files' => $files], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        self::atomicWrite($base . '/MOVED.json', (string)$json . "\n");
        if (!$compact) return; // a running job holds the log open: only compact when it is closed
        // Compact the log: one "begin" record per file with the final type in a "removed" line.
        $out = '';
        foreach ($keep as $rel => $e) {
            $rec = $e;
            $rec['t'] = 'begin';
            $out .= json_encode($rec, JSON_UNESCAPED_SLASHES) . "\n";
            if ($e['t'] !== 'begin') {
                $out .= json_encode(['t' => $e['t'], 'file' => $rel], JSON_UNESCAPED_SLASHES) . "\n";
            }
        }
        self::atomicWrite($base . '/MOVED.wal', $out);
    }

    /**
     * What is moved now for a user: agent => ['files'=>int,'bytes'=>int].
     * @return array<string,array{files:int,bytes:int}>
     */
    public static function movedSummary(string $dest, string $user): array
    {
        $base = rtrim($dest, '/') . '/' . $user;
        $out = [];
        foreach (self::readState($base) as $e) {
            if ($e['t'] !== 'removed') continue;
            $a = (string)$e['agent'];
            $out[$a] = $out[$a] ?? ['files' => 0, 'bytes' => 0];
            $out[$a]['files']++;
            $out[$a]['bytes'] += (int)$e['size'];
        }
        return $out;
    }

    /** @return resource */
    private static function walOpen(string $base)
    {
        $fh = @fopen($base . '/MOVED.wal', 'ab');
        if ($fh === false) throw new \RuntimeException('The move log cannot be opened.');
        return $fh;
    }

    /** @param resource $log */
    private static function walWrite($log, array $rec): void
    {
        fwrite($log, json_encode($rec, JSON_UNESCAPED_SLASHES) . "\n");
        fflush($log);
        if (function_exists('fsync')) @fsync($log);
    }

    private static function atomicWrite(string $path, string $data): void
    {
        $tmp = $path . '.tmp.' . getmypid();
        if (@file_put_contents($tmp, $data) !== false) @rename($tmp, $path);
        else @unlink($tmp);
    }

    /**
     * Remove the folders that THIS move emptied: walk up from each moved file
     * to the root of the store, and stop at the first folder that is not empty.
     * Never removes a root, and never a folder this move did not empty.
     *
     * @param array<int,string> $movedRels home-relative paths of the moved files
     */
    private static function pruneEmptyDirs(string $homeDir, string $agentId, array $movedRels): void
    {
        $store = HistoryStoreService::stores()[$agentId] ?? null;
        if ($store === null) return;
        foreach ($movedRels as $rel) {
            $dir = dirname($rel);
            while ($dir !== '.' && $dir !== '/' && $dir !== '') {
                if (in_array($dir, $store['roots'], true)) break;
                if (!@rmdir(rtrim($homeDir, '/') . '/' . $dir)) break; // fails when not empty
                $dir = dirname($dir);
            }
        }
    }

    /** @param array<string,mixed> $res */
    private static function fail(array $res, string $message): array
    {
        $res['status'] = 'error';
        $res['message'] = $message;
        return $res;
    }
}
