<?php
/**
 * Gemini CLI Terminal Management
 */

function getGeminiConfig() {
    $configFile = "/boot/config/plugins/unraid-geminicli/unraid-geminicli.cfg";
    $defaults = [
        'enable_tab' => '1',
        'theme' => 'dark',
        'font_size' => '14',
        'history' => '4096',
        'home_path' => '/boot/config/plugins/unraid-geminicli/home',
        'user' => 'root',
        'root_path' => '/mnt'
    ];
    
    if (file_exists($configFile)) {
        $config = @parse_ini_file($configFile);
        if (is_array($config)) {
            return array_merge($defaults, $config);
        }
    }
    
    return $defaults;
}

function saveGeminiConfig($newConfig) {
    $configFile = "/boot/config/plugins/unraid-geminicli/unraid-geminicli.cfg";
    $current = getGeminiConfig();
    
    // Whitelist allowed keys for security and to prevent pollution
    $allowed = ['enable_tab', 'theme', 'font_size', 'history', 'home_path', 'user', 'root_path', 'version'];
    
    foreach ($newConfig as $key => $value) {
        if (in_array($key, $allowed)) {
            $current[$key] = $value;
        }
    }
    
    // Build the INI string
    $ini = "";
    foreach ($current as $key => $value) {
        $ini .= "$key=\"$value\"\n";
    }
    
    if (!file_exists(dirname($configFile))) {
        mkdir(dirname($configFile), 0777, true);
    }
    
    file_put_contents($configFile, $ini);
    
    // Update the .page file metadata to show/hide the tab immediately
    updateGeminiMenuVisibility($current['enable_tab']);
}

function updateGeminiMenuVisibility($enabled) {
    $pageFile = "/usr/local/emhttp/plugins/unraid-geminicli/GeminiCLI.page";
    if (!file_exists($pageFile)) return;
    
    $content = file_get_contents($pageFile);
    // Use Menu="Tasks" for top-level navigation tabs in the header
    $newMenu = $enabled ? "Menu=\"Tasks:10\"" : "Menu=\"\"";
    
    // Replace Menu="..." line precisely, maintaining start of line
    $content = preg_replace('/^Menu=".*"$/m', $newMenu, $content);
    file_put_contents($pageFile, $content);
}

function getGeminiPidFile() {
    return "/var/run/unraid-geminicli.pid";
}

function getGeminiLockFile() {
    return "/var/run/unraid-geminicli.lock";
}

function isGeminiRunning() {
    $sock = "/var/run/geminiterm.sock";
    $pids = [];
    // -x ensures we only match the 'ttyd' executable name exactly
    // -f is still used to verify it's the instance using OUR socket
    exec("pgrep -x ttyd | xargs -I {} ps -p {} -o args= | grep -v grep | grep '$sock'", $pids);
    return !empty($pids);
}

function stopGeminiTerminal($killTmux = false) {
    $sock = "/var/run/geminiterm.sock";
    $pidFile = getGeminiPidFile();
    
    // Find only the parent ttyd process for this socket
    $pids = [];
    exec("pgrep -x ttyd | xargs -I {} ps -p {} -o pid=,args= | grep '$sock' | awk '{print $1}'", $pids);
    
    foreach ($pids as $pid) {
        if (!empty($pid)) exec("kill -9 $pid > /dev/null 2>&1");
    }
    
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
    
    // Load config
    $config = getGeminiConfig();
    
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
    file_put_contents($log, date('Y-m-d H:i:s') . " - [INFO] Session state: isRunning=" . ($isRunning?'yes':'no') . " sockExists=" . ($sockExists?'yes':'no') . ". Starting fresh with config: " . json_encode($config) . "\n", FILE_APPEND);
    
    // Use the surgical stop call
    stopGeminiTerminal(false);

    if (file_exists($shell)) chmod($shell, 0755);

    // Pass environment variables to the shell script
    $env = "export GEMINI_HOME='{$config['home_path']}'; " .
           "export GEMINI_USER='{$config['user']}'; " .
           "export GEMINI_ROOT='{$config['root_path']}'; " .
           "export GEMINI_HISTORY='{$config['history']}'; ";

    // Standard ttyd integration
    $cmd = "ttyd -i '$sock' -W -d0 " .
           "-t fontSize={$config['font_size']} " .
           "-t fontFamily='monospace' " .
           "-t disableLeaveAlert=true " .
           "bash -c \"$env $shell\"";
    
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
    // CSRF Validation for state-changing actions
    if (in_array($_GET['action'], ['save', 'restart', 'stop'])) {
        $var = @parse_ini_file("/var/local/emhttp/var.ini");
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
        if (empty($var['csrf_token']) || $token !== $var['csrf_token']) {
            header('HTTP/1.1 403 Forbidden');
            echo json_encode(['error' => 'Invalid CSRF token']);
            exit;
        }
    }

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
    } elseif ($_GET['action'] === 'save') {
        saveGeminiConfig($_POST);
        echo json_encode(['status' => 'ok']);
    } elseif ($_GET['action'] === 'debug') {
        $debug = [
            'config' => getGeminiConfig(),
            'ttyd' => exec("which ttyd 2>&1"),
            'tmux' => exec("which tmux 2>&1"),
            'path' => getenv('PATH'),
            'user' => exec("whoami"),
            'sock' => file_exists("/var/run/geminiterm.sock"),
            'running' => isGeminiRunning(),
            'log' => file_exists("/tmp/gemini-shell.log") ? gemini_tail("/tmp/gemini-shell.log", 20) : "No log found"
        ];
        echo json_encode($debug);
    }
    exit;
}

function gemini_tail($file, $lines) {
    return explode("\n", shell_exec("tail -n $lines " . escapeshellarg($file)));
}
