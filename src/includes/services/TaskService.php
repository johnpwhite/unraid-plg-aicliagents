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
        return FileStorage::ensureReady("home/$username")->ok;   // Epic #1310: facade intent
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

        FileStorage::ensureReady("home/$username");   // Epic #1310: facade intent
        $res = FileStorage::persist("home/$username")->exit;   // Epic #1310: facade intent (delegates to commitChanges)

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
            case 'target_not_mounted':
                // S-02 (#1352): UD devices can mount up to ~2 min after array start.
                return 'Storage deferred: the persistence target\'s backing device is not mounted yet. Is the Unassigned Device attached? The operation will retry automatically.';
            case 'fat32_size_cap':
                // S-09 (#1352): exit-4 precondition, surfaced when a caller maps it here.
                return 'Operation refused: the projected layer size is approaching the FAT32 4 GiB per-file limit on the persistence target. Move persistence to a POSIX pool (see the Storage tab).';
            case 'upper_not_empty':
                // S-10 (#1354): graduate found unflushed writes after its flush+consolidate.
                return 'Graduation deferred: new writes landed during the flush. The migration will retry automatically once the home is idle.';
            case 'graduate_precondition':
                // S-10 (#1354): exit-4 precondition (wrong device/engine, not flash, no layers, or an occupied passthrough dir).
                return 'Graduation refused: this entity does not meet the migration preconditions (a layered entity on a passthrough-capable device). Nothing was changed.';
            case 'mount_busy':
            default:
                return 'Data persisted to Flash, but ZRAM could not be cleared because a terminal session is still active. Please close all terminal tabs for this user to fully reset RAM usage.';
        }
    }

    /**
     * SINGLE SOURCE for the human-readable "why is this deferred" string, keyed
     * by the raw defer-reason token (mount_busy, busy_cooldown, …) rather than a
     * marker file — so the activity tray, queued toasts, and the bash task
     * status all say the SAME plain-English thing instead of leaking jargon like
     * "deferred (mount_busy)". $op tailors the phrasing (consolidate vs bake vs
     * graduate). Confirmed cause for the common case (#1381 live evidence): an
     * active agent session is running in the home, so home_mount_in_use() trips
     * on its live-ttyd-with-AICLI_HOME scan and the live unmount/remount is held
     * off until the session closes (the supervisor auto-retries — not a lock,
     * not a reboot).
     */
    public static function deferReasonHuman(string $reason, string $op = ''): string {
        $verb = $op === 'consolidate' ? 'consolidate'
              : ($op === 'graduate' ? 'move this home' : 'continue');
        switch ($reason) {
            case 'mount_busy':
            case 'busy_cooldown':
                return "Waiting for this home's open agent session(s) to close — it will $verb automatically the moment you close the session.";
            case 'bake_lock_held':
            case 'bake_landed_during_consolidate':
                return "Waiting for another storage operation on this home to finish — it will $verb automatically on the next cycle.";
            case 'target_not_mounted':
                return 'Waiting for the storage device to mount (is the Unassigned Device attached?) — it will retry automatically.';
            case 'upper_not_empty':
                return 'Flushing pending writes first — it will continue automatically once this home is idle.';
            case 'sqlite_backup_deferred':
                return 'A database backup deferred (the DB was busy) — it will retry automatically on the next cycle.';
            case 'fat32_size_cap':
                return 'Refused: the layer is approaching the FAT32 4 GiB per-file limit on this device. Move storage to a pool (Storage tab).';
            case 'graduate_precondition':
                return 'Refused: this home does not meet the move preconditions. Nothing was changed.';
            default:
                return $op !== ''
                    ? "Queued — it will $verb on the next storage cycle."
                    : 'Waiting for the storage subsystem — it will continue automatically.';
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

        FileStorage::ensureReady("home/$username");   // Epic #1310: facade intent
        $res = FileStorage::persist("home/$username")->exit;   // Epic #1310: facade intent (delegates to commitChanges)

        flock($fp, LOCK_UN);
        fclose($fp);

        return ($res === 0 || $res === 2) ? ['status' => 'ok'] : ['status' => 'error'];
    }

}
