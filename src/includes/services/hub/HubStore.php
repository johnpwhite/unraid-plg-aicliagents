<?php
/**
 * <module_context>
 *     <name>HubStore</name>
 *     <description>Canonical Config Hub store (OP #1362 / finding H-01, phase 1 — MCP servers;
 *     OP #1363 / H-02, phase 2 — instructions; OP #1364 / H-03, phase 3 — skills +
 *     commands trees). CRUD over .aicli/hub/mcp.json (canonical MCP server registry),
 *     .aicli/hub/instructions/global.md (the ONE canonical instruction doc, 256 KB cap),
 *     .aicli/hub/skills/&lt;name&gt;/… + .aicli/hub/commands/&lt;name&gt;.md (mirrored-tree
 *     skill/command store: 256 KB per file, 2 MB total, tree depth ≤3) and
 *     .aicli/hub/state.json (per-vendor projection ledger + instructions/skills/commands
 *     enabledFor target sets) via AtomicWriteService.
 *     Server names validated [a-zA-Z0-9_-]{1,64}. env values may be secret-ish —
 *     this service NEVER logs values or instruction/skill/command content
 *     (key names / paths / counts only).</description>
 *     <dependencies>ConfigService, AtomicWriteService, LogService, GitHomeService (debounced auto-commit after successful writes — OP #1365 / H-04)</dependencies>
 *     <constraints>Static methods only. Shape validation here; AgentRegistry validation
 *     of enabledFor ids lives in HubHandler. Test hook: AICLI_HUB_STATE_DIR env override
 *     (mirrors the AICLI_MANIFEST_PATH precedent).</constraints>
 * </module_context>
 */

namespace AICliAgents\Services\Hub;

use AICliAgents\Services\AtomicWriteService;
use AICliAgents\Services\ConfigService;
use AICliAgents\Services\LogService;

class HubStore {

    const SCHEMA = 1;
    const NAME_RE = '/^[a-zA-Z0-9_-]{1,64}$/D';
    const ENV_KEY_RE = '/^[A-Za-z_][A-Za-z0-9_]{0,127}$/D';
    /** HTTP header field-name (RFC 9110 token), capped at 128 chars. */
    const HEADER_KEY_RE = "/^[!#$%&'*+.^_`|~0-9A-Za-z-]{1,128}$/D";
    /** Headers the MCP client manages itself; a user value would break the transport. */
    const RESERVED_HEADERS = ['accept', 'content-length', 'content-type', 'host',
                              'mcp-protocol-version', 'mcp-session-id', 'last-event-id'];
    /**
     * Header names that carry a credential. The value of such a header must be made of
     * vault {KEY} placeholders (see headerSecretProblem()); a raw secret is refused.
     */
    const SENSITIVE_HEADER_RE = '/^(authorization|proxy-authorization|cookie)$|token|secret|passw|credential|api[-_]?key|(^|-)key$|auth/i';
    /** Env variable names and URL query names that carry a credential (#590). */
    const SENSITIVE_NAME_RE = '/token|secret|passw|credential|api[-_]?key|apikey|(^|[_-])key$|auth(?!or)|bearer|cookie|private[_-]?key|(^|[_-])sig(nature)?$/i';
    /** One vault placeholder, the same shape HubProjector::resolvePlaceholders() resolves. */
    const PLACEHOLDER_RE = '/\{[A-Z][A-Z0-9_]{1,127}\}/';
    const TRANSPORTS = ['stdio', 'http', 'sse'];
    const AGENT_ID_RE = '/^[a-z0-9][a-z0-9-]{0,63}$/iD';
    /** Size cap for the canonical instruction doc (H-02). */
    const INSTRUCTIONS_MAX_BYTES = 262144; // 256 KB
    /** Per-file size cap for skill/command files (H-03). */
    const TREE_FILE_MAX_BYTES = 262144; // 256 KB
    /** Total size cap across the skills/ + commands/ trees (H-03). */
    const TREE_TOTAL_MAX_BYTES = 2097152; // 2 MB
    /** Names already written by system skill projectors, not user-owned hub trees. */
    private const RESERVED_SYSTEM_SKILLS = ['aicli-admin', 'aicli-artefacts', 'aicli-relay'];
    /** Max path depth of a file inside a skill (SKILL.md=1, scripts/run.sh=2, …). */
    const TREE_MAX_DEPTH = 3;
    /** One path segment of a skill file: no leading dot, no traversal, ≤64 chars. */
    const TREE_SEGMENT_RE = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D';

    // ---------- Paths ----------

    public static function baseDir(): string {
        $env = getenv('AICLI_HUB_STATE_DIR');
        if ($env !== false && $env !== '') return rtrim($env, '/');
        return ConfigService::getUserStatePath() . '/hub';
    }

    public static function mcpFile(): string          { return self::baseDir() . '/mcp.json'; }
    public static function stateFile(): string        { return self::baseDir() . '/state.json'; }
    public static function instructionsFile(): string { return self::baseDir() . '/instructions/global.md'; }
    public static function skillsDir(): string        { return self::baseDir() . '/skills'; }
    public static function commandsDir(): string      { return self::baseDir() . '/commands'; }

    // ---------- damaged store files (#641) ----------

    /**
     * Why a store file cannot be trusted, or null when it is healthy or absent.
     * A file that exists but does not parse (a git restore that left conflict
     * markers, a torn write) is NOT the same as an empty store: reading it as
     * empty makes the next projection remove every managed vendor entry. The
     * first time a damaged file is seen, a copy is kept next to it.
     */
    private static function fileProblem(string $file, string $requiredKey, string $label): ?string {
        if (!is_file($file)) return null;
        $raw = (string)@file_get_contents($file);
        if (trim($raw) === '') return null; // nothing in it to lose
        $data = json_decode($raw, true);
        if (is_array($data) && isset($data[$requiredKey]) && is_array($data[$requiredKey])) return null;
        $kept = self::keepCorruptCopy($file, $raw);
        return "$label cannot be read (it is not valid JSON). The Config Hub changed nothing. "
             . ($kept !== null ? "A copy is kept as " . basename($kept) . '. ' : '')
             . "Repair the file, or delete it to start with an empty list: $file";
    }

    /** Copy a damaged file once per distinct content. Returns the copy path, or null. */
    private static function keepCorruptCopy(string $file, string $raw): ?string {
        $hash = substr(sha1($raw), 0, 12);
        foreach (glob($file . '.corrupt-*') ?: [] as $existing) {
            if (substr($existing, -12) === $hash) return $existing;
        }
        $copy = $file . '.corrupt-' . gmdate('YmdHis') . '-' . $hash;
        if (@file_put_contents($copy, $raw) === false) return null;
        @chmod($copy, 0600);
        LogService::log(basename($file) . ' is damaged; a copy was kept as ' . basename($copy), LogService::LOG_ERROR, 'HubStore');
        return $copy;
    }

    /** Null when mcp.json is healthy or absent, else a plain-words reason. */
    public static function mcpProblem(): ?string {
        return self::fileProblem(self::mcpFile(), 'servers', 'The MCP server list (mcp.json)');
    }

    /** Null when state.json is healthy or absent, else a plain-words reason. */
    public static function stateProblem(): ?string {
        return self::fileProblem(self::stateFile(), 'projections', 'The Config Hub state (state.json)');
    }

    /** First problem of either store file, or null. Projection stops when this is set. */
    public static function storeProblem(): ?string {
        return self::mcpProblem() ?? self::stateProblem();
    }

    // ---------- mcp.json (canonical server registry) ----------

    /** @return array{schema:int,servers:array<string,array>} */
    public static function getMcp(): array {
        $file = self::mcpFile();
        $empty = ['schema' => self::SCHEMA, 'servers' => []];
        if (!is_file($file)) return $empty;
        $data = json_decode((string)@file_get_contents($file), true);
        if (!is_array($data) || !isset($data['servers']) || !is_array($data['servers'])) {
            LogService::log('mcp.json unreadable or malformed — treating as empty (file untouched)', LogService::LOG_WARN, 'HubStore');
            return $empty;
        }
        $data['schema'] = (int)($data['schema'] ?? self::SCHEMA);
        return $data;
    }

    public static function saveMcp(array $mcp): bool {
        // Never overwrite a damaged file with a partial list (#641).
        if (self::mcpProblem() !== null) {
            LogService::log('refused to write mcp.json: the existing file is damaged', LogService::LOG_ERROR, 'HubStore');
            return false;
        }
        $mcp['schema'] = self::SCHEMA;
        if (!isset($mcp['servers']) || !is_array($mcp['servers'])) $mcp['servers'] = [];
        ksort($mcp['servers']);
        // Empty map must encode as {} not [].
        $payload = ['schema' => $mcp['schema'], 'servers' => empty($mcp['servers']) ? new \stdClass() : $mcp['servers']];
        $ok = AtomicWriteService::writeJson(self::mcpFile(), $payload);
        if (!$ok) LogService::log('FAILED to write mcp.json', LogService::LOG_ERROR, 'HubStore');
        return $ok;
    }

    public static function getServer(string $name): ?array {
        $mcp = self::getMcp();
        return $mcp['servers'][$name] ?? null;
    }

    /**
     * Validate + normalize a canonical server definition. Returns the normalized
     * def, or null with $errors populated. NEVER log env or header values.
     * @param array $def {command|url, args[], env{}, headers{}, transport, enabledFor[]}
     */
    public static function normalizeServer(array $def, array &$errors = []): ?array {
        $errors = [];
        $transport = (string)($def['transport'] ?? 'stdio');
        if (!in_array($transport, self::TRANSPORTS, true)) $errors[] = "invalid transport '$transport'";

        $out = ['transport' => $transport];
        if ($transport === 'stdio') {
            $cmd = trim((string)($def['command'] ?? ''));
            if ($cmd === '') $errors[] = 'command is required for stdio transport';
            $out['command'] = $cmd;
            $args = $def['args'] ?? [];
            if (!is_array($args)) $errors[] = 'args must be a list';
            $out['args'] = array_values(array_map('strval', is_array($args) ? $args : []));
            $env = $def['env'] ?? [];
            if (!is_array($env)) { $errors[] = 'env must be a map'; $env = []; }
            $cleanEnv = [];
            foreach ($env as $k => $v) {
                if (!preg_match(self::ENV_KEY_RE, (string)$k)) { $errors[] = "invalid env key '$k'"; continue; }
                $problem = self::valueSecretProblem('env value', (string)$k, (string)$v);
                if ($problem !== null) { $errors[] = $problem; continue; }
                $cleanEnv[(string)$k] = (string)$v;
            }
            ksort($cleanEnv);
            $out['env'] = $cleanEnv;
        } else {
            $url = trim((string)($def['url'] ?? ''));
            if ($url === '' || !preg_match('#^https?://#i', $url)) $errors[] = 'a http(s) url is required for http/sse transport';
            $out['url'] = $url;
            $problem = self::urlSecretProblem($url);
            if ($problem !== null) $errors[] = $problem;
            $headers = $def['headers'] ?? [];
            if (!is_array($headers)) { $errors[] = 'headers must be a map'; $headers = []; }
            $cleanHeaders = [];
            $seen = [];
            foreach ($headers as $k => $v) {
                $k = (string)$k;
                if (!preg_match(self::HEADER_KEY_RE, $k)) { $errors[] = "invalid header name '$k'"; continue; }
                if (isset($seen[strtolower($k)])) { $errors[] = "header '$k' is set twice (names are not case-sensitive)"; continue; }
                $seen[strtolower($k)] = true;
                if (in_array(strtolower($k), self::RESERVED_HEADERS, true)) { $errors[] = "header '$k' is set by the MCP client and cannot be overridden"; continue; }
                $v = (string)$v;
                if (preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $v)) { $errors[] = "header '$k' value contains control characters"; continue; }
                $problem = self::headerSecretProblem($k, $v);
                if ($problem !== null) { $errors[] = $problem; continue; }
                $cleanHeaders[$k] = $v;
            }
            ksort($cleanHeaders);
            if (!empty($cleanHeaders)) $out['headers'] = $cleanHeaders;
            // UI hint only (which header the editor shows as "Authentication"); projectors ignore it.
            $authHeader = (string)($def['authHeader'] ?? '');
            if ($authHeader !== '' && isset($cleanHeaders[$authHeader])) $out['authHeader'] = $authHeader;
        }

        $enabledFor = $def['enabledFor'] ?? [];
        if (!is_array($enabledFor)) { $errors[] = 'enabledFor must be a list'; $enabledFor = []; }
        $ids = [];
        foreach ($enabledFor as $id) {
            $id = (string)$id;
            if (!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/iD', $id)) { $errors[] = "invalid agent id '$id'"; continue; }
            $ids[] = $id;
        }
        $out['enabledFor'] = array_values(array_unique($ids));
        sort($out['enabledFor']);

        // Optional per-server tool-approval default. Canonical field; maps to Codex's
        // `default_tools_approval_mode` (verified against the official Codex MCP config
        // reference). Other vendors' transpilers ignore it. Only the Codex-valid values
        // are accepted; absent = leave the vendor's own default untouched.
        $ta = trim((string)($def['toolsApprovalMode'] ?? ''));
        if ($ta !== '') {
            if (!in_array($ta, ['auto', 'prompt', 'writes', 'approve'], true)) {
                $errors[] = "invalid toolsApprovalMode '$ta' (allowed: auto, prompt, writes, approve)";
            } else {
                $out['toolsApprovalMode'] = $ta;
            }
        }

        // Switched off as a whole: kept in the store with its targets, but never projected.
        if (!empty($def['disabled'])) $out['disabled'] = true;

        return empty($errors) ? $out : null;
    }

    /**
     * A credential-named value (an env variable or a URL query parameter) must hold
     * vault placeholders only (#590): mcp.json is plain text and the Hub git backup
     * commits and pushes it. Returns an error text, or null when the value is fine.
     * Never includes the value in the text.
     */
    public static function valueSecretProblem(string $what, string $name, string $value): ?string {
        if (!preg_match(self::SENSITIVE_NAME_RE, $name)) return null;
        if ($value === '') return null;
        $rest = trim((string)preg_replace(self::PLACEHOLDER_RE, '', (string)preg_replace('/^(Bearer|Basic|Token)\s+/i', '', $value)));
        if ($rest === '' && preg_match(self::PLACEHOLDER_RE, $value)) return null;
        return "$what '$name' looks like a credential and must not hold a raw secret. "
             . "Store the secret under Secrets, then write its key here in braces, for example '{MY_TOKEN}'.";
    }

    /** A URL must not carry a raw secret in its user-info part or in a credential-named query parameter (#590). */
    public static function urlSecretProblem(string $url): ?string {
        $parts = @parse_url($url);
        if (!is_array($parts)) return null;
        if (isset($parts['pass']) && $parts['pass'] !== '' && self::valueSecretProblem('url', 'password', rawurldecode($parts['pass'])) !== null) {
            return 'the url holds a password in its user-info part. Use a header with a secret key in braces instead.';
        }
        if (!empty($parts['query'])) {
            foreach (explode('&', $parts['query']) as $pair) {
                [$qk, $qv] = array_pad(explode('=', $pair, 2), 2, '');
                $qk = rawurldecode($qk);
                if (self::valueSecretProblem('url parameter', $qk, rawurldecode($qv)) !== null) {
                    return "the url parameter '$qk' looks like a credential and must not hold a raw secret. "
                         . "Store the secret under Secrets, then write its key in braces, for example '{MY_TOKEN}'.";
                }
            }
        }
        return null;
    }

    /**
     * Raw secrets in a stored server list (used by the git backup guard). Each
     * entry names a server and the field; never a value.
     * @return string[]
     */
    public static function rawSecretProblems(array $servers): array {
        $out = [];
        foreach ($servers as $name => $def) {
            if (!is_array($def)) continue;
            foreach ((array)($def['env'] ?? []) as $k => $v) {
                if (self::valueSecretProblem('env value', (string)$k, (string)$v) !== null) $out[] = "server '$name': env '$k'";
            }
            foreach ((array)($def['headers'] ?? []) as $k => $v) {
                if (self::headerSecretProblem((string)$k, (string)$v) !== null) $out[] = "server '$name': header '$k'";
            }
            if (isset($def['url']) && self::urlSecretProblem((string)$def['url']) !== null) $out[] = "server '$name': url";
        }
        return $out;
    }

    /** UPPER_SNAKE vault key from a server name and a field name (e.g. github + api-token -> GITHUB_API_TOKEN). */
    private static function vaultKeyFor(string $server, string $field, array $taken): string {
        $base = strtoupper(trim((string)preg_replace('/[^A-Za-z0-9]+/', '_', "{$server}_{$field}"), '_'));
        if ($base === '' || !preg_match('/^[A-Z]/', $base)) $base = 'MCP_' . $base;
        $base = substr($base, 0, 120);
        $key = $base;
        for ($i = 2; isset($taken[$key]); $i++) $key = $base . '_' . $i;
        return $key;
    }

    /**
     * F10 (#590): a server saved before the raw-secret refusal can still hold a typed
     * credential in an env variable, a header or the URL. Swap each one for a {KEY}
     * placeholder and return the values to put in the vault. Pure: writes nothing.
     * A raw value that equals an existing vault value reuses that key.
     * @param array<string,string> $vault current vault map (KEY => value)
     * @return array{def:array,secrets:array<string,string>,fields:string[]} fields name the places moved, never values
     */
    public static function moveRawSecretsOut(string $server, array $def, array $vault = []): array {
        $secrets = []; $fields = [];
        $reserve = static function (string $field, string $value) use ($server, $vault, &$secrets): string {
            foreach (array_merge($vault, $secrets) as $k => $v) {
                if ((string)$v === $value) return (string)$k;
            }
            $key = self::vaultKeyFor($server, $field, array_merge($vault, $secrets));
            $secrets[$key] = $value;
            return $key;
        };
        foreach ((array)($def['env'] ?? []) as $k => $v) {
            if (self::valueSecretProblem('env value', (string)$k, (string)$v) === null) continue;
            $def['env'][$k] = '{' . $reserve((string)$k, (string)$v) . '}';
            $fields[] = "env '$k'";
        }
        foreach ((array)($def['headers'] ?? []) as $k => $v) {
            if (self::headerSecretProblem((string)$k, (string)$v) === null) continue;
            $scheme = ''; $raw = (string)$v;
            if (preg_match('/^(Bearer|Basic|Token)\s+(.*)$/iD', $raw, $m)) { $scheme = $m[1] . ' '; $raw = $m[2]; }
            $def['headers'][$k] = $scheme . '{' . $reserve((string)$k, $raw) . '}';
            $fields[] = "header '$k'";
        }
        $url = (string)($def['url'] ?? '');
        if ($url !== '' && self::urlSecretProblem($url) !== null) {
            $url = (string)preg_replace_callback('~^([a-z][a-z0-9+.-]*://[^:/@?#]*:)([^@/?#]*)(@)~i', function ($m) use ($reserve, &$fields) {
                if (self::valueSecretProblem('url', 'password', rawurldecode($m[2])) === null) return $m[0];
                $fields[] = 'url password';
                return $m[1] . '{' . $reserve('password', rawurldecode($m[2])) . '}' . $m[3];
            }, $url);
            $url = (string)preg_replace_callback('~([?&])([^=&#]+)=([^&#]*)~', function ($m) use ($reserve, &$fields) {
                $name = rawurldecode($m[2]);
                if (self::valueSecretProblem('url parameter', $name, rawurldecode($m[3])) === null) return $m[0];
                $fields[] = "url parameter '$name'";
                return $m[1] . $m[2] . '={' . $reserve($name, rawurldecode($m[3])) . '}';
            }, $url);
            $def['url'] = $url;
        }
        return ['def' => $def, 'secrets' => $secrets, 'fields' => $fields];
    }

    /**
     * A credential header must not hold a raw secret: mcp.json is plain text, and the Hub git
     * backup commits it. The value must be vault placeholders only, after an optional scheme
     * word, for example `Bearer {GITHUB_TOKEN}`. Returns an error text, or null when the
     * header is fine. Never includes the value in the text.
     */
    public static function headerSecretProblem(string $name, string $value): ?string {
        if (!preg_match(self::SENSITIVE_HEADER_RE, $name)) return null;
        if ($value === '') return null; // an empty value holds no secret
        $rest = preg_replace('/^(Bearer|Basic|Token)\s+/i', '', $value);
        $rest = (string)preg_replace(self::PLACEHOLDER_RE, '', (string)$rest);
        if (trim($rest) === '' && preg_match(self::PLACEHOLDER_RE, $value)) return null;
        return "header '$name' looks like a credential and must not hold a raw secret. "
             . "Store the secret under Secrets, then write its key here in braces, for example "
             . ((strcasecmp($name, 'Authorization') === 0) ? "'Bearer {MY_TOKEN}'" : "'{MY_TOKEN}'") . '.';
    }

    /** Create or replace one server. $def is validated/normalized. */
    public static function saveServer(string $name, array $def, array &$errors = []): bool {
        $errors = [];
        if (!preg_match(self::NAME_RE, $name)) { $errors[] = 'invalid server name (allowed: [a-zA-Z0-9_-]{1,64})'; return false; }
        $norm = self::normalizeServer($def, $errors);
        if ($norm === null) return false;
        $problem = self::mcpProblem();
        if ($problem !== null) { $errors[] = $problem; return false; }
        $mcp = self::getMcp();
        $mcp['servers'][$name] = $norm;
        $ok = self::saveMcp($mcp);
        if ($ok) {
            LogService::log("saved MCP server '$name' (transport={$norm['transport']}, env keys=" . count($norm['env'] ?? []) . ', header keys=' . count($norm['headers'] ?? []) . ', targets=' . count($norm['enabledFor']) . ')', LogService::LOG_INFO, 'HubStore');
            // H-04 (#1365): debounced auto-commit AFTER the successful write — name only, never values.
            GitHomeService::commitIfEnabled("hub: mcp server '$name' saved");
        }
        return $ok;
    }

    public static function deleteServer(string $name): bool {
        if (!preg_match(self::NAME_RE, $name)) return false;
        $mcp = self::getMcp();
        if (!isset($mcp['servers'][$name])) return true; // idempotent
        unset($mcp['servers'][$name]);
        $ok = self::saveMcp($mcp);
        if ($ok) {
            LogService::log("deleted MCP server '$name' from the hub store (vendor files reconcile on next project)", LogService::LOG_INFO, 'HubStore');
            GitHomeService::commitIfEnabled("hub: mcp server '$name' deleted");
        }
        return $ok;
    }

    /** Replace the enabledFor target list of an existing server. */
    public static function setServerTargets(string $name, array $agentIds, array &$errors = []): bool {
        $errors = [];
        $server = self::getServer($name);
        if ($server === null) { $errors[] = "no such server '$name'"; return false; }
        $server['enabledFor'] = $agentIds;
        return self::saveServer($name, $server, $errors);
    }

    /**
     * #833: on its first install a successor agent (OpenCode 2 is the successor of OpenCode 1)
     * takes the Config Hub choices of its predecessor: every MCP server, and the instruction,
     * skill and command lists, that name the predecessor also name the successor. A predecessor
     * that gets the global instructions through its own config ($claudeCompat, OpenCode 1 reads
     * CLAUDE.md that way) also puts the successor on the instruction list. Nothing changes when
     * the successor is already on any list (a person chose for it).
     *
     * @param array<string,array<string,mixed>> $registry agent id => registry entry (only `successor` is read)
     * @param list<string> $claudeCompat agent ids that get the global instructions through their own config
     * @return list<string> the lists the agent was added to, in plain words; empty when nothing changed
     */
    public static function inheritFromPredecessor(string $agentId, array $registry, array $claudeCompat = []): array {
        $pred = null;
        foreach ($registry as $id => $entry) {
            if (is_array($entry) && (string)($entry['successor'] ?? '') === $agentId && (string)$id !== $agentId) { $pred = (string)$id; break; }
        }
        if ($pred === null) return [];
        $servers = self::getMcp()['servers'] ?? [];
        $state = self::getState();
        foreach ($servers as $def) {
            if (in_array($agentId, (array)($def['enabledFor'] ?? []), true)) return [];
        }
        foreach (self::TARGET_FIELDS as $field) {
            if (in_array($agentId, (array)($state[$field] ?? []), true)) return [];
        }
        $added = [];
        foreach ($servers as $name => $def) {
            $for = array_map('strval', (array)($def['enabledFor'] ?? []));
            if (!in_array($pred, $for, true)) continue;
            $errors = [];
            if (self::setServerTargets((string)$name, array_merge($for, [$agentId]), $errors)) $added[] = "MCP server $name";
        }
        $labels = ['instructions_enabledFor' => 'instructions', 'skills_enabledFor' => 'skills', 'commands_enabledFor' => 'commands'];
        foreach (self::TARGET_FIELDS as $field) {
            $for = array_map('strval', (array)($state[$field] ?? []));
            $want = in_array($pred, $for, true)
                || ($field === 'instructions_enabledFor' && in_array($pred, $claudeCompat, true) && !in_array($agentId, $claudeCompat, true));
            if (!$want) continue;
            $errors = [];
            if (self::setTargetSet($field, $labels[$field], array_merge($for, [$agentId]), $errors)) $added[] = $labels[$field];
        }
        return $added;
    }

    /** Switch a server off (or back on) for every agent without touching its targets. */
    public static function setServerDisabled(string $name, bool $disabled, array &$errors = []): bool {
        $errors = [];
        $server = self::getServer($name);
        if ($server === null) { $errors[] = "no such server '$name'"; return false; }
        if ($disabled) $server['disabled'] = true; else unset($server['disabled']);
        return self::saveServer($name, $server, $errors);
    }

    // ---------- instructions/global.md (canonical instruction doc, H-02) ----------

    /** The canonical instruction doc, '' when absent. Content is NEVER logged. */
    public static function getInstructions(): string {
        $file = self::instructionsFile();
        if (!is_file($file)) return '';
        return (string)@file_get_contents($file);
    }

    /**
     * Atomic save of the canonical instruction doc. Enforces the 256 KB cap
     * and refuses content containing the managed-fence markers (which would
     * destabilise fence extraction in the projected vendor files).
     */
    public static function saveInstructions(string $content, array &$errors = []): bool {
        $errors = [];
        if (strlen($content) > self::INSTRUCTIONS_MAX_BYTES) {
            $errors[] = 'instructions exceed the ' . (self::INSTRUCTIONS_MAX_BYTES / 1024) . ' KB size cap';
            return false;
        }
        foreach ([InstructionProjector::FENCE_OPEN, InstructionProjector::FENCE_CLOSE] as $marker) {
            if (strpos($content, $marker) !== false) {
                $errors[] = 'instructions may not contain the aicli-hub managed-fence markers';
                return false;
            }
        }
        $ok = AtomicWriteService::write(self::instructionsFile(), $content);
        if ($ok) {
            LogService::log('saved hub instructions (' . strlen($content) . ' bytes)', LogService::LOG_INFO, 'HubStore');
            // H-04 (#1365): byte count only — instruction content is never logged or quoted.
            GitHomeService::commitIfEnabled('hub: instructions updated (' . strlen($content) . ' bytes)');
        } else {
            LogService::log('FAILED to write hub instructions/global.md', LogService::LOG_ERROR, 'HubStore');
        }
        return $ok;
    }

    /** Agent ids targeted for instruction projection (opt-in; defaults to none). */
    public static function getInstructionTargets(): array {
        return self::getState()['instructions_enabledFor'];
    }

    /** Replace the instruction target set (shape validation only — registry validation in HubHandler). */
    public static function setInstructionTargets(array $agentIds, array &$errors = []): bool {
        return self::setTargetSet('instructions_enabledFor', 'instruction', $agentIds, $errors);
    }

    /** Agent ids targeted for skill-tree projection (H-03; opt-in; defaults to none). */
    public static function getSkillsTargets(): array {
        return self::getState()['skills_enabledFor'];
    }

    /** Replace the skills target set (shape validation only — registry validation in HubHandler). */
    public static function setSkillsTargets(array $agentIds, array &$errors = []): bool {
        return self::setTargetSet('skills_enabledFor', 'skills', $agentIds, $errors);
    }

    /** Agent ids targeted for command projection (H-03; opt-in; defaults to none). */
    public static function getCommandsTargets(): array {
        return self::getState()['commands_enabledFor'];
    }

    /** Replace the commands target set (shape validation only — registry validation in HubHandler). */
    public static function setCommandsTargets(array $agentIds, array &$errors = []): bool {
        return self::setTargetSet('commands_enabledFor', 'commands', $agentIds, $errors);
    }

    /** Shared validate + persist for the three opt-in projection target sets. */
    private static function setTargetSet(string $field, string $label, array $agentIds, array &$errors): bool {
        $errors = [];
        $ids = [];
        foreach ($agentIds as $id) {
            $id = (string)$id;
            if (!preg_match(self::AGENT_ID_RE, $id)) { $errors[] = "invalid agent id '$id'"; continue; }
            $ids[] = $id;
        }
        if (!empty($errors)) return false;
        $ids = array_values(array_unique($ids));
        sort($ids);
        $ok = self::updateState(static function (array $state) use ($field, $ids): array {
            $state[$field] = $ids;
            return $state;
        });
        if ($ok) LogService::log("$label targets set to [" . implode(',', $ids) . ']', LogService::LOG_INFO, 'HubStore');
        return $ok;
    }

    // ---------- skills/ + commands/ trees (H-03, phase 3) ----------

    /**
     * Validate a skill-relative file path: ≤TREE_MAX_DEPTH segments, each
     * matching TREE_SEGMENT_RE (no leading dot, so '.' / '..' / dotfiles are
     * structurally impossible), forward slashes only.
     */
    public static function validateTreePath(string $rel, array &$errors): bool {
        if ($rel === '' || strlen($rel) > 200) { $errors[] = 'invalid file path'; return false; }
        if (strpos($rel, '\\') !== false || $rel[0] === '/' || substr($rel, -1) === '/') {
            $errors[] = "invalid file path '$rel'"; return false;
        }
        $segments = explode('/', $rel);
        if (count($segments) > self::TREE_MAX_DEPTH) {
            $errors[] = 'file path exceeds the max depth of ' . self::TREE_MAX_DEPTH; return false;
        }
        foreach ($segments as $seg) {
            if (!preg_match(self::TREE_SEGMENT_RE, $seg)) {
                $errors[] = "invalid path segment '$seg' (no leading dot, no traversal, max 64 chars)";
                return false;
            }
        }
        return true;
    }

    /** Total bytes currently stored across the skills/ + commands/ trees. */
    public static function treeTotalBytes(): int {
        $total = 0;
        foreach (self::listSkills() as $info) $total += $info['bytes'];
        foreach (self::listCommands() as $bytes) $total += $bytes;
        return $total;
    }

    /**
     * All hub skills: name => {files: {relPath => bytes}, bytes: total}.
     * Only validly named dirs/paths are surfaced (anything else is ignored,
     * never deleted). Content is NOT included (see getSkillFile).
     * @return array<string,array{files:array<string,int>,bytes:int}>
     */
    public static function listSkills(): array {
        $out = [];
        $dir = self::skillsDir();
        if (!is_dir($dir)) return $out;
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..' || !preg_match(self::NAME_RE, $name) || !is_dir("$dir/$name")) continue;
            $files = [];
            self::collectTreeFiles("$dir/$name", '', 1, $files);
            ksort($files);
            $out[$name] = ['files' => $files, 'bytes' => array_sum($files)];
        }
        ksort($out);
        return $out;
    }

    /** Recursive file collection under a skill dir, depth-capped + name-validated. */
    private static function collectTreeFiles(string $abs, string $relPrefix, int $depth, array &$files): void {
        if ($depth > self::TREE_MAX_DEPTH) return;
        foreach (scandir($abs) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || !preg_match(self::TREE_SEGMENT_RE, $entry)) continue;
            $rel = ($relPrefix === '') ? $entry : "$relPrefix/$entry";
            if (is_dir("$abs/$entry")) {
                self::collectTreeFiles("$abs/$entry", $rel, $depth + 1, $files);
            } elseif (is_file("$abs/$entry")) {
                $files[$rel] = (int)filesize("$abs/$entry");
            }
        }
    }

    /** One skill file's content, or null when absent/invalid. Content is NEVER logged. */
    public static function getSkillFile(string $skill, string $rel): ?string {
        $errors = [];
        if (!preg_match(self::NAME_RE, $skill) || !self::validateTreePath($rel, $errors)) return null;
        $file = self::skillsDir() . "/$skill/$rel";
        if (!is_file($file)) return null;
        return (string)@file_get_contents($file);
    }

    /**
     * Create/replace one file inside a skill (atomic). A NEW skill must start
     * with SKILL.md (the one mandatory file). Enforces the per-file 256 KB cap
     * and the 2 MB total tree cap. Content is NEVER logged (path/bytes only).
     */
    public static function saveSkillFile(string $skill, string $rel, string $content, array &$errors = []): bool {
        $errors = [];
        if (!preg_match(self::NAME_RE, $skill)) { $errors[] = 'invalid skill name (allowed: [a-zA-Z0-9_-]{1,64})'; return false; }
        if (in_array(strtolower($skill), self::RESERVED_SYSTEM_SKILLS, true)) {
            $errors[] = "skill name '$skill' is reserved for a system skill";
            return false;
        }
        if (!self::validateTreePath($rel, $errors)) return false;
        if (strlen($content) > self::TREE_FILE_MAX_BYTES) {
            $errors[] = 'file exceeds the ' . (self::TREE_FILE_MAX_BYTES / 1024) . ' KB per-file cap';
            return false;
        }
        $skillDir = self::skillsDir() . "/$skill";
        if (!is_dir($skillDir) && $rel !== 'SKILL.md') {
            $errors[] = "new skill '$skill' must be created with SKILL.md first";
            return false;
        }
        $file = "$skillDir/$rel";
        $existing = is_file($file) ? (int)filesize($file) : 0;
        if (self::treeTotalBytes() - $existing + strlen($content) > self::TREE_TOTAL_MAX_BYTES) {
            $errors[] = 'skills/commands store exceeds the ' . (self::TREE_TOTAL_MAX_BYTES / 1048576) . ' MB total cap';
            return false;
        }
        $ok = AtomicWriteService::write($file, $content);
        if ($ok) {
            LogService::log("saved skill file $skill/$rel (" . strlen($content) . ' bytes)', LogService::LOG_INFO, 'HubStore');
            GitHomeService::commitIfEnabled("hub: skill file $skill/$rel saved");
        } else {
            LogService::log("FAILED to write skill file $skill/$rel", LogService::LOG_ERROR, 'HubStore');
        }
        return $ok;
    }

    /**
     * Delete one extra file from a skill. SKILL.md cannot be removed this way —
     * delete the whole skill instead (it is the skill's mandatory anchor).
     */
    public static function deleteSkillFile(string $skill, string $rel, array &$errors = []): bool {
        $errors = [];
        if (!preg_match(self::NAME_RE, $skill)) { $errors[] = 'invalid skill name'; return false; }
        if (!self::validateTreePath($rel, $errors)) return false;
        if ($rel === 'SKILL.md') { $errors[] = 'SKILL.md cannot be removed on its own — delete the whole skill'; return false; }
        $skillDir = self::skillsDir() . "/$skill";
        $file = "$skillDir/$rel";
        if (!is_file($file)) return true; // idempotent
        if (!@unlink($file)) { $errors[] = 'delete failed'; return false; }
        // Prune now-empty subdirs up to (not including) the skill root.
        $d = dirname($file);
        while ($d !== $skillDir && strncmp($d, $skillDir . '/', strlen($skillDir) + 1) === 0) {
            if (!@rmdir($d)) break; // non-empty — stop
            $d = dirname($d);
        }
        LogService::log("deleted skill file $skill/$rel", LogService::LOG_INFO, 'HubStore');
        GitHomeService::commitIfEnabled("hub: skill file $skill/$rel deleted");
        return true;
    }

    /** Delete a whole skill tree from the hub store (idempotent). */
    public static function deleteSkill(string $skill): bool {
        if (!preg_match(self::NAME_RE, $skill)) return false;
        $dir = self::skillsDir() . "/$skill";
        if (!is_dir($dir)) return true; // idempotent
        if (!self::removeTree($dir)) {
            LogService::log("FAILED to delete skill '$skill'", LogService::LOG_ERROR, 'HubStore');
            return false;
        }
        LogService::log("deleted skill '$skill' from the hub store (projected copies reconcile on next project)", LogService::LOG_INFO, 'HubStore');
        GitHomeService::commitIfEnabled("hub: skill '$skill' deleted");
        return true;
    }

    /** Bounded recursive rm of a hub-store subtree (store paths only — never vendor dirs). */
    private static function removeTree(string $dir): bool {
        $ok = true;
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $p = "$dir/$entry";
            if (is_dir($p) && !is_link($p)) $ok = self::removeTree($p) && $ok;
            else $ok = @unlink($p) && $ok;
        }
        return @rmdir($dir) && $ok;
    }

    /**
     * All hub commands: name => bytes (a command is ONE markdown file,
     * commands/&lt;name&gt;.md). Content is NOT included (see getCommand).
     * @return array<string,int>
     */
    public static function listCommands(): array {
        $out = [];
        $dir = self::commandsDir();
        if (!is_dir($dir)) return $out;
        foreach (scandir($dir) ?: [] as $entry) {
            if (substr($entry, -3) !== '.md') continue;
            $name = substr($entry, 0, -3);
            if (!preg_match(self::NAME_RE, $name) || !is_file("$dir/$entry")) continue;
            $out[$name] = (int)filesize("$dir/$entry");
        }
        ksort($out);
        return $out;
    }

    /** One command's content, or null when absent/invalid. Content is NEVER logged. */
    public static function getCommand(string $name): ?string {
        if (!preg_match(self::NAME_RE, $name)) return null;
        $file = self::commandsDir() . "/$name.md";
        if (!is_file($file)) return null;
        return (string)@file_get_contents($file);
    }

    /** Create/replace one command (atomic). Same per-file + total caps as skills. */
    public static function saveCommand(string $name, string $content, array &$errors = []): bool {
        $errors = [];
        if (!preg_match(self::NAME_RE, $name)) { $errors[] = 'invalid command name (allowed: [a-zA-Z0-9_-]{1,64})'; return false; }
        if (strlen($content) > self::TREE_FILE_MAX_BYTES) {
            $errors[] = 'file exceeds the ' . (self::TREE_FILE_MAX_BYTES / 1024) . ' KB per-file cap';
            return false;
        }
        $file = self::commandsDir() . "/$name.md";
        $existing = is_file($file) ? (int)filesize($file) : 0;
        if (self::treeTotalBytes() - $existing + strlen($content) > self::TREE_TOTAL_MAX_BYTES) {
            $errors[] = 'skills/commands store exceeds the ' . (self::TREE_TOTAL_MAX_BYTES / 1048576) . ' MB total cap';
            return false;
        }
        $ok = AtomicWriteService::write($file, $content);
        if ($ok) {
            LogService::log("saved command '$name' (" . strlen($content) . ' bytes)', LogService::LOG_INFO, 'HubStore');
            GitHomeService::commitIfEnabled("hub: command '$name' saved");
        } else {
            LogService::log("FAILED to write command '$name'", LogService::LOG_ERROR, 'HubStore');
        }
        return $ok;
    }

    /** Delete a command from the hub store (idempotent). */
    public static function deleteCommand(string $name): bool {
        if (!preg_match(self::NAME_RE, $name)) return false;
        $file = self::commandsDir() . "/$name.md";
        if (!is_file($file)) return true; // idempotent
        if (!@unlink($file)) return false;
        LogService::log("deleted command '$name' from the hub store (projected copies reconcile on next project)", LogService::LOG_INFO, 'HubStore');
        GitHomeService::commitIfEnabled("hub: command '$name' deleted");
        return true;
    }

    // ---------- state.json (projection ledger + projection target sets) ----------

    /** The three opt-in projection target-set fields stored in state.json. */
    const TARGET_FIELDS = ['instructions_enabledFor', 'skills_enabledFor', 'commands_enabledFor'];

    /** @return array{schema:int,projections:array<string,array>,instructions_enabledFor:string[],skills_enabledFor:string[],commands_enabledFor:string[]} */
    public static function getState(): array {
        $file = self::stateFile();
        $empty = ['schema' => self::SCHEMA, 'projections' => [],
                  'instructions_enabledFor' => [], 'skills_enabledFor' => [], 'commands_enabledFor' => []];
        if (!is_file($file)) return $empty;
        $data = json_decode((string)@file_get_contents($file), true);
        if (!is_array($data) || !isset($data['projections']) || !is_array($data['projections'])) return $empty;
        $data['schema'] = (int)($data['schema'] ?? self::SCHEMA);
        foreach (self::TARGET_FIELDS as $field) {
            $targets = is_array($data[$field] ?? null) ? $data[$field] : [];
            $data[$field] = array_values(array_map('strval', $targets));
        }
        return $data;
    }

    /**
     * Run $fn while holding the state.json interlock. The lock is taken non-blocking in a
     * poll loop with a deadline (never a blocking flock). When it cannot be taken in time,
     * $fn still runs, with a warning: a missed lock must not stop a Settings save.
     * @return mixed what $fn returns
     */
    private static function withStateLock(callable $fn, ?string $stateFile = null) {
        $lockFile = ($stateFile ?? self::stateFile()) . '.lock';
        @mkdir(dirname($lockFile), 0755, true);
        // A link at the lock file would make fopen create or open the target of the link.
        $fp = is_link($lockFile) ? false : @fopen($lockFile, 'c');
        $got = false;
        if ($fp) {
            $deadline = microtime(true) + 3.0;
            do {
                if (@flock($fp, LOCK_EX | LOCK_NB)) { $got = true; break; }
                usleep(50000);
            } while (microtime(true) < $deadline);
            if (!$got) LogService::log('state.json interlock not taken within 3 s; continuing without it', LogService::LOG_WARN, 'HubStore');
        }
        try {
            return $fn();
        } finally {
            if ($fp) {
                if ($got) @flock($fp, LOCK_UN);
                fclose($fp);
            }
        }
    }

    /**
     * Read-modify-write of state.json under the interlock (#666 R-160). $fn gets the
     * CURRENT state and returns the state to save.
     */
    public static function updateState(callable $fn): bool {
        return (bool)self::withStateLock(static function () use ($fn): bool {
            return self::saveState($fn(self::getState()));
        });
    }

    /**
     * Save the projection ledger only (#666 R-160). A projection run loads the state at its
     * start and saves at its end. A Settings save of a target set in between would be undone
     * by saving the whole stale copy. Here the ledger comes from $state and every target set
     * comes from the file as it is NOW.
     */
    public static function saveLedger(array $state): bool {
        return self::updateState(static function (array $fresh) use ($state): array {
            $fresh['projections'] = is_array($state['projections'] ?? null) ? $state['projections'] : [];
            return $fresh;
        });
    }

    /** Read a projection ledger kept outside the main user state file. */
    public static function getLedgerFile(string $file): array {
        if (is_link($file) || !is_file($file)) return [];
        $data = json_decode((string)@file_get_contents($file), true);
        return is_array($data) && is_array($data['projections'] ?? null) ? $data['projections'] : [];
    }

    /** Save only a projection ledger, so a restricted run home cannot rewrite global state. */
    public static function saveLedgerFile(array $state, string $file): bool {
        // The folder of this ledger belongs to the run user. A link at the file would send the write to another file.
        if (is_link($file)) {
            LogService::log('Hub ledger not saved: the ledger file is a link', LogService::LOG_WARN, 'HubStore');
            return false;
        }
        return (bool)self::withStateLock(static function () use ($state, $file): bool {
            $projections = is_array($state['projections'] ?? null) ? $state['projections'] : [];
            ksort($projections);
            $payload = ['schema' => self::SCHEMA,
                'projections' => empty($projections) ? new \stdClass() : $projections];
            return AtomicWriteService::writeJson($file, $payload);
        }, $file);
    }

    public static function saveState(array $state): bool {
        // A damaged ledger or target set is repaired by a person, not overwritten (#641).
        if (self::stateProblem() !== null) {
            LogService::log('refused to write state.json: the existing file is damaged', LogService::LOG_ERROR, 'HubStore');
            return false;
        }
        $state['schema'] = self::SCHEMA;
        if (!isset($state['projections']) || !is_array($state['projections'])) $state['projections'] = [];
        ksort($state['projections']);
        $payload = ['schema' => $state['schema'],
                    'projections' => empty($state['projections']) ? new \stdClass() : $state['projections']];
        foreach (self::TARGET_FIELDS as $field) {
            $payload[$field] = is_array($state[$field] ?? null) ? array_values(array_map('strval', $state[$field])) : [];
        }
        $ok = AtomicWriteService::writeJson(self::stateFile(), $payload);
        if (!$ok) LogService::log('FAILED to write state.json (projection ledger)', LogService::LOG_ERROR, 'HubStore');
        return $ok;
    }
}
