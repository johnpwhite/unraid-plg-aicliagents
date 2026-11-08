#!/usr/bin/env php
<?php
/**
 * Local stdio MCP server with ONE tool, `report`, for a read-only run as the restricted user (docs/specs/AGENT_RUN_ENTRY_POINT.md R18,
 * finding 8). A read-only agent has no shell and no file editing, so it cannot call `aicli-run-report`. This server is its only way to
 * report: it writes the same checked RESULT.json file in the private task folder, as the run user, and does nothing else.
 *
 * It runs as the run user (the agent starts it), loads only the class loader, reads no plugin state and opens no socket. The task folder
 * comes from the `env` block of the MCP file (AICLI_TASK_DIR). Never write anything but JSON-RPC lines to STDOUT.
 */

require_once dirname(__DIR__) . '/includes/Autoload.php';
\AICliAgents\Autoload::register();

use AICliAgents\Services\RunUserService;

const REPORT_TOOL = 'report';

function reply($id, array $result): void
{
    fwrite(STDOUT, json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
}

function fault($id, int $code, string $message): void
{
    fwrite(STDOUT, json_encode(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]], JSON_UNESCAPED_SLASHES) . "\n");
}

while (($line = fgets(STDIN)) !== false) {
    $line = trim($line);
    if ($line === '') continue;
    $msg = json_decode($line, true);
    if (!is_array($msg) || !isset($msg['method'])) continue;
    $id = $msg['id'] ?? null;
    $method = (string)$msg['method'];
    if ($id === null) continue;   // a notification (for example notifications/initialized) gets no reply
    switch ($method) {
        case 'initialize':
            $version = is_string($msg['params']['protocolVersion'] ?? null) ? $msg['params']['protocolVersion'] : '2024-11-05';
            reply($id, ['protocolVersion' => $version, 'capabilities' => ['tools' => new \stdClass()], 'serverInfo' => ['name' => 'aicli-run-report', 'version' => '1']]);
            break;
        case 'ping':
            reply($id, []);
            break;
        case 'tools/list':
            reply($id, ['tools' => [[
                'name' => REPORT_TOOL,
                'description' => 'Report the result of this script run. Call it once when the work is finished or cannot go on. status is done, failed or blocked. summary is a short plain-text summary of at most 2000 characters.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['status', 'summary'], 'properties' => [
                    'status' => ['type' => 'string', 'enum' => ['done', 'failed', 'blocked']],
                    'summary' => ['type' => 'string', 'maxLength' => 2000],
                ]],
            ]]]);
            break;
        case 'tools/call':
            $name = (string)($msg['params']['name'] ?? '');
            if ($name !== REPORT_TOOL) { fault($id, -32602, 'Unknown tool.'); break; }
            $args = is_array($msg['params']['arguments'] ?? null) ? $msg['params']['arguments'] : [];
            putenv('AICLI_RESTRICTED_RUN=1');
            $out = RunUserService::writeRunUserReport((string)($args['status'] ?? ''), (string)($args['summary'] ?? ''));
            $ok = ($out['status'] ?? '') === 'ok';
            reply($id, ['content' => [['type' => 'text', 'text' => $ok ? 'Report saved.' : (string)($out['error'] ?? 'The report could not be saved.')]], 'isError' => !$ok]);
            break;
        default:
            fault($id, -32601, 'Method not found.');
    }
}
