<?php
/**
 * <module_context>
 *     <name>StoragePathResolver</name>
 *     <description>Canonical source of truth for all storage paths in AICliAgents. Replaces scattered cfg-grep calls in shell scripts and PHP handlers.</description>
 *     <dependencies>ConfigService</dependencies>
 *     <constraints>No shell_exec for path resolution. All paths normalized (no trailing slash, no double slash). user=0 coerced to root.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class StoragePathResolver {

    /**
     * Deny-list: mounts that must never be used as persist paths.
     * S-02 (#1352): /mnt/remotes hosts network share mounts (CIFS/NFS) — refused
     * for home persistence (warn-allowed for agents behind storage_allow_remote_agent);
     * /mnt/rootshare is the Unraid root export, never a user data path. /mnt/disks
     * and /mnt/addons were REMOVED from this list — they are real Unassigned Devices
     * mount roots (not tmpfs, the old comment was wrong); /mnt/addons is the UD-blessed
     * path for plugin-owned devices. Sub-dirs under a UD device mount are valid targets
     * (validated below); the bare roots / device mount points themselves are not.
     */
    private const PERSIST_DENY_PREFIXES = [
        '/mnt/remotes',
        '/mnt/rootshare',
    ];

    /**
     * Where agent layers live. Reads agent_storage_path from cfg; falls back to PLUGIN_BASE.
     */
    public static function agentPersistPath(): string {
        $config = ConfigService::getConfig();
        $path   = $config['agent_storage_path'] ?? '';
        if (empty(trim($path))) {
            $path = self::pluginBase();
        }
        return self::normalize($path);
    }

    /**
     * Where home layers live. Reads home_storage_path → agent_storage_path → PLUGIN_BASE/persistence.
     * The persist path is plugin-wide (same directory for all users). $user is accepted for API
     * symmetry with the bash resolver and future per-user sub-path support.
     *
     * @param string|int $user Unix username. '0' or 0 normalized to 'root' (reserved for future use).
     */
    public static function homePersistPath($user): string {
        // $user accepted for API symmetry; path is currently plugin-wide, not per-user.
        // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
        return self::homePersistPathFromConfig(ConfigService::getConfig());
    }

    /**
     * The home persist path for a given config array (pure; the same rule as homePersistPath).
     * R-046 (#624): same order as home_persist_path() in resolve_paths.sh. A blank
     * value falls through to the next one; `??` only skips a missing key, so a blank
     * home_storage_path used to pick the flash path while the shell used the agent path.
     *
     * @param array<string,mixed> $config
     */
    public static function homePersistPathFromConfig(array $config): string {
        $path = '';
        foreach (['home_storage_path', 'agent_storage_path'] as $key) {
            $candidate = trim((string)($config[$key] ?? ''));
            if ($candidate !== '') {
                $path = $candidate;
                break;
            }
        }
        if ($path === '') {
            $path = self::pluginBase() . '/persistence';
        }
        return self::normalize($path);
    }

    /**
     * HOME_BACKUP.md: is a user's home a layer stack (OverlayFS + baked .sqsh
     * layers on the persist path) or hosted directly on a plain path with no
     * layers at all? Mirrors HealthService::hasLayers's own glob (kept private
     * there) and detect_backend.sh's engine=layering|passthrough split — 'direct'
     * here is the passthrough case: nothing to bake, the merged home IS the
     * durable copy already.
     *
     * @param string|int $user
     * @return string 'layers'|'direct'
     */
    public static function homeHostingMode($user): string {
        $user = self::normalizeUser($user);
        $persist = self::homePersistPath($user);
        $files = @glob("$persist/home_{$user}_*.sqsh");
        return (is_array($files) && $files !== []) ? 'layers' : 'direct';
    }

    /**
     * Alias for homeMount() — HOME_BACKUP.md names it homeMountPath (the mounted
     * home a backup rsyncs FROM, whatever hosts it).
     *
     * @param string|int $user
     */
    public static function homeMountPath($user): string {
        return self::homeMount($user);
    }

    /**
     * Path to the layer manifest JSON file. Always on flash.
     */
    public static function manifestPath(): string {
        // #1254: AICLI_MANIFEST_PATH redirects the manifest off the USB boot flash for
        // the L3.5 integration suite (run.sh points it at tmpfs) — a test-only hook
        // (same pattern as AICLI_ITEST_BACKEND), unset in production -> the flash path.
        $e = getenv('AICLI_MANIFEST_PATH');
        return ($e !== false && $e !== '') ? $e : self::pluginBase() . '/layer_manifest.json';
    }

    /**
     * Path to the persistent lifecycle log. On flash in production; #1254 lets the
     * test harness redirect it to tmpfs via AICLI_LIFECYCLE_LOG.
     */
    public static function lifecycleLogPath(): string {
        $e = getenv('AICLI_LIFECYCLE_LOG');
        return ($e !== false && $e !== '') ? $e : self::pluginBase() . '/lifecycle.log';
    }

    /**
     * S-05 (#1352): durable degraded-flag path on the boot config (survives reboot,
     * unlike the tmpfs .emergency_mode flag — the pair is written by
     * src/scripts/storage/degraded_state.sh). AICLI_DEGRADED_STATE_DIR redirects
     * the dir for the test suites (same pattern as AICLI_MANIFEST_PATH).
     */
    public static function degradedStatePath(): string {
        $e = getenv('AICLI_DEGRADED_STATE_DIR');
        $dir = ($e !== false && $e !== '') ? $e : self::pluginBase() . '/state';
        return $dir . '/degraded.json';
    }

    /**
     * OverlayFS mount point for a user's home directory.
     *
     * @param string|int $user
     */
    public static function homeMount($user): string {
        $user = self::normalizeUser($user);
        return self::normalize(self::mountRoot() . "/work/$user/home");
    }

    /**
     * OverlayFS mount point for an agent binary directory.
     *
     * SIDE_BY_SIDE_AGENT_INSTALLS.md Phase 1 (2026-09-09): delegates to
     * AgentRegistry::agentPath() instead of repeating the literal AGENT_BASE
     * path independently — this method and AgentRegistry::AGENT_BASE used to
     * be two separately-maintained sources of the same value.
     */
    public static function agentMount(string $id): string {
        return self::normalize(AgentRegistry::agentPath($id));
    }

    /**
     * ZRAM overlay upper directory for a given type and id.
     *
     * @param string $type 'home' or 'agent'
     * @param string $id   username or agent id
     */
    public static function zramUpper(string $type, string $id): string {
        return self::normalize(self::zramBase() . "/{$type}s/$id/upper");
    }

    /**
     * ZRAM overlay work directory for a given type and id.
     */
    public static function zramWork(string $type, string $id): string {
        return self::normalize(self::zramBase() . "/{$type}s/$id/work");
    }

    /**
     * The plugin folder on the flash drive. Production: PLUGIN_BASE. The env AICLI_FLASH_ROOT
     * redirects it (SecretPaths::flashRoot); tests/bootstrap.php points it at a temp folder
     * (#646, F19). A test run that would get the live folder is refused.
     */
    public static function pluginBase(): string {
        ConfigService::refuseLiveStateInTests();
        return SecretPaths::flashRoot();
    }

    /**
     * The RAM state folder (work folders, mounts, markers). Production: MOUNT_ROOT. The env
     * AICLI_STATE_ROOT redirects it (SecretPaths::stateRoot); see pluginBase().
     */
    public static function mountRoot(): string {
        ConfigService::refuseLiveStateInTests();
        return SecretPaths::stateRoot();
    }

    /** The RAM folder of the ZRAM upper layers. Production: the state root plus /zram_upper. */
    public static function zramBase(): string {
        return self::mountRoot() . '/zram_upper';
    }

    /**
     * Validates a user-configured persist path.
     * Replicates the deny-list logic from guard_path in common.sh (commit 49d55f4;
     * UD-targets update S-02 #1352).
     *
     * @param string $kind 'home' or 'agent' (S-02 #1352). Remote paths
     *                     (/mnt/remotes) stay refused for home; for agents they
     *                     are allowed when storage_allow_remote_agent=1.
     *
     * @return array{ok: bool, reason: string|null}
     */
    public static function validatePersistPath(string $path, string $kind = 'home'): array {
        $path = self::normalize($path);

        if (empty($path)) {
            return ['ok' => false, 'reason' => 'Path is empty'];
        }

        // Must be absolute
        if ($path[0] !== '/') {
            return ['ok' => false, 'reason' => 'Path must be absolute'];
        }

        // R-127 (#662): a ".." or "." part can step out of the folder that the prefix checks
        // below look at ("/boot/config/plugins/unraid-aicliagents/../../x").
        if (preg_match('#(^|/)\.\.?(/|$)#', $path) === 1) {
            return ['ok' => false, 'reason' => 'Path must not contain "." or ".." parts'];
        }

        // Plugin-internal paths are always allowed
        // The live folders and, in a test run, their sandbox twins.
        $isInternal = false;
        foreach (array_unique([SecretPaths::LIVE_FLASH_ROOT, SecretPaths::LIVE_STATE_ROOT, self::pluginBase(), self::mountRoot()]) as $root) {
            if (self::isUnder($path, $root)) { $isInternal = true; break; }
        }
        if ($isInternal) {
            return ['ok' => true, 'reason' => null];
        }

        // Deny-list check (S-02 #1352): /mnt/remotes (network shares) and
        // /mnt/rootshare (Unraid root export). Remote paths are warn-allowed for
        // AGENT storage behind the storage_allow_remote_agent config gate; always
        // refused for home.
        foreach (self::PERSIST_DENY_PREFIXES as $deny) {
            if (self::isUnder($path, $deny)) {
                if ($deny === '/mnt/remotes' && $kind === 'agent') {
                    $config = ConfigService::getConfig();
                    if ((string)($config['storage_allow_remote_agent'] ?? '0') === '1') {
                        return ['ok' => true, 'reason' => null];
                    }
                    return ['ok' => false, 'reason' => 'Path is a network share mount (/mnt/remotes) — set storage_allow_remote_agent=1 to allow it for agent storage'];
                }
                return ['ok' => false, 'reason' => "Path is inside $deny — not safe for persistence"];
            }
        }

        // /boot is flash — allowed
        if (strncmp($path, '/boot/', 6) === 0) {
            return ['ok' => true, 'reason' => null];
        }

        // Unassigned Devices mounts (S-02 #1352): a sub-directory UNDER a mounted
        // UD device (/mnt/disks/<label>/<sub>, /mnt/addons/<label>/<sub>) is a valid
        // persist target. The device mount point itself and the bare roots are not —
        // only a sub-path under the device is useful as a target root.
        if (preg_match('#^/mnt/(disks|addons)(/[^/]+)?$#D', $path)) {
            return ['ok' => false, 'reason' => 'Path must include a sub-directory under the Unassigned Device mount (e.g. /mnt/addons/my-ssd/aicliagents)'];
        }

        // /mnt/<pool>/<sub> — permissive allow for custom Unraid pools
        // Requires at least /mnt/<pool>/<something> (depth ≥ 3 segments after /)
        if (preg_match('#^/mnt/[^/]+/.+#', $path)) {
            return ['ok' => true, 'reason' => null];
        }

        // /mnt/<pool> without a sub-path — reject (too close to array root)
        if (preg_match('#^/mnt/[^/]+$#D', $path)) {
            return ['ok' => false, 'reason' => 'Path must include a sub-directory under the pool (e.g. /mnt/cache/aicliagents)'];
        }

        // /home, /root — allowed (common on non-Unraid dev boxes)
        if (strncmp($path, '/home/', 6) === 0 || strncmp($path, '/root/', 6) === 0) {
            return ['ok' => true, 'reason' => null];
        }

        // /tmp — allowed for test/development
        if (strncmp($path, '/tmp/', 5) === 0) {
            return ['ok' => true, 'reason' => null];
        }

        return ['ok' => false, 'reason' => 'Path is outside allowed prefixes'];
    }

    // -----------------------------------------------------------------------
    // Internal helpers
    // -----------------------------------------------------------------------

    /** R-127 (#662): $path is $prefix or lies below it (a "/" boundary, so "/mnt/remotes2" is not under "/mnt/remotes"). */
    private static function isUnder(string $path, string $prefix): bool {
        return $path === $prefix || strncmp($path, $prefix . '/', strlen($prefix) + 1) === 0;
    }

    /**
     * Normalizes a path: trim trailing slash, collapse double slashes.
     */
    private static function normalize(string $path): string {
        // Collapse double (or more) slashes, except leading //
        $normalized = preg_replace('#/{2,}#', '/', $path) ?? $path;
        return rtrim($normalized, '/');
    }

    /**
     * Coerces user to string; maps '0' or 0 to 'root'.
     *
     * @param string|int $user
     */
    private static function normalizeUser($user): string {
        $user = (string)$user;
        if ($user === '0') {
            return 'root';
        }
        return $user;
    }
}
