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
    public const PLUGIN_BASE = '/boot/config/plugins/unraid-aicliagents';
    public const MOUNT_ROOT  = '/tmp/unraid-aicliagents';
    public const ZRAM_BASE   = '/tmp/unraid-aicliagents/zram_upper';

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
            $path = self::PLUGIN_BASE;
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
        $config = ConfigService::getConfig();
        $path   = $config['home_storage_path']
               ?? $config['agent_storage_path']
               ?? '';
        if (empty(trim($path))) {
            $path = self::PLUGIN_BASE . '/persistence';
        }
        return self::normalize($path);
    }

    /**
     * Path to the layer manifest JSON file. Always on flash.
     */
    public static function manifestPath(): string {
        // #1254: AICLI_MANIFEST_PATH redirects the manifest off the USB boot flash for
        // the L3.5 integration suite (run.sh points it at tmpfs) — a test-only hook
        // (same pattern as AICLI_ITEST_BACKEND), unset in production -> the flash path.
        $e = getenv('AICLI_MANIFEST_PATH');
        return ($e !== false && $e !== '') ? $e : self::PLUGIN_BASE . '/layer_manifest.json';
    }

    /**
     * Path to the persistent lifecycle log. On flash in production; #1254 lets the
     * test harness redirect it to tmpfs via AICLI_LIFECYCLE_LOG.
     */
    public static function lifecycleLogPath(): string {
        $e = getenv('AICLI_LIFECYCLE_LOG');
        return ($e !== false && $e !== '') ? $e : self::PLUGIN_BASE . '/lifecycle.log';
    }

    /**
     * S-05 (#1352): durable degraded-flag path on the boot config (survives reboot,
     * unlike the tmpfs .emergency_mode flag — the pair is written by
     * src/scripts/storage/degraded_state.sh). AICLI_DEGRADED_STATE_DIR redirects
     * the dir for the test suites (same pattern as AICLI_MANIFEST_PATH).
     */
    public static function degradedStatePath(): string {
        $e = getenv('AICLI_DEGRADED_STATE_DIR');
        $dir = ($e !== false && $e !== '') ? $e : self::PLUGIN_BASE . '/state';
        return $dir . '/degraded.json';
    }

    /**
     * OverlayFS mount point for a user's home directory.
     *
     * @param string|int $user
     */
    public static function homeMount($user): string {
        $user = self::normalizeUser($user);
        return self::normalize(self::MOUNT_ROOT . "/work/$user/home");
    }

    /**
     * OverlayFS mount point for an agent binary directory.
     */
    public static function agentMount(string $id): string {
        return self::normalize('/usr/local/emhttp/plugins/unraid-aicliagents/agents/' . $id);
    }

    /**
     * ZRAM overlay upper directory for a given type and id.
     *
     * @param string $type 'home' or 'agent'
     * @param string $id   username or agent id
     */
    public static function zramUpper(string $type, string $id): string {
        return self::normalize(self::ZRAM_BASE . "/{$type}s/$id/upper");
    }

    /**
     * ZRAM overlay work directory for a given type and id.
     */
    public static function zramWork(string $type, string $id): string {
        return self::normalize(self::ZRAM_BASE . "/{$type}s/$id/work");
    }

    /**
     * Returns PLUGIN_BASE constant as a method (for callers that prefer instance-style).
     */
    public static function pluginBase(): string {
        return self::PLUGIN_BASE;
    }

    /**
     * Returns MOUNT_ROOT constant as a method.
     */
    public static function mountRoot(): string {
        return self::MOUNT_ROOT;
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

        // Plugin-internal paths are always allowed
        if (
            strncmp($path, self::PLUGIN_BASE, strlen(self::PLUGIN_BASE)) === 0 ||
            strncmp($path, self::MOUNT_ROOT, strlen(self::MOUNT_ROOT)) === 0
        ) {
            return ['ok' => true, 'reason' => null];
        }

        // Deny-list check (S-02 #1352): /mnt/remotes (network shares) and
        // /mnt/rootshare (Unraid root export). Remote paths are warn-allowed for
        // AGENT storage behind the storage_allow_remote_agent config gate; always
        // refused for home.
        foreach (self::PERSIST_DENY_PREFIXES as $deny) {
            if (strncmp($path, $deny, strlen($deny)) === 0) {
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
        if (preg_match('#^/mnt/(disks|addons)(/[^/]+)?$#', $path)) {
            return ['ok' => false, 'reason' => 'Path must include a sub-directory under the Unassigned Device mount (e.g. /mnt/addons/my-ssd/aicliagents)'];
        }

        // /mnt/<pool>/<sub> — permissive allow for custom Unraid pools
        // Requires at least /mnt/<pool>/<something> (depth ≥ 3 segments after /)
        if (preg_match('#^/mnt/[^/]+/.+#', $path)) {
            return ['ok' => true, 'reason' => null];
        }

        // /mnt/<pool> without a sub-path — reject (too close to array root)
        if (preg_match('#^/mnt/[^/]+$#', $path)) {
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
