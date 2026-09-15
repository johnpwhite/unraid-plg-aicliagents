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
 *   php event-append.php --kind=<kind> [--actor=system] [--summary=<text>]
 *       [--subject=<json>] [--data=<json>]
 *
 *   --kind     required. One of the vocabulary kinds
 *              (docs/specs/PLUGIN_EVENT_LEDGER_AND_SUBSCRIPTIONS.md).
 *   --actor    optional, defaults to "system" (this script has no
 *              AICLI_SESSION_ID and is never called from a browser).
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

    EventActor::$override = ['type' => (string)($args['actor'] ?? 'system')];

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
