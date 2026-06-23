<?php
/**
 * <module_context>
 *     <name>AutoLaunchHandler</name>
 *     <description>AJAX handler for auto-launch workspace preferences.</description>
 *     <dependencies>ConfigService, ValidationService, AgentRegistry, ProcessManager</dependencies>
 *     <constraints>Static methods only. All inputs validated before use.</constraints>
 * </module_context>
 */

namespace AICliAgents\Handlers;

use AICliAgents\Services\ConfigService;
use AICliAgents\Services\ValidationService;
use AICliAgents\Services\AgentRegistry;
use AICliAgents\Services\ProcessManager;

class AutoLaunchHandler
{
    public static function handle($action, $id): ?array
    {
        switch ($action) {
            case 'save_auto_launch':        return self::saveAutoLaunch();
            case 'get_auto_launch':         return self::getAutoLaunch();
            case 'get_auto_launch_pending': return self::getAutoLaunchPending();
            default:                        return null;
        }
    }

    public static function actions(): array
    {
        return ['save_auto_launch', 'get_auto_launch', 'get_auto_launch_pending'];
    }

    private static function saveAutoLaunch(): array
    {
        // R-C1: auto-launch is now an AGENT-LEVEL setting. `path` is accepted but
        // ignored (kept for backwards-compatible callers) — the flag governs every
        // workspace of the agent. Read from $_REQUEST (covers GET query + POST body).
        $agentId = ValidationService::validateId($_REQUEST['agentId'] ?? '');
        if (!$agentId) return ['status' => 'error', 'message' => 'Missing or invalid agentId'];

        $autoLaunch      = filter_var($_REQUEST['autoLaunch']      ?? '0', FILTER_VALIDATE_BOOLEAN);
        $freshIfNoResume = filter_var($_REQUEST['freshIfNoResume']  ?? '0', FILTER_VALIDATE_BOOLEAN);

        $ok = ConfigService::setAgentAutoLaunch($agentId, $autoLaunch, $freshIfNoResume);
        return $ok ? ['status' => 'ok'] : ['status' => 'error', 'message' => 'Write failed'];
    }

    private static function getAutoLaunch(): array
    {
        $agentId = ValidationService::validateId($_GET['agentId'] ?? '');
        if (!$agentId) return ['status' => 'error', 'message' => 'Missing or invalid agentId'];

        // R-C1: return the single agent-level flag plus the count of workspaces it
        // governs (for the UI caption). No per-workspace rows anymore.
        $config     = ConfigService::getAgentAutoLaunch($agentId);
        $workspaces = ConfigService::getWorkspaces();
        $sessions   = $workspaces['sessions'] ?? [];
        $count      = 0;
        foreach ($sessions as $session) {
            if (($session['agentId'] ?? '') === $agentId) $count++;
        }

        return [
            'status'          => 'ok',
            'agentId'         => $agentId,
            'autoLaunch'      => $config['autoLaunch'],
            'freshIfNoResume' => $config['freshIfNoResume'],
            'workspaceCount'  => $count,
        ];
    }

    /**
     * Returns ALL flagged workspaces across every agent that are eligible to
     * launch right now. Filters in priority order:
     *   1. Has agentId and path (skip malformed entries)
     *   2. autoLaunch flag is true
     *   3. Agent is currently installed (skip workspaces whose agent has been
     *      uninstalled — startTerminal would just fail)
     *   4. Session is not already running (skip no-op starts to prevent UX
     *      spinner flashes and log noise on simple page reloads)
     *   5. Resume rule: has resume OR freshIfNoResume=true
     *
     * Cross-agent scope is intentional — same as get_workspaces, CSRF-gated.
     *
     * chatId='auto' is a sentinel — TerminalService::startTerminal resolves it
     * to the real resume id via ConfigService::getResumeId at start time.
     */
    private static function getAutoLaunchPending(): array
    {
        $workspaces = ConfigService::getWorkspaces();
        $sessions   = $workspaces['sessions'] ?? [];
        $registry   = AgentRegistry::getRegistry();
        $pending    = [];

        foreach ($sessions as $session) {
            $path    = $session['path'] ?? '';
            $agentId = $session['agentId'] ?? '';
            $sid     = $session['id']      ?? '';
            if (!$agentId || !$path || !$sid) continue;

            // Per-workspace isolation: a corrupt autolaunch map read or a
            // filesystem hiccup on ONE workspace must not skip the rest.
            try {
                // R-C2: select by the AGENT-LEVEL flag — every workspace of an
                // enabled agent is eligible.
                $config = ConfigService::getAgentAutoLaunch($agentId);
                if (!$config['autoLaunch']) continue;

                // Skip if agent is not installed — startTerminal would fail and
                // user sees a confusing "binary missing" error in the log.
                $agent = $registry[$agentId] ?? null;
                if (!$agent || empty($agent['is_installed'])) continue;

                // Skip if session is already running — prevents redundant fetch(start)
                // calls on every page reload that would just no-op server-side.
                if (ProcessManager::isRunning($sid)) continue;

                $resumeId = ConfigService::getResumeId($path, $agentId);
                if ($resumeId === null && !$config['freshIfNoResume']) continue;

                $pending[] = [
                    'id'      => $sid,
                    'path'    => $path,
                    'agentId' => $agentId,
                    'chatId'  => $resumeId !== null ? 'auto' : '',
                ];
            } catch (\Throwable $e) {
                // Log via the project logger if available; never let one
                // workspace abort the sweep.
                if (function_exists('aicli_log')) {
                    aicli_log(
                        "getAutoLaunchPending: skipped $sid ($agentId): " . $e->getMessage(),
                        defined('AICLI_LOG_WARN') ? AICLI_LOG_WARN : 1,
                        'AutoLaunchHandler'
                    );
                }
            }
        }

        return ['status' => 'ok', 'pending' => $pending];
    }
}
