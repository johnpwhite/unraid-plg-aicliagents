#!/usr/bin/env php
<?php
/**
 * CLI tee for a bash publisher that cannot call PHP directly in-process
 * (docs/specs/PLUGIN_EVENT_LEDGER_AND_SUBSCRIPTIONS.md, "The tee"). The
 * shell nchan_notify helpers (src/event/stopping, src/event/stopping_array,
 * src/scripts/installer/generation.sh) call this AFTER their own curl to
 * nginx's Nchan socket, so a shell publish gets a ledger line the same way
 * a PHP publish does through NchanService::publish(). Best-effort: this
 * script always exits 0, even on a bad argument or a write failure — a
 * ledger miss must never fail the caller's shutdown/deploy sequence.
 *
 * Usage:
 *   php event-append.php --kind=<kind> [--summary=<text>]
 *       [--subject=<json>] [--data=<json>]
 *
 *   --kind     required. One of the six storage backup/restore kinds used by
 *              the plugin's shell helpers. Every other kind is refused.
 *   --actor    ignored. The actor comes from the parent process tree, not an
 *              argument or this process's environment.
 *   --summary  optional short text, plain (not JSON).
 *   --subject  optional JSON object, e.g. '{"workspaceId":"abc"}'.
 *   --data     optional JSON object or array, the same payload the shell
 *              script already published to nchan.
 *
 * Prints the seq written on success, nothing on a best-effort failure.
 */

require_once __DIR__ . '/../includes/AICliAgentsManager.php';

use AICliAgents\Services\EventActor;
use AICliAgents\Services\EventLedger;
use AICliAgents\Services\RelayMcpTools;

/** Event kinds used by the plugin-owned backup and restore shell helpers. */
const AICLI_EVENT_APPEND_SHELL_KINDS = [
    'storage.backup.started', 'storage.backup.finished', 'storage.backup.failed',
    'storage.restore.started', 'storage.restore.finished', 'storage.restore.failed',
];

/** @return array<string,string> */
function aicliEventAppendParseArgs(array $argv): array {
    $out = [];
    foreach ($argv as $arg) {
        if (strncmp($arg, '--', 2) !== 0) continue;
        $eq = strpos($arg, '=');
        if ($eq === false) {
            $out[substr($arg, 2)] = '';
        } else {
            $out[substr($arg, 2, $eq - 2)] = substr($arg, $eq + 1);
        }
    }
    return $out;
}

/** @return array<mixed> */
function aicliEventAppendJsonArg(string $raw): array {
    if ($raw === '') return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

try {
    $args = aicliEventAppendParseArgs(array_slice($argv, 1));
    $kind = (string)($args['kind'] ?? '');
    if ($kind === '') {
        exit(0); // best-effort: no kind, nothing to record, never fail the caller
    }

    // Only the plugin-owned backup helpers use this CLI tee. A workspace must
    // not forge action or Activity rows by choosing an arbitrary event kind.
    if (!in_array($kind, AICLI_EVENT_APPEND_SHELL_KINDS, true)) exit(0);

    // The event-append process environment can be changed by its caller. Start
    // at the parent process and use its launcher identity when one exists.
    $sessionId = RelayMcpTools::inheritedSessionFromParent();
    EventActor::$override = $sessionId !== ''
        ? ['type' => 'agent', 'sessionId' => $sessionId]
        : ['type' => 'system'];

    $summary = (string)($args['summary'] ?? '');
    $subject = aicliEventAppendJsonArg((string)($args['subject'] ?? ''));
    $data    = aicliEventAppendJsonArg((string)($args['data'] ?? ''));

    $seq = EventLedger::append($kind, $subject, $summary, $data);
    if ($seq !== null) {
        echo $seq, PHP_EOL;
    }
} catch (\Throwable $e) {
    // Best-effort: never fail the caller (a shutdown/deploy sequence).
}
exit(0);
