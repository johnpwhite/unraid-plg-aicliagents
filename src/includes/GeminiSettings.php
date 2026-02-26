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

    $isRunning = isGeminiRunning();
    $sockExists = file_exists($sock);

    // If already running and healthy, we are done
    if ($isRunning && $sockExists) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return;
    }

    // If we get here, we are either not running or in an unhealthy state
    file_put_contents($log, date('Y-m-d H:i:s') . " - [INFO] Session state: isRunning=" . ($isRunning?'yes':'no') . " sockExists=" . ($sockExists?'yes':'no') . ". Starting fresh.\n", FILE_APPEND);
    
    // Surgical cleanup of ALL processes matching this socket before starting
    exec("pkill -9 -f 'ttyd.*$sock' > /dev/null 2>&1");
    if (file_exists($sock)) @unlink($sock);
    if (file_exists($pidFile)) @unlink($pidFile);

    if (file_exists($shell)) chmod($shell, 0755);

    // Standard ttyd integration
    $cmd = "ttyd -i '$sock' -W -d0 " .
           "-t fontSize=14 " .
           "-t fontFamily='monospace' " .
           "-t disableLeaveAlert=true " .
           "'$shell'";
    
    exec("nohup $cmd >> $log 2>&1 & echo $!", $output);
    $pid = trim($output[0] ?? '');
    if ($pid) file_put_contents($pidFile, $pid);
    
    // Wait for socket to appear
    for ($i=0; $i<15; $i++) {
        if (file_exists($sock)) break;
        usleep(100000);
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
