<?php
/**
 * <module_context>
 *     <name>InstallerService</name>
 *     <description>Agent installation and uninstallation logic for AICliAgents.</description>
 *     <dependencies>LogService, ConfigService, StorageService, AgentRegistry</dependencies>
 *     <constraints>Under 200 lines. Handles NPM installations and space reservations.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

use AICliAgents\Services\Sources\SourceResolver;

class InstallerService {
    /**
     * Installs an agent via NPM.
     * @param string $agentId The ID of the agent to install.
     */
    public static function installAgent($agentId, $targetVersion = null) {
        @set_time_limit(900);
        if (empty($agentId)) return ['status' => 'error', 'message' => 'No Agent ID'];

        $config = ConfigService::getConfig();
        $persistPath = $config['agent_storage_path'] ?? "/boot/config/plugins/unraid-aicliagents";
        $mnt = AgentRegistry::AGENT_BASE . "/$agentId";
        
        $oldSize = 0;
        foreach (glob("$persistPath/agent_{$agentId}_*.sqsh") as $f) $oldSize += filesize($f);
        $oldSizeMB = round($oldSize / 1024 / 1024, 2);

        LogService::log("Initiating agent installation/update sequence for: $agentId...", LogService::LOG_INFO, "InstallerService");
        setInstallStatus("Preparing storage...", 10, $agentId);
        
        // 1. D-321: Ensure storage is mounted before install so changes go to ZRAM upperdir
        // If already mounted, this is a no-op. If not, it sets up the OverlayFS stack.
        if (!StorageMountService::ensureAgentMounted($agentId)) {
            LogService::log("Failed to mount storage stack for $agentId", LogService::LOG_ERROR, "InstallerService");
            setInstallStatus("Storage error", 0, $agentId, "Mount failure");
            return ['status' => 'error', 'message' => 'Could not mount agent storage'];
        }
        
        setInstallStatus("Storage ready...", 15, $agentId);
        
        // 2. Perform NPM install
        $registry = AgentRegistry::getRegistry();
        $agent = $registry[$agentId] ?? null;
        if (!$agent) {
            LogService::log("Installation aborted: Agent $agentId not found in registry.", LogService::LOG_ERROR, "InstallerService");
            return ['status' => 'error', 'message' => 'Agent not found in registry'];
        }

        // D-328: Remove version AFTER we have the agent config to avoid re-discovery loops
        AgentRegistry::removeVersion($agentId);

        $agentDir = AgentRegistry::AGENT_BASE . "/$agentId";

        // D-400 (#54): Dispatch on source type. NPM is handled by NpmSource; non-NPM agents
        // go through GithubReleaseSource / CurlInstallSource / TarballSource. SourceResolver
        // synthesises a {type:npm} source when the legacy top-level npm_package field is the
        // only thing set, so existing default-agent entries and user agents.json keep working.
        $source = SourceResolver::resolve($agent);
        if ($source === null) {
            LogService::log("Installation aborted: agent $agentId has no resolvable install source.", LogService::LOG_ERROR, "InstallerService");
            setInstallStatus("No install source", 0, $agentId, "Agent entry missing source or npm_package");
            return ['status' => 'error', 'message' => 'No install source'];
        }

        $desc = SourceResolver::descriptor($agent);
        LogService::log("Using source type '" . ($desc['type'] ?? '?') . "' for $agentId (target=" . ($targetVersion ?? 'latest') . ")", LogService::LOG_INFO, "InstallerService");
        setInstallStatus("Starting install...", 20, $agentId);

        $ok = $source->fetch($agentId, $agent, $targetVersion, function(string $msg, int $pct) use ($agentId) {
            setInstallStatus($msg, $pct, $agentId);
        });
        if (!$ok) {
            LogService::log("Fetch failed for $agentId (source=" . ($desc['type'] ?? '?') . ")", LogService::LOG_ERROR, "InstallerService");
            setInstallStatus("Install failed", 0, $agentId, "Check logs for details");
            return ['status' => 'error', 'message' => 'Fetch failed'];
        }

        $source->stage($agentId, $agent);

        // 3. Set version and permissions
        setInstallStatus("Finalizing permissions...", 80, $agentId);

        // D-323: Discover version via source-specific probe (package.json / --version / VERSION file).
        $installedVer = $source->discoverVersion($agentId, $agent);
        if ($installedVer) {
            AgentRegistry::saveVersion($agentId, $installedVer);
        } else {
            LogService::log("Warning: Could not discover version for $agentId after install.", LogService::LOG_WARN, "InstallerService");
            AgentRegistry::saveVersion($agentId, 'installed');
        }

        PermissionService::enforcePluginPermissions();
        @exec("chmod -R 755 " . escapeshellarg($agentDir));

        setInstallStatus("Baking SquashFS delta...", 90, $agentId);

        // 5. Commit changes from ZRAM to SquashFS to persist on Flash.
        // No install-time consolidate — supervisor handles consolidation on its
        // own schedule via the post-bake threshold trigger in commitChanges
        // (StorageMountService) once layer count crosses the configured ceiling.
        // Bug #512: previously a sync StorageMigrationService::consolidateEntity
        // call ran here (D-332 phase 6), redundant with the post-bake trigger
        // and a source of install-time mount races.
        $res = StorageMountService::commitChanges('agent', $agentId);
        if ($res === 1) {
            LogService::log("Installer: Critical error during persistence bake for $agentId.", LogService::LOG_ERROR, "InstallerService");
            setInstallStatus("Install Failed (Bake Error)", 0, $agentId);
            return ['status' => 'error', 'message' => 'Persistence bake failed'];
        }

        setInstallStatus("Installation complete", 100, $agentId);

        // Post-install: invalidate version cache and clear update notifications
        VersionCheckService::invalidateAgent($agentId);
        VersionCheckService::clearNotification($agentId);

        // Seed manifest-declared `default_envs` into the user's agent-env file
        // (additive; never overwrites a user value; sidecar-tracks seeded keys
        // so the next plugin upgrade doesn't resurrect a key the user deleted).
        // Per WP #736 ENV_AND_SECRETS_TIERS — runs on every successful install /
        // reinstall; the PLG-INLINE upgrade hook handles already-installed
        // agents picking up newly-declared defaults across plugin versions.
        try {
            $seed = EnvService::seedAgentDefaults($agentId);
            if (!empty($seed['seeded'])) {
                LogService::log("Installer: seeded default_envs for $agentId — " . implode(',', $seed['seeded']),
                    LogService::LOG_INFO, "InstallerService");
            }
        } catch (\Throwable $e) {
            // Seeding must never fail an install — log and continue.
            LogService::log("Installer: default_envs seed failed for $agentId: " . $e->getMessage(),
                LogService::LOG_WARN, "InstallerService");
        }

        return ['status' => 'ok'];
    }

    /**
     * Emergency install: npm install directly to RAM (AGENT_BASE), bypassing SquashFS.
     * Used when agent storage is unavailable (e.g., array stopped).
     * The installed agent is volatile — lost on reboot or when real storage returns.
     */
    public static function emergencyInstallAgent($agentId) {
        @set_time_limit(600);
        if (empty($agentId)) return ['status' => 'error', 'message' => 'No Agent ID'];

        $registry = AgentRegistry::getRegistry();
        $agent = $registry[$agentId] ?? null;
        if (!$agent) return ['status' => 'error', 'message' => 'Agent not found in registry'];

        // Emergency install bypasses the storage stack; only NPM agents are supported here
        // because GitHub/curl-based agents typically need a staging layout we'd then lose
        // on reboot anyway. Non-NPM agents should wait for array-start and do a normal install.
        if (empty($agent['npm_package'])) {
            LogService::log("Emergency install refused for $agentId: non-NPM agents are not supported in emergency mode.", LogService::LOG_ERROR, "InstallerService");
            setInstallStatus("Emergency install not available", 0, $agentId, "Non-NPM agent — start the array and try again");
            return ['status' => 'error', 'message' => 'Non-NPM agents cannot be emergency-installed'];
        }

        $package = $agent['npm_package'];
        $agentDir = AgentRegistry::AGENT_BASE . "/$agentId";
        $pluginDir = "/usr/local/emhttp/plugins/unraid-aicliagents";
        $flagFile = "/tmp/unraid-aicliagents/.emergency_agent_$agentId";

        LogService::log("EMERGENCY INSTALL: Starting RAM-only install for $agentId ($package)", LogService::LOG_WARN, "InstallerService");
        setInstallStatus("Preparing emergency install...", 10, $agentId);

        // Create agent directory directly in RAM (AGENT_BASE is on tmpfs)
        if (!is_dir($agentDir)) {
            @mkdir($agentDir, 0755, true);
        }

        setInstallStatus("Installing $package to RAM...", 20, $agentId);

        // Direct npm install — no SquashFS, no ZRAM overlay
        $cmd = "export PATH=$pluginDir/bin:\$PATH; cd " . escapeshellarg($agentDir) . " && npm install " . escapeshellarg($package . "@latest") . " --no-audit --no-fund --loglevel info 2>&1";

        $currentProgress = 20;
        $res = UtilityService::execStreaming($cmd, function($line, $isError) use ($agentId, &$currentProgress) {
            LogService::log("[NPM-Emergency] $line", LogService::LOG_DEBUG, "InstallerService");
            if ($currentProgress < 85) {
                $currentProgress += 0.5;
                $msg = "Installing: $line";
                if (strlen($msg) > 60) $msg = substr($msg, 0, 57) . "...";
                setInstallStatus($msg, (int)$currentProgress, $agentId);
                usleep(100000);
            }
        });

        if ($res !== 0) {
            LogService::log("EMERGENCY INSTALL FAILED for $agentId (Exit: $res)", LogService::LOG_ERROR, "InstallerService");
            setInstallStatus("Emergency install failed", 0, $agentId, "NPM install error");
            return ['status' => 'error', 'message' => 'NPM install failed'];
        }

        // Set permissions
        setInstallStatus("Setting permissions...", 90, $agentId);
        exec("chmod -R 755 " . escapeshellarg($agentDir));

        // Mark as emergency-installed (so cleanup knows to remove it later)
        @touch($flagFile);

        // Save version
        $installedVer = AgentRegistry::discoverVersion($agentId, $agent['binary'], $agent['binary_fallback'] ?? '');
        if ($installedVer) AgentRegistry::saveVersion($agentId, $installedVer);

        setInstallStatus("Emergency install complete", 100, $agentId);
        LogService::log("EMERGENCY INSTALL COMPLETE: $agentId installed to RAM at $agentDir", LogService::LOG_WARN, "InstallerService");

        return ['status' => 'ok', 'emergency' => true];
    }

    public static function uninstallAgent($agentId) {
        if (empty($agentId)) return ['status' => 'error', 'message' => 'No Agent ID'];

        $config = ConfigService::getConfig();
        $persistPath = $config['agent_storage_path'] ?? "/boot/config/plugins/unraid-aicliagents";
        $mnt = AgentRegistry::AGENT_BASE . "/$agentId";

        $oldSize = 0;
        foreach (glob("$persistPath/agent_{$agentId}_*.sqsh") as $f) $oldSize += filesize($f);
        $oldSizeMB = round($oldSize / 1024 / 1024, 2);

        LogService::log("Initiating complete uninstallation sequence for agent $agentId...", LogService::LOG_INFO, "InstallerService");

        // 1. Kill active sessions using this agent (tmux + ttyd)
        $safeId = escapeshellarg($agentId);
        exec("tmux ls -F '#S' 2>/dev/null | grep 'aicli-agent-.*$agentId' | xargs -I {} tmux kill-session -t {} > /dev/null 2>&1");
        exec("pgrep -f 'ttyd.*$agentId' 2>/dev/null | xargs -r kill -15 > /dev/null 2>&1");

        // 2. Unmount the stack
        if (StorageMountService::isMounted($mnt)) {
            exec("umount -l " . escapeshellarg($mnt));
        }

        // 3. Delete all SquashFS volumes from Flash
        foreach (glob("$persistPath/agent_{$agentId}_*.sqsh") as $sqsh) {
            @unlink($sqsh);
        }

        // 4. Clear ZRAM upper/work directories
        $zramBase = "/tmp/unraid-aicliagents/zram_upper/agents/$agentId";
        if (is_dir($zramBase)) {
            exec("rm -rf " . escapeshellarg($zramBase));
        }

        // 5. Remove empty mount point directory (will be recreated on reinstall)
        if (is_dir($mnt) && !StorageMountService::isMounted($mnt)) {
            @rmdir($mnt);
        }

        // 6. Clean up runtime files
        @unlink("/tmp/unraid-aicliagents/install-status-$agentId");
        @unlink("/tmp/unraid-aicliagents/task-status-agents");

        // 7. Remove workspace sessions that used this agent (keep home data intact)
        try {
            $ws = ConfigService::getWorkspaces();
            $before = count($ws['sessions'] ?? []);
            $ws['sessions'] = array_values(array_filter($ws['sessions'] ?? [], function($s) use ($agentId) {
                return ($s['agentId'] ?? '') !== $agentId;
            }));
            $removed = $before - count($ws['sessions']);
            if ($removed > 0) {
                // If active session was for this agent, clear it
                if (!empty($ws['activeId'])) {
                    $activeStillExists = false;
                    foreach ($ws['sessions'] as $s) {
                        if (($s['id'] ?? '') === $ws['activeId']) { $activeStillExists = true; break; }
                    }
                    if (!$activeStillExists) $ws['activeId'] = !empty($ws['sessions']) ? $ws['sessions'][0]['id'] : null;
                }
                ConfigService::saveWorkspaces($ws);
                LogService::log("Removed $removed workspace session(s) for $agentId.", LogService::LOG_INFO, "InstallerService");
            }
        } catch (\Throwable $e) {
            LogService::log("Warning: Could not clean workspace sessions: " . $e->getMessage(), LogService::LOG_WARN, "InstallerService");
        }

        // 8. Remove version registration
        AgentRegistry::removeVersion($agentId);

        LogService::log("Successfully uninstalled $agentId and purged $oldSizeMB MB of associated storage.", LogService::LOG_INFO, "InstallerService");
        return ['status' => 'ok'];
    }

    /**
     * Cleans up legacy files from previous monolithic architectures.
     */
    public static function cleanupLegacy() {
        LogService::log("Cleaning up legacy installer artifacts...", LogService::LOG_DEBUG, "InstallerService");
        
        $legacyFiles = [
            "/tmp/aicliagent_install.log",
            "/tmp/aicliagents_post_install.sh",
            "/usr/local/emhttp/plugins/unraid-aicliagents/scripts/install-agents.sh"
        ];
        foreach ($legacyFiles as $f) {
            if (file_exists($f)) @unlink($f);
        }
    }

    /**
     * Updates the Unraid Tasks menu visibility.
     */
    public static function updateMenuVisibility($enable) {
        $pageFile = "/usr/local/emhttp/plugins/unraid-aicliagents/AICliAgents.page";
        if (!file_exists($pageFile)) return;
        
        $content = file_get_contents($pageFile);
        if ($enable == '1') {
            $content = str_replace('Menu="none"', 'Menu="Tasks"', $content);
        } else {
            $content = str_replace('Menu="Tasks"', 'Menu="none"', $content);
        }
        file_put_contents($pageFile, $content);
    }
}
