<?php
/**
 * Gemini CLI Terminal Management
 */

function getGeminiPidFile() {
    return "/var/run/unraid-geminicli.pid";
}

function stopGeminiTerminal($killTmux = false) {
    $pidFile = getGeminiPidFile();
    $sock = "/var/run/geminiterm.sock";
    
    // 1. Kill any ttyd instance using our socket
    exec("pgrep -f '$sock' | xargs kill -9 > /dev/null 2>&1");
    
    // 2. Clean up files
    if (file_exists($pidFile)) @unlink($pidFile);
    if (file_exists($sock)) @unlink($sock);

    // 3. Kill tmux if requested
    if ($killTmux) {
        exec("tmux kill-session -t gemini-cli > /dev/null 2>&1");
    }
}

function startGeminiTerminal() {
    $sock = "/var/run/geminiterm.sock";
    $shell = "/usr/local/emhttp/plugins/unraid-geminicli/scripts/gemini-shell.sh";
    $log = "/tmp/ttyd-gemini.log";
    $pidFile = getGeminiPidFile();
    
    if (file_exists($shell)) chmod($shell, 0755);

    // ALWAYS CLEANUP BEFORE START
    // This ensures we pick up the latest flags (like the removal of closeOnDisconnect)
    // tmux session is what keeps the session alive, so ttyd restart is safe.
    stopGeminiTerminal(false);

    file_put_contents($log, date('Y-m-d H:i:s') . " - Starting fresh ttyd instance\n", FILE_APPEND);
    
    // Unraid 7.2 ttyd flags
    // -W: writable
    // -d0: debug level
    // disableLeaveAlert=true: stops the "leave page?" popup
    // closeOnDisconnect=false (DEFAULT): keeps the bridge open so reconnect works!
    $cmd = "ttyd -i '$sock' -W -d0 " .
           "-t fontSize=14 " .
           "-t fontFamily='monospace' " .
           "-t disableLeaveAlert=true " .
           "'$shell'";
    
    exec("nohup $cmd >> $log 2>&1 & echo $!", $output);
    $pid = trim($output[0] ?? '');
    if ($pid) file_put_contents($pidFile, $pid);
    
    usleep(500000);
}

if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    if ($_GET['action'] === 'start') {
        startGeminiTerminal();
        echo json_encode(['status' => 'ok', 'running' => true]);
    } elseif ($_GET['action'] === 'stop') {
        stopGeminiTerminal(isset($_GET['hard']));
        echo json_encode(['status' => 'ok']);
    } elseif ($_GET['action'] === 'restart') {
        stopGeminiTerminal(true);
        startGeminiTerminal();
        echo json_encode(['status' => 'ok', 'running' => true]);
    }
    exit;
}
