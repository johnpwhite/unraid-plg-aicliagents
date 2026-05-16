<?php
/**
 * <module_context>
 *     <name>SshKeyService</name>
 *     <description>Manages forced-command SSH key entries in authorized_keys for the configured plugin user. Keys are tagged # aicli-managed. A JSON sidecar on Flash (/boot/config/plugins/unraid-aicliagents/ssh_keys.json) persists label, fingerprint, date, os_hint, and pubkey for root persistence and non-root rebuildOnBoot.</description>
 *     <dependencies>ConfigService, AtomicWriteService</dependencies>
 *     <constraints>Static methods only. NEVER use shell_exec — all subprocess calls use proc_open with array args. Under 150 lines.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class SshKeyService {

    private const SIDECAR       = '/boot/config/plugins/unraid-aicliagents/ssh_keys.json';
    private const ATTACH_SCRIPT = '/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/ssh-attach.sh';
    private const VALID_TYPES   = ['ssh-ed25519','ssh-rsa','ecdsa-sha2-nistp256','ecdsa-sha2-nistp384','ecdsa-sha2-nistp521','sk-ssh-ed25519@openssh.com','sk-ecdsa-sha2-nistp256@openssh.com'];

    // ---------- Public API ----------

    public static function addKey(string $pubkey, string $label, string $userAgent): array {
        $pubkey = trim($pubkey);
        if (!self::validatePubkeyFormat($pubkey)) {
            return ['status' => 'error', 'message' => 'Invalid public key format'];
        }
        $fp = self::getFingerprint($pubkey);
        if ($fp === '') {
            return ['status' => 'error', 'message' => 'Could not compute fingerprint — is openssh-client installed?'];
        }
        // Sidecar is the source of truth for "is this fingerprint registered" — the
        // SHA256: fingerprint string lives in the JSON, never in authorized_keys
        // (which only has the raw pubkey bytes). An earlier substring scan of
        // authorized_keys could not match the fingerprint and silently allowed
        // duplicate lines — hence the sidecar lookup below.
        if (self::hasKey($fp)) {
            return ['status' => 'error', 'message' => 'Key already registered'];
        }
        $authKeys = self::getAuthorizedKeysPath();
        self::ensureSshDir($authKeys);
        $existing = file_exists($authKeys) ? (string)file_get_contents($authKeys) : '';
        $line = 'command="' . self::ATTACH_SCRIPT . '",no-port-forwarding,no-X11-forwarding,no-agent-forwarding,no-pty ' . $pubkey . ' # aicli-managed' . "\n";
        AtomicWriteService::write($authKeys, $existing . $line);
        $sidecar      = self::readSidecar();
        $sidecar[$fp] = ['label' => $label, 'fingerprint' => $fp, 'date' => date('Y-m-d'), 'os_hint' => self::osHint($userAgent), 'pubkey' => $pubkey];
        self::writeSidecar($sidecar);
        LifecycleLogService::log(LifecycleLogService::LEVEL_INFO, 'ssh_keys', 'ssh_key_added', ['label' => $label, 'fingerprint' => $fp, 'os_hint' => self::osHint($userAgent)]);
        return ['status' => 'ok', 'fingerprint' => $fp];
    }

    public static function removeKey(string $fingerprint): void {
        // The fingerprint string (SHA256:abc…) is never present in authorized_keys —
        // only the raw pubkey bytes are. Look up the pubkey from the sidecar and use
        // THAT to find matching lines. Previous str_contains($line, $fingerprint)
        // never matched, so removed keys stayed valid on the server.
        $sidecar = self::readSidecar();
        $pubkey  = $sidecar[$fingerprint]['pubkey'] ?? '';
        $label   = $sidecar[$fingerprint]['label']  ?? '';
        $authKeys = self::getAuthorizedKeysPath();
        if ($pubkey !== '' && file_exists($authKeys)) {
            $lines    = file($authKeys, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $filtered = array_filter($lines, fn($l) => !(str_contains($l, '# aicli-managed') && str_contains($l, $pubkey)));
            $body     = count($filtered) ? implode("\n", $filtered) . "\n" : '';
            AtomicWriteService::write($authKeys, $body);
        }
        unset($sidecar[$fingerprint]);
        self::writeSidecar($sidecar);
        LifecycleLogService::log(LifecycleLogService::LEVEL_INFO, 'ssh_keys', 'ssh_key_removed', ['label' => $label, 'fingerprint' => $fingerprint]);
    }

    public static function listKeys(): array {
        $sidecar = self::readSidecar();
        return array_values(array_map(
            fn($e) => ['label' => $e['label'], 'fingerprint' => $e['fingerprint'], 'date' => $e['date'], 'os_hint' => $e['os_hint']],
            $sidecar
        ));
    }

    public static function getFingerprint(string $pubkey): string {
        $tmp   = sys_get_temp_dir() . '/aicli-sshkey-' . uniqid('', true) . '.pub';
        file_put_contents($tmp, $pubkey . "\n");
        $pipes = [];
        $proc  = proc_open(
            ['ssh-keygen', '-l', '-f', $tmp],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $out = '';
        if (is_resource($proc)) {
            fclose($pipes[0]);
            $out = (string)stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
        }
        @unlink($tmp);
        $parts = explode(' ', trim($out));
        return (isset($parts[1]) && strncmp($parts[1], 'SHA256:', 7) === 0) ? $parts[1] : '';
    }

    public static function validatePubkeyFormat(string $pubkey): bool {
        $parts = explode(' ', $pubkey, 3);
        if (count($parts) < 2) return false;
        if (!in_array($parts[0], self::VALID_TYPES, true)) return false;
        return base64_decode($parts[1], true) !== false;
    }

    public static function hasKey(string $fingerprint): bool {
        $sidecar = self::readSidecar();
        return isset($sidecar[$fingerprint]);
    }

    public static function getAttachScript(): string {
        return self::ATTACH_SCRIPT;
    }

    public static function rebuildOnBoot(): void {
        $config = ConfigService::getConfig();
        $user   = $config['user'] ?? 'root';
        if ($user === '' || $user === 'root') return; // Unraid persists root's authorized_keys automatically
        $sidecar = self::readSidecar();
        if (empty($sidecar)) return;
        $authKeys = self::getAuthorizedKeysPath();
        self::ensureSshDir($authKeys);
        $existing = file_exists($authKeys) ? (string)file_get_contents($authKeys) : '';
        $lines    = array_filter(explode("\n", $existing), fn($l) => !str_contains($l, '# aicli-managed'));
        $clean    = rtrim(implode("\n", $lines));
        foreach ($sidecar as $entry) {
            $clean .= "\n" . 'command="' . self::ATTACH_SCRIPT . '",no-port-forwarding,no-X11-forwarding,no-agent-forwarding,no-pty ' . $entry['pubkey'] . ' # aicli-managed';
        }
        AtomicWriteService::write($authKeys, $clean . "\n");
    }

    // ---------- Private ----------

    private static function getAuthorizedKeysPath(): string {
        $config = ConfigService::getConfig();
        $user   = $config['user'] ?? 'root';
        if ($user === '' || $user === 'root') return '/root/.ssh/authorized_keys';
        $pw   = function_exists('posix_getpwnam') ? posix_getpwnam($user) : false;
        $home = is_array($pw) ? $pw['dir'] : "/home/$user";
        return "$home/.ssh/authorized_keys";
    }

    private static function ensureSshDir(string $authKeysPath): void {
        $dir = dirname($authKeysPath);
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
    }

    private static function osHint(string $ua): string {
        if (stripos($ua, 'Windows') !== false) return 'windows';
        if (stripos($ua, 'Macintosh') !== false || stripos($ua, 'Mac OS X') !== false) return 'mac';
        return 'linux';
    }

    private static function readSidecar(): array {
        if (!file_exists(self::SIDECAR)) return [];
        $data = json_decode((string)@file_get_contents(self::SIDECAR), true);
        return is_array($data) ? $data : [];
    }

    private static function writeSidecar(array $data): void {
        $dir = dirname(self::SIDECAR);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        AtomicWriteService::writeJson(self::SIDECAR, $data);
    }
}
