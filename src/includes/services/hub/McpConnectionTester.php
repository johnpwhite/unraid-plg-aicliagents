<?php
/**
 * <module_context>
 *     <name>McpConnectionTester</name>
 *     <description>Probes a remote MCP server the way an agent CLI would, so a wrong
 *     URL or token shows up in the hub editor before "Apply to agents". Streamable
 *     HTTP: POST initialize, then notifications/initialized and tools/list (tool
 *     count), then DELETE the session. Legacy SSE: GET until the `endpoint` event.
 *     Both JSON and text/event-stream responses are accepted.
 *     Origin: public pull request #25 by Joly0, hardened (Forgejo #525).</description>
 *     <constraints>This is a SERVER-SIDE REQUEST made by the Unraid host with the rights of
 *     the signed-in admin (the AJAX gateway checks the login and the CSRF token). It can reach
 *     any LAN address that the host can reach; that is intended, because most MCP servers sit
 *     on the LAN. Limits: http and https only (CURLOPT_PROTOCOLS), no redirects are followed,
 *     no credentials in the URL, 5 s connect, 10 s per request, 1 MB per response.
 *     The tester NEVER returns or logs the reply body of the remote server, a header value
 *     or any text that the remote server sent. A result holds only: ok flag, HTTP status
 *     code, a fixed plain reason written in this file, and the tool count.</constraints>
 * </module_context>
 */
namespace AICliAgents\Services\Hub;

class McpConnectionTester {

    const PROTOCOL_VERSION = '2025-06-18';
    const CONNECT_TIMEOUT_S = 5;
    const REQUEST_TIMEOUT_S = 10;
    const MAX_BODY = 1048576;

    /**
     * @param array $def normalized remote def with placeholders already resolved
     * @return array {ok: bool, message: string, status?: int, tools?: int}
     */
    public static function test(array $def): array {
        $url = (string)($def['url'] ?? '');
        $bad = self::urlProblem($url);
        if ($bad !== null) return ['ok' => false, 'message' => $bad];
        $headers = is_array($def['headers'] ?? null) ? $def['headers'] : [];
        if (($def['transport'] ?? '') === 'sse') return self::testSse($url, $headers);
        return self::testStreamable($url, $headers);
    }

    /** Fixed text for an address that the tester refuses to call, or null when it is fine. */
    public static function urlProblem(string $url): ?string {
        $p = parse_url($url);
        if (!is_array($p) || empty($p['scheme']) || empty($p['host'])) return 'The URL is not valid.';
        if (!in_array(strtolower($p['scheme']), ['http', 'https'], true)) return 'Only http and https addresses can be tested.';
        if (isset($p['user']) || isset($p['pass'])) return 'Remove the user name and password from the URL. Use the Authentication setting instead.';
        return null;
    }

    private static function testStreamable(string $url, array $headers): array {
        $init = self::post($url, $headers, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => self::PROTOCOL_VERSION, 'capabilities' => (object)[],
                         'clientInfo' => ['name' => 'aicliagents-connection-test', 'version' => '1']],
        ], 1);
        if ($init['error'] !== null) return ['ok' => false, 'message' => self::unreachable($init['errno'])];
        if ($init['status'] < 200 || $init['status'] >= 300) return self::httpFailure($init, 'http');
        $res = $init['message']['result'] ?? null;
        if (!is_array($res)) {
            if (isset($init['message']['error'])) {
                return ['ok' => false, 'status' => $init['status'],
                        'message' => 'HTTP ' . $init['status'] . ': the server answered, but it refused the initialize request.'];
            }
            return ['ok' => false, 'status' => $init['status'],
                    'message' => 'HTTP ' . $init['status'] . ' but the reply is not an MCP initialize result. Is this the MCP endpoint URL?'];
        }

        $out = ['ok' => true, 'status' => $init['status']];

        // Follow-up requests carry the session and negotiated version, as a CLI would.
        // Both values come from the remote server, so they go only into later request
        // headers after a strict shape check, and never back to the UI.
        $follow = $headers;
        if ($init['session'] !== '' && preg_match('/^[\x21-\x7E]{1,256}$/D', $init['session'])) $follow['Mcp-Session-Id'] = $init['session'];
        $ver = (string)($res['protocolVersion'] ?? '');
        $follow['MCP-Protocol-Version'] = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $ver) ? $ver : self::PROTOCOL_VERSION;
        self::post($url, $follow, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], null);
        $list = self::post($url, $follow, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], 2);
        if ($list['error'] === null && isset($list['message']['result']['tools']) && is_array($list['message']['result']['tools'])) {
            $out['tools'] = count($list['message']['result']['tools']);
        }
        if (isset($follow['Mcp-Session-Id'])) self::request('DELETE', $url, $follow, null, null);

        $out['message'] = 'Connected (HTTP ' . $init['status'] . ')'
            . (isset($out['tools']) ? ', ' . $out['tools'] . ($out['tools'] === 1 ? ' tool' : ' tools') : '') . '.';
        return $out;
    }

    private static function testSse(string $url, array $headers): array {
        $r = self::request('GET', $url, $headers + ['Accept' => 'text/event-stream'], null, 'endpoint');
        if ($r['error'] !== null && !$r['found']) return ['ok' => false, 'message' => self::unreachable($r['errno'])];
        if ($r['status'] < 200 || $r['status'] >= 300) return self::httpFailure($r, 'sse');
        if (!$r['found']) {
            return ['ok' => false, 'status' => $r['status'],
                    'message' => 'HTTP ' . $r['status'] . ' but no SSE endpoint event arrived. If the server uses streamable HTTP, switch the transport to http.'];
        }
        return ['ok' => true, 'status' => $r['status'], 'message' => 'Connected (legacy SSE endpoint answered).'];
    }

    /** Plain reason for a transport failure, from the curl error NUMBER (never the curl text). */
    private static function unreachable(int $errno): string {
        switch ($errno) {
            case 6:  return 'Unreachable: the host name could not be resolved.';
            case 7:  return 'Unreachable: the connection was refused or the host did not answer.';
            case 28: return 'Unreachable: the server did not answer in time.';
            case 35: case 51: case 58: case 60: case 77: case 83:
                     return 'Unreachable: the TLS (https) handshake or certificate check failed.';
            case 23: return 'Unreachable: the reply was larger than the 1 MB limit.';
            default: return 'Unreachable: the request failed (network error ' . $errno . ').';
        }
    }

    /** Explain a non-2xx answer in terms of what the user should change. Fixed texts only. */
    private static function httpFailure(array $r, string $transport): array {
        $s = $r['status'];
        $out = ['ok' => false, 'status' => $s];
        if ($s === 401) {
            $oauth = stripos($r['wwwAuth'], 'resource_metadata') !== false;
            $out['message'] = 'HTTP 401: the server rejected the request as unauthenticated. Check the token'
                . ($oauth ? '. The server also offers an OAuth login, which agent CLIs run themselves on first use.' : '.');
        } elseif ($s === 403) {
            $out['message'] = 'HTTP 403: authenticated, but not allowed. The token may lack a permission or scope.';
        } elseif ($s >= 300 && $s < 400) {
            $out['message'] = "HTTP $s: the server redirects to another address. The test does not follow redirects. "
                . 'Enter the final MCP endpoint URL instead; agent CLIs may not follow redirects.';
        } elseif ($s === 404 || $s === 405) {
            $out['message'] = "HTTP $s: nothing answers MCP at this URL. Check the path"
                . ($transport === 'sse' ? ', or switch the transport to http for a newer server.' : ', or try the sse transport for an older server.');
        } elseif ($s === 429) {
            $out['message'] = 'HTTP 429: the server limits how often it can be called. Try again later.';
        } elseif ($s >= 500) {
            $out['message'] = "HTTP $s: the server reported an error of its own.";
        } else {
            $out['message'] = "HTTP $s: unexpected answer from the server.";
        }
        return $out;
    }

    /** JSON-RPC POST; $wantId stops reading once that response id arrives (SSE). */
    private static function post(string $url, array $headers, array $msg, ?int $wantId): array {
        $headers['Content-Type'] = 'application/json';
        $headers['Accept'] = 'application/json, text/event-stream';
        return self::request('POST', $url, $headers, json_encode($msg), $wantId);
    }

    /**
     * @param int|string|null $want  int: JSON-RPC id to wait for; 'endpoint': SSE event name
     * @return array {status, error, errno, message, session, wwwAuth, found}. The raw body is
     *         read for parsing only and is not part of the result.
     */
    private static function request(string $method, string $url, array $headers, ?string $body, $want): array {
        $state = ['buf' => '', 'message' => null, 'found' => false, 'headers' => []];
        $ch = curl_init($url);
        $lines = [];
        foreach ($headers as $k => $v) $lines[] = str_replace(["\r", "\n"], '', $k . ': ' . $v);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_S,
            CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT_S,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$state) {
                $p = strpos($line, ':');
                if ($p !== false) $state['headers'][strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$state, $want) {
                $state['buf'] .= $chunk;
                if (strlen($state['buf']) > self::MAX_BODY) return -1;
                if ($want !== null && self::scan($state, $want)) return -1; // got what we need, stop the stream
                return strlen($chunk);
            },
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);
        $failed = ($ok === false && !$state['found']);
        curl_close($ch);
        if ($want !== null && !$state['found']) self::scan($state, $want);
        return ['status' => $status, 'error' => $failed ? 'curl' : null, 'errno' => $failed ? $errno : 0,
                'message' => $state['message'], 'found' => $state['found'],
                'session' => (string)($state['headers']['mcp-session-id'] ?? ''),
                'wwwAuth' => (string)($state['headers']['www-authenticate'] ?? '')];
    }

    /** Find the wanted JSON-RPC response (plain JSON or SSE data) or SSE event in the buffer. */
    private static function scan(array &$state, $want): bool {
        $buf = $state['buf'];
        if (is_int($want)) {
            $j = json_decode($buf, true);
            if (is_array($j) && ($j['id'] ?? null) === $want) { $state['message'] = $j; return $state['found'] = true; }
            foreach (preg_split('/\r?\n/', $buf) as $line) {
                if (strncmp($line, 'data:', 5) !== 0) continue;
                $j = json_decode(trim(substr($line, 5)), true);
                if (is_array($j) && ($j['id'] ?? null) === $want) { $state['message'] = $j; return $state['found'] = true; }
            }
            return false;
        }
        return $state['found'] = (bool)preg_match('/^event:\s*' . preg_quote((string)$want, '/') . '\s*$/m', $buf);
    }
}
