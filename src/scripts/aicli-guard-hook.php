#!/usr/bin/env php
<?php
/**
 * Layer 3 of the share-filesystem guard (docs/specs/SHFS_RECURSIVE_READ_GUARD.md, Forgejo #972):
 * the pre-tool hook of an agent. It reads the hook JSON on stdin, asks ShfsGuard, and prints the
 * deny form of the agent on a refusal.
 *
 * Usage: aicli-guard-hook.php --agent=claude|opencode
 *   claude   stdin {tool_name, tool_input, cwd}; refusal prints the PreToolUse deny JSON.
 *   opencode stdin {tool, args, cwd};            refusal prints {"deny":true,"reason":...}.
 *
 * Fail open: no AICLI_GUARD_HOOKS=1 (R18: the agent tool guard of this workspace is off), a parse error or any exception allows the call (exit 0, no output).
 * Layers 1 and 2 still apply then. Exit code is always 0.
 *
 * Test hook: with AICLI_GUARD_TEST=1 and AICLI_GUARD_CTX_FILE=<json file> the host context comes
 * from that file (roots and pools) and a refusal writes no log line. Without AICLI_GUARD_TEST=1
 * the variable is ignored.
 */

if (getenv('AICLI_GUARD_HOOKS') !== '1') {
    exit(0);
}

try {
    $agent = 'claude';
    foreach (array_slice($argv ?? [], 1) as $a) {
        if (strncmp($a, '--agent=', 8) === 0) {
            $agent = substr($a, 8);
        }
    }
    if ($agent !== 'claude' && $agent !== 'opencode') {
        exit(0);
    }
    $raw = stream_get_contents(STDIN);
    $in = json_decode($raw === false ? '' : $raw, true);
    if (!is_array($in)) {
        exit(0);
    }

    require_once __DIR__ . '/../includes/Autoload.php';
    \AICliAgents\Autoload::register();

    if ($agent === 'claude') {
        $tool = (string)($in['tool_name'] ?? '');
        $input = is_array($in['tool_input'] ?? null) ? $in['tool_input'] : [];
    } else {
        $tool = (string)($in['tool'] ?? '');
        $input = is_array($in['args'] ?? null) ? $in['args'] : [];
    }
    $cwd = (string)($in['cwd'] ?? '');
    if ($cwd === '') {
        $cwd = (string)getcwd();
    }

    $ctx = \AICliAgents\Services\ShfsGuard::hostContext();
    $refusal = \AICliAgents\Services\ShfsGuard::checkTool($agent, $tool, $input, $cwd, $ctx);
    if ($refusal === null) {
        exit(0);
    }
    $cmdText = '';
    foreach (['command', 'cmd', 'pattern', 'path', 'file_path'] as $k) {
        if (isset($input[$k]) && is_string($input[$k]) && $input[$k] !== '') {
            $cmdText = $input[$k];
            break;
        }
    }
    \AICliAgents\Services\ShfsGuard::onRefusal($refusal, 'hook', $agent, $cmdText !== '' ? $cmdText : $tool);
    if ($agent === 'claude') {
        echo json_encode([
            'hookSpecificOutput' => [
                'hookEventName' => 'PreToolUse',
                'permissionDecision' => 'deny',
                'permissionDecisionReason' => $refusal['text'],
            ],
        ], JSON_UNESCAPED_SLASHES) . "\n";
    } else {
        echo json_encode(['deny' => true, 'reason' => $refusal['text']], JSON_UNESCAPED_SLASHES) . "\n";
    }
} catch (\Throwable $e) {
    // Fail open.
}
exit(0);
