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

// InitService uses BootIntegrityService which is loaded via AICliAgentsManager before this runs.
// No separate require_once needed -- AICliAgentsManager.php handles the load order.

class InitService {
    private static $initializing = false;
    private static $initialized = false;

    /**
     * Initializes the plugin state and environment.
     */
    public static function initPlugin() {
        if (self::$initialized || self::$initializing) return;
        self::$initializing = true;

        // Force system/user timezone if available, otherwise fallback to UTC
        if (file_exists('/var/local/emhttp/var.ini')) {
            $var = @parse_ini_file('/var/local/emhttp/var.ini');
            if (!empty($var['timeZone'])) {
                @date_default_timezone_set($var['timeZone']);
            } else {
                date_default_timezone_set('UTC');
            }
        } else {
            date_default_timezone_set('UTC');
        }

        $logDir = "/tmp/unraid-aicliagents";
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
            "/boot/config/plugins/unraid-aicliagents",
            "/boot/config/plugins/unraid-aicliagents/persistence",
            "/boot/config/plugins/unraid-aicliagents/envs",
            "/boot/config/plugins/unraid-aicliagents/pkg-cache",
            "/tmp/unraid-aicliagents",
            "/tmp/unraid-aicliagents/work",
            "/tmp/unraid-aicliagents/caches",
            "/var/run/aicli-sessions"
        ];

        foreach ($directories as $dir) {
            if (!is_dir($dir)) {
                if (@mkdir($dir, 0755, true) !== false) {
                    @chmod($dir, 0755);
                }
            }
        }

        // 1. Ensure Nginx configuration is updated and reloaded if necessary
        ConfigService::ensureNginxConfig();

        // Phase 1: Ensure the durable layer manifest exists from first boot onwards (idempotent)
        LayerManifestService::initEmpty();

        // Phase 4a: Boot integrity sweep -- warn mode. Classifies every entity against the
        // manifest, emits lifecycle log lines, and fires Unraid notifications for non-healthy
        // states. Does NOT block mounts. Phase 4b adds halt-and-ask via boot_integrity_strict cfg.
        // Guard: only run once per boot (cache file acts as sentinel).
        if (!file_exists('/tmp/unraid-aicliagents/.boot_integrity_cache.json')) {
            BootIntegrityService::runBootSweep();
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

        if (StorageMountService::isMigrationInProgress()) {
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
        SupervisorService::ensureHealthy();

        // Bug #521: one-shot per-boot autolaunch sweep. The disks_mounted event hook
        // and the PLG INLINE block are the primary triggers, but a fresh boot or a
        // crash-restart that skipped both leaves sessions dead until the user opens
        // the tab. The marker is on tmpfs, so it self-clears across reboots; on the
        // first ensureInit after boot we run the sweep and write the marker.
        $bootMarker = '/tmp/unraid-aicliagents/.autolaunch_boot_done';
        if (!file_exists($bootMarker)) {
            @touch($bootMarker);
            // Run after the marker write so a partial failure doesn't loop. The
            // service is idempotent — already-running sessions are filtered.
            try {
                AutoLaunchService::launchAllPending(null, 'init_boot');
            } catch (\Throwable $e) {
                LogService::log("AutoLaunch boot sweep failed: " . $e->getMessage(), LogService::LOG_WARN);
            }
        }
    }

    /**
     * Cleans up stale PID files, sockets, and temporary scripts.
     */
    private static function cleanupStaleState() {
        $runDir = "/var/run";
        
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
        if (file_exists(StorageMountService::EMERGENCY_FLAG)) {
            $config = ConfigService::getConfig();
            $homePath = $config['home_storage_path'] ?? $config['agent_storage_path'] ?? '/boot/config/plugins/unraid-aicliagents';
            if (StorageMountService::isPathAvailable($homePath)) {
                // Storage is back — kill emergency sessions, clean up artifacts, notify UI
                LogService::log("Emergency mode cleanup: storage now available. Killing emergency sessions...", LogService::LOG_INFO, "InitService");

                // Kill emergency terminal sessions (tmux + ttyd)
                \AICliAgents\Services\ProcessManager::killAllAgentSessions();
                exec("pkill -9 -f 'ttyd.*(aicliterm|temp-terminal)-' 2>/dev/null");
                exec("pkill -9 -f 'aicli-run-' 2>/dev/null");
                usleep(500000); // 0.5s for process cleanup

                @unlink(StorageMountService::EMERGENCY_FLAG);
                $eHome = StorageMountService::EMERGENCY_HOME;
                if (is_dir($eHome)) exec("rm -rf " . escapeshellarg($eHome));
                // Remove emergency home symlinks so mount_stack.sh can mkdir
                foreach (glob("/tmp/unraid-aicliagents/work/*/home") as $link) {
                    if (is_link($link)) @unlink($link);
                }
                // Clean stale PID/socket files from killed sessions.
                // Bug #541: only unlink pid files whose recorded PID is dead.
                // Otherwise emergency cleanup can desync the registry from a
                // recycled-PID process that the OS reused for an unrelated
                // workload (we never SIGNAL the recycled PID — but the file
                // disappearing fools listActiveSessionsForAgent into thinking
                // no session exists when one actually does).
                foreach (glob("/var/run/unraid-aicliagents-*.pid") as $pidFile) {
                    $pid = (int)@file_get_contents($pidFile);
                    if ($pid > 0 && UtilityService::isPidRunning($pid)) {
                        // Process still alive — leave its pid file in place.
                        continue;
                    }
                    @unlink($pidFile);
                }
                foreach (glob("/var/run/aicliterm-*.sock") as $sock) @unlink($sock);

                // Remove emergency-installed agents from RAM (real sqsh agents will be mounted on demand)
                foreach (glob("/tmp/unraid-aicliagents/.emergency_agent_*") as $flag) {
                    $eAgentId = str_replace('/tmp/unraid-aicliagents/.emergency_agent_', '', $flag);
                    $eAgentDir = AgentRegistry::AGENT_BASE . "/$eAgentId";
                    if (is_dir($eAgentDir) && !StorageMountService::isMounted($eAgentDir)) {
                        exec("rm -rf " . escapeshellarg($eAgentDir));
                        LogService::log("Removed emergency RAM agent: $eAgentId", LogService::LOG_INFO, "InitService");
                    }
                    @unlink($flag);
                }
                // Clear emergency agent versions so real sqsh versions take over
                foreach (glob("/tmp/unraid-aicliagents/.emergency_agent_*") as $_) {} // already removed above
                @unlink("/tmp/unraid-aicliagents/install-status"); // stale global status

                // Notify UI to reload via Nchan
                NchanService::publish('storage_status', [
                    'home_available' => true,
                    'agents_available' => true,
                    'emergency_mode' => false,
                ]);

                LogService::log("Emergency mode cleanup complete. UI notified.", LogService::LOG_INFO, "InitService");
            }
        }

        // Clean stale installation progress files
        // D-327: Only delete if the corresponding install-bg.php process is NOT running
        // AND the file is older than 5 minutes (Grace period for UI completion).
        $statusFiles = glob("/tmp/unraid-aicliagents/install-status-*");
        $now = time();
        foreach ($statusFiles as $file) {
            $agentId = str_replace('/tmp/unraid-aicliagents/install-status-', '', $file);
            $mtime = filemtime($file);
            
            // Search for active background installer for this specific agent
            $cmd = "timeout 2 ps aux | grep 'install-bg.php " . escapeshellarg($agentId) . "' | grep -v grep";
            exec($cmd, $out, $res);
            
            if ($res !== 0 && ($now - $mtime) > 300) {
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
