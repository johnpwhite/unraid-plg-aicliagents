<?php
/**
 * <module_context>
 *     <name>SessionLaunchLock</name>
 *     <description>One lock for each workspace session. Only one request at a
 *     time may start, close or restart the agent of that workspace.
 *     (/tmp/unraid-aicliagents/launch-lock/&lt;safeId&gt;.lock) Forgejo #352,
 *     2026-09-29: see docs/specs/WORKSPACE_RELOAD_ONTO_CURRENT.md, "2026-09-29:
 *     one switch launches the agent once".</description>
 *     <dependencies>None.</dependencies>
 *     <constraints>Static methods only. Never throws. The lock is a flock(2) on
 *     a tmpfs file, taken NON-BLOCKING in a poll loop with a deadline (never a
 *     blocking flock). The file is opened close-on-exec, so a tmux server or a
 *     ttyd that the holder starts never inherits the lock. Re-entrant inside
 *     one PHP process: restart() holds the lock and calls gracefulClose() and
 *     startTerminal(), which ask for the same lock.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class SessionLaunchLock
{
    /**
     * How long a start waits for a close or a restart of the same workspace.
     * A restart closes the agent (up to the graceful_close_timeout, at most 60 s)
     * and then launches it again (a cold home mount can take 30 s).
     */
    public const START_WAIT_SECONDS = 120.0;

    /** How long a close or a restart waits for a start of the same workspace. */
    public const CLOSE_WAIT_SECONDS = 90.0;

    /** Poll interval of the non-blocking lock loop. */
    private const POLL_US = 100000;

    /** Test seam: when set, every wait uses this many seconds instead. */
    public static ?float $waitOverride = null;

    /** @var array<string, array{fh: resource, depth: int}> locks this process holds */
    private static array $held = [];

    /** Lock directory. AICLI_TMP_BASE redirects it for tests (PHPUnit isolation). */
    public static function dir(): string
    {
        $env = getenv('AICLI_TMP_BASE');
        $base = ($env !== false && $env !== '') ? $env : '/tmp/unraid-aicliagents';
        return $base . '/launch-lock';
    }

    /** Same charset every other session-id sanitiser in this plugin uses. */
    private static function safeId(string $id): string
    {
        return (string)preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
    }

    /** Lock file path for a session, or null for an empty id. */
    public static function path(string $id): ?string
    {
        $safe = self::safeId($id);
        return $safe === '' ? null : self::dir() . '/' . $safe . '.lock';
    }

    /**
     * Take the lock of this workspace. Waits up to $waitSeconds while another
     * request holds it. Returns true when this process holds the lock (also
     * when it held it already: the call is then counted, and each true result
     * needs one release()). Returns false on timeout or on an I/O error; the
     * caller must then do nothing to the workspace.
     */
    public static function acquire(string $id, float $waitSeconds): bool
    {
        $path = self::path($id);
        if ($path === null) return false;
        if (isset(self::$held[$path])) {
            self::$held[$path]['depth']++;
            return true;
        }
        if (!is_dir(self::dir())) @mkdir(self::dir(), 0700, true);
        // 'c' creates without truncating; 'e' sets close-on-exec.
        $fh = @fopen($path, 'ce');
        if ($fh === false) return false;
        $wait = self::$waitOverride ?? $waitSeconds;
        $deadline = microtime(true) + max(0.0, $wait);
        while (true) {
            $wouldBlock = 0;
            if (@flock($fh, LOCK_EX | LOCK_NB, $wouldBlock)) {
                self::$held[$path] = ['fh' => $fh, 'depth' => 1];
                return true;
            }
            if (microtime(true) >= $deadline) {
                @fclose($fh);
                return false;
            }
            usleep(self::POLL_US);
        }
    }

    /** Give back one acquire(). The lock is freed when the count reaches zero. */
    public static function release(string $id): void
    {
        $path = self::path($id);
        if ($path === null || !isset(self::$held[$path])) return;
        if (--self::$held[$path]['depth'] > 0) return;
        $fh = self::$held[$path]['fh'];
        unset(self::$held[$path]);
        @flock($fh, LOCK_UN);
        @fclose($fh);
    }

    /** True while THIS process holds the lock of the workspace. */
    public static function heldHere(string $id): bool
    {
        $path = self::path($id);
        return $path !== null && isset(self::$held[$path]);
    }

    /** Test seam: free every lock this process holds and clear the override. */
    public static function resetForTests(): void
    {
        foreach (self::$held as $h) {
            @flock($h['fh'], LOCK_UN);
            @fclose($h['fh']);
        }
        self::$held = [];
        self::$waitOverride = null;
    }
}
