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

        // Do NOT pre-remove the saved version. Keeping the old version while
        // the install is in-flight prevents the "v?" badge UX glitch when the
        // Store page renders during an upgrade (e.g. a parallel agent's
        // upgrade completes and refreshes the page while claude-code is still
        // baking). saveVersion at the end of the success path atomically
        // replaces the old version with the new one. Bonus: a failed install
        // also preserves the prior saved version so the user can keep using
        // the previous install — the install-status panel already surfaces the
        // failure, no need to also nuke the version metadata.
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
        $res = FileStorage::persist("agent/$agentId")->exit;   // Epic #1310: facade intent (delegates to commitChanges)
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
        // Non-root audit: iterate every per-uid tmux server so non-root
        // user sessions for this agent get killed too.
        foreach (glob('/tmp/unraid-aicliagents/tmux/tmux-*', GLOB_ONLYDIR) ?: [] as $perUserDir) {
            $sock = $perUserDir . '/default';
            if (!file_exists($sock)) continue;
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

        $layers = glob("$persistPath/agent_{$agentId}_*.sqsh") ?: [];
        $currentSize = 0;
        foreach ($layers as $l) $currentSize += (int)@filesize($l);

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

        $layers = glob("$persistPath/agent_{$agentId}_*.sqsh") ?: [];
        if (empty($layers)) {
            return ['status' => 'error', 'message' => "No SquashFS layers found for $agentId — nothing to back up"];
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
        foreach ($layers as $layer) {
            $base = basename($layer);
            if (!@copy($layer, "$backupDir/$base")) {
                // Roll back the partial backup so a failed attempt leaves nothing.
                foreach ($copied as $c) @unlink("$backupDir/$c");
                @unlink("$backupDir/meta.json");
                @rmdir($backupDir);
                return ['status' => 'error', 'message' => "Failed to copy layer $base to $backupDir"];
            }
            $copied[] = $base;
        }

        @file_put_contents("$backupDir/meta.json", json_encode([
            'agent'      => $agentId,
            'version'    => $version,
            'created_at' => $dt,
            'layers'     => $copied,
        ], JSON_PRETTY_PRINT));

        $bytes = 0;
        foreach ($copied as $c) $bytes += (int)@filesize("$backupDir/$c");
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
            $out[] = [
                'version'     => (string)$meta['version'],
                'created_at'  => (string)($meta['created_at'] ?? ''),
                'dir'         => $dir,
                'bytes'       => $bytes,
                'layer_count' => count((array)($meta['layers'] ?? [])),
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
        if (!is_array($meta) || empty($meta['version']) || empty($meta['layers'])) {
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
                && strpos((string)($cur['message'] ?? ''), 'No SquashFS layers') === false) {
                return ['status' => 'error', 'message' => 'Could not snapshot the current version before restore: ' . ($cur['message'] ?? '?')];
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
            $mnt = AgentRegistry::AGENT_BASE . "/$agentId";
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
