<?php
/**
 * <module_context>
 *     <name>ActivityService</name>
 *     <description>Registry of in-flight slow operations (install/upgrade/storage/migrate/start)
 *     at /tmp/unraid-aicliagents/activity/&lt;opId&gt;.json with watchdog stall/timeout
 *     evaluation. Every state change is also published on the Nchan channel
 *     `aicli_activity` so the activity tray updates in real time. T-08/T-09/T-10 —
 *     see docs/specs/ACTIVITY_TRAY.md.</description>
 *     <dependencies>AtomicWriteService, NchanService</dependencies>
 *     <constraints>Static methods only. Never throws — activity tracking must not break
 *     the operation it observes. Watchdog runs lazily inside list()/get() evaluation
 *     (the polling AJAX path is the republish point — the bash supervisor cannot call
 *     PHP per tick).</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class ActivityService {

    /** Registry directory (tmpfs — cleared on reboot, which is correct: no op survives one). */
    const DIR = '/tmp/unraid-aicliagents/activity';

    /** Nchan channel suffix — NchanService::publish prefixes 'aicli_'. */
    const CHANNEL = 'activity';

    /** Watchdog: running entry whose heartbeat is older than this goes `stalled`. */
    const STALL_SECONDS = 120;

    /** Watchdog: per-type hard caps (seconds since startedAt) after which the op is `failed`. */
    const HARD_CAPS = [
        'install' => 1200,   // 20 min — report T-08 prescription
        'upgrade' => 1200,
        'storage' => 3600,
        'migrate' => 3600,
        'start'   => 300,
    ];

    /** Completed (`done`) entries are pruned from list() after this many seconds. */
    const DONE_TTL_SECONDS = 60;

    private static function dir(): string {
        // Test hook: PHPUnit isolates writes away from the live tmpfs tree.
        $env = getenv('AICLI_ACTIVITY_DIR');
        return ($env !== false && $env !== '') ? $env : self::DIR;
    }

    /** opId is used as a filename — restrict to a safe charset. Returns null when invalid. */
    private static function safeOpId($opId): ?string {
        $opId = (string)$opId;
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/', $opId) ? $opId : null;
    }

    private static function pathFor(string $opId): string {
        return self::dir() . "/$opId.json";
    }

    /**
     * Create (or recreate) an activity entry. Always resets startedAt/heartbeatAt.
     * $extra may carry pid/pgid (for cancel), recovery, meta, progress, step.
     */
    public static function register(string $opId, string $type, string $label, array $extra = []): ?array {
        $opId = self::safeOpId($opId);
        if ($opId === null) return null;
        $now = time();
        $entry = array_merge([
            'opId'        => $opId,
            'type'        => $type,
            'label'       => $label,
            'step'        => '',
            'progress'    => 0,
            'status'      => 'running',
            'startedAt'   => $now,
            'heartbeatAt' => $now,
            'error'       => null,
            'recovery'    => null,
        ], $extra);
        self::save($entry);
        return $entry;
    }

    /**
     * Merge $fields into an entry and refresh heartbeatAt. Creates the entry on
     * the fly when missing and $fields carries a 'type' (lets choke-point callers
     * like setInstallStatus wire in with a single call). 'type' and 'label' are
     * creation-only defaults — when the entry already exists (e.g. install-bg.php
     * registered it with a richer label), they are NOT overwritten.
     * Returns the entry or null.
     */
    public static function update(string $opId, array $fields): ?array {
        $opId = self::safeOpId($opId);
        if ($opId === null) return null;
        $entry = self::read($opId);
        if ($entry === null) {
            if (empty($fields['type'])) return null;
            return self::register($opId, (string)$fields['type'], (string)($fields['label'] ?? $opId), $fields);
        }
        unset($fields['type'], $fields['label']); // creation-only defaults
        // A progress update on a stalled op means it woke up — return to running.
        if (($entry['status'] ?? '') === 'stalled' && !isset($fields['status'])) {
            $fields['status'] = 'running';
        }
        $entry = array_merge($entry, $fields);
        $entry['heartbeatAt'] = time();
        self::save($entry);
        return $entry;
    }

    /** Refresh heartbeatAt only (no state change, no Nchan publish — heartbeats are cheap). */
    public static function heartbeat(string $opId): void {
        $opId = self::safeOpId($opId);
        if ($opId === null) return;
        $entry = self::read($opId);
        if ($entry === null) return;
        $entry['heartbeatAt'] = time();
        if (($entry['status'] ?? '') === 'stalled') {
            $entry['status'] = 'running';
            self::save($entry); // status change — publish
            return;
        }
        self::save($entry, false);
    }

    /** Terminal success: status done, progress 100. */
    public static function finish(string $opId, string $step = 'Done'): ?array {
        return self::update($opId, ['status' => 'done', 'progress' => 100, 'step' => $step, 'error' => null]);
    }

    /**
     * Terminal failure. Creates the entry when missing (auto-launch failures can
     * fire before any register — T-10). $extra may set meta/recovery/type/label.
     */
    public static function fail(string $opId, string $error, ?string $recovery = null, array $extra = []): ?array {
        $opId = self::safeOpId($opId);
        if ($opId === null) return null;
        $fields = array_merge($extra, [
            'status'   => 'failed',
            'error'    => $error,
            'recovery' => $recovery,
        ]);
        if (self::read($opId) === null) {
            return self::register($opId, (string)($extra['type'] ?? 'start'), (string)($extra['label'] ?? $opId), $fields);
        }
        return self::update($opId, $fields);
    }

    /** Read + watchdog-evaluate a single entry. */
    public static function get(string $opId): ?array {
        $opId = self::safeOpId($opId);
        if ($opId === null) return null;
        $entry = self::read($opId);
        return $entry === null ? null : self::evaluate($entry);
    }

    /**
     * All entries, watchdog-evaluated. This IS the watchdog: the tray's polling
     * (and any list_activities call) drives stalled/failed transitions, which are
     * persisted and republished here. Done entries older than DONE_TTL_SECONDS
     * are pruned.
     */
    public static function listAll(): array {
        $out = [];
        foreach (glob(self::dir() . '/*.json') ?: [] as $file) {
            $entry = @json_decode((string)@file_get_contents($file), true);
            if (!is_array($entry) || empty($entry['opId'])) continue;
            $entry = self::evaluate($entry);
            if ($entry === null) continue; // pruned
            $out[] = $entry;
        }
        usort($out, function ($a, $b) {
            return ($b['startedAt'] ?? 0) <=> ($a['startedAt'] ?? 0);
        });
        return $out;
    }

    /** Remove a finished/stalled/failed entry. Running entries must be cancelled first. */
    public static function dismiss(string $opId): bool {
        $opId = self::safeOpId($opId);
        if ($opId === null) return false;
        $entry = self::get($opId);
        if ($entry === null) return true; // already gone
        if (($entry['status'] ?? '') === 'running') return false;
        @unlink(self::pathFor($opId));
        self::publish(['opId' => $opId, 'dismissed' => true]);
        return true;
    }

    /**
     * Cancel a running/stalled op: kill the recorded process group (when one was
     * registered — only background workers like install-bg.php record a pgid) and
     * mark the entry failed with error 'cancelled'.
     */
    public static function cancel(string $opId): array {
        $opId = self::safeOpId($opId);
        if ($opId === null) return ['status' => 'error', 'message' => 'invalid opId'];
        $entry = self::read($opId);
        if ($entry === null) return ['status' => 'error', 'message' => 'no such activity'];
        if (in_array($entry['status'] ?? '', ['done', 'failed'], true)) {
            return ['status' => 'ok', 'message' => 'already finished', 'entry' => $entry];
        }

        $killed = false;
        $pgid = (int)($entry['pgid'] ?? 0);
        // Safety: never signal our own process group (an AJAX-registered op
        // carrying the PHP-FPM pool's pgid would take the web UI down).
        $ownPgid = function_exists('posix_getpgid') ? (int)@posix_getpgid(getmypid()) : 0;
        if ($pgid > 1 && $pgid !== $ownPgid && function_exists('posix_kill')) {
            $killed = @posix_kill(-$pgid, defined('SIGTERM') ? SIGTERM : 15);
            usleep(500000);
            @posix_kill(-$pgid, defined('SIGKILL') ? SIGKILL : 9);
        }

        $entry = self::update($opId, [
            'status'   => 'failed',
            'error'    => 'cancelled',
            'recovery' => $entry['recovery'] ?? null,
        ]);
        return ['status' => 'ok', 'killed' => $killed, 'entry' => $entry];
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private static function read(string $opId): ?array {
        $entry = @json_decode((string)@file_get_contents(self::pathFor($opId)), true);
        return (is_array($entry) && !empty($entry['opId'])) ? $entry : null;
    }

    /**
     * Watchdog evaluation (lazy — see module constraints):
     *   running + heartbeat older than STALL_SECONDS          -> stalled
     *   running|stalled + startedAt older than HARD_CAPS[type] -> failed (timeout)
     *   done + older than DONE_TTL_SECONDS                     -> pruned (returns null)
     * Transitions are persisted and published.
     */
    private static function evaluate(array $entry): ?array {
        $now    = time();
        $status = $entry['status'] ?? 'running';

        if ($status === 'done') {
            if ($now - (int)($entry['heartbeatAt'] ?? $now) > self::DONE_TTL_SECONDS) {
                @unlink(self::pathFor((string)$entry['opId']));
                return null;
            }
            return $entry;
        }
        if ($status === 'failed') return $entry;

        $cap = self::HARD_CAPS[$entry['type'] ?? ''] ?? 0;
        if ($cap > 0 && ($now - (int)($entry['startedAt'] ?? $now)) > $cap) {
            $entry['status'] = 'failed';
            $entry['error']  = 'timeout: exceeded ' . $cap . 's hard cap';
            self::save($entry);
            return $entry;
        }

        if ($status === 'running' && ($now - (int)($entry['heartbeatAt'] ?? $now)) > self::STALL_SECONDS) {
            $entry['status'] = 'stalled';
            self::save($entry);
            return $entry;
        }

        return $entry;
    }

    /** Persist atomically; publish the new state on aicli_activity unless suppressed. */
    private static function save(array $entry, bool $publish = true): void {
        $dir = self::dir();
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        AtomicWriteService::writeJson(self::pathFor((string)$entry['opId']), $entry, JSON_UNESCAPED_SLASHES);
        if ($publish) self::publish($entry);
    }

    private static function publish(array $data): void {
        // Skip when nginx's Nchan socket is absent (unit tests, CI containers) —
        // saves the 1-2s curl connect timeout per state change.
        if (!file_exists('/var/run/nginx.socket')) return;
        NchanService::publish(self::CHANNEL, $data);
    }
}
