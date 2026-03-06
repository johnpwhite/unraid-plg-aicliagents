<?php
/**
 * AICliAgents CLI AJAX Handler
 */
error_reporting(E_ALL);
ini_set('display_errors', '0'); 

// Fatal Error Handler
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== NULL && ($error['type'] === E_ERROR || $error['type'] === E_PARSE || $error['type'] === E_CORE_ERROR || $error['type'] === E_COMPILE_ERROR)) {
        $msg = "FATAL PHP ERROR: {$error['message']} in {$error['file']} on line {$error['line']}";
        $logFile = "/boot/config/plugins/unraid-aicliagents/debug.log";
        @file_put_contents($logFile, "[".date("Y-m-d H:i:s")."] $msg\n", FILE_APPEND);
        
        // Try to send a clean JSON error back
        if (!headers_sent()) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'PHP Fatal Error: Check debug.log']);
        }
    }
});

require_once __DIR__ . '/includes/AICliAgentsManager.php';
