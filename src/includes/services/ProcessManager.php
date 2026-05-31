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
     * Checks if a specific terminal session is currently running.
     * @param string $id The session ID.
     * @return bool True if running.
     */
    public static function isRunning($id = 'default') {
        $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
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
            LogService::log("EVICTOR: Terminating specific session: $id", LogService::LOG_INFO, "ProcessManager");
            self::stopTerminal($id, true);
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

            // Grace period FIRST -- a freshly-created sock (mid-launch race
            // before ttyd forks, or a smoke fixture inside its test window) is
            // not yet stale. Applied regardless of ttyd state.
            $sockAge = time() - ((int)@filemtime($sock) ?: time());
            if ($sockAge < $minAgeSeconds) {
                $kept[] = ['id' => $id, 'reason' => 'too_young', 'age_seconds' => $sockAge];
                continue;
            }

            $ttydPid = self::findTtydPidForSock($sock);
            if ($ttydPid === null) {
                LogService::log("Bug #1067: sock without live ttyd (sid=$id) -- unlinking artefacts", LogService::LOG_INFO, 'ProcessManager');
                self::cleanupArtifacts($id);
                $killed[] = ['id' => $id, 'reason' => 'no_ttyd'];
                continue;
            }

            if (self::ttydHasChildren($ttydPid)) {
                $kept[] = ['id' => $id, 'reason' => 'live', 'ttyd_pid' => $ttydPid];
                continue;
            }

            LogService::log("Bug #1067: orphan ttyd (pid=$ttydPid sid=$id) has no children -- terminating", LogService::LOG_INFO, 'ProcessManager');
            self::stopTerminal($id, true);
            $killed[] = ['id' => $id, 'reason' => 'no_children', 'ttyd_pid' => $ttydPid];
        }
        if (!empty($killed) || !empty($kept)) {
            LogService::log('Bug #1067 sweep: killed=' . count($killed) . ' kept=' . count($kept), LogService::LOG_INFO, 'ProcessManager');
        }
        return ['killed' => $killed, 'kept' => $kept];
    }

    /**
     * Find the ttyd PID owning a given UNIX socket path. Returns null when no
     * ttyd process has that sock on its cmdline.
     */
    private static function findTtydPidForSock(string $sock): ?int {
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
