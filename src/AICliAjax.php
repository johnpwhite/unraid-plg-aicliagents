<?php
/**
 * AICliAgents CLI AJAX Handler
 * Centralized entry point for all frontend AJAX requests.
 */
error_reporting(E_ALL);
ini_set('display_errors', '0'); 
set_time_limit(120); // Extended limit for sync/install operations

// Fatal Error Handler
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== NULL && ($error['type'] === E_ERROR || $error['type'] === E_PARSE || $error['type'] === E_CORE_ERROR || $error['type'] === E_COMPILE_ERROR)) {
        $msg = "FATAL PHP ERROR: {$error['message']} in {$error['file']} on line {$error['line']}";
        $logDir = "/tmp/unraid-aicliagents";
        if (!is_dir($logDir)) @mkdir($logDir, 0777, true);
        $logFile = "$logDir/debug.log";
        
        $timestamp = function_exists('aicli_get_formatted_timestamp') ? aicli_get_formatted_timestamp() : date("Y-m-d H:i:s");
        @file_put_contents($logFile, "[$timestamp] $msg\n", FILE_APPEND);
        
        // Try to send a clean JSON error back
        if (!headers_sent()) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'PHP Fatal Error: Check debug.log']);
        }
    }
});

require_once __DIR__ . '/includes/AICliAgentsManager.php';
aicli_ensure_init();

if (isset($_GET['action'])) {
    aicli_log("AJAX Request: action=" . ($_GET['action'] ?? 'NONE') . " method=" . $_SERVER['REQUEST_METHOD'], AICLI_LOG_DEBUG);
    
    // Standard CSRF Validation
    $var = @parse_ini_file("/var/local/emhttp/var.ini");
    $expected = $var['csrf_token'] ?? 'NOT_SET';
    $received = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? 'MISSING';
    
    if (is_array($received)) $received = end($received);
    $received = trim((string)$received);
    $expected = trim((string)$expected);
    
    if ($received === 'MISSING' || $received !== $expected) {
        @session_start();
        if (!isset($_SESSION['csrf_token']) || trim((string)$_SESSION['csrf_token']) !== $received) {
            aicli_log("CSRF VALIDATION FAILED! Action: " . $_GET['action'], AICLI_LOG_ERROR);
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF Token']);
            exit;
        }
    }
    
    header('Content-Type: application/json');
    $id = $_GET['id'] ?? 'default';
    $action = $_GET['action'];

    if ($action === 'start') {
        $path = $_GET['path'] ?? null;
        $chatId = $_GET['chatId'] ?? null;
        $agentId = $_GET['agentId'] ?? 'gemini-cli';
        startAICliTerminal($id, $path, $chatId, $agentId);
        echo json_encode(['status' => 'ok', 'sock' => "/webterminal/aicliterm-$id/"]);
    } elseif ($action === 'install_agent') {
        $agentId = $_GET['agentId'] ?? '';
        aicli_exec_bg("/usr/bin/php /usr/local/emhttp/plugins/unraid-aicliagents/scripts/install-bg.php " . escapeshellarg($agentId));
        echo json_encode(['status' => 'ok', 'message' => 'Started']);
    } elseif ($action === 'uninstall_agent') {
        $agentId = $_GET['agentId'] ?? '';
        echo json_encode(uninstallAgent($agentId));
    } elseif ($action === 'check_updates') {
        echo json_encode(checkAgentUpdates());
    } elseif ($action === 'stop') {
        stopAICliTerminal($id, isset($_GET['hard']));
        echo json_encode(['status' => 'ok']);
    } elseif ($action === 'gc') {
        gcAICliSessions();
        echo json_encode(['status' => 'ok']);
    } elseif ($action === 'restart') {
        $path = $_GET['path'] ?? null;
        $chatId = $_GET['chatId'] ?? null;
        $agentId = $_GET['agentId'] ?? 'gemini-cli';
        stopAICliTerminal($id, true);
        startAICliTerminal($id, $path, $chatId, $agentId);
        echo json_encode(['status' => 'ok']);
    } elseif ($action === 'get_chat_session') {
        $path = $_GET['path'] ?? '';
        $id = $_GET['id'] ?? null;
        $agentId = $_GET['agentId'] ?? 'gemini-cli';
        $chatId = findAICliChatSession($path, $id, $agentId);
        echo json_encode(['chatId' => $chatId]);
    } elseif ($action === 'save') {
        saveAICliConfig($_POST);
        echo json_encode(['status' => 'ok']);
    } elseif ($action === 'get_env') {
        $path = $_GET['path'] ?? '';
        $agentId = $_GET['agentId'] ?? '';
        echo json_encode(['status' => 'ok', 'envs' => getWorkspaceEnvs($path, $agentId)]);
    } elseif ($action === 'save_env') {
        $path = $_GET['path'] ?? '';
        $agentId = $_GET['agentId'] ?? '';
        $envs = json_decode($_GET['envs'] ?? '{}', true);
        saveWorkspaceEnvs($path, $agentId, $envs);
        echo json_encode(['status' => 'ok']);
        exit;
    } elseif ($action === 'get_users') {
        echo json_encode(['status' => 'ok', 'users' => getUnraidUsers()]);
    } elseif ($action === 'create_user') {
        $user = $_POST['username'] ?? '';
        $pass = $_POST['password'] ?? '';
        $desc = $_POST['description'] ?? '';
        echo json_encode(createUnraidUser($user, $pass, $desc));
    } elseif ($action === 'sync_home') {
        try {
            aicli_log("AJAX: Manual Home Sync Requested", AICLI_LOG_INFO);
            $config = getAICliConfig();
            $success = aicli_sync_home($config['user'], true);
            if ($success) {
                aicli_log("AJAX: Manual Home Sync Finished, sending response", AICLI_LOG_INFO);
                echo json_encode(['status' => 'ok']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Rsync failed. Check debug log for details.']);
            }
        } catch (Throwable $e) {
            aicli_log("Manual Sync ERROR: " . $e->getMessage(), AICLI_LOG_ERROR);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    } elseif ($action === 'move_home') {
        $old = $_POST['old_path'] ?? '';
        $new = $_POST['new_path'] ?? '';
        if (empty($old) || empty($new) || !is_dir($old)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid source or target']);
        } else {
            if (!is_dir($new)) mkdir($new, 0777, true);
            aicli_log("Moving home data from $old to $new", AICLI_LOG_INFO);
            exec("cp -rn " . escapeshellarg($old) . "/* " . escapeshellarg($new) . "/ 2>&1", $output, $result);
            if ($result === 0) {
                $config = getAICliConfig();
                exec("chown -R " . escapeshellarg($config['user']) . ":users " . escapeshellarg($new));
                echo json_encode(['status' => 'ok']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Failed to copy data', 'output' => $output]);
            }
        }
    } elseif ($action === 'list_dir') {
        $path = $_GET['path'] ?? '/mnt';
        if (!is_dir($path)) { echo json_encode(['error' => 'Not a directory']); exit; }
        $items = [];
        if ($path !== '/') $items[] = ['name' => '..', 'path' => dirname($path)];
        foreach (scandir($path) as $file) {
            if ($file === '.' || $file === '..') continue;
            if (is_dir("$path/$file")) $items[] = ['name' => $file, 'path' => "$path/$file"];
        }
        echo json_encode(['path' => $path, 'items' => $items]);
    } elseif ($action === 'create_dir') {
        $parent = $_GET['parent'] ?? '';
        $name = $_GET['name'] ?? '';
        if (is_dir("$parent/$name")) { echo json_encode(['status' => 'error', 'message' => 'Folder already exists']); exit; }
        if (mkdir("$parent/$name", 0777, true)) echo json_encode(['status' => 'ok']);
        else echo json_encode(['status' => 'error', 'message' => 'Failed to create folder']);
    } elseif ($action === 'get_session_status') {
        $path = $_GET['path'] ?? '';
        $chatId = findAICliChatSession($path, $id, $_GET['agentId'] ?? 'gemini-cli');
        $title = "";
        if ($chatId) {
            $home = getAICliConfig()['home_path'];
            $logFile = "$home/.gemini/tmp/$chatId/logs.json";
            if (file_exists($logFile)) {
                $logs = @json_decode(file_get_contents($logFile), true);
                if ($logs && count($logs) > 0) $title = end($logs)['title'] ?? '';
            }
        }
        echo json_encode(['status' => 'ok', 'chatId' => $chatId, 'title' => $title]);
    } elseif ($action === 'get_log') {
        $logFile = "/tmp/unraid-aicliagents/debug.log";
        if (file_exists($logFile)) {
            $config = getAICliConfig();
            $maxLines = intval($config['log_max_lines'] ?? 250);
            $lines = aicli_tail($logFile, $maxLines);
            echo implode("\n", $lines);
        } else {
            echo "Log is empty or does not exist.";
        }
    } elseif ($action === 'clear_log') {
        $logFile = "/tmp/unraid-aicliagents/debug.log";
        if (file_exists($logFile)) {
            @file_put_contents($logFile, "");
            echo json_encode(['status' => 'ok']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Log file does not exist']);
        }
    } elseif ($action === 'upload_chunk') {
        $path = str_replace(array('\0', '..'), '', $_POST['path'] ?? '');
        if (empty($path) || !is_dir($path)) {
            echo json_encode(['status' => 'error', 'error' => 'Invalid destination path']);
            exit;
        }
        $fileName = basename($_POST['filename'] ?? '');
        $fileData = $_POST['filedata'] ?? '';
        $chunkIndex = intval($_POST['chunk_index'] ?? 0);
        $totalChunks = intval($_POST['total_chunks'] ?? 1);
        $decodedData = base64_decode(str_replace(' ', '+', $fileData));
        $targetFile = rtrim($path, '/') . '/' . $fileName;
        $tmpFile = $targetFile . ".partial";
        $writeMode = ($chunkIndex === 0) ? 0 : FILE_APPEND;
        if (file_put_contents($tmpFile, $decodedData, $writeMode) === false) {
            echo json_encode(['status' => 'error', 'error' => 'Failed to write chunk']);
            exit;
        }
        if ($chunkIndex === $totalChunks - 1) {
            if (rename($tmpFile, $targetFile)) {
                aicli_notify("File Uploaded", "Successfully uploaded $fileName to " . basename($path));
                echo json_encode(['status' => 'ok', 'complete' => true]);
            } else {
                @unlink($tmpFile);
                echo json_encode(['status' => 'error', 'error' => 'Failed to finalize']);
            }
        } else {
            echo json_encode(['status' => 'ok', 'chunk_received' => true]);
        }
    } elseif ($action === 'get_install_status') {
        $agentId = $_GET['agentId'] ?? '';
        $dir = "/tmp/unraid-aicliagents";
        $file = empty($agentId) ? "$dir/install-status" : "$dir/install-status-$agentId";
        if (file_exists($file)) echo file_get_contents($file);
        else echo json_encode(['message' => '', 'progress' => 0]);
    } elseif ($action === 'debug') {
        echo json_encode(['status' => 'ok', 'config' => getAICliConfig(), 'registry' => getAICliAgentsRegistry()]);
    }
    exit;
}
