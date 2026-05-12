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

        if (!$agent) {
            LogService::log("Error: Agent $agentId not found in registry.", LogService::LOG_ERROR, "TerminalService");
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
            return;
        }

        LogService::log("Found ttyd at: $ttyd", LogService::LOG_DEBUG, "TerminalService");

        // D-195: Map UID 0 to 'root' for runuser compatibility
        $username = $config['user'];
        if ($username === '0' || $username === 0) {
            $username = 'root';
        }

        // 3. Ensure Storage is Mounted (SquashFS On-Demand)
        // In emergency mode, agent binary is already in RAM and home is a symlink — skip sqsh mounts.
        $isEmergency = StorageMountService::isEmergencyMode();
        if ($agentId !== 'terminal' && !$isEmergency) {
            if (!StorageMountService::ensureAgentMounted($agentId)) {
                LogService::log("FAILED to mount agent storage for $agentId", LogService::LOG_ERROR, "TerminalService");
                return;
            }
        }
        if (!$isEmergency && !StorageMountService::ensureHomeMounted($username)) {
            LogService::log("FAILED to mount home storage for $username", LogService::LOG_ERROR, "TerminalService");
            return;
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
                    $found = true;
                    break;
                }
                usleep(100000); 
            }

            if (!$found) {
                LogService::log("CRITICAL: Socket $sock not created within 2s. Terminal will fail to connect.", LogService::LOG_ERROR, "TerminalService");
                if (file_exists($logFile)) {
                    $logTail = shell_exec("tail -n 10 " . escapeshellarg($logFile));
                    LogService::log("ttyd stderr tail: " . $logTail, LogService::LOG_ERROR, "TerminalService");
                }
            }
        } else {
            LogService::log("Failed to launch term process for $id. (No PID returned)", LogService::LOG_ERROR, "TerminalService");
        }
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
