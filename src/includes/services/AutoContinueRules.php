<?php

declare(strict_types=1);

namespace AICliAgents\Services;

/**
 * AUTO_CONTINUE_PATTERNS.md, part B and C: the operator's own auto-continue
 * patterns.
 *
 * The built-in patterns stay in TransientErrorService::builtinPatterns() and
 * QuotaDetectionService. This class keeps a user layer on flash
 * (auto-continue-rules.json, next to pane-input-rules.json) and merges it over
 * the built-ins for the two detectors:
 *   error   -> TransientErrorService 'errors' (generic when the scope is all agents)
 *   quota   -> QuotaDetectionService::detect(), after the built-in parsers
 *   busy    -> the busy list (the agent still works; no Continue)
 *   chrome  -> the chrome list (status rows that are not newer output)
 *
 * Every expression passes validate(): the shared guarded loader
 * (PaneInputRules::patternError), a catastrophic-backtracking probe, an
 * empty-match refusal, per-kind rules, and "the sample must match". At run
 * time every user pattern is matched with PaneInputRules::safeMatch().
 */
final class AutoContinueRules
{
    public const SCHEMA = 1;
    public const KINDS = ['error', 'quota', 'busy', 'chrome'];
    public const KIND_LABELS = ['error' => 'Transient error', 'quota' => 'Usage quota with retry time', 'busy' => 'Busy marker', 'chrome' => 'Chrome (status line)'];
    public const ALL_AGENTS = '*';
    public const MAX_PATTERNS = 64;
    public const MAX_NAME = 60;
    public const MAX_SAMPLE_BYTES = 4000;
    /** A Continue for a user quota pattern is planned this long after the retry time. */
    public const QUOTA_MARGIN_SECONDS = 60;
    public const REPORT_REPO = 'johnpwhite/unraid-plg-aicliagents';
    public const REPORT_TEMPLATE = 'autocontinue-pattern.yml';
    public const REPORT_LABEL = 'auto-continue-pattern';
    /** GitHub refuses very long new-issue URLs; stay well below its ~8 KiB limit. */
    public const MAX_URL = 7500;

    /** @var array{key:string,data:array}|null per-process cache of the parsed file */
    private static ?array $cache = null;

    public static function overlayPath(): string
    {
        $env = getenv('AICLI_AUTO_CONTINUE_RULES');
        return ($env !== false && $env !== '') ? $env : '/boot/config/plugins/unraid-aicliagents/auto-continue-rules.json';
    }

    public static function resetCache(): void { self::$cache = null; }

    // ------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------

    /** Accept "/body/flags" as is; wrap a bare body as /body/iu (escaping its slashes). */
    public static function normaliseRegex(string $re): string
    {
        $re = trim($re);
        if ($re === '') return '';
        // Written as /body/flags: keep it, so the loader can report a bad flag.
        if ($re[0] === '/' && preg_match('#^/.+/[A-Za-z]*$#s', $re)) return $re;
        return '/' . preg_replace('#(?<!\\\\)/#', '\\/', $re) . '/iu';
    }

    /**
     * Why an expression is unsafe or unusable for $kind, or null. Runs the
     * shared loader first, then the extra guards in the spec's §Security.
     */
    public static function regexError(string $re, string $kind): ?string
    {
        $err = PaneInputRules::patternError($re);
        if ($err !== null) return $err;
        if (preg_match('/\\\\[1-9]|\\\\g\{?-?\d|\(\?(?:R|P>|P=|>|&|\d)/', $re)) return 'back-references, recursion and atomic groups are not allowed';
        if (self::catastrophic($re)) return 'catastrophic backtracking: the expression is too slow on long input';
        if (PaneInputRules::safeMatch($re, '') || PaneInputRules::safeMatch($re, ' ')) return 'the expression matches an empty line, so it would match every line';
        if ($kind === 'error' && !preg_match('#^/\^#', $re)) return 'a transient error pattern must start with ^ (it is matched at the start of a line)';
        if ($kind === 'quota' && self::captureGroup($re) === null) return 'a quota pattern needs a capture group for the retry time, for example (?<retry>\d+h\s*\d+m)';
        return null;
    }

    /**
     * True when the expression hits the PCRE backtrack budget, or runs too long,
     * on a hostile subject. Nested-quantifier shapes the loader cannot see
     * syntactically ((a|aa)+, (\w+\s?)+$) are caught here.
     */
    public static function catastrophic(string $re): bool
    {
        $subjects = [
            str_repeat('a', 3000), str_repeat('a', 3000) . '!', str_repeat(' ', 3000) . '!',
            str_repeat('ab', 1500) . '!', str_repeat('1', 3000) . 'x', str_repeat('x=', 1500),
            str_repeat('a ', 1500) . '!', str_repeat('aaaa-', 600) . '@',
        ];
        $prevB = ini_get('pcre.backtrack_limit'); $prevR = ini_get('pcre.recursion_limit'); $prevJ = ini_get('pcre.jit');
        ini_set('pcre.backtrack_limit', '20000'); ini_set('pcre.recursion_limit', '2000');
        // The JIT does not honour the backtrack limit the same way; probe without it.
        ini_set('pcre.jit', '0');
        $start = microtime(true);
        try {
            foreach ($subjects as $s) {
                $r = @preg_match($re, $s);
                if ($r === false) {
                    $e = preg_last_error();
                    if (in_array($e, [PREG_BACKTRACK_LIMIT_ERROR, PREG_RECURSION_LIMIT_ERROR, PREG_JIT_STACKLIMIT_ERROR], true)) return true;
                }
                if (microtime(true) - $start > 0.25) return true;
            }
            return false;
        } finally {
            if ($prevB !== false) ini_set('pcre.backtrack_limit', (string)$prevB);
            if ($prevR !== false) ini_set('pcre.recursion_limit', (string)$prevR);
            if ($prevJ !== false) ini_set('pcre.jit', (string)$prevJ);
        }
    }

    /** 'retry' when the expression has a (?<retry>…) group, 1 when it has any capture group, else null. */
    public static function captureGroup(string $re)
    {
        if (preg_match('/\(\?P?<retry>|\(\?\'retry\'/', $re)) return 'retry';
        // A capture group is "(" not followed by "?", and not escaped.
        if (preg_match('/(?<!\\\\)\((?!\?)/', $re) || preg_match('/\(\?P?<[A-Za-z_]\w*>/', $re)) return 1;
        return null;
    }

    /**
     * Validate one pattern from the UI or the MCP tool.
     * @param array<string,mixed> $in name, agent, kind, re, sample, (id, enabled)
     * @return array{pattern?:array<string,mixed>,errors:string[]}
     */
    public static function validate(array $in): array
    {
        $errors = [];
        $name = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string)($in['name'] ?? '')) ?? '');
        if ($name === '') $errors[] = 'Give the pattern a name.';
        if (function_exists('mb_strlen') ? mb_strlen($name) > self::MAX_NAME : strlen($name) > self::MAX_NAME) $errors[] = 'The name is longer than ' . self::MAX_NAME . ' characters.';

        $agent = strtolower(trim((string)($in['agent'] ?? ($in['agentId'] ?? '*'))));
        if ($agent === '' || $agent === 'all') $agent = self::ALL_AGENTS;
        if ($agent !== self::ALL_AGENTS && !preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $agent)) $errors[] = 'The agent must be an agent id or "all".';

        $kind = strtolower(trim((string)($in['kind'] ?? '')));
        if (!in_array($kind, self::KINDS, true)) $errors[] = 'The kind must be one of: ' . implode(', ', self::KINDS) . '.';

        $re = self::normaliseRegex((string)($in['re'] ?? ($in['regex'] ?? '')));
        if ($re === '') $errors[] = 'Enter a regular expression.';
        elseif (in_array($kind, self::KINDS, true)) {
            $err = self::regexError($re, $kind);
            if ($err !== null) $errors[] = 'The expression is refused: ' . $err . '.';
        }

        $sampleRaw = (string)($in['sample'] ?? '');
        if (strlen($sampleRaw) > self::MAX_SAMPLE_BYTES) $errors[] = 'The sample is longer than ' . self::MAX_SAMPLE_BYTES . ' bytes; keep only the lines the pattern is for.';
        $sample = self::cleanSample($sampleRaw);
        if ($kind === 'quota' && $sample === '') $errors[] = 'A quota pattern needs a sample, so the retry time can be checked.';

        if ($errors === [] && $sample !== '') {
            $m = self::matchLines($re, $kind, $sample);
            if ($m['matched'] === 0) {
                $errors[] = self::cleanSample($sampleRaw, false) !== $sample && self::matchLines($re, $kind, self::cleanSample($sampleRaw, false))['matched'] > 0
                    ? 'The pattern matches the sample only before its secrets are masked. Do not match on a secret.'
                    : 'The pattern does not match its sample.';
            } elseif ($kind === 'quota' && $m['retry'] === null) {
                $errors[] = 'The retry-time group matched "' . (string)$m['retryText'] . '", which is not a time (use a duration such as "1h 21m" or a clock time such as "3pm").';
            }
        }
        if ($errors !== []) return ['errors' => $errors];

        $id = (string)($in['id'] ?? '');
        if (!preg_match('/^u-[a-f0-9]{8}$/', $id)) $id = '';
        return ['errors' => [], 'pattern' => [
            'id' => $id,
            'name' => $name,
            'agent' => $agent,
            'kind' => $kind,
            're' => $re,
            'sample' => $sample,
            'enabled' => !array_key_exists('enabled', $in) || filter_var($in['enabled'], FILTER_VALIDATE_BOOLEAN),
        ]];
    }

    /** Plain text (no colour), trailing spaces removed, secrets masked (unless $mask is false). */
    public static function cleanSample(string $raw, bool $mask = true): string
    {
        $t = TmuxService::plainPaneText(str_replace("\r", '', $raw));
        $rows = array_map(static fn($r): string => rtrim((string)$r), preg_split('/\n/', $t) ?: []);
        while ($rows !== [] && trim((string)end($rows)) === '') array_pop($rows);
        while ($rows !== [] && trim((string)$rows[0]) === '') array_shift($rows);
        $t = implode("\n", $rows);
        return $mask ? WorkspaceScreenService::mask($t) : $t;
    }

    /**
     * Run $re over $text the way the detector for $kind does, line by line:
     * error and chrome on the line with its border and bullet glyphs removed,
     * busy on the raw line, quota on the line and on the line joined with up to
     * three wrapped rows under it.
     *
     * @return array{lines:array<int,array{n:int,text:string,match:bool}>,matched:int,retryText:?string,retry:?int}
     */
    public static function matchLines(string $re, string $kind, string $text, ?int $now = null): array
    {
        $rows = preg_split('/\R/u', TmuxService::plainPaneText($text)) ?: [];
        $out = []; $matched = 0; $retryText = null; $retry = null;
        foreach ($rows as $i => $row) {
            $row = (string)$row;
            $subject = in_array($kind, ['error', 'chrome'], true) ? TransientErrorService::detectorLine($row) : $row;
            $hit = $subject !== '' && PaneInputRules::safeMatch($re, $subject);
            if (!$hit && $kind === 'quota') {
                for ($k = 2; $k <= 4 && $i + $k - 1 < count($rows); $k++) {
                    $joined = self::joinRows(array_slice($rows, $i, $k));
                    // The message must START on this row: a match that lies wholly in
                    // the rows below is theirs, not this row's.
                    if (PaneInputRules::safeMatch($re, $joined) && !PaneInputRules::safeMatch($re, self::joinRows(array_slice($rows, $i + 1, $k - 1)))) { $hit = true; $subject = $joined; break; }
                }
            }
            $out[] = ['n' => $i + 1, 'text' => $row, 'match' => $hit];
            if ($hit) {
                $matched++;
                if ($kind === 'quota') {
                    $g = self::retryGroupText($re, $subject);
                    if ($g !== null) { $retryText = $g; $retry = self::parseRetry($g, $now ?? time()); }
                }
            }
        }
        return ['lines' => $out, 'matched' => $matched, 'retryText' => $retryText, 'retry' => $retry];
    }

    /** Rows joined with single spaces (a wrapped message read as one line). */
    private static function joinRows(array $rows): string
    {
        return trim(preg_replace('/\s+/u', ' ', implode(' ', array_map('strval', $rows))) ?? '');
    }

    /** The retry-time group's text in $subject, or null. */
    public static function retryGroupText(string $re, string $subject): ?string
    {
        $g = self::captureGroup($re);
        if ($g === null) return null;
        $prevB = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '20000');
        try {
            if (@preg_match($re, $subject, $m) !== 1) return null;
        } finally {
            if ($prevB !== false) ini_set('pcre.backtrack_limit', (string)$prevB);
        }
        $v = $m[$g] ?? null;
        if (($v === null || $v === '') && $g === 'retry') $v = $m[1] ?? null;
        $v = trim((string)$v);
        return $v === '' ? null : $v;
    }

    /**
     * The retry time as an epoch: a clock time with am/pm ("3pm", "3:05 PM"),
     * a duration ("1h 21m", "45s", "5 minutes"), or a 24-hour clock ("15:30").
     * A clock time in the past means tomorrow. Null when it is not a time.
     */
    public static function parseRetry(string $text, int $now): ?int
    {
        $t = strtolower(trim($text));
        try {
            $tz = new \DateTimeZone(date_default_timezone_get());
            $cur = (new \DateTimeImmutable('@' . $now))->setTimezone($tz);
            if (preg_match('/\b(\d{1,2})(?::(\d{2}))?\s*([ap])\.?\s?m\b\.?/', $t, $m)) {
                $h = (int)$m[1]; $min = (int)$m[2];
                if ($h < 1 || $h > 12 || $min > 59) return null;
                if ($m[3] === 'p' && $h !== 12) $h += 12;
                if ($m[3] === 'a' && $h === 12) $h = 0;
                $c = $cur->setTime($h, $min, 0);
                if ($c->getTimestamp() <= $now) $c = $c->modify('+1 day');
                return $c->getTimestamp();
            }
            if (preg_match_all('/(\d{1,4})\s*(d|days?|h|hrs?|hours?|m|mins?|minutes?|s|secs?|seconds?)\b/', $t, $mm, PREG_SET_ORDER)) {
                $total = 0;
                foreach ($mm as $p) {
                    $u = $p[2][0];
                    $total += (int)$p[1] * ['d' => 86400, 'h' => 3600, 'm' => 60, 's' => 1][$u];
                }
                return $total > 0 && $total <= 30 * 86400 ? $now + $total : null;
            }
            if (preg_match('/\b([01]?\d|2[0-3]):([0-5]\d)\b/', $t, $m)) {
                $c = $cur->setTime((int)$m[1], (int)$m[2], 0);
                if ($c->getTimestamp() <= $now) $c = $c->modify('+1 day');
                return $c->getTimestamp();
            }
        } catch (\Throwable $e) {
            return null;
        }
        return null;
    }

    // ------------------------------------------------------------------
    // Storage
    // ------------------------------------------------------------------

    /**
     * The stored user patterns. Each is re-checked by the guarded loader: one
     * that fails (a file edited by hand) is dropped and named in 'errors'.
     * @return array{patterns:array<int,array<string,mixed>>,errors:string[]}
     */
    public static function load(): array
    {
        $path = self::overlayPath();
        $key = $path . '|' . (is_file($path) ? (string)@filemtime($path) . ':' . (string)@filesize($path) : 'none');
        if (self::$cache !== null && self::$cache['key'] === $key) return self::$cache['data'];
        $data = ['patterns' => [], 'errors' => []];
        if (is_file($path)) {
            $raw = json_decode((string)@file_get_contents($path), true);
            if (!is_array($raw)) {
                $data['errors'][] = 'auto-continue-rules.json is not valid JSON; only the built-in patterns are used.';
                LogService::log('auto-continue-rules.json is not valid JSON — using built-in patterns only', LogService::LOG_WARN, 'AutoContinueRules');
            } else {
                foreach ((array)($raw['patterns'] ?? []) as $p) {
                    if (!is_array($p)) continue;
                    $kind = (string)($p['kind'] ?? '');
                    $re = (string)($p['re'] ?? '');
                    $id = (string)($p['id'] ?? '');
                    $err = in_array($kind, self::KINDS, true) ? self::runtimeError($re, $kind) : 'unknown kind';
                    if ($err !== null || !preg_match('/^u-[a-f0-9]{8}$/', $id)) {
                        $data['errors'][] = "Pattern '" . substr((string)($p['name'] ?? $id), 0, 60) . "' is not used: " . ($err ?? 'bad id') . '.';
                        continue;
                    }
                    $data['patterns'][] = [
                        'id' => $id,
                        'name' => (string)($p['name'] ?? $id),
                        'agent' => (string)($p['agent'] ?? self::ALL_AGENTS),
                        'kind' => $kind,
                        're' => $re,
                        'sample' => (string)($p['sample'] ?? ''),
                        'enabled' => !array_key_exists('enabled', $p) || (bool)$p['enabled'],
                        'source' => (string)($p['source'] ?? 'ui'),
                        'created_at' => (int)($p['created_at'] ?? 0),
                        'updated_at' => (int)($p['updated_at'] ?? 0),
                    ];
                    if (count($data['patterns']) >= self::MAX_PATTERNS) break;
                }
            }
        }
        self::$cache = ['key' => $key, 'data' => $data];
        return $data;
    }

    /** The load-time check: the guarded loader and the runtime guards, without the sample. */
    private static function runtimeError(string $re, string $kind): ?string
    {
        return self::regexError($re, $kind);
    }

    /** @param array<int,array<string,mixed>> $patterns */
    private static function write(array $patterns): bool
    {
        $path = self::overlayPath();
        @mkdir(dirname($path), 0755, true);
        $ok = AtomicWriteService::writeJson($path, ['version' => self::SCHEMA, 'patterns' => array_values($patterns)]);
        self::resetCache();
        return $ok;
    }

    public static function find(string $id): ?array
    {
        foreach (self::load()['patterns'] as $p) if ($p['id'] === $id) return $p;
        return null;
    }

    /**
     * Validate and save (add, or update when `id` names a stored pattern).
     * @return array{pattern?:array<string,mixed>,error?:string,errors?:string[]}
     */
    public static function save(array $in, string $source = 'ui'): array
    {
        $v = self::validate($in);
        if ($v['errors'] !== []) return ['error' => implode(' ', $v['errors']), 'errors' => $v['errors']];
        $p = $v['pattern'];
        $all = self::load()['patterns'];
        $now = time();
        $found = false;
        foreach ($all as $i => $old) {
            if ($p['id'] !== '' && $old['id'] === $p['id']) {
                $all[$i] = array_merge($old, $p, ['updated_at' => $now]);
                $p = $all[$i];
                $found = true;
                break;
            }
        }
        if (!$found) {
            if (count($all) >= self::MAX_PATTERNS) return ['error' => 'You already have ' . self::MAX_PATTERNS . ' patterns; delete one first.', 'errors' => []];
            $p['id'] = 'u-' . bin2hex(random_bytes(4));
            $p['source'] = in_array($source, ['ui', 'mcp', 'cli'], true) ? $source : 'ui';
            $p['created_at'] = $now;
            $p['updated_at'] = $now;
            $all[] = $p;
        }
        if (!self::write($all)) return ['error' => 'Could not write auto-continue-rules.json.', 'errors' => []];
        LogService::log("Auto-continue pattern saved: {$p['id']} ({$p['kind']}, agent {$p['agent']}, source {$p['source']})", LogService::LOG_INFO, 'AutoContinueRules');
        return ['pattern' => $p];
    }

    public static function delete(string $id): array
    {
        $all = self::load()['patterns'];
        $keep = array_values(array_filter($all, static fn(array $p): bool => $p['id'] !== $id));
        if (count($keep) === count($all)) return ['error' => "No pattern with id '$id'."];
        if (!self::write($keep)) return ['error' => 'Could not write auto-continue-rules.json.'];
        LogService::log("Auto-continue pattern deleted: $id", LogService::LOG_INFO, 'AutoContinueRules');
        return ['deleted' => $id];
    }

    public static function setEnabled(string $id, bool $enabled): array
    {
        $all = self::load()['patterns'];
        foreach ($all as $i => $p) {
            if ($p['id'] !== $id) continue;
            $all[$i]['enabled'] = $enabled;
            $all[$i]['updated_at'] = time();
            if (!self::write($all)) return ['error' => 'Could not write auto-continue-rules.json.'];
            return ['pattern' => $all[$i]];
        }
        return ['error' => "No pattern with id '$id'."];
    }

    // ------------------------------------------------------------------
    // Merge into the detectors
    // ------------------------------------------------------------------

    /**
     * Enabled user patterns that apply to $agentId (its own and the all-agents ones).
     * @return array{error:array<string,string>,quota:array<string,string>,busy:array<string,string>,chrome:array<string,string>}
     */
    public static function forAgent(string $agentId): array
    {
        $out = ['error' => [], 'quota' => [], 'busy' => [], 'chrome' => []];
        foreach (self::load()['patterns'] as $p) {
            if (empty($p['enabled'])) continue;
            if ($p['agent'] !== self::ALL_AGENTS && $p['agent'] !== $agentId) continue;
            $out[$p['kind']]['user:' . $p['id']] = $p['re'];
        }
        return $out;
    }

    /** True when the operator added an error or quota pattern for exactly this agent. */
    public static function agentOptedIn(string $agentId): bool
    {
        foreach (self::load()['patterns'] as $p) {
            if (!empty($p['enabled']) && $p['agent'] === $agentId && in_array($p['kind'], ['error', 'quota'], true)) return true;
        }
        return false;
    }

    /**
     * Merge the user layer into TransientErrorService's pattern data.
     * @param array<string,mixed> $base TransientErrorService::builtinPatterns()
     * @return array<string,mixed>
     */
    public static function mergeInto(array $base): array
    {
        $kindKey = ['error' => 'errors', 'busy' => 'busy', 'chrome' => 'chrome'];
        foreach (self::load()['patterns'] as $p) {
            if (empty($p['enabled']) || !isset($kindKey[$p['kind']])) continue;
            $key = 'user:' . $p['id'];
            if ($p['agent'] === self::ALL_AGENTS) {
                $section = $p['kind'] === 'error' ? 'generic' : $kindKey[$p['kind']];
                $base[$section][$key] = $p['re'];
            } else {
                $base['agents'][$p['agent']][$kindKey[$p['kind']]][$key] = $p['re'];
            }
        }
        return $base;
    }

    /**
     * A user quota pattern that is the newest output on the screen, with a
     * readable retry time: a one-shot, session-only Continue after it.
     * @return array<string,mixed>|null the QuotaDetectionService::detect() shape
     */
    public static function quotaDetect(string $agentId, string $raw, int $now): ?array
    {
        $patterns = self::forAgent($agentId)['quota'];
        if ($patterns === [] || trim($raw) === '') return null;
        $lines = array_slice(preg_split('/\R/u', TmuxService::plainPaneText(rtrim($raw, "\r\n"))) ?: [], -TransientErrorService::TAIL_LINES);
        $p = TransientErrorService::patterns();
        $agent = (array)($p['agents'][$agentId] ?? []);
        $quiet = array_merge($p['chrome'], (array)($agent['chrome'] ?? []), $p['busy'], (array)($agent['busy'] ?? []));

        foreach ($patterns as $key => $re) {
            $hitAt = -1; $used = 1; $subject = '';
            foreach ($lines as $i => $row) {
                if (PaneInputRules::safeMatch($re, (string)$row)) { $hitAt = $i; $used = 1; $subject = (string)$row; continue; }
                for ($k = 2; $k <= 4 && $i + $k - 1 < count($lines); $k++) {
                    $joined = self::joinRows(array_slice($lines, $i, $k));
                    if (PaneInputRules::safeMatch($re, $joined) && !PaneInputRules::safeMatch($re, self::joinRows(array_slice($lines, $i + 1, $k - 1)))) { $hitAt = $i; $used = $k; $subject = $joined; break; }
                }
            }
            if ($hitAt < 0) continue;
            $newer = false;
            foreach (array_slice($lines, $hitAt + $used) as $row) {
                $text = TransientErrorService::detectorLine((string)$row);
                if (!preg_match('/[\p{L}\p{N}]/u', $text)) continue;
                $isQuiet = false;
                foreach ($quiet as $q) { if (PaneInputRules::safeMatch((string)$q, $text) || PaneInputRules::safeMatch((string)$q, (string)$row)) { $isQuiet = true; break; } }
                if (!$isQuiet) { $newer = true; break; }
            }
            if ($newer) continue;
            $g = self::retryGroupText($re, $subject);
            $reset = $g !== null ? self::parseRetry($g, $now) : null;
            if ($reset === null || $reset <= $now) continue;
            $id = substr((string)$key, 5);
            $name = (string)(self::find($id)['name'] ?? $id);
            return [
                'source' => 'quota-detect',
                'scope' => 'session',
                'at' => $reset + self::QUOTA_MARGIN_SECONDS,
                'reset_at' => $reset,
                'reason' => "Your auto-continue pattern '$name' found a usage quota; a Continue follows after " . $g . '.',
                'fingerprint' => substr(hash('sha256', 'user-quota|' . $key . '|' . strtolower(preg_replace('/\s+/u', ' ', $subject) ?? '')), 0, 16),
                'label' => (string)$g,
                'pattern' => (string)$key,
            ];
        }
        return null;
    }

    /**
     * Built-in patterns for the Settings list: one row per pattern.
     * @return array<int,array{agent:string,kind:string,id:string,re:string}>
     */
    public static function builtins(): array
    {
        $rows = [];
        $b = TransientErrorService::builtinPatterns();
        foreach (['generic' => 'error', 'busy' => 'busy', 'chrome' => 'chrome'] as $sec => $kind) {
            foreach ((array)$b[$sec] as $id => $re) $rows[] = ['agent' => self::ALL_AGENTS, 'kind' => $kind, 'id' => (string)$id, 're' => (string)$re];
        }
        foreach ((array)$b['agents'] as $agentId => $a) {
            foreach (['errors' => 'error', 'busy' => 'busy', 'chrome' => 'chrome'] as $sec => $kind) {
                foreach ((array)($a[$sec] ?? []) as $id => $re) $rows[] = ['agent' => (string)$agentId, 'kind' => $kind, 'id' => (string)$id, 're' => (string)$re];
            }
        }
        foreach (QuotaDetectionService::builtinPatterns() as $r) $rows[] = $r;
        return $rows;
    }

    // ------------------------------------------------------------------
    // Part C: the GitHub report
    // ------------------------------------------------------------------

    /** Screen masking plus every path under /mnt shortened to "/mnt/…". */
    public static function maskForReport(string $text): string
    {
        $text = WorkspaceScreenService::mask($text);
        return (string)preg_replace('#/mnt/[^\s"\'`<>|)\]]+#u', "/mnt/\u{2026}", $text);
    }

    /**
     * The prefilled GitHub new-issue URL for a pattern.
     * $ctx: agentVersion, model, provider, pluginVersion.
     * $edit: title and/or sample from the preview dialog (still masked here).
     *
     * @return array{url:string,title:string,fields:array<string,string>,trimmed:bool}
     */
    public static function reportUrl(array $pattern, array $ctx = [], array $edit = []): array
    {
        $kind = (string)($pattern['kind'] ?? '');
        $re = (string)($pattern['re'] ?? '');
        $agent = (string)($pattern['agent'] ?? self::ALL_AGENTS);
        $agentLabel = $agent === self::ALL_AGENTS ? 'all agents' : $agent;

        if (array_key_exists('sample', $edit)) {
            $sampleLines = preg_split('/\R/u', self::maskForReport(self::cleanSample((string)$edit['sample']))) ?: [];
        } else {
            $sample = (string)($pattern['sample'] ?? '');
            $m = $sample !== '' ? self::matchLines($re, $kind, $sample) : ['lines' => []];
            $sampleLines = [];
            foreach ($m['lines'] as $l) if ($l['match']) $sampleLines[] = self::maskForReport($l['text']);
        }
        $sampleLines = array_values(array_filter($sampleLines, static fn($l): bool => trim((string)$l) !== ''));

        $retry = '';
        if ($kind === 'quota') {
            $g = self::captureGroup($re);
            $retry = $g === 'retry' ? 'named group "retry"' : 'capture group 1';
            $got = null;
            foreach ($sampleLines as $l) { $got = self::retryGroupText($re, (string)$l); if ($got !== null) break; }
            if ($got !== null) $retry .= ' — reads "' . $got . '" in the sample';
        }
        $model = trim((string)($ctx['provider'] ?? '') . ((($ctx['provider'] ?? '') !== '' && ($ctx['model'] ?? '') !== '') ? ' / ' : '') . (string)($ctx['model'] ?? ''));
        $title = array_key_exists('title', $edit) && trim((string)$edit['title']) !== ''
            ? trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string)$edit['title']) ?? '')
            : 'Auto-continue pattern: ' . $agentLabel . ' ' . (self::KIND_LABELS[$kind] ?? $kind) . ' — ' . (string)($pattern['name'] ?? '');
        $title = self::maskForReport(function_exists('mb_substr') ? mb_substr($title, 0, 120) : substr($title, 0, 120));

        $fields = [
            'agent' => $agentLabel,
            'agent_version' => (string)($ctx['agentVersion'] ?? '') !== '' ? (string)$ctx['agentVersion'] : 'unknown',
            'provider_model' => $model !== '' ? self::maskForReport($model) : 'unknown',
            'kind' => $kind . ' (' . (self::KIND_LABELS[$kind] ?? $kind) . ')',
            'regex' => $re,
            'retry_group' => $retry !== '' ? $retry : 'none',
            'plugin_version' => (string)($ctx['pluginVersion'] ?? '') !== '' ? (string)$ctx['pluginVersion'] : ConfigService::getVersion(),
        ];
        $build = static function (array $lines) use ($title, $fields): string {
            $q = ['template' => self::REPORT_TEMPLATE, 'labels' => self::REPORT_LABEL, 'title' => $title] + $fields + ['sample' => implode("\n", $lines)];
            return 'https://github.com/' . self::REPORT_REPO . '/issues/new?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986);
        };
        $trimmed = false;
        $url = $build($sampleLines);
        while (strlen($url) > self::MAX_URL && count($sampleLines) > 1) {
            array_pop($sampleLines); $trimmed = true;
            $url = $build($sampleLines);
        }
        if (strlen($url) > self::MAX_URL && $sampleLines !== []) {
            $last = (string)$sampleLines[0];
            $lo = 0; $hi = strlen($last);
            while ($lo < $hi) {
                $mid = intdiv($lo + $hi + 1, 2);
                $cut = function_exists('mb_strcut') ? mb_strcut($last, 0, $mid, 'UTF-8') : substr($last, 0, $mid);
                if (strlen($build([$cut . "\u{2026}"])) <= self::MAX_URL) $lo = $mid; else $hi = $mid - 1;
            }
            $cut = function_exists('mb_strcut') ? mb_strcut($last, 0, $lo, 'UTF-8') : substr($last, 0, $lo);
            $sampleLines = $lo > 0 ? [$cut . "\u{2026}"] : [];
            $trimmed = true;
            $url = $build($sampleLines);
        }
        $fields['sample'] = implode("\n", $sampleLines);
        return ['url' => $url, 'title' => $title, 'fields' => $fields, 'trimmed' => $trimmed];
    }

    /**
     * The report for a stored pattern, with the context read now: installed
     * agent version, plugin version, and (when $workspaceId is given and its
     * screen shows them) the model and provider.
     */
    public static function reportFor(array $pattern, string $workspaceId = '', array $edit = []): array
    {
        $ctx = ['pluginVersion' => ConfigService::getVersion()];
        $agent = (string)($pattern['agent'] ?? '');
        if ($agent !== '' && $agent !== self::ALL_AGENTS) {
            try { $v = AgentRegistry::getInstalledVersion($agent); if ($v !== '0.0.0') $ctx['agentVersion'] = $v; } catch (\Throwable $e) {}
        }
        foreach (['agentVersion', 'model', 'provider'] as $k) {
            if (isset($edit[$k]) && is_string($edit[$k]) && $edit[$k] !== '') $ctx[$k] = substr($edit[$k], 0, 120);
        }
        return self::reportUrl($pattern, $ctx, array_intersect_key($edit, ['title' => 1, 'sample' => 1]));
    }
}
