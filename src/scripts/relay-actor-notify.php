#!/usr/bin/env php
<?php
/** Wait briefly for a newly-started selected actor, then submit the fixed Relay notice. */
require_once __DIR__ . '/../includes/AICliAgentsManager.php';

use AICliAgents\Services\AgentRelayService;

$session=(string)($argv[1] ?? '');
$topic=(string)($argv[2] ?? '');
if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/',$session) || !preg_match('/^[a-z][a-z0-9_.-]{1,127}$/',$topic)) exit(1);
for ($attempt=0; $attempt<10; $attempt++) {
    $result=AgentRelayService::notifyActorSession($session,$topic);
    if (($result['status'] ?? '') === 'ok') exit(0);
    sleep(2);
}
exit(1);
