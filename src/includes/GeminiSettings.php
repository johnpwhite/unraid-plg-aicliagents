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
    $pid = trim(file_get_contents($pidFile));
    return $pid && posix_getpgid($pid) !== false;
}

function startGeminiTerminal() {
    $sock = "/var/run/geminiterm.sock";
    $shell = "/usr/local/emhttp/plugins/unraid-geminicli/scripts/gemini-shell.sh";
    $log = "/tmp/ttyd-gemini.log";
    $pidFile = getGeminiPidFile();
    
    if (!isGeminiRunning()) {
        file_put_contents($log, date('Y-m-d H:i:s') . " - Starting ttyd on $sock\n", FILE_APPEND);
        chmod($shell, 0755);
        
        // Remove stale socket
        if (file_exists($sock)) unlink($sock);
        
        // -i: interface (socket)
        // -W: writable
        // -d: debug level
        // -t: client terminal settings
        $cmd = "ttyd -i '$sock' -W -d0 -t fontSize=14 -t fontFamily='\"JetBrains Mono\", monospace' -t disableLeaveAlert=true -t closeOnDisconnect=true '$shell'";
        exec("$cmd >> $log 2>&1 & echo $!", $output);
        
        $pid = trim($output[0]);
        file_put_contents($pidFile, $pid);
        
        // Give it a moment to create the socket
        usleep(300000);
    }
}

function stopGeminiTerminal() {
    $pidFile = getGeminiPidFile();
    $sock = "/var/run/geminiterm.sock";
    
    if (file_exists($pidFile)) {
        $pid = trim(file_get_contents($pidFile));
        exec("kill $pid > /dev/null 2>&1");
        // Kill any stragglers on the same socket
        exec("pgrep -f '$sock' | xargs kill -9 > /dev/null 2>&1");
        unlink($pidFile);
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
        echo json_encode(['status' => 'ok', 'running' => isGeminiRunning()]);
        exit;
    } elseif ($_GET['action'] === 'stop') {
        stopGeminiTerminal();
        header('Content-Type: application/json');
        echo json_encode(['status' => 'ok']);
        exit;
    }
}
