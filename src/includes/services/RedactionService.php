<?php
/**
 * <module_context>
 *     <name>RedactionService</name>
 *     <description>Fail-closed layered redaction for the diagnostics support bundle (R-08, Feature #1371).
 *     Layers: (1) structural allowlist is enforced by DiagnosticsService (raw secrets.cfg / env files are
 *     never read into a bundle — this service never sees them as content); (2) known-key value scrub:
 *     key NAMES are read from the vault + workspace secret files and any VALUE found verbatim in an
 *     excerpt is replaced with «REDACTED:keyname»; (3) pattern scrub (assignments, vendor token prefixes,
 *     Bearer/Authorization, long base64/hex runs, emails); (4) optional strict-anonymize (shares→share-NN
 *     stable map, hostname→unraid-host, LAN IPs masked); (5) self-test: assertClean() throws when any
 *     known secret VALUE survives — the bundle build aborts rather than ships.</description>
 *     <dependencies>SecretService (paths only — key/value map via its readers)</dependencies>
 *     <constraints>Never logs secret values (exception messages carry key NAMES only). Verbatim value
 *     matching uses str_replace/strpos, never regex, so metacharacters in secrets cannot break matching.
 *     Test hooks: AICLI_SECRETS_VAULT / AICLI_SECRETS_DIR env overrides (AICLI_MANIFEST_PATH precedent).</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

require_once __DIR__ . '/SecretService.php';

class RedactionService {

    /** Values shorter than this are not scrubbed/self-tested verbatim (a 1-char
     *  "value" like "1" would corrupt the whole excerpt). Same threshold is used
     *  by BOTH the scrub and the self-test so no gap opens between them. */
    public const MIN_VALUE_LEN = 4;

    // ------------------------------------------------------------------
    // Layer 2 — known-key value scrub
    // ------------------------------------------------------------------

    /**
     * Load key NAMES + values from the agent vault and every workspace secret
     * file. The map is used to (a) scrub values verbatim and (b) self-test the
     * final bundle bytes. Multiline values contribute each line >= MIN_VALUE_LEN.
     *
     * @return array<string,string> keyname => value
     */
    public static function loadKnownSecrets(): array {
        $out = [];

        $vault = getenv('AICLI_SECRETS_VAULT');
        $vault = ($vault !== false && $vault !== '') ? $vault : SecretService::getAgentSecretsPath();
        foreach (self::readIniSafe($vault) as $k => $v) {
            $out[$k] = $v;
        }

        $dir = getenv('AICLI_SECRETS_DIR');
        $dir = ($dir !== false && $dir !== '') ? $dir : SecretService::getWorkspaceSecretsDir();
        if (is_dir($dir)) {
            foreach (glob(rtrim($dir, '/') . '/*.cfg') ?: [] as $file) {
                foreach (self::readIniSafe($file) as $k => $v) {
                    // Workspace key may shadow a vault key with a different value —
                    // keep both values testable by suffixing the source on collision.
                    if (isset($out[$k]) && $out[$k] !== $v) {
                        $out[$k . '@' . basename($file)] = $v;
                    } else {
                        $out[$k] = $v;
                    }
                }
            }
        }
        return $out;
    }

    /**
     * Replace every verbatim occurrence of a known secret value with
     * «REDACTED:keyname». Pure string replacement (no regex) — metacharacters
     * in secrets are matched literally. Multiline values are also replaced
     * line-by-line so a partial leak of a multiline secret is still caught.
     */
    public static function knownKeyScrub(string $text, array $knownSecrets): string {
        foreach (self::expandSecretValues($knownSecrets) as $key => $values) {
            foreach ($values as $value) {
                $text = str_replace($value, "\u{AB}REDACTED:$key\u{BB}", $text);
            }
        }
        return $text;
    }

    /**
     * Expand the secrets map into testable fragments: the full value plus each
     * line of a multiline value, dropping fragments < MIN_VALUE_LEN. Longest
     * fragments first so a full-value replace wins over a line replace.
     *
     * @return array<string,array<int,string>> keyname => [fragment, ...]
     */
    private static function expandSecretValues(array $knownSecrets): array {
        $out = [];
        foreach ($knownSecrets as $key => $value) {
            $value = (string)$value;
            $frags = [$value];
            if (strpos($value, "\n") !== false) {
                foreach (preg_split('/\r?\n/', $value) ?: [] as $line) {
                    $frags[] = $line;
                }
            }
            $frags = array_values(array_unique(array_filter(array_map('trim', $frags), function ($f) {
                return strlen($f) >= self::MIN_VALUE_LEN;
            })));
            usort($frags, function ($a, $b) { return strlen($b) - strlen($a); });
            if ($frags !== []) {
                $out[(string)$key] = $frags;
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Layer 3 — pattern scrub
    // ------------------------------------------------------------------

    /**
     * Regex-based scrub for secret-shaped content with no known key. Order
     * matters: line-level Authorization redaction first, then assignments
     * (keep key, drop value), then vendor prefixes, long encoded runs, emails.
     */
    public static function patternScrub(string $text): string {
        // Authorization: lines wholesale (any header casing).
        $text = (string)preg_replace(
            '/^.*\bAuthorization\s*:.*$/mi',
            "\u{AB}Authorization line redacted\u{BB}",
            $text
        );
        // KEY|TOKEN|SECRET|PASSWORD|PASSWD|CREDENTIAL-bearing assignments: keep key, drop value.
        // (?!«) keeps a layer-2 «REDACTED:keyname» marker intact rather than
        // overwriting it with the anonymous «redacted».
        $text = (string)preg_replace(
            '/\b([A-Za-z0-9_.-]*(?:KEY|TOKEN|SECRET|PASSWORD|PASSWD|CREDENTIAL)[A-Za-z0-9_]*)(\s*[=:]\s*)(?!\xC2\xAB)("[^"]*"|\'[^\']*\'|\S+)/i',
            '$1$2' . "\u{AB}redacted\u{BB}",
            $text
        );
        // Bearer <token> (same layer-2 marker guard)
        $text = (string)preg_replace('/\bBearer\s+(?!\xC2\xAB)\S+/i', "Bearer \u{AB}redacted\u{BB}", $text);
        // Vendor token prefixes.
        $text = (string)preg_replace('/\bsk-[A-Za-z0-9_-]{16,}/',            "\u{AB}redacted:sk\u{BB}",   $text); // OpenAI/Anthropic
        $text = (string)preg_replace('/\bgh[pos]_[A-Za-z0-9]{16,}/',         "\u{AB}redacted:gh\u{BB}",   $text); // GitHub ghp_/gho_/ghs_
        $text = (string)preg_replace('/\bxox[bp]-[A-Za-z0-9-]{8,}/',         "\u{AB}redacted:slack\u{BB}", $text);
        $text = (string)preg_replace('/\bAIza[0-9A-Za-z_-]{30,}/',           "\u{AB}redacted:aiza\u{BB}", $text);
        // Long encoded runs: base64(url) >= 40 chars, hex >= 40 chars.
        $text = (string)preg_replace('/(?<![A-Za-z0-9+\/=_-])[A-Za-z0-9+\/=_-]{40,}(?![A-Za-z0-9+\/=_-])/', "\u{AB}redacted:b64\u{BB}", $text);
        $text = (string)preg_replace('/\b[0-9a-fA-F]{40,}\b/',               "\u{AB}redacted:hex\u{BB}",  $text);
        // Emails.
        $text = (string)preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', "\u{AB}email\u{BB}", $text);
        return $text;
    }

    // ------------------------------------------------------------------
    // Layer 4 — optional strict-anonymize
    // ------------------------------------------------------------------

    /**
     * Stable share map for strict-anonymize: sorted unique share names →
     * share-01..NN. Passing the same share list always yields the same map.
     *
     * @param array<int,string>|null $shares null = enumerate /mnt/user
     * @return array<string,string> sharename => share-NN
     */
    public static function shareMap(?array $shares = null): array {
        if ($shares === null) {
            $shares = [];
            foreach (glob('/mnt/user/*', GLOB_ONLYDIR) ?: [] as $d) {
                $shares[] = basename($d);
            }
        }
        $shares = array_values(array_unique(array_filter(array_map('strval', $shares))));
        sort($shares, SORT_STRING);
        $map = [];
        foreach ($shares as $i => $name) {
            $map[$name] = sprintf('share-%02d', $i + 1);
        }
        return $map;
    }

    /**
     * Strict-anonymize pass: share names (path-context only — /mnt/user/<x>,
     * /mnt/user0/<x>, /mnt/diskN/<x>, /mnt/cache/<x>) via the stable map,
     * hostname → unraid-host, RFC1918 LAN IPs → «ip». Public IPs untouched.
     */
    public static function anonymize(string $text, ?array $shares = null, ?string $hostname = null): string {
        foreach (self::shareMap($shares) as $name => $alias) {
            $text = (string)preg_replace(
                '#(/mnt/(?:user0?|disk[0-9]+|cache)/)' . preg_quote($name, '#') . '(?=/|\s|"|\'|$)#m',
                '$1' . $alias,
                $text
            );
        }
        $hostname = $hostname ?? (string)@php_uname('n');
        if ($hostname !== '' && strlen($hostname) >= 2) {
            $text = str_ireplace($hostname, 'unraid-host', $text);
        }
        // RFC1918: 10/8, 172.16/12, 192.168/16
        $text = (string)preg_replace(
            '/\b(?:10\.\d{1,3}\.\d{1,3}\.\d{1,3}|172\.(?:1[6-9]|2\d|3[01])\.\d{1,3}\.\d{1,3}|192\.168\.\d{1,3}\.\d{1,3})\b/',
            "\u{AB}ip\u{BB}",
            $text
        );
        return $text;
    }

    // ------------------------------------------------------------------
    // Combined redaction + Layer 5 self-test
    // ------------------------------------------------------------------

    /**
     * Apply layers 2 → 3 → (4) in order. Caller (DiagnosticsService) is
     * responsible for layer 1 (structural allowlist) and layer 5 (assertClean
     * over the final bytes of every bundle file).
     */
    public static function redact(string $text, ?array $knownSecrets = null, bool $anonymize = false): string {
        $knownSecrets = $knownSecrets ?? self::loadKnownSecrets();
        $text = self::knownKeyScrub($text, $knownSecrets);
        $text = self::patternScrub($text);
        if ($anonymize) {
            $text = self::anonymize($text);
        }
        return $text;
    }

    /**
     * Layer 5 self-test: returns the key NAMES whose secret VALUE (or any
     * multiline fragment >= MIN_VALUE_LEN) still appears verbatim in $text.
     * Empty array = clean. Never returns or logs values.
     *
     * @return array<int,string>
     */
    public static function findSurvivors(string $text, array $knownSecrets): array {
        $survivors = [];
        foreach (self::expandSecretValues($knownSecrets) as $key => $values) {
            foreach ($values as $value) {
                if (strpos($text, $value) !== false) {
                    $survivors[] = $key;
                    break;
                }
            }
        }
        return $survivors;
    }

    /**
     * Fail-closed gate: throws when any known secret value survives. The
     * exception message carries key NAMES only.
     *
     * @throws \RuntimeException
     */
    public static function assertClean(string $text, array $knownSecrets, string $label = 'content'): void {
        $survivors = self::findSurvivors($text, $knownSecrets);
        if ($survivors !== []) {
            throw new \RuntimeException(
                "Redaction self-test FAILED for $label: secret value(s) survived for key(s) "
                . implode(', ', $survivors) . ' — bundle build aborted'
            );
        }
    }

    // ------------------------------------------------------------------
    // Internal
    // ------------------------------------------------------------------

    /** parse_ini_file with the same key-shape gate SecretService applies. */
    private static function readIniSafe(string $file): array {
        if ($file === '' || !is_file($file)) return [];
        $parsed = @parse_ini_file($file);
        if (!is_array($parsed)) return [];
        $out = [];
        foreach ($parsed as $k => $v) {
            if (preg_match('/^[A-Z][A-Z0-9_]{1,127}$/', (string)$k)) {
                $out[$k] = (string)$v;
            }
        }
        return $out;
    }
}
