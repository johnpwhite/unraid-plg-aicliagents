<?php
/**
 * <module_context>
 *     <name>TmuxHandler</name>
 *     <description>AJAX actions for per-workspace+agent tmux settings and live apply.</description>
 *     <dependencies>TmuxService</dependencies>
 *     <constraints>Under 100 lines. CSRF done at dispatcher (AICliAjax.php).</constraints>
 * </module_context>
 */

namespace AICliAgents\Handlers;

require_once '/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/services/TmuxService.php';

use AICliAgents\Services\TmuxService;

class TmuxHandler {

    public static function handle($action, $id) {
        switch ($action) {
            case 'tmux_get_settings':           return self::getSettings();
            case 'tmux_save_settings':          return self::saveSettings();
            case 'tmux_apply_settings':         return self::applySettings();
            case 'tmux_reload_conf':            return self::reloadConf();
            case 'tmux_restart_session':        return self::restartSession($id);
            // Four-tier (agent defaults vs workspace overrides) endpoints.
            case 'tmux_get_agent_defaults':     return self::getAgentDefaults();
            case 'tmux_save_agent_defaults':    return self::saveAgentDefaults();
            case 'tmux_get_workspace_overrides':return self::getWorkspaceOverrides();
            case 'tmux_save_workspace_overrides': return self::saveWorkspaceOverrides();
            case 'tmux_get_effective':          return self::getEffective();
            default:                            return null;
        }
    }

    public static function actions() {
        return ['tmux_get_settings', 'tmux_save_settings', 'tmux_apply_settings',
                'tmux_reload_conf', 'tmux_restart_session',
                'tmux_get_agent_defaults', 'tmux_save_agent_defaults',
                'tmux_get_workspace_overrides', 'tmux_save_workspace_overrides',
                'tmux_get_effective'];
    }

    private static function args() {
        return [
            'path'    => $_POST['path']    ?? $_GET['path']    ?? '',
            'agentId' => $_POST['agentId'] ?? $_GET['agentId'] ?? '',
        ];
    }

    private static function getSettings() {
        $a = self::args();
        if (empty($a['path']) || empty($a['agentId'])) {
            return ['status' => 'error', 'message' => 'path and agentId required'];
        }
        return [
            'status'   => 'ok',
            'settings' => TmuxService::getSettings($a['path'], $a['agentId']),
            'confPath' => TmuxService::getConfPath($a['path'], $a['agentId']),
            'confExists' => file_exists(TmuxService::getConfPath($a['path'], $a['agentId'])),
            'allowedKeys' => TmuxService::ALLOWED_KEYS,
        ];
    }

    private static function saveSettings() {
        $a = self::args();
        if (empty($a['path']) || empty($a['agentId'])) {
            return ['status' => 'error', 'message' => 'path and agentId required'];
        }
        $raw = $_POST['settings'] ?? $_GET['settings'] ?? '{}';
        $settings = json_decode($raw, true);
        if (!is_array($settings)) {
            return ['status' => 'error', 'message' => 'settings must be a JSON object'];
        }
        $ok = TmuxService::saveSettings($a['path'], $a['agentId'], $settings);
        return $ok
            ? ['status' => 'ok']
            : ['status' => 'error', 'message' => 'Failed to persist settings'];
    }

    private static function applySettings() {
        $a = self::args();
        if (empty($a['path']) || empty($a['agentId'])) {
            return ['status' => 'error', 'message' => 'path and agentId required'];
        }
        $r = TmuxService::applySettings($a['path'], $a['agentId']);
        return ['status' => 'ok'] + $r;
    }

    private static function reloadConf() {
        $a = self::args();
        if (empty($a['path']) || empty($a['agentId'])) {
            return ['status' => 'error', 'message' => 'path and agentId required'];
        }
        return TmuxService::reloadConf($a['path'], $a['agentId']);
    }

    private static function restartSession($id) {
        $agentId = $_POST['agentId'] ?? $_GET['agentId'] ?? '';
        if (empty($agentId)) {
            return ['status' => 'error', 'message' => 'agentId required'];
        }
        $sessionId = ($id !== 'default') ? $id : null;
        $killed = TmuxService::killSessions($agentId, $sessionId);
        return ['status' => 'ok', 'killed' => $killed];
    }

    // ---------- Four-tier endpoints ----------

    private static function getAgentDefaults() {
        $agentId = $_POST['agentId'] ?? $_GET['agentId'] ?? '';
        if (empty($agentId)) return ['status' => 'error', 'message' => 'agentId required'];
        return [
            'status'      => 'ok',
            'settings'    => TmuxService::getAgentDefaults($agentId),
            'builtin'     => TmuxService::BUILTIN,
            'allowedKeys' => TmuxService::ALLOWED_KEYS,
        ];
    }

    private static function saveAgentDefaults() {
        $agentId = $_POST['agentId'] ?? $_GET['agentId'] ?? '';
        if (empty($agentId)) return ['status' => 'error', 'message' => 'agentId required'];
        $raw = $_POST['settings'] ?? $_GET['settings'] ?? '{}';
        $settings = json_decode($raw, true);
        if (!is_array($settings)) return ['status' => 'error', 'message' => 'settings must be a JSON object'];
        $ok = TmuxService::saveAgentDefaults($agentId, $settings);
        return $ok ? ['status' => 'ok'] : ['status' => 'error', 'message' => 'Failed to persist agent defaults'];
    }

    private static function getWorkspaceOverrides() {
        $a = self::args();
        if (empty($a['path']) || empty($a['agentId'])) {
            return ['status' => 'error', 'message' => 'path and agentId required'];
        }
        // Return both tiers so the drawer can pre-fill with agent defaults and flag diffs client-side.
        $agent = TmuxService::getAgentDefaults($a['agentId']);
        $merged = array_merge(TmuxService::BUILTIN, $agent);
        return [
            'status'        => 'ok',
            'overrides'     => TmuxService::getWorkspaceOverrides($a['path'], $a['agentId']),
            'agentDefaults' => $merged,
            'builtin'       => TmuxService::BUILTIN,
            'allowedKeys'   => TmuxService::ALLOWED_KEYS,
            'confPath'      => TmuxService::getConfPath($a['path'], $a['agentId']),
            'confExists'    => file_exists(TmuxService::getConfPath($a['path'], $a['agentId'])),
        ];
    }

    private static function saveWorkspaceOverrides() {
        $a = self::args();
        if (empty($a['path']) || empty($a['agentId'])) {
            return ['status' => 'error', 'message' => 'path and agentId required'];
        }
        $raw = $_POST['settings'] ?? $_GET['settings'] ?? '{}';
        $settings = json_decode($raw, true);
        if (!is_array($settings)) return ['status' => 'error', 'message' => 'settings must be a JSON object'];
        $ok = TmuxService::saveWorkspaceOverrides($a['path'], $a['agentId'], $settings);
        return $ok ? ['status' => 'ok'] : ['status' => 'error', 'message' => 'Failed to persist overrides'];
    }

    private static function getEffective() {
        $a = self::args();
        if (empty($a['path']) || empty($a['agentId'])) {
            return ['status' => 'error', 'message' => 'path and agentId required'];
        }
        return [
            'status'    => 'ok',
            'effective' => TmuxService::getEffectiveSettings($a['path'], $a['agentId']),
        ];
    }
}
