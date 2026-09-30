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
     * Expand a leading `~` (agent home) in a user-supplied path, like the shell — so a
     * `~/.claude/secrets/prod01.env` clicked in the terminal resolves even when the
     * client didn't expand it (e.g. a stale page that never received window.aicli_home).
     * The home is the agent shells' $HOME = dirname(ConfigService::getUserStatePath()).
     * A non-`~` path is returned unchanged; validation/allowlisting happens downstream.
     */
    public static function expandAgentHome($path) {
        if (!is_string($path)) return $path;
        if ($path === '~' || strncmp($path, '~/', 2) === 0) {
            $home = rtrim(dirname(ConfigService::getUserStatePath()), '/');
            return $path === '~' ? $home : $home . substr($path, 1);
        }
        return $path;
    }

    /**
     * Executes a command in the background, fully detached (#337).
     * $cmd is a shell command line; it runs under `bash -c`.
     */
    public static function execBg($cmd) {
        LogService::log("Spawning background process: $cmd", LogService::LOG_DEBUG, "UtilityService");
        self::spawnDetached(['/bin/bash', '-c', (string)$cmd]);
    }

    /** The shared spawn helper of THIS plugin generation (#337). */
    public static function detachHelperPath(): string {
        return dirname(__DIR__, 2) . '/scripts/aicli-detach.sh';
    }

    /**
     * #337: start a long-lived background process fully detached from this PHP
     * process: a new session, stdin from /dev/null, stdout/stderr to $log, and
     * no inherited descriptor (every fd above 2 is closed). A plain
     * `nohup ... &` keeps every descriptor PHP holds; during `plugin install`
     * one of them is the installer's output pipe, and Unraid's plugin command
     * waits for EOF on it forever. Every PHP spawn site uses this method.
     *
     * @param string[] $argv the command and its arguments (not a shell line)
     * @return int the pid of the started process, or 0 when none was reported
     */
    public static function spawnDetached(array $argv, string $log = '/dev/null', bool $truncate = false): int {
        $args = implode(' ', array_map('escapeshellarg', array_map('strval', $argv)));
        $helper = self::detachHelperPath();
        if (is_file($helper)) {
            $line = 'bash ' . escapeshellarg($helper) . ' --log ' . escapeshellarg($log)
                . ($truncate ? ' --truncate' : '') . ' -- ' . $args . ' 2>/dev/null';
        } else {
            // Degraded fallback (helper missing from a broken tree): still a new
            // session and no installer descriptors.
            $line = 'setsid nohup ' . $args . ' </dev/null ' . ($truncate ? '>' : '>>') . escapeshellarg($log)
                . ' 2>&1 3>&- 4>&- 9>&- & echo $!';
        }
        $out = []; $rc = 0;
        // nosemgrep: php.lang.security.exec-use.exec-use — every argument is escapeshellarg()-quoted
        @exec($line, $out, $rc);
        $pid = trim((string)($out[0] ?? ''));
        return ctype_digit($pid) ? (int)$pid : 0;
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
        try { AgentRelayService::publishUnraidNotification((string)$message, (string)$subject); } catch (\Throwable $e) { /* Relay must never block a UI notification. */ }
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
            EventBus::publishInstallProgress($agentId, $progress, $message, $reason);
            // T-08 (ACTIVITY_TRAY.md): mirror every install step into the activity
            // registry. This is the single choke point all install/upgrade/emergency
            // steps flow through, so the heartbeat refreshes per step — the legacy
            // install-status file + per-agent Nchan channel are kept unchanged.
            // Convention (matches the frontend pollers): progress<=0 = failure,
            // progress>=100 = completion, anything else = a live step.
            $opId = "install_$agentId";
            if ($progress >= 100) {
                ActivityService::finish($opId, (string)$message);
            } elseif ($progress <= 0) {
                ActivityService::fail($opId, $reason !== '' ? (string)$reason : (string)$message, null, [
                    'type' => 'install', 'label' => "Installing $agentId", 'step' => (string)$message,
                ]);
            } else {
                ActivityService::update($opId, [
                    'type'     => 'install',
                    'label'    => "Installing $agentId",
                    'step'     => (string)$message,
                    'progress' => (int)$progress,
                ]);
            }
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

    public static function getUserIdPath($id = 'default') {
        return "/var/run/unraid-aicliagents-$id.user";
    }
}
