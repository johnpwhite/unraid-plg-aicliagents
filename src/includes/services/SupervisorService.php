<?php
/**
 * <module_context>
 *     <name>SupervisorService</name>
 *     <description>PHP wrapper for the Storage Durability Supervisor daemon (Phase 3.2).
 *     Provides isRunning, start, stop, getStatus, getTickAge, getWorkState, enqueue, isHalted,
 *     clearHalt, consolidateFailureCount, resetConsolidateFailures -- all silent on failure.</description>
 *     <dependencies>None (standalone -- no dep on LogService so it is safe to call before init)</dependencies>
 *     <constraints>All public methods return null/false on failure -- no error_log, no exceptions.
 *     Pidfile is canonical; pgrep is fallback only. Path-anchored process checks (feedback_kill_patterns_vm_safety).</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class SupervisorService {

    public const PIDFILE    = '/var/run/aicli-supervisor.pid';
    public const TICKFILE   = '/var/run/aicli-supervisor.tick';
    public const WORKFILE   = '/var/run/aicli-supervisor.work.json';
    public const STATUSFILE = '/tmp/unraid-aicliagents/supervisor.status.json';

    /** Supervisor runtime directories. */
    public const SUPERVISOR_DIR      = '/tmp/unraid-aicliagents/supervisor';
    public const HALTS_DIR           = '/tmp/unraid-aicliagents/supervisor/halts';
    public const QUEUE_DIR           = '/tmp/unraid-aicliagents/supervisor/queue';
    public const CONSOLIDATE_FAILS_DIR = '/tmp/unraid-aicliagents/supervisor/consolidate-fails';

    /** Absolute path to the supervisor script on the deployed plugin tree. */
    private const SCRIPT_PATH =
        '/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/supervisor/aicli-supervisor.sh';

    /** Absolute path to the queue_helpers.sh script. */
    private const QUEUE_HELPERS =
        '/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/supervisor/queue_helpers.sh';

    // -------------------------------------------------------------------------
    // Public API — lifecycle
    // -------------------------------------------------------------------------

    /**
     * Returns true if a live supervisor process is running.
     */
    public static function isRunning(): bool {
        $pid = self::readPidfile();
        if ($pid === null) {
            return false;
        }
        return self::pidOwnsScript($pid);
    }

    /**
     * Spawn the supervisor daemon in the background (setsid + nohup + disown).
     * setsid is required when the parent has a controlling TTY (e.g. SSH session
     * during plugin install) — without it, the daemon's heartbeat loop keeps
     * descriptors coupled to the parent and the install hangs at session exit.
     * 4>&- closes any inherited non-stdio FDs (e.g. the PLG installer progress
     * pipe) so PHP-FPM can exit cleanly even if an FD was inherited from a
     * parent process chain that crossed a script boundary.
     */
    public static function start(): bool {
        if (!file_exists(self::SCRIPT_PATH)) {
            return false;
        }

        if (self::isRunning()) {
            return true;
        }

        $script = self::SCRIPT_PATH;
        $cmd = 'setsid nohup bash ' . escapeshellarg($script) . ' start </dev/null >/dev/null 2>&1 4>&- & disown';

        $desc = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
        $proc = @proc_open($cmd, $desc, $pipes, null, null, ['bypass_shell' => false]);
        if ($proc !== false) {
            foreach ($pipes as $pipe) {
                @fclose($pipe);
            }
            @proc_close($proc);
        }

        usleep(200000);
        return self::isRunning();
    }

    /**
     * Stop the running supervisor daemon.
     */
    public static function stop(int $timeoutSec = 10): bool {
        $pid = self::readPidfile();
        if ($pid === null) {
            return true;
        }

        if (!self::pidOwnsScript($pid)) {
            @unlink(self::PIDFILE);
            return true;
        }

        @posix_kill($pid, SIGTERM);

        $deadline = time() + $timeoutSec;
        while (time() < $deadline) {
            if (!self::pidAlive($pid)) {
                break;
            }
            usleep(200000);
        }

        if (self::pidAlive($pid)) {
            @posix_kill($pid, SIGKILL);
            usleep(500000);
        }

        if (!self::pidAlive($pid)) {
            @unlink(self::PIDFILE);
            return true;
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // Public API — status inspection
    // -------------------------------------------------------------------------

    /**
     * Reads /tmp/unraid-aicliagents/supervisor.status.json.
     *
     * @return array<string, mixed>|null
     */
    public static function getStatus(): ?array {
        return self::readJsonFile(self::STATUSFILE);
    }

    /**
     * Returns the age in seconds of the last heartbeat tick, or null.
     */
    public static function getTickAge(): ?int {
        if (!file_exists(self::TICKFILE)) {
            return null;
        }
        $mtime = @filemtime(self::TICKFILE);
        if ($mtime === false) {
            return null;
        }
        return max(0, time() - $mtime);
    }

    /**
     * Reads /var/run/aicli-supervisor.work.json.
     *
     * @return array<string, mixed>|null
     */
    public static function getWorkState(): ?array {
        return self::readJsonFile(self::WORKFILE);
    }

    // -------------------------------------------------------------------------
    // Public API — queue
    // -------------------------------------------------------------------------

    /**
     * Enqueue an op for the supervisor to process.
     *
     * Priority legend:
     *   0  = agent install (highest)
     *   5  = user-clicked (Persist Now, user consolidate)
     *   10 = event-driven (workspace_close, stopping_array)
     *   50 = dirty-pressure
     *   99 = schedule safety-net
     *
     * Delegates to queue_helpers.sh via shell for atomic write safety.
     *
     * @param string $type     'home' or 'agent'
     * @param string $id       entity id (username or agent key)
     * @param string $op       'bake' or 'consolidate'
     * @param string $reason   human-readable trigger reason
     * @param int    $priority 0-99
     */
    public static function enqueue(
        string $type,
        string $id,
        string $op,
        string $reason,
        int $priority
    ): bool {
        if (!file_exists(self::QUEUE_HELPERS)) {
            return false;
        }

        // Sanitize inputs for shell safety
        $type     = preg_replace('/[^a-z]/', '', $type);
        $id       = preg_replace('/[^A-Za-z0-9._\-]/', '_', $id);
        $op       = preg_replace('/[^a-z]/', '', $op);
        $reason   = preg_replace('/[^A-Za-z0-9_\-]/', '_', $reason);
        $priority = max(0, min(99, $priority));

        $helpers  = escapeshellarg(self::QUEUE_HELPERS);
        $cmd = sprintf(
            'bash -c %s',
            escapeshellarg(
                "source $helpers 2>/dev/null && queue_enqueue $priority $type $id $op $reason"
            )
        );

        $output = [];
        $ret    = 0;
        @exec($cmd, $output, $ret);
        return $ret === 0;
    }

    // -------------------------------------------------------------------------
    // Public API — halts
    // -------------------------------------------------------------------------

    /**
     * Returns true if the entity (or a specific halt kind) has an active halt.
     *
     * @param string      $entity  'home/root' or 'agent/claude-code'
     * @param string|null $kind    optional sub-kind, e.g. 'consolidate-disabled', 'corrupt_layers'
     */
    public static function isHalted(string $entity, ?string $kind = null): bool {
        $path = self::haltPath($entity, $kind);
        return file_exists($path);
    }

    /**
     * Clears a halt for the entity (or a specific halt kind).
     *
     * @param string      $entity
     * @param string|null $kind
     */
    public static function clearHalt(string $entity, ?string $kind = null): bool {
        $path = self::haltPath($entity, $kind);
        if (!file_exists($path)) {
            return true;
        }
        return @unlink($path) !== false;
    }

    // -------------------------------------------------------------------------
    // Public API — consolidate failure tracking
    // -------------------------------------------------------------------------

    /**
     * Returns the current consecutive consolidate failure count for the entity.
     */
    public static function consolidateFailureCount(string $entity): int {
        $path = self::consolidateFailPath($entity);
        if (!file_exists($path)) {
            return 0;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return 0;
        }
        return max(0, (int)trim($raw));
    }

    /**
     * Resets the consolidate failure counter for the entity.
     * Called when a user-clicked consolidate succeeds.
     */
    public static function resetConsolidateFailures(string $entity): bool {
        $path = self::consolidateFailPath($entity);
        if (!file_exists($path)) {
            return true;
        }
        return @unlink($path) !== false;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Builds the halt file path for an entity (and optional kind).
     */
    private static function haltPath(string $entity, ?string $kind = null): string {
        $safeEntity = str_replace(['/', ' '], ['__', '__'], $entity);
        $base = self::HALTS_DIR . '/' . $safeEntity;
        if ($kind !== null && $kind !== '') {
            return $base . ':' . $kind;
        }
        return $base;
    }

    /**
     * Builds the consolidate-fails file path for an entity.
     */
    private static function consolidateFailPath(string $entity): string {
        $safeEntity = str_replace(['/', ' '], ['__', '__'], $entity);
        return self::CONSOLIDATE_FAILS_DIR . '/' . $safeEntity;
    }

    /**
     * Read and return the integer PID from the pidfile, or null.
     */
    private static function readPidfile(): ?int {
        if (!file_exists(self::PIDFILE)) {
            return null;
        }
        $raw = @file_get_contents(self::PIDFILE);
        if ($raw === false) {
            return null;
        }
        $pid = (int) trim($raw);
        return ($pid > 0) ? $pid : null;
    }

    /**
     * Returns true if the given PID is alive (signal 0 succeeds).
     */
    private static function pidAlive(int $pid): bool {
        return @posix_kill($pid, 0) === true;
    }

    /**
     * Returns true if $pid is alive AND its cmdline carries our script path.
     */
    private static function pidOwnsScript(int $pid): bool {
        if (!self::pidAlive($pid)) {
            return false;
        }
        $cmdline = @file_get_contents("/proc/{$pid}/cmdline");
        if ($cmdline === false) {
            return false;
        }
        $cmdline = str_replace("\0", ' ', $cmdline);
        return strpos($cmdline, self::SCRIPT_PATH) !== false;
    }

    /**
     * Reads and JSON-decodes a file. Returns the decoded array or null on any failure.
     *
     * @return array<string, mixed>|null
     */
    private static function readJsonFile(string $path): ?array {
        if (!file_exists($path) || !is_readable($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $decoded = @json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }
        return $decoded;
    }
}
