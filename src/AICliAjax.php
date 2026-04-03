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

if (isset($_GET['action'])) {
    $currentAction = $_GET['action'] ?? 'NONE';
    
    // D-166: Avoid auto-init/mount for repair actions to prevent race conditions
    if (!in_array($currentAction, ['get_repair_status', 'repair_plugin'])) {
        aicli_ensure_init();
    }

    aicli_log("AJAX Request: action=$currentAction method=" . $_SERVER['REQUEST_METHOD'], AICLI_LOG_DEBUG);
    
    // D-15: Throttle AJAX logging. Most actions are now DEBUG only to prevent log flooding.
    $criticalActions = ['install_agent', 'uninstall_agent', 'migrate_agents', 'migrate_home'];
    $currentAction = $_GET['action'] ?? 'NONE';
    $logPriority = in_array($currentAction, $criticalActions) ? AICLI_LOG_INFO : AICLI_LOG_DEBUG;
    aicli_log("AJAX Exec: action=$currentAction", $logPriority);
    
    // Standard CSRF Validation
    $var = @parse_ini_file("/var/local/emhttp/var.ini");
    $expected = $var['csrf_token'] ?? 'NOT_SET';
    $received = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? 'MISSING';
    
    if (is_array($received)) $received = end($received);
    $received = trim((string)$received);
    $expected = trim((string)$expected);
    
    if ($received === 'MISSING' || $received !== $expected) {
        @session_start();
        $sessionToken = $_SESSION['csrf_token'] ?? 'NOT_SET';
        if ($sessionToken === 'NOT_SET' || trim((string)$sessionToken) !== $received) {
            $postKeys = implode(',', array_keys($_POST));
            $getKeys = implode(',', array_keys($_GET));
            aicli_log("CSRF VALIDATION FAILED! Action: " . $_GET['action'] . " (Method: " . $_SERVER['REQUEST_METHOD'] . ", Received: $received, Expected: $expected, Session: $sessionToken, POST_KEYS: [$postKeys], GET_KEYS: [$getKeys])", AICLI_LOG_ERROR);
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
    } elseif ($action === 'get_workspaces') {
        echo json_encode(aicli_get_workspaces());
    } elseif ($action === 'save_workspaces') {
        $json = $_POST['workspaces'] ?? '[]';
        $data = json_decode($json, true);
        if (is_array($data)) {
            aicli_save_workspaces($data);
            echo json_encode(['status' => 'ok']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Invalid data']);
        }
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
    } elseif ($action === 'evict_all') {
        $ids = $_GET['ids'] ?? '';
        if (empty($ids)) {
            aicli_evict_all();
        } else {
            aicli_evict_targeted($ids);
        }
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
        $oldConfig = getAICliConfig();
        saveAICliConfig($_POST);
        $newConfig = getAICliConfig();
        $userChanged = ($oldConfig['user'] !== $newConfig['user']);
        echo json_encode(['status' => 'ok', 'userChanged' => $userChanged]);
    } elseif ($action === 'migrate_storage') {
        $path = $_GET['path'] ?? '';
        echo json_encode(aicli_migrate_persistence($path));
    } elseif ($action === 'migrate_agent_storage') {
        $path = $_GET['path'] ?? '';
        echo json_encode(aicli_migrate_agent_storage($path));
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
    } elseif ($action === 'repair_plugin') {
        $repairScript = "/usr/local/emhttp/plugins/unraid-aicliagents/scripts/user/repair-plugin.sh";
        if (file_exists($repairScript)) {
            aicli_log("AJAX: Launching background plugin repair...", AICLI_LOG_WARN);
            // D-121: Handle detachment manually to ensure logs are appended to debug.log
            exec("nohup bash " . escapeshellarg($repairScript) . " >> /tmp/unraid-aicliagents/debug.log 2>&1 &");
            echo json_encode(['status' => 'ok']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Repair script not found.']);
        }
    } elseif ($action === 'get_repair_status') {
        $file = "/tmp/unraid-aicliagents/repair-status";
        if (file_exists($file)) {
            echo file_get_contents($file);
        } else {
            echo json_encode(['progress' => 100, 'message' => 'Not Running']);
        }
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
            exec("mv -f " . escapeshellarg($old) . "/* " . escapeshellarg($new) . "/ 2>&1", $output, $result);
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
            $fullPath = rtrim($path, '/') . '/' . $file;
            if (is_dir($fullPath)) $items[] = ['name' => $file, 'path' => $fullPath];
        }
        echo json_encode(['path' => $path, 'items' => $items]);
    } elseif ($action === 'create_dir') {
        $parent = $_GET['parent'] ?? '';
        $name = $_GET['name'] ?? '';
        if (is_dir("$parent/$name")) { echo json_encode(['status' => 'error', 'message' => 'Folder already exists']); exit; }
            if (mkdir("$parent/$name", 0777, true)) {
                aicli_log("AJAX: Folder created: $parent/$name", AICLI_LOG_INFO);
                echo json_encode(['status' => 'ok']);
            }
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
                // D-126: Swap Subject/Message for Unraid standard: Subject="File Uploaded", Message="Successfully uploaded..."
                aicli_notify("Successfully uploaded $fileName to " . basename($path), "File Uploaded");
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
        if (file_exists($file)) {
            $data = @json_decode(file_get_contents($file), true);
            if ($data && isset($data['message'])) {
                // Filter out progress strings from the message UI
                $lines = explode("\n", $data['message']);
                $filtered = array_filter($lines, function($line) {
                    $l = trim($line);
                    return !empty($l) && strpos($l, 'PROGRESS:') !== 0 && strpos($l, '[Installation Complete]') === false;
                });
                $data['message'] = implode("\n", $filtered);
                echo json_encode($data);
            } else {
                echo file_get_contents($file);
            }
        } else {
            echo json_encode(['message' => '', 'progress' => 0]);
        }
    } elseif ($action === 'get_storage_status') {
        echo json_encode(aicli_get_storage_status());
    } elseif ($action === 'expand_storage') {
        $type = $_GET['type'] ?? 'agents';
        $inc = ($type === 'agents') ? '256M' : '128M';
        echo json_encode(aicli_expand_storage($type, $inc));
    } elseif ($action === 'shrink_storage') {
        $type = $_GET['type'] ?? 'agents';
        $dec = ($type === 'agents') ? '256M' : '128M';
        echo json_encode(aicli_shrink_storage($type, $dec));
    } elseif ($action === 'debug') {
        echo json_encode([
            'status' => 'ok', 
            'config' => getAICliConfig(), 
            'registry' => getAICliAgentsRegistry(),
            'storage_status' => aicli_get_storage_status()
        ]);
    }
    exit;
}
