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

    /**
     * #585: the one rule for a wipe target. The type is agent or home. The id is
     * one path-safe name: no slash, no glob character, not "." or "..". An agent
     * id also has to match the agent id shape. Used by the handler AND by
     * nuclearRebuild(), so a direct caller is refused as well.
     */
    public static function isValidWipeTarget($type, $id): bool {
        if (!is_string($type) || !is_string($id)) return false;
        if ($type === 'agent') return (bool)preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/D', $id);
        if ($type === 'home')  return (bool)preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.-]{0,63}$/D', $id);
        return false;
    }

    /**
     * #585: does this layer file belong to EXACTLY this entity? The old glob
     * "{type}_{id}_*" also took the layers of a longer id that starts with the
     * same text plus an underscore (home "a" matched "home_a_b_..."). The part
     * after the id must start with a layer kind (consolidated, delta, vol, v<N>).
     */
    public static function layerFileBelongsTo(string $file, string $type, string $id): bool {
        $name = basename($file);
        $prefix = "{$type}_{$id}_";
        if (strncmp($name, $prefix, strlen($prefix)) !== 0) return false;
        return (bool)preg_match('/^(consolidated|delta|vol\d*|v\d+)([_.]|$)/', substr($name, strlen($prefix)));
    }

    /** @return string[] the layer files of exactly this entity under $persistPath */
    private static function entityLayerFiles(string $persistPath, string $type, string $id): array {
        $files = [];
        foreach ((array)glob(rtrim($persistPath, '/') . "/{$type}_*.sqsh") as $f) {
            if (self::layerFileBelongsTo($f, $type, $id)) $files[] = $f;
        }
        return $files;
    }

    public static function nuclearRebuild($type, $id) {
        if (!self::isValidWipeTarget($type, $id)) {
            LogService::log("Wipe refused: invalid storage type or id.", LogService::LOG_WARN, "StorageMigrationService");
            return false;
        }
        // R-046 (#624): the one resolver, so the wipe measures and clears the folder
        // the shell scripts use when a path is blank in the config.
        $persistPath = ($type === 'home')
            ? StoragePathResolver::homePersistPath('')
            : StoragePathResolver::agentPersistPath();

        $flashOld = 0;
        foreach (self::entityLayerFiles($persistPath, $type, $id) as $f) $flashOld += (int)@filesize($f);
        
        $upperDir = SecretPaths::stateRoot() . "/zram_upper/{$type}s/{$id}/upper";
        $ramOld = 0;
        if (is_dir($upperDir)) {
            $io = shell_exec("du -sm " . escapeshellarg($upperDir) . " 2>/dev/null | cut -f1");
            $ramOld = (int)trim($io);
        }

        LogService::log("Initiating nuclear wipe sequence for $type $id...", LogService::LOG_WARN, "StorageMigrationService");
        
        // 1. Unmount
        // SIDE_BY_SIDE_AGENT_INSTALLS.md Phase 1: route the agent-mount arm through
        // AgentRegistry::agentPath() instead of repeating the literal AGENT_BASE path.
        $mnt = ($type === 'agent') ? AgentRegistry::agentPath($id) : UtilityService::getWorkDir($id) . "/home";
        // #373: name the uppers to wipe BEFORE the unmount, while the kernel
        // still shows the live mount's own upperdir/workdir.
        $wipeDirs = self::rebuildWipeDirs((string)$type, (string)$id, (string)$persistPath, $mnt);
        // #512: a real unmount only. A busy mount stops the wipe: deleting its
        // layers would leave their space held by the kernel until a reboot,
        // and deleting its upper would remove files a session still uses.
        if (!StorageMountService::unmountReal($mnt)) {
            LogService::log("Wipe of $type $id stopped: $mnt is still in use. Close its sessions, then try again.", LogService::LOG_WARN, "StorageMigrationService");
            return false;
        }

        // 2. Wipe Flash
        foreach (self::entityLayerFiles($persistPath, $type, $id) as $f) @unlink($f);

        // 3. Wipe the upper(s): zram base plus, for a home, the live disk upper.
        foreach ($wipeDirs as $dir) {
            // #585: defence in depth. A wipe dir must end in this entity's own
            // name, so no odd input can point rm -rf at a parent folder.
            if (basename($dir) !== (string)$id) continue;
            if (is_dir($dir)) exec("rm -rf " . escapeshellarg($dir));
        }

        // #585: the layers are gone, so the manifest must not still list them
        // (the next boot sweep would report total_loss).
        require_once __DIR__ . '/FileStorage.php';
        FileStorage::dropManifestEntry("$type/$id");

        $flashMB = round($flashOld / 1024 / 1024, 2);
        LogService::log("Successfully wiped all storage for $id. Reclaimed $flashMB MB of Flash space and $ramOld MB of RAM.", LogService::LOG_INFO, "StorageMigrationService");
        
        return true;
    }

    /**
     * #373 (docs/specs/HOME_STORAGE_LIFECYCLE.md "the live mount wins"): the
     * directories a nuclear rebuild must remove. Always the zram base of the
     * entity. For a home also the live mount's own upperdir and workdir (they
     * sit on disk when the home runs with a disk upper) and the policy disk
     * upper/work for the persist path. A live path is used only when it has the
     * shape of an upper/work dir of THIS entity, so an odd mount option can
     * never make the rebuild delete an unrelated folder. Pure: the mount table
     * and zram base are arguments.
     *
     * @return string[]
     */
    public static function rebuildWipeDirs(
        string $type,
        string $id,
        string $persistPath,
        string $mnt,
        ?string $mounts = null,
        ?string $zramBase = null
    ): array {
        $zramBase ??= StoragePathResolver::zramBase();
        $dirs = [rtrim($zramBase, '/') . "/{$type}s/$id"];
        if ($type !== 'home') return $dirs;
        $safe = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $id) ?: 'unknown';
        if ($mounts === null) {
            $mounts = is_readable('/proc/mounts') ? (string)@file_get_contents('/proc/mounts') : '';
        }
        foreach (['upperdir' => '_upper', 'workdir' => '_work'] as $opt => $dirName) {
            $live = StorageMountService::overlayOptionAt($mounts, rtrim($mnt, '/'), $opt);
            if ($live !== null && preg_match('#/' . $dirName . '/homes/' . preg_quote($safe, '#') . '/?$#', $live)) {
                $dirs[] = rtrim($live, '/');
            }
            if ($persistPath !== '') {
                $dirs[] = rtrim($persistPath, '/') . "/$dirName/homes/$safe";
            }
        }
        return array_values(array_unique($dirs));
    }

    /**
     * Purges all migration artifacts (migrated_legacy_data/, .img.migrated, .migrated folders).
     * D-313: Version-Aware 'Analyze & Rescue' for incorrect SquashFS volumes.
     */
    /**
     * @param array{persist:string,plugin:string,state:string,mount?:callable,unmount?:callable}|null $roots
     *        #916 test seam. Null (every production call) = the configured agent persist path,
     *        the flash root and the state root. A test gives a temp folder for each and may
     *        replace the loop mount and unmount. Outside a unit run a non-null value is refused.
     */
    public static function purgeArtifacts(?array $roots = null) {
        LogService::log("PURGE: Starting 'Analyze & Rescue' for storage artifacts.", LogService::LOG_WARN, "StorageMigrationService");
        if ($roots !== null) {
            if (!class_exists('PHPUnit\\Framework\\TestCase', false)) {
                throw new \RuntimeException('purgeArtifacts(): the roots argument is a test seam and is refused outside a unit run.');
            }
            $persistPath = rtrim((string)$roots['persist'], '/');
            $pluginBase = rtrim((string)$roots['plugin'], '/');
            $stateBase = rtrim((string)$roots['state'], '/');
        } else {
            $config = ConfigService::getConfig();
            $persistPath = StoragePathResolver::agentPersistPathFromConfig($config);
            $pluginBase = SecretPaths::flashRoot();
            $stateBase = SecretPaths::stateRoot();
        }
        $envPath = "$pluginBase/envs";
        if (!is_dir($envPath)) @mkdir($envPath, 0755, true);

        // 1. Clean up migration artifacts (current + legacy naming conventions)
        $searchPaths = [$persistPath, "$persistPath/persistence", $pluginBase];
        $seen = [];
        foreach ($searchPaths as $path) {
            // A folder INSIDE the configured roots that is a link is not followed: its target is not ours to clean.
            if ($path !== $persistPath && $path !== $pluginBase && is_link($path)) continue;
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
        $mnt = $stateBase . "/mnt/rescue";
        if (!is_dir($mnt)) @mkdir($mnt, 0755, true);

        foreach (glob("$persistPath/home_*.sqsh") as $file) {
            $name = basename($file);
            if (!preg_match('/home_(.*?)_v1_vol1\.sqsh/', $name, $m)) continue;
            $user = $m[1];

            // If it matches a system directory name, it's an artifact from v44 over-eager migration
            $denylist = ['envs', 'persistence', 'pkg-cache', 'test-fixtures', 'includes', 'scripts', 'assets'];
            if (in_array($user, $denylist)) {
                LogService::log("Artifact found: $name. Attempting data rescue...", LogService::LOG_INFO, "StorageMigrationService");
                if (!self::rescueArtifactLayer($file, $mnt, $envPath, $roots['mount'] ?? null, $roots['unmount'] ?? null)) continue;
                LogService::log("Purging artifact: $name", LogService::LOG_INFO, "StorageMigrationService");
                @unlink($file);
            }
        }

        LogService::log("Legacy artifact purge complete.", LogService::LOG_INFO, "StorageMigrationService");
        return true;
    }

    /**
     * R-126 (#662): mount an artifact layer read-only, copy its .json env files out, and unmount.
     * Returns true only when the layer may be deleted. A layer that cannot be mounted (so its data
     * could not be looked at) and a busy mount are kept: the data is never thrown away unseen.
     *
     * Seams (tests): $mount fn(file, mnt): int exit code; $unmount fn(mnt): bool.
     */
    public static function rescueArtifactLayer(string $file, string $mnt, string $envPath, ?callable $mount = null, ?callable $unmount = null): bool
    {
        $name = basename($file);
        if ($mount !== null) {
            $res = $mount($file, $mnt);
        } else {
            $out = []; $res = 1;
            exec("mount -o loop,ro " . escapeshellarg($file) . " " . escapeshellarg($mnt) . " 2>/dev/null", $out, $res);
        }
        if ($res !== 0) {
            LogService::log("Keeping artifact $name: it could not be mounted, so its data was not checked.", LogService::LOG_WARN, "StorageMigrationService");
            return false;
        }
        // Rescue any .json env files back to the raw envs/ directory
        $rescueCount = 0;
        foreach (glob("$mnt/*.json") ?: [] as $json) {
            $dest = $envPath . "/" . basename($json);
            if (!file_exists($dest)) {
                copy($json, $dest);
                $rescueCount++;
            }
        }
        if ($rescueCount > 0) LogService::log("Rescued $rescueCount file(s) from $name to $envPath", LogService::LOG_INFO, "StorageMigrationService");
        // #512: a real unmount only; a busy image is kept, not purged.
        $unmounted = $unmount !== null ? $unmount($mnt) : StorageMountService::unmountReal($mnt);
        return (bool)$unmounted;
    }

    /** Run a command; returns its exit code. $seams['exec'] (fn(cmd): int) stands in for tests. */
    private static function runCmd(string $cmd, array $seams): int
    {
        if (isset($seams['exec'])) return (int)($seams['exec'])($cmd);
        $out = []; $rc = 1;
        exec($cmd . ' 2>&1', $out, $rc);
        return $rc;
    }

    /**
     * Migrates home persistence storage.
     * R-125 (#662): the new path is saved only when the copy ended with exit code 0.
     * $seams (tests): mounted (bool), old (string), exec, save (fn(array): bool).
     */
    public static function migratePersistence($newPath, array $seams = []) {
        if (empty($newPath)) return false;
        if (!($seams['mounted'] ?? StorageMountService::isBackingMountAvailable((string)$newPath))) {
            LogService::log("Refusing home migration: target storage is not mounted: $newPath", LogService::LOG_WARN, "StorageMigrationService");
            return false;
        }
        $oldPath = $seams['old'] ?? StoragePathResolver::homePersistPath('');
        if ($newPath === $oldPath) return true;

        LogService::log("Migrating home persistence from $oldPath to $newPath...", LogService::LOG_INFO, "StorageMigrationService");
        if (!is_dir($newPath)) @mkdir($newPath, 0755, true);
        if (is_dir($oldPath)) {
            $rc = self::runCmd("rsync -a " . escapeshellarg($oldPath . "/") . " " . escapeshellarg($newPath . "/"), $seams);
            if ($rc !== 0) {
                LogService::log("Home migration stopped: rsync ended with exit code $rc. The path was not changed.", LogService::LOG_ERROR, "StorageMigrationService");
                return false;
            }
        }
        $save = $seams['save'] ?? static fn(array $c): bool => (bool)ConfigService::saveConfig($c);
        return $save(['home_storage_path' => $newPath]);
    }

    /**
     * Migrates agent binary storage path.
     * R-125 (#662): the new path is saved only when every layer file was moved.
     * $seams (tests): mounted (bool), old (string), exec, save (fn(array): bool).
     */
    public static function migrateAgentStorage($newPath, array $seams = []) {
        if (empty($newPath)) return false;
        if (!($seams['mounted'] ?? StorageMountService::isBackingMountAvailable((string)$newPath))) {
            LogService::log("Refusing agent migration: target storage is not mounted: $newPath", LogService::LOG_WARN, "StorageMigrationService");
            return false;
        }
        $oldPath = $seams['old'] ?? StoragePathResolver::agentPersistPathFromConfig(ConfigService::getConfig());
        if ($newPath === $oldPath) return true;

        LogService::log("Migrating agent storage from $oldPath to $newPath...", LogService::LOG_INFO, "StorageMigrationService");
        if (!is_dir($newPath)) @mkdir($newPath, 0755, true);

        // Move all SquashFS files to the new location
        if (is_dir($oldPath)) {
            foreach (glob(rtrim($oldPath, '/') . '/*.sqsh') ?: [] as $file) {
                $rc = self::runCmd("mv -- " . escapeshellarg($file) . " " . escapeshellarg(rtrim($newPath, '/') . '/'), $seams);
                if ($rc !== 0) {
                    LogService::log("Agent migration stopped: could not move " . basename($file) . " (exit code $rc). The path was not changed.", LogService::LOG_ERROR, "StorageMigrationService");
                    return false;
                }
            }
        }

        $save = $seams['save'] ?? static fn(array $c): bool => (bool)ConfigService::saveConfig($c);
        return $save(['agent_storage_path' => $newPath]);
    }
}
