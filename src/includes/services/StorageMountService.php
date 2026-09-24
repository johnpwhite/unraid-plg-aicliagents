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
    // SIDE_BY_SIDE_AGENT_INSTALLS.md Phase 1 (2026-09-09): AGENT_MNT_BASE used to
    // duplicate AgentRegistry::AGENT_BASE's literal value under a different name.
    // Removed in favour of AgentRegistry::agentPath() at both call sites below —
    // one fewer place that would have needed updating for a version-aware path.
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

    /**
     * Is the filesystem that owns $path mounted and safe to write to?
     *
     * /mnt/user and /mnt/user0 are ordinary directories on rootfs until shfs
     * mounts them. Treating those stubs as usable can make a boot-time
     * auto-launch create files beneath them, which then prevents Unraid from
     * mounting the user-share filesystem at all. Match mount-table targets
     * exactly: the former substring check let a /mnt/user0 entry incorrectly
     * satisfy a /mnt/user check.
     *
     * $mounts is an explicit test seam; production callers read /proc/mounts.
     */
    public static function isBackingMountAvailable(string $path, ?string $mounts = null): bool {
        if ($path === '') return false;

        $target = null;
        if (preg_match('#^/mnt/(user0?)(/|$)#', $path, $m)) {
            $target = '/mnt/' . $m[1];
        } elseif (preg_match('#^/mnt/(disk\d+)(/|$)#', $path, $m)) {
            $target = '/mnt/' . $m[1];
        }

        if ($target === null) return true;
        $mounts = $mounts ?? (@file_get_contents('/proc/mounts') ?: '');
        return self::mountTableHasTarget($mounts, $target);
    }

    /** Pure parser for /proc/mounts-style rows. */
    public static function mountTableHasTarget(string $mounts, string $target): bool {
        foreach (preg_split('/\r?\n/', $mounts) ?: [] as $line) {
            $fields = preg_split('/\s+/', trim($line));
            if (!isset($fields[1])) continue;
            $mountedAt = str_replace(
                ['\\040', '\\011', '\\012', '\\134'],
                [' ', "\t", "\n", '\\'],
                $fields[1]
            );
            if ($mountedAt === $target) return true;
        }
        return false;
    }

    /** Runtime check: does this path exist and is its backing storage usable? */
    public static function isPathAvailable(string $path): bool {
        if (!self::isBackingMountAvailable($path)) return false;
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
     *   bash: <agentBase>/<id>/node_modules/.bin/<cli>: No such file or directory
     *   (<agentBase> is AgentRegistry::agentBase() — see that method for the literal it returns)
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

        $mnt = AgentRegistry::agentPath($agentId);

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

        // A state write may have observed ESTALE while this mount still looked
        // healthy in /proc. Do not keep such an overlay on the fast path: route
        // it through op_mount's busy arbiter. Busy homes are deliberately kept
        // live (no unsafe remount); the next idle request repairs them.
        $writeFault = self::hasHomeWriteFault($user);
        if (!$forcedUnmount && self::isMounted($mnt) && self::isHomeMountHealthy($user) && !$writeFault) { $exit = 0; return true; }
        if ($writeFault) {
            LogService::log("Home $user has a queued write-fault repair; requesting a safe overlay refresh.", LogService::LOG_WARN, "StorageMountService");
        }

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
            if (!$writeFault && self::isMounted($mnt) && self::isHomeMountHealthy($user)) {
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
        } elseif ($writeFault) {
            self::clearHomeWriteFault($user);
            LogService::log("Home $user write-fault repair completed after a safe overlay refresh.", LogService::LOG_INFO, "StorageMountService");
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
     * Verifies the home mount is genuinely live, not a phantom proc entry.
     * Layering backend: a healthy home mount reads "overlay <mnt> overlay ..." in
     * /proc/mounts. Passthrough backend: <mnt> is a bind of the plain persist dir.
     */
    public static function isHomeMountHealthy(string $user): bool {
        $mnt = rtrim(UtilityService::getWorkDir($user) . "/home", '/');
        $mounts = file_exists('/proc/mounts') ? (string)file_get_contents('/proc/mounts') : '';
        if (preg_match("#^overlay\s+" . preg_quote($mnt, '#') . "\s+overlay\b#m", $mounts)) {
            return true;
        }

        // Passthrough backend (detect_backend.sh engine=passthrough, e.g. Unraid 7.3
        // Internal Boot: /boot on a ZFS pool backed by NVMe). storagectl's _pt_mount
        // bind-mounts the plain dir at <mnt>, so there is no overlay line to match —
        // without this, the fast path in ensureHomeMounted never fires and every
        // supervisor tick re-logs "Mounting Home Stack" and re-shells storagectl.
        // A live bind makes <mnt> and the _pt_dir the same (dev, ino) pair. Pure
        // stat, no subprocess: this is the hot path.
        $pt = rtrim(StoragePathResolver::homePersistPath($user), '/') . "/passthrough/homes/$user";
        $a = @stat($mnt);
        $b = @stat($pt);
        return is_array($a) && is_array($b)
            && (int)$a['dev'] === (int)$b['dev']
            && (int)$a['ino'] === (int)$b['ino'];
    }

    /** Stable per-home marker path; public for diagnostics and unit coverage. */
    public static function homeWriteFaultMarkerPath(string $user): string {
        $safeUser = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $user) ?: 'unknown';
        return "/tmp/unraid-aicliagents/home_write_fault_{$safeUser}.json";
    }

    public static function hasHomeWriteFault(string $user): bool {
        return is_file(self::homeWriteFaultMarkerPath($user));
    }

    /**
     * Queue (do not perform) a repair after a confirmed OverlayFS write fault.
     * Mounting code consumes this marker through its existing busy-safe arbiter.
     */
    public static function markHomeWriteFault(string $user, array $failure = []): void {
        $marker = self::homeWriteFaultMarkerPath($user);
        @mkdir(dirname($marker), 0755, true);
        $payload = json_encode(['user' => $user, 'at' => gmdate('c'), 'failure' => $failure]);
        if (@file_put_contents($marker, $payload === false ? '{}' : $payload, LOCK_EX) === false) {
            LogService::log("Could not queue home write-fault repair for $user.", LogService::LOG_ERROR, "StorageMountService");
            return;
        }
        LogService::log("Queued safe home write-fault repair for $user.", LogService::LOG_WARN, "StorageMountService");
    }

    private static function clearHomeWriteFault(string $user): void {
        @unlink(self::homeWriteFaultMarkerPath($user));
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
        // SIDE_BY_SIDE_AGENT_INSTALLS.md Phase 2 (2026-09-09): a caller may pass a
        // path that is now a symlink (agents/<id> once that agent is migrated to
        // the versioned layout) — /proc/mounts records the kernel's literal mount
        // target, which is the REAL directory a symlink points at, never the
        // symlink's own name. Resolve first so this stays a byte-identical no-op
        // for every existing non-symlink caller (home paths, a not-yet-migrated
        // agent — realpath() of a real directory returns itself) and starts
        // matching correctly for a migrated one. realpath() returns false for a
        // dangling/absent path — keep the original $path then, matching today's
        // "not mounted" outcome.
        $resolved = @realpath($path);
        if ($resolved !== false) {
            $path = $resolved;
        }
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
     * Unconditionally remount the agent overlay via op_mount, bypassing the
     * isAgentMountHealthy fast-path. Used by forceAgentRefresh (R3 verify-live)
     * to swap a stale lowerdir for the newest baked layer after a deferred
     * refresh — ensureAgentMounted's healthy-mount short-circuit would silently
     * keep the old overlay, so a dedicated method is required.
     *
     * Delegates entirely to the existing storagectl op_mount dispatch so the
     * Epic #1310 facade rule stays green: storagectl is never called outside
     * FileStorage / StorageMountService.
     *
     * NOTE: a true return means the remount was DISPATCHED usably (exit 0 or
     * deferred exit 2), NOT a guarantee the new layer is now live — callers
     * must re-verify liveness after this call (install-bg.php does via
     * InstallerService::isAgentLayerLive).
     */
    public static function remountAgent(string $agentId): bool
    {
        $persistPath = StoragePathResolver::agentPersistPath();
        if (!self::isPathAvailable($persistPath)) {
            LogService::log("remountAgent($agentId): storage path $persistPath is not accessible.", LogService::LOG_WARN, "StorageMountService");
            return false;
        }
        $script = "/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/storage/storagectl.sh";
        // nosemgrep: php.lang.security.exec-use.exec-use
        exec(TraceContext::shellPrefix() . "bash " . escapeshellarg($script) . " mount --type agent --id " . escapeshellarg($agentId) . " --persist " . escapeshellarg($persistPath) . " 2>&1", $out, $res);
        $mnt = AgentRegistry::agentPath($agentId);
        $usable = self::mountResultIsUsable((int)$res);
        if ($usable && (int)$res === 2 && !self::isMounted($mnt)) {
            $usable = false;
            LogService::log("Agent mount for $agentId deferred with NO live overlay (target not mounted yet?) — treating as unavailable.", LogService::LOG_WARN, "StorageMountService");
        }
        if (!$usable) {
            LogService::log("remountAgent($agentId): storagectl mount failed (exit $res): " . implode("\n", $out), LogService::LOG_ERROR, "StorageMountService");
        }
        return $usable;
    }

    // ---- Side-by-side install staging ---------------------------------------
    // docs/specs/SIDE_BY_SIDE_AGENT_INSTALLS.md Phase 3 (2026-09-15).
    //
    // Three calls, used in this order by InstallerService::installAgent:
    //   stageAgentInstall()  -> bind an install-only overlay, get its path
    //   bakeStagedInstall()  -> capture what the install wrote as a new layer
    //   unstageAgentInstall()-> tear the overlay (and its layer) down
    //
    // The last one is unconditional: the failure path calls it too, and calling
    // it with nothing staged is a clean no-op by design.

    /**
     * Bind an install-only overlay for this agent and return the path to write
     * into, or null when staging is unavailable.
     *
     * The mount stacks a fresh writable layer over the SAME read-only layers the
     * running version is serving from, so the install sees exactly what a normal
     * install would see and writes somewhere nothing is reading. A null return
     * is not an error the caller has to handle specially — it means "install the
     * old way", and installAgent treats it as such.
     */
    public static function stageAgentInstall(string $agentId): ?string
    {
        $persistPath = StoragePathResolver::agentPersistPath();
        if (!self::isPathAvailable($persistPath)) {
            LogService::log("stageAgentInstall($agentId): storage path $persistPath is not accessible.", LogService::LOG_WARN, "StorageMountService");
            return null;
        }
        $script = "/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/storage/storagectl.sh";
        $out = [];
        // nosemgrep: php.lang.security.exec-use.exec-use
        exec(TraceContext::shellPrefix() . "bash " . escapeshellarg($script)
            . " stage --type agent --id " . escapeshellarg($agentId)
            . " --persist " . escapeshellarg($persistPath) . " 2>/dev/null", $out, $res);
        if ((int)$res !== 0) {
            LogService::log("stageAgentInstall($agentId): staging failed (exit $res)", LogService::LOG_WARN, "StorageMountService");
            return null;
        }
        $mount = self::stagedMountFromJson(implode("\n", $out));
        if ($mount === null || !is_dir($mount)) {
            LogService::log("stageAgentInstall($agentId): storagectl reported success but no usable staging mount.", LogService::LOG_WARN, "StorageMountService");
            return null;
        }
        LogService::log("Staged install for $agentId at $mount", LogService::LOG_INFO, "StorageMountService");
        return $mount;
    }

    /**
     * Pull the staging mount path out of a storagectl `stage` response.
     *
     * Pure (no I/O) so the contract between the two languages is unit-testable
     * rather than only observable on a live box. storagectl prints one JSON
     * object; anything else — an empty body, a shell error, a response without
     * the payload — must return null rather than a half-path the installer would
     * then write into.
     */
    public static function stagedMountFromJson(string $json): ?string
    {
        $data = json_decode(trim($json), true);
        if (!is_array($data)) return null;
        if ((int)($data['exit'] ?? 1) !== 0) return null;
        $mount = $data['payload']['mount'] ?? null;
        if (!is_string($mount) || $mount === '') return null;
        // A staging mount is always under the agents root. Refusing anything else
        // keeps a malformed or hostile response from steering an install — and an
        // install writes hundreds of megabytes wherever it is pointed.
        $expected = AgentRegistry::agentBase() . '/.staging/';
        return strncmp($mount, $expected, strlen($expected)) === 0 ? $mount : null;
    }

    /**
     * Capture what the install wrote into the staging layer as a new agent layer.
     *
     * Returns the storagectl exit code: 0 captured, 2 captured but the follow-on
     * refresh deferred (both mean the bytes reached durable storage), anything
     * else a real failure.
     */
    public static function bakeStagedInstall(string $agentId): int
    {
        $persistPath = StoragePathResolver::agentPersistPath();
        $script = "/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/storage/storagectl.sh";
        $out = [];
        // nosemgrep: php.lang.security.exec-use.exec-use
        exec(TraceContext::shellPrefix() . "bash " . escapeshellarg($script)
            . " bake --type agent --id " . escapeshellarg($agentId)
            . " --persist " . escapeshellarg($persistPath)
            . " --staged 2>&1", $out, $res);
        if ((int)$res !== 0 && (int)$res !== 2) {
            LogService::log("bakeStagedInstall($agentId): failed (exit $res): " . implode("\n", $out), LogService::LOG_ERROR, "StorageMountService");
        }
        return (int)$res;
    }

    /**
     * Tear down the staging overlay. Safe to call when nothing is staged.
     *
     * $keepUpper leaves the writable layer on disk — used only between a failed
     * bake and a diagnostic, never on the success path, where the layer has
     * already been captured and is pure duplication on a flash device.
     */
    public static function unstageAgentInstall(string $agentId, bool $keepUpper = false): void
    {
        $persistPath = StoragePathResolver::agentPersistPath();
        $script = "/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/storage/storagectl.sh";
        $out = [];
        // nosemgrep: php.lang.security.exec-use.exec-use
        exec(TraceContext::shellPrefix() . "bash " . escapeshellarg($script)
            . " unstage --type agent --id " . escapeshellarg($agentId)
            . " --persist " . escapeshellarg($persistPath)
            . ($keepUpper ? " --keep-upper" : "") . " 2>&1", $out, $res);
        if ((int)$res !== 0) {
            LogService::log("unstageAgentInstall($agentId): exit $res: " . implode("\n", $out), LogService::LOG_WARN, "StorageMountService");
        }
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
     *   deferred + bake 0 or 2  -> 2  (non-fatal; data reached Flash)
     *   deferred + bake failed  -> 1  (fatal; data NOT on Flash)
     */
    public static function mapAgentCommitResult(bool $consolidated, bool $deferred, int $bakeRc): int {
        if ($consolidated) return 0;
        if (!$deferred)     return 1;          // genuine consolidation failure
        // storagectl bake exit 2 means the delta reached durable storage but the
        // busy live mount could not be refreshed/reclaimed yet. Both 0 and 2 are
        // therefore safe, non-fatal install outcomes.
        return ($bakeRc === 0 || $bakeRc === 2) ? 2 : 1;
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
     * #129: does storagectl recommend consolidating this user's home right now?
     * Mirrors the supervisor's own signal (`_check_consolidate_policy`): run the
     * status verb and test for the homes-only `"recommended":true` verdict, which
     * appears nowhere else in the JSON so the substring test is unambiguous. Used
     * by the boot sweep to decide which homes to consolidate while guaranteed idle,
     * before their sessions relaunch. Returns false on any error (never blocks boot).
     */
    public static function homeConsolidationRecommended(string $user): bool {
        if ($user === '') return false;
        $persistPath = StoragePathResolver::homePersistPath($user);
        $script = "/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/storage/storagectl.sh";
        if (!is_file($script)) return false;
        // nosemgrep: php.lang.security.exec-use.exec-use
        $json = @shell_exec("bash " . escapeshellarg($script) . " status --type home --id " . escapeshellarg($user) . " --persist " . escapeshellarg($persistPath) . " 2>/dev/null");
        return is_string($json) && strpos($json, '"recommended":true') !== false;
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
