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

class TerminalService {
    /**
     * Starts a new AICli console session via ttyd.
     */
    public static function startTerminal($id = 'default', $path = null, $chatId = null, $agentId = 'gemini-cli') {
        LogService::log("Initiating console session sequence for: $id (Agent: $agentId)...", LogService::LOG_INFO, "TerminalService");
        
        // 1. Pre-launch checks
        if (ProcessManager::isRunning($id)) {
            LogService::log("Session $id is already running.", LogService::LOG_DEBUG, "TerminalService");
            return;
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
        ActivityService::register($actId, 'start', 'Starting ' . ($agent['name'] ?? $agentId), [
            'step' => 'preparing', 'progress' => 5,
            'meta' => ['sessionId' => $id, 'agentId' => $agentId, 'path' => (string)($path ?? '')],
        ]);

        if (!$agent) {
            LogService::log("Error: Agent $agentId not found in registry.", LogService::LOG_ERROR, "TerminalService");
            ActivityService::fail($actId, "Agent $agentId not found in registry.");
            return;
        }

        $shell = "/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/aicli-shell.sh";
        $sock = "/var/run/aicliterm-$id.sock";
        $pidFile = "/var/run/unraid-aicliagents-$id.pid";
        $logFile = "/tmp/ttyd-$id.log";

        // Record this session's agent + workspace path to the parallel
        // /var/run metadata files. ProcessManager::stopTerminal already
        // unlinks them on clean shutdown; we need to be the writer. Without
        // these files, listActiveSessionsForAgent (used by the agent-upgrade
        // UX flow) cannot match sessions to agents.
        AtomicWriteService::write(UtilityService::getAgentIdPath($id), (string)$agentId);
        if (!empty($path)) {
            AtomicWriteService::write(UtilityService::getWorkDirFilePath($id), (string)$path);
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

        $ttyd = trim((string)shell_exec("timeout 2 which ttyd"));
        if (empty($ttyd) || !file_exists($ttyd)) {
            $ttyd = "/usr/bin/ttyd"; // Standard Unraid fallback
        }

        if (!file_exists($ttyd)) {
            LogService::log("CRITICAL: ttyd binary not found in PATH or /usr/bin/ttyd!", LogService::LOG_ERROR, "TerminalService");
            ActivityService::fail($actId, 'ttyd binary not found in PATH or /usr/bin/ttyd');
            return;
        }

        LogService::log("Found ttyd at: $ttyd", LogService::LOG_DEBUG, "TerminalService");

        // D-195 / Bug #1053: any configured user that is not present in
        // /etc/passwd falls back to 'root'. ManagerConfigTab's user dropdown
        // historically saved the option INDEX (e.g. "4") instead of the
        // username — and a previously-valid user may also have been removed
        // from the system since the setting was saved. Without this fallback,
        // runuser fails with "user X does not exist" and every terminal then
        // surfaces as "Terminal session not found".
        $username = (string) ($config['user'] ?? '');
        $userValid = $username !== ''
            && function_exists('posix_getpwnam')
            && is_array(@posix_getpwnam($username));
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
        @mkdir('/tmp/unraid-aicliagents', 01777, true);
        @chmod('/tmp/unraid-aicliagents', 01777);
        @mkdir('/tmp/unraid-aicliagents/work', 01777, true);
        @chmod('/tmp/unraid-aicliagents/work', 01777);
        $userWorkDir = '/tmp/unraid-aicliagents/work/' . $username;
        if (!is_dir($userWorkDir)) {
            @mkdir($userWorkDir, 0755, true);
        }
        $pw = function_exists('posix_getpwnam') ? @posix_getpwnam($username) : null;
        if (is_array($pw)) {
            @chown($userWorkDir, $pw['uid']);
            @chgrp($userWorkDir, $pw['gid']);
            @chmod($userWorkDir, 0755);
        }
        // A stale perf.log from an earlier root-as-user run is 0644 root-owned;
        // a non-root agent can't append. World-appendable is fine for a debug
        // perf trace (no secrets).
        $perfLog = '/tmp/unraid-aicliagents/perf.log';
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
            ActivityService::update($actId, ['step' => 'mounting_agent', 'progress' => 20]);
            if (!FileStorage::ensureReady("agent/$agentId")->ok) {   // Epic #1310: express intent via the facade
                LogService::log("FAILED to mount agent storage for $agentId", LogService::LOG_ERROR, "TerminalService");
                ActivityService::fail($actId, "Failed to mount agent storage for $agentId");
                return;
            }
        }
        if (!$isEmergency) {
            ActivityService::update($actId, ['step' => 'mounting_home', 'progress' => 45]);
            if (!FileStorage::ensureReady("home/$username")->ok) {   // Epic #1310: facade intent
                LogService::log("FAILED to mount home storage for $username", LogService::LOG_ERROR, "TerminalService");
                ActivityService::fail($actId, "Failed to mount home storage for $username");
                return;
            }
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
            $resolvedChatId = ConfigService::getResumeId($path, $agentId);
            LogService::log("Auto-resume: chatId=" . ($resolvedChatId ?: 'none') . " for $agentId at $path", LogService::LOG_INFO, "TerminalService");
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
            'RESUME_CMD'        => $agent['resume_cmd'] ?? '',
            'RESUME_LATEST'     => $agent['resume_latest'] ?? '',
            'ENV_PREFIX'        => $agent['env_prefix'] ?? '',
            'AICLI_HOME'        => UtilityService::getWorkDir($username) . "/home",
            'AICLI_ROOT'        => $path ?: '/mnt',
            'AICLI_CHAT_SESSION_ID' => $resolvedChatId ?: '',
            'NODE_PATH'         => "/usr/local/emhttp/plugins/unraid-aicliagents/agents/$agentId/node_modules"
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
        $effective = EnvService::buildEffectiveEnv($path ?: null, $agentId);
        foreach ($effective as $k => $v) {
            if (EnvService::isReservedKey($k)) continue;
            if (array_key_exists($k, $env)) continue; // plugin-internal wins
            $env[$k] = $v;
        }

        $envStrParts = [];
        foreach ($env as $k => $v) {
            $envStrParts[] = "$k=" . escapeshellarg($v);
        }
        $envStr = implode(" ", $envStrParts);
        
        // D-207: Use runuser -u (non-login) to maintain inherited env if possible, 
        // but explicitly inject all AICli variables via the 'env' command wrapper.
        $cmd = "$ttyd -i " . escapeshellarg($sock) . " -p 0 -W -d0 " .
               "runuser -u " . escapeshellarg($username) . " -- env $envStr /bin/bash " . escapeshellarg($shell);
        
        LogService::log("Executing: $cmd", LogService::LOG_DEBUG, "TerminalService");

        ActivityService::update($actId, ['step' => 'launching_ttyd', 'progress' => 70]);
        exec("nohup $cmd > " . escapeshellarg($logFile) . " 2>&1 & echo $!", $out);
        
        $pid = trim($out[0] ?? '');
        LogService::log("Launch result - PID: $pid", LogService::LOG_DEBUG, "TerminalService");

        if (ctype_digit($pid)) {
            file_put_contents($pidFile, $pid);
            
            // Wait a moment for socket creation
            $found = false;
            for ($i=0; $i<20; $i++) {
                if (file_exists($sock)) {
                    @chmod($sock, 0666);
                    @chown($sock, 'nobody');
                    @chgrp($sock, 'users');
                    LogService::log("Successfully established terminal socket and launched session for $id.", LogService::LOG_INFO, "TerminalService");
                    // Done from PHP's perspective — the agent itself cold-starts
                    // inside tmux; AICLI_TTYD_READY covers that last leg in the UI.
                    ActivityService::finish($actId, 'starting_agent');
                    $found = true;
                    break;
                }
                usleep(100000); 
            }

            if (!$found) {
                LogService::log("CRITICAL: Socket $sock not created within 2s. Terminal will fail to connect.", LogService::LOG_ERROR, "TerminalService");
                ActivityService::fail($actId, 'ttyd socket was not created within 2s');
                if (file_exists($logFile)) {
                    $logTail = shell_exec("tail -n 10 " . escapeshellarg($logFile));
                    LogService::log("ttyd stderr tail: " . $logTail, LogService::LOG_ERROR, "TerminalService");
                }
            }
        } else {
            LogService::log("Failed to launch term process for $id. (No PID returned)", LogService::LOG_ERROR, "TerminalService");
            ActivityService::fail($actId, 'Failed to launch ttyd (no PID returned)');
        }
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
    public static function antigravityResumeId(string $home, string $path): ?string {
        if ($home === '' || $path === '') return null;
        $cacheFile = "$home/.gemini/antigravity-cli/cache/last_conversations.json";
        if (!is_file($cacheFile)) return null;
        $data = @json_decode((string) @file_get_contents($cacheFile), true);
        if (!is_array($data)) return null;
        $id = $data[$path] ?? null;
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9-]{20,}$/', $id)) return null;
        if (!is_file("$home/.gemini/antigravity-cli/conversations/$id.pb")) return null;
        return $id;
    }

    /**
     * Finds a recent chat session for a project path.
     */
    public static function findSession($path, $agentId = 'gemini-cli') {
        if (empty($path)) return null;

        // D-334: Gemini-specific chat session discovery
        if ($agentId === 'gemini-cli') {
            $config = ConfigService::getConfig();
            $user = $config['user'] ?? 'root';
            if (empty($user)) $user = 'root';

            $home = "/tmp/unraid-aicliagents/work/$user/home";
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
            $home = "/tmp/unraid-aicliagents/work/$user/home";
            return self::antigravityResumeId($home, $path);
        }

        // Claude and OpenCode handle their own session persistence internally
        return null;
    }

    /**
     * Enumerate active terminal sessions whose agent matches $agentId.
     * Returns a list of {id, agentId, path, chatId, started_at} records so
     * the UI can warn the user and the install flow can gracefully close
     * them before clobbering the binary.
     *
     * Session metadata lives in parallel /var/run files keyed by session id:
     *   aicliterm-<id>.sock                  — running-flag
     *   unraid-aicliagents-<id>.agentid      — agent id
     *   unraid-aicliagents-<id>.workdir      — workspace path
     *   unraid-aicliagents-<id>.chatid       — last-known resume id
     */
    public static function listActiveSessionsForAgent(string $agentId): array
    {
        // Bug #1067: reconcile orphan ttyd processes before enumerating, so the
        // agent-upgrade dialog's "open sessions" count reflects reality (not
        // stale sock files from failed launches or unclean exits).
        ProcessManager::sweepOrphanSessions();

        $out = [];
        foreach (glob("/var/run/aicliterm-*.sock") ?: [] as $sock) {
            if (!preg_match('/aicliterm-(.*)\.sock$/', $sock, $m)) continue;
            $id = $m[1];
            $agentFile = UtilityService::getAgentIdPath($id);
            $sessionAgent = is_file($agentFile) ? trim((string)@file_get_contents($agentFile)) : '';
            if ($sessionAgent !== $agentId) continue;

            $workdirFile = UtilityService::getWorkDirFilePath($id);
            $chatFile = UtilityService::getChatIdPath($id);
            $out[] = [
                'id'         => $id,
                'agentId'    => $sessionAgent,
                'path'       => is_file($workdirFile) ? trim((string)@file_get_contents($workdirFile)) : '',
                'chatId'     => is_file($chatFile) ? trim((string)@file_get_contents($chatFile)) : '',
                'started_at' => @filemtime($sock) ?: 0,
            ];
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
     * @return array<int, array<string, mixed>>  Each entry has at least 'id' and 'user'.
     */
    public static function listActiveSessionsForHome(string $user): array
    {
        ProcessManager::sweepOrphanSessions();

        $config      = ConfigService::getConfig();
        $configUser  = (string)($config['user'] ?? '');
        if ($configUser === '') $configUser = 'root';

        $all = [];
        foreach (glob("/var/run/aicliterm-*.sock") ?: [] as $sock) {
            if (!preg_match('/aicliterm-(.*)\.sock$/', $sock, $m)) continue;
            $id = $m[1];

            $agentFile   = UtilityService::getAgentIdPath($id);
            $workdirFile = UtilityService::getWorkDirFilePath($id);
            $chatFile    = UtilityService::getChatIdPath($id);
            $userFile    = UtilityService::getUserIdPath($id);

            $all[] = [
                'id'         => $id,
                'agentId'    => is_file($agentFile)   ? trim((string)@file_get_contents($agentFile))   : '',
                'path'       => is_file($workdirFile) ? trim((string)@file_get_contents($workdirFile)) : '',
                'chatId'     => is_file($chatFile)    ? trim((string)@file_get_contents($chatFile))    : '',
                'user'       => is_file($userFile)    ? trim((string)@file_get_contents($userFile))    : '',
                'started_at' => @filemtime($sock) ?: 0,
            ];
        }

        return self::filterSessionsByHome($all, $user, $configUser);
    }

    /**
     * Headlessly force-close every active terminal session belonging to $user's
     * home overlay. Reuses the proven gracefulClose path (Ctrl-C x2 + Ctrl-D x2 +
     * resume-id scrape + teardown) so it is functionally identical to a user
     * clicking "Close" in the UI — just invoked by the supervisor's force-reclaim
     * escalation rather than an HTTP request (WP #1262).
     *
     * Each session is closed independently; a failure on one does not prevent
     * subsequent sessions from being closed. Returns the count of sessions
     * successfully handed to gracefulClose (not the count of clean exits — the
     * caller must re-enumerate if it needs to confirm quiescence).
     *
     * @return int  Number of sessions closed (gracefulClose invoked).
     */
    public static function forceCloseHome(string $user): int
    {
        $sessions = self::listActiveSessionsForHome($user);
        $closed   = 0;

        foreach ($sessions as $session) {
            $sessionId = (string)($session['id'] ?? '');
            if ($sessionId === '') continue;
            try {
                \AICliAgents\Handlers\TerminalHandler::handle('graceful_close', $sessionId);
                $closed++;
                LogService::log(
                    "forceCloseHome: closed session $sessionId for user=$user (WP #1262)",
                    LogService::LOG_INFO, "TerminalService"
                );
            } catch (\Throwable $e) {
                LogService::log(
                    "forceCloseHome: failed to close session $sessionId for user=$user — " . $e->getMessage(),
                    LogService::LOG_WARN, "TerminalService"
                );
            }
        }

        LogService::log(
            "forceCloseHome: closed $closed/" . count($sessions) . " sessions for user=$user",
            LogService::LOG_INFO, "TerminalService"
        );

        return $closed;
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
