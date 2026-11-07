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
     * TERMINAL_FAST_ATTACH.md S5: the `php` flags that give a command-line plugin script the PHP
     * file cache (the same flags and the same checks as `_aicli_php_cache_init` in aicli-shell.sh).
     * PHP runs any code it finds in the cache, and the state root is mode 1777, so the folder is
     * checked at every call: <state root> and <state root>/opcache are real directories (no
     * symlink); <state root>/opcache is owned by root and not writable by group or other; and
     * <state root>/opcache/<euid> is a real directory owned by the caller, set to mode 0700.
     * Root makes the folders that are missing. When any check fails the list is empty and PHP runs
     * without the cache, as before. Nothing in Unraid's php.ini changes, and a generation folder
     * never changes, so a cached file cannot serve old code (validate_timestamps stays on).
     *
     * @return string[]
     */
    public static function phpCacheFlags(): array {
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : 0;
        $root = rtrim(SecretPaths::stateRoot(), '/');
        $base = $root . '/opcache';
        $dir = $base . '/' . $uid;
        if ($uid === 0) {
            self::ensurePhpCacheDirFor(0);
        }
        clearstatcache(true);
        if (is_link($root)) return [];
        $b = @lstat($base);
        $d = @lstat($dir);
        if ($b === false || $d === false) return [];
        if (($b['mode'] & 0170000) !== 0040000 || $b['uid'] !== 0 || ($b['mode'] & 0022) !== 0) return [];
        if (($d['mode'] & 0170000) !== 0040000 || $d['uid'] !== $uid) return [];
        if (($d['mode'] & 0777) !== 0700 && !@chmod($dir, 0700)) return [];
        if (!is_writable($dir)) return [];
        return ['-d', 'opcache.enable_cli=1', '-d', 'opcache.file_cache=' . $dir, '-d', 'opcache.file_cache_only=1',
                '-d', 'opcache.validate_timestamps=1'];
    }

    /**
     * Root only: make <state root>/opcache (owned by root, mode 0711) and <state root>/opcache/<uid>
     * (owned by that uid, mode 0700) when they are missing. It never follows a symlink and never
     * takes over a folder that exists with another owner: such a folder stays and is refused by the
     * check in phpCacheFlags(). The launch calls it for the user an agent runs as, because only root
     * can make a folder inside the root-owned parent.
     */
    public static function ensurePhpCacheDirFor(int $uid): void {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) return;
        $root = rtrim(SecretPaths::stateRoot(), '/');
        if (is_link($root)) return;
        $base = $root . '/opcache';
        if (!file_exists($base) && !is_link($base)) {
            @mkdir($base, 0711, true);
            @chmod($base, 0711);
        }
        $b = @lstat($base);
        if ($b === false || ($b['mode'] & 0170000) !== 0040000 || $b['uid'] !== 0) return;
        $dir = $base . '/' . $uid;
        if (!file_exists($dir) && !is_link($dir) && @mkdir($dir, 0700)) {
            @chown($dir, $uid);
            @chmod($dir, 0700);
        }
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
        // R-111: the child must not inherit the workspace id of the request that starts it.
        // A daemon that carried AICLI_SESSION_ID was reaped (by its environment) when that
        // workspace closed. A caller that needs the id sets it on the command itself.
        $unset = 'env -u AICLI_SESSION_ID ';
        if (is_file($helper)) {
            $line = $unset . 'bash ' . escapeshellarg($helper) . ' --log ' . escapeshellarg($log)
                . ($truncate ? ' --truncate' : '') . ' -- ' . $args . ' 2>/dev/null';
        } else {
            // Degraded fallback (helper missing from a broken tree): still a new
            // session and no installer descriptors.
            $line = $unset . 'setsid nohup ' . $args . ' </dev/null ' . ($truncate ? '>' : '>>') . escapeshellarg($log)
                . ' 2>&1 3>&- 4>&- 9>&- & echo $!';
        }
        $out = []; $rc = 0;
        // nosemgrep: php.lang.security.exec-use.exec-use — every argument is escapeshellarg()-quoted
        @exec($line, $out, $rc);
        $pid = trim((string)($out[0] ?? ''));
        return ctype_digit($pid) ? (int)$pid : 0;
    }

    /**
     * #880: the command-line PHP for a detached worker or a git filter.
     *
     * Inside the web server PHP_BINARY is php-fpm, not a command-line PHP. A worker started
     * with it prints the php-fpm usage text and exits, and no job ever runs. The command
     * line PHP of the host is /usr/bin/php (AutoLaunchService launches its worker the same way).
     * Only a command-line process may use its own PHP_BINARY, and only when that is not an fpm binary.
     *
     * Seams (tests): sapi, binary, usr_bin_executable (bool).
     */
    public static function cliPhpBinary(array $seams = []): string {
        $cli = '/usr/bin/php';
        $sapi = (string)($seams['sapi'] ?? PHP_SAPI);
        $binary = (string)($seams['binary'] ?? PHP_BINARY);
        $usrBin = array_key_exists('usr_bin_executable', $seams) ? (bool)$seams['usr_bin_executable'] : is_executable($cli);
        if ($usrBin) return $cli;
        if ($sapi === 'cli' && $binary !== '' && stripos(basename($binary), 'fpm') === false) return $binary;
        return $cli;
    }

    /**
     * #880: the one launcher of a detached PHP worker (the storage engine change and the writable-layer move).
     * It runs the script with the command-line PHP and returns the process id, or 0 when nothing started.
     * Seams (tests): php (see cliPhpBinary), spawn_detached (callable(array argv, string log, bool truncate): int).
     *
     * @param list<string> $args
     */
    public static function launchPhpWorker(string $script, array $args, string $log, array $seams = []): int {
        $argv = array_merge([self::cliPhpBinary((array)($seams['php'] ?? [])), $script], array_map('strval', $args));
        $spawn = $seams['spawn_detached'] ?? static fn(array $a, string $l, bool $t): int => self::spawnDetached($a, $l, $t);
        return (int)$spawn($argv, $log, true);
    }

    /**
     * #880: wait up to 20 seconds (a loaded host reached load 188; a longer wait avoids a false failure) until $started() is true. A detached worker that exits at once never
     * records that it runs, and the caller then marks its job as failed. Seams (tests): worker_wait_seconds,
     * clock (callable(): float), worker_sleep (callable(int microseconds)).
     */
    public static function awaitWorkerStart(callable $started, array $seams = []): bool {
        $seconds = (float)($seams['worker_wait_seconds'] ?? 20.0);
        $clock = $seams['clock'] ?? static fn(): float => microtime(true);
        $sleep = $seams['worker_sleep'] ?? static function (int $microseconds): void { usleep($microseconds); };
        $deadline = $clock() + $seconds;
        do {
            if ($started()) return true;
            if ($clock() >= $deadline) break;
            $sleep(200000);
        } while (true);
        return (bool)$started();
    }

    /**
     * #880 review: every account name in /etc/passwd whatever its UID, or [] when the file cannot be read.
     * @return list<string>
     */
    public static function getAllPasswdUsers(): array {
        $passwd = @file_get_contents('/etc/passwd');
        if (!is_string($passwd) || $passwd === '') return [];
        $users = [];
        foreach (explode("\n", $passwd) as $line) {
            $name = strstr($line, ':', true);
            if ($name !== false && $name !== '' && $line[0] !== '#') $users[] = $name;
        }
        return $users;
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
        return rtrim(SecretPaths::stateRoot(), '/') . "/work/" . $user;
    }

    /**
     * #549: npm keeps its download cache in ~/.npm. For root on Unraid that is the
     * RAM root disk, and nothing ever emptied it: every agent install, upgrade and
     * version check added to it until the next reboot (1.9 GB on prod02 after one
     * night of agent tests). Each npm call gets its own cache folder from here,
     * and removes it with removeNpmCacheDir() when it ends. A folder that a killed
     * call left behind is removed after a day. AICLI_NPM_CACHE_BASE moves the
     * base folder (tests).
     */
    public static function npmCacheDir(string $tag): string {
        $base = self::npmCacheBase();
        @mkdir($base, 0700, true);
        foreach ((array)@glob($base . '/*', GLOB_ONLYDIR) as $old) {
            if (is_string($old) && @filemtime($old) < time() - 86400) self::removeNpmCacheDir($old);
        }
        $safe = preg_replace('/[^A-Za-z0-9_.-]/', '_', $tag);
        $dir = $base . '/' . $safe . '-' . getmypid() . '-' . bin2hex(random_bytes(4));
        @mkdir($dir, 0700, true);
        return $dir;
    }

    /** Removes a folder that npmCacheDir() gave; refuses any other path. */
    public static function removeNpmCacheDir(string $dir): void {
        $base = self::npmCacheBase();
        $real = realpath($dir);
        if ($real === false || strpos($real, rtrim((string)realpath($base), '/') . '/') !== 0) return;
        exec('rm -rf ' . escapeshellarg($real));
    }

    private static function npmCacheBase(): string {
        $env = getenv('AICLI_NPM_CACHE_BASE');
        return rtrim(is_string($env) && $env !== '' ? $env : SecretPaths::stateRoot() . '/npm-cache', '/');
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
        $dir = SecretPaths::stateRoot();
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
        $dir = SecretPaths::stateRoot();
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
            // ACTIVITY_TRAY_PLAIN_NOTICES.md R1/R5/R8: the tray gets the agent's
            // display name and plain step words. The install-status file and the
            // Nchan event above keep the raw message for the Store card.
            $title = self::trayInstallTitle(self::trayAgentName((string)$agentId), false);
            if ($progress >= 100) {
                ActivityService::finish($opId, self::trayInstallStep((string)$message));
            } elseif ($progress <= 0) {
                // The raw reason (it can hold a reason code, a PHP path or a stack
                // trace) goes to the log. The tray gets one sentence.
                LogService::log("Install of $agentId failed: $message" . ((string)$reason !== '' ? " — $reason" : ''), LogService::LOG_ERROR, "UtilityService");
                $f = self::trayInstallFailure((string)$message, (string)$reason);
                ActivityService::fail($opId, $f['error'], $f['recovery'], [
                    'type' => 'install', 'label' => $title, 'step' => '',
                ]);
            } else {
                ActivityService::update($opId, [
                    'type'     => 'install',
                    'label'    => $title,
                    'step'     => self::trayInstallStep((string)$message),
                    'progress' => (int)$progress,
                ]);
            }
        }
    }

    /**
     * The agent's display name for a tray title ("Claude Code", not "claude-code").
     * Falls back to the id when the registry has no entry
     * (ACTIVITY_TRAY_PLAIN_NOTICES.md R4).
     */
    public static function trayAgentName(string $agentId): string {
        try {
            $reg = AgentRegistry::getRegistry();
            $n = is_array($reg[$agentId] ?? null) ? trim((string)($reg[$agentId]['name'] ?? '')) : '';
            if ($n !== '') return $n;
        } catch (\Throwable $e) {
        }
        return $agentId;
    }

    /**
     * The tray title of an install row: "Installing Claude Code" or
     * "Upgrading Claude Code to 2.1.4" (ACTIVITY_TRAY_PLAIN_NOTICES.md R1).
     * Pure: no I/O. $version null or empty gives "Upgrading Claude Code".
     * A first install with a chosen version gives "Installing Claude Code (version 2.1.4)" (#927).
     */
    public static function trayInstallTitle(string $agentName, bool $isUpgrade, ?string $version = null): string {
        $version = $version === null ? '' : trim($version);
        if (!$isUpgrade) return $version !== '' ? "Installing $agentName (version $version)" : "Installing $agentName";
        return $version !== '' ? "Upgrading $agentName to $version" : "Upgrading $agentName";
    }

    /**
     * The grey line of an installed upgrade that waits for open workspaces to
     * close before it switches (ACTIVITY_TRAY_PLAIN_NOTICES.md row 12). Pure.
     */
    public static function trayInstalledWaitStep(int $openWorkspaces): string {
        if ($openWorkspaces <= 0) return 'Installed — waiting for the old version to stop before it switches';
        return 'Installed — waiting for ' . $openWorkspaces . ' workspace' . ($openWorkspaces === 1 ? '' : 's')
            . ' to close before it switches';
    }

    /**
     * An install step message, in the plain words the tray shows
     * (ACTIVITY_TRAY_PLAIN_NOTICES.md R8, row 9-11). Pure: no I/O.
     * The message stays raw in the install-status file for the Store card.
     */
    public static function trayInstallStep(string $message): string {
        $m = trim($message);
        if ($m === '') return '';
        $map = [
            // [prefix, words]; the first prefix that matches wins.
            ['Installed — waiting for', null],              // already plain (install-bg.php row 12)
            ['Installation complete', ''],                  // the pill says it
            ['Emergency install complete', ''],
            ['Upgrade starting', 'Starting'],
            ['Starting installation job', 'Starting'],
            ['Starting queued upgrade', 'Starting'],
            ['Starting emergency install', 'Starting'],
            ['Queued — waiting for another agent upgrade', 'Waiting for another agent upgrade to finish'],
            ['Upgrade queued — saving workspace data', 'Saving workspace data first'],
            ['Upgrade queued', null],                       // PendingAgentUpgradeService words, already plain
            ['Backing up current version', 'Backing up the current version'],
            ['Preparing emergency install', 'Preparing storage'],
            ['Preparing storage', 'Preparing storage'],
            ['Storage ready', 'Preparing storage'],
            ['Starting install', 'Preparing the install'],
            ['Resolving release', 'Finding the release'],
            ['Fetching install script', 'Downloading'],
            ['Downloading', 'Downloading'],
            ['Fetching packages', 'Downloading'],
            ['Extracting', 'Unpacking'],
            ['Linking dependencies', 'Installing'],
            ['Installing:', 'Installing'],
            ['Finalizing permissions', 'Setting permissions'],
            ['Setting permissions', 'Setting permissions'],
            ['Baking SquashFS delta', 'Saving the new version'],
            ['Activating upgraded version', 'Switching to the new version'],
        ];
        foreach ($map as [$prefix, $words]) {
            if (stripos($m, $prefix) === 0) return $words === null ? $m : $words;
        }
        if (preg_match('/^Installing .+ to RAM/i', $m)) return 'Installing';
        // "Running install script… 12s · last output line" -> "Running the installer (12 s)"
        if (stripos($m, 'Running install script') === 0) {
            return preg_match('/^Running install script\D*?(\d+)\s*s\b/u', $m, $mm)
                ? "Running the installer ({$mm[1]} s)"
                : 'Running the installer';
        }
        return $m;
    }

    /**
     * The red sentence and the recovery sentence of a failed install
     * (ACTIVITY_TRAY_PLAIN_NOTICES.md R5, rows 11). Pure: no I/O. One plain
     * sentence; no reason code, no PHP path, no stack trace. The detail is in
     * the Debug Console, which the recovery sentence names.
     * @return array{error:string,recovery:?string}
     */
    public static function trayInstallFailure(string $message, string $reason): array {
        $console = 'Details are in the Debug Console.';
        $m = strtolower(trim($message));
        // A caller that already wrote the sentence (install-bg.php fatal path).
        if ($reason !== '' && preg_match('/^(.{1,200}?\.)\s*Details are in the Debug Console\.?$/s', $reason, $mm)) {
            return ['error' => $mm[1], 'recovery' => $console];
        }
        if ($reason !== '' && stripos($reason, 'not enough free space') !== false) {
            return ['error' => 'Not enough free space for the new version; the version in use is kept.',
                    'recovery' => 'Free some space, then try again.'];
        }
        if (strpos($m, 'storage error') === 0) return ['error' => 'The storage for the agent could not be mounted.', 'recovery' => $console];
        if (strpos($m, 'release channel') === 0) return ['error' => 'The version to install could not be chosen.', 'recovery' => $console];
        if (strpos($m, 'no install source') === 0) return ['error' => 'This agent has no install source.', 'recovery' => $console];
        if (strpos($m, 'install failed (bake') === 0) return ['error' => 'The new version could not be saved.', 'recovery' => $console];
        if (strpos($m, 'backup failed') === 0) return ['error' => 'The backup of the current version failed, so the upgrade stopped and the current version is kept.', 'recovery' => $console];
        if (strpos($m, 'emergency install not available') === 0) return ['error' => 'An emergency install is not possible for this agent.', 'recovery' => 'Start the array and try again.'];
        if (strpos($m, 'emergency install failed') === 0) return ['error' => 'The emergency install did not finish.', 'recovery' => $console];
        if (strpos($m, 'fatal error') === 0) return ['error' => 'The installer stopped with an error.', 'recovery' => $console];
        return ['error' => 'The installer did not finish.', 'recovery' => $console];
    }

    /**
     * Updates a generic task status file for maintenance progress.
     */
    public static function setTaskStatus($user, $message, $progress, $reason = '') {
        $dir = SecretPaths::stateRoot();
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
