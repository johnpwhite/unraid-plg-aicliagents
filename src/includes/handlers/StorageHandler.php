<?php
/**
 * <module_context>
 *     <name>StorageHandler</name>
 *     <description>Handles storage AJAX actions: persist, consolidate, expand, shrink, repair, wipe, purge.</description>
 *     <dependencies>AICliAgentsManager, StorageMountService, StorageMigrationService, StorageMetricsService</dependencies>
 *     <constraints>Under 150 lines. Each method returns array for JSON encoding.</constraints>
 * </module_context>
 */

namespace AICliAgents\Handlers;

class StorageHandler {

    public static function handle($action, $id) {
        $result = null;
        switch ($action) {
            case 'get_storage_status':          $result = self::getStatus(); break;
            case 'get_boot_integrity_status':   return self::getBootIntegrityStatus(); // read-only, no Nchan publish
            case 'get_supervisor_status':       return self::getSupervisorStatus(); // read-only, no Nchan publish
            case 'get_force_reclaim_state':     return self::getForceReclaimState(); // read-only, no Nchan publish
            case 'storage_job_status':          return self::storageJobStatus();    // S-08: read-only, no Nchan publish
            case 'storage_jobs_active':         return self::storageJobsActive();   // S-08: read-only, no Nchan publish
            case 'enumerate_storage_targets':   return self::enumerateStorageTargets(); // S-11: read-only, no Nchan publish
            case 'restore_from_sibling':        return self::restoreFromSibling(); // mutating, but no Nchan — integrity-only
            case 'list_halts':                  return self::listHalts();           // read-only, no Nchan publish
            case 'clear_halt':                  return self::clearHalt();           // mutating, invalidates boot cache
            case 'auto_heal_agent_install':     return self::autoHealAgentInstall(); // WP #916 — self-heal agent total_loss via reinstall
            case 'persist_agent':               $result = self::persistAgent($id); break;
            // Note: get_task_status outputs raw JSON and is dispatched directly
            case 'persist_home':                $result = self::persistHome(); break;
            case 'consolidate_storage':         $result = self::consolidate(); break;
            case 'get_home_sessions':            return self::getHomeSessions();    // R1: read-only, list open sessions for home consolidate warning
            case 'graduate_targets':            return self::graduateTargets();    // Bug #1380: read-only, qualifying relocation targets
            case 'graduate_storage':            $result = self::graduate(); break; // Bug #1380: relocate off USB flash to a durable target
            case 'expand_storage':              $result = self::expand(); break;
            case 'shrink_storage':              $result = self::shrink(); break;
            case 'repair_agent_storage':        $result = self::repairAgent($id); break;
            case 'repair_home_storage':         $result = self::repairHome(); break;
            case 'delete_home_storage':         $result = self::deleteHome(); break; // Bug #1379
            case 'wipe_storage':                $result = self::wipe(); break;
            case 'nuclear_rebuild_storage':     $result = self::wipe(); break;
            case 'purge_artifacts':             $result = self::purgeArtifacts(); break;
            case 'preflight_migrate':           return self::preflightMigrate();
            case 'execute_migrate':             $result = self::executeMigrate(); break;
            default:                            return null;
        }
        // D-402: After any mutating storage action, publish updated stats via Nchan
        if ($action !== 'get_storage_status') {
            \AICliAgents\Services\NchanService::publish('storage_status', aicli_get_storage_status());
        }
        return $result;
    }

    /** Actions handled by this handler. */
    public static function actions() {
        return ['get_storage_status', 'get_boot_integrity_status', 'get_supervisor_status', 'get_force_reclaim_state', 'get_task_status',
                'storage_job_status', 'storage_jobs_active', 'enumerate_storage_targets',
                'restore_from_sibling', 'list_halts', 'clear_halt', 'auto_heal_agent_install',
                'persist_agent', 'persist_home',
                'consolidate_storage', 'get_home_sessions', 'graduate_targets', 'graduate_storage', 'expand_storage', 'shrink_storage',
                'repair_agent_storage', 'repair_home_storage', 'delete_home_storage',
                'wipe_storage', 'nuclear_rebuild_storage', 'purge_artifacts'];
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

        return [
            'running'           => $running,
            'tick_age_s'        => $tickAge,
            'work'              => $workState,
            'status'            => $status,
            'queue_depth'       => $queueDepth,
            'pressure'          => self::_computeDirtyPressure(),
            'consolidate_fails' => self::_readConsolidateFails(),
        ];
    }

    /**
     * WP #1262: Return the current force-reclaim countdown state for the React UI.
     *
     * Reads /tmp/unraid-aicliagents/supervisor/escalation/home_<safeId>.json
     * written by the bash supervisor when a home overlay is busy AND storage
     * reclaim is needed. The React banner polls this every 5 s and displays a
     * live countdown + "Close now" shortcut.
     *
     * Response shapes:
     *   no file / parse error:  { "status":"ok", "state":"none", "now":<epoch> }
     *   countdown:              { "status":"ok", "now":<epoch>, "state":"countdown",
     *                             "entity":"home/root", "reason":"layers_near_max",
     *                             "started_at":<epoch>, "deadline_epoch":<epoch> }
     *   closing:                { "status":"ok", "now":<epoch>, "state":"closing",
     *                             "entity":"home/root", "reason":"layers_near_max",
     *                             "fired_at":<epoch> }
     */
    private static function getForceReclaimState(): array {
        $config = getAICliConfig();
        $user   = (string)($config['user'] ?? 'root');
        if (empty($user)) $user = 'root';
        $safeId = str_replace(['/', ' '], '_', $user);
        $path   = "/tmp/unraid-aicliagents/supervisor/escalation/home_{$safeId}.json";

        $none = ['status' => 'ok', 'state' => 'none', 'now' => time()];
        if (!file_exists($path)) return $none;
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') return $none;
        $decoded = @json_decode($raw, true);
        if (!is_array($decoded)) return $none;

        return array_merge(['status' => 'ok', 'now' => time()], $decoded);
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
        $countsDir = \AICliAgents\Services\SupervisorService::CONSOLIDATE_FAILS_DIR;
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

        $snapDir = '/boot/config/plugins/unraid-aicliagents/failures';
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

        $zramBase   = '/tmp/unraid-aicliagents/zram_upper';
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
        if (!preg_match('/^[a-zA-Z0-9._-]{1,64}$/', $id)) {
            return ['status' => 'error', 'message' => 'Invalid id format'];
        }
        // Sanitize reason: strip anything that isn't safe for a log line
        $reason = preg_replace('/[^a-zA-Z0-9._\- ]/', '', $reason);
        $reason = $reason !== '' ? $reason : 'user_action';

        $ok = \AICliAgents\Services\HaltService::clearHalt($type, $id, $reason);

        // Invalidate boot integrity cache so next fetch reflects cleared state
        @unlink('/tmp/unraid-aicliagents/.boot_integrity_cache.json');

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
        if (!preg_match('/^[a-zA-Z0-9._-]{1,64}$/', $id)) {
            return ['status' => 'error', 'message' => 'Invalid id format'];
        }

        $result = \AICliAgents\Services\BootIntegrityService::restoreFromSibling($type, $id);

        // Invalidate the boot integrity cache so the next fetch reflects the restored state
        @unlink('/tmp/unraid-aicliagents/.boot_integrity_cache.json');

        $status  = $result['ok'] ? 'ok' : 'error';
        $message = $result['ok']
            ? 'Restored ' . $result['restored'] . ' layer(s) from sibling directory.'
            : 'Restore completed with errors: ' . implode('; ', $result['errors']);

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
        if ($id === '' || !preg_match('/^[a-zA-Z0-9._-]{1,64}$/', $id)) {
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
            @unlink('/tmp/unraid-aicliagents/.boot_integrity_cache.json');
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
            @unlink('/tmp/unraid-aicliagents/.boot_integrity_cache.json');
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
        if ($jobId === '' || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._\-]{0,127}$/', $jobId)) {
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
        \AICliAgents\Services\ActivityService::register("storage_job_$jobId", 'storage', $label, [
            'step' => 'queued', 'progress' => 5, 'meta' => ['jobId' => $jobId],
        ]);
    }

    private static function persistAgent($id) {
        // Enqueue a user-priority bake rather than blocking inline (Phase 3.3).
        // Priority 5 = user-clicked, pre-empts schedule and dirty-pressure ops.
        // S-08 (#1353): tracked — job_id ADDED to the response (back-compat keys
        // unchanged); the job lands in the activity tray as type `storage`.
        $jobId = \AICliAgents\Services\SupervisorService::enqueueJob('agent', $id, 'bake', 'user_persist', 5);
        if ($jobId === null) {
            // Ledger unavailable — preserve the legacy untracked enqueue.
            \AICliAgents\Services\SupervisorService::enqueue('agent', $id, 'bake', 'user_persist', 5);
        }
        self::trackJobActivity($jobId, "Persist agent $id");
        return ['status' => 'ok', 'message' => 'Persistence queued. The supervisor will bake the agent layer shortly.', 'baking' => true, 'job_id' => $jobId];
    }

    private static function persistHome() {
        $config = getAICliConfig();
        $user = (string)($config['user'] ?? '');
        if ($user === '' || $user === '0') $user = 'root';
        $result = aicli_persist_home($user, true);
        if (is_array($result)) return $result;
        return [
            'status' => $result ? 'ok' : 'error',
            'message' => $result ? 'Persistence successful' : 'Persistence (Bake) failed for user ' . $user . '. Check debug.log for details.'
        ];
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
        $id = $_GET['id'] ?? '';
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $id)) {
            return ['status' => 'error', 'message' => 'invalid_id'];
        }
        require_once __DIR__ . '/../services/AgentRegistry.php';
        $registry = \AICliAgents\Services\AgentRegistry::getRegistry();
        $sessions = \AICliAgents\Services\TerminalService::listActiveSessionsForHome($id);
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
        return ['status' => 'ok', 'sessions' => array_values($mapped)];
    }

    private static function consolidate() {
        $type = $_GET['type'] ?? 'agent';
        $id   = $_GET['id']   ?? 'default';
        if ($type === 'home' && ($id === '0')) {
            $id = 'root';
        }
        // Security: reject non-allowlisted type and any id that contains
        // characters outside the safe username/agent-id set (HOME_CONSOLIDATE_CLOSE_RELAUNCH fix).
        if (!in_array($type, ['agent', 'home'], true)) {
            return ['status' => 'error', 'message' => 'invalid_type'];
        }
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $id)) {
            return ['status' => 'error', 'message' => 'invalid_id'];
        }
        aicli_log("AJAX Request: Consolidate storage for $type: $id", AICLI_LOG_INFO);

        // R2.1: home consolidate — write relaunch manifest then mark as in-progress
        // and enqueue immediately. The slow close (forceCloseHome) is deferred to the
        // supervisor's pre-bake close phase (R2.2) so this AJAX handler returns in <1 s.
        // Order: writeHomeManifest → markHomeConsolidating → enqueue → wake.
        // H1 fix: epoch travels in the job ledger entry (via enqueueJob), not a sidecar.
        $epoch = null;
        if ($type === 'home') {
            require_once __DIR__ . '/../services/UpgradeRelaunchService.php';
            $sessions = \AICliAgents\Services\TerminalService::listActiveSessionsForHome($id);
            if (!empty($sessions)) {
                // Write the relaunch manifest BEFORE setting the marker, so the supervisor
                // has a manifest to relaunch from even if the close phase takes a moment.
                // Order: writeHomeManifest → markHomeConsolidating → enqueue → wake.
                $manifest = [];
                foreach ($sessions as $s) {
                    $wp      = (string)($s['path'] ?? '');
                    $agentId = (string)($s['agentId'] ?? '');
                    $manifest[] = [
                        'sessionId'     => (string)($s['id'] ?? ''),
                        'workspacePath' => $wp,
                        'agentId'       => $agentId,
                        // hadResume: the supervisor's forceCloseHome will do the actual
                        // resume capture; this flag signals the relaunch to attempt auto.
                        'hadResume'     => $wp !== '' && $agentId !== '',
                    ];
                }
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
        $jobId = \AICliAgents\Services\SupervisorService::enqueueJob($type, $id, 'consolidate', 'user_consolidate', 5, $epoch);
        if ($jobId === null) {
            \AICliAgents\Services\SupervisorService::enqueue($type, $id, 'consolidate', 'user_consolidate', 5, null, false, $epoch);
        }
        self::trackJobActivity($jobId, "Consolidate $type/$id");

        // R2.1: wake the supervisor so the job starts immediately.
        \AICliAgents\Services\SupervisorService::wake();

        \AICliAgents\Services\LifecycleLogService::log(
            \AICliAgents\Services\LifecycleLogService::LEVEL_INFO,
            'storage', 'storage_consolidate_queued',
            ['type' => $type, 'id' => $id, 'job_id' => $jobId]
        );

        $msg = $type === 'home'
            ? "Consolidation queued. Sessions will be closed and automatically resumed on completion. Watch the activity tray for progress."
            : "Consolidation queued. It runs on the next storage cycle. Watch the activity tray for progress.";
        return ['status' => 'queued', 'job_id' => (string)$jobId, 'message' => $msg];
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
        $oldAgentPath = $config['agent_storage_path'] ?? '/boot/config/plugins/unraid-aicliagents/persistence';
        $oldHomePath  = $config['home_storage_path']  ?? '/boot/config/plugins/unraid-aicliagents/persistence';

        aicli_log("AJAX Request: Move $kind storage off USB flash drive to durable target '$target'", AICLI_LOG_INFO);
        \AICliAgents\Services\LifecycleLogService::log(
            \AICliAgents\Services\LifecycleLogService::LEVEL_INFO,
            'storage', 'storage_graduate_relocate_start',
            ['kind' => $kind, 'target' => $target,
             'from' => ($kind === 'agent' ? $oldAgentPath : $oldHomePath)]
        );

        // Drive the proven relocation via execute_migrate. It reads the new/old
        // paths from $_GET — set ONLY the chosen kind's path; leave the other
        // kind unchanged (same path in = no-op for that kind).
        $_GET['agent_storage_path'] = ($kind === 'agent') ? $target : $oldAgentPath;
        $_GET['home_storage_path']  = ($kind === 'home')  ? $target : $oldHomePath;
        $_GET['old_agent_path']     = $oldAgentPath;
        $_GET['old_home_path']      = $oldHomePath;

        $result = self::executeMigrate();
        \AICliAgents\Services\LifecycleLogService::log(
            \AICliAgents\Services\LifecycleLogService::LEVEL_INFO,
            'storage', 'storage_graduate_relocate_done',
            ['kind' => $kind, 'target' => $target,
             'status' => (string)($result['status'] ?? 'unknown')]
        );
        return $result;
    }

    private static function expand() {
        $type = $_GET['type'] ?? 'agents';
        $inc = ($type === 'agents') ? '256M' : '128M';
        aicli_log("AJAX Request: Expand storage ($type) by $inc", AICLI_LOG_INFO);
        $res = aicli_expand_storage($type, $inc);
        return ['status' => $res ? 'ok' : 'error', 'message' => $res ? '' : 'Expansion failed. Check debug log.'];
    }

    private static function shrink() {
        $type = $_GET['type'] ?? 'agents';
        aicli_log("AJAX Request: Shrink storage ($type)", AICLI_LOG_INFO);
        $res = aicli_shrink_storage($type, null);
        return ['status' => $res ? 'ok' : 'error', 'message' => $res ? '' : 'Shrink failed. Check debug log.'];
    }

    private static function repairAgent($id) {
        aicli_log("AJAX Request: Repair Agent storage for $id", AICLI_LOG_INFO);
        $res = aicli_repair_agent_storage($id);
        return ['status' => $res ? 'ok' : 'error', 'message' => $res ? '' : 'Repair failed. Check debug log.'];
    }

    private static function repairHome() {
        $type = $_GET['type'] ?? 'home';
        $user = $_GET['user'] ?? '';
        if (empty($user) && strpos($type, 'home_') === 0) $user = substr($type, 5);
        if (empty($user)) $user = getAICliConfig()['user'];
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
    private static function deleteHome(): array {
        $id = trim((string)($_REQUEST['id'] ?? ''));

        if ($id === '' || !preg_match('/^[a-zA-Z0-9._-]{1,64}$/', $id)) {
            return ['status' => 'error', 'message' => 'id is required and must be alphanumeric (1-64 chars)'];
        }

        // Extra server-side guard for the primary home users: the client swal
        // already gates these behind typed confirmation, but we enforce it here
        // too so a rogue direct AJAX call cannot silently wipe root's home.
        // A missing 'root_confirmed' flag from the client means the user did not
        // go through the typed-confirmation dialog.
        $rootLike = in_array($id, ['root', 'aicliagent'], true);
        if ($rootLike && empty($_REQUEST['root_confirmed'])) {
            return ['status' => 'error', 'message' => "Deleting '$id' requires explicit root_confirmed confirmation — use the UI dialog."];
        }

        aicli_log("AJAX Request: Delete home storage for home/$id", AICLI_LOG_WARN);

        require_once __DIR__ . '/../services/FileStorage.php';
        $result = \AICliAgents\Services\FileStorage::deleteHomeEntity($id);

        if ($result['ok']) {
            \AICliAgents\Services\ActivityService::register("storage_delete_home_$id", 'storage', "Deleted home storage: $id", [
                'step' => 'completed', 'progress' => 100,
            ]);
            \AICliAgents\Services\ActivityService::finish("storage_delete_home_$id", "Home storage deleted: $id");
        }

        return [
            'status'  => $result['ok'] ? 'ok' : 'error',
            'message' => $result['message'] ?? ($result['ok'] ? 'Home storage deleted.' : 'Delete failed.'),
        ];
    }

    private static function wipe() {
        $type = $_GET['type'] ?? 'agent';
        $id = $_GET['id'] ?? 'default';
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
        $oldAgentPath = $config['agent_storage_path'] ?? '/boot/config/plugins/unraid-aicliagents/persistence';
        $oldHomePath = $config['home_storage_path'] ?? '/boot/config/plugins/unraid-aicliagents/persistence';

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

    /**
     * Execute storage path migration with per-file progress via Nchan.
     */
    private static function executeMigrate() {
        set_time_limit(600);
        $config = getAICliConfig();
        $newAgentPath = $_GET['agent_storage_path'] ?? '';
        $newHomePath = $_GET['home_storage_path'] ?? '';
        // Old paths passed explicitly from JS — config is already saved with new values by this point
        $oldAgentPath = $_GET['old_agent_path'] ?? ($config['agent_storage_path'] ?? '/boot/config/plugins/unraid-aicliagents/persistence');
        $oldHomePath = $_GET['old_home_path'] ?? ($config['home_storage_path'] ?? '/boot/config/plugins/unraid-aicliagents/persistence');

        aicli_log("Storage Migration: Starting path migration...", AICLI_LOG_INFO);

        // Safety: ensure config still has old paths so persist/consolidate target the right location.
        // If a concurrent save already updated the paths, we must revert BEFORE any I/O.
        $liveConfig = getAICliConfig();
        $liveHomePath = $liveConfig['home_storage_path'] ?? '';
        $liveAgentPath = $liveConfig['agent_storage_path'] ?? '';
        $needsRevert = false;
        if ($newHomePath && $liveHomePath !== $oldHomePath && $liveHomePath === $newHomePath) $needsRevert = true;
        if ($newAgentPath && $liveAgentPath !== $oldAgentPath && $liveAgentPath === $newAgentPath) $needsRevert = true;
        if ($needsRevert) {
            aicli_log("Storage Migration: Config already updated to new paths by concurrent save — reverting to old paths.", AICLI_LOG_WARN);
            // Revert only the path keys, preserve everything else from live config
            $liveConfig['home_storage_path'] = $oldHomePath;
            $liveConfig['agent_storage_path'] = $oldAgentPath;
            $content = "";
            foreach ($liveConfig as $key => $value) {
                if ($key === 'csrf_token') continue;
                $content .= "$key=\"" . addslashes($value) . "\"" . PHP_EOL;
            }
            \AICliAgents\Services\AtomicWriteService::write(\AICliAgents\Services\ConfigService::CONFIG_PATH, $content);
            // Re-read to confirm revert took effect
            usleep(100000);
        }

        // Follow-on #1: a settings path change is an EXPLICIT, crash-safe TOLD
        // migration. migratePath() marker-BRACKETS this proven copy flow (it does
        // NOT replace it), so an interrupted copy leaves a resumable
        // .migration_inprogress.json for boot rather than a drifted path the
        // supervisor/boot discovers and halts on — which is what makes the
        // path_drift discovery-halt + op_mount LEGACY_FOUND probing redundant.
        require_once __DIR__ . '/../services/FileStorage.php';
        $__migResult = null;
        $__migWork = function () use (&$__migResult, $config, $oldAgentPath, $newAgentPath, $oldHomePath, $newHomePath) {
        // 1. Evict all sessions
        self::migrateProgress('Stopping active sessions...', 5);
        // R3 (CAPTURE_RESUME_ALL_CLOSE_PATHS): a storage-path migration is an
        // orderly, user-initiated action — time is available, so run the FULL
        // clean capture (quiesce + scrape, authoritative resume id) for every
        // live session BEFORE the hard evict, so chat continuity survives the
        // migration. Generous-but-bounded budget; never throws (shutdown-safe).
        aicli_log("Storage Migration: Capturing resume ids before evict (R3)...", AICLI_LOG_INFO);
        \AICliAgents\Services\TerminalService::captureResumeForShutdown(null, 60);
        aicli_log("Storage Migration: Evicting active sessions...", AICLI_LOG_INFO);
        \AICliAgents\Services\ProcessManager::evictAll();

        // 2. Enqueue bakes at OLD paths before copying — supervisor flushes dirty ZRAM
        //    so we copy the complete final state (Phase 3.3: no synchronous I/O in handler).
        $user = $config['user'] ?? 'root';
        if (empty($user)) $user = 'root';

        self::migrateProgress('Flushing dirty ZRAM layers before migration...', 10);
        aicli_log("Storage Migration: Enqueuing pre-migration bakes via supervisor...", AICLI_LOG_INFO);
        \AICliAgents\Services\SupervisorService::enqueue('home', $user, 'bake', 'pre_migrate', 5);

        // Enqueue pre-migration consolidations at OLD path (skip if on Flash)
        $oldAgentOnFlash = (strpos($oldAgentPath, '/boot/') === 0 || strpos($oldAgentPath, '/boot') === 0);
        if ($newAgentPath && $newAgentPath !== $oldAgentPath && !$oldAgentOnFlash) {
            $seenAgents = [];
            foreach (glob("$oldAgentPath/agent_*.sqsh") as $sqsh) {
                if (preg_match('/agent_(.*?)_(v\d+|delta)/', basename($sqsh), $m)) {
                    $aid = $m[1];
                    if (isset($seenAgents[$aid])) continue;
                    $seenAgents[$aid] = true;
                    if (count(glob("$oldAgentPath/agent_{$aid}_*.sqsh")) > 1) {
                        self::migrateProgress("Queuing consolidation for agent: $aid...", 15);
                        \AICliAgents\Services\SupervisorService::enqueue('agent', $aid, 'consolidate', 'pre_migrate', 5);
                    }
                }
            }
        }
        // Enqueue pre-migration consolidation for home at OLD path (skip if on Flash)
        $oldHomeOnFlash = (strpos($oldHomePath, '/boot/') === 0 || strpos($oldHomePath, '/boot') === 0);
        if ($newHomePath && $newHomePath !== $oldHomePath && !$oldHomeOnFlash) {
            if (count(glob("$oldHomePath/home_{$user}_*.sqsh")) > 1) {
                self::migrateProgress('Queuing home layer consolidation...', 20);
                \AICliAgents\Services\SupervisorService::enqueue('home', $user, 'consolidate', 'pre_migrate', 5);
            }
        }

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
                self::migrateProgress("Copying $name ({$sizeMB}MB)...", $pct, ['file' => $name]);
                aicli_log("Storage Migration: Copying $name ({$sizeMB}MB) from $oldAgentPath to $newAgentPath", AICLI_LOG_INFO);

                $src = $f;
                $dst = "$newAgentPath/$name";
                $cmd = sprintf("cp -a %s %s", escapeshellarg($src), escapeshellarg($dst)); exec($cmd, $out, $res); // nosemgrep: php.lang.security.tainted-exec — $src and $dst fully escaped — src/dst fully escaped via escapeshellarg()
                if ($res !== 0) {
                    aicli_log("Storage Migration: FAILED to copy $name", AICLI_LOG_ERROR);
                    $__migResult = ['status' => 'error', 'message' => "Failed to copy $name"];
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
                self::migrateProgress("Copying $name ({$sizeMB}MB)...", $pct, ['file' => $name]);
                aicli_log("Storage Migration: Copying $name ({$sizeMB}MB) from $oldHomePath to $newHomePath", AICLI_LOG_INFO);

                $src = $f;
                $dst = "$newHomePath/$name";
                $cmd = sprintf("cp -a %s %s", escapeshellarg($src), escapeshellarg($dst)); exec($cmd, $out, $res); // nosemgrep: php.lang.security.tainted-exec — $src and $dst fully escaped — src/dst fully escaped via escapeshellarg()
                if ($res !== 0) {
                    aicli_log("Storage Migration: FAILED to copy $name", AICLI_LOG_ERROR);
                    $__migResult = ['status' => 'error', 'message' => "Failed to copy $name"];
                    return false;
                }
                aicli_log("Storage Migration: Successfully copied $name", AICLI_LOG_INFO);
                $done++;
            }
        }

        // 4. Save new config — read FRESH config (may have been modified by concurrent requests)
        //    and only update the two path keys. This avoids overwriting other config changes.
        self::migrateProgress('Updating configuration...', 90);
        aicli_log("Storage Migration: Updating configuration with new paths...", AICLI_LOG_INFO);

        $freshConfig = getAICliConfig();
        if ($newAgentPath && $newAgentPath !== $oldAgentPath) $freshConfig['agent_storage_path'] = $newAgentPath;
        if ($newHomePath && $newHomePath !== $oldHomePath) $freshConfig['home_storage_path'] = $newHomePath;

        $content = "";
        foreach ($freshConfig as $key => $value) {
            if ($key === 'csrf_token') continue;
            $content .= "$key=\"" . addslashes($value) . "\"" . PHP_EOL;
        }
        \AICliAgents\Services\AtomicWriteService::write(\AICliAgents\Services\ConfigService::CONFIG_PATH, $content);

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
            self::migrateProgress('Queuing final consolidation at new location...', 95);
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
                if (file_exists($newFile) && filesize($newFile) > 0) {
                    @unlink($oldFile);
                    $cleanedUp++;
                } else {
                    aicli_log("Storage Migration: Keeping $name at old path (not confirmed at new path)", AICLI_LOG_WARN);
                }
            }
        }
        if ($newHomePath && $newHomePath !== $oldHomePath) {
            foreach (glob("$oldHomePath/home_*.sqsh") as $oldFile) {
                $name = basename($oldFile);
                $newFile = "$newHomePath/$name";
                if (file_exists($newFile) && filesize($newFile) > 0) {
                    @unlink($oldFile);
                    $cleanedUp++;
                } else {
                    aicli_log("Storage Migration: Keeping $name at old path (not confirmed at new path)", AICLI_LOG_WARN);
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

        self::migrateProgress('Migration complete!', 100);
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
            \AICliAgents\Services\ActivityService::fail('storage_migrate', (string)$__ret['message'], null, [
                'type' => 'migrate', 'label' => 'Storage migration',
            ]);
        }
        return $__ret;
    }

    /**
     * T-08 (ACTIVITY_TRAY.md): one writer API for migration progress — publishes
     * the legacy `migrate_progress` Nchan channel (kept for the existing manager
     * overlay) AND mirrors the step into the activity registry as the singleton
     * `storage_migrate` op (type `migrate`). progress>=100 finishes the activity.
     */
    private static function migrateProgress(string $step, int $progress, array $extra = []): void {
        \AICliAgents\Services\NchanService::publish('migrate_progress', array_merge(
            ['step' => $step, 'progress' => $progress], $extra
        ));
        if ($progress >= 100) {
            \AICliAgents\Services\ActivityService::finish('storage_migrate', $step);
        } else {
            \AICliAgents\Services\ActivityService::update('storage_migrate', [
                'type' => 'migrate', 'label' => 'Storage migration',
                'step' => $step, 'progress' => $progress,
            ]);
        }
    }
}
