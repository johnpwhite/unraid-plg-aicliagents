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

    private const VAULT_FILE          = '/boot/config/plugins/unraid-aicliagents/secrets.cfg';
    private const WS_SECRETS_DIR      = '/boot/config/plugins/unraid-aicliagents/secrets';
    private const FREEFORM_KEYS_FILE  = '/boot/config/plugins/unraid-aicliagents/secrets_freeform_keys.json';

    // ---------- Paths ----------

    public static function getAgentSecretsPath(): string {
        return self::VAULT_FILE;
    }

    public static function getWorkspaceSecretsDir(): string {
        return self::WS_SECRETS_DIR;
    }

    public static function getWorkspaceSecretsPath(string $path, string $agentId): string {
        $hash = md5($path);
        return self::WS_SECRETS_DIR . "/ws_{$hash}_{$agentId}.cfg";
    }

    public static function getFreeformKeysPath(): string {
        return self::FREEFORM_KEYS_FILE;
    }

    // ---------- Free-form-keys sidecar (tracks which agent-vault keys came from
    //            the new free-form Secrets sub-section vs the legacy schema/vault
    //            save path — lets save_agent_secrets manage only its own keys
    //            without touching schema-managed entries). ----------

    public static function getFreeformKeys(): array {
        if (!file_exists(self::FREEFORM_KEYS_FILE)) return [];
        $decoded = json_decode((string)@file_get_contents(self::FREEFORM_KEYS_FILE), true);
        return is_array($decoded) ? array_values(array_filter(array_map('strval', $decoded))) : [];
    }

    public static function setFreeformKeys(array $keys): bool {
        $unique = array_values(array_unique(array_map('strval', $keys)));
        $dir = dirname(self::FREEFORM_KEYS_FILE);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $ok = \AICliAgents\Services\AtomicWriteService::writeJson(self::FREEFORM_KEYS_FILE, $unique);
        if ($ok) @chmod(self::FREEFORM_KEYS_FILE, 0600);
        return $ok;
    }

    // ---------- Raw reads (server-side only — for launch-time env injection) ----------

    public static function getAgentSecrets(): array {
        return self::readIni(self::VAULT_FILE);
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
        return self::writeVault(self::VAULT_FILE, $map);
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
        if (!file_exists($file)) return [];
        $parsed = @parse_ini_file($file);
        if (!is_array($parsed)) return [];
        $out = [];
        foreach ($parsed as $k => $v) {
            // Defence: strip anything that doesn't look like a legitimate env name.
            // The vault was written with the same shape constraint; this just
            // protects against external edits.
            if (preg_match('/^[A-Z][A-Z0-9_]{1,127}$/', (string)$k)) {
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
     * Format matches saveVault's existing on-disk shape: KEY="value" per line,
     * double-quoted, embedded quotes addslashes-escaped.
     */
    private static function writeVault(string $file, array $map): bool {
        // Pre-create parent 0700. AtomicWriteService::write would create it 0755
        // which is wrong for the secrets/ subdir.
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        } else {
            @chmod($dir, 0700);
        }

        $content = '';
        foreach ($map as $k => $v) {
            // Final shape gate at write time.
            if (!preg_match('/^[A-Z][A-Z0-9_]{1,127}$/', (string)$k)) continue;
            $content .= $k . '="' . addslashes((string)$v) . '"' . PHP_EOL;
        }

        if (!\AICliAgents\Services\AtomicWriteService::write($file, $content)) {
            return false;
        }
        @chmod($file, 0600);
        return true;
    }
}
