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
