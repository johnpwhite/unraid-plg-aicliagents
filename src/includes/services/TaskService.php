<?php
/**
 * <module_context>
 *     <name>TaskService</name>
 *     <description>Shared background tasks and synchronization logic.</description>
 *     <dependencies>LogService, ConfigService, StorageMountService, StorageMetricsService</dependencies>
 *     <constraints>Under 150 lines. Handles cross-service tasks like persistHome.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class TaskService {
    /**
     * Initializes a fresh home storage for a user.
     * SquashFS homes are initialized on first mount if missing.
     */
    public static function initHome($username, $size = "128M") {
        if (empty($username)) {
            return false;
        }
        // empty($username) already catches '0' and 0, so we only reach here
        // with a truthy username. No additional normalisation needed.
        return StorageMountService::ensureHomeMounted($username);
    }

    /**
     * Persists a user's home directory (Bakes a SquashFS delta). Blocking.
     */
    public static function persistHome($username, $force = false) {
        if (empty($username)) return false;

        $lockFile = "/tmp/unraid-aicliagents/init_$username.lock";
        $fp = fopen($lockFile, "w+");
        if (!$fp || !flock($fp, LOCK_EX)) {
            if ($fp) fclose($fp);
            LogService::log("Persist request for $username blocked by active lock.", LogService::LOG_WARN, "TaskService");
            return false;
        }

        StorageMountService::ensureHomeMounted($username);
        $res = StorageMountService::commitChanges('home', $username);

        flock($fp, LOCK_UN);
        fclose($fp);

        if ($res === 0) {
            return ['status' => 'ok', 'message' => 'Persistence successful'];
        } elseif ($res === 2) {
            return ['status' => 'busy', 'message' => 'Data persisted to Flash, but ZRAM could not be cleared because a terminal session is still active. Please close all terminal tabs for this user to fully reset RAM usage.'];
        } else {
            return ['status' => 'error', 'message' => 'Persistence (Bake) failed. Check debug.log for details.'];
        }
    }

    /**
     * Non-blocking persist: tries to acquire lock, skips if another bake is in progress.
     * Data is safe in the ZRAM overlay and will be captured by the next persist cycle.
     */
    public static function persistHomeNonBlocking($username) {
        if (empty($username)) return false;

        $lockFile = "/tmp/unraid-aicliagents/init_$username.lock";
        $fp = fopen($lockFile, "w+");
        if (!$fp) return false;

        // LOCK_NB: return immediately if lock is held by another process
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            LogService::log("Non-blocking persist skipped for $username (bake already in progress).", LogService::LOG_DEBUG, "TaskService");
            return ['status' => 'skipped', 'message' => 'Another bake in progress. Data safe in ZRAM.'];
        }

        StorageMountService::ensureHomeMounted($username);
        $res = StorageMountService::commitChanges('home', $username);

        flock($fp, LOCK_UN);
        fclose($fp);

        return ($res === 0 || $res === 2) ? ['status' => 'ok'] : ['status' => 'error'];
    }

}
