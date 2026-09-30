<?php
/** Headless saved-workspace crash reconciliation, invoked by the supervisor. */
$_SERVER['DOCUMENT_ROOT'] = '/usr/local/emhttp';
require_once dirname(__DIR__, 2) . '/includes/AICliAgentsManager.php';

\AICliAgents\Services\AutoLaunchService::reconcileDeadWorkspaces();

