<?php
/**
 * <module_context>
 *     <name>EventSubscriptionStore</name>
 *     <description>Per-session event subscription and cursor, for
 *     `aicli_subscribe_events` / `aicli_get_events` / `aicli_ack_events`
 *     (docs/specs/PLUGIN_EVENT_LEDGER_AND_SUBSCRIPTIONS.md R3/R4/R5). One
 *     JSON file per session, named by AICLI_SESSION_ID, stored beside the
 *     Relay pending queue in the per-user state dir — TmuxService's
 *     `relayPendingDir()` uses `<user-state>/relay/pending`; this store uses
 *     `<user-state>/events`. A session id is stable across an agent relaunch,
 *     so the subscription survives one; it is reaped the same way a closed
 *     workspace's other traces are.</description>
 *     <dependencies>ConfigService (per-user state dir); AtomicWriteService
 *     (atomic write).</dependencies>
 *     <constraints>Never throws to its caller. A missing or malformed file
 *     reads back as an empty subscription, never a fatal. Session ids are
 *     sanitised to [A-Za-z0-9_-] before they become a filename.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

require_once __DIR__ . '/AtomicWriteService.php';

final class EventSubscriptionStore {

    /** Test seam: overrides the subscriptions directory. Null uses the real per-user path. */
    public static ?string $dir = null;

    /** Directory holding one JSON file per subscribed session. */
    public static function dir(): string {
        return self::$dir ?? (ConfigService::getUserStatePath() . '/events');
    }

    private static function safeId(string $sessionId): string {
        return (string)preg_replace('/[^A-Za-z0-9_-]/', '', $sessionId);
    }

    private static function fileFor(string $sessionId): string {
        return self::dir() . '/' . self::safeId($sessionId) . '.json';
    }

    /**
     * @return array{kinds:list<string>,filter:array<string,string>,cursor:array{boot_id:string,seq:int},updatedAt:int}
     */
    public static function load(string $sessionId): array {
        $empty = ['kinds' => [], 'filter' => [], 'cursor' => ['boot_id' => '', 'seq' => 0], 'updatedAt' => 0];
        $sessionId = self::safeId($sessionId);
        if ($sessionId === '') return $empty;

        $file = self::fileFor($sessionId);
        if (!is_file($file)) return $empty;

        $data = json_decode((string)@file_get_contents($file), true);
        if (!is_array($data)) return $empty;

        $cursor = is_array($data['cursor'] ?? null) ? $data['cursor'] : [];
        return [
            'kinds'     => is_array($data['kinds'] ?? null) ? array_values(array_filter($data['kinds'], 'is_string')) : [],
            'filter'    => is_array($data['filter'] ?? null) ? $data['filter'] : [],
            'cursor'    => ['boot_id' => (string)($cursor['boot_id'] ?? ''), 'seq' => (int)($cursor['seq'] ?? 0)],
            'updatedAt' => (int)($data['updatedAt'] ?? 0),
        ];
    }

    /** Raw write. Callers should prefer subscribe()/ack(), which validate first. */
    public static function save(string $sessionId, array $data): bool {
        $sessionId = self::safeId($sessionId);
        if ($sessionId === '') return false;

        $dir = self::dir();
        if (!is_dir($dir)) @mkdir($dir, 0777, true);

        $data['updatedAt'] = time();
        return AtomicWriteService::writeJson(self::fileFor($sessionId), $data);
    }

    /**
     * Validate and apply a subscription change. `$kinds === []` clears the
     * subscription. `$replace === false` merges `$kinds` into the existing
     * set instead of replacing it. The cursor is always carried forward
     * unchanged — subscribing does not lose a session's place in the ledger.
     *
     * @return array{ok:bool,error?:string,subscription?:array}
     */
    public static function subscribe(string $sessionId, array $kinds, array $filter = [], bool $replace = true): array {
        $sessionId = self::safeId($sessionId);
        if ($sessionId === '') return ['ok' => false, 'error' => 'No session id.'];

        foreach ($kinds as $kind) {
            if (!is_string($kind) || $kind === '' || !preg_match('/^[A-Za-z0-9_.*]+$/', $kind)) {
                $label = is_string($kind) ? $kind : gettype($kind);
                return ['ok' => false, 'error' => "Invalid event kind pattern '$label'. Use letters, digits, '.', '_' and '*' only, e.g. 'workspace.*'."];
            }
        }

        $existing = self::load($sessionId);
        $newKinds = $kinds;
        if (!$replace && $kinds !== []) {
            $newKinds = array_values(array_unique(array_merge($existing['kinds'], $kinds)));
        }

        $cleanFilter = [];
        foreach (['workspaceId', 'agentId', 'actor'] as $key) {
            if (isset($filter[$key]) && is_string($filter[$key]) && $filter[$key] !== '') {
                $cleanFilter[$key] = $filter[$key];
            }
        }

        $ok = self::save($sessionId, [
            'kinds'  => array_values($newKinds),
            'filter' => $cleanFilter,
            'cursor' => $existing['cursor'],
        ]);
        if (!$ok) return ['ok' => false, 'error' => 'Could not save the subscription.'];

        return ['ok' => true, 'subscription' => self::load($sessionId)];
    }

    /** Set a session's cursor (the boot id it was read against, and the last seq it has seen). */
    public static function ack(string $sessionId, int $seq, string $bootId): bool {
        $sessionId = self::safeId($sessionId);
        if ($sessionId === '') return false;

        $record = self::load($sessionId);
        $record['cursor'] = ['boot_id' => $bootId, 'seq' => max(0, $seq)];
        return self::save($sessionId, $record);
    }

    /** Remove one session's subscription file. Returns true when a file was removed. */
    public static function remove(string $sessionId): bool {
        $path = self::dir() . "/" . self::safeId($sessionId) . ".json";
        return is_file($path) ? @unlink($path) : false;
    }

    /**
     * Delete every subscription file whose session id is not in
     * $liveSessionIds — called from wherever a closed workspace's other
     * traces are swept (see UtilityHandler::saveWorkspaces()'s Relay-trace
     * sweep, which this mirrors for events).
     *
     * @return int number of files removed
     */
    public static function reapStale(array $liveSessionIds): int {
        $live = array_fill_keys(array_map([self::class, 'safeId'], array_filter($liveSessionIds, 'is_string')), true);
        $reaped = 0;
        foreach (glob(self::dir() . '/*.json') ?: [] as $file) {
            $id = basename($file, '.json');
            if ($id === '' || isset($live[$id])) continue;
            if (@unlink($file)) $reaped++;
        }
        return $reaped;
    }
}
