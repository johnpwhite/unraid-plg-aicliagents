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
            default:                    return null;
            // Note: get_install_status outputs raw JSON and is dispatched directly
        }
    }

    /** Actions handled by this handler. */
    public static function actions() {
        return ['install_agent', 'emergency_install', 'get_install_status', 'uninstall_agent',
                'check_updates', 'check_versions', 'get_version_cache', 'set_agent_channel',
                'list_active_installs', 'get_upgrade_backup_estimate', 'restore_agent_backup'];
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
     */
    private static function listActiveInstalls() {
        $dir = '/tmp/unraid-aicliagents';
        $active = [];
        foreach (glob("$dir/install-status-*") ?: [] as $f) {
            $base = basename($f);
            if (!preg_match('/^install-status-([a-z0-9][a-z0-9-]{0,63})$/', $base, $m)) continue;
            $status = @json_decode((string)@file_get_contents($f), true);
            if (!is_array($status)) continue;
            $progress = (int)($status['progress'] ?? 0);
            if ($progress > 0 && $progress < 100) {
                $active[] = [
                    'agentId'  => $m[1],
                    'progress' => $progress,
                    'status'   => (string)($status['status_text'] ?? $status['status'] ?? ''),
                ];
            }
        }
        return ['status' => 'ok', 'active' => $active];
    }

    private static function install() {
        $agentId = $_GET['agentId'] ?? '';
        if (empty($agentId)) {
            return ['status' => 'error', 'message' => 'No Agent ID provided'];
        }

        // Check if an installation is already active for this specific agent
        $cmd = "timeout 2 ps aux | grep 'install-bg.php " . escapeshellarg($agentId) . "' | grep -v grep";
        exec($cmd, $out, $res);
        if ($res === 0) {
            return ['status' => 'error', 'message' => 'An installation is already in progress for this agent.'];
        }

        // Phase 1: graceful-close any active workspace sessions using this
        // agent BEFORE the binary is replaced. Preserves each session's
        // resume id and list them for the UI.
        $preClosed = self::_closeSessionsForUpgrade($agentId);

        // Enqueue a home bake before the install so any dirty ZRAM is durable
        // before the binary replacement and potential remount. The supervisor
        // handles the bake asynchronously; the AJAX response does not block.
        $config = getAICliConfig();
        $user = $config['user'] ?? 'root';
        if (empty($user)) $user = 'root';
        \AICliAgents\Services\SupervisorService::enqueue('home', $user, 'bake', 'pre_agent_install', 5);

        $version = $_GET['version'] ?? '';

        \AICliAgents\Services\UtilityService::clearInstallStatus($agentId);
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
        $backupDest = (($_GET['backup'] ?? '') === '1') ? trim((string)($_GET['backup_dest'] ?? '')) : '';
        $versionArg = " " . escapeshellarg($version);
        $backupArg  = " " . escapeshellarg($backupDest);
        aicli_exec_bg("/usr/bin/php /usr/local/emhttp/plugins/unraid-aicliagents/scripts/install-bg.php " . escapeshellarg($agentId) . $versionArg . $backupArg);
        return ['status' => 'ok', 'message' => 'Installation started', 'pre_closed_sessions' => $preClosed];
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

        foreach ($sessions as $s) {
            $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '', $s['id']);
            // Non-root audit: shared multi-user lookup so we close sessions
            // owned by non-root configured users too.
            [$sessName, $tmuxSock, $tmuxBin] = \AICliAgents\Services\ProcessManager::findTmuxSessionForId($safeId);
            if ($sessName === '') continue;
            $escSess = escapeshellarg($sessName);
            @shell_exec("$tmuxBin resize-window -t $escSess -x 220 -y 50 2>/dev/null");
            @shell_exec("$tmuxBin send-keys -t $escSess C-c 2>/dev/null");
            usleep(200000);
            @shell_exec("$tmuxBin send-keys -t $escSess C-c 2>/dev/null");
            usleep(200000);
            @shell_exec("$tmuxBin send-keys -t $escSess C-c 2>/dev/null");
        }

        // Shared wait window - 1.5s for the agents' exit screens to render
        // and aicli-shell.sh to persist the resume id.
        usleep(1500000);

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

        return $sessions;
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
            echo file_get_contents($file); // nosemgrep: php.lang.security.injection.echoed-request.echoed-request
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
        aicli_exec_bg("/usr/bin/php /usr/local/emhttp/plugins/unraid-aicliagents/scripts/emergency-install-bg.php " . escapeshellarg($agentId));
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
        $channel = $_GET['channel'] ?? 'latest';
        $pinned = $_GET['pinned'] ?? null;
        if ($pinned === '') $pinned = null;

        if (empty($agentId)) return ['status' => 'error', 'message' => 'No Agent ID'];

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
