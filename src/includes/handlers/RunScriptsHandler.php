<?php
/**
 * <module_context>
 *     <name>RunScriptsHandler</name>
 *     <description>AJAX handler for the Settings card "Script runs": it lists, adds, changes and removes the entries
 *     of the Authorised scripts list (docs/specs/AGENT_RUN_ENTRY_POINT.md R9, issue #745). The Settings page is
 *     the only place that calls it. An agent has no tool for these actions.</description>
 *     <dependencies>AgentRunService</dependencies>
 *     <constraints>Static methods only. The dispatcher (AICliAjax.php) has checked the CSRF token before this class
 *     runs. All rules are in AgentRunService; this class only reads the request and shapes the answer.</constraints>
 * </module_context>
 */

namespace AICliAgents\Handlers;

use AICliAgents\Services\AgentRunService;

class RunScriptsHandler {

    public static function actions(): array {
        return ['run_scripts_list', 'run_scripts_save', 'run_scripts_remove', 'run_scripts_hash', 'run_scripts_user_scripts', 'run_scripts_repoint'];
    }

    public static function handle(string $action, string $id): ?array {
        switch ($action) {
            case 'run_scripts_list':   return ['status' => 'ok', 'enabled' => AgentRunService::enabled(), 'scripts' => AgentRunService::describeList(), 'agentChoices' => AgentRunService::agentChoices()];
            case 'run_scripts_save':   return self::save();
            case 'run_scripts_remove': return self::remove();
            case 'run_scripts_hash':   return self::hash();
            case 'run_scripts_user_scripts': return self::userScripts();
            case 'run_scripts_repoint': return self::repoint();
            default:                   return null;
        }
    }

    private static function save(): array {
        $in = [];
        foreach (['id', 'label', 'description', 'path', 'agents', 'folders', 'maxMode', 'users', 'maxPerDay', 'authoriseCurrent'] as $key) {
            if (isset($_POST[$key]) && is_scalar($_POST[$key])) $in[$key] = (string)$_POST[$key];
        }
        $res = AgentRunService::saveFromForm($in);
        return isset($res['error']) ? ['status' => 'error', 'message' => (string)$res['error']] : ['status' => 'ok', 'entry' => $res['entry']];
    }

    private static function remove(): array {
        $id = trim((string)($_POST['id'] ?? ''));
        if ($id === '') return ['status' => 'error', 'message' => 'id is required'];
        $res = AgentRunService::removeEntry($id);
        return isset($res['error']) ? ['status' => 'error', 'message' => (string)$res['error']] : ['status' => 'ok'];
    }

    private static function hash(): array {
        $path = (string)($_POST['path'] ?? $_GET['path'] ?? '');
        $path = trim($path);
        $resolved = @realpath($path);
        $copyPath = $resolved !== false ? $resolved : $path;
        if (\AICliAgents\Services\RunCallerIdentity::isUserScriptsCopy($copyPath)) {
            return ['status' => 'ok', 'temporaryCopy' => true,
                'originalPath' => \AICliAgents\Services\RunCallerIdentity::originalForUserScriptsCopy($copyPath),
                'message' => AgentRunService::TEMPORARY_SCRIPT_MESSAGE];
        }
        $temporaryMessage = AgentRunService::temporaryPathMessage($path);
        if ($temporaryMessage !== null) return ['status' => 'error', 'message' => $temporaryMessage];
        $res = AgentRunService::hashOfFile($path);
        return isset($res['error']) ? ['status' => 'error', 'message' => (string)$res['error']] : ['status' => 'ok', 'path' => $res['path'], 'sha256' => $res['sha256']];
    }

    private static function userScripts(): array {
        $res = AgentRunService::userScripts();
        return ['status' => 'ok'] + $res;
    }

    private static function repoint(): array {
        $id = trim((string)($_POST['id'] ?? ''));
        if ($id === '') return ['status' => 'error', 'message' => 'id is required'];
        $sha256 = trim((string)($_POST['sha256'] ?? ''));
        $res = AgentRunService::repointEntry($id, $sha256);
        return isset($res['error'])
            ? ['status' => 'error', 'message' => (string)$res['error']]
            : ['status' => 'ok', 'entry' => $res['entry'], 'merged' => !empty($res['merged'])];
    }
}
