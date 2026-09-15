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
    /** Requests permitted per peer per RATE_WINDOW seconds. Mirrors Unraid's own authlimit rate. */
    const RATE_LIMIT = 30;
    const RATE_WINDOW = 60;

    /** @var array<string,array{0:int,1:int}> peer => [window start, count] */
    private static array $rate = [];

    /** Test seam: the limiter is process-local state, so suites must be able to clear it. */
    public static function resetRateLimiter(): void { self::$rate = []; }

    /**
     * A fixed-window counter. Deliberately counts rejected requests too, so the
     * endpoint cannot be used as an oracle to brute-force a bearer token.
     */
    private static function withinRateLimit(string $peer, ?int $now = null): bool {
        $now = $now ?? time();
        $entry = self::$rate[$peer] ?? [$now, 0];
        if ($now - $entry[0] >= self::RATE_WINDOW) $entry = [$now, 0];
        $entry[1]++;
        self::$rate[$peer] = $entry;
        return $entry[1] <= self::RATE_LIMIT;
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
     * @param array<string,string> $headers
     * @return array{status:int,body:string}
     */
    public static function handleRequest(string $method, array $headers, string $body, string $peer): array {
        // Rate limiting precedes authentication on purpose (see withinRateLimit).
        if (!self::withinRateLimit($peer)) return self::reply(429, ['error'=>'rate limited']);
        if (strtoupper($method) !== 'POST') return self::reply(405, ['error'=>'POST required']);
        if (strlen($body) > self::MAX_BODY_BYTES) return self::reply(413, ['error'=>'request too large']);

        $auth = (string)($headers['authorization'] ?? '');
        if (!preg_match('/^Bearer\s+([A-Fa-f0-9]{64})$/', $auth, $m)) return self::reply(401, ['error'=>'unauthorized']);
        $session = AgentRelayService::httpSessionForToken($m[1]);
        if ($session === '') return self::reply(401, ['error'=>'unauthorized']);

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
                return self::rpc($id, ['tools'=>RelayMcpTools::definitions(RelayMcpTools::EXTERNAL_TOOLS)]);
            case 'tools/call':
                // A tool outside the external identity's scope is a JSON-RPC
                // level error, not a transport failure: the caller is
                // authenticated, it simply may not use that tool.
                return self::rpc($id, RelayMcpTools::toolResult(
                    RelayMcpTools::call((string)($params['name'] ?? ''), $args, $session, RelayMcpTools::EXTERNAL_TOOLS)
                ));
        }
        return self::reply(400, ['error'=>'unsupported method']);
    }
}
