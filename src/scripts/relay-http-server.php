#!/usr/bin/env php
<?php
/**
 * Plugin-owned TLS listener for the Relay HTTP MCP transport (#113).
 *
 * Deliberately a separate process from the Unraid webGUI: Unraid offers plugins
 * no nginx include hook (#114), and patching its own locations.conf risks
 * taking down the whole webGUI. A separate listener means a failure here can
 * only affect Relay HTTP.
 *
 * All request logic lives in RelayHttpService; this file is only the socket
 * loop. Never write to STDOUT expecting a client to read it — diagnostics go to
 * STDERR, which the bring-up script redirects to the daemon log.
 *
 * Usage: relay-http-server.php <bind-address> <port>
 */
require_once __DIR__ . '/../includes/AICliAgentsManager.php';

use AICliAgents\Services\AgentRelayService;
use AICliAgents\Services\RelayHttpService;
use AICliAgents\Services\RelayPeerService;

// RELAY_LINKED_BOXES.md §2: the listener never calls out to another box. Two
// single-threaded listeners that call each other could wait for each other.
RelayPeerService::$inListener = true;

$bind = (string)($argv[1] ?? '0.0.0.0');
$port = (int)($argv[2] ?? 8237);

// Follow Unraid's own HTTPS configuration: prefer the CA-signed bundle its SSL
// vhost serves, so a remote client can verify this endpoint rather than being
// told to skip verification.
$profile = AgentRelayService::unraidSslProfile();
$cert = (string)$profile['cert'];
// Test seam for the linked-box twin harness: a second instance serves its own
// certificate. Unset in production.
$certOverride = getenv('AICLI_RELAY_TLS_CERT');
if ($certOverride !== false && $certOverride !== '') { $cert = $certOverride; $profile['source'] = 'override'; }
if ($cert === '') {
    fwrite(STDERR, "relay-http: no usable certificate+key bundle in /boot/config/ssl/certs — refusing to serve in plaintext\n");
    exit(1);
}

$context = stream_context_create(['ssl' => [
    'local_cert' => $cert,
    'verify_peer' => false,
    'verify_peer_name' => false,
    'allow_self_signed' => true,
]]);

$errno = 0; $errstr = '';
$server = @stream_socket_server("tcp://$bind:$port", $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if ($server === false) {
    fwrite(STDERR, "relay-http: cannot bind $bind:$port — $errstr ($errno)\n");
    exit(1);
}
fwrite(STDERR, "relay-http: listening on https://$bind:$port using $cert ({$profile['source']})\n");

/** Read a full HTTP request (headers + body) with a bounded read. */
function relayHttpRead($conn): array {
    $raw = '';
    while (!str_contains($raw, "\r\n\r\n")) {
        $chunk = @fread($conn, 8192);
        if ($chunk === false || $chunk === '') return ['', '/', [], ''];
        $raw .= $chunk;
        if (strlen($raw) > RelayHttpService::MAX_BODY_BYTES * 2) break;
    }
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
    $expected = (int)($headers['content-length'] ?? 0);
    $expected = min($expected, RelayHttpService::MAX_BODY_BYTES + 1);
    while (strlen($body) < $expected) {
        $chunk = @fread($conn, 8192);
        if ($chunk === false || $chunk === '') break;
        $body .= $chunk;
    }
    return [$method, $path, $headers, $body];
}

while (true) {
    $conn = @stream_socket_accept($server, 30, $peerName);
    if ($conn === false) continue;   // accept timeout: loop so the daemon stays responsive to signals

    // Bound every phase so one slow or hostile client cannot stall the loop.
    stream_set_timeout($conn, 10);
    if (@stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLS_SERVER) !== true) { @fclose($conn); continue; }

    [$method, $path, $headers, $body] = relayHttpRead($conn);
    $peer = strtok((string)$peerName, ':') ?: 'unknown';

    $result = $method === ''
        ? ['status' => 400, 'body' => json_encode(['error' => 'malformed request'])]
        : RelayHttpService::handleRequest($method, $headers, $body, $peer, $path);

    $payload = (string)$result['body'];
    // Linked-box responses carry a signature header (RELAY_LINKED_BOXES.md §3).
    $extra = '';
    foreach ((array)($result['headers'] ?? []) as $hk => $hv) {
        if (preg_match('/^[A-Za-z0-9-]{1,64}$/', (string)$hk) && preg_match('/^[\x20-\x7E]{0,256}$/', (string)$hv)) $extra .= "$hk: $hv\r\n";
    }
    @fwrite($conn, "HTTP/1.1 {$result['status']}\r\n"
        . "Content-Type: application/json\r\n"
        . $extra
        . "Content-Length: " . strlen($payload) . "\r\n"
        . "Connection: close\r\n\r\n" . $payload);
    @fclose($conn);
}
