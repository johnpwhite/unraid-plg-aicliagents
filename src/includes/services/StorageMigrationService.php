<?php
/**
 * <module_context>
 *     <name>StorageMigrationService</name>
 *     <description>Resizing and migration of AICliAgents storage volumes.</description>
 *     <dependencies>LogService, ConfigService, StorageMountService, UtilityService, TaskService</dependencies>
 *     <constraints>Under 150 lines. Delegates complex operations to shell scripts.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class StorageMigrationService {
    /**
     * Consolidates layers for an entity (Agent or Home).
     */
    public static function consolidateEntity($type, $id) {
        LogService::log("Triggering consolidation for $type: $id", LogService::LOG_INFO, "StorageMigrationService");
        return StorageMountService::consolidate($type, $id);
    }

    /**
     * Legacy expand/shrink are no longer needed for SquashFS.
     * These now return true or perform consolidation if appropriate.
     */
    public static function expandStorage($inc = "256M") {
        LogService::log("Expansion requested. SquashFS uses dynamic ZRAM, no expansion needed.", LogService::LOG_INFO, "StorageMigrationService");
        return true;
    }

    public static function expandHomeStorage($user, $inc = "128M") {
        LogService::log("Expansion requested for $user. SquashFS uses dynamic ZRAM.", LogService::LOG_INFO, "StorageMigrationService");
        return true;
    }

    public static function shrinkStorage() {
        return self::consolidateEntity('agent', 'all'); // Placeholder
    }

    public static function shrinkHomeStorage($user) {
        return self::consolidateEntity('home', $user);
    }

    public static function nuclearRebuild($type, $id) {
        $config = ConfigService::getConfig();
        $persistPath = ($type === 'home')
            ? ($config['home_storage_path']  ?? "/boot/config/plugins/unraid-aicliagents")
            : ($config['agent_storage_path'] ?? "/boot/config/plugins/unraid-aicliagents");
        
        $flashOld = 0;
        foreach (glob("$persistPath/{$type}_{$id}_*.sqsh") as $f) $flashOld += filesize($f);
        
        $upperDir = "/tmp/unraid-aicliagents/zram_upper/{$type}s/{$id}/upper";
        $ramOld = 0;
        if (is_dir($upperDir)) {
            $io = shell_exec("du -sm " . escapeshellarg($upperDir) . " 2>/dev/null | cut -f1");
            $ramOld = (int)trim($io);
        }

        LogService::log("Initiating nuclear wipe sequence for $type $id...", LogService::LOG_WARN, "StorageMigrationService");
        
        // 1. Unmount
        $mnt = ($type === 'agent') ? "/usr/local/emhttp/plugins/unraid-aicliagents/agents/$id" : UtilityService::getWorkDir($id) . "/home";
        exec("umount -l " . escapeshellarg($mnt) . " 2>/dev/null");
        
        // 2. Wipe Flash
        foreach (glob("$persistPath/{$type}_{$id}_*.sqsh") as $f) @unlink($f);

        // 3. Wipe ZRAM
        $zramBase = "/tmp/unraid-aicliagents/zram_upper/{$type}s/$id";
        if (is_dir($zramBase)) exec("rm -rf " . escapeshellarg($zramBase));

        $flashMB = round($flashOld / 1024 / 1024, 2);
        LogService::log("Successfully wiped all storage for $id. Reclaimed $flashMB MB of Flash space and $ramOld MB of RAM.", LogService::LOG_INFO, "StorageMigrationService");
        
        return true;
    }

    /**
     * Purges all migration artifacts (migrated_legacy_data/, .img.migrated, .migrated folders).
     * D-313: Version-Aware 'Analyze & Rescue' for incorrect SquashFS volumes.
     */
    public static function purgeArtifacts() {
        LogService::log("PURGE: Starting 'Analyze & Rescue' for storage artifacts.", LogService::LOG_WARN, "StorageMigrationService");
        $config = ConfigService::getConfig();
        $persistPath = $config['agent_storage_path'] ?? "/boot/config/plugins/unraid-aicliagents";
        $pluginBase = "/boot/config/plugins/unraid-aicliagents";
        $envPath = "$pluginBase/envs";
        if (!is_dir($envPath)) @mkdir($envPath, 0755, true);

        // 1. Clean up migration artifacts (current + legacy naming conventions)
        $searchPaths = [$persistPath, "$persistPath/persistence", $pluginBase];
        $seen = [];
        foreach ($searchPaths as $path) {
            if (!is_dir($path)) continue;
            $real = realpath($path);
            if (isset($seen[$real])) continue;
            $seen[$real] = true;

            // Current: migrated_legacy_data/ directory
            $legacyDir = "$path/migrated_legacy_data";
            if (is_dir($legacyDir)) {
                LogService::log("Purging migrated_legacy_data/ in $path", LogService::LOG_INFO, "StorageMigrationService");
                exec("rm -rf " . escapeshellarg($legacyDir));
            }
            // Old-style: .img.migrated files and .migrated.* directories
            exec("rm -f " . escapeshellarg($path) . "/*.img.migrated 2>/dev/null");
            exec("rm -rf " . escapeshellarg($path) . "/*.migrated.* 2>/dev/null");
        }

        // 2. Analyze & Rescue misplaced SquashFS files (v44 artifacts)
        $mnt = "/tmp/unraid-aicliagents/mnt/rescue";
        if (!is_dir($mnt)) @mkdir($mnt, 0755, true);

        foreach (glob("$persistPath/home_*.sqsh") as $file) {
            $name = basename($file);
            if (!preg_match('/home_(.*?)_v1_vol1\.sqsh/', $name, $m)) continue;
            $user = $m[1];

            // If it matches a system directory name, it's an artifact from v44 over-eager migration
            $denylist = ['envs', 'persistence', 'pkg-cache', 'test-fixtures', 'includes', 'scripts', 'assets'];
            if (in_array($user, $denylist)) {
                LogService::log("Artifact found: $name. Attempting data rescue...", LogService::LOG_INFO, "StorageMigrationService");
                exec("mount -o loop,ro " . escapeshellarg($file) . " " . escapeshellarg($mnt) . " 2>/dev/null", $out, $res);
                if ($res === 0) {
                    // Rescue any .json env files back to the raw envs/ directory
                    $rescueCount = 0;
                    foreach (glob("$mnt/*.json") as $json) {
                        $dest = $envPath . "/" . basename($json);
                        if (!file_exists($dest)) {
                            copy($json, $dest);
                            $rescueCount++;
                        }
                    }
                    if ($rescueCount > 0) LogService::log("Rescued $rescueCount file(s) from $name to $envPath", LogService::LOG_INFO, "StorageMigrationService");
                    exec("umount -l " . escapeshellarg($mnt));
                }
                LogService::log("Purging artifact: $name", LogService::LOG_INFO, "StorageMigrationService");
                @unlink($file);
            }
        }
        
        LogService::log("Legacy artifact purge complete.", LogService::LOG_INFO, "StorageMigrationService");
        return true;
    }

    /**
     * Migrates home persistence storage.
     */
    public static function migratePersistence($newPath) {
        if (empty($newPath)) return false;
        if (!StorageMountService::isBackingMountAvailable((string)$newPath)) {
            LogService::log("Refusing home migration: target storage is not mounted: $newPath", LogService::LOG_WARN, "StorageMigrationService");
            return false;
        }
        $config = ConfigService::getConfig();
        $oldPath = $config['home_storage_path'] ?? '/boot/config/plugins/unraid-aicliagents/persistence';
        if ($newPath === $oldPath) return true;

        LogService::log("Migrating home persistence from $oldPath to $newPath...", LogService::LOG_INFO, "StorageMigrationService");
        if (!is_dir($newPath)) @mkdir($newPath, 0755, true);
        if (is_dir($oldPath)) exec("rsync -a " . escapeshellarg($oldPath . "/") . " " . escapeshellarg($newPath . "/"));
        return ConfigService::saveConfig(['home_storage_path' => $newPath]);
    }

    /**
     * Migrates agent binary storage path.
     */
    public static function migrateAgentStorage($newPath) {
        if (empty($newPath)) return false;
        if (!StorageMountService::isBackingMountAvailable((string)$newPath)) {
            LogService::log("Refusing agent migration: target storage is not mounted: $newPath", LogService::LOG_WARN, "StorageMigrationService");
            return false;
        }
        $config = ConfigService::getConfig();
        $oldPath = $config['agent_storage_path'] ?? '/boot/config/plugins/unraid-aicliagents';
        if ($newPath === $oldPath) return true;

        LogService::log("Migrating agent storage from $oldPath to $newPath...", LogService::LOG_INFO, "StorageMigrationService");
        if (!is_dir($newPath)) @mkdir($newPath, 0755, true);
        
        // Move all SquashFS files to the new location
        if (is_dir($oldPath)) {
            exec("mv " . escapeshellarg($oldPath) . "/*.sqsh " . escapeshellarg($newPath) . "/ 2>/dev/null");
        }
        
        return ConfigService::saveConfig(['agent_storage_path' => $newPath]);
    }
}
