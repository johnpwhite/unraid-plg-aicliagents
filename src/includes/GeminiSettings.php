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
        file_put_contents($log, date('Y-m-d H:i:s') . " - Starting ttyd on $sock\n", FILE_APPEND);
        chmod($shell, 0755);
        
        // -i: interface (socket)
        // -W: writable
        // -t: client terminal settings (using JSON)
        // -d: debug level 0
        $cmd = "ttyd -i '$sock' -W -d0 '$shell'";
        exec("$cmd >> $log 2>&1 &");
        
        // Give it a moment to create the socket
        usleep(300000);
    }
}

function stopGeminiTerminal() {
    $sock = "/var/run/geminiterm.sock";
    exec("pgrep -f '$sock'", $pids);
    foreach ($pids as $pid) {
        exec("kill $pid");
    }
    if (file_exists($sock)) {
        unlink($sock);
    }
}

// If called via AJAX/direct include
if (isset($_GET['action'])) {
    if ($_GET['action'] === 'start') {
        startGeminiTerminal();
        header('Content-Type: application/json');
        echo json_encode(['status' => 'ok']);
        exit;
    } elseif ($_GET['action'] === 'stop') {
        stopGeminiTerminal();
        header('Content-Type: application/json');
        echo json_encode(['status' => 'ok']);
        exit;
    }
}
