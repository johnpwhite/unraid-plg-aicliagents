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
     * #35: decide which version string to record after a successful install.
     * The on-disk probe is ground truth; but when it can't read a version and the
     * install was for an explicit target, that target IS what was installed — record
     * it instead of the useless literal 'installed' that used to leave the UI showing
     * a stale version. Returns null only when we have neither (caller falls back).
     * Pure, so it is unit-testable without a real install.
     */
    public static function versionToRecord(?string $probed, ?string $targetVersion): ?string {
        if ($probed !== null && trim($probed) !== '') return $probed;
        if ($targetVersion !== null && trim($targetVersion) !== '') return $targetVersion;
        return null;
    }

    /**
     * Installs an agent via NPM.
     * @param string $agentId The ID of the agent to install.
     */
    /**
     * End a staged install: clear the install-root override and release the
     * staging overlay.
     *
     * docs/specs/SIDE_BY_SIDE_AGENT_INSTALLS.md Phase 3. Every exit path out of
     * installAgent between staging and completion calls this, success or not.
     * The override is process-scoped, so leaving it set would point the NEXT
     * install in this process at a mount that no longer exists — which is why
     * clearing it comes first here and is not conditional on the unstage
     * succeeding.
     */
    private static function endStagedInstall(string $agentId, ?string $stagedRoot): void {
        if ($stagedRoot === null) return;
        AgentRegistry::setInstallRoot(null);
        StorageMountService::unstageAgentInstall($agentId);
    }

    public static function installAgent($agentId, $targetVersion = null) {
        @set_time_limit(900);
        if (empty($agentId)) return ['status' => 'error', 'message' => 'No Agent ID'];

        $config = ConfigService::getConfig();
        $persistPath = $config['agent_storage_path'] ?? "/boot/config/plugins/unraid-aicliagents";
        $mnt = AgentRegistry::agentPath($agentId);
        
        $oldSize = 0;
        foreach (glob("$persistPath/agent_{$agentId}_*.sqsh") as $f) $oldSize += filesize($f);
        $oldSizeMB = round($oldSize / 1024 / 1024, 2);

        LogService::log("Initiating agent installation/update sequence for: $agentId...", LogService::LOG_INFO, "InstallerService");
        setInstallStatus("Preparing storage...", 10, $agentId);
        
        // 1. D-321: Ensure storage is mounted before install so changes go to ZRAM upperdir
        // If already mounted, this is a no-op. If not, it sets up the OverlayFS stack.
        if (!FileStorage::ensureReady("agent/$agentId")->ok) {   // Epic #1310: facade intent
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

        // An omitted version means "use my selected release channel", not
        // npm's moving `latest` tag. Resolve it once, before any binary fetch or
        // replacement, so immediate, queued, forced, repair and reinstall paths
        // all obey the same Stable/Beta/Pinned contract.
        $resolution = VersionCheckService::resolveInstallTarget($agentId, $targetVersion);
        if ($resolution['target'] === null || $resolution['error'] !== null) {
            $reason = $resolution['error'] ?? 'unresolved_channel_target';
            LogService::log("Install target resolution failed for $agentId: $reason", LogService::LOG_ERROR, "InstallerService");
            setInstallStatus("Release channel error", 0, $agentId, $reason);
            return ['status' => 'error', 'message' => $reason];
        }
        $targetVersion = $resolution['target'];
        LogService::log(
            "Resolved install target for $agentId: channel={$resolution['channel']} tag="
            . ($resolution['resolved_tag'] ?? 'explicit') . " version=$targetVersion fallback="
            . ($resolution['fallback'] ? 'yes' : 'no'),
            $resolution['fallback'] ? LogService::LOG_WARN : LogService::LOG_INFO,
            "InstallerService"
        );
        LifecycleLogService::log(LifecycleLogService::LEVEL_INFO, 'installer', 'agent_install_target_resolved', [
            'agent' => $agentId,
            'channel' => $resolution['channel'],
            'resolved_tag' => $resolution['resolved_tag'],
            'version' => $targetVersion,
            'fallback' => $resolution['fallback'],
            'explicit' => $resolution['explicit'],
        ]);

        // Do NOT pre-remove the saved version. Keeping the old version while
        // the install is in-flight prevents the "v?" badge UX glitch when the
        // Store page renders during an upgrade (e.g. a parallel agent's
        // upgrade completes and refreshes the page while claude-code is still
        // baking). saveVersion at the end of the success path atomically
        // replaces the old version with the new one. Bonus: a failed install
        // also preserves the prior saved version so the user can keep using
        // the previous install — the install-status panel already surfaces the
        // failure, no need to also nuke the version metadata.
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
        LogService::log("Using source type '" . ($desc['type'] ?? '?') . "' for $agentId (target=$targetVersion)", LogService::LOG_INFO, "InstallerService");
        setInstallStatus("Starting install...", 20, $agentId);

        // docs/specs/SIDE_BY_SIDE_AGENT_INSTALLS.md Phase 3 (2026-09-15): install
        // into a layer of this install's own, over the same read-only layers the
        // running version is serving from, so nothing that is running sees the
        // new version until its workspace is reloaded.
        //
        // Until now the install wrote straight into the live mount's writable
        // layer, which is why an upgrade had to wait for every session of the
        // agent to close: by the time the mount swap was even considered, the
        // running sessions were already on the new binary.
        //
        // Staging is attempted only for source types whose install output is
        // entirely reconstructible (SourceResolver::supportsSideBySideInstall).
        // If it is unavailable for any reason the install proceeds exactly as
        // before — a degraded install is better than a refused one, and the
        // pre-install session queue is still in place behind it.
        $stagedRoot = null;
        if (SourceResolver::supportsSideBySideInstall($agent)) {
            $stagedRoot = StorageMountService::stageAgentInstall($agentId);
            if ($stagedRoot !== null) {
                AgentRegistry::setInstallRoot($stagedRoot);
                LifecycleLogService::log(LifecycleLogService::LEVEL_INFO, 'installer', 'agent_install_side_by_side', [
                    'agent' => $agentId,
                    'version' => $targetVersion,
                    'staged_at' => $stagedRoot,
                ]);
            } elseif (StorageMountService::lastStageRefusal() === 'no_space') {
                // 2026-09-26: the drive that holds the agents has no room for the
                // new version. Installing into the live tree instead would fill
                // it half way (on .4 that failed the vendor's extraction and the
                // settings saves too). Fail clearly; the version in use stays.
                $reason = 'Not enough free space on the drive that holds the agents for the new version. '
                    . 'The version in use is kept. Free some space (or let old versions be cleaned up), then try again.';
                LogService::log("Install of $agentId not started: $reason", LogService::LOG_ERROR, "InstallerService");
                setInstallStatus("Install failed", 0, $agentId, $reason);
                return ['status' => 'error', 'message' => $reason];
            } else {
                LogService::log(
                    "Side-by-side staging unavailable for $agentId — installing into the live layer (sessions of this agent must close before the new version is active).",
                    LogService::LOG_WARN, "InstallerService"
                );
            }
        }

        $ok = $source->fetch($agentId, $agent, $targetVersion, function(string $msg, int $pct) use ($agentId) {
            setInstallStatus($msg, $pct, $agentId);
        });
        if (!$ok) {
            // Surface the source's own reason when it has one (e.g. "Install script
            // timed out after 900s while: ==> Downloading …") instead of the bare
            // "Check logs for details" — that is what the activity tray shows.
            $reason = (method_exists($source, 'lastError')) ? trim((string)$source->lastError()) : '';
            LogService::log("Fetch failed for $agentId (source=" . ($desc['type'] ?? '?') . ")" . ($reason !== '' ? ": $reason" : ''), LogService::LOG_ERROR, "InstallerService");
            setInstallStatus("Install failed", 0, $agentId, $reason !== '' ? $reason : "Check logs for details");
            // A failed install leaves nothing behind: the staging layer is torn
            // down with its half-written tree, and the version in service was
            // never touched, so there is nothing to roll back.
            self::endStagedInstall($agentId, $stagedRoot);
            return ['status' => 'error', 'message' => $reason !== '' ? "Fetch failed: $reason" : 'Fetch failed'];
        }

        $source->stage($agentId, $agent);

        // 3. Set version and permissions
        setInstallStatus("Finalizing permissions...", 80, $agentId);

        // D-323: Discover version via source-specific probe (package.json / --version / VERSION file).
        // #35: if the probe can't read a version off disk but the install was for an
        // EXPLICIT target, that target is what npm installed — record it rather than the
        // useless literal 'installed' (which is what left the UI showing a stale version
        // after a successful upgrade). Always log what got recorded so a future
        // "still shows old version" is diagnosable from the log alone.
        // Probe NOW, while a staged install root is still in force (it names the
        // tree that was just installed). The record is written only after the
        // bake below succeeded (2026-09-24): a bake that failed or captured
        // nothing must not leave versions.json naming a version that never
        // reached a layer (.4: goose 1.51.0 recorded, 1.52.0 still running).
        $probed = $source->discoverVersion($agentId, $agent);
        $installedVer = self::versionToRecord($probed, $targetVersion);

        PermissionService::enforcePluginPermissions();
        // The tree that was just installed — the staging mount during a
        // side-by-side install, the live mount otherwise. chmod'ing the live
        // mount here would both miss the new files and touch a version that is
        // running.
        @exec("chmod -R 755 " . escapeshellarg(AgentRegistry::agentInstallPath($agentId)));

        setInstallStatus("Baking SquashFS delta...", 90, $agentId);

        // 5. Commit changes from ZRAM to SquashFS to persist on Flash.
        // No install-time consolidate — supervisor handles consolidation on its
        // own schedule via the post-bake threshold trigger in commitChanges
        // (StorageMountService) once layer count crosses the configured ceiling.
        // Bug #512: previously a sync StorageMigrationService::consolidateEntity
        // call ran here (D-332 phase 6), redundant with the post-bake trigger
        // and a source of install-time mount races.
        if ($stagedRoot !== null) {
            // Capture what the install wrote into its own layer as the agent's
            // new layer, then release the staging mount. The mount refresh that
            // op_bake runs afterwards is what binds the new version beside the
            // running one and moves the stable name onto it.
            $res = StorageMountService::bakeStagedInstall($agentId);
            self::endStagedInstall($agentId, $stagedRoot);
        } else {
            // Forgejo #296 (2026-09-23): the install wrote into the LIVE
            // generation's writable layer. Tell the storage ops which file is
            // the agent binary, so the consolidate can refuse a layer that
            // would drop it. The consolidate also never switches generation
            // here; it defers, and persist() then runs a delta bake.
            $binary = (string)($agent['binary'] ?? '');
            if ($binary !== '') putenv('AICLI_AGENT_BINARY=' . $binary);
            try {
                $res = FileStorage::persist("agent/$agentId")->exit;   // Epic #1310: facade intent (delegates to commitChanges)
            } finally {
                if ($binary !== '') putenv('AICLI_AGENT_BINARY');
            }
        }
        if ($res === 1) {
            LogService::log("Installer: Critical error during persistence bake for $agentId.", LogService::LOG_ERROR, "InstallerService");
            setInstallStatus("Install Failed (Bake Error)", 0, $agentId);
            return ['status' => 'error', 'message' => 'Persistence bake failed'];
        }
        if ($res === 2) {
            // Pre-install layer compaction was busy — not fatal. A delta bake preserved the
            // data to Flash; the next install will compact all layers back to one.
            LogService::log("Installer: Layer compaction busy for $agentId — install proceeded with delta bake fallback.", LogService::LOG_WARN, "InstallerService");
        }

        if ($installedVer) {
            AgentRegistry::saveVersion($agentId, $installedVer);
            LogService::log(
                "Recorded installed version for $agentId: $installedVer (probed=" . ($probed ?? 'none') . ", target=$targetVersion)",
                LogService::LOG_INFO, "InstallerService"
            );
        } else {
            LogService::log("Warning: Could not discover version for $agentId after install (no explicit target either).", LogService::LOG_WARN, "InstallerService");
            AgentRegistry::saveVersion($agentId, 'installed');
        }

        // install-bg owns agent-layer activation + closed-session relaunch. Do
        // not publish 100% before that state machine finishes: the terminal UI
        // treats completion as permission to reopen the retired ttyd id.
        $deferCompletion = getenv('AICLI_DEFER_INSTALL_COMPLETION') === '1';
        if ($deferCompletion) {
            setInstallStatus("Activating upgraded version...", 95, $agentId);
        } else {
            setInstallStatus("Installation complete", 100, $agentId);
        }

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

        // Seed manifest-declared `default_settings` into the agent's OWN JSON
        // config file, for a setting with no env var or CLI flag equivalent
        // (gemini-cli / qwen-code turn their self-updater off only through
        // settings.json). Same additive, sidecar-tracked contract as above.
        // AGENT_SELF_UPDATE_SUPPRESSION.md.
        try {
            $seedSettings = AgentSettingsSeedService::seedAgent($agentId);
            if (!empty($seedSettings['seeded'])) {
                LogService::log("Installer: seeded default_settings for $agentId — " . implode(',', $seedSettings['seeded']),
                    LogService::LOG_INFO, "InstallerService");
            }
            foreach ($seedSettings['errors'] as $err) {
                LogService::log("Installer: default_settings seed for $agentId reported '$err' — the plugin-upgrade sweep will retry",
                    LogService::LOG_WARN, "InstallerService");
            }
        } catch (\Throwable $e) {
            LogService::log("Installer: default_settings seed failed for $agentId: " . $e->getMessage(),
                LogService::LOG_WARN, "InstallerService");
        }

        // File-path-convention policy block (docs/specs/AGENT_FILE_PATH_CONVENTION.md):
        // best-effort, immediate injection so a freshly installed/upgraded agent has
        // the block without waiting for a full hub "Apply to agents" pass. Never
        // fails the install — same try/catch discipline as the env seed above.
        try {
            require_once __DIR__ . '/hub/HubProjector.php';
            \AICliAgents\Services\Hub\HubProjector::projectPolicy([$agentId]);
        } catch (\Throwable $e) {
            LogService::log("Installer: file-path policy projection failed for $agentId: " . $e->getMessage(),
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
        $agentDir = AgentRegistry::agentPath($agentId);
        $pluginDir = "/usr/local/emhttp/plugins/unraid-aicliagents";
        $flagFile = "/tmp/unraid-aicliagents/.emergency_agent_$agentId";

        LogService::log("EMERGENCY INSTALL: Starting RAM-only install for $agentId ($package)", LogService::LOG_WARN, "InstallerService");
        setInstallStatus("Preparing emergency install...", 10, $agentId);

        // Create agent directory directly in RAM (AGENT_BASE is on tmpfs)
        if (!is_dir($agentDir)) {
            @mkdir($agentDir, 0755, true);
        }

        setInstallStatus("Installing $package to RAM...", 20, $agentId);

        $resolution = VersionCheckService::resolveInstallTarget($agentId, null);
        if ($resolution['target'] === null || $resolution['error'] !== null) {
            $reason = $resolution['error'] ?? 'unresolved_channel_target';
            LogService::log("Emergency install target resolution failed for $agentId: $reason", LogService::LOG_ERROR, "InstallerService");
            setInstallStatus("Release channel error", 0, $agentId, $reason);
            return ['status' => 'error', 'message' => $reason];
        }
        $targetVersion = $resolution['target'];
        LogService::log(
            "EMERGENCY INSTALL target: channel={$resolution['channel']} tag="
            . ($resolution['resolved_tag'] ?? 'explicit') . " version=$targetVersion fallback="
            . ($resolution['fallback'] ? 'yes' : 'no'),
            $resolution['fallback'] ? LogService::LOG_WARN : LogService::LOG_INFO,
            "InstallerService"
        );

        // Direct npm install — no SquashFS, no ZRAM overlay
        $cmd = "export PATH=$pluginDir/bin:\$PATH; cd " . escapeshellarg($agentDir) . " && npm install " . escapeshellarg($package . "@$targetVersion") . " --no-audit --no-fund --loglevel info 2>&1";

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
        $mnt = AgentRegistry::agentPath($agentId);

        $oldSize = 0;
        foreach (glob("$persistPath/agent_{$agentId}_*.sqsh") as $f) $oldSize += filesize($f);
        $oldSizeMB = round($oldSize / 1024 / 1024, 2);

        LogService::log("Initiating complete uninstallation sequence for agent $agentId...", LogService::LOG_INFO, "InstallerService");

        // 1. Kill active sessions using this agent (tmux + ttyd)
        $safeId = escapeshellarg($agentId);
        // Non-root audit: iterate every per-uid tmux server so non-root
        // user sessions for this agent get killed too.
        foreach (\AICliAgents\Services\ProcessManager::tmuxSocketPaths() as $sock) {
            $tmuxBin = 'tmux -S ' . escapeshellarg($sock);
            exec("$tmuxBin ls -F '#S' 2>/dev/null | grep 'aicli-agent-.*$agentId' | xargs -I {} $tmuxBin kill-session -t {} > /dev/null 2>&1");
        }
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
            $removedIds = array_values(array_map(
                static fn($s) => (string)($s['id'] ?? ''),
                array_filter($ws['sessions'] ?? [], static fn($s) => ($s['agentId'] ?? '') === $agentId)
            ));
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
                ConfigService::saveWorkspaces($ws, $removedIds);
                LogService::log("Removed $removed workspace session(s) for $agentId.", LogService::LOG_INFO, "InstallerService");
            }
        } catch (\Throwable $e) {
            LogService::log("Warning: Could not clean workspace sessions: " . $e->getMessage(), LogService::LOG_WARN, "InstallerService");
        }

        // 8. Remove version registration (R4: clearVersion also purges passthrough-only entries)
        AgentRegistry::clearVersion($agentId);

        // 9. Remove the agent's manifest entry (WP #916). Without this, the
        // boot-integrity sweep on the next reboot finds `expected_layers > 0,
        // active_count == 0` and creates a total_loss halt for an agent the
        // user just deliberately uninstalled — surfacing a scary "Storage
        // Unavailable / drive may be disconnected" overlay for a ghost.
        @\AICliAgents\Services\FileStorage::dropManifestEntry("agent/$agentId");   // Epic #1310 facade intent

        // 10. Clear any pending halt for the agent. If uninstall is happening
        // BECAUSE the agent was halted, the halt sentinel survives the
        // uninstall and would gate the next page load.
        @\AICliAgents\Services\HaltService::clearHalt('agent', $agentId, 'agent_uninstalled');

        LogService::log("Successfully uninstalled $agentId and purged $oldSizeMB MB of associated storage.", LogService::LOG_INFO, "InstallerService");
        LifecycleLogService::log(LifecycleLogService::LEVEL_INFO, 'installer', 'agent_uninstalled', ['agent' => $agentId, 'purged_mb' => $oldSizeMB]);
        return ['status' => 'ok'];
    }

    /**
     * WP #964 (slice): resolve a destination path to its nearest existing
     * ancestor so disk_free_space() can be queried for the right volume even
     * when the user-typed backup directory doesn't exist yet.
     */
    private static function resolveExistingAncestor(string $path): string {
        $path = $path !== '' ? $path : '/';
        $guard = 0;
        while ($path !== '' && $path !== '/' && !is_dir($path) && $guard++ < 64) {
            $path = dirname($path);
        }
        return is_dir($path) ? $path : '/';
    }

    /** Inspect either storage backend without assuming every agent uses SquashFS. */
    public static function inspectUpgradeBackupSource(string $agentId, string $persistPath): array {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '', $agentId);
        $layers = glob(rtrim($persistPath, '/') . "/agent_{$safe}_*.sqsh") ?: [];
        // #338: the current version is the newest layer's STACK (down to its
        // base), not every layer file on flash — a retained previous version
        // below the base is not part of it, so the kept copy holds one version.
        $layers = LayerManifestService::agentStack($layers);
        if ($layers !== []) {
            $bytes = 0;
            foreach ($layers as $layer) $bytes += (int)@filesize($layer);
            return ['format' => 'squashfs', 'bytes' => $bytes, 'layers' => $layers, 'path' => null];
        }

        $path = rtrim($persistPath, '/') . "/passthrough/agents/$safe";
        if (!is_dir($path)) {
            return ['format' => 'unavailable', 'bytes' => 0, 'layers' => [], 'path' => null];
        }
        return ['format' => 'passthrough', 'bytes' => self::directorySize($path), 'layers' => [], 'path' => $path];
    }

    private static function directorySize(string $dir): int {
        if (!is_dir($dir)) return 0;
        $bytes = 0;
        try {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $item) {
                if ($item->isFile() && !$item->isLink()) $bytes += (int)$item->getSize();
            }
        } catch (\UnexpectedValueException $e) {
            return 0;
        }
        return $bytes;
    }

    private static function copyDirectory(string $source, string $dest): bool {
        if (!is_dir($source)) return false;
        $sourceReal = realpath($source);
        $destAncestor = realpath(self::resolveExistingAncestor($dest));
        if ($sourceReal !== false && $destAncestor !== false
            && ($destAncestor === $sourceReal || strpos($destAncestor, $sourceReal . DIRECTORY_SEPARATOR) === 0)) {
            return false; // Never recursively copy a directory into itself.
        }
        if (!is_dir($dest) && !@mkdir($dest, 0755, true) && !is_dir($dest)) return false;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $item) {
            $relative = substr($item->getPathname(), strlen(rtrim($source, '/')) + 1);
            $target = rtrim($dest, '/') . '/' . $relative;
            if ($item->isLink()) {
                if (!@symlink((string)readlink($item->getPathname()), $target)) return false;
            } elseif ($item->isDir()) {
                if (!is_dir($target) && !@mkdir($target, $item->getPerms() & 0777, true) && !is_dir($target)) return false;
            } elseif (!@copy($item->getPathname(), $target)) {
                return false;
            }
        }
        return true;
    }

    /**
     * WP #964 (slice): estimate the disk cost of backing up an agent's current
     * version before an upgrade, and check the destination has room.
     *
     * The check covers BOTH the backup copy AND the upcoming upgrade's own
     * footprint (the new version is guesstimated at ~the current version's
     * size, since the vendor rarely publishes a size up front), plus a 10%
     * headroom. Returns sizes in bytes for the overlay to render.
     */
    public static function estimateUpgradeBackup(string $agentId, string $destPath = ''): array {
        if (empty($agentId)) return ['status' => 'error', 'message' => 'No Agent ID'];
        $config = ConfigService::getConfig();
        $persistPath = $config['agent_storage_path'] ?? '/boot/config/plugins/unraid-aicliagents';
        $destPath = trim($destPath) !== '' ? trim($destPath) : $persistPath;

        $source = self::inspectUpgradeBackupSource($agentId, $persistPath);
        $layers = $source['layers'];
        $currentSize = (int)$source['bytes'];

        // Guesstimate the new version's footprint as ~the current version's.
        $estimatedNewSize = $currentSize;
        // Need room for: the backup copy + the upgrade install, +10% headroom.
        $required = (int)ceil(($currentSize + $estimatedNewSize) * 1.10);

        $free = @disk_free_space(self::resolveExistingAncestor($destPath));
        $free = ($free === false) ? 0 : (int)$free;

        return [
            'status'             => 'ok',
            'agent'              => $agentId,
            'current_version'    => AgentRegistry::getInstalledVersion($agentId),
            'dest'               => $destPath,
            'current_size'       => $currentSize,
            'estimated_new_size' => $estimatedNewSize,
            'required'           => $required,
            'free'               => $free,
            'sufficient'         => ($currentSize > 0 && $free >= $required),
            'layer_count'        => count($layers),
            'storage_format'     => $source['format'],
        ];
    }

    /**
     * WP #964 (slice): copy an agent's current SquashFS layers to a rollback
     * directory under $destPath BEFORE an upgrade replaces them. The layout
     * (aicli-rollback/<agentId>/<version>_<dt>/ + meta.json) is what the
     * future full rollback feature (WP #964) will enumerate and restore from.
     * Returns ['status'=>'ok','dir'=>...] or ['status'=>'error','message'=>...].
     */
    public static function backupAgentVersion(string $agentId, string $destPath): array {
        if (empty($agentId)) return ['status' => 'error', 'message' => 'No Agent ID'];
        if (strpos($destPath, '/') !== 0) {
            return ['status' => 'error', 'message' => 'Backup destination must be an absolute path'];
        }
        $config = ConfigService::getConfig();
        $persistPath = $config['agent_storage_path'] ?? '/boot/config/plugins/unraid-aicliagents';

        $source = self::inspectUpgradeBackupSource($agentId, $persistPath);
        $layers = $source['layers'];
        if ($source['format'] === 'unavailable' || (int)$source['bytes'] <= 0) {
            return ['status' => 'error', 'message' => "No persisted agent installation found for $agentId — nothing to back up"];
        }

        $version = AgentRegistry::getInstalledVersion($agentId);
        $versionTag = preg_replace('/[^A-Za-z0-9._-]/', '_', $version !== '' ? $version : 'unknown');

        // Skip if this exact version is already retained. Agent layers ARE the
        // version, so a second copy is redundant — it only clutters the
        // rollback picker (a restore round-trip would otherwise re-snapshot
        // each version it passes through).
        $rollbackRoot = rtrim($destPath, '/') . "/aicli-rollback/$agentId";
        foreach (glob("$rollbackRoot/*/meta.json") ?: [] as $existingMeta) {
            $em = json_decode((string)@file_get_contents($existingMeta), true);
            if (is_array($em) && ($em['version'] ?? '') === $version) {
                $existingDir = dirname($existingMeta);
                LogService::log("Backup of $agentId v$version already retained at $existingDir — skipping duplicate",
                    LogService::LOG_INFO, "InstallerService");
                return ['status' => 'ok', 'dir' => $existingDir, 'version' => $version,
                        'layers' => (array)($em['layers'] ?? []), 'bytes' => 0, 'skipped' => true];
            }
        }

        $dt = gmdate('Ymd\THis\Z');
        $backupDir = rtrim($destPath, '/') . "/aicli-rollback/$agentId/{$versionTag}_{$dt}";

        if (!is_dir($backupDir) && !@mkdir($backupDir, 0755, true) && !is_dir($backupDir)) {
            return ['status' => 'error', 'message' => "Cannot create backup directory: $backupDir"];
        }

        $copied = [];
        $payload = null;
        if ($source['format'] === 'passthrough') {
            $payload = 'passthrough';
            if (!self::copyDirectory((string)$source['path'], "$backupDir/$payload")) {
                self::rmdirContents($backupDir);
                @rmdir($backupDir);
                return ['status' => 'error', 'message' => "Failed to copy the passthrough installation to $backupDir"];
            }
        } else {
            foreach ($layers as $layer) {
                $base = basename($layer);
                if (!@copy($layer, "$backupDir/$base")) {
                    self::rmdirContents($backupDir);
                    @rmdir($backupDir);
                    return ['status' => 'error', 'message' => "Failed to copy layer $base to $backupDir"];
                }
                $copied[] = $base;
            }
        }

        @file_put_contents("$backupDir/meta.json", json_encode([
            'agent'      => $agentId,
            'version'    => $version,
            'created_at' => $dt,
            'layers'     => $copied,
            'storage_format' => $source['format'],
            'payload'    => $payload,
        ], JSON_PRETTY_PRINT));

        $bytes = 0;
        foreach ($copied as $c) $bytes += (int)@filesize("$backupDir/$c");
        if ($payload !== null) $bytes = self::directorySize("$backupDir/$payload");
        LogService::log("Backed up $agentId v$version (" . count($copied) . " layer(s), "
            . round($bytes / 1048576, 1) . " MB) to $backupDir", LogService::LOG_INFO, "InstallerService");
        LifecycleLogService::log(LifecycleLogService::LEVEL_INFO, 'installer', 'agent_version_backed_up',
            ['agent' => $agentId, 'version' => $version, 'dir' => $backupDir, 'bytes' => $bytes]);

        // WP #964: enforce the retention cap NOW that a new version is retained, so the
        // aicli-rollback tree can't grow unbounded on Flash across many upgrades.
        self::pruneRetainedBackups($agentId, $destPath, self::rollbackRetainMax());

        return ['status' => 'ok', 'dir' => $backupDir, 'version' => $version, 'layers' => $copied, 'bytes' => $bytes];
    }

    /**
     * WP #964: list the locally-retained version backups for an agent — the
     * snapshots backupAgentVersion writes under
     * <persist>/aicli-rollback/<agent>/<version>_<dt>/. Newest first.
     */
    public static function listRetainedBackups(string $agentId): array {
        if (empty($agentId)) return [];
        $config = ConfigService::getConfig();
        $persistPath = $config['agent_storage_path'] ?? '/boot/config/plugins/unraid-aicliagents';
        $base = rtrim($persistPath, '/') . "/aicli-rollback/$agentId";
        if (!is_dir($base)) return [];

        $out = [];
        foreach (glob("$base/*/meta.json") ?: [] as $metaFile) {
            $dir  = dirname($metaFile);
            $meta = json_decode((string)@file_get_contents($metaFile), true);
            if (!is_array($meta) || empty($meta['version'])) continue;
            $bytes = 0;
            foreach ((array)($meta['layers'] ?? []) as $l) $bytes += (int)@filesize("$dir/$l");
            if (($meta['storage_format'] ?? '') === 'passthrough' && !empty($meta['payload'])) {
                $bytes = self::directorySize("$dir/" . basename((string)$meta['payload']));
            }
            $out[] = [
                'version'     => (string)$meta['version'],
                'created_at'  => (string)($meta['created_at'] ?? ''),
                'dir'         => $dir,
                'bytes'       => $bytes,
                'layer_count' => count((array)($meta['layers'] ?? [])),
                'storage_format' => (string)($meta['storage_format'] ?? 'squashfs'),
            ];
        }
        // Newest first — the meta created_at is the YYYYMMDDTHHMMSSZ stamp.
        usort($out, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));

        // De-dup by version: several on-disk backups of the same version are
        // equivalent (agent layers ARE the version). Keep only the newest of
        // each so the picker shows one entry per version. New duplicates are
        // already prevented at capture time by backupAgentVersion; this also
        // collapses any duplicates left by an earlier plugin version.
        $seen = [];
        $deduped = [];
        foreach ($out as $entry) {
            if (isset($seen[$entry['version']])) continue;
            $seen[$entry['version']] = true;
            $deduped[] = $entry;
        }
        return $deduped;
    }

    /** Configured rollback retention cap — how many DISTINCT versions to keep per
     *  agent. Default 2 (the previous + one older); clamped to [1, 10]. WP #964. */
    public static function rollbackRetainMax(): int {
        $raw = (int)(ConfigService::getConfig()['rollback_retain_max'] ?? 2);
        if ($raw < 1)  $raw = 1;
        if ($raw > 10) $raw = 10;
        return $raw;
    }

    /**
     * WP #964: enforce the rollback retention cap. Keeps only the newest $keep
     * DISTINCT versions under <dest>/aicli-rollback/<agent>, deleting older versions
     * AND any same-version duplicate dirs — so retained backups can't grow unbounded
     * on Flash (an agent layer is ~100 MB). The surviving set is exactly what the
     * picker / listRetainedBackups show. Returns the directories pruned.
     */
    public static function pruneRetainedBackups(string $agentId, string $destPath, int $keep): array {
        if (empty($agentId) || $destPath === '') return [];
        if ($keep < 1) $keep = 1;
        $base = rtrim($destPath, '/') . "/aicli-rollback/$agentId";
        if (!is_dir($base)) return [];

        // (version, created_at, dir), newest-first — same ordering as listRetainedBackups.
        $entries = [];
        foreach (glob("$base/*/meta.json") ?: [] as $metaFile) {
            $meta = json_decode((string)@file_get_contents($metaFile), true);
            if (!is_array($meta) || empty($meta['version'])) continue;
            $entries[] = [
                'version'    => (string)$meta['version'],
                'created_at' => (string)($meta['created_at'] ?? ''),
                'dir'        => dirname($metaFile),
            ];
        }
        usort($entries, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));

        $keptVersions = [];
        $pruned = [];
        foreach ($entries as $e) {
            $known = isset($keptVersions[$e['version']]);
            if (!$known && count($keptVersions) < $keep) {
                $keptVersions[$e['version']] = true; // newest of a new version, within cap → keep
                continue;
            }
            // an older duplicate of a kept version, or a version beyond the cap → prune
            self::rmdirContents($e['dir']);
            @rmdir($e['dir']);
            $pruned[] = $e['dir'];
        }
        if (!empty($pruned)) {
            LogService::log("Pruned " . count($pruned) . " retained backup(s) for $agentId (cap=$keep): "
                . implode(', ', array_map('basename', $pruned)), LogService::LOG_INFO, "InstallerService");
        }
        return $pruned;
    }

    /** PHP-native recursive delete of a directory's contents (leaves the dir). */
    /**
     * #317/#318 (docs/specs/SIDE_BY_SIDE_AGENT_INSTALLS.md "2026-09-24 (#317,
     * #318)"): make a staged restore copy the active version of a versioned
     * plain-directory agent. Moves $staged to .versions/<id>/restore-<stamp>
     * (same filesystem, one rename) and points the $stableLink symlink at it
     * with a temporary link and one rename, so the name is never missing.
     * Never deletes anything: the previous version stays for the sessions that
     * use it and for the version cleanup to decide. Returns the new generation
     * id, or null (nothing changed) on failure.
     */
    public static function activatePassthroughRestoreGeneration(string $agentId, string $stableLink, string $staged, string $stamp): ?string {
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $agentId)) return null;
        if (!is_link($stableLink) || !is_dir($staged)) return null;
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $stamp)) return null;
        $gen = "restore-$stamp";
        $versions = dirname($stableLink) . "/.versions/$agentId";
        if (!is_dir($versions) && !@mkdir($versions, 0755, true)) return null;
        if (file_exists("$versions/$gen")) return null;
        if (!@rename($staged, "$versions/$gen")) return null;
        $tmp = "$stableLink.next." . getmypid();
        @unlink($tmp);
        if (!@symlink(".versions/$agentId/$gen", $tmp) || !@rename($tmp, $stableLink)) {
            @unlink($tmp);
            @rename("$versions/$gen", $staged);   // put the copy back: nothing changed
            return null;
        }
        return $gen;
    }

    private static function rmdirContents(string $dir): void {
        if (!is_dir($dir)) return;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            if ($f->isDir()) { @rmdir($f->getPathname()); } else { @unlink($f->getPathname()); }
        }
    }

    /**
     * WP #964: restore an agent to a locally-retained version backup. The
     * current version is snapshotted first (roll-forward safety), then the
     * retained layer becomes the active layer. Runs under the per-entity
     * storage lock (WP #966) so it cannot race a bake / consolidate / reconcile.
     */
    public static function restoreAgentVersion(string $agentId, string $backupDir): array {
        if (empty($agentId)) return ['status' => 'error', 'message' => 'No Agent ID'];
        $config = ConfigService::getConfig();
        $persistPath = $config['agent_storage_path'] ?? '/boot/config/plugins/unraid-aicliagents';

        // Validate $backupDir is genuinely one of THIS agent's retained
        // backups — never restore from an arbitrary caller-supplied path.
        $rollbackBase = realpath(rtrim($persistPath, '/') . "/aicli-rollback/$agentId");
        $realBackup   = realpath($backupDir);
        if ($rollbackBase === false || $realBackup === false
            || strpos($realBackup, $rollbackBase . DIRECTORY_SEPARATOR) !== 0) {
            return ['status' => 'error', 'message' => 'Not a retained backup for this agent'];
        }
        $meta = json_decode((string)@file_get_contents("$realBackup/meta.json"), true);
        $storageFormat = (string)($meta['storage_format'] ?? 'squashfs');
        $hasLayerPayload = !empty($meta['layers']);
        $hasDirectoryPayload = $storageFormat === 'passthrough' && !empty($meta['payload'])
            && is_dir("$realBackup/" . basename((string)$meta['payload']));
        if (!is_array($meta) || empty($meta['version']) || (!$hasLayerPayload && !$hasDirectoryPayload)) {
            return ['status' => 'error', 'message' => 'Retained backup is missing or has no meta.json'];
        }
        $restoreVersion = (string)$meta['version'];
        $retainedLayers = (array)$meta['layers'];

        // Per-entity storage lock (WP #966) — non-blocking: if a bake or
        // consolidate is in flight, ask the user to retry rather than hang.
        $lockId = preg_replace('/[^a-zA-Z0-9_-]/', '_', $agentId);
        $lockFh = @fopen("/var/run/aicli-bake-agent-$lockId.lock", 'c');
        if (!$lockFh || !flock($lockFh, LOCK_EX | LOCK_NB)) {
            if ($lockFh) fclose($lockFh);
            return ['status' => 'error', 'message' => 'A storage operation is in progress — try the restore again in a moment'];
        }

        try {
            // 1. Snapshot the current version so the user can roll forward.
            $cur = self::backupAgentVersion($agentId, $persistPath);
            if (($cur['status'] ?? '') !== 'ok'
                && strpos((string)($cur['message'] ?? ''), 'nothing to back up') === false) {
                return ['status' => 'error', 'message' => 'Could not snapshot the current version before restore: ' . ($cur['message'] ?? '?')];
            }

            // Passthrough backends have no SquashFS layers. Restore their
            // retained directory snapshot with an off-to-the-side copy and a
            // same-filesystem rename, then let FileStorage remount the bind.
            if ($storageFormat === 'passthrough') {
                $payload = "$realBackup/" . basename((string)$meta['payload']);
                $current = rtrim($persistPath, '/') . "/passthrough/agents/$agentId";
                $parent = dirname($current);
                $stamp = gmdate('Ymd\THis\Z') . '-' . getmypid();
                $staged = "$parent/.{$agentId}.restore-new-$stamp";
                $retired = "$parent/.{$agentId}.restore-old-$stamp";

                if (!self::copyDirectory($payload, $staged)) {
                    self::rmdirContents($staged); @rmdir($staged);
                    return ['status' => 'error', 'message' => 'Could not stage the retained passthrough installation'];
                }
                // #317/#318: on the versioned layout the stable name is a symlink
                // to .versions/<id>/<gen>. The rename-and-empty below would follow
                // that symlink and delete the files of a version a running session
                // may use. Add the restored copy as a new version instead; the
                // remount binds it beside the one in service.
                if (is_link($current)) {
                    $activated = self::activatePassthroughRestoreGeneration($agentId, $current, $staged, $stamp);
                    if ($activated === null) {
                        self::rmdirContents($staged); @rmdir($staged);
                        return ['status' => 'error', 'message' => 'Could not activate the retained passthrough installation'];
                    }
                    if (!FileStorage::ensureReady("agent/$agentId")->ok) {
                        return ['status' => 'error', 'message' => 'Retained installation restored, but the agent mount could not be reactivated'];
                    }
                    AgentRegistry::saveVersion($agentId, $restoreVersion);
                    VersionCheckService::invalidateAgent($agentId);
                    LifecycleLogService::log(LifecycleLogService::LEVEL_INFO, 'installer', 'agent_version_restored',
                        ['agent' => $agentId, 'version' => $restoreVersion, 'from' => $realBackup, 'storage_format' => 'passthrough', 'generation' => $activated]);
                    return ['status' => 'ok', 'version' => $restoreVersion];
                }
                $released = FileStorage::release("agent/$agentId");
                if (!$released->ok || $released->deferred) {
                    self::rmdirContents($staged); @rmdir($staged);
                    return ['status' => 'error', 'message' => 'Agent storage is still in use — close its sessions and retry'];
                }

                if (is_dir($current) && !@rename($current, $retired)) {
                    self::rmdirContents($staged); @rmdir($staged);
                    return ['status' => 'error', 'message' => 'Could not retire the current passthrough installation'];
                }
                if (!@rename($staged, $current)) {
                    if (is_dir($retired)) @rename($retired, $current);
                    self::rmdirContents($staged); @rmdir($staged);
                    FileStorage::ensureReady("agent/$agentId");
                    return ['status' => 'error', 'message' => 'Could not activate the retained passthrough installation'];
                }
                self::rmdirContents($retired); @rmdir($retired);

                $remounted = FileStorage::ensureReady("agent/$agentId")->ok;
                if (!$remounted) {
                    return ['status' => 'error', 'message' => 'Retained installation restored, but the agent mount could not be reactivated'];
                }
                AgentRegistry::saveVersion($agentId, $restoreVersion);
                VersionCheckService::invalidateAgent($agentId);
                LifecycleLogService::log(LifecycleLogService::LEVEL_INFO, 'installer', 'agent_version_restored',
                    ['agent' => $agentId, 'version' => $restoreVersion, 'from' => $realBackup, 'storage_format' => 'passthrough']);
                return ['status' => 'ok', 'version' => $restoreVersion];
            }

            // 2. Copy the retained layer(s) into the persistence directory.
            $manifestLayers = [];
            foreach ($retainedLayers as $layerFile) {
                $srcFile = "$realBackup/$layerFile";
                $dstFile = rtrim($persistPath, '/') . "/$layerFile";
                if (!is_file($srcFile)) {
                    return ['status' => 'error', 'message' => "Retained layer missing from backup: $layerFile"];
                }
                if (!@copy($srcFile, $dstFile)) {
                    return ['status' => 'error', 'message' => "Failed to copy retained layer into place: $layerFile"];
                }
                $manifestLayers[] = [
                    'filename'   => $layerFile,
                    'sha256'     => hash_file('sha256', $dstFile) ?: '',
                    'bytes'      => (int)@filesize($dstFile),
                    'kind'       => \AICliAgents\Services\LayerManifestService::classifyLayerKind($layerFile),
                    'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
                ];
            }

            // 3. Point the manifest at the restored layer set.
            \AICliAgents\Services\FileStorage::pointManifestAtLayers("agent/$agentId", $manifestLayers, $persistPath);   // Epic #1310 facade intent

            // 4. Remove the superseded current-version layers (preserved in
            //    aicli-rollback by step 1). Under the lock — race-free.
            foreach (glob(rtrim($persistPath, '/') . "/agent_{$agentId}_*.sqsh") ?: [] as $f) {
                if (!in_array(basename($f), $retainedLayers, true)) @unlink($f);
            }

            // 5. Wipe the agent's ZRAM upper so the merged view is exactly the
            //    restored layer (the agent overlay upper is not user data).
            self::rmdirContents("/tmp/unraid-aicliagents/zram_upper/agents/$agentId/upper");

            // 6. Remount the agent stack to pick up the restored lower layer.
            $mnt = AgentRegistry::agentPath($agentId);
            if (StorageMountService::isMounted($mnt)) {
                StorageMountService::unmount($mnt);
            }
            $remounted = FileStorage::ensureReady("agent/$agentId")->ok;   // Epic #1310: facade intent

            // 7. Record the restored version.
            AgentRegistry::saveVersion($agentId, $restoreVersion);
            VersionCheckService::invalidateAgent($agentId);

            LogService::log("Restored $agentId to v$restoreVersion from $realBackup (remounted=" . ($remounted ? 'yes' : 'no') . ")", LogService::LOG_INFO, "InstallerService");
            LifecycleLogService::log(LifecycleLogService::LEVEL_INFO, 'installer', 'agent_version_restored',
                ['agent' => $agentId, 'version' => $restoreVersion, 'from' => $realBackup]);

            return ['status' => 'ok', 'version' => $restoreVersion];
        } finally {
            flock($lockFh, LOCK_UN);
            fclose($lockFh);
        }
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

    // -------------------------------------------------------------------------
    // Verify-live gate helpers (R3)
    // -------------------------------------------------------------------------

    /**
     * Pure: extract the agent overlay's lowerdir from /proc/mounts text.
     * Returns null when the agent is not currently overlay-mounted.
     */
    public static function liveAgentLowerdir(string $agentId, string $mountsText): ?string
    {
        $safe   = preg_replace('/[^A-Za-z0-9._-]/', '', $agentId);
        // SIDE_BY_SIDE_AGENT_INSTALLS.md Phase 2 (2026-09-09): the needle must be
        // the REAL current mount target, not always agentBase()/$safe — once an
        // agent is migrated, /proc/mounts records the version-qualified directory
        // agentPath()'s stable symlink points at, never the symlink's own name.
        // agentLiveMountTarget() resolves that (falling back to the stable path
        // itself for a not-yet-migrated agent, byte-identical to Phase 1 there).
        //
        // It uses agentPath()'s STRICT id validator internally, which this
        // function's own $safe deliberately does not (Phase 1's permissive
        // matcher design, kept below) — so a malformed id that
        // agentLiveMountTarget() would reject falls back to the old permissive
        // concatenation, which can never match a real mount line anyway (no
        // real agent id looks like that).
        try {
            $target = AgentRegistry::agentLiveMountTarget($agentId);
        } catch (\InvalidArgumentException $e) {
            $target = AgentRegistry::agentBase() . '/' . $safe;
        }
        $needle = $target . ' ';
        foreach (explode("\n", $mountsText) as $line) {
            if (strpos($line, $needle) === false) continue;
            if (preg_match('/lowerdir=([^,\s]+)/', $line, $m)) return $m[1];
        }
        return null;
    }

    /**
     * True iff the live overlay lowerdir references the newest baked layer.
     * $newestLayerBasename is the basename of the highest-numbered .sqsh file
     * (e.g. "agent_claude-code_consolidated_0000000010_X.sqsh").
     *
     * Security fix: empty basename → false (no fail-open via empty strpos).
     * Correctness fix: exact leaf match (not substring) guards against prefix
     * collisions between layers. Multi-layer lowerdir (colon-joined A:B:C) is
     * split and each component's basename is checked individually.
     */
    public static function isAgentLayerLive(string $agentId, string $newestLayerBasename, string $mountsText): bool
    {
        if ($newestLayerBasename === '') return false;
        $stem = (string)preg_replace('/\.sqsh$/', '', $newestLayerBasename);
        if ($stem === '') return false;
        $ld = self::liveAgentLowerdir($agentId, $mountsText);
        if ($ld === null) return false;
        foreach (explode(':', $ld) as $component) {
            if (basename($component) === $stem) return true;
        }
        return false;
    }

    /**
     * Newest agent layer basename across both layer kinds (the persisted bake
     * outputs), or null if none. Globs the persistence dir and delegates the
     * chronological selection to newestAgentLayerBasename(). Layer filenames
     * share {type}_{id}_{kind}_{seq10}_{dt}.sqsh, so a lexical sort is
     * chronological across both the post-bake and delta-fallback kinds.
     */
    public static function newestAgentLayer(string $agentId, string $persistDir): ?string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '', $agentId);
        $glob = array_merge(
            glob("$persistDir/agent_{$safe}_consolidated_*.sqsh") ?: [],
            glob("$persistDir/agent_{$safe}_delta_*.sqsh") ?: []
        );
        return self::newestAgentLayerBasename($glob);
    }

    /**
     * Returns the basename of the newest agent layer file across all layer kinds
     * (post-bake and delta-fallback), or null if none found.
     *
     * Layer filenames share the format {type}_{id}_{kind}_{seq10}_{dt}.sqsh
     * where seq10 is a zero-padded 10-digit monotonic sequence number
     * (primary sort key) and dt is a UTC ISO 8601 basic timestamp (secondary
     * tiebreak). Lexical sort is therefore chronological; newest wins.
     *
     * @param array<string> $globResults Absolute or relative paths from glob().
     */
    public static function newestAgentLayerBasename(array $globResults): ?string
    {
        if (empty($globResults)) {
            return null;
        }
        // #338: order by the sequence number, then the time stamp. A plain
        // sort of names put an older "_delta_" after a newer "_consolidated_"
        // base, so a fresh base was never seen as the newest layer.
        usort($globResults, static fn($a, $b) => strcmp(
            LayerManifestService::layerSortKey((string)$a),
            LayerManifestService::layerSortKey((string)$b)
        ));
        return basename(end($globResults));
    }

    /**
     * Force a deferred agent overlay refresh now that sessions are closed.
     * Routes through FileStorage::forceRemount (the force-remount facade) so
     * storagectl is invoked via the canonical path — never called directly
     * (Epic #1310). Unlike ensureReady, forceRemount bypasses the
     * isAgentMountHealthy fast-path that would silently keep a stale lowerdir;
     * it unconditionally dispatches op_mount to swap in the newest baked layer.
     * Returns true when storagectl exits usably (exit 0 or 2).
     */
    public static function forceAgentRefresh(string $agentId): bool
    {
        return FileStorage::forceRemount("agent/$agentId");
    }

    // -------------------------------------------------------------------------

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
