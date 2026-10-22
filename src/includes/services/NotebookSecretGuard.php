<?php
/**
 * <module_context>
 *     <name>NotebookSecretGuard</name>
 *     <description>Finds text that looks like a secret (docs/specs/ARTEFACTS.md R13,
 *     docs/specs/ARTEFACTS_CONTRACT.md section 6). NotebookService runs scan() over the
 *     whole new file text on every create, save and patch. A hit blocks the write until
 *     the caller confirms (confirmSecret). isProductionKey() is the stricter test for a
 *     value that a person stores in a connection card's vault field.</description>
 *     <dependencies>none</dependencies>
 *     <constraints>Pure functions. Never returns, logs or echoes the matched text: only
 *     a short label of the KIND of secret. A `vault:` reference is not a secret.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class NotebookSecretGuard {

    /** Fixed-shape tokens: [label, regex]. */
    private const TOKEN_PATTERNS = [
        ['a GitHub token',      '/\bgh[pousr]_[A-Za-z0-9]{20,}\b/'],
        ['a GitHub token',      '/\bgithub_pat_[A-Za-z0-9_]{20,}\b/'],
        ['an API key (sk-)',    '/\bsk-[A-Za-z0-9_\-]{20,}/'],
        ['an AWS access key',   '/\bAKIA[0-9A-Z]{16}\b/'],
        ['a Slack token',       '/\bxox[abprs]-[A-Za-z0-9\-]{10,}/'],
        ['a Google API key',    '/\bAIza[0-9A-Za-z_\-]{30,}/'],
        ['a private key block', '/-----BEGIN [A-Z ]*PRIVATE KEY-----|-----BEGIN (?:OPENSSH|PGP|RSA|EC|DSA) /'],
    ];

    /** A key named like a secret, assigned a long hex or base64 looking value. */
    private const ASSIGNED = '/(?:^|[\s"\'{,\[(])(?:password|passwd|pwd|secret|token|api[_-]?key|apikey|access[_-]?key|client[_-]?secret|auth[_-]?token)["\']?\s*[:=]\s*["\']?([A-Za-z0-9+\/=_\-]{16,})["\']?/i';

    /**
     * @return string|null a short label such as "a GitHub token", or null when the text looks clean.
     */
    public static function scan(string $text): ?string {
        $f = self::findings($text);
        return $f === [] ? null : reset($f);
    }

    /**
     * Every secret-looking match in $text, as [sha1 of the match => label]. The hash lets a caller tell
     * whether an edit ADDED a finding without ever holding the matched text.
     * @return array<string,string>
     */
    public static function findings(string $text): array {
        if ($text === '') return [];
        // Drop vault references first: `password: vault:nb-nb_x-a_y-password` is the designed shape.
        $clean = preg_replace('/vault:nb-[A-Za-z0-9_\-]+/', 'vault:ref', $text) ?? $text;
        $out = [];
        foreach (self::TOKEN_PATTERNS as [$label, $re]) {
            if (preg_match_all($re, $clean, $m)) {
                foreach ($m[0] as $hit) $out[sha1($hit)] = $label;
            }
        }
        if (preg_match_all(self::ASSIGNED, $clean, $m)) {
            foreach ($m[1] as $value) {
                if (self::looksRandom($value)) $out[sha1($value)] = 'a password, token or key assigned to a secret-looking name';
            }
        }
        return $out;
    }

    /**
     * A finding in $new that $old did not have: the label of the first one, else null. An edit to a file
     * that already holds a confirmed secret is not blocked again for that same secret.
     */
    public static function addedFinding(string $old, string $new): ?string {
        $before = self::findings($old);
        foreach (self::findings($new) as $hash => $label) {
            if (!isset($before[$hash])) return $label;
        }
        return null;
    }

    /**
     * True when a value that a person stores in the vault looks like a live production key.
     * Used with the setting notebook_allow_prod_secrets.
     */
    public static function isProductionKey(string $value): bool {
        return (bool)preg_match(
            '/\b(?:sk|rk|pk)_live_[A-Za-z0-9]{10,}|\bAKIA[0-9A-Z]{16}\b|-----BEGIN [A-Z ]*PRIVATE KEY-----|\bxox[bp]-[A-Za-z0-9\-]{10,}|\bsk-(?:proj|live|ant)-[A-Za-z0-9_\-]{16,}|\bgh[ps]_[A-Za-z0-9]{30,}/',
            $value
        );
    }

    /** Long hex or base64-ish: needs a digit and at least 2 character classes, so a plain word is not flagged. */
    private static function looksRandom(string $v): bool {
        if (strncmp($v, 'vault:nb-', 9) === 0) return false;
        $hasDigit = (bool)preg_match('/\d/', $v);
        $hasLower = (bool)preg_match('/[a-z]/', $v);
        $hasUpper = (bool)preg_match('/[A-Z]/', $v);
        $isHex = (bool)preg_match('/^[0-9a-fA-F]{16,}$/D', $v);
        if ($isHex) return true;
        return $hasDigit && ($hasLower || $hasUpper) && ((int)$hasLower + (int)$hasUpper + (int)$hasDigit) >= 2;
    }
}
