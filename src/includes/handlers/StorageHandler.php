<?php
/**
 * <module_context>
 *     <name>StorageHandler</name>
 *     <description>Handles storage AJAX actions: persist, consolidate, repair, wipe, purge.</description>
 *     <dependencies>AICliAgentsManager, StorageMountService, StorageMigrationService, StorageMetricsService</dependencies>
 *     <constraints>Under 150 lines. Each method returns array for JSON encoding.</constraints>
 * </module_context>
 */

namespace AICliAgents\Handlers;

require_once __DIR__ . '/AgentHandler.php'; // R-170: AgentHandler::requireAgentId, same generation as this file (#367)

class StorageHandler {
    /** Tray title of the running storage migration ("Moving storage to <path>"). */
    private static string $migrateLabel = 'Moving storage';
    /** Progress of the last migration step. */
    private static int $migrateLastProgress = 0;
    /** True from the moment the migration wrote the new paths to the settings file. */
    private static bool $migrateSettingsSaved = false;

    /**
     * The tray row of a failed storage move: one plain error sentence, a recovery text that
     * says what is true now, and no stale step. Pure, so a test needs no migration.
     *
     * What was done decides the text (ACTIVITY_TRAY_PLAIN_NOTICES.md R5):
     *  - settings saved:   the setting already points to the new place;
     *  - progress 5 up:    workspaces were closed, and saves or merges may be queued at the old place;
     *  - before that:      nothing was changed.
     * @return array{error:string,recovery:string,step:string}
     */
    public static function migrationFailureRow(string $message, int $lastProgress, bool $settingsSaved): array {
        $error = trim((string)strtok($message, "\n"));
        if ($error === '') $error = 'The storage move did not finish.';
        if ($settingsSaved) {
            $recovery = 'The files were copied and the setting already points to the new place. Check the Storage settings, then open your workspaces again.';
        } elseif ($lastProgress >= 15) {
            $recovery = 'Your open workspaces were closed, and a save and a merge of layers were started at the old place. The setting still points to the old place. Open your workspaces again.';
        } elseif ($lastProgress >= 10) {
            $recovery = 'Your open workspaces were closed, and a save of their changes was started at the old place. The setting still points to the old place. Open your workspaces again.';
        } elseif ($lastProgress >= 5) {
            $recovery = 'Your open workspaces were closed. The setting still points to the old place. Open your workspaces again.';
        } else {
            $recovery = 'Nothing was changed at the old location.';
        }
        return ['error' => $error, 'recovery' => $recovery, 'step' => ''];
    }

    public static function handle($action, $id) {
        if (self::blockedByBackendMigration((string)$action)) {
            return ['status' => 'error', 'reason' => 'storage_migration', 'message' => 'Wait for the storage migration to finish before starting another storage action.'];
        }
        $result = null;
        switch ($action) {
            case 'get_storage_status':          $result = self::getStatus(); break;
            case 'get_boot_integrity_status':   return self::getBootIntegrityStatus(); // read-only, no Nchan publish
            case 'get_supervisor_status':       return self::getSupervisorStatus(); // read-only, no Nchan publish
            case 'get_force_reclaim_state':     return self::getForceReclaimState(); // read-only, no Nchan publish
            case 'storage_job_status':          return self::storageJobStatus();    // S-08: read-only, no Nchan publish
            case 'storage_jobs_active':         return self::storageJobsActive();   // S-08: read-only, no Nchan publish
            case 'enumerate_storage_targets':   return self::enumerateStorageTargets(); // S-11: read-only, no Nchan publish
            case 'get_array_stop_impact':       return self::arrayStopImpact();     // R-172: read-only, asked only when the person presses Stop array
            case 'preflight_storage_backend':   return self::preflightStorageBackend(); // global engine policy, read-only
            case 'preflight_upper_mode':        return self::preflightUpperMode();      // #456: writable-layer placement, read-only
            case 'restore_from_sibling':        return self::restoreFromSibling(); // mutating, but no Nchan — integrity-only
            case 'list_halts':                  return self::listHalts();           // read-only, no Nchan publish
            case 'clear_halt':                  return self::clearHalt();           // mutating, invalidates boot cache
            case 'auto_heal_agent_install':     return self::autoHealAgentInstall(); // WP #916 — self-heal agent total_loss via reinstall
            case 'persist_agent':               $result = self::persistAgent($id); break;
            // Note: get_task_status outputs raw JSON and is dispatched directly
            case 'persist_home':                $result = self::persistHomeAction(); break;
            case 'consolidate_storage':         $result = self::consolidate(); break;
            case 'convert_storage':             $result = self::convertAction(); break; // #496: layered home → plain folders
            // BOOT_DISK_SPACE.md R4: old agent versions
            case 'agent_old_versions':          return self::agentOldVersions();      // read-only, no Nchan publish
            case 'remove_old_versions':         $result = self::removeOldVersionsAction(); break;
            case 'clear_home_caches':           $result = self::clearHomeCaches(); break; // BOOT_DISK_SPACE.md R5: empty the caches of a plain home
            case 'get_home_cache_status':       return self::homeCacheStatus();    // BOOT_DISK_SPACE.md R5: read-only
            case 'agent_convert_estimate':      return self::agentConvertEstimate(); // BOOT_DISK_SPACE.md R3: read-only, no Nchan publish
            case 'convert_agents':              $result = self::convertAgentsAction(); break; // BOOT_DISK_SPACE.md R3: layered agents → plain folders
            case 'get_home_sessions':            return self::getHomeSessions();    // R1: read-only, list open sessions for home consolidate warning
            case 'backup_home':                 $result = self::backupHomeAction(); break; // HOME_BACKUP.md
            case 'backup_status':               return self::backupStatusAction();  // HOME_BACKUP.md: read-only, no Nchan publish
            case 'backup_validate_target':       return self::backupValidateTargetAction(); // HOME_BACKUP.md: read-only, no Nchan publish
            case 'get_home_backup_settings':    return self::getHomeBackupSettingsAction(); // HOME_BACKUP.md #287: read-only
            case 'set_home_backup_setting':     $result = self::setHomeBackupSettingAction(); break; // HOME_BACKUP.md #287: one per-home key
            case 'list_backups':                return self::listBackupsAction();   // HOME_RESTORE.md: read-only, no Nchan publish
            case 'restore_home':                $result = self::restoreHomeAction(); break; // HOME_RESTORE.md
            case 'delete_backup_snapshot':      $result = self::deleteBackupSnapshotAction(); break; // HOME_BACKUP.md 2026-09-24 follow-up
            case 'restore_status':              return self::restoreStatusAction(); // HOME_RESTORE.md: read-only, no Nchan publish
            case 'graduate_targets':            return self::graduateTargets();    // Bug #1380: read-only, qualifying relocation targets
            case 'graduate_storage':            $result = self::graduate(); break; // Bug #1380: relocate off USB flash to a durable target
            case 'repair_agent_storage':        $result = self::repairAgent($id); break;
            case 'repair_home_storage':         $result = self::repairHome(); break;
            case 'delete_home_storage':         $result = self::deleteHome(); break; // Bug #1379
            case 'delete_home_confirm_token':   return self::deleteHomeConfirmTokenAction(); // #654: single-use token, no storage change
            case 'wipe_storage':                $result = self::wipe(); break;
            case 'nuclear_rebuild_storage':     $result = self::wipe(); break;
            case 'purge_artifacts':             $result = self::purgeArtifacts(); break;
            case 'preflight_migrate':           return self::preflightMigrate();
            case 'get_storage_kind_state':     return \AICliAgents\Services\StorageBackendMigrationService::kindStates();
            case 'storage_backend_migration_status': return \AICliAgents\Services\StorageBackendMigrationService::status();
            case 'storage_backend_migration_recovery': $result = \AICliAgents\Services\StorageBackendMigrationService::recoverInterruptedAsync((string)($_REQUEST['operation'] ?? ''), getAICliConfig()); break;
            case 'execute_migrate':             $result = self::executeMigrateExclusive(); break;
            case 'execute_storage_backend':     $result = self::executeStorageBackend(); break;
            case 'execute_upper_mode':          $result = self::executeUpperMode(); break;
            default:                            return null;
        }
        // D-402: After any mutating storage action, publish updated stats via Nchan
        if ($action !== 'get_storage_status') {
            \AICliAgents\Services\EventBus::publish('storage.status', [], aicli_get_storage_status());
        }
        return $result;
    }

    /** Storage changes that can conflict with a global agent and home conversion. */
    public static function blockedByBackendMigration(string $action): bool
    {
        $mutating = [
            'restore_from_sibling', 'clear_halt', 'auto_heal_agent_install', 'persist_agent', 'persist_home',
            'consolidate_storage', 'convert_storage', 'remove_old_versions', 'clear_home_caches', 'convert_agents',
            'backup_home', 'set_home_backup_setting', 'restore_home', 'delete_backup_snapshot',
            'graduate_storage', 'repair_agent_storage', 'repair_home_storage', 'delete_home_storage',
            'wipe_storage', 'nuclear_rebuild_storage', 'purge_artifacts', 'execute_migrate', 'execute_upper_mode',
        ];
        if (!in_array($action, $mutating, true)) return false;
        return \AICliAgents\Services\StorageBackendMigrationService::isMigrationInProgress();
    }

    /** Actions handled by this handler. */
    public static function actions() {
        return ['get_storage_status', 'get_boot_integrity_status', 'get_supervisor_status', 'get_force_reclaim_state', 'get_task_status',
                'storage_job_status', 'storage_jobs_active', 'enumerate_storage_targets', 'get_array_stop_impact', 'preflight_storage_backend', 'get_storage_kind_state', 'storage_backend_migration_status', 'storage_backend_migration_recovery', 'preflight_upper_mode',
                'restore_from_sibling', 'list_halts', 'clear_halt', 'auto_heal_agent_install',
                'persist_agent', 'persist_home',
                'consolidate_storage', 'convert_storage', 'agent_old_versions', 'remove_old_versions', 'clear_home_caches', 'get_home_cache_status', 'agent_convert_estimate', 'convert_agents', 'get_home_sessions', 'graduate_targets', 'graduate_storage',
                'repair_agent_storage', 'repair_home_storage', 'delete_home_storage', 'delete_home_confirm_token',
                'wipe_storage', 'nuclear_rebuild_storage', 'purge_artifacts',
                'backup_home', 'backup_status', 'backup_validate_target',
                'get_home_backup_settings', 'set_home_backup_setting',
                'list_backups', 'restore_home', 'restore_status', 'delete_backup_snapshot', 'execute_storage_backend', 'execute_upper_mode'];
    }

    private static function getStatus() {
        return aicli_get_storage_status();
    }

    /**
     * Phase 4a: Return the cached boot integrity sweep result, or run the sweep
     * if it hasn't run this boot yet.
     *
     * Response shape:
     * {
     *   "status": "ok",
     *   "sweep": [{"entity": "home/root", "state": "healthy", "evidence": {...}}, ...],
     *   "summary": {"healthy": N, "needs_attention": N, "fresh": N},
     *   "any_critical": bool,
     *   "any_warning": bool
     * }
     */
    private static function getBootIntegrityStatus() {
        $cached = \AICliAgents\Services\BootIntegrityService::readCachedSweep();
        if ($cached === null) {
            // No cache -- run the sweep now (synchronous but fast in warn mode)
            $results = \AICliAgents\Services\BootIntegrityService::runBootSweep();
            $cached  = \AICliAgents\Services\BootIntegrityService::readCachedSweep();
            if ($cached === null) {
                // Fallback if cache write failed
                $summary = ['healthy' => 0, 'needs_attention' => 0, 'fresh' => 0];
                $anyCritical = false;
                $anyWarning  = false;
                foreach ($results as $r) {
                    $s = $r['state'];
                    if ($s === 'healthy') {
                        $summary['healthy']++;
                    } elseif ($s === 'genuine_fresh') {
                        $summary['fresh']++;
                    } else {
                        $summary['needs_attention']++;
                        $criticalStates = ['total_loss', 'partial_loss', 'host_mismatch', 'corrupt_layers'];
                        if (in_array($s, $criticalStates, true)) {
                            $anyCritical = true;
                        } else {
                            $anyWarning = true;
                        }
                    }
                }
                return ['status' => 'ok', 'sweep' => $results, 'summary' => $summary,
                        'any_critical' => $anyCritical, 'any_warning' => $anyWarning];
            }
        }
        return array_merge(['status' => 'ok'], $cached);
    }

    /**
     * Phase 3.3: Return the current supervisor daemon status for the React UI.
     *
     * Called via get_supervisor_status AJAX action. The React isSyncing overlay
     * polls this every 2 s after a workspace close and clears when
     * state=idle and queue_depth=0.
     *
     * Response shape:
     * {
     *   "running":     bool,
     *   "tick_age_s":  int|null,
     *   "work":        {state, op, entity, ...}|null,
     *   "status":      {state, queue_depth, ...}|null,
     *   "queue_depth": int,
     *   "pressure":    {"level": "ok"|"soft"|"hard"|"critical", "bytes": int}
     * }
     */
    private static function getSupervisorStatus(): array {
        $running   = \AICliAgents\Services\SupervisorService::isRunning();
        $tickAge   = \AICliAgents\Services\SupervisorService::getTickAge();
        $workState = \AICliAgents\Services\SupervisorService::getWorkState();
        $status    = \AICliAgents\Services\SupervisorService::getStatus();

        $queueDepth = 0;
        if (is_array($status) && isset($status['queue_depth'])) {
            $queueDepth = (int)$status['queue_depth'];
        } elseif (is_array($workState) && isset($workState['queue_depth'])) {
            $queueDepth = (int)$workState['queue_depth'];
        }

        // #165: derive a plain-English "a workspace save/bake is running now" flag
        // from the live work state, so the Manager Store cards can show a persistent
        // indicator (not just the transient notification pill) while an upgrade is
        // deferred behind a home bake.
        $bake = self::describeSupervisorBake($workState);

        return [
            'running'           => $running,
            'tick_age_s'        => $tickAge,
            'work'              => $workState,
            'status'            => $status,
            'queue_depth'       => $queueDepth,
            'pressure'          => self::_computeDirtyPressure(),
            'consolidate_fails' => self::_readConsolidateFails(),
            'baking'            => $bake['baking'],
            'baking_label'      => $bake['label'],
        ];
    }

    /**
     * #165: PURE — describe the supervisor's live work as a user-facing "baking"
     * state. A running bake/consolidate is the condition that defers an agent
     * upgrade behind a home save; queued/deferred/idle are NOT "baking now".
     *
     * @param array<string,mixed>|null $work  SupervisorService::getWorkState()
     * @return array{baking:bool, op:string, entity:string, label:string}
     */
    public static function describeSupervisorBake(?array $work): array
    {
        $state  = is_array($work) ? (string)($work['state'] ?? '')  : '';
        $op     = is_array($work) ? (string)($work['op'] ?? '')     : '';
        $entity = is_array($work) ? (string)($work['entity'] ?? '') : '';
        $baking = ($state === 'running' && ($op === 'bake' || $op === 'consolidate'));
        if (!$baking) {
            return ['baking' => false, 'op' => '', 'entity' => '', 'label' => ''];
        }
        $label = $op === 'consolidate' ? 'Consolidating workspace data…' : 'Saving workspace data…';
        return ['baking' => true, 'op' => $op, 'entity' => $entity, 'label' => $label];
    }

    /**
     * Return the current storage-maintenance wait state for the React UI.
     *
     * Reads /tmp/unraid-aicliagents/supervisor/escalation/home_<safeId>.json
     * written by the bash supervisor when a home overlay is busy AND storage
     * reclaim is needed. The React banner polls this every 5 s and displays a
     * waiting notice + "Close now" shortcut.
     *
     * Response shapes:
     *   no file / parse error:  { "status":"ok", "state":"none", "now":<epoch> }
     *   waiting:                { "status":"ok", "now":<epoch>, "state":"waiting",
     *                             "entity":"home/root", "reason":"layers_near_max",
     *                             "started_at":<epoch> }
     */
    private static function getForceReclaimState(): array {
        return self::forceReclaimState();
    }

    /**
     * Shared static (docs/specs/EVENT_FIRST_RECONCILIATION.md 1b.4): the SAME
     * computation the `get_force_reclaim_state` AJAX action answers with, also
     * called by the supervisor-tick publisher (publish-storage-status.php) so
     * the `maintenance` key on `aicli_storage_status` can never drift from what
     * this read reports. Still read-only — computing this value has no side
     * effect; only a caller that PUBLISHES the result (the AJAX handler's own
     * mutating-action tail, or the supervisor script) makes it visible on the
     * wire.
     *
     * Test seams: AICLI_TMP_BASE moves the folder that holds supervisor/escalation (the
     * same variable ConsolidateState uses), and $canConvert replaces the conversion probe
     * (fn(user): bool). Production sets neither.
     */
    public static function forceReclaimState(?callable $canConvert = null): array {
        $config = getAICliConfig();
        $user   = (string)($config['user'] ?? 'root');
        if (empty($user)) $user = 'root';

        // #131: surface an in-progress home consolidation on the same poll the
        // drawer and Storage tab already use, so both can show a live "tidy-up
        // underway" banner and guard conflicting actions. Distinct from `state`
        // (which means reclaim is RECOMMENDED / waiting, not running).
        require_once __DIR__ . '/../services/ConsolidateState.php';
        $consolidating = \AICliAgents\Services\ConsolidateState::isHomeConsolidating($user);
        $extra = ['consolidating' => $consolidating, 'consolidating_user' => $consolidating ? $user : ''];

        $safeId = str_replace(['/', ' '], '_', $user);
        $tmpBase = (string)getenv('AICLI_TMP_BASE');
        if ($tmpBase === '') $tmpBase = \AICliAgents\Services\SecretPaths::stateRoot();
        $path   = "$tmpBase/supervisor/escalation/home_{$safeId}.json";

        $none = array_merge(['status' => 'ok', 'state' => 'none', 'now' => time()], $extra);
        if (!file_exists($path)) return $none;
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') return $none;
        $decoded = @json_decode($raw, true);
        if (!is_array($decoded)) return $none;

        // #496 (CONVERT_LAYERED_TO_PLAIN_FOLDERS.md R9): a waiting tidy-up for a home
        // that can become plain folders offers the conversion instead of a merge. The
        // tray notice and the terminal banner read this one flag. Computed only while
        // a wait is open, so the common no-wait read runs no probe.
        if (($decoded['state'] ?? '') === 'waiting') {
            require_once __DIR__ . '/../services/FileStorage.php';
            $extra['can_convert'] = $canConvert !== null
                ? (bool)$canConvert($user)
                : \AICliAgents\Services\FileStorage::canConvertHome($user);
        }

        return array_merge(['status' => 'ok', 'now' => time()], $decoded, $extra);
    }

    /**
     * WP #922: surface recent consolidate failures to the Storage tab so the
     * user sees the issue before it escalates to a halt (which happens at 2
     * consecutive failures).
     *
     * Reads the supervisor's per-entity counter dir + the Flash-backed
     * failure-snapshot dir.
     *
     * @return array{counts: array<string,int>, recent_snapshots: array<int,string>, total_snapshots: int}
     */
    private static function _readConsolidateFails(): array {
        $countsDir = \AICliAgents\Services\SupervisorService::consolidateFailsDir();
        $counts = [];
        if (is_dir($countsDir)) {
            foreach (@scandir($countsDir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') continue;
                $val = @file_get_contents("$countsDir/$entry");
                if ($val !== false) {
                    // Filenames are "type__id" — undo the safe-encode
                    $human = str_replace('__', '/', $entry);
                    $counts[$human] = (int)trim($val);
                }
            }
        }

        $snapDir = \AICliAgents\Services\SecretPaths::flashRoot() . '/failures';
        $recent  = [];
        $total   = 0;
        if (is_dir($snapDir)) {
            $files = @scandir($snapDir, SCANDIR_SORT_DESCENDING) ?: [];
            foreach ($files as $f) {
                if ($f === '.' || $f === '..') continue;
                if (substr($f, -4) !== '.log') continue;
                $total++;
                if (count($recent) < 3) $recent[] = $f;
            }
        }

        return [
            'counts'           => $counts,
            'recent_snapshots' => $recent,
            'total_snapshots'  => $total,
        ];
    }

    /**
     * Compute the dirty-pressure tier from ZRAM upper directory sizes.
     * Path is built from known fixed constants — no user input in the shell arg.
     *
     * @return array{level: string, bytes: int}
     */
    private static function _computeDirtyPressure(): array {
        $config = getAICliConfig();
        $softMb = max(1, (int)($config['dirty_threshold_soft_mb']     ?? 512));
        $hardMb = max(1, (int)($config['dirty_threshold_hard_mb']     ?? 1024));
        $critMb = max(1, (int)($config['dirty_threshold_critical_mb'] ?? 2048));

        $zramBase   = \AICliAgents\Services\SecretPaths::stateRoot() . '/zram_upper';
        $totalBytes = 0;

        foreach (['homes', 'agents'] as $subdir) {
            $root = "$zramBase/$subdir";
            if (!is_dir($root)) {
                continue;
            }
            $entries = @scandir($root);
            if (!is_array($entries)) {
                continue;
            }
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $upperDir = "$root/$entry/upper";
                if (!is_dir($upperDir)) {
                    continue;
                }
                $raw = @shell_exec('du -sb ' . escapeshellarg($upperDir) . ' 2>/dev/null');
                if ($raw !== null && $raw !== '') {
                    $parts = explode("\t", trim($raw));
                    $totalBytes += (int)$parts[0];
                }
            }
        }

        $totalMb = $totalBytes / 1048576;
        $level   = 'ok';
        if ($totalMb >= $critMb) {
            $level = 'critical';
        } elseif ($totalMb >= $hardMb) {
            $level = 'hard';
        } elseif ($totalMb >= $softMb) {
            $level = 'soft';
        }

        return ['level' => $level, 'bytes' => $totalBytes];
    }

    /**
     * Phase 4b: Return all currently-halted entities.
     *
     * Response shape:
     * {
     *   "status": "ok",
     *   "halts": [
     *     {"type": "home", "id": "root", "state": "legacy_unmanaged", "halted_at": "...",
     *      "details": {...}, "recommended_action": "restore_from_sibling"},
     *     ...
     *   ]
     * }
     */
    private static function listHalts(): array {
        $halts = \AICliAgents\Services\HaltService::listAllHalts();
        return ['status' => 'ok', 'halts' => $halts];
    }

    /**
     * Phase 4b: Clear the halt for a single entity.
     *
     * GET parameters: type (home|agent), id, reason.
     *
     * Response shape:
     * {"status": "ok"|"error", "message": "..."}
     */
    private static function clearHalt(): array {
        $type   = trim((string)($_REQUEST['type']   ?? ''));
        $id     = trim((string)($_REQUEST['id']     ?? ''));
        $reason = trim((string)($_REQUEST['reason'] ?? 'user_action'));

        if (!in_array($type, ['home', 'agent'], true) || $id === '') {
            return ['status' => 'error', 'message' => 'type and id are required (type must be home or agent)'];
        }
        if (!preg_match('/^[a-zA-Z0-9._-]{1,64}$/D', $id)) {
            return ['status' => 'error', 'message' => 'Invalid id format'];
        }
        // Sanitize reason: strip anything that isn't safe for a log line
        $reason = preg_replace('/[^a-zA-Z0-9._\- ]/', '', $reason);
        $reason = $reason !== '' ? $reason : 'user_action';

        $ok = \AICliAgents\Services\HaltService::clearHalt($type, $id, $reason);

        // Invalidate boot integrity cache so next fetch reflects cleared state
        @unlink(\AICliAgents\Services\SecretPaths::stateRoot() . '/.boot_integrity_cache.json');

        return [
            'status'  => $ok ? 'ok' : 'error',
            'message' => $ok ? "Halt cleared for $type/$id." : "Failed to clear halt for $type/$id.",
        ];
    }

    /**
     * Phase 0: Restore sibling layers into the active persist path for an entity.
     *
     * POST parameters: type (home|agent), id (user or agent-id).
     * CSRF already validated by the dispatcher (via $_REQUEST['csrf_token']).
     *
     * Response shape:
     * {
     *   "status": "ok"|"error",
     *   "restored": int,
     *   "skipped": int,
     *   "errors": string[],
     *   "message": string
     * }
     */
    private static function restoreFromSibling() {
        $type = trim((string)($_REQUEST['type'] ?? ''));
        $id   = trim((string)($_REQUEST['id']   ?? ''));

        if (!in_array($type, ['home', 'agent'], true) || $id === '') {
            return ['status' => 'error', 'message' => 'type and id are required (type must be home or agent)'];
        }

        // Sanitize id: allow alphanumerics, hyphens, underscores, dots
        if (!preg_match('/^[a-zA-Z0-9._-]{1,64}$/D', $id)) {
            return ['status' => 'error', 'message' => 'Invalid id format'];
        }

        $result = \AICliAgents\Services\BootIntegrityService::restoreFromSibling($type, $id);

        // Invalidate the boot integrity cache so the next fetch reflects the restored state
        @unlink(\AICliAgents\Services\SecretPaths::stateRoot() . '/.boot_integrity_cache.json');

        $status  = $result['ok'] ? 'ok' : 'error';
        $message = $result['ok']
            ? 'Restored ' . $result['restored'] . ' layer(s) from sibling directory.'
            : 'Restore completed with errors (' . (int)$result['restored'] . ' layer(s) restored, backup kept): '
                . ($result['errors'] !== [] ? implode('; ', $result['errors']) : 'no layer was found to restore');

        return array_merge(['status' => $status, 'message' => $message], $result);
    }

    /**
     * WP #916: self-heal an agent total_loss halt by reinstalling the agent.
     *
     * Agent storage is pure npm code — nothing to wipe, nothing to lose. When
     * the boot-integrity sweep finds total_loss on an agent entity, the right
     * recovery is to npm-reinstall the binary, not to nag the user with a
     * destructive confirmation. The UI auto-triggers this in place of the
     * old "Start fresh and abandon data" button for agent/* total_loss halts.
     *
     * GET parameters: id (agent-id).
     *
     * Response shape:
     *   { "status": "ok"|"error", "agent": "<id>", "version": "<v|null>",
     *     "message": "<human-readable>" }
     */
    private static function autoHealAgentInstall(): array {
        $id = trim((string)($_REQUEST['id'] ?? ''));
        if ($id === '' || !preg_match('/^[a-zA-Z0-9._-]{1,64}$/D', $id)) {
            return ['status' => 'error', 'message' => 'Invalid or missing agent id'];
        }

        // Sanity: agent must be in the registry. If not, the halt is stale —
        // clear it and remove the manifest entry; don't try to reinstall a
        // non-existent agent.
        $registry = \AICliAgents\Services\AgentRegistry::getRegistry();
        if (!isset($registry[$id])) {
            require_once __DIR__ . '/../services/FileStorage.php';
            \AICliAgents\Services\FileStorage::dropManifestEntry("agent/$id");   // Epic #1310 facade intent
            \AICliAgents\Services\HaltService::clearHalt('agent', $id, 'auto_heal_stale_registry');
            @unlink(\AICliAgents\Services\SecretPaths::stateRoot() . '/.boot_integrity_cache.json');
            return [
                'status'  => 'ok',
                'agent'   => $id,
                'version' => null,
                'message' => "Agent '$id' is not in the registry — cleared the stale halt and manifest entry.",
            ];
        }

        // Pull recorded version from the manifest's most recent layer entry,
        // if any. installAgent($id, null) means "latest" — that's fine when
        // we can't recover the pinned version.
        $version = self::_recordedAgentVersion($id);

        $result = \AICliAgents\Services\InstallerService::installAgent($id, $version);
        $ok = is_array($result) && (($result['status'] ?? '') !== 'error');

        if ($ok) {
            \AICliAgents\Services\HaltService::clearHalt('agent', $id, 'auto_heal_reinstalled');
            @unlink(\AICliAgents\Services\SecretPaths::stateRoot() . '/.boot_integrity_cache.json');
            \AICliAgents\Services\LifecycleLogService::log(
                \AICliAgents\Services\LifecycleLogService::LEVEL_INFO,
                'storage_handler', 'agent_auto_healed',
                ['agent' => $id, 'version' => $version]
            );
            return [
                'status'  => 'ok',
                'agent'   => $id,
                'version' => $version,
                'message' => "Reinstalled agent '$id'" . ($version ? " (version $version)" : ' (latest)') . " and cleared the halt.",
            ];
        }

        return [
            'status'  => 'error',
            'agent'   => $id,
            'version' => $version,
            'message' => 'Auto-heal install failed: ' . (string)($result['message'] ?? $result['error'] ?? 'unknown error'),
        ];
    }

    /** WP #916: look up the version of the most recent recorded layer for an agent. */
    private static function _recordedAgentVersion(string $agentId): ?string {
        $entity = \AICliAgents\Services\LayerManifestService::getEntity("agent/$agentId");
        if (!is_array($entity)) return null;
        $layers = $entity['expected_layers'] ?? [];
        if (!is_array($layers) || empty($layers)) return null;
        $last = end($layers);
        if (is_array($last) && isset($last['version']) && is_string($last['version'])) {
            return $last['version'];
        }
        return null;
    }

    /**
     * S-08 (#1353): single job-ledger entry for the UI — ?job_id=<id>.
     * Response: { "status":"ok", "job": {job_id, op, entity, state, exit,
     * defer_reason, attempt, queued_at, started_at, finished_at, ...} | null }.
     * Also drives the job→activity-tray bridge (the supervisor can't call PHP).
     */
    private static function storageJobStatus(): array {
        $jobId = trim((string)($_REQUEST['job_id'] ?? ''));
        if ($jobId === '' || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._\-]{0,127}$/D', $jobId)) {
            return ['status' => 'error', 'message' => 'job_id required ([A-Za-z0-9._-], max 128 chars)'];
        }
        \AICliAgents\Services\SupervisorService::syncJobActivities();
        return ['status' => 'ok', 'job' => \AICliAgents\Services\SupervisorService::getJob($jobId)];
    }

    /**
     * S-08 (#1353): all ACTIVE (queued|running|deferred) job-ledger entries.
     * Response: { "status":"ok", "jobs": [ {...}, ... ] }.
     */
    private static function storageJobsActive(): array {
        \AICliAgents\Services\SupervisorService::syncJobActivities();
        return ['status' => 'ok', 'jobs' => \AICliAgents\Services\SupervisorService::listJobs(true)];
    }

    /**
     * S-08: register a `storage_job_<jobId>` activity-tray entry for a
     * user-initiated supervisor job, so the tray shows queued/running/done
     * (state mirrored by SupervisorService::syncJobActivities on poll paths).
     */
    private static function trackJobActivity(?string $jobId, string $label): void {
        if ($jobId === null || $jobId === '') return;
        // ACTIVITY_TRAY_PLAIN_NOTICES.md R8/R9: the first step reads "Queued"; Cancel
        // would only flip the row (the supervisor keeps the job), so the row has none.
        \AICliAgents\Services\ActivityService::register("storage_job_$jobId", 'storage', $label, [
            'step' => 'Queued', 'progress' => 5, 'meta' => ['jobId' => $jobId, 'cancel' => 'none'],
        ]);
    }

    /**
     * ACTIVITY_TRAY_PLAIN_NOTICES.md R4: the name a person sees for an agent
     * ("Claude Code"), never the registry id. Falls back to the id.
     */
    public static function trayAgentName(string $agentId): string {
        try {
            require_once __DIR__ . '/../services/AgentRegistry.php';
            $registry = \AICliAgents\Services\AgentRegistry::getRegistry();
            $name = is_array($registry[$agentId] ?? null) ? trim((string)($registry[$agentId]['name'] ?? '')) : '';
            return $name !== '' ? $name : $agentId;
        } catch (\Throwable $e) {
            return $agentId;
        }
    }

    private static function persistAgent($id) {
        // R-170: only a registered agent id is queued; any other text would become a job and a status file.
        if (AgentHandler::requireAgentId($id) === null) return ['status' => 'error', 'message' => 'Invalid agent id'];
        // Enqueue a user-priority bake rather than blocking inline (Phase 3.3).
        // Priority 5 = user-clicked, pre-empts schedule and dirty-pressure ops.
        // S-08 (#1353): tracked — job_id ADDED to the response (back-compat keys
        // unchanged); the job lands in the activity tray as type `storage`.
        $jobId = \AICliAgents\Services\SupervisorService::enqueueJob('agent', $id, 'bake', 'user_persist', 5);
        if ($jobId === null) {
            // Ledger unavailable — preserve the legacy untracked enqueue.
            \AICliAgents\Services\SupervisorService::enqueue('agent', $id, 'bake', 'user_persist', 5);
        }
        self::trackJobActivity($jobId, "Saving agent files: " . self::trayAgentName((string)$id));
        return ['status' => 'ok', 'message' => 'Persistence queued. The supervisor will bake the agent layer shortly.', 'baking' => true, 'job_id' => $jobId];
    }

    /**
     * #651: the home a Persist or Repair button was clicked for. The card sends
     * `id` (the page for the configured home sends nothing); a caller may also
     * send `user`, or the older `type=home_<user>`. Only when none is present
     * does the action fall back to the configured home. Returns '' for a name
     * that is not a plain user id.
     */
    public static function homeActionUser(): string {
        foreach (['user', 'id', 'type'] as $key) {
            $raw = $_REQUEST[$key] ?? '';
            if ($raw !== '' && (!is_string($raw) || preg_match('/[\x00-\x1F\x7F]/', $raw))) return '';
        }
        $user = trim((string)($_REQUEST['user'] ?? ''));
        if ($user === '') $user = trim((string)($_REQUEST['id'] ?? ''));
        if ($user === '') {
            $type = (string)($_REQUEST['type'] ?? '');
            if (strpos($type, 'home_') === 0) $user = substr($type, 5);
        }
        if ($user === '') $user = (string)(getAICliConfig()['user'] ?? 'root');
        if ($user === '0') $user = 'root';
        return preg_match('/^[A-Za-z0-9._-]+$/D', $user) ? $user : '';
    }

    /** AJAX: persist the home that was clicked (the Storage tab's Persist button). */
    private static function persistHomeAction(): array {
        $user = self::homeActionUser();
        if ($user === '') return ['status' => 'error', 'message' => 'invalid_user'];
        return self::persistHome($user);
    }

    /**
     * Public entry point shared by the `persist_home` AJAX action and the admin
     * tool `aicli_persist_home` (Tier 2, HOME_PERSIST_CONSOLIDATE_TOOLS.md,
     * 2026-09-17) — ONE place queues a home bake, so the tray pill, the job and
     * the result shape can never diverge between the button and the tool.
     * `$user` '' or '0' means the configured home user (uid 0 → root).
     */
    public static function persistHome(string $user = ''): array {
        // R1 (HOME_PERSIST_PILL_AND_FEEDBACK): mirror persistAgent() — enqueue a
        // tracked home/bake/user_persist/priority-5 job so the activity-tray pill
        // appears (queued → running → done) just like agent and consolidate operations.
        // R4: the blocking helper aicli_persist_home is unchanged — still used by
        // saveWorkspaceEnvs for synchronous env-save persistence.
        $user = trim($user);
        if ($user === '' || $user === '0') $user = 'root';
        if (!preg_match('/^[A-Za-z0-9._-]+$/D', $user)) {
            return ['status' => 'error', 'message' => 'invalid_user'];
        }
        // #567: a save in the emergency session keeps nothing. Say so; queue nothing.
        require_once __DIR__ . '/../services/TaskService.php';
        if (\AICliAgents\Services\TaskService::inEmergencyMode()) {
            return ['status' => 'emergency', 'message' => \AICliAgents\Services\TaskService::EMERGENCY_NOT_KEPT];
        }
        $jobId = \AICliAgents\Services\SupervisorService::enqueueJob('home', $user, 'bake', 'user_persist', 5);
        if ($jobId === null) {
            // Ledger unavailable — preserve the legacy untracked enqueue.
            \AICliAgents\Services\SupervisorService::enqueue('home', $user, 'bake', 'user_persist', 5);
        }
        self::trackJobActivity($jobId, "Saving home: $user");
        return ['status' => 'ok', 'message' => 'Persistence queued. The supervisor will bake the home layer shortly.', 'baking' => true, 'job_id' => $jobId];
    }

    /**
     * R1: Return the list of open sessions for a home user, so the UI can warn
     * before queuing a consolidate that will close them.
     * Read-only — no Nchan publish.
     */
    /**
     * Read-only: enumerate active terminal sessions for a home user.
     * Called by the confirm dialog before queuing a consolidate.
     *
     * R1.1: each session now includes agent display name and icon from the
     * registry, plus an explicit 'workspace' alias for the workspace path.
     */
    private static function getHomeSessions(): array {
        return self::homeSessionsResponse();
    }

    /**
     * The `get_home_sessions` answer. $sessions and $registry are test seams with the shape of
     * TerminalService::listActiveSessionsForHome (fn(id): array) and AgentRegistry::getRegistry().
     *
     * @return array{status:string,message?:string,sessions?:list<array<string,string>>}
     */
    public static function homeSessionsResponse(?callable $sessions = null, ?array $registry = null): array {
        $id = (string)($_GET['id'] ?? '');
        if (!preg_match('/^[A-Za-z0-9._-]+$/D', $id)) {
            return ['status' => 'error', 'message' => 'invalid_id'];
        }
        // #617: a read endpoint does not reap sessions. The sweep runs from actions and the supervisor.
        $mapped = self::listHomeSessions($id, false, $sessions, $registry);
        return ['status' => 'ok', 'sessions' => array_values($mapped)];
    }

    /**
     * The decorated session list behind `get_home_sessions`, reusable by the
     * admin tool's consolidate proposal (HOME_PERSIST_CONSOLIDATE_TOOLS.md) so
     * the description a human approves names the same sessions the Storage
     * tab's dialog shows. Read-only.
     *
     * @param bool $sweep false from a read endpoint (#617: no orphan reap on a read)
     * $listSessions and $registry are test seams (see homeSessionsResponse()).
     *
     * @return list<array{id:string,agentId:string,name:string,icon:string,path:string,workspace:string}>
     */
    public static function listHomeSessions(string $id, bool $sweep = true, ?callable $listSessions = null, ?array $registry = null): array {
        if (!preg_match('/^[A-Za-z0-9._-]+$/D', $id)) {
            return [];
        }
        if ($registry === null) {
            require_once __DIR__ . '/../services/AgentRegistry.php';
            $registry = \AICliAgents\Services\AgentRegistry::getRegistry();
        }
        $sessions = $listSessions !== null
            ? $listSessions($id)
            : \AICliAgents\Services\TerminalService::listActiveSessionsForHome($id, $sweep);
        $mapped = array_map(function($s) use ($registry) {
            $agentId  = (string)($s['agentId'] ?? '');
            $path     = (string)($s['path']    ?? '');
            $agent    = $registry[$agentId] ?? [];
            return [
                'id'        => (string)($s['id'] ?? ''),
                'agentId'   => $agentId,
                'name'      => (string)($agent['name']     ?? $agentId),
                'icon'      => (string)($agent['icon_url'] ?? ''),
                'path'      => $path,
                'workspace' => $path,
            ];
        }, $sessions);
        return array_values($mapped);
    }

    // -------------------------------------------------------------------
    // HOME_BACKUP.md — a clean, restorable copy of a user's home
    // -------------------------------------------------------------------

    /** AJAX: POST user, optional target/quiesce overrides. */
    private static function backupHomeAction(): array {
        $user = trim((string)($_REQUEST['user'] ?? ''));
        if ($user === '') $user = (string)(getAICliConfig()['user'] ?? 'root');
        if ($user === '0') $user = 'root';
        $opts = [];
        if (isset($_REQUEST['target']))  $opts['target']  = (string)$_REQUEST['target'];
        if (isset($_REQUEST['quiesce'])) $opts['quiesce'] = (string)$_REQUEST['quiesce'];
        aicli_log("AJAX Request: Back up home for $user", AICLI_LOG_INFO);
        return self::backupHome($user, $opts);
    }

    /**
     * Public static entry point for the admin tool's approve path
     * (`aicli_backup_home`, Tier 3) — same result shape as the AJAX action.
     *
     * @param array{target?:string,quiesce?:string} $opts request-time overrides;
     *        anything omitted falls back to the cfg keys (backup_target etc.).
     * @return array{status:string,jobId?:string,opId?:string,message?:string}
     */
    public static function backupHome(string $user, array $opts = []): array {
        if (!preg_match('/^[A-Za-z0-9._-]+$/D', $user)) {
            return ['status' => 'error', 'message' => 'invalid_user'];
        }
        require_once __DIR__ . '/../services/StorageTargetService.php';
        require_once __DIR__ . '/../services/StoragePathResolver.php';
        require_once __DIR__ . '/../services/SupervisorService.php';
        require_once __DIR__ . '/../services/ActivityService.php';
        require_once __DIR__ . '/../services/HomeBackupSettingsService.php';

        $config = getAICliConfig();
        // HOME_BACKUP.md #287: the home's OWN settings (a home with none
        // inherits the old global backup_* values once).
        $hs = \AICliAgents\Services\HomeBackupSettingsService::get($user);
        $target = trim((string)($opts['target'] ?? $hs['target']));
        if ($target === '') {
            return ['status' => 'error', 'message' => "No backup target is set for $user's home. Open Backup on the home's card in Settings > Storage and choose one first."];
        }
        $quiesce = (string)($opts['quiesce'] ?? $hs['quiesce']);
        if (!in_array($quiesce, ['cold', 'warm'], true)) $quiesce = 'cold';
        $keep = (int)$hs['keep'];
        if ($keep < 1) $keep = 1;
        $excludes = \AICliAgents\Services\HomeBackupSettingsService::excludesList($hs);
        $nudgeWorking = (bool)$hs['nudge_working'];

        // R2 pre-flight — any failure ends the job before a session is touched.
        $entity = "home/$user";
        if (\AICliAgents\Services\SupervisorService::isHalted($entity)) {
            return ['status' => 'error', 'message' => "Home backup refused: $user's home is halted."];
        }
        if (($dup = self::duplicateJobRefusal('home', $user, 'backup', "A backup of $user's home")) !== null) return $dup;
        $resolved = \AICliAgents\Services\StorageTargetService::resolveBackupTarget($target, $config);
        if (!$resolved['ok']) {
            return ['status' => 'error', 'message' => $resolved['message']];
        }
        $homeMount = \AICliAgents\Services\StoragePathResolver::homeMount($user);
        if (!is_dir($homeMount)) {
            return ['status' => 'error', 'message' => "Home backup refused: $user's home is not mounted."];
        }
        $homeBytes = self::duBytes($homeMount);
        $needed = self::requiredBackupSpace($homeBytes);
        if (!self::hasEnoughBackupSpace($resolved['free_bytes'], $homeBytes)) {
            return ['status' => 'error', 'message' => sprintf(
                'Home backup refused: the target has %s free but needs at least %s (1.5x the %s home).',
                self::humanBytes($resolved['free_bytes']), self::humanBytes($needed), self::humanBytes($homeBytes)
            )];
        }

        // Mint the job id ourselves so the options sidecar exists BEFORE the
        // queue file can possibly be popped (enqueueJob would mint its own).
        $jobId = \AICliAgents\Services\SupervisorService::generateJobId('backup', 'home', $user);
        $wrote = \AICliAgents\Services\SupervisorService::writeBackupOptions($jobId, [
            'target'        => $resolved['resolved'],
            'quiesce'       => $quiesce,
            'keep'          => $keep,
            'excludes'      => $excludes,
            'nudge_working' => $nudgeWorking,
        ]);
        if (!$wrote) {
            return ['status' => 'error', 'message' => 'Could not prepare the backup job. Try again.'];
        }
        if (!\AICliAgents\Services\SupervisorService::enqueue('home', $user, 'backup', 'user_backup_home', 5, $jobId)) {
            \AICliAgents\Services\SupervisorService::deleteBackupOptions($jobId);
            return ['status' => 'error', 'message' => 'Could not queue the backup job. Try again.'];
        }

        $opId = "user_backup_home_{$user}_{$jobId}";
        \AICliAgents\Services\ActivityService::register($opId, 'storage', "Backing up home $user", [
            'step' => 'Queued', 'progress' => 5, 'meta' => ['jobId' => $jobId, 'cancel' => 'none'],
        ]);
        \AICliAgents\Services\SupervisorService::wake();
        \AICliAgents\Services\LifecycleLogService::log(
            \AICliAgents\Services\LifecycleLogService::LEVEL_INFO,
            'storage', 'storage_backup_queued',
            ['user' => $user, 'target' => $resolved['resolved'], 'quiesce' => $quiesce, 'job_id' => $jobId]
        );
        return ['status' => 'ok', 'jobId' => $jobId, 'opId' => $opId];
    }

    /**
     * HOME_BACKUP.md #287: a home's own backup folder (''), from its
     * per-home settings. Never throws — a bad user or unreadable file is ''.
     */
    private static function homeBackupTarget(string $user): string {
        try {
            require_once __DIR__ . '/../services/HomeBackupSettingsService.php';
            // peek(): a status read never writes a settings file to the flash
            // (HOME_BACKUP.md 2026-09-24 follow-up, item 5).
            return trim((string)\AICliAgents\Services\HomeBackupSettingsService::peek($user)['target']);
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** The request's user, or the configured terminal user; '' when invalid. */
    private static function requestUser(): string {
        $user = trim((string)($_REQUEST['user'] ?? ''));
        if ($user === '') $user = (string)(getAICliConfig()['user'] ?? 'root');
        if ($user === '0') $user = 'root';
        return preg_match('/^[A-Za-z0-9._-]+$/D', $user) ? $user : '';
    }

    /** AJAX (read): GET user -> {status, settings}. HOME_BACKUP.md #287. */
    private static function getHomeBackupSettingsAction(): array {
        require_once __DIR__ . '/../services/HomeBackupSettingsService.php';
        $user = self::requestUser();
        if ($user === '') return ['status' => 'error', 'message' => 'invalid_user'];
        return ['status' => 'ok', 'settings' => \AICliAgents\Services\HomeBackupSettingsService::get($user)];
    }

    /**
     * AJAX: POST user, key, value -> {status, key, value, settings} (value =
     * what is now ON DISK) or {status:'error', key, message} with nothing
     * written. HOME_BACKUP.md #287 save-on-change contract. Writes only the
     * home's own settings file — never the plugin cfg / global storage policy.
     */
    private static function setHomeBackupSettingAction(): array {
        require_once __DIR__ . '/../services/HomeBackupSettingsService.php';
        $user = self::requestUser();
        $key = (string)($_REQUEST['key'] ?? '');
        if ($user === '') return ['status' => 'error', 'key' => $key, 'message' => 'invalid_user'];
        $res = \AICliAgents\Services\HomeBackupSettingsService::set($user, $key, $_REQUEST['value'] ?? '');
        if (($res['status'] ?? '') === 'ok') {
            aicli_log("Home backup setting '$key' saved for $user", AICLI_LOG_INFO);
            if ($key === 'schedule') {
                try { \AICliAgents\Services\BackupCronService::sync(); } catch (\Throwable $e) { /* logged by sync */ }
            }
        }
        return $res;
    }

    /** AJAX: GET user. */
    private static function backupStatusAction(): array {
        $user = trim((string)($_REQUEST['user'] ?? ''));
        if ($user === '') $user = (string)(getAICliConfig()['user'] ?? 'root');
        if ($user === '0') $user = 'root';
        if (!preg_match('/^[A-Za-z0-9._-]+$/D', $user)) {
            return ['status' => 'error', 'message' => 'invalid_user'];
        }
        return self::backupStatusFor($user);
    }

    /**
     * HOME_BACKUP.md R10: `{status, last_backup:{at,path,bytes,files,warm,ok}|null,
     * running:{jobId,step}|null, snapshots:[{path,at,bytes}]}`. Reused by
     * `backup_status` AND (for just the `last_backup` object) by
     * StorageMetricsService::getStatus()'s per-user `last_backup` field, so
     * the two surfaces can never disagree.
     */
    public static function backupStatusFor(string $user): array {
        require_once __DIR__ . '/../services/SupervisorService.php';
        $target = self::homeBackupTarget($user);
        $ownTarget = $target;
        $running = self::runningBackupJob($user);
        // The job writes a durable per-user record of its last run (finished or
        // failed) with the target it really used. It is the source of truth when
        // the run used a target override or the setting changed since.
        $lastRun = null;
        $safeUser = preg_replace('/[^A-Za-z0-9._-]/', '', $user);
        $recordPath = \AICliAgents\Services\SecretPaths::flashRoot() . "/backup-last-$safeUser.json";
        if ($safeUser !== '' && is_file($recordPath)) {
            $decoded = json_decode((string)@file_get_contents($recordPath), true);
            if (is_array($decoded)) {
                $lastRun = [
                    'ok'      => (bool)($decoded['ok'] ?? false),
                    'at'      => $decoded['at'] ?? null,
                    'target'  => (string)($decoded['target'] ?? ''),
                    'warm'    => (bool)($decoded['warm'] ?? false),
                    'summary' => (string)($decoded['summary'] ?? ''),
                ];
                if ($target === '' && $lastRun['target'] !== '') {
                    $target = $lastRun['target'];
                }
            }
        }
        if ($target === '') {
            return ['status' => 'ok', 'last_backup' => null, 'last_run' => $lastRun, 'running' => $running, 'snapshots' => []];
        }

        $base = rtrim($target, '/') . '/aicli-home-backup/' . $user;
        $snapshots = [];
        foreach (@glob("$base/*", GLOB_ONLYDIR) ?: [] as $dir) {
            $name = basename($dir);
            if (!preg_match('/^\d{8}T\d{6}Z$/D', $name)) continue; // skips 'latest' + stray dirs
            $manifest = self::readBackupManifest("$dir/backup.json");
            $snapshots[] = [
                'path'  => $dir,
                'at'    => $manifest['finished_at'] ?? null,
                'bytes' => (int)($manifest['bytes'] ?? 0),
            ];
        }
        usort($snapshots, static fn($a, $b) => strcmp((string)($b['at'] ?? ''), (string)($a['at'] ?? '')));

        $lastBackup = null;
        $latestDir = self::resolveLatestSnapshotDir($base);
        if ($latestDir !== null) {
            $manifest = self::readBackupManifest("$latestDir/backup.json");
            if ($manifest !== null) {
                $lastBackup = [
                    'at'    => $manifest['finished_at'] ?? null,
                    'path'  => $latestDir,
                    'bytes' => (int)($manifest['bytes'] ?? 0),
                    'files' => (int)($manifest['file_count'] ?? 0),
                    'warm'  => (bool)($manifest['warm'] ?? false),
                    'ok'    => true,
                    // HOME_BACKUP.md 2026-09-29: files the running sessions
                    // changed during a warm backup (0 for a cold one).
                    'changed_during' => (int)(($manifest['verify'] ?? [])['changed_during_backup'] ?? 0),
                ];
            }
        }
        // HOME_BACKUP.md 2026-09-24 follow-up: a home with no folder of its
        // own whose earlier snapshots are still where its last run put them.
        // The UI OFFERS this folder; it is never saved without a click.
        $earlier = ($ownTarget === '' && ($lastBackup !== null || $snapshots !== [])) ? rtrim($target, '/') : '';
        return ['status' => 'ok', 'last_backup' => $lastBackup, 'last_run' => $lastRun, 'running' => $running, 'snapshots' => $snapshots,
                'earlier_target' => $earlier];
    }

    /** AJAX: POST target. Server-side re-check for the picker's "Validate" button. */
    private static function backupValidateTargetAction(): array {
        require_once __DIR__ . '/../services/StorageTargetService.php';
        $target = trim((string)($_REQUEST['target'] ?? ''));
        if ($target === '') {
            return ['status' => 'error', 'ok' => false, 'message' => 'A target path is required.'];
        }
        $resolved = \AICliAgents\Services\StorageTargetService::resolveBackupTarget($target, getAICliConfig());
        return array_merge(['status' => $resolved['ok'] ? 'ok' : 'error'], $resolved);
    }

    /** Resolve the `latest` symlink under a user's backup base dir to an absolute path, or null. */
    private static function resolveLatestSnapshotDir(string $base): ?string {
        $link = "$base/latest";
        if (!is_link($link) && !is_dir($link)) return null;
        if (is_link($link)) {
            $target = @readlink($link);
            if ($target === false) return null;
            return (strpos($target, '/') === 0) ? $target : "$base/$target";
        }
        return $link;
    }

    /**
     * REVIEW_2026-09-13_EVENTS_AND_SECURITY.md S3: resolve $snapshotArg (a raw
     * path, or 'latest') to a REAL, on-disk directory PROVEN to sit inside
     * $user's own backup root — the same root listBackups() reads,
     * `<effective target>/aicli-home-backup/<user>`. Before this check,
     * `restoreHome()` used a caller-supplied `snapshot` path (or a `latest`
     * symlink target) as-is: a path outside the backup root — or a `latest`
     * symlink deliberately pointed outside it with an absolute target
     * (resolveLatestSnapshotDir() follows an absolute symlink target
     * verbatim) — would still pass every check below it (a real backup.json
     * for the right user, a real tree/ dir) if one happened to exist there,
     * and would then be copied into the user's home as if it were a
     * legitimate snapshot.
     *
     * realpath() resolves both the root and the candidate to their real,
     * symlink-free, `..`-free on-disk path, so the prefix check below cannot
     * be fooled by a relative escape (`../../etc`) OR a symlink (anywhere in
     * either path) pointing outside the root — both collapse to the same
     * real path realpath() would report for a direct copy.
     *
     * @return array{ok:bool,path?:string,message?:string}
     */
    private static function resolveSnapshotWithinBackupRoot(string $target, string $user, string $snapshotArg): array {
        if ($target === '') {
            return ['ok' => false, 'message' => 'Home restore refused: no backup target is known to resolve this snapshot.'];
        }
        // A NUL byte makes realpath() throw; refuse it before any filesystem call.
        if (\AICliAgents\Services\ValidationService::containsNulByte($target)
            || \AICliAgents\Services\ValidationService::containsNulByte($snapshotArg)) {
            return ['ok' => false, 'message' => "Home restore refused: the snapshot path is not inside $user's own backup folder."];
        }
        $root = rtrim($target, '/') . '/aicli-home-backup/' . $user;
        $realRoot = realpath($root);
        if ($realRoot === false) {
            return ['ok' => false, 'message' => 'Home restore refused: no backup snapshots exist for this user under the configured target.'];
        }
        $realRoot = rtrim($realRoot, '/');

        if ($snapshotArg === 'latest') {
            $latest = self::resolveLatestSnapshotDir($root);
            if ($latest === null) {
                return ['ok' => false, 'message' => 'Home restore refused: there is no "latest" snapshot for this user.'];
            }
            $candidate = $latest;
        } else {
            $candidate = $snapshotArg;
        }

        $real = realpath($candidate);
        if ($real === false || strpos($real, $realRoot . '/') !== 0) {
            return ['ok' => false, 'message' => "Home restore refused: the snapshot path is not inside $user's own backup folder."];
        }
        return ['ok' => true, 'path' => $real];
    }

    /** @return array|null decoded backup.json, or null when absent/corrupt. */
    private static function readBackupManifest(string $path): ?array {
        if (!is_file($path)) return null;
        $data = json_decode((string)@file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }

    /** The active backup job for $user, or null. Shape: {jobId, step}. */
    private static function runningBackupJob(string $user): ?array {
        $job = \AICliAgents\Services\SupervisorService::findActiveJob('home', $user, 'backup');
        if ($job === null) return null;
        return ['jobId' => (string)($job['job_id'] ?? ''), 'step' => (string)($job['phase'] ?? 'running')];
    }

    /** `du -sb` in bytes for a mounted path; 0 on any failure (never blocks the pre-flight). */
    private static function duBytes(string $path): int {
        $out = [];
        $rc = 1;
        // nosemgrep: php.lang.security.exec-use.exec-use — path is a resolved plugin mount, no shell interpolation
        @exec('du -sb ' . escapeshellarg($path) . ' 2>/dev/null', $out, $rc);
        if ($rc !== 0 || empty($out)) return 0;
        $first = trim((string)$out[0]);
        return (preg_match('/^(\d+)/', $first, $m)) ? (int)$m[1] : 0;
    }

    /** Human-readable byte size for an error message (GB, one decimal). */
    private static function humanBytes(int $bytes): string {
        return sprintf('%.1f GB', $bytes / 1073741824);
    }

    /** HOME_BACKUP.md R2: free space must be >= 1.5x the merged home's size. Pure. */
    public static function requiredBackupSpace(int $homeBytes): int {
        return (int)ceil($homeBytes * 1.5);
    }

    /**
     * HOME_BACKUP.md R2 space pre-flight, pure and unit-testable. `free_bytes
     * <= 0` (unknown / a probe that could not statfs) never refuses on its
     * own — it is treated as "can't tell", the same permissive default
     * StorageTargetService::freeBytes()'s callers already use elsewhere.
     */
    public static function hasEnoughBackupSpace(int $freeBytes, int $homeBytes): bool {
        return $freeBytes <= 0 || $freeBytes >= self::requiredBackupSpace($homeBytes);
    }

    // -------------------------------------------------------------------
    // HOME_RESTORE.md — put a backup snapshot back into a user's home
    // -------------------------------------------------------------------

    /** AJAX: GET user. The `list_backups` action. */
    private static function listBackupsAction(): array {
        $user = trim((string)($_REQUEST['user'] ?? ''));
        if ($user === '') $user = (string)(getAICliConfig()['user'] ?? 'root');
        if ($user === '0') $user = 'root';
        if (!preg_match('/^[A-Za-z0-9._-]+$/D', $user)) {
            return ['status' => 'error', 'message' => 'invalid_user'];
        }
        return self::listBackups($user);
    }

    /**
     * HOME_RESTORE.md R2: `{status, target, snapshots:[{path, at, warm, files,
     * bytes, ok, label}]}`. `target` is the effective target: the cfg value,
     * or (when unset) the target the last backup run actually used. `label`
     * is `'pre-restore'` for a safety snapshot a restore took, else `''`.
     */
    public static function listBackups(string $user): array {
        if (!preg_match('/^[A-Za-z0-9._-]+$/D', $user)) {
            return ['status' => 'error', 'message' => 'invalid_user'];
        }
        $target = self::effectiveBackupTarget($user);
        if ($target === '') {
            return ['status' => 'ok', 'target' => '', 'own_target' => '', 'earlier_target' => '', 'snapshots' => []];
        }

        $base = rtrim($target, '/') . '/aicli-home-backup/' . $user;
        $snapshots = [];
        foreach (@glob("$base/*", GLOB_ONLYDIR) ?: [] as $dir) {
            $name = basename($dir);
            if (!preg_match('/^\d{8}T\d{6}Z$/D', $name)) continue; // skips 'latest' + stray dirs
            $manifest = self::readBackupManifest("$dir/backup.json");
            if ($manifest === null) continue;
            $snapshots[] = self::snapshotRowFromManifest($dir, $manifest);
        }
        usort($snapshots, static fn($a, $b) => strcmp((string)($b['at'] ?? ''), (string)($a['at'] ?? '')));
        // HOME_BACKUP.md 2026-09-24 follow-up: `earlier_target` is set when the
        // home has no folder of its own but snapshots were found where its last
        // run wrote them — the dialog offers "use this folder" (one click).
        $own = self::homeBackupTarget($user);
        $earlier = ($own === '' && $snapshots !== []) ? rtrim($target, '/') : '';
        return ['status' => 'ok', 'target' => $target, 'own_target' => $own, 'earlier_target' => $earlier, 'snapshots' => $snapshots];
    }

    /**
     * HOME_RESTORE.md R2: pure, unit-testable — a decoded backup.json ->
     * a `list_backups` snapshot row. `label` is 'pre-restore' only when the
     * manifest says so (a restore's own safety snapshot); every other
     * snapshot (a normal backup) gets ''.
     *
     * @param array<string,mixed> $manifest
     * @return array{path:string,at:mixed,warm:bool,files:int,bytes:int,ok:bool,label:string}
     */
    public static function snapshotRowFromManifest(string $path, array $manifest): array {
        $label = (string)($manifest['label'] ?? '');
        return [
            'path'  => $path,
            'at'    => $manifest['finished_at'] ?? null,
            'warm'  => (bool)($manifest['warm'] ?? false),
            'files' => (int)($manifest['file_count'] ?? 0),
            'bytes' => (int)($manifest['bytes'] ?? 0),
            'ok'    => true,
            'label' => $label === 'pre-restore' ? 'pre-restore' : '',
        ];
    }

    /** The cfg's backup_target, or (when unset) the target the user's last backup run used. */
    private static function effectiveBackupTarget(string $user): string {
        $target = self::homeBackupTarget($user);
        if ($target !== '') return $target;
        $safeUser = preg_replace('/[^A-Za-z0-9._-]/', '', $user);
        if ($safeUser === '') return '';
        $recordPath = \AICliAgents\Services\SecretPaths::flashRoot() . "/backup-last-$safeUser.json";
        if (!is_file($recordPath)) return '';
        $decoded = json_decode((string)@file_get_contents($recordPath), true);
        return is_array($decoded) ? trim((string)($decoded['target'] ?? '')) : '';
    }

    /** AJAX: POST user, snapshot (a path from list_backups). HOME_BACKUP.md 2026-09-24 follow-up. */
    private static function deleteBackupSnapshotAction(): array {
        $user = self::requestUser();
        if ($user === '') return ['status' => 'error', 'message' => 'invalid_user'];
        return self::deleteBackupSnapshot($user, (string)($_REQUEST['snapshot'] ?? ''));
    }

    /**
     * Delete ONE snapshot of a home's backups, from the same folder the
     * Snapshots list reads (effectiveBackupTarget()). Refused while a backup
     * or restore of that home is queued or running. Answers the fresh
     * snapshot list and last_backup, so the dialog and the card update at once.
     *
     * @param callable|null $busyProbe test seam: fn(string $user): bool
     */
    public static function deleteBackupSnapshot(string $user, string $snapshot, ?callable $busyProbe = null): array {
        if (!preg_match('/^[A-Za-z0-9._-]+$/D', $user)) {
            return ['status' => 'error', 'message' => 'invalid_user'];
        }
        require_once __DIR__ . '/../services/HomeBackupSnapshotService.php';
        require_once __DIR__ . '/../services/SupervisorService.php';
        $busy = $busyProbe !== null
            ? (bool)$busyProbe($user)
            : (self::runningBackupJob($user) !== null || self::runningRestoreJob($user) !== null);
        $target = self::effectiveBackupTarget($user);
        $res = \AICliAgents\Services\HomeBackupSnapshotService::delete($user, $target, $snapshot, $busy);
        if (($res['status'] ?? '') !== 'ok') {
            aicli_log("Home backup snapshot delete refused for $user: " . ($res['message'] ?? ''), AICLI_LOG_WARN);
            return $res;
        }
        aicli_log("Home backup snapshot deleted for $user: {$res['deleted']}" . (isset($res['warning']) ? ' (' . $res['warning'] . ')' : ''), AICLI_LOG_INFO);
        try {
            \AICliAgents\Services\LifecycleLogService::log(
                \AICliAgents\Services\LifecycleLogService::LEVEL_INFO,
                'storage', 'storage_backup_snapshot_deleted',
                ['user' => $user, 'snapshot' => $res['deleted'], 'latest' => $res['latest'] ?? '']
            );
        } catch (\Throwable $e) { /* the debug.log line above is the audit record of last resort */ }
        $list = self::listBackups($user);
        $status = self::backupStatusFor($user);
        return array_merge($res, [
            'snapshots'      => $list['snapshots'] ?? [],
            'earlier_target' => $list['earlier_target'] ?? '',
            'last_backup'    => $status['last_backup'] ?? null,
        ]);
    }

    /** AJAX: POST user, snapshot (path or 'latest'), mode, safety_snapshot. */
    private static function restoreHomeAction(): array {
        $user = trim((string)($_REQUEST['user'] ?? ''));
        if ($user === '') $user = (string)(getAICliConfig()['user'] ?? 'root');
        if ($user === '0') $user = 'root';
        $opts = ['snapshot' => (string)($_REQUEST['snapshot'] ?? '')];
        if (isset($_REQUEST['mode']))            $opts['mode']            = (string)$_REQUEST['mode'];
        if (isset($_REQUEST['safety_snapshot'])) $opts['safety_snapshot'] = (string)$_REQUEST['safety_snapshot'];
        aicli_log("AJAX Request: Restore home for $user", AICLI_LOG_INFO);
        return self::restoreHome($user, $opts);
    }

    /**
     * Public static entry point for the admin tool's approve path
     * (`aicli_restore_home`, Tier 3) and the CLI — same result shape as the
     * AJAX action.
     *
     * @param array{snapshot?:string,mode?:string,safety_snapshot?:mixed} $opts
     * @return array{status:string,jobId?:string,opId?:string,safety_snapshot?:bool,message?:string,reason?:string}
     */
    public static function restoreHome(string $user, array $opts = []): array {
        if (!preg_match('/^[A-Za-z0-9._-]+$/D', $user)) {
            return ['status' => 'error', 'message' => 'invalid_user'];
        }
        require_once __DIR__ . '/../services/StorageTargetService.php';
        require_once __DIR__ . '/../services/StoragePathResolver.php';
        require_once __DIR__ . '/../services/SupervisorService.php';
        require_once __DIR__ . '/../services/ActivityService.php';
        require_once __DIR__ . '/../services/HomeBackupSettingsService.php';

        $snapshotArg = trim((string)($opts['snapshot'] ?? ''));
        if ($snapshotArg === '') {
            return ['status' => 'error', 'message' => 'A snapshot is required.'];
        }
        $mode = (string)($opts['mode'] ?? 'replace');
        if (!in_array($mode, ['replace', 'merge'], true)) $mode = 'replace';
        $safetySnapshot = self::optBool($opts['safety_snapshot'] ?? null, true);

        $config = getAICliConfig();
        $target = self::effectiveBackupTarget($user);

        // S3: resolve 'latest' (or take the given path) and PROVE it is a real,
        // on-disk directory inside this user's own backup root — never a path
        // (or a 'latest' symlink) pointing anywhere else. This is the FIRST
        // check on $snapshotArg, before backup.json is even read, so nothing
        // outside the backup root is ever opened, let alone copied into a home.
        $resolvedSnapshot = self::resolveSnapshotWithinBackupRoot($target, $user, $snapshotArg);
        if (!$resolvedSnapshot['ok']) {
            return ['status' => 'error', 'message' => (string)$resolvedSnapshot['message']];
        }
        $snapshotDir = (string)$resolvedSnapshot['path'];

        // R3 pre-flight — any failure ends the job before a session is touched.
        $manifest = is_dir($snapshotDir) ? self::readBackupManifest("$snapshotDir/backup.json") : null;
        if ($manifest === null) {
            return ['status' => 'error', 'message' => 'Home restore refused: the snapshot has no backup.json for this user.'];
        }
        if ((string)($manifest['user'] ?? '') !== $user) {
            return ['status' => 'error', 'message' => 'Home restore refused: that snapshot belongs to a different user.'];
        }
        if (!is_dir("$snapshotDir/tree")) {
            return ['status' => 'error', 'message' => 'Home restore refused: the snapshot has no tree/ directory.'];
        }
        $homeMount = \AICliAgents\Services\StoragePathResolver::homeMountPath($user);
        if (!is_dir($homeMount)) {
            return ['status' => 'error', 'message' => "Home restore refused: $user's home is not mounted."];
        }
        $entity = "home/$user";
        if (\AICliAgents\Services\SupervisorService::isHalted($entity)) {
            return ['status' => 'error', 'message' => "Home restore refused: $user's home is halted."];
        }

        $snapshotBytes = (int)($manifest['bytes'] ?? 0);
        $needed = self::requiredRestoreSpace($snapshotBytes, $mode);
        $free = (int)@disk_free_space($homeMount);
        if (!self::hasEnoughRestoreSpace($free, $snapshotBytes, $mode)) {
            $margin = $mode === 'merge' ? ' (1.2x the snapshot, for a merge)' : '';
            return ['status' => 'error', 'message' => sprintf(
                'Home restore refused: the home filesystem has %s free but needs at least %s%s.',
                self::humanBytes($free), self::humanBytes($needed), $margin
            )];
        }

        $safetyTarget = '';
        if ($safetySnapshot) {
            if ($target === '') {
                return ['status' => 'error', 'message' => 'Home restore refused: a safety snapshot was requested but no backup target is configured.'];
            }
            $resolvedTarget = \AICliAgents\Services\StorageTargetService::resolveBackupTarget($target, $config);
            if (!$resolvedTarget['ok']) {
                return ['status' => 'error', 'message' => 'Home restore refused: the safety-snapshot target failed pre-flight: ' . $resolvedTarget['message']];
            }
            $safetyTarget = $resolvedTarget['resolved'];
            $homeBytes = self::duBytes($homeMount);
            if (!self::hasEnoughBackupSpace($resolvedTarget['free_bytes'], $homeBytes)) {
                return ['status' => 'error', 'message' => sprintf(
                    'Home restore refused: the safety-snapshot target has %s free but needs at least %s.',
                    self::humanBytes($resolvedTarget['free_bytes']), self::humanBytes(self::requiredBackupSpace($homeBytes))
                )];
            }
        }

        // HOME_BACKUP.md #287: the safety snapshot follows the home's OWN
        // retention and excludes, like a normal backup of that home.
        $hs = \AICliAgents\Services\HomeBackupSettingsService::get($user);
        $keep = (int)$hs['keep'];
        if ($keep < 1) $keep = 1;
        $excludes = \AICliAgents\Services\HomeBackupSettingsService::excludesList($hs);

        if (($dup = self::duplicateJobRefusal('home', $user, 'restore', "A restore of $user's home")) !== null) return $dup;

        // Mint the job id ourselves so the options sidecar exists BEFORE the
        // queue file can possibly be popped (mirrors backupHome()).
        $jobId = \AICliAgents\Services\SupervisorService::generateJobId('restore', 'home', $user);
        $wrote = \AICliAgents\Services\SupervisorService::writeRestoreOptions($jobId, [
            'snapshot'        => $snapshotDir,
            'mode'            => $mode,
            'safety_snapshot' => $safetySnapshot,
            'target'          => $safetyTarget,
            'keep'            => $keep,
            'excludes'        => $excludes,
        ]);
        if (!$wrote) {
            return ['status' => 'error', 'message' => 'Could not prepare the restore job. Try again.'];
        }
        if (!\AICliAgents\Services\SupervisorService::enqueue('home', $user, 'restore', 'user_restore_home', 5, $jobId)) {
            \AICliAgents\Services\SupervisorService::deleteRestoreOptions($jobId);
            return ['status' => 'error', 'message' => 'Could not queue the restore job. Try again.'];
        }

        $opId = "user_restore_home_{$user}_{$jobId}";
        \AICliAgents\Services\ActivityService::register($opId, 'storage', "Restoring home $user", [
            'step' => 'Queued', 'progress' => 5, 'meta' => ['jobId' => $jobId, 'cancel' => 'none'],
        ]);
        \AICliAgents\Services\SupervisorService::wake();
        \AICliAgents\Services\LifecycleLogService::log(
            \AICliAgents\Services\LifecycleLogService::LEVEL_INFO,
            'storage', 'storage_restore_queued',
            ['user' => $user, 'snapshot' => $snapshotDir, 'mode' => $mode, 'safety_snapshot' => $safetySnapshot, 'job_id' => $jobId]
        );
        return ['status' => 'ok', 'jobId' => $jobId, 'opId' => $opId, 'safety_snapshot' => $safetySnapshot];
    }

    /** AJAX: GET user. */
    private static function restoreStatusAction(): array {
        $user = trim((string)($_REQUEST['user'] ?? ''));
        if ($user === '') $user = (string)(getAICliConfig()['user'] ?? 'root');
        if ($user === '0') $user = 'root';
        if (!preg_match('/^[A-Za-z0-9._-]+$/D', $user)) {
            return ['status' => 'error', 'message' => 'invalid_user'];
        }
        return self::restoreStatusFor($user);
    }

    /**
     * HOME_RESTORE.md R2/R4: `{status, last_restore:{ok,at,snapshot,mode,
     * safety_snapshot_path,cause}|null, running:{jobId,step}|null}`. Reads the
     * durable per-user record the restore job writes at
     * /boot/config/plugins/unraid-aicliagents/restore-last-<user>.json.
     */
    public static function restoreStatusFor(string $user): array {
        require_once __DIR__ . '/../services/SupervisorService.php';
        $safeUser = preg_replace('/[^A-Za-z0-9._-]/', '', $user);
        $lastRestore = null;
        $recordPath = \AICliAgents\Services\SecretPaths::flashRoot() . "/restore-last-$safeUser.json";
        if ($safeUser !== '' && is_file($recordPath)) {
            $decoded = json_decode((string)@file_get_contents($recordPath), true);
            if (is_array($decoded)) {
                $lastRestore = [
                    'ok'                   => (bool)($decoded['ok'] ?? false),
                    'at'                   => $decoded['at'] ?? null,
                    'snapshot'             => (string)($decoded['snapshot'] ?? ''),
                    'mode'                 => (string)($decoded['mode'] ?? ''),
                    'safety_snapshot_path' => (string)($decoded['safety_snapshot_path'] ?? ''),
                    'cause'                => (string)($decoded['cause'] ?? ''),
                ];
            }
        }
        $running = self::runningRestoreJob($user);
        return ['status' => 'ok', 'last_restore' => $lastRestore, 'running' => $running];
    }

    /** The active restore job for $user, or null. Shape: {jobId, step}. */
    private static function runningRestoreJob(string $user): ?array {
        $job = \AICliAgents\Services\SupervisorService::findActiveJob('home', $user, 'restore');
        if ($job === null) return null;
        return ['jobId' => (string)($job['job_id'] ?? ''), 'step' => (string)($job['phase'] ?? 'running')];
    }

    /** cfg-style truthy parse: bool passthrough, else '0'/'false'/'no'/'off' -> false, '' -> $default. */
    private static function optBool($val, bool $default): bool {
        if ($val === null) return $default;
        if (is_bool($val)) return $val;
        $s = strtolower(trim((string)$val));
        if ($s === '') return $default;
        return !in_array($s, ['0', 'false', 'no', 'off'], true);
    }

    /** HOME_RESTORE.md R3: replace needs >= the snapshot's bytes; merge needs >= 1.2x. Pure. */
    public static function requiredRestoreSpace(int $snapshotBytes, string $mode): int {
        return $mode === 'merge' ? (int)ceil($snapshotBytes * 1.2) : $snapshotBytes;
    }

    /** Same "free_bytes <= 0 never refuses" permissive default as hasEnoughBackupSpace(). Pure. */
    public static function hasEnoughRestoreSpace(int $freeBytes, int $snapshotBytes, string $mode): bool {
        return $freeBytes <= 0 || $freeBytes >= self::requiredRestoreSpace($snapshotBytes, $mode);
    }

    /**
     * Public entry point shared by the `consolidate_storage` AJAX action (no
     * arguments: reads type/id from the request) and the admin tool's approve
     * path (`aicli_consolidate_home`, Tier 3, HOME_PERSIST_CONSOLIDATE_TOOLS.md,
     * 2026-09-17, which passes them explicitly) — the ONE code path that queues
     * a consolidate, so approval never re-implements the
     * writeHomeManifest → markHomeConsolidating → enqueue → wake order below.
     *
     * @param array<string,callable> $seams test seams; production passes none. Keys:
     *        find_active_job (fn(type, id, op): ?array), sessions (fn(id): array),
     *        enqueue (fn(type, id, op, reason, prio, epoch): ?string, replaces the job-ledger enqueue),
     *        wake (fn(): void), announce (fn(type, id, jobId): void, replaces the tray row,
     *        the lifecycle log line and the reclaim notice sync).
     */
    public static function consolidate(string $type = '', string $id = '', array $relaunchSet = [], ?bool $continueAfter = null, array $seams = []): array {
        // CONSOLIDATE_CONTINUE_OPTION.md: the Storage tab sends continue_after=0 when the person
        // unticks "Send Continue ...". A caller that passes nothing keeps today's behaviour (Continue).
        if ($continueAfter === null) $continueAfter = (string)($_GET['continue_after'] ?? '1') !== '0';
        if ($type === '') $type = (string)($_GET['type'] ?? 'agent');
        if ($id === '')   $id   = (string)($_GET['id']   ?? 'default');
        if ($type === 'home' && ($id === '0')) {
            $id = 'root';
        }
        // Security: reject non-allowlisted type and any id that contains
        // characters outside the safe username/agent-id set (HOME_CONSOLIDATE_CLOSE_RELAUNCH fix).
        if (!in_array($type, ['agent', 'home'], true)) {
            return ['status' => 'error', 'message' => 'invalid_type'];
        }
        if (!preg_match('/^[A-Za-z0-9._-]+$/D', $id)) {
            return ['status' => 'error', 'message' => 'invalid_id'];
        }
        aicli_log("AJAX Request: Consolidate storage for $type: $id", AICLI_LOG_INFO);
        // R-174: before the relaunch manifest and the consolidating marker, so a repeat click changes nothing.
        if (($dup = self::duplicateJobRefusal($type, $id, 'consolidate', "A consolidation of $type $id", $seams['find_active_job'] ?? null)) !== null) return $dup;

        // R2.1: home consolidate — write relaunch manifest then mark as in-progress
        // and enqueue immediately. The slow close (forceCloseHome) is deferred to the
        // supervisor's pre-bake close phase (R2.2) so this AJAX handler returns in <1 s.
        // Order: writeHomeManifest → markHomeConsolidating → enqueue → wake.
        // H1 fix: epoch travels in the job ledger entry (via enqueueJob), not a sidecar.
        $epoch = null;
        if ($type === 'home') {
            require_once __DIR__ . '/../services/UpgradeRelaunchService.php';
            // Build the relaunch set from the UNION of the sessions live NOW and any
            // set captured earlier by the caller (CONSOLIDATE_RELAUNCH_CLOSED_SET.md,
            // 2026-09-17): the admin-tool proposal captures the open sessions at
            // propose time, so a session the user closed between proposal and
            // approval — the plugin's own reclaim banner asks them to — is still
            // relaunched. Dedup by sessionId; the live entry wins. `working:true`
            // so the post-consolidate relaunch sends one Continue (the same
            // auto-continue-on-restart behaviour an upgrade/reload already gives),
            // gated on the auto_continue_on_restart setting in the supervisor bridge.
            $sessions = isset($seams['sessions'])
                ? $seams['sessions']($id)
                : \AICliAgents\Services\TerminalService::listActiveSessionsForHome($id);
            $manifest = [];
            $seen = [];
            foreach ($sessions as $s) {
                $sid = (string)($s['id'] ?? '');
                if ($sid === '' || isset($seen[$sid])) continue;
                $seen[$sid] = true;
                $wp      = (string)($s['path'] ?? '');
                $agentId = (string)($s['agentId'] ?? '');
                $manifest[] = [
                    'sessionId'     => $sid,
                    'workspacePath' => $wp,
                    'agentId'       => $agentId,
                    'hadResume'     => $wp !== '' && $agentId !== '',
                    'working'       => $continueAfter,
                ];
            }
            foreach ($relaunchSet as $s) {
                if (!is_array($s)) continue;
                $sid = (string)($s['sessionId'] ?? $s['id'] ?? '');
                if ($sid === '' || isset($seen[$sid])) continue;
                $seen[$sid] = true;
                $wp      = (string)($s['workspacePath'] ?? $s['path'] ?? '');
                $agentId = (string)($s['agentId'] ?? '');
                $manifest[] = [
                    'sessionId'     => $sid,
                    'workspacePath' => $wp,
                    'agentId'       => $agentId,
                    'hadResume'     => $wp !== '' && $agentId !== '',
                    'working'       => $continueAfter,
                ];
            }
            if (!empty($manifest)) {
                // Write the relaunch manifest BEFORE setting the marker, so the supervisor
                // has a manifest to relaunch from even if the close phase takes a moment.
                // Order: writeHomeManifest → markHomeConsolidating → enqueue → wake.
                if (\AICliAgents\Services\UpgradeRelaunchService::writeHomeManifest($id, $manifest) === false) {
                    aicli_log("Consolidate(home): failed to write relaunch manifest for $id — aborting", AICLI_LOG_WARN);
                    return ['status' => 'error', 'message' => 'Could not write relaunch manifest. Try again.'];
                }
            }

            // R2.1: mark the consolidate as in-progress (blocks racing reconnect start),
            // then enqueue immediately — the slow close happens in the supervisor (R2.2).
            // markHomeConsolidating returns the epoch token for epoch-safe clearing later.
            require_once __DIR__ . '/../services/ConsolidateState.php';
            $epoch = \AICliAgents\Services\ConsolidateState::markHomeConsolidating($id);
        }

        // Enqueue a user-priority consolidate so the AJAX handler returns immediately.
        // S-08 (#1353): tracked — job_id in the response.
        // H1 fix: pass $epoch to enqueueJob so it is stored in the ledger entry (keyed
        // by job_id) rather than a per-entity sidecar (last-writer-wins race removed).
        if (isset($seams['enqueue'])) {
            $jobId = $seams['enqueue']($type, $id, 'consolidate', 'user_consolidate', 5, $epoch);
        } else {
            $jobId = \AICliAgents\Services\SupervisorService::enqueueJob($type, $id, 'consolidate', 'user_consolidate', 5, $epoch);
            if ($jobId === null) {
                \AICliAgents\Services\SupervisorService::enqueue($type, $id, 'consolidate', 'user_consolidate', 5, null, false, $epoch);
            }
        }

        if (!isset($seams['announce'])) {
            self::trackJobActivity($jobId, $type === 'agent'
                ? "Merging layers of agent " . self::trayAgentName((string)$id)
                : "Merging layers of home $id");
        }

        // R2.1: wake the supervisor so the job starts immediately.
        if (isset($seams['wake'])) {
            $seams['wake']();
        } else {
            \AICliAgents\Services\SupervisorService::wake();
        }

        if (isset($seams['announce'])) {
            $seams['announce']($type, $id, $jobId);
        } else {
            \AICliAgents\Services\LifecycleLogService::log(
                \AICliAgents\Services\LifecycleLogService::LEVEL_INFO,
                'storage', 'storage_consolidate_queued',
                ['type' => $type, 'id' => $id, 'job_id' => $jobId]
            );

            // STORAGE_RECLAIM_NOTICE.md: the ask in the tray hands over to this job's own entry now,
            // not at the next supervisor publish.
            if ($type === 'home') {
                \AICliAgents\Services\StorageReclaimNoticeService::sync(self::forceReclaimState(), $id);
            }
        }

        $msg = $type === 'home'
            ? "Consolidation queued. Sessions will be closed and automatically resumed on completion. Watch the activity tray for progress."
            : "Consolidation queued. It runs on the next storage cycle. Watch the activity tray for progress.";
        return ['status' => 'queued', 'job_id' => (string)$jobId, 'message' => $msg];
    }

    /**
     * AJAX `agent_old_versions` (BOOT_DISK_SPACE.md R4): GET agent=<id>|all (default all).
     * Read-only. Lists what Remove old versions removes, how much space it frees and what
     * stays because a workspace uses it. #552: it answers from the last background
     * measurement (.boot_space.json) and measures nothing. The answer carries `measured_at`,
     * `age`, `stale`, `has_data` and `measuring`.
     */
    private static function agentOldVersions(): array {
        $agent = (string)($_REQUEST['agent'] ?? 'all');
        return \AICliAgents\Services\BootSpaceService::cachedOldVersions($agent);
    }

    /**
     * AJAX `remove_old_versions` (BOOT_DISK_SPACE.md R4): POST agent=<id>|all. #552: it queues
     * a supervisor job (op `removeold`) with Activity tray steps and returns at once. The
     * request lists nothing and removes nothing.
     */
    private static function removeOldVersionsAction(): array {
        $agent = trim((string)($_REQUEST['agent'] ?? 'all'));
        if ($agent === '') $agent = 'all';
        aicli_log("AJAX Request: Remove old versions of $agent", AICLI_LOG_INFO);
        if (!\AICliAgents\Services\AgentVersionCleanupService::isValidTarget($agent)) {
            return ['status' => 'error', 'message' => 'invalid_agent'];
        }
        require_once __DIR__ . '/../services/OldVersionsJobService.php';
        if (($dup = self::duplicateJobRefusal('agent', $agent, \AICliAgents\Services\OldVersionsJobService::OP, 'The removal of old versions')) !== null) return $dup;
        $jobId =\AICliAgents\Services\SupervisorService::generateJobId(\AICliAgents\Services\OldVersionsJobService::OP, 'agent', $agent);
        if (!\AICliAgents\Services\OldVersionsJobService::writeOptions($jobId, ['agent' => $agent])) {
            return ['status' => 'error', 'message' => 'Could not prepare the job. Try again.'];
        }
        if (!\AICliAgents\Services\SupervisorService::enqueue('agent', $agent, \AICliAgents\Services\OldVersionsJobService::OP, 'user_old_versions', 5, $jobId)) {
            \AICliAgents\Services\OldVersionsJobService::deleteOptions($jobId);
            return ['status' => 'error', 'message' => 'Could not queue the job. Try again.'];
        }
        self::trackJobActivity($jobId, $agent === 'all'
            ? 'Removing old versions of all agents'
            : 'Removing old versions of ' . self::trayAgentName($agent));
        \AICliAgents\Services\SupervisorService::wake();
        return ['status' => 'queued', 'job_id' => $jobId, 'message' => 'Queued. Watch the activity tray for progress.'];
    }

    /**
     * AJAX `clear_home_caches` (BOOT_DISK_SPACE.md R5): GET/POST user=<home id>. Empties the
     * cache folders of a plain-folder home. Refused while a workspace of that home runs.
     */
    private static function clearHomeCaches(): array {
        $user = trim((string)($_REQUEST['user'] ?? ''));
        if ($user === '') $user = 'root';
        aicli_log("AJAX Request: Clear caches of home $user", AICLI_LOG_INFO);
        $r = \AICliAgents\Services\HomeCacheService::clear($user);
        if (($r['status'] ?? '') === 'ok') {
            // #552: no du in the request. The freed size is the size of the last measurement;
            // a fresh background measurement follows.
            $c = \AICliAgents\Services\BootSpaceService::cachedCache($user) ?? [];
            $r['freed_bytes'] = (int)($c['bytes'] ?? 0) + (int)($c['on_boot_bytes'] ?? 0);
            \AICliAgents\Services\BootSpaceService::startMeasure();
            \AICliAgents\Services\LifecycleLogService::log(
                \AICliAgents\Services\LifecycleLogService::LEVEL_INFO,
                'storage', 'home_caches_cleared', ['id' => $user, 'freed_bytes' => $r['freed_bytes']]);
        }
        return $r;
    }

    /**
     * AJAX `get_home_cache_status` (read-only): GET user=<home id>. #552: it answers from the
     * last background measurement and measures nothing. The answer has the shape of
     * HomeCacheService::status() plus `measured_at`, `age`, `stale`, `has_data`, `measuring`.
     */
    private static function homeCacheStatus(): array {
        $user = trim((string)($_REQUEST['user'] ?? ''));
        if ($user === '') $user = 'root';
        if (preg_match('/^[A-Za-z0-9._-]+$/D', $user) !== 1) {
            return ['status' => 'error', 'message' => 'invalid_user'];
        }
        $data = \AICliAgents\Services\BootSpaceService::read();
        $c = \AICliAgents\Services\BootSpaceService::cachedCache($user, $data)
            ?? ['location' => 'none', 'path' => '', 'bytes' => 0, 'cap_bytes' => 0, 'on_boot_bytes' => 0];
        return ['status' => 'ok'] + $c + \AICliAgents\Services\BootSpaceService::meta($data);
    }

    /** AJAX `convert_storage` (#496): GET/POST type=home, id=<user>, optional continue_after=0. */
    private static function convertAction(): array {
        $type = (string)($_REQUEST['type'] ?? 'home');
        if ($type !== 'home') {
            return ['status' => 'error', 'message' => 'Only a home can be converted to plain folders.'];
        }
        $continueAfter = (string)($_REQUEST['continue_after'] ?? '1') !== '0';
        return self::convertHome((string)($_REQUEST['id'] ?? ''), null, $continueAfter);
    }

    /**
     * #496 (docs/specs/CONVERT_LAYERED_TO_PLAIN_FOLDERS.md): queue the conversion
     * of a layered home on a non-USB disk to plain folders. The supervisor runs
     * it as op `graduate`, reason `user_convert`: it closes the home's sessions
     * as for a user consolidate, copies the merged view, switches, and relaunches
     * from the SAME relaunch manifest the consolidate flow uses. Same order as
     * consolidate(): writeHomeManifest → markHomeConsolidating → enqueue → wake.
     *
     * $gate (test seam) decides whether the home may be converted; the default
     * is FileStorage::canConvertHome. A refusal has no side effect at all.
     */
    public static function convertHome(string $id, ?callable $gate = null, bool $continueAfter = true): array {
        // #677: refuse a control character before trim(), which would turn "root\n" into "root".
        if (preg_match('/[\x00-\x1F\x7F]/', $id)) {
            return ['status' => 'error', 'message' => 'invalid_id'];
        }
        $id = trim($id);
        if ($id === '' || $id === '0') $id = 'root';
        if (!preg_match('/^[A-Za-z0-9._-]+$/D', $id)) {
            return ['status' => 'error', 'message' => 'invalid_id'];
        }
        if ($gate === null) {
            require_once __DIR__ . '/../services/FileStorage.php';
            $gate = static function (string $u): bool { return \AICliAgents\Services\FileStorage::canConvertHome($u); };
        }
        if (!$gate($id)) {
            return ['status' => 'error',
                    'message' => "The home of $id cannot be converted. Only a home with layers on a disk that is not a USB flash drive can change to plain folders."];
        }
        aicli_log("AJAX Request: Convert home $id to plain folders", AICLI_LOG_INFO);
        // R-174: before the relaunch manifest and the consolidating marker.
        if (($dup = self::duplicateJobRefusal('home', $id, 'graduate', "The conversion of the home of $id")) !== null) return $dup;

        require_once __DIR__ . '/../services/UpgradeRelaunchService.php';
        $manifest = [];
        $seen = [];
        foreach (\AICliAgents\Services\TerminalService::listActiveSessionsForHome($id) as $s) {
            $sid = (string)($s['id'] ?? '');
            if ($sid === '' || isset($seen[$sid])) continue;
            $seen[$sid] = true;
            $wp      = (string)($s['path'] ?? '');
            $agentId = (string)($s['agentId'] ?? '');
            $manifest[] = [
                'sessionId'     => $sid,
                'workspacePath' => $wp,
                'agentId'       => $agentId,
                'hadResume'     => $wp !== '' && $agentId !== '',
                'working'       => $continueAfter,
            ];
        }
        if (!empty($manifest)
            && \AICliAgents\Services\UpgradeRelaunchService::writeHomeManifest($id, $manifest) === false) {
            aicli_log("Convert(home): failed to write relaunch manifest for $id — aborting", AICLI_LOG_WARN);
            return ['status' => 'error', 'message' => 'Could not write relaunch manifest. Try again.'];
        }
        require_once __DIR__ . '/../services/ConsolidateState.php';
        $epoch = \AICliAgents\Services\ConsolidateState::markHomeConsolidating($id);

        $jobId = \AICliAgents\Services\SupervisorService::enqueueJob('home', $id, 'graduate', 'user_convert', 5, $epoch);
        if ($jobId === null) {
            \AICliAgents\Services\SupervisorService::enqueue('home', $id, 'graduate', 'user_convert', 5, null, false, $epoch);
        }
        self::trackJobActivity($jobId, "Converting home $id to plain folders");
        \AICliAgents\Services\SupervisorService::wake();

        \AICliAgents\Services\LifecycleLogService::log(
            \AICliAgents\Services\LifecycleLogService::LEVEL_INFO,
            'storage', 'storage_convert_queued',
            ['type' => 'home', 'id' => $id, 'job_id' => $jobId]
        );
        // The tidy-up ask in the tray hands over to this job's own entry now.
        \AICliAgents\Services\StorageReclaimNoticeService::sync(self::forceReclaimState(), $id);

        return ['status' => 'queued', 'job_id' => (string)$jobId,
                'message' => 'Conversion queued. Workspaces of this home close and open again when it is done. Watch the activity tray for progress.'];
    }

    /**
     * BOOT_DISK_SPACE.md R3, AJAX `agent_convert_estimate` (read-only): the numbers the
     * "Convert agents to plain folders" dialog shows. #552: from the last background
     * measurement; this request measures nothing (no du, no tree walk).
     * Response: { status:'ok', estimate:{offered, reason, disk_bytes, free_bytes, ratio,
     * agents:[{id,name,now_bytes,plain_bytes,extra_bytes,fits,estimated}], extra_bytes, fits,
     * retained_bytes, estimated} | null, has_data, measured_at, age, stale, measuring }.
     * The free space is read again now. `estimate` is null before the first measurement.
     */
    private static function agentConvertEstimate(): array {
        try {
            return \AICliAgents\Services\BootSpaceService::cachedConvert();
        } catch (\Throwable $e) {
            aicli_log("agent_convert_estimate failed: " . $e->getMessage(), AICLI_LOG_WARN);
            return ['status' => 'error', 'message' => 'The sizes could not be read. Try again.'];
        }
    }

    /** AJAX `convert_agents` (BOOT_DISK_SPACE.md R3): POST agents=<JSON list of ids> or "all". */
    private static function convertAgentsAction(): array {
        $raw = (string)($_REQUEST['agents'] ?? '');
        $requested = $raw === 'all' ? 'all' : json_decode($raw, true);
        if ($requested !== 'all' && !is_array($requested)) {
            return ['status' => 'error', 'message' => 'Choose which agents to convert.'];
        }
        return self::convertAgents($requested);
    }

    /**
     * BOOT_DISK_SPACE.md R3: queue the conversion of layered agents to plain folders,
     * one supervisor job for each agent (op `graduate`, reason `user_convert`). The
     * supervisor closes only the workspaces of that agent, copies the merged view into
     * the versioned layout, switches, and opens the workspaces again. The jobs run one
     * after another in the queue.
     *
     * Refused with no side effect when the dialog is not offered (USB boot, "Keep
     * agents compressed" on, no layered agent), when the free space after the
     * conversion would be below 10% of the disk or 2 GiB (the shortfall is named),
     * when an agent is not layered, or when an install of the agent is running.
     *
     * $estimate and $installBusy are test seams.
     *
     * @param string[]|'all' $requested
     */
    public static function convertAgents($requested, ?array $estimate = null, ?callable $installBusy = null): array {
        require_once __DIR__ . '/../services/AgentStorageService.php';
        // #552: the numbers come from the last background measurement; the request measures nothing.
        // The free space is read again, so the room check uses the live number.
        if ($estimate === null) {
            $estimate = \AICliAgents\Services\BootSpaceService::cachedConvert()['estimate'] ?? null;
            if ($estimate === null) {
                return ['status' => 'error', 'reason' => 'not_measured',
                        'message' => 'The sizes are not measured yet. Open Boot disk space, choose Measure now, then try again.'];
            }
        }
        if (empty($estimate['offered'])) {
            $why = [
                'usb_boot' => 'Agents on a USB boot stick keep compressed layers.',
                'keep_agents_compressed' => 'Agent storage is set to SquashFS layers. Set it to Plain folders on the Configuration tab to convert the agents.',
                'no_layered_agents' => 'No agent uses compressed layers.',
                'not_boot_disk' => 'The agents are not on the boot disk.',
            ][(string)($estimate['reason'] ?? '')] ?? 'Agents cannot be converted now.';
            return ['status' => 'error', 'message' => $why, 'reason' => (string)($estimate['reason'] ?? '')];
        }
        $sel = \AICliAgents\Services\AgentStorageService::selectConvertible($requested, $estimate);
        if ($sel['agents'] === []) {
            return ['status' => 'error', 'message' => 'None of the chosen agents can be converted.', 'refused' => $sel['refused']];
        }
        // The room check names the shortfall. It covers the chosen agents only.
        $chosenExtra = 0;
        foreach ($estimate['agents'] as $a) {
            if (in_array($a['id'], $sel['agents'], true)) $chosenExtra += (int)$a['plain_bytes'] - (int)$a['now_bytes'];
        }
        $fits = \AICliAgents\Services\AgentStorageService::convertFits(
            (int)$estimate['free_bytes'], (int)$estimate['disk_bytes'], max(0, $chosenExtra));
        if ($fits !== 'ok') {
            $short = (int)substr($fits, strlen('no_room:'));
            return ['status' => 'error', 'reason' => 'no_room', 'shortfall_bytes' => $short,
                    'message' => 'There is not enough room. After the conversion at least 10% of the boot disk and 2 GB must stay free. About '
                        . number_format($short / 1048576, 0) . ' MB are missing. Remove old versions or left-over files first (Home Storage, Boot disk space).'];
        }
        require_once __DIR__ . '/AgentHandler.php';
        $installBusy = $installBusy ?? [\AICliAgents\Handlers\AgentHandler::class, 'isInstallInProgress'];
        $queued = []; $skipped = $sel['refused'];
        foreach ($sel['agents'] as $id) {
            if ($installBusy($id)) { $skipped[$id] = 'install_running'; continue; }
            if (self::duplicateJobRefusal('agent', $id, 'graduate', 'The conversion') !== null) { $skipped[$id] = 'already_queued'; continue; }
            $jobId =\AICliAgents\Services\SupervisorService::enqueueJob('agent', $id, 'graduate', 'user_convert', 5);
            if ($jobId === null) {
                \AICliAgents\Services\SupervisorService::enqueue('agent', $id, 'graduate', 'user_convert', 5);
            }
            self::trackJobActivity($jobId, "Converting agent " . self::trayAgentName($id) . " to plain folders");
            \AICliAgents\Services\LifecycleLogService::log(
                \AICliAgents\Services\LifecycleLogService::LEVEL_INFO,
                'storage', 'storage_convert_agent_queued', ['type' => 'agent', 'id' => $id, 'job_id' => $jobId]
            );
            $queued[] = ['id' => $id, 'job_id' => (string)$jobId];
        }
        if ($queued === []) {
            return ['status' => 'error', 'message' => 'None of the chosen agents can be converted now.', 'skipped' => $skipped];
        }
        \AICliAgents\Services\SupervisorService::wake();
        return ['status' => 'queued', 'queued' => $queued, 'skipped' => $skipped,
                'message' => 'Conversion queued for ' . count($queued) . ' agent(s). The workspaces of each agent close and open again when it is done. Watch the activity tray for progress.'];
    }

    /**
     * Bug #1380: list the QUALIFYING relocation targets for a "move off USB
     * flash drive" graduation. Read-only. GET: type=home|agent (default home).
     * Returns the durable, non-array, non-flash, passthrough-capable, non-refused
     * candidates the data can be moved TO (the SAME list the gate uses, so the
     * picker and the `can_graduate` recommendation can never diverge).
     * Response: { status, kind, targets:[{path,label,mount_class,engine,
     * free_bytes,...}] }.
     */
    private static function graduateTargets(): array {
        $kind = (string)($_REQUEST['type'] ?? 'home');
        if (!in_array($kind, ['home', 'agent'], true)) {
            return ['status' => 'error', 'message' => 'type must be home or agent'];
        }
        require_once __DIR__ . '/../services/StorageTargetService.php';
        $targets = \AICliAgents\Services\StorageTargetService::qualifyingGraduateTargets($kind, getAICliConfig());
        return ['status' => 'ok', 'kind' => $kind, 'targets' => array_values($targets)];
    }

    /**
     * Bug #1380: "Move off USB flash drive" — relocate an entity's data from a
     * genuine USB-flash persist device to a durable non-array non-flash target
     * the user picked. REPLACES the S-10 in-place layering→passthrough enqueue:
     * graduating on a stick would write the un-layered data straight back to the
     * USB (wear); the user's model is RELOCATION to durable storage.
     *
     * The persist path is per-KIND (one home path, one agent path), so relocating
     * an entity = re-pointing that kind's storage path to the chosen target and
     * copying its .sqsh layers there. This reuses the PROVEN, crash-safe
     * preflight → execute_migrate machinery (per-file verified copy + config +
     * manifest re-point under the migration marker) rather than re-implementing a
     * copy. After relocation the data is on a durable disk (off the USB = the
     * user's win); whether to ALSO drop the layering engine in place there is left
     * to the existing `storagectl graduate` verb / supervisor (now valid because
     * the destination is durable) and is NOT chained here — chaining it would add
     * a second long mutation to a synchronous AJAX call for no data-safety gain.
     *
     * GET: type=home|agent (default home), target=<chosen qualifying path>.
     * Response: the execute_migrate result (status, files, etc.).
     */
    private static function graduate() {
        $kind   = (string)($_REQUEST['type']   ?? 'home');
        $target = trim((string)($_REQUEST['target'] ?? ''));
        if (!in_array($kind, ['home', 'agent'], true)) {
            return ['status' => 'error', 'message' => 'type must be home or agent'];
        }
        if ($target === '') {
            return ['status' => 'error', 'message' => 'A relocation target path is required (pick a durable target).'];
        }

        // Re-validate the target STILL qualifies server-side (defence in depth —
        // never trust the client to have picked from the offered list).
        require_once __DIR__ . '/../services/StorageTargetService.php';
        $qualifying = \AICliAgents\Services\StorageTargetService::qualifyingGraduateTargets($kind, getAICliConfig());
        $match = null;
        foreach ($qualifying as $t) {
            if (($t['path'] ?? '') === $target) { $match = $t; break; }
        }
        if ($match === null) {
            return ['status' => 'error',
                    'message' => "Target '$target' is not a qualifying durable, non-array, non-flash location. Refresh and pick from the offered list."];
        }

        $config       = getAICliConfig();
        $oldAgentPath = $config['agent_storage_path'] ?? \AICliAgents\Services\SecretPaths::flashRoot() . '/persistence';
        $oldHomePath  = \AICliAgents\Services\StoragePathResolver::homePersistPathFromConfig($config);

        aicli_log("AJAX Request: Move $kind storage off USB flash drive to durable target '$target'", AICLI_LOG_INFO);
        \AICliAgents\Services\LifecycleLogService::log(
            \AICliAgents\Services\LifecycleLogService::LEVEL_INFO,
            'storage', 'storage_graduate_relocate_start',
            ['kind' => $kind, 'target' => $target,
             'from' => ($kind === 'agent' ? $oldAgentPath : $oldHomePath)]
        );

        // Drive the proven relocation via execute_migrate. It reads the NEW paths
        // from $_GET (the old paths come from the live config, #652) — set ONLY the
        // chosen kind's path; leave the other kind unchanged (same path in = no-op).
        $_GET['agent_storage_path'] = ($kind === 'agent') ? $target : $oldAgentPath;
        $_GET['home_storage_path']  = ($kind === 'home')  ? $target : $oldHomePath;

        $result = self::executeMigrateExclusive();
        \AICliAgents\Services\LifecycleLogService::log(
            \AICliAgents\Services\LifecycleLogService::LEVEL_INFO,
            'storage', 'storage_graduate_relocate_done',
            ['kind' => $kind, 'target' => $target,
             'status' => (string)($result['status'] ?? 'unknown')]
        );
        return $result;
    }

    private static function repairAgent($id) {
        // R-170: the id must be a registered agent before it reaches a repair.
        if (AgentHandler::requireAgentId($id) === null) return ['status' => 'error', 'message' => 'Invalid agent id'];
        aicli_log("AJAX Request: Repair Agent storage for $id", AICLI_LOG_INFO);
        $res = aicli_repair_agent_storage($id);
        return ['status' => $res ? 'ok' : 'error', 'message' => $res ? '' : 'Repair failed. Check debug log.'];
    }

    private static function repairHome() {
        // #651: repair the home that was clicked (`id`), not the configured one.
        $user = self::homeActionUser();
        if ($user === '') return ['status' => 'error', 'message' => 'invalid_user'];
        // #131: a repair remounts the overlay — refuse while a consolidation is
        // baking that same home, or the reclaim aborts (mirrors the client guard
        // and how the interactive `start` is gated).
        require_once __DIR__ . '/../services/ConsolidateState.php';
        if (\AICliAgents\Services\ConsolidateState::isHomeConsolidating((string)$user)) {
            return ['status' => 'error', 'message' => "Storage tidy-up is in progress for $user — please wait for it to finish before repairing."];
        }
        aicli_log("AJAX Request: Repair Home storage for $user", AICLI_LOG_INFO);
        $res = aicli_repair_home_storage($user);
        return ['status' => $res ? 'ok' : 'error', 'message' => $res ? '' : "Repair failed for $user. Check debug log."];
    }

    /**
     * Bug #1379: Permanently delete a home entity and ALL its residue
     * (layers, overlay mounts, manifest entry, markers, work dir).
     *
     * GET parameters: id (home user id, e.g. "root" or "smktest_cc_1234")
     * Guards: refuses if a live session is open (entity_in_use); refuses
     * "root" and "aicliagent" IDs at the PHP boundary even though the
     * client-side swal already asks for typed confirmation — defence in depth.
     *
     * Response: { "status":"ok"|"error", "message":"..." }
     */
    /**
     * #654: why a home cannot be deleted right now, or null. A queued or running job
     * for the home (backup, restore, consolidate, convert, bake) or a running tidy-up
     * blocks the delete. $jobs is the test seam (SupervisorService::listJobs shape).
     */
    public static function deleteHomeBlocker(string $id, ?array $jobs = null): ?string {
        require_once __DIR__ . '/../services/ConsolidateState.php';
        $active = \AICliAgents\Services\SupervisorService::activeJobFor("home/$id", $jobs);
        if ($active !== null) {
            return "Home $id has a running or queued storage job ({$active['op']}). Wait for it to finish, then try again.";
        }
        if (\AICliAgents\Services\ConsolidateState::isHomeConsolidating($id)) {
            return "Storage tidy-up is in progress for $id. Wait for it to finish, then try again.";
        }
        return null;
    }

    private static function deleteConfirmPath(string $id): string {
        $tmpBase = getenv('AICLI_TMP_BASE');
        $base = rtrim($tmpBase !== false && $tmpBase !== '' ? $tmpBase : \AICliAgents\Services\SecretPaths::stateRoot(), '/');
        return $base . '/.delete_home_confirm_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $id) . '.json';
    }

    /** #654: make a single-use confirm token for deleting a primary home. Valid for 120 seconds. */
    public static function issueDeleteConfirmToken(string $id): string {
        $token = bin2hex(random_bytes(16));
        $path = self::deleteConfirmPath($id);
        @mkdir(dirname($path), 0755, true);
        \AICliAgents\Services\AtomicWriteService::write($path, json_encode(['token' => $token, 'exp' => time() + 120]));
        @chmod($path, 0600);
        return $token;
    }

    /** #654: true once for a token made by issueDeleteConfirmToken() and not yet expired. */
    public static function consumeDeleteConfirmToken(string $id, string $token): bool {
        $path = self::deleteConfirmPath($id);
        $rec = is_file($path) ? json_decode((string)@file_get_contents($path), true) : null;
        @unlink($path);
        return is_array($rec) && $token !== '' && isset($rec['token'], $rec['exp'])
            && hash_equals((string)$rec['token'], $token) && (int)$rec['exp'] >= time();
    }

    /** AJAX: the dialog calls this after the person typed DELETE for a primary home. */
    private static function deleteHomeConfirmTokenAction(): array {
        $id = trim((string)($_REQUEST['id'] ?? ''));
        if (!in_array($id, ['root', 'aicliagent'], true)) {
            return ['status' => 'error', 'message' => 'A confirm token is only needed for root and aicliagent.'];
        }
        return ['status' => 'ok', 'token' => self::issueDeleteConfirmToken($id)];
    }

    private static function deleteHome(): array {
        $id = trim((string)($_REQUEST['id'] ?? ''));

        if ($id === '' || !preg_match('/^[a-zA-Z0-9._-]{1,64}$/D', $id)) {
            return ['status' => 'error', 'message' => 'id is required and must be alphanumeric (1-64 chars)'];
        }

        // Extra server-side guard for the primary home users: the client swal
        // already gates these behind typed confirmation, but we enforce it here
        // too so a rogue direct AJAX call cannot silently wipe root's home.
        // #654: root_confirmed was a plain flag any caller could send. The dialog now
        // asks the server for a short single-use token (delete_home_confirm_token)
        // after the person types DELETE; the delete must present that token.
        $rootLike = in_array($id, ['root', 'aicliagent'], true);
        if ($rootLike && !self::consumeDeleteConfirmToken($id, (string)($_REQUEST['confirm_token'] ?? ''))) {
            return ['status' => 'error', 'message' => "Deleting '$id' requires the typed confirmation in the dialog. Open the dialog again and type DELETE."];
        }

        // #654: the browser disables the button while a job runs, but a stale tab or
        // a direct call is not stopped by that. Refuse here, before anything is removed.
        $blocker = self::deleteHomeBlocker($id);
        if ($blocker !== null) {
            return ['status' => 'error', 'message' => $blocker];
        }

        // This is a plain-text log call, not a SQL sink, and $id passed the strict allowlist above.
        aicli_log("AJAX Request: Delete home storage for home/$id", AICLI_LOG_WARN); // nosemgrep: php.lang.security.injection.tainted-sql-string.tainted-sql-string

        require_once __DIR__ . '/../services/FileStorage.php';
        $result = \AICliAgents\Services\FileStorage::deleteHomeEntity($id);

        if ($result['ok']) {
            \AICliAgents\Services\ActivityService::register("storage_delete_home_$id", 'storage', "Home storage deleted: $id", [
                'step' => '', 'progress' => 100,
                'meta' => ['kind' => 'record', 'cancel' => 'none'],
            ]);
            \AICliAgents\Services\ActivityService::finish("storage_delete_home_$id", '');
        }

        return [
            'status'  => $result['ok'] ? 'ok' : 'error',
            'message' => $result['message'] ?? ($result['ok'] ? 'Home storage deleted.' : 'Delete failed.'),
        ];
    }

    private static function wipe() {
        $type = $_GET['type'] ?? 'agent';
        $id = $_GET['id'] ?? 'default';
        // #585: type and id come straight from the request. Refuse anything that
        // is not an agent or a home with a path-safe name (id=* or id=.. wiped
        // every home layer or a whole upper tree).
        if (!\AICliAgents\Services\StorageMigrationService::isValidWipeTarget($type, $id)) {
            aicli_log("AJAX Request: Wipe storage refused: invalid type or id", AICLI_LOG_WARN);
            return ['status' => 'error', 'message' => 'Invalid storage type or id.'];
        }
        aicli_log("AJAX Request: Wipe storage for $type: $id", AICLI_LOG_WARN);
        $res = ($type === 'agent') ? aicli_nuclear_rebuild_agent_storage($id) : \AICliAgents\Services\StorageMigrationService::nuclearRebuild('home', $id);
        \AICliAgents\Services\LifecycleLogService::log($res ? \AICliAgents\Services\LifecycleLogService::LEVEL_WARN : \AICliAgents\Services\LifecycleLogService::LEVEL_ERROR, 'storage', 'storage_wiped', ['type' => $type, 'id' => $id, 'success' => $res]);
        return ['status' => $res ? 'ok' : 'error', 'message' => $res ? '' : 'Storage wipe failed. Check debug log.'];
    }

    private static function purgeArtifacts() {
        aicli_log("AJAX Request: Purge legacy migration artifacts", AICLI_LOG_WARN);
        $res = \AICliAgents\Services\StorageMigrationService::purgeArtifacts();
        return ['status' => $res ? 'ok' : 'error', 'message' => $res ? '' : 'Purge failed. Check debug log.'];
    }

    /**
     * Pre-flight check for storage path migration. Returns file inventory + sizes.
     */
    private static function preflightMigrate() {
        $config = getAICliConfig();
        $newAgentPath = $_GET['agent_storage_path'] ?? '';
        $newHomePath = $_GET['home_storage_path'] ?? '';
        $oldAgentPath = $config['agent_storage_path'] ?? \AICliAgents\Services\SecretPaths::flashRoot() . '/persistence';
        $oldHomePath = \AICliAgents\Services\StoragePathResolver::homePersistPathFromConfig($config);

        // #341 → S-11 (#1355): validate any new path via the capability probe
        // (StorageTargetService::validateTarget → FileStorage::probeTarget).
        // probe refuse / per-kind network policy → error; probe warnings are
        // surfaced per kind; exclusive-share /mnt/user paths resolve to the
        // direct pool path (resolved_*_path — the picker STORES that value).
        // The old inline $durable findmnt fstype list survives only as the
        // legacy fallback inside validateTarget when the probe is unavailable.
        require_once __DIR__ . '/../services/StorageTargetService.php';
        $pfWarnings = ['agent' => [], 'home' => []];
        $pfResolved = [];
        foreach (['agent' => $newAgentPath, 'home' => $newHomePath] as $kind => $path) {
            if (!$path || $path === ($kind === 'agent' ? $oldAgentPath : $oldHomePath)) continue;
            $verdict = \AICliAgents\Services\StorageTargetService::validateTarget($kind, $path, $config);
            if (!$verdict['ok']) {
                return ['status' => 'error', 'message' => $verdict['message']];
            }
            $pfWarnings[$kind] = $verdict['warnings'];
            if (!empty($verdict['resolved_path']) && $verdict['resolved_path'] !== $path) {
                $pfResolved["resolved_{$kind}_path"] = $verdict['resolved_path'];
            }
        }

        // Show current files as-is (no persist/consolidate yet — that happens after user confirms)
        $files = [];
        $totalBytes = 0;

        if ($newAgentPath && $newAgentPath !== $oldAgentPath) {
            foreach (glob("$oldAgentPath/agent_*.sqsh") as $f) {
                $size = filesize($f);
                $totalBytes += $size;
                $files[] = ['name' => basename($f), 'size_mb' => round($size / 1048576, 2), 'type' => 'agent', 'from' => $oldAgentPath, 'to' => $newAgentPath];
            }
        }
        if ($newHomePath && $newHomePath !== $oldHomePath) {
            // Only move home_*.sqsh files — nothing else at the path belongs to us
            foreach (glob("$oldHomePath/home_*.sqsh") as $f) {
                $size = filesize($f);
                $totalBytes += $size;
                $files[] = ['name' => basename($f), 'size_mb' => round($size / 1048576, 2), 'type' => 'home', 'from' => $oldHomePath, 'to' => $newHomePath];
            }
        }

        return array_merge([
            'status' => 'ok',
            'files' => $files,
            'total_mb' => round($totalBytes / 1048576, 2),
            'agent_changed' => ($newAgentPath && $newAgentPath !== $oldAgentPath),
            'home_changed' => ($newHomePath && $newHomePath !== $oldHomePath),
            'old_agent_path' => $oldAgentPath,
            'new_agent_path' => $newAgentPath,
            'old_home_path' => $oldHomePath,
            'new_home_path' => $newHomePath,
            'warnings' => $pfWarnings,
        ], $pfResolved);
    }

    /**
     * R-174: a second click must not queue a second job. Returns an error row when a job of
     * the same kind is already queued, running or deferred for this entity, else null.
     * $find is the test seam; it has the signature of SupervisorService::findActiveJob.
     * @return array{status:'error',reason:'already_queued',message:string}|null
     */
    public static function duplicateJobRefusal(string $type, string $id, string $op, string $what, ?callable $find = null): ?array {
        $find ??= [\AICliAgents\Services\SupervisorService::class, 'findActiveJob'];
        if ($find($type, $id, $op) === null) return null;
        return ['status' => 'error', 'reason' => 'already_queued',
                'message' => "$what is already waiting or running. Watch the activity tray for progress."];
    }

    /**
     * R-172: does the home or the agent storage sit on the array or a pool? The array-stop
     * warning asks this when the person presses "Stop array". The page used to run two bash
     * processes for it on every Unraid page load. $classify is the test seam: it maps a path
     * to true when that path depends on the array.
     * @return array{status:string,homeOnArray:bool,agentOnArray:bool,homePath:string,agentPath:string}
     */
    public static function arrayStopImpact(?callable $classify = null, ?array $config = null): array {
        $classify ??= static function (string $p): bool { return \AICliAgents\Services\StorageMountService::isArrayDependent($p); };
        $config ??= getAICliConfig();
        $homePath = (string)($config['home_storage_path'] ?? '');
        $agentPath = (string)($config['agent_storage_path'] ?? '');
        return [
            'status'       => 'ok',
            'homeOnArray'  => $homePath !== '' && (bool)$classify($homePath),
            'agentOnArray' => $agentPath !== '' && (bool)$classify($agentPath),
            'homePath'     => $homePath,
            'agentPath'    => $agentPath,
        ];
    }

    /**
     * S-11 (#1355): enumerate ranked storage target candidates for the picker.
     * Read-only (no Nchan publish). GET: kind=home|agent.
     * Response: { "status":"ok", "kind":"home", "targets":[{path,label,
     * mount_class,engine,upper_mode,free_bytes,recommendation_rank,warnings[],
     * refuse,current,recommended,advanced,...}], "recommended":"<path>|null" }
     */
    private static function enumerateStorageTargets(): array {
        $kind = trim((string)($_REQUEST['kind'] ?? 'home'));
        if (!in_array($kind, ['home', 'agent'], true)) {
            return ['status' => 'error', 'message' => 'kind must be home or agent'];
        }
        require_once __DIR__ . '/../services/StorageTargetService.php';
        $targets = \AICliAgents\Services\StorageTargetService::enumerateTargets($kind);
        $recommended = null;
        foreach ($targets as $t) {
            if (!empty($t['recommended'])) { $recommended = $t['path']; break; }
        }
        return ['status' => 'ok', 'kind' => $kind, 'targets' => $targets, 'recommended' => $recommended];
    }

    private static function preflightStorageBackend(): array {
        require_once __DIR__ . '/../services/StorageBackendMigrationService.php';
        $mode = (string)($_GET['mode'] ?? 'layering');
        // kind = homes or agents: the two storage settings on the Configuration tab.
        $kind = (string)($_GET['kind'] ?? '');
        return \AICliAgents\Services\StorageBackendMigrationService::preflight($mode, getAICliConfig(), [], $kind);
    }

    /** #456: read-only — what moving the homes' writable layer to ?mode= would do, and whether it is allowed. */
    private static function preflightUpperMode(): array {
        require_once __DIR__ . '/../services/UpperPlacementService.php';
        return \AICliAgents\Services\UpperPlacementService::preflight((string)($_GET['mode'] ?? ''), getAICliConfig());
    }

    /** #456: change the placement and move the data (closes workspaces; saves, remounts, verifies). */
    private static function executeUpperMode(): array {
        set_time_limit(1800);
        require_once __DIR__ . '/../services/UpperPlacementService.php';
        return \AICliAgents\Services\UpperPlacementService::migrate((string)($_GET['mode'] ?? ''), getAICliConfig());
    }

    private static function executeStorageBackend(): array {
        require_once __DIR__ . '/../services/StorageBackendMigrationService.php';
        $mode = (string)($_GET['mode'] ?? 'layering');
        $ack = ((string)($_GET['ack_wear'] ?? '0') === '1');
        $kind = (string)($_GET['kind'] ?? '');
        return \AICliAgents\Services\StorageBackendMigrationService::startAsync($mode, $ack, getAICliConfig(), [], $kind);
    }

    /**
     * #652: the one entry for a storage path move (action execute_migrate). It does not copy
     * anything: it checks, closes the sessions, queues the saves and queues the supervisor
     * job (op migratepath) that does the copy, so the move goes on when the browser closes.
     *
     * Only one move runs at a time: a refusal while another move holds the lock, while it
     * is queued, or while the crash-safe marker of an earlier move is still there (that
     * move must finish or resume first). A client that sends old paths which differ from
     * the live settings (a stale tab) is refused before anything is touched. The old paths
     * are never taken from the client.
     *
     * @param array<string,callable> $seams test seams for queuePathMove()
     */
    public static function executeMigrateExclusive(array $seams = []): array {
        require_once __DIR__ . '/../services/FileStorage.php';
        require_once __DIR__ . '/../services/StoragePathMoveJobService.php';
        require_once __DIR__ . '/../services/StorageBackendMigrationService.php';
        $config = getAICliConfig();
        foreach (['old_agent_path' => 'agent_storage_path', 'old_home_path' => 'home_storage_path'] as $param => $key) {
            // #753: the home path is the resolved one (the shell uses the same rule).
            $live = $key === 'home_storage_path'
                ? \AICliAgents\Services\StoragePathResolver::homePersistPathFromConfig($config)
                : (string)($config[$key] ?? \AICliAgents\Services\SecretPaths::flashRoot() . '/persistence');
            if (isset($_GET[$param]) && (string)$_GET[$param] !== $live) {
                aicli_log("Storage Migration: refusing — $param from the browser differs from the live settings", AICLI_LOG_WARN);
                return ['status' => 'error', 'message' => 'The storage settings changed since this page was loaded. Reload the page and try again.'];
            }
        }
        // The lock is held by the job while it copies. Here it is only probed.
        $lock = \AICliAgents\Services\FileStorage::acquireMigrationLock();
        if ($lock === null) {
            return ['status' => 'error', 'message' => 'Another storage move is already running. Wait for it to finish.'];
        }
        try {
            if (self::blockedByBackendMigration('execute_migrate')) {
                return ['status' => 'error', 'reason' => 'storage_migration', 'message' => 'Wait for the storage migration to finish before moving storage.'];
            }
            if (\AICliAgents\Services\FileStorage::isBackendMigrationInProgress()) {
                return ['status' => 'error', 'reason' => 'storage_migration', 'message' => 'Wait for the storage migration to finish before moving storage.'];
            }
            if (\AICliAgents\Services\FileStorage::isMigrationInProgress()) {
                return ['status' => 'error', 'message' => 'An earlier storage move did not finish. It must finish or resume first.'];
            }
            if (\AICliAgents\Services\StoragePathMoveJobService::hasPending()) {
                return ['status' => 'error', 'message' => 'Another storage move is already queued. Wait for it to finish.'];
            }
            // Keep the shared lock until queuePathMove() has created its claim.
            // An engine migration then sees the pending move before it can queue.
            return self::queuePathMove($config, (string)($_GET['agent_storage_path'] ?? ''), (string)($_GET['home_storage_path'] ?? ''), $seams);
        } finally {
            \AICliAgents\Services\FileStorage::releaseMigrationLock($lock);
        }
    }

    /**
     * Check the new paths, close the sessions, queue the saves, queue the move job.
     * Returns at once with status `queued`; the copy runs in runPathMove() under the supervisor.
     * The tray row `storage_migrate` shows "waiting" until the job starts, then the steps.
     *
     * @param array<string,mixed> $config the live settings (the old paths are taken from here)
     * @param array<string,callable> $seams test seams: validate (fn(kind, path, config): array), mounted (fn(path): bool),
     *        evict (fn(): void), enqueue (fn(type, id, op, reason, prio, jobId): bool)
     */
    public static function queuePathMove(array $config, string $newAgentPath, string $newHomePath, array $seams = []): array {
        // #652: the old paths are the live settings (the move saves the new paths only
        // after the copy). The browser's old_* values are never used, only compared.
        $oldAgentPath = $config['agent_storage_path'] ?? \AICliAgents\Services\SecretPaths::flashRoot() . '/persistence';
        $oldHomePath = \AICliAgents\Services\StoragePathResolver::homePersistPathFromConfig($config);

        aicli_log("Storage Migration: Starting path migration...", AICLI_LOG_INFO);

        // Bug #297: the picker's preflight_migrate validation only ADVISES the
        // browser — this request's own $_GET paths are what actually get used
        // below (copy destination, config save, manifest re-point). A stale
        // tab, a bypassed picker, or a hand-built request could otherwise send
        // a raw /mnt/user (FUSE/shfs) path straight through to the copy and
        // bake steps, which is the load pattern that can freeze the whole
        // host. Re-run the SAME per-kind validation server-side, on the paths
        // this request is about to act on, and from here on use ONLY the
        // resolved path it returns — never the raw submitted one. This
        // refuses tmpfs/volatile/invalid paths exactly as validateTarget does
        // for the picker, and resolves an exclusive (or single-pool, see
        // StorageTargetService::resolveViaShareConfig) /mnt/user share onto
        // its direct pool path even when the browser sent the raw share path.
        require_once __DIR__ . '/../services/StorageTargetService.php';
        $validate = $seams['validate'] ?? static fn(string $kind, string $path, array $cfg): array =>
            \AICliAgents\Services\StorageTargetService::validateTarget($kind, $path, $cfg);
        if ($newAgentPath !== '' && $newAgentPath !== $oldAgentPath) {
            $__mcVerdict = $validate('agent', (string)$newAgentPath, $config);
            if (!$__mcVerdict['ok']) {
                aicli_log("Storage Migration: refusing invalid agent target $newAgentPath — {$__mcVerdict['message']}", AICLI_LOG_ERROR);
                return ['status' => 'error', 'message' => $__mcVerdict['message']];
            }
            if (!empty($__mcVerdict['resolved_path']) && $__mcVerdict['resolved_path'] !== $newAgentPath) {
                aicli_log("Storage Migration: resolved agent target $newAgentPath -> {$__mcVerdict['resolved_path']}", AICLI_LOG_INFO);
                $newAgentPath = $__mcVerdict['resolved_path'];
            }
        }
        if ($newHomePath !== '' && $newHomePath !== $oldHomePath) {
            $__mcVerdict = $validate('home', (string)$newHomePath, $config);
            if (!$__mcVerdict['ok']) {
                aicli_log("Storage Migration: refusing invalid home target $newHomePath — {$__mcVerdict['message']}", AICLI_LOG_ERROR);
                return ['status' => 'error', 'message' => $__mcVerdict['message']];
            }
            if (!empty($__mcVerdict['resolved_path']) && $__mcVerdict['resolved_path'] !== $newHomePath) {
                aicli_log("Storage Migration: resolved home target $newHomePath -> {$__mcVerdict['resolved_path']}", AICLI_LOG_INFO);
                $newHomePath = $__mcVerdict['resolved_path'];
            }
        }
        unset($__mcVerdict);

        // Issue #56: validation and execution are separated by UI/request time.
        // Recheck the backing mounts immediately before evicting sessions or
        // creating destination directories so an array-stop race fails closed.
        $mounted = $seams['mounted'] ?? static fn(string $p): bool => \AICliAgents\Services\StorageMountService::isBackingMountAvailable($p);
        foreach ([['agent', $newAgentPath, $oldAgentPath], ['home', $newHomePath, $oldHomePath]] as [$kind, $newPath, $oldPath]) {
            if ($newPath && $newPath !== $oldPath && !$mounted((string)$newPath)) {
                aicli_log("Storage Migration: refusing unavailable $kind target $newPath", AICLI_LOG_ERROR);
                return ['status' => 'error', 'message' => ucfirst($kind) . " target storage is not mounted: $newPath"];
            }
        }

        require_once __DIR__ . '/../services/StoragePathMoveJobService.php';
        $jobSvc = \AICliAgents\Services\StoragePathMoveJobService::class;
        $moveJobId = \AICliAgents\Services\SupervisorService::generateJobId($jobSvc::OP, $jobSvc::TYPE, $jobSvc::ID);
        // One claim for the whole move: a second request is refused until this move ends.
        if (!$jobSvc::claim($moveJobId)) {
            return ['status' => 'error', 'message' => 'Another storage move is already queued. Wait for it to finish.'];
        }
        $enqueue = $seams['enqueue'] ?? static fn(string $t, string $i, string $o, string $r, int $p, string $j): bool =>
            \AICliAgents\Services\SupervisorService::enqueue($t, $i, $o, $r, $p, $j);
        // From here a failure must give the claim back and end the tray row.
        $fail = function (string $message, int $progress) use ($jobSvc, $moveJobId): array {
            $jobSvc::cleanup($moveJobId);
            $row = self::migrationFailureRow($message, $progress, false);
            \AICliAgents\Services\ActivityService::fail('storage_migrate', $row['error'], $row['recovery'], [
                'type' => 'migrate', 'label' => self::$migrateLabel, 'step' => $row['step'], 'meta' => ['cancel' => 'none'],
            ]);
            return ['status' => 'error', 'message' => $message];
        };

        // 1. Evict all sessions
        $__target = (string)($newHomePath ?: $newAgentPath);
        self::$migrateLabel = $__target !== '' ? "Moving storage to $__target" : 'Moving storage';
        self::$migrateLastProgress = 0;
        self::$migrateSettingsSaved = false;
        // Register afresh: the title carries the target path, and an old finished row must not keep its title.
        \AICliAgents\Services\ActivityService::register('storage_migrate', 'migrate', self::$migrateLabel, [
            'step' => 'Closing sessions', 'progress' => 5, 'meta' => ['cancel' => 'none'],
        ]);
        self::migrateProgress('Stopping active sessions...', 5, [], 'Closing sessions');
        $evict = $seams['evict'] ?? static function (): void {
            // R3 (CAPTURE_RESUME_ALL_CLOSE_PATHS): a storage-path migration is an
            // orderly, user-initiated action — time is available, so run the FULL
            // clean capture (quiesce + scrape, authoritative resume id) for every
            // live session BEFORE the hard evict, so chat continuity survives the
            // migration. Generous-but-bounded budget; never throws (shutdown-safe).
            aicli_log("Storage Migration: Capturing resume ids before evict (R3)...", AICLI_LOG_INFO);
            \AICliAgents\Services\TerminalService::captureResumeForShutdown(null, 60);
            aicli_log("Storage Migration: Evicting active sessions...", AICLI_LOG_INFO);
            \AICliAgents\Services\ProcessManager::evictAll();
        };
        $evict();

        // 2. Enqueue bakes at OLD paths before copying — supervisor flushes dirty ZRAM
        //    so we copy the complete final state (Phase 3.3: no synchronous I/O in handler).
        $user = $config['user'] ?? 'root';
        if (empty($user)) $user = 'root';

        self::migrateProgress('Flushing dirty ZRAM layers before migration...', 10, [], 'Saving unsaved changes');
        aicli_log("Storage Migration: Enqueuing pre-migration bakes via supervisor...", AICLI_LOG_INFO);
        // F18 (#652): the move job runs on the same supervisor, after these jobs (priority 5 runs
        // before the move's priority 6). It reads the ledger entry of the home save and refuses
        // to copy when that save did not finish.
        $preBakeId = \AICliAgents\Services\SupervisorService::generateJobId('bake', 'home', (string)$user);
        if (!$enqueue('home', (string)$user, 'bake', 'pre_migrate', 5, $preBakeId)) {
            return $fail('Could not queue the save of unsaved changes. Nothing was moved.', 10);
        }
        $preJobs = [$preBakeId];

        // Enqueue pre-migration consolidations at OLD path (skip if on Flash)
        $oldAgentOnFlash = (strpos($oldAgentPath, '/boot/') === 0 || strpos($oldAgentPath, '/boot') === 0);
        if ($newAgentPath && $newAgentPath !== $oldAgentPath && !$oldAgentOnFlash) {
            $seenAgents = [];
            foreach (glob("$oldAgentPath/agent_*.sqsh") ?: [] as $sqsh) {
                if (preg_match('/agent_(.*?)_(v\d+|delta)/', basename($sqsh), $m)) {
                    $aid = $m[1];
                    if (isset($seenAgents[$aid])) continue;
                    $seenAgents[$aid] = true;
                    if (count(glob("$oldAgentPath/agent_{$aid}_*.sqsh")) > 1) {
                        self::migrateProgress("Queuing consolidation for agent: $aid...", 15, [], 'Merging agent layers first');
                        $enqueue('agent', (string)$aid, 'consolidate', 'pre_migrate', 5, '');
                    }
                }
            }
        }
        // Enqueue pre-migration consolidation for home at OLD path (skip if on Flash)
        $oldHomeOnFlash = (strpos($oldHomePath, '/boot/') === 0 || strpos($oldHomePath, '/boot') === 0);
        if ($newHomePath && $newHomePath !== $oldHomePath && !$oldHomeOnFlash) {
            if (count(glob("$oldHomePath/home_{$user}_*.sqsh") ?: []) > 1) {
                self::migrateProgress('Queuing home layer consolidation...', 20, [], 'Merging home layers first');
                $enqueue('home', (string)$user, 'consolidate', 'pre_migrate', 5, '');
            }
        }

        // 3. Queue the move itself. The options file is written BEFORE the job is queued, so the
        //    job always finds it.
        if (!$jobSvc::writeOptions($moveJobId, [
            'new_agent' => $newAgentPath, 'new_home' => $newHomePath, 'pre_jobs' => $preJobs,
            'label' => self::$migrateLabel, 'queued_at' => time(),
        ])) {
            return $fail('Could not save the options of the move. Nothing was copied.', 22);
        }
        if (!$enqueue($jobSvc::TYPE, $jobSvc::ID, $jobSvc::OP, 'user_migrate', $jobSvc::PRIORITY, $moveJobId)) {
            return $fail('Could not queue the move. Nothing was copied.', 22);
        }
        // The row waits for the supervisor to reach the move: it has no worker that heartbeats it yet.
        \AICliAgents\Services\ActivityService::wait('storage_migrate', 'Waiting for the saves to finish', 22);
        if (!isset($seams['enqueue'])) \AICliAgents\Services\SupervisorService::wake();
        aicli_log("Storage Migration: queued move job $moveJobId", AICLI_LOG_INFO);
        return [
            'status' => 'queued', 'job_id' => $moveJobId,
            'message' => 'The storage move is queued. Your workspaces were closed. The files are copied in the background, so you can close this page. Watch the activity tray for progress.',
        ];
    }

    /**
     * F18 (#652): the copy of a storage path move. It runs ONLY in the supervisor job (op migratepath,
     * scripts/storage/migrate-paths.php through StoragePathMoveJobService::run), which holds the move
     * lock and has checked that the saves finished. The paths are the ones queuePathMove() validated
     * and resolved; this method reads no request. Progress goes to the tray row `storage_migrate`.
     * Returns the result array (status ok|error).
     */
    public static function runPathMove(string $newAgentPath, string $newHomePath): array {
        $config = getAICliConfig();
        $oldAgentPath = $config['agent_storage_path'] ?? \AICliAgents\Services\SecretPaths::flashRoot() . '/persistence';
        $oldHomePath = \AICliAgents\Services\StoragePathResolver::homePersistPathFromConfig($config);

        // Follow-on #1: a settings path change is an EXPLICIT, crash-safe TOLD
        // migration. migratePath() marker-BRACKETS this proven copy flow (it does
        // NOT replace it), so an interrupted copy leaves a resumable
        // .migration_inprogress.json for boot rather than a drifted path the
        // supervisor/boot discovers and halts on — which is what makes the
        // path_drift discovery-halt + op_mount LEGACY_FOUND probing redundant.
        require_once __DIR__ . '/../services/FileStorage.php';
        $__migResult = null;
        $__migWork = function () use (&$__migResult, $config, $oldAgentPath, $newAgentPath, $oldHomePath, $newHomePath) {
        // Issue #56: the mounts can change between the queue and the start. Recheck them
        // before the destination directories are created.
        foreach ([['agent', $newAgentPath, $oldAgentPath], ['home', $newHomePath, $oldHomePath]] as [$kind, $newPath, $oldPath]) {
            if ($newPath && $newPath !== $oldPath
                && !\AICliAgents\Services\StorageMountService::isBackingMountAvailable((string)$newPath)) {
                $__migResult = ['status' => 'error', 'message' => ucfirst($kind) . " target storage is not mounted: $newPath"];
                aicli_log("Storage Migration: refusing unavailable $kind target $newPath", AICLI_LOG_ERROR);
                return false;
            }
        }
        $__target = (string)($newHomePath ?: $newAgentPath);
        self::$migrateLabel = $__target !== '' ? "Moving storage to $__target" : 'Moving storage';
        // The sessions were closed and the saves were queued before this job was queued.
        self::$migrateLastProgress = 22;
        self::$migrateSettingsSaved = false;
        \AICliAgents\Services\ActivityService::register('storage_migrate', 'migrate', self::$migrateLabel, [
            'step' => 'Starting the copy', 'progress' => 22, 'meta' => ['cancel' => 'none'],
        ]);

        // 3. Build file list (after consolidation — minimal set)
        $files = [];
        if ($newAgentPath && $newAgentPath !== $oldAgentPath) {
            foreach (glob("$oldAgentPath/agent_*.sqsh") as $f) $files[] = ['path' => $f, 'dest' => $newAgentPath, 'type' => 'agent'];
        }
        if ($newHomePath && $newHomePath !== $oldHomePath) {
            foreach (glob("$oldHomePath/home_*.sqsh") as $f) $files[] = ['path' => $f, 'dest' => $newHomePath, 'type' => 'home'];
        }

        $total = max(count($files), 1);
        $done = 0;

        // 2. Migrate Agent files one by one
        if ($newAgentPath && $newAgentPath !== $oldAgentPath) {
            if (!is_dir($newAgentPath)) @mkdir($newAgentPath, 0755, true);
            foreach (glob("$oldAgentPath/agent_*.sqsh") as $f) {
                $name = basename($f);
                $sizeMB = round(filesize($f) / 1048576, 2);
                $pct = intval(10 + (($done / $total) * 70));
                self::migrateProgress("Copying $name ({$sizeMB}MB)...", $pct, ['file' => $name], 'Copying layer ' . ($done + 1) . " of $total ({$sizeMB} MB)");
                aicli_log("Storage Migration: Copying $name ({$sizeMB}MB) from $oldAgentPath to $newAgentPath", AICLI_LOG_INFO);

                $src = $f;
                $dst = "$newAgentPath/$name";
                $cmd = sprintf("cp -a %s %s", escapeshellarg($src), escapeshellarg($dst)); exec($cmd, $out, $res); // nosemgrep: php.lang.security.tainted-exec — $src and $dst fully escaped — src/dst fully escaped via escapeshellarg()
                if ($res !== 0) {
                    aicli_log("Storage Migration: FAILED to copy $name", AICLI_LOG_ERROR);
                    $__migResult = ['status' => 'error', 'message' => "Failed to copy $name"];
                    return false;
                }
                // #753: the copy must match the original by sha256, not only by size. The
                // settings are not saved yet, so a mismatch leaves the originals and the old setting as they were.
                if (!self::filesMatch($src, $dst, true)) {
                    @unlink($dst);
                    aicli_log("Storage Migration: the copy of $name does not match the original (sha256); originals kept", AICLI_LOG_ERROR);
                    $__migResult = ['status' => 'error', 'message' => "The copy of $name does not match the original. The original files were kept and the setting was not changed."];
                    return false;
                }
                aicli_log("Storage Migration: Successfully copied $name", AICLI_LOG_INFO);
                $done++;
            }
        }

        // 3. Migrate Home sqsh files (individual copy, not rsync of entire directory)
        if ($newHomePath && $newHomePath !== $oldHomePath) {
            if (!is_dir($newHomePath)) @mkdir($newHomePath, 0755, true);
            foreach (glob("$oldHomePath/home_*.sqsh") as $f) {
                $name = basename($f);
                $sizeMB = round(filesize($f) / 1048576, 2);
                $pct = intval(10 + (($done / $total) * 70));
                self::migrateProgress("Copying $name ({$sizeMB}MB)...", $pct, ['file' => $name], 'Copying layer ' . ($done + 1) . " of $total ({$sizeMB} MB)");
                aicli_log("Storage Migration: Copying $name ({$sizeMB}MB) from $oldHomePath to $newHomePath", AICLI_LOG_INFO);

                $src = $f;
                $dst = "$newHomePath/$name";
                $cmd = sprintf("cp -a %s %s", escapeshellarg($src), escapeshellarg($dst)); exec($cmd, $out, $res); // nosemgrep: php.lang.security.tainted-exec — $src and $dst fully escaped — src/dst fully escaped via escapeshellarg()
                if ($res !== 0) {
                    aicli_log("Storage Migration: FAILED to copy $name", AICLI_LOG_ERROR);
                    $__migResult = ['status' => 'error', 'message' => "Failed to copy $name"];
                    return false;
                }
                // #753: same check as for the agent layers above.
                if (!self::filesMatch($src, $dst, true)) {
                    @unlink($dst);
                    aicli_log("Storage Migration: the copy of $name does not match the original (sha256); originals kept", AICLI_LOG_ERROR);
                    $__migResult = ['status' => 'error', 'message' => "The copy of $name does not match the original. The original files were kept and the setting was not changed."];
                    return false;
                }
                aicli_log("Storage Migration: Successfully copied $name", AICLI_LOG_INFO);
                $done++;
            }
        }

        // 4. Save new config — read FRESH config (may have been modified by concurrent requests)
        //    and only update the two path keys. This avoids overwriting other config changes.
        self::migrateProgress('Updating configuration...', 90, [], 'Updating settings');
        aicli_log("Storage Migration: Updating configuration with new paths...", AICLI_LOG_INFO);

        $freshConfig = getAICliConfig();
        if ($newAgentPath && $newAgentPath !== $oldAgentPath) $freshConfig['agent_storage_path'] = $newAgentPath;
        if ($newHomePath && $newHomePath !== $oldHomePath) $freshConfig['home_storage_path'] = $newHomePath;

        \AICliAgents\Services\ConfigService::writeConfigFile($freshConfig);
        self::$migrateSettingsSaved = true; // from here a failure leaves the setting on the NEW place

        // Final consolidation at the new location (skip if target is Flash — avoid unnecessary USB writes)
        $newAgentOnFlash = !empty($newAgentPath) && (strpos($newAgentPath, '/boot/') === 0 || strpos($newAgentPath, '/boot') === 0);
        $newHomeOnFlash = !empty($newHomePath) && (strpos($newHomePath, '/boot/') === 0 || strpos($newHomePath, '/boot') === 0);
        $user = $config['user'] ?? 'root';
        if (empty($user)) $user = 'root';

        $needsConsolidation = false;
        if ($newAgentPath && $newAgentPath !== $oldAgentPath && !$newAgentOnFlash) {
            foreach (glob("$newAgentPath/agent_*.sqsh") as $sqsh) {
                if (preg_match('/agent_(.*?)_(v\d+|delta)/', basename($sqsh), $m)) {
                    $aid = $m[1];
                    if (count(glob("$newAgentPath/agent_{$aid}_*.sqsh")) > 1) { $needsConsolidation = true; break; }
                }
            }
        }
        if ($newHomePath && $newHomePath !== $oldHomePath && !$newHomeOnFlash) {
            if (count(glob("$newHomePath/home_{$user}_*.sqsh")) > 1) $needsConsolidation = true;
        }

        if ($needsConsolidation) {
            // Enqueue post-migration consolidations via supervisor (Phase 3.3).
            // The handler returns immediately; supervisor consolidates in the background.
            self::migrateProgress('Queuing final consolidation at new location...', 95, [], 'Merging layers at the new location');
            if ($newAgentPath && $newAgentPath !== $oldAgentPath && !$newAgentOnFlash) {
                $seenAgents = [];
                foreach (glob("$newAgentPath/agent_*.sqsh") as $sqsh) {
                    if (preg_match('/agent_(.*?)_(v\d+|delta)/', basename($sqsh), $m)) {
                        $aid = $m[1];
                        if (isset($seenAgents[$aid])) continue;
                        $seenAgents[$aid] = true;
                        if (count(glob("$newAgentPath/agent_{$aid}_*.sqsh")) > 1) {
                            \AICliAgents\Services\SupervisorService::enqueue('agent', $aid, 'consolidate', 'post_migrate', 5);
                        }
                    }
                }
            }
            if ($newHomePath && $newHomePath !== $oldHomePath && !$newHomeOnFlash) {
                if (count(glob("$newHomePath/home_{$user}_*.sqsh")) > 1) {
                    \AICliAgents\Services\SupervisorService::enqueue('home', $user, 'consolidate', 'post_migrate', 5);
                }
            }
        }

        // Build summary of final files at new locations
        $migratedFiles = [];
        if ($newAgentPath && $newAgentPath !== $oldAgentPath) {
            foreach (glob("$newAgentPath/agent_*.sqsh") as $f) {
                $sizeMB = round(filesize($f) / 1048576, 1);
                $migratedFiles[] = basename($f) . " ({$sizeMB} MB) → $newAgentPath";
            }
        }
        if ($newHomePath && $newHomePath !== $oldHomePath) {
            foreach (glob("$newHomePath/home_*.sqsh") as $f) {
                $sizeMB = round(filesize($f) / 1048576, 1);
                $migratedFiles[] = basename($f) . " ({$sizeMB} MB) → $newHomePath";
            }
        }
        $summary = implode("\n", $migratedFiles);

        // 6. Cleanup: verify files at new location, then delete originals from old path
        $cleanedUp = 0;
        if ($newAgentPath && $newAgentPath !== $oldAgentPath) {
            foreach (glob("$oldAgentPath/agent_*.sqsh") as $oldFile) {
                $name = basename($oldFile);
                $newFile = "$newAgentPath/$name";
                // #753: remove the original only when the copy has the same sha256.
                if (self::filesMatch($oldFile, $newFile)) {
                    @unlink($oldFile);
                    $cleanedUp++;
                } else {
                    aicli_log("Storage Migration: Keeping $name at old path (the copy at the new path is missing or does not match, sha256)", AICLI_LOG_WARN);
                }
            }
        }
        if ($newHomePath && $newHomePath !== $oldHomePath) {
            foreach (glob("$oldHomePath/home_*.sqsh") as $oldFile) {
                $name = basename($oldFile);
                $newFile = "$newHomePath/$name";
                if (self::filesMatch($oldFile, $newFile)) {
                    @unlink($oldFile);
                    $cleanedUp++;
                } else {
                    aicli_log("Storage Migration: Keeping $name at old path (the copy at the new path is missing or does not match, sha256)", AICLI_LOG_WARN);
                }
            }
        }
        if ($cleanedUp > 0) {
            aicli_log("Storage Migration: Cleaned up $cleanedUp file(s) from old path(s).", AICLI_LOG_INFO);
        }

        // F3 (WP#1327): re-point the manifest's recorded persistence paths to the NEW
        // location IN-TRANSACTION (still under the migration marker), scoped per entity
        // type. Without this the manifest keeps the OLD path and, once the marker
        // clears, the supervisor/boot DISCOVERS the drift and false-halts path_drift on
        // every successful migration — the >1-layer post-migrate consolidate that used
        // to re-point fires for almost no entity (and is itself skipped once halted).
        if ($newHomePath && $newHomePath !== $oldHomePath) {
            $n = \AICliAgents\Services\FileStorage::repointManifestPaths($oldHomePath, $newHomePath, 'home/');
            aicli_log("Storage Migration: re-pointed $n home manifest entr(ies) to $newHomePath", AICLI_LOG_INFO);
        }
        if ($newAgentPath && $newAgentPath !== $oldAgentPath) {
            $n = \AICliAgents\Services\FileStorage::repointManifestPaths($oldAgentPath, $newAgentPath, 'agent/');
            aicli_log("Storage Migration: re-pointed $n agent manifest entr(ies) to $newAgentPath", AICLI_LOG_INFO);
        }

        self::migrateProgress('Migration complete!', 100, [], 'Moved');
        aicli_log("Storage Migration: Complete. Agent path: $newAgentPath, Home path: $newHomePath", AICLI_LOG_INFO);

            $__migResult = ['status' => 'ok', 'message' => "Migration complete.\n\n" . $summary];
            return true;
        };

        // Run the proven copy flow under the crash-safe migration marker.
        $__migOld = $oldHomePath ?: $oldAgentPath;
        $__migNew = $newHomePath ?: $newAgentPath;
        $__mig = \AICliAgents\Services\FileStorage::migratePath($__migOld, $__migNew, $__migWork);
        $__ret = $__migResult ?? ['status' => $__mig->ok ? 'ok' : 'error', 'message' => $__mig->error ?? 'Migration failed.'];
        // T-08: single failure hook for every copy-flow error path — the activity
        // tray shows the migration as failed instead of frozen at the last step.
        if ($__ret['status'] !== 'ok') {
            // ACTIVITY_TRAY_PLAIN_NOTICES.md R5: one plain sentence in the row. The full
            // text goes to the log. The recovery text says what was done: workspaces closed,
            // saves queued, or the setting already on the new place (migrationFailureRow()).
            aicli_log("Storage Migration: failed: " . (string)$__ret['message'], AICLI_LOG_ERROR);
            $__row = self::migrationFailureRow((string)$__ret['message'], self::$migrateLastProgress, self::$migrateSettingsSaved);
            \AICliAgents\Services\ActivityService::fail('storage_migrate', $__row['error'], $__row['recovery'], [
                'type' => 'migrate', 'label' => self::$migrateLabel, 'step' => $__row['step'], 'meta' => ['cancel' => 'none'],
            ]);
        }
        return $__ret;
    }

    /**
     * #753: true when $copy is a faithful copy of $original. Both must be files, the copy
     * must not be empty, the sizes must be equal and the sha256 hashes must be equal.
     * A copy with the same size and other content is NOT a match. A path move calls this
     * before it keeps the copy and again before it removes an original.
     */
    public static function filesMatch(string $original, string $copy, bool $allowEmpty = false): bool {
        if (!is_file($original) || !is_file($copy)) return false;
        $size = filesize($copy);
        if ($size === false || ($size <= 0 && !$allowEmpty) || $size !== filesize($original)) return false;
        $a = @hash_file('sha256', $original);
        $b = @hash_file('sha256', $copy);
        return is_string($a) && is_string($b) && hash_equals($a, $b);
    }

    /**
     * T-08 (ACTIVITY_TRAY.md): one writer API for migration progress — publishes
     * the `migrate_progress` Nchan channel (the shared channel, D9/R7 in
     * EVENT_PUBLISH_OBSERVABILITY.md: migrate-btrfs-to-squashfs.sh publishes here
     * too, so there is only one migration channel and one Manager subscriber)
     * AND mirrors the step into the activity registry as the singleton
     * `storage_migrate` op (type `migrate`). progress>=100 finishes the activity.
     */
    private static function migrateProgress(string $step, int $progress, array $extra = [], ?string $trayStep = null): void {
        \AICliAgents\Services\EventBus::publish('storage.migrate', [], array_merge(
            ['step' => $step, 'progress' => $progress], $extra
        ));
        self::$migrateLastProgress = $progress;
        // The Manager channel keeps the technical step text. The tray gets the plain
        // words of ACTIVITY_TRAY_PLAIN_NOTICES.md (R1/R8); Cancel would stop nothing (R9).
        $trayStep = $trayStep ?? $step;
        if ($progress >= 100) {
            \AICliAgents\Services\ActivityService::finish('storage_migrate', $trayStep);
        } else {
            \AICliAgents\Services\ActivityService::update('storage_migrate', [
                'type' => 'migrate', 'label' => self::$migrateLabel,
                'step' => $trayStep, 'progress' => $progress,
                'meta' => ['cancel' => 'none'],
            ]);
        }
    }
}
