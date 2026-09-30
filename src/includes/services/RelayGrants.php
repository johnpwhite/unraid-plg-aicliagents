<?php
/**
 * <module_context>
 *     <name>RelayGrants</name>
 *     <description>docs/specs/RELAY_LINKED_BOXES.md §6 — the ONE grant model for
 *     every caller from outside this box: a remote (an MCP client with a bearer
 *     token) or a peer (a linked box). The code default is deny: a grant that is
 *     not on the principal's row means "no". Phase 1 uses `inbox.read`,
 *     `contacts.read` and `dm.send`. Phase 2 (#299) adds, for remotes only,
 *     `request:<topic>` (relay_request + relay_request_status for that topic)
 *     and `request.cancel` (relay_cancel_request, only with a request grant).
 *     A peer never holds a request grant (Phase 3).</description>
 *     <dependencies>none (pure)</dependencies>
 *     <constraints>Pure functions only. No file access. A principal is an array
 *     {kind:'remote'|'peer', id, grants, rate_per_min}.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class RelayGrants {
    const KIND_REMOTE = 'remote';
    const KIND_PEER = 'peer';

    /** Default requests (plus forwarded items for a peer) per minute, per credential. */
    const DEFAULT_RATE = [self::KIND_REMOTE => 30, self::KIND_PEER => 120];
    const RATE_MIN = 1;
    const RATE_MAX = 600;

    /** Grants that Phase 1 accepts on a row, per principal kind. Anything else is dropped. */
    const PHASE1_GRANTS = [
        self::KIND_REMOTE => ['inbox.read', 'contacts.read', 'dm.send'],
        self::KIND_PEER   => ['contacts.read', 'dm.send'],
    ];

    /** Prefix of a per-topic request grant (#299). Remotes only. */
    const REQUEST_PREFIX = 'request:';
    const REQUEST_CANCEL = 'request.cancel';
    /** Topic grammar, the same as AgentRelayService::validTopic() without the `.*` wildcard. */
    const TOPIC_PATTERN = '/^[a-z][a-z0-9_-]{0,31}(?:\.[a-z][a-z0-9_-]{0,31}){1,5}$/';
    const TOPIC_MAX = 128;

    /** MCP tool that each remote grant opens. relay_request* come with request:<topic> (Phase 2, see toolsFor()). */
    const TOOL_FOR_GRANT = [
        'inbox.read' => 'relay_get_inbox',
        'contacts.read' => 'relay_list_contacts',
        'dm.send' => 'relay_send_direct_message',
    ];

    /**
     * Grants a row gets when it is created, or when an older row has no grants.
     *
     * Remote: exactly today's RelayMcpTools::EXTERNAL_TOOLS, so the migration
     * changes no behaviour. Peer: contacts (all saved workspaces) and DM, which
     * is John's decision 4 (2026-09-23): the administrator narrows it later.
     */
    public static function defaults(string $kind): array {
        if ($kind === self::KIND_REMOTE) {
            return ['inbox.read' => true, 'contacts.read' => ['scope' => 'all'], 'dm.send' => true];
        }
        if ($kind === self::KIND_PEER) {
            return ['contacts.read' => ['scope' => 'all'], 'dm.send' => true];
        }
        return [];
    }

    public static function defaultRate(string $kind): int {
        return self::DEFAULT_RATE[$kind] ?? 30;
    }

    /** Clamp a stored rate. A missing or bad value gets the kind default. */
    public static function normaliseRate(string $kind, $rate): int {
        if (!is_int($rate) && !(is_string($rate) && preg_match('/^\d+$/', $rate))) return self::defaultRate($kind);
        return max(self::RATE_MIN, min(self::RATE_MAX, (int)$rate));
    }

    /**
     * Clean a grant map read from a file or a POST. Unknown grant names and
     * malformed values are dropped (deny). A null map (an older row) gets the
     * defaults; an EMPTY map stays empty, because the administrator removed
     * every grant on purpose.
     *
     * @param array|null $grants
     * @return array<string,mixed>
     */
    public static function normalise(string $kind, ?array $grants): array {
        if ($grants === null) return self::defaults($kind);
        $allowed = self::PHASE1_GRANTS[$kind] ?? [];
        $out = [];
        $hasRequest = false;
        foreach ($grants as $name => $value) {
            $name = (string)$name;
            // #299: per-topic request grants, remotes only. A peer row never
            // holds one (Phase 3 needs John's go).
            if ($kind === self::KIND_REMOTE && strpos($name, self::REQUEST_PREFIX) === 0) {
                if (self::isTrue($value) && self::validRequestTopic(substr($name, strlen(self::REQUEST_PREFIX)))) { $out[$name] = true; $hasRequest = true; }
                continue;
            }
            if ($kind === self::KIND_REMOTE && $name === self::REQUEST_CANCEL) continue;   // decided after the loop
            if (!in_array($name, $allowed, true)) continue;
            if ($name === 'contacts.read') {
                $scope = self::normaliseScope($value);
                if ($scope !== null) $out[$name] = $scope;
                continue;
            }
            if (self::isTrue($value)) $out[$name] = true;
        }
        // Cancel only makes sense with at least one request grant.
        if ($hasRequest && self::isTrue($grants[self::REQUEST_CANCEL] ?? null)) $out[self::REQUEST_CANCEL] = true;
        return $out;
    }

    private static function isTrue($value): bool { return $value === true || $value === 1 || $value === '1'; }

    /** A concrete topic name: the topic grammar, no wildcard, at most 128 bytes. */
    public static function validRequestTopic(string $topic): bool {
        return $topic !== '' && strlen($topic) <= self::TOPIC_MAX && preg_match(self::TOPIC_PATTERN, $topic) === 1;
    }

    /**
     * Topics this principal may send requests to (#299), sorted. Empty for a peer.
     *
     * @return string[]
     */
    public static function requestTopics(array $principal): array {
        if (($principal['kind'] ?? '') !== self::KIND_REMOTE) return [];
        $out = [];
        foreach ((array)($principal['grants'] ?? []) as $name => $value) {
            $name = (string)$name;
            if (strpos($name, self::REQUEST_PREFIX) !== 0 || $value !== true) continue;
            $topic = substr($name, strlen(self::REQUEST_PREFIX));
            if (self::validRequestTopic($topic)) $out[] = $topic;
        }
        sort($out);
        return $out;
    }

    /** `contacts.read` value: {scope:'all'} or {scope:'list', sessions:[ids]}. Null = denied. */
    private static function normaliseScope($value): ?array {
        if ($value === true) return ['scope' => 'all'];
        if (!is_array($value)) return null;
        if (($value['scope'] ?? '') === 'all') return ['scope' => 'all'];
        if (($value['scope'] ?? '') !== 'list') return null;
        $ids = [];
        foreach ((array)($value['sessions'] ?? []) as $id) {
            $id = (string)$id;
            if (preg_match('/^[A-Za-z0-9_-]{1,128}$/', $id) && strpos($id, 'peer_') !== 0) $ids[$id] = true;
        }
        return ['scope' => 'list', 'sessions' => array_keys($ids)];
    }

    /** Build a principal from a stored row. */
    public static function principal(string $kind, array $row): array {
        return [
            'kind' => $kind,
            'id' => (string)($row['id'] ?? ''),
            'grants' => self::normalise($kind, is_array($row['grants'] ?? null) ? $row['grants'] : null),
            'rate_per_min' => self::normaliseRate($kind, $row['rate_per_min'] ?? null),
        ];
    }

    /**
     * Default-deny check. $arg is the object of the action: a local session id
     * for contacts.read (is it in scope?), a topic for request:<topic>.
     */
    public static function allows(array $principal, string $grant, string $arg = ''): bool {
        $grants = is_array($principal['grants'] ?? null) ? $principal['grants'] : [];
        if ($grant === 'contacts.read') {
            $scope = $grants['contacts.read'] ?? null;
            if (!is_array($scope)) return false;
            if (($scope['scope'] ?? '') === 'all') return true;
            if ($arg === '') return ($scope['scope'] ?? '') === 'list';   // "may list at all"
            return in_array($arg, (array)($scope['sessions'] ?? []), true);
        }
        if (strpos($grant, self::REQUEST_PREFIX) === 0) {
            // Phase 2 (#299): remotes only, an exact topic match, never a wildcard.
            if (($principal['kind'] ?? '') !== self::KIND_REMOTE) return false;
            if (!self::validRequestTopic(substr($grant, strlen(self::REQUEST_PREFIX)))) return false;
            return ($grants[$grant] ?? false) === true;
        }
        if ($grant === self::REQUEST_CANCEL) {
            return ($principal['kind'] ?? '') === self::KIND_REMOTE && ($grants[$grant] ?? false) === true
                && self::requestTopics($principal) !== [];
        }
        return ($grants[$grant] ?? false) === true;
    }

    /**
     * MCP tools that a remote may call. Order follows EXTERNAL_TOOLS so a
     * migrated row lists exactly what it listed before.
     *
     * @return string[]
     */
    public static function toolsFor(array $principal): array {
        if (($principal['kind'] ?? '') !== self::KIND_REMOTE) return [];
        $tools = [];
        foreach (self::TOOL_FOR_GRANT as $grant => $tool) {
            if (self::allows($principal, $grant)) $tools[] = $tool;
        }
        // #299: request tools only with at least one per-topic grant.
        if (self::requestTopics($principal) !== []) {
            $tools[] = 'relay_request';
            $tools[] = 'relay_request_status';
            if (self::allows($principal, self::REQUEST_CANCEL)) $tools[] = 'relay_cancel_request';
        }
        return $tools;
    }

    /** One-line summary for the Manager table, for example "DM · contacts: all". */
    public static function summary(array $grants): string {
        $parts = [];
        $parts[] = !empty($grants['dm.send']) ? 'DM on' : 'DM off';
        $scope = $grants['contacts.read'] ?? null;
        if (!is_array($scope)) $parts[] = 'no contacts';
        elseif (($scope['scope'] ?? '') === 'all') $parts[] = 'all workspaces';
        else $parts[] = count((array)($scope['sessions'] ?? [])) . ' workspace(s)';
        $topics = count(self::requestTopics(['kind' => self::KIND_REMOTE, 'grants' => $grants]));
        if ($topics > 0) $parts[] = 'requests: ' . $topics . ($topics === 1 ? ' topic' : ' topics');
        return implode(' · ', $parts);
    }
}
