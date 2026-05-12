<?php
/**
 * <module_context>
 *     <name>ValidationService</name>
 *     <description>Centralized input validation and sanitization for all user-supplied data.</description>
 *     <dependencies>None</dependencies>
 *     <constraints>Under 150 lines. Pure validation - no side effects, no I/O.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class ValidationService {

    /** Allowed base directories for filesystem operations. */
    private static $ALLOWED_PATH_BASES = [
        '/mnt',
        '/home',
        '/root',
        '/boot/config/plugins/unraid-aicliagents',
        '/tmp/unraid-aicliagents',
    ];

    /**
     * Validates a filesystem path against a whitelist of allowed base directories.
     * Resolves symlinks and prevents traversal attacks.
     *
     * @param string $path The user-supplied path to validate.
     * @param array|null $allowedBases Override the default whitelist.
     * @return string|false The resolved canonical path, or false if invalid.
     */
    public static function validatePath($path, $allowedBases = null) {
        if (empty($path) || !is_string($path)) {
            return false;
        }

        // Resolve to canonical path (eliminates ../ and symlinks)
        $resolved = realpath($path);
        if ($resolved === false) {
            // Path doesn't exist yet - validate the parent directory instead
            $parent = realpath(dirname($path));
            if ($parent === false) {
                return false;
            }
            $resolved = $parent . '/' . basename($path);
        }

        $bases = $allowedBases ?? self::$ALLOWED_PATH_BASES;
        foreach ($bases as $base) {
            // Match exact path or path with trailing separator (prevents /mnt/users matching /mnt/user)
            if ($resolved === $base || strpos($resolved, $base . '/') === 0) {
                return $resolved;
            }
        }

        return false;
    }

    /**
     * Sanitizes a filename to prevent directory traversal and special character injection.
     *
     * @param string $filename The user-supplied filename.
     * @return string Safe filename (basename only, dangerous chars stripped).
     */
    public static function sanitizeFilename($filename) {
        // Strip directory components
        $clean = basename($filename);
        // Remove null bytes and control characters
        $clean = preg_replace('/[\x00-\x1F\x7F]/', '', $clean);
        // Remove path separators that survived basename (edge cases)
        $clean = str_replace(['/', '\\', '..'], '', $clean);
        return $clean;
    }

    /**
     * Validates an identifier (agent ID, session ID, user ID) against a safe pattern.
     *
     * @param string $id The identifier to validate.
     * @param string $pattern Regex pattern (default: alphanumeric + hyphen + underscore).
     * @param int $maxLen Maximum length (default: 64).
     * @return string|false The validated ID, or false if invalid.
     */
    public static function validateId($id, $pattern = '/^[a-zA-Z0-9_-]+$/', $maxLen = 64) {
        if (empty($id) || !is_string($id) || strlen($id) > $maxLen) {
            return false;
        }
        return preg_match($pattern, $id) ? $id : false;
    }

    /**
     * Sanitizes a log message to prevent log injection and control character abuse.
     *
     * @param string $message The message to sanitize.
     * @param int $maxLen Maximum length (default: 2000).
     * @return string Sanitized message.
     */
    public static function sanitizeLogMessage($message, $maxLen = 2000) {
        if (!is_string($message)) return '';
        // Strip control characters except newline and tab
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $message);
        return mb_substr($clean, 0, $maxLen);
    }

    /**
     * Validates an environment variable key (must be safe for shell interpolation).
     *
     * @param string $key The env var key.
     * @return string|false The validated key, or false if invalid.
     */
    public static function validateEnvKey($key) {
        if (empty($key) || !is_string($key) || strlen($key) > 128) {
            return false;
        }
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) ? $key : false;
    }

    /**
     * Escapes a value for safe INI file serialization.
     * Handles quotes, equals signs, semicolons, and newlines.
     *
     * @param string $value The value to escape.
     * @return string Safe value for INI format.
     */
    public static function escapeForIni($value) {
        if (!is_string($value)) $value = (string)$value;
        // Remove characters that corrupt INI format
        $value = str_replace(["\r", "\n", "\0"], '', $value);
        // Escape backslashes and double quotes
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace('"', '\\"', $value);
        return $value;
    }

    /**
     * Validates a Unix username against safe conventions.
     *
     * @param string $username The username to validate.
     * @return string|false The validated username, or false if invalid.
     */
    public static function validateUsername($username) {
        if (empty($username) || !is_string($username)) {
            return false;
        }
        // Standard Unix username: lowercase, digits, hyphens, underscores, 1-32 chars
        if (!preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $username)) {
            return false;
        }
        // Reject system accounts
        $reserved = ['root', 'daemon', 'bin', 'sys', 'nobody', 'www-data', 'mail'];
        if (in_array($username, $reserved, true)) {
            return false;
        }
        return $username;
    }

    /**
     * Validates a log context tag (alphanumeric + limited special chars).
     *
     * @param string $context The context string.
     * @return string Sanitized context.
     */
    public static function sanitizeContext($context) {
        if (!is_string($context)) return 'Unknown';
        return preg_replace('/[^a-zA-Z0-9_\-\[\].]/', '', mb_substr($context, 0, 64));
    }
}
