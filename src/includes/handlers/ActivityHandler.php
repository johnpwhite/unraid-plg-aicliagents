<?php
/**
 * <module_context>
 *     <name>ActivityHandler</name>
 *     <description>AJAX handler for the activity tray (T-08/T-09/T-10): list, cancel,
 *     dismiss, and retry-auto-launch over ActivityService entries.</description>
 *     <dependencies>ActivityService, TerminalService, ProcessManager</dependencies>
 *     <constraints>Static methods only. All inputs validated before use.</constraints>
 * </module_context>
 */

namespace AICliAgents\Handlers;

use AICliAgents\Services\ActivityService;
use AICliAgents\Services\ProcessManager;
use AICliAgents\Services\TerminalService;

class ActivityHandler
{
    public static function handle($action, $id): ?array
    {
        switch ($action) {
            case 'list_activities':  return self::listActivities();
            case 'cancel_activity':  return self::cancelActivity();
            case 'dismiss_activity': return self::dismissActivity();
            case 'retry_auto_launch': return self::retryAutoLaunch();
            default:                 return null;
        }
    }

    /** Actions handled by this handler. */
    public static function actions(): array
    {
        return ['list_activities', 'cancel_activity', 'dismiss_activity', 'retry_auto_launch'];
    }

    /**
     * All known activities, watchdog-evaluated. This call IS the watchdog
     * driver — the tray polls it, and each poll persists/publishes any
     * stalled/failed transitions (see ActivityService module constraints).
     */
    private static function listActivities(): array
    {
        // S-08 (#1353): mirror supervisor job-ledger state into any
        // `storage_job_*` tray entries before listing — the bash supervisor
        // cannot call PHP per transition, so the tray's poll IS the bridge
        // (same lazy pattern as the watchdog itself).
        \AICliAgents\Services\SupervisorService::syncJobActivities();
        return ['status' => 'ok', 'activities' => ActivityService::listAll()];
    }

    private static function cancelActivity(): array
    {
        $opId = (string)($_REQUEST['opId'] ?? '');
        if ($opId === '') return ['status' => 'error', 'message' => 'opId required'];
        return ActivityService::cancel($opId);
    }

    private static function dismissActivity(): array
    {
        $opId = (string)($_REQUEST['opId'] ?? '');
        if ($opId === '') return ['status' => 'error', 'message' => 'opId required'];
        return ActivityService::dismiss($opId)
            ? ['status' => 'ok']
            : ['status' => 'error', 'message' => 'cannot dismiss a running activity — cancel it first'];
    }

    /**
     * T-10 recovery hook: re-run ONE failed auto-launch workspace. The failed
     * `type:start` activity carries meta {sessionId, agentId, path, chatId}
     * recorded by AutoLaunchService at failure time.
     */
    private static function retryAutoLaunch(): array
    {
        $opId = (string)($_REQUEST['opId'] ?? '');
        if ($opId === '') return ['status' => 'error', 'message' => 'opId required'];

        $entry = ActivityService::get($opId);
        if ($entry === null) return ['status' => 'error', 'message' => 'no such activity'];
        if (($entry['type'] ?? '') !== 'start' || ($entry['recovery'] ?? '') !== 'retry') {
            return ['status' => 'error', 'message' => 'activity is not retryable'];
        }

        $meta    = is_array($entry['meta'] ?? null) ? $entry['meta'] : [];
        $sid     = (string)($meta['sessionId'] ?? '');
        $agentId = (string)($meta['agentId'] ?? '');
        $path    = (string)($meta['path'] ?? '');
        if ($sid === '' || $agentId === '' || $path === '') {
            return ['status' => 'error', 'message' => 'activity has no retry context'];
        }

        ActivityService::update($opId, ['status' => 'running', 'step' => 'retrying', 'progress' => 5, 'error' => null]);
        try {
            // startTerminal re-registers start_<sid> and steps it (T-09), so the
            // tray sees live progress for the retry as well.
            TerminalService::startTerminal($sid, $path, (string)($meta['chatId'] ?? 'auto'), $agentId);
        } catch (\Throwable $e) {
            ActivityService::fail($opId, 'retry failed: ' . $e->getMessage(), 'retry', ['meta' => $meta]);
            return ['status' => 'error', 'message' => $e->getMessage()];
        }

        if (!ProcessManager::isRunning($sid)) {
            ActivityService::fail($opId, 'retry failed: session did not start', 'retry', ['meta' => $meta]);
            return ['status' => 'error', 'message' => 'session did not start'];
        }
        return ['status' => 'ok', 'sessionId' => $sid];
    }
}
