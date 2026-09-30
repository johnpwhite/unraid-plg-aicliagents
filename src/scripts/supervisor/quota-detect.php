<?php

declare(strict_types=1);

/**
 * SCHEDULED_CONTINUE.md / Forgejo #288.
 *
 * The supervisor calls this best-effort worker on the same 30-second cadence as
 * scheduled-continue.php. It captures only the bounded tail of every running
 * session; the service stores parsed metadata/fingerprints, never transcripts.
 */

$_SERVER['DOCUMENT_ROOT'] = '/usr/local/emhttp';
require_once dirname(__DIR__, 2) . '/includes/AICliAgentsManager.php';

use AICliAgents\Services\EventActor;
use AICliAgents\Services\QuotaDetectionService;

if (class_exists(EventActor::class)) EventActor::$override = ['type' => 'system'];

try {
    QuotaDetectionService::pollAll();
} catch (\Throwable $e) {
    // Quota observation is advisory and must never disturb the supervisor.
}
exit(0);
