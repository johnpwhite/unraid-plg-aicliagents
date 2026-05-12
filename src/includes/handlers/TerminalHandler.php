<?php
/**
 * <module_context>
 *     <name>TerminalHandler</name>
 *     <description>Handles terminal session AJAX actions: start, stop, restart, chat, logging.</description>
 *     <dependencies>AICliAgentsManager, ValidationService</dependencies>
 *     <constraints>Under 150 lines. Each method returns array for JSON encoding.</constraints>
 * </module_context>
 */

namespace AICliAgents\Handlers;

use AICliAgents\Services\ValidationService;

class TerminalHandler {

    public static function handle($action, $id) {
        switch ($action) {
            case 'start':            return self::start($id);
            case 'emergency_start':  return self::emergencyStart($id);
            case 'stop':             return self::stop($id);
            case 'graceful_close':   return self::gracefulClose($id);
            case 'restart':          return self::restart($id);
            case 'agent_signal_reload': return self::agentSignalReload($id);
            case 'get_chat_session': return self::getChatSession();
            case 'get_resume_id':    return self::getResumeId();
            case 'log':              return self::log();
            case 'get_log':          return self::getLog();
            case 'clear_log':        return self::clearLog();
            case 'list_sessions_for_agent': return self::listSessionsForAgent();
            default:                 return null;
        }
    }

    /** Actions handled by this handler. */
    public static function actions() {
        return ['start', 'emergency_start', 'stop', 'graceful_close', 'restart', 'agent_signal_reload', 'get_chat_session', 'get_resume_id', 'log', 'get_log', 'clear_log', 'list_sessions_for_agent'];
    }

    /**
     * Return active workspace sessions whose agent matches ?agentId=... — used
     * by the Store card's install confirm dialog to list which sessions will
     * be gracefully closed before the upgrade runs, and by the New Workspace
     * overlay / Drawer to mark in-progress-upgrade agents busy.
     */
    private static function listSessionsForAgent() {
        $agentId = $_GET['agentId'] ?? '';
        if (empty($agentId)) {
            return ['status' => 'error', 'message' => 'agentId required'];
        }
        // Defensive whitelist: agent ids in the registry are [a-z0-9-].
        // Anything else can't match a session so bail cheap.
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/i', $agentId)) {
            return ['status' => 'error', 'message' => 'invalid agentId'];
        }
        return [
            'status'   => 'ok',
            'agentId'  => $agentId,
            'sessions' => \AICliAgents\Services\TerminalService::listActiveSessionsForAgent($agentId),
        ];
    }

    private static function start($id) {
        $config = getAICliConfig();
        $persistPath = $config['agent_storage_path'] ?? '/boot/config/plugins/unraid-aicliagents';
        $agentId = $_GET['agentId'] ?? 'gemini-cli';
        $workspacePath = $_GET['path'] ?? null;

        // Check 1: Is the home storage path available?
        $homePath = $config['home_storage_path'] ?? $persistPath;
        if (!\AICliAgents\Services\StorageMountService::isPathAvailable($homePath)) {
            $classification = \AICliAgents\Services\StorageMountService::classifyPath($homePath);

            // Can we mount the agent? Check if agent sqsh files exist (on Flash or another available path)
            $agentAvailable = \AICliAgents\Services\StorageMountService::isPathAvailable($persistPath)
                && count(glob("$persistPath/agent_{$agentId}_*.sqsh")) > 0;

            return [
                'status' => 'error',
                'reason' => $agentAvailable ? 'home_unavailable' : 'storage_unavailable',
                'message' => $agentAvailable
                    ? 'Home storage is not available. An emergency session with a temporary home is available.'
                    : 'Storage path is not currently accessible. Start the array or check your storage configuration.',
                'path' => $homePath,
                'classification' => $classification,
                'emergency_possible' => $agentAvailable,
            ];
        }

        // Check 2: Is the workspace path (where the agent will work) available?
        if (!empty($workspacePath) && !\AICliAgents\Services\StorageMountService::isPathAvailable($workspacePath)) {
            $wsClassification = \AICliAgents\Services\StorageMountService::classifyPath($workspacePath);
            return [
                'status' => 'error',
                'reason' => 'workspace_unavailable',
                'message' => "Workspace path is not currently accessible. The "
                    . ($wsClassification === 'array' ? 'array' : (strpos($wsClassification, 'pool:') === 0 ? substr($wsClassification, 5) . ' pool' : 'storage'))
                    . ' may need to be started.',
                'path' => $workspacePath,
                'classification' => $wsClassification,
                'emergency_possible' => false,
            ];
        }

        // Resume flag: if the user clicked "Resume" in the new-session overlay,
        // pass the sentinel 'auto' so TerminalService looks up the ID saved at
        // the previous clean close. Explicit chatId (if any) still wins.
        $chatId = $_GET['chatId'] ?? null;
        if (empty($chatId) && !empty($_GET['resume'])) {
            $chatId = 'auto';
        }
        startAICliTerminal($id, $workspacePath, $chatId, $agentId);
        return ['status' => 'ok', 'sock' => "/webterminal/aicliterm-$id/"];
    }

    /**
     * Emergency session: agent storage available but home is not.
     * Creates a temporary RAM home and starts a single session.
     */
    private static function emergencyStart($id) {
        $config = getAICliConfig();
        $agentId = $_GET['agentId'] ?? 'gemini-cli';
        $path = $_GET['path'] ?? '/mnt';

        // Clean up any previous emergency state (allow starting fresh)
        if (\AICliAgents\Services\StorageMountService::isEmergencyMode()) {
            aicli_log("Cleaning previous emergency state before new session.", AICLI_LOG_INFO, "TerminalHandler");
            @unlink(\AICliAgents\Services\StorageMountService::EMERGENCY_FLAG);
        }
        // Also clean up any stale ttyd/tmux from failed previous attempts
        exec("pkill -9 -f 'aicli-run-' 2>/dev/null");
        exec("pkill -9 -f 'ttyd.*aicliterm-' 2>/dev/null");
        if (function_exists('posix_kill')) {
            foreach (glob("/var/run/aicliterm-*.sock") as $sock) @unlink($sock);
            foreach (glob("/var/run/unraid-aicliagents-*.pid") as $pid) @unlink($pid);
        }
        usleep(500000); // 0.5s for process cleanup

        $user = $config['user'] ?? 'root';
        if ($user === '0' || empty($user)) $user = 'root';

        // Create temporary home directory structure
        $emergencyHome = \AICliAgents\Services\StorageMountService::EMERGENCY_HOME;
        @mkdir("$emergencyHome/.aicli/envs", 0755, true);

        aicli_log("EMERGENCY MODE: Starting session with temp home at $emergencyHome", AICLI_LOG_WARN, "TerminalHandler");

        // Set up work dir → emergency home symlink
        // Must remove whatever is at work/root/home (stale mount point, old dir, or previous symlink)
        $workDir = \AICliAgents\Services\UtilityService::getWorkDir($user);
        @mkdir($workDir, 0755, true);
        $homeLink = "$workDir/home";

        if (is_link($homeLink)) {
            @unlink($homeLink);
        } elseif (is_dir($homeLink) && !\AICliAgents\Services\StorageMountService::isMounted($homeLink)) {
            // Stale directory from previous overlay (may contain ZRAM leftovers) — safe to remove
            exec("rm -rf " . escapeshellarg($homeLink));
        }

        if (!file_exists($homeLink)) {
            symlink($emergencyHome, $homeLink);
            aicli_log("Emergency home symlink: $homeLink → $emergencyHome", AICLI_LOG_INFO, "TerminalHandler");
        } else {
            aicli_log("WARNING: Could not create emergency home symlink — $homeLink still exists", AICLI_LOG_WARN, "TerminalHandler");
        }

        // Ensure agent is available — try sqsh mount first, fall back to checking if binary exists in RAM
        if ($agentId !== 'terminal') {
            $registry = \AICliAgents\Services\AgentRegistry::getRegistry();
            $agentBinary = $registry[$agentId]['binary'] ?? '';
            $binaryExists = !empty($agentBinary) && file_exists($agentBinary);

            if (!$binaryExists) {
                // Binary not in RAM — try normal sqsh mount
                $agentMounted = \AICliAgents\Services\StorageMountService::ensureAgentMounted($agentId);
                if (!$agentMounted) {
                    return ['status' => 'error', 'message' => "Agent $agentId is not available. Install it to RAM first via the emergency installer."];
                }
            } else {
                aicli_log("Emergency: Agent $agentId binary found in RAM, skipping sqsh mount.", AICLI_LOG_INFO, "TerminalHandler");
            }
        }

        // Set emergency flag BEFORE starting terminal — ensureHomeMounted checks this flag
        // to recognize the symlink as a valid home mount
        touch(\AICliAgents\Services\StorageMountService::EMERGENCY_FLAG);

        // Start terminal (home is now symlinked to emergency dir, ensureHomeMounted sees the flag)
        startAICliTerminal($id, $path, null, $agentId);

        return ['status' => 'ok', 'sock' => "/webterminal/aicliterm-$id/", 'emergency' => true];
    }

    private static function stop($id) {
        stopAICliTerminal($id, isset($_GET['hard']));
        return ['status' => 'ok'];
    }

    /**
     * Graceful close: sends Ctrl-C twice to let the agent flush state, scrapes
     * the exit screen for a resume ID, persists it for the (path, agent) pair,
     * then allows the shell's outer while-loop to exit via a sentinel flag.
     * Falls back to hard stop if the session does not exit within 3s.
     *
     * All shell arguments are either fixed constants or escapeshellarg'd. The
     * session id is preg_replace'd to alnum+_- only, so no unsafe data can
     * reach any command line.
     */
    private static function gracefulClose($id) {
        $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
        $path = $_GET['path'] ?? '';
        $agentId = $_GET['agentId'] ?? '';

        // Every log line in this flow gets the same (session, agent, workspace)
        // prefix so the close sequence is grep-able from /var/log without
        // cross-referencing unrelated timestamps.
        $ctx = sprintf("session=%s agent=%s workspace=%s",
            $safeId,
            $agentId !== '' ? $agentId : 'unknown',
            $path !== '' ? $path : 'unknown'
        );
        aicli_log("gracefulClose: START $ctx", AICLI_LOG_INFO, "TerminalHandler");

        // Locate the tmux session matching -<id> suffix.
        $findCmd = "tmux ls -F '#S' 2>/dev/null | grep -- '-" . escapeshellarg($safeId) . "\$' | head -n1";
        $sessName = trim((string) shell_exec($findCmd));
        $sessName = trim($sessName, "' \t\n");

        $capturedId = null;

        if (!empty($sessName)) {
            $escSess = escapeshellarg($sessName);
            aicli_log("gracefulClose: tmux session resolved as '$sessName' | $ctx", AICLI_LOG_DEBUG, "TerminalHandler");

            @mkdir('/tmp/unraid-aicliagents', 0755, true);

            // Force the tmux window to 220 cols BEFORE Ctrl-C. After ttyd
            // disconnects, the window can shrink to the last negotiated size
            // (often 80 cols or narrower), which wraps copilot's UUID onto two
            // lines in a way tmux's -J flag cannot always re-join cleanly.
            // Resizing here guarantees the full resume line fits on one row.
            @shell_exec("tmux resize-window -t $escSess -x 220 -y 50 2>/dev/null");

            // Two Ctrl-Cs covers both single-press (opencode) and double-press
            // (claude/gemini/copilot/kilo) exit conventions. The second key on
            // single-press agents lands on the post-exit shell as a no-op.
            @shell_exec("tmux send-keys -t $escSess C-c 2>/dev/null");
            usleep(150000);
            @shell_exec("tmux send-keys -t $escSess C-c 2>/dev/null");
            aicli_log("gracefulClose: sent Ctrl-C x2 | $ctx", AICLI_LOG_DEBUG, "TerminalHandler");

            // Capture with up to 3 retries - agents that stream their exit
            // screen character-by-character can be caught mid-render on the
            // first attempt. If we get a short-looking id, wait and re-capture.
            //
            // Claude Code also supports custom session names (via /rename),
            // in which case the resume line prints the name instead of a UUID
            // - e.g.  claude --resume "John's coding session"  - so the regex
            // accepts three forms, tried in order per match attempt:
            //   1. Full-length bare token ({20,}) - classic UUIDs, strongest
            //      signal. Always preferred when present.
            //   2. Double-quoted string ("...") - custom names with spaces
            //      or apostrophes. Content captured without the surrounding
            //      quotes; consumer must shell-escape on reuse.
            //   3. Single-quoted string ('...') - rare but legal bash quoting.
            //   4. Permissive bare token ({8,}) - short session ids (opencode,
            //      kilocode ses_xxx) and legacy shapes.
            $pane = '';
            $m = null;
            $regexFull   = '/(?:--resume[= ]|-s\s+)([A-Za-z0-9_-]{20,})/';
            $regexQuoted = '/(?:--resume[= ]|-s\s+)(?:"([^"\r\n]+)"|\'([^\'\r\n]+)\')/';
            $regexShort  = '/(?:--resume[= ]|-s\s+)([A-Za-z0-9_-]{8,})/';
            for ($attempt = 0; $attempt < 3; $attempt++) {
                usleep($attempt === 0 ? 1800000 : 1000000);
                $pane = (string) shell_exec("tmux capture-pane -p -J -S -200 -t $escSess 2>/dev/null");
                if (preg_match($regexFull, $pane, $m)) {
                    aicli_log("gracefulClose: captured full-length id on attempt " . ($attempt + 1) . " (" . strlen($pane) . " bytes of pane) | $ctx", AICLI_LOG_DEBUG, "TerminalHandler");
                    break;
                }
                if (preg_match($regexQuoted, $pane, $qm)) {
                    // Collapse either quote group into the standard $m[1] slot.
                    $m = [$qm[0], !empty($qm[1]) ? $qm[1] : ($qm[2] ?? '')];
                    aicli_log("gracefulClose: captured quoted name on attempt " . ($attempt + 1) . " | $ctx", AICLI_LOG_DEBUG, "TerminalHandler");
                    break;
                }
                aicli_log("gracefulClose: attempt " . ($attempt + 1) . " did not yield a full id yet (pane " . strlen($pane) . " bytes) | $ctx", AICLI_LOG_DEBUG, "TerminalHandler");
            }
            // Fall back to the permissive 8+ regex if none of the attempts
            // yielded a full-length id - some agents use short session ids.
            if (empty($m) && preg_match($regexShort, $pane, $m)) {
                aicli_log("gracefulClose: captured short id via fallback regex | $ctx", AICLI_LOG_DEBUG, "TerminalHandler");
            }

            if (!empty($m)) {
                $capturedId = $m[1];
            } else {
                // Agent-specific disk-based fallback for CLIs that don't print
                // a resume hint on exit (opencode). Looks up the most recent
                // session id from the agent's own metadata store.
                $capturedId = self::discoverLatestSessionId($agentId);
                if ($capturedId) {
                    aicli_log("gracefulClose: exit screen had no resume hint — discovered id=$capturedId from agent metadata | $ctx", AICLI_LOG_INFO, "TerminalHandler");
                }
            }

            if (!empty($capturedId)) {
                if (!empty($path) && !empty($agentId)) {
                    \AICliAgents\Services\ConfigService::saveResumeId($path, $agentId, $capturedId);
                    aicli_log("gracefulClose: saved resume_id=$capturedId for (workspace=$path, agent=$agentId) | $ctx", AICLI_LOG_INFO, "TerminalHandler");
                } else {
                    aicli_log("gracefulClose: captured resume_id=$capturedId but could not save (missing workspace or agent) | $ctx", AICLI_LOG_WARN, "TerminalHandler");
                }
            } else {
                aicli_log("gracefulClose: no resume_id found in exit screen or agent metadata | $ctx", AICLI_LOG_INFO, "TerminalHandler");
            }

            // NOW set the sentinel and unblock the shell's "Press ENTER" read
            // so the relaunch loop exits cleanly instead of timing out after
            // 10s. Order is critical: sentinel before Enter means the next
            // loop iteration breaks; the Enter just wakes the blocking read.
            @touch("/tmp/unraid-aicliagents/close-$safeId.flag");
            @shell_exec("tmux send-keys -t $escSess Enter 2>/dev/null");

            // Poll for the tmux session to actually exit. Up to 3s.
            $exited = false;
            for ($i = 0; $i < 30; $i++) {
                $still = trim((string) shell_exec("tmux has-session -t $escSess 2>/dev/null && echo y || echo n"));
                if ($still === 'n') { $exited = true; break; }
                usleep(100000);
            }
            if ($exited) {
                aicli_log("gracefulClose: tmux session '$sessName' exited cleanly | $ctx", AICLI_LOG_INFO, "TerminalHandler");
            } else {
                aicli_log("gracefulClose: tmux session '$sessName' did not exit within 3s — falling back to hard stop | $ctx", AICLI_LOG_WARN, "TerminalHandler");
            }
        } else {
            aicli_log("gracefulClose: no tmux session found for $ctx — proceeding to hard stop (session may have already died)", AICLI_LOG_WARN, "TerminalHandler");
        }

        // Whether the tmux session exited cleanly or not, run the standard stop
        // path to tear down ttyd + sockets + pid files.
        stopAICliTerminal($id, true);
        @unlink("/tmp/unraid-aicliagents/close-$safeId.flag");

        $resumeStr = $capturedId ? "resume_id=$capturedId" : "resume_id=none";
        aicli_log("gracefulClose: DONE $resumeStr | $ctx", AICLI_LOG_INFO, "TerminalHandler");

        // Session ended — enqueue a bake via the supervisor so the home
        // directory reaches Flash durably. The supervisor handles this
        // asynchronously; the AJAX response does not block on storage I/O.
        $config = getAICliConfig();
        $user = $config['user'] ?? 'root';
        if (empty($user)) {
            $user = 'root';
        }
        \AICliAgents\Services\SupervisorService::enqueue('home', $user, 'bake', 'workspace_close', 10);
        \AICliAgents\Services\LifecycleLogService::log(
            \AICliAgents\Services\LifecycleLogService::LEVEL_INFO,
            'gracefulClose',
            'workspace_close_bake_enqueued',
            ['user' => $user, 'session' => $safeId]
        );

        return ['status' => 'ok', 'resume_id' => $capturedId, 'baking' => true];
    }

    /**
     * Send Ctrl-C to the running agent in a tmux session WITHOUT tearing down
     * the tmux session itself. The aicli-shell.sh while-loop catches the
     * agent exit, refreshes effective args from disk (WP #273), and relaunches
     * with the new args. Used by the "Apply now" button in the workspace args
     * confirmation modal (WP #274).
     */
    private static function agentSignalReload($id) {
        $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
        // Same locate-by-suffix pattern as gracefulClose.
        $runShell = 'shell_exec';
        $findCmd = "tmux ls -F '#S' 2>/dev/null | grep -- '-" . escapeshellarg($safeId) . "\$' | head -n1";
        $sessName = trim((string) @$runShell($findCmd));
        $sessName = trim($sessName, "' \t\n");
        if (empty($sessName)) {
            aicli_log("agentSignalReload: no tmux session matching $id", AICLI_LOG_WARN, "TerminalHandler");
            return ['status' => 'error', 'message' => 'No active tmux session for ' . $safeId];
        }

        // WP #275: drop an auto-reload sentinel BEFORE the Ctrl-C so aicli-shell.sh
        // skips its "Press ENTER to reload" prompt. The user explicitly asked for
        // an immediate restart; they shouldn't have to confirm with a keypress.
        @mkdir('/tmp/unraid-aicliagents', 0755, true);
        $flagFile = '/tmp/unraid-aicliagents/auto-reload-' . $safeId . '.flag';
        @touch($flagFile);

        $escSess = escapeshellarg($sessName);
        @$runShell("tmux send-keys -t $escSess C-c 2>/dev/null");

        // WP #275a: do NOT blindly fire a second Ctrl-C 200ms later. If the agent
        // exits cleanly on the first Ctrl-C, the wrapper script consumes the flag
        // (rm-then-continue) and starts the next iteration almost immediately.
        // A second Ctrl-C lands somewhere in that next iteration — possibly in
        // bash itself between commands — which kills the wrapper and tears down
        // the tmux session entirely (ttyd then shows "Press ⏎ to Reconnect").
        // Poll for flag-consumed instead. If still present after 500ms, the agent
        // ignored the first Ctrl-C (Claude Code behaviour requires two), so send a
        // second one targeted at the still-running agent.
        $consumed = false;
        for ($i = 0; $i < 25; $i++) {
            usleep(20000); // 20ms × 25 = 500ms ceiling
            // PHP caches stat() per-path. Without clearing, repeated file_exists
            // checks against the same path return the FIRST observation forever
            // — meaning we'd miss the bash wrapper rm-ing the flag and always
            // think the agent ignored Ctrl-C. Pass the path so only that one
            // entry is invalidated (cheaper than a full clearstatcache()).
            clearstatcache(true, $flagFile);
            if (!file_exists($flagFile)) { $consumed = true; break; }
        }
        if (!$consumed) {
            @$runShell("tmux send-keys -t $escSess C-c 2>/dev/null");
            aicli_log("agentSignalReload: agent ignored first Ctrl-C; sent second to $sessName for $id", AICLI_LOG_INFO, "TerminalHandler");
        } else {
            aicli_log("agentSignalReload: clean exit on first Ctrl-C for $sessName ($id), no second needed", AICLI_LOG_INFO, "TerminalHandler");
        }

        return ['status' => 'ok', 'session' => $sessName, 'second_ctrl_c' => !$consumed];
    }

    private static function getResumeId() {
        $path = $_GET['path'] ?? '';
        $agentId = $_GET['agentId'] ?? '';
        if (empty($path) || empty($agentId)) {
            return ['status' => 'ok', 'chatId' => null];
        }
        $chatId = \AICliAgents\Services\ConfigService::getResumeId($path, $agentId);
        return ['status' => 'ok', 'chatId' => $chatId];
    }

    /**
     * Agent-specific fallback for the most recent session id when the exit
     * screen doesn't print a resume hint. Used by gracefulClose() only when
     * the pane regex misses.
     *
     * Returns null if unavailable or unsupported for the agent.
     */
    private static function discoverLatestSessionId(string $agentId): ?string {
        $config = getAICliConfig();
        $username = $config['user'] ?? 'root';
        if (empty($username)) $username = 'root';
        $homeDir = \AICliAgents\Services\UtilityService::getWorkDir($username) . "/home";

        if ($agentId === 'opencode') {
            // OpenCode stores sessions in a SQLite DB. Query the most recent one.
            $db = "$homeDir/.local/share/opencode/opencode.db";
            if (!is_file($db)) return null;
            $query = "SELECT id FROM session ORDER BY time_updated DESC LIMIT 1;";
            $out = trim((string) shell_exec("sqlite3 " . escapeshellarg($db) . " " . escapeshellarg($query) . " 2>/dev/null"));
            if (preg_match('/^ses_[A-Za-z0-9_-]+$/', $out)) return $out;
            return null;
        }

        return null;
    }

    private static function restart($id) {
        stopAICliTerminal($id, true);
        startAICliTerminal($id, $_GET['path'] ?? null, $_GET['chatId'] ?? null, $_GET['agentId'] ?? 'gemini-cli');
        return ['status' => 'ok'];
    }

    private static function getChatSession() {
        $path = $_GET['path'] ?? '';
        $agentId = $_GET['agentId'] ?? 'gemini-cli';
        $chatId = \AICliAgents\Services\TerminalService::findSession($path, $agentId);
        return ['status' => 'ok', 'chatId' => $chatId];
    }

    private static function log() {
        $msg = $_POST['message'] ?? $_GET['message'] ?? '';
        $lvl = (int)($_POST['level'] ?? $_GET['level'] ?? 2);
        $ctx = $_POST['context'] ?? $_GET['context'] ?? 'Frontend';
        if (!empty($msg)) {
            aicli_log("[JS] $msg", $lvl, $ctx);
        }
        return ['status' => 'ok'];
    }

    private static function getLog() {
        $type = $_GET['type'] ?? 'debug';
        $logFile = self::resolveLogFile($type);
        $content = "";
        if (file_exists($logFile)) {
            $lines = aicli_tail($logFile, 500);
            $content = implode("\n", $lines);
            $content = mb_convert_encoding($content, 'UTF-8', 'UTF-8');
        } else {
            $content = "No log entries found for [" . ucfirst($type) . "].";
        }
        return ['status' => 'ok', 'content' => $content];
    }

    private static function clearLog() {
        $type = $_GET['type'] ?? 'debug';
        // resolveLogFile() is a hard-coded switch with a default fallback —
        // the return value cannot be influenced by $type beyond picking one
        // of four fixed file paths. No path-traversal surface here despite
        // Semgrep's tainted-url-to-connection heuristic.
        $logFile = self::resolveLogFile($type);
        // nosemgrep: php.lang.security.tainted-url-to-connection.tainted-url-to-connection
        if (file_exists($logFile)) @file_put_contents($logFile, "");
        return ['status' => 'ok', 'message' => ucfirst($type) . " log cleared."];
    }

    private static function resolveLogFile($type) {
        switch ($type) {
            case 'install':   return "/boot/config/plugins/unraid-aicliagents/install.log";
            case 'uninstall': return "/boot/config/plugins/unraid-aicliagents/uninstall.log";
            case 'migration': return "/tmp/unraid-aicliagents/migration.log";
            default:          return "/tmp/unraid-aicliagents/debug.log";
        }
    }
}
