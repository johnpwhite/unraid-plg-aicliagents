<?php
/**
 * Supervisor-tick activity sweep (docs/specs/EVENT_FIRST_RECONCILIATION.md,
 * "Rules for the supervisor tick"). Before this script, the activity watchdog
 * (running -> stalled after 120 s silence, hard-cap timeout, `done` pruning),
 * the orphan storage-job finish, and the stale install-marker unlink all ran
 * ONLY when a browser polled list_activities/list_active_installs — with no
 * tab open, a hung install was never marked stalled. The supervisor now runs
 * this every 30 s (same pattern as sync-activity.php) so those transitions
 * happen with no browser at all.
 *
 * docs/specs/REVIEW_2026-09-13_EVENTS_AND_SECURITY.md#S6: this tick also
 * sweeps leftover chunked-upload part files. UtilityHandler::saveFileChunk()
 * only removes `<dest>.part-<uploadId>` (and its `.idx` sidecar) when a NEW
 * upload starts in the same folder — a folder that never receives another
 * upload keeps the leftover forever. This backstop reads the tmpfs index
 * UtilityHandler maintains (one marker per uploadId) and removes each stale
 * part named there. It walks no directory: see aicliSweepStaleUploadParts().
 *
 * Best-effort: this script always exits 0, even on a fault, so a sweep miss
 * can never wedge the supervisor tick.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/AICliAgentsManager.php';

\AICliAgents\Services\EventActor::$override = ['type' => 'system'];

try {
    \AICliAgents\Services\ActivityService::sweep();
} catch (\Throwable $e) {
    // Best-effort — never fail the supervisor tick that spawned this script.
}

try {
    aicliSweepStaleUploadParts();
} catch (\Throwable $e) {
    // Best-effort — same reasoning as above.
}

// docs/specs/VOICE_MAIL.md: copy voice mail from its tmpfs store to Flash so it
// survives a reboot. Voice mail is written on every spoken message, so it lives in
// RAM and is synced here on the heartbeat rather than written to the USB stick each
// time (HYBRID_PERSISTENCE.md). flush() returns at once when nothing changed — it
// must stay cheap on every tick, because a sweep doing real work per tick is exactly
// how this script once stalled the supervisor queue.
try {
    \AICliAgents\Services\VoiceMailService::flush();
} catch (\Throwable $e) {
    // Best-effort — a missed flush is retried on the next tick.
}

exit(0);

/**
 * S6 (as built): stale chunked-upload parts are removed through the tmpfs
 * index UtilityHandler keeps (one entry per uploadId). No directory is ever
 * walked here: a first version walked every workspace tree on each supervisor
 * tick, which stalled the supervisor queue and read /mnt/user over FUSE.
 */
function aicliSweepStaleUploadParts(): void
{
    if (!class_exists('\AICliAgents\Handlers\UtilityHandler')) {
        require_once dirname(__DIR__, 2) . '/includes/handlers/UtilityHandler.php';
    }
    $swept = \AICliAgents\Handlers\UtilityHandler::sweepStaleUploadPartsFromIndex();
    if ($swept > 0) {
        aicli_log("Supervisor sweep removed $swept stale upload part file(s)", AICLI_LOG_DEBUG, "activity-sweep");
    }
}
