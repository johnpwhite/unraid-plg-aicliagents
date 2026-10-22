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
    /** #567: a save that found no change. Neutral, not a success. */
    const NOTHING_TO_SAVE = 'Nothing to save: there were no changes to write.';

    /** #567/#570: the one sentence for a save in the emergency session. */
    const EMERGENCY_NOT_KEPT = 'Not saved: this session runs on the emergency temporary home. Its work is not kept and is lost when storage returns.';

    /**
     * Test seam (#663). A test sets 'emergency' (bool), 'ensureReady' (callable)
     * and 'persist' (callable returning a FileStoragePersistResult) so persistHome
     * runs without a live storage engine. Production never sets it.
     * @var array{emergency?:bool,ensureReady?:callable,persist?:callable}|null
     */
    private static ?array $testSeam = null;

    /**
     * Test only. Pass null to restore the production calls.
     * @param array{emergency?:bool,ensureReady?:callable,persist?:callable}|null $seam
     */
    public static function setTestSeam(?array $seam): void {
        self::$testSeam = $seam;
    }

    /** #567: true while a session runs on the emergency temporary home. */
    public static function inEmergencyMode(): bool {
        if (self::$testSeam !== null && isset(self::$testSeam['emergency'])) {
            return (bool)self::$testSeam['emergency'];
        }
        require_once __DIR__ . '/StorageMountService.php';
        return StorageMountService::isEmergencyMode();
    }

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

        // #567: a session on the emergency temporary home keeps nothing.
        if (self::inEmergencyMode()) {
            return ['status' => 'emergency', 'message' => self::EMERGENCY_NOT_KEPT];
        }

        $lockFile = SecretPaths::homePersistLockPath((string)$username);
        $fp = fopen($lockFile, "w+");
        if (!$fp || !flock($fp, LOCK_EX)) {
            if ($fp) fclose($fp);
            LogService::log("Persist request for $username blocked by active lock.", LogService::LOG_WARN, "TaskService");
            return false;
        }

        if (self::$testSeam !== null && isset(self::$testSeam['persist'])) {
            if (isset(self::$testSeam['ensureReady'])) (self::$testSeam['ensureReady'])("home/$username");
            $result = (self::$testSeam['persist'])("home/$username");
        } else {
            FileStorage::ensureReady("home/$username");   // Epic #1310: facade intent
            $result = FileStorage::persist("home/$username");   // Epic #1310: facade intent (delegates to commitChanges)
        }
        $res = $result->exit;

        flock($fp, LOCK_UN);
        fclose($fp);

        if ($res === 0 && $result->nothingToSave) {
            // #567: nothing was written. Neutral, not green.
            return ['status' => 'nothing_to_save', 'message' => self::NOTHING_TO_SAVE];
        } elseif ($res === 0) {
            return ['status' => 'ok', 'message' => 'Persistence successful'];
        } elseif ($res === 2 && !$result->saved) {
            // #357: nothing was written (the storage lock stayed busy, or a
            // database copy was locked). Never report this as saved.
            return ['status' => 'not_saved', 'message' => self::deferReasonMessage('home', $username, false, (string)$result->deferReason)];
        } elseif ($res === 2) {
            return ['status' => 'busy', 'message' => self::deferReasonMessage('home', $username, true, (string)$result->deferReason)];
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
    private static function deferReasonMessage(string $type, string $id, bool $saved = true, string $fallbackReason = ''): string {
        $sanitisedId = preg_replace('/[^a-zA-Z0-9_-]/', '_', $id);
        $marker = SecretPaths::stateRoot() . "/.bake_defer_reason_{$type}_{$sanitisedId}";
        $reason = '';
        if (is_file($marker)) {
            $raw = @file_get_contents($marker);
            if ($raw !== false) {
                $reason = trim($raw);
            }
            @unlink($marker);
        }
        // The persist call may have read and removed the marker already; its result keeps the reason.
        if ($reason === '') $reason = trim($fallbackReason);
        switch ($reason) {
            // #357: both reasons mean NO layer was written — say so plainly.
            case 'sqlite_backup_deferred':
                return 'Not saved yet: a database in this home was busy (locked, or its backup timed out), so no new layer was written. Your changes are still in RAM; the next save captures them.';
            case 'bake_lock_held':
                return 'Not saved yet: another storage operation on this home (a check or a merge) held its lock for too long, so no new layer was written. Your changes are still in RAM; try Persist again, or the next save captures them.';
            case 'bake_landed_during_consolidate':
                return 'Consolidation deferred: a new delta bake landed mid-flight and took priority. The consolidate will retry automatically on the next cycle.';
            case 'target_not_mounted':
                // S-02 (#1352): UD devices can mount up to ~2 min after array start.
                return 'Storage deferred: the persistence target\'s backing device is not mounted yet. Is the Unassigned Device attached? The operation will retry automatically.';
            case 'fat32_size_cap':
                // S-09 (#1352): exit-4 precondition, surfaced when a caller maps it here.
                return 'Operation refused: the projected layer size is approaching the FAT32 4 GiB per-file limit on the persistence target. Move persistence to a POSIX pool (see the Storage tab).';
            case 'no_space':
                // #338: a bake or agent pack is not started when its result cannot fit.
                // F14 (#599): say it is the boot disk, that nothing is saved yet, and what to do.
                return 'Not saved: there is not enough free space on the boot disk (the storage device) for the new layer, so nothing was written. Your changes are still in RAM and are lost if the server restarts. Free some space (Storage, Boot disk space, shows what uses it), then save again.';
            case 'upper_not_empty':
                // S-10 (#1354): graduate found unflushed writes after its flush+consolidate.
                return 'Graduation deferred: new writes landed during the flush. The migration will retry automatically once the home is idle.';
            case 'graduate_precondition':
                // S-10 (#1354): exit-4 precondition (wrong device/engine, not flash, no layers, or an occupied passthrough dir).
                return 'Graduation refused: this entity does not meet the migration preconditions (a layered entity on a passthrough-capable device). Nothing was changed.';
            case 'upper_on_disk':
                // Forgejo #445: an automatic save of a busy home whose writable layer is on disk writes no layer on purpose.
                return 'Already safe: this home\'s changes live on the storage device, not in RAM, so nothing was lost and no new layer was written while a session is open. A compact layer is written when every session on this home has closed.';
            case 'mount_busy':
            default:
                // R-136: the "persisted to Flash" text is true only when a layer was written.
                // A not-saved result with no marker (or an unknown one) wrote nothing.
                if (!$saved && $reason !== 'mount_busy') {
                    return 'Not saved yet: the save stopped before it wrote a new layer, and no reason was recorded. Your changes are still in RAM; try Persist again, or the next save captures them. The debug log has the detail.';
                }
                return 'Data persisted to Flash, but ZRAM could not be cleared because a terminal session is still active. Please close all terminal tabs for this user to fully reset RAM usage.';
        }
    }

    /**
     * #577: the plain sentence for a refused move to plain folders. $why is the
     * reason code the storage script wrote (storage_ops.sh _gr_precondition_refuse);
     * $path and $first name the folder and the first entry in the way. An unknown
     * code falls back to the generic sentence, so the tray never shows a raw token.
     */
    public static function graduateRefusalHuman(string $why, string $path = '', string $first = ''): string {
        switch ($why) {
            case 'pt_dir_occupied':
                $where = $path !== '' ? "the plain folder $path" : 'the plain folder for this home';
                $what  = $first !== '' ? " (first: $first)" : '';
                return "Refused: $where already has files$what. Move them away, then try again. Nothing was changed.";
            case 'keep_agents_compressed':
                return 'Refused: Agent storage is set to Compressed layers. Set it to Plain folders on the Configuration tab, then try again. Nothing was changed.';
            case 'no_layers_to_convert':
            case 'no_layers':
                return 'Refused: this home has no saved layers to convert. Nothing was changed.';
            case 'boot_disk_too_small':
                return 'Refused: the boot disk does not have room for the plain folders. Free some space, then try again. Nothing was changed.';
            case 'no_work_folder':
                return 'Refused: the working folder of this home is not available. Nothing was changed.';
            case 'engine_not_passthrough':
            case 'device_not_passthrough':
                return 'Refused: the storage device of this home cannot hold plain folders. Nothing was changed.';
            case 'not_flash_entity':
                return 'Refused: this home is already in plain folders. Nothing was changed.';
            default:
                return 'Refused: this home does not meet the move preconditions. Nothing was changed.';
        }
    }

    /**
     * #577: read the refusal detail the storage script left for $entity
     * ("home/root"), and return the plain sentence, or '' when there is no detail.
     * Three lines: reason code, folder, first entry. Stale files (over 24 h) are ignored.
     */
    public static function graduateRefusalText(string $entity): string {
        if (strpos($entity, '/') === false) return '';
        [$type, $id] = explode('/', $entity, 2);
        $type = preg_replace('/[^a-z]/', '', $type);
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $id);
        $file = SecretPaths::stateRoot() . "/.graduate_refusal_{$type}_{$safe}";
        if (!is_file($file)) return '';
        $mtime = @filemtime($file);
        if ($mtime !== false && (time() - $mtime) >= 86400) return '';
        $raw = @file_get_contents($file);
        if ($raw === false) return '';
        $lines = explode("\n", $raw);
        $why = trim($lines[0] ?? '');
        if ($why === '') return '';
        return self::graduateRefusalHuman($why, trim($lines[1] ?? ''), trim($lines[2] ?? ''));
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
                // Forgejo #231: mount_busy for a bake/consolidate fires AFTER the
                // new layer is already written to disk — only the live-mount
                // refresh that frees the old copy's RAM is what's waiting on
                // open sessions. The old wording ("it will $verb automatically")
                // read as if nothing had been saved yet, which isn't true and
                // worried a user who'd just watched the Storage card go green.
                // For $op values other than bake/consolidate (e.g. graduate) no
                // new layer write is implied, so keep the plain "waiting" framing.
                if ($op === 'bake' || $op === 'consolidate') {
                    return "Your data is already saved to disk. Freeing the RAM used by the previous copy is waiting for every open session on this home to close — it happens automatically the moment they do.";
                }
                return "Waiting for this home's open agent session(s) to close — it will $verb automatically the moment you close the session.";
            case 'upper_on_disk':
                return "Your data is already on the storage device: this home's writable layer lives on disk, not in RAM. A compact layer is written when every open session on this home has closed.";
            case 'bake_lock_held':
            case 'bake_landed_during_consolidate':
                return "Waiting for another storage operation on this home to finish — it will $verb automatically on the next cycle.";
            case 'target_not_mounted':
                return 'Waiting for the storage device to mount (is the Unassigned Device attached?) — it will retry automatically.';
            case 'no_space':
                return 'Not saved: there is not enough boot disk space (storage device) for the new layer. Your changes stay in RAM. Free some space on the boot disk, then save again.';
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
        if (self::inEmergencyMode()) {
            return ['status' => 'emergency', 'message' => self::EMERGENCY_NOT_KEPT];
        }

        $lockFile = SecretPaths::homePersistLockPath((string)$username);
        $fp = fopen($lockFile, "w+");
        if (!$fp) return false;

        // LOCK_NB: return immediately if lock is held by another process
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            LogService::log("Non-blocking persist skipped for $username (bake already in progress).", LogService::LOG_DEBUG, "TaskService");
            return ['status' => 'skipped', 'message' => 'Another bake in progress. Data safe in ZRAM.'];
        }

        FileStorage::ensureReady("home/$username");   // Epic #1310: facade intent
        $result = FileStorage::persist("home/$username");   // Epic #1310: facade intent (delegates to commitChanges)

        flock($fp, LOCK_UN);
        fclose($fp);

        // #357: an exit 2 that wrote no layer is "skipped" (data safe in RAM,
        // the next cycle saves it), never "ok".
        if ($result->nothingToSave) return ['status' => 'nothing_to_save', 'message' => self::NOTHING_TO_SAVE];
        if ($result->saved) return ['status' => 'ok'];
        if ($result->exit === 2) return ['status' => 'skipped', 'message' => 'Not saved yet: the storage lock was busy. Data safe in RAM; the next save captures it.'];
        return ['status' => 'error'];
    }

}
