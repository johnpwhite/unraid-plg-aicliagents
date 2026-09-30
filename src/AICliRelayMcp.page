<?php
/**
 * HTTPS JSON-RPC MCP endpoint for one administrator-created external Relay client.
 *
 * Shares its tool table and dispatch with the local stdio adapter via
 * RelayMcpTools, narrowed to the remote's grants (RelayGrants; the migration
 * default equals RelayMcpTools::EXTERNAL_TOOLS). That default is the
 * ADR 0002 boundary: this identity reaches only its own inbox, contact
 * discovery, and private direct messaging, and holds no workspace, actor,
 * topic, or Manager authority.
 */
require_once __DIR__ . '/includes/AICliAgentsManager.php';
use AICliAgents\Services\AgentRelayService;
use AICliAgents\Services\RelayMcpTools;
header('Content-Type: application/json');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['error'=>'POST required']); exit; }
$auth=(string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
if (!preg_match('/^Bearer\\s+([A-Fa-f0-9]{64})$/',$auth,$m) || ($principal=AgentRelayService::httpPrincipalForToken($m[1]))===null) { http_response_code(401); echo json_encode(['error'=>'unauthorized']); exit; }
$session=(string)$principal['id'];
$request=json_decode((string)file_get_contents('php://input'),true); $id=$request['id'] ?? null; $method=(string)($request['method'] ?? ''); $args=is_array($request['params']['arguments'] ?? null)?$request['params']['arguments']:[];
function relayHttpReply($id,$result): void { echo json_encode(['jsonrpc'=>'2.0','id'=>$id,'result'=>$result],JSON_UNESCAPED_SLASHES); }
if ($method==='initialize') { relayHttpReply($id,['protocolVersion'=>(string)($request['params']['protocolVersion'] ?? '2025-06-18'),'capabilities'=>['tools'=>['listChanged'=>false]],'serverInfo'=>['name'=>'aicli-relay-http','version'=>'1.0.0']]); exit; }
if ($method==='ping') { relayHttpReply($id,new stdClass()); exit; }
// RELAY_LINKED_BOXES.md: the same per-remote grants as the plugin listener (RelayHttpService).
if ($method==='tools/list') { relayHttpReply($id,['tools'=>RelayMcpTools::definitionsFor($principal)]); exit; }
if ($method==='tools/call') { relayHttpReply($id,RelayMcpTools::toolResult(RelayMcpTools::call((string)($request['params']['name'] ?? ''),$args,$session,null,$principal))); exit; }
http_response_code(400); echo json_encode(['error'=>'unsupported method']);
