<?php

/**
 * Start the storage supervisor and verify its pidfile/heartbeat ownership.
 *
 * Kept as a script file because namespaced PHP inside a double-quoted `php -r`
 * shell argument loses backslashes and is blocked by the release anti-pattern
 * gate. Exit status is the installer contract: zero means ready.
 */

require_once '/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/AICliAgentsManager.php';

exit(\AICliAgents\Services\SupervisorService::ensureReady(10000) ? 0 : 1);
