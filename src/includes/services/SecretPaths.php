<?php
/**
 * <module_context>
 *     <name>SecretPaths</name>
 *     <description>docs/specs/FILE_VIEWER_SECRET_DROP.md — the rules for a
 *     workspace user's `.claude/secrets/` directory: where it lives, how to
 *     detect a path inside it, and how to keep its modes tight (0700 dir,
 *     0600 files). Used by UtilityHandler so the generic file-viewer save/list
 *     actions never loosen a secret path to 0777 and never leave a stale 0775
 *     token file lying around.</description>
 *     <dependencies>StoragePathResolver</dependencies>
 *     <constraints>No secret VALUE ever passes through this class — paths and
 *     byte/file counts only.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class SecretPaths {

    /** The directory name segment, relative to a user's home. */
    private const REL_DIR = '.claude/secrets';

    /** The production roots of the plugin state (#646). A test must never resolve to a path under one of these. */
    public const LIVE_FLASH_ROOT = '/boot/config/plugins/unraid-aicliagents';
    public const LIVE_STATE_ROOT = '/tmp/unraid-aicliagents';

    /**
     * The flash root of the plugin (vault, workspace secrets, SSH sidecar). Production:
     * LIVE_FLASH_ROOT. The env AICLI_FLASH_ROOT redirects it; tests/bootstrap.php sets it
     * to a temp folder so a test never reads or writes the real flash files (#646).
     */
    public static function flashRoot(): string {
        $e = getenv('AICLI_FLASH_ROOT');
        return ($e !== false && $e !== '') ? rtrim($e, '/') : self::LIVE_FLASH_ROOT;
    }

    /** The RAM state root (the work folders of the session users). The env AICLI_STATE_ROOT redirects it (#646). */
    public static function stateRoot(): string {
        $e = getenv('AICLI_STATE_ROOT');
        return ($e !== false && $e !== '') ? rtrim($e, '/') : self::LIVE_STATE_ROOT;
    }

    /** The lock shared by home persistence and Config Hub git saves. */
    public static function homePersistLockPath(string $user): string {
        $safeUser = preg_replace('/[^a-zA-Z0-9_-]/', '_', $user);
        return rtrim(self::stateRoot(), '/') . "/init_{$safeUser}.lock";
    }

    /**
     * Resolve $path as far as the file system allows: the deepest existing ancestor goes
     * through realpath (so a symlink or a ".." cannot hide a live path), and the rest is
     * appended as written.
     */
    public static function resolveReal(string $path): string {
        // Collapse "." and ".." first: realpath cannot do it for a part that does not exist yet.
        $parts = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '' || $seg === '.') continue;
            if ($seg === '..') { array_pop($parts); continue; }
            $parts[] = $seg;
        }
        $path = ($path !== '' && $path[0] === '/' ? '/' : '') . implode('/', $parts);
        $tail = '';
        $probe = $path;
        while ($probe !== '' && $probe !== '/' && ($real = @realpath($probe)) === false) {
            $tail = '/' . basename($probe) . $tail;
            $probe = dirname($probe);
        }
        $base = ($probe === '' || $probe === '/') ? '' : (string)@realpath($probe);
        return $base . $tail;
    }

    /** True when $path (really resolved) is the live root or inside it. */
    public static function isUnderLiveRoot(string $path): bool {
        $real = self::resolveReal($path);
        foreach ([self::LIVE_FLASH_ROOT, self::LIVE_STATE_ROOT] as $live) {
            $liveReal = self::resolveReal($live);
            foreach ([$live, $liveReal] as $root) {
                if ($root !== '' && ($real === $root || strpos($real, $root . '/') === 0)) return true;
            }
        }
        return false;
    }

    /**
     * Pure decision for the test guard: a test run (a PHPUnit TestCase class is loaded) must
     * not use any of $paths when it is under a live root. Checks the real path, not a
     * constant, so a stand-in runner that only defines AICLI_TEST_TMP is still refused (#646).
     *
     * @param string[] $paths resolved plugin state paths in use
     * @return string|null the first offending path, or null when all are safe
     */
    public static function livePathInTests(bool $phpunitLoaded, array $paths): ?string {
        if (!$phpunitLoaded) return null;
        foreach ($paths as $p) {
            if ($p !== '' && self::isUnderLiveRoot($p)) return $p;
        }
        return null;
    }

    /**
     * True when $canonicalPath IS the secrets directory, or is anything
     * inside it (a file directly in it, or in a nested sub-directory).
     * A sibling that merely starts with the same characters — e.g.
     * `/foo/.claude/secretsX` — is NOT a match: the check anchors on the
     * full `/.claude/secrets` path segment, not a string prefix.
     */
    public static function isSecretDir(string $canonicalPath): bool {
        $p = rtrim($canonicalPath, '/');
        if ($p === '') return false;
        $suffix = '/' . self::REL_DIR; // "/.claude/secrets"
        if (substr($p, -strlen($suffix)) === $suffix) return true;
        return strpos($p, $suffix . '/') !== false;
    }

    /**
     * The secrets directory path for a given workspace user, under the same
     * OverlayFS home mount ConfigService::getUserStatePath() reads/writes.
     */
    public static function secretsDirForUser(string $user): string {
        return rtrim(StoragePathResolver::homeMount($user), '/') . '/' . self::REL_DIR;
    }

    /**
     * Create (if missing) and return the secrets directory for $user, mode
     * 0700, chowned to $user like the rest of the home (ConfigService's
     * .aicli state-dir pattern). Returns '' when the user's home mount isn't
     * present yet (array stopped / emergency session) — callers must treat
     * that as "do nothing", never fabricate a path under a phantom mount.
     */
    public static function ensureSecretsDir(string $user): string {
        $user = $user === '' ? 'root' : $user;
        $home = StoragePathResolver::homeMount($user);
        if (!is_dir($home)) {
            return '';
        }
        $dir = self::secretsDirForUser($user);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        @chmod($dir, 0700);
        self::chownLikeHome($dir, $user);
        return is_dir($dir) ? $dir : '';
    }

    /** #764: most file names that one secrets listing returns. */
    public const LIST_FILES_MAX = 200;

    /**
     * #764: the regular files directly inside a secrets folder, so the folder
     * picker can show them and open one. Returns the name and the size of each
     * file, sorted by name. It never reads a file's content. A link, a folder
     * and a special file are left out.
     *
     * @return array<int,array{name:string,size:int}>
     */
    public static function listFiles(string $dir, ?bool &$truncated = null): array {
        $truncated = false;
        if (!is_dir($dir)) return [];
        $entries = @scandir($dir);
        if (!is_array($entries)) return [];
        $files = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $full = rtrim($dir, '/') . '/' . $entry;
            if (is_link($full) || !is_file($full)) continue;
            if (count($files) >= self::LIST_FILES_MAX) {
                // A file beyond the limit exists: say so, so the picker can tell the person.
                $truncated = true;
                break;
            }
            $size = @filesize($full);
            $files[] = ['name' => (string)$entry, 'size' => $size === false ? 0 : (int)$size];
        }
        return $files;
    }

    /**
     * Sets 0600 on every regular file directly inside $dir (loose modes —
     * e.g. the 0775 token files found on the test server predate this
     * feature). Also re-tightens the directory itself to 0700. Returns the
     * number of FILES it changed (the directory mode fix is not counted) so
     * callers can report "normalised: N" without ever touching a value.
     */
    public static function normaliseModes(string $dir): int {
        if (!is_dir($dir)) return 0;
        $fixed = 0;
        $entries = @scandir($dir);
        if (is_array($entries)) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') continue;
                $full = rtrim($dir, '/') . '/' . $entry;
                if (is_link($full) || !is_file($full)) continue;
                $mode = @fileperms($full);
                if ($mode === false) continue;
                if (($mode & 0777) !== 0600) {
                    if (@chmod($full, 0600)) $fixed++;
                }
            }
        }
        $dmode = @fileperms($dir);
        if ($dmode !== false && ($dmode & 0777) !== 0700) {
            @chmod($dir, 0700);
        }
        return $fixed;
    }

    /**
     * Same ownership rule as ConfigService::ensureStateDirOwnedBy: root owns
     * everything already (no-op); a non-root session user gets the dir
     * chowned to them so both the web process and the agent's own writes can
     * use it.
     */
    private static function chownLikeHome(string $dir, string $user): void {
        if ($user === '' || $user === 'root') return;
        if (!function_exists('posix_getpwnam')) return;
        $pw = @posix_getpwnam($user);
        $targetUid = is_array($pw) ? (int)$pw['uid'] : null;
        if ($targetUid === null) return;
        $stat = @stat($dir);
        $currentUid = is_array($stat) ? (int)$stat['uid'] : null;
        if ($currentUid === $targetUid) return;
        // nosemgrep: php.lang.security.exec-use.exec-use
        @shell_exec('chown ' . escapeshellarg($user) . ' ' . escapeshellarg($dir));
    }
}
