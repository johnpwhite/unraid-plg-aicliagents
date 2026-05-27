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
        // save_auto_launch is called via POST with agentId in the body, so read
        // from $_REQUEST (covers both GET query and POST body). $_GET alone
        // would silently fail because the JS only puts `action` in the URL.
        $agentId = ValidationService::validateId($_REQUEST['agentId'] ?? '');
        $path    = $_REQUEST['path'] ?? '';
        if (!$agentId) return ['status' => 'error', 'message' => 'Missing or invalid agentId'];
        if (empty($path)) return ['status' => 'error', 'message' => 'Missing path'];

        $autoLaunch      = filter_var($_REQUEST['autoLaunch']      ?? '0', FILTER_VALIDATE_BOOLEAN);
        $freshIfNoResume = filter_var($_REQUEST['freshIfNoResume']  ?? '0', FILTER_VALIDATE_BOOLEAN);

        $ok = ConfigService::saveAutoLaunch($path, $agentId, $autoLaunch, $freshIfNoResume);
        return $ok ? ['status' => 'ok'] : ['status' => 'error', 'message' => 'Write failed'];
    }

    private static function getAutoLaunch(): array
    {
        $agentId = ValidationService::validateId($_GET['agentId'] ?? '');
        if (!$agentId) return ['status' => 'error', 'message' => 'Missing or invalid agentId'];

        $workspaces = ConfigService::getWorkspaces();
        $sessions   = $workspaces['sessions'] ?? [];
        $result     = [];

        foreach ($sessions as $session) {
            if (($session['agentId'] ?? '') !== $agentId) continue;
            $path   = $session['path'] ?? '';
            $config = ConfigService::getAutoLaunch($path, $agentId);
            $result[] = [
                'id'              => $session['id'],
                'path'            => $path,
                'name'            => $session['name'] ?? '',
                'title'           => $session['title'] ?? '',
                'autoLaunch'      => $config['autoLaunch'],
                'freshIfNoResume' => $config['freshIfNoResume'],
            ];
        }

        return ['status' => 'ok', 'workspaces' => $result];
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

            // Per-workspace isolation: a corrupt autolaunch_*.json or a
            // filesystem hiccup on ONE workspace must not skip the rest.
            try {
                $config = ConfigService::getAutoLaunch($path, $agentId);
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
