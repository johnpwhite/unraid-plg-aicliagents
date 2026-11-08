<?php

declare(strict_types=1);

namespace AICliAgents\Services;

/**
 * <module_context>
 *     <name>RunUserService</name>
 *     <description>The restricted run user aicli-run for script runs (docs/specs/AGENT_RUN_ENTRY_POINT.md R14 to R18b, issue #745). It reads Unraid's user files to say whether the account is ready (the plugin never edits them), warns about SMB access, keeps the private task folder of a run, checks the report file that the run writes, and hands folders to the run user in a safe way.</description>
 *     <dependencies>SecretPaths, ConfigService (none at load time)</dependencies>
 *     <constraints>This code runs as root and touches folders that the run user can write. Rules: (1) root never writes inside a folder that the run user owns; root writes only while the folder is root owned, and then hands it over once; (2) root never reads or removes a file or tree in a folder that the run user owns by itself: it asks a child process that runs as the run user (with a time limit), so a swapped link or a FIFO cannot make root read, write or hang; (3) a mode is set at creation (umask), never by chmod on a name; (4) every check is repeated with lstat after the action.</constraints>
 * </module_context>
 */
final class RunUserService
{
    public const USER = 'aicli-run';
    public const REPORT_LIMIT_BYTES = 8192;
    public const REPORT_LIMIT_CHARS = 2000;
    /** The seconds that a child process as the run user may take. */
    public const CHILD_SECONDS = 20;

    /** The files that a private task folder may hold. */
    public const TASK_FILES = ['TASK.md', 'PROMPT.once', 'DATA.md', 'RESULT.json', 'readonly-mcp.json'];

    /** Test-only filesystem and process seams. They work only under PHPUnit (AICLI_TEST_TMP). */
    public static ?array $pathsForTests = null;
    /** @var int|null Replaces the effective user id of aicli-run-report in tests. */
    public static ?int $effectiveUidForTests = null;
    /** @var callable|null fn(string $file, int $uid, int $max): ?string  Replaces the child reader. */
    public static $readerForTests = null;
    /** @var callable|null fn(string $dir, int $uid): void  Replaces the removal of a tree that the run user owns. */
    public static $removerForTests = null;
    /** @var callable|null fn(array $argv): int  Replaces the child that prepares the home as the run user. */
    public static $childRunnerForTests = null;
    /** @var callable|null fn(string $path, string $stage): void  Swaps a legacy marker at a migration boundary. */
    public static $legacySigninSwapForTests = null;
    /** @var callable|null fn(int $pid): void  Replaces the kill of a process of the run user. */
    public static $killerForTests = null;
    /** @var callable|null fn(array $argv): int  Replaces the iptables calls of the network guard. */
    public static $guardRunnerForTests = null;
    /** @var callable|null fn(string $action, string $workspaceId): ?string  Replaces cgroup and network helpers in tests. */
    public static $runtimeRunnerForTests = null;

    private static function testPaths(): ?array
    {
        return defined('AICLI_TEST_TMP') ? self::$pathsForTests : null;
    }

    private static function path(string $key, string $fallback): string
    {
        return (string)(self::testPaths()[$key] ?? $fallback);
    }

    // ======================================================================
    // R15: is the account ready? Reads the user files only.
    // ======================================================================

    /** @return array{ready:bool,fault:string,uid:?int,gid:?int,user:string} */
    public static function status(): array
    {
        $passwdFile = self::path('passwd', '/etc/passwd');
        $groupFile = self::path('group', '/etc/group');
        $user = null;
        foreach (@file($passwdFile, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $fields = explode(':', $line);
            if (($fields[0] ?? '') === self::USER && count($fields) >= 7) {
                $user = $fields;
                break;
            }
        }
        if ($user === null) return self::fault('The run user aicli-run is missing. Create it on the Users page.', null, null);
        $uid = ctype_digit((string)$user[2]) ? (int)$user[2] : null;
        $gid = ctype_digit((string)$user[3]) ? (int)$user[3] : null;
        if ($uid === null || $uid < 1000) return self::fault('The run user needs a uid of 1000 or more.', $uid, $gid);
        if ($gid !== 100) return self::fault('The run user must use the users group (gid 100).', $uid, $gid);
        if ((string)$user[6] !== '/bin/false') return self::fault('The run user shell must be /bin/false.', $uid, $gid);

        $usersGroupFound = false;
        foreach (@file($groupFile, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $fields = explode(':', $line);
            if (count($fields) < 4) continue;
            if ((string)$fields[0] === 'users' && (string)$fields[2] === '100') $usersGroupFound = true;
            $members = array_filter(array_map('trim', explode(',', (string)$fields[3])));
            if (in_array(self::USER, $members, true) && (string)$fields[0] !== 'users') {
                return self::fault('The run user must not belong to the ' . (string)$fields[0] . ' group.', $uid, $gid);
            }
        }
        if (!$usersGroupFound) return self::fault('The users group with gid 100 is missing.', $uid, $gid);
        $sudoers = self::path('sudoers', '/etc/sudoers');
        if (self::fileNamesUser($sudoers)) return self::fault('A sudoers file names aicli-run. Remove that entry.', $uid, $gid);
        $sudoersDir = self::path('sudoersDir', '/etc/sudoers.d');
        if (self::treeNamesUser($sudoersDir)) return self::fault('A sudoers file names aicli-run. Remove that entry.', $uid, $gid);
        return ['ready' => true, 'fault' => '', 'uid' => $uid, 'gid' => $gid, 'user' => self::USER];
    }

    /** @return array{ready:false,fault:string,uid:?int,gid:?int,user:string} */
    private static function fault(string $message, ?int $uid, ?int $gid): array
    {
        return ['ready' => false, 'fault' => $message, 'uid' => $uid, 'gid' => $gid, 'user' => self::USER];
    }

    private static function fileNamesUser(string $file): bool
    {
        $resolved = @realpath($file);
        if ($resolved === false) return false;
        $st = @stat($resolved);
        if (!is_array($st) || (($st['mode'] & 0170000) !== 0100000)) return false;
        $text = @file_get_contents($resolved);
        return is_string($text) && preg_match('/\baicli-run\b/', $text) === 1;
    }

    private static function treeNamesUser(string $dir, array &$seen = []): bool
    {
        $resolved = @realpath($dir);
        if ($resolved === false) return false;
        if (isset($seen[$resolved])) return false;
        $seen[$resolved] = true;
        $st = @stat($resolved);
        if (!is_array($st) || (($st['mode'] & 0170000) !== 0040000)) return false;
        try {
            foreach (new \DirectoryIterator($resolved) as $item) {
                if ($item->isDot()) continue;
                $path = $item->getPathname();
                $childPath = @realpath($path);
                if ($childPath === false) continue;
                $child = @stat($childPath);
                if (!is_array($child)) continue;
                if ((($child['mode'] & 0170000) === 0040000) && self::treeNamesUser($path, $seen)) return true;
                if ((($child['mode'] & 0170000) === 0100000) && self::fileNamesUser($path)) return true;
            }
        } catch (\UnexpectedValueException $e) {
            return false;   // a folder that this process cannot read (only root can read /etc/sudoers.d)
        }
        return false;
    }

    // ======================================================================
    // R17a: SMB access warnings. Reads /boot/config/shares/*.cfg only.
    // ======================================================================

    /** @return list<array{share:string,kind:string,message:string}> */
    public static function shareWarnings(): array
    {
        $dir = self::path('sharesDir', '/boot/config/shares');
        $out = [];
        foreach (glob(rtrim($dir, '/') . '/*.cfg') ?: [] as $file) {
            if (is_link($file) || !is_file($file)) continue;
            $cfg = self::readConfigFile($file);
            $share = basename($file, '.cfg');
            $listed = false;
            foreach (['shareReadList', 'shareWriteList'] as $key) {
                $members = preg_split('/[\s,]+/', trim((string)($cfg[$key] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                if (in_array(self::USER, $members, true)) $listed = true;
            }
            if ($listed) $out[] = ['share' => $share, 'kind' => 'listed', 'message' => "$share lists aicli-run. Set aicli-run to No Access in SMB Security Settings."];
            $security = strtolower(trim((string)($cfg['shareSecurity'] ?? '')));
            if ($security === 'public') $out[] = ['share' => $share, 'kind' => 'public', 'message' => "$share is Public, so SMB users can reach it. Set aicli-run to No Access in SMB Security Settings."];
            if ($security === 'secure') $out[] = ['share' => $share, 'kind' => 'secure', 'message' => "$share is Secure, so every SMB user can read it. Set aicli-run to No Access in SMB Security Settings."];
        }
        return $out;
    }

    /** @return array<string,string> */
    private static function readConfigFile(string $file): array
    {
        $out = [];
        foreach (@file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (!preg_match('/^([A-Za-z0-9_]+)="?([^"\r\n]*)"?$/D', trim($line), $m)) continue;
            $out[$m[1]] = $m[2];
        }
        return $out;
    }

    // ======================================================================
    // R18: the private task folder of a run as the run user
    // ======================================================================

    /**
     * The folder of the private task folders: <state root>/work/aicli-run-tasks. It is not inside work/aicli-run:
     * that folder is the work folder of the run user and belongs to it, and the run user could replace a folder
     * inside it. The parent here is <state root>/work, which is sticky, so the run user cannot rename an entry of root.
     */
    public static function taskRoot(): string
    {
        return self::path('taskRoot', SecretPaths::stateRoot() . '/work/aicli-run-tasks');
    }

    /**
     * Make the private task folder <taskRoot>/<runId>, mode 700, owned by root. Root fills it, then
     * finalizeTaskFolder() hands it to the run user once. The agent starts only after the hand-over.
     *
     * @return array{path:string}|array{error:string}
     */
    public static function createTaskFolder(string $runId): array
    {
        if (!self::validRunId($runId)) return ['error' => 'The run needs a valid task folder id.'];
        $root = self::taskRoot();
        // #929: the root of the task folders is closed to the run user on the host (root-owned, mode 0700). Root binds only this
        // run's folder into the view (aicli-run-private-bind.sh), so no run can reach the task folder of another run.
        if (!self::ensureRootOnlyDirectory($root)) return ['error' => 'The private run task folder is not available.'];
        $rootReal = @realpath($root);
        $rootStat = @lstat($root);
        // The root of the task folders must be a plain folder that only root can change.
        if ($rootReal === false || is_link($root) || !is_array($rootStat) || (int)$rootStat['uid'] !== self::ownerOfPlugin() || ($rootStat['mode'] & 0022) !== 0) {
            return ['error' => 'The private run task folder root is not safe (a link, or open for others to change).'];
        }
        // The run user must not be able to rename or replace the root: its parent is owned by the plugin user and closed to
        // others, or it is sticky (only the owner of an entry can rename it).
        $parentStat = @lstat(dirname($rootReal));
        if (!is_array($parentStat) || (($parentStat['mode'] & 0170000) !== 0040000)
            || (int)$parentStat['uid'] !== self::ownerOfPlugin() || (($parentStat['mode'] & 0022) !== 0 && ($parentStat['mode'] & 01000) === 0)) {
            return ['error' => 'The folder above the private run task folders can be changed by the run user, so it is not used.'];
        }
        $path = $rootReal . '/' . $runId;
        $old = umask(0077);
        try {
            $made = !file_exists($path) && !is_link($path) && @mkdir($path, 0700);
        } finally {
            umask($old);
        }
        $st = @lstat($path);
        if (!$made || !is_array($st) || (($st['mode'] & 0170000) !== 0040000) || ($st['mode'] & 0777) !== 0700
            || (int)$st['uid'] !== self::ownerOfPlugin()) {
            return ['error' => 'The run task folder already exists or could not be made.'];
        }
        return ['path' => $path];
    }

    /**
     * Hand a complete private task folder to its run user, before the agent starts. Every file must be a plain file
     * with one name, mode 600, owned by the run user, and have an allowed name. Root does this once, while the
     * folder is root owned; the run user cannot change the folder before that.
     */
    public static function finalizeTaskFolder(string $dir, int $uid, int $gid): bool
    {
        $root = self::taskRoot();
        $rootReal = @realpath($root);
        $dirStat = @lstat($dir);
        $dirReal = @realpath($dir);
        if ($rootReal === false || is_link($root) || $dirReal === false || is_link($dir)
            || dirname($dirReal) !== $rootReal || !is_array($dirStat) || (($dirStat['mode'] & 0170000) !== 0040000)
            || ($dirStat['mode'] & 0777) !== 0700) return false;
        if ((int)$dirStat['uid'] !== self::ownerOfPlugin()) return false;
        foreach (scandir($dirReal) ?: [] as $name) {
            if ($name === '.' || $name === '..') continue;
            if (!in_array($name, self::TASK_FILES, true)) return false;
            $st = @lstat($dirReal . '/' . $name);
            if (!is_array($st) || (($st['mode'] & 0170000) !== 0100000) || (int)$st['uid'] !== $uid
                || (int)$st['gid'] !== $gid || (int)$st['nlink'] !== 1 || ($st['mode'] & 0777) !== 0600) return false;
        }
        return self::handOver($dirReal, $uid, $gid, 0700);
    }

    /**
     * Write one fixed task file with an open file and an atomic rename. Two callers only:
     * (1) root, while the folder is root owned: the file is handed to the run user, then renamed in;
     * (2) the run user, in its own folder (aicli-run-report): the file is already owned by that user.
     * Root never writes in a folder that the run user owns.
     */
    public static function writeTaskFile(string $dir, string $name, string $body, int $uid, int $gid): bool
    {
        if (!in_array($name, self::TASK_FILES, true)) return false;
        $st = @lstat($dir);
        $real = @realpath($dir);
        if (!is_array($st) || (($st['mode'] & 0170000) !== 0040000) || is_link($dir) || $real !== $dir || ($st['mode'] & 0777) !== 0700) return false;
        $pluginOwned = (int)$st['uid'] === self::ownerOfPlugin();
        $userOwned = (int)$st['uid'] === $uid && (int)$st['gid'] === $gid;
        // A folder that the run user owns is written only by the run user itself.
        if ($userOwned && !$pluginOwned && self::euid() !== $uid) return false;
        if (!$userOwned && !$pluginOwned) return false;
        $tmpName = '.tmp-' . bin2hex(random_bytes(8));
        $tmp = $dir . '/' . $tmpName;
        $old = umask(0077);
        try { $handle = @fopen($tmp, 'x+b'); }
        finally { umask($old); }
        if ($handle === false) return false;
        $before = false;
        $ok = false;
        try {
            $before = @fstat($handle);
            $ok = is_array($before) && (($before['mode'] & 0170000) === 0100000)
                && fwrite($handle, $body) === strlen($body) && fflush($handle);
            $after = @fstat($handle);
            $ok = $ok && is_array($after) && (int)$after['dev'] === (int)$before['dev']
                && (int)$after['ino'] === (int)$before['ino'] && (int)$after['size'] === strlen($body);
        } finally {
            fclose($handle);
        }
        $named = @lstat($tmp);
        $ok = $ok && is_array($before) && is_array($named) && (($named['mode'] & 0170000) === 0100000)
            && (int)$named['dev'] === (int)$before['dev'] && (int)$named['ino'] === (int)$before['ino']
            && (int)$named['nlink'] === 1 && ($named['mode'] & 0777) === 0600;
        if ($ok && ((int)$named['uid'] !== $uid || (int)$named['gid'] !== $gid)) {
            // Only a folder that is still owned by the plugin user may get a file handed to the run user. lchown does
            // not follow a link.
            // PHPUnit runs as root and cannot become the run user, so a test may emulate it (seam, never set in production).
            $testAsUser = defined('AICLI_TEST_TMP') && self::$effectiveUidForTests !== null && self::$effectiveUidForTests === $uid && self::ownerOfPlugin() === 0;
            $ok = (($pluginOwned && !$userOwned) || $testAsUser) && function_exists('lchown') && function_exists('lchgrp')
                && @lchown($tmp, $uid) && @lchgrp($tmp, $gid);
        }
        $named = @lstat($tmp);
        $ok = $ok && is_array($named) && (($named['mode'] & 0170000) === 0100000)
            && (int)$named['dev'] === (int)$before['dev'] && (int)$named['ino'] === (int)$before['ino']
            && (int)$named['uid'] === $uid && (int)$named['gid'] === $gid
            && (int)$named['nlink'] === 1 && ($named['mode'] & 0777) === 0600;
        $target = $dir . '/' . $name;
        if (!$ok || is_dir($target) || !@rename($tmp, $target)) {
            @unlink($tmp);
            return false;
        }
        $targetStat = @lstat($target);
        return is_array($targetStat) && (($targetStat['mode'] & 0170000) === 0100000)
            && (int)$targetStat['dev'] === (int)$before['dev'] && (int)$targetStat['ino'] === (int)$before['ino']
            && (int)$targetStat['uid'] === $uid && (int)$targetStat['gid'] === $gid
            && (int)$targetStat['nlink'] === 1 && ($targetStat['mode'] & 0777) === 0600;
    }

    /** Build a bounded, non-blocking read which refuses links without running as the run user. @return list<string> */
    public static function readCommand(string $file, int $max): array
    {
        $timeout = self::runtimeToolPath('timeout') ?: '/usr/bin/timeout';
        return [$timeout, '--signal=KILL', '5', '/usr/bin/dd', 'if=' . $file, 'iflag=nofollow,nonblock', 'bs=' . ($max + 1), 'count=1', 'status=none'];
    }

    /**
     * Root removes a tree that the run user owns with GNU `rm -rf --one-file-system`. It walks with openat and unlinkat and
     * never follows a link, so a link or a rename that the run user does during the walk cannot lead it out of the tree.
     *
     * @return list<string>
     */
    public static function removeCommand(string $dir): array
    {
        return ['timeout', '--signal=KILL', '120', 'rm', '-rf', '--one-file-system', '--', $dir];
    }

    /**
     * Read a small plain file that the run user owns. lstat checks the file before and after a bounded root read.
     * dd refuses links and opens without blocking. Null when any check fails.
     */
    public static function readUserFile(string $file, array $uids, int $max, string $workspaceId = ''): ?string
    {
        $before = @lstat($file);
        if (!is_array($before) || (($before['mode'] & 0170000) !== 0100000) || !in_array((int)$before['uid'], $uids, true)
            || (int)$before['nlink'] !== 1 || (int)$before['size'] > $max) return null;
        if ($workspaceId !== '' && !defined('AICLI_TEST_TMP') && self::existingView($workspaceId) === '') return null;
        if (defined('AICLI_TEST_TMP')) {
            $text = self::$readerForTests !== null ? (self::$readerForTests)($file, (int)$before['uid'], $max) : @file_get_contents($file, false, null, 0, $max + 1);
        } else {
            $text = self::runChild(self::readCommand($file, $max));
        }
        $after = @lstat($file);
        if (!is_string($text) || strlen($text) > $max || !is_array($after)
            || ($after['mode'] & 0170000) !== 0100000 || !in_array((int)$after['uid'], $uids, true)
            || (int)$after['nlink'] !== 1 || (int)$after['size'] > $max
            || (int)$after['dev'] !== (int)$before['dev'] || (int)$after['ino'] !== (int)$before['ino']
            || (int)$after['uid'] !== (int)$before['uid'] || (int)$after['gid'] !== (int)$before['gid']
            || (int)$after['mode'] !== (int)$before['mode'] || (int)$after['size'] !== (int)$before['size']
            || (int)$after['mtime'] !== (int)$before['mtime'] || (int)$after['ctime'] !== (int)$before['ctime']) return null;
        return $text;
    }

    /**
     * Run a command and return its standard output, or null on a fault. Finding 9 of the second review: the read has a deadline
     * and never waits for the end of the pipe. The agent has the uid of the child, so it can copy the descriptor of the child
     * (pidfd_getfd) and keep the pipe open after the child is killed; a plain stream_get_contents() would then wait for ever and
     * stop the whole supervisor tick. The streams are non-blocking, the loop ends when the child has exited (the rest of the
     * pipe is read once) or when the deadline passes (the child is killed).
     *
     * @param list<string> $argv
     * @return string|null
     */
    public static function runChild(array $argv, int $deadlineSeconds = self::CHILD_SECONDS): ?string
    {
        $proc = @proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!is_resource($proc)) return null;
        stream_set_blocking($pipes[1], false);
        $out = '';
        $deadline = microtime(true) + max(1, $deadlineSeconds);
        $exitCode = null;
        $timedOut = false;
        while (true) {
            $chunk = @fread($pipes[1], 65536);
            if (is_string($chunk) && $chunk !== '') {
                $out .= $chunk;
                if (strlen($out) > 1048576) break;
            }
            $st = proc_get_status($proc);
            if (!$st['running']) {
                $exitCode = (int)$st['exitcode'];
                $rest = @fread($pipes[1], 1048576);   // what is in the pipe now; a descriptor kept by another process is not waited for
                if (is_string($rest)) $out .= $rest;
                break;
            }
            if (microtime(true) >= $deadline) { $timedOut = true; break; }
            usleep(20000);
        }
        if ($exitCode === null) {
            @proc_terminate($proc, 9);
            if ($timedOut) $exitCode = -1;
        }
        fclose($pipes[1]);
        @proc_close($proc);
        if ($exitCode !== 0 && $exitCode !== null) return null;
        if ($exitCode === null) return null;
        return strlen($out) <= 1048576 ? $out : null;
    }

    /**
     * The marker of a temporary folder that the run user owns. The marker was written by root while the folder was
     * root owned, so it may be owned by root or, when the run user replaced it, by the run user. The content is
     * checked by the caller.
     */
    public static function readMarkerFile(string $file, int $uid): ?string
    {
        return self::readUserFile($file, [self::ownerOfPlugin(), $uid], 4096);
    }

    /** The private task folder of a run: <taskRoot>/<runId>. */
    public static function taskDirFor(string $runId): string
    {
        return self::validRunId($runId) ? rtrim(self::taskRoot(), '/') . '/' . $runId : '';
    }

    /** @return array{report:array{status:string,summary:string}}|array{error:string} */
    public static function readReportFile(string $file, int $uid, string $workspaceId = ''): array
    {
        $json = self::readUserFile($file, [$uid], self::REPORT_LIMIT_BYTES, $workspaceId);
        if ($json === null) return ['error' => 'The run report is not a small, owned plain file.'];
        $data = json_decode($json, true);
        $keys = is_array($data) ? array_keys($data) : [];
        sort($keys);
        if (!is_array($data) || $keys !== ['status', 'summary']
            || !in_array(($data['status'] ?? null), ['done', 'failed', 'blocked'], true)
            || !is_string($data['summary'] ?? null) || trim($data['summary']) === ''
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', (string)($data['summary'] ?? ''))
            || self::textLength($data['summary']) > self::REPORT_LIMIT_CHARS) {
            return ['error' => 'The run report data is not valid.'];
        }
        return ['report' => ['status' => (string)$data['status'], 'summary' => trim($data['summary'])]];
    }

    /** Write the fixed run result. It runs as the run user (aicli-run-report). */
    public static function writeRunUserReport(string $status, string $summary): array
    {
        $status = strtolower(trim($status));
        $summary = trim($summary);
        if (!in_array($status, ['done', 'failed', 'blocked'], true)) return ['error' => 'Use done, failed or blocked as the report status.'];
        if ($summary === '' || self::textLength($summary) > self::REPORT_LIMIT_CHARS
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $summary)) {
            return ['error' => 'Give a plain summary of at most 2000 characters.'];
        }
        if ((string)getenv('AICLI_RESTRICTED_RUN') !== '1') return ['error' => 'This command is only available inside a restricted script run.'];
        $taskDir = trim((string)getenv('AICLI_TASK_DIR'));
        if (defined('AICLI_TEST_TMP') && isset(self::$pathsForTests['reportDir'])) $taskDir = (string)self::$pathsForTests['reportDir'];
        // This runs as the run user, which cannot read /etc/sudoers. So it does not call status(): it checks only that the
        // process is the run user (the readiness of the account was checked by the plugin before the run started).
        $euid = self::euid();
        if (defined('AICLI_TEST_TMP') && self::$effectiveUidForTests !== null) {
            $user = self::status();
            if (empty($user['ready'])) return ['error' => (string)$user['fault']];
            $gid = (int)$user['gid'];
            $uidOfUser = (int)$user['uid'];
        } else {
            $pw = function_exists('posix_getpwuid') ? @posix_getpwuid($euid) : false;
            $uidOfUser = is_array($pw) && $pw['name'] === self::USER ? (int)$pw['uid'] : -1;
            $gid = function_exists('posix_getegid') ? (int)posix_getegid() : -1;
        }
        if ($euid !== $uidOfUser || $gid < 0) return ['error' => 'Run aicli-run-report as aicli-run.'];
        if ($taskDir === '' || $taskDir[0] !== '/') return ['error' => 'The private run task folder is not available.'];
        $root = self::taskRoot();
        $rootReal = @realpath($root);
        $taskReal = @realpath($taskDir);
        if ($rootReal === false || is_link($root) || $taskReal === false || is_link($taskDir)
            || dirname($taskReal) !== $rootReal || !self::validRunId(basename($taskReal))) {
            return ['error' => 'The private run task folder is not available.'];
        }
        $json = json_encode(['status' => $status, 'summary' => $summary], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || strlen($json) > self::REPORT_LIMIT_BYTES) return ['error' => 'The run report is too large.'];
        if (!self::writeTaskFile($taskReal, 'RESULT.json', $json . "\n", $euid, $gid)) {
            return ['error' => 'The run report could not be saved in the private task folder.'];
        }
        return ['status' => 'ok'];
    }

    /**
     * Remove the private task folder of a run. While root still owns it, root removes it. After the hand-over the
     * run user owns it, and the run user's own child process removes the content (it cannot be tricked into a link
     * target by the run user), then root removes the empty folder from its own parent.
     */
    public static function removeTaskFolder(string $runId): void
    {
        if (!self::validRunId($runId)) return;
        $root = self::taskRoot();
        $rootReal = @realpath($root);
        $folder = rtrim($root, '/') . '/' . $runId;
        $st = @lstat($folder);
        if ($rootReal === false || !is_array($st) || (($st['mode'] & 0170000) !== 0040000) || is_link($folder)) return;
        $folderReal = @realpath($folder);
        if ($folderReal !== $rootReal . '/' . $runId) return;
        self::removeOwnedTree($folderReal, (int)$st['uid']);
    }

    /** Settings files of the agents whose keys can start a program (a hook, a status line command, a key helper). Home relative. */
    public const NEUTRALISE_FILES = ['.claude/settings.json', '.claude/settings.local.json', '.gemini/settings.json', '.qwen/settings.json'];

    /** The keys that make an agent start a program or load code from the home. They are removed at each prepare. */
    public const NEUTRALISE_KEYS = ['hooks', 'statusLine', 'apiKeyHelper', 'awsAuthRefresh', 'awsCredentialExport', 'otelHeadersHelper',
        'enabledPlugins', 'extraKnownMarketplaces', 'mcpServers', 'env', 'permissions'];

    /**
     * Remove startup and code-loading keys from the agent settings files that may be present in the fresh home before the user's
     * Config Hub choices are projected. A file that is a link, is too big, or is not
     * valid JSON is removed and reported: the run does not use a settings file that the plugin cannot read. This runs AS THE RUN
     * USER (the prepare child), so no link can reach more than the run user can reach already.
     *
     * @return array{ok:bool,removed:list<string>,reason:string}
     */
    public static function neutraliseHome(string $home): array
    {
        $removed = [];
        if ($home === '' || $home[0] !== '/') return ['ok' => false, 'removed' => [], 'reason' => 'bad_home'];
        foreach (self::NEUTRALISE_FILES as $rel) {
            $file = rtrim($home, '/') . '/' . $rel;
            $st = @lstat($file);
            if (!is_array($st)) continue;
            if (($st['mode'] & 0170000) !== 0100000 || $st['size'] > 1048576) {
                if (!@unlink($file)) return ['ok' => false, 'removed' => $removed, 'reason' => "cannot remove $rel"];
                $removed[] = $rel . ' (not a plain file)';
                continue;
            }
            $data = json_decode((string)@file_get_contents($file), true);
            if (!is_array($data)) {
                if (!@unlink($file)) return ['ok' => false, 'removed' => $removed, 'reason' => "cannot remove $rel"];
                $removed[] = $rel . ' (not valid JSON)';
                continue;
            }
            $dropped = [];
            foreach (self::NEUTRALISE_KEYS as $key) {
                if (array_key_exists($key, $data)) { unset($data[$key]); $dropped[] = $key; }
            }
            if ($dropped === []) continue;
            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($json) || @file_put_contents($file, $json . "\n") === false) return ['ok' => false, 'removed' => $removed, 'reason' => "cannot rewrite $rel"];
            $removed[] = $rel . ' (' . implode(', ', $dropped) . ')';
        }
        return ['ok' => true, 'removed' => $removed, 'reason' => ''];
    }

    /**
     * Remove a folder tree. A tree that the plugin user owns is removed without following links. A tree that the
     * run user owns is removed by GNU `rm -rf --one-file-system` as root (it walks with openat and never follows a
     * link). A tree of any other owner is left alone.
     */
    public static function removeOwnedTree(string $dir, int $ownerUid): bool
    {
        $st = @lstat($dir);
        if (!is_array($st)) return true;
        if (is_link($dir) || (($st['mode'] & 0170000) !== 0040000)) return false;
        if ($ownerUid === self::ownerOfPlugin()) return self::removeRootTree($dir);
        $status = self::status();
        if (empty($status['ready']) || $ownerUid !== (int)$status['uid']) return false;
        if (defined('AICLI_TEST_TMP') && self::$removerForTests !== null) {
            (self::$removerForTests)($dir, $ownerUid);
        } elseif (defined('AICLI_TEST_TMP')) {
            self::removeRootTree($dir, false);
        } else {
            $proc = @proc_open(self::removeCommand($dir), [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
            if (is_resource($proc)) proc_close($proc);
        }
        return @rmdir($dir) || !file_exists($dir);
    }

    /** Remove only entries below the folder. Links are unlinked, never entered. Used for root owned trees. */
    private static function removeRootTree(string $path, bool $removeTop = true): bool
    {
        $st = @lstat($path);
        if (!is_array($st)) return true;
        if (($st['mode'] & 0170000) !== 0040000) return @unlink($path);
        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..' && !self::removeRootTree($path . '/' . $name)) return false;
        }
        return !$removeTop || @rmdir($path);
    }

    // ======================================================================
    // R20: give a temporary run folder to the run user
    // ======================================================================

    /**
     * Hand a folder to the run user: owner, group and mode. The folder must be a real folder that the plugin user
     * owns, its parent must be a real folder that only the plugin user can change (so nobody can swap the name for a
     * link), and the folder must be the same folder (device and inode) after the change.
     */
    public static function handOver(string $dir, int $uid, int $gid, int $mode): bool
    {
        $before = @lstat($dir);
        if (!is_array($before) || (($before['mode'] & 0170000) !== 0040000) || is_link($dir)) return false;
        $parent = dirname($dir);
        $pst = @lstat($parent);
        if (!is_array($pst) || (($pst['mode'] & 0170000) !== 0040000) || is_link($parent)
            || (int)$pst['uid'] !== self::ownerOfPlugin() || (($pst['mode'] & 0022) !== 0 && ($pst['mode'] & 01000) === 0)) return false;
        if ((int)$before['uid'] !== self::ownerOfPlugin() && (int)$before['uid'] !== $uid) return false;
        if ((int)$before['uid'] !== $uid && !@chown($dir, $uid)) return false;
        if ((int)$before['gid'] !== $gid && !@chgrp($dir, $gid)) return false;
        if (($before['mode'] & 0777) !== $mode && !@chmod($dir, $mode)) return false;
        $after = @lstat($dir);
        return is_array($after) && (($after['mode'] & 0170000) === 0040000)
            && (int)$after['dev'] === (int)$before['dev'] && (int)$after['ino'] === (int)$before['ino']
            && (int)$after['uid'] === $uid && (int)$after['gid'] === $gid && ($after['mode'] & 0777) === $mode;
    }

    /**
     * True when the run user can enter every level of a path (it needs the x right: others, or the users group).
     * Returns the first level that it cannot enter, else null.
     */
    public static function firstLevelNotEnterable(string $path): ?string
    {
        $level = '';
        foreach (explode('/', ltrim($path, '/')) as $part) {
            $level .= '/' . $part;
            $st = @stat($level);
            if (!is_array($st)) return $level;
            $mode = (int)$st['mode'];
            if (($mode & 0001) === 0 && !((int)$st['gid'] === 100 && ($mode & 0010) !== 0)) return $level;
        }
        return null;
    }

    /** Resolve an isolation tool from the fixed runtime PATH, with a test seam for path checks. */
    public static function runtimeToolPath(string $name): string
    {
        if (preg_match('/^[a-z0-9_-]+$/D', $name) !== 1) return '';
        $configured = self::path('tool_' . $name, '');
        if ($configured !== '') return is_executable($configured) ? $configured : '';
        foreach (explode(':', self::FIXED_PATH) as $dir) {
            $path = rtrim($dir, '/') . '/' . $name;
            if (is_executable($path)) return $path;
        }
        return '';
    }

    // ======================================================================
    // The view of a restricted workspace: its persistent mount space, its root-only mark and its private files
    // ======================================================================

    /** The root-only folder (mode 700) in /var/run for the mount space files and the marks. The view hides /var/run. */
    public static function viewDir(): string
    {
        return rtrim(self::path('viewDir', '/var/run/aicli-view'), '/');
    }

    private static function safeId(string $workspaceId): ?string
    {
        return preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $workspaceId) === 1 ? $workspaceId : null;
    }

    /** The persistent mount space of a restricted workspace (made by `unshare --mount=<file>`). */
    public static function nsFile(string $workspaceId): string
    {
        $id = self::safeId($workspaceId);
        return $id === null ? '' : self::viewDir() . '/' . $id . '.mnt';
    }

    /** The persistent PID namespace file of one restricted workspace. */
    public static function pidNsFile(string $workspaceId): string
    {
        $id = self::safeId($workspaceId);
        return $id === null ? '' : self::viewDir() . '/' . $id . '.pid';
    }

    /** The user namespace whose root maps only to aicli-run. */
    public static function userNsFile(string $workspaceId): string
    {
        $id = self::safeId($workspaceId);
        return $id === null ? '' : self::viewDir() . '/' . $id . '.usr';
    }

    /** The private IPC namespace file of one restricted workspace. */
    public static function ipcNsFile(string $workspaceId): string
    {
        $id = self::safeId($workspaceId);
        return $id === null ? '' : self::viewDir() . '/' . $id . '.ipc';
    }

    /** The private UTS namespace file of one restricted workspace. */
    public static function utsNsFile(string $workspaceId): string
    {
        $id = self::safeId($workspaceId);
        return $id === null ? '' : self::viewDir() . '/' . $id . '.uts';
    }

    /** The pinned child user namespace entered by the run user and its clients. */
    public static function childUserNsFile(string $workspaceId): string
    {
        $id = self::safeId($workspaceId);
        return $id === null ? '' : self::viewDir() . '/' . $id . '.childusr';
    }

    /** The private network namespace name is derived from the workspace id. */
    public static function networkNamespaceName(string $workspaceId): string
    {
        $id = self::safeId($workspaceId);
        return $id === null ? '' : 'aicli-' . substr(hash('sha256', $id), 0, 10);
    }

    /** The namespace file that `nsenter --net` opens for a restricted workspace. */
    public static function networkNamespaceFile(string $workspaceId): string
    {
        $name = self::networkNamespaceName($workspaceId);
        return $name === '' ? '' : rtrim(self::path('netnsRoot', '/var/run/netns'), '/') . '/' . $name;
    }

    /** The root-owned resolver file that the mount view binds over `/etc/resolv.conf`. */
    public static function resolverFile(string $workspaceId): string
    {
        $name = self::networkNamespaceName($workspaceId);
        return $name === '' ? '' : rtrim(self::path('etcNetnsRoot', '/etc/netns'), '/') . '/' . $name . '/resolv.conf';
    }

    /** The cgroup v2 directory name is restricted to this workspace id. */
    public static function cgroupPath(string $workspaceId): string
    {
        $id = self::safeId($workspaceId);
        $configured = self::testPaths();
        $root = is_array($configured) && !isset($configured['cgroupRoot']) ? '' : self::path('cgroupRoot', '/sys/fs/cgroup');
        return $id === null || $root === '' ? '' : rtrim($root, '/') . '/aicli-run-' . $id;
    }

    /** A fresh writable home for one restricted workspace. */
    public static function sessionHomePath(string $workspaceId): string
    {
        $id = self::safeId($workspaceId);
        return $id === null ? '' : rtrim(SecretPaths::stateRoot(), '/') . '/work/aicli-run-sessions/' . $id . '/home';
    }

    /** Make a new, empty home for this restricted workspace and hand it to aicli-run. */
    public static function prepareSessionHome(string $workspaceId, int $uid, int $gid): string
    {
        $id = self::safeId($workspaceId);
        $home = self::sessionHomePath($workspaceId);
        if ($id === null || $uid < 1000 || $gid !== 100 || $home === '') return '';
        $base = dirname(dirname($home));
        // #929: closed to the run user on the host; root binds only this workspace's home into the view.
        if (!self::ensureRootOnlyDirectory($base)) return '';
        $baseStat = @lstat($base);
        if (!is_array($baseStat) || is_link($base) || (int)$baseStat['uid'] !== self::ownerOfPlugin() || ($baseStat['mode'] & 0022) !== 0) return '';
        $sessionDir = dirname($home);
        if (file_exists($sessionDir) || is_link($sessionDir) || !@mkdir($sessionDir, 0711)) return '';
        if (!@mkdir($home, 0700)) { @rmdir($sessionDir); return ''; }
        if (!self::handOver($home, $uid, $gid, 0700)) {
            self::removeRootTree($sessionDir);
            return '';
        }
        return $home;
    }

    /** Return the existing private home of a sign-in workspace after a failed save, if its owner and mode still match. */
    public static function existingSessionHome(string $workspaceId, int $uid, int $gid): string
    {
        $home = self::sessionHomePath($workspaceId);
        $st = $home !== '' ? @lstat($home) : false;
        return is_array($st) && !is_link($home) && ($st['mode'] & 0170000) === 0040000 && (int)$st['uid'] === $uid
            && (int)$st['gid'] === $gid && ($st['mode'] & 0777) === 0700 && @realpath($home) === $home ? $home : '';
    }

    /** Return the canonical authorised folders a restricted run may see, or null if one is unsafe. */
    public static function validatedListedFolders(array $folders): ?array
    {
        $mountRoot = rtrim(self::path('mountRoot', '/mnt'), '/');
        $mountRootReal = @realpath($mountRoot);
        if (!is_string($mountRootReal)) return null;
        $prefix = rtrim($mountRootReal, '/') . '/';
        $valid = [];
        foreach ($folders as $folder) {
            if (!is_string($folder) || $folder === '' || $folder[0] !== '/' || strpos($folder, '//') !== false
                || preg_match('/[\x00-\x1F\x7F]/', $folder) === 1) return null;
            foreach (explode('/', substr($folder, 1)) as $part) {
                if ($part === '' || $part === '.' || $part === '..') return null;
            }
            $stat = @lstat($folder);
            $real = @realpath($folder);
            if (!is_array($stat) || is_link($folder) || ($stat['mode'] & 0170000) !== 0040000
                || !is_string($real) || $real !== $folder || strpos($real, $prefix) !== 0) return null;
            $valid[$real] = $real;
        }
        return array_values($valid);
    }

    /** Make durable run markers and read-only mount targets inside a restricted run folder. */
    public static function prepareFolderGuards(string $workspaceId, string $folder, string $runId, bool $writable): ?array
    {
        $id = self::safeId($workspaceId);
        $account = self::status();
        $mountRoot = rtrim(self::path('mountRoot', '/mnt'), '/');
        $folderStat = @lstat($folder);
        $folderReal = @realpath($folder);
        $mountRootReal = @realpath($mountRoot);
        if ($id === null || empty($account['ready']) || !is_array($folderStat) || is_link($folder)
            || ($folderStat['mode'] & 0170000) !== 0040000 || !is_string($folderReal) || $folderReal !== $folder
            || !is_string($mountRootReal) || strpos($folderReal, rtrim($mountRootReal, '/') . '/') !== 0) return null;

        $rid = preg_match('/^[A-Za-z0-9]{6,32}$/D', $runId) === 1 ? $runId : $id;
        $marker = json_encode(['runId' => $rid, 'workspaceId' => $id, 'created' => time(), 'expires' => 0,
            'restricted' => true, 'mayChange' => $writable], JSON_UNESCAPED_SLASHES);
        if (!is_string($marker)) return null;
        $specs = [];
        if ($writable) {
            $specs = array_merge($specs, [
                ['path' => '.git', 'type' => 'dir', 'body' => ''],
                ['path' => '.claude', 'type' => 'dir', 'body' => ''],
                ['path' => '.mcp.json', 'type' => 'file', 'body' => "{}\n"],
            ]);
        }
        $specs[] = ['path' => AgentRunService::TEMP_MARKER, 'type' => 'file', 'body' => $marker . "\n"];

        // Check every existing path before writing the marker or creating a stub. A linked or hard-linked config file
        // must not let the run user edit the protected contents through another name in the writable folder.
        foreach ($specs as $spec) {
            $rel = (string)$spec['path'];
            $parts = explode('/', $rel);
            $probe = $folderReal;
            $parentParts = $spec['type'] === 'file' ? array_slice($parts, 0, -1) : $parts;
            foreach ($parentParts as $part) {
                if ($part === '' || $part === '.' || $part === '..') return null;
                $probe .= '/' . $part;
                $st = @lstat($probe);
                if (!is_array($st)) break;
                if (is_link($probe) || ($st['mode'] & 0170000) !== 0040000) return null;
            }
            $probe = $folderReal . '/' . $rel;
            $st = @lstat($probe);
            if (!is_array($st)) continue;
            if (is_link($probe) || ($spec['type'] === 'file' && (($st['mode'] & 0170000) !== 0100000 || (int)$st['nlink'] !== 1))
                || ($spec['type'] === 'dir' && ($st['mode'] & 0170000) !== 0040000)) return null;
        }

        $uid = (int)$account['uid'];
        $gid = (int)$account['gid'];
        $protected = [];
        foreach ($specs as $spec) {
            $rel = (string)$spec['path'];
            $parts = explode('/', $rel);
            $target = $folderReal;
            $parentParts = $spec['type'] === 'file' ? array_slice($parts, 0, -1) : $parts;
            foreach ($parentParts as $part) {
                if ($part === '' || $part === '.' || $part === '..') return null;
                $target .= '/' . $part;
                $st = @lstat($target);
                if (is_array($st)) {
                    if (is_link($target) || ($st['mode'] & 0170000) !== 0040000) return null;
                    continue;
                }
                $oldUmask = umask(0077);
                try { $made = @mkdir($target, 0700); }
                finally { umask($oldUmask); }
                if (!$made || !@chown($target, $uid) || !@chgrp($target, $gid)) return null;
                $st = @lstat($target);
                if (!is_array($st) || is_link($target) || ($st['mode'] & 0170000) !== 0040000 || (int)$st['uid'] !== $uid || (int)$st['gid'] !== $gid) return null;
            }
            $target = $folderReal . '/' . $rel;
            $st = @lstat($target);
            if (is_array($st)) {
                if (is_link($target) || ($spec['type'] === 'file' && ($st['mode'] & 0170000) !== 0100000)
                    || ($spec['type'] === 'file' && (int)$st['nlink'] !== 1)
                    || ($spec['type'] === 'dir' && ($st['mode'] & 0170000) !== 0040000)) return null;
            } elseif ($spec['type'] === 'dir') {
                $oldUmask = umask(0077);
                try { $made = @mkdir($target, 0700); }
                finally { umask($oldUmask); }
                if (!$made || !@chown($target, $uid) || !@chgrp($target, $gid)) return null;
                $st = @lstat($target);
                if (!is_array($st) || is_link($target) || ($st['mode'] & 0170000) !== 0040000 || (int)$st['uid'] !== $uid || (int)$st['gid'] !== $gid) return null;
            } else {
                $oldUmask = umask(0333);
                try { $handle = @fopen($target, 'x'); }
                finally { umask($oldUmask); }
                if ($handle === false) return null;
                $body = (string)$spec['body'];
                $written = fwrite($handle, $body);
                fflush($handle);
                fclose($handle);
                if ($written !== strlen($body)) return null;
                $st = @lstat($target);
                if (!is_array($st) || is_link($target) || ($st['mode'] & 0170000) !== 0100000 || ($st['mode'] & 0777) !== 0444) return null;
            }
            if (@realpath($target) !== $target) return null;
            $protected[] = $target;
        }
        return $protected;
    }

    /** A may-change restricted marker survives reboot and prevents the folder from starting as root. */
    public static function folderHasMayChangeRunMarker(string $folder): bool
    {
        $root = @realpath($folder);
        if (!is_string($root) || !is_dir($root)) return false;
        $mountRoot = @realpath(self::path('mountRoot', '/mnt'));
        $insideMountRoot = is_string($mountRoot) && ($root === $mountRoot || strpos($root, rtrim($mountRoot, '/') . '/') === 0);
        $cursor = rtrim($root, '/') ?: '/';
        do {
            if (self::folderHasMayChangeMarkerAt($cursor)) return true;
            if (!$insideMountRoot || $cursor === $mountRoot || $cursor === '/') break;
            $cursor = dirname($cursor);
        } while (true);
        return false;
    }

    private static function folderHasMayChangeMarkerAt(string $root): bool
    {
        $file = rtrim($root, '/') . '/' . AgentRunService::TEMP_MARKER;
        $before = @lstat($file);
        if (!is_array($before)) return false;
        if (is_link($file) || ($before['mode'] & 0170000) !== 0100000 || (int)$before['nlink'] !== 1 || (int)$before['size'] > 4096) return true;
        $body = defined('AICLI_TEST_TMP')
            ? @file_get_contents($file, false, null, 0, 4097)
            : self::runChild(self::readCommand($file, 4096));
        $after = @lstat($file);
        if (!is_string($body) || strlen($body) > 4096 || !is_array($after)
            || ($after['mode'] & 0170000) !== 0100000 || (int)$after['nlink'] !== 1
            || (int)$before['dev'] !== (int)$after['dev'] || (int)$before['ino'] !== (int)$after['ino']
            || (int)$before['uid'] !== (int)$after['uid'] || (int)$before['gid'] !== (int)$after['gid']
            || (int)$before['mode'] !== (int)$after['mode'] || (int)$before['size'] !== (int)$after['size']
            || (int)$before['mtime'] !== (int)$after['mtime'] || (int)$before['ctime'] !== (int)$after['ctime']) return true;
        $marker = json_decode($body, true);
        if (!is_array($marker)) return true;
        $runId = (string)($marker['runId'] ?? '');
        if (preg_match('/^[A-Za-z0-9]{6,32}$/D', $runId) !== 1
            || !is_int($marker['created'] ?? null) || !is_int($marker['expires'] ?? null)) return true;

        // Old root temporary-run markers have no restricted flag. Older temporary folders for a restricted run used
        // the same marker shape, but the run user owned the folder. Accept only the known shape in a plugin-owned folder.
        if (!array_key_exists('restricted', $marker)) {
            $rootStat = @lstat($root);
            return array_key_exists('workspaceId', $marker) || !is_array($rootStat)
                || (int)$rootStat['uid'] !== self::ownerOfPlugin();
        }
        if (!is_bool($marker['restricted'])) return true;
        if ($marker['restricted'] === false) {
            $rootStat = @lstat($root);
            return (array_key_exists('workspaceId', $marker)
                || (array_key_exists('mayChange', $marker) && $marker['mayChange'] !== false)
                || !is_array($rootStat)
                || (int)$rootStat['uid'] !== self::ownerOfPlugin()
                || ($rootStat['mode'] & 0777) !== 0700);
        }
        $workspaceId = self::safeId((string)($marker['workspaceId'] ?? ''));
        if ($workspaceId === null) return true;
        if (array_key_exists('mayChange', $marker)) {
            if (!is_bool($marker['mayChange'])) return true;
            return $marker['mayChange'];
        }

        // Older restricted markers did not record this flag. Use the matching workspace record when it survives.
        $record = SpawnService::findRecord($workspaceId);
        $spawn = is_array($record['spawn'] ?? null) ? $record['spawn'] : [];
        $run = is_array($spawn['run'] ?? null) ? $spawn['run'] : (is_array($spawn['runUserSignIn'] ?? null) ? $spawn['runUserSignIn'] : []);
        if ((string)($run['runId'] ?? '') === $runId) {
            if (array_key_exists('mountWritable', $run)) return !empty($run['mountWritable']);
            return !empty($run['temp']) || (string)($run['mode'] ?? '') === 'may-change';
        }
        return true;
    }

    /** Remove the fresh home only after its run processes have stopped. */
    private static function removeSessionHome(string $workspaceId): bool
    {
        $home = self::sessionHomePath($workspaceId);
        if ($home === '') return false;
        $st = @lstat($home);
        if (is_array($st) && (is_link($home) || ($st['mode'] & 0170000) !== 0040000 || !self::removeOwnedTree($home, (int)$st['uid']))) return false;
        $sessionDir = dirname($home);
        $parent = @lstat($sessionDir);
        if (is_array($parent) && (is_link($sessionDir) || (int)$parent['uid'] !== self::ownerOfPlugin() || !self::removeRootTree($sessionDir))) return false;
        return !file_exists($home) && !file_exists($sessionDir);
    }

    /** The root-only mark that says a workspace must run as the run user, whatever its record says (finding 11). */
    public static function markFile(string $workspaceId): string
    {
        $id = self::safeId($workspaceId);
        return $id === null ? '' : self::viewDir() . '/' . $id . '.runas';
    }

    private static function ensureViewDir(): bool
    {
        $dir = self::viewDir();
        if (is_link($dir)) return false;
        if (!is_dir($dir)) {
            $old = umask(0077);
            try { $made = @mkdir($dir, 0700, true); }
            finally { umask($old); }
            if (!$made && !is_dir($dir)) return false;
        }
        $st = @lstat($dir);
        if (!is_array($st) || (($st['mode'] & 0170000) !== 0040000) || (int)$st['uid'] !== self::ownerOfPlugin()) return false;
        $mode = (int)$st['mode'] & 0777;
        if ($mode === 0711) {
            if (!@chmod($dir, 0700)) return false;
            $after = @lstat($dir);
            if (!is_array($after) || self::isLinkNow($dir) || (($after['mode'] & 0170000) !== 0040000)
                || (int)$after['uid'] !== self::ownerOfPlugin()) return false;
            $mode = (int)$after['mode'] & 0777;
        }
        return $mode === 0700;
    }

    /** Write the mark. A workspace with the mark never starts outside the private disk view. */
    public static function markRestricted(string $workspaceId): bool
    {
        $file = self::markFile($workspaceId);
        if ($file === '' || !self::ensureViewDir()) return false;
        $existing = @lstat($file);
        if (is_array($existing)) {
            if (is_link($file) || (($existing['mode'] & 0170000) !== 0100000)
                || (int)$existing['uid'] !== self::ownerOfPlugin() || (int)$existing['nlink'] !== 1) return false;
            if ((((int)$existing['mode'] & 0777) !== 0400) && !@chmod($file, 0400)) return false;
            $after = @lstat($file);
            return is_array($after) && !self::isLinkNow($file) && (($after['mode'] & 0170000) === 0100000)
                && (int)$after['uid'] === self::ownerOfPlugin() && (int)$after['nlink'] === 1
                && (((int)$after['mode'] & 0777) === 0400);
        }
        $old = umask(0077);
        try { $h = @fopen($file, 'x'); }
        finally { umask($old); }
        if ($h === false) return false;
        $ok = fwrite($h, self::USER . "\n") === strlen(self::USER . "\n");
        fclose($h);
        if (!$ok || !@chmod($file, 0400)) { @unlink($file); return false; }
        $after = @lstat($file);
        return is_array($after) && !is_link($file) && (($after['mode'] & 0170000) === 0100000)
            && (int)$after['uid'] === self::ownerOfPlugin() && (int)$after['nlink'] === 1
            && (((int)$after['mode'] & 0777) === 0400);
    }

    public static function isMarkedRestricted(string $workspaceId): bool
    {
        $file = self::markFile($workspaceId);
        return $file !== '' && is_file($file) && !is_link($file);
    }

    /** Keep a root-only sign-in marker outside the run-user-owned private folder until release saves sign-in files. */
    public static function markSigninWorkspace(string $workspaceId): bool
    {
        $id = self::safeId($workspaceId);
        if ($id === null || !self::ensureViewDir()) return false;
        if (!self::migrateLegacySigninMarker($id, true)) return false;
        return self::ensureSigninMarkerFile($id);
    }

    /** Remove a valid sign-in marker after a new non-sign-in start or after sign-in files have been saved. */
    public static function clearSigninMarker(string $workspaceId): bool
    {
        $id = self::safeId($workspaceId);
        if ($id === null || !self::ensureViewDir() || !self::migrateLegacySigninMarker($id)) return false;
        $file = self::signinMarkerFile($id);
        $st = @lstat($file);
        if (!is_array($st)) return !is_link($file);
        if (is_link($file) || (($st['mode'] & 0170000) !== 0100000) || (int)$st['uid'] !== self::ownerOfPlugin()
            || (int)$st['nlink'] !== 1) return false;
        return @unlink($file);
    }

    private static function ensureSigninMarkerFile(string $id): bool
    {
        if (!self::ensureViewDir()) return false;
        if (self::isMarkedSigninWorkspace($id)) return true;
        $file = self::signinMarkerFile($id);
        $existing = @lstat($file);
        if (is_array($existing)) {
            if (is_link($file) || (($existing['mode'] & 0170000) !== 0100000)
                || (int)$existing['uid'] !== self::ownerOfPlugin() || (int)$existing['nlink'] !== 1
                || (((int)$existing['mode'] & 0777) === 0400)) return false;
            if (!@unlink($file)) return false;
        }

        $tmp = self::viewDir() . '/.' . $id . '.signin-' . bin2hex(random_bytes(8));
        $old = umask(0077);
        try { $h = @fopen($tmp, 'x+b'); }
        finally { umask($old); }
        if ($h === false) return false;
        $ok = fwrite($h, "1\n") === 2 && fflush($h);
        fclose($h);
        if (!$ok || !@chmod($tmp, 0400)) { @unlink($tmp); return false; }
        $st = @lstat($tmp);
        if (!is_array($st) || is_link($tmp) || (($st['mode'] & 0170000) !== 0100000)
            || (int)$st['uid'] !== self::ownerOfPlugin() || (int)$st['nlink'] !== 1 || (((int)$st['mode'] & 0777) !== 0400)) {
            @unlink($tmp);
            return false;
        }
        if (file_exists($file) || is_link($file) || !@rename($tmp, $file)) { @unlink($tmp); return false; }
        return self::isMarkedSigninWorkspace($id);
    }

    /** Move the old private-folder marker out before a start or release examines the handed-over folder. */
    private static function migrateLegacySigninMarker(string $id, bool $recordSaysSignin = false): bool
    {
        $dir = self::privateDir($id);
        if ($dir === '') return false;
        $dirStat = @lstat($dir);
        if (!is_array($dirStat)) return !is_link($dir);
        $root = self::privateRoot();
        // #929: an older root with mode 0711 is closed here (idempotent), so a release before the first sweep still works.
        if (!self::ensurePrivateRootHidden(false)) return false;
        $rootStat = @lstat($root);
        $rootReal = @realpath($root);
        $dirReal = @realpath($dir);
        $runUid = self::runUid();
        if (is_link($dir) || (($dirStat['mode'] & 0170000) !== 0040000) || $dirReal !== $dir
            || !is_array($rootStat) || is_link($root) || (($rootStat['mode'] & 0170000) !== 0040000)
            || (int)$rootStat['uid'] !== self::ownerOfPlugin() || (((int)$rootStat['mode'] & 0777) !== 0700)
            || $rootReal === false || dirname($dirReal) !== $rootReal || !in_array(((int)$dirStat['mode'] & 0777), [0500, 0700, 0711], true)
            || ((int)$dirStat['uid'] !== self::ownerOfPlugin() && ($runUid === null || (int)$dirStat['uid'] !== $runUid))) return false;

        $legacy = $dir . '/signin-workspace';
        $legacyStat = @lstat($legacy);
        if (!is_array($legacyStat)) return !is_link($legacy);
        if (is_link($legacy) || (($legacyStat['mode'] & 0170000) !== 0100000) || (int)$legacyStat['nlink'] !== 1) return false;

        $legacyOwner = (int)$legacyStat['uid'];
        $dirOwner = (int)$dirStat['uid'];
        if ($runUid !== null && $legacyOwner === $runUid && $dirOwner === $runUid) {
            // Older releases handed this marker to aicli-run with the other files. It is not proof of sign-in.
            $after = @lstat($legacy);
            if (!self::sameFileIdentity($legacyStat, $after) || self::isLinkNow($legacy)) return false;
            return @unlink($legacy);
        }
        if ($legacyOwner !== self::ownerOfPlugin()) return false;

        if (defined('AICLI_TEST_TMP') && self::$legacySigninSwapForTests !== null) {
            (self::$legacySigninSwapForTests)($legacy, 'before-read');
        }
        // L1: a plain fopen() blocks for ever on a FIFO that replaced the file after the lstat. The bounded reader (dd with
        // nofollow and nonblock, under a 5 second deadline) never blocks and refuses a link. It also compares the file
        // before and after. The identity (device and inode) is then compared with the lstat taken above.
        $contents = self::readUserFile($legacy, [self::ownerOfPlugin()], 3);
        $afterRead = @lstat($legacy);
        if ($contents === null || !self::sameFileIdentity($legacyStat, $afterRead) || self::isLinkNow($legacy)
            || (int)($afterRead['uid'] ?? -1) !== self::ownerOfPlugin() || (int)($afterRead['nlink'] ?? 0) !== 1) return false;
        if ($contents !== "1\n" && !$recordSaysSignin) return false;

        $marker = self::signinMarkerFile($id);
        $markerBefore = @lstat($marker);
        if (!self::ensureSigninMarkerFile($id)) return false;
        $markerAfter = @lstat($marker);
        $createdMarker = !self::sameFileIdentity($markerBefore, $markerAfter);

        if (defined('AICLI_TEST_TMP') && self::$legacySigninSwapForTests !== null) {
            (self::$legacySigninSwapForTests)($legacy, 'after-marker');
        }
        $afterMarker = @lstat($legacy);
        $legacyStillMatches = self::sameFileIdentity($legacyStat, $afterMarker) && !self::isLinkNow($legacy)
            && (int)($afterMarker['uid'] ?? -1) === self::ownerOfPlugin() && (int)($afterMarker['nlink'] ?? 0) === 1;
        if (!$legacyStillMatches || !@unlink($legacy)) {
            if ($createdMarker && is_array($markerAfter)) self::unlinkSameOwnedFile($marker, $markerAfter);
            return false;
        }
        return true;
    }

    /** Compare device and inode data from lstat() or fstat() without following a second path lookup. */
    private static function sameFileIdentity(array|false|null $expected, array|false|null $actual): bool
    {
        return is_array($expected) && is_array($actual)
            && (int)$expected['dev'] === (int)$actual['dev'] && (int)$expected['ino'] === (int)$actual['ino'];
    }

    /** Remove only the marker created by the current migration attempt. */
    private static function unlinkSameOwnedFile(string $path, array $expected): void
    {
        $current = @lstat($path);
        if (self::sameFileIdentity($expected, $current) && !is_link($path)
            && (int)($current['uid'] ?? -1) === self::ownerOfPlugin() && (int)($current['nlink'] ?? 0) === 1) @unlink($path);
    }

    public static function signinMarkerFile(string $workspaceId): string
    {
        $id = self::safeId($workspaceId);
        return $id === null ? '' : self::viewDir() . '/' . $id . '.signin';
    }

    private static function isMarkedSigninWorkspace(string $workspaceId): bool
    {
        $dir = self::viewDir();
        $id = self::safeId($workspaceId);
        if ($id === null) return false;
        $dirStat = @lstat($dir);
        if (!is_array($dirStat) || is_link($dir) || (($dirStat['mode'] & 0170000) !== 0040000)
            || (int)$dirStat['uid'] !== self::ownerOfPlugin() || (((int)$dirStat['mode'] & 0777) !== 0700)) return false;
        $file = self::signinMarkerFile($id);
        $st = @lstat($file);
        return is_array($st) && (($st['mode'] & 0170000) === 0100000) && (int)$st['uid'] === self::ownerOfPlugin()
            && (int)$st['nlink'] === 1 && (((int)$st['mode'] & 0777) === 0400)
            && @file_get_contents($file) === "1\n";
    }

    /**
     * Free what a restricted workspace holds outside its record: the persistent mount space (it keeps the whole view alive
     * until it is unmounted), the mark and the private files. Best effort. Call it when the workspace is closed.
     */
    public static function releaseWorkspace(string $workspaceId, bool $recordSaysSignin = false): void
    {
        $id = self::safeId($workspaceId);
        if ($id === null) return;
        if (!self::stopRuntime($id)) {
            error_log('aicli-run: workspace ' . $id . ' still has a process; its view and network guard stay in place');
            return;
        }
        if ($recordSaysSignin) {
            $markerReady = self::migrateLegacySigninMarker($id, true) && self::ensureSigninMarkerFile($id);
        } else {
            // A stale marker cannot overrule the workspace record. Clear both the
            // current marker and any valid old root-owned marker before release.
            $markerReady = self::clearSigninMarker($id);
        }
        if (!$markerReady) {
            error_log('aicli-run: the legacy sign-in marker for workspace ' . $id . ' could not be checked safely; its private home and runtime stay in place');
            return;
        }
        if ($recordSaysSignin && self::isMarkedSigninWorkspace($id) && !self::persistSigninFiles($id, true)) {
            error_log('aicli-run: sign-in files for workspace ' . $id . ' could not be saved; its private home and runtime stay in place');
            return;
        }
        if ($recordSaysSignin && self::isMarkedSigninWorkspace($id) && !self::clearSigninMarker($id)) {
            error_log('aicli-run: the sign-in marker for workspace ' . $id . ' could not be removed after its files were saved');
            return;
        }
        if (!self::removeRunScript($id)) {
            error_log('aicli-run: workspace ' . $id . ' start script could not be removed; its mark stays in place');
            return;
        }
        if (!self::removeSessionHome($id)) {
            error_log('aicli-run: workspace ' . $id . ' home could not be removed; its restricted mark stays in place');
            return;
        }
        if (!self::removeRestrictedTmuxDirs($id)) {
            error_log('aicli-run: the tmux folders of workspace ' . $id . ' could not be removed; the next sweep tries again');
        }
        if (!self::removeRunCgroup($id) || !self::removeNetworkNamespace($id)) {
            error_log('aicli-run: workspace ' . $id . ' runtime resources could not be removed; its restricted mark stays in place');
            return;
        }
        self::releaseView($id);
        $mark = self::markFile($id);
        if ($mark !== '' && is_file($mark) && !is_link($mark)) @unlink($mark);
        if (!self::removePrivateDir($id)) error_log('aicli-run: the private files of workspace ' . $id . ' could not be removed; the next start removes them');
        // Keep the guard until every process of the run user and every workspace mark is gone.
        if ((glob(self::viewDir() . '/*.runas') ?: []) === [] && self::processesOfRunUser() === []) self::removeNetworkGuard();
    }

    /**
     * Finding 14 of the second review: the start script of a run (the private home/aicli-run-<id>.sh) holds the lines of env.sh,
     * the keys of the workspace. It is removed when the workspace closes, so a later run cannot read it. Root removes the file
     * by name (unlink never follows a link in the last part); the folder must be a real folder, not a link.
     */
    public static function removeRunScript(string $workspaceId): bool
    {
        $id = self::safeId($workspaceId);
        if ($id === null) return false;
        $dir = self::sessionHomePath($id);
        if ($dir === '' || $dir[0] !== '/') return false;
        if (!file_exists($dir) && !is_link($dir)) return true;
        if (is_link($dir) || !is_dir($dir)) return false;
        $file = rtrim($dir, '/') . '/aicli-run-' . $id . '.sh';
        $st = @lstat($file);
        if (!is_array($st)) return true;
        if (($st['mode'] & 0170000) === 0040000) return false;
        return @unlink($file);
    }

    /**
     * Claim the single place for a restricted run (findings 10 and 15 of the reviews). The check "no other restricted workspace is
     * open" and the root-only mark are made under one lock, so two starts that arrive together cannot both pass. A mark of
     * another workspace that is younger than two minutes counts as a start in progress. Returns '' when the claim is made, else
     * the id of the workspace that holds the place ('?' when the lock or the mark failed).
     */
    public static function claimRestrictedStart(string $workspaceId): string
    {
        $id = self::safeId($workspaceId);
        if ($id === null) return '?';
        if (!self::lockRestrictedStart()) return '?';
        try {
            $other = self::otherOpenRestricted($id);
            if ($other !== '') return $other;
            foreach (glob(self::viewDir() . '/*.runas') ?: [] as $m) {
                $o = basename($m, '.runas');
                if ($o === $id || is_link($m)) continue;
                $age = time() - (int)@filemtime($m);
                if ($age < 120) return $o;
            }
            if (!self::markRestricted($id)) return '?';
            @touch(self::markFile($id));
            return '';
        } finally {
            self::unlockRestrictedStart();
        }
    }

    /**
     * Before a restricted start: no process of the run user may be alive (counted by user id, not by tmux session), and the
     * network guard must be in place. A process left from an earlier run is ended; if one cannot be ended, or the rule cannot be
     * added, the start is refused. Returns '' when ready, else a sentence for the person.
     */
    public static function readyForRestrictedStart(bool $sessionIsRunning): string
    {
        if (!$sessionIsRunning && self::killRunUserProcesses() > 0) {
            return 'A process of the run user from an earlier run could not be ended, so this run was not started.';
        }
        $runtimeFault = self::runtimeRequirementsFault();
        if ($runtimeFault !== null) return $runtimeFault;
        $jobsFault = self::schedulerJobsFault();
        if ($jobsFault !== null) return $jobsFault;
        $guard = self::ensureNetworkGuard();
        return $guard['ok'] ? '' : $guard['reason'];
    }

    /** Prepare the kernel resource group and the isolated internet path for one workspace. */
    public static function prepareRuntime(string $workspaceId): array
    {
        $id = self::safeId($workspaceId);
        if ($id === null) return ['ok' => false, 'reason' => 'The workspace id for the private run is not valid.'];
        $cgroup = self::runtimeHelper('create', $id, 'cgroup');
        if ($cgroup !== 'ready') return ['ok' => false, 'reason' => 'The CPU, memory and process limits could not be set, so the restricted run was not started.'];
        $netns = self::runtimeHelper('prepare', $id, 'network');
        if ($netns !== self::networkNamespaceName($id)) {
            self::runtimeHelper('kill', $id, 'cgroup');
            self::runtimeHelper('remove', $id, 'cgroup');
            return ['ok' => false, 'reason' => 'The private network could not be limited to the internet, so the restricted run was not started.'];
        }
        return ['ok' => true, 'reason' => '', 'netns' => $netns];
    }

    /** Kill the run cgroup and require both it and the run uid to be empty before release. */
    public static function stopRuntime(string $workspaceId): bool
    {
        $id = self::safeId($workspaceId);
        if ($id === null) return false;
        if (self::cgroupPath($id) !== '') {
            self::runtimeHelper('kill', $id, 'cgroup');
            if (self::runtimeHelper('empty', $id, 'cgroup') !== 'empty') return false;
        }
        if (self::killRunUserProcesses() !== 0) return false;
        return true;
    }

    /** Run one trusted cgroup or network helper. */
    private static function runtimeHelper(string $action, string $id, string $kind): ?string
    {
        if (defined('AICLI_TEST_TMP')) {
            return self::$runtimeRunnerForTests !== null ? (self::$runtimeRunnerForTests)($kind . ':' . $action, $id) : null;
        }
        $src = dirname(__DIR__, 2);
        $script = $src . '/scripts/' . ($kind === 'cgroup' ? 'aicli-run-cgroup.sh' : 'aicli-run-network.sh');
        $out = self::runChild(['/bin/bash', $script, $action, $id], 30);
        return is_string($out) ? trim($out) : null;
    }

    /** True only when every runtime tool and cgroup v2 control exists on this host. */
    public static function runtimeRequirementsFault(): ?string
    {
        // CI fixtures must not probe or depend on the host's namespaces, tools or cgroups.
        if (defined('AICLI_TEST_TMP')) return null;
        foreach (['setpriv', 'unshare', 'nsenter', 'timeout', 'dd', 'bash', 'mount', 'umount', 'findmnt', 'sha256sum', 'env', 'php', 'runuser', 'ip', 'iptables', 'ip6tables', 'curl', 'prlimit', 'stat'] as $name) {
            if (self::runtimeToolPath($name) === '') return "Required isolation tool $name is missing, so the restricted run was not started.";
        }
        $setprivPath = self::runtimeToolPath('setpriv');
        $unsharePath = self::runtimeToolPath('unshare');
        $nsenterPath = self::runtimeToolPath('nsenter');
        $setpriv = self::runChild([$setprivPath, '--help']);
        foreach (['--no-new-privs', '--bounding-set', '--inh-caps', '--reuid', '--regid', '--clear-groups', '--keep-groups'] as $flag) {
            if (!is_string($setpriv) || strpos($setpriv, $flag) === false) return "The installed setpriv does not support $flag, so the restricted run was not started.";
        }
        $unshare = self::runChild([$unsharePath, '--help']);
        foreach (['--user', '--map-user', '--map-group', '--map-users', '--map-groups', '--setgroups', '--mount', '--pid', '--mount-proc', '--ipc', '--uts'] as $flag) {
            if (!is_string($unshare) || strpos($unshare, $flag) === false) return "The installed unshare does not support $flag, so the restricted run was not started.";
        }
        $nsenter = self::runChild([$nsenterPath, '--help']);
        foreach (['--user', '--setuid', '--setgid', '--mount', '--pid', '--ipc', '--uts', '--net', '--fork'] as $flag) {
            if (!is_string($nsenter) || strpos($nsenter, $flag) === false) return "The installed nsenter does not support $flag, so the restricted run was not started.";
        }
        $waitHelp = self::runChild(['/bin/bash', '-c', 'help wait']);
        if (!is_string($waitHelp) || strpos($waitHelp, '-n') === false) return 'Bash does not support child reaping in the private process space, so the restricted run was not started.';
        $root = self::path('cgroupRoot', '/sys/fs/cgroup');
        if (!is_file($root . '/cgroup.controllers') || !is_file($root . '/cgroup.subtree_control')) return 'Cgroup v2 is not ready, so the restricted run was not started.';
        foreach (['cpu', 'memory', 'pids'] as $controller) {
            if (!preg_match('/(?:^|\s)' . preg_quote($controller, '/') . '(?:\s|$)/', (string)@file_get_contents($root . '/cgroup.controllers'))
                || !preg_match('/(?:^|\s)' . preg_quote($controller, '/') . '(?:\s|$)/', (string)@file_get_contents($root . '/cgroup.subtree_control'))) {
                return "Cgroup v2 does not have the $controller limit enabled, so the restricted run was not started.";
            }
        }
        return null;
    }

    /** Refuse a start when aicli-run has any queued cron or at job, or a spool cannot be checked. */
    public static function schedulerJobsFault(): ?string
    {
        $uid = self::runUid();
        if ($uid === null) return 'The run user is missing, so its scheduler queues cannot be checked.';
        $spools = self::path('cronSpools', '/var/spool/cron') . "\n" . self::path('atSpools', '/var/spool/atjobs');
        $spools .= "\n" . self::path('cronSpoolsNested', '/var/spool/cron/crontabs') . "\n" . self::path('atSpoolsNested', '/var/spool/at');
        foreach (array_unique(array_filter(explode("\n", $spools))) as $root) {
            if (!file_exists($root) && !is_link($root)) continue;
            $found = self::spoolHasRunUserFile($root, $uid);
            if ($found !== false) return $found === true
                ? 'A cron or at job is queued for the run user. Remove it before starting a restricted run.'
                : 'The cron and at queues could not be checked safely, so the restricted run was not started.';
        }
        return null;
    }

    /** @return bool|null true when a run-user file exists, null when the tree cannot be checked. */
    private static function spoolHasRunUserFile(string $path, int $uid): ?bool
    {
        $st = @lstat($path);
        if (!is_array($st) || is_link($path)) return null;
        if (($st['mode'] & 0170000) !== 0040000) return (int)$st['uid'] === $uid;
        $dir = @opendir($path);
        if ($dir === false) return null;
        try {
            while (($name = readdir($dir)) !== false) {
                if ($name === '.' || $name === '..') continue;
                $found = self::spoolHasRunUserFile($path . '/' . $name, $uid);
                if ($found !== false) return $found;
            }
        } finally { closedir($dir); }
        return false;
    }

    /** Remove the network namespace and its host firewall rules after the run has stopped. */
    private static function removeNetworkNamespace(string $id): bool
    {
        if (defined('AICLI_TEST_TMP') && self::$runtimeRunnerForTests === null) return true;
        if (self::$runtimeRunnerForTests === null) {
            $netns = rtrim(self::path('netnsRoot', '/var/run/netns'), '/') . '/' . self::networkNamespaceName($id);
            $cgroup = self::cgroupPath($id);
            if (!file_exists($netns) && ($cgroup === '' || !file_exists($cgroup))
                && !file_exists(self::nsFile($id)) && !file_exists(self::pidNsFile($id)) && !file_exists(self::userNsFile($id))
                && !file_exists(self::ipcNsFile($id)) && !file_exists(self::utsNsFile($id)) && !file_exists(self::childUserNsFile($id))) return true;
        }
        return self::runtimeHelper('remove', $id, 'network') !== null;
    }

    /** Remove the empty per-workspace cgroup. */
    private static function removeRunCgroup(string $id): bool
    {
        if (defined('AICLI_TEST_TMP') && self::$runtimeRunnerForTests === null) return true;
        $path = self::cgroupPath($id);
        if (self::$runtimeRunnerForTests === null && ($path === '' || !file_exists($path))) return true;
        return self::runtimeHelper('remove', $id, 'cgroup') !== null;
    }

    /**
     * Make a new persistent mount space file for a start: the old view of the workspace is released first, then an empty
     * root-only file is made for `unshare --mount=<file>` to bind the new space to. Returns the file, or '' on a fault.
     */
    public static function prepareView(string $workspaceId, bool $signInWorkspace = false): string
    {
        $ns = self::nsFile($workspaceId);
        $pid = self::pidNsFile($workspaceId);
        $userns = self::userNsFile($workspaceId);
        $ipc = self::ipcNsFile($workspaceId);
        $uts = self::utsNsFile($workspaceId);
        $childUserns = self::childUserNsFile($workspaceId);
        if ($ns === '' || $pid === '' || $userns === '' || $ipc === '' || $uts === '' || $childUserns === '' || !self::ensureViewDir()) return '';
        if (!$signInWorkspace && !self::clearSigninMarker($workspaceId)) return '';
        self::releaseView($workspaceId);
        // #929: a handshake pipe or staging folder that release could not remove is an earlier launch that is not finished.
        $idForHandshake = self::safeId($workspaceId);
        if ($idForHandshake === null) return '';
        foreach (['go', 'privstage'] as $suffix) {
            $leftover = self::viewDir() . '/' . $idForHandshake . '.' . $suffix;
            if (file_exists($leftover) || is_link($leftover)) return '';
        }
        $old = umask(0077);
        $created = [];
        $ok = true;
        try {
            foreach ([$ns, $pid, $userns, $ipc, $uts, $childUserns] as $file) {
                $handle = @fopen($file, 'x');
                if ($handle === false) { $ok = false; break; }
                fclose($handle);
                $created[] = $file;
            }
        }
        finally { umask($old); }
        if (!$ok) {
            foreach ($created as $file) @unlink($file);
            return '';
        }
        return $ns;
    }

    /**
     * Release every runtime of a stopped workspace. Keep its mark and resources when the workspace record is missing, so a
     * damaged save cannot turn the next start of its named folder into a root start.
     *
     * @return list<string> the workspace ids whose runtime was released
     */
    public static function sweepViews(): array
    {
        $released = [];
        // #929: the first tick after an upgrade closes the older parents (mode 0711) without waiting for a start.
        self::ensureHiddenParents();
        self::closeLegacyRestrictedTmux();
        $dir = self::viewDir();
        if (!is_dir($dir) || is_link($dir)) return [];
        foreach (glob($dir . '/*.mnt') ?: [] as $file) {
            $id = basename($file, '.mnt');
            if (self::safeId($id) === null || is_link($file)) continue;
            $record = SpawnService::findRecord($id);
            $spawn = is_array($record['spawn'] ?? null) ? $record['spawn'] : [];
            $isSignInWorkspace = is_array($spawn['runUserSignIn'] ?? null);
            if (!defined('AICLI_TEST_TMP') || self::$runtimeRunnerForTests !== null) {
                if (self::runtimeHelper('refresh', $id, 'network') !== 'ready') {
                    // Stop a live run when the public-address guard cannot be refreshed. Keep an orphan's mark and rules
                    // because no saved workspace record proves that a later start may run as the restricted user.
                    if ($record === null && self::isMarkedRestricted($id)) self::stopRuntime($id);
                    else self::releaseWorkspace($id, $isSignInWorkspace);
                    continue;
                }
            }
            try {
                if (ProcessManager::isRunning($id) || ProcessManager::hasLiveTmuxSession($id) || self::processesOfRunUser() !== []) continue;
            } catch (\Throwable $e) {
                continue;
            }
            $mark = self::markFile($id);
            if ($mark !== '' && is_file($mark) && !is_link($mark) && time() - (int)@filemtime($mark) < 240) continue;
            // Keep an orphan's mark as proof that its named folder is restricted. A record that still exists is safe to
            // clean fully: releaseWorkspace waits for cgroup.kill, removes the fresh home and runtime network, then clears it.
            if ($record === null && self::isMarkedRestricted($id)) continue;
            self::releaseWorkspace($id, $isSignInWorkspace);
            if (!self::isMarkedRestricted($id) && !is_file(self::nsFile($id)) && !is_file(self::pidNsFile($id)) && !is_file(self::userNsFile($id))
                && !is_file(self::ipcNsFile($id)) && !is_file(self::utsNsFile($id)) && !is_file(self::childUserNsFile($id))) $released[] = $id;
        }
        return $released;
    }

    /** The view file of a workspace whose agent runs, or '' when there is none. */
    public static function existingView(string $workspaceId): string
    {
        $ns = self::nsFile($workspaceId);
        $pid = self::pidNsFile($workspaceId);
        $userns = self::userNsFile($workspaceId);
        $ipc = self::ipcNsFile($workspaceId);
        $uts = self::utsNsFile($workspaceId);
        $childUserns = self::childUserNsFile($workspaceId);
        $files = [$ns, $pid, $userns, $ipc, $uts, $childUserns];
        return !in_array('', $files, true) && count(array_filter($files, static fn(string $file): bool => is_file($file) && !is_link($file))) === count($files) ? $ns : '';
    }

    /** Unmount and remove the persistent mount space of a workspace (before a new start, and at close). */
    public static function releaseView(string $workspaceId): void
    {
        $ns = self::nsFile($workspaceId);
        $pid = self::pidNsFile($workspaceId);
        $userns = self::userNsFile($workspaceId);
        $ipc = self::ipcNsFile($workspaceId);
        $uts = self::utsNsFile($workspaceId);
        $childUserns = self::childUserNsFile($workspaceId);
        $files = [$childUserns, $pid, $userns, $ipc, $uts, $ns];
        if (!array_filter($files, static fn(string $file): bool => $file !== '')) return;
        if (!defined('AICLI_TEST_TMP')) {
            foreach ($files as $file) {
                if ($file === '' || (!file_exists($file) && !is_link($file))) continue;
                $proc = @proc_open(['/bin/umount', '--', $file], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
                if (is_resource($proc)) proc_close($proc);
            }
        }
        foreach ($files as $file) if ($file !== '' && is_file($file) && !is_link($file)) @unlink($file);
        $id = self::safeId($workspaceId);
        if ($id !== null) foreach (['ready', 'supervisor', 'launch.log', 'go'] as $suffix) @unlink(self::viewDir() . '/' . $id . '.' . $suffix);
        // #929: the staging folder of the private-folder bind. Only an empty folder is removed, never a tree.
        if ($id !== null) {
            $stage = self::viewDir() . '/' . $id . '.privstage';
            if (is_dir($stage) && !is_link($stage)) @rmdir($stage);
        }
    }

    /**
     * M2: remove the private folder of a workspace at release. The folder is root-owned (mode 0700 while staged, 0711 when
     * sealed), so only root changes its entries and root never works inside a folder that the run user owns. Root unlinks
     * the files by name (a link is removed, never followed) and removes the empty folder. An OLD folder that the run user
     * owns (an earlier release made them) is removed by removeOwnedTree(), which walks with openat and never follows a link.
     */
    private static function removePrivateDir(string $id): bool
    {
        $dir = self::privateDir($id);
        if ($dir === '') return true;
        $st = @lstat($dir);
        if (!is_array($st)) return !is_link($dir);
        if (is_link($dir) || (($st['mode'] & 0170000) !== 0040000)) return @unlink($dir);
        if ((int)$st['uid'] === self::ownerOfPlugin()) return self::removeFlatRootDir($dir);
        // An OLD folder that the run user owns: the walk by GNU rm (openat, one file system) as for every run-user tree.
        return self::removeOwnedTree($dir, (int)$st['uid']);
    }

    /**
     * Remove a root-owned folder that holds only files. A link or another non-folder entry is unlinked. A folder inside
     * stops the removal (the private folder never holds one), so root never enters a tree it did not make.
     */
    private static function removeFlatRootDir(string $dir): bool
    {
        $st = @lstat($dir);
        if (!is_array($st)) return true;
        if (is_link($dir) || (($st['mode'] & 0170000) !== 0040000) || (int)$st['uid'] !== self::ownerOfPlugin()) return false;
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') continue;
            $entry = $dir . '/' . $name;
            $est = @lstat($entry);
            if (!is_array($est)) continue;
            if (($est['mode'] & 0170000) === 0040000) return false;
            if (!@unlink($entry)) return false;
        }
        return @rmdir($dir) || !file_exists($dir);
    }

    /**
     * M1: older releases made each workspace folder below run-private root-owned with mode 0755 and env.sh with 0644 (the
     * keys of the workspace). The caller closes the parent (mode 0700) after this, so it runs first. A root-owned
     * folder that group or other can read or write gets mode 0700, and its root-owned files that group or other can reach get
     * 0600. A link or any other entry that is not a folder is unlinked (the link, never its target). A folder that the run
     * user owns is not entered: the start reset removes it. Only root can change the entries here, so nobody can swap one.
     */
    private static function hardenLegacyPrivateChildren(string $root): bool
    {
        foreach (scandir($root) ?: [] as $name) {
            if ($name === '.' || $name === '..') continue;
            $child = $root . '/' . $name;
            $st = @lstat($child);
            if (!is_array($st)) continue;
            if (($st['mode'] & 0170000) !== 0040000) {
                if (!@unlink($child)) return false;
                continue;
            }
            if ((int)$st['uid'] !== self::ownerOfPlugin()) continue;
            if ((((int)$st['mode'] & 0066) !== 0) && !@chmod($child, 0700)) return false;
            foreach (scandir($child) ?: [] as $fileName) {
                if ($fileName === '.' || $fileName === '..') continue;
                $file = $child . '/' . $fileName;
                $fst = @lstat($file);
                if (!is_array($fst)) continue;
                if (($fst['mode'] & 0170000) !== 0100000) {
                    if (($fst['mode'] & 0170000) !== 0040000 && !@unlink($file)) return false;
                    continue;
                }
                if ((int)$fst['uid'] === self::ownerOfPlugin() && (((int)$fst['mode'] & 0077) !== 0) && !@chmod($file, 0600)) return false;
            }
        }
        return true;
    }

    /** Non-listable root-owned parent for each workspace's private files. */
    public static function privateRoot(): string
    {
        return rtrim(self::path('privateRoot', SecretPaths::stateRoot() . '/run-private'), '/');
    }

    public static function privateDir(string $workspaceId): string
    {
        $id = self::safeId($workspaceId);
        return $id === null ? '' : self::privateRoot() . '/' . $id;
    }

    /**
     * Write a file into the private folder of a workspace: <state root>/run-private/<id>/<name>. Root keeps the parent
     * non-listable and the workspace folder root-only while it writes. The file uses a random temporary name and rename.
     * Before launch, preparePrivateDirForRunUser hands only args.json and env.sh to aicli-run as read-only files. The
     * root-owned mode 0400 sign-in marker stays in /var/run/aicli-view for release checks, outside this folder and the run view.
     */
    public static function writePrivateFile(string $workspaceId, string $name, string $body): bool
    {
        $id = self::safeId($workspaceId);
        if ($id === null || !in_array($name, ['args.json', 'env.sh'], true)) return false;
        $dir = self::privateDir($id);
        if ($dir === '') return false;
        $root = self::privateRoot();
        // #929: the parent of all private folders is root-owned mode 0700 for its whole life. The run user cannot walk it from
        // the host. Root binds only this workspace's folder into the view (aicli-run-private-bind.sh).
        if (!self::ensurePrivateRootHidden()) return false;
        $rst = @lstat($root);
        $parent = @lstat(dirname($root));
        if (!is_array($rst) || is_link($root) || (int)$rst['uid'] !== self::ownerOfPlugin() || (((int)$rst['mode'] & 0777) !== 0700)
            || !is_array($parent) || (int)$parent['uid'] !== self::ownerOfPlugin() || (($parent['mode'] & 0022) !== 0 && ($parent['mode'] & 01000) === 0)) return false;
        $runAccount = self::status();
        if (!self::resetLegacyPrivateDirForStart($id, $runAccount['uid'], $runAccount['gid'])) return false;
        if (!is_dir($dir) && !@mkdir($dir, 0700)) return false;
        $dst = @lstat($dir);
        if (!is_array($dst) || is_link($dir) || (($dst['mode'] & 0170000) !== 0040000) || (int)$dst['uid'] !== self::ownerOfPlugin() || (((int)$dst['mode'] & 0777) !== 0700)) return false;
        $tmp = $dir . '/.tmp-' . bin2hex(random_bytes(8));
        $old = umask(0077);
        try { $h = @fopen($tmp, 'x+b'); }
        finally { umask($old); }
        if ($h === false) return false;
        $ok = fwrite($h, $body) === strlen($body);
        fclose($h);
        $ok = $ok && @chmod($tmp, 0400) && @rename($tmp, $dir . '/' . $name);
        if (!$ok) @unlink($tmp);
        return $ok;
    }

    /**
     * Replace the old private folder of a previous start. A sealed root-owned folder is removed by root (it holds only files).
     * An older folder that the run user owns (mode 0500) is removed by root with GNU rm, but only when the workspace has no view
     * and no running agent. Root then makes a fresh mode 0700 folder.
     */
    private static function resetLegacyPrivateDirForStart(string $id, ?int $uid, ?int $gid): bool
    {
        $dir = self::privateDir($id);
        $root = self::privateRoot();
        $before = @lstat($dir);
        if (!is_array($before)) return !is_link($dir);
        if (is_link($dir) || (($before['mode'] & 0170000) !== 0040000)) return false;
        if ((int)$before['uid'] === self::ownerOfPlugin()) {
            if ((((int)$before['mode'] & 0777) === 0700)) return true;
            // M2: a sealed folder of an earlier start (root-owned, mode 0711, files given to the run user) holds only files.
            // Root owns every level, so the run user cannot swap anything. Remove it and make a fresh staging folder.
            if (!self::removeFlatRootDir($dir) || file_exists($dir) || self::isLinkNow($dir) || !@mkdir($dir, 0700)) return false;
            $fresh = @lstat($dir);
            return is_array($fresh) && !self::isLinkNow($dir) && (($fresh['mode'] & 0170000) === 0040000)
                && (int)$fresh['uid'] === self::ownerOfPlugin() && (((int)$fresh['mode'] & 0777) === 0700);
        }
        if ($uid === null || $gid === null || (int)$before['uid'] !== $uid || (int)$before['gid'] !== $gid
            || (((int)$before['mode'] & 0777) !== 0500)) return false;

        // #929: the parent is root-owned mode 0700, so no process of the run user can reach this old folder from the host. A
        // setpriv child as the run user could not enter it either. Root removes it with GNU rm (openat, one file system), but
        // only when this workspace has no view and no running agent: nothing of the run user can then reach it at all.
        $rootStat = @lstat($root);
        $rootReal = @realpath($root);
        $dirReal = @realpath($dir);
        if (!is_array($rootStat) || is_link($root) || (($rootStat['mode'] & 0170000) !== 0040000)
            || (int)$rootStat['uid'] !== self::ownerOfPlugin() || (((int)$rootStat['mode'] & 0777) !== 0700)
            || !is_string($rootReal) || $dirReal !== $dir || dirname($dirReal) !== $rootReal) return false;
        if (self::existingView($id) !== '' || ProcessManager::isRunning($id)) {
            LogService::log('The old private flags folder of ' . $id . ' stays: the workspace has a view or a running agent. A fresh start is needed.', LogService::LOG_WARN, 'RunUserService');
            return false;
        }
        if (!self::removeOwnedTree($dir, $uid) || file_exists($dir) || self::isLinkNow($dir) || !@mkdir($dir, 0700)) return false;
        $fresh = @lstat($dir);
        return is_array($fresh) && !self::isLinkNow($dir) && (($fresh['mode'] & 0170000) === 0040000)
            && (int)$fresh['uid'] === self::ownerOfPlugin() && (((int)$fresh['mode'] & 0777) === 0700);
    }

    /**
     * M2: the sealed private folder of a workspace is a real folder that root owns, with mode 0711 (the run user can search
     * it and cannot list or change it). A folder of the older layout (owned by the run user, mode 0500) is not sealed. The launch checks this before it binds the two files into the view.
     */
    public static function privateDirIsSealed(string $workspaceId): bool
    {
        return self::sealedPrivateDirIdentity($workspaceId) !== '';
    }

    /**
     * #929: one lstat that checks a sealed folder (a real folder, root-owned, mode 0711) and returns its device and inode as
     * `dev:ino`, or '' when it is not sealed. The launch passes this identity to the root bind, which refuses a folder that
     * was swapped after this check.
     */
    public static function sealedPrivateDirIdentity(string $workspaceId): string
    {
        $dir = self::privateDir($workspaceId);
        if ($dir === '') return '';
        $st = @lstat($dir);
        if (!is_array($st) || is_link($dir) || (($st['mode'] & 0170000) !== 0040000)
            || (int)$st['uid'] !== self::ownerOfPlugin() || (((int)$st['mode'] & 0777) !== 0711)) return '';
        return (int)$st['dev'] . ':' . (int)$st['ino'];
    }

    /** The folder of the run-user homes: <state root>/work/aicli-run-sessions (one folder <id>/home for each workspace). */
    public static function sessionsRoot(): string
    {
        return rtrim(SecretPaths::stateRoot(), '/') . '/work/aicli-run-sessions';
    }

    /**
     * #929: make a folder that the run user must not walk: a real folder, root-owned, mode 0700. A missing folder is made. An older
     * root-owned folder (mode 0711, or 0755) is closed. Any other owner, a link, or a failed re-check returns false.
     */
    private static function ensureRootOnlyDirectory(string $path, bool $create = true): bool
    {
        if (is_link($path)) return false;
        $st = @lstat($path);
        if (!is_array($st) && !$create) return true;
        // The parent is checked before any mkdir or chmod (PHP chmod follows links): a real folder, owned by the plugin user,
        // that group and other cannot change (a sticky folder is allowed, as in handOver()). A missing parent is refused, never made.
        $parent = dirname($path);
        $pst = @lstat($parent);
        if (!is_array($pst) || is_link($parent) || (($pst['mode'] & 0170000) !== 0040000) || (int)$pst['uid'] !== self::ownerOfPlugin()
            || ((($pst['mode'] & 0022) !== 0) && (($pst['mode'] & 01000) === 0))) return false;
        if (!is_array($st)) {
            $old = umask(0077);
            try { $made = @mkdir($path, 0700); }
            finally { umask($old); }
            if (!$made && !is_dir($path)) return false;
            $st = @lstat($path);
        }
        if (!is_array($st) || self::isLinkNow($path) || (($st['mode'] & 0170000) !== 0040000) || (int)$st['uid'] !== self::ownerOfPlugin()) return false;
        // A folder that group or other can change may already hold planted entries or links: it is refused, never closed.
        if ((((int)$st['mode']) & 0022) !== 0) return false;
        if ((((int)$st['mode'] & 0777) !== 0700) && !@chmod($path, 0700)) return false;
        $after = @lstat($path);
        return is_array($after) && !self::isLinkNow($path) && (($after['mode'] & 0170000) === 0040000)
            && (int)$after['uid'] === self::ownerOfPlugin() && (((int)$after['mode'] & 0777) === 0700)
            && (int)$after['dev'] === (int)$st['dev'] && (int)$after['ino'] === (int)$st['ino'];
    }

    /**
     * #929: the parent of all private folders (run-private) is root-owned mode 0700, so no path from the host leads to the private
     * files of any workspace. The old folders of earlier releases are hardened first (mode 0755, readable env.sh), then the
     * parent is closed. With $create false a missing parent is left alone.
     */
    public static function ensurePrivateRootHidden(bool $create = true): bool
    {
        $root = self::privateRoot();
        if (is_link($root)) return false;
        $st = @lstat($root);
        if (is_array($st) && (($st['mode'] & 0170000) === 0040000) && (int)$st['uid'] === self::ownerOfPlugin()
            && in_array(((int)$st['mode'] & 0777), [0700, 0711], true) && !self::hardenLegacyPrivateChildren($root)) return false;
        return self::ensureRootOnlyDirectory($root, $create);
    }

    /** The root-only parent of the tmux socket folders of restricted workspaces: <state root>/run-tmux (#929). */
    public static function runTmuxRoot(): string
    {
        return rtrim(self::path('runTmuxRoot', SecretPaths::stateRoot() . '/run-tmux'), '/');
    }

    /** The host folder of the tmux sockets of one restricted workspace. Only root can walk the parent. '' for a bad id. */
    public static function runTmuxDir(string $workspaceId): string
    {
        $id = self::safeId($workspaceId);
        return $id === null ? '' : self::runTmuxRoot() . '/s-' . $id;
    }

    /** The empty mount point in the shared tmux folder. The view shows the folder of the workspace here, so the path in the view does not change. */
    public static function tmuxMountPoint(string $workspaceId): string
    {
        $id = self::safeId($workspaceId);
        return $id === null ? '' : rtrim(SecretPaths::stateRoot(), '/') . '/tmux/s-' . $id;
    }

    /** Make or close the root-only parent of the restricted tmux folders (mode 0700, root-owned). */
    public static function ensureRunTmuxRootHidden(bool $create = true): bool
    {
        return self::ensureRootOnlyDirectory(self::runTmuxRoot(), $create);
    }

    /**
     * #929: make <state root>/tmux/s-<id> an empty folder that root owns, the mount point for the bind of the real folder. An older
     * release kept the sockets in that folder, owned by the run user: it is removed (nothing of the run user runs at a start). Any
     * other owner or a link makes the start fail.
     */
    public static function prepareTmuxMountPoint(string $workspaceId): bool
    {
        $point = self::tmuxMountPoint($workspaceId);
        if ($point === '') return false;
        $st = @lstat($point);
        if (is_array($st)) {
            $isDir = !is_link($point) && (($st['mode'] & 0170000) === 0040000);
            $uid = (int)$st['uid'];
            $status = self::status();
            $runUid = !empty($status['ready']) ? (int)$status['uid'] : -1;
            if ($isDir && $uid === self::ownerOfPlugin()) {
                if (!self::removeRootTree($point, false)) return false;
            } elseif ($isDir && $uid === $runUid) {
                if (self::processesOfRunUser() !== []) return false;
                if (!self::removeOwnedTree($point, $uid)) return false;
            } elseif (!self::quarantineForeignEntry($point)) {
                // A link, a file, or a folder of any other user was planted in the shared sticky folder.
                return false;
            }
        }
        if (!file_exists($point)) {
            $old = umask(0022);
            try { $made = @mkdir($point, 0755); }
            finally { umask($old); }
            if (!$made) return false;
        }
        $after = @lstat($point);
        return is_array($after) && !is_link($point) && (($after['mode'] & 0170000) === 0040000)
            && (int)$after['uid'] === self::ownerOfPlugin() && (((int)$after['mode'] & 0022) === 0)
            && array_values(array_diff(scandir($point) ?: ['x'], ['.', '..'])) === [];
    }

    /**
     * #929: move an entry that another user planted (a folder, a link or a file in the shared sticky tmux folder) into the
     * root-only quarantine folder <run-tmux>/.quarantine. Root may rename in a sticky folder. The move is one rename(): a link is
     * moved as a link and is never followed, and nothing below the entry is read or removed. The move is logged. Returns false when
     * the quarantine folder is not available or the rename fails (the start then fails).
     */
    public static function quarantineForeignEntry(string $path): bool
    {
        $st = @lstat($path);
        if (!is_array($st)) return true;
        if (!self::ensureRunTmuxRootHidden(true)) return false;
        $quarantine = self::runTmuxRoot() . '/.quarantine';
        if (!self::ensureRootOnlyDirectory($quarantine, true)) return false;
        $target = $quarantine . '/' . basename($path) . '.' . gmdate('YmdHis') . '.' . bin2hex(random_bytes(4));
        if (file_exists($target) || is_link($target) || !@rename($path, $target)) {
            error_log('aicli-run: the entry ' . $path . ' (owner ' . (int)$st['uid'] . ') could not be moved to the quarantine folder');
            return false;
        }
        error_log('aicli-run: the entry ' . $path . ' (owner ' . (int)$st['uid'] . ') was not made by the plugin; it was moved to ' . $target);
        return true;
    }

    /** Remove the tmux folders of a restricted workspace: the root-only folder and the mount point. Nothing of the run user may run. */
    public static function removeRestrictedTmuxDirs(string $workspaceId): bool
    {
        $ok = true;
        foreach ([self::runTmuxDir($workspaceId), self::tmuxMountPoint($workspaceId)] as $dir) {
            if ($dir === '') continue;
            $st = @lstat($dir);
            if (!is_array($st)) continue;
            if (is_link($dir) || (($st['mode'] & 0170000) !== 0040000)) { $ok = @unlink($dir) && $ok; continue; }
            $done = (int)$st['uid'] === self::ownerOfPlugin() ? self::removeRootTree($dir) : self::removeOwnedTree($dir, (int)$st['uid']);
            if (!$done || file_exists($dir)) $ok = false;
        }
        return $ok;
    }

    /**
     * #929: an older release kept the tmux sockets of a restricted workspace in <state root>/tmux/s-<id>, owned by the run user, under
     * the shared sticky parent. When no view and no process of the run user is left, the sweep removes such a folder.
     */
    public static function closeLegacyRestrictedTmux(): void
    {
        if (self::processesOfRunUser() !== []) return;
        foreach (glob(rtrim(SecretPaths::stateRoot(), '/') . '/tmux/s-*', GLOB_ONLYDIR) ?: [] as $dir) {
            $id = substr(basename($dir), 2);
            if (self::safeId($id) === null || is_link($dir) || !self::isMarkedRestricted($id) || self::existingView($id) !== '') continue;
            $st = @lstat($dir);
            if (is_array($st) && (int)$st['uid'] !== self::ownerOfPlugin()) self::removeOwnedTree($dir, (int)$st['uid']);
        }
    }
    /**
     * #929: close every existing per-workspace parent to the run user: run-private, the folder of the run-user homes and the folder
     * of the task folders. The supervisor sweep calls it, so the first tick after an upgrade closes the older mode 0711 folders.
     */
    public static function ensureHiddenParents(): bool
    {
        $ok = self::ensurePrivateRootHidden(false);
        foreach ([self::sessionsRoot(), self::taskRoot(), self::runTmuxRoot()] as $parent) {
            if (!self::ensureRootOnlyDirectory($parent, false)) $ok = false;
        }
        return $ok;
    }

    /** Hand only this workspace's private files to aicli-run as read-only data. The parent stays closed (mode 0700); the folder is sealed (mode 0711). */
    public static function preparePrivateDirForRunUser(string $workspaceId, int $uid, int $gid): bool
    {
        $id = self::safeId($workspaceId);
        $root = self::privateRoot();
        $dir = self::privateDir($workspaceId);
        $account = self::status();
        if ($id === null || $dir === '' || empty($account['ready']) || $uid !== (int)$account['uid'] || $gid !== (int)$account['gid']) return false;
        // #929: also on a bridge reconnect, an older parent with mode 0711 is closed here.
        if (!self::ensurePrivateRootHidden(false)) return false;
        $rootStat = @lstat($root);
        $dirStat = @lstat($dir);
        if (!is_array($rootStat) || is_link($root) || (($rootStat['mode'] & 0170000) !== 0040000)
            || (int)$rootStat['uid'] !== self::ownerOfPlugin() || (((int)$rootStat['mode'] & 0777) !== 0700)
            || !is_array($dirStat) || is_link($dir) || (($dirStat['mode'] & 0170000) !== 0040000)) return false;

        // M2: the folder stays root-owned for its whole life. Staging is mode 0700 with root-owned files. Sealed is mode 0711
        // (the run user can search, not list) with only args.json and env.sh given to the run user as mode 0400 files. The run
        // user cannot add, rename or remove an entry, so root never works inside a folder that the run user owns. The mount
        // helper needs the run user as owner: its namespace has no DAC override over root-owned files, and a group read
        // (mode 0440) would let every account in the users group read the keys in env.sh. A folder of an older layout (owned by
        // the run user, mode 0500) is refused, also for a bridge reconnect: only a fresh start rebuilds the folder.
        $rootOwned = (int)$dirStat['uid'] === self::ownerOfPlugin();
        $sealed = $rootOwned && (((int)$dirStat['mode'] & 0777) === 0711);
        $staging = $rootOwned && (((int)$dirStat['mode'] & 0777) === 0700);
        if (!$sealed && !$staging) {
            LogService::log('The private flags folder of ' . $id . ' has the layout of an older release (owner uid ' . (int)$dirStat['uid']
                . ', mode ' . sprintf('%04o', (int)$dirStat['mode'] & 0777) . '). It is refused, including for a bridge reconnect. A fresh start is needed.', LogService::LOG_WARN, 'RunUserService');
            return false;
        }
        if (!self::migrateLegacySigninMarker($id)) return false;
        $files = [];
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') continue;
            $file = $dir . '/' . $name;
            $st = @lstat($file);
            if (!is_array($st) || is_link($file) || (($st['mode'] & 0170000) !== 0100000) || (int)$st['nlink'] !== 1) return false;
            if (!in_array($name, ['args.json', 'env.sh'], true)) return false;
            if (((int)$st['mode'] & 0777) !== 0400) return false;
            if ($sealed) {
                if ((int)$st['uid'] !== $uid || (int)$st['gid'] !== $gid) return false;
            } else {
                $given = (int)$st['uid'] === $uid && (int)$st['gid'] === $gid;
                if (!$given && ((int)$st['uid'] !== self::ownerOfPlugin() || !@chown($file, $uid) || !@chgrp($file, $gid))) return false;
            }
            $files[] = $file;
        }
        if ($files === []) return false;
        if ($sealed) return true;
        // The folder is root-owned mode 0700 here, so nobody else can change an entry while the files are given away.
        if (!@chmod($dir, 0711)) return false;
        $after = @lstat($dir);
        if (!is_array($after) || self::isLinkNow($dir) || (int)$after['uid'] !== self::ownerOfPlugin() || (((int)$after['mode'] & 0777) !== 0711)) return false;
        foreach ($files as $file) {
            $st = @lstat($file);
            if (!is_array($st) || is_link($file) || (($st['mode'] & 0170000) !== 0100000)
                || (int)$st['uid'] !== $uid || (int)$st['gid'] !== $gid || (int)$st['nlink'] !== 1 || (((int)$st['mode'] & 0777) !== 0400)) return false;
        }
        return true;
    }

    /**
     * Another restricted workspace that is open now (a run or a sign-in), or ''. Only one runs at a time, because they
     * share one user id and so could read each other's task folders and drive each other's tmux sockets (finding 10).
     */
    public static function otherOpenRestricted(string $exceptWorkspaceId = ''): string
    {
        try {
            foreach ((ConfigService::getWorkspaces()['sessions'] ?? []) as $w) {
                if (!is_array($w)) continue;
                $id = (string)($w['id'] ?? '');
                if ($id === '' || $id === $exceptWorkspaceId) continue;
                $spawn = is_array($w['spawn'] ?? null) ? $w['spawn'] : [];
                $restricted = (string)($w['runAs'] ?? '') === self::USER
                    || (string)($spawn['run']['runAs'] ?? '') === self::USER || (string)($spawn['runUserSignIn']['runAs'] ?? '') === self::USER;
                if ($restricted && ProcessManager::isRunning($id)) return $id;
            }
        } catch (\Throwable $e) {
            return '?';
        }
        return '';
    }

    /**
     * Run the launch preparation of the run user as the run user, in a child process: the Config Hub projection into its
     * home and the repair of its plugin folder. Root writes nothing in the home of the run user, so a link that the run
     * user planted there is followed by nobody who has more rights than the run user. No restricted process runs when
     * this is called (one at a time), so the child in the host mount space exposes nothing.
     *
     * @return array{ok:bool,reason:string}
     */
    public static function prepareHomeAsRunUser(string $agentId, string $homePath, string $workspaceId = '', string $authHome = ''): array
    {
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,40}$/D', $agentId) !== 1 || $homePath === '' || $homePath[0] !== '/' || $authHome === '') return ['ok' => false, 'reason' => 'bad_arguments'];
        $hubDir = self::path('hubDir', \AICliAgents\Services\Hub\HubStore::baseDir());
        if (!is_dir($hubDir) || is_link($hubDir) || @realpath($hubDir) !== $hubDir) $hubDir = '';
        $argv = self::prepareCommand($agentId, $homePath, $hubDir, $workspaceId, $authHome);
        if (defined('AICLI_TEST_TMP')) {
            if (self::$childRunnerForTests === null) return ['ok' => true, 'reason' => 'test_no_child'];
            $rc = (int)(self::$childRunnerForTests)($argv);
            return ['ok' => $rc === 0, 'reason' => $rc === 0 ? '' : 'child_failed'];
        }
        $proc = @proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!is_resource($proc)) return ['ok' => false, 'reason' => 'no_child'];
        $rc = proc_close($proc);
        return ['ok' => $rc === 0, 'reason' => $rc === 0 ? '' : 'child_failed'];
    }

    /**
     * The command of the preparation child. Finding 5 of the second review: it does NOT run in the host mount space. It runs in
     * a second, small view (the same script as the real view, with --prepare): /mnt is hidden, /boot and /usr/local/emhttp are
     * read-only, /tmp and the state tree are private, /var/run and /var/local are hidden, and only the home of the run user is writable and the Config Hub store is
     * readable. A process of the run user that survived an earlier run, and that looks at /proc/<pid>/root of this child, so
     * sees this small view, not the host. The environment is cleared (`env -i`) and only an allow list reaches the child.
     *
     * @return list<string>
     */
    public static function prepareCommand(string $agentId, string $homePath, string $hubDir = '', string $workspaceId = '', string $authHome = ''): array
    {
        $src = dirname(__DIR__, 2);
        $state = rtrim(SecretPaths::stateRoot(), '/');
        $work = dirname($homePath);
        $account = self::status();
        $id = self::safeId($workspaceId);
        if (empty($account['ready']) || $id === null || $authHome === '' || $authHome[0] !== '/') return [];
        $setpriv = self::runtimeToolPath('setpriv') ?: '/usr/bin/setpriv';
        $env = self::runtimeToolPath('env') ?: '/usr/bin/env';
        $php = self::runtimeToolPath('php') ?: '/usr/bin/php';
        $timeout = self::runtimeToolPath('timeout') ?: '/usr/bin/timeout';
        $nsenter = self::runtimeToolPath('nsenter') ?: '/usr/bin/nsenter';
        $unshare = self::runtimeToolPath('unshare') ?: '/usr/bin/unshare';
        $inner = [$setpriv, '--reuid=' . (int)$account['uid'], '--regid=' . (int)$account['gid'], '--clear-groups', '--no-new-privs',
            '--bounding-set=-all', '--inh-caps=-all', '--reset-env', '--', $env, '-i', 'PATH=' . self::FIXED_PATH,
            'HOME=' . $homePath, 'AICLI_HOME=' . $homePath, 'USER=' . self::USER, 'LOGNAME=' . self::USER,
            'AICLI_RESTRICTED_RUN=1', 'AICLI_STATE_ROOT=' . $state];
        if ($hubDir !== '') $inner[] = 'AICLI_HUB_STATE_DIR=' . $hubDir;
        $inner = array_merge($inner, [$php, $src . '/scripts/user/run-user-prepare.php', $agentId, $homePath, $authHome]);
        $mounts = ['/bin/bash', $src . '/scripts/aicli-run-mounts.sh', '--prepare', '--writable=0', '--state-root=' . $state,
            '--resolv-file=' . self::resolverFile($id), '--rw=' . $work];
        if ($hubDir !== '') $mounts[] = '--ro=' . $hubDir;
        $mounts[] = '--ro=' . $authHome;
        return array_merge([$timeout, '--signal=KILL', '120', '/bin/bash', $src . '/scripts/aicli-run-cgroup.sh', 'join', $id,
            $nsenter, '--net=' . self::networkNamespaceFile($id), '--', $unshare, '--mount', '--pid', '--ipc', '--uts', '--fork', '--mount-proc=/proc', '--propagation', 'private'], $mounts, ['--'], $inner);
    }

    public const FIXED_PATH = '/usr/local/emhttp/plugins/unraid-aicliagents/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';

    /** Authentication files that an isolated session may copy into its empty home. */
    public const SIGNIN_FILES = ['.claude.json', '.gemini/mcp-oauth-tokens.json', '.gemini/oauth_creds.json', '.codex/auth.json',
        '.local/share/opencode/auth.json', '.local/share/opencode2-xdg/data/opencode/auth.json', '.qwen/oauth_creds.json', '.grok/auth.json'];

    /** Copy only known sign-in files between homes owned by the run user. Runs as aicli-run. */
    public static function copySigninFiles(string $sourceHome, string $targetHome, bool $replaceExisting = false): array
    {
        if ($sourceHome === '' || $sourceHome[0] !== '/' || $targetHome === '' || $targetHome[0] !== '/' || is_link($sourceHome) || is_link($targetHome)) {
            return ['ok' => false, 'reason' => 'bad_home'];
        }
        $sourceRoot = @realpath($sourceHome);
        $targetRoot = @realpath($targetHome);
        if ($sourceRoot === false || $targetRoot === false || !is_dir($sourceRoot) || !is_dir($targetRoot)) return ['ok' => false, 'reason' => 'bad_home'];
        $uid = function_exists('posix_geteuid') ? (int)posix_geteuid() : -1;
        foreach (self::SIGNIN_FILES as $rel) {
            $source = rtrim($sourceRoot, '/') . '/' . $rel;
            $target = rtrim($targetRoot, '/') . '/' . $rel;
            $targetParentExists = true;
            $targetDirCheck = $targetRoot;
            foreach (explode('/', dirname($rel)) as $part) {
                if ($part === '.' || $part === '') continue;
                $targetDirCheck .= '/' . $part;
                if (file_exists($targetDirCheck) || is_link($targetDirCheck)) {
                    if (!is_dir($targetDirCheck) || is_link($targetDirCheck)) return ['ok' => false, 'reason' => 'unsafe_target'];
                } else {
                    $targetParentExists = false;
                    break;
                }
            }
            $st = @lstat($source);
            if (!is_array($st)) {
                if ($replaceExisting && $targetParentExists && (file_exists($target) || is_link($target))) {
                    $old = @lstat($target);
                    if (!is_array($old) || is_link($target) || ($old['mode'] & 0170000) !== 0100000 || (int)$old['uid'] !== $uid
                        || (int)$old['nlink'] !== 1 || (int)$old['size'] > 4194304 || !@unlink($target)) {
                        return ['ok' => false, 'reason' => 'unsafe_target'];
                    }
                }
                continue;
            }
            if (($st['mode'] & 0170000) !== 0100000 || is_link($source) || (int)$st['uid'] !== $uid || (int)$st['nlink'] !== 1 || (int)$st['size'] > 4194304) {
                return ['ok' => false, 'reason' => 'unsafe_signin_file'];
            }
            $cursor = $sourceRoot;
            foreach (explode('/', dirname($rel)) as $part) {
                if ($part === '.' || $part === '') continue;
                $cursor .= '/' . $part;
                if (is_link($cursor)) return ['ok' => false, 'reason' => 'signin_path_changed'];
            }
            $targetParent = dirname($rel);
            $targetDir = $targetRoot;
            foreach (explode('/', $targetParent) as $part) {
                if ($part === '.' || $part === '') continue;
                $targetDir .= '/' . $part;
                $dir = $targetDir;
                if (file_exists($dir) || is_link($dir)) {
                    if (!is_dir($dir) || is_link($dir)) return ['ok' => false, 'reason' => 'unsafe_target'];
                } elseif (!@mkdir($dir, 0700)) return ['ok' => false, 'reason' => 'target_mkdir_failed'];
            }
            if (file_exists($target) || is_link($target)) {
                $old = @lstat($target);
                if (!$replaceExisting || !is_array($old) || is_link($target) || ($old['mode'] & 0170000) !== 0100000
                    || (int)$old['uid'] !== $uid || (int)$old['nlink'] !== 1 || (int)$old['size'] > 4194304) {
                    return ['ok' => false, 'reason' => 'unsafe_target'];
                }
            }
            $data = @file_get_contents($source, false, null, 0, 4194305);
            if (!is_string($data) || strlen($data) > 4194304) return ['ok' => false, 'reason' => 'signin_copy_failed'];
            $tmp = dirname($target) . '/.aicli-signin-' . bin2hex(random_bytes(8));
            $oldUmask = umask(0077);
            try { $handle = @fopen($tmp, 'xb'); }
            finally { umask($oldUmask); }
            if ($handle === false) return ['ok' => false, 'reason' => 'signin_copy_failed'];
            $written = fwrite($handle, $data);
            fflush($handle);
            fclose($handle);
            if ($written !== strlen($data) || !@chmod($tmp, 0600) || !@rename($tmp, $target)) {
                @unlink($tmp);
                return ['ok' => false, 'reason' => 'signin_copy_failed'];
            }
            $copied = @lstat($target);
            if (!is_array($copied) || (($copied['mode'] & 0170000) !== 0100000) || (int)$copied['uid'] !== $uid || ($copied['mode'] & 0777) !== 0600) return ['ok' => false, 'reason' => 'signin_copy_failed'];
        }
        return ['ok' => true, 'reason' => ''];
    }

    /** Build a bounded child command that saves sign-in files from a closed sign-in workspace. */
    public static function signinSyncCommand(string $workspaceId): ?array
    {
        $id = self::safeId($workspaceId);
        $account = self::status();
        if ($id === null || empty($account['ready'])) return null;
        $uid = (int)$account['uid'];
        $gid = (int)$account['gid'];
        $source = self::sessionHomePath($id);
        $target = self::path('authHome', UtilityService::getWorkDir(self::USER) . '/home');
        foreach ([$source, $target] as $home) {
            $st = @lstat($home);
            if (!is_array($st) || is_link($home) || ($st['mode'] & 0170000) !== 0040000 || (int)$st['uid'] !== $uid
                || (int)$st['gid'] !== $gid || ($st['mode'] & 0777) !== 0700 || @realpath($home) !== $home) return null;
        }
        if ($source === $target) return null;
        $setpriv = self::runtimeToolPath('setpriv');
        $env = self::runtimeToolPath('env');
        $php = self::runtimeToolPath('php');
        $timeout = self::runtimeToolPath('timeout');
        $nsenter = self::runtimeToolPath('nsenter');
        $unshare = self::runtimeToolPath('unshare');
        if ($setpriv === '' || $env === '' || $php === '' || $timeout === '' || $nsenter === '' || $unshare === '') return null;
        $script = dirname(__DIR__, 2) . '/scripts/user/run-user-prepare.php';
        $state = rtrim(SecretPaths::stateRoot(), '/');
        $inner = [$setpriv, '--reuid=' . $uid, '--regid=' . $gid, '--clear-groups', '--no-new-privs',
            '--bounding-set=-all', '--inh-caps=-all', '--reset-env', '--', $env, '-i', 'PATH=' . self::FIXED_PATH,
            'HOME=' . $target, 'AICLI_HOME=' . $target, 'USER=' . self::USER, 'LOGNAME=' . self::USER,
            'AICLI_STATE_ROOT=' . $state, $php, $script, '--sync-signin', $source, $target];
        $mounts = ['/bin/bash', dirname(__DIR__, 2) . '/scripts/aicli-run-mounts.sh', '--prepare', '--writable=0', '--state-root=' . $state,
            '--resolv-file=' . self::resolverFile($id), '--ro=' . $source, '--rw=' . $target];
        $mounts[] = '--';
        return [$timeout, '--signal=KILL', '60', '/bin/bash', dirname(__DIR__, 2) . '/scripts/aicli-run-cgroup.sh', 'join', $id,
            $nsenter, '--net=' . self::networkNamespaceFile($id), '--', $unshare, '--mount', '--pid', '--ipc', '--uts', '--fork',
            '--mount-proc=/proc', '--propagation', 'private', ...$mounts, ...$inner];
    }

    /** Persist only the allowed sign-in files before release removes the temporary sign-in home. */
    public static function persistSigninFilesFromSession(string $workspaceId): bool
    {
        $id = self::safeId($workspaceId);
        return $id !== null && self::persistSigninFiles($id, false);
    }

    private static function persistSigninFiles(string $id, bool $runtimeAlreadyStopped): bool
    {
        if (!$runtimeAlreadyStopped && !defined('AICLI_TEST_TMP') && !self::stopRuntime($id)) return false;
        $source = self::sessionHomePath($id);
        if ($source === '') return false;
        if (!file_exists($source) && !is_link($source)) return true;
        $argv = self::signinSyncCommand($id);
        if ($argv === null) return false;
        if (defined('AICLI_TEST_TMP')) return self::$childRunnerForTests !== null && (int)(self::$childRunnerForTests)($argv) === 0;
        $out = self::runChild($argv, 60);
        return $out !== null;
    }

    // ======================================================================
    // Every process of the run user (finding 5), the sockets root must not trust (finding 1)
    // ======================================================================

    /** The user id of the run user from the passwd file, or null when there is no such account (the account need not be ready). */
    public static function runUid(): ?int
    {
        foreach (@file(self::path('passwd', '/etc/passwd'), FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $f = explode(':', $line);
            if (($f[0] ?? '') === self::USER && isset($f[2]) && ctype_digit((string)$f[2])) return (int)$f[2];
        }
        return null;
    }

    /** Map a PID reported inside the workspace PID namespace to a host PID owned by the run user. */
    public static function hostPidForRunUserNamespacePid(string $workspaceId, int $namespacePid): ?int
    {
        if ($namespacePid < 1) return null;
        $pidNs = self::pidNsFile($workspaceId);
        $wantedNs = @stat($pidNs);
        $uid = self::runUid();
        if ($uid === null) return null;
        if (!is_file($pidNs) || !is_array($wantedNs) || !isset($wantedNs['ino'])) return null;
        $proc = self::path('procRoot', '/proc');
        foreach (@scandir($proc) ?: [] as $name) {
            if (!ctype_digit($name)) continue;
            $status = @file_get_contents($proc . '/' . $name . '/status');
            if (!is_string($status) || preg_match('/^State:\s+Z/m', $status) === 1) continue;
            if (preg_match('/^Uid:\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/m', $status, $u) !== 1
                || array_map('intval', array_slice($u, 1, 4)) !== [$uid, $uid, $uid, $uid]) continue;
            // The kernel separates the levels with a tab ("NSpid:\t4321\t17"); the last level is the PID inside the run's namespace.
            if (preg_match('/^NSpid:[\t ]+([0-9][0-9\t ]*)$/m', $status, $ns) !== 1) continue;
            $pids = preg_split('/\s+/', trim($ns[1])) ?: [];
            if ((int)end($pids) !== $namespacePid) continue;
            $processNs = @stat($proc . '/' . $name . '/ns/pid');
            if (!is_array($processNs) || (int)$processNs['ino'] !== (int)$wantedNs['ino']) continue;
            return (int)$name;
        }
        return null;
    }

    /** Direct children of a validated host PID that also belong to the run user. */
    public static function hostChildrenOfRunUserProcess(int $hostPid): array
    {
        if ($hostPid < 1) return [];
        $proc = self::path('procRoot', '/proc');
        $out = [];
        // #935: ProcessManager::childPidsOf() also works without the kernel children file.
        foreach (ProcessManager::childPidsOf($hostPid, $proc) as $pid) {
            if (self::isRunUserHostPid($pid)) $out[] = $pid;
        }
        return $out;
    }

    /** True when every real, effective, saved or file-system uid in host /proc is the run user. */
    public static function isRunUserHostPid(int $pid): bool
    {
        $uid = self::runUid();
        if ($uid === null || $pid < 1) return false;
        $status = @file_get_contents(self::path('procRoot', '/proc') . '/' . $pid . '/status');
        if (!is_string($status) || preg_match('/^Uid:\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/m', $status, $m) !== 1) return false;
        return array_map('intval', array_slice($m, 1, 4)) === [$uid, $uid, $uid, $uid];
    }

    /** Build a bounded, direct-argv tmux client for the one restricted workspace. */
    public static function restrictedTmuxArgv(string $workspaceId, array $tmuxArgs, int $deadlineSeconds = 8): ?array
    {
        $id = self::safeId($workspaceId);
        $mnt = self::nsFile($workspaceId);
        $pid = self::pidNsFile($workspaceId);
        $ipc = self::ipcNsFile($workspaceId);
        $uts = self::utsNsFile($workspaceId);
        $userns = self::childUserNsFile($workspaceId);
        $netnsFile = self::networkNamespaceFile($workspaceId);
        $uid = self::runUid();
        $status = self::status();
        $files = [$mnt, $pid, $ipc, $uts, $userns, $netnsFile];
        $filesReady = count(array_filter($files, static fn(string $file): bool => is_file($file) && !is_link($file))) === count($files);
        if ($id === null || $uid === null || empty($status['ready']) || in_array('', $files, true) || !$filesReady) return null;
        $state = rtrim(SecretPaths::stateRoot(), '/');
        $home = self::sessionHomePath($id);
        $script = dirname(__DIR__, 2) . '/scripts/aicli-run-cgroup.sh';
        $bin = '/usr/local/emhttp/plugins/unraid-aicliagents/bin/tmux';
        $args = array_values(array_map('strval', $tmuxArgs));
        if (($args[0] ?? '') === 'list-sessions' || ($args[0] ?? '') === 'ls') {
            if (!in_array('-t', $args, true)) array_splice($args, 1, 0, ['-t', 'aicli-agent-*-'.$id]);
        }
        foreach ($args as $index => $arg) {
            if ($arg !== '-t') continue;
            $target = (string)($args[$index + 1] ?? '');
            if (!preg_match('/^=?aicli-agent-[A-Za-z0-9_-]+-' . preg_quote($id, '/') . '(?::[A-Za-z0-9_.-]+)?$/D', $target)
                && $target !== 'aicli-agent-*-'.$id) return null;
        }
        $timeout = self::runtimeToolPath('timeout') ?: '/usr/bin/timeout';
        $nsenter = self::runtimeToolPath('nsenter') ?: '/usr/bin/nsenter';
        $setpriv = self::runtimeToolPath('setpriv') ?: '/usr/bin/setpriv';
        $env = self::runtimeToolPath('env') ?: '/usr/bin/env';
        return [$timeout, '--signal=KILL', (string)max(1, min(30, $deadlineSeconds)), '/bin/bash', $script, 'join', $id,
            $setpriv, '--clear-groups', '--no-new-privs', '--',
            $nsenter, '--user=' . $userns, '--mount=' . $mnt, '--pid=' . $pid, '--ipc=' . $ipc, '--uts=' . $uts, '--net=' . $netnsFile,
            '-S', (string)$uid, '-G', (string)(int)$status['gid'], '--fork', '--',
            $setpriv, '--no-new-privs', '--reset-env', '--', $env, '-i', 'PATH=' . self::FIXED_PATH,
            'HOME=' . $home, 'AICLI_HOME=' . $home, 'TMUX_TMPDIR=' . $state . '/tmux/s-' . $id, $bin, ...$args];
    }

    /** Run one restricted tmux request without a shell or pipeline. */
    public static function runRestrictedTmux(string $workspaceId, array $tmuxArgs, int $deadlineSeconds = 8): ?string
    {
        $argv = self::restrictedTmuxArgv($workspaceId, $tmuxArgs, $deadlineSeconds);
        return $argv === null ? null : self::runChild($argv, $deadlineSeconds);
    }

    /**
     * The process ids of every live process whose real, effective, saved or file-system user id is the run user. This counts
     * PROCESSES, not tmux sessions: an agent can start a process that outlives its session and keeps its view alive.
     * A zombie is not counted (it cannot do anything).
     *
     * @return list<int>
     */
    public static function processesOfRunUser(): array
    {
        $uid = self::runUid();
        if ($uid === null) return [];
        $proc = self::path('procRoot', '/proc');
        $pids = [];
        foreach (@scandir($proc) ?: [] as $name) {
            if (!ctype_digit($name)) continue;
            $status = @file_get_contents($proc . '/' . $name . '/status');
            if (!is_string($status)) continue;
            if (preg_match('/^State:\s+Z/m', $status) === 1) continue;
            if (preg_match('/^Uid:\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/m', $status, $m) !== 1) continue;
            if ((int)$m[1] === $uid || (int)$m[2] === $uid || (int)$m[3] === $uid || (int)$m[4] === $uid) $pids[] = (int)$name;
        }
        return $pids;
    }

    /**
     * Kill every process of the run user and check that none is left. Returns the number that is still there after about two
     * seconds (0 = empty). A restricted start needs 0; a process that cannot be killed stops the start.
     */
    public static function killRunUserProcesses(): int
    {
        $remaining = self::processesOfRunUser();
        for ($round = 0; $round < 10 && $remaining !== []; $round++) {
            foreach ($remaining as $pid) {
                if (defined('AICLI_TEST_TMP') && self::$killerForTests !== null) (self::$killerForTests)($pid);
                elseif (function_exists('posix_kill')) @posix_kill($pid, 9);
            }
            usleep(defined('AICLI_TEST_TMP') ? 1000 : 200000);
            $remaining = self::processesOfRunUser();
        }
        return count($remaining);
    }

    /**
     * True when root must not run a tmux client on this socket (finding 1): the socket, the folder tmux-<uid> that holds it, or
     * the session folder s-<id> belongs to the run user, or the session id is marked as a restricted workspace. A tmux client is
     * told by its server what to run (a fake server sends a shell command and the client runs it with the rights of the
     * client), so a root client on a socket that the run user controls would run the program of the agent as root.
     */
    public static function socketIsRunUserControlled(string $sock): bool
    {
        $uid = self::runUid();
        if (preg_match('#/s-([A-Za-z0-9_-]+)/#', $sock, $m) === 1 && self::isMarkedRestricted($m[1])) return true;
        if ($uid === null) {
            if (preg_match('#/tmux-([0-9]+)/#', $sock, $m) === 1 && (int)$m[1] !== 0) return true;
            // A missing entry is no evidence (the same as with a run account): a client on a missing socket finds no server.
            // Every entry that exists must be a root-owned non-link.
            foreach ([$sock, dirname($sock), dirname(dirname($sock))] as $path) {
                $st = @lstat($path);
                if (!is_array($st)) continue;
                if (is_link($path) || (($st['mode'] & 0170000) === 0120000) || (int)$st['uid'] !== 0) return true;
            }
            return false;
        }
        if (preg_match('#/tmux-(\d+)/default$#D', $sock, $m) === 1 && (int)$m[1] === $uid) return true;
        foreach ([$sock, dirname($sock), dirname(dirname($sock))] as $path) {
            $st = @lstat($path);
            if (is_array($st) && (int)$st['uid'] === $uid) return true;
        }
        return false;
    }

    /**
     * The prefix of a tmux command for a restricted workspace: it enters the view of the workspace and runs the tmux client AS THE
     * RUN USER, with a time limit and a clean environment. The output is not trusted by the caller. '' when the workspace has no
     * view. The tmux binary is the one of the plugin, by its absolute path.
     */
    public static function viewTmuxCommand(string $workspaceId): string
    {
        $argv = self::restrictedTmuxArgv($workspaceId, []);
        return $argv === null ? '' : implode(' ', array_map('escapeshellarg', $argv));
    }

    // ======================================================================
    // The network guard (finding 2)
    // ======================================================================

    /**
     * The rule that keeps the run user away from every address of this host. A restricted agent must reach its LLM and the network,
     * but not the services that listen on this host: libvirt on 127.0.0.1:16509 has no authentication on Unraid, and nginx and the
     * plugin's listeners are on the loopback or the LAN address too. One rule per IP version, in the filter table, chain OUTPUT:
     * traffic of the user id of the run user to an address of type LOCAL (every address of this host, also 127.0.0.0/8) is rejected.
     * This host OUTPUT rule blocks local addresses for any process of the run uid that ever starts outside its private network
     * space. The namespace has its own veth, NAT and forwarding rules. This extra host rule stays until every restricted workspace
     * and run-user process has stopped. The start fails closed when a required network tool or kernel setting is missing.
     *
     * @return list<string> the rule, without the command and the chain
     */
    public static function networkGuardRule(int $uid): array
    {
        return ['-m', 'owner', '--uid-owner', (string)$uid, '-m', 'addrtype', '--dst-type', 'LOCAL', '-j', 'REJECT'];
    }

    /**
     * Add the rule when it is not there (idempotent), for IPv4 and for IPv6 when the host has IPv6. Fails closed: the result is
     * ok only when the rule is in place for every version that the host uses.
     *
     * @return array{ok:bool,reason:string}
     */
    public static function ensureNetworkGuard(): array
    {
        $uid = self::runUid();
        if ($uid === null) return ['ok' => false, 'reason' => 'The run user aicli-run does not exist, so the network guard cannot be made.'];
        foreach (self::guardTools() as $tool => $required) {
            $rule = self::networkGuardRule($uid);
            $check = array_merge([$tool, '-w', '5', '-C', 'OUTPUT'], $rule);
            if (self::runGuardCommand($check) === 0) continue;
            $add = array_merge([$tool, '-w', '5', '-I', 'OUTPUT', '1'], $rule);
            if (self::runGuardCommand($add) === 0 && self::runGuardCommand($check) === 0) continue;
            if ($required) return ['ok' => false, 'reason' => "The firewall rule that keeps aicli-run away from the services of this host could not be added with $tool, so the run was not started."];
        }
        return ['ok' => true, 'reason' => ''];
    }

    /** Remove the rule (every copy). Best effort; the next start adds it again. */
    public static function removeNetworkGuard(): void
    {
        $uid = self::runUid();
        if ($uid === null) return;
        foreach (array_keys(self::guardTools()) as $tool) {
            $del = array_merge([$tool, '-w', '5', '-D', 'OUTPUT'], self::networkGuardRule($uid));
            for ($i = 0; $i < 5 && self::runGuardCommand($del) === 0; $i++) { /* delete every copy */ }
        }
    }

    /** @return array<string,bool> tool => required. The IPv6 rule is required when the host has any IPv6 address (also ::1). */
    private static function guardTools(): array
    {
        // Any IPv6 address (also ::1) means that a service of this host can listen on it, so the rule is required for IPv6 too.
        $v6 = false;
        foreach (@file(self::path('inet6', '/proc/net/if_inet6'), FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $f = preg_split('/\s+/', trim($line));
            if (isset($f[0]) && strlen($f[0]) === 32) $v6 = true;
        }
        return ['iptables' => true, 'ip6tables' => $v6];
    }

    /**
     * Run one firewall command and return its exit code. The result changes between two calls with the same arguments (the first
     * call adds the rule), so PHPStan must not treat it as a pure function.
     *
     * @param list<string> $argv
     * @phpstan-impure
     */
    private static function runGuardCommand(array $argv): int
    {
        if (defined('AICLI_TEST_TMP')) return self::$guardRunnerForTests !== null ? (int)(self::$guardRunnerForTests)($argv) : 0;
        $proc = @proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        return is_resource($proc) ? proc_close($proc) : 127;
    }

    /**
     * A fault in the settings of this host that the plugin cannot repair for the run: the resolver of the host is on the host
     * itself (the network guard would block the DNS of the agent). Null when none.
     */
    public static function hostNetworkFault(): ?string
    {
        foreach (@file(self::path('resolv', '/etc/resolv.conf'), FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^\s*nameserver\s+(\S+)/', $line, $m) !== 1) continue;
            $ns = $m[1];
            if (str_starts_with($ns, '127.') || $ns === '::1') return "The DNS server in /etc/resolv.conf ($ns) is on this host, so the network guard of the run user would block the DNS of the agent. Use a DNS server of the network.";
        }
        return null;
    }

    // ======================================================================
    // One restricted start at a time (finding 15)
    // ======================================================================

    /** @var resource|null */
    private static $startLock = null;

    /**
     * Take the lock over "check that no other restricted workspace is open" and "start". Non-blocking attempts in a loop with a
     * deadline (a blocking flock on a shared folder can hang). Returns false when the lock is not got in time.
     */
    public static function lockRestrictedStart(int $seconds = 20): bool
    {
        if (!self::ensureViewDir()) return false;
        $h = @fopen(self::viewDir() . '/.start.lock', 'c');
        if ($h === false) return false;
        $deadline = microtime(true) + $seconds;
        do {
            if (@flock($h, LOCK_EX | LOCK_NB)) { self::$startLock = $h; return true; }
            usleep(100000);
        } while (microtime(true) < $deadline);
        fclose($h);
        return false;
    }

    public static function unlockRestrictedStart(): void
    {
        if (is_resource(self::$startLock)) { @flock(self::$startLock, LOCK_UN); @fclose(self::$startLock); }
        self::$startLock = null;
    }

    // ======================================================================
    // Small helpers
    // ======================================================================

    private static function euid(): int
    {
        if (defined('AICLI_TEST_TMP') && self::$effectiveUidForTests !== null) return self::$effectiveUidForTests;
        return function_exists('posix_geteuid') ? (int)posix_geteuid() : -1;
    }

    /** The user that runs the plugin. Under PHPUnit it is the real process user (root in the CI container). */
    private static function ownerOfPlugin(): int
    {
        return function_exists('posix_geteuid') ? (int)posix_geteuid() : 0;
    }

    private static function validRunId(string $runId): bool
    {
        return preg_match('/^[A-Za-z0-9]{6,32}$/D', $runId) === 1;
    }

    private static function textLength(string $text): int
    {
        return function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
    }

    /**
     * Is the path a symbolic link now? The re-check after a chmod, mkdir, read or rename must see the disk, not the stat
     * cache of PHP or an earlier answer: the stat cache is cleared for the path first.
     * @phpstan-impure
     */
    private static function isLinkNow(string $path): bool
    {
        clearstatcache(true, $path);
        return is_link($path);
    }
}
