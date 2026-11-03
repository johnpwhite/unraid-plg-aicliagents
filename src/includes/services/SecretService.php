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

require_once __DIR__ . '/FileLock.php';

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
        $ok = false;
        $busy = false;
        self::withLock(static function () use ($file, $unique, &$ok) {
            $ok = \AICliAgents\Services\AtomicWriteService::writeJson($file, $unique);
            if ($ok) @chmod($file, 0600);
        }, $busy);
        return !$busy && $ok;
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
     * #890: this REPLACES the vault with no read. A caller that starts from the
     * stored map (read, change, write back) must use updateAgentSecrets() instead,
     * or two saves at once can lose a key. A guard test pins that.
     *
     * Map shape: ['UPPER_SNAKE_KEY' => 'value', ...]. Keys not matching the
     * shape are silently dropped — defence in depth against a corrupt POST.
     */
    public static function saveAgentSecrets(array $map): bool {
        $ok = false;
        $busy = false;
        self::withLock(static function () use ($map, &$ok) { $ok = self::writeVault(self::getAgentSecretsPath(), $map); }, $busy);
        return !$busy && $ok;
    }

    /**
     * Workspace secrets: empty $map unlinks the file (no zero-key artefacts).
     * Otherwise writes 600 in the secrets/ subdir (created 700 if missing).
     */
    public static function saveWorkspaceSecrets(string $path, string $agentId, array $map): bool {
        $file = self::getWorkspaceSecretsPath($path, $agentId);
        $ok = false;
        $busy = false;
        self::withLock(static function () use ($file, $map, &$ok) {
            if (empty($map)) {
                if (file_exists($file)) @unlink($file);
                $ok = true;
                return;
            }
            $ok = self::writeVault($file, $map);
        }, $busy);
        return !$busy && $ok;
    }

    // ---------- Locked read-modify-write (#890) ----------
    // Every writer that starts from the stored map goes through updateAgentSecrets(),
    // updateWorkspaceSecrets() or updateFreeformKeys(). One lock covers the vault, the
    // workspace secret files and the free-form key list, so two saves at once cannot lose a key.

    /** Seconds an update waits for the secret lock before it gives up. Tests shorten it. */
    public static float $lockWaitSeconds = 10.0;
    private static ?string $lastUpdateMessage = null;
    private static ?string $lastUpdateNotice = null;

    /** Lock file for the secret store. In the RAM state root, never on flash or /mnt/user. */
    public static function lockPath(): string {
        return rtrim(SecretPaths::stateRoot(), '/') . '/secrets.lock';
    }

    /** Plain-words reason the last update failed in this process, or null. Show it to the person. */
    public static function lastUpdateMessage(): ?string {
        return self::$lastUpdateMessage;
    }

    /**
     * Run $fn while holding the secret lock. LOCK_EX|LOCK_NB in a poll loop with a deadline,
     * never a blocking flock. Re-entrant in one process. At the deadline, or when no lock file
     * can be opened, $fn is NOT run and $busy is true: the caller must report the lost save.
     * (The vault differs from the workspace list here: with no lock we do not write.)
     *
     * @param bool|null $busy set to true when the lock was not taken
     * @return mixed
     */
    public static function withLock(callable $fn, ?bool &$busy = null, string $what = 'secret store') {
        $busy = false;
        $path = self::lockPath();
        if (!FileLock::isHeld($path)) {
            self::$lastUpdateMessage = null;
            self::$lastUpdateNotice = null;
        }
        $result = FileLock::run($path, self::$lockWaitSeconds, $fn, $busy);
        if ($busy) self::busyMessage($what);
        return $result;
    }

    /** A message for the person after a save that WORKED but needs their attention (a damaged file was set aside), or null. */
    public static function lastUpdateNotice(): ?string {
        return self::$lastUpdateNotice;
    }

    /**
     * Change the agent vault under the lock. $fn gets the current map and returns the new
     * map to write, or null for "no change" (nothing is written, the result is true).
     * False means nothing was written: the lock stayed busy, the vault cannot be read, or the
     * write failed. lastUpdateMessage() then says why. A damaged file is copied to <file>.corrupt-* (#636) and the update goes on with an empty map; lastUpdateNotice() then tells the person.
     *
     * @param callable(array):(?array) $fn
     */
    public static function updateAgentSecrets(callable $fn): bool {
        return self::updateFile(self::getAgentSecretsPath(), $fn, 'secret vault');
    }

    /** Same as updateAgentSecrets for the secrets of one workspace and agent. An empty new map removes the file. */
    public static function updateWorkspaceSecrets(string $path, string $agentId, callable $fn): bool {
        return self::updateFile(self::getWorkspaceSecretsPath($path, $agentId), $fn, 'workspace secrets', true);
    }

    /** Change the free-form key list under the lock. $fn gets the list and returns the new list, or null for no change. */
    public static function updateFreeformKeys(callable $fn): bool {
        $ok = false;
        $busy = false;
        self::withLock(static function () use ($fn, &$ok) {
            $next = $fn(self::getFreeformKeys());
            $ok = $next === null ? true : self::setFreeformKeys($next);
        }, $busy, 'free-form key list');
        if ($busy) return false;
        if (!$ok) self::$lastUpdateMessage = 'The list of free-form secret names could not be saved.';
        return $ok;
    }

    private static function busyMessage(string $what): void {
        self::$lastUpdateMessage = 'The ' . $what . ' is busy with another save, so this change was not saved. Retry in a moment.';
        LogService::log('Secret store: NOT SAVED: the lock stayed busy for ' . self::$lockWaitSeconds . 's (' . $what . ')', LogService::LOG_ERROR, 'SecretService');
    }

    private static function updateFile(string $file, callable $fn, string $what, bool $emptyRemoves = false): bool {
        ConfigService::refuseLiveStateInTests($file);
        $ok = false;
        $busy = false;
        self::withLock(static function () use ($file, $fn, $what, $emptyRemoves, &$ok) {
            // Read INSIDE the lock, strictly.
            $current = self::readIniStrict($file);
            $damaged = $current === null;
            if ($damaged) $current = [];
            $next = $fn($current);
            if ($next === null) {
                $ok = true;
                if ($damaged) self::$lastUpdateNotice = 'The ' . $what . ' file is damaged and could not be read. Nothing was changed. The next save sets it aside as a copy and starts a new file.';
                return;
            }
            // #636: a file that exists but cannot be parsed is never replaced or removed unseen.
            // Keep a copy first, then go on, so one damaged file never blocks every save.
            if ($damaged) {
                $copy = FileLock::keepCorruptCopy($file);
                if ($copy === null) {
                    self::$lastUpdateMessage = 'The ' . $what . ' file is damaged and a copy of it could not be made, so nothing was saved. Check the disk, then retry.';
                    LogService::log('Secret store: the damaged file ' . basename($file) . ' could not be copied; nothing was saved.', LogService::LOG_ERROR, 'SecretService');
                    return;
                }
                self::$lastUpdateNotice = 'The ' . $what . ' file was damaged and could not be read. It is kept as ' . $copy . ' next to it. Your change is saved in a new file. To get back an older value, open the copy in an editor and enter the value again.';
                FileLock::notifyDamagedFile(self::$lastUpdateNotice);
                LogService::log('Secret store: the existing file could not be parsed; a copy was kept at ' . $copy, LogService::LOG_WARN, 'SecretService');
            }
            if ($emptyRemoves && empty($next)) {
                if (file_exists($file)) @unlink($file);
                $ok = true;
                return;
            }
            $ok = self::writeVault($file, $next, $damaged);
            if (!$ok) self::$lastUpdateMessage = 'The ' . $what . ' could not be written.';
        }, $busy, $what);
        if ($busy) return false;
        return $ok;
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
    private static function writeVault(string $file, array $map, bool $copyKept = false): bool {
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

        if (!$copyKept && file_exists($file) && self::readIniStrict($file) === null) {
            $copy = FileLock::keepCorruptCopy($file);
            LogService::log('Secret store: the existing file could not be parsed; a copy was kept at ' . ($copy ?? '(no copy could be made)'), LogService::LOG_WARN, 'SecretService');
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
