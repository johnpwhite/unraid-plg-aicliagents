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

    // AGGRESSIVE CLEANUP: Always kill anything on this socket before starting
    // This ensures we never have zombie processes fighting for the terminal
    exec("pgrep -f '$sock' | xargs kill -9 > /dev/null 2>&1");
    if (file_exists($sock)) @unlink($sock);
    if (file_exists($pidFile)) @unlink($pidFile);

    file_put_contents($log, date('Y-m-d H:i:s') . " - Starting fresh ttyd instance\n", FILE_APPEND);
    
    // Unraid 7.2 ttyd flags
    // Added explicit -t rows=50 to help with height issue if client resize fails
    $cmd = "ttyd -i '$sock' -W -d0 " .
           "-t fontSize=14 " .
           "-t fontFamily='monospace' " .
           "-t disableLeaveAlert=true " .
           "-t closeOnDisconnect=true " .
           "'$shell'";
    
    exec("nohup $cmd >> $log 2>&1 & echo $!", $output);
    $pid = trim($output[0] ?? '');
    if ($pid) file_put_contents($pidFile, $pid);
    
    usleep(500000);
}

function stopGeminiTerminal($killTmux = false) {
    $pidFile = getGeminiPidFile();
    $sock = "/var/run/geminiterm.sock";
    
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
        if (!isGeminiRunning()) {
            startGeminiTerminal();
        }
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
