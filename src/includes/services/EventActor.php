<?php
/**
 * <module_context>
 *     <name>EventActor</name>
 *     <description>Names who caused an event, for EventLedger (docs/specs/
 *     PLUGIN_EVENT_LEDGER_AND_SUBSCRIPTIONS.md, R2). Three shapes: an agent
 *     tool call (the session behind AICLI_SESSION_ID), a human in the
 *     browser (a per-tab client id plus an optional device label), or the
 *     system (the supervisor, an event script, or a bare CLI call).</description>
 *     <dependencies>AdminService (agentId lookup only — optional, guarded).</dependencies>
 *     <constraints>Never throws. Never reads a secret. A client id or device
 *     label is sanitised before it is kept — this text ends up in the ledger,
 *     which every reader of aicli-admin can see.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

final class EventActor {

    /** Test seam / script override: when set, current() returns this verbatim. */
    public static ?array $override = null;

    /**
     * Resolve the actor for the event about to be recorded.
     *
     * Order: an explicit override; an agent tool call (AICLI_SESSION_ID set);
     * a browser AJAX call (an X-AICli-Client header or aicli_client param);
     * a CLI process with no session id (the supervisor, an event script); a
     * plain web request with no client id (a page load before the SPA sets
     * one — rare, still a human).
     *
     * @return array{type:string,sessionId?:string,agentId?:string,client?:string,label?:string}
     */
    public static function current(): array {
        if (self::$override !== null) {
            return self::$override;
        }

        $sessionId = (string)(getenv('AICLI_SESSION_ID') ?: '');
        if ($sessionId !== '') {
            return [
                'type'      => 'agent',
                'sessionId' => $sessionId,
                'agentId'   => self::agentIdForSession(),
            ];
        }

        $client = self::firstNonEmpty($_SERVER['HTTP_X_AICLI_CLIENT'] ?? null, $_REQUEST['aicli_client'] ?? null);
        if ($client !== '') {
            $actor = ['type' => 'human', 'client' => 'browser:' . self::sanitizeClientId($client)];
            $device = self::firstNonEmpty($_SERVER['HTTP_X_AICLI_DEVICE'] ?? null, $_REQUEST['aicli_device'] ?? null);
            $label = self::sanitizeLabel($device);
            if ($label !== '') {
                $actor['label'] = $label;
            }
            return $actor;
        }

        if (PHP_SAPI === 'cli') {
            return ['type' => 'system'];
        }

        return ['type' => 'human'];
    }

    /** AdminService may not be loaded yet in a minimal caller — never fatal. */
    private static function agentIdForSession(): string {
        if (!class_exists(AdminService::class)) {
            return '';
        }
        try {
            $identity = AdminService::callerIdentity();
            return (string)($identity['agentId'] ?? '');
        } catch (\Throwable $e) {
            return '';
        }
    }

    private static function firstNonEmpty($a, $b): string {
        $a = (string)($a ?? '');
        if ($a !== '') return $a;
        return (string)($b ?? '');
    }

    /** A per-tab client id: [A-Za-z0-9_-]{1,32}, everything else stripped. */
    private static function sanitizeClientId(string $raw): string {
        $clean = (string)preg_replace('/[^A-Za-z0-9_-]/', '', $raw);
        return substr($clean, 0, 32);
    }

    /** A device label: printable text, control characters stripped, 40 chars max. */
    private static function sanitizeLabel(string $raw): string {
        $clean = (string)preg_replace('/[\x00-\x1F\x7F]/', '', $raw);
        return substr(trim($clean), 0, 40);
    }
}
