<?php
/**
 * <module_context>
 *     <name>AutoLaunchService</name>
 *     <description>Server-side sweep that launches every workspace flagged for auto-launch. Triggered from kill-off events (plugin upgrade, array start, agent install, boot) so sessions are running before the user opens the AICliAgents tab.</description>
 *     <dependencies>ConfigService, AgentRegistry, ProcessManager, TerminalService, LogService, AutoLaunchSuppression</dependencies>
 *     <constraints>Static methods only. Idempotent — safe to call from multiple triggers because ProcessManager::isRunning skips already-live sessions.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class AutoLaunchService
{
    /** The restart state file: under the state root (the sandbox under test). */
    private static function restartStateFile(): string { return SecretPaths::stateRoot() . '/autolaunch-restart-state.json'; }
    private const MISSING_GRACE_SECONDS = 10;

    // #129: trigger reasons that represent a fresh box-boot, where the home
    // overlays are guaranteed idle (no session launched yet) — the one free
    // window to consolidate a bloated home before its sessions come back.
    private const BOOT_REASONS = ['init_boot', 'array_started'];
    // tmpfs marker so the boot consolidation split runs at most once per boot,
    // no matter which boot trigger (disks_mounted / InitService) fires first.
    /** The boot consolidate marker: under the state root (the sandbox under test). */
    private static function bootConsolidateMarker(): string { return SecretPaths::stateRoot() . '/.boot_consolidate_done'; }
    private const BOOT_CONSOLIDATE_WORKER = '/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/boot-consolidate-relaunch.php';

    /**
     * Test seams. All null in production.
     *   $launcher callable(array $sids): array — replaces launchAllPending() in reconcileDeadWorkspaces()
     *   $registryOverride, $startRunner — isolate launchAllPending() from installed agents and ttyd
     *   $stateFileOverride, $lockFileOverride — redirect the back-off state file and the sweep lock
     */
    public static $launcher = null;
    public static ?array $registryOverride = null;
    public static $startRunner = null;
    public static ?string $stateFileOverride = null;
    public static ?string $lockFileOverride = null;
    /** Test seam: callable(string $sid): bool — replaces ProcessManager::isTtydBound() in the sweep. */
    public static $ttydBoundProbe = null;

    /** Is a real ttyd bound to the socket of this workspace? */
    private static function ttydBound(string $sid): bool
    {
        if (is_callable(self::$ttydBoundProbe)) return (bool)call_user_func(self::$ttydBoundProbe, $sid);
        return ProcessManager::isTtydBound($sid);
    }

    /**
     * #759: a workspace is launched when its agent runs in tmux AND a ttyd is bound to its
     * socket. A launch that a timeout ended after the tmux step leaves the agent and no
     * ttyd; the browser cannot attach, so that workspace still counts as not launched.
     * The answer changes while another process launches the workspace, so it is impure.
     *
     * @phpstan-impure
     */
    private static function isLaunched(string $sid): bool
    {
        return ProcessManager::isRunning($sid) && self::ttydBound($sid);
    }

    private static function stateFile(): string
    {
        return self::$stateFileOverride ?? self::restartStateFile();
    }

    /** #129: is this trigger a genuine box-boot (homes idle)? */
    public static function isBootReason(string $reason): bool {
        return in_array($reason, self::BOOT_REASONS, true);
    }

    /**
     * #129 follow-up (pure, unit-tested): should this session be held back from
     * launch because its home is mid-consolidation? A relaunch into a consolidating
     * home re-opens the overlay and forces the reclaim/swap to defer.
     *
     * @param array<string, mixed> $session             one workspace record
     * @param string               $configUser          fallback user for legacy sessions with no 'user'
     * @param array<string, bool>  $consolidatingUsers  user => true when that home has the in-progress marker
     */
    public static function isHeldForConsolidation(array $session, string $configUser, array $consolidatingUsers): bool {
        $user = (string)($session['user'] ?? '');
        if ($user === '') $user = $configUser;
        return !empty($consolidatingUsers[$user]);
    }

    /**
     * #129 (pure, unit-tested): given per-home session ids, which users' homes
     * consolidation is recommended for, and which users already have a running
     * session, return the flat set (sid => true) of sessions to DEFER from the
     * immediate launch — i.e. sessions of homes that are both recommended AND
     * still idle. Their relaunch waits until after the home is consolidated.
     *
     * @param array<string, array<int, string>> $byUser        user => [sid, ...]
     * @param array<string, bool>               $recommended   user => true when storagectl recommends consolidation
     * @param array<string, bool>               $runningUsers  user => true when a session of that user is already live
     * @return array<string, bool>  sid => true for sessions to defer
     */
    public static function computeBootDeferSet(array $byUser, array $recommended, array $runningUsers): array {
        $defer = [];
        foreach ($byUser as $user => $sids) {
            if (empty($recommended[$user]) || !empty($runningUsers[$user])) continue;
            foreach ((array)$sids as $sid) {
                $sid = (string)$sid;
                if ($sid !== '') $defer[$sid] = true;
            }
        }

        return $defer;
    }

    /**
     * #129: at boot, decide which sessions to hold back for pre-launch home
     * consolidation, spawn the detached worker that consolidates those idle homes
     * and relaunches their sessions, and return the defer set so the caller skips
     * them in this sweep. Fine homes launch immediately (selective delayed
     * relaunch). No-op (returns []) when nothing is recommended or the worker is
     * missing — boot must never be blocked.
     *
     * @param array<int, array<string, mixed>> $sessions
     * @return array<string, bool>  sid => true for sessions deferred to the worker
     */
    private static function planBootConsolidation(array $sessions): array {
        $config     = ConfigService::getConfig();
        $configUser = (string)($config['user'] ?? '');
        if ($configUser === '') $configUser = 'root';

        $byUser = [];
        foreach ($sessions as $s) {
            $sid = (string)($s['id'] ?? '');
            if ($sid === '') continue;
            $user = (string)($s['user'] ?? '');
            if ($user === '') $user = $configUser;   // legacy sessions belong to the configured user
            $byUser[$user][] = $sid;
        }
        if ($byUser === []) return [];

        $runningUsers = [];
        foreach ($byUser as $user => $sids) {
            foreach ($sids as $sid) {
                if (ProcessManager::isRunning($sid)) { $runningUsers[$user] = true; break; }
            }
        }
        $recommended = [];
        foreach (array_keys($byUser) as $user) {
            if (empty($runningUsers[$user]) && StorageMountService::homeConsolidationRecommended($user)) {
                $recommended[$user] = true;
            }
        }
        if ($recommended === []) return [];

        $defer = self::computeBootDeferSet($byUser, $recommended, $runningUsers);
        if ($defer === []) return [];

        if (!is_file(self::BOOT_CONSOLIDATE_WORKER)) {
            // No worker to relaunch them — do NOT strand these sessions; let the
            // normal loop launch them and leave consolidation to the supervisor.
            self::log("Boot consolidate: worker script missing — launching normally, deferring nothing (#129)", AICLI_LOG_WARN);
            return [];
        }

        $job = ['users' => []];
        foreach (array_keys($recommended) as $user) $job['users'][$user] = $byUser[$user];
        $jobFile = SecretPaths::stateRoot() . '/.boot_consolidate_job_' . bin2hex(random_bytes(4)) . '.json';
        if (@file_put_contents($jobFile, json_encode($job)) === false) return [];
        // #337: fully detached — this runs from the boot sweep and the install.
        UtilityService::spawnDetached(['/usr/bin/php', self::BOOT_CONSOLIDATE_WORKER, $jobFile]);
        self::log("Boot consolidate: deferred " . count($defer) . " session(s) across " . count($recommended) . " home(s) for pre-launch consolidation (#129)", AICLI_LOG_INFO);
        return $defer;
    }

    /**
     * Launch every flagged workspace whose agent is installed and whose
     * session is not already running.
     *
     * Mirrors the filter chain in AutoLaunchHandler::getAutoLaunchPending so a
     * single source of truth governs which workspaces are eligible. Per-
     * workspace exceptions are caught and logged so one bad workspace can't
     * abort the rest of the sweep.
     *
     * @param ?string $filterAgentId If non-null, restrict the sweep to
     *                               workspaces of this agent (used by the
     *                               post-install hook in install-bg.php).
     *                               Null = sweep across all agents.
     * @param string  $reason        Free-form trigger label written to the
     *                               aicli_log so we can track which trigger
     *                               actually fired in production.
     * @return array{launched:int, skipped:int, failed:int, sessions:array, busy?:bool}
     */
    public static function launchAllPending(?string $filterAgentId = null, string $reason = 'unknown', ?array $onlySessionIds = null, bool $resumePreviouslyRunning = false): array
    {
        // Bug #532: serialise concurrent sweeps. PLG INLINE / disks_mounted /
        // InitService boot-marker can all fire within the same ~50 ms window
        // after Bug 521. Without this flock both processes pass the
        // ProcessManager::isRunning() check at line 76 (neither has called
        // startTerminal yet) and we end up with two ttyd processes briefly
        // racing on the same /var/run/aicliterm-<sid>.sock — the loser exits
        // silently. flock makes the second invocation wait for the first to
        // finish, after which its isRunning() check will see the live session
        // and skip cleanly.
        $lockPath = self::$lockFileOverride ?? '/var/run/aicli-autolaunch.lock';
        $lockFh   = @fopen($lockPath, 'c');
        if ($lockFh !== false) {
            // R-110: take the lock without blocking, in a poll loop with a deadline (a
            // blocking flock had no deadline at all). Sweeps are fast (each workspace just
            // kicks off a detached startTerminal), so 30 s is generous for a boot trigger.
            // The supervisor's crash reconcile runs under `timeout 20` and retries on the
            // next tick, so it does not wait: when a sweep is running it returns at once.
            $waitSeconds = $reason === 'supervisor_crash_reconcile' ? 0.0 : 30.0;
            $deadline = microtime(true) + $waitSeconds;
            $got = false;
            while (true) {
                if (@flock($lockFh, LOCK_EX | LOCK_NB)) { $got = true; break; }
                if (microtime(true) >= $deadline) break;
                usleep(100000);
            }
            if (!$got) {
                @fclose($lockFh);
                $lockFh = false;
                if ($reason === 'supervisor_crash_reconcile') {
                    self::log("Crash reconcile skipped: another auto-launch sweep is running", AICLI_LOG_INFO);
                    return ['launched' => 0, 'skipped' => 0, 'failed' => 0, 'sessions' => [], 'busy' => true];
                }
            }
        }

        // A storage-engine migration closes sessions and changes the storage
        // behind every agent. Hold the shared side of its lock for the whole
        // sweep so a migration cannot start between the running check and the
        // terminal start. Conversely, the migration's exclusive lock makes a
        // sweep return here without launching agents.
        if (class_exists(StorageBackendMigrationService::class)) {
            StorageBackendMigrationService::status(); // Reconcile a worker that stopped before it held the lock.
            $backendGuard = StorageBackendMigrationService::acquireLaunchGuard(false);
            if ($backendGuard === false || StorageBackendMigrationService::isMigrationInProgress()) {
                StorageBackendMigrationService::releaseLaunchGuard($backendGuard);
                if ($lockFh !== false) { @flock($lockFh, LOCK_UN); @fclose($lockFh); }
                self::log("Auto-launch deferred: global storage migration is running (trigger=$reason)", AICLI_LOG_INFO);
                return ['launched' => 0, 'skipped' => 0, 'failed' => 0, 'sessions' => [], 'busy' => true];
            }
        } else {
            $backendGuard = false;
        }

        $launched = 0;
        $skipped  = 0;
        $failed   = 0;
        $started  = [];

        try {
            $workspaces = ConfigService::getWorkspaces();
        } catch (\Throwable $e) {
            self::log("getWorkspaces failed: " . $e->getMessage(), AICLI_LOG_WARN);
            if (class_exists(StorageBackendMigrationService::class)) StorageBackendMigrationService::releaseLaunchGuard($backendGuard);
            if ($lockFh !== false) { @flock($lockFh, LOCK_UN); @fclose($lockFh); }
            return ['launched' => 0, 'skipped' => 0, 'failed' => 1, 'sessions' => []];
        }

        $sessions = $workspaces['sessions'] ?? [];
        $registry = self::$registryOverride ?? AgentRegistry::getRegistry();

        // #129 follow-up: never relaunch a session whose home is mid-consolidation
        // — a relaunch re-opens the overlay and forces the consolidate to defer,
        // leaving the layers untouched. The boot worker and the UI consolidate path
        // set this guard; respect it here so EVERY autolaunch trigger (supervisor
        // crash-reconcile, array start, agent install) holds off until the home is
        // clean. TerminalHandler already gates the interactive `start` the same way.
        if (is_file(__DIR__ . '/ConsolidateState.php')) require_once __DIR__ . '/ConsolidateState.php';
        $configUser = (string)(ConfigService::getConfig()['user'] ?? '');
        if ($configUser === '') $configUser = 'root';
        // One stat per distinct home user: is that home mid-consolidation right now?
        $consolidatingUsers = [];
        if (class_exists('\AICliAgents\Services\ConsolidateState')) {
            foreach ($sessions as $s) {
                $u = (string)($s['user'] ?? ''); if ($u === '') $u = $configUser;
                if (!array_key_exists($u, $consolidatingUsers)) {
                    $consolidatingUsers[$u] = \AICliAgents\Services\ConsolidateState::isHomeConsolidating($u);
                }
            }
        }

        // #129: on the first boot sweep, hold back sessions whose home needs
        // consolidation (guaranteed idle now) and hand them to a detached worker
        // that consolidates then relaunches them; fine homes launch immediately.
        // Once-per-boot marker so whichever boot trigger fires first owns the split.
        $deferSet = [];
        if (self::isBootReason($reason) && !file_exists(self::bootConsolidateMarker())) {
            @touch(self::bootConsolidateMarker());
            try {
                $deferSet = self::planBootConsolidation($sessions);
            } catch (\Throwable $e) {
                self::log("Boot consolidate planning failed, launching normally: " . $e->getMessage(), AICLI_LOG_WARN);
                $deferSet = [];
            }
        }

        foreach ($sessions as $session) {
            $path    = $session['path']    ?? '';
            $agentId = $session['agentId'] ?? '';
            $sid     = $session['id']      ?? '';
            if (!$agentId || !$path || !$sid) {
                $skipped++;
                continue;
            }
            // #129: this session's home is being consolidated first; the worker
            // will relaunch it once the home is clean.
            if (isset($deferSet[$sid])) {
                $skipped++;
                continue;
            }
            // #129 follow-up: home is mid-consolidation (marker set by the boot
            // worker or the UI path) — relaunching now would abort the reclaim.
            if (self::isHeldForConsolidation($session, $configUser, $consolidatingUsers)) {
                $skipped++;
                self::log("Auto-launch held for $sid: home is consolidating (trigger=$reason)", AICLI_LOG_INFO);
                continue;
            }
            if ($filterAgentId !== null && $agentId !== $filterAgentId) {
                $skipped++;
                continue;
            }
            if ($onlySessionIds !== null && !in_array($sid, $onlySessionIds, true)) {
                $skipped++;
                continue;
            }

            try {
                // R-C2: select by the AGENT-LEVEL flag. Every workspace whose
                // agent has auto-launch enabled is (re)launched, regardless of any
                // legacy per-workspace flag — that is the agent-level intent.
                $config = ConfigService::getAgentAutoLaunch($agentId);
                if (!$config['autoLaunch'] && !$resumePreviouslyRunning) {
                    $skipped++;
                    continue;
                }

                $agent = $registry[$agentId] ?? null;
                if (!$agent || empty($agent['is_installed'])) {
                    $skipped++;
                    continue;
                }

                // #759: a live tmux session is not enough. A launch that a timeout
                // killed after ensure-session leaves the agent in tmux and no ttyd,
                // and the browser cannot attach. Skip only when the ttyd is bound too.
                if (self::isLaunched((string)$sid)) {
                    $skipped++;
                    continue;
                }

                // Fix 2026-09-12: an operator or a tool closed this workspace on
                // purpose (graceful_close, stop, evict). This sweep is the
                // browser-independent restart path (#86, _check_saved_workspace_
                // restarts) — it must not undo a deliberate close either. See
                // docs/specs/2026-04-27-auto-launch-workspaces-design.md.
                if (!$resumePreviouslyRunning && AutoLaunchSuppression::isSuppressed($sid)) {
                    $skipped++;
                    continue;
                }

                // Issue #56: an array-start/page-load race can run this sweep
                // while /mnt/user is still an unmounted rootfs directory. Skip
                // cleanly; a later array-start or access sweep will retry.
                if (!StorageMountService::isPathAvailable($path)) {
                    $skipped++;
                    self::log("Auto-launch deferred for $sid ($agentId): workspace storage unavailable (trigger=$reason)", AICLI_LOG_WARN);
                    continue;
                }

                // Issue #761 (GitHub #10): the array is stopping and plugin storage is on
                // it. TerminalService::startTerminal refuses the start. Skip here, so the
                // refusal is not shown as a failed start in the tray. The sweep that runs
                // after the array start launches the workspace.
                if (StorageMountService::storageBlockedByArrayStop()) {
                    $skipped++;
                    self::log("Auto-launch deferred for $sid ($agentId): the array is stopping and plugin storage is on it (trigger=$reason)", AICLI_LOG_INFO);
                    continue;
                }

                $resumeId = ConfigService::getResumeId($path, $agentId, (string)$sid);
                if ($resumeId === null && !$config['freshIfNoResume'] && !$resumePreviouslyRunning) {
                    $skipped++;
                    continue;
                }

                $chatId = $resumeId !== null ? 'auto' : '';

                // #759: one launch of one workspace at a time, for every trigger.
                // The crash reconcile, the array start and the install can run
                // within seconds of each other. Take the workspace lock without
                // waiting. When another request holds it, that request launches
                // the workspace, so this trigger does nothing.
                if (!SessionLaunchLock::acquire((string)$sid, 0.0)) {
                    $skipped++;
                    self::log("Auto-launch of $sid skipped: another launch of this workspace is running (trigger=$reason)", AICLI_LOG_INFO);
                    continue;
                }
                $startResult = null;
                try {
                    // Check again under the lock: the holder before us may have finished.
                    if (self::isLaunched((string)$sid)) {
                        $skipped++;
                        self::log("Auto-launch of $sid skipped: another launch already finished (trigger=$reason)", AICLI_LOG_INFO);
                        continue;
                    }
                    self::log("Auto-launching workspace $sid for $agentId (trigger=$reason)", AICLI_LOG_INFO);
                    if (is_callable(self::$startRunner)) {
                        $startResult = call_user_func(self::$startRunner, $sid, $path, $chatId, $agentId);
                    } else {
                        $startResult = TerminalService::startTerminal($sid, $path, $chatId, $agentId, 'auto_launch');
                    }
                } finally {
                    SessionLaunchLock::release((string)$sid);
                }

                // T-10 (ACTIVITY_TRAY.md): startTerminal reports its own failures by
                // returning silently (mount/ttyd errors don't throw), so verify the
                // session actually came up. On failure, surface a recoverable
                // `type:start` activity — the tray's "Retry" button re-runs JUST
                // this workspace via the retry_auto_launch action.
                //
                // R-B3 (CLAUDE_RELAUNCH_SURVIVAL): isRunning now requires the AGENT
                // to be up — a live detached tmux session for this sid — not merely
                // "ttyd exists". A headless relaunch that brought up ttyd but no
                // agent (the old Bug #1067 failure mode) now correctly reports
                // failed here instead of falsely succeeding.
                //
                // #759: the launch is a success only when the agent runs AND a ttyd
                // is bound. A start that returned ok=false is a failure too, even
                // when the agent runs.
                $agentUp = ProcessManager::isRunning($sid);
                $bound = $agentUp && self::ttydBound((string)$sid);
                $startRefused = is_array($startResult) && array_key_exists('ok', $startResult) && $startResult['ok'] === false;
                if (!$agentUp || !$bound || $startRefused) {
                    $failed++;
                    $step = !$agentUp ? 'agent session (tmux)' : ($startRefused ? 'start step ' . (string)($startResult['reason'] ?? 'unknown') : 'terminal server (ttyd) socket');
                    self::log("Auto-launch failed for $sid ($agentId): no live terminal after the start; stopped at: $step (trigger=$reason)", AICLI_LOG_ERROR);
                    // ACTIVITY_TRAY_PLAIN_NOTICES.md rows 4-5: title "<Agent name> did not start",
                    // one red sentence, the way out as the grey line, and the Retry button.
                    TerminalService::recordStartFailure(
                        "start_$sid", UtilityService::trayAgentName((string)$agentId),
                        'The agent did not come up after the workspace started.',
                        'retry',
                        ['sessionId' => $sid, 'agentId' => $agentId, 'path' => $path, 'chatId' => $chatId],
                        TerminalService::START_RETRY_HINT
                    );
                    continue;
                }

                $launched++;
                $started[] = ['id' => $sid, 'agentId' => $agentId, 'path' => $path];
            } catch (\Throwable $e) {
                $failed++;
                self::log("Auto-launch failed for $sid ($agentId): " . $e->getMessage(), AICLI_LOG_WARN);
                // T-10: same recoverable activity for the exception path.
                // The exception message is in the log line above; the tray gets one sentence.
                TerminalService::recordStartFailure(
                    "start_$sid", UtilityService::trayAgentName((string)$agentId),
                    'The start stopped with an error; details are in the Debug Console.',
                    'retry',
                    ['sessionId' => $sid, 'agentId' => $agentId, 'path' => $path, 'chatId' => (isset($resumeId) && $resumeId !== null) ? 'auto' : ''],
                    TerminalService::START_RETRY_HINT
                );
            }
        }

        if ($launched > 0 || $failed > 0) {
            self::log("Auto-launch sweep ($reason): launched=$launched skipped=$skipped failed=$failed", AICLI_LOG_INFO);
        }

        if ($lockFh !== false) {
            @flock($lockFh, LOCK_UN);
            @fclose($lockFh);
        }
        if (class_exists(StorageBackendMigrationService::class)) StorageBackendMigrationService::releaseLaunchGuard($backendGuard);

        return [
            'launched' => $launched,
            'skipped'  => $skipped,
            'failed'   => $failed,
            'sessions' => $started,
        ];
    }

    /** Bounded crash-loop delay. Public so the policy is regression-testable. */
    public static function restartDelaySeconds(int $failedAttempts): int
    {
        $schedule = [10, 30, 60, 120, 300];
        $index = max(0, min(count($schedule) - 1, $failedAttempts - 1));
        return $schedule[$index];
    }

    /**
     * Headless reconciliation for saved workspaces whose agent process died.
     * The first missing observation only arms a grace timer; later observations
     * launch the exact saved session and apply bounded backoff after failures.
     */
    public static function reconcileDeadWorkspaces(?int $now = null): array
    {
        $now = $now ?? time();
        $state = [];
        if (is_file(self::stateFile())) {
            $decoded = json_decode((string)@file_get_contents(self::stateFile()), true);
            if (is_array($decoded)) $state = $decoded;
        }

        try {
            $sessions = ConfigService::getWorkspaces()['sessions'] ?? [];
        } catch (\Throwable $e) {
            self::log('Crash reconciliation could not read saved workspaces: ' . $e->getMessage(), AICLI_LOG_WARN);
            return ['armed' => 0, 'attempted' => 0, 'launched' => 0];
        }

        $savedIds = [];
        $eligible = [];
        $armed = 0;
        foreach ($sessions as $session) {
            $sid = (string)($session['id'] ?? '');
            $agentId = (string)($session['agentId'] ?? '');
            $path = (string)($session['path'] ?? '');
            if ($sid === '' || $agentId === '' || $path === '') continue;
            $savedIds[$sid] = true;

            $config = ConfigService::getAgentAutoLaunch($agentId);
            // Fix 2026-09-12: a suppressed workspace was closed on purpose —
            // never arm a grace timer or count a "failure" for it, exactly
            // like an upgrade-owned relaunch. See
            // docs/specs/2026-04-27-auto-launch-workspaces-design.md.
            if (!$config['autoLaunch'] || self::isLaunched((string)$sid)
                || self::upgradeOwnsRelaunch($agentId) || AutoLaunchSuppression::isSuppressed($sid)) {
                unset($state[$sid]);
                continue;
            }

            if (!isset($state[$sid]) || !is_array($state[$sid])) {
                $state[$sid] = ['first_missing_at' => $now, 'failures' => 0, 'next_attempt_at' => $now + self::MISSING_GRACE_SECONDS];
                $armed++;
                continue;
            }
            if ($now < (int)($state[$sid]['next_attempt_at'] ?? 0)) continue;
            $eligible[] = $sid;
        }

        // Intentional drawer closes disappear from workspaces.json. Purging them
        // here guarantees they can never be resurrected from stale retry state.
        foreach (array_keys($state) as $sid) {
            if (!isset($savedIds[$sid])) unset($state[$sid]);
        }

        $launched = 0;
        if ($eligible !== []) {
            // R-110: write the back-off BEFORE the launch. The supervisor runs this under
            // `timeout 20`, and a start can take 60 to 120 s: the process is then killed
            // inside the launch, the state is never written, and the slow start is begun
            // again at every tick. A success below removes the entry; a sweep that was
            // busy and tried nothing puts the old entry back.
            $before = [];
            foreach ($eligible as $sid) {
                $before[$sid] = $state[$sid];
                $failures = (int)($state[$sid]['failures'] ?? 0) + 1;
                $state[$sid]['failures'] = $failures;
                $state[$sid]['next_attempt_at'] = $now + self::restartDelaySeconds($failures);
            }
            AtomicWriteService::writeJson(self::stateFile(), $state);
            $res = is_callable(self::$launcher)
                ? call_user_func(self::$launcher, $eligible)
                : self::launchAllPending(null, 'supervisor_crash_reconcile', $eligible);
            foreach ($eligible as $sid) {
                // #759: done only with a bound ttyd; an agent with no ttyd is retried at the next tick.
                if (self::isLaunched((string)$sid)) {
                    unset($state[$sid]);
                    $launched++;
                } elseif (!empty($res['busy'])) {
                    $state[$sid] = $before[$sid];
                }
            }
        }

        AtomicWriteService::writeJson(self::stateFile(), $state);
        return ['armed' => $armed, 'attempted' => count($eligible), 'launched' => $launched];
    }

    /** Upgrades own stop/relaunch while any queue, install or activation marker exists. */
    private static function upgradeOwnsRelaunch(string $agentId): bool
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $agentId);
        $stateRoot = SecretPaths::stateRoot();
        if (is_file("$stateRoot/pending-agent-upgrade-$safe.json")) return true;
        if (is_file("$stateRoot/queued-agent-upgrade-$safe.json")) return true;
        $statusFile = "$stateRoot/install-status-$safe";
        if (!is_file($statusFile)) return false;
        $status = json_decode((string)@file_get_contents($statusFile), true);
        return is_array($status) && empty($status['completed']);
    }

    private static function log(string $msg, int $level): void
    {
        if (function_exists('aicli_log')) {
            aicli_log($msg, $level, 'AutoLaunchService');
        }
    }
}
