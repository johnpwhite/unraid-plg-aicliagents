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
 */

$MANAGER = '/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/AICliAgentsManager.php';

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

    default:
        fallback_log("Unknown action: $action");
        exit(1);
}
