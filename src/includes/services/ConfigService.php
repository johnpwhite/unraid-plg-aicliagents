<?php
/**
 * <module_context>
 *     <name>ConfigService</name>
 *     <description>Configuration management for the AICliAgents plugin.</description>
 *     <dependencies>LogService</dependencies>
 *     <constraints>Under 150 lines. Manages plugin settings and Nginx configuration.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class ConfigService {
    const CONFIG_PATH = "/boot/config/plugins/unraid-aicliagents/unraid-aicliagents.cfg";

    /**
     * Retrieves the plugin configuration.
     * @return array The configuration array.
     */
    public static function getConfig() {
        $defaults = [
            'root_path' => '/mnt/user',
            'user' => 'root',
            'history' => '100',
            'theme' => 'dark',
            'font_size' => '14',
            'debug_logging' => '0',
            'home_storage_path' => '/boot/config/plugins/unraid-aicliagents/persistence',
            'agent_storage_path' => '/boot/config/plugins/unraid-aicliagents/persistence',
            'sync_interval_mins' => '0',
            'sync_interval_hours' => '1',
            'write_protect_agents' => '1',
            'storage_opt_last_run' => '0',
            'enable_tab' => '1',
            'version_check_schedule' => '0 6 * * *',
            'version_check_months' => '3',
            // R-09 (Feature #1372): plugin health check cron — empty disables.
            'health_check_schedule' => '*/30 * * * *',
            // T-08 follow-on (ACTIVITY_TRAY.md): per-session graceful-close poll
            // budget in seconds. Default matches the historical hardcoded 3s.
            'graceful_close_timeout' => '3',
            // Storage Durability Supervisor (Phase 3)
            'supervisor_enabled'                => '1',
            'supervisor_tick_seconds'           => '5',
            // WP #748 Phase 1 (A/B/C): raised cadence defaults to reduce Flash wear.
            // OLD defaults: bake_schedule_minutes=30, dirty_threshold_soft_mb=512,
            // dirty_threshold_hard_mb=1024, dirty_threshold_critical_mb=2048,
            // consolidate_layer_threshold_flash=15.
            // Migration: PLG INLINE upgrade block rewrites these keys in existing
            // .cfg files when the stored value matches the OLD default exactly
            // (meaning the user never customised it). Customised values are left alone.
            'bake_schedule_minutes'             => '120',
            'dirty_threshold_soft_mb'           => '1024',
            'dirty_threshold_soft_pct'          => '12.5',
            'dirty_threshold_hard_mb'           => '2048',
            'dirty_threshold_hard_pct'          => '25',
            'dirty_threshold_critical_mb'       => '4096',
            'dirty_threshold_critical_pct'      => '50',
            'consolidate_layer_threshold_flash' => '30',
            'consolidate_layer_threshold_array' => '5',
            // Phase 5: homes-only consolidate policy — overlay layer ceiling.
            // Consolidation is recommended at this value MINUS 2. Read-time clamp to
            // [4, 40] via getConsolidateMaxLayers() (mirrors bash _consolidate_max_layers).
            // Default + bounds measured Phase 0.2 — see PHASE5_STORAGECTL_DISPATCHER.md.
            'consolidate_max_layers'            => '30',
            'emergency_bake_compression'        => 'lz4',
            // S-08 (#1353, STORAGE_ASYNC_JOBS.md): total wall-clock budget for a
            // deferred mount job's supervisor requeue-with-backoff (10→30→60 s)
            // before it fails + notifies. Covers UD devices mounting up to ~2 min
            // after array start.
            'storage_target_wait_s'             => '300',
            // Boot Integrity (Phase 4b)
            'boot_integrity_strict'             => '1',
            'verify_sha256_on_boot'             => '0',
            'lifecycle_log_max_bytes'           => '1048576',
            // R-05/R-07 (Feature #1370): debug.log rotation bound (tmpfs RAM
            // pressure, 1 kept generation) + structured format (text | jsonl).
            'debug_log_max_bytes'               => '5242880',
            'debug_log_format'                  => 'text',
            // T-12 (FIRST_RUN_WIZARD.md): empty = wizard not yet completed.
            // Set to 'yes' by the React wizard via the `save` action on completion.
            // No UI toggle — the wizard is deliberately one-shot.
            'first_run_done'                       => '',
            // Bug #537: array-stop / shutdown supervisor flush budget. Default
            // 60 s. Lift to 120-300 s if you have 100+ entities or run on slow
            // USB / contended memory.
            'event_stopping_flush_timeout_seconds' => '60',
        ];

        if (!file_exists(self::CONFIG_PATH)) {
            return $defaults;
        }

        $config = @parse_ini_file(self::CONFIG_PATH);
        if ($config === false) {
            return $defaults;
        }

        $merged = array_merge($defaults, $config);

        // Migrate legacy key: persistence_base → home_storage_path
        if (isset($config['persistence_base']) && !isset($config['home_storage_path'])) {
            $merged['home_storage_path'] = $config['persistence_base'];
        }

        return $merged;
    }

    // Phase 5 consolidate-policy bounds (mirror bash common.sh constants).
    const CONSOLIDATE_MAX_LAYERS_DEFAULT = 30;
    const CONSOLIDATE_MAX_LAYERS_FLOOR   = 4;
    const CONSOLIDATE_MAX_LAYERS_CEILING = 40;

    /**
     * Effective home overlay layer ceiling for the consolidate policy. The settings
     * page persists the raw value; this applies the read-time clamp to [4, 40], mirroring
     * the bash _consolidate_max_layers() helper so PHP and shell agree. Consolidation is
     * recommended at this value minus 2.
     * @return int Clamped layer ceiling.
     */
    public static function getConsolidateMaxLayers(): int {
        $config = self::getConfig();
        $raw = $config['consolidate_max_layers'] ?? self::CONSOLIDATE_MAX_LAYERS_DEFAULT;
        if (!is_numeric($raw)) {
            $raw = self::CONSOLIDATE_MAX_LAYERS_DEFAULT;
        }
        $val = (int)$raw;
        if ($val < self::CONSOLIDATE_MAX_LAYERS_FLOOR)   $val = self::CONSOLIDATE_MAX_LAYERS_FLOOR;
        if ($val > self::CONSOLIDATE_MAX_LAYERS_CEILING) $val = self::CONSOLIDATE_MAX_LAYERS_CEILING;
        return $val;
    }

    /**
     * Saves the plugin configuration.
     * @param array $newConfig The configuration array to save.
     * @param bool $notify Whether to notify the user of the change.
     */
    public static function saveConfig($newConfig, $notify = true) {
        LogService::log("Initiating plugin configuration update...", LogService::LOG_INFO, "ConfigService");

        $config = self::getConfig();
        $oldAgentPath = $config['agent_storage_path'] ?? "/boot/config/plugins/unraid-aicliagents";
        $newAgentPath = $newConfig['agent_storage_path'] ?? $oldAgentPath;
        
        $oldHomePath = $config['home_storage_path'] ?? "/boot/config/plugins/unraid-aicliagents/persistence";
        $newHomePath = $newConfig['home_storage_path'] ?? $oldHomePath;
        $oldVersionSchedule = $config['version_check_schedule'] ?? '0 6 * * *';
        $oldHealthSchedule  = $config['health_check_schedule'] ?? '*/30 * * * *';

        $changedKeys = [];
        foreach ($newConfig as $key => $val) {
            if ($key === 'csrf_token') continue;
            $oldVal = $config[$key] ?? '';
            if ((string)$oldVal !== (string)$val) {
                $changedKeys[] = "$key ($oldVal -> $val)";
            }
        }

        // D-405: Storage path migration is now handled by the dedicated execute_migrate AJAX action
        // with progress tracking. saveConfig only saves the config file — no file moves here.

        $config = array_merge($config, $newConfig);

        $content = "";
        foreach ($config as $key => $value) {
            $content .= "$key=\"" . addslashes($value) . "\"" . PHP_EOL;
        }

        if (!AtomicWriteService::write(self::CONFIG_PATH, $content)) {
            LogService::log("Error saving configuration to " . self::CONFIG_PATH, LogService::LOG_ERROR, "ConfigService");
            return false;
        }

        if ($notify) {
            if (empty($changedKeys)) {
                LogService::log("Plugin configuration saved with no logical changes.", LogService::LOG_INFO, "ConfigService");
            } else {
                LogService::log("Successfully updated plugin configuration. Changed keys: " . implode(", ", $changedKeys), LogService::LOG_INFO, "ConfigService");
                LifecycleLogService::log(LifecycleLogService::LEVEL_INFO, 'config', 'config_saved', ['changed_keys' => $changedKeys]);
            }
        }

        // Update cron job if version check schedule changed
        $newSchedule = $config['version_check_schedule'] ?? '';
        if ($newSchedule !== $oldVersionSchedule) {
            self::updateVersionCheckCron($newSchedule);
        }

        // R-09: update cron job if health check schedule changed
        $newHealthSchedule = $config['health_check_schedule'] ?? '';
        if ($newHealthSchedule !== $oldHealthSchedule) {
            self::updateHealthCheckCron($newHealthSchedule);
        }

        return true;
    }

    /**
     * Updates the cron job for agent version checking.
     */
    public static function updateVersionCheckCron(string $schedule): void {
        $cronFile = '/etc/cron.d/unraid-aicliagents.agent-check';
        $script = '/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/agentcheck';

        if (empty($schedule)) {
            // Disabled — remove cron file
            @unlink($cronFile);
        } else {
            $content = "# AICliAgents: Agent version check schedule\n$schedule $script &> /dev/null\n";
            @file_put_contents($cronFile, $content);
        }
        exec("/usr/local/sbin/update_cron 2>/dev/null");
        LogService::log("Version check cron updated: " . ($schedule ?: 'disabled'), LogService::LOG_INFO, "ConfigService");
    }

    /**
     * R-09 (Feature #1372): updates the cron job for the plugin health check.
     * Mirrors updateVersionCheckCron — empty schedule removes the cron file.
     */
    public static function updateHealthCheckCron(string $schedule): void {
        $cronFile = '/etc/cron.d/unraid-aicliagents.health-check';
        $script = '/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/healthcheck.php';

        if (empty($schedule)) {
            // Disabled — remove cron file
            @unlink($cronFile);
        } else {
            $content = "# AICliAgents: plugin health check schedule\n$schedule /usr/bin/php $script &> /dev/null\n";
            @file_put_contents($cronFile, $content);
        }
        exec("/usr/local/sbin/update_cron 2>/dev/null");
        LogService::log("Health check cron updated: " . ($schedule ?: 'disabled'), LogService::LOG_INFO, "ConfigService");
    }

    /**
     * Ensures the Nginx proxy configuration is up-to-date.
     */
    public static function ensureNginxConfig() {
        $nginxDir = "/etc/nginx/conf.d";
        $configFile = "$nginxDir/unraid-aicliagents.conf";

        if (!is_dir($nginxDir)) return;

        // Dynamic routing for multiple ttyd sessions via Unix Sockets
        $content = "location ~ ^/webterminal/(aicliterm-[^/]+)/ {
    proxy_pass http://unix:/var/run/$1.sock:/;
    proxy_http_version 1.1;
    proxy_set_header Upgrade \$http_upgrade;
    proxy_set_header Connection \"upgrade\";
    proxy_set_header Host \$host;
    proxy_set_header X-Real-IP \$remote_addr;
    proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto \$scheme;
    proxy_read_timeout 86400;
}
";

        if (file_exists($configFile) && file_get_contents($configFile) === $content) {
            return;
        }

        @file_put_contents($configFile, $content);
        exec("/etc/rc.d/rc.nginx reload > /dev/null 2>&1");
        LogService::log("Nginx configuration updated and reloaded.", LogService::LOG_DEBUG, "ConfigService");
    }

    /**
     * Helper to get the base path for user-specific state (.aicli inside their home).
     * D-333: Forces mount of home storage to ensure data is written to ZRAM/SquashFS stack.
     */
    public static function getUserStatePath() {
        $config = self::getConfig();
        $user = $config['user'] ?? 'root';
        if (empty($user)) $user = 'root';

        // Ensure home is mounted so we write into the OverlayFS stack, not the underlying rootfs
        if (!FileStorage::ensureReady("home/$user")->ok) {   // Epic #1310: facade intent
            LogService::log("getUserStatePath: home mount unavailable for '$user' — reads/writes will target bare tmpfs and may be lost", LogService::LOG_WARN, "ConfigService");
        }

        $homeDir = "/tmp/unraid-aicliagents/work/$user/home";
        $statePath = "$homeDir/.aicli";

        // Non-root user permission fix (2026-06-07): this runs as ROOT (web/emhttpd),
        // but the agent run-script writes .aicli/.exported_keys_* AS the session user.
        // Whichever side creates .aicli first owns it — and when a root write here
        // (workspaces.json, resumes, envs, autolaunch) wins, the dir is root-owned and
        // the non-root agent gets "Permission denied" creating files in it. Make .aicli
        // owned by the session user so BOTH root (web) and the agent (run-script) can
        // write. No-op for root sessions (root owns everything already).
        self::ensureStateDirOwnedBy($statePath, $user);

        return $statePath;
    }

    /**
     * Pure decision: should the session user's .aicli state dir be chowned to them?
     * Extracted so the ownership policy is testable without real OS users / root.
     *
     * @param string   $user        Session user ('root' / '' => never).
     * @param int|null $currentUid  Current owner uid of the dir (null if dir absent/unstattable).
     * @param int|null $targetUid   The session user's uid (null if unresolvable).
     */
    public static function shouldChownStateDir(string $user, ?int $currentUid, ?int $targetUid): bool
    {
        if ($user === '' || $user === 'root') return false; // root owns everything
        if ($targetUid === null) return false;              // can't map user -> uid
        return $currentUid !== $targetUid;                  // chown unless already owned
    }

    /**
     * Ensure $dir exists and is owned by $user (recursively, to fix any root-created
     * children like workspaces.json / args/ left over from before this fix). Guarded
     * by shouldChownStateDir() so steady-state reads do no work.
     */
    private static function ensureStateDirOwnedBy(string $dir, string $user): void
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (!function_exists('posix_getpwnam')) return;
        $pw = @posix_getpwnam($user);
        $targetUid = is_array($pw) ? (int)$pw['uid'] : null;
        $stat = @stat($dir);
        $currentUid = is_array($stat) ? (int)$stat['uid'] : null;
        if (!self::shouldChownStateDir($user, $currentUid, $targetUid)) return;
        // nosemgrep: php.lang.security.exec-use.exec-use
        @shell_exec("chown -R " . escapeshellarg($user) . " " . escapeshellarg($dir));
    }

    /**
     * Gets the full list of workspaces (sessions).
     */
    public static function getWorkspaces() {
        $file = self::getUserStatePath() . "/workspaces.json";
        
        if (!file_exists($file)) {
            return ['sessions' => [], 'activeId' => null];
        }
        
        return json_decode(file_get_contents($file), true) ?: ['sessions' => [], 'activeId' => null];
    }

    /**
     * Saves the list of workspaces (sessions).
     */
    public static function saveWorkspaces($data) {
        $count = count($data['sessions'] ?? []);
        $file = self::getUserStatePath() . "/workspaces.json";
        if (self::workspaceDataMatchesFile($file, $data)) {
            LogService::log("saveWorkspaces unchanged: count=$count path=$file", LogService::LOG_DEBUG, "ConfigService");
            return true;
        }
        // Diagnostic INFO (not DEBUG) so the boundary is visible in default-level
        // logs. A reported workspace-loss had no audit trail because the prior
        // log was DEBUG.
        $ok = AtomicWriteService::writeJson($file, $data);
        if (!$ok) {
            LogService::log("saveWorkspaces FAILED: count=$count path=$file", LogService::LOG_ERROR, "ConfigService");
            return false;
        }
        LogService::log("saveWorkspaces ok: count=$count path=$file", LogService::LOG_INFO, "ConfigService");
        return true;
    }

    /**
     * Return true only when an existing, valid workspace file already contains
     * exactly the requested state. Kept public so the no-write decision can be
     * tested against an isolated fixture without touching a live home overlay.
     */
    public static function workspaceDataMatchesFile(string $file, array $data): bool {
        if (!is_file($file)) return false;
        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') return false;
        $existing = json_decode($raw, true);
        return is_array($existing) && json_last_error() === JSON_ERROR_NONE && $existing === $data;
    }

    /**
     * Gets environment variables for a specific workspace and agent.
     */
    public static function getWorkspaceEnvs($path, $agentId) {
        $file = self::getEnvFilePath($path, $agentId);
        if (!file_exists($file)) {
            // Fallback to legacy path
            $hash = md5($path . $agentId);
            $legacyFile = "/boot/config/plugins/unraid-aicliagents/envs/env_$hash.json";
            if (file_exists($legacyFile)) return json_decode(file_get_contents($legacyFile), true) ?: [];
            return [];
        }
        LogService::log("Loading workspace envs for $agentId at $path", LogService::LOG_DEBUG, "ConfigService");
        return json_decode(file_get_contents($file), true) ?: [];
    }

    /**
     * Saves environment variables for a specific workspace and agent.
     */
    public static function saveWorkspaceEnvs($path, $agentId, $envs) {
        LogService::log("Initiating environment variable update for agent $agentId at $path...", LogService::LOG_INFO, "ConfigService");
        
        $oldEnvs = self::getWorkspaceEnvs($path, $agentId);
        $added = 0; $modified = 0; $removed = 0;

        foreach ($envs as $k => $v) {
            if (!isset($oldEnvs[$k])) $added++;
            elseif ((string)$oldEnvs[$k] !== (string)$v) $modified++;
        }
        foreach ($oldEnvs as $k => $v) {
            if (!isset($envs[$k])) $removed++;
        }

        $file = self::getEnvFilePath($path, $agentId);
        $ok = AtomicWriteService::writeJson($file, $envs);

        if ($ok) {
            LogService::log("Successfully updated environment variables for $agentId. Added: $added, Modified: $modified, Removed: $removed.", LogService::LOG_INFO, "ConfigService");
        } else {
            LogService::log("FAILED to save environment variables to $file", LogService::LOG_ERROR, "ConfigService");
        }

        return $ok;
    }

    /**
     * Retrieves the current plugin version from the installed .plg file.
     */
    public static function getVersion() {
        $plg = "/var/log/plugins/unraid-aicliagents.plg";
        if (!file_exists($plg)) return "unknown";
        $content = file_get_contents($plg);
        if (preg_match('/version="([^"]+)"/', $content, $m)) {
            return $m[1];
        }
        return "unknown";
    }

    /**
     * Helper to get the environment file path.
     */
    private static function getEnvFilePath($path, $agentId) {
        $hash = md5($path . $agentId);
        return self::getUserStatePath() . "/envs/env_$hash.json";
    }

    /**
     * Resume-ID persistence for the (workspace path, agent) pair.
     * Captured at clean-close time from the agent's own exit screen
     * (e.g. "claude --resume <uuid>"). Surfaced back to the UI so the
     * next session on the same combo can offer a Resume button.
     */
    private static function getResumeFilePath($path, $agentId) {
        $hash = md5($path . $agentId);
        return self::getUserStatePath() . "/resumes/resume_$hash.json";
    }

    public static function getResumeId($path, $agentId) {
        $file = self::getResumeFilePath($path, $agentId);
        if (!file_exists($file)) return null;
        $data = json_decode(file_get_contents($file), true);
        return is_array($data) ? ($data['chat_id'] ?? null) : null;
    }

    public static function saveResumeId($path, $agentId, $chatId) {
        $file = self::getResumeFilePath($path, $agentId);
        $data = ['chat_id' => $chatId, 'saved_at' => time()];
        return AtomicWriteService::writeJson($file, $data);
    }

    public static function clearResumeId($path, $agentId) {
        $file = self::getResumeFilePath($path, $agentId);
        return @unlink($file);
    }

    // -----------------------------------------------------------------------
    // Auto-launch preference persistence (per workspace+agent pair)
    // -----------------------------------------------------------------------

    private static function getAutoLaunchFilePath($path, $agentId): string
    {
        $hash = md5($path . $agentId);
        return self::getUserStatePath() . "/autolaunch/autolaunch_$hash.json";
    }

    public static function getAutoLaunch($path, $agentId): array
    {
        $file = self::getAutoLaunchFilePath($path, $agentId);
        if (!file_exists($file)) return ['autoLaunch' => false, 'freshIfNoResume' => false];
        $data = json_decode(file_get_contents($file), true);
        return is_array($data) ? $data : ['autoLaunch' => false, 'freshIfNoResume' => false];
    }

    public static function saveAutoLaunch($path, $agentId, bool $autoLaunch, bool $freshIfNoResume): bool
    {
        $file = self::getAutoLaunchFilePath($path, $agentId);
        $data = ['autoLaunch' => $autoLaunch, 'freshIfNoResume' => $freshIfNoResume];
        return AtomicWriteService::writeJson($file, $data);
    }

    public static function clearAutoLaunch($path, $agentId): void
    {
        @unlink(self::getAutoLaunchFilePath($path, $agentId));
    }

    // -----------------------------------------------------------------------
    // Agent-level auto-launch preference (R-C1/R-C2, CLAUDE_RELAUNCH_SURVIVAL).
    //
    // Auto-launch is an AGENT-LEVEL setting: enabling it for an agent makes ALL
    // of that agent's workspaces auto-launch/relaunch after a deploy, regardless
    // of how many are open. This supersedes the per-(path,agent) flag above (kept
    // only for the one-time migration; new writes go agent-scoped). Stored in a
    // single HOME-resident map so the whole agent set is one read.
    // -----------------------------------------------------------------------

    private static function getAgentAutoLaunchFilePath(): string
    {
        return self::getUserStatePath() . "/autolaunch_agents.json";
    }

    /**
     * Reads the full agent-level auto-launch map: { agentId => {autoLaunch, freshIfNoResume} }.
     */
    public static function getAgentAutoLaunchMap(): array
    {
        $file = self::getAgentAutoLaunchFilePath();
        if (!file_exists($file)) return [];
        $data = json_decode((string)file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    /**
     * Agent-level auto-launch preference for a single agent.
     * @return array{autoLaunch:bool, freshIfNoResume:bool}
     */
    public static function getAgentAutoLaunch(string $agentId): array
    {
        $map = self::getAgentAutoLaunchMap();
        $entry = $map[$agentId] ?? null;
        if (!is_array($entry)) return ['autoLaunch' => false, 'freshIfNoResume' => false];
        return [
            'autoLaunch'      => !empty($entry['autoLaunch']),
            'freshIfNoResume' => !empty($entry['freshIfNoResume']),
        ];
    }

    /**
     * Sets the agent-level auto-launch preference. Persists the whole map atomically.
     */
    public static function setAgentAutoLaunch(string $agentId, bool $autoLaunch, bool $freshIfNoResume): bool
    {
        if ($agentId === '') return false;
        $map = self::getAgentAutoLaunchMap();
        $map[$agentId] = ['autoLaunch' => $autoLaunch, 'freshIfNoResume' => $freshIfNoResume];
        return AtomicWriteService::writeJson(self::getAgentAutoLaunchFilePath(), $map);
    }

    /**
     * Removes the agent-level auto-launch entry for one agent. No-op if absent.
     */
    public static function clearAgentAutoLaunch(string $agentId): void
    {
        if ($agentId === '') return;
        $map = self::getAgentAutoLaunchMap();
        unset($map[$agentId]);
        AtomicWriteService::writeJson(self::getAgentAutoLaunchFilePath(), $map);
    }

    /**
     * One-time migration (R-C1): collapse per-(path,agent) auto-launch flags into
     * the agent-level map. For each agent, if ANY workspace had auto-launch ON, the
     * agent-level flag becomes ON (freshIfNoResume = OR of the contributing rows).
     * Idempotent: a marker on the HOME state dir guards against re-running, and the
     * function never downgrades an agent that is already enabled agent-level.
     *
     * @return bool true if the migration ran this call (false = already done / no-op marker)
     */
    public static function migrateAutoLaunchToAgentLevel(): bool
    {
        $marker = self::getUserStatePath() . "/.autolaunch_agent_migration_done";
        if (file_exists($marker)) return false;

        try {
            $workspaces = self::getWorkspaces();
            $sessions   = $workspaces['sessions'] ?? [];

            // Aggregate per-(path,agent) flags up to the agent level.
            $agg = self::getAgentAutoLaunchMap();
            foreach ($sessions as $session) {
                $path    = $session['path']    ?? '';
                $agentId = $session['agentId'] ?? '';
                if ($path === '' || $agentId === '') continue;
                $ws = self::getAutoLaunch($path, $agentId);
                if (empty($ws['autoLaunch'])) continue;
                $cur = $agg[$agentId] ?? ['autoLaunch' => false, 'freshIfNoResume' => false];
                $agg[$agentId] = [
                    'autoLaunch'      => true,
                    'freshIfNoResume' => !empty($cur['freshIfNoResume']) || !empty($ws['freshIfNoResume']),
                ];
            }

            if (!empty($agg)) {
                AtomicWriteService::writeJson(self::getAgentAutoLaunchFilePath(), $agg);
            }
        } catch (\Throwable $e) {
            LogService::log("autolaunch agent-level migration failed: " . $e->getMessage(), LogService::LOG_WARN, "ConfigService");
            // Do NOT write the marker on failure — retry next boot.
            return false;
        }

        @touch($marker);
        LogService::log("autolaunch migrated to agent-level map.", LogService::LOG_INFO, "ConfigService");
        return true;
    }
}
