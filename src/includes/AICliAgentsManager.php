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
 * Helper to check if a command exists in the system path.
 */
if (!function_exists('command_exists')) {
    function command_exists($cmd) {
        $return = shell_exec(sprintf("which %s", escapeshellarg($cmd)));
        return !empty($return);
    }
}


/**
 * One-time initialization logic.
 * Called lazily when the plugin is actually used.
 */
function aicli_init_plugin() {
    // D-61: Versioned init sentinel ensures cleanup runs exactly once per upgrade
    $version = "2026.04.06.42"; // Synchronized with release
    $doneFile = "/tmp/unraid-aicliagents/init_done_$version";
    if (file_exists($doneFile)) return;

    $workBase = "/tmp/unraid-aicliagents/work";

    // D-131: Atomic lock prevents race conditions during multi-tab refreshes
    $lock = "/tmp/unraid-aicliagents/init.lock";
    if (!is_dir('/tmp/unraid-aicliagents')) @mkdir('/tmp/unraid-aicliagents', 0777, true);
    $fp = fopen($lock, "w+");
    if (!$fp || !flock($fp, LOCK_EX)) { // D-170: Block and wait for the first process to finish init
        if ($fp) fclose($fp);
        return; 
    }

    // D-170: Double-Check Locking - If init finished while we were waiting, exit now
    if (file_exists($doneFile)) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return;
    }

    @chmod('/tmp/unraid-aicliagents', 0777);
    
    aicli_log("LEGACY CLEANUP: Version $version update detected. Cleaning up legacy sessions...", AICLI_LOG_WARN);
    
    // 1. Kill all detached background sync loops and old shell scripts
    exec("pkill -9 -f 'Periodic sync triggered' > /dev/null 2>&1");
    exec("pkill -9 -f 'sync-daemon-.*\.sh' > /dev/null 2>&1");
    exec("pkill -9 -f 'aicli-shell.sh' > /dev/null 2>&1");
    
    // 2. Kill all tmux sessions matching our pattern to clear ghosts
    if (command_exists('tmux')) {
        exec("tmux ls -F '#S' 2>/dev/null | grep -E '^aicli-agent-' | xargs -I {} tmux kill-session -t {} > /dev/null 2>&1");
    }
    
    // 4. Kill all standalone AI CLI ttyd instances (Surgical kill to avoid Unraid Web Terminal)
    exec("pkill -9 -f 'ttyd.*aicliterm-' > /dev/null 2>&1");
    
    // 5. Kill all known agent binaries (orphaned node processes)
    exec("pkill -9 -f 'node.*(gemini|opencode|nanocoder|claude|kilo|pi|codex|factory)' > /dev/null 2>&1");
    
    // 6. Clean up stale sockets, PIDs, and legacy directory-based workspaces
    exec("rm -f /var/run/aicliterm-*.sock");
    exec("rm -f /var/run/unraid-aicliagents-*.pid");
    exec("rm -f /tmp/unraid-aicliagents/sync-daemon-*.pid");
    
    // D-147: NUKE & PAVE - Aggressively clear and recreate the /work directory
    // This is required to fix the 'mkdir: Permission denied' issue when switching users,
    // as the parent '/work' might be owned by root with 0700 permissions.
    aicli_log("STABILIZER: Performing a hard reset on $workBase to fix permission ghosts...", AICLI_LOG_WARN);
    if (is_dir($workBase)) {
        // Unmount any stray homes first to avoid deleting mount data
        exec("umount -l $workBase/*/home > /dev/null 2>&1");
        exec("rm -rf " . escapeshellarg($workBase));
    }
    @mkdir($workBase, 0777, true);
    // D-157: Unraid Integration - Use nobody:users (99:100) for shared access
    @chown($workBase, 'nobody');
    @chgrp($workBase, 'users');
    @chmod($workBase, 01777); // 1777 adds Sticky Bit
    
    // D-126: Ensure Nginx is configured for Unix Sockets
    aicli_ensure_nginx_config();

    @touch($doneFile);
    flock($fp, LOCK_UN);
    fclose($fp);
    @unlink($lock);
}

function aicli_boot_resurrection() {
    aicli_log("STABILIZER: Boot-time Resurrection initiated...", AICLI_LOG_INFO);
    
    // 1. Ensure plugin is initialized (cleanups, directories, nginx)
    aicli_init_plugin();
    
    // 2. Ensure Agent Storage is mounted
    aicli_ensure_agent_storage_mounted();
    
    // 3. Identify and Restore User Home Images to RAM
    $config = getAICliConfig();
    $persistenceDir = "/boot/config/plugins/unraid-aicliagents/persistence";
    if (is_dir($persistenceDir)) {
        $homes = glob("$persistenceDir/home_*.img");
        if (!empty($homes)) {
            foreach ($homes as $hImg) {
                $filename = basename($hImg);
                $user = str_replace(['home_', '.img'], '', $filename);
                if (empty($user)) continue;
                
                aicli_log("STABILIZER: Pre-loading Home for '$user' to RAM...", AICLI_LOG_INFO);
                // D-170: This will mount the image to RAM and start the sync daemon
                aicli_init_working_dir($user, true); 
            }
        }
    }
    aicli_log("STABILIZER: Boot-time Resurrection complete. System is Ready-Now.", AICLI_LOG_INFO);
}

function aicli_ensure_init() {
    aicli_init_plugin();
    aicli_ensure_agent_storage_mounted();
    aicli_check_storage_thresholds();
}

/**
 * Self-healing mount for the Persistent Agent image.
 */
function aicli_ensure_agent_storage_mounted($forceRw = false) {
    $agentBase = "/usr/local/emhttp/plugins/unraid-aicliagents/agents";
    $mounts = file_exists('/proc/mounts') ? file_get_contents('/proc/mounts') : '';
    $isMounted = (strpos($mounts, $agentBase) !== false);
    
    // D-166: Stabilizer Lock - Prevent concurrent mount races
    $lock = "/tmp/unraid-aicliagents/mount.lock";
    $fp = fopen($lock, "w+");
    if (!flock($fp, LOCK_EX)) { if ($fp) fclose($fp); return false; }
    
    $config = getAICliConfig();
    $storageBase = $config['agent_storage_path'] ?? "/boot/config/plugins/unraid-aicliagents";
    $img = "$storageBase/aicli-agents.img";

    // D-144: Robust Namespace/Visibility Validation
    // Use stat to verify we are actually seeing the Btrfs filesystem, not a ghost directory
    $fsType = trim((string)shell_exec("stat -f -c %T " . escapeshellarg($agentBase) . " 2>/dev/null"));
    
    if ($isMounted) {
        if ($fsType === 'btrfs') {
             // Properly mounted (even if empty)
             flock($fp, LOCK_UN); fclose($fp);
             return true;
        } else {
             aicli_log("STABILIZER: Agent storage is mounted but binaries are invisible (Orphaned namespace detected: $fsType). Cleaning up...", AICLI_LOG_WARN);
             // D-121: Unstack ALL mount layers
             while (trim((string)shell_exec("mountpoint -q " . escapeshellarg($agentBase) . " && echo 1 || echo 0")) === '1') {
                 exec("umount -l " . escapeshellarg($agentBase) . " > /dev/null 2>&1");
             }
             usleep(500000);
        }
    }

    if (!file_exists($img)) {
        aicli_log("STABILIZER: Agent storage image missing at $img. Recreating fresh 512MB volume...", AICLI_LOG_WARN);
        // D-166: Auto-initialize missing binary storage (Default to 512MB)
        exec("truncate -s 512M " . escapeshellarg($img));
        // Force mkfs to clear any stale metadata ghosts
        exec("mkfs.btrfs -f -K " . escapeshellarg($img));
        exec("sync");
        exec("udevadm settle"); // Ensure kernel sees the new partition/id
    }

    if (!is_dir($agentBase)) @mkdir($agentBase, 0777, true);
    
    // Attempt mount
    $opts = "loop,compress=zstd:1,noatime,nodiratime,autodefrag";
    if ($forceRw) {
        $opts .= ",rw";
    } else if (($config['write_protect_agents'] ?? '1') === '1') {
        $opts .= ",ro";
    }
    
    $cmd = "mount -o $opts " . escapeshellarg($img) . " " . escapeshellarg($agentBase);
    exec("$cmd 2>&1", $output, $result);
    
    if ($result === 0) {
        aicli_log("STABILIZER: Agent storage successfully re-mounted" . (($config['write_protect_agents'] ?? '1') === '1' ? " (Read-Only Mode)" : "") . ".", AICLI_LOG_INFO);
        // Tag the mount so we don't accidentally lazy-unmount it in the namespace check
        @touch("$agentBase/.aicli_id");
        @chmod($agentBase, 0755);
        flock($fp, LOCK_UN); fclose($fp);
        return true;
    }
    
    // D-170: If mount failed, attempt full repair sequence before giving up
    aicli_log("STABILIZER: Initial mount failed ($result). Attempting storage repair sequence...", AICLI_LOG_WARN);
    flock($fp, LOCK_UN); fclose($fp); // Release lock before repair to avoid blocking
    
    if (aicli_repair_agent_storage()) {
        return aicli_ensure_agent_storage_mounted($forceRw);
    }

    return false;
}

/**
 * Safely repairs a Btrfs loopback image by unstacking mounts, detaching loops,
 * and performing a rescue sequence. 
 * NOTE: This is NON-DESTRUCTIVE. It does NOT unlink the image.
 */
function aicli_repair_btrfs_image($imgFile, $mntPoint) {
    if (!file_exists($imgFile)) return false;
    
    aicli_log("Btrfs Rescue: Processing $imgFile mounted at $mntPoint", AICLI_LOG_WARN);

    // 1. Unstack all mount layers
    while (trim((string)shell_exec("mountpoint -q " . escapeshellarg($mntPoint) . " && echo 1 || echo 0")) === '1') {
        exec("umount -l " . escapeshellarg($mntPoint) . " > /dev/null 2>&1");
    }

    // 2. Clear loopback state
    $loops = shell_exec("losetup -j " . escapeshellarg($imgFile) . " 2>/dev/null | cut -d: -f1");
    if (!empty($loops)) {
        foreach (explode("\n", trim($loops)) as $l) {
            if (empty(trim($l))) continue;
            exec("blockdev --setrw " . escapeshellarg($l) . " > /dev/null 2>&1");
            exec("losetup -d " . escapeshellarg($l) . " > /dev/null 2>&1");
        }
    }
    usleep(250000);

    // 3. Offline Rescue
    exec("btrfs rescue zero-log " . escapeshellarg($imgFile) . " > /dev/null 2>&1");
    exec("btrfs rescue super-recover -y " . escapeshellarg($imgFile) . " > /dev/null 2>&1");
    
    // 4. Verification Mount (Try to force RW)
    exec("mount -o loop,rw,noatime " . escapeshellarg($imgFile) . " " . escapeshellarg($mntPoint) . " 2>&1", $out, $res);
    if ($res === 0) {
        aicli_log("Btrfs Rescue: Success for $imgFile", AICLI_LOG_INFO);
        // Force boundary sync
        exec("btrfs filesystem resize max " . escapeshellarg($mntPoint) . " > /dev/null 2>&1");
        exec("umount " . escapeshellarg($mntPoint) . " > /dev/null 2>&1");
        return true;
    }

    aicli_log("Btrfs Rescue: FAILED for $imgFile. Image might be physically corrupted or locked by kernel. Output: " . implode("\n", $out), AICLI_LOG_ERROR);
    return false;
}

function aicli_repair_agent_storage() {
    $config = getAICliConfig();
    $storageBase = $config['agent_storage_path'] ?? "/boot/config/plugins/unraid-aicliagents";
    $imgFile = "$storageBase/aicli-agents.img";
    $agentBase = "/usr/local/emhttp/plugins/unraid-aicliagents/agents";

    if (aicli_repair_btrfs_image($imgFile, $agentBase)) {
        $res = aicli_ensure_agent_storage_mounted(true); // Mount RW to verify rescue
        if ($res) aicli_agent_storage_lock(); // Force return to RO protection
        return $res;
    }
    return false;
}

function aicli_repair_home_storage($username) {
    if (empty($username)) return false;
    $workDir = "/tmp/unraid-aicliagents/work";
    $imgFile = "$workDir/home_$username.img";
    $mntPoint = "$workDir/$username/home";

    if (aicli_repair_btrfs_image($imgFile, $mntPoint)) {
        // Remount home logic
        aicli_log("Home Rescue: Remounting $username home...", AICLI_LOG_INFO);
        $serviceScript = dirname(__DIR__) . "/scripts/btrfs_delta_service.sh";
        exec("bash " . escapeshellarg($serviceScript) . " mount_ram " . escapeshellarg($username), $out, $res);
        return ($res === 0);
    }
    return false;
}

/**
 * D-160: Flash Safety Helpers - Unlock/Lock Agent storage for maintenance
 */
function aicli_agent_storage_unlock() {
    $agentBase = "/usr/local/emhttp/plugins/unraid-aicliagents/agents";
    $isRo = aicli_is_agent_storage_ro();
    
    if (!$isRo) {
        // Double check it's actually mounted
        if (trim((string)shell_exec("mountpoint -q " . escapeshellarg($agentBase) . " && echo 1 || echo 0")) !== '1') {
            aicli_log("MAINTENANCE: Unlocking Agent storage (Mounting fresh RW)", AICLI_LOG_INFO);
            return aicli_ensure_agent_storage_mounted(true);
        }
        aicli_log("MAINTENANCE: Agent storage is already RW.", AICLI_LOG_DEBUG);
        return true; 
    }
    
    aicli_log("MAINTENANCE: Unlocking Agent storage (RO -> RW)", AICLI_LOG_INFO);
    
    // D-165: Hardened loop with retries for busy filesystems
    for ($i = 1; $i <= 3; $i++) {
        exec("sync " . escapeshellarg($agentBase));
        usleep(300000); // 300ms
        
        // D-168: Robust Write-Protection Clearing
        $loop = trim((string)shell_exec("findmnt -n -o SOURCE " . escapeshellarg($agentBase)));
        if (!empty($loop) && strpos($loop, '/dev/loop') === 0) {
             exec("blockdev --setrw " . escapeshellarg($loop) . " > /dev/null 2>&1");
        }

        exec("mount -o remount,rw " . escapeshellarg($agentBase) . " 2>&1", $out, $res);
        if ($res === 0) return true;
        
        aicli_log("Mnt Attempt $i failed: " . implode(" ", $out), AICLI_LOG_WARN);
        unset($out);
        usleep(500000);
    }
    
    // D-165: Hard Recovery
    aicli_log("STALL: Remount RW failed. Triggering robust repair sequence...", AICLI_LOG_WARN);
    if (aicli_repair_agent_storage()) {
        return aicli_ensure_agent_storage_mounted(true);
    }
    
    return false;
}

function aicli_agent_storage_lock() {
    $config = getAICliConfig();
    if (($config['write_protect_agents'] ?? '1') !== '1') return true;
    
    $agentBase = "/usr/local/emhttp/plugins/unraid-aicliagents/agents";
    if (aicli_is_agent_storage_ro()) return true;

    aicli_log("MAINTENANCE: Locking Agent storage (RW -> RO)", AICLI_LOG_INFO);
    
    // D-168: Robust locking loop with retries for busy filesystems
    for ($i = 1; $i <= 5; $i++) {
        exec("sync " . escapeshellarg($agentBase));
        usleep(500000); // 500ms
        
        exec("mount -o remount,ro " . escapeshellarg($agentBase) . " 2>&1", $out, $res);
        if ($res === 0) return true;
        
        $outStr = implode(" ", $out);
        aicli_log("Lock Attempt $i failed: $outStr", AICLI_LOG_WARN);
        
        if (strpos($outStr, "busy") !== false) {
             // D-196: Diagnostic Logging - See what's holding the mount
             if (command_exists('lsof')) {
                 $lsof = shell_exec("lsof +D " . escapeshellarg($agentBase) . " 2>/dev/null");
                 if (!empty($lsof)) aicli_log("Processes holding mount busy:\n$lsof", AICLI_LOG_DEBUG);
             }

             // D-169: If busy, aggressively kill lingering processes
             if ($i >= 2) {
                  aicli_log("Surgical cleanup: Killing ALL processes using $agentBase (Attempt $i)...", AICLI_LOG_WARN);
                  exec("fuser -kvm " . escapeshellarg($agentBase) . " > /dev/null 2>&1");
                  usleep(800000);
             }
        }
        
        unset($out);
        usleep(500000);
    }
    
    return false;
}

function aicli_is_agent_storage_ro() {
    $agentBase = "/usr/local/emhttp/plugins/unraid-aicliagents/agents";
    
    // 1. Check /proc/mounts (Metadata Reality)
    $mounts = file_exists('/proc/mounts') ? @file_get_contents('/proc/mounts') : '';
    $isRoMetadata = false;
    if (preg_match('|' . preg_quote($agentBase) . '[ \t]+btrfs[ \t]+([^ \t\n\r]+)|', $mounts, $matches)) {
        $options = explode(',', $matches[1]);
        if (in_array('ro', $options)) $isRoMetadata = true;
    } else {
        // If not in mounts at all
        return true; 
    }

    if ($isRoMetadata) return true;

    // 2. 'Physical Reality' Check: Try to touch a file to see if the kernel actually allows writes
    // (Btrfs sometimes stays RO in the kernel state even if metadata says RW after a panic)
    $testFile = "$agentBase/.aicli_rw_test";
    @unlink($testFile);
    if (!@touch($testFile)) {
        return true; // Cannot write, effectively RO
    }
    @unlink($testFile);
    
    return false;
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
        @chown($logDir, 'nobody');
        @chgrp($logDir, 'users');
        @chmod($logDir, 01777);
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
    if (empty($username) || getenv('AICLI_INSTALLER') === '1') return;

    $config = getAICliConfig();
    $mins = (int)($config['sync_interval_mins'] ?? 0);
    $hours = (int)($config['sync_interval_hours'] ?? 0);
    $syncMins = $mins + ($hours * 60);

    $lockFile = "/tmp/unraid-aicliagents/sync-daemon-$username.pid";
    $script = "/tmp/unraid-aicliagents/sync-daemon-$username.sh";

    // 1. If Sync is disabled (0), kill it and clean up
    if ($syncMins <= 0) {
        if (file_exists($lockFile)) {
            $pid = trim(@file_get_contents($lockFile));
            if ($pid && aicli_is_pid_running($pid)) {
                aicli_log("STABILIZER: Stopping sync daemon for $username as sync is now disabled.", AICLI_LOG_INFO);
                exec("kill -9 $pid > /dev/null 2>&1");
            }
            @unlink($lockFile);
        }
        if (file_exists($script)) @unlink($script);
        return;
    }

    // 2. Safety Floor: Prevent accidental high-frequency sync (15 min min)
    if ($syncMins < 15) $syncMins = 15;

    // 3. Check if already running with current config
    if (file_exists($lockFile)) {
        $pid = trim(@file_get_contents($lockFile));
        if ($pid && aicli_is_pid_running($pid)) {
            if (!$force) return; // Still running, no force request
            exec("kill -9 $pid > /dev/null 2>&1");
            @unlink($lockFile);
        }
    }

    // 4. Generate and start daemon
    aicli_log("STABILIZER: Re-starting standalone sync daemon for $username (Interval: $syncMins min)", AICLI_LOG_INFO);
    $cmd = "#!/bin/bash\n" .
           "exec 0<&- 1>&- 2>&- 3>&-\n" . 
           "echo \$\$ > " . escapeshellarg($lockFile) . "\n" .
           "while true; do\n" .
           "  sleep " . ($syncMins * 60) . "\n" .
           "  /usr/bin/php -r \"require_once '/usr/local/emhttp/plugins/unraid-aicliagents/includes/AICliAgentsManager.php'; aicli_log('Global periodic sync heartbeat triggered ($syncMins min)', 2); aicli_sync_home('$username', true);\"\n" .
           "done\n";
    file_put_contents($script, $cmd);
    chmod($script, 0755);
    aicli_exec_bg("nohup $script > /dev/null 2>&1");
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

function aicli_notify($message, $subject = "System Notification", $type = 'normal') {
    $importance = ($type === 'warning') ? 'alert' : 'normal';
    $title = "AI CLI Agents";
    // D-126: Remove redundant square brackets from subject as it's already in the -e Title
    $command = "/usr/local/emhttp/webGui/scripts/notify -e " . escapeshellarg($title) . " -s " . escapeshellarg($subject) . " -m " . escapeshellarg($message) . " -i " . escapeshellarg($importance);
    exec($command . " > /dev/null 2>&1");
}

/**
 * Hybrid Storage Helpers
 */
function aicli_get_persist_dir($username) {
    $config = getAICliConfig();
    $base = !empty($config['persistence_base']) ? rtrim($config['persistence_base'], '/') : "/boot/config/plugins/unraid-aicliagents/persistence";
    return "$base/$username/home";
}

/**
 * D-60: Robust path readiness check.
 * Determines if a path (especially /mnt/) is actually available or if it
 * depends on an Unraid array/pool that is currently stopped.
 */
function aicli_is_path_ready($path) {
    // 1. Non-/mnt/ paths (Flash, RAM, etc.) are always assumed ready
    if (strpos($path, '/mnt/') !== 0) return true;

    // 2. Parse Unraid State
    $var = @parse_ini_file('/var/local/emhttp/var.ini');
    $isArrayStarted = (isset($var['mdState']) && $var['mdState'] === 'STARTED');

    // 3. Extract the root mount point (e.g., "user", "disk1", "cache")
    $parts = explode('/', trim($path, '/'));
    if (count($parts) < 2) return false; 
    $mountPoint = $parts[1]; 

    // 4. Check Hardcoded Array-Dependent Paths
    if ($mountPoint === 'user' || $mountPoint === 'user0' || preg_match('/^disk\d+$/', $mountPoint)) {
        return $isArrayStarted;
    }

    // 5. Check for Custom Pools (Cache drives)
    if ($isArrayStarted) {
        $disks = @parse_ini_file('/var/local/emhttp/disks.ini', true);
        if ($disks) {
            foreach ($disks as $disk) {
                if (isset($disk['name']) && $disk['name'] === $mountPoint && $disk['status'] === 'DISK_OK') {
                    return true;
                }
            }
        }
    }

    // 6. Independent Mounts (Unassigned Devices, Custom User Mounts)
    // We only consider it ready if the base mount point actually exists as a directory.
    // This protects against typos polluting the rootfs (RAM).
    return is_dir("/mnt/$mountPoint");
}

function aicli_get_work_dir($username) {
    $base = "/tmp/unraid-aicliagents/work/$username";
    if (!is_dir($base)) {
        @mkdir($base, 0775, true);
        @chown($base, 'aicliagent');
        @chgrp($base, 'users');
        
        // D-170: Cap RAM usage for work directory to prevent system rootfs exhaustion.
        // We mount a dedicated tmpfs for the user work dir with a 128MB limit.
        // This ensures a runaway agent doesn't consume all system RAM.
        exec("mount -t tmpfs -o size=128M tmpfs " . escapeshellarg($base) . " > /dev/null 2>&1");
    }
    return $base;
}

function aicli_init_working_dir($username, $forceRestore = false) {
    if (empty($username)) $username = 'root';
    $userDir = aicli_get_work_dir($username);
    $workBase = "/tmp/unraid-aicliagents/work";
    $ramImg = "$workBase/home_$username.img";
    $ramDir = "$userDir/home";

    // D-170: Session Initialization Lock per User
    // Prevents concurrent requests from mounting or restoring the same image
    $initLock = "/tmp/unraid-aicliagents/init_$username.lock";
    if (!is_dir(dirname($initLock))) @mkdir(dirname($initLock), 0777, true);
    $fpLock = fopen($initLock, "w+");
    if (!$fpLock || !flock($fpLock, LOCK_EX)) {
        if ($fpLock) fclose($fpLock);
        return false;
    }
    
    // 1. Ensure basics
    if (!is_dir($workBase)) {
        @mkdir($workBase, 0777, true);
        @chmod($workBase, 0777); // Critical: Allow non-root users to write here
    }
    if (!is_dir($userDir)) @mkdir($userDir, 0700, true);
    if (!is_dir($ramDir)) @mkdir($ramDir, 0700, true);
    
    $persistBase = aicli_get_persist_dir($username);
    $persistImg = dirname(dirname($persistBase)) . "/home_$username.img";

    $isReady = aicli_is_path_ready(dirname($persistImg));
    
    // D-165: Move instead of copy for root -> user migration on Flash
    $rootLegacyImg = dirname(dirname($persistBase)) . "/home_root.img";
    if ($username !== 'root' && file_exists($rootLegacyImg) && !file_exists($persistImg)) {
        aicli_log("MIGRATION: Renaming root home image to $username persistence on Flash...", AICLI_LOG_INFO);
        exec("mv " . escapeshellarg($rootLegacyImg) . " " . escapeshellarg($persistImg));
    }
    $volatileFlag = "$userDir/.volatile_session";

    $serviceScript = dirname(__DIR__) . "/scripts/btrfs_delta_service.sh";

    // 2. MIGRATION & IMAGE INITIALIZATION
    if ($isReady && !file_exists($persistImg)) {
        // Migration logic: If legacy directory exists, create image and migrate
        if (is_dir($persistBase) && count(array_diff(scandir($persistBase), ['.', '..'])) > 0) {
            aicli_log("MIGRATING: Creating verified Btrfs home image from legacy directory for $username...", AICLI_LOG_INFO);
            // D-171: Call the consolidated shell script for migration and verification
            exec("$serviceScript migrate " . escapeshellarg($username) . " " . escapeshellarg($persistBase) . " " . escapeshellarg($persistImg), $output, $returnCode);
            
            if ($returnCode === 0 && file_exists($persistImg)) {
                aicli_log("Migration successful and verified for $username. Cleaning up legacy directory.", AICLI_LOG_INFO);
                // D-171: Only remove if verification passed
                exec("rm -rf " . escapeshellarg($persistBase));
            } else {
                aicli_log("ERROR: Migration failed or verification failed for $username. Check logs.", AICLI_LOG_ERROR);
            }
        } else {
            // New user: Create fresh 128MB image (Standardized Increment)
            exec("$serviceScript init " . escapeshellarg($username) . " " . escapeshellarg($persistImg) . " 128M");
        }
    }

    $config = getAICliConfig();
    $load_home_ram = (string)($config['load_home_ram'] ?? '1');

    // 3. RESTORE (Persistent -> RAM) OR DIRECT MOUNT
    if ($load_home_ram === '1') {
        if (!file_exists($ramImg) || $forceRestore) {
            if ($isReady && file_exists($persistImg)) {
                aicli_log("Restoring Home Image from Persistence to RAM for $username...", AICLI_LOG_INFO);
                // D-170: Atomic Restoration - Copy to temp file first then rename to prevent partial-file mounts
                exec("cp " . escapeshellarg($persistImg) . " " . escapeshellarg($ramImg . ".tmp") . " && mv " . escapeshellarg($ramImg . ".tmp") . " " . escapeshellarg($ramImg));
                if (file_exists($volatileFlag)) @unlink($volatileFlag);
            } else if (!$isReady) {
                aicli_log("WARN: Persistence path NOT READY ($persistImg). Creating VOLATILE RAM image.", AICLI_LOG_WARN);
                exec("$serviceScript init " . escapeshellarg($username) . " " . escapeshellarg($ramImg));
                @touch($volatileFlag);
            }
        }
        $targetImgToMount = $ramImg;
    } else {
        if (!$isReady) {
            aicli_log("ERROR: Persistence path NOT READY ($persistImg) and RAM mode disabled. Fallback to VOLATILE RAM image.", AICLI_LOG_WARN);
            exec("$serviceScript init " . escapeshellarg($username) . " " . escapeshellarg($ramImg));
            $targetImgToMount = $ramImg;
        } else {
            $targetImgToMount = $persistImg;
        }
    }


    // 4. MOUNT
    if (!aicli_is_mounted($ramDir)) {
        if ($targetImgToMount === $ramImg) {
            aicli_log("Mounting Btrfs Home (RAM) for $username...", AICLI_LOG_INFO);
            exec("$serviceScript mount_ram " . escapeshellarg($username));
        } else {
            aicli_log("Mounting Btrfs Home (Direct Persistent) for $username...", AICLI_LOG_INFO);
            exec("mount -o loop,compress-force=zstd:3,noatime " . escapeshellarg($targetImgToMount) . " " . escapeshellarg($ramDir));
        }
        
        // D-170: Verify mount succeeded
        if (!aicli_is_mounted($ramDir)) {
            aicli_log("ERROR: Failed to mount Home storage for $username.", AICLI_LOG_ERROR);
            flock($fpLock, LOCK_UN);
            fclose($fpLock);
            return false;
        }
    }

    // 5. Ownership Fixes
    if (trim((string)shell_exec('whoami')) === 'root') {
        exec("chown -R " . escapeshellarg($username) . ":users " . escapeshellarg($userDir));
        exec("chown -R " . escapeshellarg($username) . ":users " . escapeshellarg($ramDir));
    }

    flock($fpLock, LOCK_UN);
    fclose($fpLock);

    return $ramDir;
}

function aicli_is_mounted($dir) {
    return (trim((string)shell_exec("mountpoint -q " . escapeshellarg($dir) . " && echo 1 || echo 0")) === '1');
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
    
    $load_home_ram = (string)($config['load_home_ram'] ?? '1');
    if ($load_home_ram === '0') {
        aicli_log("Bypassing sync for $username: RAM execution disabled, running directly from persistent storage.", AICLI_LOG_DEBUG);
        return true;
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
    
    if (empty($username)) $username = 'root';
    $workBase = "/tmp/unraid-aicliagents/work";
    $ramImg = "$workBase/home_$username.img";
    $ramDir = "$workBase/$username/home";
    
    $persistBase = aicli_get_persist_dir($username);
    $persistImg = dirname(dirname($persistBase)) . "/home_$username.img";
    
    // 1. Readiness Checks
    if (!aicli_is_path_ready(dirname($persistImg))) {
        aicli_log("BLOCKING sync for $username: Persistence path not ready (" . dirname($persistImg) . ").", AICLI_LOG_WARN);
        return false;
    }

    $volatileFlag = dirname($ramDir) . "/.volatile_session";
    if (file_exists($volatileFlag)) {
        return false;
    }

    $syncLock = "/tmp/unraid-aicliagents/sync-operation.lock";
    $fp = fopen($syncLock, "w+");
    if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
        if ($fp) fclose($fp);
        return false;
    }

    aicli_log("Syncing Btrfs Home for $username via Delta Send/Receive...", AICLI_LOG_INFO);
    $serviceScript = dirname(__DIR__) . "/scripts/btrfs_delta_service.sh";
    
    // 2. Perform Delta Sync
    $lastSnapFile = dirname($ramDir) . "/.last_sync_snap";
    $lastSnap = file_exists($lastSnapFile) ? trim(file_get_contents($lastSnapFile)) : "";
    
    // Take new snapshot
    $currSnap = trim((string)shell_exec("bash " . escapeshellarg($serviceScript) . " snapshot " . escapeshellarg($username)));
    if (empty($currSnap)) {
        aicli_log("ERROR: Failed to create Btrfs snapshot for $username.", AICLI_LOG_ERROR);
        flock($fp, LOCK_UN);
        fclose($fp);
        return false;
    }

    // Send Delta to USB/Array Image
    exec("bash " . escapeshellarg($serviceScript) . " sync_delta " . escapeshellarg($username) . " " . escapeshellarg($persistImg) . " " . escapeshellarg($lastSnap) . " " . escapeshellarg($currSnap) . " 2>&1", $out, $res);
    
    if ($res === 0) {
        @file_put_contents($lastSnapFile, $currSnap);
        aicli_log("Delta Sync complete for $username. Consistent SQLite state preserved.", AICLI_LOG_INFO);
    } else {
        aicli_log("ERROR: Delta Sync failed for $username (Code $res). Output: " . implode("\n", $out), AICLI_LOG_ERROR);
    }
    
    flock($fp, LOCK_UN);
    fclose($fp);
    return ($res === 0);
}

function aicli_sync_agents_to_backend() {
    $config = getAICliConfig();
    $load_agents_ram = (string)($config['load_agents_ram'] ?? '0');
    if ($load_agents_ram === '0') return true;

    $targetPath = $config['agent_storage_path'] ?? '/boot/config/plugins/unraid-aicliagents';
    $persistImg = rtrim($targetPath, '/') . "/aicli-agents.img";
    $ramMnt = "/usr/local/emhttp/plugins/unraid-aicliagents/agents";

    if (!is_dir($ramMnt) || !aicli_is_path_ready(dirname($persistImg))) return false;

    aicli_log("Syncing Agent binaries from RAM to persistent storage...", AICLI_LOG_INFO);
    $syncLock = "/tmp/unraid-aicliagents/agent-sync.lock";
    $fp = fopen($syncLock, "w+");
    if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) { if ($fp) fclose($fp); return false; }

    $tempMnt = "/tmp/aicli_agent_sync";
    @mkdir($tempMnt, 0700, true);

    if (!file_exists($persistImg)) {
        exec("truncate -s 1G " . escapeshellarg($persistImg));
        exec("mkfs.btrfs -m single -L AICLI_AGENTS " . escapeshellarg($persistImg));
    }

    exec("mount -o loop,compress=zstd:1,noatime " . escapeshellarg($persistImg) . " " . escapeshellarg($tempMnt));
    exec("rsync -a --delete " . escapeshellarg($ramMnt."/") . " " . escapeshellarg($tempMnt."/"));
    exec("umount " . escapeshellarg($tempMnt));
    @rmdir($tempMnt);
    
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}

function aicli_sync_all() {
    $workBase = "/tmp/unraid-aicliagents/work";
    if (!is_dir($workBase)) return;
    
    $users = array_diff(scandir($workBase), ['.', '..']);
    foreach ($users as $username) {
        if (is_dir("$workBase/$username/home")) {
            aicli_sync_home($username, true);
        }
    }
}

function aicli_evict_all() {
    // D-62: Ensure all work is synchronized before killing sessions
    aicli_sync_all();

    // D-155: Session Checkpointing - Capture active session metadata before purging RAM
    $checkpoint = [];
    foreach (glob("/tmp/unraid-aicliagents/session-*.chatid") as $cf) {
        $id = str_replace(['/tmp/unraid-aicliagents/session-', '.chatid'], '', $cf);
        $cid = trim(@file_get_contents($cf));
        $aid = trim(@file_get_contents("/tmp/unraid-aicliagents/session-$id.agentid") ?: '');
        if (!empty($cid)) $checkpoint[$id] = ['chat_id' => $cid, 'agent_id' => $aid, 'ts' => time()];
    }
    if (!empty($checkpoint)) {
        $cpFile = "/boot/config/plugins/unraid-aicliagents/persistence/upgrade_checkpoint.json";
        @file_put_contents($cpFile, json_encode($checkpoint, JSON_PRETTY_PRINT));
        aicli_log("STABILIZER: Checkpointed " . count($checkpoint) . " active sessions for post-upgrade resumption.", AICLI_LOG_INFO);
    }

    aicli_log("DEEP CLEANUP: Forcefully terminating all agent sessions...", AICLI_LOG_WARN);
    
    // 1. Kill the sync daemons first to prevent them from spawning new PHP processes
    exec("pkill -9 -f 'Periodic sync triggered' > /dev/null 2>&1");
    exec("pkill -9 -f 'sync-daemon-.*\.sh' > /dev/null 2>&1");
    
    // 2. Kill ttyd instances (Terminal interfaces)
    // D-165: Surgical kill of AI CLI terminals only to prevent killing Unraid's system terminal
    exec("pkill -9 -f 'ttyd.*aicliterm-' > /dev/null 2>&1");
    
    // 3. Kill shell wrappers and sync scripts
    exec("pkill -9 -f 'aicli-shell.sh' > /dev/null 2>&1");
    exec("pkill -9 -f 'aicli_sync_home' > /dev/null 2>&1");
    
    // 6. Clean up sockets and PIDs to ensure a clean state on next start
    exec("rm -f /var/run/aicliterm-*.sock");
    exec("rm -f /var/run/unraid-aicliagents-*.pid");
    exec("rm -f /var/run/unraid-aicliagents-*.lock");
    exec("rm -f /tmp/unraid-aicliagents/sync-daemon-*.pid");
    
    // 7. Unmount loopback home directories
    $workBase = "/tmp/unraid-aicliagents/work";
    if (is_dir($workBase)) {
        $users = array_diff(scandir($workBase), ['.', '..']);
        foreach ($users as $username) {
            $ramDir = "$workBase/$username/home";
            if (aicli_is_mounted($ramDir)) {
                aicli_log("Unmounting home for $username...", AICLI_LOG_INFO);
                exec("umount " . escapeshellarg($ramDir) . " > /dev/null 2>&1");
            }
        }
    }
    
    aicli_log("Eviction complete.", AICLI_LOG_INFO);
}

/**
 * Targeted eviction of specific agent sessions.
 * @param array|string $ids List of session IDs to terminate.
 */
function aicli_evict_targeted($ids) {
    if (empty($ids)) return;
    $idArray = is_array($ids) ? $ids : explode(',', (string)$ids);
    aicli_log("SESSION EVICTION: Terminating target sessions: " . implode(', ', $idArray), AICLI_LOG_WARN);
    
    foreach ($idArray as $id) {
        $id = trim($id);
        if (empty($id)) continue;
        
        // 1. Kill the ttyd instance associated with this session ID
        // The command line contains the socket path: /var/run/aicliterm-$id.sock
        exec("pkill -9 -f 'aicliterm-" . escapeshellarg($id) . "' > /dev/null 2>&1");
        
        // 2. Kill the tmux session
        $tmuxName = "aicli-agent-$id";
        exec("tmux kill-session -t " . escapeshellarg($tmuxName) . " > /dev/null 2>&1");
        
        // 3. Clean up matching socket and PID files
        @unlink("/var/run/aicliterm-$id.sock");
        @unlink("/var/run/unraid-aicliagents-$id.pid");
    }
    aicli_log("Targeted eviction complete.", AICLI_LOG_INFO);
}


function setInstallStatus($message, $progress, $agentId = '', $reason = '') {
    $dir = "/tmp/unraid-aicliagents";
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    
    $status = ['message' => $message, 'progress' => $progress, 'agentId' => $agentId, 'timestamp' => time(), 'reason' => $reason];
    $file = empty($agentId) ? "$dir/install-status" : "$dir/install-status-$agentId";
    
    file_put_contents($file, json_encode($status));
}

function saveAICliConfig($newConfig, $notify = true) {
    $configFile = "/boot/config/plugins/unraid-aicliagents/unraid-aicliagents.cfg";
    $vaultFile = "/boot/config/plugins/unraid-aicliagents/secrets.cfg";
    $current = getAICliConfig();
    
    aicli_log("saveAICliConfig called", AICLI_LOG_DEBUG);

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
    $allowed = ['enable_tab', 'theme', 'font_size', 'history', 'home_path', 'user', 'root_path', 'version', 'debug_logging', 'sync_interval_hours', 'sync_interval_mins', 'log_level', 'agent_storage_path', 'load_home_ram', 'load_agents_ram', 'storage_opt_last_run', 'sync_last_run', 'write_protect_agents'];
    foreach ($newConfig as $key => $val) {
        if (strpos($key, 'preview_') === 0 || strpos($key, 'node_memory_') === 0) $allowed[] = $key;
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

        // D-72: Auto-Clone Image: If the new user doesn't have an image, clone the current one
        $persistBase = $current['persistence_base'] ?? '/boot/config/plugins/unraid-aicliagents/persistence';
        $oldImg = $persistBase . "/home_$oldUser.img";
        $newImg = $persistBase . "/home_$newUser.img";

        if (file_exists($oldImg) && !file_exists($newImg)) {
            aicli_log("Migrating environment from $oldUser to $newUser ($newImg)...", AICLI_LOG_INFO);
            // D-165: Use rename (move) for instantaneous transition and zero Flash wear.
            // Fallback to sparse-aware copy if rename fails (e.g. cross-device move).
            if (!@rename($oldImg, $newImg)) {
                aicli_log("Cross-device or permission block on rename, falling back to sparse copy...", AICLI_LOG_DEBUG);
                exec("cp --sparse=always " . escapeshellarg($oldImg) . " " . escapeshellarg($newImg) . " && rm " . escapeshellarg($oldImg), $out, $res);
                $success = ($res === 0);
            } else {
                $success = true;
            }

            if ($success) {
                @chmod($newImg, 0666);
                aicli_log("Migration complete. $newUser initialized with inherited environment from $oldUser.", AICLI_LOG_INFO);
            } else {
                aicli_log("ERROR: Failed to migrate environment to $newImg.", AICLI_LOG_ERROR);
            }
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
    
    aicli_log("Writing config to $configFile", AICLI_LOG_DEBUG);
    $tmpFile = $configFile . ".tmp." . getmypid();
    if (file_put_contents($tmpFile, $ini) !== false) {
        if (!rename($tmpFile, $configFile)) {
            @unlink($tmpFile);
            aicli_log("ERROR: Atomic rename failed for $configFile", AICLI_LOG_ERROR);
            return;
        }

        if ($notify) {
            // Swap: Subject="Settings Saved", Message="Configuration updated successfully."
            aicli_notify("Configuration updated successfully.", "Settings Saved");
        }
        
        // D-96: Optimization - Only restart the daemon if the interval actually changed
        // This avoids heavy process restarts during routine metric updates
        if ($oldSyncMins !== ((int)(($current['sync_interval_mins'] ?? 0) + (($current['sync_interval_hours'] ?? 0) * 60)))) {
            aicli_manage_sync_daemon($newUser, true);
        }

        // Ensure home directory is migrated if it was legacy or if the user changed
        aicli_migrate_home_path();

        // D-22: If user changed, ensure permissions on home path are updated AND move RAM dir
        if ($oldUser !== $newUser) {
            // D-143: Aggressive session eviction to prevent '502 Bad Gateway' with dangling sockets
            aicli_log("USER CHANGE: Terminating all active terminal sessions for $oldUser...", AICLI_LOG_WARN);
            aicli_evict_all();
            
            $workBase = "/tmp/unraid-aicliagents/work";
            if (!is_dir($workBase)) {
                @mkdir($workBase, 0777, true);
                @chmod($workBase, 0777);
            }
            
            $oldRam = "$workBase/$oldUser";
            $newRam = "$workBase/$newUser";
            
            if (is_dir($oldRam)) {
                aicli_log("Moving RAM work directory from $oldRam to $newRam", AICLI_LOG_INFO);
                // D-140: Recursive move with backup if exists to prevent clobbering
                if (is_dir($newRam)) exec("rm -rf " . escapeshellarg($newRam));
                exec("mv " . escapeshellarg($oldRam) . " " . escapeshellarg($newRam));
            } elseif (!is_dir($newRam)) {
                @mkdir($newRam, 0700, true);
            }
            
            // Re-fetch config to get the migrated home_path
            $finalConfig = getAICliConfig();
            $finalHome = $finalConfig['home_path'];
            
            aicli_log("Updating permissions for $newUser on $finalHome and $newRam", AICLI_LOG_INFO);
            // Must use recursive chown to avoid 'Permission denied' on subfolders inside the Btrfs images
            if (is_dir($finalHome)) exec("chown -R " . escapeshellarg($newUser) . ":users " . escapeshellarg($finalHome));
            if (is_dir($newRam)) exec("chown -R " . escapeshellarg($newUser) . ":users " . escapeshellarg($newRam));
            
            // D-141: Re-initialize the home directory (mount etc.) for the new user immediately
            aicli_init_working_dir($newUser);
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
        '/boot/config/plugins/unraid-aicliagents/agents.json', // D-126: Prune stale registry
        '/usr/local/emhttp/plugins/geminicli',
        '/usr/local/bin/gemini'
    ];
    foreach ($legacyFiles as $file) {
        if (file_exists($file)) {
            aicli_log("Cleanup: Removing legacy file $file", AICLI_LOG_WARN);
            // D-128: Background large directory deletions to prevent installer timeout
            is_dir($file) ? exec("nohup rm -rf " . escapeshellarg($file) . " > /dev/null 2>&1 &") : @unlink($file);
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
        'persistence_base' => '/boot/config/plugins/unraid-aicliagents/persistence',
        'user' => 'root',
        'root_path' => '/mnt/user',
        'version' => 'unknown',
        'debug_logging' => '0',
        'log_level' => '2',
        'sync_interval_hours' => '0',
        'write_protect_agents' => '1', // Default to ON for Flash safety
        'load_agents_ram' => '0'
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

function getAICliWorkingDirFile($id = 'default') {
    return "/var/run/unraid-aicliagents-$id.workdir";
}

function isAICliRunning($id = 'default', $chatId = null, $agentId = null, $path = null) {
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

    // Verify Path if requested
    if ($path !== null) {
        $workDirFile = getAICliWorkingDirFile($id);
        if (!file_exists($workDirFile)) return false;
        $runningPath = trim(file_get_contents($workDirFile));
        if ($path !== $runningPath) return false;
    }

    return true;
}

// --- Workspace Persistence ---

function aicli_get_workspaces() {
    $file = "/boot/config/plugins/unraid-aicliagents/workspaces.json";
    $default = ['sessions' => [], 'activeId' => null];
    if (!file_exists($file)) return $default;
    $data = json_decode(file_get_contents($file), true);
    if (!is_array($data)) return $default;
    // Migration: If it's a flat array, wrap it
    if (!isset($data['sessions']) && count($data) > 0 && isset($data[0]['path'])) {
        return ['sessions' => $data, 'activeId' => null];
    }
    if (!isset($data['sessions'])) return $default;
    return $data;
}

function aicli_save_workspaces($workspaces) {
    $file = "/boot/config/plugins/unraid-aicliagents/workspaces.json";
    $dir = dirname($file);
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    file_put_contents($file, json_encode($workspaces, JSON_PRETTY_PRINT));
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

/**
 * Legacy compatibility wrapper for v24-era calls found in un-migrated UI files.
 * @deprecated Use getAICliAgentsRegistry() instead.
 */
function getAICliNpmMap() {
    $registry = getAICliAgentsRegistry();
    $map = [];
    foreach ($registry as $id => $agent) {
        if (isset($agent['npm_package'])) {
            $map[$id] = $agent['npm_package'];
        }
    }
    return $map;
}

function getAICliAgentsRegistry() {
    $manifestFile = "/boot/config/plugins/unraid-aicliagents/agents.json";
    $agentBase = "/usr/local/emhttp/plugins/unraid-aicliagents/agents";
    
    // D-127: Unified Registry Architecture (Registry + NPM Mapping)
    $defaultRegistry = [
        'gemini-cli' => [
            'id' => 'gemini-cli',
            'name' => 'Gemini CLI',
            'npm_package' => '@google/gemini-cli',
            'icon_url' => '/plugins/unraid-aicliagents/assets/icons/google-gemini.png',
            'release_notes' => 'https://www.npmjs.com/package/@google/gemini-cli?activeTab=versions',
            'runtime' => 'node',
            'binary' => "$agentBase/gemini-cli/node_modules/.bin/gemini",
            'binary_fallback' => "$agentBase/gemini-cli/node_modules/@google/gemini-cli/bin/aicli.js",
            'resume_cmd' => "$agentBase/gemini-cli/node_modules/.bin/gemini --resume {chatId}",
            'resume_latest' => "$agentBase/gemini-cli/node_modules/.bin/gemini --resume",
            'env_prefix' => 'GEMINI',
        ],
        'claude-code' => [
            'id' => 'claude-code',
            'name' => 'Claude Code',
            'npm_package' => '@anthropic-ai/claude-code',
            'icon_url' => '/plugins/unraid-aicliagents/assets/icons/claude.ico',
            'release_notes' => 'https://www.npmjs.com/package/@anthropic-ai/claude-code?activeTab=versions',
            'runtime' => 'node',
            'binary' => "$agentBase/claude-code/node_modules/.bin/claude",
            'resume_cmd' => "$agentBase/claude-code/node_modules/.bin/claude --resume {chatId}",
            'resume_latest' => "$agentBase/claude-code/node_modules/.bin/claude --continue",
            'env_prefix' => 'CLAUDE',
        ],
        'opencode' => [
            'id' => 'opencode',
            'name' => 'OpenCode',
            'npm_package' => 'opencode-ai',
            'icon_url' => '/plugins/unraid-aicliagents/assets/icons/opencode.ico',
            'release_notes' => 'https://github.com/anomalyco/opencode/releases',
            'runtime' => 'node',
            'binary' => "$agentBase/opencode/node_modules/.bin/opencode",
            'resume_cmd' => "$agentBase/opencode/node_modules/.bin/opencode --session {chatId}",
            'resume_latest' => "$agentBase/opencode/node_modules/.bin/opencode --continue",
            'env_prefix' => 'OPENCODE',
        ],
        'kilocode' => [
            'id' => 'kilocode',
            'name' => 'Kilo Code',
            'npm_package' => '@kilocode/cli',
            'icon_url' => '/plugins/unraid-aicliagents/assets/icons/kilocode.ico',
            'release_notes' => 'https://github.com/Kilo-Org/kilocode/releases',
            'runtime' => 'node',
            'binary' => "$agentBase/kilocode/node_modules/.bin/kilo",
            'resume_cmd' => "$agentBase/kilocode/node_modules/.bin/kilo --session {chatId}",
            'resume_latest' => "$agentBase/kilocode/node_modules/.bin/kilo --continue",
            'env_prefix' => 'KILOCODE',
        ],
        'pi-coder' => [
            'id' => 'pi-coder',
            'name' => 'Pi Coder',
            'npm_package' => '@mariozechner/pi-coding-agent',
            'icon_url' => '/plugins/unraid-aicliagents/assets/icons/picoder.png',
            'release_notes' => 'https://github.com/badlogic/pi-mono/releases',
            'runtime' => 'node',
            'binary' => "$agentBase/pi-coder/node_modules/.bin/pi",
            'resume_cmd' => "$agentBase/pi-coder/node_modules/.bin/pi",
            'resume_latest' => "$agentBase/pi-coder/node_modules/.bin/pi",
            'env_prefix' => 'PI_CODER',
        ],
        'codex-cli' => [
            'id' => 'codex-cli',
            'name' => 'Codex CLI',
            'npm_package' => '@openai/codex',
            'icon_url' => '/plugins/unraid-aicliagents/assets/icons/codex.png',
            'release_notes' => 'https://www.npmjs.com/package/@openai/codex?activeTab=versions',
            'runtime' => 'node',
            'binary' => "$agentBase/codex-cli/node_modules/.bin/codex",
            'resume_cmd' => "$agentBase/codex-cli/node_modules/.bin/codex",
            'resume_latest' => "$agentBase/codex-cli/node_modules/.bin/codex",
            'env_prefix' => 'CODEX',
        ],
        'factory-cli' => [
            'id' => 'factory-cli',
            'name' => 'Factory CLI',
            'npm_package' => '@factory/cli',
            'icon_url' => '/plugins/unraid-aicliagents/assets/icons/factory.png',
            'release_notes' => 'https://www.npmjs.com/package/@factory/cli?activeTab=versions',
            'runtime' => 'node',
            'binary' => "$agentBase/factory-cli/node_modules/.bin/droid",
            'resume_cmd' => "$agentBase/factory-cli/node_modules/.bin/droid",
            'resume_latest' => "$agentBase/factory-cli/node_modules/.bin/droid",
            'env_prefix' => 'FACTORY',
        ],
        'nanocoder' => [
            'id' => 'nanocoder',
            'name' => 'NanoCoder',
            'npm_package' => '@nanocollective/nanocoder',
            'icon_url' => '/plugins/unraid-aicliagents/assets/icons/nanocoder.png',
            'release_notes' => 'https://www.npmjs.com/package/@nanocollective/nanocoder?activeTab=versions',
            'runtime' => 'node',
            'binary' => "$agentBase/nanocoder/node_modules/.bin/nanocoder",
            'resume_cmd' => "$agentBase/nanocoder/node_modules/.bin/nanocoder",
            'resume_latest' => "$agentBase/nanocoder/node_modules/.bin/nanocoder",
            'env_prefix' => 'NANO',
        ]
    ];

    $registry = $defaultRegistry;
    if (file_exists($manifestFile)) {
        $custom = json_decode(@file_get_contents($manifestFile), true);
        if (is_array($custom) && isset($custom['agents'])) {
            $registry = array_merge($defaultRegistry, $custom['agents']);
        }
    }

    // MANDATORY: Recalculate is_installed for all agents to ensure the UI reflects reality
    $versions = getAICliVersions();
    $versionsChanged = false;

    foreach ($registry as $id => &$agent) {
        $bin = $agent['binary'] ?? '';
        $fallback = $agent['binary_fallback'] ?? '';
        
        // D-199: Strict Verification - Must have a version record AND a physical binary
        $hasVersion = isset($versions[$id]) && $versions[$id] !== '0.0.0' && $versions[$id] !== 'unknown';
        $binExists = (!empty($bin) && file_exists($bin)) || (!empty($fallback) && file_exists($fallback));
        
        $agent['is_installed'] = ($hasVersion && $binExists);

        // D-170: Lazy-populate versions.json if missing but agent is installed
        if ($binExists && !$hasVersion) {
            $pJson = dirname($bin) . "/../package.json";
            if (!file_exists($pJson)) $pJson = dirname($fallback) . "/../package.json";
            
            if (file_exists($pJson)) {
                $pData = json_decode(@file_get_contents($pJson), true);
                if (isset($pData['version'])) {
                    $versions[$id] = $pData['version'];
                    $versionsChanged = true;
                    aicli_log("Lazy-restored version for $id: " . $pData['version'], AICLI_LOG_INFO);
                }
            }
        }
    }

    if ($versionsChanged) {
        $vFile = "/boot/config/plugins/unraid-aicliagents/versions.json";
        file_put_contents($vFile, json_encode($versions, JSON_PRETTY_PRINT));
    }

    return $registry;
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

function startAICliTerminal($id = 'default', $path = null, $chatSessionId = null, $agentId = 'gemini-cli') {
    // D-01/D-02: Sanitize inputs to prevent command injection
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
    $agentId = preg_replace('/[^a-zA-Z0-9_-]/', '', $agentId);
    if ($chatSessionId !== null) $chatSessionId = preg_replace('/[^a-zA-Z0-9_-]/', '', $chatSessionId);

    aicli_log("startAICliTerminal called: ID=$id, Agent=$agentId, Path=$path", AICLI_LOG_INFO);
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
    
    // D-195: Hardcoded support for 'terminal' pseudo-agent (Feature, not a selectable Agent)
    if ($agentId === 'terminal') {
        $agent = [
            'id' => 'terminal',
            'name' => 'Terminal',
            'icon_url' => '/plugins/unraid-aicliagents/assets/icons/terminal.png',
            'runtime' => 'shell',
            'binary' => '/bin/bash',
            'resume_cmd' => '/bin/bash',
            'resume_latest' => '/bin/bash',
            'env_prefix' => 'TERM',
            'is_installed' => true
        ];
    } else {
        $agent = $registry[$agentId] ?? $registry['gemini-cli'];
    }

    if (!$agent['is_installed']) {
        aicli_log("ERROR: Agent $agentId is not installed.", AICLI_LOG_ERROR);
        return;
    }

    // D-125: ROOTFS Safety Check (Admission Control)
    // If the Unraid RAM disk (rootfs) is above 95%, refuse to start new sessions
    $rootUsage = (int)shell_exec("df -m / | tail -1 | awk '{print $5}' | tr -d '%' 2>/dev/null");
    if ($rootUsage > 95) {
        aicli_log("CRITICAL: Rootfs is at {$rootUsage}%. Refusing to start new session to prevent system crash.", AICLI_LOG_ERROR);
        aicli_notify("Cannot start agent: RAM disk is nearly full ({$rootUsage}%). Please close inactive sessions.", "Storage Critical", "warning");
        return;
    }

    $config = getAICliConfig();
    $workingDir = $path ?: $config['root_path'];

    // Ensure Home directory exists (Hybrid RAM storage)
    $username = $config['user'];
    $homePath = aicli_init_working_dir($username);
    if (!$homePath) return; // D-170: Wait for lock or error logged elsewhere

    // D-99: STORAGE AUTO-OPTIMIZATION: Relocated here to run once per sync cycle (instead of every 5s status check)
    // D-101: LOCKING: Wrapped in /usr/bin/flock -n to ensure only ONE defrag runs per image at a time.
    $lastOpt = (int)($config['storage_opt_last_run'] ?? 0);
    if ((time() - $lastOpt) > 3600) {
        $stats = aicli_get_storage_status();
        $needsDefrag = false;
        
        // Check Agents
        $mnt = "/usr/local/emhttp/plugins/unraid-aicliagents/agents";
        if (is_dir($mnt)) {
            aicli_log("STABILIZER: Pruning & Compressing Agents (Locked)...", AICLI_LOG_DEBUG);
            $lockFile = "/tmp/unraid-aicliagents/btrfs-agents.lock";
            $service = "/usr/local/emhttp/plugins/unraid-aicliagents/scripts/btrfs_delta_service.sh";
            // Prune first, then defrag
            aicli_exec_bg("/usr/bin/flock -n " . escapeshellarg($lockFile) . " bash " . escapeshellarg($service) . " prune agents " . escapeshellarg($mnt));
            aicli_exec_bg("/usr/bin/flock -n " . escapeshellarg($lockFile) . " btrfs filesystem defragment -czstd -r " . escapeshellarg($mnt));
            $needsDefrag = true;
        }
        
        // Check current User's Home
        if (isset($stats['home_stats'][$username]) && ($stats['home_stats'][$username]['compression_ratio'] ?? '100%') === '100%') {
             $target = "/tmp/unraid-aicliagents/work/$username/home";
             if (is_dir($target)) {
                 aicli_log("STABILIZER: Pruning & Compressing Home for $username (Locked)...", AICLI_LOG_DEBUG);
                 $lockFile = "/tmp/unraid-aicliagents/btrfs-home-$username.lock";
                 // STABILIZER D-110: Periodic Background Pruning
                 $service = "/usr/local/emhttp/plugins/unraid-aicliagents/scripts/btrfs_delta_service.sh";
                 aicli_exec_bg("/usr/bin/flock -n " . escapeshellarg($lockFile) . " bash " . escapeshellarg($service) . " prune " . escapeshellarg($username));
                 aicli_exec_bg("/usr/bin/flock -n " . escapeshellarg($lockFile) . " btrfs filesystem defragment -czstd -r " . escapeshellarg($target));
                 $needsDefrag = true;
             }
        }
        
        if ($needsDefrag) saveAICliConfig(['storage_opt_last_run' => time()], false);
    }
    
    // D-45: SQLite Pre-flight Check: Ensure agent databases are consistent after RAM restoration
    aicli_pre_flight_check($agentId, $homePath, $workingDir);

    // D-158: Metadata Healing: Align external workspace metadata to nobody:users for SMB compatibility
    aicli_fix_workspace_permissions($workingDir, $agentId);

    // Ensure standalone sync daemon is running for this user
    aicli_manage_sync_daemon($username);

    // Track session count for concurrency/sync safety (Unified with shell ref)
    $refFile = "/tmp/unraid-aicliagents/sync-$username.ref";
    if (!is_dir(dirname($refFile))) @mkdir(dirname($refFile), 0777, true);
    $count = file_exists($refFile) ? (int)file_get_contents($refFile) : 0;
    // Note: shell script increments this, but we initialize it if missing
    if (!file_exists($refFile)) file_put_contents($refFile, "0");

    // D-121: Agent Storage is now authoritative from the Btrfs mount. 
    if (!aicli_ensure_agent_storage_mounted()) {
        aicli_log("CRITICAL: Agent storage could not be mounted in startAICliTerminal.", AICLI_LOG_ERROR);
        return;
    }

    $binExists = file_exists($agent['binary']);
    if (!$binExists) {
        $msg = "CRITICAL: Agent binary not found at {$agent['binary']}. Agent Storage mount might be failed or incomplete.";
        aicli_log($msg, AICLI_LOG_ERROR);
        aicli_notify("Agent binary missing. Please repair plugin storage.", "Storage Error", "error");
        return;
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

    if (isAICliRunning($id, $chatSessionId, $agentId, $workingDir) && file_exists($sock)) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return;
    }

    // D-35: Even if heartbeat is missing, we don't forcefully restart terminal sessions here.
    // The daemon will be restarted lazily via aicli_manage_sync_daemon($username) above if needed.
    // However, if the sesson exists but the AGENT or PATH has changed, we MUST kill and restart.
    $runningAgentId = file_exists($agentIdFile) ? trim(file_get_contents($agentIdFile)) : '';
    $runningPath = file_exists(getAICliWorkingDirFile($id)) ? trim(file_get_contents(getAICliWorkingDirFile($id))) : '';
    
    if (($runningAgentId !== '' && $runningAgentId !== $agentId) || ($runningPath !== '' && $runningPath !== $workingDir)) {
        aicli_log("Session context changed (Agent: $runningAgentId->$agentId, Path: $runningPath->$workingDir). Forcing session restart.", AICLI_LOG_INFO);
        stopAICliTerminal($id, true);
    }

    if (file_exists($shell)) chmod($shell, 0755);

    // Save state before starting
    if ($chatSessionId !== null) file_put_contents($chatIdFile, $chatSessionId);
    file_put_contents($agentIdFile, $agentId);
    file_put_contents(getAICliWorkingDirFile($id), $workingDir);

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

    // D-162: Per-Agent Node Memory Limit (NODE_OPTIONS)
    $memLimit = intval($config["node_memory_$agentId"] ?? 4096);
    if ($memLimit < 512) $memLimit = 512; // Safety floor
    
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
           "export NODE_OPTIONS=--max-old-space-size=$memLimit; " .
           "export OPENCODE_EXPERIMENTAL_DISABLE_COPY_ON_SELECT=true; ";
           
    if (!empty($chatSessionId)) {
        $safeChatId = escapeshellarg($chatSessionId);
        $env .= "export AICLI_CHAT_SESSION_ID=$safeChatId; ";
    } else {
        // D-156: Checkpoint Restoration - If no session ID was provided, check if we have a persistent token from an upgrade
        $cpFile = "/boot/config/plugins/unraid-aicliagents/persistence/upgrade_checkpoint.json";
        if (file_exists($cpFile)) {
            $checkpoint = json_decode(@file_get_contents($cpFile), true);
            if (isset($checkpoint[$id]) && $checkpoint[$id]['agent_id'] === $agentId) {
                $cid = $checkpoint[$id]['chat_id'];
                aicli_log("STABILIZER: Resuming checkpointed session $cid for agent $agentId after upgrade.", AICLI_LOG_INFO);
                $env .= "export AICLI_CHAT_SESSION_ID=" . escapeshellarg($cid) . "; ";
                // Clean up this specific token as it has been consumed
                unset($checkpoint[$id]);
                if (empty($checkpoint)) @unlink($cpFile);
                else @file_put_contents($cpFile, json_encode($checkpoint, JSON_PRETTY_PRINT));
            }
        }
    }

    // Load and export User-defined Workspace Environment Variables
    $customEnvs = getWorkspaceEnvs($workingDir, $agentId);
    foreach ($customEnvs as $k => $v) {
        $env .= "export " . escapeshellarg($k) . "=" . escapeshellarg($v) . "; ";
    }

    // D-132: REDIRECT CACHES TO RAM (Prevent persistent storage bloat)
    // This keeps ~/.npm and ~/.cache out of the persistent Home sync, 
    // saving hundreds of MBs on Flash storage.
    $cacheDir = "/tmp/unraid-aicliagents/caches/$username/$agentId";
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0777, true);
    $env .= "export NPM_CONFIG_CACHE=" . escapeshellarg("$cacheDir/npm") . "; ";
    $env .= "export XDG_CACHE_HOME=" . escapeshellarg("$cacheDir/xdg") . "; ";
    $env .= "export TMPDIR=" . escapeshellarg("$cacheDir/tmp") . "; ";
    
    // Ensure the cache dirs exist
    if (!is_dir("$cacheDir/npm")) @mkdir("$cacheDir/npm", 0777, true);
    if (!is_dir("$cacheDir/xdg")) @mkdir("$cacheDir/xdg", 0777, true);
    if (!is_dir("$cacheDir/tmp")) @mkdir("$cacheDir/tmp", 0777, true);

    $themeStr = getAICliTtydTheme($config['theme'] ?? 'dark');
    // D-180: Use system ttyd with robust path detection
    $ttyd = "/usr/sbin/ttyd";
    if (!file_exists($ttyd)) $ttyd = "/usr/bin/ttyd";
    if (!file_exists($ttyd)) $ttyd = "ttyd";

    // D-186: Cleanup stale socket and PID to prevent 502/Bind failures
    if (file_exists($sock)) @unlink($sock);
    if (file_exists($pidFile)) @unlink($pidFile);
    // Aggressive cleanup for stale UNIX sockets
    exec("if [ -S " . escapeshellarg($sock) . " ]; then rm -f " . escapeshellarg($sock) . "; fi");

    $log = "/tmp/unraid-aicliagents/ttyd-$id.log";
    @unlink($log);

    // Build the environment string as KEY=VAL for 'env' (no 'export' or ';')
    $envArr = [
        "AICLI_HOME" => $homePath,
        "AICLI_USER" => $config['user'],
        "AICLI_ROOT" => $workingDir,
        "AICLI_HISTORY" => $config['history'],
        "AICLI_SESSION_ID" => $id,
        "AICLI_DEBUG" => $logLevel,
        "AICLI_SYNC_MINS" => (string)$syncMins,
        "AGENT_ID" => $agentId,
        "AGENT_NAME" => $agent['name'],
        "ENV_PREFIX" => $agent['env_prefix'],
        "BINARY" => $agent['binary'],
        "RESUME_CMD" => $agent['resume_cmd'],
        "RESUME_LATEST" => $agent['resume_latest'],
        "COLORTERM" => "truecolor",
        "NODE_OPTIONS" => "--max-old-space-size=$memLimit",
        "OPENCODE_EXPERIMENTAL_DISABLE_COPY_ON_SELECT" => "true",
        "NPM_CONFIG_CACHE" => "$cacheDir/npm",
        "XDG_CACHE_HOME" => "$cacheDir/xdg",
        "TMPDIR" => "$cacheDir/tmp"
    ];
    if (!empty($chatSessionId)) $envArr["AICLI_CHAT_SESSION_ID"] = $chatSessionId;
    
    // Merge workspace-specific envs
    $customEnvs = getWorkspaceEnvs($workingDir, $agentId);
    foreach ($customEnvs as $k => $v) $envArr[preg_replace('/[^a-zA-Z0-9_-]/', '', $k)] = $v;

    $envStr = "";
    foreach ($envArr as $k => $v) $envStr .= escapeshellarg("$k=$v") . " ";

    // D-190: Unraid 7.2 ttyd uses -i for Unix Sockets (NOT -U)
    // We run runuser to drop privileges to the target user.
    $cmd = "$ttyd -i $safeSock -p 0 -W -d0 " .
           "-t fontSize=$safeFontSize " .
           "-t fontFamily='monospace' " .
           "-t theme='$themeStr' " .
           "runuser -u " . $safeUser . " -- env $envStr /bin/bash " . escapeshellarg($shell);
    
    aicli_log("Launching ttyd for session $id: $cmd", AICLI_LOG_DEBUG);
    
    exec("nohup $cmd > " . escapeshellarg($log) . " 2>&1 & echo $!", $output, $execRes);

    $pid = trim($output[0] ?? '');
    if ($pid && ctype_digit($pid)) {
        file_put_contents($pidFile, $pid);
        // D-30: Robust wait loop for socket availability and PERMISSIONS
        for ($i = 0; $i < 10; $i++) {
            if (file_exists($sock)) {
                // IMPORTANT: Nginx (nobody) needs access to the socket
                @chmod($sock, 0666);
                aicli_log("Terminal socket created and permissions fixed for $id.", AICLI_LOG_DEBUG);
                break;
            }
            usleep(200000); 
        }
        if (!file_exists($sock)) {
            aicli_log("ERROR: Terminal socket failed to appear after 2s for $id. Check $log", AICLI_LOG_ERROR);
        }
    } else {
        aicli_log("ERROR: Failed to start ttyd process for $id. Exec result: $execRes", AICLI_LOG_ERROR);
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

    // D-132: Volatile Cache Garbage Collection (RAM Protection)
    // If RAM caches grow beyond 100MB, prune them.
    $cacheBase = "/tmp/unraid-aicliagents/caches";
    if (is_dir($cacheBase)) {
        $sizeMB = (int)shell_exec("du -sm " . escapeshellarg($cacheBase) . " | cut -f1");
        if ($sizeMB > 100) {
            aicli_log("GC: Volatile RAM Cache exceeded 100MB ($sizeMB MB). Pruning to protect RAM.", AICLI_LOG_INFO);
            exec("rm -rf " . escapeshellarg($cacheBase) . "/*");
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

/**
 * Storage & Btrfs Management Helpers
 */

/**
 * Calculates free space on the USB Flash drive in Megabytes.
 */
function aicli_get_flash_headroom() {
    // D-85: Use --output=avail for robustness across different df versions/long device names
    $output = shell_exec("df -m --output=avail /boot | tail -n 1");
    return intval(trim($output ?? '0'));
}

/**
 * Returns detailed status of the Btrfs agent storage.
 */
function aicli_get_storage_status() {
    $img = "/boot/config/plugins/unraid-aicliagents/aicli-agents.img";
    $mnt = "/usr/local/emhttp/plugins/unraid-aicliagents/agents";
    
    $status = ['status' => 'missing', 'used_mb' => 0, 'total_mb' => 0, 'available_mb' => 0, 'logical_used_mb' => 0, 'home_stats' => []];
    if (!file_exists($img)) return $status;
    
    $total_bytes = filesize($img);
    $total_mb = round($total_bytes / 1024 / 1024);
    $disk_size_mb = intval(trim((string)shell_exec("du -m " . escapeshellarg($img) . " | cut -f1")));
    
    $used_mb = 0;
    $available_mb = 0;
    if (is_dir($mnt)) {
        // D-155: Report actual Filesystem Capacity for the bar limit
        $fs_output = shell_exec("df -m " . escapeshellarg($mnt) . " | tail -1 | awk '{print $2 \" \" $3 \" \" $4}'");
        if (preg_match('/(\d+)\s+(\d+)\s+(\d+)/', $fs_output, $m)) {
            $total_mb = intval($m[1]); // Honorable Filesystem Capacity
            $used_mb = intval($m[2]);  // Logical Used
            $available_mb = intval($m[3]); // Logical Free
        }
    }
    
    // Also include a summary of user home images
    $homeStats = [];
    $config = getAICliConfig();
    $activeUser = $config['user'] ?? 'root';
    
    $workDir = "/tmp/unraid-aicliagents/work";
    if (is_dir($workDir)) {
        foreach (glob("$workDir/home_*.img") as $hImg) {
            $uName = str_replace(['home_', '.img'], '', basename($hImg));
            $hMnt = "$workDir/$uName/home";
            if (is_dir($hMnt)) {
                $hTotal = round(filesize($hImg) / 1024 / 1024);
                $hDiskSize = intval(trim((string)shell_exec("du -m " . escapeshellarg($hImg) . " | cut -f1")));
                // D-155: Apply same honest capacity logic to Home images
                $hOutput = shell_exec("df -m " . escapeshellarg($hMnt) . " | tail -1 | awk '{print $2 \" \" $3 \" \" $4}'");
                $hTotal = 0; $hU = 0; $hA = 0;
                if (preg_match('/(\d+)\s+(\d+)\s+(\d+)/', $hOutput, $m)) {
                    $hTotal = intval($m[1]);
                    $hU = intval($m[2]);
                    $hA = intval($m[3]);
                }
                $hVirtualUsed = $hU;
                
                // D-136: Scale Alignment - Re-calculate logically used space
                // D-198: Disabled auto-truncation due to risk of data loss on failed mounts.
                // Manual 'Shrink' should be used instead.

                // D-133: Accurately calculate Physical usage for the UI Bar
                $hCompData = aicli_get_btrfs_compression_info($hMnt, $hVirtualUsed);
                
                // D-149: Reporting Sanitizer - Prevent negative values and division by zero
                $safeRatio = max(0.01, $hCompData['ratio']);
                $hPhysicalUsed = round($hVirtualUsed / $safeRatio);
                if ($hPhysicalUsed < 0) $hPhysicalUsed = 0;
                if ($hVirtualUsed < 0) $hVirtualUsed = 0;
                
                // D-137: Calculate Effective Free Space (Logical * Ratio)
                $hEffectiveAvailable = round($hA * $hCompData['ratio']);
                
                $homeStats[$uName] = [
                    'used_mb' => $hPhysicalUsed, // Physical for the bar
                    'logical_used_mb' => $hVirtualUsed, // Logical for the shadow
                    'available_mb' => $hA,
                    'effective_available_mb' => $hEffectiveAvailable,
                    'total_mb' => $hTotal, // Honest Btrfs capacity
                    'disk_size_mb' => $hDiskSize,
                    'ratio' => $hCompData['ratio'],
                    'compression_ratio' => $hCompData['label'],
                    'physical_pct' => $hTotal > 0 ? round(($hPhysicalUsed / $hTotal) * 100) : 0,
                    'logical_pct' => $hTotal > 0 ? round(($hVirtualUsed / $hTotal) * 100) : 0,
                    'percent' => $hTotal > 0 ? round(($hPhysicalUsed / $hTotal) * 100) : 0 // Legacy compatibility
                ];
            }
        }
    }

    $compAgents = aicli_get_btrfs_compression_info($mnt, $used_mb);
    
    // D-149: Reporting Sanitizer for Agent Storage
    $safeAgentRatio = max(0.01, $compAgents['ratio']);
    $agentPhysicalUsed = round($used_mb / $safeAgentRatio);
    if ($agentPhysicalUsed < 0) $agentPhysicalUsed = 0;
    
    $agentEffectiveAvailable = round($available_mb * $compAgents['ratio']);




    $rootfs_percent = (int)shell_exec("df -m / | tail -1 | awk '{print $5}' | tr -d '%' 2>/dev/null");
    $rootfs_free_mb = (int)shell_exec("df -m / | tail -1 | awk '{print $4}' 2>/dev/null");

    return [
        'status' => 'mounted',
        'used_mb' => $agentPhysicalUsed,
        'logical_used_mb' => $used_mb,
        'available_mb' => $available_mb,
        'effective_available_mb' => $agentEffectiveAvailable,
        'total_mb' => $total_mb,
        'file_size_mb' => round(filesize($img) / 1024 / 1024),
        'disk_size_mb' => $disk_size_mb,
        'compression_ratio' => $compAgents['label'],
        'ratio' => $compAgents['ratio'],
        'logical_pct' => $total_mb > 0 ? round(($used_mb / $total_mb) * 100) : 0,
        'home_stats' => $homeStats,
        'flash_headroom_mb' => aicli_get_flash_headroom(),
        'rootfs_usage_percent' => $rootfs_percent,
        'rootfs_free_mb' => $rootfs_free_mb,
        'rootfs_warning' => ($rootfs_percent > 85),
        'write_protect_agents' => ($config['write_protect_agents'] ?? '1') === '1',
        'is_read_only' => aicli_is_agent_storage_ro(),
        'timestamp' => time(),
        'options' => 'compress=zstd:1,noatime,autodefrag'
    ];
}

/**
 * Expands a Btrfs image. 
 * $type can be 'agents' or a username for 'home' storage.
 */
function aicli_expand_storage($type = 'agents', $sizeAdd = '256M') {
    $lockFile = "/tmp/unraid-aicliagents/resize.lock";
    $fp = fopen($lockFile, "w+");
    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        aicli_log("Resize deferred: Another storage operation is in progress.", AICLI_LOG_WARN);
        fclose($fp);
        return ['status' => 'error', 'message' => 'Resize operation already in progress.'];
    }

    try {
        if ($type === 'agents') {
            $img = "/boot/config/plugins/unraid-aicliagents/aicli-agents.img";
            $config = getAICliConfig();
            $realImg = rtrim($config['agent_storage_path'] ?? "/boot/config/plugins/unraid-aicliagents", '/') . "/aicli-agents.img";
            if (file_exists($realImg)) $img = $realImg;

            $mnt = "/usr/local/emhttp/plugins/unraid-aicliagents/agents";
            
            if (strpos($img, "/boot/") === 0) {
                $headroom = aicli_get_flash_headroom();
                if ($headroom < 150) return ['status' => 'error', 'message' => 'Flash too full to expand image safely. Free up 150MB+ on Flash first.'];
            }
            
            aicli_log("Expanding Agent storage binary container ($img) by $sizeAdd...", AICLI_LOG_INFO);
            aicli_agent_storage_unlock();
            
            exec("truncate -s +$sizeAdd " . escapeshellarg($img) . " 2>&1", $out, $res);
            if ($res !== 0) {
                return ['status' => 'error', 'message' => 'Truncate failed: ' . end($out)];
            }
            
            $loop = trim((string)shell_exec("findmnt -n -o SOURCE " . escapeshellarg($mnt)));
            if (!empty($loop) && strpos($loop, '/dev/loop') === 0) {
                aicli_log("Refreshing loop device $loop size...", AICLI_LOG_DEBUG);
                exec("losetup -c " . escapeshellarg($loop) . " 2>&1");
            }
            
            if (is_dir($mnt)) {
                aicli_log("Performing Btrfs filesystem resize (max)...", AICLI_LOG_DEBUG);
                exec("btrfs filesystem resize max " . escapeshellarg($mnt) . " 2>&1", $out, $res);
                if ($res !== 0) {
                    $sizeMb = intval(preg_replace('/[^0-9]/', '', $sizeAdd));
                    aicli_log("Btrfs max resize failed, trying targeted +{$sizeMb}M resize...", AICLI_LOG_WARN);
                    exec("btrfs filesystem resize +{$sizeMb}M " . escapeshellarg($mnt) . " 2>&1", $out2, $res2);
                    if ($res2 !== 0) {
                        return ['status' => 'error', 'message' => 'Btrfs resize failed: ' . end($out2)];
                    }
                }
            }
            aicli_log("Agent storage expansion complete.", AICLI_LOG_INFO);
            
            $throttleFile = "/boot/config/plugins/unraid-aicliagents/storage_warn_sent.txt";
            if (file_exists($throttleFile)) @unlink($throttleFile);

        } else {
            // Expand user home image
            $user = str_replace('home_', '', $type); // D-170: Strip prefix to get clean username
            $serviceScript = dirname(__DIR__) . "/scripts/btrfs_delta_service.sh";
            aicli_log("Expanding Home storage container for $user by $sizeAdd...", AICLI_LOG_INFO);
            exec("bash " . escapeshellarg($serviceScript) . " expand " . escapeshellarg($user) . " " . escapeshellarg($sizeAdd), $out, $res);
            if ($res === 0) {
                 foreach ($out as $line) if (!empty(trim($line))) aicli_log("  > " . $line, AICLI_LOG_DEBUG);
                 $throttleUser = "/boot/config/plugins/unraid-aicliagents/warn_sent_$type.txt";
                 if (file_exists($throttleUser)) @unlink($throttleUser);
            } else {
                aicli_log("Home expansion FAILED for $user. Output: " . implode("\n", $out), AICLI_LOG_ERROR);
                return ['status' => 'error', 'message' => implode("\n", $out)];
            }
        }
        return ['status' => 'ok'];
    } finally {
        if ($type === 'agents') aicli_agent_storage_lock();
        flock($fp, LOCK_UN);
        fclose($fp);
        @unlink($lockFile);
    }
}

/**
 * Shrinks a Btrfs image safely. 
 * $type can be 'agents' or a username for 'home' storage.
 */
function aicli_shrink_storage($type = 'agents', $sizeSub = 'auto') {
    $lockFile = "/tmp/unraid-aicliagents/resize.lock";
    $fp = fopen($lockFile, "w+");
    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        aicli_log("Shrink deferred: Another storage operation is in progress.", AICLI_LOG_WARN);
        fclose($fp);
        return ['status' => 'error', 'message' => 'Resize operation already in progress.'];
    }

    try {
        if (strpos($type, 'home_') === 0) {
            $user = str_replace('home_', '', $type);
            $workDir = "/tmp/unraid-aicliagents/work";
            $img = "$workDir/home_$user.img";
            $mnt = "$workDir/$user/home";
            $minSize = 512; // D-170: Increased to 512MB for Btrfs stability on RAM disks
        } else {
            $img = "/boot/config/plugins/unraid-aicliagents/aicli-agents.img";
            // D-188: Detect real image location
            $config = getAICliConfig();
            $realImg = rtrim($config['agent_storage_path'] ?? "/boot/config/plugins/unraid-aicliagents", '/') . "/aicli-agents.img";
            if (file_exists($realImg)) $img = $realImg;
            $mnt = "/usr/local/emhttp/plugins/unraid-aicliagents/agents";
            $minSize = 512; // D-170: Increased to 512MB for Btrfs stability
        }

        if (!file_exists($img)) {
            return ['status' => 'error', 'message' => 'Image file not found.'];
        }

        aicli_log("Evaluating shrink for $type...", AICLI_LOG_INFO);
        if (is_dir($mnt)) {
            if ($type === 'agents') {
                if (!aicli_agent_storage_unlock()) {
                    aicli_log("Shrink aborted: Failed to unlock agent storage (RO -> RW)", AICLI_LOG_ERROR);
                    return ['status' => 'error', 'message' => 'Failed to unlock storage for write access.'];
                }
            }

            $df_stats = shell_exec("df -m --output=size " . escapeshellarg($mnt) . " | tail -1");
            $currMb = intval(trim($df_stats ?? '0'));
            
            $storageStats = aicli_get_storage_status();
            $logicalUsed = 0;
            if ($type === 'agents') {
                $logicalUsed = $storageStats['logical_used_mb'] ?? 0;
            } else {
                $user = str_replace('home_', '', $type);
                $logicalUsed = $storageStats['home_stats'][$user]['logical_used_mb'] ?? 0;
            }

            // Calculate Target: Logical Used + 256MB Headroom (Clamped to minSize)
            $targetMb = max($minSize, ceil($logicalUsed + 256));
            
            if ($sizeSub !== 'auto') {
                $reqSubMb = intval(preg_replace('/[^0-9]/', '', $sizeSub));
                $requestedTarget = $currMb - $reqSubMb;
                $targetMb = max($targetMb, $requestedTarget);
            }

            $subMb = $currMb - $targetMb;

            if ($subMb <= 10) { 
                return ['status' => 'error', 'message' => "Storage is already optimized. Minimum safe size is approximately {$targetMb}MB (Contents + 128MB buffer)."];
            }

            aicli_log("Shrinking $type: Current {$currMb}MB -> Target {$targetMb}MB (Reduction: {$subMb}MB)", AICLI_LOG_INFO);
            
            aicli_log("STABILIZER: Aggressively balancing and defragmenting $type...", AICLI_LOG_DEBUG);
            exec("btrfs filesystem defragment -r -czstd " . escapeshellarg($mnt) . " > /dev/null 2>&1");
            // Standard balance to free up empty chunks before resize
            exec("btrfs balance start -dusage=75 -musage=75 " . escapeshellarg($mnt) . " > /dev/null 2>&1");
            // Full metadata balance to ensure all blocks are packed
            exec("btrfs balance start -m " . escapeshellarg($mnt) . " > /dev/null 2>&1");

            aicli_log("Resizing Btrfs filesystem to {$targetMb}M...", AICLI_LOG_INFO);
            exec("btrfs filesystem resize {$targetMb}M " . escapeshellarg($mnt) . " 2>&1", $out, $res);
            if ($res !== 0) {
                return ['status' => 'error', 'message' => 'Btrfs resize failed: ' . end($out)];
            }

            $loop = trim((string)shell_exec("findmnt -n -o SOURCE " . escapeshellarg($mnt)));
            if (!empty($loop) && strpos($loop, '/dev/loop') === 0) {
                exec("truncate -s {$targetMb}M " . escapeshellarg($img) . " 2>&1", $out, $res);
                exec("losetup -c " . escapeshellarg($loop) . " 2>&1");
            }

            aicli_log("Shrink of $type complete.", AICLI_LOG_INFO);
            return ['status' => 'ok', 'message' => 'Storage shrunk successfully.'];
        } else {
            return ['status' => 'error', 'message' => 'Storage filesystem is not mounted.'];
        }
    } finally {
        if ($type === 'agents') aicli_agent_storage_lock();
        flock($fp, LOCK_UN);
        fclose($fp);
        @unlink($lockFile);
    }
}

/**
 * Checks all mounted images and notifies the user if they are nearly full.
 */
function aicli_check_storage_thresholds() {
    $status = aicli_get_storage_status();
    $throttleDuration = 86400; // 24 hours
    
    // Check Agent Binary Storage
    if ($status['status'] === 'mounted') {
        $pct = ($status['used_mb'] / $status['total_mb']) * 100;
        if ($pct > 90) {
            $f = "/boot/config/plugins/unraid-aicliagents/storage_warn_sent.txt";
            $last = file_exists($f) ? (int)file_get_contents($f) : 0;
            if (time() - $last > $throttleDuration) {
                aicli_notify("Agent binaries are using {$status['used_mb']}MB ({$pct}%). Consider expanding storage in settings.", "Agent Storage Warning", "warning");
                file_put_contents($f, time());
            }
        }
    }
    
    // Check User Home Storages
    $homeStats = $status['home_stats'] ?? [];
    foreach ($homeStats as $user => $stats) {
        if ($stats['percent'] > 90) {
            $f = "/boot/config/plugins/unraid-aicliagents/warn_sent_$user.txt";
            $last = file_exists($f) ? (int)file_get_contents($f) : 0;
            if (time() - $last > $throttleDuration) {
                aicli_notify("AI persistence for user '$user' is {$stats['percent']}% full. Consider expansion.", "User Home Storage Warning", "warning");
                file_put_contents($f, time());
            }
        }
    }
}

/**
 * Storage Reservation Helpers for Concurrent Installations
 */
function aicli_add_reservation($agentId, $mb) {
    if (empty($agentId)) return;
    $dir = "/tmp/unraid-aicliagents/reservations";
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    file_put_contents("$dir/$agentId", $mb);
}

function aicli_remove_reservation($agentId) {
    $f = "/tmp/unraid-aicliagents/reservations/$agentId";
    if (file_exists($f)) @unlink($f);
}

function aicli_get_total_reservation() {
    $dir = "/tmp/unraid-aicliagents/reservations";
    $total = 0;
    if (is_dir($dir)) {
        foreach (glob("$dir/*") as $f) {
            $total += (int)file_get_contents($f);
        }
    }
    return $total;
}

function installAgent($agentId) {
    // D-170: Increase execution time for large agent installs/expansions
    @set_time_limit(900); // 15 minutes
    if (empty($agentId)) return ['status' => 'error', 'message' => 'No Agent ID'];

    $lockFile = "/tmp/unraid-aicliagents/install-$agentId.lock";
    if (file_exists($lockFile)) {
        $pid = trim(@file_get_contents($lockFile));
        if (aicli_is_pid_running($pid)) {
             return ['status' => 'error', 'message' => "Installation for $agentId is already in progress (PID $pid)."];
        }
    }
    file_put_contents($lockFile, getmypid());
    
    // Reset status file to avoid stale progress reporting from previous attempts
    setInstallStatus("Initializing...", 5, $agentId);

    aicli_log("installAgent started for $agentId", AICLI_LOG_INFO);
    
    // D-195: Proactively stop active sessions and KILL processes for this agent before upgrade
    // This prevents 'mount busy' errors caused by open file handles to deleted binaries.
    aicli_log("Scanning for active sessions for agent $agentId before install/upgrade...", AICLI_LOG_DEBUG);
    foreach (glob("/var/run/unraid-aicliagents-*.agentid") as $af) {
        $runningAgent = trim(@file_get_contents($af));
        if ($runningAgent === $agentId) {
            $sessId = str_replace(['/var/run/unraid-aicliagents-', '.agentid'], '', $af);
            aicli_log("Stopping active session $sessId for agent $agentId to allow clean upgrade.", AICLI_LOG_INFO);
            stopAICliTerminal($sessId, true);
        }
    }
    
    // D-197: Global kill for any node processes running this agent binary root (Catch orphaned or un-tracked processes)
    exec("pkill -9 -f " . escapeshellarg("/agents/$agentId/node_modules") . " > /dev/null 2>&1");

    aicli_agent_storage_unlock();
    
    try {
        setInstallStatus("Consulting NPM metadata...", 10, $agentId);
        $registry = getAICliAgentsRegistry();
        if (!isset($registry[$agentId])) {
            aicli_log("ERROR: Agent $agentId not found in registry", AICLI_LOG_ERROR);
            return ['status' => 'error', 'error' => 'Agent not found in registry'];
        }
        
        // MANDATORY STORAGE CHECK: Ensure the agent binary storage is mounted.
        if (!aicli_ensure_agent_storage_mounted()) {
            aicli_log("ERROR: Agent storage could not be mounted. Installation aborted to prevent data loss.", AICLI_LOG_ERROR);
            return ['status' => 'error', 'message' => 'Agent storage mount failure. Please check logs.'];
        }
        
        $config = getAICliConfig();
        $usePreview = ($config["preview_$agentId"] ?? "0") === "1";
        aicli_log("Using preview channel: " . ($usePreview ? "yes" : "no"), AICLI_LOG_DEBUG);
        
        $bootConfig = "/boot/config/plugins/unraid-aicliagents";
        $cacheDir = "$bootConfig/pkg-cache";
        $agentBase = "/usr/local/emhttp/plugins/unraid-aicliagents/agents"; // New base for agent installations
        
        if (!is_dir($cacheDir)) mkdir($cacheDir, 0777, true);
        if (!is_dir($agentBase)) mkdir($agentBase, 0777, true);

        $installedVer = "installed";

        // NPM-based agents — D-127: Unified registry mapping
        $package = $registry[$agentId]['npm_package'] ?? null;
        if (!$package) {
            aicli_log("ERROR: No NPM package mapping for $agentId", AICLI_LOG_ERROR);
            return ['status' => 'error', 'error' => 'NPM package mapping missing'];
        }

        $installPackage = $package;
        if ($usePreview) {
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

        $agentDir = "$agentBase/$agentId";
        
        // D-77: Pre-Install Storage Validation (Size Prediction)
        $mntPoint = "/usr/local/emhttp/plugins/unraid-aicliagents/agents";
        $freeMbOutput = shell_exec("df -m " . escapeshellarg($mntPoint) . " | tail -1 | awk '{print $4}'");
        $freeMb = intval(trim($freeMbOutput ?? '0'));
        $pluginDir = "/usr/local/emhttp/plugins/unraid-aicliagents";

        $requiredMb = 256; 
        
        // D-190: Account for existing agent size if upgrading
        $existingSizeMb = 0;
        if (is_dir($agentDir)) {
            $existingSizeMb = intval(trim((string)shell_exec("du -sm " . escapeshellarg($agentDir) . " | cut -f1")));
            aicli_log("Detected existing agent size for $agentId: $existingSizeMb MB", AICLI_LOG_DEBUG);
        }

        setInstallStatus("Fetching package size and dependencies...", 25, $agentId);
        $sizeOutput = [];
        $npmCache = "/tmp/unraid-aicliagents/npm-cache/root";
        if (!is_dir($npmCache)) @mkdir($npmCache, 0777, true);
        exec("export NPM_CONFIG_CACHE=" . escapeshellarg($npmCache) . "; export PATH=$pluginDir/bin:\$PATH; $pluginDir/bin/npm view " . escapeshellarg($package) . " dist.unpackedSize 2>/dev/null", $sizeOutput);
        $unpackedBytes = intval(trim($sizeOutput[0] ?? '0'));
        if ($unpackedBytes > 0) {
            $requiredMb = max(256, ceil(($unpackedBytes / 1024 / 1024) * 3.0)); 
            aicli_log("Size Prediction for $agentId: " . round($unpackedBytes/1024/1024, 2) . " MB unpacked. Requiring $requiredMb MB free (incl. 3x NPM overhead).", AICLI_LOG_DEBUG);
        }

        $totalReservations = aicli_get_total_reservation();
        $storageStats = aicli_get_storage_status();
        $effectiveFreeMb = $storageStats['effective_available_mb'] ?? $freeMb;
        
        $predictedAvailable = ($effectiveFreeMb + $existingSizeMb) - $totalReservations;

        if ($predictedAvailable < $requiredMb) {
            aicli_log("Space tight for $agentId (Effective $predictedAvailable MB available (incl. $existingSizeMb MB existing), $requiredMb MB required). Attempting auto-expansion...", AICLI_LOG_INFO);
            $headroom = aicli_get_flash_headroom();
            $shortfall = $requiredMb - $predictedAvailable;
            $multiple = ceil($shortfall / 256);
            $expansionMb = $multiple * 256;

            $config = getAICliConfig();
            $realImg = rtrim($config['agent_storage_path'] ?? "/boot/config/plugins/unraid-aicliagents", '/') . "/aicli-agents.img";
            $isOnFlash = (strpos($realImg, "/boot/") === 0);

            if (!$isOnFlash || $headroom > ($expansionMb + 150)) {
                 aicli_log("Auto-Expanding Agent storage by {$expansionMb}MB (Multiple of 256MB) to accommodate $agentId...", AICLI_LOG_INFO);
                 aicli_expand_storage('agents', "{$expansionMb}M");
                 $storageStats = aicli_get_storage_status();
                 $effectiveFreeMb = $storageStats['effective_available_mb'] ?? 0;
                 $predictedAvailable = ($effectiveFreeMb + $existingSizeMb) - $totalReservations;
            }
        }

        if ($predictedAvailable < $requiredMb) {
            aicli_log("Aborting install of $agentId: Insufficient space after reservations ($predictedAvailable MB available, $requiredMb MB required)", AICLI_LOG_WARN);
            setInstallStatus("Error: Insufficient Space (Pending: {$totalReservations}MB)", 0, $agentId, 'disk_full');
            return ['status' => 'error', 'reason' => 'disk_full', 'message' => "Insufficient agent storage. Needed: " . $requiredMb . "MB (+{$totalReservations}MB pending). Only $predictedAvailable MB available (Effective)."];
        }

        aicli_add_reservation($agentId, $requiredMb);

        if (is_dir($agentDir)) exec("rm -rf " . escapeshellarg($agentDir));
        mkdir($agentDir, 0777, true);
        
        setInstallStatus("Downloading & Installing agent via NPM...", 50, $agentId);
        $npmCache = "/tmp/unraid-aicliagents/npm-cache/root";
        if (!is_dir($npmCache)) @mkdir($npmCache, 0777, true);
        
        aicli_log("Running Direct Install into /mnt/agents: $pluginDir/bin/npm install (RAM Cached)", AICLI_LOG_DEBUG);
        $npmOutput = [];
        exec("export NPM_CONFIG_CACHE=" . escapeshellarg($npmCache) . "; export PATH=$pluginDir/bin:\$PATH; cd " . escapeshellarg($agentDir) . " && $pluginDir/bin/npm install --prefix " . escapeshellarg($agentDir) . " " . escapeshellarg($installPackage) . " --no-audit --no-fund --quiet 2>&1", $npmOutput, $result);
        aicli_log("NPM finished with code $result.", AICLI_LOG_DEBUG);
        
        if ($result !== 0) {
            $isNoSpace = false;
            foreach ($npmOutput as $line) {
                if (strpos($line, 'ENOSPC') !== false || strpos($line, 'No space left on device') !== false) {
                    $isNoSpace = true;
                    break;
                }
            }
            
            if ($isNoSpace) {
                aicli_log("ERROR: Disk full during agent installation: $agentId (Cleaning up partial files)", AICLI_LOG_ERROR);
                exec("rm -rf " . escapeshellarg($agentDir)); 
                setInstallStatus("Error: Disk Full", 0, $agentId, 'disk_full');
                return ['status' => 'error', 'reason' => 'disk_full', 'message' => 'Storage container is full. Please expand storage in Settings.'];
            }

            aicli_log("ERROR: NPM install failed for $installPackage (Cleaning up)", AICLI_LOG_ERROR);
            exec("rm -rf " . escapeshellarg($agentDir));
            setInstallStatus("Error: Install failed. Check logs.", 0, $agentId);
            return ['status' => 'error', 'error' => 'NPM install failed: ' . (end($npmOutput) ?: 'Unknown error')];
        }

        $pJson = "$agentDir/node_modules/" . str_replace('/', DIRECTORY_SEPARATOR, $package) . "/package.json";
        if (file_exists($pJson)) {
            $pData = json_decode(file_get_contents($pJson), true);
            $installedVer = $pData['version'] ?? $installedVer;
            aicli_log("Detected version from package.json: $installedVer", AICLI_LOG_DEBUG);
        }

        setInstallStatus("Finalizing permissions...", 90, $agentId);
        
        $binary = $registry[$agentId]['binary'] ?? '';
        $fallback = $registry[$agentId]['binary_fallback'] ?? '';
        $exists = (!empty($binary) && file_exists($binary)) || (!empty($fallback) && file_exists($fallback));
        
        if (!$exists) {
            aicli_log("ERROR: Binary missing after direct NPM install ($agentId).", AICLI_LOG_ERROR);
            setInstallStatus("Error: Binary missing. Check logs.", 0, $agentId);
            return ['status' => 'error', 'error' => 'Binary verification failed'];
        }

        exec("chmod -R 755 " . escapeshellarg($agentDir));
        foreach ([$binary, $fallback] as $b) {
            if (!empty($b) && file_exists($b)) {
                exec("chmod +x " . escapeshellarg($b) . " > /dev/null 2>&1");
                exec("chmod +x $(readlink -f " . escapeshellarg($b) . ") > /dev/null 2>&1");
            }
        }

        clearstatcache(true, $agentDir);
        
        setInstallStatus("Finalizing...", 98, $agentId);
        saveAICliVersion($agentId, $installedVer);
        gcPkgCache();
        setInstallStatus("Installation Complete!", 100, $agentId);
        aicli_sync_agents_to_backend();
        aicli_log("Agent $agentId installed successfully", AICLI_LOG_INFO);
        return ['status' => 'ok'];

    } catch (Exception $e) {
        aicli_log("Installation failed for $agentId: " . $e->getMessage(), AICLI_LOG_ERROR);
        return ['status' => 'error', 'message' => $e->getMessage()];
    } finally {
        aicli_remove_reservation($agentId);
        aicli_agent_storage_lock();
        @unlink($lockFile);
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
        aicli_agent_storage_unlock();
        
        // D-83: Stop all active terminal sessions for this agent BEFORE uninstalling
        aicli_log("Scanning for active sessions for agent $agentId before uninstall...", AICLI_LOG_INFO);
        foreach (glob("/var/run/unraid-aicliagents-*.agentid") as $af) {
            $runningAgent = trim(@file_get_contents($af));
            if ($runningAgent === $agentId) {
                $sessId = str_replace(['/var/run/unraid-aicliagents-', '.agentid'], '', $af);
                aicli_log("Stopping active session $sessId for agent $agentId", AICLI_LOG_INFO);
                stopAICliTerminal($sessId, true);
            }
        }

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
            @unlink($cacheFile);
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

        // 4. Prune workspaces associated with this agent
        $wsData = aicli_get_workspaces();
        $sessions = $wsData['sessions'] ?? [];
        $activeId = $wsData['activeId'] ?? null;
        
        $newSessions = [];
        $changed = false;
        foreach ($sessions as $ws) {
            if ($ws['agentId'] === $agentId) {
                $changed = true;
                aicli_log("Pruning workspace session " . ($ws['id'] ?? 'unknown') . " for uninstalled agent $agentId", AICLI_LOG_INFO);
                if ($activeId === ($ws['id'] ?? null)) $activeId = null; 
                continue;
            }
            $newSessions[] = $ws;
        }
        
        if ($changed) {
            aicli_save_workspaces([
                'sessions' => $newSessions,
                'activeId' => $activeId
            ]);
        }

        gcPkgCache();
        aicli_sync_agents_to_backend();
        
        $statusFile = "/tmp/unraid-aicliagents/install-status-$agentId";
        if (file_exists($statusFile)) @unlink($statusFile);

        aicli_log("Agent $agentId uninstalled successfully", AICLI_LOG_INFO);
        return ['status' => 'ok'];

    } catch (Exception $e) {
        aicli_log("Uninstall failed for $agentId: " . $e->getMessage(), AICLI_LOG_ERROR);
        return ['status' => 'error', 'message' => $e->getMessage()];
    } finally {
        aicli_agent_storage_lock();
        @unlink($lockFile);
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
        $current = $currentVersions[$id] ?? "unknown";
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

/**
 * D-60: Migrate all persistent user data to a new base directory.
 * Used when switching from Flash to Array storage.
 */
function aicli_migrate_persistence($newBase) {
    $newBase = rtrim($newBase, '/');
    if (empty($newBase)) return ['status' => 'error', 'message' => 'New path cannot be empty'];
    
    // 1. Check if path is ready
    if (!aicli_is_path_ready($newBase)) {
        return ['status' => 'error', 'message' => "The selected path ($newBase) is not currently available (is the array started?)."];
    }

    $config = getAICliConfig();
    $oldBase = rtrim($config['persistence_base'] ?? "/boot/config/plugins/unraid-aicliagents/persistence", '/');
    if ($oldBase === $newBase) return ['status' => 'ok', 'message' => 'Path is identical, no move needed.'];

    aicli_log("MIGRATION: Starting persistence migration from $oldBase to $newBase", AICLI_LOG_INFO);

    // 2. Stop all background syncs and terminals
    exec("pkill -9 -f 'Periodic sync triggered'");
    
    // 3. Create destination
    if (!is_dir($newBase)) {
        if (!@mkdir($newBase, 0755, true)) {
            return ['status' => 'error', 'message' => "Failed to create destination directory: $newBase"];
        }
    }

    // 4. Perform the move (rsync -avS preserves everything including sparse images)
    $cmd = "rsync -avSL /usr/local/emhttp/plugins/unraid-aicliagents/persistence/ " . escapeshellarg($newBase . "/");
    exec($cmd, $out, $res);

    if ($res !== 0) {
        aicli_log("ERROR: Migration rsync failed (Code $res): " . implode("\n", $out), AICLI_LOG_ERROR);
        return ['status' => 'error', 'message' => "Data copy failed. Check logs."];
    }

    // 5. Update configuration
    saveAICliConfig(['persistence_base' => $newBase]);
    
    aicli_log("MIGRATION: Successfully moved data to $newBase", AICLI_LOG_INFO);
    
    // 6. Optional: Cleanup old data (Keep it for safety? User might want to revert)
    if (strpos($oldBase, "/boot/") === 0) {
        // Only rename if it was on Flash, to keep Flash clean but safe
        @rename($oldBase, $oldBase . ".migrated." . date("Ymd_His"));
    }

    return ['status' => 'ok'];
}

/**
 * Migrate the Agent Binary image (aicli-agents.img) to a new directory.
 */
function aicli_migrate_agent_storage($newBase) {
    if (empty($newBase)) return ['status' => 'error', 'message' => 'New path cannot be empty'];
    $newBase = rtrim($newBase, '/');
    if (!aicli_is_path_ready($newBase)) {
        return ['status' => 'error', 'message' => "The selected target path ($newBase) is not currently available (is the array started?)."];
    }

    $config = getAICliConfig();
    $oldBase = rtrim($config['agent_storage_path'] ?? "/boot/config/plugins/unraid-aicliagents", '/');
    if ($oldBase === $newBase) return ['status' => 'ok', 'message' => 'Path is identical.'];

    $oldImg = "$oldBase/aicli-agents.img";
    $newImg = "$newBase/aicli-agents.img";
    $ramImg = "/tmp/unraid-aicliagents/aicli-agents.img";
    $mntPoint = "/usr/local/emhttp/plugins/unraid-aicliagents/agents";

    if (!file_exists($oldImg)) {
        return ['status' => 'error', 'message' => "Old image not found at $oldImg"];
    }

    aicli_log("MIGRATION: Moving agent storage from $oldBase to $newBase", AICLI_LOG_INFO);

    // 1. Unmount current
    if (aicli_is_mounted($mntPoint)) {
        exec("umount -l " . escapeshellarg($mntPoint));
    }

    // 2. Create destination
    if (!is_dir($newBase)) mkdir($newBase, 0755, true);

    // 3. Move the file
    if (!@rename($oldImg, $newImg)) {
        // Fallback to copy if rename fails across filesystems (D-126: Force sparse support)
        exec("cp --sparse=always " . escapeshellarg($oldImg) . " " . escapeshellarg($newImg) . " && rm " . escapeshellarg($oldImg), $out, $res);
        if ($res !== 0) return ['status' => 'error', 'message' => "Failed to move image file. Check permissions."];
    }

    // 4. Update Config
    saveAICliConfig(['agent_storage_path' => $newBase]);

    // 5. Remount using standard stabilizer (honors Write-Protect settings)
    aicli_ensure_agent_storage_mounted();
    
    return ['status' => 'ok'];
}


/**
 * D-133: Internal helper to get structured compression data.
 */
function aicli_get_btrfs_compression_info($mnt, $logical_used_mb) {
    if (!is_dir($mnt) || $logical_used_mb <= 0) return ['ratio' => 1.0, 'pct' => 100, 'label' => "1.0x"];
    
    // Check compsize
    $compsizePath = "/usr/local/bin/compsize";
    if (!file_exists($compsizePath)) $compsizePath = trim((string)shell_exec("which compsize 2>/dev/null"));
    
    if (!empty($compsizePath) && file_exists($compsizePath)) {
        $out = shell_exec(escapeshellarg($compsizePath) . " -x " . escapeshellarg($mnt) . " | grep 'Total' | tail -1 2>/dev/null");
        if (preg_match('/(\d+[KMG]?)\s+(\d+[KMG]?)\s+(\d+)%/', $out, $cm)) {
            $pct = intval($cm[3]);
            if ($pct <= 0) $pct = 1; 
            $ratio = 100 / $pct;
            return ['ratio' => $ratio, 'pct' => $pct, 'label' => round($ratio, 1) . "x ($pct%)"];
        }
    }
    
    // Fallback: Parse 'btrfs filesystem usage'
    $usageOut = @shell_exec("btrfs filesystem usage -m " . escapeshellarg($mnt) . " 2>/dev/null | grep 'Data,single' | head -1");
    if ($usageOut && preg_match('/Used:(\d+\.?\d*)MiB/', $usageOut, $m)) {
        $phys_mb = (float)$m[1];
        if ($phys_mb > 1) {
            $ratio = $logical_used_mb / $phys_mb;
            $pct = min(100, round(($phys_mb / $logical_used_mb) * 100));
            if ($pct <= 0) $pct = 1;
            return ['ratio' => max(1.0, $ratio), 'pct' => $pct, 'label' => round($ratio, 1) . "x ($pct%)"];
        }
    }
    
    return ['ratio' => 1.0, 'pct' => 100, 'label' => "1.0x"];
}

/**
 * Returns Btrfs compression string (e.g. "1.5x (60%)") for a mount point.
 * @deprecated Use aicli_get_btrfs_compression_info() instead.
 */
function aicli_get_btrfs_compression($mnt, $logical_used_mb) {
    $info = aicli_get_btrfs_compression_info($mnt, $logical_used_mb);
    return $info['label'];
}

/**
 * Deploys the Nginx reverse proxy config for AI CLI Terminal sessions.
 * This maps /webterminal/aicliterm-* to the corresponding Unix sockets.
 */
function aicli_ensure_nginx_config() {
    $confFile = "/etc/nginx/conf.d/unraid-aicliagents.conf";
    $conf = "location ~ ^/webterminal/aicliterm-([^/]+)/ {
    proxy_pass http://unix:/var/run/aicliterm-$1.sock:/;
    proxy_http_version 1.1;
    proxy_set_header Upgrade \$http_upgrade;
    proxy_set_header Connection \"upgrade\";
    proxy_set_header Host \$host;
    proxy_read_timeout 86400;
}
";

    if (!file_exists($confFile) || file_get_contents($confFile) !== $conf) {
        aicli_log("STABILIZER: Restoring Nginx Proxy configuration...", AICLI_LOG_INFO);
        @file_put_contents($confFile, $conf);
        // D-128: Background the Nginx reload to prevent installer hangs if Nginx is slow to respond
        exec("nohup /usr/sbin/nginx -s reload >> /tmp/unraid-aicliagents/debug.log 2>&1 &");
    }
}
/**
 * Session Migration Bridge: Merges root sessions into the current user's home.
 */
function aicli_migrate_root_sessions($targetUser = '') {
    if (empty($targetUser)) {
        $config = getAICliConfig();
        $targetUser = $config['user'] ?? 'aicliagent';
    }
    if ($targetUser === 'root') return ['status' => 'error', 'message' => 'Target user is already root.'];

    aicli_log("MIGRATION: Importing sessions from root to $targetUser...", AICLI_LOG_INFO);
    
    $rootPersist = "/boot/config/plugins/unraid-aicliagents/persistence/root/home";
    $targetMnt = aicli_get_work_dir($targetUser);

    if (!is_dir($rootPersist)) return ['status' => 'error', 'message' => 'Root persistence directory not found.'];
    if (!aicli_is_mounted(dirname($targetMnt))) aicli_init_working_dir($targetUser);

    // D-153: Targeted rsync of all agent-specific metadata folders
    $metadataDirs = [".local/share/aicliagents", ".gemini", ".opencode", ".claude-code", ".aider", ".kilocode"];
    $migratedCount = 0;

    foreach ($metadataDirs as $dir) {
        $src = "$rootPersist/$dir/";
        $dest = "$targetMnt/$dir/";
        
        if (is_dir($src)) {
            if (!is_dir($dest)) mkdir($dest, 0700, true);
            exec("rsync -av --ignore-existing " . escapeshellarg($src) . " " . escapeshellarg($dest) . " 2>&1");
            $migratedCount++;
        }
    }
    
    if ($migratedCount > 0) {
        // Fix ownership for the NEW user on the RAM mount
        exec("chown -R " . escapeshellarg($targetUser) . ":users " . escapeshellarg($targetMnt . "/.local") . " > /dev/null 2>&1");
        exec("chown -R " . escapeshellarg($targetUser) . ":users " . escapeshellarg($targetMnt . "/.gemini") . " > /dev/null 2>&1");
        aicli_log("MIGRATION: Successfully imported $migratedCount session folders from root to $targetUser.", AICLI_LOG_INFO);
        return ['status' => 'ok', 'message' => "Successfully imported $migratedCount session folders."];
    }
    
    return ['status' => 'error', 'message' => 'No session data found to import in root home.'];
}
/**
 * D-158: Metadata Healing - Ensures agent metadata in workspaces is accessible.
 * Sets ownership to nobody:users (99:100) for external SMB compatibility.
 */
function aicli_fix_workspace_permissions($path, $agentId) {
    if (empty($path) || $path === '/' || strpos($path, '/mnt/user') !== 0) return;
    
    $metadataDirs = [".gemini", ".aicliagents", ".opencode", ".claude-code", ".aider", ".kilocode", ".local/share/aicliagents"];
    foreach ($metadataDirs as $dir) {
        $target = rtrim($path, '/') . "/$dir";
        if (is_dir($target)) {
            aicli_log("STABILIZER: Aligning permissions for $target to nobody:users...", AICLI_LOG_DEBUG);
            exec("chown -R nobody:users " . escapeshellarg($target) . " > /dev/null 2>&1");
            exec("chmod -R 775 " . escapeshellarg($target) . " > /dev/null 2>&1");
        }
    }
}
