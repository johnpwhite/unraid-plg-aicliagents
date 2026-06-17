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
        // Matches legacy (vol1, delta_<epoch>) and canonical (_delta_<dt>, _consolidated_<dt>) formats.
        $agentKindAlt = '(?:v\d+_vol\d+|vol\d+|delta_\d+|delta_\d{8}T\d{6}Z|consolidated_\d{8}T\d{6}Z)';
        foreach (glob("$agentPath/agent_*.sqsh") as $file) {
            if (preg_match("/^agent_(.*?)_{$agentKindAlt}\.sqsh\$/", basename($file), $m)) {
                $id = $m[1];
                if (isset($agents[$id])) continue;
                $mnt = StorageMountService::AGENT_MNT_BASE . "/$id";
                $agents[$id] = self::getEntityStats('agent', $id, $agentPath, $mnt);
            }
        }

        // 2. Discover Homes from Home Storage Path
        $unraidUsers = UtilityService::getUnraidUsers();
        foreach ($unraidUsers as $user) {
            $pattern = "$homePath/home_{$user}_*.sqsh";
            $files = glob($pattern);
            if (count($files) > 0 || is_dir(UtilityService::getWorkDir($user))) {
                $mnt = "/tmp/unraid-aicliagents/work/$user/home";
                $homes[$user] = self::getEntityStats('home', $user, $homePath, $mnt);
            }
        }

        // 3. Fallback: Check active work directories for homes without deltas yet
        $workBase = "/tmp/unraid-aicliagents/work";
        if (is_dir($workBase)) {
            foreach (glob("$workBase/*", GLOB_ONLYDIR) as $dir) {
                $user = basename($dir);
                if (!isset($homes[$user]) && !str_contains($user, '.migrated')) {
                    $homes[$user] = self::getEntityStats('home', $user, $homePath, "$dir/home");
                }
            }
        }

        // 4. Identify Migration Artifacts (Leftovers)
        // Check migrated_legacy_data/ (current migration output), plus old-style .img.migrated files
        $searchPaths = [$agentPath, $homePath, "$agentPath/persistence", "/boot/config/plugins/unraid-aicliagents"];
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
        $homeWear  = (string)(FileStorage::probeTarget($homePath)['wear'] ?? 'wear_normal');
        $agentWear = (string)(FileStorage::probeTarget($agentPath)['wear'] ?? 'wear_normal');
        // Only bother enumerating targets when the device side could possibly
        // qualify (genuine USB flash) — saves the target probe sweep on the
        // common durable-box case where the offer is moot anyway.
        $homeFlashStick  = ($homeDev === 'flash'  && $homeWear  === 'wear_sensitive');
        $agentFlashStick = ($agentDev === 'flash' && $agentWear === 'wear_sensitive');
        $homeHasTarget  = $homeFlashStick
            && count(StorageTargetService::qualifyingGraduateTargets('home', $config)) > 0;
        $agentHasTarget = $agentFlashStick
            && count(StorageTargetService::qualifyingGraduateTargets('agent', $config)) > 0;
        foreach ($homes as $_u => $_st)  {
            $_hasLayers = (($_st['layers'] ?? 0) > 0);
            $homes[$_u] = array_merge($_st, FileStorage::effectiveBackendCaps($homeDev, $_hasLayers),
                ['can_graduate' => FileStorage::canGraduate($homeDev, $homeWear, $_hasLayers, $homeHasTarget)]);
        }
        foreach ($agents as $_a => $_st) {
            $_hasLayers = (($_st['layers'] ?? 0) > 0);
            $agents[$_a] = array_merge($_st, FileStorage::effectiveBackendCaps($agentDev, $_hasLayers),
                ['can_graduate' => FileStorage::canGraduate($agentDev, $agentWear, $_hasLayers, $agentHasTarget)]);
        }

        // Rootfs (RAM Disk) stats
        $rootUsage = 0;
        $rootTotal = 0;
        $rootOut = shell_exec("df -m / | tail -n 1");
        if (preg_match('/(\d+)\s+(\d+)\s+(\d+)\s+(\d+)%/', $rootOut, $matches)) {
            $rootTotal = (int)$matches[1];
            $rootUsage = (int)$matches[2];
        }
        
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
     * Calculates migration progress by comparing legacy images vs new .sqsh files.
     */
    private static function getMigrationProgress($persistPath) {
        $total = 0;
        $done = 0;

        $searchPaths = [$persistPath, "$persistPath/persistence", "/boot/config/plugins/unraid-aicliagents"];
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
     * Retrieves metrics for a specific SquashFS-backed entity.
     * Focuses on ZRAM usage (Dirty data) vs Physical footprint.
     */
    private static function getEntityStats($type, $id, $persistPath, $mnt) {
        $mounted = StorageMountService::isMounted($mnt);
        $dirtyMB = 0;
        $zramTotal = 4096; // 4GB Virtual ZRAM limit

        // D-309: Calculate 'Dirty' RAM usage (size of the upperdir in ZRAM)
        $upperDir = "/tmp/unraid-aicliagents/zram_upper/{$type}s/{$id}/upper";
        if (is_dir($upperDir)) {
            $dirtyMB = self::getDirSize($upperDir);
        }

        // Calculate Physical Size (sum of all .sqsh volumes for this entity)
        // D-405: Order layers by stack position — newest delta first, base last.
        // Timestamp is a STRING so legacy epoch ("1776318800") and canonical dt
        // ("20260506T143022Z") sort correctly together via lex compare.
        $physicalSize = 0;
        $layerFiles = [];
        foreach (glob("$persistPath/{$type}_{$id}_*.sqsh") as $sqsh) {
            $fsize = @filesize($sqsh);
            $physicalSize += $fsize;
            $bname = basename($sqsh);
            $ts = '00000000T000000Z';
            if (preg_match('/_(?:delta|consolidated)_(\d{8}T\d{6}Z)/', $bname, $m)) {
                $ts = $m[1];
            } elseif (preg_match('/_delta_(\d{10,})/', $bname, $m)) {
                $ts = $m[1];
            } elseif (preg_match('/_v(\d{10,})_/', $bname, $m)) {
                $ts = $m[1];
            }
            $layerFiles[] = [
                'path' => $sqsh,
                'name' => $bname,
                'size_bytes' => $fsize,
                'timestamp' => $ts,
                'is_delta' => strpos($bname, 'delta') !== false
            ];
        }
        // Sort: newest delta first, base volumes last
        usort($layerFiles, function($a, $b) {
            if ($a['is_delta'] && !$b['is_delta']) return -1;
            if (!$a['is_delta'] && $b['is_delta']) return 1;
            return strcmp($b['timestamp'], $a['timestamp']);
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
     * Helper to calculate directory size in MB.
     */
    private static function getDirSize($path) {
        $io = shell_exec("du -sm " . escapeshellarg($path) . " 2>/dev/null | cut -f1");
        return (int)trim($io);
    }
}
