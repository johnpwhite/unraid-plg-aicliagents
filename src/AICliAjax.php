<?php
/**
 * <module_context>
 *     <name>AICliAjax</name>
 *     <description>Thin AJAX dispatcher routing actions to focused handler classes.</description>
 *     <dependencies>AICliAgentsManager, ValidationService, TerminalHandler, StorageHandler, AgentHandler, UtilityHandler</dependencies>
 *     <constraints>Under 100 lines. Shared middleware only: CSRF, error handling, time limits.</constraints>
 * </module_context>
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');
set_time_limit(30);

// Fatal Error Handler
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== NULL && ($error['type'] === E_ERROR || $error['type'] === E_PARSE || $error['type'] === E_CORE_ERROR || $error['type'] === E_COMPILE_ERROR)) {
        $msg = "FATAL PHP ERROR: {$error['message']} in {$error['file']} on line {$error['line']}";
        $logFile = "/tmp/unraid-aicliagents/debug.log";
        @file_put_contents($logFile, "[" . date("Y-m-d H:i:s") . "] $msg\n", FILE_APPEND);
        if (!headers_sent()) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'PHP Fatal Error: Check debug.log', 'details' => $msg]);
        }
    }
});

require_once '/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/AICliAgentsManager.php';
require_once '/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/services/ValidationService.php';
require_once '/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/handlers/TerminalHandler.php';
require_once '/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/handlers/StorageHandler.php';
require_once '/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/handlers/AgentHandler.php';
require_once '/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/handlers/UtilityHandler.php';
require_once '/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/handlers/TmuxHandler.php';
require_once '/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/handlers/ArgsHandler.php';
require_once '/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/handlers/AutoLaunchHandler.php';
require_once '/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/handlers/EnvHandler.php';
use AICliAgents\Services\ValidationService;

if (isset($_GET['action'])) {
    $action = $_GET['action'];
    if ($action !== 'filetree') {
        header('Content-Type: application/json');
    }

    // Validate session ID
    $rawId = $_GET['id'] ?? 'default';
    $id = ValidationService::validateId($rawId) ?: 'default';

    // CSRF Validation (Unraid uses GET with csrf_token in query string - Lesson 5)
    $var = @parse_ini_file("/var/local/emhttp/var.ini");
    $expected = trim((string)($var['csrf_token'] ?? ''));
    $received = $_REQUEST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (is_array($received)) $received = end($received);
    $received = trim((string)$received);

    if (empty($expected) || $received !== $expected) {
        aicli_log("CSRF FAILED! Action: $action (Received: $received, Expected: $expected)", AICLI_LOG_ERROR, "AICliAjax");
        echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF Token']);
        exit;
    }

    try {
        // Log milestone actions at INFO, everything else at DEBUG
        $milestones = ['start', 'stop', 'restart', 'install_agent', 'uninstall_agent', 'consolidate_storage', 'persist_home', 'repair_agent_storage', 'repair_home_storage', 'save', 'wipe_storage'];
        $logLvl = in_array($action, $milestones) ? AICLI_LOG_INFO : AICLI_LOG_DEBUG;
        aicli_log("Handling AJAX Request: $action ($id)", $logLvl, "AICliAjax");

        // D-405: Increase limits for file upload actions (base64 POST data can be large)
        if (in_array($action, ['save_file', 'upload_chunk', 'save_pasted_image'])) {
            @ini_set('post_max_size', '64M');
            @ini_set('upload_max_filesize', '64M');
            set_time_limit(120);
        }

        // Initialize environment (skip mount for non-mount actions)
        $mustMountActions = ['start', 'restart', 'install_agent', 'uninstall_agent', 'expand_storage', 'shrink_storage', 'migrate_agents'];
        aicli_ensure_init(!in_array($action, $mustMountActions));

        // Route to handler - raw output actions first
        if ($action === 'filetree') {
            \AICliAgents\Handlers\UtilityHandler::rawFiletree();
        } elseif ($action === 'get_install_status') {
            \AICliAgents\Handlers\AgentHandler::rawInstallStatus();
        } elseif ($action === 'get_task_status') {
            // Task status file is already JSON - read and output directly.
            // SECURITY: $user gets interpolated into a filesystem path so
            // validate before use. Accept only POSIX-ish usernames or the
            // literal "agents" sentinel.
            $user = $_GET['user'] ?? '';
            if (empty($user)) {
                $type = $_GET['type'] ?? 'agents';
                $user = ($type === 'agents') ? 'agents' : getAICliConfig()['user'];
            }
            if (!preg_match('/^[a-zA-Z0-9._-]{1,64}$/', (string)$user)) {
                header('Content-Type: application/json');
                echo json_encode(['progress' => 0, 'step' => 'invalid user']);
            } else {
                $file = "/tmp/unraid-aicliagents/task-status-$user";
                header('Content-Type: application/json');
                // nosemgrep: php.lang.security.injection.echoed-request.echoed-request
                if (file_exists($file)) echo file_get_contents($file);
                else echo json_encode(['progress' => 0, 'step' => 'Starting...']);
            }
        } else {
            // Standard JSON handlers
            $result = \AICliAgents\Handlers\TerminalHandler::handle($action, $id)
                   ?? \AICliAgents\Handlers\StorageHandler::handle($action, $id)
                   ?? \AICliAgents\Handlers\AgentHandler::handle($action, $id)
                   ?? \AICliAgents\Handlers\TmuxHandler::handle($action, $id)
                   ?? \AICliAgents\Handlers\ArgsHandler::handle($action, $id)
                   ?? \AICliAgents\Handlers\AutoLaunchHandler::handle($action, $id)
                   ?? \AICliAgents\Handlers\EnvHandler::handle($action, $id)
                   ?? \AICliAgents\Handlers\UtilityHandler::handle($action, $id);

            if ($result !== null) {
                // json_encode output served as application/json — no browser
                // HTML parser involved. Not a user-controlled HTML sink.
                // nosemgrep: php.lang.security.injection.echoed-request.echoed-request
                echo json_encode($result);
            } else {
                // Same: JSON response, not HTML. $action has already reached
                // the dispatcher's switch without matching a handler.
                // nosemgrep: php.lang.security.injection.echoed-request.echoed-request
                echo json_encode(['status' => 'error', 'message' => "Unknown action: $action"]);
            }
        }
    } catch (\Throwable $e) {
        aicli_log("AJAX EXCEPTION [$action]: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine(), AICLI_LOG_ERROR);
        $config = getAICliConfig();
        $response = ['status' => 'error', 'message' => $e->getMessage()];
        if (($config['log_level'] ?? 2) >= 3) {
            $response['trace'] = $e->getTraceAsString();
        }
        echo json_encode($response);
    }

    exit;
}
