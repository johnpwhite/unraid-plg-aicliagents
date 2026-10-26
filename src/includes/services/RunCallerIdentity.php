<?php
/**
 * <module_context>
 *     <name>RunCallerIdentity</name>
 *     <description>Finds the script that called `aicli-run`, from the kernel's process list and not from what the
 *     caller says (docs/specs/AGENT_RUN_ENTRY_POINT.md R6, R7, R8, issue #745). It starts at the pid of the CLI and
 *     walks up the parent chain in /proc (at most 12 levels). It skips only the pass-through programs (`sudo`,
 *     `timeout` and the like). The first other level is the decision level: it is the caller script when it is an
 *     interpreter that runs a script FILE, and the caller is "interactive" in every other case. The walk never
 *     climbs past the decision level, so `bash -c`, a shell that reads its script from standard input, `python3 -c`
 *     and a plain program that an authorised script started are never taken for that script. The hash is taken from
 *     the file that the process has open (`/proc/<pid>/fd/255` for bash), so a script that is replaced after the
 *     start is hashed as it was read.</description>
 *     <dependencies>None.</dependencies>
 *     <constraints>Read only. Never throws. A process that exited, a missing file or a denied read ends the look at
 *     that level, never the walk. Anything unclear fails closed ("interactive"). A process that runs as root can fake
 *     its name, its parent chain and its open files (R12): this class guards against a stray or changed script, not
 *     against a hostile root process. $procRoot is a test seam that works only under PHPUnit.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

final class RunCallerIdentity
{
    /** The most levels of the parent chain that the walk looks at (R6). */
    public const MAX_LEVELS = 12;

    /** The largest script file that the plugin hashes, in bytes. A larger file is treated as "no script". */
    public const MAX_SCRIPT_BYTES = 8 * 1024 * 1024;

    /** Shells. bash keeps the script it runs open on file 255 and has no other way (fail closed). */
    private const BASH = '/^bash$/D';
    /** Other shells and interpreters: the first word after the program must be the script file, with no option. */
    private const INTERPRETER = '/^(sh|dash|ash|zsh|ksh|php\d*|python\d*|perl|node)$/D';

    /** Programs that only pass a call on. They are in the chain, and they are never the caller script (R6). */
    private const PASS_THROUGH = ['cron', 'crond', 'runuser', 'su', 'sudo', 'timeout', 'nohup', 'setsid', 'flock', 'env', 'nice', 'ionice'];

    /**
     * The folder of the copies that the User Scripts plugin runs (R7). Checked on prod02 on 2026-10-26
     * (user.scripts startSchedule.php): it copies <originals>/<name>/script to <copies>/<name>/script,
     * adds "#!/bin/bash" when the text has no "#!" line, removes every "\r", and runs the copy.
     */
    private const USER_SCRIPTS_ROOT = '/tmp/user.scripts/tmpScripts';

    /** The folder of the User Scripts originals: the path a person authorises on Settings, Script runs (#778). */
    private const USER_SCRIPTS_ORIGINALS = '/boot/config/plugins/user.scripts/scripts';

    /** Test seam: replaces the folder of the User Scripts copies. Works only under PHPUnit. */
    public static ?string $userScriptsRoot = null;

    /** Test seam: replaces the folder of the User Scripts originals. Works only under PHPUnit. */
    public static ?string $userScriptsOriginals = null;

    public static function originalsRoot(): string
    {
        return rtrim(self::underTest() && self::$userScriptsOriginals !== null ? self::$userScriptsOriginals : self::USER_SCRIPTS_ORIGINALS, '/');
    }

    /** The folder of the temporary copies that User Scripts runs. */
    public static function copiesRoot(): string
    {
        return rtrim(self::underTest() && self::$userScriptsRoot !== null ? self::$userScriptsRoot : self::USER_SCRIPTS_ROOT, '/');
    }

    /** True when $path is the original of a User Script: <originals>/<name>/script (#778). */
    public static function isUserScriptsOriginal(string $path): bool
    {
        return preg_match('#^' . preg_quote(self::originalsRoot(), '#') . '/[^/]+/script$#D', $path) === 1;
    }

    /** The User Scripts folder name for an original or temporary copy path, or an empty string. */
    public static function userScriptNameFromPath(string $path): string
    {
        foreach ([self::originalsRoot(), self::copiesRoot()] as $root) {
            if (preg_match('#^' . preg_quote($root, '#') . '/([^/]+)/script$#D', $path, $m) === 1) return $m[1];
        }
        return '';
    }

    /** True when a path names the temporary copy that User Scripts makes for a run. */
    public static function isUserScriptsCopy(string $path): bool
    {
        return preg_match('#^' . preg_quote(self::copiesRoot(), '#') . '/[^/]+/script$#D', $path) === 1;
    }

    /** The original path for a temporary copy, or an empty string. */
    public static function originalForUserScriptsCopy(string $path): string
    {
        $name = self::isUserScriptsCopy($path) ? self::userScriptNameFromPath($path) : '';
        return $name === '' ? '' : self::originalsRoot() . '/' . $name . '/script';
    }

    /** The text of the copy that User Scripts makes from the text of an original (#778). */
    public static function userScriptsCopyText(string $original): string
    {
        $text = strncmp($original, '#!', 2) === 0 ? $original : "#!/bin/bash\n" . $original;
        return str_replace("\r", '', $text);
    }

    /** Test seam: the folder that stands in for /proc. null means /proc. Works only under PHPUnit. */
    public static ?string $procRoot = null;

    /** The seams count only when the PHPUnit bootstrap ran. In production a stray value is ignored. */
    private static function underTest(): bool
    {
        return defined('AICLI_TEST_TMP');
    }

    private static function root(): string
    {
        return rtrim(self::underTest() && self::$procRoot !== null ? self::$procRoot : '/proc', '/');
    }

    /**
     * Who called? $startPid is the pid of the CLI. $selfEnv is the environment of the CLI (null reads getenv()).
     *
     * @param array<string,string>|null $selfEnv
     * @return array{kind:string,path:string,sha256:string,userScript:string,copyPath:string,uid:int,chain:list<array<string,mixed>>,insideWorkspace:bool}
     *         kind is "script" or "interactive". For "interactive", path and sha256 are empty strings.
     */
    public static function identify(int $startPid, ?array $selfEnv = null): array
    {
        $chain = [];
        $decision = null; // [pid, name, cmd]
        $inside = ((string)(($selfEnv ?? self::getenvAll())['AICLI_SESSION_ID'] ?? '')) !== '';
        $pid = $startPid;
        $uid = self::uidOf($startPid);
        $childStart = self::startTime($startPid);
        $reused = false;
        for ($level = 0; $level < self::MAX_LEVELS && $pid > 0; $level++) {
            $cmd = self::cmdline($pid);
            $name = self::programName($pid, $cmd);
            $chain[] = ['pid' => $pid, 'name' => $name];
            if ($level > 0) {
                if (self::envOfHasSession($pid)) $inside = true;
                // The first level above the CLI that is not a pass-through program decides who the caller is.
                if ($decision === null && !in_array($name, self::PASS_THROUGH, true)) $decision = [$pid, $name, $cmd];
            }
            $parent = self::parentOf($pid);
            if ($parent <= 0 || $parent === $pid) break;
            // A parent cannot start after its child. If it did, the pid was reused: end the walk, fail closed.
            $parentStart = self::startTime($parent);
            if ($childStart !== null && $parentStart !== null && $parentStart > $childStart) {
                $reused = true;
                $chain[] = ['pid' => $parent, 'name' => '(pid reused)'];
                break;
            }
            $childStart = $parentStart;
            $pid = $parent;
        }

        $out = ['kind' => 'interactive', 'path' => '', 'sha256' => '', 'userScript' => '', 'copyPath' => '', 'uid' => $uid, 'chain' => $chain, 'insideWorkspace' => $inside];
        if ($decision === null || $reused) return $out;
        $script = self::scriptOf($decision[0], $decision[1], $decision[2]);
        if ($script === null) return $out;
        $hash = self::hashOpenFile($script['hashPath']);
        if ($hash === null) return $out;
        $userScript = '';
        $path = $script['path'];
        $quoted = preg_quote(self::copiesRoot(), '#');
        $copyPath = '';
        if (preg_match('#^' . $quoted . '/([^/]+)/script$#D', $script['rawPath'], $m) === 1) {
            // R7 (#778): the caller is the original that a person authorises. The copy path stays in the
            // record. The hash stays the hash of the copy that bash has open.
            $userScript = $m[1];
            $copyPath = $script['rawPath'];
            $path = self::originalsRoot() . '/' . $m[1] . '/script';
        }
        foreach ($chain as $i => $item) {
            if ($item['pid'] === $decision[0]) $chain[$i]['script'] = $copyPath !== '' ? $copyPath : $path;
        }
        $out['copyPath'] = $copyPath;
        $out['kind'] = 'script';
        $out['path'] = $path;
        $out['sha256'] = $hash;
        $out['userScript'] = $userScript;
        $out['chain'] = $chain;
        return $out;
    }

    /** A short line for the tray: "aicli-run <- bash script.sh <- cron". */
    public static function chainText(array $chain): string
    {
        $parts = [];
        foreach ($chain as $item) {
            $text = (string)($item['name'] ?? '?');
            if (!empty($item['script'])) $text .= ' ' . (string)$item['script'];
            $parts[] = $text . ' (' . (int)($item['pid'] ?? 0) . ')';
        }
        return implode(' <- ', $parts);
    }

    // ---- The script of the decision level. Anything unclear gives null ("interactive"). ----

    /**
     * @param list<string> $cmd
     * @return array{path:string,rawPath:string,hashPath:string}|null
     *         path: the resolved file. rawPath: the name the process used. hashPath: what to hash (the open file when there is one).
     */
    private static function scriptOf(int $pid, string $name, array $cmd): ?array
    {
        // bash keeps its script open on file 255. On Unraid `sh` is bash, so a script that starts with #!/bin/sh has the
        // same open file. When a process of either name has such a file, that file is the script and the name on the
        // command line is not used. bash with no such file (bash -c, standard input, a terminal) has no script.
        if (preg_match(self::BASH, $name) === 1 || $name === 'sh') {
            $link = self::root() . "/$pid/fd/255";
            $target = @readlink($link);
            $usable = is_string($target) && $target !== '' && $target[0] === '/' && preg_match('/ \(deleted\)$/D', $target) !== 1 && is_file($link);
            if ($usable) {
                $real = @realpath($target);
                if ($real === false) return null;
                return ['path' => $real, 'rawPath' => $target, 'hashPath' => $link];
            }
            if (preg_match(self::BASH, $name) === 1) return null;
        }
        if (preg_match(self::INTERPRETER, $name) !== 1) return null;
        // `interpreter script ...`: the word after the program is the script. An option there (-c, -s, -e, -d, -W ...)
        // is not understood, so it fails closed.
        $word = $cmd[1] ?? '';
        if ($word === '' || $word[0] === '-') return null;
        $path = $word;
        if ($word[0] !== '/') {
            $cwd = @readlink(self::root() . "/$pid/cwd");
            if (!is_string($cwd) || $cwd === '' || $cwd[0] !== '/') return null;
            $path = rtrim($cwd, '/') . '/' . $word;
        }
        if (!is_file($path)) return null;
        $real = @realpath($path);
        if ($real === false) return null;
        return ['path' => $real, 'rawPath' => $path, 'hashPath' => $real];
    }

    /** The sha256 of a file by reading the path that was given (an open file for bash). Null on a fault or a big file. */
    private static function hashOpenFile(string $path): ?string
    {
        $size = @filesize($path);
        if ($size === false || $size > self::MAX_SCRIPT_BYTES) return null;
        $hash = @hash_file('sha256', $path);
        return is_string($hash) ? $hash : null;
    }

    // ---- /proc readers. Each one returns an empty answer on a fault. ----

    private static function parentOf(int $pid): int
    {
        $status = @file_get_contents(self::root() . "/$pid/status");
        if (!is_string($status) || preg_match('/^PPid:\s*(\d+)/m', $status, $m) !== 1) return 0;
        return (int)$m[1];
    }

    private static function uidOf(int $pid): int
    {
        $status = @file_get_contents(self::root() . "/$pid/status");
        if (!is_string($status) || preg_match('/^Uid:\s*(\d+)/m', $status, $m) !== 1) return -1;
        return (int)$m[1];
    }

    /** The start time of a process (field 22 of /proc/<pid>/stat, in ticks after boot), or null when it is not known. */
    private static function startTime(int $pid): ?int
    {
        $stat = @file_get_contents(self::root() . "/$pid/stat");
        if (!is_string($stat)) return null;
        $close = strrpos($stat, ')');
        if ($close === false) return null;
        $fields = preg_split('/\s+/', trim(substr($stat, $close + 1))) ?: [];
        // After the name the fields start at number 3, so field 22 is the index 19.
        return isset($fields[19]) && ctype_digit($fields[19]) ? (int)$fields[19] : null;
    }

    /** @return list<string> The words of the command line. */
    private static function cmdline(int $pid): array
    {
        $raw = @file_get_contents(self::root() . "/$pid/cmdline");
        if (!is_string($raw) || $raw === '') return [];
        $words = explode("\0", rtrim($raw, "\0"));
        return array_values(array_map('strval', $words));
    }

    /** The program name: the base name of the first word without a login dash, else the comm file. */
    private static function programName(int $pid, array $cmd): string
    {
        $first = $cmd[0] ?? '';
        if ($first !== '') return ltrim(basename($first), '-');
        $comm = @file_get_contents(self::root() . "/$pid/comm");
        return is_string($comm) ? trim($comm) : '';
    }

    private static function envOfHasSession(int $pid): bool
    {
        $environ = @file_get_contents(self::root() . "/$pid/environ");
        if (!is_string($environ)) return false;
        foreach (explode("\0", $environ) as $entry) {
            if (strpos($entry, 'AICLI_SESSION_ID=') === 0 && $entry !== 'AICLI_SESSION_ID=') return true;
        }
        return false;
    }

    /** @return array<string,string> */
    private static function getenvAll(): array
    {
        $env = getenv();
        return is_array($env) ? $env : [];
    }
}
