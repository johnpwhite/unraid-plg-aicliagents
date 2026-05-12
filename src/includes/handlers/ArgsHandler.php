<?php
/**
 * <module_context>
 *     <name>ArgsHandler</name>
 *     <description>AJAX handler for CLI args: save/get agent-level and workspace-level args.</description>
 *     <dependencies>ArgsService, ValidationService</dependencies>
 *     <constraints>Static methods only. All inputs validated via ValidationService before use.</constraints>
 * </module_context>
 */

namespace AICliAgents\Handlers;

use AICliAgents\Services\ArgsService;
use AICliAgents\Services\ValidationService;

class ArgsHandler {

    public static function handle($action, $id): ?array {
        switch ($action) {
            case 'save_agent_args':     return self::saveAgentArgs();
            case 'get_agent_args':      return self::getAgentArgs();
            case 'save_workspace_args': return self::saveWorkspaceArgs();
            case 'get_workspace_args':  return self::getWorkspaceArgs();
            default:                    return null;
        }
    }

    private static function saveAgentArgs(): array {
        $agentId = ValidationService::validateId($_GET['agentId'] ?? '');
        if (!$agentId) return ['status' => 'error', 'message' => 'Missing or invalid agentId'];

        $args   = trim($_REQUEST['args'] ?? '');
        $errors = ArgsService::validateArgs($args);
        if ($errors) {
            return ['status' => 'error', 'message' => 'Rejected chars: ' . implode(', ', $errors)];
        }

        $ok = ArgsService::saveAgentArgs($agentId, $args);
        return $ok ? ['status' => 'ok'] : ['status' => 'error', 'message' => 'Write failed'];
    }

    private static function getAgentArgs(): array {
        $agentId = ValidationService::validateId($_GET['agentId'] ?? '');
        if (!$agentId) return ['status' => 'error', 'message' => 'Missing or invalid agentId'];

        return ['status' => 'ok', 'args' => ArgsService::getAgentArgs($agentId)];
    }

    private static function saveWorkspaceArgs(): array {
        $agentId = ValidationService::validateId($_GET['agentId'] ?? '');
        $path    = $_REQUEST['path'] ?? '';
        if (!$agentId) return ['status' => 'error', 'message' => 'Missing or invalid agentId'];
        if (empty($path)) return ['status' => 'error', 'message' => 'Missing path'];

        $args   = trim($_REQUEST['args'] ?? '');
        $errors = ArgsService::validateArgs($args);
        if ($errors) {
            return ['status' => 'error', 'message' => 'Rejected chars: ' . implode(', ', $errors)];
        }

        $ok = ArgsService::saveWorkspaceArgs($path, $agentId, $args);
        return $ok ? ['status' => 'ok'] : ['status' => 'error', 'message' => 'Write failed'];
    }

    private static function getWorkspaceArgs(): array {
        $agentId = ValidationService::validateId($_GET['agentId'] ?? '');
        $path    = $_REQUEST['path'] ?? '';
        if (!$agentId) return ['status' => 'error', 'message' => 'Missing or invalid agentId'];
        if (empty($path)) return ['status' => 'error', 'message' => 'Missing path'];

        return ['status' => 'ok', 'args' => ArgsService::getWorkspaceArgs($path, $agentId)];
    }
}
