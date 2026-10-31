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

require_once __DIR__ . '/HomeCacheService.php';
require_once __DIR__ . '/AgentTempService.php';
require_once __DIR__ . '/StorageBackendPolicyService.php';
require_once __DIR__ . '/HumanIdentity.php';

class ConfigService {
    private static ?string $lastWorkspaceSaveMessage = null;

    /**
     * The plugin config file. Production: flash root plus unraid-aicliagents.cfg. The env AICLI_FLASH_ROOT redirects the
     * flash folder (SecretPaths::flashRoot); tests/bootstrap.php points it at a temp folder,
     * so a test never reads or writes the real file (#646, F19).
     */
    public static function configPath(): string {
        self::refuseLiveStateInTests();
        return SecretPaths::flashRoot() . '/unraid-aicliagents.cfg';
    }

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
            // Issue #471: the Logging Level select showed its first choice (Errors Only) when no level was stored,
            // and the next save then stored 0. LogService already uses 2 (Normal, Info) when nothing is stored.
            'log_level' => '2',
            'home_storage_path' => SecretPaths::flashRoot() . '/persistence',
            'agent_storage_path' => SecretPaths::flashRoot() . '/persistence',
            // GLOBAL_STORAGE_BACKEND_MIGRATION.md: two storage settings, one for homes
            // (storage_backend_homes) and one for agents (storage_backend_agents). Each is
            // 'layering' (Compressed layers) or 'passthrough' (plain folders). getConfig() adds
            // both keys. It reads the old keys storage_backend_mode and keep_agents_compressed
            // as a fallback for one more release. A change runs a migration for that kind only.
            // #456: where a home's writable layer lives under the layering engine:
            // 'zram' (RAM, lost on a crash until saved) or 'disk' (durable, uses
            // space on the persistence drive). Changing it runs UpperPlacementService.
            'storage_upper_mode' => 'zram',
            // BOOT_DISK_SPACE.md R4: 1 = each agent keeps its previous version for rollback;
            // 0 = only the version in use stays (saves space on the boot disk).
            'keep_previous_version' => '1',
            // #461 (RELAY_NOTICE_WORDING.md): the wording of the notices the plugin types into an
            // agent for the Relay: full | short | minimal | custom. The custom text is validated
            // by RelayNoticeWording::validateCustom (an invalid one falls back to short).
            'relay_notice_style' => 'short',
            // RELAY_TOKEN_SAVINGS.md R15: minutes before a second notice may be typed when the inbox is still unread (1..240).
            'relay_notice_resend_minutes' => '10',
            'relay_notice_custom' => '',
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
            // NOTEBOOK_HANG_OBSERVABILITY.md R12: timing traces (perf.log and the "slow:" lines in
            // debug.log) are OFF unless this is '1'. Switch: Debug Console tab, "Performance trace".
            'perf_trace'                        => '0',
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
            // AGENT_SPAWN_WORKSPACE.md: an agent starts a helper workspace (aicli_spawn_workspace and its
            // three companion tools). A person changes these on Settings only: none is on the Tier 2
            // allow-list, so an agent cannot raise its own limits. Off on a fresh install.
            // spawn_max_children: running helpers for each parent. spawn_max_total: running helpers on
            // the box. spawn_max_per_agent: running helpers of one agent kind. spawn_allow_unattended:
            // the launch profile's unattended flags (for example the Codex sandbox bypass) may be used,
            // and only for a worktree that the tool made.
            'spawn_enabled'                        => '0',
            'spawn_max_children'                   => '3',
            'spawn_max_total'                      => '6',
            'spawn_max_per_agent'                  => '2',
            'spawn_allow_unattended'               => '0',
            // AGENT_RUN_ENTRY_POINT.md (#745): a script on the server starts an agent run through
            // `aicli-run`. A person changes these on Settings, Script runs only: none is on the agent allow-list.
            // run_enabled is the master switch (off on a fresh install). run_max_concurrent: runs open at one time.
            // run_daily_budget: runs started in 24 hours. run_cooldown_hours: wait before one key runs again.
            // run_approval_hours: time to approve a run. run_max_minutes: run time limit.
            // run_notify_routine: notify at the start and end of a run.
            'run_enabled'                          => '0',
            'run_max_concurrent'                   => '2',
            'run_daily_budget'                     => '6',
            'run_cooldown_hours'                   => '24',
            'run_approval_hours'                   => '24',
            'run_max_minutes'                      => '60',
            'run_notify_routine'                   => '1',
            // run_temp_root: folder for the temporary run folders of aicli-run --temp ('' = the appdata pool path + /_tmp/aicliagents-runs).
            // run_temp_keep_hours: hours that a temporary run folder stays after its run closed.
            'run_temp_root'                        => '',
            'run_temp_keep_hours'                  => '24',
            // HUMAN_ACTOR_IDENTITY.md: the name shown after the Unraid login in the records of who acted,
            // for example "root (John)". Empty shows the login only. Attribution only, never a right.
            'human_display_name'                   => '',
            // ARTEFACTS.md (Notebook): humans change these on Settings > Notebook
            // only. An agent may not: none of them is on AdminService::SETTINGS_ALLOWLIST.
            // notebook_folder is the folder name in each project root
            // (^[A-Za-z0-9._-]{1,40}$, no leading dot, never 'assets').
            'notebook_enabled'                     => '1',
            // NOTEBOOK_WIKI_PAGE.md R1: show the Wiki as its own Unraid page (Tasks menu). Off by default.
            'wiki_page_enabled'                    => '0',
            'notebook_auto_open'                   => '0',
            'notebook_folder'                      => 'notebook',
            'notebook_allow_prod_secrets'          => '0',
            'notebook_spoken_notice'               => '0',
            // ARTEFACTS_CONTRACT.md section 10: the agent journal (1 = on) and the quiet
            // hours for spoken notices ("HH:MM-HH:MM", may wrap midnight, empty = none).
            'notebook_journal'                     => '0',
            // NOTEBOOK_ANSWER_NOTIFY.md: tell the agent when a person answers in the Notebook (1 = on).
            'notebook_notify_agent'                => '1',
            'notebook_quiet_hours'                 => '',
            // BOOT_DISK_SPACE.md R6: "Folder for moved sessions". Empty, or a folder under
            // /mnt/<pool>. Old conversation sessions move there from the home (Storage tab,
            // Boot disk space, Move old sessions).
            'history_move_path'                    => '',
            // RESPONSIVE_PRIORITY.md: "Keep the terminal responsive" (1 = on). Off by default: a public plugin
            // must not change the priority of Unraid's own programs unless the user asks.
            'responsive_priority'                  => '0',
            // AGENT_TEMP_FOLDER.md (#554): "Agent temporary folder". ram (default) = /tmp as today; pool = the folder
            // agent_tmp_path on a mounted pool or disk; boot = a folder next to the persistence data (internal boot disk only).
            // agent_tmp_warn_mb: the Boot disk space card warns when the agent temporary folders in RAM pass this size.
            'agent_tmp_location'                    => 'ram',
            'agent_tmp_path'                        => '',
            'agent_tmp_warn_mb'                     => '1024',
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
            // VOICE_LIVE_ENGINE.md R1 (#731): a streaming (websocket) engine for live dictation. Empty = off.
            'stt_stream_url'                        => '',
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
            // BOOT_DISK_SPACE.md R5: where the caches of a plain-folder home live.
            // 'ram' (a tmpfs for each home, capped) or 'pool' (a folder on a pool).
            'home_cache_location'                   => 'ram',
            'home_cache_path'                       => '',
            'home_cache_ram_mb'                     => '1024',
        ];

        if (!file_exists(self::configPath())) {
            return self::withStorageKinds($defaults, []);
        }

        $config = @parse_ini_file(self::configPath());
        if ($config === false) {
            return self::withStorageKinds($defaults, []);
        }

        $merged = self::withStorageKinds(array_merge($defaults, $config), $config);

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
     * Add the two storage settings (homes and agents) to a config array. The new keys win.
     * When one is absent the old keys fill it (StorageBackendPolicyService::resolveKindModes).
     * The boot disk is probed only when the old rule needs it, and only once per request.
     *
     * @param array<string,mixed> $merged config with defaults
     * @param array<string,mixed> $raw    the keys of the cfg file (no defaults)
     */
    private static function withStorageKinds(array $merged, array $raw): array {
        static $internalBoot = [];
        $needsBoot = !StorageBackendPolicyService::isMode($raw[StorageBackendPolicyService::KEY_AGENTS] ?? null)
            && StorageBackendPolicyService::normalizeMode($raw['storage_backend_mode'] ?? 'layering') === StorageBackendPolicyService::LAYERING
            && (string)($raw['keep_agents_compressed'] ?? '0') !== '1';
        $internal = false;
        if ($needsBoot) {
            $path = (string)($merged['agent_storage_path'] ?? '');
            if (!array_key_exists($path, $internalBoot)) {
                // The boot disk class does not change while the server runs. Keep the answer in
                // the RAM state folder, so a config that is not saved yet costs one storagectl
                // probe per boot, not one per request (#748).
                $memo = SecretPaths::stateRoot() . '/.agent-boot-class-' . md5($path);
                $known = @file_get_contents($memo);
                if ($known === '1' || $known === '0') {
                    $internalBoot[$path] = $known === '1';
                } else {
                    try {
                        require_once __DIR__ . '/FileStorage.php';
                        $internalBoot[$path] = $path !== '' && (string)(FileStorage::probeTarget($path)['mount_class'] ?? '') === 'boot_internal';
                        @file_put_contents($memo, $internalBoot[$path] ? '1' : '0');
                    } catch (\Throwable $e) {
                        $internalBoot[$path] = false;
                    }
                }
            }
            $internal = $internalBoot[$path];
        }
        $kinds = StorageBackendPolicyService::resolveKindModes($raw, $internal);
        $merged[StorageBackendPolicyService::KEY_HOMES] = $kinds['homes'];
        $merged[StorageBackendPolicyService::KEY_AGENTS] = $kinds['agents'];
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
        if (!file_exists(self::configPath())) return [];
        $config = @parse_ini_file(self::configPath());
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
        return self::writeConfigFile($raw);
    }

    /**
     * The one writer of the plugin .cfg (#636). It escapes with the INI rules
     * (backslash, double quote, dollar), not addslashes, and proves the text reads
     * back before it writes: a value such as "/mnt/user/John's files" keeps its
     * exact bytes on every save. Public so the storage handler uses the same writer.
     * The csrf_token key never reaches the file.
     */
    public static function writeConfigFile(array $config): bool {
        unset($config['csrf_token']);
        $content = ValidationService::buildIni($config, $skipped);
        if ($content === false) {
            LogService::log("Refusing to write the plugin config: a value does not survive the INI round trip.", LogService::LOG_ERROR, "ConfigService");
            return false;
        }
        if (!empty($skipped)) {
            LogService::log("Plugin config: left out keys the INI reader cannot hold: " . implode(', ', $skipped), LogService::LOG_WARN, "ConfigService");
        }
        return AtomicWriteService::write(self::configPath(), $content);
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

        // The two storage settings (homes and agents). A bad value is replaced, never stored.
        // An old caller that sends storage_backend_mode sets the homes setting.
        if (isset($newConfig['storage_backend_mode'])) {
            if (!isset($newConfig[StorageBackendPolicyService::KEY_HOMES])) {
                $newConfig[StorageBackendPolicyService::KEY_HOMES] = $newConfig['storage_backend_mode'];
            }
            unset($newConfig['storage_backend_mode']);
        }
        foreach ([StorageBackendPolicyService::KEY_HOMES, StorageBackendPolicyService::KEY_AGENTS] as $_kindKey) {
            if (isset($newConfig[$_kindKey])) {
                $newConfig[$_kindKey] = StorageBackendPolicyService::normalizeMode($newConfig[$_kindKey]);
            }
        }
        // The old keep_agents_compressed flag is gone. An old caller that sends it sets the agents setting.
        if (isset($newConfig['keep_agents_compressed'])) {
            if (!isset($newConfig[StorageBackendPolicyService::KEY_AGENTS])) {
                $newConfig[StorageBackendPolicyService::KEY_AGENTS] = ((string)$newConfig['keep_agents_compressed'] === '1')
                    ? StorageBackendPolicyService::LAYERING : StorageBackendPolicyService::PASSTHROUGH;
            }
            unset($newConfig['keep_agents_compressed']);
        }

        // BOOT_DISK_SPACE.md R5: the three cache settings. A bad value is replaced, never stored.
        if (array_key_exists('home_cache_location', $newConfig)) {
            $newConfig['home_cache_location'] = HomeCacheService::normaliseLocation($newConfig['home_cache_location']);
        }
        if (array_key_exists('home_cache_ram_mb', $newConfig)) {
            $newConfig['home_cache_ram_mb'] = HomeCacheService::normaliseRamMb($newConfig['home_cache_ram_mb']);
        }
        if (array_key_exists('home_cache_path', $newConfig)) {
            $newConfig['home_cache_path'] = HomeCacheService::normalisePath(
                $newConfig['home_cache_path'], (string)(self::getConfig()['home_cache_path'] ?? ''));
        }
        if (isset($newConfig['storage_upper_mode'])) {
            $newConfig['storage_upper_mode'] = ((string)$newConfig['storage_upper_mode'] === 'disk') ? 'disk' : 'zram';
        }

        // AGENT_TEMP_FOLDER.md R2: the three settings of the agent temporary folder. A refused value is never
        // stored: the stored one stays (UtilityHandler::save tells the person the reason).
        if (array_key_exists('agent_tmp_warn_mb', $newConfig)) {
            $newConfig['agent_tmp_warn_mb'] = AgentTempService::normaliseWarnMb($newConfig['agent_tmp_warn_mb']);
        }
        if (array_key_exists('agent_tmp_location', $newConfig) || array_key_exists('agent_tmp_path', $newConfig)) {
            $cur = self::getConfig();
            $wantLoc = $newConfig['agent_tmp_location'] ?? ($cur['agent_tmp_location'] ?? 'ram');
            $wantPath = $newConfig['agent_tmp_path'] ?? ($cur['agent_tmp_path'] ?? '');
            $chk = AgentTempService::checkSetting($wantLoc, $wantPath);
            if ($chk['ok']) {
                $newConfig['agent_tmp_location'] = $chk['location'];
                if (array_key_exists('agent_tmp_path', $newConfig) || $chk['location'] === 'pool') $newConfig['agent_tmp_path'] = $chk['path'];
            } else {
                unset($newConfig['agent_tmp_location'], $newConfig['agent_tmp_path']);
            }
        }

        // RESPONSIVE_PRIORITY.md: responsive_priority is 0 or 1. Anything else is 0.
        if (array_key_exists('responsive_priority', $newConfig)) {
            $newConfig['responsive_priority'] = ((string)$newConfig['responsive_priority'] === '1') ? '1' : '0';
        }

        // AGENT_SPAWN_WORKSPACE.md: the helper-workspace settings. The two switches are 0 or 1. Each cap is a
        // whole number inside its range; a bad value becomes the default, never a stored odd number.
        foreach (['spawn_enabled', 'spawn_allow_unattended'] as $spawnFlag) {
            if (array_key_exists($spawnFlag, $newConfig)) {
                $newConfig[$spawnFlag] = ((string)$newConfig[$spawnFlag] === '1') ? '1' : '0';
            }
        }
        foreach (['spawn_max_children' => [1, 10, 3], 'spawn_max_total' => [1, 20, 6], 'spawn_max_per_agent' => [1, 10, 2]] as $spawnCap => [$capMin, $capMax, $capDefault]) {
            if (array_key_exists($spawnCap, $newConfig)) {
                $capRaw = $newConfig[$spawnCap];
                $capOk = is_scalar($capRaw) && preg_match('/^\d{1,3}$/D', (string)$capRaw) === 1
                    && (int)$capRaw >= $capMin && (int)$capRaw <= $capMax;
                $newConfig[$spawnCap] = (string)($capOk ? (int)$capRaw : $capDefault);
            }
        }

        // AGENT_RUN_ENTRY_POINT.md: the script-run settings. The two switches are 0 or 1. Each number is inside its
        // range; a bad value becomes the default, never a stored odd number.
        foreach (['run_enabled', 'run_notify_routine'] as $runFlag) {
            if (array_key_exists($runFlag, $newConfig)) {
                $newConfig[$runFlag] = ((string)$newConfig[$runFlag] === '1') ? '1' : '0';
            }
        }
        foreach (['run_max_concurrent' => [1, 10, 2], 'run_daily_budget' => [1, 500, 6], 'run_cooldown_hours' => [0, 720, 24], 'run_approval_hours' => [1, 168, 24], 'run_max_minutes' => [1, 1440, 60], 'run_temp_keep_hours' => [1, 720, 24]] as $runCap => [$runMin, $runMax, $runDefault]) {
            if (array_key_exists($runCap, $newConfig)) {
                $runRaw = $newConfig[$runCap];
                $runOk = is_scalar($runRaw) && preg_match('/^\d{1,4}$/D', (string)$runRaw) === 1
                    && (int)$runRaw >= $runMin && (int)$runRaw <= $runMax;
                $newConfig[$runCap] = (string)($runOk ? (int)$runRaw : $runDefault);
            }
        }

        // AGENT_RUN_ENTRY_POINT.md R19: the root of the temporary run folders is empty (the appdata pool path) or a full path
        // that AgentRunService accepts. A refused value is never stored; it becomes empty.
        if (array_key_exists('run_temp_root', $newConfig)) {
            $tempRoot = is_scalar($newConfig['run_temp_root']) ? rtrim(trim((string)$newConfig['run_temp_root']), '/') : '';
            if ($tempRoot !== '' && class_exists('\\AICliAgents\\Services\\AgentRunService') && AgentRunService::tempRootProblem($tempRoot) !== null) $tempRoot = '';
            $newConfig['run_temp_root'] = $tempRoot;
        }

        // HUMAN_ACTOR_IDENTITY.md R3: the display name is one short line of plain text.
        if (array_key_exists('human_display_name', $newConfig)) {
            $newConfig['human_display_name'] = is_scalar($newConfig['human_display_name'])
                ? HumanIdentity::sanitizeDisplay((string)$newConfig['human_display_name']) : '';
            HumanIdentity::reset();
        }

        // BOOT_DISK_SPACE.md R4: keep_previous_version is 0 or 1.
        if (isset($newConfig['keep_previous_version'])) {
            $newConfig['keep_previous_version'] = ((string)$newConfig['keep_previous_version'] === '0') ? '0' : '1';
        }

        if (isset($newConfig['relay_notice_style'])) {
            $st = (string)$newConfig['relay_notice_style'];
            $newConfig['relay_notice_style'] = in_array($st, \AICliAgents\Services\RelayNoticeWording::STYLES, true) ? $st : 'short';
        }
        if (isset($newConfig['relay_notice_resend_minutes'])) {
            $newConfig['relay_notice_resend_minutes'] = (string)max(1, min(240, (int)$newConfig['relay_notice_resend_minutes']));
        }
        if (isset($newConfig['relay_notice_custom'])) {
            $newConfig['relay_notice_custom'] = trim((string)preg_replace('/[\r\n]+/', ' ', (string)$newConfig['relay_notice_custom']));
            if (strlen($newConfig['relay_notice_custom']) > \AICliAgents\Services\RelayNoticeWording::CUSTOM_MAX) {
                $newConfig['relay_notice_custom'] = substr($newConfig['relay_notice_custom'], 0, \AICliAgents\Services\RelayNoticeWording::CUSTOM_MAX);
            }
        }

        $config = self::getConfig();
        $oldAgentPath = StoragePathResolver::agentPersistPathFromConfig($config);
        $newAgentPath = $newConfig['agent_storage_path'] ?? $oldAgentPath;
        
        // #753: the same rule as the shell (home path, then agent path, then the flash default).
        require_once __DIR__ . '/StoragePathResolver.php';
        $oldHomePath = StoragePathResolver::homePersistPathFromConfig($config);
        $newHomePath = $newConfig['home_storage_path'] ?? $oldHomePath;
        $oldVersionSchedule = $config['version_check_schedule'] ?? '0 6 * * *';
        $oldHealthSchedule  = $config['health_check_schedule'] ?? '*/30 * * * *';
        $oldAdminTools      = (string)($config['admin_tools_enabled'] ?? '0');
        $oldVoiceEnabled    = (string)($config['voice_enabled'] ?? '0');
        $oldNotebookEnabled = (string)($config['notebook_enabled'] ?? '1');
        $oldResponsive      = (string)($config['responsive_priority'] ?? '0');

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

        if (!self::writeConfigFile($config)) {
            LogService::log("Error saving configuration to " . self::configPath(), LogService::LOG_ERROR, "ConfigService");
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

        // RESPONSIVE_PRIORITY.md R4: a real change of the setting applies (on) or resets (off) at once.
        $newResponsive = (string)($config['responsive_priority'] ?? '0');
        if ($newResponsive !== $oldResponsive) {
            self::runResponsivePriority($newResponsive === '1' ? 'apply' : 'reset');
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
        // The same server carries the Notebook tools, so a change of the Notebook switch counts too (#483).
        $notebookSwitchMoved = (string)($config['notebook_enabled'] ?? '1') !== $oldNotebookEnabled;
        if (($newAdminTools !== $oldAdminTools || $notebookSwitchMoved) && \class_exists(AdminMcpTools::class)) {
            try {
                AdminMcpTools::ensureMcpRegistered();
            } catch (\Throwable $e) {
                LogService::log("AdminMcpTools::ensureMcpRegistered() failed after config save: " . $e->getMessage(), LogService::LOG_WARN, "ConfigService");
            }
        }

        // AGENT_INJECTED_GUIDANCE.md R6: the guidance block in each agent's instruction file names the Voice and
        // Notebook features only while they are on, so a real change of one of the three switches writes it again.
        // admin_tools_enabled already re-projects through ensureMcpRegistered(); this covers the other two keys.
        $newNotebookEnabled = (string)($config['notebook_enabled'] ?? '1');
        $guidanceChanged = ($newAdminTools !== $oldAdminTools)
            || ((string)($config['voice_enabled'] ?? '0') !== $oldVoiceEnabled)
            || ($newNotebookEnabled !== $oldNotebookEnabled);
        if ($guidanceChanged && \class_exists(\AICliAgents\Services\Hub\HubProjector::class)) {
            try {
                \AICliAgents\Services\Hub\HubProjector::projectPolicy();
            } catch (\Throwable $e) {
                LogService::log("HubProjector::projectPolicy() failed after config save: " . $e->getMessage(), LogService::LOG_WARN, "ConfigService");
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
            if (!preg_match('#^[0-9*/,\-]+$#D', $field)) return '';
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

    /** @var (callable(string):void)|null test seam: replaces the real start of priority.sh. */
    public static $responsivePrioritySpawner = null;

    /**
     * RESPONSIVE_PRIORITY.md R4: run priority.sh (apply, reset or auto) detached. The script
     * changes only an allow-list of processes and reads the setting itself in the auto mode.
     * Best effort: it never throws.
     */
    public static function runResponsivePriority(string $mode): void {
        if (!in_array($mode, ['apply', 'reset', 'auto'], true)) return;
        try {
            if (self::$responsivePrioritySpawner !== null) {
                (self::$responsivePrioritySpawner)($mode);
                return;
            }
            $script = dirname(__DIR__, 2) . '/scripts/priority.sh';
            if (!is_file($script)) return;
            UtilityService::spawnDetached(['/bin/bash', $script, $mode]);
        } catch (\Throwable $e) {
            LogService::log("priority.sh $mode could not start: " . $e->getMessage(), LogService::LOG_WARN, "ConfigService");
        }
    }

    /** @var (callable(string):bool)|null test seam: replaces FileStorage::ensureReady("home/<user>")->ok. */
    public static $homeReadyProbe = null;
    /** @var (callable():int)|null test seam for the persistent home-mount retry timer. */
    public static $homeMountBackoffClock = null;
    /** @var (callable(string):void)|null test seam for the one-per-state failure warning. */
    public static $homeMountUnavailableLogger = null;
    /** @var (callable(string,array<string,mixed>,bool):string)|null test seam for the failure-state fingerprint. */
    public static $homeMountFailureFingerprintProbe = null;

    private const HOME_MOUNT_RETRY_BASE_SECONDS = 30;
    private const HOME_MOUNT_RETRY_MAX_SECONDS = 300;

    /** The cross-request failure marker for one user's home. */
    public static function homeMountFailureMarkerPath(string $user): string {
        $safeUser = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $user) ?: 'unknown';
        return rtrim(SecretPaths::stateRoot(), '/') . "/home_mount_failure_{$safeUser}.json";
    }

    private static function homeMountBackoffNow(): int {
        if (self::$homeMountBackoffClock !== null) return (int)(self::$homeMountBackoffClock)();
        return time();
    }

    /** Hash the inputs that can change whether a home mount can now succeed. */
    private static function homeMountFailureFingerprint(string $user, array $config, bool $mountRefused): string {
        if (self::$homeMountFailureFingerprintProbe !== null) {
            return (string)(self::$homeMountFailureFingerprintProbe)($user, $config, $mountRefused);
        }
        require_once __DIR__ . '/StoragePathResolver.php';
        require_once __DIR__ . '/StorageMountService.php';
        $persistPath = StoragePathResolver::homePersistPathFromConfig($config);
        $mountPath = UtilityService::getWorkDir($user) . '/home';
        $facts = [
            'user' => $user,
            'persist' => $persistPath,
            'backend' => (string)($config['storage_backend_mode'] ?? ''),
            'upper_mode' => (string)($config['home_upper_mode'] ?? ''),
            'migration' => StorageMountService::isMigrationInProgress(),
            'array_stop' => $mountRefused,
            'backing_mount' => StorageMountService::isBackingMountAvailable($persistPath),
            'mounted' => StorageMountService::isMounted($mountPath),
            'healthy' => StorageMountService::isHomeMountHealthy($user, null, $mountPath, $persistPath),
        ];
        return hash('sha256', (string)json_encode($facts));
    }

    /** @return array<string,mixed>|null */
    private static function readHomeMountFailureState(string $user): ?array {
        $raw = @file_get_contents(self::homeMountFailureMarkerPath($user));
        if (!is_string($raw) || $raw === '') return null;
        $state = json_decode($raw, true);
        return is_array($state) ? $state : null;
    }

    private static function writeHomeMountFailureState(string $user, array $state): void {
        $path = self::homeMountFailureMarkerPath($user);
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return;
        try {
            $tmp = $path . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
            $json = json_encode($state, JSON_UNESCAPED_SLASHES);
            if (!is_string($json) || @file_put_contents($tmp, $json) === false) {
                @unlink($tmp);
                return;
            }
            @chmod($tmp, 0600);
            if (!@rename($tmp, $path)) @unlink($tmp);
        } catch (\Throwable $e) {
            // A failed cache write must not block the safe unmounted-state path.
        }
    }

    private static function clearHomeMountFailureState(string $user): void {
        @unlink(self::homeMountFailureMarkerPath($user));
    }

    private static function homeMountFailureBackoffActive(?array $state, string $fingerprint, int $now): bool {
        return is_array($state)
            && isset($state['fingerprint'], $state['retry_at'])
            && is_string($state['fingerprint'])
            && hash_equals($state['fingerprint'], $fingerprint)
            && (int)$state['retry_at'] > $now;
    }

    /** Store one failure warning per stable state and increase the retry delay. */
    private static function recordHomeMountFailure(string $user, string $fingerprint, ?array $previous, string $fallback, bool $logWarning): void {
        $sameState = is_array($previous)
            && isset($previous['fingerprint'])
            && is_string($previous['fingerprint'])
            && hash_equals($previous['fingerprint'], $fingerprint);
        $failures = $sameState ? max(0, (int)($previous['failures'] ?? 0)) + 1 : 1;
        $delay = min(self::HOME_MOUNT_RETRY_MAX_SECONDS, self::HOME_MOUNT_RETRY_BASE_SECONDS * (2 ** min(4, $failures - 1)));
        $logged = $sameState && !empty($previous['logged']);
        if (!$logged && $logWarning) {
            $message = "getUserStatePath: home mount unavailable for '$user' — state goes to $fallback (outside the mount point) until the home mounts";
            if (self::$homeMountUnavailableLogger !== null) {
                (self::$homeMountUnavailableLogger)($message);
            } else {
                LogService::log($message, LogService::LOG_WARN, 'ConfigService');
            }
            $logged = true;
        }
        self::writeHomeMountFailureState($user, [
            'fingerprint' => $fingerprint,
            'failures' => $failures,
            'retry_at' => self::homeMountBackoffNow() + $delay,
            'logged' => $logged,
        ]);
    }

    /**
     * Pause (microseconds) before the one retry of a failed home mount. The default is
     * half a second. AICLI_HOME_MOUNT_RETRY_PAUSE_MS is a test-only override
     * (tests/bootstrap.php sets 0); production never sets it.
     */
    private static function homeMountRetryPauseUs(): int {
        $e = getenv('AICLI_HOME_MOUNT_RETRY_PAUSE_MS');
        if ($e !== false && $e !== '' && is_numeric($e) && (int)$e >= 0) return (int)$e * 1000;
        return 500000;
    }

    /**
     * #692: where state goes while the home mount is not available. The folder lies
     * OUTSIDE the mount point (work/<user>/home). A write into the bare mount point is
     * hidden when the overlay mounts on top of it, and the settings write is lost.
     */
    public static function unmountedStatePath(string $user): string {
        $safe = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $user) ?: 'unknown';
        return rtrim(SecretPaths::stateRoot(), '/') . "/state_unmounted/$safe/.aicli";
    }

    /** @var (callable():bool)|null test seam: replaces the "mount refused while the array stops" check. */
    public static $arrayStopProbe = null;
    /** @var (callable(string):bool)|null test seam: replaces the "home storage is down with the array" check. */
    public static $arrayDownProbe = null;

    /** Issue #761: is a home mount refused now, because the array stops and the storage is on it? */
    private static function homeMountRefusedByArrayStop(): bool {
        if (self::$arrayStopProbe !== null) return (bool)(self::$arrayStopProbe)();
        require_once __DIR__ . '/StorageMountService.php';
        return StorageMountService::storageBlockedByArrayStop();
    }

    /** Issue #761: is the home storage missing by design, because the array is not started? */
    private static function homeStorageDownWithArray(string $user): bool {
        if (self::$arrayDownProbe !== null) return (bool)(self::$arrayDownProbe)($user);
        require_once __DIR__ . '/StorageMountService.php';
        require_once __DIR__ . '/StoragePathResolver.php';
        return StorageMountService::storageDownWithArray(StoragePathResolver::homePersistPath($user));
    }

    /** @var (callable(string):FileStorageReadiness)|null test seam for deferred home mounts. */
    public static $homeReadinessProbe = null;

    private static function homeReadiness(string $user): FileStorageReadiness {
        if (self::$homeReadinessProbe !== null) return (self::$homeReadinessProbe)($user);
        if (self::$homeReadyProbe !== null) {
            return FileStorageReadiness::fromExit((self::$homeReadyProbe)($user) ? 0 : 1, null);
        }
        return FileStorage::ensureReady("home/$user");   // Epic #1310: facade intent
    }

    /**
     * #692: move state that was written while the home was not mounted into the mounted
     * home. A file that already exists in the mounted home wins and is not overwritten.
     * What is left over (a conflict) is parked in state_unmounted_conflict/, so the next
     * call does not scan it again. Best effort: it never throws.
     */
    public static function adoptUnmountedState(string $user, string $statePath): int {
        $src = self::unmountedStatePath($user);
        if (!is_dir($src)) return 0;
        $moved = 0; $left = 0;
        try {
            @mkdir($statePath, 0755, true);
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($it as $item) {
                $rel = substr($item->getPathname(), strlen($src) + 1);
                $dst = $statePath . '/' . $rel;
                if ($item->isDir()) { @mkdir($dst, 0755, true); continue; }
                if (file_exists($dst)) { $left++; continue; }
                if (@copy($item->getPathname(), $dst)) { @unlink($item->getPathname()); $moved++; } else { $left++; }
            }
        } catch (\Throwable $e) {
            $left++;
            LogService::log("getUserStatePath: could not adopt state written while '$user' was not mounted: " . $e->getMessage(), LogService::LOG_WARN, "ConfigService");
        }
        $top = dirname($src);
        if ($left === 0) {
            @shell_exec('rm -rf ' . escapeshellarg($top) . ' 2>/dev/null');   // nosemgrep: php.lang.security.exec-use.exec-use
        } else {
            $park = rtrim(SecretPaths::stateRoot(), '/') . '/state_unmounted_conflict/' . basename($top) . '-' . date('YmdHis');
            @mkdir(dirname($park), 0755, true);
            @rename($top, $park);
            LogService::log("getUserStatePath: $left file(s) written while '$user' was not mounted conflict with the mounted home; parked in $park", LogService::LOG_WARN, "ConfigService");
        }
        LogService::log("getUserStatePath: adopted $moved file(s) written while '$user' was not mounted", LogService::LOG_INFO, "ConfigService");
        return $moved;
    }

    /**
     * Helper to get the base path for user-specific state (.aicli inside their home).
     * D-333: Forces mount of home storage to ensure data is written to ZRAM/SquashFS stack.
     *
     * #692: when the mount is not available (after one retry), the path is NOT the bare
     * folder under the mount point. It is unmountedStatePath(), outside the mount point,
     * so a later mount cannot hide what was written. The next call that finds the mount
     * ready moves that state into the home (adoptUnmountedState).
     */
    public static function getUserStatePath() {
        self::refuseLiveStateInTests();
        $config = self::getConfig();
        $user = $config['user'] ?? 'root';
        if (empty($user)) $user = 'root';

        // A stable failure gets one persistent retry marker across PHP-FPM
        // requests. This prevents every UI poll and supervisor tick from
        // repeating the mount and warning. Only inspect mount state after a
        // failure or while a marker exists; the healthy path stays cheap.
        $hasFailureMarker = is_file(self::homeMountFailureMarkerPath($user));
        // Reading var.ini is only needed after a failure. Keep healthy state
        // lookups free of an array-stop probe on every UI or supervisor poll.
        $mountRefused = $hasFailureMarker ? self::homeMountRefusedByArrayStop() : false;
        $previousFailure = $hasFailureMarker ? self::readHomeMountFailureState($user) : null;
        $fingerprint = $hasFailureMarker ? self::homeMountFailureFingerprint($user, $config, $mountRefused) : '';
        $backoffActive = $hasFailureMarker
            && self::homeMountFailureBackoffActive($previousFailure, $fingerprint, self::homeMountBackoffNow());

        // Ensure home is mounted so we write into the OverlayFS stack, not the underlying rootfs.
        $ready = false;
        $readiness = FileStorageReadiness::fromExit(1, null);
        if (!$backoffActive) {
            $readiness = self::homeReadiness($user);
            $ready = $readiness->ok;
            if (!$ready && !$mountRefused) {
                // Check array-stop state after the first mount attempt fails.
                // A stopped array cannot recover from an immediate retry.
                $mountRefused = self::homeMountRefusedByArrayStop();
            }
            // Issue #761: while the array stops, the mount is refused on purpose. A retry cannot
            // succeed, so do not wait for it. State still goes to the fallback below, which is
            // outside the mount point: a refused mount never lets a write reach the bare folder.
            if (!$ready && !$mountRefused) {
                // The first mount can fail once (the mount point vanished between mkdir and mount, #692).
                $pause = self::homeMountRetryPauseUs();
                if ($pause > 0) usleep($pause);
                $readiness = self::homeReadiness($user);
                $ready = $readiness->ok;
            }
        }
        if (!$ready) {
            $fallback = self::unmountedStatePath($user);
            self::refuseLiveStateInTests($fallback);
            // Issue #761: expected while the array is down. Log it only otherwise, so an open
            // page does not write one line a second to syslog.
            $transientMoveDefer = $readiness->deferred && $readiness->deferReason === 'storage_move_active';
            if (!$backoffActive && !$transientMoveDefer) {
                if ($fingerprint === '') {
                    $fingerprint = self::homeMountFailureFingerprint($user, $config, $mountRefused);
                }
                $logWarning = !$mountRefused && !self::homeStorageDownWithArray($user);
                self::recordHomeMountFailure($user, $fingerprint, $previousFailure, $fallback, $logWarning);
            }
            self::ensureStateDirOwnedBy($fallback, $user);
            return $fallback;
        }

        if ($hasFailureMarker) self::clearHomeMountFailureState($user);

        // #646: the state root is injectable (AICLI_STATE_ROOT); tests/bootstrap.php redirects it.
        $homeDir = SecretPaths::stateRoot() . "/work/$user/home";
        $statePath = "$homeDir/.aicli";
        self::refuseLiveStateInTests($statePath);

        // Non-root user permission fix (2026-06-07): this runs as ROOT (web/emhttpd),
        // but the agent run-script writes .aicli/.exported_keys_* AS the session user.
        // Whichever side creates .aicli first owns it — and when a root write here
        // (workspaces.json, resumes, envs, autolaunch) wins, the dir is root-owned and
        // the non-root agent gets "Permission denied" creating files in it. Make .aicli
        // owned by the session user so BOTH root (web) and the agent (run-script) can
        // write. No-op for root sessions (root owns everything already).
        self::ensureStateDirOwnedBy($statePath, $user);

        self::adoptUnmountedState($user, $statePath);
        return $statePath;
    }

    /**
     * Pure decision: is this process a test run that has NOT loaded tests/bootstrap.php?
     * A PHPUnit TestCase class in memory means test code is running; the bootstrap defines
     * AICLI_TEST_TMP and points every path at a sandbox. Without it, a test (or a stand-in
     * runner) writes fixtures into the operator's LIVE state. On 2026-10-12 such a runner put
     * three fake workspaces, fake resume tokens and an empty auto-launch list into the real
     * store on .4 (Forgejo #473). Refusing here is cheap and exact.
     */
    public static function shouldRefuseLiveState(bool $phpunitLoaded, bool $sandboxDefined): bool
    {
        return $phpunitLoaded && !$sandboxDefined;
    }

    /**
     * Throw when test code runs against live state (see shouldRefuseLiveState).
     * #646: the constant alone is a heuristic (a stand-in runner can define it), so the
     * guard also compares real paths: while a PHPUnit TestCase class is loaded, the state
     * root, the flash root and every path in $paths must lie outside the live roots
     * (/tmp/unraid-aicliagents and /boot/config/plugins/unraid-aicliagents).
     */
    public static function refuseLiveStateInTests(string ...$paths): void
    {
        $phpunit = class_exists('PHPUnit\\Framework\\TestCase', false);
        if (self::shouldRefuseLiveState($phpunit, defined('AICLI_TEST_TMP'))) {
            throw new \RuntimeException('Refusing to use the live plugin state from test code: load tests/bootstrap.php (run the suite with ../ci/run unit), never a stand-in runner on the host.');
        }
        $bad = SecretPaths::livePathInTests($phpunit, array_merge([SecretPaths::stateRoot(), SecretPaths::flashRoot()], $paths));
        if ($bad !== null) {
            throw new \RuntimeException("Refusing to use the live plugin state from test code: $bad is a production path. Point AICLI_STATE_ROOT and AICLI_FLASH_ROOT at a temp folder (tests/bootstrap.php does).");
        }
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

    /** True when workspaces.json holds content that is not a JSON object or list (#666 R-155). */
    private static function workspacesFileUnreadable(string $file): bool {
        if (!file_exists($file)) return false;
        $raw = @file_get_contents($file);
        if ($raw === false || trim($raw) === '') return false;
        return !is_array(json_decode($raw, true));
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

    /** Seconds that a "being deleted" mark stays valid. A delete that died half way cannot block a workspace longer than this. */
    public const WORKSPACE_DELETING_TTL = 600;

    private static function workspaceDeletingPath(string $id): string {
        $safe = preg_replace('/[^A-Za-z0-9_-]/', '', $id);
        return rtrim(SecretPaths::stateRoot(), '/') . '/deleting-ws-' . ($safe !== '' ? $safe : 'invalid');
    }

    /**
     * #687: mark a workspace as being deleted. The delete closes the session first and
     * removes the record last. In that gap an open browser tab sends `start` again and
     * would start the session as an untracked one. Call this BEFORE the close. Best effort.
     */
    public static function markWorkspaceDeleting(string $id): void {
        if ($id === '') return;
        @file_put_contents(self::workspaceDeletingPath($id), (string)time());
    }

    /** #687: remove the "being deleted" mark (the delete failed, or the record is gone for good). */
    public static function clearWorkspaceDeleting(string $id): void {
        if ($id === '') return;
        @unlink(self::workspaceDeletingPath($id));
    }

    /**
     * #687: true when `start` must refuse this workspace id: a delete of it is under way, or
     * the registry holds a removal tombstone for it. A Relay actor is never refused here: its
     * definition stays server-side. An id that was never saved is NOT refused (a new workspace
     * can start before its first save lands).
     */
    public static function isWorkspaceRemoved(string $id): bool {
        if ($id === '') return false;
        $mark = self::workspaceDeletingPath($id);
        $at = is_file($mark) ? (int)@filemtime($mark) : 0;
        if ($at > 0 && (time() - $at) < self::WORKSPACE_DELETING_TTL) return true;
        if ($at > 0) @unlink($mark);   // stale: the delete died half way
        if (isset(self::relayActorIds()[$id])) return false;
        $deleted = self::getWorkspaces()['deletedIds'] ?? [];
        $when = is_array($deleted) ? ($deleted[$id] ?? null) : null;
        return is_int($when) && $when > (time() - 86400);
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
        $file = self::getUserStatePath() . "/workspaces.json";
        // #666 R-155: getWorkspaces() turns a file it cannot parse into the empty
        // default. Saving on top of that default would replace every workspace with
        // the one record of this save. Keep a copy and refuse until the file is fixed.
        if (self::workspacesFileUnreadable($file)) {
            $copy = $file . '.corrupt-' . date('Ymd-His');
            if (!glob($file . '.corrupt-*')) @copy($file, $copy);
            LogService::log("saveWorkspaces REFUSED: $file cannot be parsed; a copy was kept as " . basename($copy), LogService::LOG_ERROR, "ConfigService");
            self::$lastWorkspaceSaveMessage = 'The workspace list file is damaged, so nothing was saved. A copy was kept next to it as workspaces.json.corrupt-*. Restore or remove the damaged file, then retry.';
            return false;
        }
        $before = self::getWorkspaces();
        $data = self::mergeWorkspaceSnapshot($before, $data, $removedIds);
        $count = count($data['sessions'] ?? []);
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
                // AGENT_SELF_SERVICE.md R8: and for the workspace's own speed.
                if (!array_key_exists('tts_speed', $session) && array_key_exists('tts_speed', $prior)) {
                    $session['tts_speed'] = $prior['tts_speed'];
                }
                // AGENT_SPAWN_WORKSPACE.md: a page loaded before a helper existed resends the record
                // without the helper marks. Keep them, or a browser save would turn a helper into an
                // ordinary workspace (no autoClose, no parent, no spawn rules).
                foreach (['spawned', 'spawn'] as $spawnKey) {
                    if (!array_key_exists($spawnKey, $session) && array_key_exists($spawnKey, $prior)) {
                        $session[$spawnKey] = $prior[$spawnKey];
                    }
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
            // #385: a drawer that does not list every stored row (a row another tab
            // or a tool just added, a hidden one, a test fixture) still sends a
            // reorder, and "not a full list" used to throw that order away, so the
            // rows snapped back on the next load. The rows the save DOES name take
            // the slots those same rows already hold, in the saved order. Rows it
            // does not name keep their place; a save of one row moves nothing.
            $named = [];
            foreach (($incoming['sessions'] ?? []) as $s) {
                $nid = is_array($s) ? (string)($s['id'] ?? '') : '';
                if ($nid !== '' && isset($ordered[$nid]) && !in_array($nid, $named, true)) $named[] = $nid;
            }
            if (count($named) >= 2) {
                $keys = array_keys($ordered);
                $n = 0;
                foreach ($keys as $i => $k) {
                    if (in_array((string)$k, $named, true)) $keys[$i] = $named[$n++];
                }
                $reordered = [];
                foreach ($keys as $k) $reordered[$k] = $ordered[$k];
                $ordered = $reordered;
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
            $legacyFile = SecretPaths::flashRoot() . "/envs/env_$hash.json";
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
            : (SecretPaths::stateRoot() . "/work/$user");
        $script = "$workDir/aicli-run-$sid.sh";
        if (!is_file($script)) return '';
        if (!preg_match('/^export AICLI_CHAT_SESSION_ID=(\S*)$/m', (string)@file_get_contents($script), $m)) return '';
        $chat = trim($m[1], "'\"");
        return ($chat !== '_fresh_' && preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $chat)) ? $chat : '';
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

    /** Sources a scheduled-continue entry can have. */
    public const SCHEDULED_CONTINUE_SOURCES = ['user', 'quota-detect', 'transient-error'];

    /** At most this many `user` entries (operator or agent) for one workspace (#831). */
    public const SCHEDULED_CONTINUE_USER_CAP = 20;

    /**
     * #831: turn the stored value of one workspace into a list of entries.
     * The old shape was ONE entry (an object with an `at` key). It reads as a list of one.
     * An entry without an `id` gets a stable id made from its own fields, so the same
     * old entry has the same id on every read until the next write stores it.
     *
     * @param mixed $raw
     * @return list<array<string,mixed>>
     */
    public static function normalizeScheduledEntries($raw, string $sessionId = ''): array
    {
        if (!is_array($raw) || $raw === []) return [];
        $list = array_key_exists('at', $raw) ? [$raw] : array_values($raw);
        $out = [];
        foreach ($list as $e) {
            if (!is_array($e) || !isset($e['at'])) continue;
            if (!isset($e['id']) || !is_string($e['id']) || $e['id'] === '') {
                $e['id'] = substr(sha1($sessionId . '|' . (int)$e['at'] . '|' . (int)($e['created_at'] ?? 0) . '|' . (string)($e['source'] ?? 'user')), 0, 8);
            }
            $out[] = $e;
        }
        return $out;
    }

    /** @return array<string,list<array<string,mixed>>> workspace id => entries (old single-entry files are migrated on read) */
    public static function getScheduledContinueMap(): array
    {
        $file = self::getScheduledContinueFilePath();
        if (!file_exists($file)) return [];
        $data = json_decode((string)file_get_contents($file), true);
        if (!is_array($data)) return [];
        $map = [];
        foreach ($data as $sid => $raw) {
            $list = self::normalizeScheduledEntries($raw, (string)$sid);
            if ($list !== []) $map[(string)$sid] = $list;
        }
        return $map;
    }

    /** @return list<array<string,mixed>> every entry of one workspace, in the order they were added */
    public static function getScheduledContinues(string $sessionId): array
    {
        return self::getScheduledContinueMap()[$sessionId] ?? [];
    }

    /** The first entry of one workspace, or null when none is set. */
    public static function getScheduledContinue(string $sessionId): ?array
    {
        return self::getScheduledContinues($sessionId)[0] ?? null;
    }

    /**
     * #831: the text the ticker types for an operator or agent entry, or null for the plain Continue
     * nudge. A `reason` puts the tag `[Scheduled: <reason>] ` in front. A `message` replaces the
     * standard continue prompt, but only for a `user` entry: the `message` of a quota-detect entry is
     * a note for the person and is never typed.
     *
     * @param array<string,mixed> $entry
     */
    public static function scheduledContinueText(array $entry): ?string
    {
        $reason = trim((string)($entry['reason'] ?? ''));
        $message = trim((string)($entry['message'] ?? ''));
        $ownMessage = (string)($entry['source'] ?? 'user') === 'user' && $message !== '';
        if ($reason === '' && !$ownMessage) return null;
        return ($reason !== '' ? '[Scheduled: ' . $reason . '] ' : '') . ($ownMessage ? $message : TmuxService::CONTINUE_PROMPT);
    }

    /** How long a writer waits for the scheduled-continue lock before it goes on without it. */
    private const SCHEDULED_CONTINUE_LOCK_WAIT_S = 2.0;

    /**
     * Test seam: called inside the scheduled-continue lock, after the map was read and
     * before it is written back. A test uses it to look at what a second writer could do.
     * @var callable|null
     */
    public static $afterScheduledContinueRead = null;

    /**
     * Read the map, change it and write it back while holding one lock (the lost-update item of #661).
     * The poll (set), the ticker (clear) and the UI (set/clear) run in different processes.
     * Each one used to read the whole map, change one entry and write the whole map back, so
     * a write that landed between the read and the write was lost. The lock is taken with
     * LOCK_NB in a poll loop with a deadline, never a blocking flock. When a holder is stuck
     * past the deadline, the change is still made without the lock (the old behaviour): a
     * stuck holder must not stop the supervisor tick.
     *
     * @param callable(array):?array $mutate returns the new map, or null for "nothing to write"
     */
    private static function mutateScheduledContinueMap(callable $mutate): bool
    {
        $path = self::getScheduledContinueFilePath();
        $lockPath = $path . '.lock';
        @mkdir(dirname($lockPath), 0755, true);
        $lock = @fopen($lockPath, 'c');
        if ($lock !== false) {
            $deadline = microtime(true) + self::SCHEDULED_CONTINUE_LOCK_WAIT_S;
            $held = false;
            do {
                if (@flock($lock, LOCK_EX | LOCK_NB)) { $held = true; break; }
                usleep(20000);
            } while (microtime(true) < $deadline);
            if (!$held) { @fclose($lock); $lock = false; }
        }
        try {
            $map = self::getScheduledContinueMap();
            if (is_callable(self::$afterScheduledContinueRead)) {
                call_user_func(self::$afterScheduledContinueRead);
            }
            $new = $mutate($map);
            if ($new === null) return true;
            return AtomicWriteService::writeJson($path, $new);
        } finally {
            if ($lock !== false) { @flock($lock, LOCK_UN); @fclose($lock); }
        }
    }

    /**
     * Add one entry to a workspace's list (#831). Returns the entry id, or null on failure
     * (see $error). Rules, all inside the lock: a `quota-detect` entry replaces the
     * workspace's older `quota-detect` and `transient-error` entries; a `transient-error`
     * entry replaces the older `transient-error` entry; neither removes a `user` entry.
     * A `user` entry is refused above SCHEDULED_CONTINUE_USER_CAP.
     *
     * @param array<string,mixed> $entry
     */
    public static function addScheduledContinue(string $sessionId, array $entry, ?string &$error = null): ?string
    {
        $error = null;
        if ($sessionId === '') { $error = 'A workspace id is required.'; return null; }
        $source = (string)($entry['source'] ?? 'user');
        $entry['source'] = $source;
        $id = isset($entry['id']) && is_string($entry['id']) && $entry['id'] !== '' ? $entry['id'] : bin2hex(random_bytes(4));
        $entry['id'] = $id;
        $failure = null;
        $ok = self::mutateScheduledContinueMap(static function (array $map) use ($sessionId, $entry, $source, &$failure): ?array {
            $list = $map[$sessionId] ?? [];
            if ($source === 'user') {
                $users = count(array_filter($list, static fn(array $e): bool => ($e['source'] ?? 'user') === 'user'));
                if ($users >= self::SCHEDULED_CONTINUE_USER_CAP) {
                    $failure = 'This workspace already has ' . self::SCHEDULED_CONTINUE_USER_CAP . ' scheduled continues. Remove one first.';
                    return null;
                }
            } else {
                $replaced = $source === 'quota-detect' ? ['quota-detect', 'transient-error'] : [$source];
                $list = array_values(array_filter($list, static fn(array $e): bool => !in_array((string)($e['source'] ?? 'user'), $replaced, true)));
            }
            $list[] = $entry;
            $map[$sessionId] = $list;
            return $map;
        });
        if ($failure !== null) { $error = $failure; return null; }
        if (!$ok) { $error = 'Write failed.'; return null; }
        return $id;
    }

    /** Add one entry. Kept for the callers that need only a yes or no. */
    public static function setScheduledContinue(string $sessionId, array $entry): bool
    {
        return self::addScheduledContinue($sessionId, $entry) !== null;
    }

    /** Replace the entry that has this id (the ticker advances a repeating entry this way). False when it is gone. */
    public static function replaceScheduledContinue(string $sessionId, string $entryId, array $entry): bool
    {
        if ($sessionId === '' || $entryId === '') return false;
        $found = false;
        $ok = self::mutateScheduledContinueMap(static function (array $map) use ($sessionId, $entryId, $entry, &$found): ?array {
            foreach ($map[$sessionId] ?? [] as $i => $e) {
                if (($e['id'] ?? '') === $entryId) {
                    $entry['id'] = $entryId;
                    $map[$sessionId][$i] = $entry;
                    $found = true;
                    return $map;
                }
            }
            return null;
        });
        return $ok && $found;
    }

    /**
     * Remove entries of one workspace. With $entryId only that entry goes; with $source
     * only the entries of that source go (a detector clears its own entry and nothing
     * else); with neither, all go. No-op when nothing matches.
     *
     * @return int how many entries were removed
     */
    public static function clearScheduledContinue(string $sessionId, ?string $entryId = null, ?string $source = null): int
    {
        if ($sessionId === '') return 0;
        $removed = 0;
        self::mutateScheduledContinueMap(static function (array $map) use ($sessionId, $entryId, $source, &$removed): ?array {
            if (!isset($map[$sessionId])) return null;
            $keep = [];
            foreach ($map[$sessionId] as $e) {
                $match = ($entryId === null || ($e['id'] ?? '') === $entryId)
                    && ($source === null || (string)($e['source'] ?? 'user') === $source);
                if ($match) $removed++; else $keep[] = $e;
            }
            if ($removed === 0) return null;
            if ($keep === []) unset($map[$sessionId]); else $map[$sessionId] = $keep;
            return $map;
        });
        return $removed;
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
