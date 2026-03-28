<?php
/**
 * AICliAgents CLI Terminal Management
 */

// Force system/user timezone if available, otherwise fallback to UTC
if (file_exists('/var/local/emhttp/var.ini')) {
    $var = @parse_ini_file('/var/local/emhttp/var.ini');
    if (!empty($var['timeZone'])) {
        @date_default_timezone_set($var['timeZone']);
    } else {
        date_default_timezone_set('UTC');
    }
} else {
    date_default_timezone_set('UTC');
}

// Logging Levels
define('AICLI_LOG_ERROR', 0);
define('AICLI_LOG_WARN',  1);
define('AICLI_LOG_INFO',  2);
define('AICLI_LOG_DEBUG', 3);


/**
 * One-time initialization logic.
 * Called lazily when the plugin is actually used.
 */
function aicli_init_plugin() {
    if (file_exists('/tmp/unraid-aicliagents/init_done_v3')) return;

    if (!is_dir('/tmp/unraid-aicliagents')) @mkdir('/tmp/unraid-aicliagents', 0777, true);
    @chmod('/tmp/unraid-aicliagents', 0777);
    
    aicli_log("SCORCHED EARTH: Cleaning up all legacy ghost processes and sessions...", AICLI_LOG_WARN);
    
    // 1. Kill all detached background sync loops and old shell scripts
    exec("pkill -9 -f 'Periodic sync triggered' > /dev/null 2>&1");
    exec("pkill -9 -f 'sync-daemon-.*\.sh' > /dev/null 2>&1");
    exec("pkill -9 -f 'aicli-shell.sh' > /dev/null 2>&1");
    
    // 2. Kill all tmux sessions matching our pattern to clear ghosts
    exec("tmux ls -F '#S' 2>/dev/null | grep -E '^aicli-agent-' | xargs -I {} tmux kill-session -t {} > /dev/null 2>&1");
    
    // 4. Kill all standalone ttyd instances
    exec("pkill -9 -x ttyd > /dev/null 2>&1");
    
    // 5. Kill all known agent binaries (orphaned node processes)
    exec("pkill -9 -f 'node.*(gemini|opencode|nanocoder|claude|kilo|pi|codex|factory)' > /dev/null 2>&1");
    
    // 6. Clean up stale sockets and PIDs
    exec("rm -f /var/run/aicliterm-*.sock");
    exec("rm -f /var/run/unraid-aicliagents-*.pid");
    exec("rm -f /tmp/unraid-aicliagents/sync-daemon-*.pid");
    
    // Run migration and legacy cleanup
    aicli_cleanup_legacy();
    aicli_migrate_home_path();

    @touch('/tmp/unraid-aicliagents/init_done_v3');
}

/**
 * Lazy initializer to ensure constants and config are ready.
 */
function aicli_ensure_init() {
    aicli_init_plugin();
}

// Set up global error logging to debug file
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    // D-53: PHP 8.0+ compatibility for @ operator
    if (!(error_reporting() & $errno)) return false;
    aicli_log("PHP ERROR [$errno]: $errstr in $errfile on line $errline", AICLI_LOG_ERROR);
    return false;
});
set_exception_handler(function($e) {
    aicli_log("PHP EXCEPTION: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine(), AICLI_LOG_ERROR);
});

/**
 * Returns a hardcoded professional timestamp for unified logging.
 */
function aicli_get_formatted_timestamp() {
    return date("Y-m-d H:i:s");
}


// Logging Levels are defined at the top of the file

/**
 * Main logging engine for AICliAgents.
 */
function aicli_log($msg, $level = AICLI_LOG_INFO) {
    static $currentLevel = null;
    if ($currentLevel === null) {
        $configFile = "/boot/config/plugins/unraid-aicliagents/unraid-aicliagents.cfg";
        $cfg = file_exists($configFile) ? @parse_ini_file($configFile) : [];
        if (!isset($cfg['log_level']) && isset($cfg['debug_logging'])) {
            $currentLevel = ($cfg['debug_logging'] === '1') ? AICLI_LOG_DEBUG : AICLI_LOG_INFO;
        } else {
            $currentLevel = (int)($cfg['log_level'] ?? AICLI_LOG_INFO);
        }
    }

    if ($level > $currentLevel && $level > AICLI_LOG_WARN) return;

    $levelNames = [0 => 'ERROR', 1 => 'WARN', 2 => 'INFO', 3 => 'DEBUG'];
    $levelStr = $levelNames[$level] ?? 'UNKNOWN';

    $msgStr = is_string($msg) ? $msg : json_encode($msg);
    $logDir = "/tmp/unraid-aicliagents";
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0777, true);
        @chmod($logDir, 0777);
    }
    $logFile = "$logDir/debug.log";

    $timestamp = aicli_get_formatted_timestamp();
    $logLine = "[$timestamp] [$levelStr] $msgStr\n";
    @file_put_contents($logFile, $logLine, FILE_APPEND);
    
    // Prune log periodically (1% chance on log call to save IO)
    // Prune log periodically (1% chance on log call to save IO)
    if (rand(1, 100) === 1) {
        $maxMb = 50; 
        if (file_exists($logFile) && filesize($logFile) > $maxMb * 1024 * 1024) {
            $lines = [];
            exec("tail -n 5000 " . escapeshellarg($logFile) . " 2>&1", $lines);
            file_put_contents($logFile, "[LOG PRUNED - Size exceeded {$maxMb}MB]\n" . implode("\n", $lines));
        }
    }
    
    if (function_exists('posix_getuid') && @fileowner($logFile) === posix_getuid()) {
        @chmod($logFile, 0666);
    }
}

/**
 * Standalone Sync Daemon Manager
 * Handles the background schedule independently of terminal sessions.
 */
function aicli_manage_sync_daemon($username, $force = false) {
    // D-37: If we are in installer mode, don't start the daemon yet. 
    // It will be started lazily when the user first opens the plugin tab.
    if (getenv('AICLI_INSTALLER') === '1') {
        aicli_log("Installer mode detected. Skipping sync daemon start for $username.", AICLI_LOG_DEBUG);
        return;
    }

    $config = getAICliConfig();
    $syncMins = (int)($config['sync_interval_mins'] ?? 0) + ((int)($config['sync_interval_hours'] ?? 0) * 60);
    $lockFile = "/tmp/unraid-aicliagents/sync-daemon-$username.pid";
    
    // 1. Check if daemon is already running
    if (file_exists($lockFile)) {
        $pid = trim(file_get_contents($lockFile));
        if ($pid && aicli_is_pid_running($pid)) {
            // D-40: Only kill if we are forcing a restart (e.g. interval changed) or if sync is now disabled
            if ($syncMins <= 0 || $force) {
                aicli_log("Cleaning up existing sync daemon (PID $pid) for $username " . ($syncMins <= 0 ? "as sync is disabled." : "to apply new settings."), AICLI_LOG_INFO);
                exec("kill -9 $pid > /dev/null 2>&1");
                @unlink($lockFile);
                if ($syncMins <= 0) return;
            } else {
                // Daemon is running and we are not forcing a restart.
                return;
            }
        }
    }

    // 2. Start daemon if enabled
    if ($syncMins > 0) {
        aicli_log("Starting standalone sync daemon for $username (Interval: $syncMins min)", AICLI_LOG_INFO);
        $script = "/tmp/unraid-aicliagents/sync-daemon-$username.sh";
        // D-38: EXTREMELY IMPORTANT - We must close all inherited FDs (especially FD 3 used by Unraid installer)
        // to prevent the plugin manager from hanging.
        $cmd = "#!/bin/bash\n" .
               "exec 0<&- 1>&- 2>&- 3>&-\n" . 
               "echo \$\$ > " . escapeshellarg($lockFile) . "\n" .
               "while true; do\n" .
               "  sleep " . ($syncMins * 60) . "\n" .
               "  cd / && /usr/bin/php -r \"require_once '/usr/local/emhttp/plugins/unraid-aicliagents/includes/AICliAgentsManager.php'; aicli_log('Global periodic sync heartbeat triggered ($syncMins min)', 2); aicli_sync_home('$username', true);\"\n" .
               "done\n";
        file_put_contents($script, $cmd);
        chmod($script, 0755);
        // Use surgical bg exec to prevent installer hangs
        aicli_exec_bg("nohup $script > /dev/null 2>&1");
    }
}

// Legacy wrapper for compatibility
function aicli_debug($msg) {
    aicli_log($msg, AICLI_LOG_DEBUG);
}

function aicli_is_pid_running($pid) {
    if (empty($pid) || !is_numeric($pid)) return false;
    if (function_exists('posix_getpgid')) return @posix_getpgid($pid) !== false;
    exec("kill -0 " . escapeshellarg($pid) . " 2>/dev/null", $output, $result);
    return $result === 0;
}

function aicli_exec_bg($command) {
    aicli_log("Background Exec (Detached): $command", AICLI_LOG_DEBUG);
    // D-36: Standard Linux detachment pattern. 
    // Redirecting all output to /dev/null and using & ensures PHP does not wait.
    exec("nohup $command > /dev/null 2>&1 &");
}

function aicli_notify($subject, $message, $type = 'normal') {
    $command = "/usr/local/emhttp/webGui/scripts/notify -e \"AI CLI Agents Plugin\" -s " . escapeshellarg($subject) . " -d " . escapeshellarg($message) . " -i " . escapeshellarg($type);
    exec($command . " > /dev/null 2>&1");
}

/**
 * Hybrid Storage Helpers
 */
function aicli_get_work_dir($username) {
    return "/tmp/unraid-aicliagents/work/$username/home";
}

function aicli_get_persist_dir($username) {
    return "/boot/config/plugins/unraid-aicliagents/persistence/$username/home";
}

function aicli_init_working_dir($username, $forceRestore = false) {
    $workBase = "/tmp/unraid-aicliagents/work";
    if (!is_dir($workBase)) {
        if (!@mkdir($workBase, 0777, true)) {
            aicli_log("ERROR: Failed to create work base directory: $workBase", AICLI_LOG_ERROR);
        }
        @chmod($workBase, 0777);
    } else {
        @chmod($workBase, 0777);
    }
    
    $userDir = "$workBase/$username";
    if (!is_dir($userDir)) {
        if (!@mkdir($userDir, 0700, true)) {
            aicli_log("ERROR: Failed to create user directory: $userDir", AICLI_LOG_ERROR);
        }
    }
    
    // Always ensure the user owns their directory if we are root
    // D-25: This fixes Permission Denied if root created it but user needs to write logs
    if (trim((string)shell_exec('whoami')) === 'root') {
        exec("chown -R " . escapeshellarg($username) . ":users " . escapeshellarg($userDir), $out, $res);
        if ($res !== 0) aicli_log("ERROR: Failed to chown user directory $userDir to $username", AICLI_LOG_ERROR);
        chmod($userDir, 0700);
    }

    $ramDir = "$userDir/home";
    $flashDir = aicli_get_persist_dir($username);

    // D-50: If forceRestore is true, we re-sync from flash even if ramDir exists.
    if (!is_dir($ramDir) || $forceRestore) {
        if (!is_dir($ramDir)) {
            aicli_log("Initializing RAM home for $username: $ramDir", AICLI_LOG_INFO);
            if (!@mkdir($ramDir, 0700, true)) {
                aicli_log("ERROR: Failed to create RAM home directory: $ramDir", AICLI_LOG_ERROR);
            }
        } else {
            aicli_log("Forcing re-sync of RAM home for $username from Flash.", AICLI_LOG_INFO);
        }
        if (trim((string)shell_exec('whoami')) === 'root') {
             exec("chown -R " . escapeshellarg($username) . ":users " . escapeshellarg($ramDir));
        }

        // Restore from flash if it exists
        if (is_dir($flashDir)) {
            aicli_log("Restoring persistent data from Flash: $flashDir", AICLI_LOG_INFO);
            // rsync -a preserves permissions, so we follow up with chown
            // D-50: Added --delete to ensure stale files in RAM are removed during forceRestore
            // D-51: Added -L to copy real files instead of broken links from Flash
            $rsyncFlags = $forceRestore ? "-avcL --delete" : "-avcL";
            exec("rsync $rsyncFlags " . escapeshellarg($flashDir . "/") . " " . escapeshellarg($ramDir . "/"), $out, $res);
            if ($res !== 0) aicli_log("ERROR: Failed to restore persistent data from Flash ($flashDir) to RAM ($ramDir)", AICLI_LOG_ERROR);
            
            if (trim((string)shell_exec('whoami')) === 'root') {
                 exec("chown -R " . escapeshellarg($username) . ":users " . escapeshellarg($ramDir));
            }
        }
    } else {
        // Even if it exists, ensure ownership is correct (in case of manual user switch)
        if (trim((string)shell_exec('whoami')) === 'root') {
            exec("chown -R " . escapeshellarg($username) . ":users " . escapeshellarg($ramDir));
        }
    }
    return $ramDir;
}

function aicli_sync_home($username, $force = false) {
    $config = getAICliConfig();
    $activeUser = $config['user'] ?? 'root';
    
    // D-35: Security/Stability - Only allow sync for the user CURRENTLY selected in settings
    // This immediately kills any 'ghost' triggers from previous sessions/users
    if ($username !== $activeUser) {
        aicli_log("BLOCKING sync for $username: Not the active user ($activeUser).", AICLI_LOG_WARN);
        return false;
    }

    $ramDir = aicli_get_work_dir($username);
    $flashDir = aicli_get_persist_dir($username);
    
    // If not forced (manual/daemon), check for active sessions
    if (!$force) {
        $socks = glob("/var/run/aicliterm-*.sock");
        if (!empty($socks)) {
            aicli_log("Bypassing sync for $username: Active workspaces detected.", AICLI_LOG_DEBUG);
            return;
        }
    }
    
    if (!is_dir($ramDir)) {
        aicli_log("Bypassing sync for $username: RAM directory does not exist.", AICLI_LOG_DEBUG);
        return false;
    }
    
    if (!is_writable($ramDir)) {
        aicli_log("Bypassing sync for $username: RAM directory is not writable by current user (" . trim(shell_exec('whoami')) . ")", AICLI_LOG_WARN);
        return false;
    }

    $syncLock = "/tmp/unraid-aicliagents/sync-operation.lock";
    $fp = fopen($syncLock, "w+");
    if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
        aicli_log("Sync already in progress for $username. Skipping this cycle.", AICLI_LOG_INFO);
        if ($fp) fclose($fp);
        return false;
    }

    aicli_log("Syncing RAM home to Flash drive for $username (Force: " . ($force ? "Yes" : "No") . ")", AICLI_LOG_INFO);
    
    if (!is_dir($flashDir)) {
        @mkdir($flashDir, 0700, true);
    }
    
    // D-52/D-54: Performance - Exclude caches and use --size-only for Flash sync.
    // This is much faster on USB drives as it skips the latency of checking all timestamps.
    $excludeFlags = "--exclude='.npm' --exclude='.bun' --exclude='.cache' --exclude='node_modules' --exclude='*.log' --exclude='log/'";
    
    $cmd = "rsync -rLtD --delete --size-only $excludeFlags --no-p --no-g --no-o --modify-window=2 --itemize-changes " .
           escapeshellarg($ramDir . "/") . " " . escapeshellarg($flashDir . "/");
           
    exec("cd / && " . $cmd . " 2>&1", $out, $res);
    
    if ($res !== 0) {
        aicli_log("ERROR: Sync failed for $username (Code $res). Output: " . implode("\n", $out), AICLI_LOG_ERROR);
    } else {
        aicli_log("Sync complete for $username", AICLI_LOG_INFO);
    }
    
    flock($fp, LOCK_UN);
    fclose($fp);
    return ($res === 0);
}

function aicli_sync_all() {
    $workBase = "/tmp/unraid-aicliagents/work";
    if (!is_dir($workBase)) return;
    
    $users = array_diff(scandir($workBase), ['.', '..']);
    foreach ($users as $username) {
        aicli_sync_home($username, true);
    }
}


function setInstallStatus($message, $progress, $agentId = '') {
    $dir = "/tmp/unraid-aicliagents";
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    
    $status = ['message' => $message, 'progress' => $progress, 'agentId' => $agentId, 'timestamp' => time()];
    $file = empty($agentId) ? "$dir/install-status" : "$dir/install-status-$agentId";
    
    file_put_contents($file, json_encode($status));
}

function saveAICliConfig($newConfig) {
    $configFile = "/boot/config/plugins/unraid-aicliagents/unraid-aicliagents.cfg";
    $vaultFile = "/boot/config/plugins/unraid-aicliagents/secrets.cfg";
    $current = getAICliConfig();
    
    aicli_log("saveAICliConfig called", AICLI_LOG_INFO);

    // 1. Handle Vault (API Keys) - Preserve existing keys if not in POST
    $registry = getAICliAgentsRegistry();
    $vaultKeys = ['GEMINI_API_KEY', 'CLAUDE_API_KEY', 'AIDER_API_KEY', 'OPENAI_API_KEY'];
    foreach ($registry as $agent) {
        $prefix = $agent['env_prefix'] ?? '';
        if (!empty($prefix)) {
            $keyName = $prefix . "_API_KEY";
            if (!in_array($keyName, $vaultKeys)) $vaultKeys[] = $keyName;
        }
    }
    
    $existingVault = file_exists($vaultFile) ? @parse_ini_file($vaultFile) : [];
    $vaultIni = "";
    foreach ($vaultKeys as $vk) {
        $val = isset($newConfig[$vk]) ? trim($newConfig[$vk]) : ($existingVault[$vk] ?? '');
        // D-17: Escape single quotes in vault values to prevent INI corruption
        $escapedVal = addcslashes($val, "'");
        $vaultIni .= "$vk='$escapedVal'\n";
    }
    
    aicli_log("Updating secrets vault", AICLI_LOG_DEBUG);
    file_put_contents($vaultFile, $vaultIni);
    chmod($vaultFile, 0600); 

    // Capture old state for comparison
    $oldConfig = getAICliConfig();
    $oldSyncMins = (int)($oldConfig['sync_interval_mins'] ?? 0) + ((int)($oldConfig['sync_interval_hours'] ?? 0) * 60);

    // 2. Handle Main Config
    $allowed = ['enable_tab', 'theme', 'font_size', 'history', 'home_path', 'user', 'root_path', 'version', 'debug_logging', 'sync_interval_hours', 'sync_interval_mins', 'log_level'];
    foreach ($newConfig as $key => $val) {
        if (strpos($key, 'preview_') === 0) $allowed[] = $key;
    }
    
    foreach ($newConfig as $key => $value) {
        if (in_array($key, $allowed)) {
            $current[$key] = $value;
        }
    }

    // Capture new user for migration check
    $oldUser = $oldConfig['user'] ?? 'root';
    $newUser = $current['user'] ?? 'root';
    
    // D-41: Force home_path to the NEW user persistence folder if it's a standard path
    if ($oldUser !== $newUser && strpos($current['home_path'], "/boot/config/plugins/unraid-aicliagents/") === 0) {
        $current['home_path'] = "/boot/config/plugins/unraid-aicliagents/persistence/$newUser/home";
    }

    // 3. User Transition: Sync old user's RAM to Flash before we change anything
    if ($oldUser !== $newUser) {
        aicli_log("User changing from $oldUser to $newUser. Syncing old user's RAM to Flash first...", AICLI_LOG_INFO);
        aicli_sync_home($oldUser, true);
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
    
    aicli_log("Writing config to $configFile", AICLI_LOG_DEBUG);
    if (file_put_contents($configFile, $ini) !== false) {
        aicli_notify("Settings Saved", "Plugin configuration has been updated.");
        
        // Refresh the standalone sync daemon with the new settings (forced)
        aicli_manage_sync_daemon($newUser, true);

        // Ensure home directory is migrated if it was legacy or if the user changed
        aicli_migrate_home_path();

        // D-22: If user changed, ensure permissions on home path are updated AND move RAM dir
        if ($oldUser !== $newUser) {
            $oldRam = "/tmp/unraid-aicliagents/work/$oldUser";
            $newRam = "/tmp/unraid-aicliagents/work/$newUser";
            
            if (is_dir($oldRam)) {
                aicli_log("Moving RAM work directory from $oldRam to $newRam", AICLI_LOG_INFO);
                if (is_dir($newRam)) exec("rm -rf " . escapeshellarg($newRam));
                exec("mv " . escapeshellarg($oldRam) . " " . escapeshellarg($newRam));
            }
            
            // Re-fetch config to get the migrated home_path
            $finalConfig = getAICliConfig();
            $finalHome = $finalConfig['home_path'];
            
            aicli_log("Updating permissions for $newUser on $finalHome and $newRam", AICLI_LOG_INFO);
            exec("chown -R " . escapeshellarg($newUser) . ":users " . escapeshellarg($finalHome));
            if (is_dir($newRam)) exec("chown -R " . escapeshellarg($newUser) . ":users " . escapeshellarg($newRam));
        }
    } else {
        aicli_log("ERROR: Failed to write to config file $configFile", AICLI_LOG_ERROR);
    }
    
    updateAICliMenuVisibility($current['enable_tab']);
}

/**
 * Migration & Cleanup Helpers
 * These are called by the installer or on-demand, not during normal operation.
 */
function aicli_cleanup_legacy() {
    $legacyFiles = [
        '/boot/config/plugins/unraid-geminicli.plg',
        '/boot/config/plugins/geminicli.plg',
        '/var/log/plugins/unraid-geminicli.plg',
        '/var/log/plugins/geminicli.plg',
        '/usr/local/emhttp/plugins/unraid-geminicli',
        '/usr/local/emhttp/plugins/geminicli',
        '/usr/local/bin/gemini'
    ];
    foreach ($legacyFiles as $file) {
        if (file_exists($file)) {
            aicli_log("Cleanup: Removing legacy file $file", AICLI_LOG_WARN);
            is_dir($file) ? @exec("rm -rf " . escapeshellarg($file)) : @unlink($file);
        }
    }
}

function aicli_migrate_home_path() {
    $configFile = "/boot/config/plugins/unraid-aicliagents/unraid-aicliagents.cfg";
    if (!file_exists($configFile)) return;
    
    $config = @parse_ini_file($configFile);
    if (!$config) return;
    
    $oldHome = $config['home_path'] ?? '';
    $user = $config['user'] ?? 'root';
    $legacyDefault = "/boot/config/plugins/unraid-aicliagents/home";
    
    // New Standard: persistence dir for the user
    $newPersistBase = "/boot/config/plugins/unraid-aicliagents/persistence/$user/home";

    // Migration logic:
    // 1. If home_path is the old default or pointing to legacy plugin root (non-persistence)
    // 2. If home_path points to a differernt user's persistence folder
    $isLegacyPath = ($oldHome === $legacyDefault || (strpos($oldHome, "/boot/config/plugins/unraid-aicliagents/") === 0 && strpos($oldHome, "/persistence/") === false));
    $isWrongUserPersistence = (strpos($oldHome, "/boot/config/plugins/unraid-aicliagents/persistence/") === 0 && strpos($oldHome, "/persistence/$user/") === false);

    if ($isLegacyPath || $isWrongUserPersistence) {
        if (is_dir($oldHome) && $oldHome !== $newPersistBase) {
            aicli_log("MIGRATION: Moving home data from $oldHome to $newPersistBase", AICLI_LOG_INFO);
            if (!is_dir(dirname($newPersistBase))) @mkdir(dirname($newPersistBase), 0755, true);
            if (!is_dir($newPersistBase)) @mkdir($newPersistBase, 0700, true);
            
            // Move files, avoiding overwriting
            exec("rsync -a " . escapeshellarg($oldHome . "/") . " " . escapeshellarg($newPersistBase . "/"));
            
            // D-42: Remove the old folder to avoid migration loops in subsequent refreshes
            exec("rm -rf " . escapeshellarg($oldHome));
            
            // Update configuration directly to avoid recursion
            $config['home_path'] = $newPersistBase;
            $ini = "";
            foreach ($config as $k => $v) {
                $escapedV = addcslashes($v, '"\\');
                $ini .= "$k=\"$escapedV\"\n";
            }
            file_put_contents($configFile, $ini);
            aicli_log("MIGRATION: Configuration updated to $newPersistBase and old folder removed.", AICLI_LOG_INFO);
        } elseif (!is_dir($oldHome) || $oldHome !== $newPersistBase) {
            // If it doesn't exist or is invalid, just update the config directly
            $config['home_path'] = $newPersistBase;
            $ini = "";
            foreach ($config as $k => $v) {
                $escapedV = addcslashes($v, '"\\');
                $ini .= "$k=\"$escapedV\"\n";
            }
            file_put_contents($configFile, $ini);
            aicli_log("MIGRATION: Updated home_path reference to $newPersistBase", AICLI_LOG_INFO);
        }
    }
}


/**
 * Returns a list of valid Unraid users by parsing /etc/passwd.
 * Filters out system accounts and returns an array of [username => description].
 */
function getUnraidUsers() {
    $users = [];
    $data = @file_get_contents('/etc/passwd');
    if (!$data) return ['root' => 'Superuser'];

    $lines = explode("\n", trim($data));
    foreach ($lines as $line) {
        $parts = explode(':', $line);
        if (count($parts) < 5) continue;
        
        $user = $parts[0];
        $uid = (int)$parts[2];
        $desc = $parts[4];

        // Unraid user range: root (0) or standard users (usually > 1000 or as defined in Unraid config)
        // We also want to include 'root' as the default.
        if ($uid === 0 || ($uid >= 1000 && $uid < 65000)) {
            // Clean up description (Unraid often stores a full name or description here)
            $users[$user] = !empty($desc) ? $desc : ucfirst($user);
        }
    }
    ksort($users);
    return $users;
}

/**
 * Create a new Unraid user using the internal emcmd tool.
 * This ensures the user is synced to the flash drive and Samba.
 */
function createUnraidUser($username, $password, $description = '') {
    // Basic validation
    if (!preg_match('/^[a-z][-a-z0-9_]*$/', $username)) {
        return ['status' => 'error', 'message' => 'Invalid username format (lowercase, starts with letter).'];
    }

    aicli_log("Creating Unraid user: $username", AICLI_LOG_INFO);
    
    // Unraid cmdUserEdit requires password to be base64 encoded
    $encodedPassword = base64_encode($password);
    
    // Build query string matching Unraid's internal expectations
    $params = [
        'cmdUserEdit' => 'Add',
        'userName' => $username,
        'userPassword' => $encodedPassword,
        'userPasswordConf' => $encodedPassword,
        'userDesc' => $description
    ];
    
    $queryString = http_build_query($params, '', '&');
    
    $script = "/tmp/unraid-aicliagents/user-create-bg.php";
    
    // We use HEREDOC to avoid escaping hell. Variables starting with \$ will be literal in the script.
    $phpCode = <<<BG_PHP_SCRIPT
<?php
// Follow Unraid Timezone
\$var = @parse_ini_file('/var/local/emhttp/var.ini');
if (!empty(\$var['timeZone'])) @date_default_timezone_set(\$var['timeZone']);

function get_ts() {
    \$display = @parse_ini_file('/boot/config/plugins/dynamix/dynamix.cfg');
    \$legacy = ['%c' => 'D j M Y h:i A','%A' => 'l','%Y' => 'Y','%B' => 'F','%e' => 'j','%d' => 'd','%m' => 'm','%I' => 'h','%H' => 'H','%M' => 'i','%S' => 's','%p' => 'a','%R' => 'H:i', '%F' => 'Y-m-d', '%T' => 'H:i:s'];
    \$fmt = strtr(\$display['date'] ?? 'Y-m-d', \$legacy);
    if ((\$display['date'] ?? '') !== '%c' && !empty(\$display['time'] ?? '')) \$fmt .= ' ' . strtr(\$display['time'], \$legacy);
    return date(\$fmt);
}

try {
    \$payload = "$queryString";
    \$who = trim((string)shell_exec('whoami'));
    
    // Use Unraid's native emcmd utility which handles the socket and CSRF correctly.
    \$cmd = "/usr/local/emhttp/plugins/dynamix/scripts/emcmd " . escapeshellarg(\$payload);
    \$reply = shell_exec("\$cmd 2>&1");
    
    \$now = get_ts();
    \$log = "[\$now] [UserCreate] Running as: \$who\\n";
    \$log .= "[\$now] [UserCreate] Payload: \$payload\\n";
    \$log .= "[\$now] [UserCreate] Command: \$cmd\\n";
    \$log .= "[\$now] [UserCreate] Reply Start---\\n\$reply\\n---Reply End\\n";
    
    if (empty(trim((string)\$reply))) {
        \$log .= "[\$now] [UserCreate] Note: Empty reply is common if successful. Checking emhttpd state...\\n";
        \$log .= "[\$now] [UserCreate] emhttpd state: " . trim((string)shell_exec('ps aux | grep emhttpd | grep -v grep')) . "\\n";
    }
    
    file_put_contents('/tmp/unraid-aicliagents/debug.log', \$log, FILE_APPEND);
} catch (Throwable \$e) {
    \$now = get_ts();
    file_put_contents('/tmp/unraid-aicliagents/debug.log', "[\$now] [UserCreate] PHP Crash: " . \$e->getMessage() . "\\n", FILE_APPEND);
}
?>
BG_PHP_SCRIPT;
    
    if (!is_dir('/tmp/unraid-aicliagents')) @mkdir('/tmp/unraid-aicliagents', 0777, true);
    file_put_contents($script, $phpCode);
    
    aicli_log("Triggering Unraid user creation via background script: $username", AICLI_LOG_INFO);
    aicli_exec_bg("/usr/bin/php -q $script");

    return ['status' => 'ok'];
}

function getAICliConfig() {
    $configFile = "/boot/config/plugins/unraid-aicliagents/unraid-aicliagents.cfg";
    $defaults = [
        'enable_tab' => '1',
        'theme' => 'dark',
        'font_size' => '14',
        'history' => '1000',
        'home_path' => '/boot/config/plugins/unraid-aicliagents/persistence/root/home',
        'user' => 'root',
        'root_path' => '/mnt/user',
        'version' => 'unknown',
        'debug_logging' => '0',
        'log_level' => '2',
        'sync_interval_mins' => '0',
        'sync_interval_hours' => '0'
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

function aicli_pre_flight_check($agentId, $homePath, $workingDir) {
    if ($agentId !== 'opencode' && $agentId !== 'nanocoder' && $agentId !== 'claude-code') return;

    $dbFiles = [];
    
    // 1. Check global agent data dir
    if ($agentId === 'opencode') {
        $dbFiles[] = "$homePath/.local/share/opencode/opencode.db";
        // Also check if workspace has a local storage DB
        if (!empty($workingDir) && is_dir("$workingDir/.opencode/storage")) {
            $dbFiles[] = "$workingDir/.opencode/storage/opencode.db";
        }
    } elseif ($agentId === 'claude-code') {
         $dbFiles[] = "$homePath/.local/share/claude-code/claude-code.db";
    }

    foreach ($dbFiles as $db) {
        if (file_exists($db)) {
            aicli_log("[Pre-Flight] Checking SQLite DB integrity: $db", AICLI_LOG_INFO);
            
            // D-49: Perform integrity check before checkpointing. 
            // If the DB is malformed, it must be cleared to allow the agent to start.
            $checkCmd = "sqlite3 " . escapeshellarg($db) . " 'PRAGMA integrity_check;' 2>&1";
            exec($checkCmd, $checkOut, $checkRes);
            $checkMsg = implode("\n", $checkOut);

            if ($checkRes !== 0 || strpos(strtolower($checkMsg), 'ok') === false) {
                aicli_log("[Pre-Flight] ERROR: DB Integrity check FAILED for $db: $checkMsg", AICLI_LOG_ERROR);
                $corruptPath = $db . ".corrupt." . date("Ymd_His");
                aicli_log("[Pre-Flight] Moving malformed DB to $corruptPath", AICLI_LOG_WARN);
                @rename($db, $corruptPath);
                // Also remove any WAL/SHM files to ensure a clean start
                if (file_exists($db . "-wal")) @unlink($db . "-wal");
                if (file_exists($db . "-shm")) @unlink($db . "-shm");
                continue; // Skip checkpointing for the now-removed DB
            }

            aicli_log("[Pre-Flight] Repairing/Checkpointing SQLite DB: $db", AICLI_LOG_INFO);
            
            // D-46: Forced Checkpoint (TRUNCATE) merges WAL into main DB and resets journal.
            // This fixes consistency issues caused by missing WAL files after RAM/Flash syncs.
            $cmd = "sqlite3 " . escapeshellarg($db) . " 'PRAGMA wal_checkpoint(TRUNCATE);' 2>&1";
            exec($cmd, $out, $res);
            
            if ($res !== 0) {
                aicli_log("[Pre-Flight] WARN: DB Checkpoint failed for $db (Code $res): " . implode("\n", $out), AICLI_LOG_WARN);
            } else {
                aicli_log("[Pre-Flight] DB Checkpoint successful for $db", AICLI_LOG_DEBUG);
            }
        }
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
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
    $sock = getAICliSock($id);
    if (!file_exists($sock)) return false;

    // Faster check: just see if ttyd is running with this socket in its cmdline
    // D-19: Use direct pgrep -f for speed. Much lighter than the ps | grep pipeline.
    $escapedSock = escapeshellarg($sock);
    $pids = [];
    exec("pgrep -f \"ttyd.*$escapedSock\" 2>/dev/null", $pids);
    
    if (empty($pids)) return false;

    // Verify Chat ID if requested (cheap file read)
    if ($chatId !== null) {
        $chatIdFile = getAICliChatIdFile($id);
        if (!file_exists($chatIdFile)) return false;
        $runningChatId = trim(file_get_contents($chatIdFile));
        if ($chatId !== $runningChatId) return false;
    }

    return true;
}

function stopAICliTerminal($id = 'default', $killTmux = false) {
    // Decrement session count for the user
    $config = getAICliConfig();
    $username = $config['user'];
    $countFile = "/var/run/aicli-sessions/$username.count";
    if (file_exists($countFile)) {
        $count = (int)file_get_contents($countFile);
        if ($count > 0) file_put_contents($countFile, $count - 1);
        
        // D-35: Decoupled schedule - No longer triggering sync on last session close.
        // Sync is now managed entirely by the standalone daemon and manual triggers.
        /*
        if ($count <= 1) {
            aicli_sync_home($username);
        }
        */
    }

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
        if (!empty($pid) && ctype_digit($pid)) {
            // D-21: Graceful SIGTERM (15) first, then SIGKILL (9) fallback to allow agents to save state
            exec("kill -15 " . escapeshellarg($pid) . " > /dev/null 2>&1; sleep 0.2; kill -9 " . escapeshellarg($pid) . " > /dev/null 2>&1");
        }
    }
    
    // Aggressive Socket Cleanup: Sometimes ttyd exits but the socket remains, blocking new instances
    if (file_exists($sock)) {
        aicli_log("Aggressive Socket Cleanup: Removing stale socket $sock", AICLI_LOG_WARN);
        @unlink($sock);
    }
    // D-03: Initialize $nodePids before exec() to prevent undefined variable
    $nodePids = [];
    $escapedId = escapeshellarg("AICLI_SESSION_ID=$id");
    exec("pgrep -f $escapedId 2>/dev/null", $nodePids);
    foreach ($nodePids as $np) {
        $np = trim($np);
        if (!empty($np) && ctype_digit($np)) {
            // D-21: Graceful SIGTERM (15) first, then SIGKILL (9) fallback to allow agents to save state
            exec("kill -15 " . escapeshellarg($np) . " > /dev/null 2>&1; sleep 0.2; kill -9 " . escapeshellarg($np) . " > /dev/null 2>&1");
        }
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
        'gemini-cli' => '@google/gemini-cli',
        'opencode' => 'opencode-ai',
        'claude-code' => '@anthropic-ai/claude-code',
        'kilocode' => '@kilocode/cli',
        'pi-coder' => '@mariozechner/pi-coding-agent',
        'codex-cli' => '@openai/codex',
        'factory-cli' => '@factory/cli',
        'nanocoder' => '@nanocollective/nanocoder'
    ];
}

function getAICliAgentsRegistry() {
    $manifestFile = "/boot/config/plugins/unraid-aicliagents/agents.json";
    $agentBase = "/usr/local/emhttp/plugins/unraid-aicliagents/agents";
    
    $defaultRegistry = [
        'gemini-cli' => [
            'id' => 'gemini-cli',
            'name' => 'Gemini CLI',
            'icon_url' => '/plugins/unraid-aicliagents/assets/icons/google-gemini.png?v=' . ($config['version'] ?? 'stable'),
            'release_notes' => 'https://www.npmjs.com/package/@google/gemini-cli?activeTab=versions',
            'runtime' => 'node',
            'binary' => "$agentBase/gemini-cli/node_modules/.bin/gemini",
            'binary_fallback' => "$agentBase/gemini-cli/node_modules/@google/gemini-cli/bin/aicli.js",
            'resume_cmd' => "$agentBase/gemini-cli/node_modules/.bin/gemini --resume {chatId}",
            'resume_latest' => "$agentBase/gemini-cli/node_modules/.bin/gemini --resume",
            'env_prefix' => 'GEMINI',
            'is_installed' => file_exists("$agentBase/gemini-cli/node_modules/.bin/gemini") || file_exists("$agentBase/gemini-cli/node_modules/@google/gemini-cli/bin/aicli.js")
        ],
        'claude-code' => [
            'id' => 'claude-code',
            'name' => 'Claude Code',
            'icon_url' => '/plugins/unraid-aicliagents/assets/icons/claude.ico',
            'release_notes' => 'https://www.npmjs.com/package/@anthropic-ai/claude-code?activeTab=versions',
            'runtime' => 'node',
            'binary' => "$agentBase/claude-code/node_modules/.bin/claude",
            'resume_cmd' => "$agentBase/claude-code/node_modules/.bin/claude --resume {chatId}",
            'resume_latest' => "$agentBase/claude-code/node_modules/.bin/claude --continue",
            'env_prefix' => 'CLAUDE',
            'is_installed' => file_exists("$agentBase/claude-code/node_modules/.bin/claude")
        ],
        'opencode' => [
            'id' => 'opencode',
            'name' => 'OpenCode',
            'icon_url' => '/plugins/unraid-aicliagents/assets/icons/opencode.ico',
            'release_notes' => 'https://github.com/anomalyco/opencode/releases',
            'runtime' => 'node',
            'binary' => "$agentBase/opencode/node_modules/.bin/opencode",
            'resume_cmd' => "$agentBase/opencode/node_modules/.bin/opencode --session {chatId}",
            'resume_latest' => "$agentBase/opencode/node_modules/.bin/opencode --continue",
            'env_prefix' => 'OPENCODE',
            'is_installed' => file_exists("$agentBase/opencode/node_modules/.bin/opencode")
        ],
        'kilocode' => [
            'id' => 'kilocode',
            'name' => 'Kilo Code',
            'icon_url' => '/plugins/unraid-aicliagents/assets/icons/kilocode.ico',
            'release_notes' => 'https://github.com/Kilo-Org/kilocode/releases',
            'runtime' => 'node',
            'binary' => "$agentBase/kilocode/node_modules/.bin/kilo",
            'resume_cmd' => "$agentBase/kilocode/node_modules/.bin/kilo --session {chatId}",
            'resume_latest' => "$agentBase/kilocode/node_modules/.bin/kilo --continue",
            'env_prefix' => 'KILOCODE',
            'is_installed' => file_exists("$agentBase/kilocode/node_modules/.bin/kilo")
        ],
        'pi-coder' => [
            'id' => 'pi-coder',
            'name' => 'Pi Coder',
            'icon_url' => '/plugins/unraid-aicliagents/assets/icons/picoder.png',
            'release_notes' => 'https://github.com/badlogic/pi-mono/releases',
            'runtime' => 'node',
            'binary' => "$agentBase/pi-coder/node_modules/.bin/pi",
            'resume_cmd' => "$agentBase/pi-coder/node_modules/.bin/pi",
            'resume_latest' => "$agentBase/pi-coder/node_modules/.bin/pi",
            'env_prefix' => 'PI_CODER',
            'is_installed' => file_exists("$agentBase/pi-coder/node_modules/.bin/pi")
        ],
        'codex-cli' => [
            'id' => 'codex-cli',
            'name' => 'Codex CLI',
            'icon_url' => '/plugins/unraid-aicliagents/assets/icons/codex.png',
            'release_notes' => 'https://www.npmjs.com/package/@openai/codex?activeTab=versions',
            'runtime' => 'node',
            'binary' => "$agentBase/codex-cli/node_modules/.bin/codex",
            'resume_cmd' => "$agentBase/codex-cli/node_modules/.bin/codex",
            'resume_latest' => "$agentBase/codex-cli/node_modules/.bin/codex",
            'env_prefix' => 'CODEX',
            'is_installed' => file_exists("$agentBase/codex-cli/node_modules/.bin/codex")
        ],
        'factory-cli' => [
            'id' => 'factory-cli',
            'name' => 'Factory CLI',
            'icon_url' => '/plugins/unraid-aicliagents/assets/icons/factory.png',
            'release_notes' => 'https://www.npmjs.com/package/@factory/cli?activeTab=versions',
            'runtime' => 'node',
            'binary' => "$agentBase/factory-cli/node_modules/.bin/droid",
            'resume_cmd' => "$agentBase/factory-cli/node_modules/.bin/droid",
            'resume_latest' => "$agentBase/factory-cli/node_modules/.bin/droid",
            'env_prefix' => 'FACTORY',
            'is_installed' => file_exists("$agentBase/factory-cli/node_modules/.bin/droid")
        ],
        'nanocoder' => [
            'id' => 'nanocoder',
            'name' => 'NanoCoder',
            'icon_url' => '/plugins/unraid-aicliagents/assets/icons/nanocoder.png',
            'release_notes' => 'https://www.npmjs.com/package/@nanocollective/nanocoder?activeTab=versions',
            'runtime' => 'node',
            'binary' => "$agentBase/nanocoder/node_modules/.bin/nanocoder",
            'resume_cmd' => "$agentBase/nanocoder/node_modules/.bin/nanocoder",
            'resume_latest' => "$agentBase/nanocoder/node_modules/.bin/nanocoder",
            'env_prefix' => 'NANO',
            'is_installed' => file_exists("$agentBase/nanocoder/node_modules/.bin/nanocoder")
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

function getWorkspaceEnvs($path, $agentId) {
    if (empty($path)) return [];
    
    // 1. Load custom workspace overrides from persistence
    $file = "/boot/config/plugins/unraid-aicliagents/workspace_envs.json";
    $data = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
    $key = $path . ":" . $agentId;
    $envs = $data[$key] ?? [];
    
    // 2. Inject global API key from Secrets Vault if not already overridden
    $registry = getAICliAgentsRegistry();
    $prefix = $registry[$agentId]['env_prefix'] ?? '';
    if (!empty($prefix)) {
        $apiKeyVar = $prefix . "_API_KEY";
        // If not in local overrides, fetch from global vault
        if (!isset($envs[$apiKeyVar])) {
            $vaultFile = "/boot/config/plugins/unraid-aicliagents/secrets.cfg";
            $vault = file_exists($vaultFile) ? @parse_ini_file($vaultFile) : [];
            // Special cases for agents that might use multiple providers
            $checkVars = [$apiKeyVar];
            if ($agentId === 'opencode' || $agentId === 'codex-cli' || $agentId === 'kilocode') {
                $checkVars[] = 'OPENAI_API_KEY';
                $checkVars[] = 'GEMINI_API_KEY';
            }
            
            foreach ($checkVars as $v) {
                if (!empty($vault[$v])) {
                    $envs[$v] = $vault[$v];
                    // We only inject the first one we find that matches the prefix or standard keys
                    break;
                }
            }
        }
    }
    
    return $envs;
}

function saveWorkspaceEnvs($path, $agentId, $envs) {
    if (empty($path)) return;
    $file = "/boot/config/plugins/unraid-aicliagents/workspace_envs.json";
    $data = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
    $key = $path . ":" . $agentId;
    
    if (empty($envs)) {
        unset($data[$key]);
    } else {
        $filtered = [];
        foreach ($envs as $k => $v) {
            $k = preg_replace('/[^a-zA-Z0-9_-]/', '', $k);
            if (!empty($k)) $filtered[$k] = $v;
        }
        $data[$key] = $filtered;
    }
    
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
}

function startAICliTerminal($id = 'default', $workingDir = null, $chatSessionId = null, $agentId = 'gemini-cli') {
    // D-01/D-02: Sanitize inputs to prevent command injection
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
    $agentId = preg_replace('/[^a-zA-Z0-9_-]/', '', $agentId);
    if ($chatSessionId !== null) $chatSessionId = preg_replace('/[^a-zA-Z0-9_-]/', '', $chatSessionId);

    aicli_log("startAICliTerminal called: ID=$id, Agent=$agentId, Path=$workingDir", AICLI_LOG_INFO);
    $sock = getAICliSock($id);
    $shell = "/usr/local/emhttp/plugins/unraid-aicliagents/scripts/aicli-shell.sh";
    $logDir = "/tmp/unraid-aicliagents";
    if (!is_dir($logDir)) @mkdir($logDir, 0777, true);
    $log = "$logDir/ttyd-aicli-$id.log";
    $pidFile = getAICliPidFile($id);
    $lockFile = getAICliLockFile($id);
    $chatIdFile = getAICliChatIdFile($id);
    $agentIdFile = getAICliAgentIdFile($id);

    // D-04: Define $binDir (was undefined in this scope)
    $binDir = "/usr/local/emhttp/plugins/unraid-aicliagents/bin"; // This is the old binDir, but still used for some legacy checks.
    $agentBase = "/usr/local/emhttp/plugins/unraid-aicliagents/agents"; // New base for agent installations

    $registry = getAICliAgentsRegistry();
    $agent = $registry[$agentId] ?? $registry['gemini-cli'];

    if (!$agent['is_installed']) {
        aicli_log("ERROR: Agent $agentId is not installed.", AICLI_LOG_ERROR);
        return;
    }

    $config = getAICliConfig();
    $workingDir = $workingDir ?: $config['root_path'];

    // Ensure Home directory exists (Hybrid RAM storage)
    $username = $config['user'];
    $homePath = aicli_init_working_dir($username);
    
    // D-45: SQLite Pre-flight Check: Ensure agent databases are consistent after RAM restoration
    aicli_pre_flight_check($agentId, $homePath, $workingDir);

    // Ensure standalone sync daemon is running for this user
    aicli_manage_sync_daemon($username);

    // Track session count for concurrency/sync safety (Unified with shell ref)
    $refFile = "/tmp/unraid-aicliagents/sync-$username.ref";
    if (!is_dir(dirname($refFile))) @mkdir(dirname($refFile), 0777, true);
    $count = file_exists($refFile) ? (int)file_get_contents($refFile) : 0;
    // Note: shell script increments this, but we initialize it if missing
    if (!file_exists($refFile)) file_put_contents($refFile, "0");

    // Ensure binary is in RAM (Restore from USB cache if missing)
    // The agent['binary'] path now points to the isolated agent directory
    $binExists = file_exists($agent['binary']);
    
    if (!$binExists) {
        aicli_log("Agent $agentId missing from RAM, attempting restore...", AICLI_LOG_WARN);
        $cacheFile = "/boot/config/plugins/unraid-aicliagents/pkg-cache/$agentId.tar.gz";
        $agentInstallDir = "$agentBase/$agentId"; // The target directory for this agent
        if (file_exists($cacheFile)) {
            aicli_log("Found cached agent: $cacheFile. Restoring to RAM...", AICLI_LOG_INFO);
            // D-20: Use --no-same-owner for permission robustness on Unraid filesystems
            // Ensure the target directory exists before extracting
            if (!is_dir($agentInstallDir)) @mkdir($agentInstallDir, 0777, true);
            exec("tar -xzf " . escapeshellarg($cacheFile) . " --no-same-owner -C " . escapeshellarg($agentInstallDir) . "/");
            // D-47: Forced recursive permissions after restoration to ensure binaries are executable
            exec("chmod -R 755 " . escapeshellarg($agentInstallDir));
            aicli_log("Agent $agentId restored and permissions updated.", AICLI_LOG_INFO);
        }
        // Legacy/Optimized single-file fallback for Gemini is no longer needed with isolated directories
    }

    // D-48: ABSOLUTELY MANDATORY: Always ensure the ENTIRE agent directory is executable.
    // This is required because agents like OpenCode spawn internal sub-processes (e.g., .opencode) 
    // that are not explicitly listed in our registry. Recursive chmod in RAM is fast and fixes EACCES.
    $agentInstallDir = "$agentBase/$agentId";
    if (is_dir($agentInstallDir)) {
        exec("chmod -R 755 " . escapeshellarg($agentInstallDir) . " > /dev/null 2>&1");
    }

    // D-05: Removed duplicate getAICliConfig() call
    
    $fp = fopen($lockFile, "w+");
    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        fclose($fp);
        return; 
    }

    // D-33: Enhanced Heartbeat Check (Unified Lock File)
    $syncMins = (int)($config['sync_interval_mins'] ?? 0) + ((int)($config['sync_interval_hours'] ?? 0) * 60);
    $heartbeatRunning = true;
    if ($syncMins > 0) {
        $hbLock = "/tmp/unraid-aicliagents/sync-daemon-$username.pid";
        if (!file_exists($hbLock)) {
            $heartbeatRunning = false;
        } else {
            $hbPid = trim(file_get_contents($hbLock));
            if (!$hbPid || !aicli_is_pid_running($hbPid)) $heartbeatRunning = false;
        }
    }

    if (isAICliRunning($id, $chatSessionId, $agentId) && file_exists($sock)) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return;
    }

    // D-35: Even if heartbeat is missing, we don't forcefully restart terminal sessions here.
    // The daemon will be restarted lazily via aicli_manage_sync_daemon($username) above if needed.
    // However, if the sesson exists but the AGENT has changed, we MUST kill and restart.
    $runningAgentId = file_exists($agentIdFile) ? trim(file_get_contents($agentIdFile)) : '';
    if ($runningAgentId !== '' && $runningAgentId !== $agentId) {
        aicli_log("Agent ID changed ($runningAgentId -> $agentId). Forcing session restart.", AICLI_LOG_INFO);
        stopAICliTerminal($id, true);
    }

    if (file_exists($shell)) chmod($shell, 0755);

    // Save state before starting
    if ($chatSessionId !== null) file_put_contents($chatIdFile, $chatSessionId);
    file_put_contents($agentIdFile, $agentId);

    // D-02: Escape all env values to prevent shell injection
    // D-25: Export the RAM working dir as AICLI_HOME to ensure write access and performance
    $safeHome = escapeshellarg($homePath);
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
    $logLevel = (isset($config['log_level'])) ? (string)$config['log_level'] : (($config['debug_logging'] ?? '0') === '1' ? '3' : '2');
    $safeDebug = escapeshellarg($logLevel);
    $syncMins = (int)($config['sync_interval_mins'] ?? 0) + ((int)($config['sync_interval_hours'] ?? 0) * 60);
    $safeSync = escapeshellarg((string)$syncMins);

    $env = "export AICLI_HOME=$safeHome; " .
           "export AICLI_USER=$safeUser; " .
           "export AICLI_ROOT=$safeRoot; " .
           "export AICLI_HISTORY=$safeHistory; " .
           "export AICLI_SESSION_ID=$safeId; " .
           "export AICLI_DEBUG=$safeDebug; " .
           "export AICLI_SYNC_MINS=$safeSync; " .
           "export AGENT_ID=$safeAgentId; " .
           "export AGENT_NAME=$safeAgentName; " .
           "export ENV_PREFIX=$safeEnvPrefix; " .
           "export BINARY=$safeBinary; " .
           "export RESUME_CMD=$safeResumeCmd; " .
           "export RESUME_LATEST=$safeResumeLatest; " .
           "export COLORTERM=truecolor; " .
           "export OPENCODE_EXPERIMENTAL_DISABLE_COPY_ON_SELECT=true; ";
           
    if (!empty($chatSessionId)) {
        $safeChatId = escapeshellarg($chatSessionId);
        $env .= "export AICLI_CHAT_SESSION_ID=$safeChatId; ";
    }

    // Load and export User-defined Workspace Environment Variables
    $customEnvs = getWorkspaceEnvs($workingDir, $agentId);
    foreach ($customEnvs as $k => $v) {
        $env .= "export " . escapeshellarg($k) . "=" . escapeshellarg($v) . "; ";
    }

    $themeStr = getAICliTtydTheme($config['theme'] ?? 'dark');
    
    $cmd = "ttyd -i $safeSock -W -d0 " .
           "-t fontSize=$safeFontSize " .
           "-t fontFamily='monospace' " .
           "-t theme='$themeStr' " .
           "-t termName=xterm-256color " .
           "-t copyOnSelection=true " .
           "-t disableLeaveAlert=true " .
           "-t enable-utf8=true " .
           "-t allowProposedApi=true " .
           "-t terminalType=xterm-256color " .
           "-t 'terminalOverrides=xterm-256color:Ms=\\E]52;c;%p2%s\\7' " .
           "-t titleFixed=" . escapeshellarg($agent['name'] . " - $id") . " " .
           "runuser -u " . $safeUser . " -- /bin/bash -c " . escapeshellarg("$env $shell");
    
    exec("nohup $cmd >> " . escapeshellarg($log) . " 2>&1 & echo $!", $output);
    @chmod($log, 0666);
    $pid = trim($output[0] ?? '');
    if ($pid && ctype_digit($pid)) {
        file_put_contents($pidFile, $pid);
        // D-30: Give ttyd a moment to bind the Unix socket before returning to frontend.
        // This prevents the Nginx proxy from returning 502 Bad Gateway on first load.
        usleep(800000); 
    }
    
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

function gcPkgCache() {
    $cacheDir = "/boot/config/plugins/unraid-aicliagents/pkg-cache";
    if (!is_dir($cacheDir)) return;
    
    $registry = getAICliAgentsRegistry();
    $allowed = array_keys($registry);
    
    $files = glob("$cacheDir/*.tar.gz");
    foreach ($files as $file) {
        $name = basename($file, ".tar.gz");
        if (!in_array($name, $allowed)) {
            aicli_log("GC: Removing orphaned cache file: $file", AICLI_LOG_WARN);
            unlink($file);
        }
    }
}

function installAgent($agentId) {
    if (empty($agentId)) return ['status' => 'error', 'message' => 'No Agent ID'];

    $lockFile = "/tmp/unraid-aicliagents/install-$agentId.lock";
    if (file_exists($lockFile)) {
        $pid = trim(@file_get_contents($lockFile));
        if (aicli_is_pid_running($pid)) {
             return ['status' => 'error', 'message' => "Installation for $agentId is already in progress (PID $pid)."];
        }
    }
    file_put_contents($lockFile, getmypid());

    aicli_log("installAgent started for $agentId", AICLI_LOG_INFO);
    try {
        setInstallStatus("Initializing...", 10, $agentId);
    $registry = getAICliAgentsRegistry();
    if (!isset($registry[$agentId])) {
        aicli_log("ERROR: Agent $agentId not found in registry", AICLI_LOG_ERROR);
        return ['status' => 'error', 'error' => 'Agent not found in registry'];
    }
    
    $config = getAICliConfig();
    $usePreview = ($config["preview_$agentId"] ?? "0") === "1";
    aicli_log("Using preview channel: " . ($usePreview ? "yes" : "no"), AICLI_LOG_DEBUG);
    
    $bootConfig = "/boot/config/plugins/unraid-aicliagents";
    $cacheDir = "$bootConfig/pkg-cache";
    $agentBase = "/usr/local/emhttp/plugins/unraid-aicliagents/agents"; // New base for agent installations
    
    if (!is_dir($cacheDir)) mkdir($cacheDir, 0777, true);
    if (!is_dir($agentBase)) mkdir($agentBase, 0777, true); // Ensure agent base directory exists

    // 1. Prepare temporary RAM directory for installation/staging
    setInstallStatus("Preparing temporary RAM area...", 20, $agentId);
    $tmpDir = "/tmp/aicli-install-$agentId";
    if (is_dir($tmpDir)) exec("rm -rf $tmpDir");
    mkdir($tmpDir, 0777, true);

    $installedVer = "installed";

    // NPM-based agents — D-19: Use shared mapping function
    $npmMap = getAICliNpmMap();

        $package = $npmMap[$agentId] ?? null;
        if (!$package) {
            aicli_log("ERROR: No NPM package mapping for $agentId", AICLI_LOG_ERROR);
            return ['status' => 'error', 'error' => 'NPM package mapping missing'];
        }

        $installPackage = $package;
        if ($usePreview) {
            // D-21: Generic tag discovery for preview releases
            $tagsOutput = [];
            exec("npm info " . escapeshellarg($package) . " dist-tags --json 2>/dev/null", $tagsOutput);
            $tags = json_decode(implode("\n", $tagsOutput), true);
            
            if (isset($tags['preview'])) {
                $installPackage .= "@preview";
            } elseif (isset($tags['next'])) {
                $installPackage .= "@next";
            } elseif (isset($tags['nightly'])) {
                $installPackage .= "@nightly";
            } else {
                $installPackage .= "@latest";
            }
        } else {
            $installPackage .= "@latest";
        }

        // D-27: Clean up previous attempts to avoid 'File exists' errors
        if (is_dir($tmpDir)) exec("rm -rf " . escapeshellarg($tmpDir));
        mkdir($tmpDir, 0777, true);
        
        // D-24: Avoid manual package.json creation; let NPM handle it unless strictly needed.
        // This resolves conflicts with scoped package linkage.

        setInstallStatus("Downloading & Installing dependencies...", 50, $agentId);
        $pluginDir = "/usr/local/emhttp/plugins/unraid-aicliagents";
        aicli_log("Running: $pluginDir/bin/npm install --prefix " . escapeshellarg($tmpDir) . " " . escapeshellarg($installPackage), AICLI_LOG_DEBUG);
        $npmOutput = [];
        // D-22: Execute npm install with the explicit package name as the primary target
        exec("export PATH=$pluginDir/bin:\$PATH; cd " . escapeshellarg($tmpDir) . " && $pluginDir/bin/npm install --prefix " . escapeshellarg($tmpDir) . " " . escapeshellarg($installPackage) . " 2>&1", $npmOutput, $result);
        aicli_log("NPM finished with code $result. Output snapshot:\n" . implode("\n", array_slice($npmOutput, 0, 50)), AICLI_LOG_DEBUG);
        aicli_log("NPM finished with code $result. Output snapshot:\n" . implode("\n", array_slice($npmOutput, 0, 50)), AICLI_LOG_DEBUG);
        
        if ($result !== 0) {
            aicli_log("ERROR: NPM install failed for $installPackage", AICLI_LOG_ERROR);
            return ['status' => 'error', 'error' => 'NPM install failed: ' . end($npmOutput)];
        }

        // Get installed version from package.json in the tmp dir
        $pJson = "$tmpDir/node_modules/" . str_replace('/', DIRECTORY_SEPARATOR, $package) . "/package.json";
        if (file_exists($pJson)) {
            $pData = json_decode(file_get_contents($pJson), true);
            $installedVer = $pData['version'] ?? $installedVer;
            aicli_log("Detected version from package.json: $installedVer", AICLI_LOG_DEBUG);
        }

    // 2. UNIFIED CACHING: Tarball the resulting installation to USB
    setInstallStatus("Backing up install for reboot support...", 70, $agentId);
    aicli_log("Taring $tmpDir to $cacheDir/$agentId.tar.gz", AICLI_LOG_DEBUG);
    $tarOutput = [];
    // D-20: Use --no-same-owner during both pack and unpack to ensure compatibility
    exec("tar -czf " . escapeshellarg("$cacheDir/$agentId.tar.gz") . " --no-same-owner -C " . escapeshellarg($tmpDir) . " . 2>&1", $tarOutput, $result);
    if ($result !== 0) {
        aicli_log("ERROR: Tarball creation failed. Log: " . implode("\n", $tarOutput), AICLI_LOG_ERROR);
        return ['status' => 'error', 'error' => 'Caching to USB failed'];
    }

    // 3. Move to isolated RAM agent directory
    setInstallStatus("Deploying to isolated RAM environment...", 90, $agentId);
    $agentDir = "$agentBase/$agentId";
    aicli_log("Cleaning up old version from RAM before deployment: $agentDir", AICLI_LOG_DEBUG);
    if (is_dir($agentDir)) {
        exec("rm -rf " . escapeshellarg($agentDir));
    }
    mkdir($agentDir, 0777, true);

    setInstallStatus("Copying files to agent directory...", 95, $agentId);
    
    // D-26: TOTAL discovery in source before transfer (no filters)
    $tmpDiscovery = [];
    exec("find " . escapeshellarg($tmpDir) . " -maxdepth 4 2>&1", $tmpDiscovery);
    aicli_log("Pre-copy discovery in tmpDir ($tmpDir):\n" . implode("\n", array_slice($tmpDiscovery, 0, 50)), AICLI_LOG_DEBUG);
    
    aicli_log("Deploying files from $tmpDir to $agentDir (using rsync)", AICLI_LOG_DEBUG);
    // D-25: Back to rsync with -a and --info=name1 for granular feedback
    $deployOutput = [];
    exec("rsync -a " . escapeshellarg($tmpDir) . "/ " . escapeshellarg($agentDir) . "/ 2>&1", $deployOutput, $result);
    
    if ($result !== 0) {
        aicli_log("ERROR: Failed to deploy files using rsync. Result: $result. Output: " . implode(" ", $deployOutput), AICLI_LOG_ERROR);
        return ['status' => 'error', 'error' => 'Deployment to RAM failed'];
    }

    // D-26: Force deep recursive permissions to ensure binaries and their targets are executable
    exec("chmod -R 755 " . escapeshellarg($agentDir));
    
    // Explicitly target known binary links and their symlink targets
    foreach ([$registry[$agentId]['binary'] ?? '', $registry[$agentId]['binary_fallback'] ?? ''] as $b) {
        if (!empty($b)) {
            exec("chmod +x " . escapeshellarg($b) . " > /dev/null 2>&1");
            // If it's a symlink, chmod the real file too
            exec("chmod +x $(readlink -f " . escapeshellarg($b) . ") > /dev/null 2>&1");
        }
    }

    // D-25: Clear PHP's internal file status cache to ensure it sees the newly transferred files
    clearstatcache(true, $agentDir);
    
    // VERIFICATION: Discovery after deploy (broad search)
    $findOutput = [];
    exec("find " . escapeshellarg($agentDir) . " -maxdepth 3 2>&1", $findOutput);
    aicli_log("Post-deploy discovery in agentDir ($agentDir) [MaxDepth 3]:\n" . implode("\n", array_slice($findOutput, 0, 50)), AICLI_LOG_DEBUG);
    
    $binary = $registry[$agentId]['binary'] ?? '';
    $fallback = $registry[$agentId]['binary_fallback'] ?? '';
    $exists = (!empty($binary) && file_exists($binary)) || (!empty($fallback) && file_exists($fallback));
    
    if (!$exists) {
        aicli_log("ERROR: Binary missing after deploy ($agentId) despite tar-pipe. Result code: $result", AICLI_LOG_ERROR);
        setInstallStatus("Error: Binary missing after deploy. Check logs.", 0, $agentId);
        return ['status' => 'error', 'error' => 'Binary verification failed'];
    }
    
    setInstallStatus("Finalizing...", 98, $agentId);
    exec("rm -rf " . escapeshellarg($tmpDir));

    saveAICliVersion($agentId, $installedVer);
    gcPkgCache();
    setInstallStatus("Installation Complete!", 100, $agentId);
    aicli_log("Agent $agentId installed successfully", AICLI_LOG_INFO);
    @unlink("/tmp/unraid-aicliagents/install-$agentId.lock");
    return ['status' => 'ok'];
} catch (Exception $e) {
    aicli_log("Installation failed: " . $e->getMessage(), AICLI_LOG_ERROR);
    @unlink("/tmp/unraid-aicliagents/install-$agentId.lock");
    return ['status' => 'error', 'message' => $e->getMessage()];
}
}

function uninstallAgent($agentId) {
    if (empty($agentId)) return ['status' => 'error', 'message' => 'No Agent ID'];
    
    $lockFile = "/tmp/unraid-aicliagents/install-$agentId.lock";
    if (file_exists($lockFile)) {
        $pid = trim(@file_get_contents($lockFile));
        if (aicli_is_pid_running($pid)) {
             return ['status' => 'error', 'message' => 'Operation already in progress for this agent.'];
        }
    }
    file_put_contents($lockFile, getmypid());
    
    try {
        aicli_log("uninstallAgent started for $agentId", AICLI_LOG_INFO);
        
        // 1. Remove from RAM (Isolated Directory)
        $agentDir = "/usr/local/emhttp/plugins/unraid-aicliagents/agents/$agentId";
        if (is_dir($agentDir)) {
            aicli_log("Removing agent RAM directory: $agentDir", AICLI_LOG_DEBUG);
            exec("rm -rf " . escapeshellarg($agentDir));
        }

        // 2. Remove USB cache
        $cacheDir = "/boot/config/plugins/unraid-aicliagents/pkg-cache";
        $cacheFile = "$cacheDir/$agentId.tar.gz";
        if (file_exists($cacheFile)) {
            aicli_log("Removing cache file: $cacheFile", AICLI_LOG_DEBUG);
            unlink($cacheFile);
        }
        
        // 3. Remove version record
        $versionsFile = "/boot/config/plugins/unraid-aicliagents/versions.json";
        if (file_exists($versionsFile)) {
            $versions = json_decode(file_get_contents($versionsFile), true);
            if (isset($versions[$agentId])) {
                unset($versions[$agentId]);
                file_put_contents($versionsFile, json_encode($versions, JSON_PRETTY_PRINT));
            }
        }

        gcPkgCache();
        aicli_log("Agent $agentId uninstalled successfully", AICLI_LOG_INFO);
        @unlink($lockFile);
        return ['status' => 'ok'];
    } catch (Exception $e) {
        aicli_log("Uninstall failed for $agentId: " . $e->getMessage(), AICLI_LOG_ERROR);
        @unlink($lockFile);
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

function aicli_versions_match($v1, $v2) {
    if ($v1 === $v2) return true;
    $v1 = ltrim(trim((string)$v1), 'vV');
    $v2 = ltrim(trim((string)$v2), 'vV');
    return $v1 === $v2;
}

function checkAgentUpdates() {
    $registry = getAICliAgentsRegistry();
    $currentVersions = getAICliVersions();
    $config = getAICliConfig();
    $updates = [];
    
    foreach ($registry as $id => $agent) {
        if (empty($agent['is_installed'])) continue;
        $hasUpdate = false;
        $latestVersion = "Unknown";
        $current = $currentVersions[$id] ?? "0.0.0";
        $usePreview = ($config["preview_$id"] ?? "0") === "1";

        // NPM-based agents — D-21: Intelligent tag discovery for updates
        $npmMap = getAICliNpmMap();
        $package = $npmMap[$id] ?? null;
        
        if ($package) {
            $tagsOutput = [];
            exec("npm info " . escapeshellarg($package) . " dist-tags --json 2>/dev/null", $tagsOutput);
            $tags = json_decode(implode("\n", $tagsOutput), true);
            
            if ($usePreview) {
                if (isset($tags['preview'])) $latestVersion = $tags['preview'];
                elseif (isset($tags['next'])) $latestVersion = $tags['next'];
                elseif (isset($tags['nightly'])) $latestVersion = $tags['nightly'];
                else $latestVersion = $tags['latest'] ?? 'Unknown';
            } else {
                $latestVersion = $tags['latest'] ?? 'Unknown';
            }
            
            if ($latestVersion !== 'Unknown' && !aicli_versions_match($latestVersion, $current)) {
                $hasUpdate = true;
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

// LIBRARY END - No direct AJAX handler here. use AICliAjax.php instead.
