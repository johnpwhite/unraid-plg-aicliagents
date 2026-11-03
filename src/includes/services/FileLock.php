<?php
/**
 * <module_context>
 *     <name>FileLock</name>
 *     <description>One cross-process lock for a read-change-write of a state file (#890). LOCK_EX|LOCK_NB in a poll loop with a deadline, never a blocking flock. Re-entrant in one process for each lock file. The lock file is opened close-on-exec so a child process never keeps it. Used by SecretService (secrets.lock) and EnvService (envs.lock).</description>
 *     <dependencies>none</dependencies>
 *     <constraints>The lock file belongs in the RAM state root, never on flash or /mnt/user. When the deadline passes or no lock file can be opened, the callback does NOT run and $busy is true.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class FileLock {
    private const NOTIFY_SCRIPT = '/usr/local/emhttp/plugins/dynamix/scripts/notify';

    /** @var array<string,int> depth of the holding frames for each lock path in this process */
    private static array $depth = [];

    /** True when this process already holds the lock at $path (the next run() re-enters). */
    public static function isHeld(string $path): bool {
        return (self::$depth[$path] ?? 0) > 0;
    }

    /**
     * Run $fn while holding the lock at $path.
     *
     * @param callable  $fn
     * @param bool|null $busy set to true when the lock was not taken and $fn did not run
     * @return mixed what $fn returns, or null when busy
     */
    public static function run(string $path, float $waitSeconds, callable $fn, ?bool &$busy = null) {
        $busy = false;
        if ((self::$depth[$path] ?? 0) > 0) return $fn();
        if (!is_dir(dirname($path))) @mkdir(dirname($path), 0755, true);
        // 'e' = close-on-exec: a process started inside the lock must not keep it open.
        $fh = @fopen($path, 'ce');
        if ($fh === false) { $busy = true; return null; }
        $deadline = microtime(true) + $waitSeconds;
        while (!@flock($fh, LOCK_EX | LOCK_NB)) {
            if (microtime(true) >= $deadline) {
                @fclose($fh);
                $busy = true;
                return null;
            }
            usleep(10000);
        }
        self::$depth[$path] = 1;
        try {
            return $fn();
        } finally {
            unset(self::$depth[$path]);
            @flock($fh, LOCK_UN);
            @fclose($fh);
        }
    }

    /**
     * #636: copy a file that exists but cannot be read to <file>.corrupt-<time> (never over an
     * earlier copy) before the caller replaces or removes it. Returns the copy's base name, or
     * null when the copy could not be made.
     */
    public static function keepCorruptCopy(string $file): ?string {
        $base = $file . '.corrupt-' . date('Ymd-His');
        $copy = $base;
        for ($n = 2; file_exists($copy) && $n < 100; $n++) $copy = $base . '-' . $n;
        if (!@copy($file, $copy)) return null;
        @chmod($copy, 0600);
        return basename($copy);
    }

    /**
     * #893: tell the person that a damaged file was set aside. Many save paths reply with no
     * place for a notice (Relay, voice, git, admin tool), so the shared code sends one Unraid
     * notification. The text names files only, never a value. No notify script: nothing is sent.
     */
    public static function notifyDamagedFile(string $notice): void {
        $script = getenv('AICLI_NOTIFY_SCRIPT') ?: self::NOTIFY_SCRIPT;
        if (!is_executable($script)) return;
        @exec(escapeshellarg($script) . ' -e AICliAgents -s ' . escapeshellarg('AI CLI Agents: a saved file was damaged')
            . ' -d ' . escapeshellarg($notice) . ' -i warning >/dev/null 2>&1');
    }
}
