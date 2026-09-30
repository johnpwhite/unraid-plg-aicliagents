<?php
/**
 * <module_context>
 *     <name>ProcessManager</name>
 *     <description>Session and process management for the AICliAgents plugin.</description>
 *     <dependencies>LogService</dependencies>
 *     <constraints>Under 150 lines. Manages session status and clean termination.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class ProcessManager {
    /**
     * R-B2 (CLAUDE_RELAUNCH_SURVIVAL) test seam. When non-null, this callable is
     * used INSTEAD of the real tmux `has-session` probe to decide whether a
     * detached agent session named aicli-agent-<agent>-<sid> is alive. Lets unit
     * tests assert the liveness/sweep logic without a real tmux server.
     * Signature: fn(string $safeId): bool
     * @var (callable(string):bool)|null
     */
    public static $tmuxLivenessProbe = null;

    /**
     * R-B2 test seam — overrides findTtydPidForSock. fn(string $sock): ?int
     * @var (callable(string):?int)|null
     */
    public static $ttydPidProbe = null;

    /**
     * R-B2 test seam — overrides ttydHasChildren. fn(int $pid): bool
     * @var (callable(int):bool)|null
     */
    public static $ttydChildrenProbe = null;

    /**
     * CRITICAL-1 (zombie-session leak) test seam — overrides paneHasLiveAgent.
     * Given the resolved [sessionName, tmuxBin] for a sid, returns whether the
     * detached pane currently has a LIVE AGENT (true) or is PARKED on the
     * run-loop shell with the agent dead (false). Lets unit tests assert the
     * keep/reap decision without a real tmux server.
     * Signature: fn(string $sessName, string $tmuxBin): bool
     * @var (callable(string,string):bool)|null
     */
    public static $paneLivenessProbe = null;

    /** @var (callable(string):array<int,int>)|null Test seam for exact session-environment process discovery. */
    public static $sessionPidProbe = null;

    /** @var (callable(int,int):bool)|null Test seam for process signalling. */
    public static $sessionSignalProbe = null;

    /** @var (callable(int):bool)|null Bug #141 test seam — is this pid a tmux SERVER? */
    public static $tmuxServerProbe = null;

    /** @var (callable(int):?array{ppid:int,cmdline:string})|null Bug #141 test seam — /proc reader. */
    public static $processInfoProbe = null;

    /** @var (callable(int):?string)|null Bug #141 test seam — overrides resolveOwningSessionId. */
    public static $owningSessionProbe = null;

    /** @var (callable(string):string)|null #142 test seam — overrides sessionLaunchGeneration. */
    public static $sessionGenerationProbe = null;

    /** @var (callable(string):bool)|null test seam — stubs restartTerminalBridge's ttyd kill (AUTO_RECONNECT_ALL_ON_DEPLOY). */
    public static $bridgeRestartProbe = null;

    /**
     * Forgejo #364 test seam — the directory that holds one folder per
     * generation (`<root>/<gen>/src/...`). Null = PLUGIN_ROOT/.generations.
     * @var string|null
     */
    public static $generationsRoot = null;

    /** @var array<string,string> Forgejo #364: bridge fingerprint per generation (generations are immutable). */
    private static $bridgeFingerprintCache = [];

    /**
     * @var (callable():array<int,array{name:string,sock:string,created?:int,path?:string}>)|null
     * Forgejo #315 test seam — replaces the raw tmux listing behind
     * listAgentTmuxSessions() (every aicli-agent-* session on every plugin tmux
     * server). The parse into {id, agentId} still runs, so tests cover it.
     */
    public static $tmuxSessionListProbe = null;

    /**
     * Bug #297: the `pgrep -f` alternation for THIS PLUGIN'S OWN ttyd
     * processes only. A bare `ttyd` alternative also matches Unraid's own
     * web terminal (`ttyd -R -o -i /var/run/ttyd.sock bash --login`) and any
     * other plugin's ttyd — killing those breaks the terminal for the whole
     * host, not just this plugin's sessions.
     *
     * This plugin always launches ttyd with `-i` pointed at a socket named
     * `aicliterm-<id>.sock` or `temp-terminal-<id>.sock` (TerminalService),
     * or the legacy `geminiterm-<id>.sock`. Matching on those socket-name
     * substrings — never on the bare word `ttyd` — identifies only this
     * plugin's own ttyd processes. This mirrors the proven pattern already
     * used by the uninstaller sweep (`cleanup.sh`) and
     * `InitService::bootCleanup`, kept here as ONE named constant so
     * `evictAll()` (and any future caller) can never drift from it or fall
     * back to an unscoped match.
     */
    public const EVICT_TTYD_PATTERN = 'ttyd.*(aicliterm|temp-terminal|geminiterm)-';

    /** Bug #297: the tmux half — this plugin's own detached agent sessions only, never a bare `tmux`. */
    public const EVICT_TMUX_PATTERN = 'tmux.*aicli-agent-';

    /**
     * The full `pgrep -f` pattern evictAll() kills: this plugin's own ttyd
     * instances OR its own tmux agent sessions. Extracted to its own method
     * (Bug #297) so a unit test can assert, without running a real kill,
     * that it matches this plugin's process lines and never a bare Unraid
     * ttyd line such as `ttyd -R -o -i /var/run/ttyd.sock bash --login`.
     */
    public static function evictKillPattern(): string {
        return '(' . self::EVICT_TTYD_PATTERN . '|' . self::EVICT_TMUX_PATTERN . ')';
    }

    /** Plugin root holding the `src` symlink and the .active-generation marker. */
    public const PLUGIN_ROOT = '/usr/local/emhttp/plugins/unraid-aicliagents';

    /** Root holding every tmux server socket the plugin owns. */
    public const TMUX_ROOT = '/tmp/unraid-aicliagents/tmux';

    /**
     * Reset all test seams to their live-probe defaults. Call in tearDown.
     */
    public static function resetProbes(): void {
        self::$tmuxLivenessProbe = null;
        self::$ttydPidProbe = null;
        self::$ttydChildrenProbe = null;
        self::$paneLivenessProbe = null;
        self::$sessionPidProbe = null;
        self::$sessionSignalProbe = null;
        self::$tmuxServerProbe = null;
        self::$processInfoProbe = null;
        self::$owningSessionProbe = null;
        self::$sessionGenerationProbe = null;
        self::$bridgeRestartProbe = null;
        self::$tmuxSessionListProbe = null;
        self::$generationsRoot = null;
        self::$bridgeFingerprintCache = [];
    }

    // ---------- Generation drift (#142) ----------
    //
    // A running ttyd holds its generation path in argv, so every reconnect
    // re-executes the shell script from the generation that workspace launched
    // with. Publishing a fix to aicli-shell.sh therefore does nothing for an
    // already-open workspace — on 2026-08-11 a verified fix was reported as
    // shipped while every live workspace kept running the old code, because
    // nothing anywhere compared the two. These three helpers make that
    // difference visible so the UI can offer to close it.

    /** The generation embedded in a process command line, or '' if there is none. */
    public static function parseGenerationFromCommandLine(string $cmdline): string {
        if ($cmdline === '') return '';
        return preg_match('#/\.generations/([^/\0]+)/#', $cmdline, $m) === 1 ? $m[1] : '';
    }

    /** The generation the plugin's `src` symlink currently resolves to ('' if unknown). */
    public static function activeGeneration(string $root = self::PLUGIN_ROOT): string {
        $marker = @file_get_contents($root . '/.active-generation');
        return is_string($marker) ? trim($marker) : '';
    }

    /**
     * The generation a live session's ttyd was launched from ('' if unknown —
     * no live ttyd, or a pre-generation layout).
     */
    public static function sessionLaunchGeneration(string $id): string {
        $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
        if ($safeId === '') return '';
        if (is_callable(self::$sessionGenerationProbe)) {
            return (string) call_user_func(self::$sessionGenerationProbe, $safeId);
        }
        $needle = "aicliterm-$safeId.sock";
        foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $file) {
            $cmdline = @file_get_contents($file);
            if (!is_string($cmdline) || strpos($cmdline, $needle) === false) continue;
            if (strpos($cmdline, 'ttyd') === false) continue;
            $gen = self::parseGenerationFromCommandLine($cmdline);
            if ($gen !== '') return $gen;
        }
        return '';
    }

    /**
     * Is a session running superseded code?
     *
     * UNKNOWN IS NEVER STALE. A session we cannot attribute — or an unreadable
     * marker — would otherwise wear a permanent "update me" badge that no
     * action can clear, which trains the user to ignore the badge entirely.
     */
    public static function generationIsStale(string $launched, string $active): bool {
        if ($launched === '' || $active === '') return false;
        return $launched !== $active;
    }

    // ---------- Bridge equivalence (Forgejo #364) ----------
    //
    // A new generation is not always new BRIDGE code. On 2026-09-29 eleven
    // generations in a row carried a byte-identical aicli-shell.sh, yet each
    // activation (every release-gate dev overlay and every promote) SIGTERMed
    // the ttyd of every open workspace. The owner's terminals dropped at each
    // one, and nginx logged `connect() to unix:/var/run/aicliterm-<id>.sock
    // failed` until the warm-up started a new ttyd. Renewing a bridge whose
    // code did not change gives nothing and costs a visible drop.

    /**
     * The files a live ttyd executes, from ITS OWN generation, each time a
     * browser connects to a workspace whose agent already runs (the attach
     * path of aicli-shell.sh). Paths are relative to `<generation>/src`.
     *
     * - aicli-shell.sh: ttyd runs it on every connection.
     * - log-bridge.php, storage/resolve_paths.sh, terminfo/tmux.terminfo:
     *   the `$PLUGIN_SRC/...` references OUTSIDE the new-session block.
     * - user/apply-tmux-json.php + services/TmuxService.php: the tmux option
     *   pass; its output comes from TmuxService::ALLOWED_KEYS / APPEND_KEYS
     *   only, so for TmuxService just those two declarations are digested
     *   (BRIDGE_ATTACH_CONSTS) — the rest of that class changes often and is
     *   not run by the bridge.
     *
     * The `$PLUGIN_SRC/...` files INSIDE the new-session block run only when
     * the agent's tmux session does not exist (a launch, not an attach), so
     * they are listed in BRIDGE_LAUNCH_ONLY_FILES and do not make a bridge
     * stale. BridgeEquivalenceTest pins both lists against the script text:
     * a new reference must be put in one list or the other.
     */
    public const BRIDGE_ATTACH_FILES = [
        'scripts/aicli-shell.sh',
        'scripts/log-bridge.php',
        'scripts/storage/resolve_paths.sh',
        'terminfo/tmux.terminfo',
        'scripts/user/apply-tmux-json.php',
        'includes/services/TmuxService.php',
    ];

    /** Forgejo #364: for these attach files only the named `const` declarations are digested. */
    public const BRIDGE_ATTACH_CONSTS = [
        'includes/services/TmuxService.php' => ['ALLOWED_KEYS', 'APPEND_KEYS'],
    ];

    /** Forgejo #364: `$PLUGIN_SRC/...` references that run only when a new tmux session is made. */
    public const BRIDGE_LAUNCH_ONLY_FILES = [
        'scripts/relay-agent.php',
        'scripts/admin-agent.php',
        'secret-service/secret-service-up.sh',
        'scripts/user/effective-env-export.php',
        'includes/AICliAgentsManager.php',
        'scripts/user/agent-exit-recorder.sh',
    ];

    /**
     * A digest of a generation's BRIDGE_ATTACH_FILES, or '' when it cannot be
     * read (no such generation folder, or no aicli-shell.sh in it). A missing
     * optional file hashes as "missing", so adding or removing one changes the
     * digest.
     */
    public static function bridgeFingerprint(string $generation): string {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $generation) !== 1) return '';
        if (isset(self::$bridgeFingerprintCache[$generation])) return self::$bridgeFingerprintCache[$generation];
        $root = (self::$generationsRoot ?? self::PLUGIN_ROOT . '/.generations') . '/' . $generation . '/src';
        if (!is_file($root . '/scripts/aicli-shell.sh')) return '';
        $parts = [];
        foreach (self::BRIDGE_ATTACH_FILES as $rel) {
            $file = $root . '/' . $rel;
            if (!is_file($file)) {
                $digest = 'missing';
            } elseif (isset(self::BRIDGE_ATTACH_CONSTS[$rel])) {
                $digest = self::constDeclarationsDigest((string) @file_get_contents($file), self::BRIDGE_ATTACH_CONSTS[$rel]);
            } else {
                $digest = (string) @hash_file('sha256', $file);
            }
            $parts[] = $rel . "\0" . ($digest !== '' ? $digest : 'unreadable');
        }
        return self::$bridgeFingerprintCache[$generation] = hash('sha256', implode("\n", $parts));
    }

    /**
     * sha256 of the text of the named `const NAME = ...;` declarations, in the
     * given order; a declaration that is not found counts as "missing:NAME".
     *
     * @param string[] $names
     */
    public static function constDeclarationsDigest(string $php, array $names): string {
        $parts = [];
        foreach ($names as $name) {
            $parts[] = preg_match('/\bconst\s+' . preg_quote($name, '/') . '\s*=\s*[^;]*;/', $php, $m) === 1
                ? $m[0] : 'missing:' . $name;
        }
        return hash('sha256', implode("\n", $parts));
    }

    /**
     * Must this session's web bridge be renewed to run current code?
     *
     * Only when the generations differ (generationIsStale — unknown is never
     * stale) AND the bridge code differs. When either digest cannot be read
     * the answer is "stale": that keeps the #142 behaviour, and a bridge whose
     * generation folder is gone cannot serve a new connection anyway.
     */
    public static function bridgeIsStale(string $launched, string $active): bool {
        if (!self::generationIsStale($launched, $active)) return false;
        $old = self::bridgeFingerprint($launched);
        $new = self::bridgeFingerprint($active);
        if ($old === '' || $new === '') return true;
        return $old !== $new;
    }

    /**
     * Restart ONLY the web bridge for a session: kill its ttyd, leave the
     * detached tmux session and the agent inside it running.
     *
     * This is how a workspace moves onto the current generation without
     * restarting its agent — the page re-fires `start`, and TerminalService
     * resolves the shell path from the live `src` symlink at launch, so the new
     * ttyd runs current code. Safe against the orphan sweep: a socket with no
     * ttyd classifies as `no_ttyd`, which only unlinks artefacts and never
     * touches the tmux session (sweepOrphanSessions).
     *
     * @return bool whether a ttyd was found and signalled.
     */
    public static function restartTerminalBridge(string $id): bool {
        $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
        if ($safeId === '') return false;
        if (is_callable(self::$bridgeRestartProbe)) {
            return (bool) call_user_func(self::$bridgeRestartProbe, $safeId);
        }
        $sock = "/var/run/aicliterm-$safeId.sock";
        $pid = self::findTtydPidForSock($sock);
        if (!$pid) return false;
        LogService::log("Bridge refresh (#142): terminating ttyd pid=$pid for $safeId; agent + tmux session left running", LogService::LOG_INFO, 'ProcessManager');
        @posix_kill($pid, defined('SIGTERM') ? SIGTERM : 15);
        self::publishBridgeEvent($safeId);
        return true;
    }

    /**
     * WORKSPACE_LIFECYCLE_EVENTS.md: tell every open tab a session's web
     * bridge identity may have changed, so a device that missed the change
     * does not wait out the 30 s status poll before it replaces a dead
     * iframe. Reads the exact value `get_session_status` already reports
     * (TerminalGenerationService::current()), so the push and the poll can
     * never disagree. Best-effort: a bridge mid-restart with no live
     * identity yet publishes nothing — the next poll (or the session's next
     * start) reports the real one once it exists.
     */
    private static function publishBridgeEvent(string $safeId): void {
        if ($safeId === '' || !class_exists('\\AICliAgents\\Services\\TerminalGenerationService')) return;
        $generation = TerminalGenerationService::current($safeId);
        if ($generation === null || $generation === '') return;
        if (!class_exists('\\AICliAgents\\Services\\NchanService')) return;
        EventBus::publish('workspace', [], ['event' => 'bridge', 'id' => $safeId, 'generation' => $generation]);
    }

    /**
     * AUTO_RECONNECT_ALL_ON_DEPLOY.md: restart the web bridge of every session
     * running superseded code, so a deploy moves the WHOLE fleet onto the new
     * generation in one shot instead of the user clicking "Reconnect terminal"
     * per tile. Non-destructive — each restart is a ttyd SIGTERM only; agents
     * and their tmux sessions keep running.
     *
     * Restarts EXACTLY the stale sessions: a session on the current generation,
     * or one we cannot attribute (unknown generation is never stale), is left
     * untouched. Idempotent — a bridge already gone returns false and is not
     * counted, so a second pass or a second open tab firing the same reconnect
     * never double-restarts.
     *
     * @param string[]     $sessionIds ids to consider (typically the running ones).
     * @param string|null  $active     active generation; defaults to activeGeneration().
     * @return array{reconnected:int,ids:string[]}
     */
    public static function restartAllStaleBridges(array $sessionIds, ?string $active = null): array {
        $active = $active ?? self::activeGeneration();
        $ids = [];
        foreach ($sessionIds as $id) {
            $id = (string) $id;
            if ($id === '') continue;
            $launched = self::sessionLaunchGeneration($id);
            // Forgejo #364: a bridge whose code is the same in both generations
            // is left alone — no drop for an open terminal.
            if (!self::bridgeIsStale($launched, $active)) continue;
            if (self::restartTerminalBridge($id)) {
                $ids[] = $id;
            }
        }
        return ['reconnected' => count($ids), 'ids' => $ids];
    }

    /**
     * Bug #141: the session a process ACTUALLY belongs to, or null if unknown.
     *
     * AICLI_SESSION_ID cannot be trusted for this on its own. The shared tmux
     * server exported the id of whichever session forked it, and tmux copies
     * that environment into every session created afterwards — so on a legacy
     * shared socket, `aicli-run-sn3ic0.sh` reports AICLI_SESSION_ID=sw3w1s.
     * Reaping on the env alone therefore killed live sibling workspaces.
     *
     * Launch identity is trustworthy: TerminalService writes each session a
     * uniquely-named `aicli-run-<sid>.sh`, and every process in that session
     * has it in its ancestry. Walk up from $pid and take the first one found.
     *
     * Returns null when no run script appears in the ancestry (a ttyd wrapper,
     * or a genuinely detached child) — callers then fall back to the env match,
     * preserving the original reap behaviour for those.
     */
    public static function resolveOwningSessionId(int $pid): ?string {
        if (is_callable(self::$owningSessionProbe)) {
            $v = call_user_func(self::$owningSessionProbe, $pid);
            return is_string($v) && $v !== '' ? $v : null;
        }
        $seen = [];
        // Bounded: deep enough for agent → shell → wrapper chains, cheap enough
        // to run per candidate, and immune to a malformed /proc parent cycle.
        for ($i = 0; $i < 12 && $pid > 1 && !isset($seen[$pid]); $i++) {
            $seen[$pid] = true;
            $info = self::processInfo($pid);
            if ($info === null) return null;
            if (preg_match('#/aicli-run-([A-Za-z0-9_-]+)\.sh#', $info['cmdline'], $m) === 1) {
                return $m[1];
            }
            $pid = $info['ppid'];
        }
        return null;
    }

    /**
     * Read one process's parent pid + cmdline from /proc.
     *
     * @return array{ppid:int,cmdline:string}|null
     */
    private static function processInfo(int $pid): ?array {
        if (is_callable(self::$processInfoProbe)) {
            $v = call_user_func(self::$processInfoProbe, $pid);
            return is_array($v) ? $v : null;
        }
        $stat = @file_get_contents("/proc/$pid/stat");
        if (!is_string($stat) || $stat === '') return null;
        $close = strrpos($stat, ')');
        if ($close === false) return null;
        $fields = preg_split('/\s+/', trim(substr($stat, $close + 1)));
        $ppid = isset($fields[1]) ? (int) $fields[1] : 0;
        $cmdline = (string) @file_get_contents("/proc/$pid/cmdline");
        return ['ppid' => $ppid, 'cmdline' => $cmdline];
    }

    /**
     * Bug #141: the private TMUX_TMPDIR for ONE session.
     *
     * Every session used to share a single tmux server, which meant a single
     * point of failure the reap could reach: the server inherits the
     * AICLI_SESSION_ID of whichever session forked it, tmux copies that
     * environment into every session created later, and closing the owning
     * workspace therefore SIGKILLed the server out from under every other
     * workspace. Giving each session its own TMUX_TMPDIR gives it its own
     * server, so a close can only ever reach its own processes.
     *
     * The id is sanitised to the session-id alphabet, so a hostile value can
     * never escape TMUX_ROOT.
     */
    public static function sessionTmuxTmpdir(string $sessionId): string {
        $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId);
        if ($safeId === '') $safeId = 'unknown';
        return self::TMUX_ROOT . '/s-' . $safeId;
    }

    /**
     * Remove a closed session's private tmux folder (sessionTmuxTmpdir()) once
     * no tmux server answers on any socket in it. A server can take a moment
     * to exit after its last session is killed, so liveness is re-checked for
     * up to ~1 s; a folder whose server is still up is kept. Only the folder
     * of THIS session id is ever touched (the id is sanitised by
     * sessionTmuxTmpdir(), so it cannot escape $root).
     *
     * @param callable|null $serverAlive fn(string $socket): bool — test seam;
     *        defaults to `tmux -S <socket> list-sessions` exiting 0.
     * @return bool true when the folder is gone (or never existed).
     */
    public static function removeDeadSessionTmuxDir(string $sessionId, string $root = self::TMUX_ROOT, ?callable $serverAlive = null, int $waitMs = 1000): bool {
        $dir = $root . substr(self::sessionTmuxTmpdir($sessionId), strlen(self::TMUX_ROOT));
        if (!is_dir($dir) || is_link($dir)) return !file_exists($dir);
        $serverAlive ??= static function (string $sock): bool {
            $rc = 1; $out = [];
            // nosemgrep: php.lang.security.exec-use.exec-use
            @exec('tmux -S ' . escapeshellarg($sock) . ' list-sessions > /dev/null 2>&1', $out, $rc);
            return $rc === 0;
        };
        $deadline = microtime(true) + max(0, $waitMs) / 1000;
        do {
            $alive = false;
            foreach (glob($dir . '/tmux-*/default') ?: [] as $sock) {
                if ($serverAlive($sock)) { $alive = true; break; }
            }
            if (!$alive) break;
            if (microtime(true) >= $deadline) return false;
            usleep(100000);
        } while (true);
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $path = $f->getPathname();
            if ($f->isDir() && !$f->isLink()) @rmdir($path); else @unlink($path);
        }
        @rmdir($dir);
        return !file_exists($dir);
    }

    /**
     * Every tmux server socket the plugin can talk to, newest layout first.
     *
     * Two layouts coexist during migration:
     *   - per-session (Bug #141): <root>/s-<sid>/tmux-<uid>/default
     *   - legacy shared:          <root>/tmux-<uid>/default
     * Sessions launched before the upgrade keep running on the legacy socket,
     * so discovery must cover both or a live workspace becomes unreachable.
     *
     * @return array<int,string>
     */
    public static function tmuxSocketPaths(string $root = self::TMUX_ROOT): array {
        $socks = [];
        foreach (glob($root . '/s-*/tmux-*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (file_exists($dir . '/default')) $socks[] = $dir . '/default';
        }
        foreach (glob($root . '/tmux-*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (file_exists($dir . '/default')) $socks[] = $dir . '/default';
        }
        return $socks;
    }

    /**
     * Forgejo #315: every RUNNING agent session, read from tmux — the source of
     * truth for "a session of agent X is running".
     *
     * The ttyd socket /var/run/aicliterm-<id>.sock only says that a browser
     * terminal is attached. The terminal page mounts a terminal only for the
     * workspaces opened in that browser, and a ttyd can be gone after a restart
     * while tmux (and the agent in it) keeps running. So a ttyd scan can count
     * zero sessions for an agent that is in use.
     *
     * Cost, per call: one glob, one in-process connect() per socket file (a dead
     * server's socket file refuses at once, with no process spawn), and ONE
     * shell spawn that runs `tmux ls` on the live servers only. No spawn at all
     * when no server is live.
     *
     * @return array<int,array{id:string,agentId:string,name:string,sock:string,created:int,path:string}>
     *         One row per aicli-agent-<agentId>-<id> session, in socket order.
     */
    public static function listAgentTmuxSessions(): array {
        $raw = is_callable(self::$tmuxSessionListProbe)
            ? (array) call_user_func(self::$tmuxSessionListProbe)
            : self::rawAgentTmuxSessions();
        $out = [];
        $seen = [];
        foreach ($raw as $row) {
            if (!is_array($row)) continue;
            $name = trim((string)($row['name'] ?? ''));
            $sock = (string)($row['sock'] ?? '');
            [$id, $agentId] = self::parseAgentSessionName($name, $sock);
            if ($id === '' || $agentId === '' || isset($seen[$id])) continue;
            $seen[$id] = true;
            $out[] = [
                'id'      => $id,
                'agentId' => $agentId,
                'name'    => $name,
                'sock'    => $sock,
                'created' => (int)($row['created'] ?? 0),
                'path'    => (string)($row['path'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Split a tmux session name `aicli-agent-<agentId>-<id>` into [id, agentId].
     *
     * Agent ids contain dashes (claude-code), so the split point is the session
     * id. On the per-session layout (<root>/s-<id>/tmux-<uid>/default) the id is
     * the socket directory name, which is exact. On the legacy shared socket the
     * id is the last dash-delimited token (the plugin mints ids with no dash).
     *
     * @return array{0:string,1:string} ['', ''] when the name is not an agent session.
     */
    public static function parseAgentSessionName(string $name, string $sock = ''): array {
        $prefix = 'aicli-agent-';
        if (strncmp($name, $prefix, strlen($prefix)) !== 0) return ['', ''];
        $rest = substr($name, strlen($prefix));
        $id = '';
        if ($sock !== '' && preg_match('#/s-([A-Za-z0-9_-]+)/tmux-[^/]+/default$#', $sock, $m)) {
            $id = $m[1];
            $suffix = '-' . $id;
            if (strlen($rest) <= strlen($suffix) || substr($rest, -strlen($suffix)) !== $suffix) {
                return ['', ''];
            }
        } else {
            $dash = strrpos($rest, '-');
            if ($dash === false) return ['', ''];
            $id = substr($rest, $dash + 1);
        }
        if ($id === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $id)) return ['', ''];
        $agentId = substr($rest, 0, -(strlen($id) + 1));
        if ($agentId === '') return ['', ''];
        return [$id, $agentId];
    }

    /**
     * The live half of listAgentTmuxSessions(): raw {name, sock, created, path}
     * rows from every plugin tmux server that answers.
     *
     * @return array<int,array{name:string,sock:string,created:int,path:string}>
     */
    private static function rawAgentTmuxSessions(): array {
        $live = [];
        foreach (self::tmuxSocketPaths() as $sock) {
            // A socket file outlives its server (e2e runs leave dozens). connect()
            // refuses at once on a dead one, so only live servers cost a tmux call.
            if (!self::isLiveUnixSocket($sock)) continue;
            $live[] = $sock;
        }
        if ($live === []) return [];

        $marker = '@@AICLI_SOCK ';
        $script = '';
        foreach ($live as $sock) {
            $esc = escapeshellarg($sock);
            $script .= 'printf ' . escapeshellarg($marker . '%s\n') . " $esc; "
                . "tmux -S $esc ls -F '#{session_name}|#{session_created}|#{session_path}' 2>/dev/null; ";
        }
        // nosemgrep: php.lang.security.exec-use.exec-use
        $raw = (string) @shell_exec($script);

        $rows = [];
        $sock = '';
        foreach (explode("\n", $raw) as $line) {
            if (strncmp($line, $marker, strlen($marker)) === 0) {
                $sock = substr($line, strlen($marker));
                continue;
            }
            if ($sock === '' || strncmp($line, 'aicli-agent-', 12) !== 0) continue;
            $parts = explode('|', $line, 3);
            $rows[] = [
                'name'    => $parts[0],
                'sock'    => $sock,
                'created' => (int)($parts[1] ?? 0),
                'path'    => (string)($parts[2] ?? ''),
            ];
        }
        return $rows;
    }

    /**
     * Bug #141 guard: is this pid a tmux SERVER?
     *
     * A tmux server must never be signalled by a workspace close. Sessions are
     * torn down with `kill-session`; killing the server itself is never
     * required and, on the legacy shared socket, drops every other workspace.
     * The server keeps its original `tmux …` argv and is reparented to init
     * once it daemonises, which is what we match on.
     */
    public static function isTmuxServerProcess(int $pid): bool {
        if (is_callable(self::$tmuxServerProbe)) {
            return (bool) call_user_func(self::$tmuxServerProbe, $pid);
        }
        $cmdline = @file_get_contents("/proc/$pid/cmdline");
        if (!is_string($cmdline) || $cmdline === '') return false;
        $argv0 = strtok($cmdline, "\0");
        if (!is_string($argv0) || basename($argv0) !== 'tmux') return false;
        // Only the daemonised server survives reparented to init; a transient
        // client still owned by its caller is safe (and pointless) to signal.
        $stat = @file_get_contents("/proc/$pid/stat");
        if (!is_string($stat) || $stat === '') return true; // argv says tmux — stay safe
        $tail = substr($stat, (int) strrpos($stat, ')'));
        $fields = preg_split('/\s+/', trim($tail));
        return isset($fields[2]) && (int) $fields[2] === 1;
    }

    /** Exact NUL-delimited environment match; never a command-line substring match. */
    public static function environmentBelongsToSession(string $environment, string $sessionId): bool
    {
        $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId);
        if ($safeId === '') return false;
        return strpos("\0" . $environment . "\0", "\0AICLI_SESSION_ID={$safeId}\0") !== false;
    }

    /**
     * Reap every remaining process that inherited this workspace's exact session
     * environment. Agent extensions can detach a child from tmux, in which case
     * killing the pane or matching argv alone leaves it reparented to PID 1.
     *
     * The narrow environment key is injected only by TerminalService for a
     * workspace process tree. This is therefore safe for normal close, unlike a
     * broad agent-name or workspace-path pkill.
     *
     * @return array<int,int> PIDs signalled (unique, in discovery order).
     */
    public static function terminateSessionDescendants(string $sessionId): array
    {
        $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId);
        if ($safeId === '') return [];
        $discover = static function () use ($safeId): array {
            if (is_callable(self::$sessionPidProbe)) {
                return array_values(array_filter(call_user_func(self::$sessionPidProbe, $safeId), 'is_int'));
            }
            $pids = [];
            foreach (glob('/proc/[0-9]*/environ') ?: [] as $file) {
                $pid = (int)basename(dirname($file));
                if ($pid <= 1 || $pid === getmypid()) continue;
                $environment = @file_get_contents($file);
                if (is_string($environment) && self::environmentBelongsToSession($environment, $safeId)) {
                    $pids[] = $pid;
                }
            }
            return $pids;
        };
        $signal = static function (int $pid, int $signal): bool {
            if (is_callable(self::$sessionSignalProbe)) {
                return (bool)call_user_func(self::$sessionSignalProbe, $pid, $signal);
            }
            return function_exists('posix_kill') ? @posix_kill($pid, $signal) : @shell_exec('kill -' . (int)$signal . ' ' . (int)$pid . ' 2>/dev/null') === null;
        };

        // Bug #141: never signal a tmux SERVER, even when its environment
        // matches. On the legacy shared socket the server carries the
        // AICLI_SESSION_ID of whichever session forked it, so signalling it
        // took down every OTHER workspace too. The session's own pane is
        // already gone by here (kill-session), so skipping the server costs
        // nothing and removes the cross-workspace blast radius.
        // A second, wider leak from the same shared server: every sibling
        // session's `aicli-run-<sid>.sh` (and its agent) also inherited this
        // id, so the env match alone killed live sibling workspaces too. Trust
        // launch identity over the inherited variable — when a process's
        // ancestry names a DIFFERENT session's run script, it is not ours.
        $victims = static function () use ($discover, $safeId): array {
            return array_values(array_filter(
                $discover(),
                static function (int $pid) use ($safeId): bool {
                    if (self::isTmuxServerProcess($pid)) return false;
                    $owner = self::resolveOwningSessionId($pid);
                    return $owner === null || $owner === $safeId;
                }
            ));
        };

        $seen = [];
        foreach ($victims() as $pid) {
            $seen[$pid] = true;
            $signal($pid, defined('SIGTERM') ? SIGTERM : 15);
        }
        // Give cooperative agent/plugin children a short chance to flush, then
        // re-discover so a child that detached during close is also reaped.
        usleep(250000);
        foreach ($victims() as $pid) {
            $seen[$pid] = true;
            $signal($pid, defined('SIGKILL') ? SIGKILL : 9);
        }
        return array_map('intval', array_keys($seen));
    }

    /**
     * R-B2: true when a LIVE detached tmux session exists for this session id on
     * any of the plugin's per-uid tmux sockets. This is the browser-independent
     * liveness signal: the agent runs inside this detached session whether or not
     * a ttyd/browser is attached. Sessions are named aicli-agent-<agentId>-<sid>;
     * findTmuxSessionForId already matches the trailing -<sid> across every
     * per-uid socket (PHP runs as root; non-root agent sessions live in
     * tmux-<other-uid>/default), so we reuse it.
     *
     * @phpstan-impure Live tmux probe — result changes as sessions start/stop.
     */
    public static function hasLiveTmuxSession(string $id): bool {
        $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
        if ($safeId === '') return false;
        if (is_callable(self::$tmuxLivenessProbe)) {
            return (bool) call_user_func(self::$tmuxLivenessProbe, $safeId);
        }
        [$sessName] = self::findTmuxSessionForId($safeId);
        return $sessName !== '';
    }

    /**
     * CRITICAL-1 (zombie-session leak): a tmux session merely EXISTING is not
     * proof the agent is alive. When the agent dies, aicli-shell.sh's run-loop
     * parks FOREVER on a `read`, so the tmux session survives with the pane sitting
     * on the bare run-loop shell. The old hasLiveTmuxSession()-only test then
     * reported such a zombie as "running"/"keep" forever — never reaped, blocking
     * relaunch.
     *
     * This is the real liveness signal: the detached session exists AND its pane
     * has a LIVE AGENT (the pane's foreground command is the agent, not the parked
     * shell, OR the pane process has an agent child). The 'terminal' agent is
     * excluded — its pane IS a shell by design, so a shell pane is alive for it.
     *
     * @phpstan-impure Live tmux/pane probe.
     */
    public static function tmuxSessionHasLiveAgent(string $id): bool {
        $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
        if ($safeId === '') return false;
        if (!self::hasLiveTmuxSession($safeId)) return false;

        [$sessName, , $tmuxBin] = self::findTmuxSessionForId($safeId);
        if ($sessName === '') {
            // Test seam path (tmuxLivenessProbe forced true with no real session):
            // fall back to the pane probe with the synthetic name so injected
            // probes still drive the decision deterministically.
            $sessName = "aicli-agent-$safeId";
        }

        // The 'terminal' agent's pane is a login shell on purpose — a shell pane
        // IS the live agent. Session name is aicli-agent-<agentId>-<sid>; detect
        // the terminal agent from the name prefix.
        if (self::isTerminalAgentSession($sessName)) {
            return true;
        }

        return self::paneHasLiveAgent($sessName, $tmuxBin);
    }

    /**
     * True when the session name belongs to the raw 'terminal' agent
     * (aicli-agent-terminal-<sid>), whose pane is legitimately a bare shell.
     */
    public static function isTerminalAgentSession(string $sessName): bool {
        return (bool) preg_match('/^aicli-agent-terminal-/', $sessName);
    }

    /**
     * Probe the detached pane: does it host a LIVE AGENT, or is it PARKED on the
     * run-loop shell (agent dead)?
     *
     * Signal (robust against the brief agent-restart window):
     *   - tmux reports the pane pid + foreground command + dead flag.
     *   - ALIVE if the pane's foreground command is NOT a bare shell
     *     (it's the agent binary, node, or the `bash -c "<agent>"` wrapper that is
     *     mid-exec), OR the pane pid has at least one child process (the agent /
     *     its subtree). Either means an agent is on the pane.
     *   - PARKED (agent dead) only when the pane command IS a bare shell AND the
     *     pane pid has no children — exactly the run-loop blocked on `read`.
     *   - pane_dead=1 (the command exited and remain-on-exit kept the pane) is
     *     unambiguously dead.
     *
     * @phpstan-impure
     */
    public static function paneHasLiveAgent(string $sessName, string $tmuxBin = 'tmux'): bool {
        if (is_callable(self::$paneLivenessProbe)) {
            return (bool) call_user_func(self::$paneLivenessProbe, $sessName, $tmuxBin);
        }
        $escSess = escapeshellarg($sessName);
        $fmt = escapeshellarg('#{pane_pid} #{pane_dead} #{pane_current_command}');
        // nosemgrep: php.lang.security.exec-use.exec-use
        $out = trim((string) @shell_exec("$tmuxBin list-panes -t $escSess -F $fmt 2>/dev/null | head -n1"));
        if ($out === '') {
            // Could not read the pane (session vanished mid-probe). Treat as not
            // alive — the session-existence gate already ran; a race here favours
            // re-launch over a phantom keep.
            return false;
        }
        $parts = preg_split('/\s+/', $out, 3);
        $panePid = isset($parts[0]) ? (int) $parts[0] : 0;
        $paneDead = isset($parts[1]) ? (int) $parts[1] : 0;
        $paneCmd = $parts[2] ?? '';

        if ($paneDead === 1) {
            return false;
        }

        // Foreground command is not a bare shell -> an agent (or its bash -c
        // wrapper mid-exec) is on the pane.
        if ($paneCmd !== '' && !self::isBareShellCommand($paneCmd)) {
            return true;
        }

        // Bare shell on the pane: alive only if it has a live child (the agent /
        // its subtree). The parked run-loop blocked on `read` has none.
        if ($panePid > 0) {
            // nosemgrep: php.lang.security.exec-use.exec-use
            $kids = trim((string) @shell_exec('pgrep -P ' . $panePid . ' 2>/dev/null'));
            return $kids !== '';
        }
        return false;
    }

    /**
     * True for the bare interactive/run-loop shells the pane parks on when the
     * agent has exited (bash/sh/zsh/dash). The agent foreground command is never
     * one of these (it's node/claude/the agent binary basename).
     */
    private static function isBareShellCommand(string $cmd): bool {
        return in_array($cmd, ['bash', 'sh', 'zsh', 'dash', '-bash', '-sh', '-zsh'], true);
    }

    /**
     * Checks if a specific terminal session is currently running.
     *
     * R-B3: "running" now means the AGENT is up — i.e. a live detached tmux
     * session exists for this id (the browser-independent signal) OR a ttyd is
     * bound to the session's socket. A headless auto-launch/relaunch holds the
     * agent in a detached tmux session with no browser/ttyd children, so the old
     * "ttyd exists" test falsely reported failure (AutoLaunch) and let the
     * orphan-sweep reap it. Either signal alive => running.
     *
     * @param string $id The session ID.
     * @return bool True if running.
     * @phpstan-impure Live process probe — the result legitimately changes
     *                 between calls (e.g. before/after startTerminal), so
     *                 phpstan must not narrow repeated calls to one value.
     */
    public static function isRunning($id = 'default') {
        $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);

        // R-B2/B3 + CRITICAL-1: a detached tmux session means the agent is up
        // regardless of any browser/ttyd — but ONLY if the pane actually hosts a
        // live agent. A zombie session (agent dead, run-loop parked on the shell)
        // must NOT report running, or it leaks forever and blocks relaunch.
        if (self::tmuxSessionHasLiveAgent($id)) {
            return true;
        }

        $sock = "/var/run/aicliterm-$id.sock";
        if (!file_exists($sock)) {
            return false;
        }

        $escapedSock = escapeshellarg($sock);
        $pids = [];
        exec("pgrep -f \"ttyd.*$escapedSock\" 2>/dev/null", $pids);

        return !empty($pids);
    }

    /**
     * IMPORTANT-1 (startTerminal early-return regression): true only when a REAL
     * ttyd is actually bound to this session's socket. isRunning() now reports true
     * for a detached tmux session with NO ttyd (the relaunch-survival state), so
     * startTerminal must NOT early-return on isRunning — if ttyd died but tmux
     * survived, the browser has no socket and we must (re)launch ttyd against the
     * existing detached session (ensure-session is idempotent → it attaches, never
     * double-spawns the agent).
     *
     * @phpstan-impure Live ttyd probe.
     */
    public static function isTtydBound($id = 'default'): bool {
        $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
        $sock = "/var/run/aicliterm-$id.sock";
        if (!file_exists($sock)) {
            return false;
        }
        return self::findTtydPidForSock($sock) !== null;
    }

    /**
     * Stops a terminal session and cleans up its artifacts.
     * @param string $id The session ID.
     * @param bool $killTmux Whether to also kill the associated tmux session.
     * @param string $reason WORKSPACE_LIFECYCLE_EVENTS.md `stopped` reason:
     *   graceful_close|stop|evict|upgrade. Every stop/evict/orphan-reap path
     *   funnels through this one method, so the publish lives here once
     *   rather than at every call site.
     */
    public static function stopTerminal($id = 'default', $killTmux = false, string $reason = 'stop') {
        $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
        LogService::log("Initiating termination sequence for session: $id...", LogService::LOG_INFO, "ProcessManager");
        
        $sock = "/var/run/aicliterm-$id.sock";
        $pidFile = "/var/run/unraid-aicliagents-$id.pid";
        
        // 1. Kill ttyd
        $pids = [];
        exec("pgrep -x ttyd | xargs -I {} ps -p {} -o pid=,args= | grep " . escapeshellarg($sock) . " | awk '{print $1}'", $pids);
        foreach ($pids as $pid) {
            $pid = trim($pid);
            if (ctype_digit($pid)) {
                exec("kill -15 $pid > /dev/null 2>&1; sleep 0.2; kill -9 $pid > /dev/null 2>&1");
            }
        }
        
        // 2. Kill agent processes (Node)
        $nodePids = [];
        $escapedId = escapeshellarg("AICLI_SESSION_ID=$id");
        exec("pgrep -f $escapedId 2>/dev/null", $nodePids);
        foreach ($nodePids as $np) {
            $np = trim($np);
            if (ctype_digit($np)) {
                exec("kill -15 $np > /dev/null 2>&1; sleep 0.2; kill -9 $np > /dev/null 2>&1");
            }
        }

        // #159: the pgrep -f above matches the ARGV, which only the runuser/ttyd/
        // tmux wrappers carry (they name AICLI_SESSION_ID=<id> on their command
        // line). A detached agent binary that reparented to PID 1 has a bare
        // binary path for argv, so it slips through — and this teardown is the
        // periodic orphan sweep's reaper (sweepOrphanSessions), so such a process
        // would never be reaped. terminateSessionDescendants reads the actual
        // ENVIRONMENT (/proc/<pid>/environ), reaping the reparented binary too.
        // Env-scoped => safe (it skips tmux servers and respects launch identity).
        self::terminateSessionDescendants($id);

        // D-319: Introduce brief delay before socket removal to allow processes to finish writes
        usleep(500000); // 0.5s

        // 3. Artifact Cleanup
        if (file_exists($sock)) {
            @unlink($sock);
        }
        if (file_exists($pidFile)) {
            @unlink($pidFile);
        }
        // UPGRADE_ACTIVATION_WITHOUT_CLOSED_SET.md §Event: remember which agent this
        // session ran BEFORE the runfile goes, so the close can nudge a pending
        // layer activation for it (below) instead of waiting out the retry backoff.
        $closedAgentId = trim((string)@file_get_contents("/var/run/unraid-aicliagents-$id.agentid"));
        @unlink("/var/run/unraid-aicliagents-$id.chatid");
        @unlink("/var/run/unraid-aicliagents-$id.agentid");
        @unlink("/var/run/unraid-aicliagents-$id.user");

        if ($killTmux) {
            // Non-root audit: iterate per-uid tmux sockets so non-root
            // sessions get killed too. findTmuxSessionForId returns plain
            // 'tmux' tmuxBin when nothing matches — falls through harmlessly.
            [$sessName, $tmuxSock, $tmuxBin] = self::findTmuxSessionForId($id);
            if ($sessName !== '') {
                $escSess = escapeshellarg($sessName);
                // nosemgrep: php.lang.security.exec-use.exec-use
                @shell_exec("$tmuxBin kill-session -t $escSess > /dev/null 2>&1");
            }
            // The session's own tmux server exits with its last session, but
            // its s-<id> socket folder stayed behind: 97 of them had piled up
            // on .4 by 2026-09-24. Remove it once no server answers there.
            self::removeDeadSessionTmuxDir((string)$id);
        }
        
        // #218: the close is over — drop the intent mark on every path that
        // ends one, not only the graceful handler, so a mark can never outlive
        // the close that set it.
        \AICliAgents\Services\TerminalService::clearClosing((string)$id);
        LogService::log("Successfully closed terminal session and purged associated runfiles for $id.", LogService::LOG_INFO, "ProcessManager");

        // WORKSPACE_LIFECYCLE_EVENTS.md: the session is actually down now —
        // announce it once, from the one place every stop/evict/orphan-reap
        // path funnels through.
        self::publishStoppedEvent($id, $reason);

        // DRAWER_RESTART_AS_NEW.md: a closed session's "fresh context" marker no
        // longer describes anything — the next launch resumes (or restart-fresh
        // re-marks it after this close). Best-effort.
        if (class_exists('\AICliAgents\Services\AgentRelayService')) {
            try { AgentRelayService::clearConversationRestart($id); } catch (\Throwable $e) { /* never break the close */ }
        }

        // Close event → immediate activation attempt (the supervisor tick's idle
        // probe and the backoff pen stay as the fallback). Best-effort, never
        // lets a nudge failure break the close.
        if ($closedAgentId !== '' && preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $closedAgentId)) {
            try {
                require_once __DIR__ . '/UpgradeRelaunchService.php';
                if (UpgradeRelaunchService::nudgeActivation($closedAgentId)) {
                    LogService::log("Session $id closed — nudged pending layer activation for $closedAgentId.", LogService::LOG_INFO, "ProcessManager");
                }
            } catch (\Throwable $e) {
                LogService::log("Activation nudge for $closedAgentId skipped: " . $e->getMessage(), LogService::LOG_WARN, "ProcessManager");
            }
        }
    }

    /**
     * Terminates all AI-related processes (Aggressive).
     */
    public static function evictAll() {
        LogService::log("EVICTOR: Terminating ALL AI sessions...", LogService::LOG_WARN, "ProcessManager");
        // WORKSPACE_LIFECYCLE_EVENTS.md: a global evict has no per-id capture
        // path like evictTargeted's stopTerminal loop, so read the ids about
        // to die from the registry BEFORE the raw kill, then announce each
        // as stopped once the kill has actually run. Best-effort — a
        // registry read failure must not block the evict itself.
        $ids = [];
        if (class_exists('\\AICliAgents\\Services\\ConfigService')) {
            try {
                foreach ((ConfigService::getWorkspaces()['sessions'] ?? []) as $s) {
                    if (is_array($s) && !empty($s['id'])) $ids[] = (string)$s['id'];
                }
            } catch (\Throwable $e) {
                // Best-effort — proceed with the evict even with no ids to announce.
            }
        }
        // Bug #297: this used to be a bare `pgrep -f '(ttyd|...)'`, which
        // matched (and killed) EVERY ttyd on the host, including Unraid's own
        // web terminal. evictKillPattern() matches only this plugin's own
        // ttyd/tmux processes — see EVICT_TTYD_PATTERN above.
        exec("pgrep -f '" . self::evictKillPattern() . "' | xargs kill -9 > /dev/null 2>&1");
        foreach ($ids as $id) {
            self::publishStoppedEvent($id, 'evict');
            // Fix 2026-09-12: a global evict is an operator/tool decision to
            // close everything. Auto-launch must not bring these workspaces
            // straight back on the next page load. See
            // docs/specs/2026-04-27-auto-launch-workspaces-design.md.
            AutoLaunchSuppression::suppress($id);
        }
    }

    /**
     * Terminates specific AI sessions by ID.
     */
    public static function evictTargeted($ids) {
        if (empty($ids)) {
            return;
        }
        $idArray = explode(',', $ids);
        foreach ($idArray as $id) {
            $id = trim($id);
            if (empty($id)) {
                continue;
            }
            // R5 (CAPTURE_RESUME_ALL_CLOSE_PATHS): harden this latent path so a
            // future caller wiring it onto a LIVE session can't lose resume.
            // Fast disk-fallback capture (discoverLatestSessionId -> saveResumeId)
            // BEFORE the destructive stopTerminal. Best-effort, never throws.
            self::captureFallbackBeforeKill($id);
            LogService::log("EVICTOR: Terminating specific session: $id", LogService::LOG_INFO, "ProcessManager");
            self::stopTerminal($id, true, 'evict');
            // Fix 2026-09-12: a targeted evict is an operator/tool decision to
            // close this workspace. Auto-launch must not bring it straight back
            // on the next page load. See
            // docs/specs/2026-04-27-auto-launch-workspaces-design.md.
            AutoLaunchSuppression::suppress($id);
        }
    }

    /**
     * WORKSPACE_LIFECYCLE_EVENTS.md: announce a workspace going down. Shared
     * by stopTerminal() (every stop/evict/orphan-reap path) and evictAll()
     * (which has no single id to hand stopTerminal). Best-effort — never
     * blocks or throws.
     */
    private static function publishStoppedEvent(string $id, string $reason): void {
        if ($id === '' || !class_exists('\\AICliAgents\\Services\\NchanService')) return;
        EventBus::publish('workspace', [], ['event' => 'stopped', 'id' => $id, 'reason' => $reason]);
    }

    /**
     * R5 helper: fast disk-fallback resume capture for ONE session id, read from
     * the session's /var/run metadata (agent id + workspace path). Used by the
     * latent hard-kill paths (evictTargeted) so resume survives even if they are
     * ever invoked on a live session. Best-effort: never throws.
     */
    public static function captureFallbackBeforeKill(string $id): void
    {
        $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
        if ($safeId === '') return;
        try {
            $agentFile   = UtilityService::getAgentIdPath($safeId);
            $workdirFile = UtilityService::getWorkDirFilePath($safeId);
            $agentId = is_file($agentFile)   ? trim((string)@file_get_contents($agentFile))   : '';
            $path    = is_file($workdirFile) ? trim((string)@file_get_contents($workdirFile)) : '';
            if ($agentId === '' || $path === '') return;
            if (!class_exists('\\AICliAgents\\Handlers\\TerminalHandler', false)) {
                require_once __DIR__ . '/../handlers/TerminalHandler.php';
            }
            $diskId = \AICliAgents\Handlers\TerminalHandler::discoverLatestSessionId($agentId, $path);
            // Disk-only (newest on disk): guarded on a shared folder (RESUME_IDENTITY_PER_WORKSPACE.md V3).
            if ($diskId !== null && $diskId !== '' && ConfigService::saveDiskFallbackResumeId($path, $agentId, $diskId, $safeId)) {
                LogService::log(
                    "captureFallbackBeforeKill: saved resume_id=$diskId for (workspace=$path, agent=$agentId) before evict (R5)",
                    LogService::LOG_INFO, "ProcessManager"
                );
            }
        } catch (\Throwable $e) {
            LogService::log(
                "captureFallbackBeforeKill: failed for session=$safeId — " . $e->getMessage(),
                LogService::LOG_WARN, "ProcessManager"
            );
        }
    }

    /**
     * Non-root audit: locate a tmux session whose name ends in '-<safeId>'
     * across every per-uid tmux socket under the plugin's TMUX_TMPDIR.
     * PHP runs as root; non-root agent sessions live in tmux-<other-uid>/
     * default and are invisible from root's default tmux client.
     *
     * Returns [sessionName, socketPath, tmuxBin]. tmuxBin is the prefix
     * to use for all subsequent tmux invocations on the resolved session
     * (e.g. 'tmux -S /tmp/.../tmux-1003/default send-keys ...'). Empty
     * values + plain 'tmux' when no session matches anywhere.
     *
     * Cost (2026-09-24): the session's OWN per-session socket is asked first,
     * and a socket whose server is gone is skipped with an in-process
     * connect() instead of a shell + tmux spawn. The orphan sweep calls this
     * three times per open browser terminal on every list_sessions_for_agent,
     * and with 8 terminals and the dead socket files e2e runs leave behind a
     * full scan cost 2-5 s per call (the Store's install dialogs wait on it).
     */
    public static function findTmuxSessionForId(string $safeId): array {
        $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '', $safeId);
        if ($safeId === '') return ['', '', 'tmux'];
        foreach (self::sessionSocketCandidates($safeId) as $sock) {
            if (!self::isLiveUnixSocket($sock)) continue;
            $cmd = 'tmux -S ' . escapeshellarg($sock) . " ls -F '#S' 2>/dev/null | grep -- '-" . escapeshellarg($safeId) . "\$' | head -n1";
            // nosemgrep: php.lang.security.exec-use.exec-use
            $name = trim((string) @shell_exec($cmd));
            $name = trim($name, "' \t\n");
            if ($name !== '') {
                return [$name, $sock, 'tmux -S ' . escapeshellarg($sock)];
            }
        }
        return ['', '', 'tmux'];
    }

    /**
     * The tmux sockets to search for session $safeId, in search order: its
     * own per-session server(s) (<root>/s-<id>/tmux-<uid>/default) first,
     * then every other socket (other per-session servers and the legacy
     * shared ones), each once.
     *
     * @return list<string>
     */
    public static function sessionSocketCandidates(string $safeId, string $root = self::TMUX_ROOT): array {
        $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '', $safeId);
        $all = self::tmuxSocketPaths($root);
        if ($safeId === '') return $all;
        $ownPrefix = $root . '/s-' . $safeId . '/';
        $own = [];
        $rest = [];
        foreach ($all as $sock) {
            if (strncmp($sock, $ownPrefix, strlen($ownPrefix)) === 0) $own[] = $sock;
            else $rest[] = $sock;
        }
        return array_merge($own, $rest);
    }

    /**
     * True when a server listens on the unix socket $path. A socket file
     * outlives its tmux server; connect() on it refuses at once, so a dead
     * server costs no process spawn.
     */
    public static function isLiveUnixSocket(string $path): bool {
        $c = @stream_socket_client('unix://' . $path, $errno, $errstr, 0.2);
        if ($c === false) return false;
        @fclose($c);
        return true;
    }

    /**
     * Non-root audit: kill every aicli-agent-* tmux session across every
     * per-uid tmux server. Used by stop-plugin / uninstall / boot-cleanup
     * paths that need a clean slate regardless of which user owns the
     * sessions.
     */
    public static function killAllAgentSessions(): void {
        foreach (self::tmuxSocketPaths() as $sock) {
            $tmuxBin = 'tmux -S ' . escapeshellarg($sock);
            $cmd = "$tmuxBin ls -F '#S' 2>/dev/null | grep -E '^aicli-agent-' | xargs -r -I {} $tmuxBin kill-session -t {} > /dev/null 2>&1";
            // nosemgrep: php.lang.security.exec-use.exec-use
            @shell_exec($cmd);
        }
    }

    /**
     * Bug #1067: reconcile /var/run/aicliterm-*.sock against live ttyd processes.
     * Orphan classes detected and cleaned:
     *   - no_ttyd       : sock file exists but no ttyd process for it -> unlink artefacts.
     *   - no_children   : ttyd alive but pgrep -P <pid> empty (session child exited) -> stopTerminal.
     *
     * Skips very-fresh sock files (default 30s grace) -- a ttyd that just launched
     * but hasn't been attached to yet legitimately has no children.
     *
     * Returns ['killed' => [...{id,reason}], 'kept' => [...{id,reason}]].
     */
    public static function sweepOrphanSessions(int $minAgeSeconds = 30): array {
        $killed = [];
        $kept = [];
        $socks = glob('/var/run/aicliterm-*.sock') ?: [];
        foreach ($socks as $sock) {
            if (!preg_match('/aicliterm-(.*)\.sock$/', $sock, $m)) continue;
            $id = $m[1];

            $sockAge = time() - ((int)@filemtime($sock) ?: time());
            $verdict = self::classifyOrphanSession($id, $sock, $sockAge, $minAgeSeconds);

            if ($verdict['action'] === 'keep') {
                $kept[] = $verdict['record'];
                continue;
            }

            // action === 'reap' — perform the side effects the classification dictates.
            $reason = $verdict['record']['reason'];
            if ($reason === 'no_ttyd') {
                LogService::log("Bug #1067: sock without live ttyd (sid=$id) -- unlinking artefacts", LogService::LOG_INFO, 'ProcessManager');
                self::cleanupArtifacts($id);
                // WORKSPACE_LIFECYCLE_EVENTS.md: this unlinks the session's web
                // bridge artefacts with no relaunch pending. Best-effort — the
                // bridge is already gone by this point, so this ordinarily
                // publishes nothing (see publishBridgeEvent); it exists so a
                // future artefact ordering change still gets the announcement.
                self::publishBridgeEvent($id);
            } elseif ($reason === 'tmux_zombie') {
                // CRITICAL-1: detached tmux session whose pane is parked on the
                // dead-agent run-loop shell. stopTerminal(killTmux=true) kills the
                // zombie session + purges artefacts so relaunch is unblocked.
                LogService::log("CRITICAL-1: zombie tmux session (sid=$id) -- pane parked, agent dead -- killing session + artefacts", LogService::LOG_INFO, 'ProcessManager');
                self::stopTerminal($id, true);
            } else { // no_children
                $pid = $verdict['record']['ttyd_pid'] ?? '?';
                LogService::log("Bug #1067: orphan ttyd (pid=$pid sid=$id) has no children AND no tmux session -- terminating", LogService::LOG_INFO, 'ProcessManager');
                self::stopTerminal($id, true);
            }
            $killed[] = $verdict['record'];
        }
        if (!empty($killed) || !empty($kept)) {
            LogService::log('Bug #1067 sweep: killed=' . count($killed) . ' kept=' . count($kept), LogService::LOG_INFO, 'ProcessManager');
        }
        return ['killed' => $killed, 'kept' => $kept];
    }

    /**
     * PURE decision (no side effects) for one session in the orphan sweep — the
     * TDD seam for R-B2. Returns ['action' => 'keep'|'reap', 'record' => [...]].
     * The caller performs the actual cleanup/teardown for a 'reap'.
     *
     * Decision order:
     *   1. Grace period — a freshly-created sock (mid-launch race before ttyd
     *      forks, or ensure-session still doing synchronous birth work) is not yet
     *      stale; keep. The within-grace keep also covers the brief agent-restart
     *      window where the pane is momentarily the shell.
     *   2. CRITICAL-1: a detached tmux session for this sid is the headless
     *      auto-launch/relaunch state — but it only counts as ALIVE when its pane
     *      hosts a LIVE AGENT (tmuxSessionHasLiveAgent). A tmux session whose pane
     *      is PARKED on the run-loop shell (agent dead) past grace is a ZOMBIE and
     *      MUST be reaped — otherwise it leaks forever and blocks relaunch.
     *      (The 'terminal' agent's shell pane is excluded from the parked-shell
     *      reap inside tmuxSessionHasLiveAgent, so it stays alive.)
     *   3. ttyd present + live agent in pane — keep (tmux_alive).
     *   4. ttyd has children — a live attached client; keep.
     *   5. No live agent, no live ttyd, past grace — dead; reap.
     *
     * Reap reasons:
     *   - no_ttyd          : sock, no ttyd, no live agent.
     *   - tmux_zombie      : detached tmux session whose pane is parked (agent dead).
     *   - no_children      : ttyd alive but childless, no live agent.
     *
     * @return array{action:string, record:array<string,mixed>}
     */
    public static function classifyOrphanSession(string $id, string $sock, int $sockAge, int $minAgeSeconds = 30): array {
        if ($sockAge < $minAgeSeconds) {
            return ['action' => 'keep', 'record' => ['id' => $id, 'reason' => 'too_young', 'age_seconds' => $sockAge]];
        }

        $tmuxExists = self::hasLiveTmuxSession($id);
        $agentAlive = $tmuxExists && self::tmuxSessionHasLiveAgent($id);
        $ttydPid = self::findTtydPidForSock($sock);

        if ($ttydPid === null) {
            if ($agentAlive) {
                return ['action' => 'keep', 'record' => ['id' => $id, 'reason' => 'tmux_detached']];
            }
            if ($tmuxExists) {
                // Session exists but pane is parked on the dead-agent shell — zombie.
                return ['action' => 'reap', 'record' => ['id' => $id, 'reason' => 'tmux_zombie']];
            }
            return ['action' => 'reap', 'record' => ['id' => $id, 'reason' => 'no_ttyd']];
        }

        if ($agentAlive) {
            return ['action' => 'keep', 'record' => ['id' => $id, 'reason' => 'tmux_alive', 'ttyd_pid' => $ttydPid]];
        }

        if (self::ttydHasChildren($ttydPid)) {
            return ['action' => 'keep', 'record' => ['id' => $id, 'reason' => 'live', 'ttyd_pid' => $ttydPid]];
        }

        // ttyd childless AND no live agent. If a (zombie) tmux session is still
        // hanging around, tear it down too via stopTerminal(killTmux=true).
        $reason = $tmuxExists ? 'tmux_zombie' : 'no_children';
        return ['action' => 'reap', 'record' => ['id' => $id, 'reason' => $reason, 'ttyd_pid' => $ttydPid]];
    }

    /**
     * Find the ttyd PID owning a given UNIX socket path. Returns null when no
     * ttyd process has that sock on its cmdline.
     */
    private static function findTtydPidForSock(string $sock): ?int {
        if (is_callable(self::$ttydPidProbe)) {
            $v = call_user_func(self::$ttydPidProbe, $sock);
            return $v === null ? null : (int) $v;
        }
        // nosemgrep: php.lang.security.exec-use.exec-use
        $out = trim((string) @shell_exec('pgrep -f ' . escapeshellarg('ttyd.*' . $sock)));
        if ($out === '') return null;
        foreach (explode("\n", $out) as $line) {
            $pid = (int) trim($line);
            if ($pid > 0) return $pid;
        }
        return null;
    }

    /**
     * pgrep -P -- returns true if the given PID has at least one child.
     */
    private static function ttydHasChildren(int $ttydPid): bool {
        if (is_callable(self::$ttydChildrenProbe)) {
            return (bool) call_user_func(self::$ttydChildrenProbe, $ttydPid);
        }
        // nosemgrep: php.lang.security.exec-use.exec-use
        $out = trim((string) @shell_exec('pgrep -P ' . (int)$ttydPid));
        return $out !== '';
    }

    /**
     * Unlink the /var/run/* metadata files for a given session id. Used when
     * ttyd is already dead so stopTerminal would have nothing to kill.
     */
    private static function cleanupArtifacts(string $id): void {
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
        @unlink("/var/run/aicliterm-{$safe}.sock");
        @unlink("/var/run/unraid-aicliagents-{$safe}.pid");
        @unlink("/var/run/unraid-aicliagents-{$safe}.chatid");
        @unlink("/var/run/unraid-aicliagents-{$safe}.agentid");
        @unlink("/var/run/unraid-aicliagents-{$safe}.workdir");
        @unlink("/var/run/unraid-aicliagents-{$safe}.user");
    }
}
