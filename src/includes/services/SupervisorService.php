<?php
/**
 * <module_context>
 *     <name>SupervisorService</name>
 *     <description>PHP wrapper for the Storage Durability Supervisor daemon (Phase 3.2).
 *     Provides isRunning, start, stop, getStatus, getTickAge, getWorkState, enqueue, isHalted,
 *     clearHalt, consolidateFailureCount, resetConsolidateFailures -- all silent on failure.</description>
 *     <dependencies>TraceContext (only — tiny, static, dependency-free; R-06 trace propagation. Still no dep on LogService so it is safe to call before init)</dependencies>
 *     <constraints>All public methods return null/false on failure -- no error_log, no exceptions.
 *     Pidfile is canonical; pgrep is fallback only. Path-anchored process checks (feedback_kill_patterns_vm_safety).</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

require_once __DIR__ . '/TraceContext.php';

class SupervisorService {

    public const PIDFILE    = '/var/run/aicli-supervisor.pid';
    public const LOCKFILE   = '/var/run/aicli-supervisor.lock';
    public const TICKFILE   = '/var/run/aicli-supervisor.tick';
    public const WORKFILE   = '/var/run/aicli-supervisor.work.json';
    public const STATUSFILE = '/tmp/unraid-aicliagents/supervisor.status.json';

    /** Supervisor runtime directories. */
    public const SUPERVISOR_DIR      = '/tmp/unraid-aicliagents/supervisor';
    public const HALTS_DIR           = '/tmp/unraid-aicliagents/supervisor/halts';
    public const QUEUE_DIR           = '/tmp/unraid-aicliagents/supervisor/queue';
    public const CONSOLIDATE_FAILS_DIR = '/tmp/unraid-aicliagents/supervisor/consolidate-fails';
    /** S-08 (#1353): job ledger — one <job_id>.json per tracked supervisor job. */
    public const JOBS_DIR            = '/tmp/unraid-aicliagents/supervisor/jobs';

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

    /**
     * Wake the supervisor NOW (SIGUSR1) so its inter-tick sleep breaks early and
     * the next work tick — including the deferred-consolidate resume check —
     * runs within ≤1 s instead of up to a full tick (5 s) later. Sent from the
     * session-close path so a deferred consolidate resumes the moment the user
     * closes the workspace, rather than feeling like "nothing happened". Sent to
     * the MAIN pid only (the supervisor traps USR1; the heartbeat subshell does
     * not and must never receive it). No-op if the supervisor isn't running.
     * Returns true if the signal was delivered.
     */
    public static function wake(): bool {
        $pid = self::readPidfile();
        if ($pid === null || $pid <= 0) {
            return false;
        }
        if (!self::pidOwnsScript($pid)) {
            return false; // stale/foreign pid — don't signal an unrelated process
        }
        if (!function_exists('posix_kill')) {
            return false;
        }
        $sig = defined('SIGUSR1') ? SIGUSR1 : 10; // Linux SIGUSR1 = 10
        return @posix_kill($pid, $sig);
    }

    /**
     * OP#1381 — supervisor self-heal. Detect a DEAD or WEDGED supervisor and
     * restore exactly one healthy instance. Cheap (one pgrep + a stat); safe to
     * call from the page-load / health-poll backstop on every request.
     *
     * The plain `if (!isRunning()) start()` backstop only covers a cleanly-dead
     * supervisor. It does NOT cover the wedged states observed on .4:
     *   (a) >1 live supervisor main process (single-instance race lost),
     *   (b) procs alive but the pidfile is EMPTY/stale (isRunning() false while
     *       a wedged proc still holds the flock — a fresh start() would just
     *       flock-wait 10s and exit, never displacing the wedge),
     *   (c) a supervisor that owns the pidfile but stopped ticking (stale tick
     *       beyond the budget — wedged main loop / dead-and-unrespawned heartbeat).
     *
     * Heal = SIGTERM→SIGKILL every supervisor main process (releasing the flock),
     * clear the pidfile, then start() one clean instance.
     *
     * SAFETY: a healthy supervisor mid-bake/consolidate keeps ticking (the
     * heartbeat is a separate process), so a FRESH tick (within budget) means
     * healthy even during a long op — we never kill it. We only heal on a
     * structural fault (wrong instance count / empty-pidfile-with-procs) or a
     * genuinely STALE tick. $tickBudgetSec defaults to max(30, 6×tick).
     *
     * @return string one of: 'ok' (nothing to do), 'started' (was down, started
     *                one), 'healed' (was wedged, killed strays + restarted),
     *                'noop' (script missing / could not act).
     */
    public static function ensureHealthy(?int $tickBudgetSec = null): string {
        if (!file_exists(self::SCRIPT_PATH)) {
            return 'noop';
        }

        $pids    = self::livePids();              // every live supervisor proc (path-anchored)
        $running = self::isRunning();

        if ($tickBudgetSec === null) {
            // Defensive: keep the "safe before init" property — only consult
            // ConfigService if it is loaded; otherwise assume the default tick.
            $tick = 5;
            if (class_exists('\\AICliAgents\\Services\\ConfigService')) {
                $tick = (int) (ConfigService::getConfig()['supervisor_tick_seconds'] ?? 5);
            }
            $tickBudgetSec = max(30, 6 * max(1, $tick));
        }
        $tickAge   = self::getTickAge();
        $tickFresh = ($tickAge !== null && $tickAge <= $tickBudgetSec);

        // (1) Clean down: no procs at all -> start one (clear a stale pidfile first).
        if ($pids === []) {
            if ($running) {
                @unlink(self::PIDFILE); // pidfile names a dead owner — stale
            }
            return self::start() ? 'started' : 'noop';
        }

        // (2) Healthy: the pidfile names a LIVE supervisor that owns the script
        //     (isRunning) AND the heartbeat tick is fresh. This is the normal case,
        //     INCLUDING (a) a long bake/consolidate in flight — the work child is a
        //     non-root tree member and the heartbeat keeps ticking — and (b) a
        //     TRANSIENT extra root: a racing `start` from a concurrent page-load
        //     backstop that has not yet lost the flock and exited (it drains within
        //     `flock -w 10`). The flock guarantees only ONE active supervisor; a
        //     transient loser is harmless, so we must NOT kill the healthy owner
        //     over it (that would turn a benign race into a real restart storm —
        //     every poll healing the box the previous poll just healed).
        if ($running && $tickFresh) {
            return 'ok';
        }

        // (3) Wedged: the pidfile names no live owner (empty/stale pidfile while
        //     procs run — a wedged proc holds the flock so a plain start() can't
        //     displace it), OR a stale tick (wedged main loop / dead-unrespawned
        //     heartbeat), OR genuinely >1 root with NO healthy owner. Kill every
        //     supervisor proc (releasing the flock), clear the pidfile, start one.
        //     A single transient extra root with a healthy owner never reaches here
        //     (covered by (2) above).
        self::reapAllSupervisors($pids);
        @unlink(self::PIDFILE);
        return self::start() ? 'healed' : 'noop';
    }

    /**
     * Public READ-ONLY view of the supervisor process footprint — the same set
     * livePids() enumerates for the self-heal reaper, exposed (non-mutating) so
     * the state-invariant auditor (StorageStateAuditService, Feature #1382) can
     * assert single-instance from ONE source of truth rather than re-deriving a
     * pgrep. Returns every live PID path-anchored to `<SCRIPT_PATH> ... start`
     * (main daemon + forked heartbeat subshell + work children).
     *
     * @return int[]
     */
    public static function daemonPids(): array {
        return self::livePids();
    }

    /**
     * READ-ONLY single-instance health verdict for the state-invariant auditor
     * (Feature #1382). Uses the SAME definition ensureHealthy() acts on so the
     * audit and the self-heal agree on what "single, healthy instance" means:
     * the flock guarantees only ONE active supervisor, so a LIVE pidfile owner
     * (isRunning) with a FRESH heartbeat is single-instance — EVEN with extra
     * path-matching procs (the forked heartbeat subshell, an in-flight work
     * child, or a transient losing `start` still draining its flock wait). A
     * genuine wedge is procs present with NO healthy owner OR a stale tick.
     *
     * @return array{healthy:bool, procs:int, running:bool, tick_age:?int, reason:string}
     */
    public static function singleInstanceHealth(?int $tickBudgetSec = null): array {
        $pids    = self::livePids();
        $running = self::isRunning();
        if ($tickBudgetSec === null) {
            $tick = 5;
            if (class_exists('\\AICliAgents\\Services\\ConfigService')) {
                $tick = (int) (ConfigService::getConfig()['supervisor_tick_seconds'] ?? 5);
            }
            $tickBudgetSec = max(30, 6 * max(1, $tick));
        }
        $tickAge   = self::getTickAge();
        $tickFresh = ($tickAge !== null && $tickAge <= $tickBudgetSec);

        if ($pids === []) {
            // No supervisor running at all — not a multi-instance fault. Whether
            // that is "should be running" is HealthService::evalSupervisor's call.
            return ['healthy' => true, 'procs' => 0, 'running' => $running, 'tick_age' => $tickAge, 'reason' => 'no daemon procs'];
        }
        if ($running && $tickFresh) {
            return ['healthy' => true, 'procs' => count($pids), 'running' => true, 'tick_age' => $tickAge, 'reason' => 'live owner, fresh heartbeat'];
        }
        $reason = !$running
            ? 'daemon proc(s) present but pidfile names no live owner (wedged — holds the flock)'
            : "heartbeat stale (tick {$tickAge}s > budget {$tickBudgetSec}s)";
        return ['healthy' => false, 'procs' => count($pids), 'running' => $running, 'tick_age' => $tickAge, 'reason' => $reason];
    }

    /**
     * Every live supervisor PID whose cmdline is path-anchored to
     * `<SCRIPT_PATH> ... start` (VM-safety — never a bare name match). Matches the
     * main supervisor AND its forked heartbeat subshell (inherits the cmdline) AND
     * any spawned work child still showing the script path. Used by the self-heal
     * reaper to release the flock by killing the whole supervisor footprint.
     *
     * @return int[]
     */
    private static function livePids(): array {
        $needle = self::SCRIPT_PATH;
        $out = [];
        $glob = @glob('/proc/[0-9]*/cmdline') ?: [];
        foreach ($glob as $f) {
            $raw = @file_get_contents($f);
            if ($raw === false || $raw === '') {
                continue;
            }
            $cmd = str_replace("\0", ' ', $raw);
            if (strpos($cmd, $needle) === false || strpos($cmd, ' start') === false) {
                continue;
            }
            if (preg_match('#/proc/(\d+)/cmdline#', $f, $m)) {
                $out[] = (int) $m[1];
            }
        }
        return $out;
    }

    /**
     * SIGTERM (grace) then SIGKILL every supervisor main/heartbeat PID. Specific
     * PIDs only — never a process-group kill (the #4b EXIT-trap-self-SIGTERM
     * lesson, plus VM safety). Releases the single-instance flock so a fresh
     * start() can take over.
     *
     * @param int[] $pids
     */
    private static function reapAllSupervisors(array $pids): void {
        foreach ($pids as $pid) {
            if ($pid > 1) {
                @posix_kill($pid, SIGTERM);
            }
        }
        // Brief grace for clean TERM handlers, then force survivors.
        $deadline = microtime(true) + 3.0;
        do {
            usleep(150000);
            $alive = false;
            foreach ($pids as $pid) {
                if ($pid > 1 && @posix_kill($pid, 0) === true) {
                    $alive = true;
                    break;
                }
            }
        } while ($alive && microtime(true) < $deadline);

        foreach ($pids as $pid) {
            if ($pid > 1 && @posix_kill($pid, 0) === true) {
                @posix_kill($pid, SIGKILL);
            }
        }
        usleep(200000);
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
     * @param string  $type     'home' or 'agent'
     * @param string  $id       entity id (username or agent key)
     * @param string  $op       'bake', 'consolidate' or 'mount' (S-08)
     * @param string  $reason   human-readable trigger reason
     * @param int     $priority 0-99
     * @param ?string $jobId    S-08 (#1353): optional job-ledger key. When set,
     *                          queue_enqueue writes a `queued` ledger entry at
     *                          JOBS_DIR/<jobId>.json and the supervisor records
     *                          running/done/failed/deferred transitions there.
     */
    public static function enqueue(
        string $type,
        string $id,
        string $op,
        string $reason,
        int $priority,
        ?string $jobId = null,
        bool $coalesce = false,
        ?string $consolidateEpoch = null
    ): bool {
        $helpersPath = self::queueHelpersPath();
        if (!file_exists($helpersPath)) {
            return false;
        }

        // Sanitize inputs for shell safety
        $type     = preg_replace('/[^a-z]/', '', $type);
        $id       = preg_replace('/[^A-Za-z0-9._\-]/', '_', $id);
        $op       = preg_replace('/[^a-z]/', '', $op);
        $reason   = preg_replace('/[^A-Za-z0-9_\-]/', '_', $reason);
        $priority = max(0, min(99, $priority));
        $jobArg   = '';
        if ($jobId !== null && preg_match('/^[A-Za-z0-9][A-Za-z0-9._\-]{0,127}$/', $jobId)) {
            // positional arg 6 (trace) passed as '' so queue_enqueue keeps its
            // AICLI_TRACE_ID env default (the ${6:-default} fallback fires on '').
            $jobArg = " '' $jobId";
        }
        $epochArg = '';
        if ($consolidateEpoch !== null && preg_match('/^[A-Za-z0-9._\-]{1,128}$/', $consolidateEpoch)) {
            // Passed as 8th positional to queue_enqueue (after job_id at position 7).
            // $jobArg already has a leading space + '' + space + job_id, so only append
            // when jobArg is non-empty (an untracked consolidate won't carry an epoch).
            if ($jobArg !== '') {
                $epochArg = ' ' . escapeshellarg($consolidateEpoch);
            }
        }

        $safeIdFs = preg_replace('/[^A-Za-z0-9._\-]/', '_', $id);
        $guard    = '';
        if ($coalesce) {
            // Mirror _check_consolidate_policy's filename-based skip: if a bake for
            // this entity is already queued, a second pre-install bake is redundant
            // (both just flush dirty home ZRAM). Keeps concurrent installs from
            // stacking N home deltas -> layers_near_max -> consolidation storm.
            // Check runs inside the same bash -c as queue_enqueue so check+enqueue
            // is one process — shrinks (not eliminates) the race window.
            $guard = 'ls "$QUEUE_DIR"/*_' . $type . '_' . $safeIdFs . '_' . $op
                   . '.req >/dev/null 2>&1 && exit 0; ';
        }

        $helpers  = escapeshellarg($helpersPath);
        // R-06: AICLI_TRACE_ID env prefix — queue_enqueue reads it and records an
        // additive "trace" key in the queue-entry JSON, so the supervisor's later
        // execution of this op joins the originating AJAX request end-to-end.
        // S-08: QUEUE_DIR/JOBS_DIR are pinned explicitly so the bash side always
        // writes where this class reads (and the AICLI_*_DIR test hooks redirect
        // both sides together).
        $cmd = TraceContext::shellPrefix()
            . 'QUEUE_DIR=' . escapeshellarg(self::queueDir())
            . ' JOBS_DIR=' . escapeshellarg(self::jobsDir())
            . ' ' . sprintf(
                'bash -c %s',
                escapeshellarg(
                    $guard . "source $helpers 2>/dev/null && queue_enqueue $priority $type $id $op $reason$jobArg$epochArg"
                )
            );

        $output = [];
        $ret    = 0;
        @exec($cmd, $output, $ret);
        return $ret === 0;
    }

    // -------------------------------------------------------------------------
    // Public API — job ledger (S-08, Feature #1353; see docs/specs/STORAGE_ASYNC_JOBS.md)
    // -------------------------------------------------------------------------

    /**
     * Enqueue a TRACKED op: generates a job id, enqueues with it, returns the
     * id (or null on enqueue failure). The ledger entry is written by
     * queue_enqueue (state=queued); the supervisor drives it to
     * running/done/failed/deferred.
     */
    public static function enqueueJob(
        string $type,
        string $id,
        string $op,
        string $reason,
        int $priority,
        ?string $consolidateEpoch = null
    ): ?string {
        $safeId = preg_replace('/[^A-Za-z0-9._\-]/', '_', $id);
        try {
            $rand = substr(bin2hex(random_bytes(4)), 0, 6);
        } catch (\Throwable $e) {
            $rand = substr((string)mt_rand(100000, 999999), 0, 6);
        }
        $jobId = sprintf('%s-%s-%s-%d-%s', preg_replace('/[^a-z]/', '', $op), $type, $safeId, time(), $rand);
        if (!self::enqueue($type, $id, $op, $reason, $priority, $jobId, false, $consolidateEpoch)) {
            return null;
        }
        return $jobId;
    }

    /**
     * Read one job-ledger entry. Returns the decoded array or null.
     * @return array<string,mixed>|null
     */
    public static function getJob(string $jobId): ?array {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._\-]{0,127}$/', $jobId)) {
            return null;
        }
        return self::readJsonFile(self::jobsDir() . "/$jobId.json");
    }

    /**
     * All ledger entries, newest first. $activeOnly keeps only
     * queued|running|deferred states (the set a UI poll cares about).
     * @return array<int,array<string,mixed>>
     */
    public static function listJobs(bool $activeOnly = true): array {
        $out = [];
        foreach (@glob(self::jobsDir() . '/*.json') ?: [] as $f) {
            $j = self::readJsonFile($f);
            if ($j === null || empty($j['job_id'])) {
                continue;
            }
            if ($activeOnly && !in_array($j['state'] ?? '', ['queued', 'running', 'deferred'], true)) {
                continue;
            }
            $out[] = $j;
        }
        usort($out, function ($a, $b) {
            return ($b['queued_at'] ?? 0) <=> ($a['queued_at'] ?? 0);
        });
        return $out;
    }

    /**
     * Newest ACTIVE (queued|running|deferred) job for an entity+op — the
     * dedup seam FileStorage::ensureReadyAsync rides so a poll/retry loop can
     * never pile mount jobs onto the queue.
     * @return array<string,mixed>|null
     */
    public static function findActiveJob(string $type, string $id, string $op): ?array {
        $entity = $type . '/' . $id;
        foreach (self::listJobs(true) as $j) {
            if (($j['entity'] ?? '') === $entity && ($j['op'] ?? '') === $op) {
                return $j;
            }
        }
        return null;
    }

    /**
     * S-08 bridge to the activity tray: the bash supervisor cannot call PHP
     * cheaply, so PHP polling paths (list_activities, storage_job_status,
     * storage_jobs_active) call this to mirror tracked-job state into any
     * `storage_job_<id>` activity entry that a user-initiated handler
     * registered. Entries the handlers never registered are left alone
     * (supervisor-internal jobs don't surface in the tray).
     */
    public static function syncJobActivities(): void {
        require_once __DIR__ . '/ActivityService.php';
        foreach (self::listJobs(false) as $job) {
            $jobId = (string)($job['job_id'] ?? '');
            if ($jobId === '') {
                continue;
            }
            $opId  = 'storage_job_' . $jobId;
            $entry = ActivityService::get($opId);
            if ($entry === null) {
                continue; // not user-initiated — no tray entry to mirror into
            }
            $state = (string)($job['state'] ?? '');
            $defer = isset($job['defer_reason']) && $job['defer_reason'] !== null ? (string)$job['defer_reason'] : '';
            switch ($state) {
                case 'done':
                    if (($entry['status'] ?? '') !== 'done') {
                        ActivityService::finish($opId);
                    }
                    break;
                case 'failed':
                    if (($entry['status'] ?? '') !== 'failed') {
                        $err = 'storage job failed (exit ' . (string)($job['exit'] ?? '?') . ')'
                             . ($defer !== '' ? ", reason: $defer" : '');
                        ActivityService::fail($opId, $err);
                    }
                    break;
                case 'running':
                    if (($entry['step'] ?? '') !== 'running') {
                        ActivityService::update($opId, ['step' => 'running', 'progress' => 50]);
                    } else {
                        ActivityService::heartbeat($opId);
                    }
                    break;
                case 'deferred':
                    // #1381 UX: render the human "why" verbatim in the tray
                    // (single source: TaskService::deferReasonHuman) instead of
                    // leaking the jargon token "deferred (mount_busy)".
                    $op   = isset($job['op']) ? (string)$job['op'] : '';
                    $step = TaskService::deferReasonHuman($defer, $op);
                    if (($entry['step'] ?? '') !== $step) {
                        ActivityService::update($opId, ['step' => $step, 'progress' => 25]);
                    } else {
                        ActivityService::heartbeat($opId);
                    }
                    break;
                default: // queued
                    ActivityService::heartbeat($opId);
                    break;
            }
        }

        // Orphan safety net: a user job that completed and was reaped from the
        // ledger (or whose terminal transition was missed across a supervisor
        // restart) would otherwise leave a stuck 'running'/'queued' tray entry.
        // Finish any storage_job_* activity still marked active that has no live
        // ledger job. With the supervisor's real-time push this is rare; this
        // guarantees the pill never freezes mid-flight.
        $liveOpIds = [];
        foreach (self::listJobs(false) as $job) {
            $jid = (string)($job['job_id'] ?? '');
            if ($jid !== '') $liveOpIds['storage_job_' . $jid] = true;
        }
        foreach (ActivityService::listAll() as $a) {
            $opId = (string)($a['opId'] ?? '');
            if (strpos($opId, 'storage_job_') !== 0) continue;
            if (isset($liveOpIds[$opId])) continue;
            $st = (string)($a['status'] ?? '');
            if ($st === 'running' || $st === 'stalled') {
                ActivityService::finish($opId, 'done');
            }
        }
    }

    /** Ledger dir — AICLI_JOBS_DIR redirects for tests (PHPUnit / smoke isolation). */
    public static function jobsDir(): string {
        $env = getenv('AICLI_JOBS_DIR');
        return ($env !== false && $env !== '') ? $env : self::JOBS_DIR;
    }

    /** Queue dir — AICLI_QUEUE_DIR redirects for tests. */
    public static function queueDir(): string {
        $env = getenv('AICLI_QUEUE_DIR');
        return ($env !== false && $env !== '') ? $env : self::QUEUE_DIR;
    }

    /** queue_helpers.sh path — AICLI_QUEUE_HELPERS redirects for tests (CI has no deployed tree). */
    private static function queueHelpersPath(): string {
        $env = getenv('AICLI_QUEUE_HELPERS');
        return ($env !== false && $env !== '') ? $env : self::QUEUE_HELPERS;
    }

    /**
     * Wait until any in-flight supervisor op for $type/$id has drained and the
     * queue holds no further work for that entity. Used by install-bg.php to
     * gate the agent binary swap on completion of a pre-install home bake
     * (WP #859 / AGENT_UPGRADE_HOME_BAKE_SYNC).
     *
     * Returns an array with:
     *   status      : 'completed' | 'no_pending_work' | 'timeout'
     *   waited_s    : seconds spent waiting (0 if no_pending_work returned fast)
     *
     * 'completed' means we observed a queued or running op for this entity
     * and watched it drain. 'no_pending_work' means we waited at least
     * $heartbeatGraceSec without ever seeing one queued or running (the normal
     * case when no sessions were close-required, or the supervisor is down).
     * 'timeout' means we hit $timeoutSec while an op for our entity was still
     * running — caller should proceed; commit_stack.sh's marker-timestamp
     * guard still protects against data loss.
     *
     * Polls at 500 ms; bounded by $timeoutSec. Heartbeat grace must be less
     * than the timeout; default 6 s covers the worst-case supervisor pickup
     * latency (5 s heartbeat + 1 s slack).
     */
    public static function waitForOpsToDrain(
        string $type,
        string $id,
        int $timeoutSec = 30,
        int $heartbeatGraceSec = 6
    ): array {
        $startedAt = microtime(true);
        $deadline = $startedAt + max(1, $timeoutSec);
        $entity = $type . '/' . $id;
        $sawAction = false;

        while (microtime(true) < $deadline) {
            $work = self::getWorkState();
            $busyForOurs = is_array($work)
                && ($work['state'] ?? '') === 'running'
                && ($work['entity'] ?? '') === $entity;

            $queuedForOurs = self::isQueuedFor($type, $id);

            if ($busyForOurs || $queuedForOurs) {
                $sawAction = true;
            } else {
                $elapsed = microtime(true) - $startedAt;
                if ($sawAction) {
                    return ['status' => 'completed', 'waited_s' => (int) round($elapsed)];
                }
                if ($elapsed >= $heartbeatGraceSec) {
                    return ['status' => 'no_pending_work', 'waited_s' => (int) round($elapsed)];
                }
            }

            usleep(500000);
        }

        return ['status' => 'timeout', 'waited_s' => $timeoutSec];
    }

    /**
     * Returns true if at least one queue request file targets $type/$id.
     * Filename convention (queue_helpers.sh): <prio>_<epoch>_<type>_<id>_<op>.req
     */
    private static function isQueuedFor(string $type, string $id): bool {
        if (!is_dir(self::QUEUE_DIR)) {
            return false;
        }
        $safeType = preg_replace('/[^a-z]/', '', $type);
        $safeId   = preg_replace('/[^A-Za-z0-9._\-]/', '_', $id);
        $pattern  = self::QUEUE_DIR . '/*_*_' . $safeType . '_' . $safeId . '_*.req';
        $matches  = @glob($pattern);
        return is_array($matches) && !empty($matches);
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
