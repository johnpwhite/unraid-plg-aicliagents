<?php
/**
 * Gemini CLI Terminal Management
 */

function getGeminiPidFile() {
    return "/var/run/unraid-geminicli.pid";
}

function startGeminiTerminal() {
    $sock = "/var/run/geminiterm.sock";
    $shell = "/usr/local/emhttp/plugins/unraid-geminicli/scripts/gemini-shell.sh";
    $log = "/tmp/ttyd-gemini.log";
    $pidFile = getGeminiPidFile();
    
    if (file_exists($shell)) chmod($shell, 0755);

    // If already running, just return
    if (isGeminiRunning()) {
        return;
    }

    // Cleanup stale socket
    if (file_exists($sock)) @unlink($sock);

    file_put_contents($log, date('Y-m-d H:i:s') . " - Starting ttyd on $sock\n", FILE_APPEND);
    
    // Unraid 7.2 ttyd flags
    // REMOVED closeOnDisconnect=true so the server stays alive
    // Added explicit rows/cols to help with initial render
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

function stopGeminiTerminal($killTmux = false) {
    $pidFile = getGeminiPidFile();
    $sock = "/var/run/geminiterm.sock";
    
    // Kill all ttyd processes matching our socket
    exec("pgrep -f '$sock' | xargs kill -9 > /dev/null 2>&1");
    
    if (file_exists($pidFile)) @unlink($pidFile);
    if (file_exists($sock)) @unlink($sock);

    if ($killTmux) {
        exec("tmux kill-session -t gemini-cli > /dev/null 2>&1");
    }
}

function isGeminiRunning() {
    $sock = "/var/run/geminiterm.sock";
    $pids = [];
    exec("pgrep -f '$sock'", $pids);
    return !empty($pids);
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
