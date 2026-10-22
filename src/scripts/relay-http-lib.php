<?php
/**
 * Connection handling for the Relay HTTP listener (relay-http-server.php).
 *
 * R-055 (#633): the listener used to serve one connection at a time, with only a
 * per-read timeout. A client that sent one byte every few seconds held the whole
 * listener, so linked boxes and remote clients got no answer. Now every connection is
 * non-blocking and has its own overall deadline, and the server loop moves all open
 * connections forward with stream_select().
 *
 * One process, one thread: the in-memory limiters in RelayHttpService and
 * RelayPeerService stay correct, which a fork per connection would break.
 *
 * Expects AICliAgentsManager.php to be loaded (RelayHttpService::MAX_BODY_BYTES).
 * Spec: docs/specs/RELAY_HTTP_TRANSPORT.md
 */

/** Seconds from accept until the reply is sent. A connection still open after that is closed. */
const RELAY_HTTP_CONN_SECONDS = 15;
/** Open connections at once. Further connections are closed at once. */
const RELAY_HTTP_MAX_CONNECTIONS = 64;

/** Return the address from PHP's remote socket name, without its port. */
function relayHttpPeerAddress(string $peerName): string {
    if (preg_match('/^\[([^]]+)\]:[0-9]+$/D', $peerName, $match)) return $match[1];
    if (preg_match('/^([^:]+):[0-9]+$/D', $peerName, $match)) return $match[1];
    return $peerName !== '' ? $peerName : 'unknown';
}

/** @return array{sock:resource,peer:string,phase:string,buf:string,out:string,deadline:int} */
function relayHttpNewConn($sock, string $peer, bool $tls, int $now): array {
    return ['sock' => $sock, 'peer' => $peer, 'phase' => $tls ? 'tls' : 'read', 'buf' => '', 'out' => '', 'deadline' => $now + RELAY_HTTP_CONN_SECONDS];
}

/** Largest request the loop reads: headers plus the biggest accepted body, with room to spare. */
function relayHttpReadCap(): int {
    return \AICliAgents\Services\RelayHttpService::MAX_BODY_BYTES * 2 + 8192;
}

/**
 * Parse the bytes read so far. Returns null while the request is not complete (no end
 * of headers yet, or fewer body bytes than Content-Length announced). $force says no
 * more bytes will be read, so what is there is handled as it is.
 *
 * @return array{0:string,1:string,2:array<string,string>,3:string}|null [method, path, headers, body]
 */
function relayHttpParse(string $raw, bool $force = false): ?array {
    $max = \AICliAgents\Services\RelayHttpService::MAX_BODY_BYTES;
    if (!str_contains($raw, "\r\n\r\n") && !$force && strlen($raw) <= $max * 2) return null;
    [$head, $body] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
    $lines = explode("\r\n", $head);
    $requestLine = explode(' ', (string)array_shift($lines));
    $method = (string)($requestLine[0] ?? '');
    $path = (string)($requestLine[1] ?? '/');
    $headers = [];
    foreach ($lines as $line) {
        $pos = strpos($line, ':');
        if ($pos === false) continue;
        $headers[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
    }
    // Honour Content-Length so a body split across packets is read in full.
    $expected = min((int)($headers['content-length'] ?? 0), $max + 1);
    if (strlen($body) < $expected && !$force) return null;
    return [$method, $path, $headers, $body];
}

/** The full HTTP reply for one handler result. */
function relayHttpResponse(array $result): string {
    $payload = (string)$result['body'];
    // Linked-box responses carry a signature header (RELAY_LINKED_BOXES.md §3).
    $extra = '';
    foreach ((array)($result['headers'] ?? []) as $hk => $hv) {
        if (preg_match('/^[A-Za-z0-9-]{1,64}$/D', (string)$hk) && preg_match('/^[\x20-\x7E]{0,256}$/D', (string)$hv)) $extra .= "$hk: $hv\r\n";
    }
    return "HTTP/1.1 {$result['status']}\r\n"
        . "Content-Type: application/json\r\n"
        . $extra
        . "Content-Length: " . strlen($payload) . "\r\n"
        . "Connection: close\r\n\r\n" . $payload;
}

function relayHttpClose(array &$conns, $key): void {
    if (isset($conns[$key])) { @fclose($conns[$key]['sock']); unset($conns[$key]); }
}

/**
 * Move one connection forward as far as it can go without waiting: finish the TLS
 * handshake, read the request, call the handler, write the reply. Closes the
 * connection when it is done or has failed. $handle receives
 * (method, path, headers, body, peer) and returns [status, body, headers?].
 *
 * @param array<int|string,array> $conns
 */
function relayHttpAdvance(array &$conns, $key, callable $handle): void {
    if (!isset($conns[$key])) return;
    $c =& $conns[$key];
    $sock = $c['sock'];
    if ($c['phase'] === 'tls') {
        $r = @stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
        if ($r === false) { unset($c); relayHttpClose($conns, $key); return; }
        if ($r === 0) return;                      // the handshake needs more bytes
        $c['phase'] = 'read';
    }
    if ($c['phase'] === 'read') {
        $eof = false;
        while (strlen($c['buf']) < relayHttpReadCap()) {
            $chunk = @fread($sock, 8192);
            if ($chunk === false) { $eof = true; break; }
            if ($chunk === '') { $eof = feof($sock); break; }
            $c['buf'] .= $chunk;
        }
        $req = relayHttpParse($c['buf'], strlen($c['buf']) >= relayHttpReadCap());
        if ($req === null) {
            if ($eof) { unset($c); relayHttpClose($conns, $key); }   // the client left before it finished
            return;
        }
        [$method, $path, $headers, $body] = $req;
        try {
            $result = $method === ''
                ? ['status' => 400, 'body' => json_encode(['error' => 'malformed request'])]
                : $handle($method, $path, $headers, $body, $c['peer']);
        } catch (\Throwable $e) {
            fwrite(STDERR, 'relay-http: handler failed: ' . $e->getMessage() . "\n");
            $result = ['status' => 500, 'body' => json_encode(['error' => 'internal error'])];
        }
        $c['out'] = relayHttpResponse($result);
        $c['buf'] = '';
        $c['phase'] = 'write';
    }
    if ($c['phase'] === 'write') {
        while ($c['out'] !== '') {
            $n = @fwrite($sock, $c['out']);
            if ($n === false) { unset($c); relayHttpClose($conns, $key); return; }
            if ($n === 0) return;                  // the socket is full; wait until it is writable
            $c['out'] = substr($c['out'], $n);
        }
        unset($c);
        relayHttpClose($conns, $key);
    }
}

/** Close every connection whose deadline has passed. Returns how many were closed. */
function relayHttpSweepDeadlines(array &$conns, int $now): int {
    $closed = 0;
    foreach (array_keys($conns) as $key) {
        if ($conns[$key]['deadline'] <= $now) { relayHttpClose($conns, $key); $closed++; }
    }
    return $closed;
}
