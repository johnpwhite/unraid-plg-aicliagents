<?php

declare(strict_types=1);

/**
 * ensure-command-env.php — AGENT_SPAWN_WORKSPACE.md R21.
 *
 * Called by the shared launcher (aicli-shell.sh) before every agent launch:
 *   php ensure-command-env.php <agentId> <home>
 * It makes sure the commands that the agent runs keep the AICLI_* variables (AICLI_RELAY_COMMAND and the
 * others), through AgentCommandEnvService. It does nothing for an agent that has no `command_env` rule. It
 * prints nothing and always ends with exit code 0, so it can never stop a launch.
 */

$_SERVER['DOCUMENT_ROOT'] = '/usr/local/emhttp';
require_once dirname(__DIR__) . '/includes/AICliAgentsManager.php';

use AICliAgents\Services\AgentCommandEnvService;

try {
    $agentId = (string)($argv[1] ?? '');
    $home = (string)($argv[2] ?? '');
    if (preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $agentId) === 1) {
        AgentCommandEnvService::ensure($agentId, $home !== '' ? $home : null);
    }
} catch (\Throwable $e) {
    // Never stop a launch.
}
exit(0);
