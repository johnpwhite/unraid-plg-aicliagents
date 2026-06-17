<?php
/**
 * <module_context>
 *     <name>GitHandler</name>
 *     <description>AJAX handler for the git-backed home config layer (OP #1365 /
 *     finding H-04). Split out of HubHandler by the audit's size guidance
 *     (HubHandler was already 300 lines; combined they would exceed the ~400-line
 *     practical handler ceiling). Actions: hub_git_status / hub_git_init /
 *     hub_git_commit / hub_git_log / hub_git_diff / hub_git_restore /
 *     hub_git_set_remote / hub_git_push. hub_git_init flips hub_git_enabled=1
 *     (+ hub_git_autocommit default-on) in the plugin config AFTER a successful
 *     GitHomeService::init(). hub_git_set_remote optionally stores the PAT into
 *     the existing SecretService agent vault under HUB_GIT_TOKEN (merge, not
 *     replace; registered as a free-form key so the Secrets UI lists it) — the
 *     token value is never logged and never echoed back.</description>
 *     <dependencies>GitHomeService, ConfigService, SecretService</dependencies>
 *     <constraints>Static methods only. All inputs validated before use (paths/
 *     refs re-validated inside GitHomeService too — defence in depth). Follows
 *     the HubHandler registration shape in AICliAjax.php. Push is EXPLICIT only;
 *     nothing in this handler is called on a timer.</constraints>
 * </module_context>
 */

namespace AICliAgents\Handlers;

use AICliAgents\Services\ConfigService;
use AICliAgents\Services\Hub\GitHomeService;
use AICliAgents\Services\SecretService;

class GitHandler
{
    public static function handle($action, $id): ?array
    {
        switch ($action) {
            case 'hub_git_status':     return GitHomeService::status();
            case 'hub_git_init':       return self::init();
            case 'hub_git_commit':     return self::commit();
            case 'hub_git_log':        return self::log();
            case 'hub_git_diff':       return self::diff();
            case 'hub_git_restore':    return self::restore();
            case 'hub_git_restore_settings': return GitHomeService::restorePluginSettings();
            case 'hub_git_set_remote': return self::setRemote();
            case 'hub_git_push':       return GitHomeService::push();
            default:                   return null;
        }
    }

    /** Actions handled by this handler. */
    public static function actions(): array
    {
        return ['hub_git_status', 'hub_git_init', 'hub_git_commit', 'hub_git_log',
                'hub_git_diff', 'hub_git_restore', 'hub_git_restore_settings',
                'hub_git_set_remote', 'hub_git_push'];
    }

    // ---------- lifecycle ----------

    /** Enable the layer: init repo + .gitignore, then persist the opt-in config keys. */
    private static function init(): array
    {
        $r = GitHomeService::init();
        if (($r['status'] ?? '') !== 'ok') return $r;
        // Opt-in flips ONLY after the repo actually exists. autocommit defaults
        // on once enabled (user can flip the key off in the .cfg if undesired).
        $config = ConfigService::getConfig();
        $new = ['hub_git_enabled' => '1'];
        if (!isset($config['hub_git_autocommit'])) $new['hub_git_autocommit'] = '1';
        ConfigService::saveConfig($new, false);
        return $r;
    }

    /** Manual commit. POST: message (sanitized in the service; names only by convention). */
    private static function commit(): array
    {
        $message = trim((string)($_REQUEST['message'] ?? ''));
        if ($message === '') $message = 'hub: manual commit';
        if (strlen($message) > 200) return ['status' => 'error', 'message' => 'message too long (max 200 chars)'];
        return GitHomeService::commit($message);
    }

    private static function log(): array
    {
        $limit = (int)($_REQUEST['limit'] ?? 20);
        return GitHomeService::log(max(1, min(50, $limit)));
    }

    private static function diff(): array
    {
        [$path, $ref, $err] = self::pathRef();
        if ($err !== null) return ['status' => 'error', 'message' => $err];
        return GitHomeService::diffFile($path, $ref);
    }

    private static function restore(): array
    {
        [$path, $ref, $err] = self::pathRef();
        if ($err !== null) return ['status' => 'error', 'message' => $err];
        return GitHomeService::restoreFile($path, $ref);
    }

    // ---------- remote ----------

    /**
     * POST: url, token (optional — empty keeps the stored one). The token goes
     * into the existing agent-secrets vault (HUB_GIT_TOKEN) via merge so the
     * standard Secrets UI remains the canonical editor for it.
     */
    private static function setRemote(): array
    {
        $url = trim((string)($_REQUEST['url'] ?? ''));
        if ($url === '') return ['status' => 'error', 'message' => 'url required'];
        $r = GitHomeService::setRemote($url);
        if (($r['status'] ?? '') !== 'ok') return $r;

        $token = (string)($_REQUEST['token'] ?? '');
        if ($token !== '') {
            if (preg_match('/[\x00-\x1f\x7f]/', $token) || strlen($token) > 512) {
                return ['status' => 'error', 'message' => 'invalid token value'];
            }
            $vault = SecretService::getAgentSecrets();
            $vault[GitHomeService::TOKEN_KEY] = $token;
            if (!SecretService::saveAgentSecrets($vault)) {
                return ['status' => 'error', 'message' => 'remote saved but storing the token failed'];
            }
            $free = SecretService::getFreeformKeys();
            if (!in_array(GitHomeService::TOKEN_KEY, $free, true)) {
                $free[] = GitHomeService::TOKEN_KEY;
                SecretService::setFreeformKeys($free);
            }
        }
        $out = ['status' => 'ok'];
        if (!empty($r['insecure'])) {
            $out['warning'] = 'Saved, but this is a plain http:// remote — your token is sent in cleartext on every push. Use https:// unless this is a trusted local network.';
        }
        return $out;
    }

    // ---------- validation ----------

    /** @return array{0:string,1:string,2:?string} [path, ref, error] */
    private static function pathRef(): array
    {
        $path = (string)($_REQUEST['path'] ?? '');
        $ref  = (string)($_REQUEST['ref'] ?? '');
        if ($path === '' || $ref === '') return ['', '', 'path and ref required'];
        if ($path[0] === '/' || strpos($path, '..') !== false || !preg_match('#^[A-Za-z0-9._/ -]{1,256}$#', $path)) {
            return ['', '', 'invalid path'];
        }
        if (!preg_match('/^[0-9a-f]{4,40}$/i', $ref) && $ref !== 'HEAD') {
            return ['', '', 'invalid ref'];
        }
        return [$path, $ref, null];
    }
}
