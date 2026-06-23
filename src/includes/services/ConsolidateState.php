<?php
/**
 * <module_context>
 *     <name>ConsolidateState</name>
 *     <description>Per-user "home consolidate in progress" marker
 *     (/tmp/unraid-aicliagents/home-consolidate-&lt;safeuser&gt;.flag, json
 *     {user, started_at}). Single source of truth for the
 *     HOME_CONSOLIDATE_INPROGRESS_GUARD: StorageHandler::consolidate(home) SETS
 *     it before forceCloseHome so a racing TerminalHandler::start observes it and
 *     refuses to relaunch (breaking the close→auto-reconnect→re-pin deadlock); the
 *     supervisor CLEARS it on consolidate completion (success/failure/give-up).
 *     Mirrors AgentHandler::isInstallInProgress's marker + staleness shape.</description>
 *     <dependencies>SupervisorService (jobs ledger, for the active-job staleness check)</dependencies>
 *     <constraints>Static methods only. Never throws — marker bookkeeping must not
 *     break the consolidate or the start it guards. Marker writes are best-effort
 *     (mkdir -p). Staleness fallback guarantees `start` can never be wedged
 *     permanently if a clear is ever missed.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

require_once __DIR__ . '/SupervisorService.php';

class ConsolidateState {

    /**
     * A home consolidate can run for minutes (bake + remount). The marker is
     * only treated as stale once it is older than this AND no active consolidate
     * job exists for the user — so a long-but-live consolidate is never wrongly
     * cleared mid-flight. Mirrors AgentHandler::INSTALL_STALE_THRESHOLD_SECS.
     */
    const HOME_CONSOLIDATE_STALE_SECS = 600;

    /** Marker base dir — AICLI_TMP_BASE redirects for tests (PHPUnit isolation). */
    private static function baseDir(): string {
        $env = getenv('AICLI_TMP_BASE');
        return ($env !== false && $env !== '') ? $env : '/tmp/unraid-aicliagents';
    }

    /** HOME-safe slug for the marker filename (same charset the consolidate id is validated against). */
    private static function safeUser(string $user): string {
        $user = trim($user);
        if ($user === '' || $user === '0') $user = 'root';
        // Defensive: collapse anything outside the safe set so the filename can
        // never escape the marker dir, even though callers already validate.
        return (string)preg_replace('/[^A-Za-z0-9._-]/', '_', $user);
    }

    private static function flagPath(string $user): string {
        return self::baseDir() . '/home-consolidate-' . self::safeUser($user) . '.flag';
    }

    /**
     * Mark a home consolidate as in progress for $user. Returns the epoch token
     * written into the marker so the caller can thread it through the job and
     * pass it back to clearHomeConsolidating for epoch-safe clearing (R3.2).
     *
     * @param string      $user   The home user (validated by caller).
     * @param string|null $epoch  Owning epoch / job-id. Generated if null.
     * @return string             The epoch stored in the marker.
     */
    public static function markHomeConsolidating(string $user, ?string $epoch = null): string {
        if ($epoch === null || $epoch === '') {
            // time-based token with 4 bytes of entropy — not a date/random constraint,
            // just a unique-enough opaque handle for within-session uniqueness.
            $epoch = (string)time() . '_' . bin2hex(random_bytes(4));
        }
        $flag = self::flagPath($user);
        @mkdir(dirname($flag), 0777, true);
        @file_put_contents($flag, (string)json_encode([
            'user'       => self::safeUser($user),
            'started_at' => time(),
            'epoch'      => $epoch,
        ]));
        return $epoch;
    }

    /**
     * Clear the marker for $user.
     *
     * If $epoch is supplied (non-null), the flag is only unlinked when the
     * stored epoch matches — so an OLD job's give-up cannot clear a NEWER
     * marker a fresh request just set (R3.2). A null $epoch clears
     * unconditionally (legacy callers, staleness fallback).
     *
     * @param string      $user   The home user.
     * @param string|null $epoch  Owning epoch to match, or null for unconditional.
     */
    public static function clearHomeConsolidating(string $user, ?string $epoch = null): void {
        $flag = self::flagPath($user);
        if ($epoch !== null) {
            // Epoch-conditional: only unlink when the stored epoch matches.
            $data = @json_decode((string)@file_get_contents($flag), true);
            if (!is_array($data)) {
                // Unreadable / not JSON — unconditionally clear (can't prove ownership).
                @unlink($flag);
                return;
            }
            if ((string)($data['epoch'] ?? '') !== $epoch) {
                // Epoch mismatch — this is not our marker; leave it alone.
                return;
            }
        }
        @unlink($flag);
    }

    /**
     * True iff a home consolidate is in progress for $user — i.e. the marker
     * exists AND is not stale.
     *
     * Staleness fallback (mirrors isInstallInProgress): if the flag is older than
     * HOME_CONSOLIDATE_STALE_SECS AND there is NO active consolidate-home job for
     * this user in the supervisor jobs ledger (state in {queued,running,deferred}),
     * the marker is crash residue — best-effort unlink and return false so `start`
     * can never be wedged permanently. A fresh marker, or a stale marker with a
     * still-active job, reports in progress.
     */
    public static function isHomeConsolidating(string $user): bool {
        if (trim($user) === '') return false;
        $flag = self::flagPath($user);
        if (!is_file($flag)) return false;

        $age = time() - (int)@filemtime($flag);
        if ($age <= self::HOME_CONSOLIDATE_STALE_SECS) {
            // Fresh marker is authoritative.
            return true;
        }

        // Stale mtime — only honour it if a consolidate job is genuinely still
        // active for this user (a long bake/remount). Otherwise it's residue.
        if (self::hasActiveConsolidateJob($user)) {
            return true;
        }

        @unlink($flag);
        return false;
    }

    /**
     * True if the supervisor jobs ledger holds a consolidate job for home/$user
     * whose state is queued, running, or deferred. Best-effort: any read error
     * → false (the caller then treats a stale marker as residue, never wedged).
     */
    private static function hasActiveConsolidateJob(string $user): bool {
        $safe = self::safeUser($user);
        $jobsDir = SupervisorService::jobsDir();
        $entity  = 'home/' . $safe;
        foreach ((array)@glob($jobsDir . '/*.json') as $f) {
            $job = @json_decode((string)@file_get_contents($f), true);
            if (!is_array($job)) continue;
            $op = (string)($job['op'] ?? '');
            if ($op !== 'consolidate') continue;
            if ((string)($job['entity'] ?? '') !== $entity) continue;
            $state = (string)($job['state'] ?? '');
            if (in_array($state, ['queued', 'running', 'deferred'], true)) {
                return true;
            }
        }
        return false;
    }
}
