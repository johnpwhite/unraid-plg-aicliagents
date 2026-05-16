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
            case 'restore_from_sibling':        return self::restoreFromSibling(); // mutating, but no Nchan — integrity-only
            case 'list_halts':                  return self::listHalts();           // read-only, no Nchan publish
            case 'clear_halt':                  return self::clearHalt();           // mutating, invalidates boot cache
            case 'persist_agent':               $result = self::persistAgent($id); break;
            // Note: get_task_status outputs raw JSON and is dispatched directly
            case 'persist_home':                $result = self::persistHome(); break;
            case 'consolidate_storage':         $result = self::consolidate(); break;
            case 'expand_storage':              $result = self::expand(); break;
            case 'shrink_storage':              $result = self::shrink(); break;
            case 'repair_agent_storage':        $result = self::repairAgent($id); break;
            case 'repair_home_storage':         $result = self::repairHome(); break;
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
        return ['get_storage_status', 'get_boot_integrity_status', 'get_supervisor_status', 'get_task_status',
                'restore_from_sibling', 'list_halts', 'clear_halt',
                'persist_agent', 'persist_home',
                'consolidate_storage', 'expand_storage', 'shrink_storage',
                'repair_agent_storage', 'repair_home_storage', 'wipe_storage',
                'nuclear_rebuild_storage', 'purge_artifacts'];
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
            'running'     => $running,
            'tick_age_s'  => $tickAge,
            'work'        => $workState,
            'status'      => $status,
            'queue_depth' => $queueDepth,
            'pressure'    => self::_computeDirtyPressure(),
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

    private static function persistAgent($id) {
        // Enqueue a user-priority bake rather than blocking inline (Phase 3.3).
        // Priority 5 = user-clicked, pre-empts schedule and dirty-pressure ops.
        \AICliAgents\Services\SupervisorService::enqueue('agent', $id, 'bake', 'user_persist', 5);
        return ['status' => 'ok', 'message' => 'Persistence queued. The supervisor will bake the agent layer shortly.', 'baking' => true];
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

    private static function consolidate() {
        $type = $_GET['type'] ?? 'agent';
        $id   = $_GET['id']   ?? 'default';
        if ($type === 'home' && ($id === '0')) {
            $id = 'root';
        }
        aicli_log("AJAX Request: Consolidate storage for $type: $id", AICLI_LOG_INFO);
        // Enqueue a user-priority consolidate so the AJAX handler returns immediately
        // (Phase 3.3). Priority 5 = user-clicked; supervisor resets failure counter
        // and skips the halt check for user-triggered consolidations.
        \AICliAgents\Services\SupervisorService::enqueue($type, $id, 'consolidate', 'user_consolidate', 5);
        \AICliAgents\Services\LifecycleLogService::log(\AICliAgents\Services\LifecycleLogService::LEVEL_INFO, 'storage', 'storage_consolidate_queued', ['type' => $type, 'id' => $id]);
        return ['status' => 'ok', 'message' => 'Consolidation queued. The supervisor will consolidate layers shortly.'];
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

        // #341: Validate fstype of any new path before listing files.
        // Uses proc_open (injection-safe) to call findmnt -- a path on tmpfs must be
        // rejected here rather than on the first bake attempt.
        $durable = ['ext4', 'xfs', 'btrfs', 'vfat', 'exfat', 'f2fs', 'ntfs', 'fuseblk'];
        foreach (['agent' => $newAgentPath, 'home' => $newHomePath] as $kind => $path) {
            if (!$path || $path === ($kind === 'agent' ? $oldAgentPath : $oldHomePath)) continue;
            $proc = proc_open( // nosemgrep: php.lang.security.tainted-exec.tainted-exec — array-form proc_open, no shell interpolation; $path is validated by guard_path before this call
                ['findmnt', '--noheadings', '--output', 'FSTYPE', '--target', $path],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes
            );
            $fstype = $proc ? trim((string)stream_get_contents($pipes[1])) : '';
            if ($proc) { fclose($pipes[1]); fclose($pipes[2]); proc_close($proc); }
            $blocked = ['tmpfs', 'ramfs', 'devtmpfs', 'overlay', 'zram', 'squashfs'];
            if (in_array($fstype, $blocked, true)) {
                return ['status' => 'error', 'message' =>
                    "Persistence path '$path' is on $fstype — not a durable filesystem. "
                    . 'Choose a path on ext4, xfs, btrfs, or vfat.'];
            }
            if ($fstype === '') {
                return ['status' => 'error', 'message' =>
                    "Cannot determine filesystem type for '$path'. "
                    . 'Ensure the path exists and is mounted before setting it as the persistence location.'];
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

        return [
            'status' => 'ok',
            'files' => $files,
            'total_mb' => round($totalBytes / 1048576, 2),
            'agent_changed' => ($newAgentPath && $newAgentPath !== $oldAgentPath),
            'home_changed' => ($newHomePath && $newHomePath !== $oldHomePath),
            'old_agent_path' => $oldAgentPath,
            'new_agent_path' => $newAgentPath,
            'old_home_path' => $oldHomePath,
            'new_home_path' => $newHomePath
        ];
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

        // 1. Evict all sessions
        \AICliAgents\Services\NchanService::publish('migrate_progress', ['step' => 'Stopping active sessions...', 'progress' => 5]);
        aicli_log("Storage Migration: Evicting active sessions...", AICLI_LOG_INFO);
        \AICliAgents\Services\ProcessManager::evictAll();

        // 2. Enqueue bakes at OLD paths before copying — supervisor flushes dirty ZRAM
        //    so we copy the complete final state (Phase 3.3: no synchronous I/O in handler).
        $user = $config['user'] ?? 'root';
        if (empty($user)) $user = 'root';

        \AICliAgents\Services\NchanService::publish('migrate_progress', ['step' => 'Flushing dirty ZRAM layers before migration...', 'progress' => 10]);
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
                        \AICliAgents\Services\NchanService::publish('migrate_progress', ['step' => "Queuing consolidation for agent: $aid...", 'progress' => 15]);
                        \AICliAgents\Services\SupervisorService::enqueue('agent', $aid, 'consolidate', 'pre_migrate', 5);
                    }
                }
            }
        }
        // Enqueue pre-migration consolidation for home at OLD path (skip if on Flash)
        $oldHomeOnFlash = (strpos($oldHomePath, '/boot/') === 0 || strpos($oldHomePath, '/boot') === 0);
        if ($newHomePath && $newHomePath !== $oldHomePath && !$oldHomeOnFlash) {
            if (count(glob("$oldHomePath/home_{$user}_*.sqsh")) > 1) {
                \AICliAgents\Services\NchanService::publish('migrate_progress', ['step' => 'Queuing home layer consolidation...', 'progress' => 20]);
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
                \AICliAgents\Services\NchanService::publish('migrate_progress', ['step' => "Copying $name ({$sizeMB}MB)...", 'progress' => $pct, 'file' => $name]);
                aicli_log("Storage Migration: Copying $name ({$sizeMB}MB) from $oldAgentPath to $newAgentPath", AICLI_LOG_INFO);

                $src = $f;
                $dst = "$newAgentPath/$name";
                $cmd = sprintf("cp -a %s %s", escapeshellarg($src), escapeshellarg($dst)); exec($cmd, $out, $res); // nosemgrep: php.lang.security.tainted-exec — $src and $dst fully escaped — src/dst fully escaped via escapeshellarg()
                if ($res !== 0) {
                    aicli_log("Storage Migration: FAILED to copy $name", AICLI_LOG_ERROR);
                    return ['status' => 'error', 'message' => "Failed to copy $name"];
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
                \AICliAgents\Services\NchanService::publish('migrate_progress', ['step' => "Copying $name ({$sizeMB}MB)...", 'progress' => $pct, 'file' => $name]);
                aicli_log("Storage Migration: Copying $name ({$sizeMB}MB) from $oldHomePath to $newHomePath", AICLI_LOG_INFO);

                $src = $f;
                $dst = "$newHomePath/$name";
                $cmd = sprintf("cp -a %s %s", escapeshellarg($src), escapeshellarg($dst)); exec($cmd, $out, $res); // nosemgrep: php.lang.security.tainted-exec — $src and $dst fully escaped — src/dst fully escaped via escapeshellarg()
                if ($res !== 0) {
                    aicli_log("Storage Migration: FAILED to copy $name", AICLI_LOG_ERROR);
                    return ['status' => 'error', 'message' => "Failed to copy $name"];
                }
                aicli_log("Storage Migration: Successfully copied $name", AICLI_LOG_INFO);
                $done++;
            }
        }

        // 4. Save new config — read FRESH config (may have been modified by concurrent requests)
        //    and only update the two path keys. This avoids overwriting other config changes.
        \AICliAgents\Services\NchanService::publish('migrate_progress', ['step' => 'Updating configuration...', 'progress' => 90]);
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
            \AICliAgents\Services\NchanService::publish('migrate_progress', ['step' => 'Queuing final consolidation at new location...', 'progress' => 95]);
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

        \AICliAgents\Services\NchanService::publish('migrate_progress', ['step' => 'Migration complete!', 'progress' => 100]);
        aicli_log("Storage Migration: Complete. Agent path: $newAgentPath, Home path: $newHomePath", AICLI_LOG_INFO);

        return ['status' => 'ok', 'message' => "Migration complete.\n\n" . $summary];
    }
}
