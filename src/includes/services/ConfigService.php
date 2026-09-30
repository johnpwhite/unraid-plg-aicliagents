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
    private static ?string $lastWorkspaceSaveMessage = null;

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
            // One global storage engine for all agents and homes.  SquashFS +
            // zram is the safe default on the boot device and removable media;
            // changing this setting runs an explicit all-entity migration.
            'storage_backend_mode' => 'layering',
            // #153: the former sync_interval_hours/mins keys were removed — no
            // scheduler ever read them. The automatic-save cadence is
            // bake_schedule_minutes (below), which the Configuration tab now exposes.
            'write_protect_agents' => '1',
            // #272/#110: automatic safe launch. The workspace identity remains
            // the configured /mnt/user path, but cache-only shares launch from
            // their direct pool path so agent I/O never needs shfs. The old
            // 'share' and 'pool' values remain accepted by PoolPathService.
            'workspace_cwd' => 'auto',
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
            // PLUGIN_MANAGEMENT_TOOLS.md: read-only "plugin management" tool
            // catalogue for agents (AdminMcpTools). Off on a fresh install —
            // '0' here makes that explicit instead of relying on the service's
            // own fallback. Settings > Configuration > Plugin management.
            'admin_tools_enabled'                  => '0',
            // CONTINUE_ON_RESTART.md (2026-09-09): moved here from the Relay's
            // settings.json — this governs whether a WORKSPACE resumes its own
            // work after a restart, not Relay messaging. On by default, same as
            // it was at the old location. A value already saved at the OLD
            // location (including an explicit "0") is migrated the first time
            // autoContinueOnRestart() runs — see that method.
            'auto_continue_on_restart'             => '1',
            // TRANSIENT_ERROR_AUTO_CONTINUE.md (#312): continue a workspace by itself
            // after a TEMPORARY model/API error (5xx, overloaded, stream reset).
            // 'recommended' = only agents whose error shape is verified
            // (TransientErrorService::RECOMMENDED_AGENTS); 'all' | 'off'.
            'transient_error_continue'             => 'recommended',
            // Wait before each automatic continue of one incident, in minutes.
            'transient_error_backoff_minutes'      => '1,5,15',
            // Automatic continues per incident before the tray says "needs you".
            'transient_error_max_continues'        => '3',
            // RELAY_WAITING_PILL.md (2026-09-09) Part 2/3: capture-on-deliver. Off by
            // default — the operator turns it on deliberately in Settings > Session &
            // Environment. When on, clicking Deliver on a waiting-message pill records
            // one redacted pane sample paired with the gate's verdict, to build correct
            // idle profiles for agents nobody has observed yet. See RelayGateSampleService.
            'relay_gate_sampling_enabled'          => '0',
            // AGENT_VOICE.md R3: empty tts_url means browser-speech mode. The
            // matching secret (tts_api_key) is NEVER a cfg key — it lives only
            // in secrets.cfg (SecretService::TOKEN_KEY = 'TTS_API_KEY').
            'tts_url'                              => '',
            'tts_voice'                            => 'af_heart',
            'tts_speed'                            => '1.0',
            // VOICE_SWITCHES.md R1: the one global on/off switch. Off by
            // default. '0'/'1' string, read the same way every other
            // plugin-config boolean is read (never a truthy cast).
            'voice_enabled'                        => '0',
            // VOICE_INPUT.md R1: empty stt_url means browser-recognition mode
            // (the page's own SpeechRecognition, no server round trip). The
            // matching secret (stt_api_key) is NEVER a cfg key — it lives only
            // in secrets.cfg (VoiceService::STT_TOKEN_KEY = 'STT_API_KEY').
            'stt_url'                               => '',
            'stt_model'                             => 'whisper-1',
            'stt_language'                          => '',
            // VOICE_MAIL.md R8: how much voice mail to keep. A message goes when
            // its workspace holds more than this many (heard ones first), or when
            // it is older than this many days, whichever comes first.
            'voicemail_max_per_workspace'           => '50',
            'voicemail_max_age_days'                => '7',
            // HOME_BACKUP.md: a clean, restorable copy of a user's home on a
            // target the operator chose. Empty target = the feature is off.
            'backup_target'                        => '',
            // 'cold' closes the sessions first (consistent agent databases);
            // 'warm' copies the live home with no close (best effort).
            'backup_quiesce'                        => 'cold',
            // How many snapshots to keep per user (oldest removed first, never
            // the one 'latest' points to).
            'backup_keep'                           => '5',
            // 'off' | 'daily:HH:MM' | 'weekly:D:HH:MM' (D = 0-6, 0 = Sunday).
            'backup_schedule'                       => 'off',
            // Newline list of rsync exclude patterns. These are re-downloadable
            // caches (2026-09-12 incident loss list) — never the agent's own
            // config, memory, transcripts, or secrets.
            'backup_excludes'                       => ".claude/plugins/**\n**/node_modules/**\n.grok/marketplace-cache/**\n.gemini/antigravity-cli/**\n.cache/**\n.claude/image-cache/**\n**/*.tmp",
            // When on, a session recorded 'working' at close time gets one
            // Continue nudge after it is resumed post-backup.
            'backup_nudge_working'                  => '1',
            // WORKSPACE_UPLOAD_MULTI_CHUNKED.md R3: the largest single file the
            // upload overlay accepts, in bytes. 0 = no cap. Read by
            // UtilityHandler::getUploadLimits() as `max_file_bytes`. A file at
            // or below this is still sent in chunks when it is bigger than one
            // chunk — this setting only bounds the TOTAL file size.
            'upload_max_bytes'                      => '536870912',
        ];

        if (!file_exists(self::CONFIG_PATH)) {
            return $defaults;
        }

        $config = @parse_ini_file(self::CONFIG_PATH);
        if ($config === false) {
            return $defaults;
        }

        $merged = array_merge($defaults, $config);

        // Migrate the legacy path only when the modern home key is absent. The
        // old cfg entry may still be present on disk after an upgrade, but it is
        // not an active setting and must not leak into settings/admin output.
        if (isset($config['persistence_base']) && !isset($config['home_storage_path'])) {
            $merged['home_storage_path'] = $config['persistence_base'];
        }
        unset($merged['persistence_base']);

        return $merged;
    }

    /**
     * The config file's OWN keys, with no defaults merged in — needed to tell
     * "never set" apart from "set to the default value" (getConfig() cannot
     * make that distinction, since a merge fills in every missing key). Used
     * by the auto-continue-on-restart migration below. Returns [] when the
     * file is absent or unparsable, same as getConfig()'s own fallback.
     */
    private static function rawConfigFile(): array {
        if (!file_exists(self::CONFIG_PATH)) return [];
        $config = @parse_ini_file(self::CONFIG_PATH);
        return is_array($config) ? $config : [];
    }

    /**
     * CONTINUE_ON_RESTART.md (2026-09-09): auto-continue-on-restart moved here
     * from AgentRelayService — it decides whether a WORKSPACE resumes its own
     * work after a restart (TmuxService::submitContinueNudge()), which is
     * session-restart behaviour, not Relay messaging. It only lived on the
     * Relay tab because #34 needed a server-side file and reused the nearest
     * one that existed.
     *
     * This getter doubles as the one-time migration: if this plugin's own
     * config has never stored the key (rawConfigFile() has no entry — NOT the
     * same as getConfig(), which would already show the merged-in default),
     * read whatever the OLD Relay-owned settings.json holds — including an
     * explicit false — adopt it, and persist it here so the read-through
     * happens at most once per install. An install that never touched the
     * setting at the old location adopts the new default (on).
     */
    public static function autoContinueOnRestart(): bool {
        $raw = self::rawConfigFile();
        if (array_key_exists('auto_continue_on_restart', $raw)) {
            return (string)$raw['auto_continue_on_restart'] === '1';
        }

        $adopted = true; // new default, used when the old location never had a value either
        if (\class_exists(AgentRelayService::class)) {
            try {
                $legacy = AgentRelayService::legacyAutoContinueOnRestartIfStored();
                if ($legacy !== null) $adopted = $legacy;
            } catch (\Throwable $e) {
                LogService::log("Auto-continue migration read failed: " . $e->getMessage(), LogService::LOG_WARN, "ConfigService");
            }
        }
        self::persistAutoContinueOnRestart($adopted);
        return $adopted;
    }

    /** Explicit setter for the Manager UI / tests. Writes straight to this plugin's own config. */
    public static function setAutoContinueOnRestart(bool $enabled): array {
        return self::persistAutoContinueOnRestart($enabled)
            ? ['status' => 'ok', 'enabled' => $enabled]
            : ['status' => 'error', 'message' => 'Could not save the auto-continue setting.'];
    }

    /**
     * Writes only the auto_continue_on_restart key, leaving every other key in
     * the file (or its absence) untouched — deliberately narrower than
     * saveConfig(), which would materialise the FULL default map to disk on a
     * fresh install the first time anything reads this setting.
     */
    private static function persistAutoContinueOnRestart(bool $enabled): bool {
        $raw = self::rawConfigFile();
        $raw['auto_continue_on_restart'] = $enabled ? '1' : '0';
        $content = "";
        foreach ($raw as $key => $value) {
            $content .= "$key=\"" . addslashes((string)$value) . "\"" . PHP_EOL;
        }
        return AtomicWriteService::write(self::CONFIG_PATH, $content);
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

        if (isset($newConfig['storage_backend_mode'])) {
            $newConfig['storage_backend_mode'] = StorageBackendPolicyService::normalizeMode(
                $newConfig['storage_backend_mode']
            );
        }

        $config = self::getConfig();
        $oldAgentPath = $config['agent_storage_path'] ?? "/boot/config/plugins/unraid-aicliagents";
        $newAgentPath = $newConfig['agent_storage_path'] ?? $oldAgentPath;
        
        $oldHomePath = $config['home_storage_path'] ?? "/boot/config/plugins/unraid-aicliagents/persistence";
        $newHomePath = $newConfig['home_storage_path'] ?? $oldHomePath;
        $oldVersionSchedule = $config['version_check_schedule'] ?? '0 6 * * *';
        $oldHealthSchedule  = $config['health_check_schedule'] ?? '*/30 * * * *';
        $oldAdminTools      = (string)($config['admin_tools_enabled'] ?? '0');
        $oldVoiceEnabled    = (string)($config['voice_enabled'] ?? '0');

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

        // PLUGIN_MANAGEMENT_TOOLS.md: keep the plugin-management MCP server and
        // its projected skill in step with the setting the instant it is saved,
        // so the switch does not wait for the next boot to take effect.
        //
        // Gated on an actual CHANGE, exactly like the two cron schedules above.
        // ensureMcpRegistered() is a cheap no-op when the stored registration
        // already agrees, but saveConfig() is called from far more places than the
        // settings screen, and an unconditional call makes every one of them read
        // the Config Hub store to answer a question nobody asked. InitService still
        // calls it unconditionally at boot, which is where the self-heal belongs.
        $newAdminTools = (string)($config['admin_tools_enabled'] ?? '0');
        if ($newAdminTools !== $oldAdminTools && \class_exists(AdminMcpTools::class)) {
            try {
                AdminMcpTools::ensureMcpRegistered();
            } catch (\Throwable $e) {
                LogService::log("AdminMcpTools::ensureMcpRegistered() failed after config save: " . $e->getMessage(), LogService::LOG_WARN, "ConfigService");
            }
        }

        // VOICE_SWITCHES.md R8: tell every open tab the global switch moved,
        // through the SAME `aicli_voice` channel a spoken clip uses — but
        // never on a no-op save (a memory rule from an earlier incident: a
        // publisher that fires on every save, not only on a real change,
        // storms every open tab). Gated on an actual CHANGE, exactly like the
        // admin_tools_enabled hook above.
        $newVoiceEnabled = (string)($config['voice_enabled'] ?? '0');
        if ($newVoiceEnabled !== $oldVoiceEnabled && \class_exists(VoiceService::class)) {
            try {
                VoiceService::publishState($newVoiceEnabled === '1');
            } catch (\Throwable $e) {
                LogService::log("VoiceService::publishState() failed after config save: " . $e->getMessage(), LogService::LOG_WARN, "ConfigService");
            }
        }

        return true;
    }

    /**
     * Updates the cron job for agent version checking.
     */
    /**
     * A cron schedule that is SAFE to write into /etc/cron.d.
     *
     * 2026-09-09: both cron writers interpolated the stored schedule straight into the
     * file — `"# comment\n$schedule $script &> /dev/null\n"` — with no escaping. A value
     * containing a newline therefore writes an ARBITRARY ROOT CRON LINE. Nothing
     * user-facing could reach it (only the settings form writes the key, and the
     * plugin-management allow-list deliberately excludes both schedule keys for exactly
     * this reason), so this was a sharp edge rather than a live hole. It is still an
     * unvalidated write to /etc/cron.d as root, and it should not survive on trust.
     *
     * Accept only what cron actually needs: five whitespace-separated fields built from
     * digits, * / , - and the step/range syntax, or one of the @-shorthands cron
     * defines. Anything else — a newline above all — is refused and the caller leaves
     * the existing file alone rather than writing something it cannot vouch for.
     *
     * @return string '' when the schedule is unusable, otherwise the trimmed schedule.
     */
    public static function safeCronSchedule(string $schedule): string {
        $schedule = trim($schedule);
        if ($schedule === '') return '';
        // No control characters anywhere — a newline is the whole attack.
        if (preg_match('/[\x00-\x1F\x7F]/', $schedule)) return '';
        $shorthands = ['@reboot','@yearly','@annually','@monthly','@weekly','@daily','@midnight','@hourly'];
        if (in_array(strtolower($schedule), $shorthands, true)) return strtolower($schedule);
        $fields = preg_split('/\s+/', $schedule);
        if (!is_array($fields) || count($fields) !== 5) return '';
        foreach ($fields as $field) {
            if (!preg_match('#^[0-9*/,\-]+$#', $field)) return '';
        }
        return $schedule;
    }

    public static function updateVersionCheckCron(string $schedule): void {
        $cronFile = '/etc/cron.d/unraid-aicliagents.agent-check';
        $script = '/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/agentcheck';

        if ($schedule !== '' && self::safeCronSchedule($schedule) === '') {
            LogService::log('Refused an unsafe version-check cron schedule; the existing schedule is unchanged.', LogService::LOG_ERROR, 'ConfigService');
            return;
        }

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

        if ($schedule !== '' && self::safeCronSchedule($schedule) === '') {
            LogService::log('Refused an unsafe health-check cron schedule; the existing schedule is unchanged.', LogService::LOG_ERROR, 'ConfigService');
            return;
        }

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
        // #168: DETACH + DELAY the nginx reload. During a plugin install/upgrade
        // this method runs inside the Unraid plugin-manager's `plugin … nchan`
        // wrapper, which streams the script output to the browser THROUGH nginx.
        // A synchronous reload severs that stream mid-progress — the dialog
        // freezes and the wrapper can even hang on the dead pipe (zombie child +
        // "operation continues in background" banner). Running it detached, a few
        // seconds later, lets the wrapper finish streaming and reap cleanly first.
        // #337: the shared spawn helper also closes every other inherited fd
        // (the install flock fd 9, the installer's output pipe fd 4).
        UtilityService::spawnDetached(['/bin/bash', '-c', 'sleep 3; /etc/rc.d/rc.nginx reload']);
        LogService::log("Nginx configuration updated; reload scheduled (detached).", LogService::LOG_DEBUG, "ConfigService");
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
    /**
     * Resolve which agent a request is really about, and NEVER guess.
     *
     * Eight call sites used to write `$_GET['agentId'] ?? 'gemini-cli'`. That default
     * is indefensible: a request that forgot to name its agent does not become a Gemini
     * request. Four of those sites LAUNCH a terminal, so a missing parameter started the
     * wrong agent in the user's workspace; saveEnv() WROTE environment variables into
     * another agent's env file. A silently wrong answer is worse than a refusal, so this
     * returns '' when it cannot be certain and the caller reports an error.
     *
     * Order: an explicitly supplied id wins; otherwise the workspace record for this
     * session id; otherwise the workspace record for this path — but ONLY when exactly
     * one workspace matches, because two workspaces may share a path with different
     * agents. An ambiguous path refuses, the same way uniqueIdentityMatch() refuses to
     * guess a Relay owner. Requested 2026-09-09.
     */
    public static function resolveAgentId($requested, string $sessionId = '', string $path = ''): string {
        $requested = is_string($requested) ? trim($requested) : '';
        if ($requested !== '') return $requested;

        $sessions = self::getWorkspaces()['sessions'] ?? [];
        $sessionId = trim($sessionId);
        if ($sessionId !== '') {
            foreach ($sessions as $w) {
                if (is_array($w) && (string)($w['id'] ?? '') === $sessionId) {
                    $found = trim((string)($w['agentId'] ?? ''));
                    if ($found !== '') return $found;
                }
            }
        }

        $path = trim($path);
        if ($path !== '') {
            $match = '';
            foreach ($sessions as $w) {
                if (!is_array($w) || (string)($w['path'] ?? '') !== $path) continue;
                $candidate = trim((string)($w['agentId'] ?? ''));
                if ($candidate === '') continue;
                if ($match !== '' && $match !== $candidate) return '';   // ambiguous — refuse
                $match = $candidate;
            }
            if ($match !== '') return $match;
        }
        return '';
    }

    /**
     * The single user-facing answer when resolveAgentId() gave up. Logged at ERROR
     * because it means a caller sent an incomplete request — that is a fault to fix,
     * not a routine condition — and worded so the user knows what to do next.
     */
    public static function agentIdUnresolvedError(string $action, string $sessionId = '', string $path = ''): array {
        LogService::log(
            "Could not resolve the agent for '$action' (session=" . ($sessionId !== '' ? $sessionId : 'none')
            . " path=" . ($path !== '' ? $path : 'none') . "); refused rather than defaulting to another agent.",
            LogService::LOG_ERROR,
            'ConfigService'
        );
        return ['status' => 'error', 'message' =>
            'Sorry — the plugin cannot tell which agent this workspace uses, so it stopped '
            . 'instead of starting the wrong one. Please close this workspace and open it '
            . 'again from the drawer. The log records what went wrong.'];
    }

    public static function getWorkspaces() {
        $file = self::getUserStatePath() . "/workspaces.json";

        $workspaces = file_exists($file)
            ? (json_decode(file_get_contents($file), true) ?: ['sessions' => [], 'activeId' => null])
            : ['sessions' => [], 'activeId' => null];
        return self::mergeRelayManagedWorkspaces($workspaces);
    }

    /**
     * Relay topic actors are server-managed workspaces.  The browser drawer is
     * a view of the registry, not its authority: an actor must remain available
     * for headless boot/recovery even if a user closes every visible tab.
     */
    private static function relayActorIds(): array {
        $file = self::getUserStatePath() . '/relay/actors.json';
        $data = is_file($file) ? json_decode((string)@file_get_contents($file), true) : [];
        $ids = [];
        foreach (($data['actors'] ?? []) as $actor) {
            if (is_array($actor) && !empty($actor['paused'])) continue;
            $id = is_array($actor) ? (string)($actor['session_id'] ?? '') : (string)$actor;
            if ($id !== '') $ids[$id] = true;
        }
        return $ids;
    }

    private static function relayManagedWorkspaceFile(): string {
        return self::getUserStatePath() . '/relay/managed_workspaces.json';
    }

    /** Persist the full descriptor at actor assignment time, before any UI can remove it. */
    public static function rememberRelayManagedWorkspace(array $workspace): bool {
        $id = (string)($workspace['id'] ?? '');
        $path = (string)($workspace['path'] ?? '');
        $agentId = (string)($workspace['agentId'] ?? '');
        if ($id === '' || $path === '' || $agentId === '') return false;
        $file = self::relayManagedWorkspaceFile();
        $data = is_file($file) ? json_decode((string)@file_get_contents($file), true) : [];
        if (!is_array($data)) $data = [];
        $records = is_array($data['workspaces'] ?? null) ? $data['workspaces'] : [];
        $records[$id] = $workspace;
        return AtomicWriteService::writeJson($file, ['schema' => 1, 'workspaces' => $records]);
    }

    private static function mergeRelayManagedWorkspaces(array $workspaces): array {
        $actorIds = self::relayActorIds();
        if ($actorIds === []) return $workspaces;
        $file = self::relayManagedWorkspaceFile();
        $data = is_file($file) ? json_decode((string)@file_get_contents($file), true) : [];
        $records = is_array($data['workspaces'] ?? null) ? $data['workspaces'] : [];
        $sessions = [];
        foreach (($workspaces['sessions'] ?? []) as $session) {
            if (is_array($session) && !empty($session['id'])) $sessions[(string)$session['id']] = $session;
        }
        foreach ($actorIds as $id => $_) {
            if (!isset($sessions[$id]) && isset($records[$id]) && is_array($records[$id])) $sessions[$id] = $records[$id];
        }
        $workspaces['sessions'] = array_values($sessions);
        if (($workspaces['activeId'] ?? null) !== null && !isset($sessions[(string)$workspaces['activeId']])) {
            $workspaces['activeId'] = array_key_first($sessions) ?: null;
        }
        return $workspaces;
    }

    /** All retained topic-owner (managed) workspace descriptors, keyed by id. */
    public static function getManagedWorkspaces(): array {
        $file = self::relayManagedWorkspaceFile();
        $data = is_file($file) ? json_decode((string)@file_get_contents($file), true) : [];
        return is_array($data['workspaces'] ?? null) ? $data['workspaces'] : [];
    }

    /** The retained descriptor for one managed workspace, or null if never recorded. */
    public static function getManagedWorkspace(string $id): ?array {
        $rec = self::getManagedWorkspaces()[$id] ?? null;
        return is_array($rec) && $rec !== [] ? $rec : null;
    }

    /** Drop one retained managed-workspace snapshot (e.g. after an identity re-adopt). No-op if absent. */
    public static function forgetManagedWorkspace(string $id): bool {
        $file = self::relayManagedWorkspaceFile();
        $data = is_file($file) ? json_decode((string)@file_get_contents($file), true) : [];
        if (!is_array($data)) $data = [];
        $records = is_array($data['workspaces'] ?? null) ? $data['workspaces'] : [];
        if (!array_key_exists($id, $records)) return true;
        unset($records[$id]);
        return AtomicWriteService::writeJson($file, ['schema' => 1, 'workspaces' => $records]);
    }

    /**
     * Pure decision: return the drawer with a managed workspace re-added from its
     * retained snapshot. Idempotent — a workspace already present (or one with no
     * snapshot) is returned unchanged. Extracted so recovery is testable without
     * touching the real state path. Returns [workspaces, restored].
     *
     * @return array{0: array, 1: bool}
     */
    public static function withManagedWorkspaceRestored(array $managed, array $workspaces, string $id): array {
        $rec = is_array($managed[$id] ?? null) && $managed[$id] !== [] ? $managed[$id] : null;
        if ($rec === null) return [$workspaces, false];
        foreach (($workspaces['sessions'] ?? []) as $s) {
            if (is_array($s) && (string)($s['id'] ?? '') === $id) return [$workspaces, false];
        }
        $workspaces['sessions'][] = $rec;
        return [$workspaces, true];
    }

    /**
     * Bring a closed topic-owner workspace back into the visible drawer from its
     * retained snapshot, so "Start now" can relaunch it as a real tab (#127).
     * Returns the descriptor (restored or already present), or null if no snapshot.
     */
    public static function restoreManagedWorkspaceToDrawer(string $id): ?array {
        $rec = self::getManagedWorkspace($id);
        if ($rec === null) return null;
        $file = self::getUserStatePath() . '/workspaces.json';
        $ws = file_exists($file)
            ? (json_decode((string)file_get_contents($file), true) ?: ['sessions' => [], 'activeId' => null])
            : ['sessions' => [], 'activeId' => null];
        [$ws, $restored] = self::withManagedWorkspaceRestored([$id => $rec], $ws, $id);
        if ($restored) self::saveWorkspaces($ws);
        return $rec;
    }

    /**
     * Saves the list of workspaces (sessions).
     *
     * WORKSPACE_LIFECYCLE_EVENTS.md R2: after a write actually lands, this
     * publishes created/updated/removed on the `workspaces` channel from the
     * diff between the registry before this call and the merged result — the
     * publisher lives with the writer, so every caller (the drawer's save,
     * AdminService, boot resurrection, import) announces the same way. Pass
     * $publish=false only for a caller that must stay silent (none exist
     * today); a resave with no on-disk change publishes nothing regardless.
     */
    public static function saveWorkspaces($data, array $removedIds = [], bool $publish = true) {
        self::$lastWorkspaceSaveMessage = null;
        $before = self::getWorkspaces();
        $data = self::mergeWorkspaceSnapshot($before, $data, $removedIds);
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
            $failure = AtomicWriteService::lastFailure();
            $detail = $failure ? " stage={$failure['stage']} warning={$failure['warning']}" : '';
            LogService::log("saveWorkspaces FAILED: count=$count path=$file$detail", LogService::LOG_ERROR, "ConfigService");
            if (AtomicWriteService::lastFailureIsStaleHandle()) {
                $user = self::getConfig()['user'] ?? 'root';
                if (!is_string($user) || $user === '') $user = 'root';
                StorageMountService::markHomeWriteFault($user, $failure ?? []);
                // The mount arbiter performs a real remount only when the home
                // is idle. A deferred result means an agent is still using it,
                // so do not retry against the same known-bad overlay.
                $repair = FileStorage::ensureReady("home/$user");
                if ($repair->ok && !$repair->deferred && AtomicWriteService::writeJson($file, $data)) {
                    LogService::log("saveWorkspaces recovered after safe overlay refresh: count=$count path=$file", LogService::LOG_INFO, "ConfigService");
                    if ($publish) self::publishWorkspaceDiff($before['sessions'] ?? [], $data['sessions'] ?? [], $removedIds);
                    return true;
                }
                self::$lastWorkspaceSaveMessage = 'Workspace state could not be saved because its storage needs repair. Close active agent sessions, then retry; repair is queued automatically.';
            }
            return false;
        }
        LogService::log("saveWorkspaces ok: count=$count path=$file", LogService::LOG_INFO, "ConfigService");
        if ($publish) self::publishWorkspaceDiff($before['sessions'] ?? [], $data['sessions'] ?? [], $removedIds);
        return true;
    }

    /** User-safe explanation for the current request's failed workspace save. */
    public static function lastWorkspaceSaveMessage(): ?string {
        return self::$lastWorkspaceSaveMessage;
    }

    /**
     * WORKSPACE_LIFECYCLE_EVENTS.md R2: runtime stamps excluded from the
     * "updated" diff — they change on their own and must never make a save
     * look like a person's edit: the last-active clock, the resumed chat id
     * (rewritten by the agent itself), and the R5 creation audit stamp (set
     * once, never a user edit — mergeWorkspaceSnapshot already preserves it
     * on a resend). changedSnapshotFields() compares every OTHER key present
     * on either record — today that means `name`, `path`, `agentId` and
     * `title` (the fields the drawer/admin tools actually write onto a
     * session record); `args`, `env`, `autoLaunch` and `channel` live in
     * their own per-agent/per-workspace stores today (ArgsService,
     * EnvService, ConfigService::setAgentAutoLaunch, AgentRegistry's channel
     * setter) so they never appear on a record and compare as a no-op — a
     * future record embedding one of them is compared automatically, with no
     * second look at this list. `env` is the one exception: compared by key
     * NAMES only (never the secret values) — see changedSnapshotFields().
     */
    private const VOLATILE_SNAPSHOT_KEYS = ['lastActive', 'created', 'chatSessionId', 'createdBy', 'createdAt'];

    /**
     * WORKSPACE_LIFECYCLE_EVENTS.md R2: diff the registry before a save
     * against the sessions array after the merge, for the created/updated/
     * removed events the writer publishes. Pure — no I/O, no publish.
     *
     * $removedIds is the raw list the CALLER asked to remove this save (not
     * the merge's cumulative tombstone set) — an id no longer present in
     * $beforeSessions (already gone from an earlier save) is dropped here,
     * which is the "an id already tombstoned publishes nothing twice" edge
     * case (WORKSPACE_LIFECYCLE_EVENTS.md Edge Cases).
     *
     * @return array{created: array[], updated: array<string,string[]>, removed: string[]}
     */
    public static function diffWorkspaceSnapshot(array $beforeSessions, array $afterSessions, array $removedIds): array {
        $before = [];
        foreach ($beforeSessions as $s) {
            if (is_array($s) && !empty($s['id'])) $before[(string)$s['id']] = $s;
        }
        $beforeOrder = array_keys($before);

        $after = [];
        foreach ($afterSessions as $s) {
            if (is_array($s) && !empty($s['id'])) $after[(string)$s['id']] = $s;
        }
        $afterOrder = array_keys($after);

        // Order is RELATIVE, among the rows present in both snapshots. A row
        // that was inserted or removed shifts every absolute index after it,
        // and that is not a change to the other rows. Only a row whose
        // position among the common rows moved carries `order`.
        $commonBefore = array_values(array_filter($beforeOrder, static fn($id) => isset($after[$id])));
        $commonAfter  = array_values(array_filter($afterOrder,  static fn($id) => isset($before[$id])));
        $posBefore = array_flip($commonBefore);
        $posAfter  = array_flip($commonAfter);

        $created = [];
        $updated = [];
        foreach ($after as $id => $record) {
            if (!isset($before[$id])) {
                $created[] = $record;
                continue;
            }
            $fields = self::changedSnapshotFields($before[$id], $record);
            if (($posBefore[$id] ?? null) !== ($posAfter[$id] ?? null)) {
                $fields[] = 'order';
            }
            if ($fields !== []) $updated[$id] = $fields;
        }

        $removed = [];
        foreach (array_values(array_filter($removedIds, 'is_string')) as $id) {
            if (isset($before[$id])) $removed[] = $id;
        }

        return ['created' => $created, 'updated' => $updated, 'removed' => $removed];
    }

    /**
     * The fields that differ between two session records — every key present
     * on either side EXCEPT `id` (the identity we already matched them by)
     * and VOLATILE_SNAPSHOT_KEYS. Sorted so the reported `fields` list is
     * deterministic regardless of key insertion order.
     */
    private static function changedSnapshotFields(array $prior, array $next): array {
        $keys = array_unique(array_merge(array_keys($prior), array_keys($next)));
        $changed = [];
        foreach ($keys as $key) {
            if (!is_string($key) || $key === 'id') continue;
            if (in_array($key, self::VOLATILE_SNAPSHOT_KEYS, true)) continue;
            if ($key === 'env') {
                $a = array_keys(is_array($prior['env'] ?? null) ? $prior['env'] : []);
                $b = array_keys(is_array($next['env'] ?? null) ? $next['env'] : []);
                sort($a);
                sort($b);
                if ($a !== $b) $changed[] = 'env';
                continue;
            }
            if (($prior[$key] ?? null) !== ($next[$key] ?? null)) $changed[] = $key;
        }
        sort($changed);
        return $changed;
    }

    /**
     * WORKSPACE_LIFECYCLE_EVENTS.md R2: publish created/updated/removed on
     * the `workspaces` channel from the diff, AFTER the write this call
     * describes has already landed on disk. Best-effort — a publish failure
     * must never surface as a save failure; EventBus::publish() itself
     * never throws.
     */
    private static function publishWorkspaceDiff(array $beforeSessions, array $afterSessions, array $removedIds): void {
        if (!class_exists('\\AICliAgents\\Services\\EventBus')) return;
        $diff = self::diffWorkspaceSnapshot($beforeSessions, $afterSessions, $removedIds);
        foreach ($diff['created'] as $record) {
            EventBus::publish('workspace', [], [
                'event'     => 'created',
                'id'        => (string)($record['id'] ?? ''),
                'agentId'   => (string)($record['agentId'] ?? ''),
                'name'      => (string)($record['name'] ?? ''),
                'path'      => (string)($record['path'] ?? ''),
                'createdBy' => (string)($record['createdBy'] ?? 'human'),
            ]);
        }
        foreach ($diff['updated'] as $id => $fields) {
            EventBus::publish('workspace', [], ['event' => 'updated', 'id' => $id, 'fields' => $fields]);
        }
        foreach ($diff['removed'] as $id) {
            EventBus::publish('workspace', [], ['event' => 'removed', 'id' => $id]);
        }
    }

    /**
     * Merge a browser snapshot into the server registry. Omission is not a
     * deletion: another tab may have created that workspace after this tab
     * loaded. Only an explicit removed id may delete a server-side row.
     */
    public static function mergeWorkspaceSnapshot(array $existing, array $incoming, array $removedIds = []): array {
        // A close and an older browser save can cross on the wire.  Omission
        // alone must not delete a workspace, but an explicit close must also
        // not be undone by that older save arriving afterwards.  Retain a
        // bounded tombstone set in the registry for 24 hours; ids are
        // generated uniquely, so this only rejects stale copies of the row.
        // Lengthened from 1 hour (WORKSPACE_LIFECYCLE_EVENTS.md "Tombstones"):
        // once `removed` is pushed and a device has reconciled, it never
        // resends the row, so a stale save surviving this long is a bug
        // worth seeing rather than a normal race to tolerate.
        $now = time();
        $tombstones = [];
        foreach (($existing['deletedIds'] ?? []) as $id => $deletedAt) {
            if (is_string($id) && is_int($deletedAt) && $deletedAt > ($now - 86400)) {
                $tombstones[$id] = $deletedAt;
            }
        }
        foreach (array_values(array_filter($removedIds, 'is_string')) as $id) {
            $tombstones[$id] = $now;
        }
        // Keep the on-disk registry bounded even if a client creates and closes
        // many workspaces during the tombstone hour.
        if (count($tombstones) > 256) {
            arsort($tombstones, SORT_NUMERIC);
            $tombstones = array_slice($tombstones, 0, 256, true);
        }
        $removed = array_fill_keys(array_keys($tombstones), true);
        // A Relay topic actor is an operational service, not an ordinary drawer
        // tab. Its definition is kept server-side and cannot be deleted by a
        // stale browser snapshot or the close-workspace button.
        foreach (self::relayActorIds() as $id => $_) unset($removed[$id]);

        // WORKSPACE_LIFECYCLE_EVENTS.md R5: every workspace record carries
        // createdBy/createdAt. Indexed once so the incoming loop below can tell
        // "an existing record the drawer resent" (preserve its original stamp)
        // from "a genuinely new id" (stamp it now) without a second scan.
        $existingById = [];
        foreach (($existing['sessions'] ?? []) as $s) {
            if (is_array($s) && !empty($s['id'])) $existingById[(string)$s['id']] = $s;
        }

        $sessions = [];
        foreach (($incoming['sessions'] ?? []) as $session) {
            if (!is_array($session) || empty($session['id']) || isset($removed[$session['id']])) continue;
            $id = (string)$session['id'];
            $prior = $existingById[$id] ?? null;
            if ($prior !== null) {
                // An existing record the drawer resent without these fields
                // (older browser state) keeps its original stamp — explicit
                // values on $session still win, they are never overwritten.
                if (!array_key_exists('createdBy', $session) && array_key_exists('createdBy', $prior)) {
                    $session['createdBy'] = $prior['createdBy'];
                }
                if (!array_key_exists('createdAt', $session) && array_key_exists('createdAt', $prior)) {
                    $session['createdAt'] = $prior['createdAt'];
                }
                // VOICE_MAIL.md R14: a page loaded before the spoken name existed
                // resends the record without it. Keep the stored value; a clear
                // sends the key with ''.
                if (!array_key_exists('spoken_name', $session) && array_key_exists('spoken_name', $prior)) {
                    $session['spoken_name'] = $prior['spoken_name'];
                }
                // AGENT_VOICE.md R14: the same rule for the workspace's own voice.
                if (!array_key_exists('tts_voice', $session) && array_key_exists('tts_voice', $prior)) {
                    $session['tts_voice'] = $prior['tts_voice'];
                }
            } else {
                // A genuinely new workspace — stamp it now if the caller did not.
                if (!array_key_exists('createdAt', $session)) $session['createdAt'] = $now;
                if (!array_key_exists('createdBy', $session)) $session['createdBy'] = self::currentActorSessionId();
            }
            $sessions[$id] = $session;
        }
        foreach (($existing['sessions'] ?? []) as $session) {
            if (!is_array($session) || empty($session['id'])) continue;
            $id = (string)$session['id'];
            if (!isset($removed[$id]) && !isset($sessions[$id])) $sessions[$id] = $session;
        }
        // Order rule (WORKSPACE_LIFECYCLE_EVENTS.md, Edge Cases): a partial
        // save, one record from a tool or a service, never moves a row. Only a
        // full list, the drawer's own reorder, decides the order. A new id from
        // a partial save goes to the end, where the drawer would put it. Without
        // this rule a single-record save pulled that row to the front, the next
        // full-list save from a tab moved it back, and both published `order`.
        $incomingIds = [];
        foreach (($incoming['sessions'] ?? []) as $s) {
            if (is_array($s) && !empty($s['id'])) $incomingIds[(string)$s['id']] = true;
        }
        $liveExisting = [];
        foreach (array_keys($existingById) as $id) {
            if (!isset($removed[$id])) $liveExisting[] = $id;
        }
        $isFullList = true;
        foreach ($liveExisting as $id) {
            if (!isset($incomingIds[$id])) { $isFullList = false; break; }
        }
        if (!$isFullList) {
            $ordered = [];
            foreach ($liveExisting as $id) {
                if (isset($sessions[$id])) $ordered[$id] = $sessions[$id];
            }
            foreach ($sessions as $id => $s) {
                if (!isset($ordered[$id])) $ordered[$id] = $s;
            }
            $sessions = $ordered;
        }
        $incoming['sessions'] = array_values($sessions);
        if ($tombstones !== []) $incoming['deletedIds'] = $tombstones;
        else unset($incoming['deletedIds']);
        if (!array_key_exists('activeId', $incoming)) $incoming['activeId'] = $existing['activeId'] ?? null;
        if ($incoming['activeId'] !== null && !isset($sessions[(string)$incoming['activeId']])) {
            $incoming['activeId'] = $existing['activeId'] ?? (array_key_first($sessions) ?: null);
        }
        return $incoming;
    }

    /**
     * Who created a brand-new workspace record, for WORKSPACE_LIFECYCLE_EVENTS.md
     * R5's `createdBy` field: the agent session behind AICLI_SESSION_ID, or
     * 'human' when there is none (a browser save, or a call with no session).
     * Guarded with class_exists so a unit test that loads ConfigService alone
     * (without EventActor) still gets a sane default instead of a fatal.
     */
    private static function currentActorSessionId(): string {
        if (!class_exists('\\AICliAgents\\Services\\EventActor')) return 'human';
        try {
            $actor = \AICliAgents\Services\EventActor::current();
        } catch (\Throwable $e) {
            return 'human';
        }
        if (($actor['type'] ?? '') === 'agent' && !empty($actor['sessionId'])) {
            return (string)$actor['sessionId'];
        }
        return 'human';
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
    /**
     * Human name of a workspace by session id: the live drawer first, then the
     * retained managed snapshot (a workspace closed in the browser but still
     * running keeps its name there). '' when neither knows it — callers pick their
     * own fallback, and must never print a raw id like "sc8ymt" to an operator.
     * One copy, used by the Activity tray's start rows and waiting-message pills.
     */
    public static function workspaceName(string $sessionId): string {
        foreach (self::getWorkspaces()['sessions'] ?? [] as $w) {
            if ((string)($w['id'] ?? '') === $sessionId) {
                $n = trim((string)($w['name'] ?? ''));
                if ($n !== '') return $n;
            }
        }
        $managed = self::getManagedWorkspace($sessionId);
        return is_array($managed) ? trim((string)($managed['name'] ?? '')) : '';
    }

    private static function getResumeFilePath($path, $agentId) {
        $hash = md5($path . $agentId);
        return self::getUserStatePath() . "/resumes/resume_$hash.json";
    }

    // -----------------------------------------------------------------------
    // Resume identity: a conversation belongs to ONE workspace
    // (docs/specs/RESUME_IDENTITY_PER_WORKSPACE.md)
    //
    // Two workspaces may be open on the same folder with the same agent. The old
    // single slot, keyed md5(path . agentId), made them one identity: whichever
    // closed last owned it, and the other resumed that conversation. Now each
    // workspace has its own record, and the per-folder slot survives only as a
    // "last conversation closed in this folder" POINTER that records its owner —
    // it still lets a NEW workspace, created with resume ticked, pick up where the
    // folder left off, but never hands out a conversation an open sibling owns.
    // -----------------------------------------------------------------------

    /** The per-WORKSPACE record: {chat_id, path, agent, saved_at}. */
    private static function getSessionResumeFilePath(string $sessionId): string {
        $sid = preg_replace('/[^A-Za-z0-9_-]/', '', $sessionId);
        return self::getUserStatePath() . "/resumes/session_$sid.json";
    }

    private static function readResumeJson(string $file): ?array {
        if (!is_file($file)) return null;
        $data = json_decode((string)@file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    /**
     * Ids of the OPEN drawer workspaces on this folder with this agent. Closing a
     * workspace removes it from the drawer, which is exactly what frees its
     * conversation for the next workspace on the folder.
     *
     * @return list<string>
     */
    public static function openWorkspaceIdsFor(string $path, string $agentId): array {
        $want = rtrim($path, '/');
        $out = [];
        foreach (self::getWorkspaces()['sessions'] ?? [] as $w) {
            if (rtrim((string)($w['path'] ?? ''), '/') !== $want) continue;
            if ((string)($w['agentId'] ?? '') !== $agentId) continue;
            $id = (string)($w['id'] ?? '');
            if ($id !== '') $out[] = $id;
        }
        return $out;
    }

    /** Is another OPEN workspace (not $sessionId) on this same folder with this same agent? */
    public static function isSharedFolder(string $path, string $agentId, string $sessionId): bool {
        foreach (self::openWorkspaceIdsFor($path, $agentId) as $id) {
            if ($id !== $sessionId) return true;
        }
        return false;
    }

    /**
     * The chat id a workspace was LAUNCHED with, read from its run script. Used only
     * as EVIDENCE to attribute a legacy (ownerless) folder slot on a shared folder —
     * never as a live identity: Claude forks a conversation on resume, so this is
     * where a workspace started, not where it is now. '' when unknown or fresh.
     */
    public static function launchedChatId(string $sessionId): string {
        $sid = preg_replace('/[^A-Za-z0-9_-]/', '', $sessionId);
        if ($sid === '') return '';
        $user = (self::getConfig()['user'] ?? 'root') ?: 'root';
        $workDir = class_exists('\AICliAgents\Services\UtilityService')
            ? \AICliAgents\Services\UtilityService::getWorkDir($user)
            : "/tmp/unraid-aicliagents/work/$user";
        $script = "$workDir/aicli-run-$sid.sh";
        if (!is_file($script)) return '';
        if (!preg_match('/^export AICLI_CHAT_SESSION_ID=(\S*)$/m', (string)@file_get_contents($script), $m)) return '';
        $chat = trim($m[1], "'\"");
        return ($chat !== '_fresh_' && preg_match('/^[A-Za-z0-9_-]{1,128}$/', $chat)) ? $chat : '';
    }

    /**
     * PURE — which conversation may THIS workspace resume? Unit-tested without files.
     *
     * @param array|null           $session   the workspace's own record {chat_id, path, agent}
     * @param array|null           $pointer   the folder pointer {chat_id, owner?}
     * @param string               $sessionId '' for a workspace that does not exist yet
     *                                        (the new-workspace dialog)
     * @param list<string>         $openIds   OPEN drawer workspaces on this folder + agent
     * @param array<string,string> $launched  session id => chat id it launched with (evidence)
     * @return array{chat:?string,reason:string}
     */
    public static function resumeDecision(?array $session, ?array $pointer, string $path, string $agentId,
                                          string $sessionId, array $openIds, array $launched): array {
        // 1. Its own record wins — but only for the folder and agent it was written for.
        if ($session !== null) {
            $own = (string)($session['chat_id'] ?? '');
            if ($own !== '' && rtrim((string)($session['path'] ?? ''), '/') === rtrim($path, '/')
                && (string)($session['agent'] ?? '') === $agentId) {
                return ['chat' => $own, 'reason' => 'own_record'];
            }
        }
        $chat = (string)($pointer['chat_id'] ?? '');
        if ($chat === '') return ['chat' => null, 'reason' => 'none'];
        $owner  = (string)($pointer['owner'] ?? '');
        $others = array_values(array_filter($openIds, static fn($id) => $id !== $sessionId));

        // 2. The folder pointer names its owner.
        if ($owner !== '') {
            if ($owner === $sessionId)              return ['chat' => $chat, 'reason' => 'own_pointer'];
            if (in_array($owner, $openIds, true))   return ['chat' => null,  'reason' => "owned_by_open:$owner"];
            return ['chat' => $chat, 'reason' => 'owner_closed'];
        }

        // 3. A legacy slot records no owner. Attribute it by launch evidence, and never
        //    to two workspaces: a sibling that launched with it keeps it.
        foreach ($others as $id) {
            if (($launched[$id] ?? '') === $chat)   return ['chat' => null,  'reason' => "evidence_names:$id"];
        }
        if ($sessionId !== '' && ($launched[$sessionId] ?? '') === $chat) {
            return ['chat' => $chat, 'reason' => 'evidence_names_self'];
        }
        if ($others !== [])                         return ['chat' => null,  'reason' => 'shared_no_evidence'];
        return ['chat' => $chat, 'reason' => 'legacy_unshared'];
    }

    /**
     * The conversation this workspace should resume, or null. $sessionId is the
     * workspace; omit it only where no workspace exists yet (the new-workspace dialog).
     */
    public static function getResumeId($path, $agentId, ?string $sessionId = null) {
        $path = (string)$path; $agentId = (string)$agentId; $sid = (string)($sessionId ?? '');
        $session = $sid !== '' ? self::readResumeJson(self::getSessionResumeFilePath($sid)) : null;
        $pointer = self::readResumeJson(self::getResumeFilePath($path, $agentId));
        $open    = self::openWorkspaceIdsFor($path, $agentId);
        // Evidence is read only for an ownerless slot on a folder other workspaces share.
        $launched = [];
        if ($pointer !== null && (string)($pointer['owner'] ?? '') === '' && array_diff($open, [$sid]) !== []) {
            foreach (array_unique(array_merge($open, $sid !== '' ? [$sid] : [])) as $id) {
                $launched[$id] = self::launchedChatId($id);
            }
        }
        $d = self::resumeDecision($session, $pointer, $path, $agentId, $sid, $open, $launched);
        if ($d['chat'] === null && $d['reason'] !== 'none') {
            LogService::log("Resume withheld for " . ($sid !== '' ? $sid : 'a new workspace')
                . " at $path ($agentId): {$d['reason']}", LogService::LOG_INFO, 'ConfigService');
        }
        return $d['chat'];
    }

    /**
     * Record a conversation for a workspace. The folder pointer is always updated
     * (it serves the NEXT workspace on this folder) and names its owner.
     */
    public static function saveResumeId($path, $agentId, $chatId, ?string $sessionId = null) {
        $sid = (string)($sessionId ?? '');
        $now = time();
        // CLAUDE_RESUME_BY_SESSION_ID.md R1/R4: a named Claude session's exit line gives
        // its NAME, which is not unique. Every save path comes through here, so resolve
        // it to the conversation's id once, before anything is written.
        if ((string)$agentId === ClaudeSessionNameResolver::AGENT_ID && is_string($chatId)
            && !ClaudeSessionNameResolver::isSessionId($chatId)) {
            $previous = $sid !== '' ? self::readResumeJson(self::getSessionResumeFilePath($sid)) : null;
            $chatId = ClaudeSessionNameResolver::resolve((string)$agentId, (string)$path, $chatId,
                is_array($previous) ? (string)($previous['chat_id'] ?? '') : null,
                $sid !== '' && self::isSharedFolder((string)$path, (string)$agentId, $sid));
        }
        $ok = AtomicWriteService::writeJson(self::getResumeFilePath($path, $agentId),
            ['chat_id' => $chatId, 'saved_at' => $now, 'owner' => $sid]);
        if ($sid !== '') {
            $ok = AtomicWriteService::writeJson(self::getSessionResumeFilePath($sid),
                ['chat_id' => $chatId, 'path' => (string)$path, 'agent' => (string)$agentId, 'saved_at' => $now]) && $ok;
        }
        return $ok;
    }

    /**
     * Record a conversation id that came from DISK — the newest in this folder's
     * store — rather than from the workspace's own screen. On a folder another open
     * workspace shares, "newest on disk" is as likely the sibling's conversation as
     * this one's, so it is NOT saved: the workspace keeps its last good record. A
     * stale conversation that is its own beats a fresh one that is someone else's.
     *
     * @return bool true when saved
     */
    public static function saveDiskFallbackResumeId($path, $agentId, $chatId, ?string $sessionId = null): bool {
        $sid = (string)($sessionId ?? '');
        if ($sid !== '' && self::isSharedFolder((string)$path, (string)$agentId, $sid)) {
            LogService::log("Resume not saved for $sid at $path ($agentId): the newest conversation on disk cannot be"
                . " attributed on a folder another open workspace shares", LogService::LOG_INFO, 'ConfigService');
            return false;
        }
        return (bool)self::saveResumeId($path, $agentId, $chatId, $sessionId);
    }

    /** "Start as new": drop this workspace's record, and the folder pointer only if it is ours to drop. */
    public static function clearResumeId($path, $agentId, ?string $sessionId = null) {
        $sid = (string)($sessionId ?? '');
        if ($sid !== '') @unlink(self::getSessionResumeFilePath($sid));
        $file    = self::getResumeFilePath($path, $agentId);
        $pointer = self::readResumeJson($file);
        if ($pointer === null) return true;
        $owner = (string)($pointer['owner'] ?? '');
        $ours  = $owner === $sid
            || ($owner === '' && ($sid === '' || !self::isSharedFolder((string)$path, (string)$agentId, $sid)));
        return $ours ? @unlink($file) : true;
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

    /** SCHEDULED_CONTINUE.md (#234): per-workspace scheduled-continue sidecar,
     *  keyed by session id, mirroring the auto-launch sidecar so a stale browser
     *  workspaces.json save can never clobber a server-set schedule. */
    private static function getScheduledContinueFilePath(): string
    {
        return self::getUserStatePath() . "/scheduled_continue.json";
    }

    /** @return array<string,array{at:int,repeat:string,message?:string,source?:string,created_at?:int,last_fired_at?:int}> */
    public static function getScheduledContinueMap(): array
    {
        $file = self::getScheduledContinueFilePath();
        if (!file_exists($file)) return [];
        $data = json_decode((string)file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    /** The schedule for one workspace, or null when none is set. */
    public static function getScheduledContinue(string $sessionId): ?array
    {
        $entry = self::getScheduledContinueMap()[$sessionId] ?? null;
        return is_array($entry) ? $entry : null;
    }

    /** Upsert one workspace's schedule. Persists the whole map atomically. */
    public static function setScheduledContinue(string $sessionId, array $entry): bool
    {
        if ($sessionId === '') return false;
        $map = self::getScheduledContinueMap();
        $map[$sessionId] = $entry;
        return AtomicWriteService::writeJson(self::getScheduledContinueFilePath(), $map);
    }

    /** Remove one workspace's schedule. No-op if absent. */
    public static function clearScheduledContinue(string $sessionId): void
    {
        if ($sessionId === '') return;
        $map = self::getScheduledContinueMap();
        if (!array_key_exists($sessionId, $map)) return;
        unset($map[$sessionId]);
        AtomicWriteService::writeJson(self::getScheduledContinueFilePath(), $map);
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
        // #86: saved drawer workspaces are restart-enabled by default. An entry
        // only exists when the user has made an explicit choice, so a stored
        // false remains the opt-out while an absent row means ON.
        if (!is_array($entry)) return ['autoLaunch' => true, 'freshIfNoResume' => true];
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
                $legacyFile = self::getAutoLaunchFilePath($path, $agentId);
                // Missing legacy files meant "no choice made", which now
                // inherits the default-on policy. A present false file was an
                // explicit user opt-out and must remain false after migration.
                if (!file_exists($legacyFile)) continue;
                $ws = self::getAutoLaunch($path, $agentId);
                if (!array_key_exists($agentId, $agg)) {
                    $agg[$agentId] = ['autoLaunch' => false, 'freshIfNoResume' => false];
                }
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
