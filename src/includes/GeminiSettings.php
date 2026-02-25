<?php
/**
 * Gemini CLI Terminal Management
 */

function startGeminiTerminal() {
    $sock = "/var/run/geminiterm.sock";
    $shell = "/usr/local/emhttp/plugins/unraid-geminicli/scripts/gemini-shell.sh";
    
    // Check if ttyd is already running for this socket
    exec("pgrep -f 'ttyd.*$sock'", $pids);
    
    if (empty($pids)) {
        // Start ttyd listening on the unix socket
        // -i: interface (socket path)
        // -d: debug level
        // we use the unraid-standard ttyd-exec if available
        if (file_exists("/usr/local/sbin/ttyd-exec")) {
            exec("/usr/local/sbin/ttyd-exec -i '$sock' '$shell' > /dev/null 2>&1 &");
        } else {
            // Fallback for systems where ttyd-exec isn't standard
            exec("ttyd -i '$sock' '$shell' > /dev/null 2>&1 &");
        }
    }
}

// If called via AJAX/direct include
if (isset($_GET['action']) && $_GET['action'] === 'start') {
    startGeminiTerminal();
    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok']);
    exit;
}
