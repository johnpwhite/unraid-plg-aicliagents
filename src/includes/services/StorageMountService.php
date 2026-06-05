<?php
/**
 * <module_context>
 *     <name>StorageMountService</name>
 *     <description>Mounting and lifecycle management for AICliAgents storage.</description>
 *     <dependencies>LogService, ConfigService</dependencies>
 *     <constraints>Under 150 lines. Manages SquashFS + OverlayFS stacks.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class StorageMountService {
    const AGENT_MNT_BASE = "/usr/local/emhttp/plugins/unraid-aicliagents/agents";
    const MIGRATION_LOCK = "/tmp/unraid-aicliagents/migration.lock";
    const EMERGENCY_FLAG = "/tmp/unraid-aicliagents/.emergency_mode";
    const EMERGENCY_HOME = "/tmp/unraid-aicliagents/emergency_home";

    public static function isMigrationInProgress() {
        return file_exists(self::MIGRATION_LOCK);
    }

    public static function isEmergencyMode() {
        return file_exists(self::EMERGENCY_FLAG);
    }

    /** Runtime check: is this path usable right now? */
    public static function isPathAvailable(string $path): bool {
        if (empty($path)) return false;
        // For /mnt/user/ paths, the directory can exist on tmpfs even when the array is stopped.
        // mkdir/writes succeed but data goes to RAM and is lost. Check if shfs is actually mounted.
        if (preg_match('#^/mnt/user0?(/|$)#', $path)) {
            $mounts = @file_get_contents('/proc/mounts') ?: '';
            if (strpos($mounts, 'shfs /mnt/user') === false) return false;
        }
        // For /mnt/disk* paths (individual array disks), check if the specific disk is mounted
        if (preg_match('#^/mnt/(disk\d+)(/|$)#', $path, $m)) {
            $mounts = $mounts ?? (@file_get_contents('/proc/mounts') ?: '');
            if (strpos($mounts, " /mnt/{$m[1]} ") === false) return false;
        }
        return is_dir($path) && is_readable($path);
    }

    /**
     * Classify a path by storage type. Delegates to classify-path.sh (single source of truth).
     * Returns: 'flash' | 'array' | 'pool:<name>' | 'unassigned' | 'ram' | 'unknown'
     */
    public static function classifyPath(string $path): string {
        if (empty($path)) return 'unknown';
        $script = "/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/storage/classify-path.sh";
        if (!file_exists($script)) {
            // Fallback: basic classification without disks.ini pool detection
            if (strpos($path, '/boot/') === 0 || $path === '/boot') return 'flash';
            if (strpos($path, '/tmp/') === 0 || $path === '/tmp') return 'ram';
            if (preg_match('#^/mnt/(user0?|disk\d+)(/|$)#', $path)) return 'array';
            if (preg_match('#^/mnt/(disks|remotes)(/|$)#', $path)) return 'unassigned';
            return 'unknown';
        }
        $result = trim((string)shell_exec("bash " . escapeshellarg($script) . " " . escapeshellarg($path) . " 2>/dev/null"));
        return !empty($result) ? $result : 'unknown';
    }

    /** Does this path depend on the Unraid array or a pool? */
    public static function isArrayDependent(string $path): bool {
        $class = self::classifyPath($path);
        return ($class === 'array' || strpos($class, 'pool:') === 0);
    }

    /** Legacy stubs. OverlayFS is always writable via ZRAM. */
    public static function lock() { return true; }
    public static function unlock() { return true; }

    /**
     * Ensures the agent binary storage is mounted for a specific agent.
     *
     * Mounts are only considered healthy if the registered binary path is actually
     * readable through the mount. Overlay mounts can end up in a "phantom" state
     * where /proc/mounts shows them as active but the SquashFS lowerdir has been
     * unmounted/replaced (e.g., after an agent upgrade swaps in a new .sqsh). In
     * that state only the ZRAM upper-layer files are visible — the binary is
     * gone and launches fail with MODULE_NOT_FOUND. We detect this, lazy-unmount
     * the phantom, and fall through to rebuild the stack cleanly.
     */

    /**
     * Bug #1065: mount every installed agent's overlay. Called from
     * events/disks_mounted (at array start) and InitService boot-marker so
     * the agent binaries are available to ANY shell on the host immediately
     * after reboot — not just to terminals opened through the plugin's UI.
     *
     * Without this, the forum-reported failure is:
     *   bash: /usr/local/emhttp/plugins/unraid-aicliagents/agents/<id>/node_modules/.bin/<cli>: No such file or directory
     * because agent overlays were previously lazy-mounted only from
     * TerminalService::startTerminal — the Unraid host terminal never
     * triggered a mount, so the agent appeared "gone" until the user
     * opened a webterm.
     *
     * Idempotent — ensureAgentMounted short-circuits on healthy mounts via
     * its fast-path check, so calling on every boot is cheap.
     *
     * Returns ['mounted' => [...ids...], 'failed' => [...ids...]].
     */
    public static function mountAllInstalledAgents(): array {
        $mounted = [];
        $failed = [];
        $registry = [];
        try {
            $registry = AgentRegistry::getRegistry();
        } catch (\Throwable $e) {
            LogService::log("mountAllInstalledAgents: registry load failed: " . $e->getMessage(), LogService::LOG_WARN, "StorageMountService");
            return ['mounted' => [], 'failed' => []];
        }
        foreach ($registry as $id => $agent) {
            if ($id === 'terminal') continue;
            if (empty($agent['is_installed'])) continue;
            try {
                if (self::ensureAgentMounted($id)) {
                    $mounted[] = $id;
                } else {
                    $failed[] = $id;
                }
            } catch (\Throwable $e) {
                $failed[] = $id;
                LogService::log("mountAllInstalledAgents: $id failed: " . $e->getMessage(), LogService::LOG_WARN, "StorageMountService");
            }
        }
        LogService::log("Boot agent-mount sweep (Bug #1065): mounted=" . count($mounted) . " [" . implode(",", $mounted) . "] failed=" . count($failed) . " [" . implode(",", $failed) . "]", LogService::LOG_INFO, "StorageMountService");
        return ['mounted' => $mounted, 'failed' => $failed];
    }

    public static function ensureAgentMounted($agentId) {
        if (self::isMigrationInProgress()) return false;

        $mnt = self::AGENT_MNT_BASE . "/$agentId";

        if (self::isMounted($mnt)) {
            if (self::isAgentMountHealthy($agentId)) return true;
            LogService::log("Stale agent mount detected for '$agentId' (binary missing under healthy-looking overlay). Unmounting to rebuild.", LogService::LOG_WARN, "StorageMountService");
            // nosemgrep: php.lang.security.exec-use.exec-use
            @shell_exec("umount -l " . escapeshellarg($mnt) . " 2>&1");
            // fall through to remount via mount_stack.sh below
        }

        $persistPath = StoragePathResolver::agentPersistPath();

        if (!self::isPathAvailable($persistPath)) {
            LogService::log("Agent mount skipped: storage path $persistPath is not accessible.", LogService::LOG_WARN, "StorageMountService");
            return false;
        }

        LogService::log("Mounting Agent Stack: $agentId", LogService::LOG_INFO, "StorageMountService");

        // Phase 5: route through the storagectl dispatcher (op_mount) instead of
        // the mount_stack.sh shim. Exit code is unchanged (0 ok / non-0 fail).
        $script = "/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/storage/storagectl.sh";
        // nosemgrep: php.lang.security.exec-use.exec-use
        exec("bash " . escapeshellarg($script) . " mount --type agent --id " . escapeshellarg($agentId) . " --persist " . escapeshellarg($persistPath) . " 2>&1", $out, $res);

        if ($res !== 0) {
            LogService::log("Mount script FAILED for agent $agentId: " . implode("\n", $out), LogService::LOG_ERROR, "StorageMountService");
        }

        return ($res === 0);
    }

    /**
     * Ensures the user home storage is mounted.
     *
     * Mirrors the agent-mount health-check pattern: verifies the path is
     * genuinely an overlay mount (not a phantom proc entry), and serializes
     * concurrent callers with a per-user flock so two simultaneous PHP
     * requests cannot both invoke mount_stack.sh.
     */
    public static function ensureHomeMounted($user) {
        if (self::isMigrationInProgress()) return false;

        $workDir = UtilityService::getWorkDir($user);
        $mnt = "$workDir/home";

        // Bug #1054 self-heal: when the OverlayFS upperdir has the wrong
        // owner (mounted by an older plugin version, or pre-upgrade state),
        // OverlayFS caches the upper's metadata at mount time -- chowning
        // the underlying upper does NOT propagate to the merged view, so
        // writes from the agent user keep failing with EACCES even though
        // the underlying inode now shows the correct owner. The helper
        // chowns the upper + work (covers root-owned subdirs from copy_up,
        // e.g. .aicli/ written by PHP-as-root) and FORCES an unmount when
        // a wrong-owner upper is detected, so the mount step below rebuilds
        // the kernel overlay state with mount_stack.sh's OWNER chown intact.
        $forcedUnmount = self::ensureHomeUpperOwnership($user, $mnt);

        // Emergency mode: home is a symlink to the temp RAM dir — treat as mounted
        if (is_link($mnt) && self::isEmergencyMode()) return true;

        // Fast path: already a healthy overlay mount
        if (!$forcedUnmount && self::isMounted($mnt) && self::isHomeMountHealthy($user)) return true;

        // Serialize concurrent mounts for the same user with an advisory lock.
        // Losers block until the winner finishes, then re-check before mounting.
        $safeUser = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $user) ?: 'unknown';
        $lockFile = "/tmp/unraid-aicliagents/home_mount_{$safeUser}.lock";
        $lock = @fopen($lockFile, 'c');
        if ($lock !== false) {
            flock($lock, LOCK_EX);
            if (self::isMounted($mnt) && self::isHomeMountHealthy($user)) {
                flock($lock, LOCK_UN);
                fclose($lock);
                return true;
            }
        }

        $persistPath = StoragePathResolver::homePersistPath($user);

        if (!self::isPathAvailable($persistPath)) {
            LogService::log("Home mount skipped: storage path $persistPath is not accessible.", LogService::LOG_WARN, "StorageMountService");
            if ($lock !== false) { flock($lock, LOCK_UN); fclose($lock); }
            return false;
        }

        // Phantom mount: stale /proc/mounts entry but not a healthy overlay.
        // Lazy-unmount to clear it, then reassemble the stack cleanly.
        if (self::isMounted($mnt)) {
            LogService::log("Stale home mount detected for '$user' (not a healthy overlay). Unmounting to rebuild.", LogService::LOG_WARN, "StorageMountService");
            // nosemgrep: php.lang.security.exec-use.exec-use
            @shell_exec("umount -l " . escapeshellarg($mnt) . " 2>&1");
        }

        LogService::log("Mounting Home Stack for $user", LogService::LOG_INFO, "StorageMountService");

        // Phase 5: route through the storagectl dispatcher (op_mount) instead of
        // the mount_stack.sh shim. Exit code unchanged.
        $script = "/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/storage/storagectl.sh";
        // Bug #1054: pass $user as --owner so op_mount chowns the OverlayFS
        // upperdir to the agent user -- otherwise the home overlay mounts but is
        // effectively read-only for non-root agents.
        // nosemgrep: php.lang.security.exec-use.exec-use
        exec("bash " . escapeshellarg($script) . " mount --type home --id " . escapeshellarg($user) . " --persist " . escapeshellarg($persistPath) . " --owner " . escapeshellarg($user) . " 2>&1", $out, $res);

        if ($res !== 0) {
            LogService::log("Mount script FAILED for home $user: " . implode("\n", $out), LogService::LOG_ERROR, "StorageMountService");
        }

        if ($lock !== false) { flock($lock, LOCK_UN); fclose($lock); }
        return ($res === 0);
    }

    /**
     * Bug #1054: detect an OverlayFS home upperdir whose owner does NOT
     * match the agent user, chown it recursively (covers root-owned subdirs
     * like .aicli/ that PHP-as-root copy_up'd into the upper), and FORCE
     * an unmount so the next mount step rebuilds the kernel overlay state
     * via mount_stack.sh -- which then applies the OWNER chown at the
     * correct moment for OverlayFS to honour the new owner on the merged
     * view. Chowning alone is insufficient because OverlayFS caches upper
     * metadata at mount time and ignores subsequent owner changes.
     *
     * Returns true if an unmount was forced (caller must skip the fast-path
     * mount check and let the mount step below rebuild). Returns false when
     * no action was needed (correct owner already, or user is root).
     */
    private static function ensureHomeUpperOwnership(string $user, string $mnt): bool {
        if ($user === '' || $user === 'root') return false;
        if (!function_exists('posix_getpwnam')) return false;
        $pw = @posix_getpwnam($user);
        if (!is_array($pw)) return false;
        $upper = self::resolveHomeUpperPath($user);
        if ($upper === null || !is_dir($upper)) return false;
        $stat = @stat($upper);
        if (!is_array($stat) || (int)$stat['uid'] === (int)$pw['uid']) return false;

        LogService::log("Home upper for $user owned by uid={$stat['uid']} (expected {$pw['uid']}) -- chowning + forcing unmount per Bug #1054; mount_stack.sh will rebuild with correct owner", LogService::LOG_INFO, "StorageMountService");
        // nosemgrep: php.lang.security.exec-use.exec-use
        @shell_exec("chown -R " . escapeshellarg($user) . " " . escapeshellarg($upper));
        $work = preg_replace('#/upper$#', '/work', $upper);
        if (is_string($work) && is_dir($work)) {
            // nosemgrep: php.lang.security.exec-use.exec-use
            @shell_exec("chown -R " . escapeshellarg($user) . " " . escapeshellarg($work));
        }

        if (self::isMounted($mnt)) {
            // nosemgrep: php.lang.security.exec-use.exec-use
            @shell_exec("umount -l " . escapeshellarg($mnt));
        }
        return true;
    }

    /**
     * Resolves the OverlayFS upperdir path for a home overlay. Mirrors
     * mount_stack.sh fstype branching (vfat -> ZRAM, else -> persist disk).
     */
    private static function resolveHomeUpperPath(string $user): ?string {
        $persistPath = StoragePathResolver::homePersistPath($user);
        if (empty($persistPath)) return null;
        $cmd = "findmnt --noheadings --output FSTYPE --target " . escapeshellarg($persistPath);
        // nosemgrep: php.lang.security.exec-use.exec-use
        $fstype = trim((string) @shell_exec($cmd));
        $safeUser = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $user) ?: 'unknown';
        if ($fstype === 'vfat' || $fstype === '') {
            return "/tmp/unraid-aicliagents/zram_upper/homes/$safeUser/upper";
        }
        return rtrim($persistPath, '/') . "/_upper/homes/$safeUser";
    }

    /**
     * Verifies the home overlay mount is genuinely an OverlayFS, not a phantom
     * proc entry. A healthy home mount reads "overlay <mnt> overlay ..." in /proc/mounts.
     */
    public static function isHomeMountHealthy(string $user): bool {
        $mnt = rtrim(UtilityService::getWorkDir($user) . "/home", '/');
        $mounts = file_exists('/proc/mounts') ? (string)file_get_contents('/proc/mounts') : '';
        return (bool)preg_match("#^overlay\s+" . preg_quote($mnt, '#') . "\s+overlay\b#m", $mounts);
    }

    /**
     * Forcefully unmounts a path.
     */
    public static function unmount($path) {
        if (empty($path)) return false;
        $path = rtrim($path, '/');
        if (!self::isMounted($path)) return true;
        
        LogService::log("Unmounting $path...", LogService::LOG_DEBUG, "StorageMountService");
        exec("umount -l " . escapeshellarg($path) . " 2>&1", $out, $res);
        return ($res === 0);
    }

    /**
     * Checks if a generic path is mounted.
     */
    public static function isMounted($path) {
        if (empty($path)) return false;
        $path = rtrim($path, '/');
        $mounts = file_exists('/proc/mounts') ? file_get_contents('/proc/mounts') : '';
        // D-324: Exact path match to prevent matching /agents when checking /agents/gh-copilot
        return (preg_match("#\s" . preg_quote($path) . "\s#", $mounts) === 1);
    }

    /**
     * Verifies an agent's overlay mount actually exposes the registered binary.
     * Used by ensureAgentMounted() to detect "phantom mount" states where the
     * overlay is present but its SquashFS lowerdir has been unmounted — only
     * ZRAM upper-layer files are visible and the binary is missing.
     */
    public static function isAgentMountHealthy(string $agentId): bool {
        $registry = function_exists('getAICliAgentsRegistry') ? getAICliAgentsRegistry() : [];
        $bin = $registry[$agentId]['binary'] ?? '';
        $fallback = $registry[$agentId]['binary_fallback'] ?? '';
        if (empty($bin) && empty($fallback)) return true; // nothing to check
        if ($bin && is_file($bin)) return true;
        if ($fallback && is_file($fallback)) return true;
        return false;
    }

    /**
     * Commits changes from ZRAM to SquashFS.
     *
     * For $type === 'home': delta bake via commit_stack.sh + post-bake threshold
     * auto-consolidate (the historic behaviour — home is a delta-stack).
     *
     * For $type === 'agent' (WP #748 J, 2026-05-13): routes directly to
     * consolidate(), which bakes the merged mount view (the just-installed
     * package) as a single fresh `_consolidated_` layer, atomically replaces
     * the layer set, and remounts with a single lowerdir. One layer per agent,
     * no delta accumulation, no later consolidate. See
     * docs/specs/STORAGE_DURABILITY_SUPERVISOR.md §"Agent storage — single
     * layer, always persisted".
     *
     * Returns the exit code: 0=Success, 1=Fail, 2=Busy(Baked but RAM not cleared).
     */
    /**
     * Map an agent commit outcome to the commitChanges() exit code.
     * Pure decision (no I/O) so the #1304 data-safety contract is unit-testable:
     *   consolidated            -> 0  (success)
     *   not deferred (real fail)-> 1  (fatal)
     *   deferred + bake ok      -> 2  (non-fatal; data reached Flash)
     *   deferred + bake failed  -> 1  (fatal; data NOT on Flash)
     */
    public static function mapAgentCommitResult(bool $consolidated, bool $deferred, int $bakeRc): int {
        if ($consolidated) return 0;
        if (!$deferred)     return 1;          // genuine consolidation failure
        return $bakeRc === 0 ? 2 : 1;          // deferred: 2 only if the fallback bake saved it
    }

    public static function commitChanges($type, $id) {
        // WP #748 J — agents always collapse to a single layer on every install
        // /upgrade. Bypass commit_stack.sh's delta path entirely.
        if ($type === 'agent') {
            $deferred = false;
            $ok = self::consolidate($type, $id, $deferred);

            // Consolidation deferred (overlay busy) — fall back to a delta bake so
            // the ZRAM data is at least safe on Flash. Without this, all agent data
            // lives only in ZRAM and is lost on reboot. The next install consolidates
            // the delta layers back to a single layer.
            $bakeRc = 0;
            if (!$ok && $deferred) {
                $agentPersistPath = StoragePathResolver::agentPersistPath();
                $bakeScript = "/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/storage/storagectl.sh";
                // nosemgrep: php.lang.security.exec-use.exec-use
                exec("bash " . escapeshellarg($bakeScript) . " bake --type agent --id " . escapeshellarg($id) . " --persist " . escapeshellarg($agentPersistPath), $bakeOut, $bakeRc);
            }

            // Map (consolidated, deferred, bakeRc) -> 0 ok / 1 fatal / 2 deferred-but-safe.
            // Pure decision extracted to mapAgentCommitResult() so the data-safety
            // contract (deferred + bake-ok must NOT be fatal) is unit-tested directly.
            $code = self::mapAgentCommitResult($ok, $deferred, $bakeRc);
            if ($code === 1 && !$ok && $deferred) {
                LogService::log("Agent $id: consolidation deferred AND fallback delta bake failed (rc=$bakeRc) — data remains in ZRAM only.", LogService::LOG_ERROR, "StorageMountService");
            } elseif ($code === 2) {
                LogService::log("Agent $id: consolidation deferred; delta bake succeeded — data is safe on Flash, consolidation deferred to next install.", LogService::LOG_WARN, "StorageMountService");
            }
            return $code;
        }

        $persistPath = ($type === 'home')
            ? StoragePathResolver::homePersistPath($id)
            : StoragePathResolver::agentPersistPath();

        // Don't attempt bake if the persist path is unavailable
        if (!self::isPathAvailable($persistPath)) {
            LogService::log("Persist skipped for $type $id: storage path $persistPath not accessible.", LogService::LOG_WARN, "StorageMountService");
            return 1;
        }

        // Phase 5: route through the storagectl dispatcher (op_bake) instead of
        // the commit_stack.sh shim. Exit code unchanged (0 ok / 1 fail / 2 deferred).
        $script = "/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/storage/storagectl.sh";

        $upperDir = StoragePathResolver::zramUpper($type, $id);
        $dirtyMB = 0;
        if (is_dir($upperDir)) {
            $io = shell_exec("du -sm " . escapeshellarg($upperDir) . " 2>/dev/null | cut -f1");
            $dirtyMB = (int)trim((string)$io);
        }

        LogService::log("Initiating SquashFS persistence bake for $type $id ($dirtyMB MB dirty)...", LogService::LOG_INFO, "StorageMountService");
        LifecycleLogService::log(LifecycleLogService::LEVEL_INFO, 'StorageMountService', 'bake_start', ['type' => $type, 'id' => $id, 'dirty_mb' => $dirtyMB, 'persist_path' => $persistPath]);
        // nosemgrep: php.lang.security.exec-use.exec-use
        exec("bash " . escapeshellarg($script) . " bake --type " . escapeshellarg($type) . " --id " . escapeshellarg($id) . " --persist " . escapeshellarg($persistPath), $out, $res);

        if ($res === 0) {
            LogService::log("Successfully persisted $dirtyMB MB of RAM storage to Flash disk for $type $id.", LogService::LOG_INFO, "StorageMountService");
            LifecycleLogService::log(LifecycleLogService::LEVEL_INFO, 'StorageMountService', 'bake_ok', ['type' => $type, 'id' => $id, 'dirty_mb' => $dirtyMB, 'result' => 0]);
        } elseif ($res === 2) {
            // WP #1078: peek (don't consume) the defer-reason marker so the backend
            // log line names the actual cause. TaskService.php consumes & unlinks
            // it when building the user-facing message; we only read it here.
            $sanitisedId = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$id);
            $marker = "/tmp/unraid-aicliagents/.bake_defer_reason_{$type}_{$sanitisedId}";
            $deferReason = 'unknown';
            if (is_file($marker)) {
                $raw = @file_get_contents($marker);
                if ($raw !== false) $deferReason = trim($raw) ?: 'unknown';
            }
            LogService::log("Backed up $dirtyMB MB to Flash for $id, RAM flush deferred (reason=$deferReason).", LogService::LOG_INFO, "StorageMountService");
            LifecycleLogService::log(LifecycleLogService::LEVEL_INFO, 'StorageMountService', 'bake_deferred', ['type' => $type, 'id' => $id, 'dirty_mb' => $dirtyMB, 'reason' => $deferReason]);
        } else {
            LogService::log("FAILED SquashFS persistence bake for $type $id. Check commit_stack.sh output.", LogService::LOG_ERROR, "StorageMountService");
            LifecycleLogService::log(LifecycleLogService::LEVEL_ERROR, 'StorageMountService', 'bake_failed', ['type' => $type, 'id' => $id, 'result' => $res]);
        }

        // Phase 1: Write manifest entry after a successful or busy-but-baked commit.
        // Silent on failure — manifest writes are instrumentation, not load-bearing yet.
        if ($res === 0 || $res === 2) {
            $entity = "$type/$id";
            $sqshFiles = glob("$persistPath/{$type}_{$id}_*.sqsh") ?: [];
            if (!empty($sqshFiles)) {
                usort($sqshFiles, static fn($a, $b) => filemtime($b) <=> filemtime($a));
                $newest = $sqshFiles[0];
                $sha256 = LayerManifestService::computeFileSha256($newest);
                LayerManifestService::addLayer($entity, [
                    'filename'     => basename($newest),
                    'sha256'       => $sha256 ?? '',
                    'bytes'        => (int)filesize($newest),
                    'kind'         => 'delta',
                    'created_at'   => date('Y-m-d\TH:i:s\Z'),
                    'persist_path' => $persistPath,
                ]);
            }
        }

        // Phase 5: the count>=5 home auto-consolidate that used to live here is
        // REMOVED. Home consolidation is now driven by the homes-only policy in
        // storagectl `status` (layers >= consolidate_max_layers-2, or space
        // pressure), evaluated each supervisor tick (_check_consolidate_policy),
        // or by a manual "consolidate now" trigger. Bakes still create deltas here;
        // consolidation cadence is decoupled from the per-bake layer count, so a
        // delta no longer forces an expensive re-squash every 5 layers.

        return $res;
    }


    /**
     * Consolidates layers into a single base volume.
     */
    public static function consolidate($type, $id, bool &$deferred = false) {
        $persistPath = ($type === 'home')
            ? StoragePathResolver::homePersistPath($id)
            : StoragePathResolver::agentPersistPath();
        // Phase 5: route through the storagectl dispatcher (op_consolidate) instead
        // of the consolidate_layers.sh shim. Exit code unchanged (0 ok / 1 / 2).
        $script = "/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/storage/storagectl.sh";

        $oldSize = 0;
        foreach (glob("$persistPath/{$type}_{$id}_*.sqsh") as $f) {
            $oldSize += (int)filesize($f);
        }
        $oldSizeMB = round($oldSize / 1024 / 1024, 2);

        LogService::log("Initiating layer consolidation for $type $id (Current footprint: $oldSizeMB MB)...", LogService::LOG_INFO, "StorageMountService");
        LifecycleLogService::log(LifecycleLogService::LEVEL_INFO, 'StorageMountService', 'consolidate_start', ['type' => $type, 'id' => $id, 'old_size_mb' => $oldSizeMB]);
        // nosemgrep: php.lang.security.exec-use.exec-use
        exec("bash " . escapeshellarg($script) . " consolidate --type " . escapeshellarg($type) . " --id " . escapeshellarg($id) . " --persist " . escapeshellarg($persistPath), $out, $res);

        if ($res === 0) {
            $newSize = 0;
            $sqshAfter = glob("$persistPath/{$type}_{$id}_*.sqsh") ?: [];
            foreach ($sqshAfter as $f) {
                $newSize += (int)filesize($f);
            }
            $newSizeMB = round($newSize / 1024 / 1024, 2);
            LogService::log("Successfully consolidated storage layers for $id. Footprint changed from $oldSizeMB MB to $newSizeMB MB on Flash.", LogService::LOG_INFO, "StorageMountService");
            LifecycleLogService::log(LifecycleLogService::LEVEL_INFO, 'StorageMountService', 'consolidate_ok', ['type' => $type, 'id' => $id, 'old_mb' => $oldSizeMB, 'new_mb' => $newSizeMB]);

            // Phase 1: Replace manifest entries with the single consolidated layer
            if (!empty($sqshAfter)) {
                $consolidated = $sqshAfter[0];
                $sha256 = LayerManifestService::computeFileSha256($consolidated);
                $newLayer = [
                    'filename'   => basename($consolidated),
                    'sha256'     => $sha256 ?? '',
                    'bytes'      => (int)filesize($consolidated),
                    'kind'       => 'consolidated',
                    'created_at' => date('Y-m-d\TH:i:s\Z'),
                ];
                LayerManifestService::replaceLayers("$type/$id", [$newLayer], $persistPath);
            }
        } elseif ($res === 2) {
            $deferred = true;
            LogService::log("Consolidation deferred for $type $id (overlay busy) — supervisor will retry.", LogService::LOG_WARN, "StorageMountService");
            LifecycleLogService::log(LifecycleLogService::LEVEL_WARN, 'StorageMountService', 'consolidate_deferred', ['type' => $type, 'id' => $id]);
        } else {
            LogService::log("FAILED consolidation for $type $id. Check consolidate_layers.sh output.", LogService::LOG_ERROR, "StorageMountService");
            LifecycleLogService::log(LifecycleLogService::LEVEL_ERROR, 'StorageMountService', 'consolidate_failed', ['type' => $type, 'id' => $id, 'result' => $res]);
        }

        return ($res === 0);
    }

    public static function repairHomeStorage($user) {
        if (empty($user)) return false;
        LogService::log("Initiating mount repair sequence for home $user...", LogService::LOG_WARN, "StorageMountService");
        
        // For SquashFS, repair means remounting or consolidating.
        $res = self::ensureHomeMounted($user);
        if ($res) {
            LogService::log("Successfully verified and remounted storage stack for $user.", LogService::LOG_INFO, "StorageMountService");
        }
        return $res;
    }

    // -----------------------------------------------------------------------
    // Pending-consolidation marker — read-only remnant after Phase 5
    //
    // The producer (markConsolidatePending, fired by the old count>=5
    // auto-consolidate) was removed in Phase 5: consolidation is now policy- and
    // manual-driven. getConsolidatePendingSince remains only because
    // StorageMetricsService still reads it for the UI "Awaiting idle" badge — with
    // no producer it returns null, so the badge simply never lights. isMountBusy is
    // kept as a public helper for the manual / Phase-6 "consolidate now" path.
    // -----------------------------------------------------------------------

    private const PENDING_DIR = '/tmp/unraid-aicliagents';

    /**
     * Returns true if any process holds an open fd on the mount.
     * Uses `fuser -sm` (silent, mount-points only) — same check commit_stack.sh
     * uses. A return code of 0 means at least one process is using the mount.
     * Argument is shell-escaped via escapeshellarg.
     */
    public static function isMountBusy(string $mnt): bool
    {
        if (empty($mnt) || !is_dir($mnt)) return false;
        // Append `; echo __RC=$?` so we can read the rc through shell_exec
        // (sandbox-friendly — no exec/system).
        $cmd = 'fuser -sm ' . escapeshellarg($mnt) . ' 2>/dev/null; echo __RC=$?';
        $out = (string)@shell_exec($cmd);
        if (preg_match('/__RC=(\d+)/', $out, $m)) {
            return ((int)$m[1] === 0);
        }
        return false;
    }

    public static function clearConsolidatePending(string $type, string $id): void
    {
        @unlink(self::pendingMarkerPath($type, $id));
        LifecycleLogService::log(LifecycleLogService::LEVEL_INFO, 'StorageMountService', 'consolidate_marker_cleared', ['type' => $type, 'id' => $id]);
    }

    /**
     * Returns the unix timestamp the consolidate was first deferred, or null
     * if no pending marker exists. The UI uses this to render a "since" hint.
     */
    public static function getConsolidatePendingSince(string $type, string $id): ?int
    {
        $f = self::pendingMarkerPath($type, $id);
        if (!file_exists($f)) return null;
        $contents = trim((string)@file_get_contents($f));
        return ctype_digit($contents) ? (int)$contents : null;
    }

    private static function pendingMarkerPath(string $type, string $id): string
    {
        // Sanitise id so it can't escape the marker directory.
        $safeId = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $id) ?: 'unknown';
        return self::PENDING_DIR . "/.consolidate_pending_{$type}_{$safeId}";
    }

}
