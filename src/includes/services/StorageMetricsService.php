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
        $agentPath = $config['agent_storage_path'] ?? "/boot/config/plugins/unraid-aicliagents";
        $homePath = $config['home_storage_path'] ?? $agentPath;

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
