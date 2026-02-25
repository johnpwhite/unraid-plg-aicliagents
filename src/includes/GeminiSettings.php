<?php
/**
 * Gemini CLI Terminal Management
 */

function startGeminiTerminal() {
    $sock = "/var/run/geminiterm.sock";
    $shell = "/usr/local/emhttp/plugins/unraid-geminicli/scripts/gemini-shell.sh";
    $log = "/tmp/unraid-geminicli/ttyd.log";
    
    if (!is_dir("/tmp/unraid-geminicli")) {
        mkdir("/tmp/unraid-geminicli", 0777, true);
    }
    
    // Check if ttyd is already running for this socket
    exec("pgrep -f 'ttyd.*$sock'", $pids);
    
    if (empty($pids)) {
        file_put_contents($log, date('Y-m-d H:i:s') . " - Starting ttyd\n", FILE_APPEND);
        // Ensure shell is executable
        chmod($shell, 0755);
        
        $cmd = file_exists("/usr/local/sbin/ttyd-exec") 
            ? "/usr/local/sbin/ttyd-exec -i '$sock' -W '$shell'" 
            : "ttyd -i '$sock' -W '$shell'";
            
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
