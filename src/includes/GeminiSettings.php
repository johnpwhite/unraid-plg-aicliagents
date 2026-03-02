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
    $plgFile = "/usr/local/emhttp/plugins/unraid-geminicli/unraid-geminicli.plg";
    $version = 'unknown';
    
    if (file_exists($plgFile)) {
        // Find version="..." in the <PLUGIN> tag
        $plg = file_get_contents($plgFile);
        if (preg_match('/version="(.*?)"/', $plg, $m)) {
            $version = $m[1];
        }
    }

    $defaults = [
        'enable_tab' => '1',
        'theme' => 'dark',
        'font_size' => '14',
        'history' => '4096',
        'home_path' => '/boot/config/plugins/unraid-geminicli/home',
        'user' => 'root',
        'root_path' => '/mnt',
        'version' => $version,
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

function getGeminiChatIdFile($id = 'default') {
    return "/var/run/unraid-geminicli-$id.chatid";
}

function isGeminiRunning($id = 'default', $chatId = null) {
    $sock = getGeminiSock($id);
    $pids = [];
    exec("pgrep -x ttyd | xargs -I {} ps -p {} -o args= | grep -v grep | grep '$sock'", $pids);
    
    if (empty($pids)) return false;

    // If a chatId is provided, we MUST check if it matches the running ttyd's chatId
    if ($chatId !== null) {
        $chatIdFile = getGeminiChatIdFile($id);
        $runningChatId = file_exists($chatIdFile) ? trim(file_get_contents($chatIdFile)) : '';
        if ($chatId !== $runningChatId) {
            return false; // Force a restart because the requested chat ID changed
        }
    }

    return true;
}

function stopGeminiTerminal($id = 'default', $killTmux = false) {
    $sock = getGeminiSock($id);
    $pidFile = getGeminiPidFile($id);
    $chatIdFile = getGeminiChatIdFile($id);
    
    // 1. Kill ttyd
    $pids = [];
    exec("pgrep -x ttyd | xargs -I {} ps -p {} -o pid=,args= | grep '$sock' | awk '{print $1}'", $pids);
    foreach ($pids as $pid) {
        if (!empty($pid)) exec("kill -9 $pid > /dev/null 2>&1");
    }
    
    // 2. Kill associated node processes (even if orphaned)
    // We look for node processes that have the GEMINI_SESSION_ID in their environment
    exec("pgrep -f 'node.*gemini.mjs' | xargs -I {} grep -l 'GEMINI_SESSION_ID=$id' /proc/{}/environ 2>/dev/null | grep -oP '\d+'", $nodePids);
    foreach ($nodePids as $np) {
        if (!empty($np)) exec("kill -9 $np > /dev/null 2>&1");
    }
    
    if (file_exists($sock)) @unlink($sock);
    if (file_exists($pidFile)) @unlink($pidFile);
    if (file_exists($chatIdFile)) @unlink($chatIdFile);

    if ($killTmux) {
        $sessionName = "gemini-cli-$id";
        exec("tmux kill-session -t $sessionName > /dev/null 2>&1");
    }
}

function startGeminiTerminal($id = 'default', $workingDir = null, $chatSessionId = null) {
    $sock = getGeminiSock($id);
    $shell = "/usr/local/emhttp/plugins/unraid-geminicli/scripts/gemini-shell.sh";
    $log = "/tmp/ttyd-gemini-$id.log";
    $pidFile = getGeminiPidFile($id);
    $lockFile = getGeminiLockFile($id);
    $chatIdFile = getGeminiChatIdFile($id);
    
    $config = getGeminiConfig();
    $workingDir = $workingDir ?: $config['root_path'];
    
    $fp = fopen($lockFile, "w+");
    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        fclose($fp);
        return; 
    }

    if (isGeminiRunning($id, $chatSessionId) && file_exists($sock)) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return;
    }

    stopGeminiTerminal($id, false);
    if (file_exists($shell)) chmod($shell, 0755);

    // Save the new chat ID before starting
    file_put_contents($chatIdFile, $chatSessionId ?: '');

    $env = "export GEMINI_HOME='{$config['home_path']}'; " .
           "export GEMINI_USER='{$config['user']}'; " .
           "export GEMINI_ROOT='$workingDir'; " .
           "export GEMINI_HISTORY='{$config['history']}'; " .
           "export GEMINI_SESSION_ID='$id'; ";
           
    if (!empty($chatSessionId)) {
        $env .= "export GEMINI_CHAT_SESSION_ID='$chatSessionId'; ";
    }

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
    
    // Perform light GC on start to keep things clean
    gcGeminiSessions();
    
    for ($i=0; $i<15; $i++) {
        if (file_exists($sock)) break;
        usleep(100000);
    }
    
    flock($fp, LOCK_UN);
    fclose($fp);
}

function findGeminiChatSession($path, $id = null) {
    // 1. If we have a tab ID, check if it's already running. 
    // If it is, the "truth" is whatever that process is currently using.
    if ($id !== null && isGeminiRunning($id)) {
        $chatIdFile = getGeminiChatIdFile($id);
        if (file_exists($chatIdFile)) {
            $current = trim(file_get_contents($chatIdFile));
            if (!empty($current)) return $current;
        }
    }

    $config = getGeminiConfig();
    $home = $config['home_path'];
    $projectsFile = "$home/.gemini/projects.json";
    
    if (!file_exists($projectsFile)) return null;
    
    $data = json_decode(file_get_contents($projectsFile), true);
    if (!isset($data['projects'])) return null;
    
    // Traverse up to find the nearest project project root (matching Gemini CLI behavior)
    $projectId = null;
    $checkPath = realpath($path);
    while ($checkPath && $checkPath !== '/') {
        // Sort projects by path length (longest first) to ensure we match the most specific one
        // in case of overlaps, although the loop already matches as it goes up.
        foreach ($data['projects'] as $pPath => $pId) {
            $realPPath = realpath($pPath);
            if ($realPPath && $realPPath === $checkPath) {
                // VERIFY: Does the project folder actually exist in tmp?
                if (is_dir("$home/.gemini/tmp/$pId")) {
                    $projectId = $pId;
                    break 2;
                }
            }
        }
        $checkPath = dirname($checkPath);
    }
    
    if (!$projectId) return null;

    $logFile = "$home/.gemini/tmp/$projectId/logs.json";
    
    if (file_exists($logFile)) {
        $logs = json_decode(file_get_contents($logFile), true);
        if (is_array($logs) && !empty($logs)) {
            // Get the sessionId from the very last entry
            $last = end($logs);
            $fullId = $last['sessionId'] ?? null;
            if ($fullId && strlen($fullId) > 8) {
                // Gemini CLI expects the short 8-char prefix for --resume
                return substr($fullId, 0, 8);
            }
            return $fullId;
        }
    }
    return null;
}

/**
 * Garbage collect stale ttyd processes and sockets
 */
function gcGeminiSessions() {
    $runDir = "/var/run";
    $socks = glob("$runDir/geminiterm-*.sock");
    $pids = glob("$runDir/unraid-geminicli-*.pid");

    // 1. Cleanup PID files where the process is gone
    foreach ($pids as $pf) {
        $pid = trim(file_get_contents($pf));
        if (!empty($pid) && !file_exists("/proc/$pid")) {
            @unlink($pf);
            // If the pid file is "unraid-geminicli-XYZ.pid", extract XYZ
            if (preg_match('/unraid-geminicli-(.*)\.pid$/', $pf, $m)) {
                $id = $m[1];
                $sock = getGeminiSock($id);
                if (file_exists($sock)) @unlink($sock);
            }
        }
    }

    // 2. Cleanup Sockets that have no ttyd listeners
    foreach ($socks as $sock) {
        if (preg_match('/geminiterm-(.*)\.sock$/', $sock, $m)) {
            $id = $m[1];
            if (!isGeminiRunning($id)) {
                @unlink($sock);
                $pidFile = getGeminiPidFile($id);
                if (file_exists($pidFile)) @unlink($pidFile);
            }
        }
    }
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
        $chatId = $_GET['chatId'] ?? null;
        startGeminiTerminal($id, $path, $chatId);
        echo json_encode(['status' => 'ok', 'sock' => "/webterminal/geminiterm-$id/"]);
    } elseif ($_GET['action'] === 'stop') {
        stopGeminiTerminal($id, isset($_GET['hard']));
        echo json_encode(['status' => 'ok']);
    } elseif ($_GET['action'] === 'gc') {
        gcGeminiSessions();
        echo json_encode(['status' => 'ok']);
    } elseif ($_GET['action'] === 'restart') {
        $path = $_GET['path'] ?? null;
        $chatId = $_GET['chatId'] ?? null;
        stopGeminiTerminal($id, true);
        startGeminiTerminal($id, $path, $chatId);
        echo json_encode(['status' => 'ok']);
    } elseif ($_GET['action'] === 'get_chat_session') {
        $path = $_GET['path'] ?? '';
        $id = $_GET['id'] ?? null;
        $chatId = findGeminiChatSession($path, $id);
        echo json_encode(['status' => 'ok', 'chatId' => $chatId]);
    } elseif ($_GET['action'] === 'save') {
        saveGeminiConfig($_POST);
        echo json_encode(['status' => 'ok']);
    } elseif ($_GET['action'] === 'list_dir') {
        $path = $_GET['path'] ?? '/mnt';
        if (empty($path)) $path = '/';
        $path = realpath($path) ?: $path;
        
        $items = [];
        if ($path !== '/') {
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
        } elseif ($_GET['action'] === 'get_session_status') {
            $id = preg_replace('/[^a-z0-9\-]/', '', $_GET['id'] ?? '');
            $path = $_GET['path'] ?? '';
            $session = "gemini-cli-$id";
            
            // 1. Get Live Title from Tmux
            $title = exec("tmux display-message -p -t $session '#T' 2>/dev/null");
            if (empty($title) || in_array($title, ['unraid', 'sh', 'bash'])) {
                $title = exec("tmux display-message -p -t $session '#W' 2>/dev/null");
            }

            // Restore Emojis: Gemini CLI uses ◇ (Ready), ✦ (Working), and ✋ (Busy)
            // If they are coming through as text, map them back for a cleaner look.
            $statusMap = [
                '_Ready'   => '◇',
                '_Working' => '✦',
                '_Busy'    => '✋'
            ];
            foreach ($statusMap as $txt => $emoji) {
                if (strpos($title, $txt) !== false) {
                    $title = str_replace($txt, $emoji, $title);
                }
            }
    
            // 2. Get Live Chat ID from Logs (Matches what's actually happening in terminal)
            $chatId = findGeminiChatSession($path, $id);
    
            echo json_encode([
                'status' => 'ok', 
                'title' => $title,
                'chatId' => $chatId
            ]);
        }
     elseif ($_GET['action'] === 'debug') {
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
