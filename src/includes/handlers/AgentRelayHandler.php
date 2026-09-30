<?php
namespace AICliAgents\Handlers;
use AICliAgents\Services\AgentRelayService;
use AICliAgents\Services\RelayPeerService;
class AgentRelayHandler {
    public static function handle($action, $id) {
        $session = $id !== 'default' ? $id : (string)($_REQUEST['session_id'] ?? '');
        switch ($action) {
            case 'relay_get_session': return ['status'=>'ok','relay'=>AgentRelayService::subscriptions($session),'topics'=>AgentRelayService::topicCatalog(true),'inbox'=>AgentRelayService::inbox($session, 5)];
            // Full per-workspace message history for the drawer's "Relay inbox" view:
            // direct messages (sent + received) + received topic events + contacts.
            case 'relay_get_inbox': return array_merge(['status'=>'ok'], AgentRelayService::agentInbox($session, 40));
            case 'relay_delete_message': return AgentRelayService::deleteInboxMessage($session, (string)($_POST['message_id'] ?? $_GET['message_id'] ?? ''));
            case 'relay_clear_read': return AgentRelayService::clearInboxMessages($session, is_array($j = json_decode((string)($_POST['ids'] ?? '[]'), true)) ? $j : []);
            case 'relay_get_topics': return ['status'=>'ok','topics'=>AgentRelayService::topicCatalog(true)];
            // CONTINUE_ON_RESTART.md (2026-09-09): the "continue after a restart"
            // setting moved to ConfigService (session-restart behaviour, not Relay
            // messaging) — it is no longer one of this handler's settings.
            case 'relay_get_settings': return ['status'=>'ok','notification_topic'=>AgentRelayService::notificationTopic(),'notifications_mirrored'=>AgentRelayService::notificationsMirrored(),'notification_topic_fixed'=>AgentRelayService::NOTIFICATION_TOPIC,'mcp_enabled'=>AgentRelayService::mcpEnabled()];
            case 'relay_get_history': return ['status'=>'ok','history'=>AgentRelayService::history((int)($_GET['limit'] ?? 100))];
            case 'relay_set_notifications_mirrored': return AgentRelayService::setNotificationsMirrored(($_POST['enabled'] ?? '0') === '1');
            case 'relay_set_mcp_enabled': return AgentRelayService::setMcpEnabled(($_POST['enabled'] ?? '0') === '1');
            // #157: terminal-input safety rules (shared block-list + per-agent idle
            // allow-list) for TmuxService::paneAcceptsInput() — governs Relay notices,
            // Continue, and Reload, not only Relay. Kept in this handler (2026-09-09
            // PaneInputRules rename) because it is still reached from the AJAX chain
            // ahead of a dedicated handler; moving the case arms is a bigger change
            // than a rename and was left alone.
            case 'pane_input_get_rules': return array_merge(['status'=>'ok'], \AICliAgents\Services\PaneInputRules::effective(), ['overlay'=>\AICliAgents\Services\PaneInputRules::readOverlay(), 'agents'=>array_map(static fn(array $a): string => (string)($a['name'] ?? ''), \AICliAgents\Services\AgentRegistry::getDefaultAgents())]);
            case 'pane_input_save_rules': $layer=json_decode((string)($_POST['rules'] ?? '{}'),true); return \AICliAgents\Services\PaneInputRules::saveOverlay(is_array($layer)?$layer:[]);
            case 'pane_input_probe': $aid=(string)($_REQUEST['agent_id'] ?? ''); return preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/',$aid) && preg_match('/^[A-Za-z0-9_-]{1,128}$/',$session) ? array_merge(['status'=>'ok'], \AICliAgents\Services\TmuxService::paneAcceptsInput($aid,$session)) : ['status'=>'error','message'=>'Choose a running workspace.'];
            // RELAY_REMOTE_CLIENTS.md: a table of remotes, each with its own token.
            // Phase 2 (#299): request_topics feeds the remote Permissions dialog.
            case 'relay_get_http_clients': return ['status'=>'ok','clients'=>AgentRelayService::httpClients(),'request_topics'=>AgentRelayService::requestableTopics()];
            case 'relay_create_http_client': {
                // Legacy `rotate=1` (single-client Manager) rotates row one.
                $rotateId = (string)($_POST['rotate_id'] ?? '');
                if ($rotateId === '' && (($_POST['rotate'] ?? '') === '1')) $rotateId = (string)(AgentRelayService::httpClients()[0]['id'] ?? '');
                return AgentRelayService::createHttpClient((string)($_POST['name'] ?? ''), $rotateId);
            }
            case 'relay_rotate_http_client': return AgentRelayService::rotateHttpClient((string)($_POST['id'] ?? ''));
            case 'relay_rename_http_client': return AgentRelayService::renameHttpClient((string)($_POST['id'] ?? ''), (string)($_POST['name'] ?? ''));
            case 'relay_delete_http_client': return AgentRelayService::deleteHttpClient((string)($_POST['id'] ?? ''));
            case 'relay_get_http_client': return ['status'=>'ok','client'=>AgentRelayService::httpClientPreview()];
            // Returns the secret itself, for the Manager's copy action only (row one when no id).
            case 'relay_reveal_http_token': return AgentRelayService::httpClientToken((string)($_REQUEST['id'] ?? ''));
            // RELAY_LINKED_BOXES.md (#309): linked boxes. Every change here is a
            // Manager action (CSRF-gated by AICliAjax.php, like the rest).
            case 'relay_get_peers': return RelayPeerService::overview();
            case 'relay_set_peer_links': return AgentRelayService::setPeerLinksEnabled(($_POST['enabled'] ?? '0') === '1');
            case 'relay_create_pairing_code': return RelayPeerService::createPairingCode(trim((string)($_POST['url'] ?? '')));
            case 'relay_cancel_pairing_code': return RelayPeerService::cancelPairingCode();
            case 'relay_preview_pairing_code': return RelayPeerService::previewPairingCode((string)($_POST['code'] ?? ''));
            case 'relay_redeem_pairing_code': return RelayPeerService::redeemPairingCode((string)($_POST['code'] ?? ''), trim((string)($_POST['url'] ?? '')), (string)($_POST['replace_id'] ?? ''));
            case 'relay_rename_peer': return RelayPeerService::rename((string)($_POST['id'] ?? ''), (string)($_POST['name'] ?? ''));
            case 'relay_test_peer': return RelayPeerService::test((string)($_POST['id'] ?? ''));
            case 'relay_rotate_peer': return RelayPeerService::rotate((string)($_POST['id'] ?? ''));
            case 'relay_unlink_peer': return RelayPeerService::unlink((string)($_POST['id'] ?? ''));
            case 'relay_set_grants': {
                // One Permissions dialog for remotes and peers (spec §6). Both
                // services re-normalise the posted map: the client is never trusted.
                $g = json_decode((string)($_POST['grants'] ?? '{}'), true);
                $kind = (string)($_POST['kind'] ?? 'peer');
                if ($kind === 'remote') return AgentRelayService::setRemoteGrants((string)($_POST['id'] ?? ''), is_array($g) ? $g : [], $_POST['rate_per_min'] ?? null);
                if ($kind !== 'peer') return ['status'=>'error','message'=>'Unknown kind of Relay principal.'];
                return RelayPeerService::setGrants((string)($_POST['id'] ?? ''), is_array($g) ? $g : [], $_POST['rate_per_min'] ?? null);
            }
            case 'relay_get_status': return ['status'=>'ok','relay'=>AgentRelayService::statusSummary()];
            case 'relay_get_http_listener': return ['status'=>'ok','listener'=>AgentRelayService::httpListenerSettings()];
            case 'relay_set_http_listener': return AgentRelayService::saveHttpListener(
                ($_POST['enabled'] ?? '0') === '1',
                (int)($_POST['port'] ?? AgentRelayService::HTTP_LISTENER_DEFAULT_PORT),
                (string)($_POST['bind'] ?? '0.0.0.0')
            );
            case 'relay_set_actor': return AgentRelayService::setActor((string)($_POST['topic'] ?? ''),(string)($_POST['session_id'] ?? ''),($_POST['start_on_boot'] ?? '1') === '1');
            // actors() returns the {schema, actors} storage document; the UI wants
            // the topic -> owner map. Returning the document double-wrapped it and
            // no saved owner ever displayed.
            case 'relay_get_actors':
                // #128: self-heal ownership by identity before reading the owner table,
                // so a recreated workspace's owner is rebound the moment it is viewed.
                AgentRelayService::reAdoptOwnersByIdentity();
                $owners = AgentRelayService::actors()['actors'] ?? [];
                $running = [];
                // Retained identity for owners whose tab is closed, so the owner
                // table shows name/agent/path instead of "id · not in drawer" (#127).
                $managed = \AICliAgents\Services\ConfigService::getManagedWorkspaces();
                $drawer = [];
                foreach (\AICliAgents\Services\ConfigService::getWorkspaces()['sessions'] ?? [] as $w) {
                    if (($id = (string)($w['id'] ?? '')) !== '') $drawer[$id] = true;
                }
                $known = [];
                foreach ($owners as $o) {
                    $sid = is_array($o) ? (string)($o['session_id'] ?? '') : (string)$o;
                    if ($sid === '') continue;
                    if (!isset($running[$sid])) $running[$sid] = \AICliAgents\Services\ProcessManager::isRunning($sid);
                    if (!isset($known[$sid]) && !isset($drawer[$sid]) && is_array($managed[$sid] ?? null)) {
                        $known[$sid] = [
                            'name'    => (string)($managed[$sid]['name'] ?? $sid),
                            'agentId' => (string)($managed[$sid]['agentId'] ?? ''),
                            'path'    => (string)($managed[$sid]['path'] ?? ''),
                        ];
                    }
                }
                return ['status'=>'ok','actors'=>$owners,'running'=>$running,'known'=>$known];
            case 'relay_actor_status': return AgentRelayService::actorStatus((string)($_REQUEST['session_id'] ?? ''));
            case 'relay_pause_actor': return AgentRelayService::pauseActorWorkspace((string)($_REQUEST['session_id'] ?? ''));
            case 'relay_launch_actor': return AgentRelayService::launchActorWorkspace((string)($_REQUEST['session_id'] ?? ''));
            case 'relay_save_topic': return AgentRelayService::saveTopic((string)($_POST['topic'] ?? ''), (string)($_POST['description'] ?? ''));
            case 'relay_archive_topic': return AgentRelayService::archiveTopic((string)($_POST['topic'] ?? ''));
            case 'relay_remove_topic': return AgentRelayService::removeTopic((string)($_POST['topic'] ?? ''));
            case 'relay_save_subscriptions': $topics=json_decode((string)($_POST['topics'] ?? '[]'),true); $ok=AgentRelayService::saveSubscriptions($session,is_array($topics)?$topics:[],($_POST['agent_self_manage'] ?? '0')==='1'); if ($ok) { $s=AgentRelayService::subscriptions($session); $s['direct_delivery']=($_POST['direct_delivery'] ?? '0')==='1'; $ok=\AICliAgents\Services\AtomicWriteService::writeJson(AgentRelayService::baseDir().'/subscriptions/'.$session.'.json',['schema'=>1,'subscriptions'=>$s['subscriptions'],'agent_self_manage'=>$s['agent_self_manage'],'direct_delivery'=>$s['direct_delivery']]); } return $ok ? ['status'=>'ok'] : ['status'=>'error','message'=>'Could not save subscriptions'];
            case 'relay_publish_test': return AgentRelayService::publish((string)($_POST['topic'] ?? ''),(string)($_POST['severity'] ?? 'info'),(string)($_POST['summary'] ?? ''));
            case 'relay_set_state': return AgentRelayService::setState($session,(string)($_POST['message_id'] ?? ''),(string)($_POST['state'] ?? '')) ? ['status'=>'ok'] : ['status'=>'error','message'=>'Could not update relay delivery'];
        } return null;
    }
}
