<?php
/**
 * Gemini CLI Terminal Management
 */

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

function getGeminiConfig() {
    $configFile = "/boot/config/plugins/unraid-geminicli/unraid-geminicli.cfg";
    $defaults = [
        'enable_tab' => '1',
        'theme' => 'dark',
        'font_size' => '14',
        'history' => '4096',
        'home_path' => '/boot/config/plugins/unraid-geminicli/home',
        'user' => 'root',
        'root_path' => '/mnt',
        'sessions' => json_encode([['id' => 'default', 'name' => 'Main', 'path' => '/mnt']])
    ];
    
    if (file_exists($configFile)) {
        $config = @parse_ini_file($configFile);
        if (is_array($config)) {
            return array_merge($defaults, $config);
        }
    }
    
    return $defaults;
}

function getGeminiPidFile($id = 'default') {
    return "/var/run/unraid-geminicli-$id.pid";
}

function getGeminiLockFile($id = 'default') {
    return "/var/run/unraid-geminicli-$id.lock";
}

function getGeminiSock($id = 'default') {
    return "/var/run/geminiterm-$id.sock";
}

function isGeminiRunning($id = 'default') {
    $sock = getGeminiSock($id);
    $pids = [];
    exec("pgrep -x ttyd | xargs -I {} ps -p {} -o args= | grep -v grep | grep '$sock'", $pids);
    return !empty($pids);
}

function stopGeminiTerminal($id = 'default', $killTmux = false) {
    $sock = getGeminiSock($id);
    $pidFile = getGeminiPidFile($id);
    
    $pids = [];
    exec("pgrep -x ttyd | xargs -I {} ps -p {} -o pid=,args= | grep '$sock' | awk '{print $1}'", $pids);
    
    foreach ($pids as $pid) {
        if (!empty($pid)) exec("kill -9 $pid > /dev/null 2>&1");
    }
    
    if (file_exists($sock)) @unlink($sock);
    if (file_exists($pidFile)) @unlink($pidFile);

    if ($killTmux) {
        $sessionName = "gemini-cli-$id";
        exec("tmux kill-session -t $sessionName > /dev/null 2>&1");
    }
}

function startGeminiTerminal($id = 'default', $workingDir = null) {
    $sock = getGeminiSock($id);
    $shell = "/usr/local/emhttp/plugins/unraid-geminicli/scripts/gemini-shell.sh";
    $log = "/tmp/ttyd-gemini-$id.log";
    $pidFile = getGeminiPidFile($id);
    $lockFile = getGeminiLockFile($id);
    
    $config = getGeminiConfig();
    $workingDir = $workingDir ?: $config['root_path'];
    
    $fp = fopen($lockFile, "w+");
    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        fclose($fp);
        return; 
    }

    if (isGeminiRunning($id) && file_exists($sock)) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return;
    }

    stopGeminiTerminal($id, false);
    if (file_exists($shell)) chmod($shell, 0755);

    $env = "export GEMINI_HOME='{$config['home_path']}'; " .
           "export GEMINI_USER='{$config['user']}'; " .
           "export GEMINI_ROOT='$workingDir'; " .
           "export GEMINI_HISTORY='{$config['history']}'; " .
           "export GEMINI_SESSION_ID='$id'; ";

    $cmd = "ttyd -i '$sock' -W -d0 " .
           "-t fontSize={$config['font_size']} " .
           "-t fontFamily='monospace' " .
           "-t disableLeaveAlert=true " .
           "-t enable-utf8=true " .
           "-t titleFixed='Gemini CLI - $id' " .
           "bash -c \"$env $shell\"";
    
    exec("nohup $cmd >> $log 2>&1 & echo $!", $output);
    $pid = trim($output[0] ?? '');
    if ($pid) file_put_contents($pidFile, $pid);
    
    for ($i=0; $i<15; $i++) {
        if (file_exists($sock)) break;
        usleep(100000);
    }
    
    flock($fp, LOCK_UN);
    fclose($fp);
}

if (isset($_GET['action'])) {
    // CSRF Validation for state-changing actions
    if (in_array($_GET['action'], ['save', 'create_dir'])) {
        $var = @parse_ini_file("/var/local/emhttp/var.ini");
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_REQUEST['csrf_token'] ?? '';
        if (empty($var['csrf_token']) || $token !== $var['csrf_token']) {
            header('HTTP/1.1 403 Forbidden');
            echo json_encode(['error' => 'Invalid CSRF token']);
            exit;
        }
    }

    header('Content-Type: application/json');
    $id = $_GET['id'] ?? 'default';

    if ($_GET['action'] === 'start') {
        $path = $_GET['path'] ?? null;
        startGeminiTerminal($id, $path);
        echo json_encode(['status' => 'ok', 'sock' => "/webterminal/geminiterm-$id/"]);
    } elseif ($_GET['action'] === 'stop') {
        stopGeminiTerminal($id, isset($_GET['hard']));
        echo json_encode(['status' => 'ok']);
    } elseif ($_GET['action'] === 'restart') {
        $path = $_GET['path'] ?? null;
        stopGeminiTerminal($id, true);
        startGeminiTerminal($id, $path);
        echo json_encode(['status' => 'ok']);
    } elseif ($_GET['action'] === 'save') {
        saveGeminiConfig($_POST);
        echo json_encode(['status' => 'ok']);
    } elseif ($_GET['action'] === 'list_dir') {
        $root = getGeminiConfig()['root_path'];
        $path = realpath($_GET['path'] ?? $root);
        if (strpos($path, realpath($root)) !== 0) $path = realpath($root);
        
        $items = [];
        if ($path !== realpath($root)) {
            $items[] = ['name' => '..', 'path' => dirname($path), 'type' => 'dir'];
        }
        
        $files = scandir($path);
        foreach ($files as $f) {
            if ($f === '.' || $f === '..') continue;
            $full = "$path/$f";
            if (is_dir($full)) {
                $items[] = ['name' => $f, 'path' => $full, 'type' => 'dir'];
            }
        }
        echo json_encode(['path' => $path, 'items' => $items]);
    } elseif ($_GET['action'] === 'create_dir') {
        $parent = realpath($_GET['parent'] ?? '');
        $name = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['name'] ?? '');
        $root = getGeminiConfig()['root_path'];
        if (strpos($parent, realpath($root)) === 0 && !empty($name)) {
            mkdir("$parent/$name", 0777, true);
            echo json_encode(['status' => 'ok']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Invalid path or name']);
        }
    } elseif ($_GET['action'] === 'get_title') {
        $id = preg_replace('/[^a-z0-9\-]/', '', $_GET['id'] ?? '');
        $session = "gemini-$id";
        $title = '';
        if (!empty($id)) {
            // Get the window name (#W) or title (#T) from tmux
            $title = exec("tmux display-message -p -t $session '#T' 2>/dev/null");
            // Fallback to window name if title is empty or just the hostname/shell
            if (empty($title) || $title === 'unraid' || $title === 'sh' || $title === 'bash') {
                $title = exec("tmux display-message -p -t $session '#W' 2>/dev/null");
            }
        }
        echo json_encode(['status' => 'ok', 'title' => $title]);
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
