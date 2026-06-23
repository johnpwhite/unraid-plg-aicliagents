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

    /**
     * Reset all test seams to their live-probe defaults. Call in tearDown.
     */
    public static function resetProbes(): void {
        self::$tmuxLivenessProbe = null;
        self::$ttydPidProbe = null;
        self::$ttydChildrenProbe = null;
        self::$paneLivenessProbe = null;
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
     */
    public static function stopTerminal($id = 'default', $killTmux = false) {
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
        
        // D-319: Introduce brief delay before socket removal to allow processes to finish writes
        usleep(500000); // 0.5s

        // 3. Artifact Cleanup
        if (file_exists($sock)) {
            @unlink($sock);
        }
        if (file_exists($pidFile)) {
            @unlink($pidFile);
        }
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
        }
        
        LogService::log("Successfully closed terminal session and purged associated runfiles for $id.", LogService::LOG_INFO, "ProcessManager");
    }

    /**
     * Terminates all AI-related processes (Aggressive).
     */
    public static function evictAll() {
        LogService::log("EVICTOR: Terminating ALL AI sessions...", LogService::LOG_WARN, "ProcessManager");
        exec("pgrep -f '(ttyd|aicliterm|geminiterm|tmux.*aicli-agent-)' | xargs kill -9 > /dev/null 2>&1");
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
            self::stopTerminal($id, true);
        }
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
            if ($diskId !== null && $diskId !== '') {
                ConfigService::saveResumeId($path, $agentId, $diskId);
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
     */
    public static function findTmuxSessionForId(string $safeId): array {
        $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '', $safeId);
        if ($safeId === '') return ['', '', 'tmux'];
        foreach (glob('/tmp/unraid-aicliagents/tmux/tmux-*', GLOB_ONLYDIR) ?: [] as $perUserDir) {
            $sock = $perUserDir . '/default';
            if (!file_exists($sock)) continue;
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
     * Non-root audit: kill every aicli-agent-* tmux session across every
     * per-uid tmux server. Used by stop-plugin / uninstall / boot-cleanup
     * paths that need a clean slate regardless of which user owns the
     * sessions.
     */
    public static function killAllAgentSessions(): void {
        foreach (glob('/tmp/unraid-aicliagents/tmux/tmux-*', GLOB_ONLYDIR) ?: [] as $perUserDir) {
            $sock = $perUserDir . '/default';
            if (!file_exists($sock)) continue;
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
