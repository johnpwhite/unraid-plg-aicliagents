<?php
/**
 * <module_context>
 *     <name>AutoLaunchService</name>
 *     <description>Server-side sweep that launches every workspace flagged for auto-launch. Triggered from kill-off events (plugin upgrade, array start, agent install, boot) so sessions are running before the user opens the AICliAgents tab.</description>
 *     <dependencies>ConfigService, AgentRegistry, ProcessManager, TerminalService, LogService</dependencies>
 *     <constraints>Static methods only. Idempotent — safe to call from multiple triggers because ProcessManager::isRunning skips already-live sessions.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class AutoLaunchService
{
    /**
     * Launch every flagged workspace whose agent is installed and whose
     * session is not already running.
     *
     * Mirrors the filter chain in AutoLaunchHandler::getAutoLaunchPending so a
     * single source of truth governs which workspaces are eligible. Per-
     * workspace exceptions are caught and logged so one bad workspace can't
     * abort the rest of the sweep.
     *
     * @param ?string $filterAgentId If non-null, restrict the sweep to
     *                               workspaces of this agent (used by the
     *                               post-install hook in install-bg.php).
     *                               Null = sweep across all agents.
     * @param string  $reason        Free-form trigger label written to the
     *                               aicli_log so we can track which trigger
     *                               actually fired in production.
     * @return array{launched:int, skipped:int, failed:int, sessions:array}
     */
    public static function launchAllPending(?string $filterAgentId = null, string $reason = 'unknown'): array
    {
        // Bug #532: serialise concurrent sweeps. PLG INLINE / disks_mounted /
        // InitService boot-marker can all fire within the same ~50 ms window
        // after Bug 521. Without this flock both processes pass the
        // ProcessManager::isRunning() check at line 76 (neither has called
        // startTerminal yet) and we end up with two ttyd processes briefly
        // racing on the same /var/run/aicliterm-<sid>.sock — the loser exits
        // silently. flock makes the second invocation wait for the first to
        // finish, after which its isRunning() check will see the live session
        // and skip cleanly.
        $lockPath = '/var/run/aicli-autolaunch.lock';
        $lockFh   = @fopen($lockPath, 'c');
        if ($lockFh !== false) {
            // Block up to 30 s for the prior sweep to finish. Sweeps are fast
            // (each workspace just kicks off a detached startTerminal) so 30 s
            // is generous; non-blocking would risk silently skipping the
            // sweep when triggers are too close together.
            if (!@flock($lockFh, LOCK_EX)) {
                @fclose($lockFh);
                $lockFh = false;
            }
        }

        $launched = 0;
        $skipped  = 0;
        $failed   = 0;
        $started  = [];

        try {
            $workspaces = ConfigService::getWorkspaces();
        } catch (\Throwable $e) {
            self::log("getWorkspaces failed: " . $e->getMessage(), AICLI_LOG_WARN);
            if ($lockFh !== false) { @flock($lockFh, LOCK_UN); @fclose($lockFh); }
            return ['launched' => 0, 'skipped' => 0, 'failed' => 1, 'sessions' => []];
        }

        $sessions = $workspaces['sessions'] ?? [];
        $registry = AgentRegistry::getRegistry();

        foreach ($sessions as $session) {
            $path    = $session['path']    ?? '';
            $agentId = $session['agentId'] ?? '';
            $sid     = $session['id']      ?? '';
            if (!$agentId || !$path || !$sid) {
                $skipped++;
                continue;
            }
            if ($filterAgentId !== null && $agentId !== $filterAgentId) {
                $skipped++;
                continue;
            }

            try {
                $config = ConfigService::getAutoLaunch($path, $agentId);
                if (!$config['autoLaunch']) {
                    $skipped++;
                    continue;
                }

                $agent = $registry[$agentId] ?? null;
                if (!$agent || empty($agent['is_installed'])) {
                    $skipped++;
                    continue;
                }

                if (ProcessManager::isRunning($sid)) {
                    $skipped++;
                    continue;
                }

                $resumeId = ConfigService::getResumeId($path, $agentId);
                if ($resumeId === null && !$config['freshIfNoResume']) {
                    $skipped++;
                    continue;
                }

                $chatId = $resumeId !== null ? 'auto' : '';
                self::log("Auto-launching workspace $sid for $agentId (trigger=$reason)", AICLI_LOG_INFO);
                TerminalService::startTerminal($sid, $path, $chatId, $agentId);
                $launched++;
                $started[] = ['id' => $sid, 'agentId' => $agentId, 'path' => $path];
            } catch (\Throwable $e) {
                $failed++;
                self::log("Auto-launch failed for $sid ($agentId): " . $e->getMessage(), AICLI_LOG_WARN);
            }
        }

        if ($launched > 0 || $failed > 0) {
            self::log("Auto-launch sweep ($reason): launched=$launched skipped=$skipped failed=$failed", AICLI_LOG_INFO);
        }

        if ($lockFh !== false) {
            @flock($lockFh, LOCK_UN);
            @fclose($lockFh);
        }

        return [
            'launched' => $launched,
            'skipped'  => $skipped,
            'failed'   => $failed,
            'sessions' => $started,
        ];
    }

    private static function log(string $msg, int $level): void
    {
        if (function_exists('aicli_log')) {
            aicli_log($msg, $level, 'AutoLaunchService');
        }
    }
}
