<?php
/**
 * <module_context>
 *     <name>InstallLock</name>
 *     <description>#150: a global, cross-process lock that serializes agent
 *     installs. Each upgrade spawns its own install-bg.php, and each install's
 *     layer consolidation writes to the USB flash. Running several at once hammers
 *     a slow flash — observed on Tower: dstate backpressure, deferred agent-home
 *     mounts (bake_lock_held), and the shfs-wedge-watch tripping. This lock makes
 *     the heavy npm-fetch + flash-consolidation phase run one-at-a-time; the rest
 *     queue behind it (the UI already shows an "upgrading" chip). Non-blocking
 *     flock in a poll loop with a deadline — NEVER a bare blocking flock — so a
 *     wedged holder can never hang every other install forever.</description>
 *     <dependencies>none</dependencies>
 *     <constraints>Lock file lives on tmpfs (/tmp), not flash — the lock itself
 *     must never add flash writes.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class InstallLock
{
    const LOCK_FILE = '/tmp/unraid-aicliagents/install-global.lock';

    /**
     * Acquire the global install lock (non-blocking poll). Keep the returned file
     * handle referenced for the whole critical section — the lock releases on
     * release()/fclose or when the process exits (so a crashed holder never wedges
     * the queue). If the lock can't be taken within $deadlineSec we return
     * held=false and let the caller PROCEED rather than hang forever (the activity
     * watchdog already fails a stalled install at 20 min). $onWait fires once, the
     * first time we have to queue.
     *
     * @return array{fh:?resource, held:bool, queued:bool}
     */
    public static function acquire(?callable $onWait = null, int $deadlineSec = 1500, ?string $lockFile = null, int $pollUs = 400000): array
    {
        $lockFile = $lockFile ?? self::LOCK_FILE;
        @mkdir(dirname($lockFile), 0755, true);
        $fh = @fopen($lockFile, 'c');
        if (!$fh) return ['fh' => null, 'held' => false, 'queued' => false];

        $deadline = time() + max(1, $deadlineSec);
        $queued = false;
        while (true) {
            if (@flock($fh, LOCK_EX | LOCK_NB)) {
                return ['fh' => $fh, 'held' => true, 'queued' => $queued];
            }
            if (!$queued) {
                if ($onWait) { try { $onWait(); } catch (\Throwable $e) { /* status update is best-effort */ } }
                $queued = true;
            }
            if (time() >= $deadline) {
                // Proceed unlocked rather than block indefinitely behind a wedged holder.
                return ['fh' => $fh, 'held' => false, 'queued' => $queued];
            }
            usleep($pollUs);
        }
    }

    /** Release a lock returned by acquire(). Safe to call with a non-held/failed result. */
    public static function release(array $lock): void
    {
        $fh = $lock['fh'] ?? null;
        if (is_resource($fh)) {
            @flock($fh, LOCK_UN);
            @fclose($fh);
        }
    }
}
