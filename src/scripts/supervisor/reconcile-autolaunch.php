<?php
/** Headless saved-workspace crash reconciliation, invoked by the supervisor. */
$_SERVER['DOCUMENT_ROOT'] = '/usr/local/emhttp';
require_once '/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/AICliAgentsManager.php';

\AICliAgents\Services\AutoLaunchService::reconcileDeadWorkspaces();

