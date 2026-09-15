#!/usr/bin/env php
<?php
/**
 * Relay self-service control for an already-running agent workspace.
 * Identity is the launch-injected AICLI_SESSION_ID; callers never supply one.
 * Usage: relay-agent.php <topics|subscriptions|inbox|contacts|direct|reply|create|update|archive|delete|join|leave|publish|request|request-status|ack|resolve|fail> ...
 *
 * Two of these take positional arguments that are easy to get wrong:
 *   direct <recipient_session_id> <message>
 *   reply  <thread_id> <message> <recipient_session_id>      <- recipient is LAST
 * reply keeps the exchange in ONE thread; direct always opens a new one. mvp-dmoe
 * reported reply as broken (2026-09-08) after calling it with two arguments and
 * reading the direct usage from the error, so both shapes are spelled out here,
 * in --help, and in the error itself.
 * Event summaries, request summaries, and response notes are limited to 2048 bytes.
 */
require_once __DIR__ . '/../includes/AICliAgentsManager.php';

use AICliAgents\Services\AgentRelayService;
use AICliAgents\Services\TmuxService;

$session = (string)(getenv('AICLI_SESSION_ID') ?: '');
$action = $argv[1] ?? 'topics';
$topic = $argv[2] ?? '';
$description = $argv[3] ?? '';

switch ($action) {
    case 'topics':        $result = ['status'=>'ok', 'topics'=>AgentRelayService::agentTopics($session)]; break;
    case 'subscriptions': $result = ['status'=>'ok', 'relay'=>AgentRelayService::subscriptions($session)]; break;
    case 'inbox':         $result = ['status'=>'ok', 'inbox'=>AgentRelayService::agentInbox($session)]; break;
    case 'contacts':      $result = ['status'=>'ok', 'contacts'=>AgentRelayService::agentContacts($session)]; break;
    case 'direct':        $result = AgentRelayService::directMessage($session, $topic, $description); break;
    case 'reply':         $result = AgentRelayService::directMessage($session, (string)($argv[4] ?? ''), $description, $topic); break;
    case 'create':
    case 'update':        $result = AgentRelayService::agentSaveTopic($session, $topic, $description); break;
    case 'archive':       $result = AgentRelayService::agentArchiveTopic($session, $topic); break;
    case 'delete':        $result = AgentRelayService::agentRemoveTopic($session, $topic); break;
    case 'join':          $result = AgentRelayService::agentJoinTopic($session, $topic); break;
    case 'leave':         $result = AgentRelayService::agentLeaveTopic($session, $topic); break;
    case 'publish':       $result = AgentRelayService::agentPublish($session, $topic, (string)($argv[3] ?? ''), (string)($argv[4] ?? '')); break;
    case 'request':       $result = AgentRelayService::request($session, $topic, $description, (int)($argv[4] ?? 300), (int)($argv[5] ?? 1800)); break;
    case 'request-status':$result = AgentRelayService::requestStatus($session, $topic); break;
    case 'ack':           $result = AgentRelayService::respondRequest($session, $topic, 'acknowledged', $description); break;
    case 'resolve':       $result = AgentRelayService::respondRequest($session, $topic, 'resolved', $description); break;
    case 'fail':          $result = AgentRelayService::respondRequest($session, $topic, 'failed', $description); break;
    // Session-agnostic: re-attempt delivery of every deferred DM notice across all
    // live sessions. Driven by the supervisor tick so a queued notice surfaces even
    // when no Manager browser tab is open to run the drawer poll.
    case 'drain-all':     $result = ['status'=>'ok', 'drain'=>TmuxService::drainAllPendingRelay()]; break;
    default: $result = ['status'=>'error', 'message'=>'Unknown action. Use topics, subscriptions, inbox, contacts, direct, reply, create, update, archive, delete, join, leave, publish, request, request-status, ack, resolve, fail, or drain-all. Message shapes: "direct <recipient_session_id> <message>" and "reply <thread_id> <message> <recipient_session_id>".'];
}
echo json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(($result['status'] ?? 'error') === 'ok' ? 0 : 1);
