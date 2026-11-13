<?php
/**
 * <module_context>
 *     <name>ShfsView</name>
 *     <description>Layer 1 of the share-filesystem guard (docs/specs/SHFS_RECURSIVE_READ_GUARD.md, Forgejo #972): the hidden view of a helper workspace. A workspace whose effective `view` protection is on (GuardPolicy; by default a workspace that has a parent) starts its tmux server inside a private, persistent mount space in which every guarded root (/mnt/user, /mnt/user0, /mnt/remotes and every mount of a guarded type) is an empty read-only tmpfs. Only the paths that the helper needs are bound back: its own folder (read-write), the paths of the spawn field hostPaths, and the absolute paths that its task text names (read-write, John 2026-11-13). This class holds the PURE parts (validation, task-text extraction, the bind plan) and builds the command that runs src/scripts/guard/aicli-guard-view.sh. The script does the mounts.</description>
 *     <dependencies>ShfsGuard (hostContext, normalizePath, rules), RunUserService (viewDir, runtimeToolPath), ConfigService, UtilityService</dependencies>
 *     <constraints>Never runs a mount, unshare or nsenter itself: only TerminalService runs the command that this class builds, and a unit test never runs it. A pool-only share is bound from its pool folder (no I/O through shfs); any other share is bound from the shfs path itself. A path with a pipe or a control character is refused, because the script gets each bind as one word source|target. The mount-space file ends in .gview so that RunUserService::sweepViews (which reads *.mnt) never touches it.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class ShfsView
{
    public const MAX_HOST_PATHS = 16;
    public const MAX_TASK_PATHS = 16;
    /** Bounding-set capabilities that undo a mount view (spec R10). */
    public const DROP_CAPS = ['sys_admin', 'sys_ptrace', 'sys_module', 'sys_chroot', 'dac_read_search'];

    /** @var array<string,mixed>|null context of the host (test seam) */
    public static ?array $ctxForTests = null;
    /** @var array<string,mixed>|null plugin config (test seam) */
    public static ?array $configForTests = null;
    /** @var callable|null fn(string $sourcePath): bool, a test seam for the existence check */
    public static $existsForTests = null;

    private const POOL_FREE_ROOTS = ['user', 'user0', 'remotes'];

    /** The setting shfs_guard. On when absent; off only for 0, false, off or no. */
    public static function enabled(): bool
    {
        $config = self::$configForTests;
        if ($config === null) {
            try {
                $config = \function_exists('getAICliConfig') ? getAICliConfig() : ConfigService::getConfig();
            } catch (\Throwable $e) {
                $config = [];
            }
        }
        $v = strtolower(trim((string)($config['shfs_guard'] ?? '1')));
        return !in_array($v, ['0', 'false', 'off', 'no'], true);
    }

    /** @return array{roots:array<string,string>,pools:array<string,?string>,allow:list<string>} */
    public static function context(): array
    {
        return self::$ctxForTests ?? ShfsGuard::hostContext();
    }

    /** @param array<string,mixed> $ctx @return array<string,string> root => fstype, with the fixed roots added */
    private static function roots(array $ctx): array
    {
        $roots = [];
        foreach ((array)($ctx['roots'] ?? []) as $r => $t) {
            $r = rtrim((string)$r, '/');
            if ($r !== '' && $r !== '/') $roots[$r] = (string)$t;
        }
        foreach ((array)(ShfsGuard::rules()['fixedRoots'] ?? []) as $r) {
            if (!isset($roots[$r])) $roots[$r] = 'fixed';
        }
        return $roots;
    }

    /**
     * The guarded roots that the view covers: the outermost ones only (a mount below a covered root is hidden by it).
     * @param array<string,mixed> $ctx
     * @return list<string>
     */
    public static function coverRoots(array $ctx): array
    {
        $all = array_keys(self::roots($ctx));
        sort($all);
        $out = [];
        foreach ($all as $r) {
            foreach ($out as $o) {
                if (strncmp($r, $o . '/', strlen($o) + 1) === 0) continue 2;
            }
            $out[] = $r;
        }
        return $out;
    }

    /**
     * Where a path is. Null when it is not under a guarded root. 'depth0' is true for the root itself.
     * @param array<string,mixed> $ctx
     * @return array{root:string,source:string,sourcePath:string,depth0:bool}|null source is pool, shfs or network
     */
    public static function locate(string $path, array $ctx): ?array
    {
        $best = null;
        foreach (self::roots($ctx) as $r => $t) {
            if (($path === $r || strncmp($path, $r . '/', strlen($r) + 1) === 0) && ($best === null || strlen($r) > strlen($best))) $best = $r;
        }
        if ($best === null) return null;
        $type = self::roots($ctx)[$best];
        $network = in_array($type, ['nfs', 'nfs4', 'cifs', 'smb3', 'fuse.sshfs', 'fuse.rclone'], true)
            || $best === '/mnt/remotes' || strncmp($best, '/mnt/remotes/', 13) === 0;
        $rest = ltrim(substr($path, strlen($best)), '/');
        if ($network) return ['root' => $best, 'source' => 'network', 'sourcePath' => $path, 'depth0' => $rest === ''];
        $parts = $rest === '' ? [''] : explode('/', $rest, 2);
        $pool = $best === '/mnt/user' ? (((array)($ctx['pools'] ?? []))[$parts[0]] ?? null) : null;
        if (is_string($pool) && $pool !== '' && $rest !== '') {
            return ['root' => $best, 'source' => 'pool', 'sourcePath' => rtrim($pool, '/') . (isset($parts[1]) ? '/' . $parts[1] : ''), 'depth0' => false];
        }
        return ['root' => $best, 'source' => 'shfs', 'sourcePath' => $path, 'depth0' => $rest === ''];
    }

    private static function badChars(string $p): bool
    {
        return $p === '' || $p[0] !== '/' || preg_match('/[\x00-\x1F\x7F|]/', $p) === 1 || in_array('..', explode('/', $p), true);
    }

    /**
     * Check the spawn field hostPaths. Each entry is {path, mode} (mode ro or rw, default ro) or a plain path (ro).
     * The path is absolute, has no "..", and is under a guarded root or on a pool (/mnt/<pool>/...).
     * @param mixed $raw
     * @param array<string,mixed> $ctx
     * @return array{0:list<array{path:string,mode:string}>,1:?string} the clean list, and an error sentence
     */
    public static function validateHostPaths($raw, array $ctx): array
    {
        if ($raw === null || $raw === []) return [[], null];
        if (!is_array($raw) || array_keys($raw) !== range(0, count($raw) - 1)) return [[], 'hostPaths must be a list of {path, mode} entries.'];
        if (count($raw) > self::MAX_HOST_PATHS) return [[], 'hostPaths holds at most ' . self::MAX_HOST_PATHS . ' paths.'];
        $out = [];
        foreach ($raw as $e) {
            $path = is_array($e) ? (string)($e['path'] ?? '') : (is_string($e) ? $e : '');
            $mode = is_array($e) ? strtolower(trim((string)($e['mode'] ?? 'ro'))) : 'ro';
            if ($mode === '') $mode = 'ro';
            if (!in_array($mode, ['ro', 'rw'], true)) return [[], "hostPaths: the mode of '$path' must be ro or rw."];
            $path = trim($path);
            if (self::badChars($path)) return [[], "hostPaths: '$path' must be an absolute path with no '..', no pipe and no control character."];
            $path = ShfsGuard::normalizePath($path, '/');
            $loc = self::locate($path, $ctx);
            if ($loc !== null) {
                if ($loc['depth0']) return [[], "hostPaths: '$path' is a whole guarded root. Name a folder inside it."];
            } else {
                $segs = explode('/', ltrim($path, '/'));
                if (count($segs) < 3 || $segs[0] !== 'mnt' || in_array($segs[1], self::POOL_FREE_ROOTS, true)) {
                    return [[], "hostPaths: '$path' must be under /mnt/user, /mnt/user0, /mnt/remotes or on a pool (/mnt/<pool>/<folder>)."];
                }
            }
            $out[$path] = ($out[$path] ?? 'ro') === 'rw' ? 'rw' : $mode;
        }
        $list = [];
        foreach ($out as $p => $m) $list[] = ['path' => $p, 'mode' => $m];
        return [$list, null];
    }

    /**
     * The absolute paths under a guarded root that a task text names. A mention of a bare root (/mnt/user) is ignored.
     * @return list<string>
     */
    public static function extractTaskPaths(string $task): array
    {
        $found = [];
        if (preg_match_all('~(?<![A-Za-z0-9_./-])(/mnt/(?:user0?|remotes)/[^\s\'"`<>()\[\]{}|;,*?!#$\\\\]+)~', $task, $m) !== false) {
            foreach ($m[1] as $raw) {
                $raw = rtrim($raw, '.:/-');
                if ($raw === '' || self::badChars($raw)) continue;
                $p = ShfsGuard::normalizePath($raw, '/');
                $segs = explode('/', ltrim($p, '/'));
                if (count($segs) < 3) continue;
                $found[$p] = true;
                if (count($found) >= self::MAX_TASK_PATHS) break;
            }
        }
        return array_keys($found);
    }

    /**
     * The bind plan. Pure but for the existence check of a source (seam: $exists).
     * @param list<string> $extraRw read-write paths the plugin adds (the git folder of a worktree's repository)
     * @param list<array{path:string,mode:string}> $hostPaths
     * @param list<string> $taskPaths
     * @param array<string,mixed> $ctx
     * @param callable|null $exists fn(string $sourcePath): bool
     * @return array{covers:list<string>,binds:list<array<string,mixed>>,skipped:list<array{path:string,reason:string}>,errors:list<string>}
     */
    public static function plan(string $folder, array $extraRw, array $hostPaths, array $taskPaths, array $ctx, ?callable $exists = null): array
    {
        $exists = $exists ?? self::$existsForTests ?? static fn(string $p): bool => file_exists($p);
        $errors = [];
        $skipped = [];
        /** @var array<string,array{mode:string,origin:string}> $want */
        $want = [];
        $add = static function (string $p, string $mode, string $origin) use (&$want): void {
            if (!isset($want[$p])) { $want[$p] = ['mode' => $mode, 'origin' => $origin]; return; }
            if ($mode === 'rw') $want[$p]['mode'] = 'rw';
        };
        if ($folder !== '') $add(ShfsGuard::normalizePath($folder, '/'), 'rw', 'folder');
        foreach ($extraRw as $p) if (!self::badChars((string)$p)) $add(ShfsGuard::normalizePath((string)$p, '/'), 'rw', 'git');
        foreach ($hostPaths as $h) $add((string)$h['path'], (string)$h['mode'], 'hostPaths');
        foreach ($taskPaths as $p) $add((string)$p, 'rw', 'task');

        $items = [];
        foreach ($want as $p => $w) {
            if (self::badChars($p)) { $errors[] = "A path to bind is not safe: $p"; continue; }
            $loc = self::locate($p, $ctx);
            if ($loc === null) {
                // Visible already (a pool or a plain folder). A read-only request is enforced with a bind onto itself.
                $items[] = ['path' => $p, 'mode' => $w['mode'], 'source' => 'pool', 'from' => $p, 'origin' => $w['origin'], 'apply' => $w['mode'] === 'ro' && $w['origin'] === 'hostPaths'];
                continue;
            }
            if ($loc['depth0']) { $skipped[] = ['path' => $p, 'reason' => 'a whole guarded root is never bound']; continue; }
            $ok = $loc['source'] === 'network' ? true : (bool)$exists($loc['sourcePath']);
            if (!$ok) {
                if ($w['origin'] === 'folder') $errors[] = 'The folder of the workspace does not exist: ' . $loc['sourcePath'];
                else $skipped[] = ['path' => $p, 'reason' => 'missing'];
                continue;
            }
            $items[] = ['path' => $p, 'mode' => $w['mode'], 'source' => $loc['source'], 'from' => $loc['sourcePath'], 'origin' => $w['origin'], 'apply' => true];
        }
        // Order by depth, then name. A child that its applied parent already gives with the same or a wider mode is dropped.
        usort($items, static fn(array $a, array $b): int => [substr_count($a['path'], '/'), $a['path']] <=> [substr_count($b['path'], '/'), $b['path']]);
        $kept = [];
        foreach ($items as $it) {
            foreach ($kept as $k) {
                if ($k['apply'] && $it['apply'] && strncmp($it['path'], $k['path'] . '/', strlen($k['path']) + 1) === 0
                    && ($k['mode'] === 'rw' || $it['mode'] === 'ro') && $k['source'] === $it['source']) {
                    $skipped[] = ['path' => $it['path'], 'reason' => 'inside ' . $k['path']];
                    continue 2;
                }
            }
            $kept[] = $it;
        }
        return ['covers' => self::coverRoots($ctx), 'binds' => $kept, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * The plan of one workspace record at start time. The spawn tool keeps hostPaths and the task paths in spawn.view.
     * @param array<string,mixed> $record
     * @return array{covers:list<string>,binds:list<array<string,mixed>>,skipped:list<array{path:string,reason:string}>,errors:list<string>}
     */
    public static function planForRecord(array $record, string $folder, ?array $ctx = null): array
    {
        $ctx = $ctx ?? self::context();
        $spawn = is_array($record['spawn'] ?? null) ? $record['spawn'] : [];
        $view = is_array($spawn['view'] ?? null) ? $spawn['view'] : [];
        $extra = [];
        $repo = (string)($spawn['worktree']['repo'] ?? '');
        if ($repo !== '' && $repo[0] === '/') $extra[] = rtrim($repo, '/') . '/.git';
        [$hostPaths] = self::validateHostPaths($view['hostPaths'] ?? [], $ctx);
        $task = [];
        foreach ((array)($view['taskPaths'] ?? []) as $p) if (is_string($p) && !self::badChars($p)) $task[] = $p;
        return self::plan($folder, $extra, $hostPaths, $task, $ctx);
    }

    /**
     * The list for the spawn reply: view.bound. Every wanted path, with its mode and its source.
     * @param array<string,mixed> $plan
     * @return list<array{path:string,mode:string,source:string}>
     */
    public static function bound(array $plan): array
    {
        $out = [];
        foreach ((array)($plan['binds'] ?? []) as $b) $out[] = ['path' => (string)$b['path'], 'mode' => (string)$b['mode'], 'source' => (string)$b['source']];
        return $out;
    }

    // ------------------------------------------------------------------
    // The command (only TerminalService runs it)
    // ------------------------------------------------------------------

    public static function nsFile(string $id): string
    {
        return preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $id) === 1 ? RunUserService::viewDir() . '/' . $id . '.gview' : '';
    }

    /** Remove the mount-space file of a workspace (at a new start and at close). Best effort. */
    public static function release(string $id): void
    {
        $f = self::nsFile($id);
        if ($f === '' || (!file_exists($f) && !is_link($f))) return;
        if (!defined('AICLI_TEST_TMP')) {
            $proc = @proc_open(['/bin/umount', '--', $f], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
            if (is_resource($proc)) proc_close($proc);
        }
        if (is_file($f) && !is_link($f)) @unlink($f);
    }

    /** Make an empty root-only file for `unshare --mount=<file>`. Returns the file, or '' on a fault. */
    public static function prepare(string $id): string
    {
        $f = self::nsFile($id);
        if ($f === '') return '';
        $dir = dirname($f);
        if (is_link($dir) || (!is_dir($dir) && !@mkdir($dir, 0700, true))) return '';
        self::release($id);
        $old = umask(0077);
        try { $h = @fopen($f, 'x'); } finally { umask($old); }
        if ($h === false) return '';
        fclose($h);
        return $f;
    }

    /**
     * Wrap the ensure-session command so that it runs in the hidden view. Returns [command, error]. The command is '' on a fault
     * and the start fails closed.
     * @param array<string,mixed> $plan
     * @param list<string> $hide folders that get an empty tmpfs (ssh keys)
     * @param array{ssh?:bool,caps?:bool} $flags R18: which protections of the view apply. ssh = hide the ssh keys, caps = drop
     *        the admin powers (setpriv). Both default to on. The docker check is a launch switch of aicli-shell.sh, not of this script.
     * @return array{0:string,1:string}
     */
    public static function wrapCommand(string $activeSrc, string $id, string $ensureCmd, array $plan, array $hide, array $flags = []): array
    {
        $hideSsh = ($flags['ssh'] ?? true) !== false;
        $dropCaps = ($flags['caps'] ?? true) !== false;
        if (!empty($plan['errors'])) return ['', (string)$plan['errors'][0]];
        $unshare = RunUserService::runtimeToolPath('unshare');
        $setpriv = RunUserService::runtimeToolPath('setpriv');
        if ($unshare === '') return ['', 'tool_missing:unshare'];
        if ($dropCaps && $setpriv === '') return ['', 'tool_missing:setpriv'];
        $script = $activeSrc . '/scripts/guard/aicli-guard-view.sh';
        $ns = self::prepare($id);
        if ($ns === '') return ['', 'namespace_file'];
        $args = '';
        foreach ((array)$plan['covers'] as $c) $args .= ' --cover=' . escapeshellarg((string)$c);
        foreach ((array)$plan['binds'] as $b) {
            if (empty($b['apply'])) continue;
            $args .= ' --bind=' . escapeshellarg($b['mode'] . '|' . $b['from'] . '|' . $b['path']);
        }
        foreach ($hideSsh ? $hide : [] as $h) {
            if (!self::badChars($h)) $args .= ' --hide=' . escapeshellarg($h);
        }
        $drop = implode(',', array_map(static fn(string $c): string => '-' . $c, self::DROP_CAPS));
        $caps = $dropCaps ? ' --setpriv=' . escapeshellarg($setpriv) . ' --drop-caps=' . escapeshellarg($drop) : '';
        $cmd = escapeshellarg($unshare) . ' --mount=' . escapeshellarg($ns) . ' /bin/bash ' . escapeshellarg($script) . $args . $caps
            . ' -- /bin/bash -c ' . escapeshellarg($ensureCmd);
        return [$cmd, ''];
    }

    /** The folders whose ssh keys the view hides (root's and the overlay home's). @return list<string> */
    public static function sshHideFolders(string $username): array
    {
        $out = [];
        foreach (['/root/.ssh', UtilityService::getWorkDir($username) . '/home/.ssh'] as $d) {
            if (is_dir($d)) $out[] = $d;
        }
        return array_values(array_unique($out));
    }

    /**
     * The status text of the spawn reply: built, off_by_setting or failed:<reason>.
     * @param array<string,mixed>|null $started the result of the start
     */
    public static function statusText(bool $settingOn, ?array $started): string
    {
        if (!$settingOn) return 'off_by_setting';
        if ($started === null) return 'planned';
        if (!empty($started['ok'])) return (string)($started['viewStatus'] ?? 'built');
        $reason = (string)($started['viewReason'] ?? '');
        return $reason !== '' ? 'failed:' . $reason : 'failed:not_started';
    }
}
