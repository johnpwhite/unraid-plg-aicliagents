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
        return ['run_scripts_list', 'run_scripts_save', 'run_scripts_remove', 'run_scripts_hash'];
    }

    public static function handle(string $action, string $id): ?array {
        switch ($action) {
            case 'run_scripts_list':   return ['status' => 'ok', 'enabled' => AgentRunService::enabled(), 'scripts' => AgentRunService::describeList()];
            case 'run_scripts_save':   return self::save();
            case 'run_scripts_remove': return self::remove();
            case 'run_scripts_hash':   return self::hash();
            default:                   return null;
        }
    }

    private static function save(): array {
        $in = [];
        foreach (['id', 'label', 'path', 'agents', 'folders', 'maxMode', 'users', 'maxPerDay', 'authoriseCurrent'] as $key) {
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
        $res = AgentRunService::hashOfFile((string)($_POST['path'] ?? $_GET['path'] ?? ''));
        return isset($res['error']) ? ['status' => 'error', 'message' => (string)$res['error']] : ['status' => 'ok', 'path' => $res['path'], 'sha256' => $res['sha256']];
    }
}
