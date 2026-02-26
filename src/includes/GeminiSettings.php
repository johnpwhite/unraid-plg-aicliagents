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
    // Use pgrep -f for more robust detection of the process matching the socket
    exec("pgrep -f 'ttyd.*$sock'", $pids);
    return !empty($pids);
}

function stopGeminiTerminal($killTmux = false) {
    $sock = "/var/run/geminiterm.sock";
    $pidFile = getGeminiPidFile();
    
    // Surgical kill using pkill -f
    exec("pkill -9 -f 'ttyd.*$sock' > /dev/null 2>&1");
    
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
    
    // Prevent race conditions
    $fp = fopen($lockFile, "w+");
    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        fclose($fp);
        return; 
    }

    // Only start if not already running AND the socket exists
    // If the socket is missing but process is running, we have a problem (likely orphan)
    if (!isGeminiRunning() || !file_exists($sock)) {
        file_put_contents($log, date('Y-m-d H:i:s') . " - [INFO] Starting ttyd session for $sock\n", FILE_APPEND);
        
        // Clean up any stale state if not running
        if (!isGeminiRunning()) {
            exec("pkill -9 -f 'ttyd.*$sock' > /dev/null 2>&1");
            if (file_exists($sock)) @unlink($sock);
        }

        if (file_exists($shell)) chmod($shell, 0755);

        // Standard ttyd integration with flexible options
        $cmd = "ttyd -i '$sock' -W -d0 " .
               "-t fontSize=14 " .
               "-t fontFamily='monospace' " .
               "-t disableLeaveAlert=true " .
               "'$shell'";
        
        exec("nohup $cmd >> $log 2>&1 & echo $!", $output);
        $pid = trim($output[0] ?? '');
        if ($pid) file_put_contents($pidFile, $pid);
        
        // Give it a moment to bind to the socket
        for ($i=0; $i<10; $i++) {
            if (file_exists($sock)) break;
            usleep(100000);
        }
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
