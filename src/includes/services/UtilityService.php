<?php
/**
 * <module_context>
 *     <name>UtilityService</name>
 *     <description>Common helper functions for AICliAgents.</description>
 *     <dependencies>LogService</dependencies>
 *     <constraints>Under 150 lines. General purpose utilities.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class UtilityService {
    /**
     * Executes a command in the background.
     */
    public static function execBg($cmd) {
        LogService::log("Spawning background process: $cmd", LogService::LOG_DEBUG, "UtilityService");
        exec("nohup $cmd > /dev/null 2>&1 &");
    }

    /**
     * Checks if a specific PID is still running on the system.
     */
    public static function isPidRunning($pid) {
        if (empty($pid) || !is_numeric($pid)) return false;
        if (function_exists('posix_kill')) return @posix_kill((int)$pid, 0);
        exec("kill -0 " . escapeshellarg($pid) . " 2>/dev/null", $output, $result);
        return $result === 0;
    }

    /**
     * Sends a GUI notification to the Unraid dashboard.
     */
    public static function notify($message, $subject = "AICliAgents") {
        $msg = escapeshellarg($message);
        $sub = escapeshellarg($subject);
        exec("/usr/local/emhttp/plugins/dynamix/scripts/notify -e \"AICliAgents\" -s $sub -m $msg -i \"tasks\"");
    }

    /**
     * Returns a list of real Unraid users (excluding system accounts).
     */
    public static function getUnraidUsers() {
        $users = [];
        $passwd = file_get_contents('/etc/passwd');
        if ($passwd) {
            foreach (explode("\n", $passwd) as $line) {
                if (empty($line)) continue;
                $parts = explode(':', $line);
                $uid = (int)$parts[2];
                // Standard Unraid users are usually 1000+ or specifically root
                if ($uid === 0 || ($uid >= 1000 && $uid < 60000)) {
                    $users[] = $parts[0];
                }
            }
        }
        return $users;
    }

    /**
     * Creates a new Unraid user.
     */
    public static function createUser($username, $password, $description = "") {
        if (empty($username) || empty($password)) return ['status' => 'error', 'message' => 'Username/Password required'];
        
        $cmd = "/usr/local/sbin/useradd -m -g users -s /bin/bash -c " . escapeshellarg($description) . " " . escapeshellarg($username);
        exec($cmd, $out, $res);
        if ($res !== 0) return ['status' => 'error', 'message' => 'Failed to create user: ' . implode(" ", $out)];
        
        $passCmd = "echo " . escapeshellarg($username . ":" . $password) . " | chpasswd";
        exec($passCmd, $out, $res);
        if ($res !== 0) return ['status' => 'error', 'message' => 'Failed to set password'];
        
        return ['status' => 'ok'];
    }

    /**
     * Efficiently tails a file.
     */
    public static function tail($file, $lines = 100) {
        if (!file_exists($file)) return [];
        $output = [];
        exec("tail -n " . (int)$lines . " " . escapeshellarg($file) . " 2>&1", $output);
        return $output;
    }

    /**
     * Returns the workspace directory for a user in RAM.
     */
    public static function getWorkDir($user) {
        if (empty($user)) $user = 'root';
        return "/tmp/unraid-aicliagents/work/" . $user;
    }

    /**
     * Executes a command and streams output to a callback (real-time feedback).
     */
    public static function execStreaming($cmd, $callback) {
        $descriptorspec = [
            0 => ["pipe", "r"], // stdin
            1 => ["pipe", "w"], // stdout
            2 => ["pipe", "w"]  // stderr
        ];

        $process = proc_open($cmd, $descriptorspec, $pipes);
        if (is_resource($process)) {
            while ($line = fgets($pipes[1])) {
                $callback(trim($line), false);
            }
            while ($line = fgets($pipes[2])) {
                $callback(trim($line), true);
            }
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            return proc_close($process);
        }
        return -1;
    }

    /**
     * Clears any existing installation status files for an agent or all agents.
     */
    public static function clearInstallStatus($agentId = '') {
        $dir = "/tmp/unraid-aicliagents";
        if (empty($agentId)) {
            foreach (glob("$dir/install-status*") as $file) {
                @unlink($file);
            }
        } else {
            @unlink("$dir/install-status-$agentId");
        }
    }

    /**
     * Updates the installation status file for frontend polling.
     */
    public static function setInstallStatus($message, $progress, $agentId = '', $reason = '') {
        $dir = "/tmp/unraid-aicliagents";
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $file = empty($agentId) ? "$dir/install-status" : "$dir/install-status-$agentId";
        $status = [
            'step' => $message,
            'status_text' => $message,
            'progress' => $progress,
            'completed' => ($progress >= 100),
            'timestamp' => time(),
            'reason' => $reason
        ];
        @file_put_contents($file, json_encode($status));
        // D-402: Publish install progress via Nchan for real-time UI updates
        if (!empty($agentId)) {
            NchanService::publishInstallProgress($agentId, $progress, $message, $reason);
        }
    }

    /**
     * Updates a generic task status file for maintenance progress.
     */
    public static function setTaskStatus($user, $message, $progress, $reason = '') {
        $dir = "/tmp/unraid-aicliagents";
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $file = "$dir/task-status-$user";
        $status = [
            'step' => $message,
            'status_text' => $message,
            'progress' => $progress,
            'completed' => ($progress >= 100),
            'timestamp' => time(),
            'reason' => $reason
        ];
        @file_put_contents($file, json_encode($status));
    }

    /**
     * Path Helpers for Terminal Sessions
     */
    public static function getSockPath($id = 'default') {
        return "/var/run/aicliterm-$id.sock";
    }

    public static function getPidPath($id = 'default') {
        return "/var/run/unraid-aicliagents-$id.pid";
    }

    public static function getChatIdPath($id = 'default') {
        return "/var/run/unraid-aicliagents-$id.chatid";
    }

    public static function getAgentIdPath($id = 'default') {
        return "/var/run/unraid-aicliagents-$id.agentid";
    }

    public static function getWorkDirFilePath($id = 'default') {
        return "/var/run/unraid-aicliagents-$id.workdir";
    }
}
