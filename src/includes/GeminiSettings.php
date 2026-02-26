<?php
/**
 * Gemini CLI Terminal Management
 */

function getGeminiPidFile() {
    return "/var/run/unraid-geminicli.pid";
}

function stopGeminiTerminal($killTmux = false) {
    $sock = "/var/run/geminiterm.sock";
    $pidFile = getGeminiPidFile();
    
    // Surgical kill: only ttyd processes using OUR specific socket
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
    
    if (file_exists($shell)) chmod($shell, 0755);

    // Check if truly running
    $pids = [];
    exec("ps -ef | grep 'ttyd' | grep '$sock' | grep -v grep | awk '{print $2}'", $pids);
    
    if (empty($pids)) {
        // Clean start
        if (file_exists($sock)) @unlink($sock);
        
        file_put_contents($log, date('Y-m-d H:i:s') . " - Starting ttyd\n", FILE_APPEND);
        
        // ttyd configuration:
        // -i: socket
        // -W: writable
        // -t: options
        // We set high rows/cols to avoid the "8 lines" bug on start
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
