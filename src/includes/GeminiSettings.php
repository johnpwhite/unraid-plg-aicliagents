<?php
/**
 * Gemini CLI Terminal Management
 */

function getGeminiPidFile() {
    return "/var/run/unraid-geminicli.pid";
}

function isGeminiRunning() {
    $pidFile = getGeminiPidFile();
    if (!file_exists($pidFile)) return false;
    $pid = trim(@file_get_contents($pidFile));
    if (!$pid) return false;
    return posix_getpgid($pid) !== false;
}

function startGeminiTerminal() {
    $sock = "/var/run/geminiterm.sock";
    $shell = "/usr/local/emhttp/plugins/unraid-geminicli/scripts/gemini-shell.sh";
    $log = "/tmp/ttyd-gemini.log";
    $pidFile = getGeminiPidFile();
    
    if (file_exists($shell)) chmod($shell, 0755);

    if (!isGeminiRunning()) {
        file_put_contents($log, date('Y-m-d H:i:s') . " - Starting fresh ttyd instance\n", FILE_APPEND);
        
        // Cleanup socket if exists
        if (file_exists($sock)) @unlink($sock);
        
        // Unraid 7.2 ttyd flags:
        // -W: writable
        // -d0: debug level 0
        // -t: client terminal options
        // We use JetBrains Mono if available, else monospace
        $cmd = "ttyd -i '$sock' -W -d0 " .
               "-t fontSize=14 " .
               "-t fontFamily='\"JetBrains Mono\",monospace' " .
               "-t disableLeaveAlert=true " .
               "-t closeOnDisconnect=true " .
               "'$shell'";
        
        // Use nohup and background execution
        exec("nohup $cmd >> $log 2>&1 & echo $!", $output);
        $pid = trim($output[0] ?? '');
        if ($pid) file_put_contents($pidFile, $pid);
        
        usleep(500000); // Wait half a second for socket to initialize
    }
}

function stopGeminiTerminal($killTmux = false) {
    $pidFile = getGeminiPidFile();
    $sock = "/var/run/geminiterm.sock";
    
    // Kill ttyd process
    if (file_exists($pidFile)) {
        $pid = trim(@file_get_contents($pidFile));
        if ($pid) exec("kill $pid > /dev/null 2>&1");
        @unlink($pidFile);
    }
    
    // Cleanup any other ttyd instances on this socket
    exec("pgrep -f '$sock' | xargs kill -9 > /dev/null 2>&1");
    
    if (file_exists($sock)) @unlink($sock);

    if ($killTmux) {
        exec("tmux kill-session -t gemini-cli > /dev/null 2>&1");
    }
}

if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    if ($_GET['action'] === 'start') {
        startGeminiTerminal();
        echo json_encode(['status' => 'ok', 'running' => isGeminiRunning()]);
    } elseif ($_GET['action'] === 'stop') {
        stopGeminiTerminal(isset($_GET['hard']));
        echo json_encode(['status' => 'ok']);
    } elseif ($_GET['action'] === 'restart') {
        stopGeminiTerminal(true);
        startGeminiTerminal();
        echo json_encode(['status' => 'ok', 'running' => isGeminiRunning()]);
    }
    exit;
}
