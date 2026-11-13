<?php
/**
 * <module_context>
 *     <name>ShfsGuard</name>
 *     <description>The shared checker of the share-filesystem guard (docs/specs/SHFS_RECURSIVE_READ_GUARD.md, Forgejo #972). It decides if a command or an agent tool call walks a whole folder tree over a guarded root (the Unraid share filesystem shfs, or a network mount), or walks from an ancestor of one (/ or /mnt) without staying on one file system. It returns null (allow) or a refusal with a plain text that names the pool path to use. Layer 3 (the pre-tool hook, src/scripts/aicli-guard-hook.php) calls it. The bash wrapper of layer 2 follows the same rule list and the same case table.</description>
 *     <dependencies>includes/guard/shfs-guard-rules.json (rule list). LogService only in onRefusal().</dependencies>
 *     <constraints>Paths are normalised with string rules only: never stat, realpath or any file system call on a path that an agent names (a dead network mount would hang the check). hostContext() reads /proc/self/mountinfo and /boot/config/shares/*.cfg only. Test hook: AICLI_GUARD_CTX_FILE (a JSON context file) is honoured by hostContext() only when AICLI_GUARD_TEST=1.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class ShfsGuard
{
    /** @var array<string,mixed>|null */
    private static ?array $rules = null;

    /** Mount types that are network mounts (kind "network"). */
    private const NETWORK_TYPES = ['nfs', 'nfs4', 'cifs', 'smb3', 'fuse.sshfs', 'fuse.rclone'];

    /** Shells that run the text after -c: the guard looks inside it. */
    private const SHELLS = ['bash', 'sh', 'zsh', 'dash', 'ash'];

    /** Words that start a command inside a compound shell command. */
    private const KEYWORDS = ['if', 'then', 'do', 'else', 'elif', 'while', 'until', '!', '{', '}', 'time'];

    /**
     * The rule list, loaded once.
     * @return array<string,mixed>
     */
    public static function rules(): array
    {
        if (self::$rules === null) {
            $file = __DIR__ . '/../guard/shfs-guard-rules.json';
            $data = null;
            $raw = @file_get_contents($file);
            if ($raw !== false) {
                $data = json_decode($raw, true);
            }
            self::$rules = is_array($data) ? $data : [
                'fixedRoots' => ['/mnt/user', '/mnt/user0', '/mnt/remotes'],
                'fstypes' => ['fuse.shfs', 'nfs', 'nfs4', 'cifs', 'smb3', 'fuse.sshfs', 'fuse.rclone'],
                'ancestors' => ['/', '/mnt'],
                'commands' => ['find', 'grep', 'egrep', 'fgrep', 'rg', 'ag', 'ack', 'fd', 'fdfind', 'du', 'ls', 'tree', 'git'],
                'maxDepthAllowed' => 1,
                'texts' => [
                    'pool' => "aicli guard: refused '{cmd}'. A recursive read over {root} can freeze this server. Use the pool path: {pool}.",
                    'array' => "aicli guard: refused '{cmd}'. A recursive read over {root} can freeze this server. Search a smaller named folder.",
                    'network' => "aicli guard: refused '{cmd}'. {root} is a network mount. Name a smaller folder.",
                    'ancestor' => "aicli guard: refused '{cmd}'. A walk from {path} goes into the share filesystem. Stay on one file system or name the folder you need.",
                ],
            ];
        }
        return self::$rules;
    }

    /**
     * Join a path to the current folder and clean it with string rules (., .., //). No file system call.
     */
    public static function normalizePath(string $path, string $cwd): string
    {
        if ($path === '') {
            $path = '.';
        }
        if ($path[0] !== '/') {
            $path = rtrim($cwd === '' ? '/' : $cwd, '/') . '/' . $path;
        }
        $out = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($out);
                continue;
            }
            $out[] = $seg;
        }
        return '/' . implode('/', $out);
    }

    // ------------------------------------------------------------------
    // Layer 3 entry points
    // ------------------------------------------------------------------

    /**
     * Check one command given as an argument list.
     * @param string[] $argv
     * @param array<string,mixed> $ctx
     * @return array{kind:string,root:string,path:string,poolPath:?string,text:string}|null
     */
    public static function checkArgv(array $argv, string $cwd, array $ctx): ?array
    {
        $argv = array_values(array_map('strval', $argv));
        if ($argv === []) {
            return null;
        }
        $name = basename($argv[0]);
        $cmd = substr(implode(' ', $argv), 0, 200);
        $max = (int)(self::rules()['maxDepthAllowed'] ?? 1);
        $paths = null;       // start paths; [] means the current folder
        $allowAncestor = false;

        switch ($name) {
            case 'find':
                $i = 1;
                $n = count($argv);
                while ($i < $n && in_array($argv[$i], ['-H', '-L', '-P'], true)) {
                    $i++;
                }
                $paths = [];
                while ($i < $n && $argv[$i] !== '' && !in_array($argv[$i][0], ['-', '(', '!', ','], true)) {
                    $paths[] = $argv[$i];
                    $i++;
                }
                $depth = null;
                for (; $i < $n; $i++) {
                    if ($argv[$i] === '-xdev' || $argv[$i] === '-mount') {
                        $allowAncestor = true;
                    } elseif ($argv[$i] === '-maxdepth' && isset($argv[$i + 1]) && is_numeric($argv[$i + 1])) {
                        $d = (int)$argv[$i + 1];
                        $depth = $depth === null ? $d : min($depth, $d);
                    }
                }
                if ($depth !== null && $depth <= $max) {
                    return null;
                }
                break;

            case 'grep':
            case 'egrep':
            case 'fgrep':
                $p = self::parseOpts($argv, 'efmABCdD', [
                    'regexp', 'file', 'max-count', 'after-context', 'before-context', 'context', 'directories',
                    'devices', 'exclude', 'exclude-from', 'exclude-dir', 'include', 'label', 'binary-files',
                    'group-separator',
                ]);
                $rec = isset($p['flags']['-r']) || isset($p['flags']['-R'])
                    || isset($p['flags']['recursive']) || isset($p['flags']['dereference-recursive'])
                    || in_array('recurse', $p['vals']['-d'] ?? [], true)
                    || in_array('recurse', $p['vals']['directories'] ?? [], true);
                if (!$rec) {
                    return null;
                }
                $hasPattern = isset($p['flags']['-e']) || isset($p['flags']['-f'])
                    || isset($p['flags']['regexp']) || isset($p['flags']['file']);
                $paths = $hasPattern ? $p['pos'] : array_slice($p['pos'], 1);
                break;

            case 'rg':
                $p = self::parseOpts($argv, 'ABCEMTefgjmrt', [
                    'regexp', 'file', 'glob', 'iglob', 'type', 'type-not', 'type-add', 'type-clear',
                    'after-context', 'before-context', 'context', 'max-count', 'max-columns', 'max-depth',
                    'maxdepth', 'threads', 'encoding', 'replace', 'sort', 'sortr', 'pre', 'pre-glob',
                    'ignore-file', 'path-separator', 'max-filesize', 'colors', 'color', 'engine',
                    'context-separator', 'field-context-separator', 'field-match-separator',
                    'dfa-size-limit', 'regex-size-limit', 'hostname-bin', 'hyperlink-format',
                ]);
                $noPattern = isset($p['flags']['-e']) || isset($p['flags']['-f'])
                    || isset($p['flags']['regexp']) || isset($p['flags']['file'])
                    || isset($p['flags']['files']) || isset($p['flags']['type-list']);
                $paths = $noPattern ? $p['pos'] : array_slice($p['pos'], 1);
                $allowAncestor = isset($p['flags']['one-file-system']);
                break;

            case 'ag':
            case 'ack':
                $p = self::parseOpts($argv, $name === 'ag' ? 'ABCGgmpW' : 'ABCgm', [
                    'depth', 'ignore', 'ignore-dir', 'file-search-regex', 'path-to-ignore', 'pager',
                    'after', 'before', 'context', 'max-count', 'workers', 'type',
                ]);
                $noPattern = isset($p['flags']['-g']) || isset($p['flags']['-f']);
                $paths = $noPattern ? $p['pos'] : array_slice($p['pos'], 1);
                break;

            case 'fd':
            case 'fdfind':
                $cut = $argv;
                foreach ($argv as $k => $t) {
                    if ($k > 0 && in_array($t, ['-x', '-X', '--exec', '--exec-batch'], true)) {
                        $cut = array_slice($argv, 0, $k);
                        break;
                    }
                }
                $p = self::parseOpts($cut, 'dtEeScjo', [
                    'max-depth', 'min-depth', 'exact-depth', 'type', 'extension', 'exclude', 'size', 'color',
                    'threads', 'changed-within', 'changed-before', 'owner', 'base-directory', 'search-path',
                    'max-results', 'format', 'ignore-file', 'batch-size',
                ]);
                foreach (array_merge($p['vals']['-d'] ?? [], $p['vals']['max-depth'] ?? [], $p['vals']['exact-depth'] ?? []) as $v) {
                    if (is_numeric($v) && (int)$v <= $max) {
                        return null;
                    }
                }
                $paths = array_merge(array_slice($p['pos'], 1), $p['vals']['search-path'] ?? []);
                $allowAncestor = isset($p['flags']['one-file-system']) || isset($p['flags']['mount']) || isset($p['flags']['xdev']);
                break;

            case 'du':
                $p = self::parseOpts($argv, 'BdtX', [
                    'block-size', 'max-depth', 'threshold', 'exclude', 'exclude-from', 'files0-from', 'time-style',
                ]);
                $paths = $p['pos'];
                $allowAncestor = isset($p['flags']['-x']) || isset($p['flags']['one-file-system']);
                break;

            case 'ls':
                $p = self::parseOpts($argv, 'IwT', [
                    'ignore', 'hide', 'width', 'tabsize', 'block-size', 'format', 'sort', 'time', 'time-style',
                    'indicator-style', 'quoting-style',
                ]);
                if (!isset($p['flags']['-R']) && !isset($p['flags']['recursive'])) {
                    return null;
                }
                $paths = $p['pos'];
                break;

            case 'tree':
                $p = self::parseOpts($argv, 'LPIoHT', ['charset', 'filelimit', 'sort', 'timefmt', 'fromfile']);
                foreach ($p['vals']['-L'] ?? [] as $v) {
                    if (is_numeric($v) && (int)$v <= $max) {
                        return null;
                    }
                }
                $paths = $p['pos'];
                $allowAncestor = isset($p['flags']['-x']);
                break;

            case 'git':
                $wd = $cwd;
                $n = count($argv);
                $i = 1;
                $tree = null;
                while ($i < $n && isset($argv[$i][0]) && $argv[$i][0] === '-') {
                    $t = $argv[$i];
                    if ($t === '-C') {
                        $wd = self::normalizePath($argv[$i + 1] ?? '.', $wd);
                        $i += 2;
                    } elseif ($t === '-c' || $t === '--git-dir' || $t === '--namespace') {
                        $i += 2;
                    } elseif ($t === '--work-tree') {
                        $tree = $argv[$i + 1] ?? null;
                        $i += 2;
                    } elseif (strncmp($t, '--work-tree=', 12) === 0) {
                        $tree = substr($t, 12);
                        $i++;
                    } else {
                        $i++;
                    }
                }
                $sub = $argv[$i] ?? '';
                if ($sub !== 'grep' && $sub !== 'ls-files') {
                    return null;
                }
                $paths = [$tree !== null ? self::normalizePath($tree, $wd) : $wd];
                break;

            default:
                return null;
        }

        return self::evaluate($paths ?? [], $allowAncestor, $cwd, $ctx, $cmd);
    }

    /**
     * Check shell text (the command of a Bash tool call).
     * @param array<string,mixed> $ctx
     * @return array{kind:string,root:string,path:string,poolPath:?string,text:string}|null
     */
    public static function checkCommand(string $shell, string $cwd, array $ctx, int $depth = 0): ?array
    {
        if ($depth > 4 || trim($shell) === '') {
            return null;
        }
        foreach (self::splitShell($shell) as $segment) {
            $r = self::checkSimple($segment, $cwd, $ctx, $depth);
            if ($r !== null) {
                return $r;
            }
        }
        return null;
    }

    /**
     * Check one tool call of an agent.
     * @param array<string,mixed> $input
     * @param array<string,mixed> $ctx
     * @return array{kind:string,root:string,path:string,poolPath:?string,text:string}|null
     */
    public static function checkTool(string $agent, string $tool, array $input, string $cwd, array $ctx): ?array
    {
        $isBash = false;
        $isWalk = false;
        if ($agent === 'claude') {
            $isBash = $tool === 'Bash';
            $isWalk = $tool === 'Grep' || $tool === 'Glob';
        } elseif ($agent === 'opencode') {
            $t = strtolower($tool);
            $isBash = $t === 'bash' || $t === 'shell'; // OpenCode 2 calls its shell tool "shell" (opencode.ai/docs/permissions)
            $isWalk = $t === 'grep' || $t === 'glob';
        }
        if ($isBash) {
            $c = $input['command'] ?? '';
            return is_string($c) ? self::checkCommand($c, $cwd, $ctx) : null;
        }
        if ($isWalk) {
            $p = $input['path'] ?? ($input['filePath'] ?? '');
            $p = is_string($p) ? $p : '';
            $max = 200;
            // One named FILE on the share filesystem (not a network mount) is a single read: one stat decides it.
            if ($p !== '' && $ctx !== [] && self::isShfsFile(self::normalizePath($p, $cwd), $ctx)) return null;
            return self::evaluate($p === '' ? [] : [$p], false, $cwd, $ctx, substr($tool . ' ' . ($p === '' ? $cwd : $p), 0, $max));
        }
        return null;
    }

    /**
     * Called by the hook on every refusal (R14): one plugin log line, and the event `guard.refused` with one Activity
     * tray row, at most one for each workspace in 60 seconds (GuardEventService). $command is the text the agent tried
     * to run; it is cut to 200 characters. The shell wrapper reaches the same event through scripts/aicli-guard-event.php.
     * @param array<string,mixed> $refusal
     */
    public static function onRefusal(array $refusal, string $layer, string $agent, string $command = ''): void
    {
        if (getenv('AICLI_GUARD_TEST') === '1') {
            return;
        }
        try {
            $msg = sprintf('shfs guard refused (%s, %s, kind %s): %s', $layer, $agent, (string)($refusal['kind'] ?? ''), substr((string)($refusal['path'] ?? ''), 0, 200));
            LogService::log($msg, LogService::LOG_INFO, 'ShfsGuard');
            GuardEventService::raiseRefusal($layer, $agent, (string)($refusal['kind'] ?? ''), $command !== '' ? $command : (string)($refusal['path'] ?? ''));
        } catch (\Throwable $e) {
            // The guard never fails because a log line failed.
        }
    }

    // ------------------------------------------------------------------
    // Host context
    // ------------------------------------------------------------------

    /**
     * Guarded roots (mount point => fstype, or "fixed") and the pool path of each pool-only share.
     * Never touches a guarded path.
     * @return array{roots:array<string,string>,pools:array<string,?string>,allow:list<string>}
     */
    public static function hostContext(string $mountinfo = '/proc/self/mountinfo', string $sharesDir = '/boot/config/shares'): array
    {
        $override = getenv('AICLI_GUARD_CTX_FILE');
        if (getenv('AICLI_GUARD_TEST') === '1' && is_string($override) && $override !== '') {
            $raw = @file_get_contents($override);
            $data = $raw === false ? null : json_decode($raw, true);
            if (is_array($data)) {
                return ['roots' => (array)($data['roots'] ?? []), 'pools' => (array)($data['pools'] ?? []), 'allow' => (array)($data['allow'] ?? [])];
            }
        }

        $rules = self::rules();
        $types = (array)($rules['fstypes'] ?? []);
        $roots = [];
        $safe = [];
        $raw = @file_get_contents($mountinfo);
        if ($raw !== false) {
            foreach (explode("\n", $raw) as $line) {
                $sep = strpos($line, ' - ');
                if ($sep === false) {
                    continue;
                }
                $left = explode(' ', substr($line, 0, $sep));
                $right = explode(' ', substr($line, $sep + 3));
                if (!isset($left[4], $right[0])) {
                    continue;
                }
                $mp = preg_replace_callback('/\\\\([0-7]{3})/', static fn(array $m): string => chr(octdec($m[1])), $left[4]);
                $mp = rtrim((string)$mp, '/') ?: '/';
                if (!in_array($right[0], $types, true)) {
                    $safe[] = $mp;
                    continue;
                }
                $roots[$mp] = $right[0];
            }
        }
        foreach ((array)($rules['fixedRoots'] ?? []) as $fixed) {
            if (!isset($roots[$fixed])) {
                $roots[$fixed] = 'fixed';
            }
        }

        // A safe-type mount STRICTLY inside a guarded root (the helper view's pool binds) is allowed.
        $allow = [];
        foreach ($safe as $mp) {
            foreach (array_keys($roots) as $r) {
                if ($r !== '/' && strncmp($mp, $r . '/', strlen($r) + 1) === 0) {
                    $allow[] = $mp;
                    break;
                }
            }
        }

        $pools = [];
        foreach (glob(rtrim($sharesDir, '/') . '/*.cfg') ?: [] as $cfg) {
            $share = basename($cfg, '.cfg');
            $kv = [];
            foreach (@file($cfg, FILE_IGNORE_NEW_LINES) ?: [] as $l) {
                if (preg_match('/^\s*([A-Za-z0-9_]+)\s*=\s*"?([^"]*)"?\s*$/D', $l, $m)) {
                    $kv[$m[1]] = trim($m[2]);
                }
            }
            $pool = $kv['shareCachePool'] ?? '';
            $pools[$share] = (($kv['shareUseCache'] ?? '') === 'only' && $pool !== '' && ($kv['shareCachePool2'] ?? '') === '')
                ? '/mnt/' . $pool . '/' . $share
                : null;
        }
        return ['roots' => $roots, 'pools' => $pools, 'allow' => $allow];
    }

    /**
     * True when the path is a regular file under a share-filesystem root (never a network mount: a dead server
     * could hang the stat). One stat of one named path on shfs is a single cheap request, not a walk.
     * @param array<string,mixed> $ctx
     */
    private static function isShfsFile(string $path, array $ctx): bool
    {
        $hit = self::classify($path, $ctx);
        if ($hit === null || $hit['kind'] === 'network') return false;
        return @is_file($path);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Decide on a list of start paths. An empty list means the current folder.
     * @param string[] $paths
     * @param array<string,mixed> $ctx
     */
    private static function evaluate(array $paths, bool $allowAncestor, string $cwd, array $ctx, string $cmd): ?array
    {
        if ($paths === []) {
            $paths = [$cwd === '' ? '/' : $cwd];
        }
        $rules = self::rules();
        foreach ($paths as $raw) {
            $path = self::normalizePath($raw, $cwd);
            $hit = self::classify($path, $ctx);
            if ($hit !== null) {
                return self::refusal($hit['kind'], $hit['root'], $path, $hit['poolPath'], $cmd);
            }
            if (!$allowAncestor && in_array($path, (array)($rules['ancestors'] ?? []), true)) {
                $fixed = (array)($rules['fixedRoots'] ?? []);
                return self::refusal('ancestor', (string)($fixed[0] ?? '/mnt/user'), $path, null, $cmd);
            }
        }
        return null;
    }

    /**
     * Is the path equal to or under a guarded root? Returns the kind, the root and the pool path.
     * @param array<string,mixed> $ctx
     * @return array{kind:string,root:string,poolPath:?string}|null
     */
    private static function classify(string $path, array $ctx): ?array
    {
        $roots = [];
        foreach ((array)($ctx['roots'] ?? []) as $r => $t) {
            $roots[rtrim((string)$r, '/')] = (string)$t;
        }
        foreach ((array)(self::rules()['fixedRoots'] ?? []) as $r) {
            if (!isset($roots[$r])) {
                $roots[$r] = 'fixed';
            }
        }
        $best = null;
        foreach ($roots as $r => $t) {
            if ($r === '' || $r === '/') {
                continue;
            }
            if (($path === $r || strncmp($path, $r . '/', strlen($r) + 1) === 0) && ($best === null || strlen($r) > strlen($best))) {
                $best = $r;
            }
        }
        if ($best === null) {
            return null;
        }
        // A mount of a safe type STRICTLY inside a guarded root (a pool folder bound over a share path by the helper
        // view) is not guarded. Same rule as the shell wrapper (AICLI_GUARD_ALLOW).
        foreach ((array)($ctx['allow'] ?? []) as $m) {
            $m = rtrim((string)$m, '/');
            if (strlen($m) > strlen($best) && ($path === $m || strncmp($path, $m . '/', strlen($m) + 1) === 0)) return null;
        }
        $type = $roots[$best];
        $network = in_array($type, self::NETWORK_TYPES, true)
            || $best === '/mnt/remotes' || strncmp($best, '/mnt/remotes/', 13) === 0;
        if ($network) {
            return ['kind' => 'network', 'root' => $best, 'poolPath' => null];
        }
        $rest = ltrim(substr($path, strlen($best)), '/');
        if ($rest === '') {
            return ['kind' => 'array', 'root' => $best, 'poolPath' => null];
        }
        $parts = explode('/', $rest, 2);
        $pool = ((array)($ctx['pools'] ?? []))[$parts[0]] ?? null;
        if (is_string($pool) && $pool !== '') {
            return ['kind' => 'pool', 'root' => $best, 'poolPath' => rtrim($pool, '/') . (isset($parts[1]) ? '/' . $parts[1] : '')];
        }
        return ['kind' => 'array', 'root' => $best, 'poolPath' => null];
    }

    /** @return array{kind:string,root:string,path:string,poolPath:?string,text:string} */
    private static function refusal(string $kind, string $root, string $path, ?string $pool, string $cmd): array
    {
        $texts = (array)(self::rules()['texts'] ?? []);
        $tpl = (string)($texts[$kind] ?? $texts['array'] ?? 'aicli guard: refused {cmd}.');
        $text = strtr($tpl, ['{cmd}' => $cmd, '{root}' => $root, '{pool}' => (string)$pool, '{path}' => $path]);
        return ['kind' => $kind, 'root' => $root, 'path' => $path, 'poolPath' => $pool, 'text' => $text];
    }

    /**
     * Generic option parser. Short letters in $shortVal take a value (rest of the cluster or the next
     * argument). Long names in $longVal take a value after a space. Everything else is a flag.
     * @param string[] $a
     * @param string[] $longVal
     * @return array{flags:array<string,bool>,vals:array<string,string[]>,pos:string[]}
     */
    private static function parseOpts(array $a, string $shortVal, array $longVal): array
    {
        $flags = [];
        $vals = [];
        $pos = [];
        $end = false;
        $n = count($a);
        for ($i = 1; $i < $n; $i++) {
            $t = $a[$i];
            if ($end || $t === '' || $t === '-' || $t[0] !== '-') {
                $pos[] = $t;
                continue;
            }
            if ($t === '--') {
                $end = true;
                continue;
            }
            if ($t[1] === '-') {
                $body = substr($t, 2);
                $eq = strpos($body, '=');
                if ($eq !== false) {
                    $name = substr($body, 0, $eq);
                    $vals[$name][] = substr($body, $eq + 1);
                } else {
                    $name = $body;
                    if (in_array($name, $longVal, true)) {
                        $vals[$name][] = $a[++$i] ?? '';
                    }
                }
                $flags[$name] = true;
                continue;
            }
            $len = strlen($t);
            for ($j = 1; $j < $len; $j++) {
                $c = $t[$j];
                $flags['-' . $c] = true;
                if (strpos($shortVal, $c) !== false) {
                    $rest = substr($t, $j + 1);
                    $vals['-' . $c][] = $rest !== '' ? $rest : ($a[++$i] ?? '');
                    break;
                }
            }
        }
        return ['flags' => $flags, 'vals' => $vals, 'pos' => $pos];
    }

    /**
     * Split shell text into simple commands. Each command is a list of tokens (text, quoted flag).
     * @return array<int,array<int,array{0:string,1:bool}>>
     */
    private static function splitShell(string $s): array
    {
        $segments = [];
        // The closures below change these by reference; the @var types tell PHPStan they are not constants.
        /** @var list<array{0:string,1:bool}> $seg */
        $seg = [];
        $cur = '';
        /** @var bool $has */
        $has = false;
        /** @var bool $quoted */
        $quoted = false;
        $heredocs = [];   // delimiters waiting for the next newline
        $n = strlen($s);
        $endTok = function () use (&$seg, &$cur, &$has, &$quoted): void {
            if ($has) {
                $seg[] = [$cur, $quoted];
            }
            $cur = '';
            $has = false;
            $quoted = false;
        };
        $endSeg = function () use (&$segments, &$seg): void {
            if ($seg !== []) {
                $segments[] = $seg;
            }
            $seg = [];
        };
        for ($i = 0; $i < $n; $i++) {
            $c = $s[$i];
            if ($c === "'") {
                $j = strpos($s, "'", $i + 1);
                if ($j === false) {
                    $j = $n;
                }
                $cur .= substr($s, $i + 1, $j - $i - 1);
                $has = true;
                $quoted = true;
                $i = $j;
            } elseif ($c === '"') {
                $has = true;
                $quoted = true;
                for ($i++; $i < $n && $s[$i] !== '"'; $i++) {
                    if ($s[$i] === '\\' && $i + 1 < $n && strpos('"\\$`', $s[$i + 1]) !== false) {
                        $i++;
                    }
                    $cur .= $s[$i];
                }
            } elseif ($c === '\\') {
                if ($i + 1 < $n) {
                    $i++;
                    if ($s[$i] !== "\n") {
                        $cur .= $s[$i];
                        $has = true;
                    }
                }
            } elseif ($c === ' ' || $c === "\t" || $c === "\r") {
                $endTok();
            } elseif ($c === "\n") {
                $endTok();
                $endSeg();
                if ($heredocs !== []) {
                    foreach ($heredocs as [$delim, $strip]) {
                        while ($i + 1 < $n) {
                            $e = strpos($s, "\n", $i + 1);
                            $line = $e === false ? substr($s, $i + 1) : substr($s, $i + 1, $e - $i - 1);
                            $i = $e === false ? $n : $e;
                            if (($strip ? trim($line) : rtrim($line, "\r")) === $delim) {
                                break;
                            }
                        }
                    }
                    $heredocs = [];
                }
            } elseif ($c === ';' || $c === '&' || $c === '|' || $c === '(' || $c === ')' || $c === '`') {
                $endTok();
                $endSeg();
            } elseif ($c === '#' && !$has) {
                $e = strpos($s, "\n", $i);
                $i = $e === false ? $n : $e - 1;
            } elseif ($c === '<' && !$has && substr($s, $i, 2) === '<<' && substr($s, $i, 3) !== '<<<') {
                $endTok();
                $k = $i + 2;
                $strip = false;
                if (($s[$k] ?? '') === '-') {
                    $strip = true;
                    $k++;
                }
                while (($s[$k] ?? '') === ' ' || ($s[$k] ?? '') === "\t") {
                    $k++;
                }
                $d = '';
                for (; $k < $n && strpos(" \t\n;&|()<>", $s[$k]) === false; $k++) {
                    if ($s[$k] !== "'" && $s[$k] !== '"' && $s[$k] !== '\\') {
                        $d .= $s[$k];
                    }
                }
                if ($d !== '') {
                    $heredocs[] = [$d, $strip];
                }
                $i = $k - 1;
            } else {
                $cur .= $c;
                $has = true;
            }
        }
        $endTok();
        $endSeg();
        return $segments;
    }

    /**
     * Check one simple command. May change $cwd (cd).
     * @param array<int,array{0:string,1:bool}> $tokens
     * @param array<string,mixed> $ctx
     */
    private static function checkSimple(array $tokens, string &$cwd, array $ctx, int $depth): ?array
    {
        // Drop redirections (an unquoted token that starts with a digit run and < or >).
        $t = [];
        for ($k = 0, $n = count($tokens); $k < $n; $k++) {
            [$text, $q] = $tokens[$k];
            if (!$q && preg_match('/^[0-9]*(?:>>?|<)/', $text) === 1) {
                if (preg_match('/^[0-9]*(?:>>?|<)$/D', $text) === 1) {
                    $k++;
                }
                continue;
            }
            $t[] = $text;
        }
        $i = 0;
        $n = count($t);
        while ($i < $n) {
            $w = $t[$i];
            $base = basename($w);
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*=/', $w) === 1 || in_array($w, self::KEYWORDS, true)) {
                $i++;
                continue;
            }
            switch ($base) {
                case 'sudo':
                    $i++;
                    while ($i < $n && $t[$i] !== '' && $t[$i][0] === '-') {
                        $i += in_array($t[$i], ['-u', '-g', '-C', '-h', '-p', '-r', '-t', '-U', '-D', '-T'], true) ? 2 : 1;
                    }
                    continue 2;
                case 'env':
                    $i++;
                    while ($i < $n && ($t[$i] === '' ? false : ($t[$i][0] === '-' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*=/', $t[$i]) === 1))) {
                        $i += $t[$i] === '-u' ? 2 : 1;
                    }
                    continue 2;
                case 'timeout':
                    $i++;
                    while ($i < $n && $t[$i] !== '' && $t[$i][0] === '-') {
                        $i += in_array($t[$i], ['-k', '-s'], true) ? 2 : 1;
                    }
                    $i++;
                    continue 2;
                case 'nice':
                    $i++;
                    while ($i < $n && $t[$i] !== '' && $t[$i][0] === '-') {
                        $i += $t[$i] === '-n' ? 2 : 1;
                    }
                    continue 2;
                case 'xargs':
                    $i++;
                    while ($i < $n && $t[$i] !== '' && $t[$i][0] === '-') {
                        $i += in_array($t[$i], ['-n', '-I', '-P', '-d', '-L', '-s', '-E', '-a', '-J'], true) ? 2 : 1;
                    }
                    continue 2;
                case 'nohup':
                case 'exec':
                case 'builtin':
                    $i++;
                    continue 2;
                case 'command':
                    $i++;
                    while ($i < $n && $t[$i] !== '' && $t[$i][0] === '-') {
                        $i++;
                    }
                    continue 2;
            }
            break;
        }
        if ($i >= $n) {
            return null;
        }
        $argv = array_slice($t, $i);
        $base = basename($argv[0]);
        if ($base === 'cd' || $base === 'pushd') {
            $d = $argv[1] ?? '';
            if ($d !== '' && $d !== '-' && $d[0] !== '~' && $d[0] !== '$') {
                $cwd = self::normalizePath($d, $cwd);
            }
            return null;
        }
        if (in_array($base, self::SHELLS, true)) {
            for ($k = 1, $m = count($argv); $k < $m; $k++) {
                if ($argv[$k] !== '' && $argv[$k][0] === '-' && ($argv[$k][1] ?? '') !== '-' && strpos($argv[$k], 'c') !== false) {
                    return isset($argv[$k + 1]) ? self::checkCommand($argv[$k + 1], $cwd, $ctx, $depth + 1) : null;
                }
            }
            return null;
        }
        if (in_array($base, (array)(self::rules()['commands'] ?? []), true)) {
            return self::checkArgv($argv, $cwd, $ctx);
        }
        return null;
    }
}
