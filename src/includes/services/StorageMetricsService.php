<?php
/**
 * <module_context>
 *     <name>StorageMetricsService</name>
 *     <description>Storage utilization and health metrics for AICliAgents.</description>
 *     <dependencies>LogService, ConfigService, StorageMountService, UtilityService, TaskService</dependencies>
 *     <constraints>Under 150 lines. Provides unified status for agents and home directories.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class StorageMetricsService {
    /**
     * Retrieves unified storage metrics for agents, homes, and rootfs.
     */
    /**
     * How many versions of one agent are mounted right now, and what the ones
     * that are no longer current are costing.
     *
     * docs/specs/SIDE_BY_SIDE_AGENT_INSTALLS.md Phase 3, R6. Returns keys that
     * are always present, so the UI never has to distinguish "no overlap" from
     * "this plugin version does not report it":
     *
     *   generations         how many versions of this agent are mounted
     *   generations_max     the ceiling past which an upgrade waits instead
     *   superseded_mb       the writable layers held by versions no longer current
     *   at_generation_limit true when the next upgrade of this agent will wait
     *
     * @return array<string,mixed>
     */
    public static function agentGenerationPressure(string $agentId, string $persistPath): array {
        $mounted = AgentRegistry::mountedGenerations($agentId);
        $live = self::liveGenerationId($agentId);
        $supersededMb = 0;
        foreach ($mounted as $gen) {
            if ($gen === $live) continue;
            // The layer a generation actually owns is recorded when it is bound;
            // never re-derived here, because a generation that adopted the
            // pre-Phase-3 shared layer records a different path and a guess
            // would report the wrong number (or zero).
            $upper = self::generationUpperPath($agentId, $gen);
            if ($upper !== null && is_dir($upper)) {
                $supersededMb += self::getDirSize($upper);
            }
        }
        $count = count($mounted);
        return [
            'generations'         => $count,
            'generations_max'     => AgentRegistry::MAX_CONCURRENT_GENERATIONS,
            'superseded_mb'       => $supersededMb,
            'at_generation_limit' => !AgentRegistry::canAddGeneration($count),
        ];
    }

    /** The generation id the agent's stable name currently points at, or null. */
    private static function liveGenerationId(string $agentId): ?string {
        $stable = AgentRegistry::agentPath($agentId);
        if (!is_link($stable)) return null;
        $target = @readlink($stable);
        if (!is_string($target)) return null;
        $prefix = ".versions/$agentId/";
        return strncmp($target, $prefix, strlen($prefix)) === 0
            ? substr($target, strlen($prefix))
            : null;
    }

    /**
     * The writable layer one generation owns, read from the record the mount
     * pipeline writes beside the generation directories (resolve_paths.sh
     * agent_generation_state_set). Null when there is no record.
     */
    private static function generationUpperPath(string $agentId, string $generationId): ?string {
        $file = AgentRegistry::agentBase() . "/.versions/$agentId/.state/"
              . preg_replace('/[^A-Za-z0-9._-]/', '', $generationId) . '.env';
        if (!is_file($file)) return null;
        foreach (explode("\n", (string)@file_get_contents($file)) as $line) {
            if (strncmp($line, 'upper=', 6) === 0) {
                $val = trim(substr($line, 6));
                return $val !== '' ? $val : null;
            }
        }
        return null;
    }

    public static function getStatus() {
        $config = ConfigService::getConfig();
        // F8 (WP#1332): resolve the persist paths through StoragePathResolver — the
        // SAME chain the engine uses — so the UI globs the same directory the engine
        // bakes to (incl. the empty-config `/persistence` fallback + normalize). The
        // old ad-hoc `home_storage_path ?? agent_storage_path` lacked the fallback, so
        // on default config the UI counted layers in a different dir than the engine
        // and the effective-backend verdict could diverge.
        require_once __DIR__ . '/StoragePathResolver.php';
        $agentPath = StoragePathResolver::agentPersistPath();
        $homePath  = StoragePathResolver::homePersistPath('');

        $agents = [];
        $homes = [];
        $artifacts = [];

        // 1. Discover Agents from Agent Storage Path.
        // Matches legacy (vol1, delta_<epoch>) and canonical formats including the
        // Phase-5 seq-keyed form: agent_<id>_<kind>_<seq10>_<dt>.sqsh (WP D01-D03).
        // The (?:\d+_)? group makes the 10-digit sequence before the timestamp optional
        // so both the current seq-keyed names AND legacy no-seq names are matched.
        foreach (glob("$agentPath/agent_*.sqsh") as $file) {
            $id = self::agentIdFromLayerFilename(basename($file));
            if ($id !== null) {
                if (isset($agents[$id])) continue;
                // SIDE_BY_SIDE_AGENT_INSTALLS.md Phase 1 (2026-09-09): the old
                // StorageMountService::AGENT_MNT_BASE was a second constant
                // duplicating AgentRegistry::AGENT_BASE's literal — removed,
                // route through the resolver instead.
                $mnt = AgentRegistry::agentPath($id);
                $agents[$id] = self::getEntityStats('agent', $id, $agentPath, $mnt);
                // SIDE_BY_SIDE_AGENT_INSTALLS.md Phase 3, R6 (2026-09-15): an
                // agent can now have a superseded version still mounted for a
                // session that has not been reloaded, and each one costs a
                // writable layer measured at 255-450 MB on this hardware. R6 is
                // explicit that this must be VISIBLE while it is happening —
                // an operator watching flash headroom during a slow-draining
                // overlap should see it here, not discover it as an
                // out-of-space install failure later.
                $agents[$id] += self::agentGenerationPressure($id, $agentPath);
            }
        }

        // 2. Discover Homes from Home Storage Path
        // (Sections 2 and 3 of the old code live in homeUserIds(), so BackupCronService
        // can list the homes without the du/df work of getEntityStats.)
        foreach (self::homeUserIds($homePath) as $user) {
            $homes[$user] = self::getEntityStats('home', $user, $homePath, SecretPaths::stateRoot() . "/work/$user/home");
        }

        // 4. Identify Migration Artifacts (Leftovers)
        // Check migrated_legacy_data/ (current migration output), plus old-style .img.migrated files
        $searchPaths = [$agentPath, $homePath, "$agentPath/persistence", SecretPaths::flashRoot()];
        $seenPaths = [];
        foreach ($searchPaths as $path) {
            if (!is_dir($path)) continue;
            $realPath = realpath($path);
            if (isset($seenPaths[$realPath])) continue;
            $seenPaths[$realPath] = true;

            // Current migration output: migrated_legacy_data/ directory
            $legacyDir = "$path/migrated_legacy_data";
            if (is_dir($legacyDir)) {
                $artifacts[] = [
                    'name' => 'migrated_legacy_data',
                    'path' => $legacyDir,
                    'size_mb' => self::getDirSize($legacyDir),
                    'type' => 'directory'
                ];
            }
            // Old-style: *.img.migrated files
            foreach (glob("$path/*.img.migrated") as $img) {
                $artifacts[] = [
                    'name' => basename($img),
                    'path' => $img,
                    'size_mb' => round(@filesize($img) / 1024 / 1024, 2),
                    'type' => 'image'
                ];
            }
            // Old-style: *.migrated.* directories
            foreach (glob("$path/*.migrated.*", GLOB_ONLYDIR) as $dir) {
                $artifacts[] = [
                    'name' => basename($dir),
                    'path' => $dir,
                    'size_mb' => self::getDirSize($dir),
                    'type' => 'directory'
                ];
            }
        }

        // Epic #1310 Step 3: surface the backend capabilities (the GENUINE device
        // test) per entity via the FileStorage facade — so the UI can render the
        // Bake/Consolidate controls conditionally (wired in Step 6). Computed once
        // per persist path (stable) and merged ADDITIVELY, so the existing status
        // shape is unchanged. This is the first READ consumer routed through the
        // facade (it is no longer dark).
        require_once __DIR__ . '/FileStorage.php';
        // F8 (WP#1332): the device verdict is the RAW backendForPath (memoised per
        // request); the effective caps come from the SINGLE shared invariant helper
        // FileStorage::effectiveBackendCaps (mirrors bash effective_backend_from_facts)
        // — the UI no longer re-implements the layers-stay-flash rule inline, so it
        // cannot gate on a different rule than the engine.
        $homeDev  = FileStorage::backendForPath($homePath)['backend'];
        $agentDev = FileStorage::backendForPath($agentPath)['backend'];
        // Bug #1380: per-entity graduate OFFER = "move the data off a USB flash
        // drive". Offered ONLY when the persist device is a GENUINE USB-flash
        // device (v1 verdict flash AND the v2 probe wear axis is wear_sensitive
        // — an internal-boot ZFS/SSD is wear_normal and never qualifies) AND a
        // qualifying durable non-array non-flash target exists to move TO. The
        // qualifying-target check is per-KIND (same for every entity of a kind),
        // so it is evaluated ONCE here (one enumerateTargets per kind, memoised
        // probes). probeTarget is memoised per path per request.
        require_once __DIR__ . '/StorageTargetService.php';
        $config = (isset($config) && is_array($config)) ? $config : [];
        $homeProbe  = FileStorage::probeTarget($homePath);
        $agentProbe = FileStorage::probeTarget($agentPath);
        $homeWear  = (string)($homeProbe['wear'] ?? 'wear_normal');
        $agentWear = (string)($agentProbe['wear'] ?? 'wear_normal');
        // Forgejo #251: `backend=flash` describes the layered engine, not where
        // its writable upper lives. An existing layered entity on a ZFS pool
        // remains a flash-backend entity while its upper is durable on disk.
        $homeUpperMode  = (string)($homeProbe['upper_mode'] ?? ($homeDev === 'flash' ? 'zram' : 'disk'));
        $agentUpperMode = (string)($agentProbe['upper_mode'] ?? ($agentDev === 'flash' ? 'zram' : 'disk'));
        // Only bother enumerating targets when the device side could possibly
        // qualify (genuine USB flash) — saves the target probe sweep on the
        // common durable-box case where the offer is moot anyway.
        $homeFlashStick  = ($homeDev === 'flash'  && $homeWear  === 'wear_sensitive');
        $agentFlashStick = ($agentDev === 'flash' && $agentWear === 'wear_sensitive');
        $homeHasTarget  = $homeFlashStick
            && count(StorageTargetService::qualifyingGraduateTargets('home', $config)) > 0;
        $agentHasTarget = $agentFlashStick
            && count(StorageTargetService::qualifyingGraduateTargets('agent', $config)) > 0;
        // HOME_BACKUP.md R10: `last_backup` per user — the same object
        // StorageHandler::backupStatusFor() returns, so the Storage tab and
        // the backup_status AJAX action can never disagree.
        require_once __DIR__ . '/../handlers/StorageHandler.php';
        require_once __DIR__ . '/HomeBackupSettingsService.php';
        // HOME_STORAGE_CARD_JOB_STATE.md (#247): `job` — the supervisor job that is
        // queued, running or deferred for this home, or null. The card shows the
        // state and locks its buttons from THIS field, so every device agrees.
        require_once __DIR__ . '/SupervisorService.php';
        $_jobs = SupervisorService::listJobs(true);
        foreach ($homes as $_u => $_st)  {
            $_hasLayers = (($_st['layers'] ?? 0) > 0);
            // HOME_BACKUP.md #287: the Home card's backup strip needs the home's
            // OWN settings summary and its last run (ok/failed) — from the same
            // backupStatusFor() object as last_backup, so the surfaces agree.
            $_bk = \AICliAgents\Handlers\StorageHandler::backupStatusFor((string)$_u);
            $_bkSummary = null;
            try {
                // peek(): the 30 s status sweep never writes a settings file.
                $_bkS = HomeBackupSettingsService::peek((string)$_u);
                $_bkSummary = ['configured' => $_bkS['target'] !== '', 'target' => $_bkS['target'],
                               'schedule' => $_bkS['schedule'], 'quiesce' => $_bkS['quiesce'],
                               // HOME_BACKUP.md 2026-09-24 follow-up: where the home's
                               // earlier snapshots are when it has no folder of its own.
                               'earlier_target' => (string)($_bk['earlier_target'] ?? '')];
            } catch (\Throwable $e) {
                $_bkSummary = null;
            }
            $_caps = FileStorage::effectiveBackendCaps($homeDev, $_hasLayers);
            $homes[$_u] = array_merge($_st, $_caps,
                ['upper_mode' => $homeUpperMode,
                 'percent' => self::upperPercent((int)($_st['dirty_mb'] ?? 0), $homeUpperMode)],
                ['can_graduate' => FileStorage::canGraduate($homeDev, $homeWear, $_hasLayers, $homeHasTarget)],
                // #496 (CONVERT_LAYERED_TO_PLAIN_FOLDERS.md R9): a layered home on a
                // non-USB disk can become plain folders in place. The Storage card
                // reads THIS field; the tidy-up notice and the banner use the same rule.
                ['can_convert' => FileStorage::canConvert($homeDev, $homeWear, $_caps['backend'], $_hasLayers, 'home'),
                 'persist_path' => $homePath],
                // R22: the rollback copy of the old layers, or null. The card reads THIS field.
                ['graduated' => self::graduatedForHome((string)$_u)],
                ['last_backup' => $_bk['last_backup'] ?? null,
                 'last_backup_run' => $_bk['last_run'] ?? null,
                 'backup' => $_bkSummary],
                ['job' => SupervisorService::activeJobFor('home/' . (string)$_u, $_jobs)]);
        }
        foreach ($agents as $_a => $_st) {
            $_hasLayers = (($_st['layers'] ?? 0) > 0);
            $agents[$_a] = array_merge($_st, FileStorage::effectiveBackendCaps($agentDev, $_hasLayers),
                ['upper_mode' => $agentUpperMode,
                 'percent' => self::upperPercent((int)($_st['dirty_mb'] ?? 0), $agentUpperMode)],
                ['can_graduate' => FileStorage::canGraduate($agentDev, $agentWear, $_hasLayers, $agentHasTarget)],
                // #496 R11: agents keep their layers, so this is always false.
                ['can_convert' => false]);
        }

        // Rootfs (RAM Disk) stats
        $rootDf = self::parseRootDf((string)shell_exec("df -m / | tail -n 1"));
        $rootTotal = $rootDf['total_mb'];
        $rootUsage = $rootDf['used_mb'];

        return [
            'migration_in_progress' => StorageMountService::isMigrationInProgress(),
            'migration_progress' => self::getMigrationProgress($agentPath),
            'agents' => $agents,
            'homes'  => $homes,
            'artifacts' => $artifacts,
            'home_available' => StorageMountService::isPathAvailable($homePath),
            'agents_available' => StorageMountService::isPathAvailable($agentPath),
            'emergency_mode' => StorageMountService::isEmergencyMode(),
            // S-05 (#1352): durable degraded flag — survives the reboot that wipes
            // the tmpfs emergency_mode flag, so the UI can surface a prior-session
            // failure the boot reconciliation could not auto-clear.
            'degraded' => StorageMountService::degradedState(),
            'rootfs' => [
                'total_mb' => $rootTotal,
                'used_mb'  => $rootUsage,
                'percent'  => $rootTotal > 0 ? round(($rootUsage / $rootTotal) * 100) : 0
            ]
        ];
    }

    /**
     * Pure: the size and use of the root file system from the last line of `df -m /`.
     * The line holds the device name, then size, used, available, use% and the mount point.
     * The four values are counted from the END of the line, so the device name may hold
     * digits and spaces without shifting them. A line with a missing column (for example a
     * device "rootfs2" and no "available" column), empty output, or a use% of "-" gives zero
     * for both values.
     *
     * @return array{total_mb:int,used_mb:int}
     */
    public static function parseRootDf(string $dfLine): array
    {
        $zero = ['total_mb' => 0, 'used_mb' => 0];
        $fields = preg_split('/\s+/', trim($dfLine), -1, PREG_SPLIT_NO_EMPTY);
        if ($fields === false) return $zero;
        // Fields from the end: mount point, use%, available, used, size.
        $n = count($fields);
        if ($n < 5) return $zero;
        [$size, $used, $avail, $pct] = [$fields[$n - 5], $fields[$n - 4], $fields[$n - 3], $fields[$n - 2]];
        if (preg_match('/^\d+$/', $size) !== 1 || preg_match('/^\d+$/', $used) !== 1
            || preg_match('/^\d+$/', $avail) !== 1 || preg_match('/^\d+%$/', $pct) !== 1) {
            return $zero;
        }
        return ['total_mb' => (int)$size, 'used_mb' => (int)$used];
    }

    /**
     * Calculates migration progress by comparing legacy images vs new .sqsh files.
     */
    private static function getMigrationProgress($persistPath) {
        $total = 0;
        $done = 0;

        $searchPaths = [$persistPath, "$persistPath/persistence", SecretPaths::flashRoot()];
        $images = [];
        
        foreach ($searchPaths as $path) {
            if (!is_dir($path)) continue;
            foreach (glob("$path/*.img*") as $img) {
                if (str_contains($img, '.sqsh')) continue;
                $name = basename($img);
                // Extract base name without .migrated or .img
                $base = preg_replace('/(\.img|\.migrated).*/', '', $name);
                $images[$base] = $img;
            }
        }
        
        $total = count($images);
        foreach ($images as $base => $path) {
            // Check if a corresponding .sqsh exists (legacy *_vol1 or canonical *_consolidated_<dt>).
            $pattern = str_replace('home_', '', $base);
            $pattern = str_replace('aicli-agents', 'agent_*', $pattern);
            $hasLegacy     = count(glob("$persistPath/*{$pattern}*_vol1.sqsh")) > 0;
            $hasCanonical  = count(glob("$persistPath/*{$pattern}*_consolidated_*.sqsh")) > 0;
            if ($hasLegacy || $hasCanonical) {
                $done++;
            }
        }
        
        return [
            'total' => $total,
            'done' => $done,
            'percent' => $total > 0 ? round(($done / $total) * 100) : 100
        ];
    }

    /**
     * Resolves the REAL upperdir for a home or agent entity, for dirty-RAM
     * reporting (Forgejo #232). Reuses the same canonical resolvers the mount
     * pipeline itself uses, rather than re-deriving or guessing a path, so
     * this can never disagree with where the entity actually writes:
     *   - home: StorageMountService::resolveHomeUpperPath (its own
     *     flash-vs-disk backend branch).
     *   - agent: the CURRENT generation's recorded upper (generationUpperPath
     *     against liveGenerationId) for a versioned agent; null for a
     *     legacy/non-versioned one, which falls back to the plain ZRAM guess
     *     below (its upper has always lived there).
     * Never throws; returns null (caller then falls back / reports 0) rather
     * than let a resolver failure abort the whole metrics response.
     */
    private static function resolveUpperPathForMetrics($type, $id): ?string {
        if ($type === 'home') {
            require_once __DIR__ . '/StorageMountService.php';
            return StorageMountService::resolveHomeUpperPath((string)$id);
        }
        if ($type === 'agent') {
            $live = self::liveGenerationId((string)$id);
            if ($live !== null) {
                $upper = self::generationUpperPath((string)$id, $live);
                if ($upper !== null) return $upper;
            }
        }
        $legacy = rtrim(SecretPaths::stateRoot(), '/') . "/zram_upper/{$type}s/{$id}/upper";
        return is_dir($legacy) ? $legacy : null;
    }

    /**
     * Retrieves metrics for a specific SquashFS-backed entity.
     * Focuses on ZRAM usage (Dirty data) vs Physical footprint.
     */
    private static function getEntityStats($type, $id, $persistPath, $mnt) {
        $mounted = StorageMountService::isMounted($mnt);
        $dirtyMB = 0;
        $zramTotal = 4096; // 4GB Virtual ZRAM limit

        // D-309 / Forgejo #232: measure the upperdir this entity is ACTUALLY
        // using, not a hardcoded ZRAM-only guess. A disk-backed home/agent
        // (StorageMountService::resolveHomeUpperPath's non-flash branch, or a
        // versioned agent generation bound to a disk upper) writes its upper
        // under $persistPath/_upper/..., which the old hardcoded
        // "/tmp/.../zram_upper/..." path never matched — is_dir() silently
        // failed, $dirtyMB stayed 0, and the Storage card showed "Synced"
        // with zero visibility into however much real dirty data sat there
        // (confirmed live 2026-09-16: 1206 MB unreported for home/root).
        $upperDir = self::resolveUpperPathForMetrics($type, $id);
        if ($upperDir !== null && is_dir($upperDir)) {
            $dirtyMB = self::getDirSize($upperDir);
        }

        // Calculate Physical Size (sum of all .sqsh volumes for this entity)
        // D-405: Order layers by stack position — newest delta first, base last.
        // Timestamp is a STRING so legacy epoch ("1776318800") and canonical dt
        // ("20260506T143022Z") sort correctly together via lex compare.
        $physicalSize = 0;
        $layerFiles = [];
        require_once __DIR__ . '/LayerManifestService.php';
        foreach (LayerManifestService::layerFilesOfEntity((string)$persistPath, (string)$type, (string)$id) as $sqsh) {   // R-002: exact entity
            $fsize = @filesize($sqsh);
            $physicalSize += $fsize;
            $bname = basename($sqsh);
            [$ts, $seq] = self::layerOrderKey($bname);
            $layerFiles[] = [
                'path' => $sqsh,
                'name' => $bname,
                'size_bytes' => $fsize,
                'timestamp' => $ts,
                'seq' => $seq,
                'is_delta' => strpos($bname, 'delta') !== false
            ];
        }
        // Sort: newest delta first, base volumes last
        usort($layerFiles, function($a, $b) {
            if ($a['is_delta'] && !$b['is_delta']) return -1;
            if (!$a['is_delta'] && $b['is_delta']) return 1;
            $c = strcmp($b['timestamp'], $a['timestamp']);
            return $c !== 0 ? $c : ($b['seq'] <=> $a['seq']);
        });

        // WP #271 follow-up: surface the deferred-consolidation status so the
        // UI can render a "Awaiting idle" badge while the queue is non-empty
        // for this entity.
        $pendingSince = StorageMountService::getConsolidatePendingSince($type, $id);

        return [
            'mounted'              => $mounted,
            'mount_point'          => $mnt,
            'dirty_mb'             => $dirtyMB,
            'physical_mb'          => round($physicalSize / 1024 / 1024, 2),
            'layers'               => count($layerFiles),
            'layer_files'          => $layerFiles,
            'percent'              => min(100, round(($dirtyMB / 1024) * 100)),
            'consolidate_pending'  => ($pendingSince !== null),
            'consolidate_pending_since' => $pendingSince,
        ];
    }

    /**
     * R-134: the ids of the homes the Storage tab draws a card for, without any
     * du/df/mount work. Homes of Unraid users that have layers or a work folder,
     * then any other work folder (a home with no layer yet; '.migrated' folders skipped).
     * $workBase is a test seam.
     *
     * @return string[]
     */
    public static function homeUserIds(?string $homePath = null, ?string $workBase = null): array
    {
        require_once __DIR__ . '/LayerManifestService.php';
        $homePath = $homePath ?? StoragePathResolver::homePersistPath('');
        $workBase = $workBase ?? (rtrim(SecretPaths::stateRoot(), '/') . '/work');
        $ids = [];
        foreach (UtilityService::getUnraidUsers() as $user) {
            $files = LayerManifestService::layerFilesOfEntity($homePath, 'home', (string)$user);   // R-002: exact entity
            if (count($files) > 0 || is_dir(UtilityService::getWorkDir($user))) {
                $ids[(string)$user] = true;
            }
        }
        if (is_dir($workBase)) {
            foreach (glob("$workBase/*", GLOB_ONLYDIR) ?: [] as $dir) {
                $user = basename($dir);
                if (!isset($ids[$user]) && !str_contains($user, '.migrated')) {
                    $ids[$user] = true;
                }
            }
        }
        return array_map('strval', array_keys($ids));
    }

    /** Return the agent id encoded by a supported persisted layer filename. */
    public static function agentIdFromLayerFilename(string $filename): ?string
    {
        $agentKindAlt = '(?:v\d+_vol\d+|vol\d+|delta_\d+|delta_(?:\d+_)?\d{8}T\d{6}Z|consolidated_(?:\d+_)?\d{8}T\d{6}Z)';
        if (preg_match("/^agent_(.*?)_{$agentKindAlt}\.sqsh\$/", basename($filename), $match) !== 1) {
            return null;
        }
        return $match[1];
    }

    /**
     * R-131: the sort key of one layer file name: [timestamp string, sequence].
     * The current name {type}_{id}_{kind}_{seq10}_{dt}.sqsh (LayerManifestService::parseLayerName)
     * gives the dt and the sequence. The timestamp is a STRING so a legacy epoch
     * ("1776318800") and a canonical dt ("20260506T143022Z") sort together by lex compare.
     *
     * @return array{0: string, 1: int}
     */
    public static function layerOrderKey(string $bname): array
    {
        require_once __DIR__ . '/LayerManifestService.php';
        if (LayerManifestService::parseLayerName($bname) !== null
            && preg_match('/_(\d{10})_(\d{8}T\d{6}Z)\.sqsh$/', $bname, $m) === 1) {
            return [$m[2], (int)$m[1]];
        }
        if (preg_match('/_(?:delta|consolidated)_(\d{8}T\d{6}Z)/', $bname, $m)) return [$m[1], 0];
        if (preg_match('/_delta_(\d{10,})/', $bname, $m)) return [$m[1], 0];
        if (preg_match('/_v(\d{10,})_/', $bname, $m)) return [$m[1], 0];
        return ['00000000T000000Z', 0];
    }

    /**
     * Helper to calculate directory size in MB.
     */
    private static function getDirSize($path) {
        $io = shell_exec("du -sm " . escapeshellarg($path) . " 2>/dev/null | cut -f1");
        return (int)trim($io);
    }

    /**
     * CONVERT_LAYERED_TO_PLAIN_FOLDERS.md R22: the rollback copy of a converted home, or null.
     * The home card shows its size and its removal date, and offers "Remove old layers".
     * @param array{persist?:string,retention_days?:int,du?:callable,locked?:list<string>} $seams tests
     * @return array{key:string,bytes:int,expires_at:int}|null
     */
    public static function graduatedForHome(string $user, array $seams = []): ?array
    {
        require_once __DIR__ . '/BootSpaceService.php';
        $persist = (string)($seams['persist'] ?? BootSpaceService::persistPath());
        if (isset($seams['retention_days'])) {
            $days = (int)$seams['retention_days'];
        } else {
            $raw = trim((string)(ConfigService::getConfig()['graduated_retention_days'] ?? '14'));
            $days = preg_match('/^\d+$/', $raw) === 1 ? (int)$raw : 14;   // the supervisor also falls back to 14
        }
        try {
            return BootSpaceService::graduatedCopyForHome($persist, $user, $days, $seams);
        } catch (\Throwable $e) {
            return null;   // the card must still render
        }
    }

    /** ZRAM has a real 4 GiB capacity; a disk upper has no honest bar denominator. */
    private static function upperPercent(int $upperMB, string $upperMode): ?int {
        return $upperMode === 'zram'
            ? min(100, (int)round(($upperMB / 4096) * 100))
            : null;
    }
}
