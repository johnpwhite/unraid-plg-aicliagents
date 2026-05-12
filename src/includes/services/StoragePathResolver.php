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

    /** Deny-list: system tmpfs mounts that must never be used as persist paths. */
    private const PERSIST_DENY_PREFIXES = [
        '/mnt/disks',
        '/mnt/remotes',
        '/mnt/rootshare',
        '/mnt/addons',
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
        return self::PLUGIN_BASE . '/layer_manifest.json';
    }

    /**
     * Path to the persistent lifecycle log. Always on flash.
     */
    public static function lifecycleLogPath(): string {
        return self::PLUGIN_BASE . '/lifecycle.log';
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
     * Replicates the deny-list logic from guard_path in common.sh (commit 49d55f4).
     *
     * @return array{ok: bool, reason: string|null}
     */
    public static function validatePersistPath(string $path): array {
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

        // Deny-list check: block known system tmpfs mounts
        foreach (self::PERSIST_DENY_PREFIXES as $deny) {
            if (strncmp($path, $deny, strlen($deny)) === 0) {
                return ['ok' => false, 'reason' => "Path is inside system tmpfs mount ($deny) — not safe for persistence"];
            }
        }

        // /boot is flash — allowed
        if (strncmp($path, '/boot/', 6) === 0) {
            return ['ok' => true, 'reason' => null];
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
