<?php
/**
 * <module_context>
 *     <name>AgentHandler</name>
 *     <description>Handles agent marketplace AJAX actions: install, uninstall, status, updates.</description>
 *     <dependencies>AICliAgentsManager, InstallerService, UtilityService</dependencies>
 *     <constraints>Under 150 lines. Each method returns array for JSON encoding.</constraints>
 * </module_context>
 */

namespace AICliAgents\Handlers;

class AgentHandler {

    /** Time limit override for long-running actions (seconds). */
    private static $TIME_LIMITS = [
        'install_agent'        => 900,
        'restore_agent_backup' => 300,
    ];

    public static function handle($action, $id) {
        // Apply per-action time limits
        if (isset(self::$TIME_LIMITS[$action])) {
            set_time_limit(self::$TIME_LIMITS[$action]);
        }

        switch ($action) {
            case 'install_agent':       return self::install();
            case 'emergency_install':   return self::emergencyInstall();
            case 'uninstall_agent':     return self::uninstall();
            case 'check_updates':       return self::checkUpdates();
            case 'check_versions':      return self::checkVersions();
            case 'get_version_cache':   return self::getVersionCache();
            case 'set_agent_channel':   return self::setAgentChannel();
            case 'list_active_installs': return self::listActiveInstalls();
            case 'get_upgrade_backup_estimate': return self::getUpgradeBackupEstimate();
            case 'restore_agent_backup': return self::restoreAgentBackup();
            case 'cancel_agent_upgrade': return self::cancelAgentUpgrade();
            default:                    return null;
            // Note: get_install_status outputs raw JSON and is dispatched directly
        }
    }

    /** Actions handled by this handler. */
    public static function actions() {
        return ['install_agent', 'emergency_install', 'get_install_status', 'uninstall_agent',
                'check_updates', 'check_versions', 'get_version_cache', 'set_agent_channel',
                'list_active_installs', 'get_upgrade_backup_estimate', 'restore_agent_backup',
                'cancel_agent_upgrade'];
    }

    /**
     * WP #964: restore an agent to a locally-retained version backup. Runs
     * synchronously — a restore is a local layer-copy + remount (seconds), not
     * a download — so the UI shows a blocking "Restoring…" overlay rather than
     * the background install-progress panel.
     */
    private static function restoreAgentBackup() {
        $agentId   = $_GET['agentId'] ?? '';
        $backupDir = (string)($_GET['backup_dir'] ?? '');
        if (empty($agentId) || $backupDir === '') {
            return ['status' => 'error', 'message' => 'Missing agentId or backup_dir'];
        }
        return \AICliAgents\Services\InstallerService::restoreAgentVersion($agentId, $backupDir);
    }

    /**
     * #71 (cancel): abandon a QUEUED agent upgrade before it fires. When a user
     * upgrades an agent with running sessions and does NOT force-close them, the
     * upgrade is queued (a pending request + a "queued" status chip) and the
     * supervisor auto-starts it once the sessions close. This lets the user back
     * out of that queued upgrade. Queued-only: an upgrade whose binary swap has
     * already begun is NOT interrupted (that could leave a half-installed agent).
     */
    private static function cancelAgentUpgrade() {
        $agentId = (string)($_GET['agentId'] ?? $_POST['agentId'] ?? '');
        if ($agentId === '' || !array_key_exists($agentId, \AICliAgents\Services\AgentRegistry::getDefaultAgents())) {
            return ['status' => 'error', 'message' => 'Invalid agent id'];
        }
        // Queued-only guard: never cancel a running binary swap.
        if (\AICliAgents\Services\PendingAgentUpgradeService::backgroundInstallRunning($agentId)) {
            return ['status' => 'error', 'reason' => 'already_running',
                    'message' => 'The upgrade has already started and can no longer be cancelled.'];
        }
        $wasQueued = \AICliAgents\Services\PendingAgentUpgradeService::read($agentId) !== [];
        \AICliAgents\Services\PendingAgentUpgradeService::cancel($agentId);
        \AICliAgents\Services\UtilityService::clearInstallStatus($agentId);
        aicli_log("Agent upgrade cancelled by user for $agentId (was_queued=" . ($wasQueued ? '1' : '0') . ")", AICLI_LOG_INFO);
        return ['status' => 'ok', 'cancelled' => $wasQueued,
                'message' => $wasQueued ? 'Queued upgrade cancelled.' : 'No queued upgrade to cancel.'];
    }

    /**
     * WP #964 (slice): size + free-space estimate for the pre-upgrade "keep a
     * copy" overlay. With no `dest` the backend defaults it to the persistence
     * location and echoes the resolved path back, so the overlay's destination
     * field has a single source of truth.
     */
    private static function getUpgradeBackupEstimate() {
        $agentId = $_GET['agentId'] ?? '';
        if (empty($agentId)) {
            return ['status' => 'error', 'message' => 'No Agent ID provided'];
        }
        $dest = (string)($_GET['dest'] ?? '');
        return \AICliAgents\Services\InstallerService::estimateUpgradeBackup($agentId, $dest);
    }

    /**
     * Scan /tmp/unraid-aicliagents/install-status-* and return the agent ids
     * whose install is still in progress (progress > 0 and < 100). The UI
     * uses this to grey out icons in the New Workspace overlay + disable
     * launch buttons in the drawer.
     *
     * Read-only (docs/specs/EVENT_FIRST_RECONCILIATION.md R3): a stale marker
     * is skipped here, never unlinked — the unlink moved to the supervisor-tick
     * sweep (ActivityService::sweep() -> UpgradeRelaunchService::
     * reapStaleInstallMarkers()), so it happens with no browser open too.
     */
    private static function listActiveInstalls() {
        $dir = '/tmp/unraid-aicliagents';
        $active = [];
        $seen = [];
        foreach (glob("$dir/install-status-*") ?: [] as $f) {
            $base = basename($f);
            if (!preg_match('/^install-status-([a-z0-9][a-z0-9-]{0,63})$/', $base, $m)) continue;
            $status = @json_decode((string)@file_get_contents($f), true);
            if (!is_array($status)) continue;
            // A safely queued upgrade is deliberately not active: terminals
            // remain usable until every session closes naturally.
            if (($status['phase'] ?? '') === 'queued') continue;
            $progress = (int)($status['progress'] ?? 0);
            if ($progress > 0 && $progress < 100) {
                // Honour the same staleness guard as isInstallInProgress: a marker
                // that is old with no install-bg process running is a crash
                // residue, so it is left out of the active list (never shown as
                // "installing" forever) — but only the sweep unlinks the file.
                $age = time() - (int)@filemtime($f);
                if ($age > self::INSTALL_STALE_THRESHOLD_SECS) {
                    $agentId = $m[1];
                    if (\AICliAgents\Services\UpgradeRelaunchService::hasPendingAgentUpgrade($agentId)) {
                        $active[] = [
                            'agentId' => $agentId,
                            'progress' => 99,
                            'status' => 'Waiting for running processes before activating the upgraded version',
                        ];
                        $seen[$agentId] = true;
                        continue;
                    }
                    $cmd = "timeout 2 ps aux | grep 'install-bg.php " . escapeshellarg($agentId) . "' | grep -v grep";
                    exec($cmd, $ignored, $rc);
                    if ($rc !== 0) {
                        continue;
                    }
                }
                $active[] = [
                    'agentId'  => $m[1],
                    'progress' => $progress,
                    'status'   => (string)($status['status_text'] ?? $status['status'] ?? ''),
                ];
                $seen[$m[1]] = true;
            }
        }
        foreach (\AICliAgents\Services\UpgradeRelaunchService::pendingAgentIds() as $agentId) {
            if (isset($seen[$agentId])) continue;
            $active[] = [
                'agentId' => $agentId,
                'progress' => 99,
                'status' => 'Waiting for running processes before activating the upgraded version',
            ];
        }
        return ['status' => 'ok', 'active' => $active];
    }

    /**
     * True when an install/upgrade is in progress for $agentId. Single source of
     * truth for both the install() already-running guard and TerminalHandler::start
     * (UPGRADE_RELAUNCH_ZOMBIE_SKIP R2) — belt-and-suspenders: checks BOTH the
     * install-status in-progress marker (set EARLY in install(), before sessions
     * are closed) AND a live `install-bg.php <agentId>` process.
     *
     * Staleness guard: if the status-file shows 1–99 but is older than
     * INSTALL_STALE_THRESHOLD_SECS AND no install-bg process is running, the
     * install crashed (SIGKILL / OOM) without writing progress=100. Treat as
     * NOT in progress and best-effort clear the marker so `start` is unblocked.
     */
    const INSTALL_STALE_THRESHOLD_SECS = 180;

    public static function isInstallInProgress(string $agentId): bool {
        if ($agentId === '') return false;

        // #72: install-bg can finish while activation is deferred by an open
        // agent mount. Reopening a retired workspace while its closed-set
        // manifest remains would pin the stale binary indefinitely.
        if (\AICliAgents\Services\UpgradeRelaunchService::hasPendingAgentUpgrade($agentId)) {
            return true;
        }

        $statusFile = "/tmp/unraid-aicliagents/install-status-$agentId";
        $markerInProgress = false;
        if (is_file($statusFile)) {
            $status = @json_decode((string)@file_get_contents($statusFile), true);
            if (is_array($status)) {
                $progress = (int)($status['progress'] ?? 0);
                if (($status['phase'] ?? '') !== 'queued' && $progress > 0 && $progress < 100) {
                    $markerInProgress = true;
                }
            }
        }

        // Signal 2: a live install-bg.php process for this agent.
        $cmd = "timeout 2 ps aux | grep 'install-bg.php " . escapeshellarg($agentId) . "' | grep -v grep";
        $out = [];
        exec($cmd, $out, $processRunning);
        $processRunning = ($processRunning === 0);

        if ($processRunning) {
            // A live install-bg process is authoritative — in progress regardless
            // of the marker's age.
            return true;
        }

        if ($markerInProgress) {
            // No process. Check whether the marker is fresh (written recently)
            // or stale (crash residue from a killed install-bg).
            $age = time() - (int)@filemtime($statusFile);
            if ($age <= self::INSTALL_STALE_THRESHOLD_SECS) {
                // Signal 1 (fresh marker): in progress — process may not have
                // appeared yet (race between marker write and exec).
                return true;
            }
            // Stale marker with no process → crashed install. Best-effort clear
            // so subsequent start() calls are not wedged permanently.
            @unlink($statusFile);
            return false;
        }

        return false;
    }

    /**
     * Why this agent's upgrade must still wait for its sessions to close, or
     * null when it can be installed beside the version in service.
     *
     * docs/specs/SIDE_BY_SIDE_AGENT_INSTALLS.md Phase 3 (2026-09-15), Forgejo
     * #216. ONE function owns this decision, and both the pre-lock and the
     * post-lock check call it, because the spec is explicit that an install
     * path which thinks it must close sessions and a side-by-side path that has
     * made closing unnecessary must never half-run together.
     *
     * The three reasons to still wait:
     *
     *  1. The agent's source type is not one whose install output is entirely
     *     reconstructible. In practice: `curl_install` agents, whose vendor
     *     scripts land their binary inside a captive home directory inside the
     *     agent's own tree. Versioning that path is real work with its own
     *     phase; claiming side-by-side for them before it is done would be
     *     claiming a guarantee that is not there.
     *  2. The agent is not on the versioned layout yet — it has never been
     *     mounted at a generation-qualified path, so there is no second place
     *     to put a version. This resolves itself: the first upgrade after this
     *     code ships converts the layout, and every later one qualifies.
     *  3. The ceiling on concurrently-mounted generations is already reached.
     *     Each one costs a live overlay plus a writable layer of a few hundred
     *     megabytes on a write-endurance-limited stick, so past the ceiling the
     *     honest answer is to wait rather than to keep stacking versions.
     *
     * The message is written to be read by a person: it is what the Store card
     * shows underneath "Upgrade queued safely".
     */
    public static function sideBySideInstallBlocker(string $agentId): ?string {
        $registry = \AICliAgents\Services\AgentRegistry::getRegistry();
        $agent = $registry[$agentId] ?? null;
        if (!is_array($agent)) {
            return 'this agent is not in the registry';
        }
        if (!\AICliAgents\Services\Sources\SourceResolver::supportsSideBySideInstall($agent)) {
            return 'this agent keeps its sign-in inside its own install folder, so a new version cannot run beside the old one yet';
        }

        $mounted = \AICliAgents\Services\AgentRegistry::mountedGenerations($agentId);
        if ($mounted === []) {
            return 'this agent has not been moved onto the side-by-side layout yet; the next upgrade will do that';
        }
        if (!\AICliAgents\Services\AgentRegistry::canAddGeneration(count($mounted))) {
            return 'two versions of this agent are already running; close a workspace before installing a third';
        }
        return null;
    }

    private static function install() {
        $agentId = $_GET['agentId'] ?? '';
        $version = (string)($_GET['version'] ?? '');
        $backupDest = (($_GET['backup'] ?? '') === '1') ? trim((string)($_GET['backup_dest'] ?? '')) : '';
        $force = (($_GET['force'] ?? '') === '1');
        return self::installCore((string)$agentId, $version, $backupDest, $force);
    }

    /**
     * The install/upgrade action itself, with no $_GET dependency — factored out
     * of install() (Tier 3, PLUGIN_MANAGEMENT_TOOLS.md "Phase 3 as built") so
     * AdminService::executeApprovedUpgradeAgent() can run the EXACT same code
     * path a human's "Upgrade" click runs, after a Tier-3 proposal is approved,
     * instead of a second copy of this logic. install() above is now a thin
     * $_GET-reading wrapper; every line below is unchanged from before the refactor.
     *
     * @return array<string,mixed>
     */
    public static function installCore(string $agentId, string $version = '', string $backupDest = '', bool $force = false): array {
        if (empty($agentId)) {
            return ['status' => 'error', 'message' => 'No Agent ID provided'];
        }

        // Check if an installation is already active for this specific agent
        $cmd = "timeout 2 ps aux | grep 'install-bg.php " . escapeshellarg($agentId) . "' | grep -v grep";
        exec($cmd, $out, $res);
        if ($res === 0) {
            return ['status' => 'error', 'message' => 'An installation is already in progress for this agent.'];
        }

        $sessions = \AICliAgents\Services\TerminalService::listActiveSessionsForAgent($agentId);

        // #71: waiting is the default and is entirely non-destructive. This
        // backend check is authoritative even if a stale UI calls the endpoint.
        //
        // docs/specs/SIDE_BY_SIDE_AGENT_INSTALLS.md Phase 3 (2026-09-15), #216:
        // the wait is no longer the only safe answer. When this agent can be
        // installed beside the version in service, open sessions are not a
        // reason to defer anything — they keep the version they launched with
        // until their workspace is reloaded, and the install writes into a layer
        // of its own. See sideBySideInstallBlocker() for the three conditions
        // that still send an upgrade back to the queue.
        if (!$force && $sessions !== []) {
            $blocker = self::sideBySideInstallBlocker($agentId);
            if ($blocker !== null) {
                \AICliAgents\Services\LogService::log(
                    "Upgrade for $agentId queued behind " . count($sessions) . " session(s): $blocker",
                    \AICliAgents\Services\LogService::LOG_INFO, "AgentHandler"
                );
                return \AICliAgents\Services\PendingAgentUpgradeService::queue(
                    $agentId, $version, $backupDest, count($sessions), [], $blocker
                );
            }
            \AICliAgents\Services\LifecycleLogService::log(
                \AICliAgents\Services\LifecycleLogService::LEVEL_INFO, 'installer',
                'agent_upgrade_no_wait_required',
                ['agent' => $agentId, 'open_sessions' => count($sessions)]
            );
        }

        $admission = null;
        if (!$force) {
            $admission = \AICliAgents\Services\AgentUpgradeAdmissionService::acquire($agentId);
            if ($admission === null) {
                // Another install of this agent holds the admission lock. That
                // is a genuine conflict whatever the install strategy, so it
                // queues either way.
                return \AICliAgents\Services\PendingAgentUpgradeService::queue(
                    $agentId, $version, $backupDest, count($sessions)
                );
            }
            // A terminal may have reached final registration after the first
            // list but before this lock. Recheck while admission is exclusive —
            // and apply the SAME side-by-side decision, or an upgrade that was
            // just cleared to proceed would fall into the queue a moment later
            // because one workspace opened in between.
            $sessions = \AICliAgents\Services\TerminalService::listActiveSessionsForAgent($agentId);
            $lateBlocker = $sessions !== [] ? self::sideBySideInstallBlocker($agentId) : null;
            if ($lateBlocker !== null) {
                \AICliAgents\Services\AgentUpgradeAdmissionService::release($admission);
                return \AICliAgents\Services\PendingAgentUpgradeService::queue(
                    $agentId, $version, $backupDest, count($sessions), [], $lateBlocker
                );
            }
        }

        // An immediate or explicitly forced install supersedes an older queue.
        \AICliAgents\Services\PendingAgentUpgradeService::cancel($agentId);

        try {

        // #158: refuse the upgrade up-front when the agent binary is held by a
        // process that is NOT one of this agent's own workspaces (e.g. a Claude
        // workspace running `opencode` as a tool). We must not force-kill someone
        // else's agent, and swapping the binary while it is held wedges the
        // remount. Tear down nothing — ask the operator to close the external
        // holder and retry. Fail-open: detection miss returns no holders.
        if ($force) {
            $ourSids = array_values(array_filter(array_map(
                static fn($s): string => (string)($s['id'] ?? ''),
                \AICliAgents\Services\TerminalService::listActiveSessionsForAgent($agentId)
            )));
            $externalHolders = self::externalBinaryHolders($agentId, $ourSids);
            if (!empty($externalHolders)) {
                $n = count($externalHolders);
                $name = \AICliAgents\Services\AgentRegistry::getDefaultAgents()[$agentId]['name'] ?? $agentId;
                // #87: name the blockers (pid, command, cwd, tmux session, age) instead
                // of a bare count, so the operator can find and close them.
                $msg = "$name is still in use by $n process(es) outside its workspaces. Close those and retry the upgrade:\n"
                    . self::describeHolders($externalHolders);
                aicli_log("Upgrade deferred for $agentId: $n external binary holder(s) — " . implode(',', array_map(
                    static fn($h): string => $h['pid'] . '@' . ($h['sid'] !== '' ? $h['sid'] : '?'), $externalHolders
                )), AICLI_LOG_WARN);
                \AICliAgents\Services\UtilityService::clearInstallStatus($agentId);
                return ['status' => 'error', 'message' => $msg, 'reason' => 'binary_in_use_external', 'holders' => $externalHolders];
            }
        }

        // R2 (UPGRADE_RELAUNCH_ZOMBIE_SKIP): write the in-progress marker as the
        // FIRST mutating action — BEFORE _closeSessionsForUpgrade — so a racing
        // `start` reliably observes the upgrade and refuses to spawn a zombie
        // session during the binary swap. clearInstallStatus runs here (not later)
        // so this early marker survives; the later setInstallStatus calls update it.
        \AICliAgents\Services\UtilityService::clearInstallStatus($agentId);
        setInstallStatus("Upgrade starting…", 5, $agentId);

        // Phase 1: graceful-close any active workspace sessions using this
        // agent BEFORE the binary is replaced. Preserves each session's
        // resume id and list them for the UI.
        $preClosed = $force ? self::_closeSessionsForUpgrade($agentId) : [];

        // Enqueue a home bake before the install so any dirty ZRAM is durable
        // before the binary replacement and potential remount. The supervisor
        // handles the bake asynchronously; the AJAX response does not block.
        $config = getAICliConfig();
        $user = $config['user'] ?? 'root';
        if (empty($user)) $user = 'root';
        \AICliAgents\Services\SupervisorService::enqueue('home', $user, 'bake', 'pre_agent_install', 5, null, true);

        // Advance the marker now that sessions are closed and the job is about to
        // launch (the early "Upgrade starting…" marker set above remains in place
        // throughout — do NOT clear it here, or the start guard's window reopens).
        setInstallStatus("Starting installation job...", 5, $agentId);
        // Record pre-closed sessions inside install-status so the UI can
        // surface them on completion.
        if (!empty($preClosed)) {
            // $agentId is registry-validated before reaching this handler, so
            // $statusFile is a known local tmpfs path — not an outbound URL.
            $statusFile = "/tmp/unraid-aicliagents/install-status-$agentId";
            $cur = @json_decode((string)@file_get_contents($statusFile), true) ?: [];
            $cur['pre_closed_sessions'] = $preClosed;
            @file_put_contents($statusFile, json_encode($cur)); // nosemgrep: php.lang.security.tainted-url-to-connection.tainted-url-to-connection
        }
        // WP #964 (slice): optional pre-upgrade backup. The version + backup-dest
        // slots are passed positionally and ALWAYS present (empty string when
        // unused) so install-bg.php can read argv[2]/argv[3] unambiguously.
        $versionArg = " " . escapeshellarg($version);
        $backupArg  = " " . escapeshellarg($backupDest);
        // #176: strip AICLI_SESSION_ID so the detached install job is NEVER a
        // "session descendant" the reaper (terminateSessionDescendants, matches
        // AICLI_SESSION_ID in /proc/environ) can kill mid-install. Needed when an
        // upgrade is triggered from inside an agent session; /proc/environ is
        // fixed at exec, so this must happen at the spawn site.
        aicli_exec_bg("env -u AICLI_SESSION_ID /usr/bin/php /usr/local/emhttp/plugins/unraid-aicliagents/scripts/install-bg.php " . escapeshellarg($agentId) . $versionArg . $backupArg);
            // Tell the caller WHAT KIND of install this is. The Store hides every
            // terminal for an agent while it installs, which is right only when
            // the install will actually interrupt those sessions. A side-by-side
            // install never touches them, so hiding them there is a roadblock
            // that does nothing but misinform — it used to say "session will
            // resume automatically" about a session that was never stopped.
            // docs/specs/UPGRADE_WITHOUT_INTERRUPTION.md
            return [
                'status'              => 'ok',
                'message'             => 'Installation started',
                'pre_closed_sessions' => $preClosed,
                'side_by_side'        => (!$force && self::sideBySideInstallBlocker($agentId) === null),
                'open_sessions'       => count($sessions),
            ];
        } finally {
            \AICliAgents\Services\AgentUpgradeAdmissionService::release($admission);
        }
    }

    /**
     * Graceful-close every active session using $agentId before its binary
     * is replaced.
     *
     *   1. Ctrl-C x 3 per session (200ms apart) — covers the Claude Code
     *      case where the agent is mid-operation: #1 interrupts the current
     *      tool call, #2 triggers the "press again to exit" confirmation,
     *      #3 actually exits. Quiescent agents absorb the extra presses
     *      harmlessly on the post-exit shell prompt. See
     *      memory/reference_agent_exit_patterns.md.
     *   2. Wait 1.5s for exit screens + resume-id persistence.
     *   3. Touch the close sentinel so aicli-shell.sh's relaunch loop exits.
     *   4. Capture each session's pane PIDs before destroying the session.
     *   5. tmux kill-session (SIGHUP-based; sufficient for Node agents that
     *      honour SIGHUP - opencode, gemini, kilocode, etc.).
     *   6. Post-kill verify: Claude Code and some other Node tools catch
     *      SIGHUP and keep running orphaned after their pty closes. For
     *      every captured PID that is still alive, escalate SIGTERM then
     *      500ms wait then SIGKILL. Strictly scoped to the PIDs we captured
     *      from tmux list-panes: no broad pgrep patterns (see
     *      memory/feedback_kill_patterns_vm_safety.md - a loose agent-name
     *      regex killed a VM whose cmdline happened to contain the word).
     */
    private static function _closeSessionsForUpgrade(string $agentId): array
    {
        $sessions = \AICliAgents\Services\TerminalService::listActiveSessionsForAgent($agentId);
        if (empty($sessions)) return [];

        aicli_log("Upgrade: graceful-closing " . count($sessions) . " session(s) for $agentId before install", AICLI_LOG_INFO);

        @mkdir('/tmp/unraid-aicliagents', 0755, true);

        // Quiesce each agent + capture its resume id via the SHARED pipeline
        // (TerminalHandler::captureResumeForClose) so the post-upgrade auto-relaunch
        // (AutoLaunchService::launchAllPending -> getResumeId -> chatId='auto')
        // resumes the same conversation for EVERY agent type. This runs the exact
        // exit-key + exit-screen-scrape + disk-fallback + saveResumeId sequence the
        // UI close uses. Previously this path sent a bare Ctrl-C x3 (no Ctrl-D, so
        // agy never quiesced) and saved NOTHING — aicli-shell.sh's own post-exit
        // GUID-sync is short-circuited by the close sentinel below, so resume was
        // lost on every upgrade. captureResumeForClose runs its own per-session
        // quiesce wait, so no separate Ctrl-C loop / shared sleep is needed here.
        require_once __DIR__ . '/TerminalHandler.php';
        foreach ($sessions as $s) {
            $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '', $s['id']);
            // Non-root audit: shared multi-user lookup (quiesce + capture pass).
            [$sessName, $tmuxSock, $tmuxBin] = \AICliAgents\Services\ProcessManager::findTmuxSessionForId($safeId);
            if ($sessName === '') continue;
            TerminalHandler::captureResumeForClose($sessName, $tmuxSock, $tmuxBin, $agentId, (string)($s['path'] ?? ''), "upgrade id=$safeId");
        }

        // Touch close sentinels so aicli-shell.sh exits its relaunch loop
        // instead of respawning against the half-upgraded binary.
        foreach ($sessions as $s) {
            @touch('/tmp/unraid-aicliagents/close-' . $s['id'] . '.flag');
        }
        usleep(300000);

        // Capture pane PIDs + their direct children per session, then
        // kill-session. The pane PID is the shell (aicli-shell.sh); its
        // child is the agent binary (claude.exe, opencode, etc.).
        $survivorPids = [];
        foreach ($sessions as $s) {
            $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '', $s['id']);
            // Non-root audit: shared multi-user lookup (capture-PIDs pass).
            [$sessName, $tmuxSock, $tmuxBin] = \AICliAgents\Services\ProcessManager::findTmuxSessionForId($safeId);
            if ($sessName === '') continue;
            $escSess = escapeshellarg($sessName);

            $paneOut = (string) shell_exec("$tmuxBin list-panes -t $escSess -F '#{pane_pid}' 2>/dev/null");
            foreach (explode("\n", trim($paneOut)) as $panePidStr) {
                $panePid = (int) $panePidStr;
                if ($panePid <= 1) continue;
                $survivorPids[] = $panePid;
                $children = (string) shell_exec("pgrep -P " . escapeshellarg((string)$panePid) . " 2>/dev/null");
                foreach (explode("\n", trim($children)) as $childPidStr) {
                    $childPid = (int) $childPidStr;
                    if ($childPid > 1) $survivorPids[] = $childPid;
                }
            }

            @shell_exec("$tmuxBin kill-session -t $escSess 2>/dev/null");
        }

        // Escalate on any captured PID still alive after kill-session.
        // Claude Code is the known offender - its claude.exe catches SIGHUP
        // and continues running orphaned after its pty closes.
        $survivorPids = array_unique($survivorPids);
        if (!empty($survivorPids)) {
            usleep(300000); // SIGHUP takes a moment on well-behaved agents.
            foreach ($survivorPids as $pid) {
                if ($pid <= 1) continue;
                $probe = (string) shell_exec("kill -0 " . escapeshellarg((string)$pid) . " 2>&1; echo _$?");
                if (strpos($probe, '_0') !== false) {
                    aicli_log("Upgrade: PID $pid survived kill-session for $agentId - sending SIGTERM", AICLI_LOG_WARN);
                    @shell_exec("kill -TERM " . escapeshellarg((string)$pid) . " 2>/dev/null");
                }
            }
            usleep(500000);
            foreach ($survivorPids as $pid) {
                if ($pid <= 1) continue;
                $probe = (string) shell_exec("kill -0 " . escapeshellarg((string)$pid) . " 2>&1; echo _$?");
                if (strpos($probe, '_0') !== false) {
                    aicli_log("Upgrade: PID $pid survived SIGTERM for $agentId - sending SIGKILL", AICLI_LOG_WARN);
                    @shell_exec("kill -KILL " . escapeshellarg((string)$pid) . " 2>/dev/null");
                }
            }
        }

        // #158/#159: reap by ENVIRONMENT, not just the pane tree. On Tower an
        // OpenCode agent that exits rc=1 in <3s crash-loops; a child can detach
        // from the pane and reparent to PID 1 (its argv is a bare binary path, so
        // pgrep -f AICLI_SESSION_ID= never matches it). The pane-scoped kill above
        // misses it, it keeps the agent binary mounted, and the post-install
        // remount then wedges at 99% while the loop re-pins the stale layer — the
        // operator sees "it force-closed but they immediately started again". The
        // normal drawer close already reaps these via terminateSessionDescendants;
        // the upgrade close did not. Mirror it here (env-scoped => safe, no broad
        // name/path pkill).
        $reaped = self::reapUpgradeSurvivors(array_map(
            static fn($s): string => (string)($s['id'] ?? ''), $sessions
        ));
        if (!empty($reaped)) {
            aicli_log("Upgrade: reaped " . count($reaped) . " env-scoped survivor(s) for $agentId after pane kill", AICLI_LOG_INFO);
        }

        // R2: record the EXACT closed set so the post-install relaunch brings
        // back precisely these sessions (resumed), independent of the
        // per-workspace autoLaunch flag. Source of truth for relaunchClosedSet().
        require_once __DIR__ . '/../services/UpgradeRelaunchService.php';
        $closedSet = [];
        $cfgUser = (getAICliConfig()['user'] ?? 'root') ?: 'root';
        foreach ($sessions as $s) {
            $wp = (string)($s['path'] ?? '');
            $closedSet[] = [
                'sessionId'     => (string)($s['id'] ?? ''),
                'workspacePath' => $wp,
                'user'          => $cfgUser,
                'hadResume'     => $wp !== '' && \AICliAgents\Services\ConfigService::getResumeId($wp, $agentId, (string)($s['id'] ?? '')) !== null,
            ];
        }
        if (\AICliAgents\Services\UpgradeRelaunchService::writeManifest($agentId, $closedSet) === false) {
            aicli_log("Upgrade: failed to write relaunch manifest for $agentId — sessions will not auto-relaunch", AICLI_LOG_WARN);
        }

        return $sessions;
    }

    /**
     * #158: reap every closed session's env-scoped descendants after the pane
     * kill. Loops the reaper (default: the environ-based
     * ProcessManager::terminateSessionDescendants, already used by the normal
     * drawer close) once per session and returns the unique pids signalled.
     * Injectable reaper => unit-testable without live processes.
     *
     * @param array<int,string> $sessionIds
     * @param (callable(string):array<int,int>)|null $reaper
     * @return array<int,int> unique pids reaped, in first-seen order
     */
    public static function reapUpgradeSurvivors(array $sessionIds, ?callable $reaper = null): array
    {
        $reaper = $reaper ?? static function (string $sid): array {
            return \AICliAgents\Services\ProcessManager::terminateSessionDescendants($sid);
        };
        $seen = [];
        foreach ($sessionIds as $sid) {
            $sid = (string)$sid;
            if ($sid === '') continue;
            foreach ((array)$reaper($sid) as $pid) {
                $pid = (int)$pid;
                if ($pid > 1) $seen[$pid] = true;
            }
        }
        return array_map('intval', array_keys($seen));
    }

    /**
     * #158: PURE — of the processes running $binaryPath, return the ones whose
     * session id is NOT among the sessions we are closing for this upgrade. These
     * are EXTERNAL holders (e.g. a Claude workspace running `opencode` as a tool);
     * they pin the agent binary but must NOT be force-killed. The upgrade defers
     * with an actionable message when any exist, rather than swapping under a live
     * process or wedging.
     *
     * @param array<int,array{pid:int,sid:string,cmd:string}> $procs
     * @param string $binaryPath exact agent binary path (full path => safe match)
     * @param array<int,string> $ourSessionIds sessions being closed for this upgrade
     * @return array<int,array{pid:int,sid:string}>
     */
    public static function classifyExternalBinaryHolders(array $procs, string $binaryPath, array $ourSessionIds, string $fallbackPath = ''): array
    {
        if ($binaryPath === '') return [];
        $ours = array_fill_keys(array_map('strval', $ourSessionIds), true);
        $accept = [$binaryPath => true];
        if ($fallbackPath !== '') $accept[$fallbackPath] = true;
        $ext = [];
        foreach ($procs as $p) {
            // #164: a holder must be EXECUTING the binary, not merely mention its
            // path. The binary is argv[0] (direct exec, e.g. `opencode.exe -s …`)
            // or argv[1] (interpreter form, e.g. `node <cli.js>`). Matching the
            // path ANYWHERE in the command line wrongly counted the session's own
            // ttyd bridge — which carries `env BINARY=<path>` as an argument for
            // the launched agent, but does not run the binary and has no
            // AICLI_SESSION_ID in its own environ (sid ''), so it was flagged as
            // an external holder and blocked a legitimate force-upgrade.
            $argv = (isset($p['argv']) && is_array($p['argv']))
                ? array_values(array_map('strval', $p['argv']))
                : (preg_split('/\s+/', trim((string)($p['cmd'] ?? ''))) ?: []);
            $isHolder = (isset($argv[0]) && isset($accept[$argv[0]]))
                     || (isset($argv[1]) && isset($accept[$argv[1]]));
            if (!$isHolder) continue;                             // not a holder of THIS binary
            $sid = (string)($p['sid'] ?? '');
            if ($sid !== '' && isset($ours[$sid])) continue;      // one of our closing sessions
            $ext[] = [
                'pid'   => (int)($p['pid'] ?? 0),
                'sid'   => $sid,
                // #87: carry the descriptive facts through so the UI can name the blocker.
                'cmd'   => (string)($p['cmd'] ?? ''),
                'cwd'   => (string)($p['cwd'] ?? ''),
                'age_s' => (int)($p['age_s'] ?? 0),
                'tmux'  => (string)($p['tmux'] ?? ''),
            ];
        }
        return $ext;
    }

    /**
     * #158: live wrapper — scan /proc for processes running $agentId's binary and
     * classify external holders (session id not in $closedSids). Fail-open: an
     * empty binary path or a scan miss returns [] (never blocks an upgrade on a
     * detection failure).
     *
     * @param array<int,string> $closedSids
     * @return array<int,array{pid:int,sid:string}>
     */
    public static function externalBinaryHolders(string $agentId, array $closedSids): array
    {
        $agents = \AICliAgents\Services\AgentRegistry::getDefaultAgents();
        $bin = (string)($agents[$agentId]['binary'] ?? '');
        $fallback = (string)($agents[$agentId]['binary_fallback'] ?? '');
        if ($bin === '') return [];
        $procs = [];
        foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $file) {
            $pid = (int)basename(dirname($file));
            if ($pid <= 1) continue;
            $raw = @file_get_contents($file);        // NUL-delimited argv
            if ($raw === false || $raw === '') continue;
            $argv = array_values(array_filter(explode("\0", $raw), static fn($t): bool => $t !== ''));
            // #164: only a process EXECUTING the binary (argv[0] or the argv[1]
            // interpreter form) is a candidate holder — a mere mention of the path
            // elsewhere (ttyd's `BINARY=<path>` env-arg, a shell -c script) is not.
            // Gate here so we read environ ONLY for real candidates.
            $isCandidate = (isset($argv[0]) && ($argv[0] === $bin || ($fallback !== '' && $argv[0] === $fallback)))
                        || (isset($argv[1]) && ($argv[1] === $bin || ($fallback !== '' && $argv[1] === $fallback)));
            if (!$isCandidate) continue;
            $env = (string)@file_get_contents("/proc/$pid/environ");
            $sid = '';
            foreach (explode("\0", $env) as $kv) {
                if (strpos($kv, 'AICLI_SESSION_ID=') === 0) { $sid = substr($kv, 17); break; }
            }
            $procs[] = array_merge(
                ['pid' => $pid, 'sid' => $sid, 'cmd' => str_replace("\0", ' ', $raw), 'argv' => $argv],
                self::describeProcess($pid, $env)
            );
        }
        return self::classifyExternalBinaryHolders($procs, $bin, $closedSids, $fallback);
    }

    /**
     * #87: the concrete facts an operator needs to find and close a blocking
     * process — where it runs (cwd), how long it has run (age), and which tmux
     * session hosts it, if any — read from /proc without touching the process.
     * Best-effort: every field degrades to '' / 0 when unreadable.
     *
     * @return array{cwd:string,age_s:int,tmux:string}
     */
    public static function describeProcess(int $pid, string $environ = ''): array
    {
        $cwd = (string)(@readlink("/proc/$pid/cwd") ?: '');
        $started = @filectime("/proc/$pid");
        $age = $started ? max(0, time() - (int)$started) : 0;
        $tmux = '';
        foreach (explode("\0", $environ) as $kv) {
            // TMUX=<socket>,<server pid>,<session index>; the socket basename is
            // the plugin's per-session dir or a user's own server ("default").
            if (strpos($kv, 'TMUX=') === 0) {
                $parts = explode(',', substr($kv, 5));
                $tmux = basename(dirname((string)($parts[0] ?? ''))) . '/' . basename((string)($parts[0] ?? ''));
                break;
            }
        }
        return ['cwd' => $cwd, 'age_s' => $age, 'tmux' => $tmux];
    }

    /**
     * #87: one human-readable line per blocking process, so the UI can show WHO
     * is holding the binary instead of a bare count. Pure over the holder rows.
     *
     * @param array<int,array{pid:int,sid?:string,cmd?:string,cwd?:string,age_s?:int,tmux?:string}> $holders
     */
    public static function describeHolders(array $holders, int $max = 5): string
    {
        $lines = [];
        foreach (array_slice($holders, 0, $max) as $h) {
            $cmd = trim((string)($h['cmd'] ?? ''));
            if (strlen($cmd) > 80) $cmd = substr($cmd, 0, 77) . '…';
            $age = (int)($h['age_s'] ?? 0);
            $ageText = $age >= 3600 ? sprintf('%dh %dm', intdiv($age, 3600), intdiv($age % 3600, 60)) : sprintf('%dm', intdiv($age, 60));
            $bits = ["pid " . (int)($h['pid'] ?? 0)];
            if ($cmd !== '') $bits[] = $cmd;
            $sid = (string)($h['sid'] ?? '');
            $bits[] = $sid !== '' ? "workspace $sid" : 'NOT a plugin workspace (external)';
            if (($h['cwd'] ?? '') !== '') $bits[] = 'cwd ' . $h['cwd'];
            if (($h['tmux'] ?? '') !== '') $bits[] = 'tmux ' . $h['tmux'];
            $bits[] = 'running ' . $ageText;
            $lines[] = implode(' · ', $bits);
        }
        if (count($holders) > $max) $lines[] = '… and ' . (count($holders) - $max) . ' more';
        return implode("\n", $lines);
    }

    /**
     * Raw output for install status (file is already JSON).
     * Called directly by dispatcher (not through handle()).
     */
    public static function rawInstallStatus() {
        $agentId = $_GET['agentId'] ?? '';
        // SECURITY: agentId goes straight into a file path, so restrict to the
        // character class registry entries actually use (lowercase, digits,
        // hyphens). Blocks "../" traversal, null bytes, and any shell meta
        // that could weaponise the subsequent echo. Semgrep flagged this as
        // an echoed-request XSS/LFI candidate and it was legitimate.
        if ($agentId !== '' && !preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/i', $agentId)) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'invalid agentId']);
            return;
        }
        $file = empty($agentId)
            ? "/tmp/unraid-aicliagents/install-status"
            : "/tmp/unraid-aicliagents/install-status-{$agentId}";
        header('Content-Type: application/json');
        // $agentId is restricted to [a-z0-9-] above, so no path-traversal surface
        // remains. File content is author-written JSON.
        if (file_exists($file)) {
            $raw = (string)file_get_contents($file);
            // #87: while an installed upgrade waits for running processes before it
            // can activate (99%), enumerate the blockers on EVERY poll — pid,
            // command, cwd, tmux session, age, and whether each is a plugin
            // workspace or an external process — so the wait is explained and
            // refreshable instead of an indefinite unexplained 99%.
            $status = $agentId !== '' ? json_decode($raw, true) : null;
            if (is_array($status) && (string)($status['phase'] ?? '') === 'awaiting_activation') {
                $holders = self::externalBinaryHolders($agentId, []);
                $status['holders'] = $holders;
                $status['blockers'] = $holders === []
                    ? 'No process is running the old version now; activation runs on the next supervisor pass.'
                    : "Waiting for " . count($holders) . " process(es) still running the old version:\n" . self::describeHolders($holders);
                $raw = (string)json_encode($status);
            }
            echo $raw; // nosemgrep: php.lang.security.injection.echoed-request.echoed-request
        } else {
            echo json_encode(['status' => 'pending', 'progress' => -1]);
        }
    }

    private static function emergencyInstall() {
        $agentId = $_GET['agentId'] ?? '';
        if (empty($agentId)) {
            return ['status' => 'error', 'message' => 'No Agent ID provided'];
        }

        // Check if already installed (binary exists in RAM)
        $registry = \AICliAgents\Services\AgentRegistry::getRegistry();
        $agent = $registry[$agentId] ?? null;
        if ($agent && !empty($agent['binary']) && file_exists($agent['binary'])) {
            return ['status' => 'ok', 'message' => 'Agent already available'];
        }

        \AICliAgents\Services\UtilityService::clearInstallStatus($agentId);
        setInstallStatus("Starting emergency install...", 5, $agentId);
        // #176: dissociate the detached install job from any session (see install()).
        aicli_exec_bg("env -u AICLI_SESSION_ID /usr/bin/php /usr/local/emhttp/plugins/unraid-aicliagents/scripts/emergency-install-bg.php " . escapeshellarg($agentId));
        return ['status' => 'ok', 'message' => 'Emergency installation started'];
    }

    /**
     * Force a fresh version check for all agents.
     */
    private static function checkVersions() {
        set_time_limit(180);
        if (\AICliAgents\Services\VersionCheckService::isCheckRunning()) {
            return ['status' => 'ok', 'message' => 'Check already in progress', 'cache' => \AICliAgents\Services\VersionCheckService::getCachedResults()];
        }
        $cache = \AICliAgents\Services\VersionCheckService::checkAllAgents(true);
        return ['status' => 'ok', 'cache' => $cache];
    }

    /**
     * Get cached version data (triggers background check if stale).
     */
    private static function getVersionCache() {
        $config = getAICliConfig();
        $months = (int)($config['version_check_months'] ?? 3);
        $cache = \AICliAgents\Services\VersionCheckService::getCachedResults();
        $checking = \AICliAgents\Services\VersionCheckService::isCheckRunning();

        // Trigger background check if cache is empty, globally stale, or any agent is individually stale
        $registry = \AICliAgents\Services\AgentRegistry::getRegistry();
        $needsCheck = !$cache || !\AICliAgents\Services\VersionCheckService::isCacheFresh(3600);
        if (!$needsCheck) {
            // Check for individually stale agents (e.g., after install/downgrade invalidation).
            // Covers both npm agents (dist-tag cache) and non-NPM agents that implement
            // populateCache (e.g. CurlInstallSource with a manifest_url). Without this,
            // invalidateAgent() for antigravity-cli sets checked_at=0 but the store page
            // never kicks off a refresh until npm agents also expire (up to 1 hour later).
            foreach ($registry as $id => $agent) {
                if ($id === 'terminal') continue;
                $isNpm = !empty($agent['npm_package']);
                if (!$isNpm) {
                    $source = \AICliAgents\Services\Sources\SourceResolver::resolve($agent);
                    if ($source === null || !method_exists($source, 'populateCache')) continue;
                }
                $agentEntry = $cache[$id] ?? null;
                if (!$agentEntry || ($agentEntry['checked_at'] ?? 0) === 0) {
                    $needsCheck = true;
                    break;
                }
            }
        }
        if ($needsCheck && !$checking) {
            aicli_exec_bg("/usr/bin/php /usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/version-check-bg.php");
            $checking = true;
        }

        // Build per-agent dropdown data. NPM agents use the dist-tag cache path (multi-version
        // dropdown). Non-NPM agents (github_release/curl_install/tarball) get a minimal entry
        // with only the installed version, since per-refresh GitHub API polling would blow the
        // unauthenticated rate limit. Update-available detection for those agents happens on
        // the explicit "Check updates" path via AgentRegistry::checkUpdates().
        $dropdowns = [];
        foreach ($registry as $id => $agent) {
            if ($id === 'terminal') continue;

            if (!empty($agent['npm_package'])) {
                $channel = \AICliAgents\Services\AgentRegistry::getChannel($id);
                $dropdowns[$id] = [
                    'versions' => \AICliAgents\Services\VersionCheckService::getAvailableVersions($id, $channel, $months),
                    'update' => \AICliAgents\Services\VersionCheckService::hasUpdate($id),
                    'installed' => \AICliAgents\Services\AgentRegistry::getInstalledVersion($id),
                    'channel' => $channel,
                    'pinned' => \AICliAgents\Services\AgentRegistry::getPinned($id),
                    'checked_at' => $cache[$id]['checked_at'] ?? null,
                    'check_error' => $cache[$id]['check_error'] ?? null,
                ];
                continue;
            }

            // Non-NPM agent — only emit a dropdown entry if the source resolver can handle it.
            if (\AICliAgents\Services\Sources\SourceResolver::resolve($agent) === null) continue;

            $installed = \AICliAgents\Services\AgentRegistry::getInstalledVersion($id);
            $channel   = \AICliAgents\Services\AgentRegistry::getChannel($id);

            // Prefer the populated cache (e.g. GithubReleaseSource::populateCache).
            // Fall back to installed-only when no cache entry exists yet (first run
            // before checkAllAgents has populated it, or sources without populateCache).
            $versions = \AICliAgents\Services\VersionCheckService::getAvailableVersions($id, $channel, $months);
            if (empty($versions)) {
                $versions = [];
                if ($installed && $installed !== '0.0.0' && $installed !== 'unknown') {
                    $versions[] = ['version' => $installed, 'tags' => ['installed'], 'timestamp' => 0, 'date' => null];
                }
            }
            $dropdowns[$id] = [
                'versions'   => $versions,
                'update'     => \AICliAgents\Services\VersionCheckService::hasUpdate($id),
                'installed'  => $installed,
                'channel'    => $channel,
                'pinned'     => \AICliAgents\Services\AgentRegistry::getPinned($id),
                'checked_at' => $cache[$id]['checked_at'] ?? time(),
                'check_error'=> $cache[$id]['check_error'] ?? null,
            ];
        }

        return ['status' => 'ok', 'dropdowns' => $dropdowns, 'checking' => $checking];
    }

    /**
     * Set the selected channel/pin for an agent.
     */
    private static function setAgentChannel() {
        $agentId = $_GET['agentId'] ?? '';
        $channel = strtolower(trim((string)($_GET['channel'] ?? 'stable')));
        $pinned = $_GET['pinned'] ?? null;
        if ($pinned === '') $pinned = null;

        if (empty($agentId)) return ['status' => 'error', 'message' => 'No Agent ID'];
        if (!in_array($channel, ['stable', 'latest', 'beta', 'pinned'], true)) {
            return ['status' => 'error', 'message' => 'Unsupported release channel'];
        }
        $channel = \AICliAgents\Services\AgentRegistry::normalizeChannel($channel);
        if ($channel === 'pinned' && $pinned === null) {
            $installed = \AICliAgents\Services\AgentRegistry::getInstalledVersion($agentId);
            if (in_array($installed, ['', '0.0.0', 'unknown', 'installed'], true)) {
                return ['status' => 'error', 'message' => 'Choose an installed version before pinning'];
            }
            $pinned = $installed;
        }

        \AICliAgents\Services\AgentRegistry::setChannel($agentId, $channel, $pinned);
        // Clear old notification for this agent since channel changed
        \AICliAgents\Services\VersionCheckService::clearNotification($agentId);
        \AICliAgents\Services\LifecycleLogService::log(\AICliAgents\Services\LifecycleLogService::LEVEL_INFO, 'agent_registry', 'agent_channel_set', ['agent' => $agentId, 'channel' => $channel, 'pinned' => $pinned]);

        return ['status' => 'ok', 'channel' => $channel, 'pinned' => $pinned];
    }

    private static function uninstall() {
        return uninstallAgent($_GET['agentId'] ?? '');
    }

    private static function checkUpdates() {
        return checkAgentUpdates();
    }
}
