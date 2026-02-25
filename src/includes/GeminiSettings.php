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
        file_put_contents($log, date('Y-m-d H:i:s') . " - Starting ttyd\n", FILE_APPEND);
        chmod($shell, 0755);
        
        // Unraid's ttyd expects to be behind nginx proxy. 
        // We use -i to bind to a socket that nginx matches in rc.nginx:
        // location ~ /webterminal/(.*)/(.*)$ { proxy_pass http://unix:/var/run/$1.sock:/$2; }
        // So for 'geminiterm', the socket MUST be /var/run/geminiterm.sock
        $cmd = "ttyd -i '$sock' -W '$shell'";
        exec("$cmd >> $log 2>&1 &");
        
        // Give it a moment to create the socket
        usleep(200000);
    }
}

// If called via AJAX/direct include
if (isset($_GET['action']) && $_GET['action'] === 'start') {
    startGeminiTerminal();
    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok']);
    exit;
}
