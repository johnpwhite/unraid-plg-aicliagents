#!/usr/bin/env php
<?php
/**
 * Relay self-service control for an already-running agent workspace.
 * Identity is the launch-injected AICLI_SESSION_ID; callers never supply one.
 * Usage: relay-agent.php <topics|subscriptions|inbox|contacts|direct|reply|create|update|archive|delete|join|leave|publish|request|request-status|cancel-request|ack|resolve|fail|drain-all|peer-sync> ...
 *
 * A contact on a linked box has an id like peer_<12 hex>__<session id> and the
 * name "<workspace> @ <box>". direct and reply take it like any other id.
 *
 * Two of these take positional arguments that are easy to get wrong:
 *   direct <recipient_session_id> <message>
 *   reply  <thread_id> <message> <recipient_session_id>      <- recipient is LAST
 * reply keeps the exchange in ONE thread; direct always opens a new one. mvp-dmoe
 * reported reply as broken (2026-09-08) after calling it with two arguments and
 * reading the direct usage from the error, so both shapes are spelled out here,
 * in --help, and in the error itself.
 * Event summaries, request summaries, and response notes are limited to 2048 bytes.
 * request <topic> <summary> [ack_seconds] [resolve_seconds] [client_request_id]
 * cancel-request <request_id> [note]   (the original sender only, before it is closed)
 */

/**
 * Always run the LIVE generation's code (Forgejo #333). A workspace can hold a
 * command path that names the generation it launched with
 * (`.generations/<id>/src/scripts/relay-agent.php`), and that tree never
 * changes, so after an update the command ran old Relay code against the
 * shared Relay store — the same fault that made an old MCP server keep a
 * linked-box reply as a local message. Before loading any code, hand this call
 * over to the live generation's relay-agent.php with the same arguments.
 * The guard variable stops a second hop.
 */
if (getenv('AICLI_RELAY_AGENT_LIVE_HOP') === false) {
    $relayAgentLink = (string)(getenv('AICLI_RELAY_AGENT_LIVE_SRC') ?: '/usr/local/emhttp/plugins/unraid-aicliagents/src');   // test seam
    $relayAgentLive = realpath($relayAgentLink);
    $relayAgentMine = realpath(__DIR__ . '/..');
    // Only an INSTALLED generation hops (its tree sits under the plugin
    // directory). A copy run from a repository checkout serves itself.
    $relayAgentPluginDir = realpath(dirname($relayAgentLink));
    if ($relayAgentLive !== false && $relayAgentMine !== false && $relayAgentLive !== $relayAgentMine
        && $relayAgentPluginDir !== false && strpos($relayAgentMine, $relayAgentPluginDir . '/') === 0
        && is_file($relayAgentLive . '/scripts/relay-agent.php')) {
        putenv('AICLI_RELAY_AGENT_LIVE_HOP=1');
        $relayAgentArgs = array_merge([$relayAgentLive . '/scripts/relay-agent.php'], array_slice($argv, 1));
        if (function_exists('pcntl_exec')) @pcntl_exec(PHP_BINARY, $relayAgentArgs);
        // No pcntl (or the exec failed): run it as a child with the same stdio.
        $relayAgentRc = 1;
        passthru(implode(' ', array_map('escapeshellarg', array_merge([PHP_BINARY], $relayAgentArgs))), $relayAgentRc);
        exit($relayAgentRc);
    }
}

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
    case 'request':       $result = AgentRelayService::request($session, $topic, $description, (int)($argv[4] ?? 300), (int)($argv[5] ?? 1800), (string)($argv[6] ?? '')); break;
    case 'request-status':$result = AgentRelayService::requestStatus($session, $topic); break;
    case 'cancel-request':$result = AgentRelayService::cancelRequest($session, $topic, $description); break;
    case 'ack':           $result = AgentRelayService::respondRequest($session, $topic, 'acknowledged', $description); break;
    case 'resolve':       $result = AgentRelayService::respondRequest($session, $topic, 'resolved', $description); break;
    case 'fail':          $result = AgentRelayService::respondRequest($session, $topic, 'failed', $description); break;
    // Session-agnostic: re-attempt delivery of every deferred DM notice across all
    // live sessions. Driven by the supervisor tick so a queued notice surfaces even
    // when no Manager browser tab is open to run the drawer poll.
    case 'drain-all':     $result = ['status'=>'ok', 'drain'=>TmuxService::drainAllPendingRelay()]; break;
    // RELAY_LINKED_BOXES.md §3: the supervisor's _relay_peer_tick. Sends each
    // linked box's outbox and refreshes hello + contacts every 60 s. Does
    // nothing when linked boxes are off or no box is paired.
    case 'peer-sync':     $result = \AICliAgents\Services\RelayPeerService::syncAll(($argv[2] ?? '') === '--force'); break;
    // #331: bring the Relay HTTP listener into the stored state (start or stop),
    // stopping any listener of an older generation. Run by the activation of a
    // new generation and by the supervisor when its audit cannot decide alone.
    // Session-agnostic and idempotent, like drain-all.
    // #383: remove the Relay traces of finished test sessions (ids "e2e…"). Session-
    // agnostic and idempotent; a running session is never touched. Run by
    // tests/lib/e2e-sessions.sh close and by the boot sweep.
    case 'purge-test-traces': $result = ['status'=>'ok', 'purged'=>AgentRelayService::purgeTestSessionTraces()]; break;
    case 'http-listener-reconcile': $result = AgentRelayService::ensureHttpListener(); break;
    default: $result = ['status'=>'error', 'message'=>'Unknown action. Use topics, subscriptions, inbox, contacts, direct, reply, create, update, archive, delete, join, leave, publish, request, request-status, cancel-request, ack, resolve, fail, drain-all, peer-sync, or purge-test-traces. Message shapes: "direct <recipient_session_id> <message>" and "reply <thread_id> <message> <recipient_session_id>".'];
}
echo json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(($result['status'] ?? 'error') === 'ok' ? 0 : 1);
