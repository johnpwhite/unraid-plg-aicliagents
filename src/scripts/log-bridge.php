#!/usr/bin/env php
<?php
/**
 * <module_context>
 *     <name>log-bridge</name>
 *     <description>Safe PHP bridge for shell script logging. Accepts arguments via $argv instead of string interpolation.</description>
 *     <dependencies>AICliAgentsManager.php</dependencies>
 *     <constraints>CLI-only. Arguments passed via $argv to prevent injection.</constraints>
 * </module_context>
 *
 * Usage: php log-bridge.php <action> [args...]
 *   log <message> <level> [context]   - Log a message via aicli_log
 *   init <username> [force]           - Initialize working directory
 *   stop <session_id> [sync]          - Stop a terminal session
 *   workspace_exited <session_id> <exit_code> - Publish the WORKSPACE_LIFECYCLE_EVENTS.md
 *                                        `exited` event (agent process exit inside a
 *                                        live session; see agent-exit-recorder.sh)
 */

// #367: this generation's manager, never the src link an update repoints.
$MANAGER = dirname(__DIR__) . '/includes/AICliAgentsManager.php';

// Fallback to file logging if manager doesn't exist (e.g., during uninstall)
function fallback_log($message) {
    $logFile = '/tmp/unraid-aicliagents/debug.log';
    $entry = '[' . date('Y-m-d H:i:s') . '] [log-bridge] ' . $message . PHP_EOL;
    @file_put_contents($logFile, $entry, FILE_APPEND);
}

if ($argc < 2) {
    fallback_log('log-bridge called with no arguments');
    exit(1);
}

$action = $argv[1];

if (!file_exists($MANAGER)) {
    fallback_log("Manager not found at $MANAGER. Action: $action");
    exit(0);
}

require_once $MANAGER;

switch ($action) {
    case 'log':
        $message = $argv[2] ?? '';
        $level = (int)($argv[3] ?? 2); // Default: INFO
        $context = $argv[4] ?? 'SHELL';
        aicli_log("[$context] $message", $level);
        break;

    case 'init':
        $username = $argv[2] ?? '';
        $force = ($argv[3] ?? '') === 'true';
        if (!empty($username)) {
            aicli_init_working_dir($username, $force);
        }
        break;

    case 'stop':
        $sessionId = $argv[2] ?? '';
        $sync = ($argv[3] ?? '') === 'true';
        if (!empty($sessionId)) {
            stopAICliTerminal($sessionId, $sync);
        }
        break;

    // WORKSPACE_LIFECYCLE_EVENTS.md: the agent process inside a live session
    // just ended (a crash, a normal exit, or a relaunch inside the same
    // workspace). Published from agent-exit-recorder.sh, which runs in the
    // same shell as every other session event and has no other cheap way to
    // reach Nchan. Best-effort — a malformed id is simply ignored.
    case 'workspace_exited':
        $sessionId = $argv[2] ?? '';
        $exitCode = (int)($argv[3] ?? 0);
        if ($sessionId !== '' && class_exists('\\AICliAgents\\Services\\EventBus')) {
            \AICliAgents\Services\EventBus::publish('workspace', [], [
                'event' => 'exited',
                'id'    => $sessionId,
                'code'  => $exitCode,
            ]);
        }
        break;

    default:
        fallback_log("Unknown action: $action");
        exit(1);
}
