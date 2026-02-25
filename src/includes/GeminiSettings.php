<?php
/**
 * Gemini CLI Terminal Management
 */

function startGeminiTerminal() {
    $sock = "/var/run/geminiterm.sock";
    $shell = "/usr/local/emhttp/plugins/unraid-geminicli/scripts/gemini-shell.sh";
    $log = "/tmp/ttyd-gemini.log";
    
    // Check if ttyd is already running
    exec("pgrep -f '$sock'", $pids);
    
    if (empty($pids)) {
        // Log to /tmp directly to avoid folder issues
        file_put_contents($log, date('Y-m-d H:i:s') . " - Starting ttyd\n", FILE_APPEND);
        
        // Ensure scripts are executable
        chmod($shell, 0755);
        
        // Use raw ttyd if ttyd-exec fails/missing
        $cmd = "ttyd -i '$sock' -W '$shell'";
        exec("$cmd >> $log 2>&1 &");
    }
}

// If called via AJAX/direct include
if (isset($_GET['action']) && $_GET['action'] === 'start') {
    startGeminiTerminal();
    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok']);
    exit;
}
