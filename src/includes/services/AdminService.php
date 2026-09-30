<?php
/**
 * <module_context>
 *     <name>AdminService</name>
 *     <description>Data layer for the Plugin Management Tools catalogue
 *     (docs/specs/PLUGIN_MANAGEMENT_TOOLS.md). Tier 1 (read-only, 2026-09-08) answers
 *     a question about plugin state; Tier 2 (change, 2026-09-09) executes a routine,
 *     reversible mutation. Every method — both tiers — calls the SAME service
 *     functions the Manager UI already calls (ArgsService, EnvService, SecretService,
 *     ConfigService, AgentRegistry): this class adds no new state, no new file
 *     format, and no new mutation path of its own. It mirrors RelayMcpTools: a thin,
 *     curated surface a stdio MCP adapter (admin-mcp.php) and CLI (admin-agent.php)
 *     can both call, the way relay-mcp.php and relay-agent.php both call
 *     RelayMcpTools. It does NOT itself require_once its dependencies — like
 *     RelayMcpTools, it assumes the caller has already loaded
 *     AICliAgentsManager.php, which requires every service and handler once, up
 *     front, for the whole request.</description>
 *     <dependencies>ConfigService, ProcessManager, SupervisorService, StorageMetricsService,
 *     AgentRegistry, VersionCheckService, ActivityService, ArgsService, EnvService,
 *     SecretService, ValidationService, LifecycleLogService, RedactionService,
 *     UtilityService, LogService, Handlers\StorageHandler, VoiceService (speak(),
 *     AGENT_VOICE.md), TmuxService (sendInput(), WORKSPACE_SEND_INPUT.md),
 *     EventLedger (sendInput()'s workspace.input event), FavouritesService and
 *     Handlers\FavouritesHandler (listFavourites()/addFavourite()/
 *     removeFavourite()/createWorkspace()'s favouriteId, WORKSPACE_FAVOURITES.md)</dependencies>
 *     <constraints>Tier 1 methods are read-only — no file write, no process
 *     start/stop, no state change. Tier 2 methods (2026-09-09, PLUGIN_MANAGEMENT_TOOLS.md
 *     "Tier 2 — change") DO write, but only by calling the exact same service method
 *     the matching AJAX handler calls (ArgsHandler/EnvHandler/AutoLaunchHandler/
 *     AgentHandler/ConfigService::saveConfig) — never a bypass, never new validation
 *     that contradicts the UI's own. Secrets never round-trip in EITHER tier: a
 *     value from the vault or an env file must never appear in a return array, only
 *     key names — aicli_set_workspace_env writes a value but never echoes it back,
 *     the same discipline aicli_get_workspace already applies to reads. Every method
 *     is defensive — a missing class, a throw, or a corrupt on-disk file must come
 *     back as a well-formed array carrying an 'error' string, never an uncaught
 *     exception, because this class is called from an MCP stdio transport where an
 *     exception kills the whole connection, not just one tool call. A Tier 2 method
 *     signals "refused, nothing changed" the same way a Tier 1 method signals "could
 *     not answer": an 'error' key, never a thrown exception — AdminMcpTools::call()
 *     turns that into status=>'error' for a mutating tool (see its own doc comment).
 *     SINGLE-WRAPPER RULE (2026-09-08): AdminMcpTools::call() already wraps every
 *     result under the tool's own key (e.g. 'workspaces' => AdminService::listWorkspaces()).
 *     A method here must therefore return its payload UNWRAPPED, the way overview() always
 *     has, never re-wrapped under a second key of the same name. Three methods did
 *     that by mistake (listWorkspaces/listAgents/listActivities each returned
 *     ['name' => $list], and storageStatus() named its own metrics sub-key 'storage',
 *     colliding with the tool wrap key of the same name) and the JSON came out
 *     double-nested. Fixed here; wrapping stays in AdminMcpTools alone, on purpose,
 *     so there is exactly one place a caller has to unwrap.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class AdminService {

    /**
     * getLogs() hard cap on returned lines. TerminalHandler::getLog() (the
     * Debug Console's backing call) allows up to 2000 — that is a scrollable
     * pane a human can skim. An MCP answer becomes part of a model's context
     * window, so this tool caps far tighter regardless of what the caller asks
     * for; see the min() clamp in getLogs().
     */
    private const MAX_LOG_LINES = 500;

    /**
     * The plugin writes to exactly four log files. This mirrors
     * TerminalHandler::resolveLogFile() (private, so not callable from a
     * service) rather than reusing it — the four paths are a hard-coded
     * switch there too, and a fifth log type has never shipped without a
     * matching UI change this file would need to track anyway. getLogs()
     * only ever reads 'debug' (the operational log every service writes to
     * via LogService::log()); the other three are named here for completeness
     * and so a future Tier-1 addition does not have to rediscover the paths.
     */
    private const LOG_FILES = [
        'install'   => '/boot/config/plugins/unraid-aicliagents/install.log',
        'uninstall' => '/boot/config/plugins/unraid-aicliagents/uninstall.log',
        'migration' => '/tmp/unraid-aicliagents/migration.log',
        'debug'     => '/tmp/unraid-aicliagents/debug.log',
    ];

    /**
     * Plugin version, active code generation, supervisor state, and headline
     * counts — the "what is this box even running" answer from
     * PLUGIN_MANAGEMENT_TOOLS.md's problem statement. Every field here is a
     * read the Manager UI already performs on page load; this method just
     * collects them in one place instead of the four separate AJAX round
     * trips the browser makes.
     *
     * @return array<string,mixed>
     */
    public static function overview(): array {
        try {
            $sessions = ConfigService::getWorkspaces()['sessions'] ?? [];
            $runningCount = 0;
            foreach ($sessions as $w) {
                $sid = is_array($w) ? (string)($w['id'] ?? '') : '';
                if ($sid !== '' && ProcessManager::isRunning($sid)) $runningCount++;
            }

            // AgentRegistry::getRegistry() always carries a synthetic 'terminal'
            // entry (a bare shell fallback, not a real CLI agent — see the
            // is_installed=true special-case at AgentRegistry::getRegistry()).
            // Every other agent-facing count in this file excludes it the same
            // way AgentHandler's own loops do.
            $registry = AgentRegistry::getRegistry();
            $agentsKnown = 0;
            $agentsInstalled = 0;
            foreach ($registry as $id => $agent) {
                if ($id === 'terminal') continue;
                $agentsKnown++;
                if (!empty($agent['is_installed'])) $agentsInstalled++;
            }

            $workState = SupervisorService::getWorkState();
            $supervisorRunning = SupervisorService::isRunning();
            $supervisorState = is_array($workState) && ($workState['state'] ?? '') !== ''
                ? (string)$workState['state']
                : ($supervisorRunning ? 'idle' : 'stopped');

            // WORKSPACE_FAVOURITES.md R7: a saved-count headline, the same
            // "one place instead of four round trips" reasoning as every other
            // field here. FavouritesService::list() is used directly (not
            // listFavourites()'s decorated shape) — a count needs no
            // agentInstalled/openWorkspaceId lookup.
            if (!class_exists('\AICliAgents\Services\FavouritesService')) {
                require_once __DIR__ . '/FavouritesService.php';
            }
            $favouritesCount = count(\AICliAgents\Services\FavouritesService::list());

            return [
                'plugin_version'     => ConfigService::getVersion(),
                'active_generation'  => ProcessManager::activeGeneration(),
                'supervisor_running' => $supervisorRunning,
                'supervisor_state'   => $supervisorState,
                'workspaces'         => count($sessions),
                'workspaces_running' => $runningCount,
                'agents_known'       => $agentsKnown,
                'agents_installed'   => $agentsInstalled,
                'favourites'         => $favouritesCount,
            ];
        } catch (\Throwable $e) {
            return self::failure('overview', $e);
        }
    }

    /**
     * Every saved workspace with its live running state — the drawer's own
     * list, backed by the same ConfigService::getWorkspaces() call and the
     * same ProcessManager::isRunning() probe TerminalHandler::
     * getSessionsRunning() uses for the drawer's live dot.
     *
     * @return list<array<string,mixed>>|array{error:string}
     */
    public static function listWorkspaces(): array {
        try {
            $sessions = ConfigService::getWorkspaces()['sessions'] ?? [];
            $out = [];
            foreach ($sessions as $w) {
                if (!is_array($w)) continue;
                $id = (string)($w['id'] ?? '');
                if ($id === '') continue;
                $out[] = [
                    'id'         => $id,
                    'name'       => (string)($w['name'] ?? ''),
                    'path'       => (string)($w['path'] ?? ''),
                    'agentId'    => (string)($w['agentId'] ?? ''),
                    'running'    => ProcessManager::isRunning($id),
                    'lastActive' => $w['lastActive'] ?? null,
                    // VOICE_SWITCHES.md R5: default true when the record has no
                    // 'voice' field at all (an older registry, or never muted).
                    'voice'      => self::workspaceVoiceEnabled($w),
                    // VOICE_MAIL.md R14 / AGENT_VOICE.md R14: '' = not set.
                    'spokenName' => (string)($w['spoken_name'] ?? ''),
                    'voiceId'    => VoiceService::workspaceVoiceFor($w),
                ];
            }
            // Single-wrapper rule (see class doc): return the list directly.
            // AdminMcpTools::call() supplies the one and only 'workspaces' key.
            return $out;
        } catch (\Throwable $e) {
            return self::failure('listWorkspaces', $e);
        }
    }

    /**
     * One workspace's full Tier-1 record: its saved fields, its effective CLI
     * args (agent default, overridden by any workspace-level args — the same
     * precedence ArgsService::getEffectiveArgs() applies for a real launch),
     * every env KEY the session would see (never a value — see the class
     * doc), and its agent-level auto-launch preference.
     *
     * With $id === '' this resolves the caller's OWN workspace from
     * AICLI_SESSION_ID, exactly the way RelayMcpTools/relay-agent.php resolve
     * Relay identity — so "what am I running as" needs no argument from the
     * calling agent.
     *
     * @return array<string,mixed>
     */
    public static function getWorkspace(string $id = ''): array {
        try {
            if ($id === '') {
                $id = (string)(getenv('AICLI_SESSION_ID') ?: '');
            }
            if ($id === '') {
                return ['error' => 'No workspace id was given, and AICLI_SESSION_ID is not set for this process. Pass an id, or call this from inside a workspace session.'];
            }

            $sessions = ConfigService::getWorkspaces()['sessions'] ?? [];
            $record = null;
            foreach ($sessions as $w) {
                if (is_array($w) && (string)($w['id'] ?? '') === $id) { $record = $w; break; }
            }
            if ($record === null) {
                return ['error' => "No workspace with id '$id' was found."];
            }

            $path    = (string)($record['path'] ?? '');
            $agentId = (string)($record['agentId'] ?? '');

            $args = '';
            if ($path !== '' && $agentId !== '') {
                try { $args = ArgsService::getEffectiveArgs($path, $agentId); }
                catch (\Throwable $e) { /* args are best-effort; a workspace with no saved args file is normal, not a failure */ }
            }

            // EnvService::buildEffectiveEnv() layers agent defaults, the agent
            // secrets vault, workspace secrets, and both general-env tiers into
            // one KEY => VALUE map — the exact set of vars a real launch would
            // export. We take array_keys() only; the values (several of which
            // ARE secret values by construction) never leave this scope.
            $envKeys = [];
            if ($path !== '' && $agentId !== '') {
                try { $envKeys = array_values(array_keys(EnvService::buildEffectiveEnv($path, $agentId))); }
                catch (\Throwable $e) { /* env is best-effort; never fail the whole lookup for it */ }
            }
            sort($envKeys, SORT_NATURAL | SORT_FLAG_CASE);

            $autoLaunch = ['autoLaunch' => null, 'freshIfNoResume' => null];
            if ($agentId !== '') {
                try { $autoLaunch = ConfigService::getAgentAutoLaunch($agentId); }
                catch (\Throwable $e) { /* leave the null placeholder — a missing preference file just means "never set" */ }
            }

            return [
                'id'          => $id,
                'name'        => (string)($record['name'] ?? ''),
                'path'        => $path,
                'agentId'     => $agentId,
                'running'     => ProcessManager::isRunning($id),
                'lastActive'  => $record['lastActive'] ?? null,
                'args'        => $args,
                'env_keys'    => $envKeys,
                'auto_launch' => $autoLaunch,
                // VOICE_SWITCHES.md R5: default true when the record has no
                // 'voice' field at all (an older registry, or never muted).
                'voice'       => self::workspaceVoiceEnabled($record),
                // VOICE_MAIL.md R14 / AGENT_VOICE.md R14: '' = not set.
                'spokenName'  => (string)($record['spoken_name'] ?? ''),
                'voiceId'     => VoiceService::workspaceVoiceFor($record),
            ];
        } catch (\Throwable $e) {
            return self::failure('getWorkspace', $e);
        }
    }

    /**
     * Installed agents with their version/channel/update state — the same
     * three facts AgentHandler's dropdown builder assembles per agent
     * (AgentRegistry::getRegistry() + ::getChannel() + VersionCheckService::
     * hasUpdate()), narrowed to agents that are actually installed and with
     * the synthetic 'terminal' entry excluded (see the note in overview()).
     *
     * hasUpdate() reads ONLY the version-check cache written by the existing
     * cron/manual check — it makes no network call itself, so this stays a
     * pure read with no side effect and no rate-limit risk from a looping
     * caller.
     *
     * @return list<array<string,mixed>>|array{error:string}
     */
    public static function listAgents(): array {
        try {
            $registry = AgentRegistry::getRegistry();
            $out = [];
            foreach ($registry as $id => $agent) {
                if ($id === 'terminal') continue;
                if (empty($agent['is_installed'])) continue;

                $update = null;
                try { $update = VersionCheckService::hasUpdate((string)$id); }
                catch (\Throwable $e) { /* an agent with no cache entry yet is normal, not a failure */ }

                $out[] = [
                    'id'                => (string)$id,
                    'name'              => (string)($agent['name'] ?? $id),
                    'version'           => (string)($agent['version'] ?? 'unknown'),
                    'channel'           => (string)($agent['channel'] ?? 'stable'),
                    'update_available'  => $update !== null,
                    'update_to_version' => $update['available'] ?? null,
                ];
            }
            // Single-wrapper rule (see class doc): return the list directly.
            // AdminMcpTools::call() supplies the one and only 'agents' key.
            return $out;
        } catch (\Throwable $e) {
            return self::failure('listAgents', $e);
        }
    }

    /**
     * Storage + supervisor summary — the same two calls the React "isSyncing"
     * overlay polls (StorageMetricsService::getStatus() for per-entity layer
     * counts and dirty state, SupervisorService for the daemon's live work),
     * plus the plain-English "baking" flag StorageHandler::
     * describeSupervisorBake() derives from the raw work state for the
     * Manager Store cards. Two independent try/catches: a storage read
     * failure (e.g. a share unmounted mid-call) should not also blank out an
     * otherwise-healthy supervisor read, and vice versa.
     *
     * @return array<string,mixed>
     */
    public static function storageStatus(): array {
        try {
            $storage = StorageMetricsService::getStatus();
        } catch (\Throwable $e) {
            $storage = ['error' => 'storage metrics unavailable: ' . $e->getMessage()];
        }

        try {
            $work = SupervisorService::getWorkState();
            $bake = \AICliAgents\Handlers\StorageHandler::describeSupervisorBake($work);
            $supervisor = [
                'running'      => SupervisorService::isRunning(),
                'tick_age_s'   => SupervisorService::getTickAge(),
                'status'       => SupervisorService::getStatus(),
                'work'         => $work,
                'baking'       => $bake['baking'],
                'baking_label' => $bake['label'],
            ];
        } catch (\Throwable $e) {
            $supervisor = ['error' => 'supervisor status unavailable: ' . $e->getMessage()];
        }

        // Single-wrapper rule (see class doc): the AdminMcpTools tool wrap key for
        // this tool is ALSO literally 'storage' (aicli_get_storage_status), so a
        // same-named sub-key here read as double-nested JSON even though nothing
        // was actually wrapped twice. Named 'metrics' instead of 'storage' so the
        // two payload sections (metrics, supervisor) sit directly under the one
        // real 'storage' wrap key AdminMcpTools::call() applies.
        return ['metrics' => $storage, 'supervisor' => $supervisor];
    }

    /**
     * HOME_RESTORE.md R6 read tool. One user's backup snapshots plus the
     * last backup and last restore records, so an agent can see what a
     * restore proposal would act on before it calls aicli_restore_home.
     * Loads StorageHandler on demand, the same "CLI bootstrap loads the
     * handler, not the AJAX dispatcher" pattern backup-home --scheduled and
     * executeApprovedBackupHome() already use (BackupCliBootstrapTest pins
     * that pattern for backup; this is its restore sibling).
     *
     * @return array{list_backups?:array<string,mixed>,last_backup?:array<string,mixed>|null,last_restore?:array<string,mixed>|null,error?:string}
     */
    public static function listBackups(string $user): array {
        $user = trim($user);
        if ($user === '') return ['error' => 'A user is required.'];
        try {
            if (!class_exists('\AICliAgents\Handlers\StorageHandler')) { require_once __DIR__ . '/../handlers/StorageHandler.php'; }
            $backups = \AICliAgents\Handlers\StorageHandler::listBackups($user);
            $backupStatus = \AICliAgents\Handlers\StorageHandler::backupStatusFor($user);
            $restoreStatus = \AICliAgents\Handlers\StorageHandler::restoreStatusFor($user);
            return [
                'list_backups' => $backups,
                'last_backup'  => $backupStatus['last_backup'] ?? null,
                'last_restore' => $restoreStatus['last_restore'] ?? null,
            ];
        } catch (\Throwable $e) {
            return self::failure('listBackups', $e);
        }
    }

    /**
     * Every saved favourite — a bookmark of one workspace's agent and folder
     * (docs/specs/WORKSPACE_FAVOURITES.md R1/R2) — decorated with whether its
     * agent is installed and any open workspace's id. Reuses
     * FavouritesHandler::decorateAll() (the SAME decoration `list_favourites`
     * itself returns) rather than a second copy of that lookup.
     *
     * @return list<array<string,mixed>>|array{error:string}
     */
    public static function listFavourites(): array {
        try {
            if (!class_exists('\AICliAgents\Services\FavouritesService')) {
                require_once __DIR__ . '/FavouritesService.php';
            }
            if (!class_exists('\AICliAgents\Handlers\FavouritesHandler')) {
                require_once __DIR__ . '/../handlers/FavouritesHandler.php';
            }
            $favourites = \AICliAgents\Services\FavouritesService::list();
            return \AICliAgents\Handlers\FavouritesHandler::decorateAll($favourites);
        } catch (\Throwable $e) {
            return self::failure('listFavourites', $e);
        }
    }

    /**
     * The Activity tray, newest first — including anything a Tier-3 tool will
     * eventually leave "waiting" for human approval. ActivityService::
     * listAll() IS the tray's watchdog (it evaluates + persists stalled/failed
     * transitions on every call, per its own doc comment), so calling it here
     * has the same side effect the tray's own poll already has; it is not a
     * new one this method introduces. SupervisorService::syncJobActivities()
     * mirrors any bash-supervisor job-ledger change into a tray entry first,
     * matching ActivityHandler::listActivities() exactly.
     *
     * @param int $limit Most-recent entries to return (clamped to 1..200).
     * @return list<array<string,mixed>>|array{error:string}
     */
    public static function listActivities(int $limit = 25): array {
        try {
            try { SupervisorService::syncJobActivities(); }
            catch (\Throwable $e) { /* best-effort mirror; a stale storage_job_* entry beats no list at all */ }

            $limit = max(1, min(200, $limit));
            $all = ActivityService::listAll();
            // Single-wrapper rule (see class doc): return the list directly.
            // AdminMcpTools::call() supplies the one and only 'activities' key.
            return array_slice($all, 0, $limit);
        } catch (\Throwable $e) {
            return self::failure('listActivities', $e);
        }
    }

    /**
     * The plugin's config keys (ConfigService::getConfig() — defaults merged
     * with the saved .cfg), grouped the way a human would scan the
     * Configuration tab, each with a one-line description. There is no
     * existing grouped/described view of these keys to reuse — the
     * Configuration tab (ui/ManagerConfigTab.php) is hand-built HTML with the
     * descriptions embedded as page text, not as data — so the grouping and
     * descriptions below are curated here, sourced from the inline comments
     * already next to each default in ConfigService::getConfig().
     *
     * @return array<string,mixed>
     */
    public static function getSettings(): array {
        try {
            $config = ConfigService::getConfig();
        } catch (\Throwable $e) {
            return self::failure('getSettings', $e, ['groups' => []]);
        }

        $meta = self::settingDescriptions();
        $groups = [];
        foreach ($config as $key => $value) {
            $key = (string)$key;
            $entry = $meta[$key] ?? ['group' => 'other', 'description' => 'A plugin setting with no admin description yet.'];
            $groups[$entry['group']][] = [
                'key'         => $key,
                'value'       => is_scalar($value) || $value === null ? $value : json_encode($value),
                'description' => $entry['description'],
            ];
        }
        return ['groups' => $groups];
    }

    /**
     * One-line, human-facing description + group for every key
     * ConfigService::getConfig() ships a default for. Kept as a plain map
     * (not a config-key registry service) because Tier 1 is read-only and
     * this is the only method that needs it; if Tier 2's aicli_set_setting
     * grows an allow-list, that allow-list should probably become the single
     * source both draw from instead of duplicating this map.
     *
     * @return array<string,array{group:string,description:string}>
     */
    private static function settingDescriptions(): array {
        return [
            // ---- general ----
            'root_path'      => ['group' => 'general', 'description' => 'The default folder offered when a user creates a new workspace.'],
            'user'           => ['group' => 'general', 'description' => 'The Unraid user account that runs agent sessions.'],
            'history'        => ['group' => 'general', 'description' => 'How many lines of terminal scrollback the UI keeps per session.'],
            'theme'          => ['group' => 'general', 'description' => 'The terminal color theme.'],
            'font_size'      => ['group' => 'general', 'description' => 'The terminal font size, in pixels.'],
            'enable_tab'     => ['group' => 'general', 'description' => "Shows or hides the AI Cli Agents tab in Unraid's top menu."],
            'workspace_cwd'  => ['group' => 'general', 'description' => 'Where a session runs from: the share path (default), or the pool path for a cache-only share.'],
            'first_run_done' => ['group' => 'general', 'description' => 'Marks the first-run setup wizard as already complete.'],

            // ---- storage paths ----
            'home_storage_path'    => ['group' => 'storage', 'description' => "Where the plugin stores each user's persistent home data."],
            'agent_storage_path'   => ['group' => 'storage', 'description' => "Where the plugin stores each agent's persistent install data."],
            'write_protect_agents' => ['group' => 'storage', 'description' => 'Keeps installed agent files read-only between upgrades.'],
            'storage_opt_last_run' => ['group' => 'storage', 'description' => 'Timestamp of the last storage optimization pass.'],

            // ---- schedules ----
            'version_check_schedule'                => ['group' => 'schedules', 'description' => 'How often the plugin checks online for new agent versions.'],
            'version_check_months'                  => ['group' => 'schedules', 'description' => 'How many months of release history the update check keeps.'],
            'health_check_schedule'                 => ['group' => 'schedules', 'description' => 'How often the plugin runs its own health check.'],
            'graceful_close_timeout'                => ['group' => 'schedules', 'description' => 'Seconds the plugin waits for a session to close cleanly before it forces the close.'],
            'event_stopping_flush_timeout_seconds'  => ['group' => 'schedules', 'description' => 'Seconds the plugin waits to flush storage when the Unraid array stops.'],

            // ---- storage durability supervisor ----
            'supervisor_enabled'          => ['group' => 'supervisor', 'description' => 'Turns the storage durability supervisor on or off.'],
            'supervisor_tick_seconds'     => ['group' => 'supervisor', 'description' => 'How often the supervisor checks for work, in seconds.'],
            'bake_schedule_minutes'       => ['group' => 'supervisor', 'description' => 'How often the plugin saves unsaved home directory changes automatically, in minutes.'],
            'emergency_bake_compression'  => ['group' => 'supervisor', 'description' => 'Compression method used for an emergency save.'],
            'storage_target_wait_s'       => ['group' => 'supervisor', 'description' => 'Seconds the supervisor waits for a storage device to finish mounting.'],

            // ---- dirty-data pressure thresholds ----
            'dirty_threshold_soft_mb'      => ['group' => 'dirty_pressure', 'description' => 'Unsaved-data size, in MB, that starts a normal save.'],
            'dirty_threshold_soft_pct'     => ['group' => 'dirty_pressure', 'description' => 'Unsaved-data size, as a percent of the layer budget, that starts a normal save.'],
            'dirty_threshold_hard_mb'      => ['group' => 'dirty_pressure', 'description' => 'Unsaved-data size, in MB, that forces an urgent save.'],
            'dirty_threshold_hard_pct'     => ['group' => 'dirty_pressure', 'description' => 'Unsaved-data size, as a percent of the layer budget, that forces an urgent save.'],
            'dirty_threshold_critical_mb'  => ['group' => 'dirty_pressure', 'description' => 'Unsaved-data size, in MB, that blocks new writes until a save finishes.'],
            'dirty_threshold_critical_pct' => ['group' => 'dirty_pressure', 'description' => 'Unsaved-data size, as a percent of the layer budget, that blocks new writes until a save finishes.'],

            // ---- consolidation ----
            'consolidate_layer_threshold_flash' => ['group' => 'consolidate', 'description' => 'Layer count that starts an automatic consolidate on USB flash storage.'],
            'consolidate_layer_threshold_array' => ['group' => 'consolidate', 'description' => 'Layer count that starts an automatic consolidate on array storage.'],
            'consolidate_max_layers'            => ['group' => 'consolidate', 'description' => 'How many saved layers of a home directory can build up before the plugin must merge them into one.'],

            // ---- boot integrity ----
            'boot_integrity_strict' => ['group' => 'boot_integrity', 'description' => 'Blocks boot when a storage integrity check fails, instead of only warning.'],
            'verify_sha256_on_boot' => ['group' => 'boot_integrity', 'description' => 'Checks file hashes on every boot, instead of only file size and time.'],

            // ---- logging ----
            'debug_logging'            => ['group' => 'logging', 'description' => 'Turns verbose debug logging on or off.'],
            'lifecycle_log_max_bytes'  => ['group' => 'logging', 'description' => 'Maximum size of the lifecycle log, in bytes, before it rotates.'],
            'debug_log_max_bytes'      => ['group' => 'logging', 'description' => 'Maximum size of the debug log, in bytes, before it rotates.'],
            'debug_log_format'         => ['group' => 'logging', 'description' => 'Debug log format: plain text, or one JSON record per line.'],

            // ---- agent voice (AGENT_VOICE.md, VOICE_SWITCHES.md) ----
            'voice_enabled' => ['group' => 'voice', 'description' => 'The global voice switch. Off by default. When off, the aicli_speak tool and the speak command both refuse.'],
            'tts_url'       => ['group' => 'voice', 'description' => 'Text-to-speech engine URL. Empty uses the browser\'s own voice; set to switch every tab to engine audio. Changes only on the Settings page, Agent voice — an agent cannot change it.'],
            'tts_voice'     => ['group' => 'voice', 'description' => 'The voice id to ask the engine for (ignored in browser mode).'],
            'tts_speed'     => ['group' => 'voice', 'description' => 'Speech speed, 0.5 (slower) to 2.0 (faster).'],

            // ---- voice input (VOICE_INPUT.md) — the dictation counterpart of the group above ----
            'stt_url'       => ['group' => 'voice', 'description' => 'Speech-to-text (transcription) engine URL. Empty uses the browser\'s own speech recognition; set to send recordings to a transcription server instead. Changes only on the Settings page, Agent voice — an agent cannot change it.'],
            'stt_model'     => ['group' => 'voice', 'description' => 'The model name to ask the transcription engine for (ignored in browser mode). Must match a model the engine actually has installed.'],
            'stt_language'  => ['group' => 'voice', 'description' => 'Language hint for transcription. Empty lets the engine detect the language on its own.'],

            // ---- voice mail (VOICE_MAIL.md R8) ----
            'voicemail_max_per_workspace' => ['group' => 'voice', 'description' => 'How many voice mail messages to keep for each workspace, 1 to 500. When a workspace has more, the oldest heard messages go first.'],
            'voicemail_max_age_days'      => ['group' => 'voice', 'description' => 'How many days to keep a voice mail message, 1 to 90. Older messages are removed whether they were heard or not.'],
        ];
    }

    /**
     * A bounded, filtered tail of the debug log — the log every service
     * writes to via LogService::log() — returned as STRUCTURED records
     * rather than raw text. The plugin writes this log itself and knows its
     * exact shape (see LogService::log()), so the tool should let a caller
     * query around that known structure instead of handing a model a wall of
     * text to re-parse itself.
     *
     * Each record is shaped:
     *   ['ts' => '2026-09-08 22:24:07', 'level' => 'INFO', 'context' => 'ConfigService',
     *    'trace' => 'a916e8bb'|null, 'message' => 'saveWorkspaces ok: ...']
     * for a line matching LogService::log()'s text format ("[ts] [LEVEL]
     * [context] [t:id] message", trace tag optional) or its JSONL format
     * (debug_log_format=jsonl). A line matching neither shape — a raw
     * multi-line continuation (a stack trace, spliced command output), or
     * anything written by something other than LogService::log() — is never
     * dropped and never throws parseLogLine(): it comes back with
     * ts/level/context/trace all null and the WHOLE raw line as 'message',
     * so it still survives readably instead of vanishing or crashing the
     * tool.
     *
     * The $context filter now matches the PARSED context field
     * (case-insensitive substring) — the whole point of parsing — rather
     * than a regex re-extraction of the same tag on every call.
     *
     * Every record's message is scrubbed through RedactionService::redact()
     * before it leaves this method — the same known-secret + pattern scrub
     * the diagnostics support bundle applies — because an agent's own log
     * line can legitimately contain a value that matches a vault entry (e.g.
     * a failed-auth line echoing what was sent), and secrets must never
     * round-trip through an admin tool.
     *
     * @param string $context Case-insensitive substring match on the parsed
     *                        'context' field. Empty = no filter.
     * @param int    $lines   Records to return after filtering, hard-capped
     *                        at MAX_LOG_LINES regardless of what is requested.
     * @return array<string,mixed>
     */
    public static function getLogs(string $context = '', int $lines = 100): array {
        try {
            $lines = max(1, min(self::MAX_LOG_LINES, $lines ?: 100));
            $logFile = self::LOG_FILES['debug'];
            if (!file_exists($logFile)) {
                return ['records' => [], 'count' => 0];
            }

            // Same 2000-raw-line scan bound TerminalHandler::getLog() uses: a
            // context filter never forces a read of the whole (rotated, so
            // already size-bounded) file.
            $raw = UtilityService::tail($logFile, 2000);
            $records = array_map([self::class, 'parseLogLine'], $raw);

            $context = trim($context);
            if ($context !== '') {
                $needle = strtolower($context);
                $records = array_values(array_filter($records, function (array $rec) use ($needle): bool {
                    return $rec['context'] !== null && str_contains(strtolower($rec['context']), $needle);
                }));
            }

            $records = array_slice($records, -$lines);

            // Loaded once for the whole batch rather than per record —
            // loadKnownSecrets() reads the vault + every workspace secrets
            // file from disk.
            $knownSecrets = RedactionService::loadKnownSecrets();
            foreach ($records as &$rec) {
                $rec['message'] = RedactionService::redact(
                    mb_convert_encoding($rec['message'], 'UTF-8', 'UTF-8'),
                    $knownSecrets
                );
            }
            unset($rec);

            return [
                'records' => $records,
                'count'   => count($records),
            ];
        } catch (\Throwable $e) {
            return self::failure('getLogs', $e, ['records' => [], 'count' => 0]);
        }
    }

    /**
     * Parse one raw debug-log line into a structured record. Handles both
     * formats LogService::log() can write:
     *   text  — "[ts] [LEVEL] [context] [t:traceId] message" (trace tag optional;
     *            LEVEL is one of INFO / ERR! / WARN / DBUG — the exact strings
     *            LogService::log() emits, not a guess)
     *   jsonl — {"ts","lvl","ctx","trace","msg"} (debug_log_format=jsonl)
     * A line matching neither shape is NEVER dropped and NEVER throws: it
     * comes back with ts/level/context/trace all null and the whole raw line
     * as 'message' — see the getLogs() doc comment for why that matters.
     *
     * @return array{ts:?string,level:?string,context:?string,trace:?string,message:string}
     */
    private static function parseLogLine(string $line): array {
        $line = rtrim($line, "\r\n");

        if ($line !== '' && $line[0] === '{') {
            $j = json_decode($line, true);
            if (is_array($j) && array_key_exists('msg', $j)) {
                return [
                    'ts'      => isset($j['ts']) ? (string)$j['ts'] : null,
                    'level'   => isset($j['lvl']) ? (string)$j['lvl'] : null,
                    'context' => isset($j['ctx']) ? (string)$j['ctx'] : null,
                    'trace'   => !empty($j['trace']) ? (string)$j['trace'] : null,
                    'message' => (string)$j['msg'],
                ];
            }
            // Not the expected JSONL shape — falls through to the raw
            // fallback below, same as any other unparseable line.
        }

        // Text format: "[timestamp] [LEVL] [Context] [t:id] message" — the
        // trace tag is optional (TraceContext::getId() may be null).
        if (preg_match('/^\[([^\]]*)\]\s\[(INFO|ERR!|WARN|DBUG)\]\s\[([^\]]*)\](?:\s\[t:([a-z0-9]{4,16})\])?\s(.*)$/s', $line, $m)) {
            return [
                'ts'      => $m[1],
                'level'   => $m[2],
                'context' => $m[3],
                'trace'   => $m[4] !== '' ? $m[4] : null,
                'message' => $m[5],
            ];
        }

        return ['ts' => null, 'level' => null, 'context' => null, 'trace' => null, 'message' => $line];
    }

    // ------------------------------------------------------------------
    // EVENTS (read) — docs/specs/PLUGIN_EVENT_LEDGER_AND_SUBSCRIPTIONS.md
    // (2026-09-11), R3/R4/R5/R8. All three tools are TIER 1 (read): a
    // subscription writes only the caller's own file, never plugin state,
    // and a read never deletes — ack only advances the calling session's own
    // cursor. Every method resolves the session from AICLI_SESSION_ID; there
    // is no caller-supplied session id, the same rule the Relay tools use.
    // ------------------------------------------------------------------

    /**
     * Register the event kinds (and optional filter) this session wants to
     * see. `kinds: []` clears the subscription. `replace: false` merges
     * `kinds` into whatever is already subscribed.
     *
     * @return array<string,mixed>
     */
    public static function subscribeEvents(array $kinds, array $filter = [], bool $replace = true): array {
        try {
            $sid = (string)(getenv('AICLI_SESSION_ID') ?: '');
            if ($sid === '') return ['error' => 'No session id — this tool can only be called from a running workspace.'];
            if (!class_exists('\\AICliAgents\\Services\\EventSubscriptionStore')) {
                return ['error' => 'The event subscription store is unavailable.'];
            }

            $result = EventSubscriptionStore::subscribe($sid, $kinds, $filter, $replace);
            if (!($result['ok'] ?? false)) {
                return ['error' => (string)($result['error'] ?? 'Could not save the subscription.')];
            }

            $head = class_exists('\\AICliAgents\\Services\\EventLedger') ? EventLedger::headSeq() : 0;
            return ['subscription' => $result['subscription'], 'head_seq' => $head];
        } catch (\Throwable $e) {
            return self::failure('subscribeEvents', $e);
        }
    }

    /**
     * Events after `sinceSeq` (default: this session's stored cursor)
     * matching `kinds` (default: the stored subscription) and the stored
     * filter, oldest first. `ack` (default true) advances the cursor to the
     * last event returned. An unsubscribed session with no explicit `kinds`
     * gets an empty result, never the whole ledger.
     *
     * @param string[]|null $kinds
     * @return array<string,mixed>
     */
    public static function getEvents(?int $sinceSeq = null, ?array $kinds = null, int $limit = 100, bool $ack = true): array {
        $empty = ['events' => [], 'next_seq' => 0, 'truncated' => false, 'gap' => false, 'oldest_seq' => 0, 'head_seq' => 0, 'reset' => false];
        try {
            $sid = (string)(getenv('AICLI_SESSION_ID') ?: '');
            if ($sid === '') return ['error' => 'No session id — this tool can only be called from a running workspace.'];
            if (!class_exists('\\AICliAgents\\Services\\EventLedger') || !class_exists('\\AICliAgents\\Services\\EventSubscriptionStore')) {
                return $empty;
            }

            $sub = EventSubscriptionStore::load($sid);
            $effectiveKinds = $kinds !== null ? array_values(array_filter($kinds, 'is_string')) : $sub['kinds'];
            $filter = $sub['filter'];

            $liveBoot = EventLedger::bootId();
            $storedBoot = (string)($sub['cursor']['boot_id'] ?? '');
            $reset = $storedBoot !== '' && $storedBoot !== $liveBoot;
            $since = $sinceSeq ?? ($reset ? 0 : (int)($sub['cursor']['seq'] ?? 0));

            if ($effectiveKinds === []) {
                return [
                    'events' => [], 'next_seq' => $since, 'truncated' => false, 'gap' => false,
                    'oldest_seq' => EventLedger::oldestSeq(), 'head_seq' => EventLedger::headSeq(), 'reset' => $reset,
                ];
            }

            $filterFn = function (array $event) use ($effectiveKinds, $filter): bool {
                $kind = (string)($event['kind'] ?? '');
                $matched = false;
                foreach ($effectiveKinds as $pattern) {
                    if (EventLedger::kindMatches($pattern, $kind)) { $matched = true; break; }
                }
                if (!$matched) return false;
                if (isset($filter['workspaceId']) && (string)($event['subject']['workspaceId'] ?? '') !== $filter['workspaceId']) return false;
                if (isset($filter['agentId'])) {
                    $agentMatch = (string)($event['subject']['agentId'] ?? '') === $filter['agentId']
                        || (string)($event['actor']['agentId'] ?? '') === $filter['agentId'];
                    if (!$agentMatch) return false;
                }
                if (isset($filter['actor']) && (string)($event['actor']['type'] ?? '') !== $filter['actor']) return false;
                return true;
            };

            $limit = max(1, min(500, $limit ?: 100));
            $result = EventLedger::read($since, $limit, $filterFn);
            $result['reset'] = $reset;

            if ($ack && $result['events'] !== []) {
                EventSubscriptionStore::ack($sid, (int)$result['next_seq'], $liveBoot);
            }

            return $result;
        } catch (\Throwable $e) {
            return self::failure('getEvents', $e, $empty);
        }
    }

    /**
     * Set this session's cursor directly (read-then-commit, for a caller
     * that peeked with `ack: false` and now wants to commit what it acted on).
     *
     * @return array<string,mixed>
     */
    public static function ackEvents(int $seq): array {
        try {
            $sid = (string)(getenv('AICLI_SESSION_ID') ?: '');
            if ($sid === '') return ['error' => 'No session id — this tool can only be called from a running workspace.'];
            if (!class_exists('\\AICliAgents\\Services\\EventSubscriptionStore') || !class_exists('\\AICliAgents\\Services\\EventLedger')) {
                return ['error' => 'The event subscription store is unavailable.'];
            }

            $bootId = EventLedger::bootId();
            $seq = max(0, $seq);
            if (!EventSubscriptionStore::ack($sid, $seq, $bootId)) {
                return ['error' => 'Could not save the cursor.'];
            }
            return ['cursor' => ['boot_id' => $bootId, 'seq' => $seq]];
        } catch (\Throwable $e) {
            return self::failure('ackEvents', $e);
        }
    }

    /**
     * Best-effort ledger append for a Tier 2 setter that has no nchan channel
     * of its own (PLUGIN_EVENT_LEDGER_AND_SUBSCRIPTIONS.md R1/R7 — "settings
     * changed" and "one env var changed" are facts with no publisher today).
     * Never throws, and a missing EventLedger (a unit test that loads
     * AdminService in isolation) is silently skipped — the setter's own
     * success is never affected by this being best-effort.
     */
    private static function emitEvent(string $kind, array $subject, string $summary, array $data = []): void {
        if (!class_exists('\\AICliAgents\\Services\\EventLedger')) return;
        try {
            EventLedger::append($kind, $subject, $summary, $data);
        } catch (\Throwable $e) {
            // Best-effort — see doc comment above.
        }
    }

    // ------------------------------------------------------------------
    // TIER 2 — change (docs/specs/PLUGIN_MANAGEMENT_TOOLS.md, 2026-09-09).
    // Every method below WRITES, but only through the exact service call its
    // matching AJAX handler already uses (see the class doc's "Tier 2" note).
    // A refusal returns ['error' => '...'] — the same convention as failure()
    // — never a thrown exception and never a partial write.
    // ------------------------------------------------------------------

    /**
     * The Tier 2 allow-list for aicli_set_setting, spelled out as a constant per
     * PLUGIN_MANAGEMENT_TOOLS.md's non-negotiable #4: "Only keys that are safe for
     * an agent to change." Every key here is a cosmetic/verbosity preference with
     * NO effect on where data lives, who owns it, or when it moves:
     *   - theme / font_size: terminal display only (font_size bounds mirror the
     *     Configuration tab's own <input min="8" max="32">, ManagerConfigTab.php).
     *   - debug_logging / debug_log_format: log verbosity/shape only.
     * Deliberately EXCLUDED, per the spec's own examples and beyond: storage paths
     * (home_storage_path/agent_storage_path), the user account (user), anything
     * that starts a storage/consolidate operation or changes a dirty-data
     * threshold, the supervisor switch/cadence, boot-integrity checks, and BOTH
     * cron-schedule keys — the latter are written verbatim into a crontab file
     * (ConfigService::updateVersionCheckCron/updateHealthCheckCron) with no
     * shell-metacharacter filtering, so accepting a schedule string from a model
     * would be a cron-injection vector, not merely a "coarse-grained" setting.
     * Each value is either an enum (exact string match), a ['min','max'] integer
     * range, a ['minf','maxf'] float range, or a ['regex'=>...,'hint'=>...] pattern
     * (AGENT_VOICE.md R3's three cfg keys — a URL and a voice id are not enum- or
     * integer-range-shaped); enforced by settingAllowedValue() below.
     *
     * AGENT_VOICE.md R3: tts_url/tts_voice/tts_speed are here; tts_api_key is
     * DELIBERATELY absent — it lives only in secrets.cfg and is reachable only
     * from the Manager Settings `save_voice_settings` AJAX action, never from
     * this Tier 2 tool or the CLI.
     *
     * REVIEW_2026-09-13_EVENTS_AND_SECURITY.md S1: `tts_url` MUST stay on this
     * allow-list — VoiceHandler::saveVoiceSettings() (the Settings page's own
     * `save_voice_settings` AJAX action, operator, CSRF-checked) calls THIS
     * method, `AdminService::setSetting('tts_url', …)`, directly, and must
     * keep working exactly as before. The refusal an AGENT sees lives one
     * layer UP, in AdminMcpTools' dispatch of `aicli_set_setting` (and the
     * CLI `set-setting` verb routes through that same dispatch) — it refuses
     * `tts_url` by name, before ever calling this method, so the Settings
     * page's own direct call here is never touched. Do not add a `tts_url`
     * special case in THIS method — a 2026-09-13 attempt to do exactly that
     * broke `save_voice_settings` (VoiceHandlerTest), since it calls this
     * method directly, not through the tool dispatch.
     *
     * VOICE_SWITCHES.md R1: voice_enabled joins the same group — a plain
     * '0'/'1' switch, so it uses the enum-array rule shape like debug_logging,
     * not tts_url's regex or tts_speed's float-range.
     *
     * VOICE_INPUT.md R1: stt_url/stt_model/stt_language are the input-side
     * counterparts — stt_url mirrors tts_url's own regex+hint shape exactly
     * (same URL grammar, same allowEmpty=browser-mode meaning). stt_model
     * WIDENS the plain `\w`/`.`/`-` shape the requirement first named to also
     * allow a `/` (2026-09-13, after verifying the recommended engine): a
     * self-hosted transcription engine such as Speaches names its models
     * with a vendor path, e.g. `Systran/faster-whisper-small` — that is the
     * exact, real value the README tells an operator to type into the Model
     * field, and it must round-trip through this SAME allow-list
     * `save_voice_settings` (the Settings page) calls, so the regex must
     * accept it; still no whitespace or shell/HTML metacharacters. `#...#`
     * delimiters (not `/.../`) because the pattern itself now contains a
     * literal `/`. stt_language a short IETF-ish tag or empty (detect). Like
     * tts_url (S1 above), stt_url STAYS on this allow-list for the Settings
     * page's own direct `AdminService::setSetting('stt_url', …)` call — the
     * AGENT-facing refusal is a TOOL-layer check in AdminMcpTools' dispatch
     * of `aicli_set_setting`, mirroring the tts_url refusal exactly.
     *
     * @var array<string,array<int,string>|array{min:int,max:int}|array{minf:float,maxf:float}|array{regex:string,hint:string,allowEmpty?:bool}>
     */
    private const SETTINGS_ALLOWLIST = [
        'theme'            => ['dark', 'light', 'solarized'],
        'font_size'        => ['min' => 8, 'max' => 32],
        'debug_logging'    => ['0', '1'],
        'debug_log_format' => ['text', 'jsonl'],
        'voice_enabled'    => ['0', '1'],
        'tts_url'          => ['regex' => '#^https?://[^\s/]+(/v1)?/?$#i', 'allowEmpty' => true, 'hint' => "empty (browser mode), or an http(s) URL with no path beyond an optional '/v1'"],
        'tts_voice'        => ['regex' => '/^[a-z][a-z0-9_]{0,63}$/', 'hint' => 'a lowercase voice id (letters, digits, underscore)'],
        'tts_speed'        => ['minf' => 0.5, 'maxf' => 2.0],
        'stt_url'          => ['regex' => '#^https?://[^\s/]+(/v1)?/?$#i', 'allowEmpty' => true, 'hint' => "empty (browser mode), or an http(s) URL with no path beyond an optional '/v1'"],
        'stt_model'        => ['regex' => '#^[\w.\-/]{1,64}$#', 'hint' => "a model name the engine lists (letters, digits, . _ - and, for a vendor-style id, /, e.g. Systran/faster-whisper-small)"],
        'stt_language'     => ['regex' => '/^[a-z]{2,3}(-[A-Za-z]{2,4})?$/', 'allowEmpty' => true, 'hint' => 'empty (detect), or a short language tag such as en or en-US'],
        // VOICE_MAIL.md R8: a retention POLICY for kept messages (text only, a few
        // hundred bytes each). The upper bounds keep the store small on Flash;
        // VoiceMailService::HARD_MAX_TOTAL still caps the whole store at 2000.
        'voicemail_max_per_workspace' => ['min' => 1, 'max' => 500],
        'voicemail_max_age_days'      => ['min' => 1, 'max' => 90],

        // HOME_BACKUP.md #287: since the per-home redesign these six global keys
        // are only the SEED a home with no settings file of its own inherits
        // once (HomeBackupSettingsService); they never overwrite a home's own
        // settings.
        // HOME_BACKUP.md "Settings (cfg keys)": these six are lower-risk than the
        // EXCLUDED home_storage_path/agent_storage_path above — a backup target is
        // a DESTINATION for a copy, never where live data lives, so a bad value
        // here cannot strand a session's real home the way a bad storage path
        // could. backup_schedule is deliberately NOT a raw cron string (unlike
        // version_check_schedule/health_check_schedule, which this class's own
        // header comment excludes for exactly that reason) — its tight regex
        // accepts only 'off'/'daily:HH:MM'/'weekly:D:HH:MM', which
        // BackupCronService::parseScheduleToCron() then turns into the real cron
        // expression server-side, so there is no cron-injection surface here.
        'backup_target'       => ['regex' => '#^/\S+$#', 'allowEmpty' => true, 'hint' => "empty, or an absolute path with no whitespace to a non-FUSE backup target (the default a home without backup settings of its own inherits once; each home's own folder is set with Backup on its Home card)"],
        'backup_quiesce'      => ['cold', 'warm'],
        'backup_keep'         => ['min' => 1, 'max' => 50],
        'backup_schedule'     => ['regex' => '#^(off|daily:([01]\d|2[0-3]):([0-5]\d)|weekly:[0-6]:([01]\d|2[0-3]):([0-5]\d))$#', 'hint' => "'off', 'daily:HH:MM', or 'weekly:D:HH:MM' (D is 0-6, Sunday=0)"],
        'backup_excludes'     => ['regex' => "#^[A-Za-z0-9_./*\r\n -]*$#", 'allowEmpty' => true, 'hint' => 'a newline-separated list of relative glob patterns (letters, digits, . / * - _ and spaces only)'],
        'backup_nudge_working' => ['0', '1'],

        // WORKSPACE_UPLOAD_MULTI_CHUNKED.md R3: a size POLICY only — it never
        // moves data or changes where it lives, so it belongs in this
        // cosmetic/verbosity-style group. A whole number of bytes, or '0' for
        // no cap (the Settings page shows/edits it in MB and converts).
        'upload_max_bytes' => ['regex' => '/^[0-9]+$/', 'hint' => 'a whole number of bytes, or 0 for no limit'],
    ];

    /**
     * Every workspace record ConfigService::getWorkspaces() knows about, keyed by
     * id, or null if $id is empty/unknown. Shared by every Tier 2 workspace method
     * so "no such workspace" is refused the same way everywhere — this is also
     * what testCreate/UpdateWorkspaceRefuseUnknownTarget-style tests exercise.
     *
     * @return array<string,mixed>|null
     */
    private static function findSession(string $id): ?array {
        if ($id === '') return null;
        foreach ((ConfigService::getWorkspaces()['sessions'] ?? []) as $w) {
            if (is_array($w) && (string)($w['id'] ?? '') === $id) return $w;
        }
        return null;
    }

    /**
     * VOICE_SWITCHES.md: a workspace's own voice switch — `voice` on its
     * record, default true when absent (an older registry, or a workspace
     * nobody has ever muted). Strict `=== false` on purpose: only a real,
     * explicit false mutes — any other stored shape (missing, true, or a
     * malformed value from a hand-edited file) stays enabled.
     *
     * @param array<string,mixed> $record
     */
    private static function workspaceVoiceEnabled(array $record): bool {
        return ($record['voice'] ?? true) !== false;
    }

    /** True when $agentId names a real entry in the registry (installed or not — a channel/auto-launch preference may legitimately be set before install). */
    private static function agentExists(string $agentId): bool {
        if ($agentId === '') return false;
        return array_key_exists($agentId, AgentRegistry::getRegistry());
    }

    /**
     * The calling agent's own identity, resolved from AICLI_SESSION_ID exactly the
     * way getWorkspace('') resolves "myself" — used ONLY for Activity-tray
     * attribution (PLUGIN_MANAGEMENT_TOOLS.md "Audit": "naming the workspace and
     * agent that asked"), never for authorization. Best-effort: an unresolvable
     * caller (no session id, e.g. a remote client) still gets a tray entry, just
     * with 'unknown' fields, rather than blocking the audit write entirely.
     *
     * @return array{workspaceId:string,agentId:string,name:string}
     */
    public static function callerIdentity(): array {
        $sid = (string)(getenv('AICLI_SESSION_ID') ?: '');
        if ($sid === '') return ['workspaceId' => '', 'agentId' => '', 'name' => ''];
        try {
            $record = self::findSession($sid);
        } catch (\Throwable $e) {
            return ['workspaceId' => $sid, 'agentId' => '', 'name' => ''];
        }
        if ($record === null) return ['workspaceId' => $sid, 'agentId' => '', 'name' => ''];
        return [
            'workspaceId' => $sid,
            'agentId'     => (string)($record['agentId'] ?? ''),
            'name'        => (string)($record['name'] ?? ''),
        ];
    }

    /**
     * Create a new workspace. Reuses ConfigService::saveWorkspaces() — the SAME
     * merge-based writer UtilityHandler::saveWorkspaces() (the drawer's own
     * save_workspaces AJAX action) calls; only the one new record is submitted,
     * relying on mergeWorkspaceSnapshot()'s documented union semantics ("omission
     * is not a deletion") to leave every other workspace untouched.
     *
     * PATH VALIDATION (non-negotiable #3): $path is a filesystem path from a
     * MODEL, not a human clicking a picker the way the UI's own create flow works.
     * It is routed through ValidationService::validatePath() — the same allowlist
     * (`/mnt`, `/home`, `/root`, the plugin's own flash/tmp dirs) UtilityHandler
     * uses for checkPath()/createDirectory()/rawFiletree() — AND, unlike a
     * not-yet-created path, must already exist as a real, readable directory: the
     * WorkspacePicker only ever lets a human "Open" a folder that is already
     * there, never a path that doesn't exist yet, so a tool speaking for a human
     * refuses rather than guessing whether an absent path was a typo or an attempt
     * to reach outside the allowlist.
     *
     * WORKSPACE_FAVOURITES.md R7: $favouriteId is optional. When given, it fills
     * $agentId, $path, $name (and the new record's own voice switch) from the
     * saved favourite — any of $agentId/$path/$name the caller ALSO passed
     * explicitly wins over the favourite's own value, the same "explicit
     * argument overrides" rule VOICE_SWITCHES.md's own setters follow. An
     * unknown $favouriteId refuses before any path/agent validation runs. A
     * successful create from a favourite calls FavouritesService::touch() —
     * the SAME bump `touch_favourite` gives it when a human opens it from the
     * drawer — so "most recently opened" ordering treats a tool-driven open
     * exactly like a click.
     *
     * @return array<string,mixed>
     */
    public static function createWorkspace(string $path, string $agentId, string $name = '', ?string $favouriteId = null): array {
        try {
            $favourite = null;
            $voice = null;
            if ($favouriteId !== null && $favouriteId !== '') {
                if (!class_exists('\AICliAgents\Services\FavouritesService')) {
                    require_once __DIR__ . '/FavouritesService.php';
                }
                $favourite = \AICliAgents\Services\FavouritesService::get($favouriteId);
                if ($favourite === null) {
                    return ['error' => "No favourite with id '$favouriteId' was found."];
                }
                if ($agentId === '') $agentId = (string)$favourite['agentId'];
                if ($path === '') $path = (string)$favourite['path'];
                if ($name === '') $name = (string)$favourite['name'];
                $voice = (bool)($favourite['voice'] ?? true);
            }

            if (!self::agentExists($agentId)) {
                return ['error' => "Unknown agent '$agentId'. Ask aicli_list_agents for valid ids."];
            }
            $resolved = ValidationService::validatePath($path);
            if ($resolved === false || !is_dir($resolved) || !is_readable($resolved)) {
                return ['error' => "'$path' does not resolve to an existing, readable folder inside an allowed location. Create the folder first (e.g. from the Manager UI), then try again."];
            }

            $name = trim($name);
            if ($name === '') {
                $name = $resolved === '/' ? 'Root' : (basename($resolved) ?: 'Workspace');
            }

            $id = 's' . bin2hex(random_bytes(4));
            // Astronomically unlikely, but a colliding id would silently overwrite
            // another workspace's record via the merge — regenerate once rather
            // than trust randomness blindly.
            if (self::findSession($id) !== null) $id = 's' . bin2hex(random_bytes(4));

            // WORKSPACE_LIFECYCLE_EVENTS.md R5: every workspace record names who
            // created it and when. The caller is either the agent behind
            // AICLI_SESSION_ID, or 'human' when this runs with no session (a
            // remote client, or a test). ConfigService::mergeWorkspaceSnapshot()
            // would stamp the same defaults for a browser-created record that
            // omits them; setting them here means an admin-tool creation never
            // depends on that fallback running first.
            $createdBy = self::callerIdentity()['workspaceId'];
            $record = [
                'id' => $id, 'name' => $name, 'path' => $resolved, 'agentId' => $agentId, 'lastActive' => null,
                'createdBy' => $createdBy !== '' ? $createdBy : 'human', 'createdAt' => time(),
            ];
            if ($voice !== null) $record['voice'] = $voice;
            $ok = ConfigService::saveWorkspaces(['sessions' => [$record]], []);
            if (!$ok) {
                return ['error' => ConfigService::lastWorkspaceSaveMessage() ?? 'Could not save the new workspace.'];
            }
            if ($favourite !== null) {
                \AICliAgents\Services\FavouritesService::touch($favouriteId);
            }
            return $record;
        } catch (\Throwable $e) {
            return self::failure('createWorkspace', $e);
        }
    }

    /**
     * Rename a workspace, move it to a different path, switch its agent, and/or
     * reorder it among the other workspaces — one tool for all four, since they
     * are all just fields on the same saved record. Unlike createWorkspace(), a
     * reorder needs the FULL session list submitted in the desired order (the
     * merge in ConfigService::mergeWorkspaceSnapshot() only respects the order of
     * entries actually present in the write), so this method always writes the
     * whole list back — simpler than branching on "did the caller ask to
     * reorder" and correct either way.
     *
     * $order is a zero-based target index among all workspaces; out-of-range
     * values clamp rather than refuse (an agent asking for "move to the end" by
     * passing a large number is a reasonable, harmless request).
     *
     * VOICE_SWITCHES.md R5: $voice sets the workspace's own voice switch — a
     * plain bool, already parsed from `on|off|true|false|1|0` by the caller
     * (AdminMcpTools::call(), the same filter_var(FILTER_VALIDATE_BOOLEAN)
     * convention aicli_set_auto_launch's autoLaunch/freshIfNoResume already
     * use). null leaves the field untouched, same as every other parameter.
     *
     * VOICE_MAIL.md R14: $spokenName sets the name the workspace is announced by
     * before each spoken message; '' clears it (the display name is used).
     *
     * AGENT_VOICE.md R14: $voiceId sets the workspace's own engine voice; '' clears
     * it (the Settings default is used). A value with the wrong shape is refused.
     *
     * @return array<string,mixed>
     */
    public static function updateWorkspace(string $id, ?string $name = null, ?string $path = null, ?string $agentId = null, ?int $order = null, ?bool $voice = null, ?string $spokenName = null, ?string $voiceId = null): array {
        try {
            $sessions = ConfigService::getWorkspaces()['sessions'] ?? [];
            $index = null;
            foreach ($sessions as $i => $w) {
                if (is_array($w) && (string)($w['id'] ?? '') === $id) { $index = $i; break; }
            }
            if ($index === null) {
                return ['error' => "No workspace with id '$id' was found."];
            }

            $record = $sessions[$index];
            if ($name !== null) {
                $name = trim($name);
                if ($name === '') return ['error' => 'name cannot be blank.'];
                $record['name'] = $name;
            }
            if ($path !== null) {
                $resolved = ValidationService::validatePath($path);
                if ($resolved === false || !is_dir($resolved) || !is_readable($resolved)) {
                    return ['error' => "'$path' does not resolve to an existing, readable folder inside an allowed location."];
                }
                $record['path'] = $resolved;
            }
            if ($agentId !== null) {
                if (!self::agentExists($agentId)) {
                    return ['error' => "Unknown agent '$agentId'. Ask aicli_list_agents for valid ids."];
                }
                $record['agentId'] = $agentId;
            }
            if ($voice !== null) {
                $record['voice'] = $voice;
                // VOICE_MAIL.md R11: a stored voice_mode wins over the old flag
                // (VoiceMailService::modeFor), so writing only the flag would leave
                // a workspace set to Speak still speaking after "voice off". Move
                // the mode with it: off is Off, on is Speak.
                $record['voice_mode'] = $voice ? 'speak' : 'off';
            }
            if ($spokenName !== null) {
                // VOICE_MAIL.md R14 (Forgejo #376): the name the workspace is
                // announced by ("<spoken name> says:"). '' clears it.
                $record['spoken_name'] = VoiceMailService::normaliseSpokenName($spokenName);
            }
            if ($voiceId !== null) {
                // AGENT_VOICE.md R14 (Forgejo #377): the workspace's own engine
                // voice, the same id rule as the Settings field. '' clears it.
                $voiceId = trim($voiceId);
                if ($voiceId !== '' && !VoiceService::isValidVoiceId($voiceId)) {
                    return ['error' => "'$voiceId' is not a valid voice id. Use lowercase letters, digits and underscores, starting with a letter (for example af_heart)."];
                }
                $record['tts_voice'] = $voiceId;
            }

            // Remove the (unmodified-position) old entry, then reinsert the updated
            // $record either back at its own index (no reorder asked for — this
            // reproduces its original position exactly, since removing first and
            // reinserting at the same index shifts nothing relative to its
            // neighbours) or at the clamped target index (reorder asked for).
            $sessions = array_values($sessions);
            array_splice($sessions, $index, 1);
            $target = $order === null ? $index : max(0, min(count($sessions), $order));
            array_splice($sessions, $target, 0, [$record]);

            $workspaces = ConfigService::getWorkspaces();
            $ok = ConfigService::saveWorkspaces(['sessions' => $sessions, 'activeId' => $workspaces['activeId'] ?? null], []);
            if (!$ok) {
                return ['error' => ConfigService::lastWorkspaceSaveMessage() ?? 'Could not save the workspace change.'];
            }
            return $record;
        } catch (\Throwable $e) {
            return self::failure('updateWorkspace', $e);
        }
    }

    /**
     * Set a workspace's saved CLI arguments — reuses ArgsService::saveWorkspaceArgs(),
     * the exact call ArgsHandler::saveWorkspaceArgs() (the `save_workspace_args`
     * AJAX action) makes, including its own char-allowlist validation
     * (ArgsService::validateArgs()) so this tool cannot accept anything the UI's
     * own editor would reject.
     *
     * @return array<string,mixed>
     */
    public static function setWorkspaceArgs(string $id, string $args): array {
        try {
            $session = self::findSession($id);
            if ($session === null) {
                return ['error' => "No workspace with id '$id' was found."];
            }
            $path = (string)($session['path'] ?? '');
            $agentId = (string)($session['agentId'] ?? '');
            if ($path === '' || $agentId === '') {
                return ['error' => "Workspace '$id' has no path/agent on record; cannot set args."];
            }

            $args = trim($args);
            $errors = ArgsService::validateArgs($args);
            if ($errors) {
                return ['error' => 'Rejected characters: ' . implode(', ', $errors)];
            }

            $ok = ArgsService::saveWorkspaceArgs($path, $agentId, $args);
            if (!$ok) return ['error' => 'Write failed.'];
            // Args are not secrets — the value is safe to carry in the ledger.
            self::emitEvent('agent.args', ['workspaceId' => $id, 'agentId' => $agentId], "Arguments changed for workspace '$id'", ['args' => $args]);
            return ['id' => $id, 'args' => $args];
        } catch (\Throwable $e) {
            return self::failure('setWorkspaceArgs', $e);
        }
    }

    /**
     * Set (or clear, with an empty $value) one environment variable for a
     * workspace. $secret routes the write to the vault (SecretService::
     * saveWorkspaceSecrets(), UPPER_SNAKE keys only — the same shape
     * EnvHandler::saveWorkspaceSecrets() enforces) instead of the general env
     * store (EnvService::saveWorkspaceEnvs()) — both are wholesale-replace
     * writers, so this method reads the current map, applies the one-key delta,
     * and writes the whole map back, exactly like EnvHandler's own handlers do
     * for a single free-form key.
     *
     * SECRETS NEVER ROUND-TRIP (non-negotiable #2): the return value never
     * carries $value back, for EITHER $secret=true or $secret=false — an admin
     * tool result goes straight into a model's context, and this codebase's rule
     * (AdminService class doc; AdminToolsTest's
     * testGetWorkspaceNeverReturnsASecretValueOnlyItsKeyName) is that no tool
     * echoes a value, not just a secret one.
     *
     * @return array<string,mixed>
     */
    public static function setWorkspaceEnv(string $id, string $key, string $value, bool $secret = false): array {
        try {
            $session = self::findSession($id);
            if ($session === null) {
                return ['error' => "No workspace with id '$id' was found."];
            }
            $path = (string)($session['path'] ?? '');
            $agentId = (string)($session['agentId'] ?? '');
            if ($path === '' || $agentId === '') {
                return ['error' => "Workspace '$id' has no path/agent on record; cannot set an env var."];
            }

            if ($secret) {
                if (!preg_match('/^[A-Z][A-Z0-9_]{0,127}$/', $key)) {
                    return ['error' => "Invalid secret name '$key' — secrets must be UPPER_SNAKE (A-Z, 0-9, _)."];
                }
                if (EnvService::isReservedKey($key)) {
                    return ['error' => "'$key' is a reserved name managed by the plugin runtime."];
                }
                $current = SecretService::getWorkspaceSecrets($path, $agentId);
                if ($value === '') { unset($current[$key]); } else { $current[$key] = EnvService::sanitiseValue($value); }
                $ok = SecretService::saveWorkspaceSecrets($path, $agentId, $current);
            } else {
                if (!EnvService::validateKey($key)) {
                    return ['error' => "Invalid or reserved name '$key' — see aicli_get_workspace's env_keys for what is already set."];
                }
                $current = EnvService::getWorkspaceEnvs($path, $agentId);
                if ($value === '') { unset($current[$key]); } else { $current[$key] = EnvService::sanitiseValue($value); }
                $ok = EnvService::saveWorkspaceEnvs($path, $agentId, $current);
            }
            if (!$ok) return ['error' => 'Write failed.'];

            // Key NAME and a changed flag only — never the value, secret or not.
            self::emitEvent('agent.env', ['workspaceId' => $id, 'agentId' => $agentId], "Environment variable '$key' changed for workspace '$id'", ['key' => $key, 'changed' => true]);
            return ['id' => $id, 'key' => $key, 'secret' => $secret, 'action' => $value === '' ? 'cleared' : 'set'];
        } catch (\Throwable $e) {
            return self::failure('setWorkspaceEnv', $e);
        }
    }

    /**
     * Set an agent's auto-launch preference. Auto-launch is AGENT-level, not
     * per-workspace (AutoLaunchHandler's own "R-C1" note: the flag governs every
     * workspace of that agent) — reuses ConfigService::setAgentAutoLaunch(), the
     * exact call AutoLaunchHandler::saveAutoLaunch() (the `save_auto_launch` AJAX
     * action) makes.
     *
     * @return array<string,mixed>
     */
    public static function setAutoLaunch(string $agentId, bool $autoLaunch, bool $freshIfNoResume = false): array {
        try {
            if (!self::agentExists($agentId)) {
                return ['error' => "Unknown agent '$agentId'. Ask aicli_list_agents for valid ids."];
            }
            $ok = ConfigService::setAgentAutoLaunch($agentId, $autoLaunch, $freshIfNoResume);
            if (!$ok) return ['error' => 'Write failed.'];
            self::emitEvent('agent.autolaunch', ['agentId' => $agentId], "Auto-launch " . ($autoLaunch ? 'enabled' : 'disabled') . " for '$agentId'", ['autoLaunch' => $autoLaunch, 'freshIfNoResume' => $freshIfNoResume]);
            return ['agentId' => $agentId, 'autoLaunch' => $autoLaunch, 'freshIfNoResume' => $freshIfNoResume];
        } catch (\Throwable $e) {
            return self::failure('setAutoLaunch', $e);
        }
    }

    /**
     * SCHEDULED_CONTINUE.md (#234) Tier 2. Schedule a Continue for a workspace at
     * a wall-clock time, one-shot or recurring. `at` is a unix epoch (the caller
     * resolves "3pm" etc.); `repeat` is none|daily|weekly. The supervisor tick
     * fires it through the readiness-gated Continue path. Stored in a sidecar so a
     * browser workspaces.json save can never drop it.
     *
     * @param string $source `user` for an operator schedule or `quota-detect`
     *                       for a terminal quota detector.
     * @return array<string,mixed>
     */
    public static function setScheduledContinue(string $sessionId, int $at, string $repeat = 'none', string $message = '', string $source = 'user'): array {
        try {
            $sessionId = trim($sessionId);
            if ($sessionId === '') return ['error' => 'A workspace id is required.'];
            if (self::findSession($sessionId) === null) {
                return ['error' => "No workspace '$sessionId'. Ask aicli_list_workspaces for valid ids."];
            }
            if ($at <= time()) return ['error' => 'The scheduled time must be in the future (pass a unix epoch).'];
            if (!in_array($repeat, ['none', 'daily', 'weekly'], true)) {
                return ['error' => "'$repeat' is not a valid repeat. Use none, daily or weekly."];
            }
            if (!in_array($source, ['user', 'quota-detect'], true)) {
                return ['error' => "'$source' is not a valid schedule source."];
            }
            $entry = ['at' => $at, 'repeat' => $repeat, 'source' => $source, 'created_at' => time(), 'last_fired_at' => 0];
            if ($message !== '') $entry['message'] = $message;
            if (!ConfigService::setScheduledContinue($sessionId, $entry)) return ['error' => 'Write failed.'];
            self::emitEvent('workspace.scheduled_continue', ['id' => $sessionId], "Scheduled continue set for '$sessionId'", ['at' => $at, 'repeat' => $repeat]);
            return ['workspaceId' => $sessionId, 'at' => $at, 'repeat' => $repeat, 'scheduled' => true];
        } catch (\Throwable $e) {
            return self::failure('setScheduledContinue', $e);
        }
    }

    /**
     * SCHEDULED_CONTINUE.md (#234) Tier 2. Remove a workspace's scheduled Continue.
     *
     * @return array<string,mixed>
     */
    public static function clearScheduledContinue(string $sessionId): array {
        try {
            $sessionId = trim($sessionId);
            if ($sessionId === '') return ['error' => 'A workspace id is required.'];
            ConfigService::clearScheduledContinue($sessionId);
            self::emitEvent('workspace.scheduled_continue', ['id' => $sessionId], "Scheduled continue cleared for '$sessionId'", ['cleared' => true]);
            return ['workspaceId' => $sessionId, 'cleared' => true];
        } catch (\Throwable $e) {
            return self::failure('clearScheduledContinue', $e);
        }
    }

    /**
     * HOME_PERSIST_CONSOLIDATE_TOOLS.md Tier 2 (2026-09-17). Queue a persist
     * (bake) of one user's home: the unsaved changes are written to a new
     * layer on the persistence target now and, once no session holds the home,
     * the RAM/upper copy is reclaimed. Closes NO session — which is exactly why
     * this is a change tool and aicli_consolidate_home is not. Hands off to
     * StorageHandler::persistHome(), the SAME entry point the Storage tab's
     * Persist button uses, so the job and its tray pill are identical.
     *
     * @return array<string,mixed>
     */
    public static function persistHome(string $user = ''): array {
        try {
            $resolved = self::resolveHomeUser($user);
            if (isset($resolved['error'])) return $resolved;
            $u = (string)$resolved['user'];
            if (!class_exists('\\AICliAgents\\Services\\ConsolidateState')) { require_once __DIR__ . '/ConsolidateState.php'; }
            if (ConsolidateState::isHomeConsolidating($u)) {
                return ['error' => "$u's home is being consolidated right now; a persist would only queue behind it. Wait for the consolidate to finish (aicli_list_activities shows it)."];
            }
            if (!class_exists('\\AICliAgents\\Handlers\\StorageHandler')) { require_once __DIR__ . '/../handlers/StorageHandler.php'; }
            $result = \AICliAgents\Handlers\StorageHandler::persistHome($u);
            if (($result['status'] ?? '') !== 'ok') {
                return ['error' => (string)($result['message'] ?? 'The persist could not be queued.')];
            }
            return [
                'user'    => $u,
                'jobId'   => (string)($result['job_id'] ?? ''),
                'queued'  => true,
                'message' => 'Persist queued. The supervisor saves the home to its storage layers shortly. If a session still holds the home, the data is saved but the RAM copy is only reclaimed once every session on it closes — do not call this again in a loop; watch the Activity tray.',
            ];
        } catch (\Throwable $e) {
            return self::failure('persistHome', $e);
        }
    }

    /**
     * Resolve '' / '0' to the configured home user and confirm that home
     * exists in the storage status (the same `homes` map the Storage tab
     * draws its cards from). Shared by the persist and consolidate tools.
     *
     * @return array{user:string,home:array<string,mixed>}|array{error:string}
     */
    private static function resolveHomeUser(string $user): array {
        $user = trim($user);
        if ($user === '' || $user === '0') {
            $cfg = ConfigService::getConfig();
            $user = (string)($cfg['user'] ?? 'root');
            if ($user === '' || $user === '0') $user = 'root';
        }
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $user)) {
            return ['error' => "'$user' is not a valid user name."];
        }
        try {
            $status = StorageMetricsService::getStatus();
            $homes = is_array($status['homes'] ?? null) ? $status['homes'] : [];
        } catch (\Throwable $e) {
            return self::failure('resolveHomeUser', $e);
        }
        if (!array_key_exists($user, $homes)) {
            return ['error' => "'$user' has no home to persist or consolidate."];
        }
        return ['user' => $user, 'home' => is_array($homes[$user]) ? $homes[$user] : []];
    }

    /**
     * Set an agent's release channel (and, for 'pinned', which version). Mirrors
     * AgentHandler::setAgentChannel() (the `set_agent_channel` AJAX action) step
     * for step, including its own channel-name validation and its
     * pin-to-currently-installed fallback, and the same clearNotification() +
     * LifecycleLogService audit line it already writes — this tool does not
     * invent a second history of channel changes.
     *
     * @return array<string,mixed>
     */
    public static function setAgentChannel(string $agentId, string $channel, ?string $pinned = null): array {
        try {
            if (!self::agentExists($agentId)) {
                return ['error' => "Unknown agent '$agentId'. Ask aicli_list_agents for valid ids."];
            }
            $channel = strtolower(trim($channel));
            if ($pinned === '') $pinned = null;
            if (!in_array($channel, ['stable', 'latest', 'beta', 'pinned'], true)) {
                return ['error' => 'Unsupported release channel.'];
            }
            $channel = AgentRegistry::normalizeChannel($channel);
            if ($channel === 'pinned' && $pinned === null) {
                $installed = AgentRegistry::getInstalledVersion($agentId);
                if (in_array($installed, ['', '0.0.0', 'unknown', 'installed'], true)) {
                    return ['error' => 'Choose an installed version before pinning.'];
                }
                $pinned = $installed;
            }

            if (!AgentRegistry::setChannel($agentId, $channel, $pinned)) {
                return ['error' => 'Could not save the release channel.'];
            }
            if (AgentRegistry::getChannel($agentId) !== $channel
                || ($channel === 'pinned' && AgentRegistry::getPinned($agentId) !== $pinned)) {
                return ['error' => 'The release channel did not persist.'];
            }
            VersionCheckService::clearNotification($agentId);
            LifecycleLogService::log(LifecycleLogService::LEVEL_INFO, 'agent_registry', 'agent_channel_set', ['agent' => $agentId, 'channel' => $channel, 'pinned' => $pinned, 'via' => 'admin_tool']);

            self::emitEvent('agent.channel', ['agentId' => $agentId], "Release channel for '$agentId' set to '$channel'", ['channel' => $channel, 'pinned' => $pinned]);
            return ['agentId' => $agentId, 'channel' => $channel, 'pinned' => $pinned];
        } catch (\Throwable $e) {
            return self::failure('setAgentChannel', $e);
        }
    }

    /**
     * Trigger the same version-availability check the Store tab's "Check for
     * updates" button runs (AgentHandler::checkUpdates() -> the global
     * checkAgentUpdates() wrapper -> AgentRegistry::checkUpdates()). Reads only
     * from each source's own update-check path (npm dist-tags / GitHub releases
     * / a custom index) — no plugin state changes as a result of asking; the
     * result is cached the same way the UI's own check already caches it.
     *
     * @return array<string,mixed>
     */
    public static function checkUpdates(): array {
        try {
            $result = AgentRegistry::checkUpdates();
            return is_array($result) ? $result : ['updates' => []];
        } catch (\Throwable $e) {
            return self::failure('checkUpdates', $e, ['updates' => []]);
        }
    }

    /** Human-readable allow-list, used both by the refusal message and by tests. @return string[] */
    public static function allowedSettingKeys(): array {
        return array_keys(self::SETTINGS_ALLOWLIST);
    }

    /** True when $value is acceptable for the already-allow-listed $key. */
    private static function settingValueAllowed(string $key, string $value): bool {
        $rule = self::SETTINGS_ALLOWLIST[$key] ?? null;
        if ($rule === null) return false;
        if (isset($rule['min'], $rule['max'])) {
            return is_numeric($value) && (int)$value >= $rule['min'] && (int)$value <= $rule['max'];
        }
        if (isset($rule['minf'], $rule['maxf'])) {
            return is_numeric($value) && (float)$value >= $rule['minf'] && (float)$value <= $rule['maxf'];
        }
        if (isset($rule['regex'])) {
            if ($value === '') return !empty($rule['allowEmpty']);
            return (bool)preg_match($rule['regex'], $value);
        }
        return in_array($value, $rule, true);
    }

    /** Human-facing "allowed" description for the setSetting() refusal message, matching whichever rule shape $key uses. */
    private static function settingAllowedDescription(array $rule): string {
        if (isset($rule['min'], $rule['max'])) return "an integer {$rule['min']}-{$rule['max']}";
        if (isset($rule['minf'], $rule['maxf'])) return "a number {$rule['minf']}-{$rule['maxf']}";
        if (isset($rule['regex'])) return (string)($rule['hint'] ?? 'a value matching the required pattern');
        return implode('|', $rule);
    }

    /**
     * Change ONE plugin config key — never the whole file (non-negotiable #4).
     * $key must be on SETTINGS_ALLOWLIST; anything else is refused with a message
     * naming the tier, per the spec's instruction that the refusal must say so
     * rather than reading as a generic validation error. Writes via
     * ConfigService::saveConfig() with a SINGLE-key array — saveConfig() merges
     * ($config = array_merge($config, $newConfig)), so passing only the one
     * changed key is sufficient and cannot clobber any other setting, unlike the
     * Configuration tab's own save which posts the whole form.
     *
     * REVIEW_2026-09-13_EVENTS_AND_SECURITY.md S1: this method still ACCEPTS
     * `tts_url` — it is the Settings page's own direct call path
     * (VoiceHandler::saveVoiceSettings()) and must keep working unchanged.
     * The refusal an AGENT tool call sees lives at the TOOL layer, in
     * AdminMcpTools' dispatch of `aicli_set_setting`, one level above this
     * method — see that dispatch case for the one-sentence message.
     *
     * @param scalar $value
     * @return array<string,mixed>
     */
    public static function setSetting(string $key, $value): array {
        try {
            if (!array_key_exists($key, self::SETTINGS_ALLOWLIST)) {
                return ['error' => "'$key' is not on the Tier 2 settings allow-list (docs/specs/PLUGIN_MANAGEMENT_TOOLS.md). "
                    . 'Only these keys can be changed by a Plugin Management tool: ' . implode(', ', self::allowedSettingKeys()) . '. '
                    . 'Storage paths, the user account, and anything that moves or schedules data are not on this Tier 2 allow-list — change them from the Manager UI.'];
            }
            $value = (string)$value;
            if (!self::settingValueAllowed($key, $value)) {
                $rule = self::SETTINGS_ALLOWLIST[$key];
                $allowed = self::settingAllowedDescription($rule);
                return ['error' => "'$value' is not a valid value for '$key'. Allowed: $allowed."];
            }

            // HOME_BACKUP.md: backup_target's regex only checks shape (an absolute
            // path with no whitespace). When the concurrent backend work has landed
            // StorageTargetService::resolveBackupTarget(), defer to its real
            // FUSE/durability verdict too — the same check the card's own 'Check'
            // button calls via backup_validate_target. Best-effort: a resolver that
            // does not exist yet, or itself throws, must never block every OTHER
            // Tier 2 setting from working.
            if ($key === 'backup_target' && $value !== '' && method_exists(StorageTargetService::class, 'resolveBackupTarget')) {
                try {
                    $resolved = StorageTargetService::resolveBackupTarget($value);
                    if (is_array($resolved) && array_key_exists('ok', $resolved) && !$resolved['ok']) {
                        return ['error' => (string)($resolved['message'] ?? "'$value' is not a usable backup target.")];
                    }
                } catch (\Throwable $e) {
                    // Best-effort only — see doc comment above.
                }
            }

            $ok = ConfigService::saveConfig([$key => $value]);
            if (!$ok) return ['error' => 'Write failed.'];

            // HOME_BACKUP.md R1: a schedule change made through this Tier 2 path
            // (aicli_set_setting / the CLI's set-setting) must re-register the cron
            // the same way the Manager UI's own Save does (UtilityHandler::save()).
            if ($key === 'backup_schedule') {
                BackupCronService::sync(ConfigService::getConfig());
            }

            // No key on today's Tier 2 allow-list is secret-shaped, but a future
            // one might be — check by name so this event can never leak a value
            // for a key that looks like a credential, without waiting for
            // RedactionService to grow a settings-key notion of its own.
            $data = ['key' => $key, 'changed' => true];
            if (!preg_match('/token|secret|password|key|credential/i', $key)) {
                $data['value'] = $value;
            }
            self::emitEvent('settings.changed', ['name' => $key], "Setting '$key' changed", $data);
            return ['key' => $key, 'value' => $value];
        } catch (\Throwable $e) {
            return self::failure('setSetting', $e);
        }
    }

    /**
     * WORKSPACE_FAVOURITES.md R7: bookmark $workspaceId's agent and folder as a
     * favourite, or refresh an existing favourite on the same (agentId, path)
     * pair with the workspace's current name and voice switch — the same
     * upsert FavouritesService::addFromWorkspace() already does for the row
     * menu's "Add to favourites"/"Update favourite" action
     * (FavouritesHandler::handle('add_favourite')). This method calls that
     * SAME service method, never a second copy of the workspace-record read.
     *
     * @return array<string,mixed>
     */
    public static function addFavourite(string $workspaceId): array {
        try {
            if (!class_exists('\AICliAgents\Services\FavouritesService')) {
                require_once __DIR__ . '/FavouritesService.php';
            }
            if (!class_exists('\AICliAgents\Handlers\FavouritesHandler')) {
                require_once __DIR__ . '/../handlers/FavouritesHandler.php';
            }
            $result = \AICliAgents\Services\FavouritesService::addFromWorkspace($workspaceId);
            if (isset($result['error'])) {
                return ['error' => (string)$result['error']];
            }
            $favourite = \AICliAgents\Handlers\FavouritesHandler::decorate($result['favourite']);
            $verb = $result['updated'] ? 'Updated' : 'Added';
            self::emitEvent(
                'favourite.added',
                ['id' => $favourite['id'], 'agentId' => $favourite['agentId'], 'workspaceId' => $workspaceId],
                "$verb favourite '{$favourite['name']}'",
                ['updated' => (bool)$result['updated']]
            );
            $favourite['updated'] = (bool)$result['updated'];
            return $favourite;
        } catch (\Throwable $e) {
            return self::failure('addFavourite', $e);
        }
    }

    /**
     * WORKSPACE_FAVOURITES.md R7: remove one favourite by id. Never touches the
     * workspace it was bookmarking, if one is still open — a favourite is a
     * pointer, not a copy (see FavouritesService's own class doc). Refuses,
     * before removing anything, when $id names no favourite, the same
     * "refuse, never guess" convention every other Tier 2 method here follows.
     *
     * @return array<string,mixed>
     */
    public static function removeFavourite(string $id): array {
        try {
            if (!class_exists('\AICliAgents\Services\FavouritesService')) {
                require_once __DIR__ . '/FavouritesService.php';
            }
            $favourite = \AICliAgents\Services\FavouritesService::get($id);
            if ($favourite === null) {
                return ['error' => "No favourite with id '$id' was found."];
            }
            if (!\AICliAgents\Services\FavouritesService::remove($id)) {
                return ['error' => "No favourite with id '$id' was found."];
            }
            self::emitEvent(
                'favourite.removed',
                ['id' => $id, 'agentId' => $favourite['agentId'] ?? ''],
                "Removed favourite '{$favourite['name']}'"
            );
            return ['id' => $id, 'removed' => true];
        } catch (\Throwable $e) {
            return self::failure('removeFavourite', $e);
        }
    }

    /**
     * AGENT_VOICE.md R1: speak one line of text through the operator's configured
     * path (browser speech, or a configured engine). Thin wrapper: resolves the
     * caller's identity (name/agentId for attribution) via callerIdentity(), lets
     * an explicit $workspaceId override which workspace the clip is attributed
     * to (an agent speaking about a DIFFERENT workspace than its own session —
     * e.g. reporting on a long task in another tab — is a legitimate use), and
     * delegates every limit/mode/transport decision to VoiceService::speak(),
     * the same class this method's own AJAX sibling (VoiceHandler::testVoice())
     * calls directly for a human's Test-button click.
     *
     * @return array<string,mixed>
     */
    public static function speak(string $text, ?string $workspaceId = null, ?string $voice = null): array {
        try {
            $caller = self::callerIdentity();
            $wsId = ($workspaceId !== null && trim($workspaceId) !== '') ? trim($workspaceId) : $caller['workspaceId'];
            // VOICE_MAIL.md R13 (Forgejo #375): an explicit workspaceId must name a
            // real workspace. An agent once passed its DISPLAY NAME here; it was used
            // as the id unchecked, so the workspace's own Voice mail mode never
            // applied and its messages played aloud under a second, unknown id. A
            // name that exactly one workspace has resolves to that id; anything
            // else is refused, so nothing is spoken for a workspace we cannot find.
            // VOICE_SWITCHES.md R6 keeps the global switch first: while it is off,
            // VoiceService::speak() below refuses with `global_off` whatever the id.
            $globalOn = (string)(ConfigService::getConfig()['voice_enabled'] ?? '0') === '1';
            if ($globalOn && $workspaceId !== null && trim($workspaceId) !== '') {
                $resolved = VoiceMailService::resolveWorkspace($wsId);
                if ($resolved['record'] === null) {
                    if ($resolved['matches'] !== []) {
                        $ids = implode(', ', array_map(static fn(array $w): string => (string)($w['id'] ?? ''), $resolved['matches']));
                        return ['error' => "More than one workspace is named '$wsId' ($ids). Pass the workspace id, not its name.",
                                'mode' => 'refused', 'reason' => 'ambiguous_workspace'];
                    }
                    return ['error' => "No workspace with id or name '$wsId' was found. Pass the workspace id (aicli_list_workspaces), or leave workspaceId out to speak as your own workspace.",
                            'mode' => 'refused', 'reason' => 'unknown_workspace'];
                }
                $wsId = (string)($resolved['record']['id'] ?? $wsId);
            }
            $session = self::findSession($wsId);
            $actorContext = [
                'workspaceId' => $wsId,
                'agentId'     => $session['agentId'] ?? $caller['agentId'],
                'name'        => $session['name'] ?? $caller['name'],
            ];
            return VoiceService::speak($text, $actorContext, $voice);
        } catch (\Throwable $e) {
            return self::failure('speak', $e);
        }
    }

    /** WORKSPACE_SEND_INPUT.md R4: hard cap on one send-input call's text length. */
    private const SEND_INPUT_MAX_CHARS = 4000;

    /** WORKSPACE_SEND_INPUT.md R5: redacted excerpt length for the ledger event (same length as VoiceService::EXCERPT_CHARS). */
    private const SEND_INPUT_EXCERPT_CHARS = 80;

    /** REVIEW_2026-09-13_EVENTS_AND_SECURITY.md S7: per-TARGET minimum gap between send-input calls, the same window as VoiceService::MIN_GAP_SECONDS. */
    private const SEND_INPUT_MIN_GAP_SECONDS = 3;

    /** Test seam: overrides the tmpfs dir the S7 gap markers live in. Null uses the real path. */
    public static ?string $sendInputRateDir = null;

    /** Test seam: callable(): int returning "now" (epoch seconds), so a test can move time without sleeping. Null uses time(). */
    public static $sendInputNow = null;

    /** Resolved runtime dir for the S7 gap markers (tmpfs; $sendInputRateDir overrides for tests). */
    private static function sendInputRateDir(): string {
        return self::$sendInputRateDir ?? '/tmp/unraid-aicliagents/send-input';
    }

    /** "Now", in epoch seconds — $sendInputNow overrides for tests. */
    private static function sendInputNow(): int {
        return self::$sendInputNow !== null ? (int)(self::$sendInputNow)() : time();
    }

    /**
     * REVIEW_2026-09-13_EVENTS_AND_SECURITY.md S7: `send_input` had no rate
     * limit — an agent could flood a pane with keystrokes as fast as it could
     * call the tool. This gives it the SAME per-target 3s gap `speak` already
     * has (VoiceService::speak()'s "R2: per-workspace 3s gap"), keyed on the
     * TARGET workspace (the pane being typed into), not the caller, so two
     * different agents typing into the SAME pane still share one gap. One
     * tmpfs marker file per target, named after the workspace id (sanitised
     * the same way VoiceService's gap key is): its mtime is the last
     * ALLOWED call's time.
     *
     * Returns true (and touches the marker to $now) when $workspaceId may
     * send input now. Returns false (leaving the marker untouched) when the
     * last call to this same target was under SEND_INPUT_MIN_GAP_SECONDS
     * ago — the caller must refuse without ever touching tmux, ProcessManager,
     * or the ledger.
     */
    private static function sendInputGateOpen(string $workspaceId): bool {
        $dir = self::sendInputRateDir();
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        $file = $dir . '/.last-' . preg_replace('/[^A-Za-z0-9_-]/', '_', $workspaceId);
        $now = self::sendInputNow();
        $last = @filemtime($file);
        if ($last !== false && ($now - $last) < self::SEND_INPUT_MIN_GAP_SECONDS) {
            return false;
        }
        @touch($file, $now);
        return true;
    }

    /**
     * WORKSPACE_SEND_INPUT.md: type $text into a running workspace's terminal
     * pane and, unless $enter is false, press Enter — through the SAME unified
     * paste path the Relay uses (TmuxService::pasteText() plus the Enter-confirm
     * helper, PASTE_ENTER_CONFIRM.md), via TmuxService::pasteAndConfirm(), which
     * reuses that machinery instead of copying it a third time. The readiness
     * gate of RELAY_DELIVERY_READINESS_GATE.md applies unless $force, exactly
     * like the tray's Force inject (RELAY_WAITING_PILL.md R2): $force swaps
     * TmuxService::paneAcceptsInput() for TmuxService::paneIsAddressable(),
     * which never reads the screen, only proves a live agent is on the pane.
     *
     * R4 refuses, before ever touching tmux: an unknown workspace id, a
     * workspace with no agent on record, a workspace that is not running (the
     * SAME ProcessManager::isRunning() liveness check TerminalHandler's
     * get_sessions_running uses), an empty text (once its trailing newline is
     * stripped — that newline is only the caller's way of saying "press Enter
     * after this"; $enter alone decides that), text over SEND_INPUT_MAX_CHARS,
     * a control character other than newline/tab, or (S7) a call to the SAME
     * target workspace within SEND_INPUT_MIN_GAP_SECONDS of the last one.
     *
     * A refused call (an 'error' key) never reaches the pane and is never
     * ledgered — the same convention every other Tier 2 method here follows.
     * A processed call (delivered or deferred by the readiness gate) always
     * is: see recordSendInputLedger().
     *
     * @return array<string,mixed>
     */
    public static function sendInput(string $workspaceId, string $text, bool $enter = true, bool $force = false): array {
        try {
            $session = self::findSession($workspaceId);
            if ($session === null) {
                return ['error' => "No workspace with id '$workspaceId' was found."];
            }
            $name = (string)($session['name'] ?? $workspaceId);
            $agentId = (string)($session['agentId'] ?? '');
            if ($agentId === '') {
                return ['error' => "Workspace '$name' has no agent on record; cannot type into it."];
            }
            if (!ProcessManager::isRunning($workspaceId)) {
                return ['error' => "Workspace '$name' is not running — start it before typing into it."];
            }

            // The trailing newline (the caller's "press Enter after this") is
            // stripped; $enter alone decides whether Enter is pressed.
            $text = rtrim($text, "\n");
            if ($text === '') {
                return ['error' => 'There is nothing to type — text is empty.'];
            }
            $chars = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
            if ($chars > self::SEND_INPUT_MAX_CHARS) {
                return ['error' => 'Text is too long — the cap is ' . self::SEND_INPUT_MAX_CHARS . ' characters.'];
            }
            if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text)) {
                return ['error' => 'Text contains a control character other than newline/tab, which is not allowed.'];
            }
            if (!self::sendInputGateOpen($workspaceId)) {
                return ['error' => "Typed into workspace '$name' too recently — wait a few seconds and try again."];
            }

            $gate = $force
                ? TmuxService::paneIsAddressable($agentId, $workspaceId)
                : TmuxService::paneAcceptsInput($agentId, $workspaceId);
            if ($gate['ready'] !== true) {
                self::recordSendInputLedger($workspaceId, $agentId, $name, $chars, $force, false, $text);
                return ['workspaceId' => $workspaceId, 'delivered' => false, 'deferred' => true, 'forced' => $force, 'reason' => $gate['reason'], 'chars' => $chars];
            }

            $delivery = TmuxService::pasteAndConfirm($agentId, $workspaceId, $text, $enter);
            if (($delivery['status'] ?? '') !== 'ok') {
                return ['error' => (string)($delivery['message'] ?? 'Could not type into that workspace.')];
            }

            self::recordSendInputLedger($workspaceId, $agentId, $name, $chars, $force, true, $text);
            $result = ['workspaceId' => $workspaceId, 'delivered' => true, 'deferred' => false, 'forced' => $force, 'chars' => $chars];
            if ($enter) $result['confirmed'] = $delivery['confirmed'] ?? null;
            return $result;
        } catch (\Throwable $e) {
            return self::failure('sendInput', $e);
        }
    }

    /**
     * WORKSPACE_SEND_INPUT.md R5: one ledger event per non-refused call
     * (delivered or deferred — a hard R4 refusal never reaches here, since it
     * changed nothing). The 'actor' envelope (who typed) resolves through
     * EventLedger::append()'s own EventActor::current() default from
     * AICLI_SESSION_ID; subject names the TARGET workspace/agent so a
     * subscriber can filter on the workspace that was typed into. Never the
     * full text — only a redacted, length-capped excerpt.
     */
    private static function recordSendInputLedger(string $workspaceId, string $targetAgentId, string $name, int $chars, bool $forced, bool $delivered, string $text): void {
        $caller = self::callerIdentity();
        $callerAgentId = $caller['agentId'] !== '' ? $caller['agentId'] : 'an agent';
        $summary = EventLedger::summaryFor('workspace.input', ['agentId' => $callerAgentId, 'name' => $name, 'chars' => $chars]);
        self::emitEvent(
            'workspace.input',
            ['workspaceId' => $workspaceId, 'agentId' => $targetAgentId],
            $summary,
            ['chars' => $chars, 'forced' => $forced, 'delivered' => $delivered, 'excerpt' => self::sendInputExcerpt($text)]
        );
    }

    /** Redacted, length-capped excerpt for the ledger — mirrors VoiceService::excerpt(); never the full text. */
    private static function sendInputExcerpt(string $text): string {
        $safe = $text;
        if (class_exists('\\AICliAgents\\Services\\RedactionService')) {
            try {
                $safe = RedactionService::redact($text, RedactionService::loadKnownSecrets());
            } catch (\Throwable $e) {
                // Fall back to the untruncated original — a broken redaction pass must not block the excerpt.
            }
        }
        return function_exists('mb_substr') ? mb_substr($safe, 0, self::SEND_INPUT_EXCERPT_CHARS) : substr($safe, 0, self::SEND_INPUT_EXCERPT_CHARS);
    }

    // ------------------------------------------------------------------
    // TIER 3 — destructive-proposal (docs/specs/PLUGIN_MANAGEMENT_TOOLS.md
    // "Phase 3 as built", 2026-09-09).
    //
    // WHY this tier carries more weight than the spec originally assumed: the
    // spec hoped MCP `toolsApprovalMode` could provide a per-call human
    // approval boundary. GAP 3 (Phase 2 gap closure, same date) found it
    // cannot — exactly one of ~13 registered vendors (Codex, via
    // Transpiler::toCodexToml()) even emits that key, and it stays 'auto' on
    // this box regardless (AdminMcpTools::setMcpRegistered()'s own doc
    // comment). So THIS tier plus the projected skill's confirm-before-change
    // rule is the only real boundary between an agent and a destructive
    // action — not a client-side approval prompt.
    //
    // Every method below is one of two shapes:
    //   validate*()  — refuses a bad request NOW (unknown id, nothing to do,
    //                   already at the target state). Pure: no write, no tray
    //                   entry. Called by both proposeX() (below) AND by unit
    //                   tests that want to assert "refused before any pending
    //                   item exists" without going through the tray.
    //   proposeX()   — calls validateX(), and on success writes a PENDING
    //                   Activity tray item (ActivityService::propose()) and
    //                   returns immediately. NEVER executes the action. The
    //                   params recorded in the pending item are EXACTLY what
    //                   executeApprovedX() will act on later — approval never
    //                   re-resolves "what did the caller mean" from scratch.
    //
    // A human approves or rejects via ActivityHandler's approve_activity /
    // reject_activity AJAX actions (Manager UI only — there is deliberately
    // NO MCP tool or CLI action that approves or rejects; exposing one would
    // let the same agent that proposes an action also approve it, which
    // defeats the entire point of this tier). approvePending()/
    // rejectPending() below are what those AJAX actions call.
    // ------------------------------------------------------------------

    /** Tier 3 tool name => AdminService method that executes it once a human approves. Single source of truth for approvePending()'s dispatch. */
    private const TIER3_EXECUTORS = [
        'aicli_delete_workspace' => 'executeApprovedDeleteWorkspace',
        'aicli_upgrade_agent'    => 'executeApprovedUpgradeAgent',
        // HOME_BACKUP.md Tier row: closes every session of the user, so it is
        // destructive-proposal like the other two, never Tier 2.
        'aicli_backup_home'      => 'executeApprovedBackupHome',
        // HOME_RESTORE.md Tier row: same reason as backup, plus it overwrites
        // (or merges into) the home's contents — destructive-proposal, never Tier 2.
        'aicli_restore_home'     => 'executeApprovedRestoreHome',
        // HOME_PERSIST_CONSOLIDATE_TOOLS.md Tier row (2026-09-17): closes every
        // session of the user and relaunches them — destructive-proposal.
        'aicli_consolidate_home' => 'executeApprovedConsolidateHome',
    ];

    /**
     * Validate a workspace deletion and describe its consequence in plain
     * language, WITHOUT writing anything. Refuses only when `id` does not
     * name a known workspace — deleting a running workspace, or a Relay
     * actor, is allowed (the description says what will happen; the human
     * decides).
     *
     * @return array{error:string}|array{description:string,params:array<string,mixed>}
     */
    public static function validateDeleteWorkspace(string $id): array {
        $session = self::findSession($id);
        if ($session === null) {
            return ['error' => "No workspace with id '$id' was found."];
        }
        $name    = (string)($session['name'] ?? $id);
        $path    = (string)($session['path'] ?? '');
        $agentId = (string)($session['agentId'] ?? '');

        $consequence = "Delete workspace \"$name\" ($path, agent: $agentId).";
        if (ProcessManager::isRunning($id)) {
            $consequence .= ' It is currently running; it will be closed first (a graceful close, the same as clicking the tab\'s close button).';
        }
        try {
            $actor = AgentRelayService::actorStatus($id);
            if (!empty($actor['is_actor']) && empty($actor['paused'])) {
                $topics = implode(', ', array_map('strval', $actor['topics'] ?? []));
                $consequence .= " It owns the Relay topic(s) $topics — deleting it pauses automatic Relay restart for those topics.";
            }
        } catch (\Throwable $e) {
            // Best-effort description enrichment only — a Relay read fault must
            // never block a Tier 3 proposal from being described and offered.
        }
        $consequence .= ' The workspace record and its saved arguments/environment are removed. This cannot be undone from the Manager UI.';

        return ['description' => $consequence, 'params' => ['id' => $id]];
    }

    /**
     * Tier 3. Validate, then propose — never executes. See the class doc
     * above this section for the shape every Tier 3 tool follows.
     *
     * @return array<string,mixed>
     */
    public static function proposeDeleteWorkspace(string $id): array {
        try {
            $validated = self::validateDeleteWorkspace($id);
            if (isset($validated['error'])) return $validated;
            return self::proposePending('aicli_delete_workspace', $validated['description'], $validated['params']);
        } catch (\Throwable $e) {
            return self::failure('proposeDeleteWorkspace', $e);
        }
    }

    /**
     * The validated action itself, run ONLY by approvePending() after a human
     * approves — never by proposeDeleteWorkspace() or any tool call. Re-checks
     * that the target still exists (spec Edge Case: "a workspace is deleted
     * while an agent holds a reference" — here the target may simply have
     * been deleted or renamed between proposal and approval), then reuses the
     * SAME sequence the drawer's own close-and-remove flow uses
     * (AICliAgentsTerminal.tsx closeTab(): pause a live Relay actor, graceful
     * close, forget Relay traces, remove the record, re-adopt owners) — no
     * new holder-check logic invented here.
     *
     * @param array<string,mixed> $params From the pending item's own 'meta.params' (see propose()).
     * @return array<string,mixed>
     */
    public static function executeApprovedDeleteWorkspace(array $params): array {
        try {
            $id = (string)($params['id'] ?? '');
            if ($id === '') return ['error' => 'This pending item has no workspace id recorded.'];
            $session = self::findSession($id);
            if ($session === null) {
                return ['error' => "Workspace '$id' no longer exists; nothing to delete."];
            }

            try {
                $actor = AgentRelayService::actorStatus($id);
                if (!empty($actor['is_actor']) && empty($actor['paused'])) {
                    AgentRelayService::pauseActorWorkspace($id);
                }
            } catch (\Throwable $e) {
                // Best-effort — a Relay fault must not block the delete itself.
            }

            if (ProcessManager::isRunning($id)) {
                \AICliAgents\Handlers\TerminalHandler::handle('graceful_close', $id);
            }

            try { AgentRelayService::forgetSessionRelayTraces($id); }
            catch (\Throwable $e) { /* best-effort cleanup, same as UtilityHandler::saveWorkspaces() */ }

            $workspaces = ConfigService::getWorkspaces();
            $remaining = array_values(array_filter(
                $workspaces['sessions'] ?? [],
                static fn($w): bool => is_array($w) && (string)($w['id'] ?? '') !== $id
            ));
            $ok = ConfigService::saveWorkspaces(['sessions' => $remaining, 'activeId' => $workspaces['activeId'] ?? null], [$id]);
            if (!$ok) {
                return ['error' => ConfigService::lastWorkspaceSaveMessage() ?? 'Could not save the workspace removal.'];
            }

            // This delete path goes through ConfigService::saveWorkspaces() directly,
            // not the save_workspaces AJAX action — UtilityHandler::saveWorkspaces()'s
            // own event-subscription sweep never runs for it, so sweep here too.
            if (class_exists('\\AICliAgents\\Services\\EventSubscriptionStore')) {
                try { EventSubscriptionStore::reapStale(array_column($remaining, 'id')); }
                catch (\Throwable $e) { /* best-effort, same as the Relay-trace cleanup above */ }
            }

            try { AgentRelayService::reAdoptOwnersByIdentity(); }
            catch (\Throwable $e) { /* best-effort, same as UtilityHandler::saveWorkspaces() */ }

            return ['id' => $id, 'name' => (string)($session['name'] ?? $id)];
        } catch (\Throwable $e) {
            return self::failure('executeApprovedDeleteWorkspace', $e);
        }
    }

    /**
     * Validate an agent upgrade and describe its consequence, WITHOUT writing
     * anything. Refuses when: the agent id is unknown; the agent is not
     * installed (Tier 3 upgrades an installed agent — installing a new one is
     * a separate, not-yet-built candidate, see the spec's "deferred" list);
     * no target version was given AND none is known from the last update
     * check; or the target version is already what is installed.
     *
     * @return array{error:string}|array{description:string,params:array<string,mixed>}
     */
    public static function validateUpgradeAgent(string $agentId, string $version = ''): array {
        if (!self::agentExists($agentId)) {
            return ['error' => "Unknown agent '$agentId'. Ask aicli_list_agents for valid ids."];
        }
        $registry = AgentRegistry::getRegistry();
        $agent = $registry[$agentId] ?? [];
        if (empty($agent['is_installed'])) {
            return ['error' => "'$agentId' is not installed. Tier 3 upgrade_agent only upgrades an ALREADY-INSTALLED agent."];
        }
        $installed = (string)($agent['version'] ?? 'unknown');

        $version = trim($version);
        if ($version === '') {
            $update = null;
            try { $update = VersionCheckService::hasUpdate($agentId); }
            catch (\Throwable $e) { /* no cache entry yet is normal, not a failure — falls through to the refusal below */ }
            $version = (string)($update['available'] ?? '');
            if ($version === '') {
                return ['error' => "No update is currently known for '$agentId'. Call aicli_check_updates first, or pass an explicit version."];
            }
        }
        if ($version === $installed) {
            return ['error' => "'$agentId' is already at version '$installed'."];
        }

        $name = (string)($agent['name'] ?? $agentId);
        $sessions = TerminalService::listActiveSessionsForAgent($agentId);
        $consequence = "Upgrade $name ($agentId) from $installed to $version.";
        if ($sessions !== []) {
            $n = count($sessions);
            $consequence .= " $n workspace(s) currently run $name; the upgrade will WAIT for them to close on their own "
                . '(the same safe default the Store tab uses) rather than force-closing them. '
                . 'Force-closing running sessions is not offered by this tool — use the Manager UI for that.';
        } else {
            $consequence .= " No workspace is currently running $name, so the upgrade starts immediately on approval.";
        }

        return ['description' => $consequence, 'params' => ['agentId' => $agentId, 'version' => $version]];
    }

    /**
     * Tier 3. Validate, then propose — never executes.
     *
     * @return array<string,mixed>
     */
    public static function proposeUpgradeAgent(string $agentId, string $version = ''): array {
        try {
            $validated = self::validateUpgradeAgent($agentId, $version);
            if (isset($validated['error'])) return $validated;
            return self::proposePending('aicli_upgrade_agent', $validated['description'], $validated['params']);
        } catch (\Throwable $e) {
            return self::failure('proposeUpgradeAgent', $e);
        }
    }

    /**
     * The validated action itself, run ONLY by approvePending(). Reuses
     * AgentHandler::installCore() — the EXACT non-$_GET-dependent core of the
     * Store tab's own "Upgrade" click (AgentHandler::install() is now a thin
     * $_GET-reading wrapper around it) — with $force=false ALWAYS: this tool
     * never force-closes a running session. If sessions are still running at
     * approval time, installCore() queues the upgrade via
     * PendingAgentUpgradeService exactly the way a human's own non-forced
     * click would, and the supervisor picks it up once they close — no new
     * upgrade path, no bypass of #71's queueing safety.
     *
     * @param array<string,mixed> $params From the pending item's own 'meta.params'.
     * @return array<string,mixed>
     */
    public static function executeApprovedUpgradeAgent(array $params): array {
        try {
            $agentId = (string)($params['agentId'] ?? '');
            $version = (string)($params['version'] ?? '');
            if ($agentId === '') return ['error' => 'This pending item has no agent id recorded.'];
            if (!self::agentExists($agentId)) {
                return ['error' => "Agent '$agentId' no longer exists; nothing to upgrade."];
            }
            $result = \AICliAgents\Handlers\AgentHandler::installCore($agentId, $version, '', false);
            if (($result['status'] ?? '') !== 'ok') {
                return ['error' => (string)($result['message'] ?? 'The upgrade could not be started.')];
            }
            return ['agentId' => $agentId, 'version' => $version, 'detail' => $result];
        } catch (\Throwable $e) {
            return self::failure('executeApprovedUpgradeAgent', $e);
        }
    }

    /**
     * HOME_BACKUP.md Tier row. Validate a home-backup request and describe its
     * consequence in plain language, WITHOUT writing anything or closing any
     * session. Refuses when `user` has no home to back up (the same set the
     * Storage tab's Home backup card offers, R12) or when `quiesce` is neither
     * 'cold' nor 'warm'. `target`, when given, gets only a shape check here —
     * the real FUSE/durability/free-space verdict (R2) runs inside
     * StorageHandler::backupHome() itself once approved, the same "validate()
     * is a fast pre-check, the executor does the real work" split
     * validateDeleteWorkspace()/executeApprovedDeleteWorkspace() already use.
     *
     * @return array{error:string}|array{description:string,params:array<string,mixed>}
     */
    public static function validateBackupHome(string $user, array $opts = []): array {
        $user = trim($user);
        if ($user === '') return ['error' => 'A user is required.'];

        $homes = [];
        try {
            $status = StorageMetricsService::getStatus();
            $homes = is_array($status['homes'] ?? null) ? $status['homes'] : [];
        } catch (\Throwable $e) {
            return self::failure('validateBackupHome', $e);
        }
        if (!array_key_exists($user, $homes)) {
            return ['error' => "'$user' has no home to back up."];
        }

        // HOME_BACKUP.md #287: the home's OWN settings (inherits the old global
        // backup_* values once when the home has none yet).
        require_once __DIR__ . '/HomeBackupSettingsService.php';
        $homeSettings = HomeBackupSettingsService::get($user);
        $quiesce = (string)($opts['quiesce'] ?? $homeSettings['quiesce']);
        if (!in_array($quiesce, ['cold', 'warm'], true)) {
            return ['error' => "'$quiesce' is not a valid quiesce mode. Use 'cold' or 'warm'."];
        }

        $target = trim((string)($opts['target'] ?? ''));
        if ($target !== '' && $target[0] !== '/') {
            return ['error' => "'$target' is not an absolute path."];
        }
        $effectiveTarget = $target !== '' ? $target : (string)$homeSettings['target'];
        if ($effectiveTarget === '') {
            return ['error' => "No backup target is set for $user's home. Set one with Backup on the home's card in Settings > Storage, or pass `target`."];
        }

        $mode = $quiesce === 'cold' ? 'cold (closes every running session first)' : 'warm (no close, best effort)';
        $consequence = "Back up $user's home to $effectiveTarget, $mode. "
            . ($quiesce === 'cold'
                ? "$user's running sessions are closed, the home is baked if it is a layer stack, then copied; every session relaunches afterward, and any session that was working gets a Continue nudge. "
                : "Sessions are left running; the snapshot is marked warm. ")
            . 'This does not run on its own — only the operator\'s own schedule runs a backup without a human approval step.';

        return ['description' => $consequence, 'params' => ['user' => $user, 'quiesce' => $quiesce, 'target' => $target]];
    }

    /**
     * Tier 3. Validate, then propose — never executes. See the class doc
     * above this section for the shape every Tier 3 tool follows.
     *
     * @return array<string,mixed>
     */
    public static function proposeBackupHome(string $user, array $opts = []): array {
        try {
            $validated = self::validateBackupHome($user, $opts);
            if (isset($validated['error'])) return $validated;
            return self::proposePending('aicli_backup_home', $validated['description'], $validated['params']);
        } catch (\Throwable $e) {
            return self::failure('proposeBackupHome', $e);
        }
    }

    /**
     * The validated action itself, run ONLY by approvePending() after a human
     * approves — never by proposeBackupHome() or any tool call. Re-checks that
     * `user` still has a home (the target may have been deleted between
     * proposal and approval, the same Edge Case executeApprovedDeleteWorkspace
     * re-checks for a workspace id), then hands off to
     * StorageHandler::backupHome() — the SAME public entry point the
     * `backup_home` AJAX action and the CLI's `backup-home --scheduled` call —
     * so approval never re-implements the close/bake/copy/relaunch sequence.
     *
     * @param array<string,mixed> $params From the pending item's own 'meta.params' (see propose()).
     * @return array<string,mixed>
     */
    public static function executeApprovedBackupHome(array $params): array {
        try {
            $user = (string)($params['user'] ?? '');
            if ($user === '') return ['error' => 'This pending item has no user recorded.'];

            $homes = [];
            try {
                $status = StorageMetricsService::getStatus();
                $homes = is_array($status['homes'] ?? null) ? $status['homes'] : [];
            } catch (\Throwable $e) {
                return self::failure('executeApprovedBackupHome', $e);
            }
            if (!array_key_exists($user, $homes)) {
                return ['error' => "'$user' no longer has a home; nothing to back up."];
            }

            $opts = [];
            if (isset($params['quiesce']) && (string)$params['quiesce'] !== '') $opts['quiesce'] = (string)$params['quiesce'];
            if (isset($params['target']) && (string)$params['target'] !== '') $opts['target'] = (string)$params['target'];

            if (!class_exists('\AICliAgents\Handlers\StorageHandler')) { require_once __DIR__ . '/../handlers/StorageHandler.php'; }
            $result = \AICliAgents\Handlers\StorageHandler::backupHome($user, $opts);
            if (($result['status'] ?? '') === 'error') {
                return ['error' => (string)($result['message'] ?? 'The backup could not be started.')];
            }
            return array_merge(['user' => $user], $result);
        } catch (\Throwable $e) {
            return self::failure('executeApprovedBackupHome', $e);
        }
    }

    /**
     * HOME_PERSIST_CONSOLIDATE_TOOLS.md Tier 3 (2026-09-17). Validate a home
     * consolidate and describe its consequence in plain language, WITHOUT
     * queuing anything, marking anything, or closing any session. The
     * description names the sessions that WILL be closed on approval — the
     * same list the Storage tab's own confirm dialog shows.
     *
     * @return array{error:string}|array{description:string,params:array<string,mixed>}
     */
    public static function validateConsolidateHome(string $user): array {
        $resolved = self::resolveHomeUser($user);
        if (isset($resolved['error'])) return $resolved;
        $u = (string)$resolved['user'];
        $home = $resolved['home'];
        if (!class_exists('\\AICliAgents\\Services\\ConsolidateState')) { require_once __DIR__ . '/ConsolidateState.php'; }
        if (ConsolidateState::isHomeConsolidating($u)) {
            return ['error' => "$u's home is already being consolidated."];
        }
        $layers = (int)($home['layers'] ?? 0);
        $mb     = (int)round((float)($home['physical_mb'] ?? 0));
        $dirty  = (int)($home['dirty_mb'] ?? 0);
        if (!class_exists('\\AICliAgents\\Handlers\\StorageHandler')) { require_once __DIR__ . '/../handlers/StorageHandler.php'; }
        $sessions = \AICliAgents\Handlers\StorageHandler::listHomeSessions($u);
        $n = count($sessions);
        $named = array_map(function (array $s): string {
            $agent = $s['name'] !== '' ? $s['name'] : $s['agentId'];
            return $agent . ' in ' . ($s['path'] !== '' ? $s['path'] : '(no folder)');
        }, array_slice($sessions, 0, 6));
        $list = implode(', ', $named) . ($n > 6 ? ', …' : '');
        // CONSOLIDATE_RELAUNCH_CLOSED_SET.md (2026-09-17): capture the sessions
        // OPEN NOW, at propose time, into the params. The user often closes a
        // session between proposal and approval — the plugin's own "Storage
        // reclaim needed / Close now" banner asks them to — so by the time the
        // approved consolidate runs, listActiveSessionsForHome is empty and the
        // consolidate has nothing to relaunch. This captured set is what the
        // approve path seeds the relaunch manifest from, so a session the user
        // closed on the plugin's prompt still comes back, resumed.
        $relaunchSet = [];
        foreach ($sessions as $s) {
            $sid = (string)($s['id'] ?? '');
            $wp  = (string)($s['path'] ?? '');
            $aid = (string)($s['agentId'] ?? '');
            if ($sid === '') continue;
            $relaunchSet[] = [
                'sessionId'     => $sid,
                'workspacePath' => $wp,
                'agentId'       => $aid,
                'hadResume'     => $wp !== '' && $aid !== '',
                'working'       => true,
            ];
        }
        $consequence = "Consolidate $u's home: merge its $layers saved layer(s) ($mb MB) and $dirty MB of unsaved changes into one layer. "
            . ($n > 0
                ? "This CLOSES EVERY RUNNING SESSION of $u first ($n: $list), then relaunches each one with its conversation resumed; a session that was working gets one Continue. "
                : "No session is open on this home right now, so nothing is closed. ")
            . 'Nothing happens until a human approves this in the Manager UI Activity tray.';
        return ['description' => $consequence, 'params' => ['user' => $u, 'relaunchSet' => $relaunchSet]];
    }

    /**
     * Tier 3. Validate, then propose — never executes.
     *
     * @return array<string,mixed>
     */
    public static function proposeConsolidateHome(string $user): array {
        try {
            $validated = self::validateConsolidateHome($user);
            if (isset($validated['error'])) return $validated;
            return self::proposePending('aicli_consolidate_home', $validated['description'], $validated['params']);
        } catch (\Throwable $e) {
            return self::failure('proposeConsolidateHome', $e);
        }
    }

    /**
     * The validated action itself, run ONLY by approvePending() after a human
     * approves — never by proposeConsolidateHome() or any tool call. Re-checks
     * that the home still exists and is not already consolidating, then hands
     * off to StorageHandler::consolidate('home', …) — the SAME public
     * entry point the `consolidate_storage` AJAX action uses — so approval
     * never re-implements the manifest/mark/enqueue/wake sequence.
     *
     * @param array<string,mixed> $params From the pending item's own 'meta.params'.
     * @return array<string,mixed>
     */
    public static function executeApprovedConsolidateHome(array $params): array {
        try {
            $u = (string)($params['user'] ?? '');
            if ($u === '') return ['error' => 'This pending item has no user recorded.'];
            $resolved = self::resolveHomeUser($u);
            if (isset($resolved['error'])) return ['error' => "'$u' no longer has a home; nothing to consolidate."];
            if (!class_exists('\\AICliAgents\\Services\\ConsolidateState')) { require_once __DIR__ . '/ConsolidateState.php'; }
            if (ConsolidateState::isHomeConsolidating($u)) {
                return ['error' => "$u's home is already being consolidated."];
            }
            if (!class_exists('\\AICliAgents\\Handlers\\StorageHandler')) { require_once __DIR__ . '/../handlers/StorageHandler.php'; }
            $relaunchSet = isset($params['relaunchSet']) && is_array($params['relaunchSet']) ? $params['relaunchSet'] : [];
            $result = \AICliAgents\Handlers\StorageHandler::consolidate('home', $u, $relaunchSet);
            if (($result['status'] ?? '') === 'error') {
                return ['error' => (string)($result['message'] ?? 'The consolidate could not be queued.')];
            }
            return array_merge(['user' => $u], $result);
        } catch (\Throwable $e) {
            return self::failure('executeApprovedConsolidateHome', $e);
        }
    }

    /**
     * HOME_RESTORE.md Tier row. Validate a home-restore request and describe
     * its consequence in plain language, WITHOUT writing anything, closing
     * any session, or touching a file. Mirrors validateBackupHome() above:
     * refuses when `user` has no home, when `snapshot` is missing, or when
     * `mode` is neither 'replace' nor 'merge'. The real snapshot-exists /
     * same-user / space / mount pre-flight (R3) runs inside
     * StorageHandler::restoreHome() itself once approved — this is only the
     * fast, pre-write shape check.
     *
     * @return array{error:string}|array{description:string,params:array<string,mixed>}
     */
    public static function validateRestoreHome(string $user, array $opts = []): array {
        $user = trim($user);
        if ($user === '') return ['error' => 'A user is required.'];

        $homes = [];
        try {
            $status = StorageMetricsService::getStatus();
            $homes = is_array($status['homes'] ?? null) ? $status['homes'] : [];
        } catch (\Throwable $e) {
            return self::failure('validateRestoreHome', $e);
        }
        if (!array_key_exists($user, $homes)) {
            return ['error' => "'$user' has no home to restore."];
        }

        $snapshot = trim((string)($opts['snapshot'] ?? ''));
        if ($snapshot === '') {
            return ['error' => 'A snapshot is required — a path from aicli_list_backups, or "latest".'];
        }

        $mode = (string)($opts['mode'] ?? 'replace');
        if (!in_array($mode, ['replace', 'merge'], true)) {
            return ['error' => "'$mode' is not a valid restore mode. Use 'replace' or 'merge'."];
        }

        $safety = array_key_exists('safety_snapshot', $opts) ? (bool)$opts['safety_snapshot'] : true;

        $modeText = $mode === 'replace'
            ? 'replace (the home ends up identical to the snapshot; anything the snapshot does not have is removed)'
            : 'merge (the snapshot\'s files are copied over the home; nothing already there is removed)';
        $consequence = "Restore $user's home from the snapshot '$snapshot', mode $modeText. "
            . "$user's running sessions are closed first; every session relaunches afterward, and any session that was working gets a Continue nudge. "
            . ($safety
                ? "A safety snapshot of the current home is taken first, labelled 'pre-restore', so this restore is itself one more restore away from undone. "
                : "No safety snapshot is taken first, at the caller's request — this restore cannot be undone by restoring a snapshot of its own \"before\" state. ")
            . 'This does not run on its own — only a human\'s approval in the Manager runs a restore.';

        return ['description' => $consequence, 'params' => ['user' => $user, 'snapshot' => $snapshot, 'mode' => $mode, 'safety_snapshot' => $safety]];
    }

    /**
     * Tier 3. Validate, then propose — never executes. See the class doc
     * above this section for the shape every Tier 3 tool follows.
     *
     * @return array<string,mixed>
     */
    public static function proposeRestoreHome(string $user, array $opts = []): array {
        try {
            $validated = self::validateRestoreHome($user, $opts);
            if (isset($validated['error'])) return $validated;
            return self::proposePending('aicli_restore_home', $validated['description'], $validated['params']);
        } catch (\Throwable $e) {
            return self::failure('proposeRestoreHome', $e);
        }
    }

    /**
     * The validated action itself, run ONLY by approvePending() after a human
     * approves — never by proposeRestoreHome() or any tool call. Re-checks
     * that `user` still has a home (it may have been deleted between
     * proposal and approval, the same Edge Case executeApprovedBackupHome
     * re-checks), then hands off to StorageHandler::restoreHome() — the SAME
     * public entry point the `restore_home` AJAX action and the CLI's
     * `restore-home --yes` use, so approval never re-implements the
     * close/safety-snapshot/copy/verify/relaunch sequence.
     *
     * @param array<string,mixed> $params From the pending item's own 'meta.params' (see propose()).
     * @return array<string,mixed>
     */
    public static function executeApprovedRestoreHome(array $params): array {
        try {
            $user = (string)($params['user'] ?? '');
            if ($user === '') return ['error' => 'This pending item has no user recorded.'];

            $homes = [];
            try {
                $status = StorageMetricsService::getStatus();
                $homes = is_array($status['homes'] ?? null) ? $status['homes'] : [];
            } catch (\Throwable $e) {
                return self::failure('executeApprovedRestoreHome', $e);
            }
            if (!array_key_exists($user, $homes)) {
                return ['error' => "'$user' no longer has a home; nothing to restore."];
            }

            $opts = [];
            if (isset($params['snapshot']) && (string)$params['snapshot'] !== '') $opts['snapshot'] = (string)$params['snapshot'];
            if (isset($params['mode']) && (string)$params['mode'] !== '') $opts['mode'] = (string)$params['mode'];
            if (array_key_exists('safety_snapshot', $params)) $opts['safety_snapshot'] = (bool)$params['safety_snapshot'];

            if (!class_exists('\AICliAgents\Handlers\StorageHandler')) { require_once __DIR__ . '/../handlers/StorageHandler.php'; }
            $result = \AICliAgents\Handlers\StorageHandler::restoreHome($user, $opts);
            if (($result['status'] ?? '') === 'error') {
                return ['error' => (string)($result['message'] ?? 'The restore could not be started.')];
            }
            return array_merge(['user' => $user], $result);
        } catch (\Throwable $e) {
            return self::failure('executeApprovedRestoreHome', $e);
        }
    }

    /**
     * Shared by every proposeX() above: write the pending Activity tray item
     * and return the "waiting for the user" result a Tier 3 tool hands back
     * to its caller. The opId is prefixed 'pending_' (distinct from Tier 2's
     * 'admin_' audit-entry prefix) so the two kinds of tray entry are
     * grep-able apart.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private static function proposePending(string $tool, string $description, array $params): array {
        $caller = self::callerIdentity();
        $opId = 'pending_' . preg_replace('/[^a-z0-9_]/', '_', $tool) . '_' . bin2hex(random_bytes(4));
        $entry = ActivityService::propose($opId, $tool, $description, $params, $caller);
        if ($entry === null) {
            return ['error' => 'Could not create the pending approval item.'];
        }
        return [
            'opId'        => $opId,
            'description' => $description,
            'message'     => 'Nothing has happened yet. Waiting for a human to Approve or Reject this in the Manager UI, Activity tray.',
        ];
    }

    /**
     * A human clicked Approve in the Manager UI (ActivityHandler's
     * approve_activity action — never called from an MCP tool or the CLI: see
     * this section's class doc for why there is deliberately no agent-facing
     * path to this method). Executes using ONLY the params recorded at
     * proposal time (ActivityService::approve()'s own doc comment) and marks
     * the tray entry done/failed accordingly. Works even if the proposing
     * workspace/agent no longer exists — nothing here reads the proposer's
     * identity, only the on-disk pending entry (spec Edge Case).
     *
     * @return array<string,mixed>
     */
    public static function approvePending(string $opId): array {
        $entry = ActivityService::approve($opId);
        if ($entry === null) {
            return ['error' => "No pending approval item with id '$opId' was found (it may already have been approved, rejected, or dismissed)."];
        }
        $meta   = is_array($entry['meta'] ?? null) ? $entry['meta'] : [];
        $tool   = (string)($meta['tool'] ?? '');
        $params = is_array($meta['params'] ?? null) ? $meta['params'] : [];
        $method = self::TIER3_EXECUTORS[$tool] ?? null;
        if ($method === null) {
            ActivityService::fail($opId, "No executor is registered for tool '$tool'.");
            return ['error' => "No executor is registered for tool '$tool'."];
        }

        $result = self::{$method}($params);
        if (isset($result['error'])) {
            ActivityService::fail($opId, (string)$result['error']);
            return ['error' => (string)$result['error']];
        }
        ActivityService::finish($opId, 'Approved and applied');
        return ['opId' => $opId, 'tool' => $tool, 'result' => $result];
    }

    /**
     * A human clicked Reject. Discards the pending item WITHOUT executing it
     * — see ActivityService::reject() for why this is a distinct action from
     * the generic Dismiss button.
     *
     * @return array<string,mixed>
     */
    public static function rejectPending(string $opId, string $reason = ''): array {
        $ok = ActivityService::reject($opId, $reason !== '' ? $reason : 'rejected by user');
        if (!$ok) {
            return ['error' => "No pending approval item with id '$opId' was found (it may already have been approved, rejected, or dismissed)."];
        }
        return ['opId' => $opId, 'rejected' => true];
    }

    /**
     * Uniform failure shape for every public method: log the real exception
     * server-side (message + class, never a secret — nothing in this file
     * ever holds one long enough to log it) and hand the caller back a
     * well-formed array instead of letting the throw propagate. An uncaught
     * exception here would kill the MCP stdio transport for every OTHER tool
     * call in the same session, not just this one — the same reasoning
     * RelayMcpTools::call() applies by returning ['status'=>'error', ...]
     * instead of throwing.
     *
     * @param array<string,mixed> $shape Extra keys the caller's contract
     *                                   promises even on failure (e.g. an
     *                                   empty 'agents' => [] so a caller can
     *                                   foreach() the result unconditionally).
     * @return array<string,mixed>
     */
    private static function failure(string $method, \Throwable $e, array $shape = []): array {
        try {
            LogService::log("AdminService::$method failed: " . get_class($e) . ': ' . $e->getMessage(), LogService::LOG_ERROR, 'AdminService');
        } catch (\Throwable $ignored) {
            // LogService itself is unavailable — still return a well-formed
            // array rather than compounding the failure.
        }
        // Scrub before returning: an exception string is a classic disclosure
        // vector (a failed env or vault read can carry the offending value) and this
        // result goes straight to a model. The log line above keeps the raw text —
        // that is our own record, and getLogs() scrubs it on the way out.
        $detail = $e->getMessage();
        if (class_exists('\\AICliAgents\\Services\\RedactionService')) {
            $detail = RedactionService::redact($detail);
        }
        return array_merge($shape, ['error' => "$method unavailable: " . $detail]);
    }
}
