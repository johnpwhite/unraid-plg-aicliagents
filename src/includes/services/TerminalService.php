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
        // IMPORTANT-1: early-return ONLY when a real ttyd is bound to this sid's
        // socket. isRunning() is now true for a detached tmux session with NO ttyd
        // (the relaunch-survival state this feature creates) — returning on that
        // would leave the browser with no socket. When ttyd died but tmux survived
        // we must fall through and (re)launch ttyd; ensure-session is idempotent so
        // it attaches to the existing detached session rather than double-spawning.
        if (ProcessManager::isTtydBound($id)) {
            LogService::log("Session $id already has a bound ttyd — reusing.", LogService::LOG_DEBUG, "TerminalService");
            return;
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

        // Running-agent-safe updates: resolve the active `src` pointer once at
        // launch. ttyd and the detached tmux bootstrap then keep an immutable
        // generation path in argv, so a later atomic activation cannot replace
        // the wrapper underneath a running workspace.
        $activeSrc = realpath('/usr/local/emhttp/plugins/unraid-aicliagents/src');
        if ($activeSrc === false) {
            $activeSrc = '/usr/local/emhttp/plugins/unraid-aicliagents/src';
        }
        $shell = $activeSrc . "/scripts/aicli-shell.sh";
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
        $ensureCmd = "runuser -u " . escapeshellarg($username) . " -- env $envStr /bin/bash " .
                     escapeshellarg($shell) . " --ensure-session";
        $ensureLog = "/tmp/unraid-aicliagents/ensure-$id.log";
        ActivityService::update($actId, ['step' => 'starting_agent', 'progress' => 60]);
        LogService::log("Ensuring detached agent session (headless): $ensureCmd", LogService::LOG_DEBUG, "TerminalService");
        exec("$ensureCmd > " . escapeshellarg($ensureLog) . " 2>&1", $ensureOut, $ensureRc);
        if ($ensureRc !== 0) {
            $tail = file_exists($ensureLog) ? trim((string)shell_exec("tail -n 5 " . escapeshellarg($ensureLog))) : '';
            LogService::log("ensure-session returned rc=$ensureRc for $id (continuing to ttyd; attach will retry). tail: $tail", LogService::LOG_WARN, "TerminalService");
        } else {
            LogService::log("Detached agent session is live for $id ($agentId).", LogService::LOG_INFO, "TerminalService");
        }

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
                    \AICliAgents\Handlers\TerminalHandler::handle('graceful_close', $sessionId);
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
            $aicliHome = (string)($ts['aicliHome'] ?? '');
            $tmuxBin   = (string)($ts['tmuxBin']   ?? '');

            if ($sessName === '' || $tmuxBin === '') continue;
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
                        if ($bId !== null && $bId !== '') {
                            \AICliAgents\Services\ConfigService::saveResumeId($bPath, $bAgent, (string)$bId);
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
                    $escSess = escapeshellarg($sessName);
                    // nosemgrep: php.lang.security.exec-use.exec-use
                    @shell_exec("$tmuxBin kill-session -t $escSess 2>/dev/null");
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
        foreach (glob('/tmp/unraid-aicliagents/tmux/tmux-*', GLOB_ONLYDIR) ?: [] as $perUserDir) {
            $sock = $perUserDir . '/default';
            if (!file_exists($sock)) continue;
            $tmuxBin = 'tmux -S ' . escapeshellarg($sock);
            // List all sessions matching aicli-agent-* prefix.
            $raw = trim((string)@shell_exec("$tmuxBin ls -F '#S' 2>/dev/null | grep -E '^aicli-agent-'"));
            if ($raw === '') continue;
            foreach (explode("\n", $raw) as $line) {
                $sessName = trim($line);
                if ($sessName === '') continue;
                // Read AICLI_HOME from the session's environment.
                $escSess = escapeshellarg($sessName);
                $envLine = trim((string)@shell_exec("$tmuxBin showenv -t $escSess AICLI_HOME 2>/dev/null"));
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
        $save = $seams['save'] ?? function (string $path, string $agentId, string $id): void {
            ConfigService::saveResumeId($path, $agentId, $id);
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
                    $save($path, $agentId, (string)$id);
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
            @mkdir('/tmp/unraid-aicliagents', 0755, true);
            \AICliAgents\Handlers\TerminalHandler::captureResumeForClose(
                $sessName, $tmuxSock, $tmuxBin, $agentId, $path, $ctx
            );
        }

        // Tear down ttyd + sockets + tmux (the kill sweep would do this anyway;
        // doing it here lets the budget account for the slow part — the scrape).
        ProcessManager::stopTerminal($safeId, true);
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
