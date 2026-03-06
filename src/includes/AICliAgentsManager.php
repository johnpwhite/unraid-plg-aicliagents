<?php
/**
 * AICliAgents CLI Terminal Management
 */
@session_start();

// Set up global error logging to debug file
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    aicli_debug("PHP ERROR [$errno]: $errstr in $errfile on line $errline");
    return false;
});
set_exception_handler(function($e) {
    aicli_debug("PHP EXCEPTION: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
});

function aicli_debug($msg) {
    // D-06: Cache debug flag in static var to prevent recursion (aicli_debug -> getAICliConfig -> error -> aicli_debug)
    static $debugEnabled = null;
    if ($debugEnabled === null) {
        $configFile = "/boot/config/plugins/unraid-aicliagents/unraid-aicliagents.cfg";
        $cfg = file_exists($configFile) ? @parse_ini_file($configFile) : [];
        $debugEnabled = (($cfg['debug_logging'] ?? '0') === '1');
    }

    $msgStr = is_string($msg) ? $msg : json_encode($msg);
    // Always log critical errors even if debug is off
    if (!$debugEnabled && strpos($msgStr, 'ERROR') === false && strpos($msgStr, 'EXCEPTION') === false) return;

    // D-10: Write to RAM instead of USB to prevent flash wear
    $logDir = "/tmp/unraid-aicliagents";
    if (!is_dir($logDir)) @mkdir($logDir, 0777, true);
    $logFile = "$logDir/debug.log";

    // Size cap: truncate if > 500KB to prevent runaway growth
    if (file_exists($logFile) && filesize($logFile) > 512000) {
        $tail = @file_get_contents($logFile, false, null, -256000);
        @file_put_contents($logFile, "--- LOG TRUNCATED ---\n" . $tail);
    }

    $timestamp = date("Y-m-d H:i:s");
    $output = "[$timestamp] $msgStr\n";
    @file_put_contents($logFile, $output, FILE_APPEND);
}

function aicli_notify($subject, $message, $type = 'normal') {
    $command = "/usr/local/emhttp/webGui/scripts/notify -e \"AICliAgents\" -s " . escapeshellarg($subject) . " -d " . escapeshellarg($message) . " -i " . escapeshellarg($type);
    shell_exec($command);
}

function setInstallStatus($msg, $progress) {
    file_put_contents("/tmp/aicli-install-status", json_encode(['message' => $msg, 'progress' => $progress]));
}

function saveAICliConfig($newConfig) {
    $configFile = "/boot/config/plugins/unraid-aicliagents/unraid-aicliagents.cfg";
    $vaultFile = "/boot/config/plugins/unraid-aicliagents/secrets.cfg";
    $current = getAICliConfig();
    
    aicli_debug("saveAICliConfig called");

    // 1. Handle Vault (API Keys) - Preserve existing keys if not in POST
    $vaultKeys = ['GEMINI_API_KEY', 'CLAUDE_API_KEY', 'AIDER_API_KEY', 'OPENAI_API_KEY'];
    $existingVault = file_exists($vaultFile) ? @parse_ini_file($vaultFile) : [];
    $vaultIni = "";
    foreach ($vaultKeys as $vk) {
        $val = isset($newConfig[$vk]) ? trim($newConfig[$vk]) : ($existingVault[$vk] ?? '');
        // D-17: Escape single quotes in vault values to prevent INI corruption
        $escapedVal = addcslashes($val, "'");
        $vaultIni .= "$vk='$escapedVal'\n";
    }
    
    aicli_debug("Updating secrets vault");
    file_put_contents($vaultFile, $vaultIni);
    chmod($vaultFile, 0600); 

    // 2. Handle Main Config
    $allowed = ['enable_tab', 'theme', 'font_size', 'history', 'home_path', 'user', 'root_path', 'version', 'debug_logging'];
    foreach ($newConfig as $key => $val) {
        if (strpos($key, 'preview_') === 0) $allowed[] = $key;
    }
    
    foreach ($newConfig as $key => $value) {
        if (in_array($key, $allowed)) {
            $current[$key] = $value;
        }
    }
    
    // Build the INI string
    // D-17: Escape double quotes and backslashes to prevent INI corruption
    $ini = "";
    foreach ($current as $key => $value) {
        $escapedValue = addcslashes($value, '"\\');
        $ini .= "$key=\"$escapedValue\"\n";
    }
    
    if (!file_exists(dirname($configFile))) {
        mkdir(dirname($configFile), 0777, true);
    }
    
    aicli_debug("Writing config to $configFile");
    if (file_put_contents($configFile, $ini) !== false) {
        aicli_notify("Settings Saved", "Plugin configuration has been updated.");
    } else {
        aicli_debug("ERROR: Failed to write to config file $configFile");
    }
    
    updateAICliMenuVisibility($current['enable_tab']);
}

function getAICliConfig() {
    $configFile = "/boot/config/plugins/unraid-aicliagents/unraid-aicliagents.cfg";
    $defaults = [
        'enable_tab' => '1',
        'theme' => 'dark',
        'font_size' => '14',
        'history' => '1000',
        'home_path' => '/boot/config/plugins/unraid-aicliagents/home',
        'user' => 'root',
        'root_path' => '/mnt/user',
        'version' => 'unknown',
        'debug_logging' => '0'
    ];
    
    if (file_exists($configFile)) {
        $config = @parse_ini_file($configFile);
        return array_merge($defaults, is_array($config) ? $config : []);
    }
    
    return $defaults;
}

function updateAICliMenuVisibility($enabled) {
    $pageFile = "/usr/local/emhttp/plugins/unraid-aicliagents/AICliAgents.page";
    if (!file_exists($pageFile)) return;
    
    $content = file_get_contents($pageFile);
    $type = ($enabled == "1") ? "xmenu" : "node";
    
    // Standard Unraid .page file Regex for Type
    $newContent = preg_replace('/Type=".*"/', "Type=\"$type\"", $content);
    file_put_contents($pageFile, $newContent);
}

/**
 * Returns a JSON theme string for ttyd based on the selected theme.
 * Colors optimized for high-contrast and legibility.
 */
function getAICliTtydTheme($theme) {
    switch ($theme) {
        case 'light':
            return json_encode([
                'background' => '#ffffff',
                'foreground' => '#222222',
                'cursor' => '#ff8c00',
                'black' => '#000000',
                'red' => '#cc0000',
                'green' => '#4e9a06',
                'yellow' => '#c4a000',
                'blue' => '#3465a4',
                'magenta' => '#75507b',
                'cyan' => '#06989a',
                'white' => '#d3d7cf'
            ]);
        case 'solarized':
            // Solarized Dark
            return json_encode([
                'background' => '#002b36',
                'foreground' => '#839496',
                'cursor' => '#ff8c00',
                'black' => '#073642',
                'red' => '#dc322f',
                'green' => '#859900',
                'yellow' => '#b58900',
                'blue' => '#268bd2',
                'magenta' => '#d33682',
                'cyan' => '#2aa198',
                'white' => '#eee8d5'
            ]);
        case 'dark':
        default:
            return json_encode([
                'background' => '#0d0d0d',
                'foreground' => '#e0e0e0',
                'cursor' => '#ff8c00',
                'black' => '#000000',
                'red' => '#cc0000',
                'green' => '#4e9a06',
                'yellow' => '#c4a000',
                'blue' => '#3465a4',
                'magenta' => '#75507b',
                'cyan' => '#06989a',
                'white' => '#d3d7cf'
            ]);
    }
}

function getAICliPidFile($id = 'default') {
    return "/var/run/unraid-aicliagents-$id.pid";
}

function getAICliLockFile($id = 'default') {
    return "/var/run/unraid-aicliagents-$id.lock";
}

function getAICliSock($id = 'default') {
    return "/var/run/aicliterm-$id.sock";
}

function getAICliChatIdFile($id = 'default') {
    return "/var/run/unraid-aicliagents-$id.chatid";
}

function getAICliAgentIdFile($id = 'default') {
    return "/var/run/unraid-aicliagents-$id.agentid";
}

function isAICliRunning($id = 'default', $chatId = null, $agentId = null) {
    $sock = getAICliSock($id);
    $pids = [];
    // Find ttyd process for this specific socket
    // We check for the socket path in the command line of ttyd processes
    exec("pgrep -x ttyd | xargs -I {} ps -p {} -o pid=,args= | grep " . escapeshellarg($sock) . " | grep -v grep", $pids);
    
    if (empty($pids)) return false;

    // 1. Verify Agent ID and Session ID by looking at the command line environment string
    $foundMatch = false;
    foreach ($pids as $p) {
        $agentMatch = ($agentId === null || strpos($p, "AGENT_ID='$agentId'") !== false);
        $sessionMatch = (strpos($p, "AICLI_SESSION_ID='$id'") !== false);
        
        if ($agentMatch && $sessionMatch) {
            $foundMatch = true;
            break;
        }
    }
    if (!$foundMatch) return false;

    // 2. Verify Chat ID
    if ($chatId !== null) {
        $chatIdFile = getAICliChatIdFile($id);
        $runningChatId = file_exists($chatIdFile) ? trim(file_get_contents($chatIdFile)) : '';
        if ($chatId !== $runningChatId) {
            return false; // Chat ID mismatch
        }
    }

    return true;
}

function stopAICliTerminal($id = 'default', $killTmux = false) {
    // D-01: Validate session ID to prevent command injection
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
    $sock = getAICliSock($id);
    $pidFile = getAICliPidFile($id);
    $chatIdFile = getAICliChatIdFile($id);
    $agentIdFile = getAICliAgentIdFile($id);
    
    // 1. Kill ttyd
    $pids = [];
    exec("pgrep -x ttyd | xargs -I {} ps -p {} -o pid=,args= | grep " . escapeshellarg($sock) . " | awk '{print $1}'", $pids);
    foreach ($pids as $pid) {
        $pid = trim($pid);
        if (!empty($pid) && ctype_digit($pid)) exec("kill -9 " . escapeshellarg($pid) . " > /dev/null 2>&1");
    }
    
    // 2. Kill associated agent processes (even if orphaned)
    // D-03: Initialize $nodePids before exec() to prevent undefined variable
    $nodePids = [];
    $escapedId = escapeshellarg("AICLI_SESSION_ID=$id");
    exec("pgrep -f $escapedId 2>/dev/null", $nodePids);
    foreach ($nodePids as $np) {
        $np = trim($np);
        if (!empty($np) && ctype_digit($np)) exec("kill -9 " . escapeshellarg($np) . " > /dev/null 2>&1");
    }
    
    if (file_exists($sock)) @unlink($sock);
    if (file_exists($pidFile)) @unlink($pidFile);
    if (file_exists($chatIdFile)) @unlink($chatIdFile);
    if (file_exists($agentIdFile)) @unlink($agentIdFile);

    if ($killTmux) {
        // Kill any tmux session matching the pattern aicli-agent-*-id
        $safeId = escapeshellarg($id);
        exec("tmux ls -F '#S' 2>/dev/null | grep -E '^aicli-agent-.*-'$safeId'$' | xargs -I {} tmux kill-session -t {} > /dev/null 2>&1");
    }
}

// D-19: Shared NPM package mapping to avoid duplication across install and update functions
function getAICliNpmMap() {
    return [
        'opencode' => 'opencode-ai',
        'claude-code' => '@anthropic-ai/claude-code',
        'kilocode' => '@kilocode/cli',
        'pi-coder' => '@mariozechner/pi-coding-agent',
        'codex-cli' => '@openai/codex'
    ];
}

function getAICliAgentsRegistry() {
    $manifestFile = "/boot/config/plugins/unraid-aicliagents/agents.json";
    $binDir = "/usr/local/emhttp/plugins/unraid-aicliagents/bin";
    $bootConfig = "/boot/config/plugins/unraid-aicliagents";

    $defaultRegistry = [
        'gemini-cli' => [
            'id' => 'gemini-cli',
            'name' => 'Gemini CLI',
            'icon_url' => '/plugins/unraid-aicliagents/unraid-aicliagents.png',
            'release_notes' => 'https://github.com/google-gemini/gemini-cli/releases',
            'runtime' => 'node',
            'binary' => "node $binDir/aicli.mjs",
            'resume_cmd' => "node $binDir/aicli.mjs --resume {chatId}",
            'resume_latest' => "node $binDir/aicli.mjs --resume",
            'env_prefix' => 'GEMINI',
            'is_installed' => file_exists("$binDir/aicli.mjs")
        ],
        'claude-code' => [
            'id' => 'claude-code',
            'name' => 'Claude Code',
            'icon_url' => '/plugins/unraid-aicliagents/assets/icons/claude.ico',
            'release_notes' => 'https://www.npmjs.com/package/@anthropic-ai/claude-code?activeTab=versions',
            'runtime' => 'node',
            'binary' => "$binDir/node_modules/.bin/claude",
            'resume_cmd' => "$binDir/node_modules/.bin/claude --resume {chatId}",
            'resume_latest' => "$binDir/node_modules/.bin/claude --continue",
            'env_prefix' => 'CLAUDE',
            'is_installed' => file_exists("$binDir/node_modules/.bin/claude")
        ],
        'opencode' => [
            'id' => 'opencode',
            'name' => 'OpenCode',
            'icon_url' => '/plugins/unraid-aicliagents/assets/icons/opencode.ico',
            'release_notes' => 'https://github.com/anomalyco/opencode/releases',
            'runtime' => 'node',
            'binary' => "$binDir/node_modules/.bin/opencode",
            'resume_cmd' => "$binDir/node_modules/.bin/opencode --session {chatId}",
            'resume_latest' => "$binDir/node_modules/.bin/opencode --continue",
            'env_prefix' => 'OPENCODE',
            'is_installed' => file_exists("$binDir/node_modules/.bin/opencode")
        ],
        'kilocode' => [
            'id' => 'kilocode',
            'name' => 'Kilo Code',
            'icon_url' => '/plugins/unraid-aicliagents/assets/icons/kilocode.ico',
            'release_notes' => 'https://github.com/Kilo-Org/kilocode/releases',
            'runtime' => 'node',
            'binary' => "$binDir/node_modules/.bin/kilo",
            'resume_cmd' => "$binDir/node_modules/.bin/kilo --session {chatId}",
            'resume_latest' => "$binDir/node_modules/.bin/kilo --continue",
            'env_prefix' => 'KILOCODE',
            'is_installed' => file_exists("$binDir/node_modules/.bin/kilo")
        ],
        'pi-coder' => [
            'id' => 'pi-coder',
            'name' => 'Pi Coder',
            'icon_url' => '/plugins/unraid-aicliagents/assets/icons/picoder.png',
            'release_notes' => 'https://github.com/badlogic/pi-mono/releases',
            'runtime' => 'node',
            'binary' => "$binDir/node_modules/.bin/pi",
            'resume_cmd' => "$binDir/node_modules/.bin/pi",
            'resume_latest' => "$binDir/node_modules/.bin/pi",
            'env_prefix' => 'PI_CODER',
            'is_installed' => file_exists("$binDir/node_modules/.bin/pi")
        ],
        'codex-cli' => [
            'id' => 'codex-cli',
            'name' => 'Codex CLI',
            'icon_url' => '/plugins/unraid-aicliagents/assets/icons/codex.png',
            'release_notes' => 'https://www.npmjs.com/package/@openai/codex?activeTab=versions',
            'runtime' => 'node',
            'binary' => "$binDir/node_modules/.bin/codex",
            'resume_cmd' => "$binDir/node_modules/.bin/codex",
            'resume_latest' => "$binDir/node_modules/.bin/codex",
            'env_prefix' => 'CODEX',
            'is_installed' => file_exists("$binDir/node_modules/.bin/codex")
        ]
    ];

    if (file_exists($manifestFile)) {
        $custom = json_decode(file_get_contents($manifestFile), true);
        if (is_array($custom) && isset($custom['agents'])) {
            return $custom['agents'];
        }
    }

    return $defaultRegistry;
}
function startAICliTerminal($id = 'default', $workingDir = null, $chatSessionId = null, $agentId = 'gemini-cli') {
    // D-01/D-02: Sanitize inputs to prevent command injection
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
    $agentId = preg_replace('/[^a-zA-Z0-9_-]/', '', $agentId);
    if ($chatSessionId !== null) $chatSessionId = preg_replace('/[^a-zA-Z0-9_-]/', '', $chatSessionId);

    aicli_debug("startAICliTerminal called: ID=$id, Agent=$agentId, Path=$workingDir");
    $sock = getAICliSock($id);
    $shell = "/usr/local/emhttp/plugins/unraid-aicliagents/scripts/aicli-shell.sh";
    $log = "/tmp/ttyd-aicli-$id.log";
    $pidFile = getAICliPidFile($id);
    $lockFile = getAICliLockFile($id);
    $chatIdFile = getAICliChatIdFile($id);
    $agentIdFile = getAICliAgentIdFile($id);

    // D-04: Define $binDir (was undefined in this scope)
    $binDir = "/usr/local/emhttp/plugins/unraid-aicliagents/bin";

    $registry = getAICliAgentsRegistry();
    $agent = $registry[$agentId] ?? $registry['gemini-cli'];

    if (!$agent['is_installed']) {
        aicli_debug("ERROR: Agent $agentId is not installed.");
        return;
    }

    $config = getAICliConfig();
    $workingDir = $workingDir ?: $config['root_path'];

    // Ensure Home directory exists
    $homePath = $config['home_path'];
    if (!is_dir($homePath)) {
        aicli_debug("Creating home directory: $homePath");
        mkdir($homePath, 0777, true);
    }

    // Ensure binary is in RAM (Restore from USB cache if missing)
    $binPath = ($agentId === 'gemini-cli') ? "$binDir/aicli.mjs" : "$binDir/node_modules";
    
    if (!file_exists($binPath)) {
        aicli_debug("Agent $agentId missing from RAM, attempting restore...");
        $cacheFile = "/boot/config/plugins/unraid-aicliagents/pkg-cache/$agentId.tar.gz";
        if (file_exists($cacheFile)) {
            aicli_debug("Found cached agent: $cacheFile. Restoring to RAM...");
            // D-20: Use --no-same-owner for permission robustness on Unraid filesystems
            exec("tar -xzf " . escapeshellarg($cacheFile) . " --no-same-owner -C " . escapeshellarg($binDir) . "/");
        } elseif ($agentId === 'gemini-cli') {
            // Legacy/Optimized single-file fallback for Gemini
            $bootSource = "/boot/config/plugins/unraid-aicliagents/aicli.mjs";
            if (file_exists($bootSource)) {
                aicli_debug("Restoring Gemini from legacy boot source");
                copy($bootSource, "$binDir/aicli.mjs");
            }
        }
    }

    // D-05: Removed duplicate getAICliConfig() call
    
    $fp = fopen($lockFile, "w+");
    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        fclose($fp);
        return; 
    }

    if (isAICliRunning($id, $chatSessionId, $agentId) && file_exists($sock)) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return;
    }

    stopAICliTerminal($id, false);
    
    // Check if the agent has changed for this session ID
    $runningAgentId = file_exists($agentIdFile) ? trim(file_get_contents($agentIdFile)) : '';
    if ($runningAgentId !== '' && $runningAgentId !== $agentId) {
        // Agent changed! We MUST kill the old tmux session or we will just re-attach to the old agent
        $sessionName = "aicli-agent-" . escapeshellarg($runningAgentId) . "-" . escapeshellarg($id);
        exec("tmux kill-session -t $sessionName > /dev/null 2>&1");
        aicli_debug("Killed old agent session: $sessionName");
    }

    if (file_exists($shell)) chmod($shell, 0755);

    // Save state before starting
    file_put_contents($chatIdFile, $chatSessionId ?: '');
    file_put_contents($agentIdFile, $agentId);

    // D-02: Escape all env values to prevent shell injection
    $safeHome = escapeshellarg($config['home_path']);
    $safeUser = escapeshellarg($config['user']);
    $safeRoot = escapeshellarg($workingDir);
    $safeHistory = escapeshellarg($config['history']);
    $safeId = escapeshellarg($id);
    $safeAgentId = escapeshellarg($agentId);
    $safeAgentName = escapeshellarg($agent['name']);
    $safeEnvPrefix = escapeshellarg($agent['env_prefix']);
    $safeBinary = escapeshellarg($agent['binary']);
    $safeResumeCmd = escapeshellarg($agent['resume_cmd']);
    $safeResumeLatest = escapeshellarg($agent['resume_latest']);
    $safeSock = escapeshellarg($sock);
    $safeFontSize = escapeshellarg($config['font_size']);

    $env = "export AICLI_HOME=$safeHome; " .
           "export AICLI_USER=$safeUser; " .
           "export AICLI_ROOT=$safeRoot; " .
           "export AICLI_HISTORY=$safeHistory; " .
           "export AICLI_SESSION_ID=$safeId; " .
           "export AGENT_ID=$safeAgentId; " .
           "export AGENT_NAME=$safeAgentName; " .
           "export ENV_PREFIX=$safeEnvPrefix; " .
           "export BINARY=$safeBinary; " .
           "export RESUME_CMD=$safeResumeCmd; " .
           "export RESUME_LATEST=$safeResumeLatest; ";
           
    if (!empty($chatSessionId)) {
        $safeChatId = escapeshellarg($chatSessionId);
        $env .= "export AICLI_CHAT_SESSION_ID=$safeChatId; ";
    }

    $themeStr = getAICliTtydTheme($config['theme'] ?? 'dark');
    
    $cmd = "ttyd -i $safeSock -W -d0 " .
           "-t fontSize=$safeFontSize " .
           "-t fontFamily='monospace' " .
           "-t theme='$themeStr' " .
           "-t disableLeaveAlert=true " .
           "-t enable-utf8=true " .
           "-t titleFixed=" . escapeshellarg($agent['name'] . " - $id") . " " .
           "bash -c \"$env $shell\"";
    
    exec("nohup $cmd >> " . escapeshellarg($log) . " 2>&1 & echo $!", $output);
    $pid = trim($output[0] ?? '');
    if ($pid && ctype_digit($pid)) file_put_contents($pidFile, $pid);
    
    flock($fp, LOCK_UN);
    fclose($fp);
}

function findAICliChatSession($path, $id = null, $agentId = 'gemini-cli') {
    if (empty($path)) return null;
    
    // 1. If we have a session ID, check if it's ALREADY running THIS specific agent
    if ($id !== null && isAICliRunning($id, null, $agentId)) {
        $chatIdFile = getAICliChatIdFile($id);
        if (file_exists($chatIdFile)) {
            $current = trim(file_get_contents($chatIdFile));
            if (!empty($current)) return $current;
        }
    }

    // 2. Perform agent-specific project discovery
    if ($agentId === 'gemini-cli') {
        $config = getAICliConfig();
        $home = $config['home_path'];
        $projectsFile = "$home/.gemini/projects.json";
        if (!file_exists($projectsFile)) return null;
        
        $data = @json_decode(file_get_contents($projectsFile), true);
        $projects = $data['projects'] ?? [];
        
        $lookup = [];
        foreach ($projects as $pPath => $pId) {
            $rp = realpath($pPath);
            if ($rp) $lookup[$rp] = $pId;
        }

        $checkPath = realpath($path);
        while ($checkPath && $checkPath !== '/') {
            if (isset($lookup[$checkPath])) {
                $pId = $lookup[$checkPath];
                if (is_dir("$home/.gemini/tmp/$pId")) {
                    $logFile = "$home/.gemini/tmp/$pId/logs.json";
                    if (file_exists($logFile)) {
                        $logs = @json_decode(file_get_contents($logFile), true);
                        if ($logs && count($logs) > 0) {
                            return end($logs)['chatSessionId'] ?? null;
                        }
                    }
                }
                break;
            }
            $checkPath = dirname($checkPath);
        }
    } elseif ($agentId === 'claude-code' || $agentId === 'opencode') {
        // Claude and OpenCode use their own session management, 
        // for now we just return null and let them resume 'latest' internally
        return null;
    }

    return null;
}

function gcAICliSessions() {
    $runDir = "/var/run";
    $socks = glob("$runDir/aicliterm-*.sock");
    foreach ($socks as $sock) {
        if (preg_match('/aicliterm-(.*)\.sock$/', $sock, $m)) {
            $id = $m[1];
            if (!isAICliRunning($id)) {
                stopAICliTerminal($id, true);
            }
        }
    }
}

function getAICliVersions() {
    $file = "/boot/config/plugins/unraid-aicliagents/versions.json";
    if (file_exists($file)) return json_decode(file_get_contents($file), true) ?: [];
    return [];
}

function saveAICliVersion($agentId, $version) {
    $file = "/boot/config/plugins/unraid-aicliagents/versions.json";
    $versions = getAICliVersions();
    $versions[$agentId] = $version;
    file_put_contents($file, json_encode($versions, JSON_PRETTY_PRINT));
}

function installAgent($agentId) {
    aicli_debug("installAgent started for $agentId");
    setInstallStatus("Initializing...", 10);
    $registry = getAICliAgentsRegistry();
    if (!isset($registry[$agentId])) {
        aicli_debug("ERROR: Agent $agentId not found in registry");
        return ['status' => 'error', 'error' => 'Agent not found in registry'];
    }
    
    $config = getAICliConfig();
    $usePreview = ($config["preview_$agentId"] ?? "0") === "1";
    aicli_debug("Using preview channel: " . ($usePreview ? "yes" : "no"));
    
    $bootConfig = "/boot/config/plugins/unraid-aicliagents";
    $cacheDir = "$bootConfig/pkg-cache";
    $binDir = "/usr/local/emhttp/plugins/unraid-aicliagents/bin";
    
    if (!is_dir($cacheDir)) mkdir($cacheDir, 0777, true);
    if (!is_dir($binDir)) mkdir($binDir, 0777, true);

    // 1. Prepare temporary RAM directory for installation/staging
    setInstallStatus("Preparing temporary RAM area...", 20);
    $tmpDir = "/tmp/aicli-install-$agentId";
    if (is_dir($tmpDir)) exec("rm -rf $tmpDir");
    mkdir($tmpDir, 0777, true);

    $installedVer = "installed";

    if ($agentId === 'gemini-cli') {
        setInstallStatus("Checking " . ($usePreview ? "preview" : "latest") . " version...", 30);
        $opts = [ "http" => [ "method" => "GET", "header" => "User-Agent: Unraid-AICliAgents\r\n" ] ];
        $context = stream_context_create($opts);
        
        $githubUrl = $usePreview ? "https://api.github.com/repos/google-gemini/gemini-cli/releases" : "https://api.github.com/repos/google-gemini/gemini-cli/releases/latest";
        aicli_debug("Fetching Gemini releases from $githubUrl");
        
        $tag = "v0.31.0"; 
        try {
            $response = @file_get_contents($githubUrl, false, $context);
            if ($response) {
                $data = json_decode($response, true);
                if ($usePreview && is_array($data) && isset($data[0]['tag_name'])) {
                    $tag = $data[0]['tag_name'];
                } elseif (!$usePreview && isset($data['tag_name'])) {
                    $tag = $data['tag_name'];
                }
                aicli_debug("Resolved Gemini tag: $tag");
            } else {
                aicli_debug("WARNING: GitHub API returned no response, using fallback tag $tag");
            }
        } catch (Exception $e) {
            aicli_debug("EXCEPTION: GitHub API call failed: " . $e->getMessage());
        }

        setInstallStatus("Downloading Gemini $tag...", 50);
        $url = "https://github.com/google-gemini/gemini-cli/releases/download/$tag/gemini.js";
        $dest = "$tmpDir/aicli.mjs";
        aicli_debug("Downloading Gemini from $url to $dest");
        exec("wget -q -O " . escapeshellarg($dest) . " " . escapeshellarg($url), $output, $result);
        
        if ($result !== 0 || !file_exists($dest)) {
            aicli_debug("ERROR: Gemini download failed (code $result)");
            return ['status' => 'error', 'error' => "Download failed (wget code $result)"];
        }
        $installedVer = $tag;
    } else {
        // NPM-based agents — D-19: Use shared mapping function
        $npmMap = getAICliNpmMap();

        $package = $npmMap[$agentId] ?? null;
        if (!$package) {
            aicli_debug("ERROR: No NPM package mapping for $agentId");
            return ['status' => 'error', 'error' => 'NPM package mapping missing'];
        }

        $installPackage = $package;
        if ($usePreview) $installPackage .= "@next";

        setInstallStatus("Downloading & Installing $installPackage (NPM)...", 50);
        aicli_debug("Running: npm install --prefix " . escapeshellarg($tmpDir) . " " . escapeshellarg($installPackage));
        $npmOutput = [];
        exec("npm install --prefix " . escapeshellarg($tmpDir) . " " . escapeshellarg($installPackage) . " 2>&1", $npmOutput, $result);
        aicli_debug("NPM finished with code $result.");
        
        if ($result !== 0) {
            aicli_debug("ERROR: NPM install failed for $installPackage");
            return ['status' => 'error', 'error' => 'NPM install failed: ' . end($npmOutput)];
        }

        // Get installed version from package.json in the tmp dir
        $pJson = "$tmpDir/node_modules/" . str_replace('/', DIRECTORY_SEPARATOR, $package) . "/package.json";
        if (file_exists($pJson)) {
            $pData = json_decode(file_get_contents($pJson), true);
            $installedVer = $pData['version'] ?? $installedVer;
            aicli_debug("Detected version from package.json: $installedVer");
        }
    }

    // 2. UNIFIED CACHING: Tarball the resulting installation to USB
    setInstallStatus("Backing up install for reboot support...", 70);
    aicli_debug("Taring $tmpDir to $cacheDir/$agentId.tar.gz");
    $tarOutput = [];
    // D-20: Use --no-same-owner during both pack and unpack to ensure compatibility
    exec("tar -czf " . escapeshellarg("$cacheDir/$agentId.tar.gz") . " --no-same-owner -C " . escapeshellarg($tmpDir) . " . 2>&1", $tarOutput, $result);
    if ($result !== 0) {
        aicli_debug("ERROR: Tarball creation failed. Log: " . implode("\n", $tarOutput));
        return ['status' => 'error', 'error' => 'Caching to USB failed'];
    }

    // 3. Move to active RAM bin directory
    setInstallStatus("Deploying to active RAM environment...", 90);
    aicli_debug("Copying installed files from $tmpDir to $binDir");
    // Ensure binary directory clean for this agent if it was a single file (like Gemini)
    if ($agentId === 'gemini-cli' && file_exists("$binDir/aicli.mjs")) unlink("$binDir/aicli.mjs");
    
    exec("cp -r " . escapeshellarg($tmpDir) . "/* " . escapeshellarg($binDir) . "/", $output, $result);
    exec("rm -rf $tmpDir");

    saveAICliVersion($agentId, $installedVer);
    setInstallStatus("Installation Complete!", 100);
    aicli_debug("Agent $agentId installed successfully");
    return ['status' => 'ok'];
}

function uninstallAgent($agentId) {
    aicli_debug("uninstallAgent started for $agentId");
    $bootConfig = "/boot/config/plugins/unraid-aicliagents";
    $cacheDir = "$bootConfig/pkg-cache";
    $binDir = "/usr/local/emhttp/plugins/unraid-aicliagents/bin";

    // 1. Remove from RAM
    if ($agentId === 'gemini-cli') {
        if (file_exists("$binDir/aicli.mjs")) unlink("$binDir/aicli.mjs");
    } else {
        // D-07: Clean up NPM agent binaries from RAM
        $npmMap = getAICliNpmMap();
        $package = $npmMap[$agentId] ?? null;
        if ($package) {
            // Remove the specific package directory from node_modules
            $pkgDir = "$binDir/node_modules/" . str_replace('/', DIRECTORY_SEPARATOR, $package);
            if (is_dir($pkgDir)) {
                exec("rm -rf " . escapeshellarg($pkgDir));
                aicli_debug("Removed NPM package dir: $pkgDir");
            }
            // Remove the .bin symlink if it exists
            $binName = basename($package);
            $binLink = "$binDir/node_modules/.bin/$binName";
            if (file_exists($binLink)) @unlink($binLink);
        }
    }

    // 2. Remove USB cache
    $cacheFile = "$cacheDir/$agentId.tar.gz";
    if (file_exists($cacheFile)) {
        aicli_debug("Removing cache file: $cacheFile");
        unlink($cacheFile);
    }
    
    // Legacy Gemini cleanup
    if ($agentId === 'gemini-cli' && file_exists("$bootConfig/aicli.mjs")) {
        unlink("$bootConfig/aicli.mjs");
    }

    // 3. Remove version record
    $versions = getAICliVersions();
    if (isset($versions[$agentId])) {
        aicli_debug("Removing version record for $agentId");
        unset($versions[$agentId]);
        file_put_contents("/boot/config/plugins/unraid-aicliagents/versions.json", json_encode($versions, JSON_PRETTY_PRINT));
    }
    
    return ['status' => 'ok'];
}

function checkAgentUpdates() {
    $registry = getAICliAgentsRegistry();
    $currentVersions = getAICliVersions();
    $config = getAICliConfig();
    $updates = [];
    
    foreach ($registry as $id => $agent) {
        if (!$agent['is_installed']) continue;
        $hasUpdate = false;
        $latestVersion = "Unknown";
        $current = $currentVersions[$id] ?? "0.0.0";
        $usePreview = ($config["preview_$id"] ?? "0") === "1";

        if ($id === 'gemini-cli') {
            $opts = [ "http" => [ "method" => "GET", "header" => "User-Agent: Unraid-AICliAgents\r\n" ] ];
            $context = stream_context_create($opts);
            $url = $usePreview ? "https://api.github.com/repos/google-gemini/gemini-cli/releases" : "https://api.github.com/repos/google-gemini/gemini-cli/releases/latest";
            $response = @file_get_contents($url, false, $context);
            if ($response) {
                $data = json_decode($response, true);
                $latestVersion = $usePreview ? ($data[0]['tag_name'] ?? 'Unknown') : ($data['tag_name'] ?? 'Unknown');
                if ($latestVersion !== $current) $hasUpdate = true;
            }
        } else {
            // NPM agents — D-19: Use shared mapping function
            $npmMap = getAICliNpmMap();
            $package = $npmMap[$id] ?? null;
            if ($package) {
                $tag = $usePreview ? "next" : "latest";
                $latestVersion = trim(shell_exec("npm show " . escapeshellarg($package) . " version --tag=" . escapeshellarg($tag)) ?? '');
                if ($latestVersion && $latestVersion !== $current) $hasUpdate = true;
            }
        }
        $updates[$id] = ['has_update' => $hasUpdate, 'latest_version' => $latestVersion, 'installed_version' => $current];
    }
    return ['status' => 'ok', 'updates' => $updates];
}

function aicli_tail($file, $lines) {
    $output = shell_exec("tail -n $lines " . escapeshellarg($file));
    return explode("\n", $output ?? "");
}

if (isset($_GET['action'])) {
    // Standard CSRF Validation for ALL actions
    $var = @parse_ini_file("/var/local/emhttp/var.ini");
    $expected = $var['csrf_token'] ?? 'NOT_SET';
    
    // Check POST first, then GET (some legacy calls use GET)
    $received = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? 'MISSING';
    
    if (is_array($received)) {
        aicli_debug("CSRF is array: " . json_encode($received));
        $received = end($received);
    }
    
    $received = trim((string)$received);
    $expected = trim((string)$expected);
    
    if ($received === 'MISSING' || $received !== $expected) {
        // Fallback to Session Check (Unraid 7+)
        @session_start();
        if (isset($_SESSION['csrf_token']) && trim((string)$_SESSION['csrf_token']) === $received) {
            aicli_debug("CSRF Validation Passed via Session Fallback.");
        } else {
            aicli_debug("CSRF VALIDATION FAILED! Action: " . $_GET['action']);
            aicli_debug("Expected (from var.ini): [$expected]");
            aicli_debug("Received: [$received]");
            if (isset($_SESSION['csrf_token'])) aicli_debug("Session CSRF also mismatch: [" . trim((string)$_SESSION['csrf_token']) . "]");
            
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF Token']);
            exit;
        }
    }
    
    header('Content-Type: application/json');
    $id = $_GET['id'] ?? 'default';

    if ($_GET['action'] === 'start') {
        $path = $_GET['path'] ?? null;
        $chatId = $_GET['chatId'] ?? null;
        $agentId = $_GET['agentId'] ?? 'gemini-cli';
        startAICliTerminal($id, $path, $chatId, $agentId);
        echo json_encode(['status' => 'ok', 'sock' => "/webterminal/aicliterm-$id/"]);
    } elseif ($_GET['action'] === 'install_agent') {
        $agentId = $_GET['agentId'] ?? '';
        echo json_encode(installAgent($agentId));
    } elseif ($_GET['action'] === 'uninstall_agent') {
        $agentId = $_GET['agentId'] ?? '';
        echo json_encode(uninstallAgent($agentId));
    } elseif ($_GET['action'] === 'check_updates') {
        echo json_encode(checkAgentUpdates());
    } elseif ($_GET['action'] === 'stop') {
        stopAICliTerminal($id, isset($_GET['hard']));
        echo json_encode(['status' => 'ok']);
    } elseif ($_GET['action'] === 'gc') {
        gcAICliSessions();
        echo json_encode(['status' => 'ok']);
    } elseif ($_GET['action'] === 'restart') {
        $path = $_GET['path'] ?? null;
        $chatId = $_GET['chatId'] ?? null;
        $agentId = $_GET['agentId'] ?? 'gemini-cli';
        stopAICliTerminal($id, true);
        startAICliTerminal($id, $path, $chatId, $agentId);
        echo json_encode(['status' => 'ok']);
    } elseif ($_GET['action'] === 'get_chat_session') {
        $path = $_GET['path'] ?? '';
        $id = $_GET['id'] ?? null;
        $agentId = $_GET['agentId'] ?? 'gemini-cli';
        $chatId = findAICliChatSession($path, $id, $agentId);
        echo json_encode(['chatId' => $chatId]);
    } elseif ($_GET['action'] === 'save') {
        saveAICliConfig($_POST);
        echo json_encode(['status' => 'ok']);
    } elseif ($_GET['action'] === 'move_home') {
        $old = $_POST['old_path'] ?? '';
        $new = $_POST['new_path'] ?? '';
        if (empty($old) || empty($new) || !is_dir($old)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid source or target']);
            exit;
        }
        if (!is_dir($new)) mkdir($new, 0777, true);
        
        aicli_debug("Moving home data from $old to $new");
        exec("cp -rn " . escapeshellarg($old) . "/* " . escapeshellarg($new) . "/ 2>&1", $output, $result);
        if ($result === 0) {
            echo json_encode(['status' => 'ok']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to copy data', 'output' => $output]);
        }
    } elseif ($_GET['action'] === 'list_dir') {
        $path = $_GET['path'] ?? '/mnt';
        if (!is_dir($path)) { echo json_encode(['error' => 'Not a directory']); exit; }
        $items = [];
        if ($path !== '/') $items[] = ['name' => '..', 'path' => dirname($path)];
        foreach (scandir($path) as $file) {
            if ($file === '.' || $file === '..') continue;
            if (is_dir("$path/$file")) $items[] = ['name' => $file, 'path' => "$path/$file"];
        }
        echo json_encode(['path' => $path, 'items' => $items]);
    } elseif ($_GET['action'] === 'create_dir') {
        // D-08: Frontend sends params via GET query string, not POST body
        $parent = $_GET['parent'] ?? '';
        $name = $_GET['name'] ?? '';
        if (is_dir("$parent/$name")) { echo json_encode(['status' => 'error', 'message' => 'Folder already exists']); exit; }
        if (mkdir("$parent/$name", 0777, true)) echo json_encode(['status' => 'ok']);
        else echo json_encode(['status' => 'error', 'message' => 'Failed to create folder']);
    } elseif ($_GET['action'] === 'get_session_status') {
        $path = $_GET['path'] ?? '';
        $chatId = findAICliChatSession($path, $id, $_GET['agentId'] ?? 'gemini-cli');
        $title = "";
        if ($chatId) {
            $home = getAICliConfig()['home_path'];
            $logFile = "$home/.gemini/tmp/$chatId/logs.json";
            if (file_exists($logFile)) {
                $logs = @json_decode(file_get_contents($logFile), true);
                if ($logs && count($logs) > 0) $title = end($logs)['title'] ?? '';
            }
        }
        echo json_encode(['status' => 'ok', 'chatId' => $chatId, 'title' => $title]);
    } elseif ($_GET['action'] === 'get_log') {
        // D-10: Read from RAM log path
        $logFile = "/tmp/unraid-aicliagents/debug.log";
        if (file_exists($logFile)) {
            $lines = aicli_tail($logFile, 50);
            echo implode("\n", $lines);
        } else {
            echo "Log is empty or does not exist.";
        }
    } elseif ($_GET['action'] === 'clear_log') {
        // D-10: Clear from RAM log path
        $logFile = "/tmp/unraid-aicliagents/debug.log";
        if (file_exists($logFile)) {
            @file_put_contents($logFile, "");
            echo json_encode(['status' => 'ok']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Log file does not exist']);
        }
    } elseif ($_GET['action'] === 'upload_chunk') {
        @set_time_limit(0); 
        $path = str_replace(array('\0', '..'), '', $_POST['path'] ?? '');
        if (empty($path) || !is_dir($path)) {
            aicli_debug("[FileUpload] Failed. Invalid path: $path");
            echo json_encode(['status' => 'error', 'error' => 'Invalid destination path']);
            exit;
        }

        $fileName = basename($_POST['filename'] ?? '');
        $fileData = $_POST['filedata'] ?? '';
        $chunkIndex = intval($_POST['chunk_index'] ?? 0);
        $totalChunks = intval($_POST['total_chunks'] ?? 1);

        if (empty($fileName) || empty($fileData)) {
            aicli_debug("[FileUpload] Failed. Missing filename or filedata payload.");
            echo json_encode(['status' => 'error', 'error' => 'File payload or filename missing']);
            exit;
        }

        $decodedData = base64_decode(str_replace(' ', '+', $fileData));
        if ($decodedData === false) {
            aicli_debug("[FileUpload] Failed to decode base64 chunk $chunkIndex");
            echo json_encode(['status' => 'error', 'error' => 'Failed to decode base64 payload']);
            exit;
        }

        // Write to destination directory with .partial suffix to bypass /tmp RAM limits
        $targetFile = rtrim($path, '/') . '/' . $fileName;
        $tmpFile = $targetFile . ".partial";
        
        $writeMode = ($chunkIndex === 0) ? 0 : FILE_APPEND;
        
        if (file_put_contents($tmpFile, $decodedData, $writeMode) === false) {
            aicli_debug("[FileUpload] Failed to write chunk $chunkIndex to $tmpFile");
            echo json_encode(['status' => 'error', 'error' => 'Failed to write chunk to disk']);
            exit;
        }

        if ($chunkIndex === $totalChunks - 1) {
            if (rename($tmpFile, $targetFile)) {
                aicli_debug("[FileUpload] Success: uploaded and assembled $fileName to $targetFile");
                aicli_notify("File Uploaded", "Successfully uploaded $fileName to " . basename($path));
                echo json_encode(['status' => 'ok', 'complete' => true]);
            } else {
                aicli_debug("[FileUpload] Failed to move assembled file to $targetFile");
                @unlink($tmpFile);
                echo json_encode(['status' => 'error', 'error' => 'Failed to finalize file']);
            }
        } else {
            echo json_encode(['status' => 'ok', 'chunk_received' => true]);
        }
    } elseif ($_GET['action'] === 'get_install_status') {
        $statusFile = "/tmp/aicli-install-status";
        if (file_exists($statusFile)) {
            echo file_get_contents($statusFile);
        } else {
            echo json_encode(['message' => '', 'progress' => 0]);
        }
    } elseif ($_GET['action'] === 'debug') {
        echo json_encode(['status' => 'ok', 'config' => getAICliConfig(), 'registry' => getAICliAgentsRegistry()]);
    }
    exit;
}
