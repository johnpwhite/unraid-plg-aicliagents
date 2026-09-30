<?php
/**
 * Request handling for the plugin-owned Relay HTTP listener (#113).
 *
 * Unraid gates its whole webGUI behind a session cookie (`auth_request` →
 * `auth-request.php`), and offers plugins no nginx include hook (#114), so the
 * Relay HTTPS endpoint shipped in 2026.08.04.01 was never reachable. This
 * service backs a separate plugin-owned listener instead of patching any
 * Unraid-owned file — if it fails, only Relay HTTP is affected.
 *
 * All logic lives here rather than in the socket loop so it is testable without
 * binding a port. See docs/specs/RELAY_HTTP_TRANSPORT.md.
 */
namespace AICliAgents\Services;

class RelayHttpService {
    /** Bodies above this are refused before any JSON parsing. */
    const MAX_BODY_BYTES = 65536;
    /** FAILED pre-authentication requests permitted per address per RATE_WINDOW seconds. Mirrors Unraid's own authlimit rate. */
    const RATE_LIMIT = 30;
    const RATE_WINDOW = 60;

    /** @var array<string,array{0:int,1:int}> address => [window start, failures] */
    private static array $rate = [];

    /** Test seam: the limiter is process-local state, so suites must be able to clear it. */
    public static function resetRateLimiter(): void { self::$rate = []; RelayPeerService::resetLimiters(); }

    /**
     * RELAY_LINKED_BOXES.md Phase 2 (#299): the per-address counter counts only
     * requests that FAIL before authentication (wrong method, too large, no or
     * a bad token). Once an address reaches RATE_LIMIT failures in the window,
     * every request from it is refused BEFORE the token is checked, so the
     * endpoint still cannot be used as an oracle to brute-force a bearer token.
     * An authenticated request counts only against its credential, so one
     * bridge address can carry several tokens.
     */
    private static function addressBlocked(string $peer, ?int $now = null): bool {
        $now = $now ?? time();
        $entry = self::$rate[$peer] ?? null;
        if ($entry === null || $now - $entry[0] >= self::RATE_WINDOW) return false;
        return $entry[1] >= self::RATE_LIMIT;
    }

    private static function recordFailure(string $peer, ?int $now = null): void {
        $now = $now ?? time();
        $entry = self::$rate[$peer] ?? [$now, 0];
        if ($now - $entry[0] >= self::RATE_WINDOW) $entry = [$now, 0];
        $entry[1]++;
        self::$rate[$peer] = $entry;
    }

    /** A refusal before authentication: counted against the address. */
    private static function refuse(string $peer, int $status, array $payload): array {
        self::recordFailure($peer);
        return self::reply($status, $payload);
    }

    private static function reply(int $status, array $payload): array {
        return ['status'=>$status, 'body'=>json_encode($payload, JSON_UNESCAPED_SLASHES)];
    }
    /** $result is array|stdClass: `ping` must serialise as `{}`, not `[]`. */
    private static function rpc($id, array|\stdClass $result): array {
        return self::reply(200, ['jsonrpc'=>'2.0','id'=>$id,'result'=>$result]);
    }

    /**
     * Handle one request. Header keys are expected lower-cased by the caller.
     *
     * $path routes the request (RELAY_LINKED_BOXES.md §3): `/peer/v1/*` is the
     * signed box-to-box path and goes to RelayPeerService; every other path
     * keeps the MCP behaviour below, unchanged. The peer path has its own
     * limits: per source address for FAILED authentication only, and per link
     * after authentication, so a busy linked box is not blocked by the MCP
     * per-address limit.
     *
     * @param array<string,string> $headers
     * @return array{status:int,body:string,headers?:array<string,string>}
     */
    public static function handleRequest(string $method, array $headers, string $body, string $peer, string $path = '/'): array {
        $path = (string)(parse_url($path, PHP_URL_PATH) ?? '/');
        if (strpos($path, RelayPeerService::PREFIX) === 0) {
            if (strlen($body) > self::MAX_BODY_BYTES) return self::reply(413, ['error'=>'request too large']);
            return RelayPeerService::handle($method, $path, $headers, $body, $peer);
        }
        // The address check precedes authentication on purpose (see addressBlocked).
        if (self::addressBlocked($peer)) return self::reply(429, ['error'=>'rate limited']);
        if (strtoupper($method) !== 'POST') return self::refuse($peer, 405, ['error'=>'POST required']);
        if (strlen($body) > self::MAX_BODY_BYTES) return self::refuse($peer, 413, ['error'=>'request too large']);

        $auth = (string)($headers['authorization'] ?? '');
        if (!preg_match('/^Bearer\s+([A-Fa-f0-9]{64})$/', $auth, $m)) return self::refuse($peer, 401, ['error'=>'unauthorized']);
        $principal = AgentRelayService::httpPrincipalForToken($m[1]);
        if ($principal === null) return self::refuse($peer, 401, ['error'=>'unauthorized']);
        $session = (string)$principal['id'];
        // RELAY_LINKED_BOXES.md §6 + Phase 2 (#299): after authentication the
        // limit is per credential (default 30 a minute), whatever the address.
        if (!RelayPeerService::withinCredentialLimit($session, (int)$principal['rate_per_min'])) return self::reply(429, ['error'=>'rate limited']);
        $allowed = RelayGrants::toolsFor($principal);

        $request = json_decode($body, true);
        if (!is_array($request)) return self::reply(400, ['error'=>'malformed JSON-RPC request']);

        $id = $request['id'] ?? null;
        $rpcMethod = (string)($request['method'] ?? '');
        $params = is_array($request['params'] ?? null) ? $request['params'] : [];
        $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        switch ($rpcMethod) {
            case 'initialize':
                return self::rpc($id, [
                    'protocolVersion' => (string)($params['protocolVersion'] ?? '2025-06-18'),
                    'capabilities' => ['tools'=>['listChanged'=>false]],
                    'serverInfo' => ['name'=>'aicli-relay-http','version'=>'1.0.0'],
                ]);
            case 'notifications/initialized':
                return self::reply(200, ['jsonrpc'=>'2.0']);
            case 'ping':
                return self::rpc($id, new \stdClass());
            case 'tools/list':
                return self::rpc($id, ['tools'=>RelayMcpTools::definitionsFor($principal)]);
            case 'tools/call':
                // A tool outside the external identity's scope is a JSON-RPC
                // level error, not a transport failure: the caller is
                // authenticated, it simply may not use that tool.
                return self::rpc($id, RelayMcpTools::toolResult(
                    RelayMcpTools::call((string)($params['name'] ?? ''), $args, $session, $allowed, $principal)
                ));
        }
        return self::reply(400, ['error'=>'unsupported method']);
    }
}
