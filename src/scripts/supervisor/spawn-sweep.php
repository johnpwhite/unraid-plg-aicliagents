<?php

declare(strict_types=1);

/**
 * spawn-sweep.php — AGENT_SPAWN_WORKSPACE.md R14 (autoClose).
 *
 * Run about every 30 s by the supervisor's work tick (_spawn_sweep_tick). It closes each helper workspace
 * that reported its task (aicli_finish_task) once its pane is idle again: the helper is stopped, leaves the
 * workspace list, and its worktree is removed when it is merged and clean. A tool call cannot do this itself,
 * because the call runs inside the pane that would be closed. Best effort: it never throws and never blocks
 * the tick; a helper that is busy waits for the next tick. It does nothing while the feature is off.
 */

$_SERVER['DOCUMENT_ROOT'] = '/usr/local/emhttp';
require_once dirname(__DIR__, 2) . '/includes/AICliAgentsManager.php';

use AICliAgents\Services\EventActor;
use AICliAgents\Services\AgentRunService;
use AICliAgents\Services\SpawnService;

if (class_exists('\AICliAgents\Services\EventActor')) {
    EventActor::$override = ['type' => 'system'];
}

try {
    // The clean-up after a person closed a helper does not depend on the switch: the helper exists already.
    SpawnService::sweepPersonClosed();
    // An existing script run must close at its time limit, even when new helper work is turned off.
    AgentRunService::sweepTimeouts();
    // Script runs also close after a report when helper work is off; this sweep selects only run workspaces.
    SpawnService::sweepAutoClose(true);
    // The switch is read like every other one: only the string "1" means on. When it is off, a helper that
    // reported stays in the list until a person closes it.
    if (!SpawnService::enabled()) {
        exit(0);
    }
    SpawnService::sweepAutoClose();
    SpawnService::sweepOverdue();   // a helper past its maxMinutes gets one event and one message to its parent
} catch (\Throwable $e) {
    // Advisory: the next tick tries again.
}
exit(0);
