<?php
/**
 * <module_context>
 *     <name>StorageMountService</name>
 *     <description>Mounting and lifecycle management for AICliAgents storage.</description>
 *     <dependencies>LogService, ConfigService, TraceContext</dependencies>
 *     <constraints>Under 150 lines. Manages SquashFS + OverlayFS stacks.</constraints>
 * </module_context>
 *
 * @internal Storage-component internal (Epic #1310). Consumers must express intent
 *           via the FileStorage facade (ensureReady / persist / release / status) —
 *           never call ensureHomeMounted / ensureAgentMounted / commitChanges
 *           directly. Enforced by RegressionGuardsTest::testEpic1310ConsumersUseFacadeNotOwnerMethods.
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

    /**
     * S-05 (#1352): the durable degraded flag — written by degraded_state.sh as
     * the reboot-surviving counterpart of the tmpfs EMERGENCY_FLAG. Lets the UI /
     * boot-time consumers see that the PREVIOUS session ended degraded even after
     * the reboot wiped /tmp.
     *
     * @return array{active: bool, reason: string|null, set_at: string|null}
     */
    public static function degradedState(): array {
        $path = StoragePathResolver::degradedStatePath();
        if (!is_file($path)) {
            return ['active' => false, 'reason' => null, 'set_at' => null];
        }
        $decoded = json_decode((string)@file_get_contents($path), true);
        if (!is_array($decoded)) {
            return ['active' => true, 'reason' => 'unknown', 'set_at' => null];
        }
        return [
            'active' => true,
            'reason' => isset($decoded['reason']) ? (string)$decoded['reason'] : 'unknown',
            'set_at' => isset($decoded['set_at']) ? (string)$decoded['set_at'] : null,
        ];
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
     * WP #1309: classify a storagectl `mount` exit code as "the entity's
     * storage is usable right now". After op_mount became busy-safe, a mount
     * can DEFER (exit 2) when the overlay is busy — it keeps the LIVE mount
     * (the upper holds all data; only the lower refresh waits for idle), so
     * exit 2 is a usable mount, not a failure. Pure predicate so the launch
     * callers (ensureHomeMounted / ensureAgentMounted) share one definition.
     *   0 → true (mounted/refreshed) · 2 → true (deferred, live mount kept)
     *   1 / other non-zero → false (hard failure)
     */
    public static function mountResultIsUsable(int $res): bool {
        return $res === 0 || $res === 2;
    }

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

    // L5 (WP#1333): optional by-ref $exit surfaces the op_mount exit code (0 ok /
    // 2 deferred-busy-but-usable / else fail) so FileStorage::ensureReady can report
    // the 'deferred' state. Default-valued, so existing bool-context callers are
    // unaffected.
    public static function ensureAgentMounted($agentId, int &$exit = 0) {
        $exit = 1;
        if (self::isMigrationInProgress()) return false;

        $mnt = self::AGENT_MNT_BASE . "/$agentId";

        if (self::isMounted($mnt)) {
            if (self::isAgentMountHealthy($agentId)) { $exit = 0; return true; }
            // F5 (WP#1328): the THIRD copy-up-poison site WP#1309 missed (homes were
            // fixed at the ensureHomeMounted comment below). A PHP `umount -l` of a
            // stale/phantom agent overlay (agent uppers ARE writable) followed by an
            // op_mount rebind on the SAME upper double-binds it → copy-up poison
            // (new-file create → ENOENT). REMOVED: op_mount's busy-arbiter adjudicates
            // by construction (idle phantom → real umount + rebuild; busy overlay →
            // exit 2 kept live & usable; busy phantom → exit 1 surfaced). Never lazy-
            // detach-then-rebind in PHP.
            LogService::log("Stale agent mount detected for '$agentId' (binary missing under healthy-looking overlay). Routing teardown through op_mount's busy-arbiter (no PHP lazy detach).", LogService::LOG_WARN, "StorageMountService");
            // fall through to op_mount below — the arbiter handles the stale mount.
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
        // R-06: TraceContext::shellPrefix() prepends AICLI_TRACE_ID=<id> (validated
        // [a-z0-9]{4,16} at setId, so safe to interpolate) — joins this exec's shell
        // log lines to the originating AJAX request.
        // nosemgrep: php.lang.security.exec-use.exec-use
        exec(TraceContext::shellPrefix() . "bash " . escapeshellarg($script) . " mount --type agent --id " . escapeshellarg($agentId) . " --persist " . escapeshellarg($persistPath) . " 2>&1", $out, $res);

        // WP #1309: exit 2 = deferred-busy (the live overlay is kept) → usable.
        // S-02 (#1352): that contract assumes a LIVE overlay was kept (mount_busy).
        // A target_not_mounted defer (UD device / pool not yet mounted) exits 2
        // BEFORE any overlay exists — verify the mount is actually present before
        // treating the defer as usable.
        $exit = (int)$res;
        $usable = self::mountResultIsUsable((int)$res);
        if ($usable && (int)$res === 2 && !self::isMounted($mnt)) {
            $usable = false;
            LogService::log("Agent mount for $agentId deferred with NO live overlay (target not mounted yet?) — treating as unavailable.", LogService::LOG_WARN, "StorageMountService");
        }
        if (!$usable) {
            LogService::log("Mount script FAILED for agent $agentId: " . implode("\n", $out), LogService::LOG_ERROR, "StorageMountService");
        }

        return $usable;
    }

    /**
     * Ensures the user home storage is mounted.
     *
     * Mirrors the agent-mount health-check pattern: verifies the path is
     * genuinely an overlay mount (not a phantom proc entry), and serializes
     * concurrent callers with a per-user flock so two simultaneous PHP
     * requests cannot both invoke mount_stack.sh.
     */
    // L5 (WP#1333): optional by-ref $exit (see ensureAgentMounted) surfaces the
    // op_mount exit code so ensureReady can report the 'deferred' state.
    public static function ensureHomeMounted($user, int &$exit = 0) {
        $exit = 1;
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
        if (is_link($mnt) && self::isEmergencyMode()) { $exit = 0; return true; }

        // Fast path: already a healthy overlay mount
        if (!$forcedUnmount && self::isMounted($mnt) && self::isHomeMountHealthy($user)) { $exit = 0; return true; }

        // Serialize concurrent mounts for the same user with an advisory lock.
        // Losers block until the winner finishes, then re-check before mounting.
        $safeUser = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $user) ?: 'unknown';
        $lockFile = "/tmp/unraid-aicliagents/home_mount_{$safeUser}.lock";
        $lock = @fopen($lockFile, 'c');
        if ($lock !== false) {
            // Bounded wait: a wedged winner must NOT block this PHP-FPM worker
            // forever. FPM runs with max_execution_time=0, so AICliAjax's
            // set_time_limit() is a no-op and a bare blocking LOCK_EX could hang the
            // request permanently and starve the worker pool (a stuck mount then
            // makes unrelated requests — e.g. a workspace export — hang too). Poll
            // LOCK_NB up to ~10s; on timeout proceed anyway: the actual mount
            // (storagectl op_mount) holds its OWN mount-op lock so we never
            // double-mount, and the isMounted re-check below short-circuits when the
            // winner already finished.
            $lockDeadline = microtime(true) + 10.0;
            while (!flock($lock, LOCK_EX | LOCK_NB)) {
                if (microtime(true) >= $lockDeadline) {
                    LogService::log("Home mount lock wait timed out for $safeUser after 10s — proceeding (winner may be wedged); op_mount lock still serializes.", LogService::LOG_WARN, "StorageMountService");
                    break;
                }
                usleep(100000); // 100ms
            }
            if (self::isMounted($mnt) && self::isHomeMountHealthy($user)) {
                flock($lock, LOCK_UN);
                fclose($lock);
                $exit = 0;
                return true;
            }
        }

        $persistPath = StoragePathResolver::homePersistPath($user);

        if (!self::isPathAvailable($persistPath)) {
            LogService::log("Home mount skipped: storage path $persistPath is not accessible.", LogService::LOG_WARN, "StorageMountService");
            if ($lock !== false) { flock($lock, LOCK_UN); fclose($lock); }
            return false;
        }

        // WP #1309: the second copy-up-poison site (a PHP `umount -l` of a
        // phantom home mount, immediately followed by an op_mount rebind) is
        // REMOVED. op_mount's busy-arbiter now handles a phantom safely by
        // construction: an idle phantom is sync-umounted and rebuilt; a busy
        // phantom (a non-overlay mount that won't release) surfaces as exit 1
        // rather than an unsafe lazy-remount. Lazy-umount-then-rebind on the
        // same upper is exactly what poisons copy-up, so PHP must never do it.

        LogService::log("Mounting Home Stack for $user", LogService::LOG_INFO, "StorageMountService");

        // Phase 5: route through the storagectl dispatcher (op_mount) instead of
        // the mount_stack.sh shim. Exit code unchanged.
        $script = "/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/storage/storagectl.sh";
        // Bug #1054: pass $user as --owner so op_mount chowns the OverlayFS
        // upperdir to the agent user -- otherwise the home overlay mounts but is
        // effectively read-only for non-root agents.
        // R-06: trace env prefix (see ensureAgentMounted).
        // nosemgrep: php.lang.security.exec-use.exec-use
        exec(TraceContext::shellPrefix() . "bash " . escapeshellarg($script) . " mount --type home --id " . escapeshellarg($user) . " --persist " . escapeshellarg($persistPath) . " --owner " . escapeshellarg($user) . " 2>&1", $out, $res);

        // WP #1309: exit 2 = deferred-busy. op_mount kept the LIVE overlay (the
        // upper holds all data; only the lower refresh waits for idle) — that is
        // a usable, writable home, NOT a failure. Treat it as success so the
        // user never sees a spurious "Mount script FAILED" for a working mount.
        // S-02 (#1352): that contract assumes a LIVE overlay was kept. A
        // target_not_mounted defer (UD device / pool not yet mounted) exits 2
        // BEFORE any overlay exists — verify the mount is actually present
        // before treating the defer as usable, or a workspace would open over
        // an unmounted (tmpfs) home dir.
        $exit = (int)$res;
        $usable = self::mountResultIsUsable((int)$res);
        if ($usable && (int)$res === 2 && !self::isMounted($mnt)) {
            $usable = false;
            LogService::log("Home mount for $user deferred with NO live overlay (target not mounted yet?) — treating as unavailable.", LogService::LOG_WARN, "StorageMountService");
        }
        if (!$usable) {
            LogService::log("Mount script FAILED for home $user: " . implode("\n", $out), LogService::LOG_ERROR, "StorageMountService");
        } elseif ((int)$res === 2) {
            LogService::log("Home mount for $user deferred (busy) — live mount kept; lower refresh deferred to idle.", LogService::LOG_INFO, "StorageMountService");
        }

        if ($lock !== false) { flock($lock, LOCK_UN); fclose($lock); }
        return $usable;
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
        $safeUser = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $user) ?: 'unknown';
        // #1322/#1313a: drive the zram-vs-disk upper-mode from the GENUINE device test
        // (FileStorage::backendForPath -> detect_backend.sh removable/USB), NOT a
        // vfat-fstype replica — flash device (wear) -> ZRAM upper; durable -> disk
        // upper. Both PHP and bash _entity_paths now key on the SAME single source
        // (backend_for), so the upper a mount reads can never diverge from where a bake
        // writes (a vfat-formatted SSD is no longer mis-treated as a wear-limited stick).
        require_once __DIR__ . '/FileStorage.php';
        $backend = FileStorage::backendForPath($persistPath)['backend'];
        if ($backend === 'flash') {
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

    // L5 (WP#1333): commitChanges() was DELETED — its persist consumer-policy (the
    // agent consolidate→delta-bake-fallback and the home bake + logging) moved into
    // the facade (FileStorage::persist), which now routes through the storagectl seam
    // directly (op_bake records the manifest under the lock — F6), collapsing the
    // persist verb to the same depth as release/status. consolidate() /
    // mapAgentCommitResult() / isPathAvailable() remain here as the helpers the facade
    // composes; the data-safety contract is still guarded (RegressionGuardsTest now
    // asserts the agent fallback in FileStorage::persist) + unit-tested
    // (AgentCommitResultTest::mapAgentCommitResult).


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
        // R-06: trace env prefix (see ensureAgentMounted).
        // nosemgrep: php.lang.security.exec-use.exec-use
        exec(TraceContext::shellPrefix() . "bash " . escapeshellarg($script) . " consolidate --type " . escapeshellarg($type) . " --id " . escapeshellarg($id) . " --persist " . escapeshellarg($persistPath), $out, $res);

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
