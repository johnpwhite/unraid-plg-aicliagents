<?php
/**
 * <module_context>
 *     <name>RelayPeerCrypto</name>
 *     <description>docs/specs/RELAY_LINKED_BOXES.md §2–§3 — the pure crypto of
 *     linked boxes: the pairing code format, pairing proofs, link-key wrapping,
 *     per-direction request and response signatures, and the SPKI pin of a
 *     certificate. It has no state and does no I/O except reading a
 *     certificate file for its pin.</description>
 *     <dependencies>ext-openssl (SPKI only)</dependencies>
 *     <constraints>Every comparison of a secret value uses hash_equals().
 *     A link key never leaves this box after pairing: a request carries only
 *     an HMAC made with a key derived from it.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class RelayPeerCrypto {
    const CODE_PREFIX = 'AICLI-LINK1-';
    const CODE_VERSION = 1;
    const PROTOCOL = 1;
    /** Timestamp window for a signed request, in seconds (spec §7, replay). */
    const TS_WINDOW = 300;
    const NAME_MAX = 40;
    const URL_MAX = 200;

    private const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    // ---- base32 (RFC 4648, no padding) ---------------------------------------

    public static function base32Encode(string $bin): string {
        $bits = ''; $out = '';
        foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        foreach (str_split($bits, 5) as $chunk) $out .= self::B32[bindec(str_pad($chunk, 5, '0'))];
        return $bin === '' ? '' : $out;
    }

    public static function base32Decode(string $text): ?string {
        $text = strtoupper((string)preg_replace('/[\s-]/', '', $text));
        if ($text === '' || strspn($text, self::B32) !== strlen($text)) return null;
        $bits = '';
        foreach (str_split($text) as $c) $bits .= str_pad(decbin(strpos(self::B32, $c)), 5, '0', STR_PAD_LEFT);
        $out = '';
        foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $out .= chr(bindec($byte));
        return $out;
    }

    // ---- pairing code ----------------------------------------------------------

    /**
     * Encode a pairing code. Binary layout (then base32):
     *   ver(1) box_id(8) spki_sha256(32) secret(16) name_len(1) name url_len(1) url crc32(4)
     * The CRC only catches a mistyped code with a clear message. Security comes
     * from the pin (checked on TLS) and the one-time secret (checked by HMAC).
     */
    public static function encodePairingCode(string $boxId, string $spkiB64, string $secretHex, string $name, string $url): string {
        $spki = base64_decode($spkiB64, true);
        if (!preg_match('/^[a-f0-9]{16}$/', $boxId) || $spki === false || strlen($spki) !== 32 || !preg_match('/^[a-f0-9]{32}$/', $secretHex)) {
            throw new \InvalidArgumentException('bad pairing code fields');
        }
        $name = substr($name, 0, self::NAME_MAX);
        $url = substr($url, 0, self::URL_MAX);
        $bin = chr(self::CODE_VERSION) . hex2bin($boxId) . $spki . hex2bin($secretHex)
            . chr(strlen($name)) . $name . chr(strlen($url)) . $url;
        $bin .= pack('N', crc32($bin));
        return self::CODE_PREFIX . self::base32Encode($bin);
    }

    /**
     * @return array{box_id:string,spki:string,secret:string,name:string,url:string}|array{error:string}
     */
    public static function decodePairingCode(string $code): array {
        $code = trim($code);
        if (stripos($code, self::CODE_PREFIX) !== 0) return ['error' => 'This is not a linked-box pairing code. It starts with ' . self::CODE_PREFIX . '.'];
        $bin = self::base32Decode(substr($code, strlen(self::CODE_PREFIX)));
        if ($bin === null || strlen($bin) < 1 + 8 + 32 + 16 + 1 + 1 + 4) return ['error' => 'The pairing code is incomplete. Copy the whole code again.'];
        $body = substr($bin, 0, -4);
        $crc = unpack('N', substr($bin, -4))[1];
        if ($crc !== crc32($body)) return ['error' => 'The pairing code has a typing error. Copy the whole code again.'];
        if (ord($body[0]) !== self::CODE_VERSION) return ['error' => 'This pairing code comes from a newer plugin version. Update this box first.'];
        $o = 1;
        $boxId = bin2hex(substr($body, $o, 8)); $o += 8;
        $spki = base64_encode(substr($body, $o, 32)); $o += 32;
        $secret = bin2hex(substr($body, $o, 16)); $o += 16;
        $nl = ord($body[$o] ?? "\0"); $o++;
        $name = (string)substr($body, $o, $nl); $o += $nl;
        $ul = ord($body[$o] ?? "\0"); $o++;
        $url = (string)substr($body, $o, $ul); $o += $ul;
        if ($o !== strlen($body) || strlen($name) !== $nl || strlen($url) !== $ul) return ['error' => 'The pairing code is damaged. Copy the whole code again.'];
        return ['box_id' => $boxId, 'spki' => $spki, 'secret' => $secret, 'name' => $name, 'url' => $url];
    }

    // ---- pairing proofs and key wrap -------------------------------------------

    public static function pairRequestProof(string $secretHex, string $codeBox, string $joinBox, string $joinSpki, string $joinUrl, string $ts, string $nonce): string {
        return hash_hmac('sha256', implode("\n", ['pair-req', $codeBox, $joinBox, $joinSpki, $joinUrl, $ts, $nonce]), hex2bin($secretHex));
    }

    public static function pairResponseProof(string $secretHex, string $codeBox, string $joinBox, string $nonce, string $linkKeyHex): string {
        return hash_hmac('sha256', implode("\n", ['pair-resp', $codeBox, $joinBox, $nonce, hash('sha256', $linkKeyHex)]), hex2bin($secretHex));
    }

    /**
     * XOR a 32-byte key with an HMAC keystream. Used for the link key at
     * pairing (wrapped under the one-time secret) and for a new key at rekey
     * (wrapped under the current link key). The same call unwraps.
     */
    public static function wrapKey(string $keyHex, string $wrapKeyHex, string $label, string $nonce): string {
        // hex2bin() returns false for bad input. The wrapped key must be exactly
        // 32 bytes (the length of the HMAC stream it is XORed with); the wrapping
        // key is the 16-byte pairing secret or a 32-byte link key.
        $key = preg_match('/^[0-9a-f]{64}$/i', $keyHex) ? hex2bin($keyHex) : false;
        $wrap = preg_match('/^(?:[0-9a-f]{2}){16,}$/i', $wrapKeyHex) ? hex2bin($wrapKeyHex) : false;
        if ($key === false || $wrap === false) {
            throw new \InvalidArgumentException('RelayPeerCrypto::wrapKey needs a 32-byte hex key and a hex wrapping key of at least 16 bytes');
        }
        $stream = hash_hmac('sha256', "wrap\n$label\n$nonce", $wrap, true);
        return bin2hex($key ^ $stream);
    }

    // ---- request / response signatures -------------------------------------------

    /** Key for one direction: requests from $from to $to, or responses from $from to $to. */
    public static function directionKey(string $linkKeyHex, string $purpose, string $from, string $to): string {
        return hash_hmac('sha256', "aicli-relay-peer v1 $purpose $from>$to", hex2bin($linkKeyHex), true);
    }

    public static function signRequest(string $linkKeyHex, string $fromBox, string $toBox, string $method, string $path, string $ts, string $nonce, string $body): string {
        $base = implode("\n", ['v1', strtoupper($method), $path, $ts, $nonce, hash('sha256', $body)]);
        return hash_hmac('sha256', $base, self::directionKey($linkKeyHex, 'req', $fromBox, $toBox));
    }

    public static function signResponse(string $linkKeyHex, string $responderBox, string $requesterBox, int $status, string $requestNonce, string $body): string {
        $base = implode("\n", ['v1-resp', (string)$status, $requestNonce, hash('sha256', $body)]);
        return hash_hmac('sha256', $base, self::directionKey($linkKeyHex, 'resp', $responderBox, $requesterBox));
    }

    /** Timestamp check. Returns the skew in seconds when outside the window, else 0. */
    public static function clockSkew(string $ts, ?int $now = null): int {
        $now = $now ?? time();
        if (!preg_match('/^\d{9,11}$/', $ts)) return PHP_INT_MAX;
        $d = (int)$ts - $now;
        return abs($d) > self::TS_WINDOW ? $d : 0;
    }

    public static function newNonce(): string { return bin2hex(random_bytes(8)); }
    public static function newKey(): string { return bin2hex(random_bytes(32)); }

    // ---- SPKI pin -------------------------------------------------------------------

    /**
     * SHA-256 of the SubjectPublicKeyInfo of a PEM certificate (or a bundle
     * whose first certificate is the leaf), base64. This is the value that
     * curl's CURLOPT_PINNEDPUBLICKEY takes after "sha256//".
     */
    public static function spkiFromPem(string $pem): string {
        if (!preg_match('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $pem, $m)) return '';
        $pub = @openssl_pkey_get_public($m[0]);
        if ($pub === false) return '';
        $details = openssl_pkey_get_details($pub);
        $keyPem = (string)($details['key'] ?? '');
        $der = base64_decode((string)preg_replace('/-----[^-]+-----|\s/', '', $keyPem), true);
        return $der === false || $der === '' ? '' : base64_encode(hash('sha256', $der, true));
    }

    public static function spkiFromFile(string $file): string {
        if ($file === '' || !is_file($file)) return '';
        return self::spkiFromPem((string)@file_get_contents($file));
    }

    public static function validSpki(string $spki): bool {
        $raw = base64_decode($spki, true);
        return $raw !== false && strlen($raw) === 32;
    }
}
