<?php
/**
 * <module_context>
 *     <name>SecretService</name>
 *     <description>Two-tier secrets store: agent-wide (legacy flat secrets.cfg) and per-(workspace,agent). 600-perms files on Flash. Reads come in two flavours: raw (server-side only, for env injection) and UI (returns has_value bool, never the value). Placeholder resolution like {GOOSE_PROVIDER}_API_KEY stays in the handler/UI layer — this service handles literal KEY=value maps only.</description>
 *     <dependencies>AtomicWriteService</dependencies>
 *     <constraints>Static methods only. File perms enforced 600, directory 700. Never logs values.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class SecretService {

    // ---------- Paths ----------
    // All flash locations hang off SecretPaths::flashRoot() (#646): the live
    // /boot/config/plugins/unraid-aicliagents in production, a temp folder in
    // tests (AICLI_FLASH_ROOT, set by tests/bootstrap.php).

    public static function getAgentSecretsPath(): string {
        // Test seam (RELAY_LINKED_BOXES.md, twin harness and unit tests): a
        // second Relay instance needs its own vault. Unset in production.
        $e = getenv('AICLI_SECRET_VAULT_FILE');
        return ($e !== false && $e !== '') ? $e : SecretPaths::flashRoot() . '/secrets.cfg';
    }

    public static function getWorkspaceSecretsDir(): string {
        return SecretPaths::flashRoot() . '/secrets';
    }

    public static function getWorkspaceSecretsPath(string $path, string $agentId): string {
        $hash = md5($path);
        return self::getWorkspaceSecretsDir() . "/ws_{$hash}_{$agentId}.cfg";
    }

    public static function getFreeformKeysPath(): string {
        return SecretPaths::flashRoot() . '/secrets_freeform_keys.json';
    }

    // ---------- Free-form-keys sidecar (tracks which agent-vault keys came from
    //            the new free-form Secrets sub-section vs the legacy schema/vault
    //            save path — lets save_agent_secrets manage only its own keys
    //            without touching schema-managed entries). ----------

    public static function getFreeformKeys(): array {
        $file = self::getFreeformKeysPath();
        if (!file_exists($file)) return [];
        $decoded = json_decode((string)@file_get_contents($file), true);
        return is_array($decoded) ? array_values(array_filter(array_map('strval', $decoded))) : [];
    }

    public static function setFreeformKeys(array $keys): bool {
        $unique = array_values(array_unique(array_map('strval', $keys)));
        $file = self::getFreeformKeysPath();
        ConfigService::refuseLiveStateInTests($file);
        $dir = dirname($file);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $ok = \AICliAgents\Services\AtomicWriteService::writeJson($file, $unique);
        if ($ok) @chmod($file, 0600);
        return $ok;
    }

    // ---------- Raw reads (server-side only — for launch-time env injection) ----------

    public static function getAgentSecrets(): array {
        return self::readIni(self::getAgentSecretsPath());
    }

    public static function getWorkspaceSecrets(string $path, string $agentId): array {
        return self::readIni(self::getWorkspaceSecretsPath($path, $agentId));
    }

    // ---------- UI reads (mask values — only return has_value flag) ----------

    public static function getAgentSecretsForUI(): array {
        return self::maskMap(self::getAgentSecrets());
    }

    public static function getWorkspaceSecretsForUI(string $path, string $agentId): array {
        return self::maskMap(self::getWorkspaceSecrets($path, $agentId));
    }

    // ---------- Writes ----------

    /**
     * Replace the agent-level secrets vault wholesale with $map. Caller is
     * responsible for any merge-with-existing semantics (the legacy
     * UtilityHandler::saveVault path merges via placeholder resolution before
     * calling here).
     *
     * Map shape: ['UPPER_SNAKE_KEY' => 'value', ...]. Keys not matching the
     * shape are silently dropped — defence in depth against a corrupt POST.
     */
    public static function saveAgentSecrets(array $map): bool {
        return self::writeVault(self::getAgentSecretsPath(), $map);
    }

    /**
     * Workspace secrets: empty $map unlinks the file (no zero-key artefacts).
     * Otherwise writes 600 in the secrets/ subdir (created 700 if missing).
     */
    public static function saveWorkspaceSecrets(string $path, string $agentId, array $map): bool {
        $file = self::getWorkspaceSecretsPath($path, $agentId);
        if (empty($map)) {
            if (file_exists($file)) @unlink($file);
            return true;
        }
        return self::writeVault($file, $map);
    }

    // ---------- Internal ----------

    private static function readIni(string $file): array {
        return self::readIniStrict($file) ?? [];
    }

    /**
     * Like readIni, but null when the file EXISTS and cannot be parsed (a damaged
     * or hand-edited vault). [] still means "no file" or "no keys". The writer uses
     * the difference to keep a copy of a vault it cannot read (#636).
     */
    private static function readIniStrict(string $file): ?array {
        if (!file_exists($file)) return [];
        $parsed = @parse_ini_file($file);
        if (!is_array($parsed)) return null;
        $out = [];
        foreach ($parsed as $k => $v) {
            // Defence: strip anything that doesn't look like a legitimate env name.
            // The vault was written with the same shape constraint; this just
            // protects against external edits.
            if (preg_match('/^[A-Z][A-Z0-9_]{1,127}$/D', (string)$k)) {
                $out[$k] = (string)$v;
            }
        }
        return $out;
    }

    private static function maskMap(array $raw): array {
        $out = [];
        foreach ($raw as $k => $v) {
            $out[$k] = ((string)$v !== '');
        }
        return $out;
    }

    /**
     * Write the INI vault. Ensures the parent directory exists with 0700,
     * writes via AtomicWriteService for crash/race safety, then chmods 0600.
     * Format: KEY="value" per line, double-quoted, with the INI escapes
     * (backslash, double quote, dollar) from ValidationService::buildIni, which
     * also proves the text reads back (#636). addslashes is wrong here: the INI
     * reader does not undo it, so an apostrophe was corrupted.
     *
     * Refused (returns false, nothing written): a key that is an INI reserved word
     * (NO, ON, OFF, YES, NONE, NULL, TRUE, FALSE), because one such line makes the
     * whole file unreadable. A vault that exists but cannot be parsed is copied to
     * <file>.corrupt-<time> first, so the replacement never destroys it unseen.
     */
    private static function writeVault(string $file, array $map): bool {
        // #646: a test never writes a vault under a live root.
        ConfigService::refuseLiveStateInTests($file);
        // Pre-create parent 0700. AtomicWriteService::write would create it 0755
        // which is wrong for the secrets/ subdir.
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        } else {
            @chmod($dir, 0700);
        }

        $clean = [];
        foreach ($map as $k => $v) {
            // Final shape gate at write time.
            if (!preg_match('/^[A-Z][A-Z0-9_]{1,127}$/D', (string)$k)) continue;
            if (ValidationService::isReservedIniKey($k)) {
                LogService::log("Secret store: refused the key '$k': it is an INI reserved word.", LogService::LOG_WARN, 'SecretService');
                return false;
            }
            $clean[$k] = (string)$v;
        }
        $content = ValidationService::buildIni($clean);
        if ($content === false) {
            LogService::log('Secret store: refused to write: a value does not survive the INI round trip.', LogService::LOG_ERROR, 'SecretService');
            return false;
        }

        if (file_exists($file) && self::readIniStrict($file) === null) {
            $copy = $file . '.corrupt-' . date('Ymd-His');
            if (@copy($file, $copy)) @chmod($copy, 0600);
            LogService::log('Secret store: the existing file could not be parsed; a copy was kept at ' . basename($copy), LogService::LOG_WARN, 'SecretService');
        }

        // 0600 from the first byte: the temp file is never group- or world-readable.
        $ok = \AICliAgents\Services\AtomicWriteService::write($file, $content, 0600);
        if ($ok) {
            $problem = self::modeProblem($file);
            if ($problem !== null && !self::$modeWarned) {
                self::$modeWarned = true;
                LogService::log('Secret store: ' . $problem, LogService::LOG_WARN, 'SecretService');
            }
        }
        return $ok;
    }

    /** Log the loose-mode warning once per process, not on every save. */
    private static bool $modeWarned = false;

    /**
     * #666 R-167: why the file is not private, or null when it is. On a FAT drive (the Unraid
     * flash at /boot is one) chmod does nothing: the mode that stat shows comes from the mount
     * options, so a vault written there can be readable by other local users whatever this
     * code asks for. See docs/specs/ENV_AND_SECRETS_TIERS.md (Secret file mode on a FAT flash drive).
     */
    public static function modeProblem(string $file): ?string {
        clearstatcache(true, $file);
        $perm = @fileperms($file);
        if ($perm === false) return null;
        $perm &= 0777;
        if (($perm & 0077) === 0) return null;
        return sprintf('%s has mode %04o, not 0600. On a FAT drive such as /boot, chmod has no effect and the mount options decide who can read the file.', basename($file), $perm);
    }
}
