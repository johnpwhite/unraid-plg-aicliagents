<?php
/**
 * <module_context>
 *     <name>TerminalService</name>
 *     <description>Terminal session management for AICliAgents.</description>
 *     <dependencies>LogService, ConfigService, ProcessManager, AgentRegistry</dependencies>
 *     <constraints>Under 200 lines. Focuses on ttyd launches and environment injection.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

require_once __DIR__ . '/SessionLaunchLock.php';

class TerminalService {
    /** Test seam: callable(string $name, string $path): bool for required runtime readiness. */
    public static $requiredRuntimeProbe = null;
    /** Test-only overrides for the terminal programs and output paths. */
    public static ?array $runtimeProgramsForTests = null;
    public static ?array $startPathsForTests = null;
    /** @var callable|null fn(string $command, string $log): int  Replaces ensure-session in tests. */
    public static $ensureRunnerForTests = null;
    /** Test seam: called with each bound path right after its owner and mode check, so a test can swap the path there. */
    public static $bindCheckSwapForTests = null;
    /** @var callable|null fn(array $argv, string $log, bool $truncate): int  Replaces detached ttyd launch in tests. */
    public static $spawnRunnerForTests = null;

    /** Resolve the programs used by every terminal start and auto-launch sweep. */
    public static function requiredRuntimePrograms(): array
    {
        if (defined('AICLI_TEST_TMP') && self::$runtimeProgramsForTests !== null) return self::$runtimeProgramsForTests;
        $ttyd = '/usr/bin/ttyd';
        if (!is_callable(self::$requiredRuntimeProbe)) {
            $foundTtyd = trim((string)shell_exec('timeout 2 which ttyd 2>/dev/null'));
            if ($foundTtyd !== '') $ttyd = $foundTtyd;
        }
        return [
            'node' => '/usr/local/emhttp/plugins/unraid-aicliagents/bin/node',
            'tmux' => '/usr/local/emhttp/plugins/unraid-aicliagents/bin/tmux',
            'ttyd' => $ttyd,
        ];
    }

    /** Required programs used by every terminal start and auto-launch sweep. */
    public static function missingRequiredRuntimePrograms(?array $programs = null): array
    {
        $programs ??= self::requiredRuntimePrograms();
        $missing = [];
        foreach ($programs as $name => $path) {
            $ready = is_callable(self::$requiredRuntimeProbe)
                ? (bool)call_user_func(self::$requiredRuntimeProbe, $name, $path)
                : is_executable($path);
            if (!$ready) $missing[] = $name;
        }
        return $missing;
    }

    /**
     * Starts a new AICli console session via ttyd.
     *
     * Forgejo #352 (2026-09-29): every start of a workspace first takes the
     * workspace's SessionLaunchLock. A close or a restart of the same workspace
     * holds that lock from the close until the new agent runs. A start that
     * arrives in that window (an open page saw the agent stop and asked for a
     * start) waits, then finds the new session live and only attaches to it.
     * Before this, the page's start and the restart each launched an agent on
     * the same conversation: two `claude --resume <id>` processes in two tmux
     * servers, one of them invisible. See docs/specs/WORKSPACE_RELOAD_ONTO_CURRENT.md.
     *
     * #611: returns ['ok' => bool, 'reason' => string]. A refused start (lock timeout, path
     * unavailable, array stopping, unknown agent, no terminal server, failed mount, ensure-session
     * timeout, no ttyd socket) is ok=false, so a caller can tell it from a start that worked.
     * $lockWaitSeconds lets a web request wait less than the request time limit.
     * @return array{ok:bool,reason:string}
     */
    public static function startTerminal($id = 'default', $path = null, $chatId = null, $agentId = 'gemini-cli', string $origin = 'interactive', ?float $lockWaitSeconds = null): array {
        // Every start path reaches this method, including manual workspace opens,
        // retries and auto-launch. Take the shared global lock before the per-
        // workspace lock so a storage migration can keep all sessions closed.
        StorageBackendMigrationService::status(); // Reconcile a worker that stopped before it held the lock.
        $migrationLock = StorageBackendMigrationService::acquireLaunchGuard(false);
        if ($migrationLock === false || StorageBackendMigrationService::isMigrationInProgress()) {
            StorageBackendMigrationService::releaseLaunchGuard($migrationLock);
            LogService::log("Start of $id refused: storage is being migrated. Nothing was launched.", LogService::LOG_WARN, "TerminalService");
            return ['ok' => false, 'reason' => 'storage_migration'];
        }
        // #906: an agent that is being uninstalled does not start. The uninstall holds this lock
        // exclusively; a start holds it shared until it ends. No stale mark: the kernel frees it.
        $agentLock = false;
        if ($agentId !== 'terminal' && (string)$agentId !== '') {
            $agentLock = AgentUninstallLock::acquireForStart((string)$agentId);
            if ($agentLock === false) {
                StorageBackendMigrationService::releaseLaunchGuard($migrationLock);
                LogService::log("Start of $id refused: the $agentId agent is being uninstalled. Nothing was launched.", LogService::LOG_WARN, "TerminalService");
                return ['ok' => false, 'reason' => 'agent_uninstalling'];
            }
        }
        try {
            if (!SessionLaunchLock::acquire((string)$id, $lockWaitSeconds ?? SessionLaunchLock::START_WAIT_SECONDS)) {
                LogService::log("Start of $id refused: another start, close or restart of this workspace did not finish in time. Nothing was launched.", LogService::LOG_WARN, "TerminalService");
                return ['ok' => false, 'reason' => 'lock_timeout'];
            }
            try {
                return self::startTerminalLocked($id, $path, $chatId, $agentId, $origin);
            } finally {
                SessionLaunchLock::release((string)$id);
            }
        } finally {
            AgentUninstallLock::release($agentLock);
            StorageBackendMigrationService::releaseLaunchGuard($migrationLock);
        }
    }

    /**
     * TERMINAL_BACKGROUND_WARM.md: launch ONLY the ttyd bridge of a workspace
     * whose tmux session is live. Call it only while this process holds the
     * workspace's SessionLaunchLock and after the caller checked
     * ProcessManager::isRunning() under that lock (BridgeWarmService::attach).
     * Origin 'bridge_warm' skips --ensure-session, so it never launches an agent.
     */
    public static function attachBridgeLocked(string $id, ?string $path, string $agentId): void {
        if (!SessionLaunchLock::heldHere($id)) {
            LogService::log("Bridge warm-up of $id refused: the caller does not hold the workspace lock.", LogService::LOG_WARN, "TerminalService");
            return;
        }
        self::startTerminalLocked($id, $path, 'auto', $agentId, self::ORIGIN_BRIDGE_WARM);
    }

    /** TERMINAL_BACKGROUND_WARM.md: the origin of a bridge-only (re)attach. */
    public const ORIGIN_BRIDGE_WARM = 'bridge_warm';

    /** Forgejo #521: the longest the headless ensure-session step may run, in seconds. */
    public const ENSURE_TIMEOUT_SECONDS = 60;

    /** Forgejo #521: seconds between the stop signal and the kill signal. */
    public const ENSURE_KILL_AFTER_SECONDS = 5;

    /**
     * R-115, finding 2 of the review of the run user: the socket belongs to nobody and the group root, mode 600. No group
     * has access. Before, the group was users (mode 660), and the run user has the group users (gid 100), so a process of
     * the run user could connect to the terminal of every root workspace and type into its agent. The web server of the
     * Unraid page reaches the socket as its owner: the nginx.conf says "user root", and its workers run as nobody on
     * the checked host. In both cases the owner of the socket (nobody) or root is the one that connects; mode 600 allows the owner
     * and root. What could not be proven here: the live connect of the page terminal on the host (a live check in the spec).
     */
    public const TTYD_SOCKET_MODE = 0600;

    /** R-115: restrict the ttyd socket to its owner (nobody). The group is root, which has no other member. */
    public static function secureTtydSocket(string $sock): void
    {
        @chmod($sock, self::TTYD_SOCKET_MODE);
        @chown($sock, 'nobody');
        @chgrp($sock, 'root');
    }

    /** Wrap the ensure-session command so it can never block the start request forever. */
    public static function wrapEnsureTimeout(string $cmd): string
    {
        return 'timeout -k ' . self::ENSURE_KILL_AFTER_SECONDS . ' ' . self::ENSURE_TIMEOUT_SECONDS . ' ' . $cmd;
    }

    /**
     * The ensure-session command, which makes the tmux server of the workspace and starts the agent in it.
     * #781: it starts through aicli-clean-exec.sh, so the server and the agent get no descriptor above 2
     * and umask 022 from the PHP process. Under aicli-run that process runs below the caller's script.
     * $envStr is already shell-quoted (KEY='value' words).
     */
    public static function ensureSessionCommand(string $activeSrc, string $username, string $envStr, string $shell, array $mountView = []): string
    {
        $command = '/bin/bash ' . escapeshellarg($activeSrc . '/scripts/aicli-clean-exec.sh') . ' runuser -u ' . escapeshellarg($username)
            . " -- env $envStr /bin/bash " . escapeshellarg($shell) . ' --ensure-session';
        if ($mountView === []) return $command;
        // A restricted run gets its own mount, PID and network namespaces. The
        // namespace supervisor stays alive after tmux starts, so the detached
        // server remains inside the PID space until the cgroup is stopped.
        $folder = trim((string)($mountView['folder'] ?? ''));
        if ($folder === '' || $folder[0] !== '/' || strpos($folder, '..') !== false || preg_match('/[\x00-\x1F\x7F]/', $folder) === 1) return '';
        $writable = !empty($mountView['writable']) ? '1' : '0';
        $ns = trim((string)($mountView['nsFile'] ?? ''));
        if ($ns === '' || $ns[0] !== '/' || strpos($ns, '..') !== false || preg_match('/[\x00-\x1F\x7F]/', $ns) === 1) return '';
        $pidNs = trim((string)($mountView['pidNsFile'] ?? ''));
        if ($pidNs === '' || $pidNs[0] !== '/' || strpos($pidNs, '..') !== false || preg_match('/[\x00-\x1F\x7F]/', $pidNs) === 1) return '';
        $id = (string)($mountView['workspaceId'] ?? '');
        $netns = (string)($mountView['networkNamespace'] ?? '');
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $id) !== 1 || $netns !== RunUserService::networkNamespaceName($id)) return '';
        $resolverFile = RunUserService::resolverFile($id);
        $netnsFile = RunUserService::networkNamespaceFile($id);
        $userns = RunUserService::userNsFile($id);
        $ipcNs = RunUserService::ipcNsFile($id);
        $utsNs = RunUserService::utsNsFile($id);
        if ($resolverFile === '' || $netnsFile === '' || $userns === '' || $ipcNs === '' || $utsNs === '') return '';
        $state = trim((string)($mountView['stateRoot'] ?? ''));
        if ($state === '' || $state[0] !== '/' || strpos($state, '..') !== false || preg_match('/[\x00-\x1F\x7F]/', $state) === 1) return '';
        $uid = (int)($mountView['uid'] ?? 0);
        $gid = (int)($mountView['gid'] ?? 0);
        $home = trim((string)($mountView['home'] ?? ''));
        if ($uid < 1000 || $gid !== 100 || $home === '' || $home[0] !== '/' || strpos($home, '..') !== false) return '';
        $setpriv = RunUserService::runtimeToolPath('setpriv');
        if ($setpriv === '') return '';
        // #929: root binds the private, home and task folders of THIS workspace into the view. Each one is a path with the device
        // and inode that PHP read when it checked the owner and mode. An empty or malformed value fails closed.
        $ids = (array)($mountView['ids'] ?? []);
        $privateDir = trim((string)($mountView['privateDir'] ?? ''));
        $privateDirId = trim((string)($mountView['privateDirId'] ?? ''));
        $homeId = trim((string)($ids[$home] ?? ''));
        $taskDir = trim((string)($mountView['taskDir'] ?? ''));
        $taskId = $taskDir === '' ? '-' : trim((string)($ids[$taskDir] ?? ''));
        $tmuxDir = trim((string)($mountView['tmuxDir'] ?? ''));
        $tmuxDirId = trim((string)($mountView['tmuxDirId'] ?? ''));
        $hidden = [];
        foreach ([[$privateDir, $privateDirId], [$home, $homeId]] as [$hiddenPath, $hiddenId]) {
            if ($hiddenPath === '' || $hiddenPath[0] !== '/' || strpos($hiddenPath, '..') !== false || preg_match('/[\x00-\x1F\x7F]/', $hiddenPath) === 1
                || preg_match('/^[0-9]+:[0-9]+$/D', $hiddenId) !== 1) return '';
            $hidden[] = escapeshellarg($hiddenPath);
            $hidden[] = escapeshellarg($hiddenId);
        }
        if ($taskDir === '') {
            $hidden[] = escapeshellarg('-');
            $hidden[] = escapeshellarg('-');
        } else {
            if ($taskDir[0] !== '/' || strpos($taskDir, '..') !== false || preg_match('/[\x00-\x1F\x7F]/', $taskDir) === 1
                || preg_match('/^[0-9]+:[0-9]+$/D', $taskId) !== 1) return '';
            $hidden[] = escapeshellarg($taskDir);
            $hidden[] = escapeshellarg($taskId);
        }
        // #929: the root-only host folder of the tmux sockets, bound onto <state>/tmux/s-<id> in the view.
        if ($tmuxDir === '' || $tmuxDir[0] !== '/' || strpos($tmuxDir, '..') !== false || preg_match('/[\x00-\x1F\x7F]/', $tmuxDir) === 1
            || preg_match('/^[0-9]+:[0-9]+$/D', $tmuxDirId) !== 1) return '';
        $hidden[] = escapeshellarg($tmuxDir);
        $hidden[] = escapeshellarg($tmuxDirId);
        $binds = '';
        foreach (['rw' => (array)($mountView['rw'] ?? []), 'ro' => (array)($mountView['ro'] ?? [])] as $mode => $paths) {
            foreach ($paths as $p) {
                $p = (string)$p;
                if ($p === '' || $p[0] !== '/' || strpos($p, '..') !== false || preg_match('/[\x00-\x1F\x7F]/', $p) === 1) return '';
                $binds .= ' --' . $mode . '=' . escapeshellarg($p);
            }
        }
        // L2: the device and inode that PHP read when it checked owner, mode and link count of each path. The script compares them
        // with the path before it binds, so a file swapped after the PHP check is refused.
        foreach ($ids as $p => $idText) {
            $p = (string)$p;
            $idText = (string)$idText;
            if ($p === '' || $p[0] !== '/' || strpos($p, '..') !== false || preg_match('/[\x00-\x1F\x7F]/', $p) === 1 || preg_match('/^[0-9]+:[0-9]+$/D', $idText) !== 1) return '';
            $binds .= ' --expect-id=' . escapeshellarg($idText . '=' . $p);
        }
        foreach ((array)($mountView['protect'] ?? []) as $p) {
            $p = (string)$p;
            if ($p === '' || $p[0] !== '/' || strpos($p, '..') !== false || preg_match('/[\x00-\x1F\x7F]/', $p) === 1) return '';
            $binds .= ' --protect=' . escapeshellarg($p);
        }
        foreach ((array)($mountView['readFolders'] ?? []) as $p) {
            $p = (string)$p;
            if ($p === '' || $p[0] !== '/' || strpos($p, '..') !== false || preg_match('/[\x00-\x1F\x7F]/', $p) === 1) return '';
            $binds .= ' --read-folder=' . escapeshellarg($p);
        }
        $inner = escapeshellarg($setpriv) . ' --no-new-privs --reset-env -- ' . self::restrictedEnvPrefix()
            . ' HOME=' . escapeshellarg($home) . ' AICLI_HOME=' . escapeshellarg($home) . ' USER=' . escapeshellarg(RunUserService::USER)
            . ' LOGNAME=' . escapeshellarg(RunUserService::USER) . ' AICLI_STATE_ROOT=' . escapeshellarg($state)
            . ' AICLI_RESTRICTED_RUN=1 ' . $envStr . ' /bin/bash '
            . escapeshellarg($shell) . ' --ensure-session';
        $inner = '/bin/bash ' . escapeshellarg($activeSrc . '/scripts/aicli-run-command.sh') . ' '
            . escapeshellarg((string)$uid) . ' ' . escapeshellarg((string)$gid) . ' -- ' . $inner;
        return self::restrictedEnvPrefix() . ' nsenter -t 1 -m -- /bin/bash '
            . escapeshellarg($activeSrc . '/scripts/aicli-run-view.sh') . ' ' . escapeshellarg($id) . ' ' . escapeshellarg($ns)
            . ' ' . escapeshellarg($pidNs) . ' ' . escapeshellarg($ipcNs) . ' ' . escapeshellarg($utsNs)
            . ' ' . escapeshellarg($userns) . ' ' . escapeshellarg($netns) . ' ' . escapeshellarg($id)
            . ' ' . escapeshellarg((string)$uid) . ' ' . escapeshellarg((string)$gid) . ' ' . implode(' ', $hidden)
            . ' --folder=' . escapeshellarg($folder) . ' --writable=' . $writable . ' --state-root=' . escapeshellarg($state)
            . ' --resolv-file=' . escapeshellarg($resolverFile) . $binds . ' -- ' . $inner;
    }

    /**
     * The paths that the view binds back into the private state tree (docs/specs/AGENT_RUN_ENTRY_POINT.md R17, finding 3).
     * Writable: the work folder and home of the run user, the private task folder of this run, the tmux folder of this
     * session (made here, owned by the run user) and the PHP cache folder of the run user. Read-only: this run's args.json
     * and env.sh files, the tmux quirk file of the agent and the terminfo folder. The root-owned mode 0400 sign-in marker
     * stays out of the view. A durable run marker prevents a
     * damaged workspace record from starting the folder as root. A may-change folder also gets read-only .git and .claude
     * directories, the marker and MCP config. Nothing else of the state tree is in the view: not the root home, not the logs,
     * not the other runs.
     *
     * @param array<string,mixed> $runMeta
     * @param array<string,mixed> $account the result of RunUserService::status()
     * @return array{rw:list<string>,ro:list<string>,protect:list<string>,readFolders:list<string>}|null null when a path cannot be made safely
     */
    private static function runUserBindPaths(string $id, string $username, string $agentId, array $runMeta, array $account, string $folder, ?string &$failure = null): ?array
    {
        $failure = '';
        $state = rtrim(SecretPaths::stateRoot(), '/');
        $uid = (int)($account['uid'] ?? 0);
        $gid = (int)($account['gid'] ?? 0);
        if ($uid < 1000 || $gid < 1) { $failure = 'run-user uid or gid is invalid'; return null; }
        $rw = [];
        $ro = [];
        // L2: the device and inode of each bound path come from the SAME lstat that checked its owner, mode and link count.
        // A second lstat later would accept a path that was renamed between the check and that second lstat.
        $ids = [];
        $readFolders = RunUserService::validatedListedFolders((array)($runMeta['readFolders'] ?? []));
        if ($readFolders === null) { $failure = 'an authorised read folder is invalid'; return null; }
        $home = RunUserService::sessionHomePath($id);
        $homeStat = @lstat($home);
        if (!is_array($homeStat) || is_link($home) || ($homeStat['mode'] & 0170000) !== 0040000
            || (int)$homeStat['uid'] !== $uid || (int)$homeStat['gid'] !== $gid || ($homeStat['mode'] & 0777) !== 0700) {
            $observedUid = is_array($homeStat) ? (string)(int)$homeStat['uid'] : 'missing';
            $observedGid = is_array($homeStat) ? (string)(int)$homeStat['gid'] : 'missing';
            $observedMode = is_array($homeStat) ? sprintf('%04o', (int)$homeStat['mode'] & 0777) : 'missing';
            $observedType = !is_array($homeStat) ? 'missing'
                : ((($homeStat['mode'] & 0170000) === 0040000) ? 'directory' : (is_link($home) ? 'symlink' : 'not-directory'));
            $failure = 'private home mismatch: expected directory uid=' . $uid . ' gid=' . $gid . ' mode=0700; observed type='
                . $observedType . ' uid='
                . $observedUid . ' gid=' . $observedGid . ' mode=' . $observedMode;
            return null;
        }
        $rw[] = $home;
        $ids[$home] = (int)$homeStat['dev'] . ':' . (int)$homeStat['ino'];
        if (self::$bindCheckSwapForTests !== null) (self::$bindCheckSwapForTests)($home);
        $taskDir = (string)($runMeta['taskDir'] ?? '');
        if ($taskDir !== '') {
            $taskStat = @lstat($taskDir);
            if (!is_array($taskStat) || ($taskStat['mode'] & 0170000) !== 0040000 || is_link($taskDir) || dirname($taskDir) !== RunUserService::taskRoot()) {
                $failure = 'the private task folder is invalid';
                return null;
            }
            $rw[] = $taskDir;
            $ids[$taskDir] = (int)$taskStat['dev'] . ':' . (int)$taskStat['ino'];
            if (self::$bindCheckSwapForTests !== null) (self::$bindCheckSwapForTests)($taskDir);
        }
        // The tmux folder of this session (#929). The shared parent <state>/tmux is sticky and open (workspaces that run as other users
        // share it), so the sockets of a restricted run must not live there. The real folder is <state>/run-tmux/s-<id> under a
        // root-only parent. Root binds it onto the empty root-owned mount point <state>/tmux/s-<id>, the path that tmux uses in the view.
        $tmuxRoot = $state . '/tmux';
        if (!is_dir($tmuxRoot)) @mkdir($tmuxRoot, 01777, true);
        @chmod($tmuxRoot, 01777);
        if (!RunUserService::ensureRunTmuxRootHidden()) { $failure = 'the root-only tmux parent is not available'; return null; }
        $tmuxHost = RunUserService::runTmuxDir($id);
        if ($tmuxHost === '') { $failure = 'the tmux folder path is invalid'; return null; }
        if (!file_exists($tmuxHost) && !is_link($tmuxHost)) @mkdir($tmuxHost, 0700);
        $tst = @lstat($tmuxHost);
        if ($tst === false || ($tst['mode'] & 0170000) !== 0040000 || is_link($tmuxHost)) {
            $failure = 'the tmux folder is not a real folder';
            return null;
        }
        if ((int)$tst['uid'] === 0 && !RunUserService::handOver($tmuxHost, $uid, $gid, 0700)) {
            $failure = 'the tmux folder could not be handed to the run user';
            return null;
        }
        $tst = @lstat($tmuxHost);
        if ($tst === false || (int)$tst['uid'] !== $uid) { $failure = 'the tmux folder owner is invalid'; return null; }
        if (!RunUserService::prepareTmuxMountPoint($id)) { $failure = 'the tmux mount point could not be made'; return null; }
        $tmuxDir = RunUserService::tmuxMountPoint($id);
        $rw[] = $tmuxDir;
        $tmuxHostId = (int)$tst['dev'] . ':' . (int)$tst['ino'];
        $ids[$tmuxDir] = $tmuxHostId;
        if (self::$bindCheckSwapForTests !== null) (self::$bindCheckSwapForTests)($tmuxHost);
        $privateDir = RunUserService::privateDir($id);
        if ($privateDir === '' || !is_dir($privateDir)) { $failure = 'the private flags folder is missing'; return null; }
        // M2: the folder is root-owned mode 0711. Only the two files below belong to the run user (mode 0400).
        // #929: the identity of the folder comes from the same lstat that checks owner and mode. Root binds this folder into the view
        // (the parent run-private is closed on the host) and refuses a folder that was swapped after this check.
        $privateDirId = RunUserService::sealedPrivateDirIdentity($id);
        if ($privateDirId === '') { $failure = 'the private flags folder owner or mode is invalid'; return null; }
        foreach (['args.json', 'env.sh'] as $privateName) {
            $privateFile = $privateDir . '/' . $privateName;
            $privateStat = @lstat($privateFile);
            if (!is_array($privateStat) || is_link($privateFile) || (($privateStat['mode'] & 0170000) !== 0100000)
                || (int)$privateStat['uid'] !== $uid || (int)$privateStat['gid'] !== $gid
                || (int)$privateStat['nlink'] !== 1 || (((int)$privateStat['mode'] & 0777) !== 0400)) {
                $failure = 'a private flags file owner, mode, or link count is invalid';
                return null;
            }
            $ro[] = $privateFile;
            $ids[$privateFile] = (int)$privateStat['dev'] . ':' . (int)$privateStat['ino'];
            if (self::$bindCheckSwapForTests !== null) (self::$bindCheckSwapForTests)($privateFile);
        }
        $quirk = TmuxService::getQuirkPath($agentId);
        $quirkStat = @lstat($quirk);
        if (is_array($quirkStat) && ($quirkStat['mode'] & 0170000) === 0100000 && !is_link($quirk)) {
            $ro[] = $quirk;
            $ids[$quirk] = (int)$quirkStat['dev'] . ':' . (int)$quirkStat['ino'];
            if (self::$bindCheckSwapForTests !== null) (self::$bindCheckSwapForTests)($quirk);
        }
        $terminfo = $state . '/terminfo';
        $terminfoStat = @lstat($terminfo);
        if (is_array($terminfoStat) && ($terminfoStat['mode'] & 0170000) === 0040000 && !is_link($terminfo)) {
            $ro[] = $terminfo;
            $ids[$terminfo] = (int)$terminfoStat['dev'] . ':' . (int)$terminfoStat['ino'];
            if (self::$bindCheckSwapForTests !== null) (self::$bindCheckSwapForTests)($terminfo);
        }
        $runId = (string)($runMeta['runId'] ?? $runMeta['runUserSignIn']['runId'] ?? '');
        $protect = RunUserService::prepareFolderGuards($id, $folder, $runId, !empty($runMeta['mountWritable']));
        if ($protect === null) { $failure = 'the workspace folder guard rejected the launch folder'; return null; }
        return ['rw' => $rw, 'ro' => $ro, 'protect' => $protect, 'readFolders' => $readFolders, 'ids' => $ids,
            'privateDir' => $privateDir, 'privateDirId' => $privateDirId, 'taskDir' => $taskDir,
            'tmuxDir' => $tmuxHost, 'tmuxDirId' => $tmuxHostId];
    }

    /**
     * The environment of a restricted run as shell `export` lines, built by root from the same five tiers as
     * effective-env-export.php (finding 12). The run user cannot read the vault, so it reads this file instead.
     */
    private static function restrictedEnvText(string $rootDir, string $agentId): string
    {
        $builder = method_exists(EnvService::class, 'buildLaunchEnv') ? 'buildLaunchEnv' : 'buildEffectiveEnv';
        $text = '';
        $env = EnvService::$builder($rootDir !== '' ? $rootDir : null, $agentId);
        foreach ((array)$env as $key => $value) {
            $key = (string)$key;
            if (EnvService::isReservedKey($key) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $key) !== 1) continue;
            $text .= 'export ' . $key . '=' . escapeshellarg((string)$value) . "\n";
        }
        return $text;
    }

    /**
     * The command that starts the browser bridge (ttyd) of a restricted workspace: it first enters the view of the
     * workspace, then runs the shell as the run user. $inner is the usual `runuser -u <user> -- env ... /bin/bash <shell>`.
     * An empty string means that the view is missing, and the bridge must not start outside it.
     */
    public static function attachInViewCommand(string $nsFile, string $inner, string $workspaceId = ''): string
    {
        $id = preg_replace('/[^A-Za-z0-9_-]/', '', $workspaceId);
        $mnt = RunUserService::nsFile($id);
        $pidNs = RunUserService::pidNsFile($id);
        $ipcNs = RunUserService::ipcNsFile($id);
        $utsNs = RunUserService::utsNsFile($id);
        $userns = RunUserService::childUserNsFile($id);
        $netnsFile = RunUserService::networkNamespaceFile($id);
        $nsenter = RunUserService::runtimeToolPath('nsenter');
        $setpriv = RunUserService::runtimeToolPath('setpriv');
        $account = RunUserService::status();
        $files = [$mnt, $pidNs, $ipcNs, $utsNs, $userns, $netnsFile];
        $filesReady = count(array_filter($files, static fn(string $file): bool => is_file($file) && !is_link($file))) === count($files);
        if ($id === '' || $id !== $workspaceId || $nsFile !== $mnt || in_array('', $files, true)
            || $nsenter === '' || $setpriv === '' || !$filesReady || empty($account['ready'])) return '';
        return self::restrictedEnvPrefix() . ' /bin/bash ' . escapeshellarg(dirname(__DIR__, 2) . '/scripts/aicli-run-cgroup.sh')
            . ' join ' . escapeshellarg($id) . ' ' . escapeshellarg($setpriv)
            . ' --clear-groups --no-new-privs --'
            . ' ' . escapeshellarg($nsenter) . ' --user=' . escapeshellarg($userns)
            . ' --mount=' . escapeshellarg($mnt) . ' --pid=' . escapeshellarg($pidNs)
            . ' --ipc=' . escapeshellarg($ipcNs) . ' --uts=' . escapeshellarg($utsNs)
            . ' --net=' . escapeshellarg($netnsFile) . ' -S ' . escapeshellarg((string)(int)$account['uid'])
            . ' -G ' . escapeshellarg((string)(int)$account['gid']) . ' --fork -- ' . $inner;
    }

    /** The empty environment with the allow list of a restricted run: a fixed PATH, TERM and LANG, nothing else. */
    public static function restrictedEnvPrefix(): string
    {
        return 'env -i PATH=' . escapeshellarg(RunUserService::FIXED_PATH) . ' TERM=xterm-256color LANG=C.UTF-8';
    }

    /** True when `timeout` ended the command: 124 = stop signal, 137 = kill signal. */
    public static function isEnsureTimeout(int $rc): bool
    {
        return $rc === 124 || $rc === 137;
    }

    /** What a person does after any failed start (ACTIVITY_TRAY_PLAIN_NOTICES.md R5). */
    public const START_RECOVERY = 'Open the workspace and press Restart, or close and reopen it.';

    /** The grey line of a failed start that has a Retry button (auto-launch). */
    public const START_RETRY_HINT = 'Press Retry, or open the workspace and press Restart.';

    /**
     * The grey line of a start row: "<workspace name> · <step in words>"
     * (ACTIVITY_TRAY_PLAIN_NOTICES.md R2). The workspace is the "where"; the
     * title already holds the "what".
     */
    public static function startStepText(string $workspace, string $words): string
    {
        $workspace = trim($workspace);
        return $workspace !== '' ? "$workspace · $words" : $words;
    }

    /**
     * Turn the start row into a failed row: title "<Agent name> did not start",
     * one red sentence, and the recovery sentence as the grey line
     * (ACTIVITY_TRAY_PLAIN_NOTICES.md R1, R5). ActivityService::update() keeps
     * the label it was created with, so the failed row is registered again.
     * $recovery = 'retry' keeps the Retry button; then $step is the grey line.
     * @param array<string,mixed> $meta
     */
    public static function recordStartFailure(string $actId, string $agentName, string $sentence, string $recovery, array $meta, string $step = ''): void
    {
        $workspaceId = (string)($meta['sessionId'] ?? '');
        if ($workspaceId !== '') {
            $restricted = RunUserService::isMarkedRestricted($workspaceId);
            $runUserWorkspace = !empty($meta['_runUserWorkspace']) || $restricted;
            $isSignInWorkspace = !empty($meta['_runUserSignIn']);
            unset($meta['_runUserWorkspace'], $meta['_runUserSignIn']);
            if ($restricted && $runUserWorkspace && !$isSignInWorkspace) {
                // The start's captured state is authoritative even if its workspace record has since been removed.
                RunUserService::clearSigninMarker($workspaceId);
            }
            if ($restricted) RunUserService::releaseWorkspace($workspaceId, $isSignInWorkspace);
            // If release could not stop or save a sign-in run, it keeps the restricted mark and marker for retry.
            // A failed start with no live restricted runtime must not leave a marker for a later non-sign-in run.
            if ($runUserWorkspace) {
                if (!RunUserService::isMarkedRestricted($workspaceId)) RunUserService::clearSigninMarker($workspaceId);
            }
        }
        $old = ActivityService::get($actId);
        ActivityService::register($actId, 'start', "$agentName did not start", [
            'status'   => 'failed',
            'step'     => $step,
            'progress' => (int)($old['progress'] ?? 0),
            'error'    => $sentence,
            'recovery' => $recovery,
            'meta'     => $meta,
        ]);
    }

    /**
     * The red line of a failed start: one plain sentence, no path, no binary
     * name, no id (ACTIVITY_TRAY_PLAIN_NOTICES.md R5). The detail is in the
     * Debug Console log lines written next to each call.
     */
    public static function startFailureSentence(string $reason, string $agentName): string
    {
        switch ($reason) {
            case 'storage_migration':    return 'Storage is being migrated. Workspaces open again when it finishes.';
            case 'agent_uninstalling':  return 'This agent is being uninstalled. It cannot start now.';
            case 'host_shutdown':       return StorageMountService::HOST_SHUTDOWN_MESSAGE;
            case 'agent_unknown':       return 'This agent is not installed on this server.';
            case 'no_terminal_server':  return 'The terminal server program is missing.';
            case 'runtime_unavailable': return 'A required terminal program is missing or cannot run. Check the Debug Console.';
            case 'agent_storage':       return "The storage for $agentName could not be mounted.";
            case 'home_storage':        return 'The home storage could not be mounted.';
            case 'terminal_no_answer':  return 'The terminal server did not answer in 2 seconds.';
            case 'terminal_no_launch':  return 'The terminal server could not be started.';
            case 'ensure_timeout':      return "$agentName did not start in time.";
            case 'ensure_failed':       return "$agentName could not start. Check the Debug Console.";
        }
        return "$agentName did not start.";
    }

    /**
     * #759: the launch step that runs now, and the workspace it belongs to. A
     * `timeout` around a caller (the supervisor reconcile runs under `timeout 20`,
     * the array-start event under `timeout 60`) ends the launch with SIGTERM, and
     * PHP then stops with no log line. The trap below writes one ERROR line that
     * names the workspace and the step. Null when no launch runs.
     * @var array{id:string,agentId:string,phase:string}|null
     */
    private static ?array $launchTrap = null;

    /** @var int depth of nested launches that share one trap (a restart calls start inside start) */
    private static int $launchTrapDepth = 0;

    /** @var mixed the SIGTERM handler that was set before the trap, to restore */
    private static $launchTrapPrevious = null;

    /** @var bool the async-signals setting before the trap */
    private static bool $launchTrapAsync = false;

    /** Remember the step that runs now (shown in the ERROR line if a signal ends the launch). */
    private static function launchStep(string $step): void
    {
        if (self::$launchTrap !== null) self::$launchTrap['phase'] = $step;
    }

    /**
     * Write the ERROR line of a launch that a signal ended, and show it in the
     * activity tray. Public so a test can call it without a real signal.
     */
    public static function onLaunchSignal(int $signal): void
    {
        $t = self::$launchTrap;
        if ($t === null) return;
        $id = $t['id'];
        LogService::log("CRITICAL: the launch of $id was stopped by signal $signal during step: " . $t['phase'] . ". The workspace may have an agent and no terminal server (ttyd).", LogService::LOG_ERROR, "TerminalService");
        try {
            $agentName = UtilityService::trayAgentName($t['agentId']);
            self::recordStartFailure("start_$id", $agentName, "$agentName did not finish starting.", self::START_RECOVERY,
                ['sessionId' => $id, 'agentId' => $t['agentId'],
                    '_runUserWorkspace' => !empty($t['_runUserWorkspace']), '_runUserSignIn' => !empty($t['_runUserSignIn'])]);
        } catch (\Throwable $e) {
            // The log line above is the record. The tray is a courtesy.
        }
    }

    /** Set the trap for SIGTERM when the pcntl extension exists. Nested calls share one trap. */
    private static function armLaunchTrap(string $id, string $agentId, array $runUserContext = []): void
    {
        if (self::$launchTrapDepth++ > 0) return;   // the outer launch owns the trap
        self::$launchTrap = ['id' => $id, 'agentId' => $agentId, 'phase' => 'preparing',
            '_runUserWorkspace' => !empty($runUserContext['runUserWorkspace']),
            '_runUserSignIn' => !empty($runUserContext['runUserSignIn'])];
        if (!function_exists('pcntl_signal') || !function_exists('pcntl_async_signals') || !defined('SIGTERM')) return;
        self::$launchTrapAsync = pcntl_async_signals();
        pcntl_async_signals(true);
        self::$launchTrapPrevious = function_exists('pcntl_signal_get_handler') ? pcntl_signal_get_handler(SIGTERM) : SIG_DFL;
        $previous = self::$launchTrapPrevious;
        pcntl_signal(SIGTERM, static function (int $sig) use ($previous): void {
            self::onLaunchSignal($sig);
            if (is_callable($previous)) { $previous($sig); return; }
            pcntl_signal($sig, SIG_DFL);
            if (function_exists('posix_kill')) posix_kill(getmypid(), $sig);
        });
    }

    /** Remove the trap again when the outer launch ends. */
    private static function disarmLaunchTrap(): void
    {
        if (--self::$launchTrapDepth > 0) return;
        self::$launchTrapDepth = 0;
        self::$launchTrap = null;
        if (function_exists('pcntl_signal') && defined('SIGTERM') && self::$launchTrapPrevious !== null) {
            pcntl_signal(SIGTERM, self::$launchTrapPrevious);
            pcntl_async_signals(self::$launchTrapAsync);
        }
        self::$launchTrapPrevious = null;
    }

    /** startTerminal() body. Call it only while this process holds the workspace's SessionLaunchLock. */
    private static function startTerminalLocked($id, $path, $chatId, $agentId, string $origin): array {
        $workspaceRecord = SpawnService::findRecord((string)$id);
        $spawnMeta = is_array($workspaceRecord['spawn'] ?? null) ? $workspaceRecord['spawn'] : [];
        $runMeta = is_array($spawnMeta['run'] ?? null) ? $spawnMeta['run'] : (is_array($spawnMeta['runUserSignIn'] ?? null) ? $spawnMeta['runUserSignIn'] : []);
        $isRunUserSignIn = is_array($spawnMeta['runUserSignIn'] ?? null);
        $markedRestricted = RunUserService::isMarkedRestricted((string)$id);
        $runUserView = $isRunUserSignIn || (string)($workspaceRecord['runAs'] ?? '') === RunUserService::USER
            || (string)($runMeta['runAs'] ?? '') === RunUserService::USER || $markedRestricted;
        $runUserContext = ['record' => $workspaceRecord, 'spawn' => $spawnMeta, 'runMeta' => $runMeta,
            'isRunUserSignIn' => $isRunUserSignIn, 'markedRestricted' => $markedRestricted,
            'runUserWorkspace' => $runUserView, 'runUserSignIn' => $isRunUserSignIn];
        self::armLaunchTrap((string)$id, (string)$agentId, $runUserContext);
        try {
            return self::startTerminalSteps($id, $path, $chatId, $agentId, $origin, $runUserContext);
        } finally {
            self::disarmLaunchTrap();
        }
    }

    /** The steps of a start. Called by startTerminalLocked() only. */
    private static function startTerminalSteps($id, $path, $chatId, $agentId, string $origin, array $runUserContext): array {
        LogService::log("Initiating console session sequence for: $id (Agent: $agentId)...", LogService::LOG_INFO, "TerminalService");
        
        // 1. Pre-launch checks
        // IMPORTANT-1: early-return ONLY when a real ttyd is bound to this sid's
        // socket. isRunning() is now true for a detached tmux session with NO ttyd
        // (the relaunch-survival state this feature creates) — returning on that
        // would leave the browser with no socket. When ttyd died but tmux survived
        // we must fall through and (re)launch ttyd; ensure-session is idempotent so
        // it attaches to the existing detached session rather than double-spawning.
        if (ProcessManager::isTtydBound($id)) {
            LogService::log("Session $id (Agent: $agentId): reusing bound bridge.", LogService::LOG_INFO, "TerminalService", false);   // debug.log only: this line repeats at every page reload
            return ['ok' => true, 'reason' => 'already_running'];
        }

        // Issue #56: every caller converges here, including boot auto-launch,
        // activity retry and post-install relaunch. The browser handler already
        // checked the workspace, but those headless paths bypassed it and could
        // reach tmux while /mnt/user was only a rootfs stub. Fail before writing
        // session metadata, mounting HOME or invoking aicli-shell.sh.
        if (!empty($path) && !StorageMountService::isPathAvailable((string)$path)) {
            LogService::log(
                "Session $id deferred: workspace path is unavailable: $path",
                LogService::LOG_WARN,
                "TerminalService"
            );
            return ['ok' => false, 'reason' => 'path_unavailable'];
        }

        // Forgejo #558: the array is stopping. A pool stays mounted until the end
        // of the stop, so the check above passes; a start here holds the pool
        // and the array cannot stop. Every start path converges here.
        if (!empty($path) && StorageMountService::startBlockedByArrayStop((string)$path)) {
            LogService::log(
                "Session $id deferred: the array is stopping and the workspace is on it: $path",
                LogService::LOG_WARN,
                "TerminalService"
            );
            return ['ok' => false, 'reason' => 'array_stopping'];
        }

        // Issue #761 (GitHub #10): a workspace folder on flash or RAM passes the check
        // above. But the start below still mounts the agent and the home from their
        // persist paths, and while the array stops those mounts hold the pool. Auto-launch
        // and the crash reconcile come through here too. The launch trap and the
        // workspace lock are set by the caller, and this return releases both.
        if (StorageMountService::storageBlockedByArrayStop()) {
            LogService::log(
                "Session $id deferred: the array is stopping and plugin storage is on it",
                LogService::LOG_WARN,
                "TerminalService"
            );
            return ['ok' => false, 'reason' => 'array_stopping'];
        }

        // #910: the host shuts down (reboot or power-off). Nothing starts, whatever the
        // storage class: the array-stop guards above let a flash workspace run during a
        // plain array stop, and that is right only when the host stays up. One log line
        // per shutdown comes from refuseWhileHostShutsDown().
        if (StorageMountService::refuseWhileHostShutsDown("the start of session $id")) {
            return ['ok' => false, 'reason' => 'host_shutdown'];
        }

        // 2. Setup environment
        $config = ConfigService::getConfig();
        $registry = AgentRegistry::getRegistry();

        // D-208: Special case for raw terminal feature (does not require registry entry)
        if ($agentId === 'terminal') {
            $agent = [
                'name' => 'Raw Terminal',
                'binary' => '',
                'resume_cmd' => '',
                'resume_latest' => '',
                'env_prefix' => ''
            ];
        } else {
            $agent = $registry[$agentId] ?? null;
        }

        // T-09 (ACTIVITY_TRAY.md): step-level start activity. The cold-start
        // overlay subscribes to aicli_activity and renders the live step text;
        // the AICLI_TTYD_READY dismissal path is unchanged (additive display).
        $actId = "start_$id";
        // Lead with the WORKSPACE. The tray is server-wide, so two "Starting Claude
        // Code" rows side by side said nothing about which workspace each one was —
        // the same rule the waiting-message pill follows (RELAY_WAITING_PILL.md).
        // ACTIVITY_TRAY.md "When a start row appears": the agent's tmux session is alive and
        // only its terminal connection (ttyd) is gone — the post-deploy click. Nothing is
        // starting, so this is a RECONNECT, and the tray keeps it out of sight.
        $reattach = ProcessManager::isRunning((string)$id);
        $wsName = ConfigService::workspaceName((string)$id);
        if ($wsName === '' && !empty($path)) $wsName = basename((string)$path);
        // ACTIVITY_TRAY_PLAIN_NOTICES.md R1/R2: the title says what happens, with the
        // agent's display name ("Starting Claude Code"). The workspace goes in the
        // grey line ("home-homelab · Mounting home storage"), never in the title.
        $agentName = (string)($agent['name'] ?? $agentId);
        $startLabel = ($reattach ? 'Reconnecting to ' : 'Starting ') . $agentName;
        $wsGrey = $wsName !== '' ? $wsName : (string)$id;
        $startStep = static fn(string $words): string => self::startStepText($wsGrey, $words);
        $startMeta = ['sessionId' => $id, 'agentId' => $agentId, 'path' => (string)($path ?? ''), 'origin' => $origin, 'reattach' => $reattach,
            '_runUserWorkspace' => !empty($runUserContext['runUserWorkspace']),
            '_runUserSignIn' => !empty($runUserContext['runUserSignIn'])];
        $activityMeta = $startMeta;
        unset($activityMeta['_runUserWorkspace'], $activityMeta['_runUserSignIn']);
        ActivityService::register($actId, 'start', $startLabel, [
            'step' => $startStep('Preparing environment'), 'progress' => 5, 'meta' => $activityMeta,
        ]);

        if (!$agent) {
            LogService::log("Error: Agent $agentId not found in registry.", LogService::LOG_ERROR, "TerminalService");
            self::recordStartFailure($actId, $agentName, self::startFailureSentence('agent_unknown', $agentName), self::START_RECOVERY, $startMeta);
            return ['ok' => false, 'reason' => 'agent_unknown'];
        }

        $runtimePrograms = self::requiredRuntimePrograms();
        $missingRuntime = self::missingRequiredRuntimePrograms($runtimePrograms);
        if ($missingRuntime !== []) {
            $missingNames = implode(', ', $missingRuntime);
            LogService::log("Start of $id refused: required runtime program(s) unavailable: $missingNames.", LogService::LOG_ERROR, "TerminalService");
            self::recordStartFailure($actId, $agentName, self::startFailureSentence('runtime_unavailable', $agentName), self::START_RECOVERY, $startMeta);
            return ['ok' => false, 'reason' => 'runtime_unavailable'];
        }

        // Running-agent-safe updates: resolve the active `src` pointer once at
        // launch. ttyd and the detached tmux bootstrap then keep an immutable
        // generation path in argv, so a later atomic activation cannot replace
        // the wrapper underneath a running workspace.
        $activeSrc = realpath('/usr/local/emhttp/plugins/unraid-aicliagents/src');
        if ($activeSrc === false) {
            $activeSrc = '/usr/local/emhttp/plugins/unraid-aicliagents/src';
        }
        $shell = $activeSrc . "/scripts/aicli-shell.sh";
        $testPaths = defined('AICLI_TEST_TMP') ? (self::$startPathsForTests ?? []) : [];
        $sock = (string)($testPaths['socket'] ?? "/var/run/aicliterm-$id.sock");
        $pidFile = (string)($testPaths['pid'] ?? "/var/run/unraid-aicliagents-$id.pid");
        $logFile = (string)($testPaths['log'] ?? "/tmp/ttyd-$id.log");

        // Record this session's agent + workspace path to the parallel
        // /var/run metadata files. ProcessManager::stopTerminal already
        // unlinks them on clean shutdown; we need to be the writer. Without
        // these files, listActiveSessionsForAgent (used by the agent-upgrade
        // UX flow) cannot match sessions to agents.
        AtomicWriteService::write(UtilityService::getAgentIdPath($id), (string)$agentId);
        if (!empty($path)) {
            AtomicWriteService::write(UtilityService::getWorkDirFilePath($id), (string)$path);
        }
        // CLAUDE_RESUME_BY_SESSION_ID.md R5: a workspace whose saved or requested resume
        // id is still a Claude session NAME (saved before this resolver existed) heals on
        // this launch instead of opening Claude's session picker.
        if ((string)$agentId === ClaudeSessionNameResolver::AGENT_ID && is_string($chatId) && $chatId !== ''
            && !ClaudeSessionNameResolver::isSessionId($chatId) && !empty($path)) {
            $chatId = ClaudeSessionNameResolver::resolve((string)$agentId, (string)$path, $chatId, null,
                ConfigService::isSharedFolder((string)$path, (string)$agentId, (string)$id));
        }
        if (!empty($chatId) && $chatId !== 'auto' && $chatId !== '_fresh_') {
            AtomicWriteService::write(UtilityService::getChatIdPath($id), (string)$chatId);
        }
        // NOTE: per-session user is written AFTER $username is finalised (~line 99);
        // the write call below is the only place that happens.

        // Verification
        if (!file_exists($shell)) {
            LogService::log("Warning: Shell script missing: $shell", LogService::LOG_WARN, "TerminalService");
        }

        $ttyd = (string)($runtimePrograms['ttyd'] ?? '/usr/bin/ttyd');

        if (!file_exists($ttyd)) {
            LogService::log("CRITICAL: ttyd binary not found in PATH or /usr/bin/ttyd!", LogService::LOG_ERROR, "TerminalService");
            self::recordStartFailure($actId, $agentName, self::startFailureSentence('no_terminal_server', $agentName), self::START_RECOVERY, $startMeta);
            return ['ok' => false, 'reason' => 'no_terminal_server'];
        }

        LogService::log("Found ttyd at: $ttyd", LogService::LOG_DEBUG, "TerminalService");

        // R16: the workspace record and sign-in flag were captured before the launch trap was armed.
        $workspaceRecord = $runUserContext['record'];
        $spawnMeta = $runUserContext['spawn'];
        $runMeta = $runUserContext['runMeta'];
        $isRunUserSignIn = $runUserContext['isRunUserSignIn'];
        // The mark is read from two places. A browser save can resend a record without the top-level field (the merge keeps
        // only the spawn marks), and a missing mark would start the agent as root, outside the private disk view.
        // Finding 11: and from a root-only mark in /var/run that the first restricted start wrote. A record that is gone (a
        // damaged workspace file reads as an empty list) must not turn the workspace into one that starts as root.
        $markedRestricted = $runUserContext['markedRestricted'];
        $runUserView = $runUserContext['runUserWorkspace'];
        if (!$runUserView && RunUserService::folderHasMayChangeRunMarker((string)$path)) {
            LogService::log("Start of $id refused: the folder holds a marker from a restricted run that could change files.", LogService::LOG_ERROR, 'TerminalService');
            self::recordStartFailure($actId, $agentName, 'This folder has a marker from a restricted run that could change files. Review the folder contents, then remove .aicli-run.json before you start it as root.', self::START_RECOVERY, $startMeta);
            return ['ok' => false, 'reason' => 'run_user_folder_marker'];
        }
        if ($markedRestricted && $workspaceRecord === null) {
            LogService::log("Start of $id refused: it is marked as a restricted run, but its workspace record is gone.", LogService::LOG_ERROR, 'TerminalService');
            self::recordStartFailure($actId, $agentName, 'This workspace was a restricted script run, and its record is gone, so it was not started.', self::START_RECOVERY, $startMeta);
            return ['ok' => false, 'reason' => 'run_user_record_missing'];
        }
        if (!$runUserView && $workspaceRecord === null && !empty($path)) {
            // A folder under the temporary root with no record at all is the leftover of a restricted run, not a place for root.
            $tempRoot = AgentRunService::tempRoot();
            if (!isset($tempRoot['error']) && strpos((string)$path, rtrim((string)$tempRoot['path'], '/') . '/') === 0) {
                LogService::log("Start of $id refused: no record, and the folder is a temporary run folder.", LogService::LOG_ERROR, 'TerminalService');
                self::recordStartFailure($actId, $agentName, 'This folder is a temporary run folder, and the workspace has no record, so it was not started.', self::START_RECOVERY, $startMeta);
                return ['ok' => false, 'reason' => 'run_user_record_missing'];
            }
        }
        if ($runUserView) {
            // Only one restricted workspace is open at a time: they share one user id (finding 10). The check and the root-only
            // mark are made under one lock (finding 15 of the second review).
            $other = RunUserService::claimRestrictedStart((string)$id);
            if ($other !== '') {
                LogService::log("Start of $id refused: the restricted workspace $other is open.", LogService::LOG_WARN, 'TerminalService');
                self::recordStartFailure($actId, $agentName, 'Another script run as aicli-run is open. Only one runs at a time. Start this one when the other has closed.', self::START_RECOVERY, $startMeta);
                return ['ok' => false, 'reason' => 'run_user_busy'];
            }
            // Findings 2 and 5 of the second review: no process of the run user is alive before the start, and the firewall
            // rule that keeps it away from the services of this host is in place. Fail closed.
            $notReady = RunUserService::readyForRestrictedStart(ProcessManager::isRunning((string)$id));
            if ($notReady !== '') {
                LogService::log("Start of $id refused: $notReady", LogService::LOG_ERROR, 'TerminalService');
                self::recordStartFailure($actId, $agentName, $notReady, self::START_RECOVERY, $startMeta);
                return ['ok' => false, 'reason' => 'run_user_not_ready'];
            }
        }
        if ($runUserView) {
            $runUserStatus = RunUserService::status();
            if (empty($runUserStatus['ready'])) {
                LogService::log("Start of $id refused: " . (string)$runUserStatus['fault'], LogService::LOG_WARN, 'TerminalService');
                self::recordStartFailure($actId, $agentName, (string)$runUserStatus['fault'], self::START_RECOVERY, $startMeta);
                return ['ok' => false, 'reason' => 'run_user_not_ready'];
            }
            if ($origin !== self::ORIGIN_BRIDGE_WARM && !ProcessManager::isRunning((string)$id)) {
                $runtime = RunUserService::prepareRuntime((string)$id);
                if (empty($runtime['ok'])) {
                    LogService::log("Start of $id refused: " . (string)$runtime['reason'], LogService::LOG_ERROR, 'TerminalService');
                    self::recordStartFailure($actId, $agentName, (string)$runtime['reason'], self::START_RECOVERY, $startMeta);
                    return ['ok' => false, 'reason' => 'run_user_runtime_failed'];
                }
            }
        }

        // D-195 / Bug #1053: any configured user that is not present in
        // /etc/passwd falls back to 'root'. ManagerConfigTab's user dropdown
        // historically saved the option INDEX (e.g. "4") instead of the
        // username — and a previously-valid user may also have been removed
        // from the system since the setting was saved. Without this fallback,
        // runuser fails with "user X does not exist" and every terminal then
        // surfaces as "Terminal session not found".
        $username = $runUserView ? RunUserService::USER : (string) ($config['user'] ?? '');
        $userValid = $runUserView || ($username !== ''
            && function_exists('posix_getpwnam')
            && is_array(@posix_getpwnam($username)));
        if (!$userValid) {
            if ($username !== '' && $username !== 'root') {
                LogService::log("Configured user '$username' does not exist on this system — falling back to root (Bug #1053)",
                    LogService::LOG_WARN, "TerminalService");
            }
            $username = 'root';
        }

        // WP #1262: persist the effective username so listActiveSessionsForHome
        // and filterSessionsByHome can attribute legacy-started sessions to the
        // right user without a config re-read.
        AtomicWriteService::write(UtilityService::getUserIdPath($id), $username);

        // Bug #1054: ensure the shared tmp tree is world-writable (sticky) and
        // the per-user work dir is owned by the agent user, so non-root agents
        // can create their per-session run scripts (aicli-run-*.sh) and append
        // perf.log. PHP runs here as the web user (root on Unraid); aicli-shell.sh
        // runs as $username after runuser, and otherwise has no way to mkdir under
        // a root-owned tmp tree. The OverlayFS upper for the home stack is chowned
        // by mount_stack.sh — this block handles only the non-overlay tmp paths.
        $stateRoot = SecretPaths::stateRoot();
        @mkdir($stateRoot, 01777, true);
        @chmod($stateRoot, 01777);
        $workRoot = $stateRoot . '/work';
        @mkdir($workRoot, 01777, true);
        @chmod($workRoot, 01777);
        $userWorkDir = $workRoot . '/' . $username;
        if (!is_dir($userWorkDir)) {
            @mkdir($userWorkDir, 0755, true);
        }
        $pw = function_exists('posix_getpwnam') ? @posix_getpwnam($username) : null;
        // Finding 5 of the second review: root never changes the owner or mode of a path that the run user can replace with a
        // link (chown and chmod follow a link). The folder is changed only while ROOT owns it: the run user cannot swap a
        // folder that root owns, in the sticky parent. A link, or a folder that already belongs to the user, is left alone.
        $workStat = @lstat($userWorkDir);
        $rootOwnsWork = is_array($workStat) && !is_link($userWorkDir) && (int)$workStat['uid'] === 0 && ($workStat['mode'] & 0170000) === 0040000;
        if (is_array($pw) && $rootOwnsWork) {
            @chown($userWorkDir, $pw['uid']);
            @chgrp($userWorkDir, $pw['gid']);
            @chmod($userWorkDir, 0755);
        }
        if (is_array($pw)) {
            // TERMINAL_FAST_ATTACH.md S5: the PHP file-cache folder of this user. Only root can make it.
            UtilityService::ensurePhpCacheDirFor((int)$pw["uid"]);
        }
        // A stale perf.log from an earlier root-as-user run is 0644 root-owned;
        // a non-root agent can't append. World-appendable is fine for a debug
        // perf trace (no secrets).
        $perfLog = SecretPaths::stateRoot() . '/perf.log';
        if (file_exists($perfLog)) {
            @chmod($perfLog, 0666);
        }

        // 2.5 Write agent quirk profile to tmp so aicli-shell.sh Tier-1.5 can apply it.
        // T-07: quirk profiles are agent-specific tmux requirements (e.g. Claude Code's
        // extended-keys / terminal-features for Shift+Enter) that sit between BUILTIN and
        // user-editable tiers. The file is ephemeral (/tmp) — written fresh every launch.
        if ($agentId !== 'terminal') {
            TmuxService::writeQuirkFile($agentId);
        }

        // 3. Ensure Storage is Mounted (SquashFS On-Demand)
        // In emergency mode, agent binary is already in RAM and home is a symlink — skip sqsh mounts.
        $isEmergency = StorageMountService::isEmergencyMode();
        if ($agentId !== 'terminal' && !$isEmergency) {
            ActivityService::update($actId, ['step' => $startStep('Mounting agent storage'), 'progress' => 20]);
            if (!FileStorage::ensureReady("agent/$agentId")->ok) {   // Epic #1310: express intent via the facade
                LogService::log("FAILED to mount agent storage for $agentId", LogService::LOG_ERROR, "TerminalService");
                self::recordStartFailure($actId, $agentName, self::startFailureSentence('agent_storage', $agentName), self::START_RECOVERY, $startMeta);
                return ['ok' => false, 'reason' => 'agent_storage'];
            }
        }
        if (!$isEmergency) {
            ActivityService::update($actId, ['step' => $startStep('Mounting home storage'), 'progress' => 45]);
            if (!FileStorage::ensureReady("home/$username")->ok) {   // Epic #1310: facade intent
                LogService::log("FAILED to mount home storage for $username", LogService::LOG_ERROR, "TerminalService");
                self::recordStartFailure($actId, $agentName, self::startFailureSentence('home_storage', $agentName), self::START_RECOVERY, $startMeta);
                return ['ok' => false, 'reason' => 'home_storage'];
            }

            // #113: re-assert this agent's managed Hub config now the home
            // overlay is mounted and before ttyd starts the client. MCP clients
            // read their server list once at startup, so a config written only
            // when an administrator toggled a setting can be absent here — most
            // often because that projection ran against an unmounted home and
            // silently no-opped. Never blocks the launch.
            if ($runUserView) {
                if ($isRunUserSignIn && !RunUserService::markSigninWorkspace((string)$id)) {
                    self::recordStartFailure($actId, $agentName, 'A private sign-in marker could not be saved, so the sign-in workspace was not started.', self::START_RECOVERY, $startMeta);
                    return ['ok' => false, 'reason' => 'run_user_signin_marker_failed'];
                }
            } else {
                \AICliAgents\Services\Hub\HubProjector::projectForLaunch((string)$agentId);
            }

            // Always-on Relay: give a never-configured workspace its default
            // subscription document now, so it actually receives messages.
            // Idempotent — an existing document, including a deliberately empty
            // one, is left alone. A run as the run user has no Relay.
            try { if (!$runUserView) AgentRelayService::ensureWorkspaceSubscription((string)$id); }
            catch (\Throwable $e) { LogService::log("Relay default subscription for $id failed: " . $e->getMessage(), LogService::LOG_WARN, "TerminalService"); }
        }

        // Environment Construction
        // Resolve effective binary: prefer 'binary', fall back to 'binary_fallback'
        // if the primary doesn't exist. Packages like Claude Code 2.1.x ship a
        // native binary at bin/claude.exe and drop the legacy cli.js — but older
        // installs may still have only cli.js. This keeps both paths working.
        $primaryBin = $agent['binary'] ?? '';
        $fallbackBin = $agent['binary_fallback'] ?? '';
        $effectiveBin = ($primaryBin && is_file($primaryBin))
            ? $primaryBin
            : ($fallbackBin && is_file($fallbackBin) ? $fallbackBin : $primaryBin);

        // If the UI asked to resume but didn't supply an explicit ID, look up
        // the saved ID stored at clean-close for this (path, agent) pair.
        $resolvedChatId = $chatId;
        if ($resolvedChatId === 'auto' && $path) {
            $resolvedChatId = ConfigService::getResumeId($path, $agentId, (string)$id);
            if ((string)$agentId === ClaudeSessionNameResolver::AGENT_ID && is_string($resolvedChatId) && $resolvedChatId !== '') {
                $resolvedChatId = ClaudeSessionNameResolver::resolve((string)$agentId, (string)$path, $resolvedChatId, null,
                    ConfigService::isSharedFolder((string)$path, (string)$agentId, (string)$id));
            }
            LogService::log("Auto-resume: chatId=" . ($resolvedChatId ?: 'none') . " for $agentId at $path", LogService::LOG_INFO, "TerminalService");
        }

        // RESUME_IDENTITY_PER_WORKSPACE.md (V2): with no conversation resolved, the shell
        // falls back to FOLDER-scoped discovery — the newest conversation in this folder's
        // store. On a folder another open workspace shares, that is as likely the sibling's
        // live conversation as this one's, so start fresh rather than guess.
        if (($resolvedChatId === null || $resolvedChatId === '') && $path
            && ConfigService::isSharedFolder((string)$path, (string)$agentId, (string)$id)) {
            $resolvedChatId = '_fresh_';
            LogService::log("Start fresh for $id: $agentId at $path is shared with another open workspace, so folder-wide conversation discovery cannot be trusted", LogService::LOG_INFO, "TerminalService");
        }

        if ($agentId === 'claude-code' && !$runUserView) {
            // For the run user, the child process of run-user-prepare.php does this as the run user (finding 4).
            ClaudePluginPathService::repairHome(UtilityService::getWorkDir($username) . '/home');
        }

        $env = [
            'AICLI_SESSION_ID'  => $id,
            'AICLI_USER'        => $username,
            'AGENT_ID'          => $agentId,
            'AGENT_NAME'        => $agent['name'],
            'BINARY'            => $effectiveBin,
            // Fix A: pass both raw paths so aicli-shell.sh can re-resolve on every
            // relaunch iteration. This lets an in-place upgrade (e.g. claude-code
            // 2.1.x swapping cli.js → native bin/claude.exe) be picked up without
            // closing the workspace. The shell freezes these as frozen_binary /
            // frozen_binary_fallback and re-evaluates existence before each launch.
            'BINARY_PRIMARY'    => $primaryBin,
            'BINARY_FALLBACK'   => $fallbackBin,
            // Resolve {plugin_args} placeholder in resume templates so the shell
            // script receives ready-to-use strings. plugin_args are a plugin
            // default (e.g. codex sandbox overrides) — not user-editable workspace
            // args — and must survive in every launch path (fresh, resume, resume-latest).
            'PLUGIN_ARGS'       => $agent['plugin_args'] ?? '',
            'RESUME_CMD'        => str_replace(
                '{plugin_args}',
                $agent['plugin_args'] ?? '',
                $agent['resume_cmd'] ?? ''
            ),
            'RESUME_LATEST'     => str_replace(
                '{plugin_args}',
                $agent['plugin_args'] ?? '',
                $agent['resume_latest'] ?? ''
            ),
            'ENV_PREFIX'        => $agent['env_prefix'] ?? '',
            'AICLI_HOME'        => $runUserView ? RunUserService::sessionHomePath((string)$id) : UtilityService::getWorkDir($username) . "/home",
            'AICLI_ROOT'        => $path ?: '/mnt',
            // #110: the directory the agent actually runs IN. Identical to the
            // workspace path unless the operator opted into the pool path for
            // cache-only shares, which keeps the agent's cwd off the /mnt/user
            // FUSE mount (an array stop can then unmount it). The workspace
            // IDENTITY (AICLI_ROOT, hashes, resume records) never changes.
            'AICLI_CWD'         => $path ? PoolPathService::launchDirectory((string)$path, $config) : '/mnt',
            // Agent Relay self-service reads this launch-bound session identity;
            // the command itself never accepts a session argument.
            'AICLI_RELAY_COMMAND' => '/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/relay-agent.php',   // one word: works quoted (#735)
            // Plugin management (read-only) self-service. Same launch-bound identity
            // rule as the Relay: the command never accepts a session argument.
            'AICLI_ADMIN_COMMAND' => '/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/admin-agent.php',
            'AICLI_CHAT_SESSION_ID' => $resolvedChatId ?: '',
            // SIDE_BY_SIDE_AGENT_INSTALLS.md Phase 1: route through the resolver
            // instead of the literal AGENT_BASE path.
            'NODE_PATH'         => AgentRegistry::agentPath($agentId) . "/node_modules"
        ];

        // Merge the full 5-tier effective env from EnvService — single source
        // of truth shared with aicli-shell.sh (which also re-reads it per loop
        // iteration for hot-apply). Tier order (later wins per key):
        //   1. agent['source']['env']           (registry/agents.json defaults)
        //   2. SecretService::getAgentSecrets   (global agent vault — secrets.cfg)
        //   3. SecretService::getWorkspaceSecrets  (per-workspace secrets — new)
        //   4. EnvService::getAgentEnvs         (general agent vars — new)
        //   5. EnvService::getWorkspaceEnvs     (general workspace vars — existing)
        // Per WP #736: this also closes the latent dead-write gap where
        // secrets.cfg was never injected at launch (verified 2026-05-11).
        // Plugin-internal vars set above (AICLI_*, AGENT_ID, BINARY, etc.) must
        // win over user-set vars regardless — EnvService rejects reserved names
        // at save time; the explicit precedence here is belt-and-braces.
        // #214 (security): the user/secret env tiers deliberately DO NOT go on the
        // launch command line. `env $envStr` becomes the argv of the long-lived
        // ttyd/runuser processes, world-readable in `ps` / /proc/<pid>/cmdline, so
        // merging vault + workspace secrets into $env here leaked every secret to
        // any local user. It was also redundant: the wrapper's RUN_SCRIPT injects
        // the SAME 5-tier effective-env merge (secrets included)
        // off-argv via effective-env-export.php — for BOTH the headless
        // ensure-session tmux create and the ttyd attach (same session) — plus
        // _aicli_load_envs re-applies it every run-loop iteration. So $env carries
        // ONLY the non-secret plugin-internal launch vars (AICLI_*, AGENT_ID,
        // BINARY, …) set above; the agent still gets its full env, just from the
        // environment (RUN_SCRIPT), never argv. See ENV_AND_SECRETS_TIERS.md.

        if ($runUserView) {
            // R18: a run as the run user cannot run the root commands and cannot read the plugin state, so it gets neither
            // command. It gets the private task folder and the mark that the shell reads (it never creates a session).
            unset($env['AICLI_RELAY_COMMAND'], $env['AICLI_ADMIN_COMMAND']);
            $env['AICLI_TASK_DIR'] = (string)($runMeta['taskDir'] ?? '');
            $env['AICLI_RESTRICTED_RUN'] = '1';
            // Findings 7 and 12: root writes the flags and environment in a private folder. Before a new run starts,
            // it hands only args.json and env.sh to aicli-run as read-only files. A bridge reconnect reuses the sealed files.
            $privateDir = RunUserService::privateDir((string)$id);
            $privateFilesReady = $privateDir !== '';
            if ($privateFilesReady && $origin !== self::ORIGIN_BRIDGE_WARM) {
                $argsSource = ArgsService::getIdArgsPath((string)$id);
                $argsJson = is_file($argsSource) ? (string)@file_get_contents($argsSource) : '';
                if ($argsJson === '' || json_decode($argsJson, true) === null) $argsJson = '{"args":""}';
                $envText = self::restrictedEnvText((string)$path, (string)$agentId);
                $privateFilesReady = RunUserService::writePrivateFile((string)$id, 'args.json', $argsJson)
                    && RunUserService::writePrivateFile((string)$id, 'env.sh', $envText);
            }
            if (!$privateFilesReady || !RunUserService::preparePrivateDirForRunUser((string)$id, (int)$runUserStatus['uid'], (int)$runUserStatus['gid'])) {
                LogService::log("Start of $id refused: the private flags and environment of the run could not be written.", LogService::LOG_ERROR, 'TerminalService');
                self::recordStartFailure($actId, $agentName, 'The private flags and environment of this run could not be written, so it was not started.', self::START_RECOVERY, $startMeta);
                return ['ok' => false, 'reason' => 'run_user_private_failed'];
            }
            $env['AICLI_ARGS_ID_FILE'] = $privateDir . '/args.json';
            $env['AICLI_ENV_FILE'] = $privateDir . '/env.sh';

            // Create the private HOME after writePrivateFile() has replaced any old
            // run-user-owned private folder. The HOME has its own path, but the
            // view must validate it only after all start-time resets are complete.
            // R16: the run user copies known sign-in files, then projects the user's
            // Config Hub choices into this fresh home. A failure stops the start.
            $runUserHome = RunUserService::sessionHomePath((string)$id);
            $agentAlreadyRunning = $origin === self::ORIGIN_BRIDGE_WARM || ProcessManager::isRunning((string)$id);
            $reusedSigninHome = false;
            if (!$agentAlreadyRunning) {
                if (is_array($spawnMeta['runUserSignIn'] ?? null)) {
                    $runUserHome = RunUserService::existingSessionHome((string)$id, (int)$runUserStatus['uid'], (int)$runUserStatus['gid']);
                    $reusedSigninHome = $runUserHome !== '';
                }
                if (!$reusedSigninHome) $runUserHome = RunUserService::prepareSessionHome((string)$id, (int)$runUserStatus['uid'], (int)$runUserStatus['gid']);
            }
            if ($runUserHome === '' || !is_dir($runUserHome) || is_link($runUserHome)) {
                self::recordStartFailure($actId, $agentName, 'A fresh private home could not be made for this run.', self::START_RECOVERY, $startMeta);
                return ['ok' => false, 'reason' => 'run_user_home_failed'];
            }
            $authHome = UtilityService::getWorkDir(RunUserService::USER) . '/home';
            $prepared = ($agentAlreadyRunning || $reusedSigninHome)
                ? ['ok' => true, 'reason' => $agentAlreadyRunning ? 'agent_alive' : 'reused_signin_home']
                : RunUserService::prepareHomeAsRunUser((string)$agentId, $runUserHome, (string)$id, $authHome);
            if (empty($prepared['ok'])) {
                LogService::log("Run-user home preparation for $id failed ({$prepared['reason']}); the agent was not started.", LogService::LOG_ERROR, 'TerminalService');
                self::recordStartFailure($actId, $agentName, 'The sign-in files or Config Hub choices could not be prepared for this run.', self::START_RECOVERY, $startMeta);
                return ['ok' => false, 'reason' => 'run_user_home_prepare_failed'];
            }
        }
        $envStrParts = [];
        foreach ($env as $k => $v) {
            $envStrParts[] = "$k=" . escapeshellarg($v);
        }
        $envStr = implode(" ", $envStrParts);
        
        // R-B1 (CLAUDE_RELAUNCH_SURVIVAL): bring the agent up in a DETACHED tmux
        // session BEFORE ttyd, headlessly. Previously ttyd forked aicli-shell.sh
        // (→ tmux new-session → agent) only when a browser WebSocket connected, so
        // an auto-launch/relaunch with no browser left ttyd child-less; the orphan
        // sweep (Bug #1067) then reaped it ~90s later — claude was dead/blank before
        // the user opened the tab. Running aicli-shell.sh --ensure-session here
        // creates the tmux session + agent synchronously, independent of any
        // browser, so it survives the deploy and the sweep. ttyd's later attach
        // (interactive mode, on browser-connect) just connects to the live session.
        // Idempotent: if the session already exists this is a fast no-op.
        $mountView = [];
        $viewNs = '';
        $bindFailure = '';
        $paths = null;   // set only for a run-user view; the failure text below reads it
        if ($runUserView) {
            // A bridge-only start keeps the view that the running agent has. Any other start builds a new view first.
            $viewNs = $origin === self::ORIGIN_BRIDGE_WARM ? RunUserService::existingView((string)$id)
                : RunUserService::prepareView((string)$id, $isRunUserSignIn);
            $paths = $viewNs !== '' ? ($origin === self::ORIGIN_BRIDGE_WARM ? ['rw' => [], 'ro' => [], 'protect' => [], 'readFolders' => []] : self::runUserBindPaths((string)$id, $username, (string)$agentId, $runMeta, $runUserStatus, (string)$env['AICLI_CWD'], $bindFailure)) : null;
            $sessionHome = RunUserService::sessionHomePath((string)$id);
            $mountView = ['folder' => (string)$env['AICLI_CWD'], 'writable' => !empty($runMeta['mountWritable']), 'nsFile' => $viewNs,
                'workspaceId' => (string)$id, 'pidNsFile' => RunUserService::pidNsFile((string)$id),
                'networkNamespace' => RunUserService::networkNamespaceName((string)$id), 'uid' => (int)$runUserStatus['uid'],
                'gid' => (int)$runUserStatus['gid'], 'home' => $sessionHome,
                'stateRoot' => SecretPaths::stateRoot(), 'rw' => $paths['rw'] ?? [], 'ro' => $paths['ro'] ?? [], 'protect' => $paths['protect'] ?? [],
                'readFolders' => $paths['readFolders'] ?? [], 'ids' => $paths['ids'] ?? [],
                'privateDir' => $paths['privateDir'] ?? '', 'privateDirId' => $paths['privateDirId'] ?? '', 'taskDir' => $paths['taskDir'] ?? '',
                'tmuxDir' => $paths['tmuxDir'] ?? '', 'tmuxDirId' => $paths['tmuxDirId'] ?? ''];
            if ($viewNs === '' || $paths === null) $mountView['folder'] = '';   // fail closed below
        }
        $ensureCmd = self::ensureSessionCommand($activeSrc, $username, $envStr, $shell, $mountView);
        if ($ensureCmd === '' && ($origin !== self::ORIGIN_BRIDGE_WARM || $viewNs === '')) {
            // Fail closed: no private disk view, no agent. (A bridge reconnect runs no ensure command; it needs only the existing view.)
            $viewFailure = $viewNs === '' ? 'namespace setup did not produce a view'
                : ($paths === null ? ($bindFailure !== '' ? $bindFailure : 'the bind paths are invalid') : 'the view command inputs are invalid');
            LogService::log("Start of $id refused: the folder for the private disk view is not valid ($viewFailure).", LogService::LOG_ERROR, 'TerminalService');
            self::recordStartFailure($actId, $agentName, 'The private read-only disk view could not be prepared for this run.', self::START_RECOVERY, $startMeta);
            return ['ok' => false, 'reason' => 'run_user_view_invalid'];
        }
        $ensureLog = SecretPaths::stateRoot() . "/ensure-$id.log";
        ActivityService::update($actId, ['step' => $startStep('Launching agent'), 'progress' => 60]);
        $ensureRc = 0;
        self::launchStep('ensure-session (agent in tmux)');
        if ($origin === self::ORIGIN_BRIDGE_WARM) {
            // TERMINAL_BACKGROUND_WARM.md: a bridge warm-up attaches to a tmux
            // session the caller found live under the workspace lock. It never
            // creates a session, so it can never launch an agent.
            LogService::log("Bridge warm-up for $id: tmux session is live — launching the terminal bridge only.", LogService::LOG_INFO, "TerminalService");
        } else {
        LogService::log("Ensuring detached agent session (headless): $ensureCmd", LogService::LOG_DEBUG, "TerminalService");
        if (defined('AICLI_TEST_TMP') && is_callable(self::$ensureRunnerForTests)) {
            $ensureRc = (int)(self::$ensureRunnerForTests)($ensureCmd, $ensureLog);
        } else {
            exec(self::wrapEnsureTimeout($ensureCmd) . " > " . escapeshellarg($ensureLog) . " 2>&1", $ensureOut, $ensureRc);
        }
        }
        if ($origin === self::ORIGIN_BRIDGE_WARM) {
            // No ensure-session ran; nothing to report.
        } elseif (self::isEnsureTimeout((int)$ensureRc)) {
            // Forgejo #521: a blocked ensure-session must end the start with a visible error.
            LogService::log("ensure-session timed out (rc=$ensureRc) for $id after " . self::ENSURE_TIMEOUT_SECONDS . "s. Start aborted.", LogService::LOG_ERROR, "TerminalService");
            self::recordStartFailure($actId, $agentName, self::startFailureSentence('ensure_timeout', $agentName), self::START_RECOVERY, $startMeta);
            return ['ok' => false, 'reason' => 'ensure_timeout'];
        } elseif ($ensureRc !== 0) {
            $tail = file_exists($ensureLog) ? trim((string)shell_exec("tail -n 5 " . escapeshellarg($ensureLog))) : '';
            LogService::log("ensure-session returned rc=$ensureRc for $id. Start aborted before ttyd. tail: $tail", LogService::LOG_ERROR, "TerminalService");
            if ($runUserView) {
                // R17: when the private disk view cannot be made, no agent starts (nothing runs outside the view).
                self::recordStartFailure($actId, $agentName, 'The private read-only disk view could not be prepared for this run. Check the Debug Console.', self::START_RECOVERY, $startMeta);
                return ['ok' => false, 'reason' => 'run_user_view_failed'];
            }
            self::recordStartFailure($actId, $agentName, self::startFailureSentence('ensure_failed', $agentName), self::START_RECOVERY, $startMeta);
            return ['ok' => false, 'reason' => 'ensure_failed'];
        } else {
            LogService::log("Detached agent session is live for $id ($agentId).", LogService::LOG_INFO, "TerminalService");
        }

        self::launchStep('after ensure-session, before the ttyd start');
        if ($runUserView) {
            $account = RunUserService::status();
            $home = RunUserService::sessionHomePath((string)$id);
            $setpriv = RunUserService::runtimeToolPath('setpriv');
            $env = RunUserService::runtimeToolPath('env');
            if ($setpriv === '' || $env === '') {
                self::recordStartFailure($actId, $agentName, 'A required isolation tool is missing, so this restricted workspace was not started.', self::START_RECOVERY, $startMeta);
                return ['ok' => false, 'reason' => 'run_user_tool_missing'];
            }
            $inner = escapeshellarg($setpriv) . ' --no-new-privs --reset-env -- ' . escapeshellarg($env) . ' -i PATH='
                . escapeshellarg(RunUserService::FIXED_PATH) . ' TERM=xterm-256color LANG=C.UTF-8 HOME=' . escapeshellarg($home)
                . ' AICLI_HOME=' . escapeshellarg($home) . ' USER=' . escapeshellarg(RunUserService::USER) . ' LOGNAME=' . escapeshellarg(RunUserService::USER)
                . ' AICLI_STATE_ROOT=' . escapeshellarg(SecretPaths::stateRoot()) . ' AICLI_RESTRICTED_RUN=1 '
                . $envStr . ' /bin/bash ' . escapeshellarg($shell);
        } else {
            // Root and other configured users retain the existing terminal path.
            $inner = "runuser -u " . escapeshellarg($username) . " -- env $envStr /bin/bash " . escapeshellarg($shell);
        }
        if ($runUserView) {
            // The browser shell joins the same cgroup and saved namespaces as the detached agent.
            $inner = self::attachInViewCommand($viewNs, $inner, (string)$id);
            if ($inner === '') {
                LogService::log("Start of $id refused: the view of the restricted run is missing, so the terminal cannot attach.", LogService::LOG_ERROR, 'TerminalService');
                self::recordStartFailure($actId, $agentName, 'The private view of this restricted run is missing. Restart the workspace from the plugin.', self::START_RECOVERY, $startMeta);
                return ['ok' => false, 'reason' => 'run_user_view_missing'];
            }
        }
        $cmd = "$ttyd -i " . escapeshellarg($sock) . " -p 0 -W -d0 " . $inner;

        LogService::log("Executing: $cmd", LogService::LOG_DEBUG, "TerminalService");

        ActivityService::update($actId, ['step' => $startStep('Starting terminal server'), 'progress' => 70]);
        // #337: fully detached — an auto-launch runs from `plugin install`, and
        // ttyd must not keep the installer's output pipe open. `exec` keeps
        // the reported pid equal to ttyd's own.
        $spawnArgv = ['/bin/bash', '-c', 'exec ' . $cmd];
        $spawned = defined('AICLI_TEST_TMP') && is_callable(self::$spawnRunnerForTests)
            ? (int)(self::$spawnRunnerForTests)($spawnArgv, $logFile, true)
            : UtilityService::spawnDetached($spawnArgv, $logFile, true);
        $out = $spawned > 0 ? [(string)$spawned] : [];
        
        $pid = trim($out[0] ?? '');
        self::launchStep('waiting for the ttyd socket');
        LogService::log("Launch result - PID: $pid", LogService::LOG_DEBUG, "TerminalService");

        if (ctype_digit($pid)) {
            file_put_contents($pidFile, $pid);
            
            // Wait a moment for socket creation
            $found = false;
            for ($i=0; $i<20; $i++) {
                if (file_exists($sock)) {
                    self::secureTtydSocket($sock);
                    // RESPONSIVE_PRIORITY.md R5: raise the new ttyd (and its tmux server) when "Keep the terminal responsive" is on.
                    ConfigService::runResponsivePriority('auto');
                    LogService::log("Successfully established terminal socket and launched session for $id.", LogService::LOG_INFO, "TerminalService");
                    // Done from PHP's perspective — the agent itself cold-starts
                    // inside tmux; AICLI_TTYD_READY covers that last leg in the UI.
                    // R8: a finished start shows no leftover step.
                    ActivityService::finish($actId, '');
                    if ($origin === self::ORIGIN_BRIDGE_WARM) {
                        // TERMINAL_BACKGROUND_WARM.md: the agent did not start —
                        // only its web bridge is new. Publish the bridge identity
                        // (the SPA remounts the frame on the new ttyd) and NOT
                        // `started`, which a page may read as a restart.
                        $gen = class_exists(TerminalGenerationService::class) ? TerminalGenerationService::current((string)$id) : null;
                        if (is_string($gen) && $gen !== '') {
                            EventBus::publish('workspace', [], ['event' => 'bridge', 'id' => (string)$id, 'generation' => $gen]);
                        }
                        $found = true;
                        break;
                    }
                    // Tell live surfaces (the drawer, the Relay owner table) that a
                    // workspace just started — whether from the UI, Relay "Start now",
                    // or a system autolaunch — so they refresh without a manual reload.
                    $startedPayload = ['event' => 'started', 'id' => (string)$id, 'agentId' => (string)$agentId, 'ts' => time()];
                    // HOME_BACKUP.md / HOME_RESTORE.md: a home-backup or
                    // home-restore relaunch already resumed this session
                    // server-side (and sent its own Continue nudge when the
                    // close recorded it as "working"). continuedBy tells the
                    // stopped->started handler this one was already handled, so
                    // CONTINUE_ON_RESTART's auto-continue does not nudge it twice.
                    if ($origin === 'backup_relaunch') {
                        $startedPayload['continuedBy'] = 'backup';
                    } elseif ($origin === 'restore_relaunch') {
                        $startedPayload['continuedBy'] = 'restore';
                    } elseif (class_exists(ContinueHold::class) && ContinueHold::isHeld((string)$id)) {
                        // #349: the operator restarted this workspace with "do not
                        // continue" (Switch to the installed version). The same
                        // field tells every open page not to auto-continue it.
                        $startedPayload['continuedBy'] = 'operator-switch';
                    }
                    EventBus::publish('workspace', [], $startedPayload);
                    $found = true;
                    break;
                }
                usleep(100000); 
            }

            if (!$found) {
                LogService::log("CRITICAL: Socket $sock not created within 2s. Terminal will fail to connect.", LogService::LOG_ERROR, "TerminalService");
                self::recordStartFailure($actId, $agentName, self::startFailureSentence('terminal_no_answer', $agentName), self::START_RECOVERY, $startMeta);
                if (file_exists($logFile)) {
                    $logTail = shell_exec("tail -n 10 " . escapeshellarg($logFile));
                    LogService::log("ttyd stderr tail: " . $logTail, LogService::LOG_ERROR, "TerminalService");
                }
                return ['ok' => false, 'reason' => 'terminal_no_answer'];
            }
        } else {
            LogService::log("Failed to launch term process for $id. (No PID returned)", LogService::LOG_ERROR, "TerminalService");
            self::recordStartFailure($actId, $agentName, self::startFailureSentence('terminal_no_launch', $agentName), self::START_RECOVERY, $startMeta);
            return ['ok' => false, 'reason' => 'terminal_no_launch'];
        }
        return ['ok' => true, 'reason' => 'started'];
    }

    /**
     * Resolve the conversation id agy will resume for a workspace path, from
     * agy's OWN authoritative index: <home>/.gemini/antigravity-cli/cache/
     * last_conversations.json maps {cwd -> conversation-id} (agy keys it by the
     * directory it was launched in — the workspace path).
     *
     * Why this exists: a bare `agy` launch does NOT auto-resume — it opens a
     * blank conversation (verified live: the cli.log shows "conversation ''" then
     * the user manually re-selecting the prior chat). So the plugin must pass the
     * id explicitly via `agy --conversation <id>`. Reading agy's own index beats
     * guessing from .pb mtimes: a global-newest-mtime scan picks a DIFFERENT
     * workspace's chat (and the stale resume-config entries on .4 prove that scan
     * misfires). We return the id only when its conversation file still exists, so
     * a stale index entry (conversation since deleted) can't yield a dead
     * --conversation that agy rejects -> blank session.
     */
    /**
     * OpenCode's newest resumable session FOR THIS WORKSPACE (#146).
     *
     * OpenCode keeps every session in one SQLite `session` table with a
     * `directory` column = the workspace cwd. The old discovery ran
     * `ORDER BY time_updated DESC LIMIT 1` with NO directory filter, so it
     * returned the globally-newest session — which belongs to whichever
     * workspace was used last, NOT the one being resumed (exactly the
     * cross-workspace hazard the claude-code branch guards against). Resuming
     * that id in a different cwd opened the wrong chat or fell back to an old one.
     *
     * The fix: filter by `directory = $path` and top-level sessions
     * (`parent_id IS NULL` — child/sub sessions are not resume targets), newest
     * first. We read BOTH the legacy shared db AND every per-session isolated db
     * (XDG_DATA_HOME/aicli-opencode/<session>/opencode/opencode.db) and take the
     * newest across all of them: the same session id can appear in several dbs
     * with different `time_updated` after the isolation migration, and the
     * freshest activity may live only in the isolated copy. Dedup by max time.
     */
    public static function opencodeResumeId(string $home, string $path): ?string {
        if ($home === '') return null;
        $dbs = [];
        $legacy = "$home/.local/share/opencode/opencode.db";
        if (is_file($legacy)) $dbs[] = $legacy;
        foreach (glob("$home/.local/share/aicli-opencode/*/opencode/opencode.db") ?: [] as $d) {
            if (is_file($d)) $dbs[] = $d;
        }
        return self::newestOpencodeStyleSession($dbs, $path);
    }

    /**
     * OpenCode 2's newest resumable session FOR THIS WORKSPACE (#822).
     *
     * The launch script gives OpenCode 2 its own data folder (XDG_DATA_HOME =
     * <home>/.local/share/opencode2-xdg/data), so its database is
     * <home>/.local/share/opencode2-xdg/data/opencode/opencode.db. In 2.2.0 the sessions are in
     * the table `session_v2` (id, parent_id, directory, time_updated: same columns that the
     * OpenCode 1 query uses). The table `session` that a migration may leave is not read: those are
     * the sessions of OpenCode 1. Not proven with a real session (needs a model): when the table or
     * a column differs the query fails and this returns null, then the launch uses --continue.
     */
    public static function opencode2ResumeId(string $home, string $path): ?string {
        if ($home === '') return null;
        $db = "$home/.local/share/opencode2-xdg/data/opencode/opencode.db";
        return is_file($db) ? self::newestOpencodeStyleSession([$db], $path, 'session_v2') : null;
    }

    /**
     * Kilo Code's newest resumable session FOR THIS WORKSPACE (#147).
     *
     * Kilo is an OpenCode fork: its `.local/share/kilo/kilo.db` has the SAME
     * `session` table (id `ses_…`, `directory` = cwd, `parent_id`, `time_updated`),
     * so it reuses the exact directory-filtered discovery. Kilo is NOT under the
     * per-workspace XDG isolation (that block is opencode-only), so there is a
     * single db. Before this branch existed, discoverLatestSessionId had no
     * kilocode case and returned null — so resume fell back to `--continue` with no
     * chat id and Kilo started fresh ("Auto-resume: chatId=none").
     */
    public static function kilocodeResumeId(string $home, string $path): ?string {
        if ($home === '') return null;
        $db = "$home/.local/share/kilo/kilo.db";
        return is_file($db) ? self::newestOpencodeStyleSession([$db], $path) : null;
    }

    /**
     * Shared core for OpenCode-shaped session stores: newest top-level `ses_…`
     * session whose `directory` matches $path, across all given dbs (dedup by max
     * time). Prepared read-only SQLite3 — no shell-out, no escaping.
     */
    private static function newestOpencodeStyleSession(array $dbs, string $path, string $table = 'session'): ?string
    {
        if ($dbs === [] || !class_exists('SQLite3')) return null;

        $dir = rtrim(str_replace('\\', '/', $path), '/');
        // The same folder has two names on Unraid: /mnt/user/<share>/... and the pool
        // path /mnt/cache/<share>/.... A workspace launched through one name stores its
        // sessions under that name, so a lookup by only the configured name misses the
        // newest sessions and returns an old one from a stale store (2026-10-02, #387:
        // OpenCode "Session not found"). Match every name of the folder.
        $dirs = self::equivalentWorkspacePaths($dir);
        // Prepared statements (bound params) — no SQL escaping, no shell-out, and it
        // works from the sqlite3 PHP extension the host + runner already ship.
        // LIMIT 5 (not 1): a stray corrupt/foreign row at the top must not hide the
        // newest VALID session — we take the first well-formed id in DESC order.
        $in = [];
        foreach ($dirs as $i => $_) { $in[] = ":d$i"; $in[] = ":e$i"; }
        $sql = $dir !== ''
            ? "SELECT id, time_updated FROM $table WHERE directory IN (" . implode(',', $in) . ") AND (parent_id IS NULL OR parent_id = '') ORDER BY time_updated DESC LIMIT 5"
            // No workspace context (older call-site): last-resort global newest,
            // matching the historical behaviour rather than returning nothing.
            : "SELECT id, time_updated FROM $table WHERE (parent_id IS NULL OR parent_id = '') ORDER BY time_updated DESC LIMIT 5";

        $bestId = null; $bestTime = -1;
        foreach ($dbs as $dbPath) {
            try {
                $db = new \SQLite3($dbPath, SQLITE3_OPEN_READONLY);
            } catch (\Throwable $e) { continue; }
            $db->busyTimeout(500);
            $stmt = @$db->prepare($sql);
            if ($stmt === false) { $db->close(); continue; }
            if ($dir !== '') {
                foreach ($dirs as $i => $d) {
                    $stmt->bindValue(":d$i", $d, SQLITE3_TEXT);
                    $stmt->bindValue(":e$i", $d . '/', SQLITE3_TEXT);
                }
            }
            $res = @$stmt->execute();
            if ($res) {
                // Rows are DESC by time; the first well-formed id is this db's newest.
                while (is_array($row = $res->fetchArray(SQLITE3_ASSOC))) {
                    $id = (string)($row['id'] ?? '');
                    if (!preg_match('/^ses_[A-Za-z0-9_-]+$/D', $id)) continue;
                    $t = (int)($row['time_updated'] ?? 0);
                    if ($t > $bestTime) { $bestTime = $t; $bestId = $id; }
                    break;
                }
            }
            $db->close();
        }
        return $bestId;
    }

    /**
     * Every name one workspace folder goes by: the path itself plus its /mnt/user and
     * /mnt/cache twin. Pure. Other pools are not guessed: only the two forms the plugin
     * itself produces (the configured share path and its pool path).
     *
     * @return list<string>
     */
    public static function equivalentWorkspacePaths(string $dir): array
    {
        $dir = rtrim($dir, '/');
        $out = [$dir];
        if (strpos($dir, '/mnt/user/') === 0) $out[] = '/mnt/cache/' . substr($dir, strlen('/mnt/user/'));
        elseif (strpos($dir, '/mnt/cache/') === 0) $out[] = '/mnt/user/' . substr($dir, strlen('/mnt/cache/'));
        return array_values(array_unique($out));
    }

    public static function antigravityResumeId(string $home, string $path): ?string {
        if ($home === '' || $path === '') return null;
        $cacheFile = "$home/.gemini/antigravity-cli/cache/last_conversations.json";
        if (!is_file($cacheFile)) return null;
        $data = @json_decode((string) @file_get_contents($cacheFile), true);
        if (!is_array($data)) return null;
        $id = $data[$path] ?? null;
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9-]{20,}$/D', $id)) return null;
        if (!is_file("$home/.gemini/antigravity-cli/conversations/$id.pb")) return null;
        return $id;
    }

    /** Resolve Kimi Code's newest session for one exact workspace. */
    public static function kimiCodeResumeId(string $home, string $path): ?string {
        if ($home === '' || $path === '') return null;
        $indexFile = "$home/.kimi-code/session_index.jsonl";
        if (!is_file($indexFile)) return null;
        $wanted = rtrim(str_replace('\\', '/', $path), '/');
        $fh = @fopen($indexFile, 'rb');
        if ($fh === false) return null;
        $newestId = null;
        $newestAt = -1;
        while (($line = fgets($fh)) !== false) {
            $row = json_decode($line, true);
            if (!is_array($row)) continue;
            $workDir = rtrim(str_replace('\\', '/', (string)($row['workDir'] ?? $row['work_dir'] ?? '')), '/');
            $id = (string)($row['sessionId'] ?? $row['session_id'] ?? $row['id'] ?? '');
            if ($workDir !== $wanted || !preg_match('/^[A-Za-z0-9_-]{8,}$/D', $id)) continue;
            $updatedAt = (int)($row['updatedAt'] ?? $row['updated_at'] ?? 0);
            if ($updatedAt >= $newestAt) {
                $newestAt = $updatedAt;
                $newestId = $id;
            }
        }
        fclose($fh);
        return $newestId;
    }

    /** Resolve Grok Build's newest URL-encoded per-workspace session directory. */
    public static function grokBuildResumeId(string $home, string $path): ?string {
        if ($home === '' || $path === '') return null;
        $root = "$home/.grok/sessions";
        if (!is_dir($root)) return null;
        $wanted = rtrim(str_replace('\\', '/', $path), '/');
        $newestId = null;
        $newestAt = -1;
        foreach (glob("$root/*", GLOB_ONLYDIR) ?: [] as $workspaceDir) {
            $decoded = rtrim(str_replace('\\', '/', rawurldecode(basename($workspaceDir))), '/');
            if ($decoded !== $wanted) continue;
            foreach (glob("$workspaceDir/*", GLOB_ONLYDIR) ?: [] as $sessionDir) {
                $id = basename($sessionDir);
                if (!preg_match('/^[A-Za-z0-9_-]{8,}$/D', $id)) continue;
                $mtime = @filemtime($sessionDir) ?: 0;
                foreach (['summary.json', 'updates.jsonl', 'chat_history.jsonl'] as $marker) {
                    $mtime = max($mtime, @filemtime("$sessionDir/$marker") ?: 0);
                }
                if ($mtime >= $newestAt) {
                    $newestAt = $mtime;
                    $newestId = $id;
                }
            }
        }
        return $newestId;
    }

    /**
     * Finds a recent chat session for a project path.
     */
    public static function findSession($path, $agentId = 'gemini-cli') {
        if (empty($path) || !is_string($path) || ValidationService::containsNulByte($path)) return null;

        // D-334: Gemini-specific chat session discovery
        if ($agentId === 'gemini-cli') {
            $config = ConfigService::getConfig();
            $user = $config['user'] ?? 'root';
            if (empty($user)) $user = 'root';

            $home = SecretPaths::stateRoot() . "/work/$user/home";
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
        }

        // agy: read agy's own per-workspace conversation index. Without this,
        // get_chat_session returns null for antigravity on every page load, the
        // UI loses the chat id (chatSessionId=''), and a re-launch of the session
        // runs bare `agy` -> blank conversation ("not resuming the last chat").
        if ($agentId === 'antigravity-cli') {
            $config = ConfigService::getConfig();
            $user = $config['user'] ?? 'root';
            if (empty($user)) $user = 'root';
            $home = SecretPaths::stateRoot() . "/work/$user/home";
            return self::antigravityResumeId($home, $path);
        }

        if ($agentId === 'kimi-code') {
            $config = ConfigService::getConfig();
            $user = $config['user'] ?? 'root';
            if (empty($user)) $user = 'root';
            $home = SecretPaths::stateRoot() . "/work/$user/home";
            return self::kimiCodeResumeId($home, $path);
        }

        if ($agentId === 'grok-build') {
            $config = ConfigService::getConfig();
            $user = $config['user'] ?? 'root';
            if (empty($user)) $user = 'root';
            $home = SecretPaths::stateRoot() . "/work/$user/home";
            return self::grokBuildResumeId($home, $path);
        }

        // Other agents either handle persistence internally or have no safely
        // documented workspace-to-session index.
        return null;
    }

    /**
     * Which live sessions are running an agent version that is no longer the
     * one installed — docs/specs/WORKSPACE_APPLY_AGENT_VERSION.md.
     *
     * Deliberately NOT phrased as "out of date". Since side-by-side installs,
     * the installed version is simply whichever was installed LAST: switching a
     * release channel back to Stable installs an older version, and that older
     * version is then the current one. A session can therefore differ from the
     * installed version in either direction, and the only honest statement is
     * that it differs — which is why this returns a set rather than a
     * comparison, and why every label built from it names the target version
     * instead of calling it newer or older.
     *
     * A session's version is read from the generation path the launcher pinned
     * into its environment, the same signal the generation reaper trusts. A
     * session whose generation cannot be read is NOT reported: an unreadable
     * process must never produce an offer to restart a workspace.
     *
     * @param string|null   $procRoot       injected by tests.
     * @param callable|null $activeResolver agentId => active generation id|null.
     *        Injected by tests so this is assertable on ANY host, including a CI
     *        container with no versioned agents installed. Without the seam the
     *        cases that matter — a downgrade, a mismatch — could only skip there,
     *        and a test that skips in CI guards nothing.
     * #350 (2026-09-29): a session that started on the OLD plain-folder layout
     * names the stable path agents/<id>/... and no generation. When a busy
     * old-layout bind is moved beside the new version
     * (SIDE_BY_SIDE_AGENT_INSTALLS.md "a busy old layout"), storagectl writes
     * the version it shows to /tmp/unraid-aicliagents/passthrough-legacy-<id>.
     * Such a session runs that version, so it is offered the switch too.
     *
     * @param callable|null $legacyResolver agentId => the moved old-layout
     *        generation|null. Injected by tests.
     * @return array<string,string> session id => the generation it is running
     */
    public static function sessionsOnOtherAgentVersion(?string $procRoot = null, ?callable $activeResolver = null, ?callable $legacyResolver = null): array
    {
        $legacyResolver = $legacyResolver ?? static function (string $agent): ?string {
            $g = trim((string)@file_get_contents(SecretPaths::stateRoot() . '/passthrough-legacy-' . $agent));
            return preg_match('/^[A-Za-z0-9][A-Za-z0-9._@-]*$/D', $g) ? $g : null;
        };
        $legacy = [];   // agentId => moved old-layout generation|null
        $root = $procRoot ?? '/proc';
        $active = [];   // agentId => active generation
        $out = [];
        foreach (glob($root . '/[0-9]*/environ') ?: [] as $f) {
            $raw = @file_get_contents($f);
            if ($raw === false || $raw === '') continue;
            $env = [];
            foreach (explode("\0", $raw) as $pair) {
                $eq = strpos($pair, '=');
                if ($eq === false) continue;
                $env[substr($pair, 0, $eq)] = substr($pair, $eq + 1);
            }
            $sid = (string)($env['AICLI_SESSION_ID'] ?? '');
            $agent = (string)($env['AGENT_ID'] ?? '');
            // frozen_binary*, NOT BINARY*. BINARY / BINARY_PRIMARY / BINARY_FALLBACK
            // are the INPUTS the launcher receives from PHP, built on the stable
            // symlink, and they stay that way by design. The launcher pins the
            // resolved generation into the frozen_* copies
            // (aicli-shell.sh `_aicli_pin_to_real_generation`), and those are what
            // the running agent actually executes and re-resolves against.
            //
            // Reading BINARY here finds a stable path for every session, concludes
            // nothing is on a different version, and the feature silently never
            // appears — which is exactly what it did until this was caught against
            // live sessions on 2026-09-15.
            $binary = (string)($env['frozen_binary_primary'] ?? $env['frozen_binary'] ?? '');
            if ($sid === '' || $agent === '' || $binary === '') continue;
            if (isset($out[$sid])) continue;

            // The generation this session is pinned to, from its own binary path.
            if (preg_match('#/\.versions/' . preg_quote($agent, '#') . '/([A-Za-z0-9._@-]+)/#', $binary, $m)) {
                $sessionGen = $m[1];
            } else {
                // Not on the versioned layout. Only an old-layout session whose
                // bind was moved beside a new version (#350) has a version to
                // name; any other shape has nothing to offer.
                if (!array_key_exists($agent, $legacy)) {
                    $resolved = $legacyResolver($agent);
                    $legacy[$agent] = is_string($resolved) && $resolved !== '' ? $resolved : null;
                }
                $stablePrefix = rtrim(AgentRegistry::agentBase(), '/') . '/' . $agent . '/';
                if ($legacy[$agent] === null || strncmp($binary, $stablePrefix, strlen($stablePrefix)) !== 0) {
                    continue;
                }
                $sessionGen = $legacy[$agent];
            }

            if (!array_key_exists($agent, $active)) {
                $active[$agent] = null;
                if ($activeResolver !== null) {
                    $resolved = $activeResolver($agent);
                    $active[$agent] = is_string($resolved) && $resolved !== '' ? $resolved : null;
                } else {
                    try {
                        $stable = AgentRegistry::agentPath($agent);
                        $target = @readlink($stable);
                        $prefix = ".versions/$agent/";
                        if (is_string($target) && strncmp($target, $prefix, strlen($prefix)) === 0) {
                            $active[$agent] = substr($target, strlen($prefix));
                        }
                    } catch (\Throwable $e) { /* unknown agent id — leave null */ }
                }
            }
            $activeGen = $active[$agent];
            if ($activeGen === null || $activeGen === $sessionGen) continue;
            $out[$sid] = $sessionGen;
        }
        return $out;
    }

    /**
     * Forgejo #315 (2026-09-24): the running sessions of every agent, read from
     * tmux (ProcessManager::listAgentTmuxSessions) — NOT from the ttyd socket.
     *
     * A workspace runs in tmux whether or not a browser terminal is attached.
     * The terminal page mounts a terminal only for the workspaces opened in that
     * browser (#308), and ttyd can be gone after a restart while the agent keeps
     * running. Scanning /var/run/aicliterm-*.sock therefore counted 0 sessions
     * for an agent that was in use, and the install warning, the side-by-side
     * decision and the upgrade queue then treated a busy agent as idle.
     *
     * 'attached' carries the separate fact "a browser terminal (ttyd socket)
     * exists" for a caller that needs it. A ttyd socket with no tmux session is
     * not a running session.
     *
     * @return array<int,array{id:string,agentId:string,path:string,chatId:string,user:string,started_at:int,attached:bool}>
     */
    private static function runningSessionRecords(): array
    {
        $out = [];
        foreach (ProcessManager::listAgentTmuxSessions() as $t) {
            $id = (string)$t['id'];
            $workdirFile = UtilityService::getWorkDirFilePath($id);
            $chatFile    = UtilityService::getChatIdPath($id);
            $userFile    = UtilityService::getUserIdPath($id);
            $path = is_file($workdirFile) ? trim((string)@file_get_contents($workdirFile)) : '';
            // The .workdir metadata holds the path as the user chose it (the
            // /mnt/user form). tmux holds the resolved start dir — use it only
            // when the metadata file is gone.
            if ($path === '') $path = (string)($t['path'] ?? '');
            $out[] = [
                'id'         => $id,
                'agentId'    => (string)$t['agentId'],
                'path'       => $path,
                'chatId'     => is_file($chatFile) ? trim((string)@file_get_contents($chatFile)) : '',
                'user'       => is_file($userFile) ? trim((string)@file_get_contents($userFile)) : '',
                'started_at' => (int)($t['created'] ?? 0),
                'attached'   => file_exists(UtilityService::getSockPath($id)),
            ];
        }
        return $out;
    }

    /**
     * Running workspace sessions whose agent matches $agentId, so the UI can
     * warn the user and the install flow can decide whether to wait, install
     * side by side, or close them before it replaces the binary.
     *
     * "Running" means a tmux session aicli-agent-<agentId>-<id> exists on the
     * workspace's tmux server (Forgejo #315). Metadata comes from the parallel
     * /var/run files keyed by session id, when present:
     *   unraid-aicliagents-<id>.workdir      — workspace path
     *   unraid-aicliagents-<id>.chatid       — last-known resume id
     * Each record also carries 'attached' — whether the ttyd socket
     * aicliterm-<id>.sock (a browser terminal) exists.
     *
     * @param bool $sweep false for a read-only caller (no reaping of orphan sessions)
     * @return array<int,array{id:string,agentId:string,path:string,chatId:string,started_at:int,attached:bool}>
     */
    public static function listActiveSessionsForAgent(string $agentId, bool $sweep = true): array
    {
        // Bug #1067: reconcile orphan ttyd processes first. This only tidies
        // stale browser-terminal sockets now; the count itself comes from tmux
        // (Forgejo #315), so a missing ttyd can no longer hide a running agent.
        // #617: a read endpoint passes $sweep = false, so a page read never reaps a session.
        if ($sweep) ProcessManager::sweepOrphanSessions();

        $out = [];
        foreach (self::runningSessionRecords() as $rec) {
            if ($rec['agentId'] !== $agentId) continue;
            unset($rec['user']);
            $out[] = $rec;
        }
        return $out;
    }

    /**
     * Pure filter — TDD seam for listActiveSessionsForHome / forceCloseHome.
     *
     * Returns only the sessions that belong to $user:
     *   - Session has a non-empty 'user' key equal to $user (case-sensitive), OR
     *   - Session has no 'user' key / empty 'user' AND $configUser === $user
     *     (legacy sessions started before per-session user tracking was added).
     *
     * @param array<int, array<string, mixed>> $sessions  Output of listActiveSessionsForHome's inner loop.
     * @param string $user       The home-user to filter for.
     * @param string|null $configUser  The current configured user from getAICliConfig()['user'].
     * @return array<int, array<string, mixed>>
     */
    public static function filterSessionsByHome(array $sessions, string $user, ?string $configUser = null): array
    {
        $out = [];
        foreach ($sessions as $session) {
            $sessionUser = isset($session['user']) ? (string)$session['user'] : '';
            if ($sessionUser !== '') {
                // Has explicit user — exact match only.
                if ($sessionUser === $user) {
                    $out[] = $session;
                }
            } else {
                // Legacy session (no user written) — belongs to the configured user.
                if ($configUser !== null && $configUser === $user) {
                    $out[] = $session;
                }
            }
        }
        return $out;
    }

    /**
     * Enumerate active terminal sessions belonging to $user's home overlay.
     * Mirrors listActiveSessionsForAgent exactly, adding a 'user' field read
     * from the .user metadata file, then delegates filtering to
     * filterSessionsByHome (which handles legacy sessions transparently).
     *
     * @param bool $sweep false for a read-only caller (no reaping of orphan sessions, #617)
     * @return array<int, array<string, mixed>>  Each entry has at least 'id' and 'user'.
     */
    public static function listActiveSessionsForHome(string $user, bool $sweep = true): array
    {
        // #617: a read endpoint passes $sweep = false, so a page read never reaps a session.
        if ($sweep) ProcessManager::sweepOrphanSessions();

        $config      = ConfigService::getConfig();
        $configUser  = (string)($config['user'] ?? '');
        if ($configUser === '') $configUser = 'root';

        // Forgejo #315: tmux is the source of truth for a running session, the
        // same as listActiveSessionsForAgent — a workspace with no browser
        // terminal attached still holds this home.
        $all = self::runningSessionRecords();

        return self::filterSessionsByHome($all, $user, $configUser);
    }

    /**
     * Headlessly force-close every active terminal session belonging to $user's
     * home overlay.
     *
     * R3.3 extension: in addition to the /var/run socket registry, enumerates
     * ALL live aicli-agent-* tmux sessions across every per-uid tmux socket and
     * closes any whose AICLI_HOME env matches this user's home mount — catching
     * reconnect sessions with `unknown` metadata that have no .sock registry entry.
     *
     * @param string $user   The home user to close sessions for.
     * @param array  $seams  Injectable seams for unit testing. Keys:
     *                         'list_home_sessions' callable(string $user): array
     *                         'tmux_list_sessions' callable(): array  Each entry:
     *                             ['name'=>string, 'sock'=>string, 'tmuxBin'=>string,
     *                              'aicliHome'=>string]
     *                         'close_session' callable(string $sessionId): void
     *                         'skip_ttyd_teardown' bool — if true, skip teardownHomeTtyds
     * @return int  Number of sessions closed (gracefulClose invoked or seam called).
     */
    public static function forceCloseHome(string $user, array $seams = []): int
    {
        // -----------------------------------------------------------------------
        // Part A: registered sessions (socket-registry path — existing behaviour)
        // -----------------------------------------------------------------------
        $listHomeSeam = $seams['list_home_sessions'] ?? null;
        $sessions = is_callable($listHomeSeam)
            ? $listHomeSeam($user)
            : self::listActiveSessionsForHome($user);

        $closeSeam = $seams['close_session'] ?? null;

        $closed   = 0;
        $closedIds = [];   // track which session ids we already closed (dedup with Part B)

        foreach ($sessions as $session) {
            $sessionId = (string)($session['id'] ?? '');
            if ($sessionId === '') continue;
            try {
                if (is_callable($closeSeam)) {
                    $closeSeam($sessionId);
                } else {
                    \AICliAgents\Handlers\TerminalHandler::closeForMaintenance($sessionId);
                }
                $closed++;
                // Only dedup against Part B on a SUCCESSFUL close. If the graceful
                // close throws (e.g. a missing autoload — the bug that left a
                // detached agent holding the overlay via mmap), the session must
                // NOT be marked closed, so Part B's kill-session backstop reaps it.
                $closedIds[$sessionId] = true;
                LogService::log(
                    "forceCloseHome: closed session $sessionId for user=$user (registry path)",
                    LogService::LOG_INFO, "TerminalService"
                );
            } catch (\Throwable $e) {
                LogService::log(
                    "forceCloseHome: failed to close session $sessionId for user=$user — " . $e->getMessage(),
                    LogService::LOG_WARN, "TerminalService"
                );
            }
        }

        // -----------------------------------------------------------------------
        // Part B: unregistered tmux sessions (R3.3 — reconnect sessions with
        // unknown metadata that have no /var/run/aicliterm-*.sock entry).
        //
        // Enumerate every aicli-agent-* session across all per-uid tmux sockets,
        // check its AICLI_HOME env, close those matching this user's home mount.
        // -----------------------------------------------------------------------
        require_once __DIR__ . '/StoragePathResolver.php';
        $homeMount = \AICliAgents\Services\StoragePathResolver::homeMount($user);

        $tmuxListSeam = $seams['tmux_list_sessions'] ?? null;
        if (is_callable($tmuxListSeam)) {
            $tmuxSessions = $tmuxListSeam();
        } else {
            $tmuxSessions = self::_enumerateTmuxSessions();
        }

        foreach ($tmuxSessions as $ts) {
            $sessName  = (string)($ts['name']      ?? '');
            $tmuxSock  = (string)($ts['sock']      ?? '');
            $aicliHome = (string)($ts['aicliHome'] ?? '');
            $tmuxBin   = (string)($ts['tmuxBin']   ?? '');

            if ($sessName === '' || $tmuxSock === '') continue;
            if ($aicliHome !== $homeMount) continue;

            // Extract the sessionId from aicli-agent-<agentId>-<sessionId>.
            // The sessionId is the LAST dash-delimited token.
            $parts = explode('-', $sessName);
            $sessionId = array_pop($parts);   // last token = session id
            if ($sessionId === '' || isset($closedIds[$sessionId])) continue;

            // Not in the registry — no ttyd/socket, so a graceful exit-screen
            // scrape is impossible. Still try a DISK-FALLBACK resume capture
            // first (the agent's on-disk conversation store yields the latest id
            // with no ttyd needed), so even a backstop-reaped session preserves
            // precise resume on relaunch. agentId from the session name, workspace
            // from the per-session .workdir metadata. Then tmux kill-session is the
            // best available teardown (it reaps the pane's whole process tree).
            // Note: if a .sock file appears for this session it was already handled
            // in Part A; $closedIds dedup prevents double-close.
            $closedIds[$sessionId] = true;
            if (!is_callable($closeSeam)) {
                try {
                    require_once __DIR__ . '/../handlers/TerminalHandler.php';
                    require_once __DIR__ . '/UtilityService.php';
                    require_once __DIR__ . '/ConfigService.php';
                    $bAgent = \AICliAgents\Handlers\TerminalHandler::agentIdFromSessionName($sessName, $sessionId);
                    $wdf    = \AICliAgents\Services\UtilityService::getWorkDirFilePath($sessionId);
                    $bPath  = is_file($wdf) ? trim((string)@file_get_contents($wdf)) : '';
                    if ($bAgent !== '' && $bPath !== '') {
                        $bId = \AICliAgents\Handlers\TerminalHandler::discoverLatestSessionId($bAgent, $bPath);
                        // Disk-only (newest on disk): guarded on a shared folder (RESUME_IDENTITY_PER_WORKSPACE.md V3).
                        if ($bId !== null && $bId !== '' && \AICliAgents\Services\ConfigService::saveDiskFallbackResumeId($bPath, $bAgent, (string)$bId, (string)$sessionId)) {
                            LogService::log(
                                "forceCloseHome: disk-fallback saved resume_id=$bId for unregistered $sessName (workspace=$bPath, agent=$bAgent)",
                                LogService::LOG_INFO, "TerminalService"
                            );
                        }
                    }
                } catch (\Throwable $e) { /* best-effort — never block the teardown */ }
            }
            try {
                if (is_callable($closeSeam)) {
                    $closeSeam($sessionId);
                } else {
                    ProcessManager::runTmuxForSocket($tmuxSock, ['kill-session', '-t', $sessName], 8);
                }
                $closed++;
                LogService::log(
                    "forceCloseHome: closed unregistered tmux session $sessName (id=$sessionId) for user=$user (R3.3)",
                    LogService::LOG_INFO, "TerminalService"
                );
            } catch (\Throwable $e) {
                LogService::log(
                    "forceCloseHome: failed to close unregistered session $sessName for user=$user — " . $e->getMessage(),
                    LogService::LOG_WARN, "TerminalService"
                );
            }
        }

        // R3.1: tear down the home's ttyds AFTER closing sessions so home_mount_in_use()
        // no longer sees a live ttyd — the mount frees deterministically.
        if (!($seams['skip_ttyd_teardown'] ?? false)) {
            self::teardownHomeTtyds($user, $seams);
        }

        LogService::log(
            "forceCloseHome: closed $closed sessions for user=$user",
            LogService::LOG_INFO, "TerminalService"
        );

        return $closed;
    }

    /**
     * Enumerate all aicli-agent-* tmux sessions across every per-uid tmux socket,
     * reading each session's AICLI_HOME env via `tmux showenv`. Returns an array
     * of ['name'=>string, 'sock'=>string, 'tmuxBin'=>string, 'aicliHome'=>string].
     *
     * Seam-injectable via $seams['tmux_list_sessions'] in forceCloseHome.
     */
    private static function _enumerateTmuxSessions(): array
    {
        $results = [];
        foreach (\AICliAgents\Services\ProcessManager::tmuxSocketPaths() as $sock) {
            $tmuxBin = \AICliAgents\Services\ProcessManager::tmuxPrefixForSocket($sock);
            $raw = \AICliAgents\Services\ProcessManager::runTmuxForSocket($sock, ['list-sessions', '-F', '#S'], 8);
            if (!is_string($raw)) continue;
            $raw = trim($raw);
            if ($raw === '') continue;
            foreach (explode("\n", $raw) as $line) {
                $sessName = trim($line);
                if ($sessName === '' || strncmp($sessName, 'aicli-agent-', 12) !== 0) continue;
                // Read AICLI_HOME from the session's environment.
                $envLine = trim((string)(\AICliAgents\Services\ProcessManager::runTmuxForSocket($sock,
                    ['showenv', '-t', '=' . $sessName, 'AICLI_HOME'], 8) ?? ''));
                // showenv output: "AICLI_HOME=/path/to/mount"  (or "-AICLI_HOME" if unset)
                $aicliHome = '';
                if (preg_match('/^AICLI_HOME=(.+)$/', $envLine, $m)) {
                    $aicliHome = $m[1];
                }
                $results[] = [
                    'name'      => $sessName,
                    'sock'      => $sock,
                    'tmuxBin'   => $tmuxBin,
                    'aicliHome' => $aicliHome,
                ];
            }
        }
        return $results;
    }

    /**
     * Tear down all ttyd processes whose argv contains AICLI_HOME=<homeMount>
     * for this user. Called AFTER forceCloseHome so the home mount is no longer
     * pinned by live ttyds — allowing `home_mount_in_use()` to return idle and
     * the consolidate bake to proceed deterministically (R3.1).
     *
     * Targets ONLY ttyds whose argv contains the EXACT home mount string for
     * this user — never other users' homes. Uses SIGTERM → 3 s wait → SIGKILL
     * escalation.
     *
     * @param string $user   The home user.
     * @param array  $seams  Injectable seams for testing:
     *                         'proc_scan'    callable(): array<array{pid:string,cmdline:string}>
     *                         'kill_proc'    callable(string $pid, int $signal): void
     *                         'skip_sigkill' bool — if true, omit SIGKILL escalation (test use only)
     * @return int  Count of ttyds targeted for teardown.
     */
    public static function teardownHomeTtyds(string $user, array $seams = []): int
    {
        require_once __DIR__ . '/StoragePathResolver.php';
        $homeMount = \AICliAgents\Services\StoragePathResolver::homeMount($user);
        $target    = "AICLI_HOME=$homeMount";   // exact argv token to match

        $procScan = $seams['proc_scan'] ?? null;
        $killProc = $seams['kill_proc'] ?? null;
        $skipSigkill = (bool)($seams['skip_sigkill'] ?? false);

        // Enumerate candidate pids.
        if (is_callable($procScan)) {
            $procs = $procScan();
        } else {
            $procs = self::_scanTtydProcs();
        }

        $killed = 0;
        foreach ($procs as $proc) {
            $pid     = (string)($proc['pid']     ?? '');
            $cmdline = (string)($proc['cmdline'] ?? '');
            if ($pid === '') continue;
            // The cmdline is NUL-delimited; check for the exact AICLI_HOME=<mount> token.
            // After tr '\0' '\n', each arg is its own "line" — we do a straight contains check.
            $args = str_replace("\x00", "\n", $cmdline);
            if (strpos($args, $target) === false) continue;

            // Matched — SIGTERM (15) first.
            $sigterm = defined('SIGTERM') ? SIGTERM : 15;
            $sigkill = defined('SIGKILL') ? SIGKILL : 9;
            if (is_callable($killProc)) {
                $killProc($pid, $sigterm);
            } else {
                @posix_kill((int)$pid, $sigterm);
            }
            $killed++;

            if (!$skipSigkill) {
                // Wait up to 3 s for the process to exit; escalate to SIGKILL if needed.
                $deadline = time() + 3;
                while (time() < $deadline) {
                    if (!file_exists("/proc/$pid")) break;
                    usleep(200000);   // 200 ms poll
                }
                if (file_exists("/proc/$pid")) {
                    if (is_callable($killProc)) {
                        $killProc($pid, $sigkill);
                    } else {
                        @posix_kill((int)$pid, $sigkill);
                    }
                    LogService::log(
                        "teardownHomeTtyds: SIGKILL sent to ttyd pid=$pid for user=$user (SIGTERM timeout)",
                        LogService::LOG_WARN, "TerminalService"
                    );
                }
            }

            LogService::log(
                "teardownHomeTtyds: terminated ttyd pid=$pid for user=$user home=$homeMount (R3.1)",
                LogService::LOG_INFO, "TerminalService"
            );
        }

        return $killed;
    }

    /**
     * Scan /proc for live ttyd processes. Returns array of
     * ['pid'=>string, 'cmdline'=>string] for each ttyd found via pgrep.
     */
    private static function _scanTtydProcs(): array
    {
        $raw = trim((string)@shell_exec('pgrep -x ttyd 2>/dev/null'));
        if ($raw === '') return [];
        $out = [];
        foreach (explode("\n", $raw) as $pid) {
            $pid = trim($pid);
            if ($pid === '' || !ctype_digit($pid)) continue;
            $cmdline = (string)@file_get_contents("/proc/$pid/cmdline");
            $out[] = ['pid' => $pid, 'cmdline' => $cmdline];
        }
        return $out;
    }

    /**
     * Enumerate EVERY live terminal session across ALL users (not just one
     * home). listActiveSessionsForHome/ForAgent are scoped; the shutdown-capture
     * entrypoint needs the unscoped set, so this globs the same /var/run
     * metadata files directly and returns the full {id, agentId, path, ...} list.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function enumerateAllLiveSessions(): array
    {
        ProcessManager::sweepOrphanSessions();
        return self::listLiveSessions();
    }

    /**
     * Read-only enumeration of live-terminal sessions (one per ttyd socket) with their
     * metadata. Unlike enumerateAllLiveSessions() it does NOT run sweepOrphanSessions()
     * — so it is safe to call on the drawer's 5 s running-state poll without triggering
     * reap side effects. See DRAWER_ACTIVE_STATE_RECONCILE.md.
     *
     * @return array<int,array{id:string,agentId:string,path:string,chatId:string,user:string,started_at:int}>
     */
    public static function listLiveSessions(): array
    {
        $out = [];
        foreach (glob("/var/run/aicliterm-*.sock") ?: [] as $sock) {
            if (!preg_match('/aicliterm-(.*)\.sock$/', $sock, $m)) continue;
            $id = $m[1];

            $agentFile   = UtilityService::getAgentIdPath($id);
            $workdirFile = UtilityService::getWorkDirFilePath($id);
            $chatFile    = UtilityService::getChatIdPath($id);
            $userFile    = UtilityService::getUserIdPath($id);

            $out[] = [
                'id'         => $id,
                'agentId'    => is_file($agentFile)   ? trim((string)@file_get_contents($agentFile))   : '',
                'path'       => is_file($workdirFile) ? trim((string)@file_get_contents($workdirFile)) : '',
                'chatId'     => is_file($chatFile)    ? trim((string)@file_get_contents($chatFile))    : '',
                'user'       => is_file($userFile)    ? trim((string)@file_get_contents($userFile))    : '',
                'started_at' => @filemtime($sock) ?: 0,
            ];
        }
        return $out;
    }

    /**
     * Live sessions that are NOT represented in the drawer's saved workspaces — i.e.
     * running invisibly (a reconnect that minted a new id, a removed drawer entry whose
     * agent persisted, a mid-recovery relaunch). Pure set-difference so it is
     * unit-testable without sockets/processes. The drawer surfaces these under an
     * "Untracked" divider so nothing can run without being visible + closable.
     * See DRAWER_ACTIVE_STATE_RECONCILE.md.
     *
     * @param array<int,array{id?:string,agentId?:string,path?:string,chatId?:string,started_at?:int}> $live
     * @param array<int,array{id?:string}|string> $drawerSessions
     * @return array<int,array{id:string,agentId:string,path:string,chatId:string,started_at:int}>
     */
    // ---- Deliberate-close intent (docs/specs/WORKSPACE_CLOSE_INTENT.md, #218) --
    //
    // A close is not instant. Measured on a real workspace: 5s capturing the
    // resume id, 3s waiting for tmux, 1s to kill — about 9 seconds end to end.
    // For that whole window the workspace has already left the drawer's list
    // while its session is still alive, which is precisely the shape
    // reconcileOrphans reports as untracked. So closing a workspace made it
    // appear, briefly, as an orphan offering Attach and Close — it looked like
    // something had gone wrong when nothing had.
    //
    // A fixed grace period was the obvious fix and the wrong one: it has to be
    // right for every agent on every box, and a slow-to-quiesce agent would
    // still flash the row. Recording the INTENT instead means a healthy close
    // never shows the row at all, however long it takes.
    //
    // The TTL is only a backstop for a close that died halfway — a PHP fatal, a
    // container restart — where nothing is left to clear the mark. It can
    // afford to be generous precisely because a healthy close clears it in
    // seconds; it is never the thing waited on in the normal case.
    private const CLOSE_INTENT_TTL = 60;

    private static function closeIntentPath(string $sessionId): string
    {
        $base = rtrim((string)(getenv('AICLI_TMP_BASE') ?: SecretPaths::stateRoot()), '/');
        $safe = preg_replace('/[^A-Za-z0-9_-]/', '', $sessionId);
        return $base . '/closing-' . ($safe !== '' ? $safe : 'invalid');
    }

    /** Mark a session as deliberately closing. Best-effort; never breaks a close. */
    public static function markClosing(string $sessionId): void
    {
        if ($sessionId === '') return;
        @mkdir(dirname(self::closeIntentPath($sessionId)), 0755, true);
        @file_put_contents(self::closeIntentPath($sessionId), (string)time());
    }

    /** Clear the mark. Called on every exit path out of a close, success or not. */
    public static function clearClosing(string $sessionId): void
    {
        if ($sessionId === '') return;
        @unlink(self::closeIntentPath($sessionId));
    }

    /**
     * True while this session is deliberately closing.
     *
     * @param int|null $now injected by tests so the TTL is assertable without sleeping.
     */
    public static function isClosing(string $sessionId, ?int $now = null): bool
    {
        if ($sessionId === '') return false;
        $path = self::closeIntentPath($sessionId);
        $raw = @file_get_contents($path);
        if ($raw === false) return false;
        $at = (int)trim((string)$raw);
        $now = $now ?? time();
        if ($at <= 0 || ($now - $at) > self::CLOSE_INTENT_TTL) {
            // Stale: the close never reported back. Drop the mark so the
            // session shows as the orphan it has turned out to be.
            @unlink($path);
            return false;
        }
        return true;
    }

    public static function reconcileOrphans(array $live, array $drawerSessions): array
    {
        $known = [];
        foreach ($drawerSessions as $w) {
            $id = is_array($w) ? (string)($w['id'] ?? '') : (string)$w;
            if ($id !== '') $known[$id] = true;
        }
        $out = [];
        foreach ($live as $s) {
            $id = (string)($s['id'] ?? '');
            if ($id === '' || isset($known[$id])) continue;
            // #218: a workspace the operator is deliberately closing is not an
            // orphan, it is a close in progress. See markClosing above.
            if (self::isClosing($id)) continue;
            $out[] = [
                'id'         => $id,
                'agentId'    => (string)($s['agentId'] ?? ''),
                'path'       => (string)($s['path'] ?? ''),
                'chatId'     => (string)($s['chatId'] ?? ''),
                'started_at' => (int)($s['started_at'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * Shared shutdown-capture entrypoint (R1 of CAPTURE_RESUME_ALL_CLOSE_PATHS).
     *
     * Captures resume ids for live sessions before an OS-lifecycle hard-kill
     * (array stop / shutdown / uninstall / migration), using a HYBRID strategy:
     *
     *   Pass 1 (GUARANTEED, fast, no quiesce): for EVERY target session, run the
     *     disk-fallback discoverLatestSessionId -> saveResumeId. This always runs
     *     to completion for all sessions and never closes anything — it is the
     *     hard floor so resume is never lost even if the budget is tiny.
     *   Pass 2 (BEST-EFFORT, time-boxed): while a budget remains, run the full
     *     captureResumeForClose (quiesce + scrape, overwrites the fallback with
     *     the authoritative id) AND close the session. When the deadline passes,
     *     pass 2 STOPS — remaining sessions keep their pass-1 fallback and are NOT
     *     closed here (the caller's kill sweep handles them). Each pass-2 step is
     *     try/caught so one hang/failure can't abort the rest.
     *
     * NEVER throws (shutdown safety) and no-ops cleanly when there are no
     * sessions. Returns a summary ['fallback_saved', 'clean_closed', 'skipped'].
     *
     * @param array<int,string>|null $sessionIds  null = all live sessions; else only these ids.
     * @param int $budgetSecs                       total wall-clock budget for pass 2.
     * @param array<string,callable|int> $seams     test seams (enumerate/clock/discover/save/closeFull).
     * @return array{fallback_saved:int,clean_closed:int,skipped:int}
     */
    public static function captureResumeForShutdown(?array $sessionIds = null, int $budgetSecs = 8, array $seams = []): array
    {
        $summary = ['fallback_saved' => 0, 'clean_closed' => 0, 'skipped' => 0];

        // --- Seams (default to the real implementations) -------------------
        $enumerate = $seams['enumerate'] ?? function (): array {
            return self::enumerateAllLiveSessions();
        };
        $clock = $seams['clock'] ?? function (): int { return time(); };
        $discover = $seams['discover'] ?? function (string $agentId, string $path): ?string {
            // The bash lifecycle bridges (R2/R4) bootstrap only AICliAgentsManager,
            // which does NOT load the handlers — ensure TerminalHandler is present
            // before the static call (it's the disk-fallback source of truth).
            if (!class_exists('\\AICliAgents\\Handlers\\TerminalHandler', false)) {
                require_once __DIR__ . '/../handlers/TerminalHandler.php';
            }
            return \AICliAgents\Handlers\TerminalHandler::discoverLatestSessionId($agentId, $path);
        };
        $save = $seams['save'] ?? function (string $path, string $agentId, string $id, string $sessionId = ''): void {
            // Pass 1 is disk-only (newest on disk): guarded on a shared folder (RESUME_IDENTITY_PER_WORKSPACE.md V3).
            ConfigService::saveDiskFallbackResumeId($path, $agentId, $id, $sessionId);
        };
        $closeFull = $seams['closeFull'] ?? function (array $session): void {
            self::cleanCloseSession($session);
        };
        // NOTE: pass 2 checks the deadline only at the START of each session, so a
        // single close can overrun the budget by one closeFull's worth of fixed
        // work (cleanCloseSession's bounded quiesce sleeps + scrape). The HARD
        // backstop against a truly wedged close is the `timeout -k <ceiling>`
        // around the whole php process in capture_resume.sh — not an in-PHP cap.

        // --- Resolve target sessions --------------------------------------
        $sessions = [];
        try {
            $all = $enumerate();
            if ($sessionIds === null) {
                $sessions = $all;
            } else {
                $want = array_flip(array_map('strval', $sessionIds));
                foreach ($all as $s) {
                    if (isset($want[(string)($s['id'] ?? '')])) $sessions[] = $s;
                }
            }
        } catch (\Throwable $e) {
            LogService::log(
                "captureResumeForShutdown: failed to enumerate sessions — " . $e->getMessage(),
                LogService::LOG_WARN, "TerminalService"
            );
            return $summary;
        }

        if (empty($sessions)) {
            return $summary;
        }

        // --- Pass 1: guaranteed fast disk-fallback for EVERY session -------
        // Track which sessions got a pass-1 fallback so pass 2 can be ordered to
        // protect the ones that DIDN'T (scrape-only agents whose discover()
        // returned null — kilocode/codex/gemini/opencode). If the budget runs
        // out, the skipped sessions should be the ones that already have a saved
        // fallback, maximizing total resume capture.
        $noFallback = [];   // sessions discover() couldn't save a fallback for (scrape-only)
        $hasFallback = [];  // sessions that already have a disk fallback from pass 1
        foreach ($sessions as $s) {
            $agentId = (string)($s['agentId'] ?? '');
            $path    = (string)($s['path'] ?? '');
            try {
                $id = ($agentId !== '' && $path !== '') ? $discover($agentId, $path) : null;
                if ($id !== null && $id !== '') {
                    $save($path, $agentId, (string)$id, (string)($s['id'] ?? ''));
                    $summary['fallback_saved']++;
                    $hasFallback[] = $s;
                } else {
                    $summary['skipped']++;
                    $noFallback[] = $s;
                }
            } catch (\Throwable $e) {
                $summary['skipped']++;
                $noFallback[] = $s; // no fallback saved => prioritize in pass 2
                LogService::log(
                    "captureResumeForShutdown: pass-1 fallback failed for session=" . ($s['id'] ?? '?') . " — " . $e->getMessage(),
                    LogService::LOG_WARN, "TerminalService"
                );
            }
        }

        // --- Pass 2: best-effort, time-boxed full clean close -------------
        // Order: no-fallback (scrape-only) sessions FIRST — they depend ENTIRELY
        // on this clean close for resume — then the ones that already have a
        // disk fallback. Stable within each group (original enumeration order).
        $pass2Order = array_merge($noFallback, $hasFallback);
        if ($budgetSecs > 0) {
            $deadline = $clock() + $budgetSecs;
            foreach ($pass2Order as $s) {
                if ($clock() >= $deadline) break;
                try {
                    $closeFull($s);
                    $summary['clean_closed']++;
                } catch (\Throwable $e) {
                    LogService::log(
                        "captureResumeForShutdown: pass-2 clean close failed for session=" . ($s['id'] ?? '?') . " — " . $e->getMessage(),
                        LogService::LOG_WARN, "TerminalService"
                    );
                }
            }
        }

        LogService::log(
            sprintf(
                "captureResumeForShutdown: fallback_saved=%d clean_closed=%d skipped=%d (budget=%ds, %d sessions)",
                $summary['fallback_saved'], $summary['clean_closed'], $summary['skipped'],
                $budgetSecs, count($sessions)
            ),
            LogService::LOG_INFO, "TerminalService"
        );

        return $summary;
    }

    /**
     * Pass-2 helper: run the FULL clean capture (quiesce + scrape, which itself
     * saveResumeId's the authoritative id) for one session, then tear it down.
     * Mirrors gracefulClose's pipeline without the HTTP/$_GET surface.
     *
     * @param array<string,mixed> $session
     */
    public static function cleanCloseSession(array $session): void
    {
        $id      = (string)($session['id'] ?? '');
        $agentId = (string)($session['agentId'] ?? '');
        $path    = (string)($session['path'] ?? '');
        if ($id === '') return;

        $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
        $ctx = sprintf("session=%s agent=%s workspace=%s (shutdown-capture)",
            $safeId, $agentId !== '' ? $agentId : 'unknown', $path !== '' ? $path : 'unknown');

        if (!class_exists('\\AICliAgents\\Handlers\\TerminalHandler', false)) {
            require_once __DIR__ . '/../handlers/TerminalHandler.php';
        }

        [$sessName, $tmuxSock, $tmuxBin] = ProcessManager::findTmuxSessionForId($safeId);
        if ($sessName !== '') {
            @mkdir(SecretPaths::stateRoot(), 0755, true);
            \AICliAgents\Handlers\TerminalHandler::captureResumeForClose(
                $sessName, $tmuxSock, $tmuxBin, $agentId, $path, $ctx
            );
        }

        // Tear down ttyd + sockets + tmux (the kill sweep would do this anyway;
        // doing it here lets the budget account for the slow part — the scrape).
        ProcessManager::stopTerminal($safeId, true);
        // #814: the capture set the closing sentinel; the session is gone, so remove it.
        @unlink(\AICliAgents\Handlers\TerminalHandler::closingFlagPath($safeId));
    }

    /**
     * Cleans up inactive terminal sessions.
     */
    public static function gc() {
        $socks = glob("/var/run/aicliterm-*.sock");
        foreach ($socks as $sock) {
            if (preg_match('/aicliterm-(.*)\.sock$/', $sock, $m)) {
                $id = $m[1];
                if (!ProcessManager::isRunning($id)) {
                    LogService::log("GC: Cleaning up inactive terminal session: $id", LogService::LOG_INFO, "TerminalService");
                    ProcessManager::stopTerminal($id, true);
                }
            }
        }
    }
}
