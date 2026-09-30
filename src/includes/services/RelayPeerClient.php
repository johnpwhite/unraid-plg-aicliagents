<?php
/**
 * <module_context>
 *     <name>RelayPeerClient</name>
 *     <description>docs/specs/RELAY_LINKED_BOXES.md §3 — the HTTPS client for
 *     box-to-box calls. It trusts the peer's public key (the SPKI pin), not a
 *     certificate name or chain: Unraid often serves a self-signed or
 *     myunraid.net certificate for a LAN address. One unpinned mode exists,
 *     only for the signed channel-binding hello after a certificate change;
 *     it reports the key it saw on that same connection.</description>
 *     <dependencies>ext-curl, RelayPeerCrypto</dependencies>
 *     <constraints>Connect timeout 3 s, total 10 s (callers may shorten it).
 *     No redirects. Response bodies are capped. Tests replace the transport
 *     through $transport.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class RelayPeerClient {
    const CONNECT_TIMEOUT = 3;
    const TOTAL_TIMEOUT = 10;
    const MAX_RESPONSE_BYTES = 262144;

    /**
     * Test seam. A callable(array $request): array that replaces curl. The
     * request is {url, path, headers, body, pin, timeout}; the response is
     * {ok, status, headers, body, error, pin_mismatch, spki_seen}.
     * @var callable|null
     */
    public static $transport = null;

    /**
     * POST $body to $baseUrl.$path. $pin is the base64 SPKI hash, or '' for
     * the one unpinned channel-binding call (then spki_seen is filled in).
     *
     * @param array<string,string> $headers
     * @return array{ok:bool,status:int,headers:array<string,string>,body:string,error:string,pin_mismatch:bool,spki_seen:string}
     */
    public static function post(string $baseUrl, string $path, array $headers, string $body, string $pin, int $timeout = self::TOTAL_TIMEOUT): array {
        $req = ['url' => $baseUrl, 'path' => $path, 'headers' => $headers, 'body' => $body, 'pin' => $pin, 'timeout' => $timeout];
        if (self::$transport !== null) {
            $r = (self::$transport)($req);
            return $r + ['ok' => false, 'status' => 0, 'headers' => [], 'body' => '', 'error' => '', 'pin_mismatch' => false, 'spki_seen' => ''];
        }
        return self::curl($req);
    }

    /** Only https://host[:port][/] with a host and no user info, query or fragment. */
    public static function validBaseUrl(string $url): bool {
        if (strlen($url) > RelayPeerCrypto::URL_MAX) return false;
        $p = parse_url($url);
        if (!is_array($p) || ($p['scheme'] ?? '') !== 'https' || empty($p['host'])) return false;
        if (isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment'])) return false;
        return !isset($p['path']) || $p['path'] === '/' || $p['path'] === '';
    }

    public static function joinUrl(string $baseUrl, string $path): string {
        return rtrim($baseUrl, '/') . $path;
    }

    private static function curl(array $req): array {
        $out = ['ok' => false, 'status' => 0, 'headers' => [], 'body' => '', 'error' => '', 'pin_mismatch' => false, 'spki_seen' => ''];
        if (!function_exists('curl_init')) { $out['error'] = 'curl is not available'; return $out; }
        if (!self::validBaseUrl((string)$req['url'])) { $out['error'] = 'invalid peer address'; return $out; }
        $ch = curl_init(self::joinUrl((string)$req['url'], (string)$req['path']));
        $hdrs = ['Content-Type: application/json', 'Expect:'];
        foreach ($req['headers'] as $k => $v) $hdrs[] = $k . ': ' . $v;
        $respHeaders = [];
        $received = '';
        $opts = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => (string)$req['body'],
            CURLOPT_HTTPHEADER => $hdrs,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => max(1, min(self::TOTAL_TIMEOUT, (int)$req['timeout'])),
            // Trust is the pinned key, not the chain or the name (spec §3).
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$respHeaders): int {
                $pos = strpos($line, ':');
                if ($pos !== false) $respHeaders[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$received): int {
                if (strlen($received) + strlen($chunk) > self::MAX_RESPONSE_BYTES) return 0;   // abort
                $received .= $chunk;
                return strlen($chunk);
            },
        ];
        if (defined('CURLOPT_PROTOCOLS_STR')) $opts[constant('CURLOPT_PROTOCOLS_STR')] = 'https';
        else $opts[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        if ($req['pin'] !== '') {
            $opts[CURLOPT_PINNEDPUBLICKEY] = 'sha256//' . $req['pin'];
        } else {
            $opts[CURLOPT_CERTINFO] = true;
        }
        curl_setopt_array($ch, $opts);
        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $out['status'] = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $out['headers'] = $respHeaders;
        $out['body'] = $received;
        if ($req['pin'] === '') {
            // The key seen on THIS connection, the one that carried the signed hello.
            $info = curl_getinfo($ch, CURLINFO_CERTINFO);
            $leaf = is_array($info) && isset($info[0]['Cert']) ? (string)$info[0]['Cert'] : '';
            $out['spki_seen'] = RelayPeerCrypto::spkiFromPem($leaf);
        }
        // CURLE_SSL_PINNEDPUBKEYNOTMATCH is 90.
        $out['pin_mismatch'] = $errno === 90;
        $out['error'] = $errno !== 0 ? (curl_error($ch) ?: "curl error $errno") : '';
        $out['ok'] = $ok !== false && $errno === 0;
        return $out;
    }
}
