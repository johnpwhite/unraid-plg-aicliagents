<?php
/**
 * <module_context>
 *     <name>TmuxDuplicateService</name>
 *     <description>Finds plugin tmux servers that the plugin cannot reach: a second
 *     server for a workspace (an "unreachable copy") or a server with no socket
 *     path. Shows the screen of such a server and stops it on the operator's
 *     click, and never touches the reachable server of the workspace.
 *     2026-09-29, see docs/specs/DRAWER_ACTIVE_STATE_RECONCILE.md,
 *     "2026-09-29: unreachable copies".</description>
 *     <dependencies>SessionLaunchLock, TmuxSocketRecovery, WorkspaceScreenService (mask),
 *     TmuxService (plainPaneText), ProcessManager (TMUX_ROOT).</dependencies>
 *     <constraints>scan() reads /proc only (no tmux call, no process spawn in
 *     the normal case) because the drawer polls it every 30 s. It runs `ss`
 *     once only when two listening sockets carry the same path. view() and
 *     stop() are drawer actions (CSRF, an operator's click); they are NOT
 *     admin or Relay tools.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

final class TmuxDuplicateService
{
    /** Session name the plugin gives every agent tmux session. */
    private const NAME_RE = '/^aicli-agent-([A-Za-z0-9_-]+)$/';
    /** The run script the plugin starts in each session: aicli-run-<id>.sh */
    private const RUN_RE = '/aicli-run-([A-Za-z0-9_-]+)\.sh$/';
    /** The path that tmux binds when it runs with TMUX_TMPDIR or /tmp. */
    private const BOUND_RE = '#/tmux-\d+/default$#';
    private const ID_RE = '/^[A-Za-z0-9_-]{1,64}$/';
    /** __SO_ACCEPTCON: the flag of a listening socket in /proc/net/unix. */
    private const SO_ACCEPTCON = 0x10000;
    private const MAX_LINES = 200;
    private const MAX_BYTES = 32768;

    // ---- Test seams --------------------------------------------------------
    /** Root of the proc tree (tests give a fake tree in a temp folder). */
    public static ?string $procRoot = null;
    /** Root of the plugin tmux folders (ProcessManager::TMUX_ROOT). */
    public static ?string $tmuxRoot = null;
    /** fn(string $path): ?int — file inode of the path, null when it is gone. */
    public static $statIno = null;
    /** fn(): array<int,int> — listening socket inode => file (vfs) inode (default: `ss -xlne`). */
    public static $vfsProbe = null;
    /** fn(list<string> $args): array{0:int,1:string} — run tmux (default: the tmux on PATH). */
    public static $tmuxRunner = null;
    /** fn(int $pid, int $sig): bool */
    public static $signal = null;
    /** fn(int $pid): bool */
    public static $alive = null;
    /** fn(int $us): void */
    public static $sleep = null;
    /** fn(string $path): void — remove a dead socket file after a stop. */
    public static $cleanup = null;
    /** Operations for TmuxSocketRecovery::recover() in tests. */
    public static array $recoveryOps = [];

    public static function resetSeams(): void
    {
        self::$procRoot = null;
        self::$tmuxRoot = null;
        self::$statIno = null;
        self::$vfsProbe = null;
        self::$tmuxRunner = null;
        self::$signal = null;
        self::$alive = null;
        self::$sleep = null;
        self::$cleanup = null;
        self::$recoveryOps = [];
    }

    private static function proc(): string
    {
        return rtrim(self::$procRoot ?? '/proc', '/');
    }

    private static function tmuxRoot(): string
    {
        return rtrim(self::$tmuxRoot ?? ProcessManager::TMUX_ROOT, '/');
    }

    // ---- Detection ---------------------------------------------------------

    /**
     * Every plugin tmux server that the plugin cannot reach through the
     * session's own socket. One row for each server:
     *   sessionId, agentId, serverPid, agentPid, chatId, startedAt,
     *   path         the socket path the server bound (from /proc/net/unix),
     *   reachablePath a path where the server still answers ('' = none),
     *   recoveryPath  an earlier recovery socket of this server ('' = none),
     *   reason        'duplicate' (another server owns the session's socket)
     *                 or 'unreachable' (no server of the id owns it).
     *
     * A server that owns the session's own socket path is never reported.
     * One server for a session id that owns its path = nothing reported.
     *
     * @return list<array<string,mixed>>
     */
    public static function scan(): array
    {
        try {
            return self::scanInner();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** @return list<array<string,mixed>> */
    private static function scanInner(): array
    {
        $servers = self::pluginServers();
        if ($servers === []) return [];

        $listeners = self::listeners();
        $vfs = null;   // lazy: `ss` runs only when two listeners share a path
        $owner = [];   // path => listening socket inode that owns the file (0 = none)
        $ownerOf = function (string $path) use (&$owner, &$vfs, $listeners): int {
            if (array_key_exists($path, $owner)) return $owner[$path];
            $cands = $listeners['byPath'][$path] ?? [];
            $ino = self::statIno($path);
            if ($ino === null || $cands === []) return $owner[$path] = 0;
            if (count($cands) === 1) return $owner[$path] = $cands[0];
            if ($vfs === null) $vfs = self::vfsMap();
            foreach ($cands as $c) {
                if (isset($vfs[$c]) && $vfs[$c] === $ino) return $owner[$path] = $c;
            }
            if ($vfs === []) {
                // No `ss`: the newest bind has the highest socket inode.
                return $owner[$path] = max($cands);
            }
            return $owner[$path] = 0;
        };

        $root = self::tmuxRoot();
        $bySession = [];
        foreach ($servers as $pid => $s) {
            $bound = '';
            $owns = false;
            foreach ($s['sockets'] as $ino) {
                $p = $listeners['byInode'][$ino] ?? '';
                if ($p === '' || !preg_match(self::BOUND_RE, $p)) continue;
                $bound = $p;
                if ($ownerOf($p) === $ino) { $owns = true; break; }
            }
            $s['path'] = $bound;
            $s['owns'] = $owns;
            $s['primary'] = $owns && strncmp($bound, $root . '/s-' . $s['sessionId'] . '/', strlen($root . '/s-' . $s['sessionId'] . '/')) === 0;
            $s['legacy'] = $owns && !$s['primary'] && strncmp($bound, $root . '/tmux-', strlen($root . '/tmux-')) === 0;
            $bySession[$s['sessionId']][$pid] = $s;
        }

        $report = [];
        foreach ($bySession as $sid => $group) {
            $hasPrimary = false;
            foreach ($group as $s) { if ($s['primary']) { $hasPrimary = true; break; } }
            foreach ($group as $pid => $s) {
                if ($s['primary']) continue;
                // A single server on the old shared socket folder is reachable.
                if ($s['legacy'] && !$hasPrimary) continue;
                $recovery = self::recoveryPathFor((string)$sid, (int)$pid);
                $report[] = [
                    'sessionId' => (string)$sid,
                    'agentId' => $s['agentId'],
                    'serverPid' => (int)$pid,
                    'agentPid' => 0,
                    'chatId' => '',
                    'startedAt' => $s['startedAt'],
                    'path' => $s['path'],
                    'reachablePath' => $s['owns'] ? $s['path'] : '',
                    'recoveryPath' => self::statIno($recovery) !== null ? $recovery : '',
                    'reason' => $hasPrimary ? 'duplicate' : 'unreachable',
                ];
            }
        }
        if ($report === []) return [];

        // Rare path only: find the agent under each reported server.
        $tree = self::processTree();
        foreach ($report as &$r) {
            [$r['agentPid'], $r['chatId']] = self::agentUnder((int)$r['serverPid'], $tree);
        }
        unset($r);
        usort($report, static fn($a, $b) => [$a['sessionId'], $a['serverPid']] <=> [$b['sessionId'], $b['serverPid']]);
        return $report;
    }

    /**
     * Plugin tmux servers: comm "tmux: server" and a command line with
     * `new-session ... -s aicli-agent-<agentId>-<sessionId>`.
     *
     * @return array<int,array{sessionId:string,agentId:string,name:string,sockets:list<int>,startedAt:int}>
     */
    private static function pluginServers(): array
    {
        $proc = self::proc();
        $out = [];
        $btime = null;
        foreach (@scandir($proc) ?: [] as $e) {
            if (!ctype_digit((string)$e)) continue;
            $comm = @file_get_contents("$proc/$e/comm");
            if ($comm === false || trim($comm) !== 'tmux: server') continue;
            $args = self::cmdline((int)$e);
            $parsed = self::parseServerCmdline($args);
            if ($parsed === null) continue;
            $sockets = [];
            foreach (@scandir("$proc/$e/fd") ?: [] as $fd) {
                if (!ctype_digit((string)$fd)) continue;
                $l = @readlink("$proc/$e/fd/$fd");
                if (is_string($l) && preg_match('/^socket:\[(\d+)\]$/', $l, $m)) $sockets[] = (int)$m[1];
            }
            if ($btime === null) $btime = self::bootTime();
            $parsed['sockets'] = $sockets;
            $parsed['startedAt'] = self::startTime((int)$e, $btime);
            $out[(int)$e] = $parsed;
        }
        return $out;
    }

    /**
     * @param list<string> $args
     * @return array{sessionId:string,agentId:string,name:string}|null
     */
    public static function parseServerCmdline(array $args): ?array
    {
        if ($args === [] || !in_array('new-session', $args, true)) return null;
        $name = '';
        for ($i = 0; $i < count($args) - 1; $i++) {
            if ($args[$i] === '-s') { $name = $args[$i + 1]; break; }
        }
        if (!preg_match(self::NAME_RE, $name, $m)) return null;
        $rest = $m[1];
        $sid = '';
        foreach (array_reverse($args) as $a) {
            if (preg_match(self::RUN_RE, $a, $rm)) { $sid = $rm[1]; break; }
        }
        if ($sid !== '' && substr($rest, -strlen('-' . $sid)) === '-' . $sid) {
            $agent = substr($rest, 0, -strlen('-' . $sid));
        } else {
            $cut = strrpos($rest, '-');
            if ($cut === false) return null;
            $agent = substr($rest, 0, $cut);
            $sid = substr($rest, $cut + 1);
        }
        if ($agent === '' || !preg_match(self::ID_RE, $sid)) return null;
        return ['sessionId' => $sid, 'agentId' => $agent, 'name' => $name];
    }

    /**
     * Listening unix sockets with a path, from /proc/net/unix.
     *
     * @return array{byInode:array<int,string>,byPath:array<string,list<int>>}
     */
    private static function listeners(): array
    {
        $byInode = [];
        $byPath = [];
        $raw = (string)@file_get_contents(self::proc() . '/net/unix');
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $f = preg_split('/\s+/', trim($line));
            // Num RefCount Protocol Flags Type St Inode Path
            if (!is_array($f) || count($f) < 8 || !ctype_xdigit($f[3])) continue;
            if ((hexdec($f[3]) & self::SO_ACCEPTCON) === 0) continue;
            $ino = (int)$f[6];
            $path = implode(' ', array_slice($f, 7));
            if ($ino <= 0 || $path === '' || $path[0] !== '/') continue;
            $byInode[$ino] = $path;
            $byPath[$path][] = $ino;
        }
        return ['byInode' => $byInode, 'byPath' => $byPath];
    }

    /** @return array<int,int> listening socket inode => file inode; [] when `ss` is missing. */
    private static function vfsMap(): array
    {
        if (is_callable(self::$vfsProbe)) return (array)call_user_func(self::$vfsProbe);
        // nosemgrep: php.lang.security.exec-use.exec-use
        $raw = (string)@shell_exec('ss -xlne 2>/dev/null');
        return self::parseSsVfs($raw);
    }

    /**
     * `ss -xlne`: "u_str LISTEN 0 128 <path> <sockIno> * 0 <-> ino:<vfsIno> dev:..."
     *
     * @return array<int,int>
     */
    public static function parseSsVfs(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            if (preg_match('#\s(/\S+)\s+(\d+)\s.*\bino:(\d+)\b#', $line, $m)) {
                $out[(int)$m[2]] = (int)$m[3];
            }
        }
        return $out;
    }

    private static function statIno(string $path): ?int
    {
        if (is_callable(self::$statIno)) {
            $r = call_user_func(self::$statIno, $path);
            return $r === null ? null : (int)$r;
        }
        clearstatcache(true, $path);
        $st = @stat($path);
        return is_array($st) ? (int)$st['ino'] : null;
    }

    /** @return list<string> */
    private static function cmdline(int $pid): array
    {
        $raw = @file_get_contents(self::proc() . "/$pid/cmdline");
        if (!is_string($raw) || $raw === '') return [];
        return array_values(array_filter(explode("\0", $raw), static fn($a) => $a !== ''));
    }

    private static function bootTime(): int
    {
        $raw = (string)@file_get_contents(self::proc() . '/stat');
        return preg_match('/^btime\s+(\d+)/m', $raw, $m) ? (int)$m[1] : 0;
    }

    /**
     * Fields of /proc/<pid>/stat after the "(comm)" part. comm can hold spaces
     * ("tmux: server"), so the split starts after the LAST ')'.
     *
     * @return list<string>
     */
    private static function statFields(int $pid): array
    {
        $raw = (string)@file_get_contents(self::proc() . "/$pid/stat");
        $p = strrpos($raw, ')');
        if ($p === false) return [];
        return preg_split('/\s+/', trim(substr($raw, $p + 1))) ?: [];
    }

    private static function startTime(int $pid, int $btime): int
    {
        $f = self::statFields($pid);
        // After ')': [0]=state [1]=ppid [2]=pgrp ... [19]=starttime (field 22).
        if (!isset($f[19]) || $btime <= 0) return 0;
        return $btime + intdiv((int)$f[19], 100);
    }

    /** @return array<int,array{ppid:int,pgrp:int,state:string}> */
    private static function processTree(): array
    {
        $proc = self::proc();
        $tree = [];
        foreach (@scandir($proc) ?: [] as $e) {
            if (!ctype_digit((string)$e)) continue;
            $f = self::statFields((int)$e);
            if (!isset($f[2])) continue;
            $tree[(int)$e] = ['ppid' => (int)$f[1], 'pgrp' => (int)$f[2], 'state' => (string)$f[0]];
        }
        return $tree;
    }

    /**
     * All descendants of $pid, breadth first.
     *
     * @param array<int,array{ppid:int,pgrp:int,state:string}> $tree
     * @return list<int>
     */
    private static function descendants(int $pid, array $tree): array
    {
        $children = [];
        foreach ($tree as $p => $t) $children[$t['ppid']][] = $p;
        $out = [];
        $queue = $children[$pid] ?? [];
        while ($queue !== []) {
            $p = array_shift($queue);
            $out[] = $p;
            foreach ($children[$p] ?? [] as $c) $queue[] = $c;
        }
        return $out;
    }

    /**
     * The agent under a server: the first descendant that is not a shell, and
     * the conversation id from its `--resume <id>` (or `--session-id <id>`).
     *
     * @param array<int,array{ppid:int,pgrp:int,state:string}> $tree
     * @return array{0:int,1:string}
     */
    private static function agentUnder(int $serverPid, array $tree): array
    {
        $shells = ['bash', 'sh', 'dash', 'zsh', 'sleep', 'tmux', 'timeout', 'env'];
        $agent = 0;
        $chat = '';
        foreach (self::descendants($serverPid, $tree) as $p) {
            $args = self::cmdline($p);
            if ($args === []) continue;
            $base = basename($args[0]);
            if ($agent === 0 && !in_array($base, $shells, true)) $agent = $p;
            if ($chat === '') {
                $chat = self::chatIdFromArgs($args);
            }
            if ($agent !== 0 && $chat !== '') break;
        }
        return [$agent, $chat];
    }

    /** @param list<string> $args */
    public static function chatIdFromArgs(array $args): string
    {
        for ($i = 0; $i < count($args); $i++) {
            $a = $args[$i];
            foreach (['--resume', '--session-id', '--session'] as $flag) {
                if ($a === $flag && isset($args[$i + 1]) && preg_match('/^[A-Za-z0-9._:-]{4,128}$/', $args[$i + 1])) {
                    return $args[$i + 1];
                }
                if (strncmp($a, $flag . '=', strlen($flag) + 1) === 0) {
                    $v = substr($a, strlen($flag) + 1);
                    if (preg_match('/^[A-Za-z0-9._:-]{4,128}$/', $v)) return $v;
                }
            }
        }
        return '';
    }

    /** Where a recovered socket of this server lives. */
    public static function recoveryPathFor(string $sessionId, int $serverPid): string
    {
        $safe = (string)preg_replace('/[^A-Za-z0-9_-]/', '', $sessionId);
        return self::tmuxRoot() . '/s-' . $safe . '/recovered-' . $serverPid . '.sock';
    }

    // ---- Actions (operator's click in the drawer) --------------------------

    /**
     * Find one reported row again, right before an action. Refuses a server
     * that owns the session's own socket: scan() never reports it.
     *
     * @return array<string,mixed>|null
     */
    private static function findTarget(string $sessionId, int $serverPid): ?array
    {
        foreach (self::scan() as $r) {
            if ($r['sessionId'] === $sessionId && (int)$r['serverPid'] === $serverPid) return $r;
        }
        return null;
    }

    /** @return array<string,mixed>|null an error response, or null when the input is valid */
    private static function badInput(string $sessionId, int $serverPid): ?array
    {
        if (!preg_match(self::ID_RE, $sessionId) || $serverPid <= 1) {
            return ['status' => 'error', 'reason' => 'bad_request', 'message' => 'A workspace id and a server process id are necessary.'];
        }
        return null;
    }

    private static function notFound(): array
    {
        return ['status' => 'error', 'reason' => 'not_found',
            'message' => 'This copy is not running any more, or it is the visible workspace itself. Nothing was changed.'];
    }

    private static function busy(): array
    {
        return ['status' => 'busy', 'reason' => 'launch_in_progress',
            'message' => 'This workspace is starting, closing or restarting. Try again in a moment.'];
    }

    /**
     * A socket path where the target server answers now: its own bound path
     * when it still owns it, an earlier recovery socket that is still its, or
     * a new recovery socket (TmuxSocketRecovery). Caller holds the lock.
     *
     * @param array<string,mixed> $t
     * @return array{ok:bool,path?:string,reason?:string}
     */
    private static function reachSocket(array $t): array
    {
        if ((string)$t['reachablePath'] !== '') return ['ok' => true, 'path' => (string)$t['reachablePath']];
        $rec = self::recoveryPathFor((string)$t['sessionId'], (int)$t['serverPid']);
        if ((string)$t['recoveryPath'] !== '') {
            if (self::socketBelongsTo($rec, (int)$t['serverPid'])) return ['ok' => true, 'path' => $rec];
            @unlink($rec);   // stale: the server of that pid made a newer socket, or exited
        }
        $bound = (string)$t['path'];
        if ($bound === '') {
            // The server has no listening socket that we know. Try the
            // session's own path: tmux binds its original path again.
            $bound = self::tmuxRoot() . '/s-' . $t['sessionId'] . '/tmux-0/default';
        }
        $state = self::processTree()[(int)$t['serverPid']]['state'] ?? '';
        if (!in_array($state, ['S', 'R', 'I'], true)) {
            // A stopped server would act on SIGUSR1 LATER, after the live path
            // is back, and take it. Do not signal it.
            return ['ok' => false, 'reason' => 'server_not_ready'];
        }
        $r = TmuxSocketRecovery::recover($bound, (int)$t['serverPid'], $rec, self::$recoveryOps);
        self::log("recover session={$t['sessionId']} server={$t['serverPid']} path=$bound -> "
            . ($r['ok'] ? $rec : 'FAILED ' . ($r['reason'] ?? '')) . ' steps=' . implode(',', $r['steps']),
            $r['ok'] ? 'info' : 'warn');
        return $r['ok'] ? ['ok' => true, 'path' => $rec] : ['ok' => false, 'reason' => (string)($r['reason'] ?? 'no_socket')];
    }

    /** True when the listening socket of $pid is the file at $path. */
    private static function socketBelongsTo(string $path, int $pid): bool
    {
        $ino = self::statIno($path);
        if ($ino === null) return false;
        $vfs = self::vfsMap();
        $proc = self::proc();
        foreach (@scandir("$proc/$pid/fd") ?: [] as $fd) {
            if (!ctype_digit((string)$fd)) continue;
            $l = @readlink("$proc/$pid/fd/$fd");
            if (is_string($l) && preg_match('/^socket:\[(\d+)\]$/', $l, $m) && ($vfs[(int)$m[1]] ?? -1) === $ino) return true;
        }
        return false;
    }

    /** @param list<string> $args @return array{0:int,1:string} */
    private static function tmux(array $args): array
    {
        if (is_callable(self::$tmuxRunner)) return call_user_func(self::$tmuxRunner, $args);
        $cmd = 'tmux ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>/dev/null';
        $out = [];
        $rc = 1;
        // nosemgrep: php.lang.security.exec-use.exec-use
        @exec($cmd, $out, $rc);
        return [$rc, implode("\n", $out)];
    }

    /**
     * "Show screen": the screen text of an unreachable copy, plain and masked.
     * Read-only for the agent: capture-pane only, no key is sent.
     *
     * @return array<string,mixed>
     */
    public static function view(string $sessionId, int $serverPid): array
    {
        if (($bad = self::badInput($sessionId, $serverPid)) !== null) return $bad;
        if (!SessionLaunchLock::acquire($sessionId, 10.0)) return self::busy();
        try {
            $t = self::findTarget($sessionId, $serverPid);
            if ($t === null) return self::notFound();
            $sock = self::reachSocket($t);
            if (!$sock['ok']) {
                return ['status' => 'error', 'reason' => (string)($sock['reason'] ?? 'no_socket'),
                    'message' => 'The screen of this copy could not be read. The visible workspace was not changed.'];
            }
            $name = 'aicli-agent-' . $t['agentId'] . '-' . $t['sessionId'];
            [$rc, $raw] = self::tmux(['-S', (string)$sock['path'], 'capture-pane', '-p', '-J', '-t', $name, '-S', '-' . self::MAX_LINES]);
            if ($rc !== 0) {
                return ['status' => 'error', 'reason' => 'capture_failed',
                    'message' => 'The screen of this copy could not be read. The visible workspace was not changed.'];
            }
            $text = self::cleanScreen($raw);
            self::log("view session=$sessionId server=$serverPid socket={$sock['path']} bytes=" . strlen($text), 'warn');
            return ['status' => 'ok', 'sessionId' => $sessionId, 'serverPid' => $serverPid,
                'agentId' => $t['agentId'], 'text' => $text];
        } finally {
            SessionLaunchLock::release($sessionId);
        }
    }

    /** Plain text, trailing blank lines removed, secrets masked, capped. */
    public static function cleanScreen(string $raw): string
    {
        if (class_exists(__NAMESPACE__ . '\\TmuxService')) $raw = TmuxService::plainPaneText($raw);
        $rows = array_map(static fn($r): string => rtrim((string)$r), preg_split('/\R/u', $raw) ?: []);
        while ($rows !== [] && end($rows) === '') array_pop($rows);
        $rows = array_slice($rows, -self::MAX_LINES);
        $text = implode("\n", $rows);
        if (class_exists(__NAMESPACE__ . '\\WorkspaceScreenService')) $text = WorkspaceScreenService::mask($text);
        if (strlen($text) > self::MAX_BYTES) {
            $text = substr($text, -self::MAX_BYTES);
            if (function_exists('mb_scrub')) $text = mb_scrub($text, 'UTF-8');
        }
        return $text;
    }

    /**
     * "Stop this copy": end an unreachable copy. Never the server that owns
     * the session's socket (findTarget refuses it), and only processes under
     * the target server.
     *
     *   1. Through a socket of the copy: Ctrl-C twice (the agents' normal
     *      exit), wait up to 5 s for the agent, then `kill-server`.
     *   2. When no socket is possible, or the server still runs after 3 s:
     *      TERM the process groups of the copy's panes and the server, then
     *      KILL what is left after 3 s.
     *
     * The close flag and the environment reap of a normal close are NOT used:
     * the visible workspace has the same session id and would stop too.
     *
     * @return array<string,mixed>
     */
    public static function stop(string $sessionId, int $serverPid): array
    {
        if (($bad = self::badInput($sessionId, $serverPid)) !== null) return $bad;
        if (!SessionLaunchLock::acquire($sessionId, 30.0)) return self::busy();
        try {
            $t = self::findTarget($sessionId, $serverPid);
            if ($t === null) return self::notFound();
            $method = 'term';
            $sock = self::reachSocket($t);
            if ($sock['ok']) {
                $name = 'aicli-agent-' . $t['agentId'] . '-' . $t['sessionId'];
                self::tmux(['-S', (string)$sock['path'], 'send-keys', '-t', $name, 'C-c']);
                self::pause(300000);
                self::tmux(['-S', (string)$sock['path'], 'send-keys', '-t', $name, 'C-c']);
                $agentPid = (int)$t['agentPid'];
                if ($agentPid > 1) self::waitGone($agentPid, 5.0);
                self::tmux(['-S', (string)$sock['path'], 'kill-server']);
                if (self::waitGone($serverPid, 3.0)) $method = 'graceful';
            }
            if ($method !== 'graceful') {
                $method = self::terminate($serverPid);
            }
            $gone = !self::isAlive($serverPid);
            // tmux does not remove its socket file when it exits.
            if ($gone) {
                self::removeDeadSocket(self::recoveryPathFor($sessionId, $serverPid));
                if ((string)$t['reachablePath'] !== '') self::removeDeadSocket((string)$t['reachablePath']);
            }
            self::log("stop session=$sessionId server=$serverPid agent={$t['agentPid']} method=$method gone=" . ($gone ? 'yes' : 'no'), 'warn');
            return $gone
                ? ['status' => 'ok', 'method' => $method]
                : ['status' => 'error', 'reason' => 'still_running', 'method' => $method,
                   'message' => 'The copy did not stop. The visible workspace was not changed.'];
        } finally {
            SessionLaunchLock::release($sessionId);
        }
    }

    /** Remove a socket file that no server answers on any more. */
    private static function removeDeadSocket(string $path): void
    {
        if (is_callable(self::$cleanup)) { call_user_func(self::$cleanup, $path); return; }
        if (!file_exists($path)) return;
        if (class_exists(__NAMESPACE__ . '\\ProcessManager') && ProcessManager::isLiveUnixSocket($path)) return;
        @unlink($path);
    }

    /** TERM, then KILL, the process groups under the server and the server itself. */
    private static function terminate(int $serverPid): string
    {
        $tree = self::processTree();
        $own = function_exists('posix_getpgrp') ? (int)posix_getpgrp() : -1;
        $groups = [];
        foreach (self::descendants($serverPid, $tree) as $p) {
            $g = $tree[$p]['pgrp'] ?? 0;
            if ($g > 1 && $g !== $own && $g !== $serverPid) $groups[$g] = true;
        }
        foreach (array_keys($groups) as $g) self::sendSignal(-$g, 15);
        self::sendSignal($serverPid, 15);
        if (self::waitGone($serverPid, 3.0)) return 'term';
        foreach (array_keys($groups) as $g) self::sendSignal(-$g, 9);
        self::sendSignal($serverPid, 9);
        self::waitGone($serverPid, 1.0);
        return 'kill';
    }

    private static function sendSignal(int $pid, int $sig): bool
    {
        if (is_callable(self::$signal)) return (bool)call_user_func(self::$signal, $pid, $sig);
        return function_exists('posix_kill') && @posix_kill($pid, $sig);
    }

    private static function isAlive(int $pid): bool
    {
        if (is_callable(self::$alive)) return (bool)call_user_func(self::$alive, $pid);
        $f = self::statFields($pid);
        return $f !== [] && ($f[0] ?? '') !== 'Z';
    }

    private static function waitGone(int $pid, float $seconds): bool
    {
        $deadline = microtime(true) + $seconds;
        while (self::isAlive($pid)) {
            if (microtime(true) >= $deadline) return false;
            self::pause(100000);
        }
        return true;
    }

    private static function pause(int $us): void
    {
        if (is_callable(self::$sleep)) { call_user_func(self::$sleep, $us); return; }
        usleep($us);
    }

    private static function log(string $msg, string $level): void
    {
        if (!class_exists(__NAMESPACE__ . '\\LogService')) return;
        LogService::log('UnreachableCopy: ' . $msg, $level === 'warn' ? LogService::LOG_WARN : LogService::LOG_INFO, 'TmuxDuplicate');
    }
}
