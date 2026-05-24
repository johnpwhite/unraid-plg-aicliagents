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
            return ['status' => 'busy', 'message' => self::deferReasonMessage('home', $username)];
        } else {
            return ['status' => 'error', 'message' => 'Persistence (Bake) failed. Check debug.log for details.'];
        }
    }

    /**
     * Reads the defer-reason marker that commit_stack.sh / consolidate_layers.sh
     * write before any exit-2 path (WP #1078). Returns a user-facing message
     * matched to the actual deferral cause. Unlinks the marker after read so
     * stale reasons don't bleed across runs. Falls back to the historic "mount
     * busy" wording when the marker is missing or has an unknown value — that
     * was the original meaning of exit-2 before WP #935 added defer-eligible
     * SQLite failures, and remains the most common case (active terminal).
     */
    private static function deferReasonMessage(string $type, string $id): string {
        $sanitisedId = preg_replace('/[^a-zA-Z0-9_-]/', '_', $id);
        $marker = "/tmp/unraid-aicliagents/.bake_defer_reason_{$type}_{$sanitisedId}";
        $reason = '';
        if (is_file($marker)) {
            $raw = @file_get_contents($marker);
            if ($raw !== false) {
                $reason = trim($raw);
            }
            @unlink($marker);
        }
        switch ($reason) {
            case 'sqlite_backup_deferred':
                return 'Data persisted to Flash, but a SQLite database backup deferred (DB locked or backup timed out). The bake will retry automatically on the next cycle.';
            case 'bake_lock_held':
                return 'Data persisted to Flash, but a concurrent bake is in flight. The current operation will retry automatically on the next cycle.';
            case 'bake_landed_during_consolidate':
                return 'Consolidation deferred: a new delta bake landed mid-flight and took priority. The consolidate will retry automatically on the next cycle.';
            case 'mount_busy':
            default:
                return 'Data persisted to Flash, but ZRAM could not be cleared because a terminal session is still active. Please close all terminal tabs for this user to fully reset RAM usage.';
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
