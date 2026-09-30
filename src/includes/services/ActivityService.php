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

    /**
     * Parked state (docs/specs/UPGRADE_ACTIVATION_WITHOUT_CLOSED_SET.md): the
     * op's own work is finished and it now waits for an EXTERNAL condition with
     * no upper bound — e.g. an installed upgrade whose storage layer activates
     * only when the last process running the old version exits. No worker is
     * heartbeating, so a waiting entry is exempt from BOTH the stall detector
     * and the hard cap (which turned a 25-minute open workspace into a false
     * "FAILED: timeout"). A later progress update or finish() moves it on.
     */
    const STATUS_WAITING = 'waiting';

    /**
     * Tier 3 (destructive-proposal) state, PLUGIN_MANAGEMENT_TOOLS.md "Phase 3
     * as built" (2026-09-09): a tool VALIDATED a destructive request and is now
     * waiting for a HUMAN to approve or reject it in the Manager UI — the agent
     * that called the tool never executes it. Like STATUS_WAITING this has no
     * worker heartbeating it, so it is exempt from both the stall detector and
     * the hard cap: a proposal a human has not yet looked at must never be
     * auto-failed by a timer. Unlike every other entry this class writes, a
     * pending item's whole point is to survive the proposing agent's session
     * ending (spec Edge Cases) — see propose()/approve()/reject() below.
     */
    const STATUS_PENDING_APPROVAL = 'pending_approval';

    /**
     * True while listAll() still runs its own evaluate() pass, one release
     * after sweep() shipped (2026-09-11, docs/specs/EVENT_FIRST_RECONCILIATION.md
     * "Rules for the supervisor tick"). The supervisor now drives the watchdog
     * on its own 30 s tick, so a browser poll is no longer load-bearing for a
     * stall/timeout transition — but listAll() keeps evaluating too, for one
     * release, as a safety net while the sweep is observed in production.
     * Remove the evaluate() call inside listAll() (and this constant) in the
     * release AFTER that observation period.
     */
    const LIST_EVALUATES = true;

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
        // A progress update on a stalled or waiting op means it woke up — return
        // to running (a waiting op resumes when its external condition clears).
        $cur = $entry['status'] ?? '';
        if (($cur === 'stalled' || $cur === self::STATUS_WAITING) && !isset($fields['status'])) {
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
     * Park the op: its own work is done, it waits for an external condition
     * with no upper bound (see STATUS_WAITING). Exempt from stall/hard-cap
     * evaluation; dismissable; revived to running by the next progress update;
     * finished by finish(). No-op when the entry does not exist.
     */
    public static function wait(string $opId, string $step, int $progress = 99): ?array {
        $opId = self::safeOpId($opId);
        if ($opId === null || self::read($opId) === null) return null;
        return self::update($opId, [
            'status'   => self::STATUS_WAITING,
            'step'     => $step,
            'progress' => max(0, min(99, $progress)),
            'error'    => null,
        ]);
    }

    /**
     * Tier 3 (PLUGIN_MANAGEMENT_TOOLS.md "Phase 3 as built"): write a PENDING
     * item describing a destructive action a tool has already fully VALIDATED
     * but never executes itself. This IS the tray entry a human sees and acts
     * on — there is no separate audit write the way Tier 2's
     * recordChangeAudit() adds on top of the actual mutation, because here
     * nothing has happened yet.
     *
     * $params carries EXACTLY what approve() will execute later — re-reading
     * the target's live state at approval time would let it drift from what
     * the human actually read and approved. $caller/$description are for the
     * human reading the tray, never used to decide whether to execute.
     *
     * @param array<string,mixed> $params  What approve() will execute, verbatim.
     * @param array{workspaceId?:string,agentId?:string,name?:string} $caller
     */
    public static function propose(string $opId, string $tool, string $description, array $params, array $caller = []): ?array {
        $opId = self::safeOpId($opId);
        if ($opId === null) return null;
        $now = time();
        $entry = [
            'opId'        => $opId,
            'type'        => 'proposal',
            'label'       => $description,
            'step'        => 'Waiting for approval',
            'progress'    => 0,
            'status'      => self::STATUS_PENDING_APPROVAL,
            'startedAt'   => $now,
            'heartbeatAt' => $now,
            'error'       => null,
            'recovery'    => null,
            'meta'        => [
                'tool'        => $tool,
                'description' => $description,
                'params'      => $params,
                'proposedAt'  => $now,
                'workspaceId' => (string)($caller['workspaceId'] ?? ''),
                'agentId'     => (string)($caller['agentId'] ?? ''),
            ],
        ];
        self::save($entry);
        return $entry;
    }

    /**
     * A human clicked Approve. Atomically (best-effort — see class module
     * constraints on locking) transitions PENDING_APPROVAL -> 'approved' and
     * hands back the full entry (with its 'meta' — tool/params — intact) so
     * the caller (AdminService::approvePending()) can execute the recorded
     * action and then call finish()/fail(). Returns null when there is no
     * such entry OR it is not currently pending — in particular a SECOND
     * approve click on an already-approved/finished item is refused here
     * rather than executing twice. Never re-reads the proposing workspace or
     * agent: only this on-disk entry, so approval never depends on the
     * proposer's session still being alive (spec Edge Cases).
     */
    public static function approve(string $opId): ?array {
        $opId = self::safeOpId($opId);
        if ($opId === null) return null;
        $entry = self::read($opId);
        if ($entry === null || ($entry['status'] ?? '') !== self::STATUS_PENDING_APPROVAL) return null;
        return self::update($opId, ['status' => 'approved', 'step' => 'Approved — executing']);
    }

    /**
     * A human clicked Reject (or dismissed a proposal). Discards the pending
     * item WITHOUT executing it — marks it 'failed' with $reason as the error
     * so the tray shows why, the same terminal shape cancel() leaves behind.
     * Returns false when there is no such entry or it was not pending (e.g.
     * already approved/executed), so a caller can tell "nothing to reject"
     * from "rejected".
     */
    public static function reject(string $opId, string $reason = 'rejected by user'): bool {
        $opId = self::safeOpId($opId);
        if ($opId === null) return false;
        $entry = self::read($opId);
        if ($entry === null || ($entry['status'] ?? '') !== self::STATUS_PENDING_APPROVAL) return false;
        self::update($opId, ['status' => 'failed', 'error' => $reason]);
        return true;
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
     * All entries, watchdog-evaluated. The supervisor tick's sweep() (above)
     * is now the primary watchdog driver (docs/specs/EVENT_FIRST_RECONCILIATION.md);
     * this method still evaluates too, for one release, guarded by
     * LIST_EVALUATES — see that constant's doc comment. Done entries older
     * than DONE_TTL_SECONDS are pruned either way.
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

    /**
     * The supervisor-tick watchdog (docs/specs/EVENT_FIRST_RECONCILIATION.md
     * "Rules for the supervisor tick"): the same running->stalled->failed and
     * done-pruning transitions evaluate()/listAll() already drive, plus the
     * orphan storage-job finish (SupervisorService::syncJobActivities()) and
     * the stale install-marker unlink (UpgradeRelaunchService::
     * reapStaleInstallMarkers()) — both moved OUT of a read path (list_activities,
     * list_active_installs) so they run with no browser open at all. Called
     * from src/scripts/supervisor/activity-sweep.php every 30 s. Never throws:
     * a sweep miss must never break the tick that ran it.
     *
     * @return array{stalled:int,failed:int,pruned:int}
     */
    public static function sweep(): array {
        $counts = ['stalled' => 0, 'failed' => 0, 'pruned' => 0];
        foreach (glob(self::dir() . '/*.json') ?: [] as $file) {
            $entry = @json_decode((string)@file_get_contents($file), true);
            if (!is_array($entry) || empty($entry['opId'])) continue;
            $before = (string)($entry['status'] ?? '');
            $after  = self::evaluate($entry);
            if ($after === null) { $counts['pruned']++; continue; }
            $afterStatus = (string)($after['status'] ?? '');
            if ($before === $afterStatus) continue;
            if ($afterStatus === 'stalled') $counts['stalled']++;
            elseif ($afterStatus === 'failed') $counts['failed']++;
        }

        if (class_exists('\AICliAgents\Services\SupervisorService')) {
            try {
                \AICliAgents\Services\SupervisorService::syncJobActivities();
            } catch (\Throwable $e) {
                // Best-effort — a bridge fault must never break the rest of the sweep.
            }
        }

        if (class_exists('\AICliAgents\Services\UpgradeRelaunchService')) {
            try {
                \AICliAgents\Services\UpgradeRelaunchService::reapStaleInstallMarkers();
            } catch (\Throwable $e) {
                // Best-effort — same reasoning.
            }
        }

        return $counts;
    }

    /** Remove a finished/stalled/failed entry. Running entries must be cancelled first. */
    public static function dismiss(string $opId): bool {
        $opId = self::safeOpId($opId);
        if ($opId === null) return false;
        $entry = self::get($opId);
        if ($entry === null) return true; // already gone
        if (($entry['status'] ?? '') === 'running') return false;
        // A pending Tier 3 proposal must be explicitly rejected (reject(), which
        // records WHY), never silently swept away by the generic Dismiss button —
        // the whole point of a pending item is that discarding it is a decision,
        // not housekeeping.
        if (($entry['status'] ?? '') === self::STATUS_PENDING_APPROVAL) return false;
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
        // Same reasoning as dismiss(): a pending Tier 3 proposal is discarded by
        // reject() (records why), never by the generic Cancel button — there is
        // no worker/process to cancel here in the first place.
        if (($entry['status'] ?? '') === self::STATUS_PENDING_APPROVAL) {
            return ['status' => 'error', 'message' => 'a pending approval item must be rejected, not cancelled'];
        }

        $killed = false;
        // A waiting op has no live worker (its recorded pgid belongs to a process
        // that already exited and may have been reused) — never signal it.
        $pgid = ($entry['status'] ?? '') === self::STATUS_WAITING ? 0 : (int)($entry['pgid'] ?? 0);
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
        // Parked on an external condition: no worker, no deadline (see STATUS_WAITING).
        if ($status === self::STATUS_WAITING) return $entry;
        // Waiting on a HUMAN, not an external system condition — same no-deadline
        // exemption as STATUS_WAITING, for the same reason: no worker is
        // heartbeating this, so a stall/timeout verdict would be meaningless.
        if ($status === self::STATUS_PENDING_APPROVAL) return $entry;

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
        // A REDIRECTED store (AICLI_ACTIVITY_DIR — the test hook; nothing shipped sets it)
        // is not the live tray's data. Broadcasting it put phantom rows in every operator's
        // open tray: smoke isolates its store this way, yet its "Smoke install — FAILED —
        // timeout: exceeded 1200s hard cap" (A166, a forced timeout) and "Smoke job" (A174)
        // appeared as a red "1 failed" until the tray's next poll — far longer in a
        // background tab — for entries the server's own list_activities never returns.
        // docs/specs/ACTIVITY_TRAY.md
        $redirected = getenv('AICLI_ACTIVITY_DIR');
        if ($redirected !== false && $redirected !== '') return;
        EventBus::publish('activity', [], $data);
    }
}
