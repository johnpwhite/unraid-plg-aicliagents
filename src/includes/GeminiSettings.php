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
    $pids = [];
    // Check for any ttyd process listening on our specific socket
    exec("ps -ef | grep 'ttyd' | grep '$sock' | grep -v grep | awk '{print $2}'", $pids);
    return !empty($pids);
}

function stopGeminiTerminal($killTmux = false) {
    $sock = "/var/run/geminiterm.sock";
    $pidFile = getGeminiPidFile();
    
    // Kill all ttyd instances bound to our socket
    exec("ps -ef | grep 'ttyd' | grep '$sock' | grep -v grep | awk '{print $2}' | xargs kill -9 > /dev/null 2>&1");
    
    if (file_exists($sock)) @unlink($sock);
    if (file_exists($pidFile)) @unlink($pidFile);

    if ($killTmux) {
        exec("tmux kill-session -t gemini-cli > /dev/null 2>&1");
    }
}

function startGeminiTerminal() {
    $sock = "/var/run/geminiterm.sock";
    $shell = "/usr/local/emhttp/plugins/unraid-geminicli/scripts/gemini-shell.sh";
    $log = "/tmp/ttyd-gemini.log";
    $pidFile = getGeminiPidFile();
    $lockFile = getGeminiLockFile();
    
    // 1. Atomic lock to prevent double-start from PHP/AJAX race
    $fp = fopen($lockFile, "w+");
    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        fclose($fp);
        return; 
    }

    if (!isGeminiRunning()) {
        file_put_contents($log, date('Y-m-d H:i:s') . " - Starting clean ttyd session\n", FILE_APPEND);
        
        if (file_exists($shell)) chmod($shell, 0755);
        if (file_exists($sock)) @unlink($sock);

        // Standard Unraid 7.2 ttyd integration
        // -t columns/rows: provides a healthy default before client resize
        $cmd = "ttyd -i '$sock' -W -d0 " .
               "-t fontSize=14 " .
               "-t fontFamily='monospace' " .
               "-t disableLeaveAlert=true " .
               "-t columns=160 " .
               "-t rows=60 " .
               "'$shell'";
        
        exec("nohup $cmd >> $log 2>&1 & echo $!", $output);
        $pid = trim($output[0] ?? '');
        if ($pid) file_put_contents($pidFile, $pid);
        
        usleep(500000);
    }
    
    flock($fp, LOCK_UN);
    fclose($fp);
}

if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    if ($_GET['action'] === 'start') {
        startGeminiTerminal();
        echo json_encode(['status' => 'ok']);
    } elseif ($_GET['action'] === 'stop') {
        stopGeminiTerminal(isset($_GET['hard']));
        echo json_encode(['status' => 'ok']);
    } elseif ($_GET['action'] === 'restart') {
        stopGeminiTerminal(true);
        startGeminiTerminal();
        echo json_encode(['status' => 'ok']);
    }
    exit;
}
