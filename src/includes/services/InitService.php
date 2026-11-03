<?php
/**
 * <module_context>
 *     <name>InitService</name>
 *     <description>Plugin initialization logic for AICliAgents.</description>
 *     <dependencies>LogService, ConfigService, StorageMountService</dependencies>
 *     <constraints>Under 150 lines. Focuses on scaffolding and state initialization.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

// InitService uses BootIntegrityService. The class loader (includes/Autoload.php, registered by
// AICliAgentsManager.php) loads it when it is first used. No require_once and no load order needed.

class InitService {
    private static $initializing = false;
    private static $initialized = false;

    /**
     * Test seams (#663). Production keeps this array empty, so every default below is the
     * production value. Keys: runDir, varIni, emergencyFlag, emergencyHome, agentBase (paths);
     * migrationInProgress, nginx, bootSweep, migrateAutoLaunch, supervisor, bootLaunch,
     * killSessions, publish (callables that replace the real call).
     * @var array<string,mixed>
     */
    private static $seams = [];

    /**
     * Test seam: install seams and clear the once-per-process flags. Tests only.
     *
     * @param array<string,mixed> $seams
     */
    public static function resetForTests(array $seams = []): void {
        self::$seams = $seams;
        self::$initialized = false;
        self::$initializing = false;
    }

    /** The run folder that holds the pid and socket files of the sessions. */
    private static function runDir(): string {
        return rtrim((string)(self::$seams['runDir'] ?? '/var/run'), '/');
    }

    /**
     * Initializes the plugin state and environment.
     */
    public static function initPlugin() {
        if (self::$initialized || self::$initializing) return;
        self::$initializing = true;

        // Force system/user timezone if available, otherwise fallback to UTC
        $varIni = (string)(self::$seams['varIni'] ?? '/var/local/emhttp/var.ini');
        if (file_exists($varIni)) {
            $var = @parse_ini_file($varIni);
            if (!empty($var['timeZone'])) {
                @date_default_timezone_set($var['timeZone']);
            } else {
                date_default_timezone_set('UTC');
            }
        } else {
            date_default_timezone_set('UTC');
        }

        $logDir = SecretPaths::stateRoot();
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
            @chmod($logDir, 0755);
        }
        $debugLog = "$logDir/debug.log";
        if (!file_exists($debugLog)) {
            @touch($debugLog);
            @chmod($debugLog, 0666);
        }

        // D-300: Atomic marker to ensure initialization is logged once per session (RAM disk)
        $initMarker = "$logDir/.init_done";
        $fp = @fopen($initMarker, "c+");
        if ($fp && flock($fp, LOCK_EX)) {
            $content = fread($fp, 10);
            if (empty($content)) {
                LogService::log("Initializing AICliAgents Plugin (v" . ConfigService::getVersion() . ")...", LogService::LOG_INFO);
                fwrite($fp, "INIT_DONE");
                fflush($fp);
            } else {
                LogService::log("Initializing AICliAgents Plugin...", LogService::LOG_DEBUG);
            }
            flock($fp, LOCK_UN);
            fclose($fp);
        }
        
        $config = ConfigService::getConfig();
        $directories = [
            SecretPaths::flashRoot(),
            SecretPaths::flashRoot() . '/persistence',
            SecretPaths::flashRoot() . '/envs',
            SecretPaths::flashRoot() . '/pkg-cache',
            SecretPaths::stateRoot(),
            rtrim(SecretPaths::stateRoot(), '/') . '/work',
            rtrim(SecretPaths::stateRoot(), '/') . '/caches',
            self::runDir() . "/aicli-sessions"
        ];

        foreach ($directories as $dir) {
            if (!is_dir($dir)) {
                if (@mkdir($dir, 0755, true) !== false) {
                    @chmod($dir, 0755);
                }
            }
        }

        // 1. Ensure Nginx configuration is updated and reloaded if necessary
        if (isset(self::$seams['nginx'])) { (self::$seams['nginx'])(); } else { ConfigService::ensureNginxConfig(); }

        // Phase 1: Ensure the durable layer manifest exists from first boot onwards (idempotent)
        LayerManifestService::initEmpty();

        // Phase 4a: Boot integrity sweep -- warn mode. Classifies every entity against the
        // manifest, emits lifecycle log lines, and fires Unraid notifications for non-healthy
        // states. Does NOT block mounts. Phase 4b adds halt-and-ask via boot_integrity_strict cfg.
        // Guard: only run once per boot (cache file acts as sentinel).
        if (!file_exists(SecretPaths::stateRoot() . '/.boot_integrity_cache.json')) {
            if (isset(self::$seams['bootSweep'])) { (self::$seams['bootSweep'])(); } else { BootIntegrityService::runBootSweep(); }
        }

        // R-C1 (CLAUDE_RELAUNCH_SURVIVAL): one-time migration of per-(path,agent)
        // auto-launch flags up to the new agent-level map. Idempotent — guarded by
        // a persistent marker on the HOME state dir; cheap stat on the steady state.
        try {
            if (isset(self::$seams['migrateAutoLaunch'])) { (self::$seams['migrateAutoLaunch'])(); } else { ConfigService::migrateAutoLaunchToAgentLevel(); }
        } catch (\Throwable $e) {
            LogService::log("autolaunch agent-level migration error: " . $e->getMessage(), LogService::LOG_WARN, "InitService");
        }

        // 2. Cleanup stale sessions and temporary artifacts
        self::cleanupStaleState();
        
        self::$initialized = true;
        self::$initializing = false;
        LogService::log("Plugin initialization complete.", LogService::LOG_DEBUG);
    }

    /**
     * Ensures the plugin is fully initialized for the current request.
     */
    public static function ensureInit($skipMount = false) {
        self::initPlugin();

        $migrating = isset(self::$seams['migrationInProgress'])
            ? (bool)(self::$seams['migrationInProgress'])()
            : StorageMountService::isMigrationInProgress();
        if ($migrating) {
            // D-301: Log that we are in a waiting state
            LogService::log("Request deferred: Storage migration is currently in progress.", LogService::LOG_DEBUG);
            return;
        }

        // Phase 3 + OP#1381: Lazy backstop / self-heal — restore exactly one
        // healthy supervisor on the next page load. ensureHealthy() covers the
        // plain dead-supervisor case (start one) AND the wedged states a bare
        // isRunning() check misses: >1 instance, an empty/stale pidfile while a
        // wedged proc still holds the flock, or a supervisor that stopped ticking.
        // It is cheap (one /proc scan + a stat) and never disturbs a healthy
        // supervisor — including one mid-bake, which keeps ticking via its
        // separate heartbeat process, so a fresh tick reads as healthy.
        if (isset(self::$seams['supervisor'])) { (self::$seams['supervisor'])(); } else { SupervisorService::ensureHealthy(); }

        // Bug #521: one-shot per-boot autolaunch sweep. The disks_mounted event hook
        // and the PLG INLINE block are the primary triggers, but a fresh boot or a
        // crash-restart that skipped both leaves sessions dead until the user opens
        // the tab. The marker is on tmpfs, so it self-clears across reboots; on the
        // first ensureInit after boot we run the sweep and write the marker.
        $bootMarker = SecretPaths::stateRoot() . '/.autolaunch_boot_done';
        if (!file_exists($bootMarker)) {
            @touch($bootMarker);
            // Run after the marker write so a partial failure doesn't loop. The
            // service is idempotent — already-running sessions are filtered.
            try {
                if (isset(self::$seams['bootLaunch'])) {
                    (self::$seams['bootLaunch'])();
                } else {
                    self::runBootLaunchSweep();
                }
            } catch (\Throwable $e) {
                LogService::log("AutoLaunch boot sweep failed: " . $e->getMessage(), LogService::LOG_WARN);
            }
        }
    }

    /**
     * The real once-per-boot sweep: register the MCP servers, project the Config Hub,
     * then launch the pending sessions. The order matters (see the notes inside).
     */
    private static function runBootLaunchSweep(): void {
        // Relay is always-on by default, but the default is inert until
        // the server is actually registered in the Config Hub. Do that
        // first, then project, and only then launch — workspaces read
        // their MCP server list once at startup, so a projection running
        // after the sweep would not reach this boot's sessions at all.
        AgentRelayService::ensureMcpRegistered();
        // Same ordering rule for the plugin-management server: a workspace
        // reads its MCP server list once at startup. This is a no-op unless
        // the stored registration disagrees with admin_tools_enabled, so the
        // common case (feature off, nothing registered) costs one array read.
        \AICliAgents\Services\AdminMcpTools::ensureMcpRegistered();
        // #137: reboot closed local workspaces without a UI close — sweep
        // their orphaned Relay subscriptions so they stop ghosting contacts.
        AgentRelayService::sweepOrphanedSubscriptions();
        \AICliAgents\Services\Hub\HubProjector::projectAll();
        AutoLaunchService::launchAllPending(null, 'init_boot');
        AgentRelayService::ensureBootActors();
        // #113: the Relay HTTP listener is a plugin-owned process, so
        // nothing else restores it after a boot or a plugin restart.
        AgentRelayService::ensureHttpListener();
    }

    /**
     * Kills the emergency terminal sessions (tmux and ttyd). Used once storage is back.
     */
    private static function killEmergencySessions(): void {
        if (isset(self::$seams['killSessions'])) {
            (self::$seams['killSessions'])();
            return;
        }
        \AICliAgents\Services\ProcessManager::killAllAgentSessions();
        exec("pkill -9 -f 'ttyd.*(aicliterm|temp-terminal)-' 2>/dev/null");
        exec("pkill -9 -f 'aicli-run-' 2>/dev/null");
        usleep(500000); // 0.5s for process cleanup
    }

    /**
     * Cleans up stale PID files, sockets, and temporary scripts.
     */
    private static function cleanupStaleState() {
        $runDir = self::runDir();
        $emergencyFlag = (string)(self::$seams['emergencyFlag'] ?? StorageMountService::emergencyFlag());
        $emergencyHome = (string)(self::$seams['emergencyHome'] ?? StorageMountService::emergencyHome());

        // Clean stale session artifacts if process is not running
        foreach (glob("$runDir/unraid-aicliagents-*.pid") as $pidFile) {
            if (file_exists($pidFile)) {
                $pid = (int)trim(file_get_contents($pidFile));
                if ($pid > 0 && !posix_kill($pid, 0)) {
                    @unlink($pidFile);
                    $id = str_replace(['unraid-aicliagents-', '.pid'], '', basename($pidFile));
                    @unlink("$runDir/aicliterm-$id.sock");
                }
            }
        }

        // Clean stale emergency mode state — only if HOME storage is back (not agent storage)
        if (file_exists($emergencyFlag)) {
            $config = ConfigService::getConfig();
            $homePath = StoragePathResolver::homePersistPathFromConfig($config);   // R-046: the one resolver
            if (StorageMountService::isPathAvailable($homePath)) {
                // Storage is back — kill emergency sessions, clean up artifacts, notify UI
                LogService::log("Emergency mode cleanup: storage now available. Killing emergency sessions...", LogService::LOG_INFO, "InitService");

                // Kill emergency terminal sessions (tmux + ttyd)
                self::killEmergencySessions();

                @unlink($emergencyFlag);
                if (is_dir($emergencyHome)) exec("rm -rf " . escapeshellarg($emergencyHome));
                // Remove emergency home symlinks so mount_stack.sh can mkdir
                foreach (glob(rtrim(SecretPaths::stateRoot(), '/') . '/work/*/home') as $link) {
                    if (is_link($link)) @unlink($link);
                }
                // Clean stale PID/socket files from killed sessions.
                // Bug #541: only unlink pid files whose recorded PID is dead.
                // Otherwise emergency cleanup can desync the registry from a
                // recycled-PID process that the OS reused for an unrelated
                // workload (we never SIGNAL the recycled PID — but the file
                // disappearing fools listActiveSessionsForAgent into thinking
                // no session exists when one actually does).
                foreach (glob("$runDir/unraid-aicliagents-*.pid") as $pidFile) {
                    $pid = (int)@file_get_contents($pidFile);
                    if ($pid > 0 && UtilityService::isPidRunning($pid)) {
                        // Process still alive — leave its pid file in place.
                        continue;
                    }
                    @unlink($pidFile);
                }
                // #682: a terminal socket belongs to its session. Keep the socket of a session whose
                // process is still alive, or the live terminal loses its socket.
                foreach (glob("$runDir/aicliterm-*.sock") as $sock) {
                    $sockId = substr(basename($sock), strlen('aicliterm-'), -strlen('.sock'));
                    $sessionPid = (int)@file_get_contents("$runDir/unraid-aicliagents-$sockId.pid");
                    if ($sessionPid > 0 && UtilityService::isPidRunning($sessionPid)) continue;
                    @unlink($sock);
                }

                // Remove emergency-installed agents from RAM (real sqsh agents will be mounted on demand)
                foreach (glob(SecretPaths::stateRoot() . '/.emergency_agent_*') as $flag) {
                    $eAgentId = str_replace(SecretPaths::stateRoot() . '/.emergency_agent_', '', $flag);
                    // SIDE_BY_SIDE_AGENT_INSTALLS.md Phase 1: agentBase(), not agentPath() —
                    // this runs unguarded on the boot init path (InitService::initPlugin,
                    // no surrounding try/catch), and $eAgentId comes from a flag-file
                    // name rather than a freshly-validated request param, so this call
                    // site keeps the non-throwing accessor to avoid a new failure mode
                    // on every page load if a stray flag file ever exists.
                    $eAgentDir = (string)(self::$seams['agentBase'] ?? AgentRegistry::agentBase()) . "/$eAgentId";
                    if (is_dir($eAgentDir) && !StorageMountService::isMounted($eAgentDir)) {
                        exec("rm -rf " . escapeshellarg($eAgentDir));
                        LogService::log("Removed emergency RAM agent: $eAgentId", LogService::LOG_INFO, "InitService");
                    }
                    @unlink($flag);
                }
                // Clear emergency agent versions so real sqsh versions take over
                foreach (glob(SecretPaths::stateRoot() . '/.emergency_agent_*') as $_) {} // already removed above
                @unlink(SecretPaths::stateRoot() . '/install-status'); // stale global status

                // Notify UI to reload via Nchan
                $statusEvent = [
                    'home_available' => true,
                    'agents_available' => true,
                    'emergency_mode' => false,
                ];
                if (isset(self::$seams['publish'])) { (self::$seams['publish'])('storage.status', [], $statusEvent); }
                else { EventBus::publish('storage.status', [], $statusEvent); }

                LogService::log("Emergency mode cleanup complete. UI notified.", LogService::LOG_INFO, "InitService");
            }
        }

        // Clean stale installation progress files
        // D-327: Only delete if the corresponding install-bg.php process is NOT running
        // AND the file is older than 5 minutes (Grace period for UI completion).
        $stateRoot = SecretPaths::stateRoot();
        $statusFiles = glob(rtrim($stateRoot, '/') . '/install-status-*');
        $now = time();
        foreach ($statusFiles as $file) {
            $agentId = str_replace(rtrim($stateRoot, '/') . '/install-status-', '', $file);
            $mtime = filemtime($file);
            
            // Search for active background installer for this specific agent
            $running = PendingAgentUpgradeService::backgroundInstallRunning($agentId);

            if (!$running && ($now - $mtime) > 300) {
                @unlink($file);
            }
        }
    }

    /**
     * Boot-time state restoration (Resurrection).
     */
    public static function bootResurrection() {
        self::initPlugin();
        LogService::log("Boot Resurrection: Checking for legacy images and persistent entities...", LogService::LOG_DEBUG);
        
        // With SquashFS, we don't need to mount everything at boot.
        // It will be mounted on-demand when a session starts.
        
        LogService::log("Boot Resurrection complete.", LogService::LOG_INFO);
    }
}
