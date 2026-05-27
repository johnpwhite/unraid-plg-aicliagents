<?php
/**
 * <module_context>
 *     <name>SshHandler</name>
 *     <description>AJAX handler for SSH launch link management: add/remove/list keys and check SSH daemon status. Reads /boot/config/ident.cfg for USE_SSH state.</description>
 *     <dependencies>SshKeyService</dependencies>
 *     <constraints>Static methods only. Under 150 lines. Delegates all key logic to SshKeyService.</constraints>
 * </module_context>
 */

namespace AICliAgents\Handlers;

use AICliAgents\Services\SshKeyService;

class SshHandler {

    public static function actions(): array {
        return ['add_key', 'remove_key', 'list_keys', 'check_ssh_enabled'];
    }

    public static function handle(string $action, string $id): ?array {
        switch ($action) {
            case 'add_key':           return self::addKey();
            case 'remove_key':        return self::removeKey();
            case 'list_keys':         return self::listKeys();
            case 'check_ssh_enabled': return self::checkSshEnabled();
            default:                  return null;
        }
    }

    private static function addKey(): array {
        $pubkey = trim($_POST['pubkey'] ?? '');
        $label  = trim($_POST['label']  ?? '');
        $ua     = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if ($pubkey === '') return ['status' => 'error', 'message' => 'pubkey is required'];
        if ($label  === '') $label = 'Unnamed key';
        return SshKeyService::addKey($pubkey, $label, $ua);
    }

    private static function removeKey(): array {
        $fp = trim($_REQUEST['fingerprint'] ?? '');
        if ($fp === '') return ['status' => 'error', 'message' => 'fingerprint is required'];
        SshKeyService::removeKey($fp);
        return ['status' => 'ok'];
    }

    private static function listKeys(): array {
        return ['status' => 'ok', 'keys' => SshKeyService::listKeys()];
    }

    private static function checkSshEnabled(): array {
        $cfg = '/boot/config/ident.cfg';
        // Normalize configured user — '0' is a legacy invalid value from a select-index bug
        $pluginCfg = \AICliAgents\Services\ConfigService::getConfig();
        $sshUser = $pluginCfg['user'] ?? 'root';
        if (empty($sshUser)) $sshUser = 'root';
        if (!file_exists($cfg)) return ['enabled' => false, 'client_has_key' => false, 'user' => $sshUser];
        $content = (string)@file_get_contents($cfg);
        $enabled = (bool)preg_match('/^USE_SSH\s*=\s*"?yes"?/mi', $content);
        // Server-scoped, not browser-scoped: if ANY key is registered on this
        // Unraid box, every browser shows the SSH launch button. The OS-level
        // private key is what actually gates the connection — the cookie-based
        // per-browser check was solving a fake problem.
        $clientHasKey = !empty(SshKeyService::listKeys());
        return ['enabled' => $enabled, 'client_has_key' => $clientHasKey, 'user' => $sshUser];
    }
}
