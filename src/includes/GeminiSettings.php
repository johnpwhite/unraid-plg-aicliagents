<?php
/**
 * Gemini CLI Terminal Management
 */

function getGeminiPidFile() {
    return "/var/run/unraid-geminicli.pid";
}

function getGeminiLockFile() {
    return "/var/run/unraid-geminicli.lock";
}

function isGeminiRunning() {
    $sock = "/var/run/geminiterm.sock";
    // Check if the socket actually exists and ttyd is running
    if (!file_exists($sock)) return false;
    
    $pids = [];
    exec("pgrep -f '$sock'", $pids);
    return !empty($pids);
}

function startGeminiTerminal() {
    $sock = "/var/run/geminiterm.sock";
    $shell = "/usr/local/emhttp/plugins/unraid-geminicli/scripts/gemini-shell.sh";
    $log = "/tmp/ttyd-gemini.log";
    $pidFile = getGeminiPidFile();
    $lockFile = getGeminiLockFile();
    
    // Prevent race conditions with a simple lock
    $fp = fopen($lockFile, "w+");
    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        return; // Already being started by another thread
    }

    if (!isGeminiRunning()) {
        file_put_contents($log, date('Y-m-d H:i:s') . " - Starting fresh ttyd instance\n", FILE_APPEND);
        
        // Ensure scripts are executable
        if (file_exists($shell)) chmod($shell, 0755);

        // CLEANUP: Kill ANY ttyd instance that might be hanging around
        exec("pgrep -f 'ttyd' | xargs kill -9 > /dev/null 2>&1");
        if (file_exists($sock)) @unlink($sock);

        // Start ttyd
        // Added rows/cols to help with initial 8-line issue
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
    
    flock($fp, LOCK_UN);
    fclose($fp);
}

function stopGeminiTerminal($killTmux = false) {
    $pidFile = getGeminiPidFile();
    $sock = "/var/run/geminiterm.sock";
    
    exec("pgrep -f 'ttyd' | xargs kill -9 > /dev/null 2>&1");
    if (file_exists($pidFile)) @unlink($pidFile);
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
