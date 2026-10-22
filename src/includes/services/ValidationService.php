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

    /** Raw media reads cannot send a form token. Every other browser action needs one. */
    public static function isCsrfExemptRead($action): bool {
        return is_string($action) && in_array($action, ['voice_clip', 'notebook_asset'], true);
    }

    /**
     * R-176: AJAX actions that change storage or history. They are accepted as POST only, so the
     * CSRF token travels in the request body and never appears in a URL, a proxy log or the
     * browser history. The browser helper aicliAjax() (CommonLogging.php) keeps the same list.
     */
    public const POST_ONLY_ACTIONS = [
        'persist_agent', 'persist_home', 'consolidate_storage', 'convert_storage', 'remove_old_versions',
        'clear_home_caches', 'convert_agents', 'graduate_storage', 'repair_agent_storage', 'repair_home_storage',
        'delete_home_storage', 'wipe_storage', 'nuclear_rebuild_storage', 'purge_artifacts', 'backup_home',
        'set_home_backup_setting', 'restore_home', 'delete_backup_snapshot', 'clear_halt', 'restore_from_sibling',
        'auto_heal_agent_install', 'history_move', 'history_bring_back', 'execute_migrate',
        'execute_storage_backend', 'storage_backend_migration_recovery', 'execute_upper_mode',
    ];

    /**
     * R-176: the refusal message for an action that must be POST, or null when the request may go on.
     * A request with no method (a command line caller, a test) is not a web request and is let through.
     */
    public static function methodRefusal($action, ?string $method): ?string {
        if (!is_string($action) || !in_array($action, self::POST_ONLY_ACTIONS, true)) return null;
        if ($method === null || $method === '') return null;
        return strtoupper($method) === 'POST' ? null : 'This action needs a POST request.';
    }

    /** Return the dispatcher refusal when the request token does not match the server token. */
    public static function csrfTokenRefusal(string $expected, array $request, array $server): ?string {
        $received = $request['csrf_token'] ?? $server['HTTP_X_CSRF_TOKEN'] ?? '';
        if (is_array($received)) $received = end($received);
        $expected = trim($expected);
        $received = trim((string)$received);
        return $expected === '' || $received !== $expected ? 'Invalid CSRF Token' : null;
    }

    /**
     * Allowed base directories for filesystem operations. The plugin folders are the live roots in
     * production, and the test sandbox roots under test (SecretPaths), so a test writes only into the sandbox.
     *
     * Public so that the workspace picker can list the folders that LEAD to an
     * allowed base (UtilityHandler::allowedChildren). Read-only: callers get a
     * copy of the list and cannot extend it.
     */
    public static function allowedPathBases(): array {
        return [
            '/mnt',
            '/home',
            '/root',
            SecretPaths::flashRoot(),
            SecretPaths::stateRoot(),
        ];
    }

    /**
     * True when a value holds a NUL byte (chr 0). PHP 8 makes realpath(), fopen(),
     * scandir(), mkdir() and similar calls throw ValueError on one, which would crash
     * the request. Path checks call this before any filesystem call. Check the raw
     * value: trim() removes a NUL at either end and would hide it.
     */
    public static function containsNulByte($value): bool {
        return is_string($value) && strpos($value, "\0") !== false;
    }

    /**
     * Validates a filesystem path against a whitelist of allowed base directories.
     * Resolves symlinks and prevents traversal attacks.
     *
     * @param string $path The user-supplied path to validate.
     * @param array|null $allowedBases Override the default whitelist.
     * @return string|false The resolved canonical path, or false if invalid.
     */
    public static function validatePath($path, $allowedBases = null) {
        if (empty($path) || !is_string($path) || self::containsNulByte($path)) {
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

        $bases = $allowedBases ?? self::allowedPathBases();
        foreach ($bases as $base) {
            // Match exact path or path with trailing separator (prevents /mnt/users matching /mnt/user)
            if ($resolved === $base || strpos($resolved, $base . '/') === 0) {
                return $resolved;
            }
        }

        return false;
    }

    /**
     * #368 (docs/specs/TERMINAL_URL_LINKS.md): agent scratch files live under
     * /tmp (Claude Code prints /tmp/claude-0/...). The viewer may READ them;
     * nothing may write there. Only read actions call validateReadPath(); every
     * write action still calls validatePath(), which refuses /tmp outside
     * /tmp/unraid-aicliagents. realpath() is applied first, so a /tmp symlink
     * that points into a refused place resolves there and is refused.
     */
    private static $READ_ONLY_EXTRA_BASES = ['/tmp'];

    /** File names never shown from a scratch folder, even read-only (login copies, keys, tokens). */
    private static $READ_DENY_NAMES = [
        'storagestate.json', '.git-credentials', '.netrc', 'id_rsa', 'id_ed25519', 'id_ecdsa',
        'secrets.cfg', 'credentials', 'credentials.json', '.env',
    ];

    /**
     * Like validatePath() for READ actions: also accepts /tmp, except credential
     * files under the extra base. Returns the resolved path or false.
     */
    public static function validateReadPath($path) {
        $resolved = self::validatePath($path);
        if ($resolved !== false) return $resolved;
        if (empty($path) || !is_string($path) || self::containsNulByte($path)) return false;
        $canon = realpath($path);
        if ($canon === false) {
            $parent = realpath(dirname($path));
            if ($parent === false) return false;
            $canon = $parent . '/' . basename($path);
        }
        $resolved = self::validatePath($canon, self::$READ_ONLY_EXTRA_BASES);
        if ($resolved === false) return false;
        $name = strtolower(basename($resolved));
        if (in_array($name, self::$READ_DENY_NAMES, true)
            || substr($name, -6) === '.token' || substr($name, -4) === '.pem' || substr($name, -4) === '.key') {
            return false;
        }
        return $resolved;
    }

    /**
     * True when $resolved is readable through the viewer but not writable: it is
     * under /tmp and outside every write allow-list base.
     */
    public static function isReadOnlyPath($resolved): bool {
        return is_string($resolved) && $resolved !== '' && self::validatePath($resolved) === false
            && self::validatePath($resolved, self::$READ_ONLY_EXTRA_BASES) !== false;
    }

    /**
     * Like validatePath(), but tolerant of a not-yet-created path at ANY depth.
     * validatePath() only accepts a missing file whose PARENT dir exists; this
     * walks up to the nearest EXISTING ancestor, canonicalises THAT against the
     * allowlist, then re-appends the missing tail. Used for "intended" paths —
     * a "+ Add <file>" placeholder or a file about to be created whose parent
     * dir(s) don't exist yet (e.g. a project with no .claude/ directory).
     * Symlink-safe: only the existing ancestor is realpath()-canonicalised; a
     * non-existent tail segment cannot be a symlink. Returns the intended
     * absolute path, or false if it resolves outside the allowlist (or nothing
     * in the chain exists / it escapes via ..).
     *
     * @param string $path
     * @return string|false
     */
    public static function validateIntendedPath($path) {
        if (empty($path) || !is_string($path) || self::containsNulByte($path)) {
            return false;
        }
        $tail = [];
        $cur = rtrim($path, '/');
        // Reject traversal outright — the existing-ancestor realpath below
        // would collapse ".." but only AFTER we've split; refuse it up front.
        if (strpos($cur, '/../') !== false || substr($cur, -3) === '/..') {
            return false;
        }
        while ($cur !== '' && $cur !== '/' && realpath($cur) === false) {
            $tail[] = basename($cur);
            $parent = dirname($cur);
            if ($parent === $cur) {
                return false; // reached the top with nothing existing
            }
            $cur = $parent;
        }
        $baseReal = realpath($cur);
        if ($baseReal === false) {
            return false;
        }
        $intended = $baseReal . (empty($tail) ? '' : '/' . implode('/', array_reverse($tail)));
        foreach (self::allowedPathBases() as $base) {
            if ($intended === $base || strpos($intended, $base . '/') === 0) {
                return $intended;
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
     * Escapes a value for a double-quoted INI string (#636).
     * PHP's INI reader undoes exactly three escapes inside double quotes:
     * backslash, double quote and dollar. It does NOT undo addslashes() output
     * (a quote written as backslash-quote stays two characters), so addslashes
     * must never write an INI file. A dollar is escaped so that ${NAME} is not
     * expanded. A newline is legal inside a double-quoted INI value and is kept
     * (a PEM key is multi-line); only a NUL byte is dropped.
     *
     * @param string $value The value to escape.
     * @return string Safe value for INI format.
     */
    public static function escapeForIni($value) {
        if (!is_string($value)) $value = (string)$value;
        $value = str_replace("\0", '', $value);
        return str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value);
    }

    /** Words the INI reader treats as booleans or null. A key with one of these names breaks the whole file (#636). */
    public const RESERVED_INI_KEYS = ['NO', 'ON', 'OFF', 'YES', 'NONE', 'NULL', 'TRUE', 'FALSE'];

    /** True when $key is a word that PHP's INI reader refuses as a key name. */
    public static function isReservedIniKey($key): bool {
        return in_array(strtoupper((string)$key), self::RESERVED_INI_KEYS, true);
    }

    /**
     * Builds the text of a flat KEY="value" INI file (the vault, the workspace
     * secret files and the plugin .cfg) and proves it reads back (#636).
     *
     * A key that is reserved or has a shape the reader cannot hold is skipped and
     * named in $skipped. Every remaining value is then read back through
     * parse_ini_string and compared; any difference means the file would corrupt a
     * value, so the method returns false and the caller must not write.
     *
     * @param array      $map     key => scalar value
     * @param string[]   $skipped keys left out (out parameter)
     * @return string|false the file content, or false when the round trip fails
     */
    public static function buildIni(array $map, ?array &$skipped = null) {
        $skipped = [];
        $content = '';
        $expect = [];
        foreach ($map as $key => $value) {
            $key = (string)$key;
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/', $key) || self::isReservedIniKey($key)
                || (!is_scalar($value) && $value !== null)) {
                $skipped[] = $key;
                continue;
            }
            $value = str_replace("\0", '', (string)$value);
            $content .= $key . '="' . self::escapeForIni($value) . '"' . PHP_EOL;
            $expect[$key] = $value;
        }
        if ($content === '') return '';
        $back = @parse_ini_string($content, false, INI_SCANNER_NORMAL);
        if (!is_array($back) || count($back) !== count($expect)) return false;
        foreach ($expect as $key => $value) {
            if (!array_key_exists($key, $back) || (string)$back[$key] !== $value) return false;
        }
        return $content;
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
