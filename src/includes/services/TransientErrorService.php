<?php

declare(strict_types=1);

namespace AICliAgents\Services;

/**
 * TRANSIENT_ERROR_AUTO_CONTINUE.md / Forgejo #312.
 *
 * An agent can stop in the middle of a task when one model call fails with a
 * TEMPORARY provider error (HTTP 5xx, 529 "overloaded", a 429 that is not a
 * usage quota, a stream reset, a timeout). The agent then waits at its idle
 * prompt, and the task stays stopped until a person types "continue".
 *
 * This service is the second detection kind beside the quota kinds in
 * QuotaDetectionService. It reads the same bounded pane capture on the same
 * supervisor tick. When the LAST thing in the pane is such an error and the
 * pane is idle, it plans ONE Continue for that workspace through the existing
 * scheduled-continue sidecar (source 'transient-error'). The supervisor's
 * readiness-gated ticker sends it. Rules:
 *   - Per workspace. No fan-out to sibling sessions.
 *   - Backoff per incident: 1, 5, 15 minutes by default, and at most 3
 *     automatic continues. An incident is one error fingerprint.
 *   - New output (the pane reaches an idle prompt without the error) resets it.
 *   - The same error again after a continue counts toward the cap. At the cap
 *     the service stops and marks the workspace "needs you" in the Activity tray.
 *   - A usage-limit (quota) match always wins. A pending schedule of another
 *     source (quota, operator) is never replaced.
 *
 * Patterns are DATA (patterns()), so a new agent shape is one row, not code.
 * Only parsed metadata and a short fingerprint are stored, never pane text.
 */
final class TransientErrorService
{
    public const SOURCE = 'transient-error';
    public const DEFAULT_BACKOFF_MINUTES = [1, 5, 15];
    public const DEFAULT_MAX_CONTINUES = 3;
    public const MODES = ['recommended', 'all', 'off'];
    /** How many trailing capture lines the detector reads. */
    public const TAIL_LINES = 40;
    /** Contiguous wrapped lines of one error message that may follow its first line. */
    public const MAX_CONTINUATION_LINES = 4;
    /** A row this many columns short of its block's widest row still counts as wrapped. */
    private const WRAP_SLACK = 12;
    /** A row shorter than this never counts as wrapped (no error box is that narrow). */
    private const WRAP_MIN_WIDTH = 40;
    private const STATE_PATH = '/tmp/unraid-aicliagents/transient-error-state.json';

    /**
     * Agents that are ON in the default 'recommended' mode. Each one has a
     * verified error shape (a real pane sample or the agent's own source); the
     * spec's agent table gives the source for each row.
     */
    public const RECOMMENDED_AGENTS = ['opencode', 'kilocode', 'claude-code', 'codex-cli', 'gemini-cli', 'qwen-code'];

    /**
     * The pattern data. Every regex runs against ONE plain-text line from which
     * the leading border and bullet glyphs were removed (see stripLead()).
     *   generic  error lines that any agent can show
     *   exclude  words that make an error NOT transient (quota, billing, auth,
     *            context size). Checked on the whole error block.
     *   busy     generic "the agent is still working or retrying" markers
     *   agents   per-agent additions: errors, busy, chrome (status lines that
     *            may follow an error without being "newer output"), and
     *            'generic' => false to disable the generic error set.
     *
     * AUTO_CONTINUE_PATTERNS.md: patterns() is these built-ins with the
     * operator's own patterns (auto-continue-rules.json) merged in by
     * AutoContinueRules::mergeInto(); builtinPatterns() is the baked data only.
     *
     * @return array{generic:array<string,string>,exclude:array<string,string>,busy:array<string,string>,chrome:array<string,string>,agents:array<string,array<string,mixed>>}
     */
    public static function patterns(): array
    {
        $base = self::builtinPatterns();
        try {
            return AutoContinueRules::mergeInto($base);
        } catch (\Throwable $e) {
            return $base; // a broken user layer never disables the built-ins
        }
    }

    /** @return array{generic:array<string,string>,exclude:array<string,string>,busy:array<string,string>,chrome:array<string,string>,agents:array<string,array<string,mixed>>} */
    public static function builtinPatterns(): array
    {
        // OpenCode and its fork Kilo render the TUI the same way (real capture:
        // tests/fixtures/transient-error/opencode-503-overloaded.txt).
        $opencodeTui = [
            'errors' => [
                // The assistant-message error box shows the provider's JSON body:
                // {"message":"Streaming response failed: [503] ...","type":"server_error"}
                'opencode-json-error' => '/^\{"message"\s*:\s*".{0,400}"type"\s*:\s*"(?:server_error|overloaded_error|api_error|rate_limit_error|timeout_error|service_unavailable(?:_error)?)"/iu',
                // The same box with a plain provider message and a bracketed status.
                'opencode-status-error' => '/^(?:AI_APICallError|APIError|ProviderError|Streaming response failed)\b.{0,160}\[(?:429|5\d\d)\]/iu',
            ],
            'busy' => [
                // Footer while the session works or retries: "⬝⬝⬝⬝ esc interrupt".
                'opencode-esc-interrupt' => '/\besc\s+(?:again\s+to\s+)?interrupt\b/iu',
                // Retry status line: "... [retrying in 5s attempt #2]".
                'opencode-retrying' => '/\bretrying\s+in\s+\d+|\battempt\s+#\d+/iu',
            ],
            'chrome' => [
                // Message footer: "▣  Build · Nemotron 3 Ultra Free".
                'opencode-message-footer' => '/^▣\s+\S.{0,120}·/u',
                // Input status line: "Build auto · Nemotron 3 Ultra Free OpenCode Zen".
                'opencode-input-status' => '/^[\w-]+(?:\s+[\w-]+)?\s+·\s+\S/u',
            ],
        ];

        return [
            'generic' => [
                // A JSON error body at the start of a line.
                'json-error-type' => '/^\{.{0,300}"type"\s*:\s*"(?:server_error|overloaded_error|api_error|rate_limit_error|timeout_error|service_unavailable(?:_error)?)"/iu',
                // "API Error: 529 ...", "Error: 503 Service Unavailable", "error: 429 Too Many Requests".
                // The status code must be followed by a JSON body or a status word,
                // so "Error: expected 500 to equal 200" in test output does not match.
                'error-status' => '/^(?:API\s*Error|APIError|AI_APICallError|ProviderError|Error)\b[^\p{L}\p{N}]{0,4}.{0,40}?(?<![\d.])(?:429|5(?:0[0-4]|2[0-9]))(?![\d.])(?:\s*\{|.{0,60}?\b(?:overloaded|unavailable|server|internal|gateway|too\s+many|rate|timed?\s*out|upstream))/iu',
                // "Error: model is overloaded", "Error: service temporarily unavailable".
                'error-overloaded' => '/^(?:API\s*Error|APIError|AI_APICallError|Error)\b.{0,120}\b(?:overloaded|temporarily\s+(?:unavailable|overloaded)|service\s+unavailable|bad\s+gateway|gateway\s+time-?out|internal\s+server\s+error|too\s+many\s+requests|rate[\s_-]?limit(?:ed)?)\b/iu',
                // "Error: ECONNRESET", "error: stream disconnected", "API Error (Request timed out.)".
                'error-network' => '/^(?:API\s*Error|APIError|Error)\b.{0,120}\b(?:ECONNRESET|ETIMEDOUT|ECONNREFUSED|EAI_AGAIN|socket\s+hang\s+up|fetch\s+failed|stream\s+(?:error|disconnected|reset|closed)|connection\s+(?:reset|error|closed|refused)|request\s+timed\s+out|timed\s+out|network\s+error)\b/iu',
            ],
            'exclude' => [
                'usage-quota' => '/usage\s+limit|\bquota\b|weekly\s+limit|insufficient[_\s]quota|resource[_\s]exhausted|billing|credit\s+balance|out\s+of\s+credits|upgrade\s+your\s+plan|exceeded\s+your\s+current/iu',
                'auth' => '/invalid[_\s]api[_\s]key|unauthori[sz]ed|authentication|permission[_\s]denied|\b40[13]\b/iu',
                'request-shape' => '/context\s+(?:length|window)|maximum\s+context|prompt\s+is\s+too\s+long|invalid[_\s]request|\b400\b/iu',
            ],
            'busy' => [
                'esc-to-interrupt' => '/\besc(?:ape)?\s+(?:again\s+)?to\s+(?:interrupt|cancel)\b/iu',
                'retrying' => '/\bretrying\s+in\s+\d+|\bretrying\s+\d+\s*\/\s*\d+|\breconnecting\.{0,3}\s*\d+\s*\/\s*\d+/iu',
            ],
            'chrome' => [
                // Idle prompt glyph lines are blank after stripLead(); these are
                // the common footers that follow an error at an idle prompt.
                'shortcuts-hint' => '/^\?\s+for\s+shortcuts\b/iu',
                'context-left' => '/^\d{1,3}%\s+context\s+left\b/iu',
                // A footer whose first column is the working folder, with an optional
                // branch name: OpenCode "/mnt/cache/x", Gemini "~/project (main*)".
                'footer-cwd' => '/^(?:~|\/)\S*(?:\s+\([^)]{0,60}\))?$/u',
            ],
            'agents' => [
                'opencode' => $opencodeTui,
                'kilocode' => $opencodeTui,
                // Claude Code: "⎿  API Error: 529 {"type":"error","error":{"type":"overloaded_error",...}}"
                // after its own retries ("Retrying in 5 seconds… (attempt 3/10)") gave up.
                'claude-code' => [
                    'errors' => [
                        'claude-api-error-status' => '/^API\s+Error:?\s*(?:429|5\d\d)\b/iu',
                        'claude-api-error-network' => '/^API\s+Error\s*\((?:Request\s+timed\s+out|Connection\s+error)[^)]*\)/iu',
                    ],
                    'busy' => [
                        'claude-retrying' => '/\bRetrying\s+in\s+\d+\s*(?:s|sec|seconds?)\b.{0,40}\battempt\s+\d+\s*\/\s*\d+/iu',
                    ],
                    'chrome' => [
                        'claude-mode-footer' => '/^(?:⏵⏵|⏸)\s*\S/u',
                        'claude-auto-compact' => '/\bContext\s+left\s+until\s+auto-compact\b/iu',
                    ],
                ],
                // Codex CLI: final error cell "■ stream disconnected before completion: ..."
                // or "■ exceeded retry limit, last status: 503 Service Unavailable" after
                // its own stream retries ("Reconnecting... 2/5") gave up.
                'codex-cli' => [
                    'errors' => [
                        'codex-stream-disconnected' => '/^stream\s+disconnected\s+before\s+completion\b/iu',
                        'codex-retry-limit' => '/^exceeded\s+retry\s+limit,\s+last\s+status:\s*(?:429|5\d\d)\b/iu',
                        // codex-rs/protocol/src/error.rs: ServerOverloaded and InternalServerError.
                        'codex-at-capacity' => '/^Selected\s+model\s+is\s+at\s+capacity\b/iu',
                        'codex-high-demand' => '/^We(?:\'|’)re\s+currently\s+experiencing\s+high\s+demand\b/iu',
                        'codex-stream-error' => '/^stream\s+error:.{0,160}\b(?:5\d\d|429|overloaded|disconnected|timed\s+out|reset)\b/iu',
                    ],
                    'busy' => [
                        'codex-working' => '/\bWorking\s*\(\d+\s*s\b/iu',
                    ],
                    'chrome' => [
                        'codex-footer' => '/^(?:⏎|Enter)\s+send\b|\bctrl\s*\+\s*j\s+newline\b|^⌃J\s+newline\b/iu',
                    ],
                ],
                // Gemini CLI and its fork Qwen Code: "✕ [API Error: ... 503 ... overloaded ...]".
                'gemini-cli' => [
                    'errors' => [
                        'gemini-api-error' => '/^\[API\s+Error:.{0,200}(?:\b(?:429|5\d\d)\b|overloaded|UNAVAILABLE|INTERNAL|DEADLINE_EXCEEDED|fetch\s+failed|ECONNRESET|timed\s+out|connection\s+error)/iu',
                    ],
                    'busy' => ['gemini-trying-to-reach' => '/\bTrying\s+to\s+reach\b.{0,80}\(Attempt\s+\d+\s*\/\s*\d+\)/iu'],
                    'chrome' => [
                        'gemini-input-placeholder' => '/^Type\s+your\s+message\b/iu',
                        'gemini-footer' => '/\bno\s+sandbox\b|\bcontext\s+left\)/iu',
                    ],
                ],
                'qwen-code' => [
                    'errors' => [
                        'qwen-api-error' => '/^\[API\s+Error:.{0,200}(?:\b(?:429|5\d\d)\b|overloaded|UNAVAILABLE|INTERNAL|DEADLINE_EXCEEDED|fetch\s+failed|ECONNRESET|timed\s+out|connection\s+error|too\s+many\s+requests|throttled|capacity)/iu',
                    ],
                    'busy' => ['qwen-trying-to-reach' => '/\bTrying\s+to\s+reach\b.{0,80}\(Attempt\s+\d+\s*\/\s*\d+\)|\bRetrying\s+(?:with\s+backoff|after\s+explicit\s+delay)\b/iu'],
                    'chrome' => [
                        'qwen-input-placeholder' => '/^Type\s+your\s+message\b/iu',
                        'qwen-footer' => '/\bno\s+sandbox\b|\bcontext\s+left\)/iu',
                    ],
                ],
            ],
        ];
    }

    /**
     * Read the pane capture and decide:
     *   error  the transient error that is the LAST output, or null
     *   busy   the agent shows a working or retrying marker
     *
     * $raw may carry ANSI colour (tmux capture-pane -e); it is reduced to plain
     * text first. Quota detection wins: when QuotaDetectionService recognises
     * the pane, or the error block names a quota, error is null.
     *
     * @return array{error:?array{pattern:string,fingerprint:string,summary:string},busy:bool}
     */
    public static function analyse(string $agentId, string $raw, ?int $now = null): array
    {
        $out = ['error' => null, 'busy' => false];
        if (trim($raw) === '') return $out;
        $plain = TmuxService::plainPaneText(rtrim($raw, "\r\n"));
        $lines = preg_split('/\R/u', $plain) ?: [];
        $lines = array_slice($lines, -self::TAIL_LINES);
        $p = self::patterns();
        $agent = $p['agents'][$agentId] ?? [];

        // Busy or retrying: the markers sit in the bottom rows (footer, status).
        $busy = array_merge($p['busy'], (array)($agent['busy'] ?? []));
        foreach (array_slice($lines, -8) as $line) {
            foreach ($busy as $re) {
                if (PaneInputRules::safeMatch($re, (string)$line)) { $out['busy'] = true; break 2; }
            }
        }

        // A verified quota message always wins over a transient error.
        if (QuotaDetectionService::detect($agentId, implode("\n", $lines), $now ?? time()) !== null) return $out;

        $errors = (array)($agent['errors'] ?? []);
        if (($agent['generic'] ?? true) !== false) $errors = array_merge($errors, $p['generic']);
        $chrome = array_merge($p['chrome'], (array)($agent['chrome'] ?? []));

        // Walk up from the bottom. Skip blank and chrome rows; the first block of
        // contiguous content rows is the newest output. The error must be in it,
        // with at most MAX_CONTINUATION_LINES wrapped rows below it.
        $block = [];
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $text = self::stripLead(self::primaryColumn((string)$lines[$i]));
            // A busy or retrying status row is chrome too: it is not new output,
            // and $out['busy'] already says the agent is not idle.
            $isQuiet = !preg_match('/[\p{L}\p{N}]/u', $text) || self::matchesAny($chrome, $text) !== null || self::matchesAny($busy, $text) !== null;
            if ($isQuiet) {
                if ($block !== []) break;
                continue;
            }
            array_unshift($block, $text);
            if (count($block) > self::MAX_CONTINUATION_LINES + 4) break;
        }
        if ($block === []) return $out;

        $hitAt = -1; $hitId = '';
        $widest = 0;
        foreach ($block as $text) $widest = max($widest, mb_strlen(rtrim($text)));
        foreach ($block as $k => $text) {
            // An error box narrower than its message WRAPS: OpenCode's
            // {"message":"...[503] ... Nvidia:" / "Service ... overloaded","type":"server_error"}
            // put "type" on the next row, and a one-row match missed it, so no
            // Continue was scheduled (tool-essential, 2026-09-24). A row that
            // fills the width of the block is matched joined with the rows under
            // it; the '^' anchor still pins the start of the error to the start
            // of a row. A SHORT row did not wrap, so it is never joined: two
            // unrelated short rows of code output ("Error: expected 500 …" over
            // "log: API Error: 529 overloaded …") must not read as one error.
            $id = self::matchesAny($errors, $text);
            if ($id === null && $k < count($block) - 1 && self::rowLooksWrapped($text, $widest)) {
                $joined = implode(' ', array_slice($block, $k, self::MAX_CONTINUATION_LINES + 1));
                $id = self::matchesAny($errors, $joined);
            }
            if ($id !== null) { $hitAt = $k; $hitId = $id; }
        }
        if ($hitAt < 0) return $out;
        if (count($block) - 1 - $hitAt > self::MAX_CONTINUATION_LINES) return $out; // newer output below it

        $errorBlock = implode(' ', array_slice($block, $hitAt));
        if (self::matchesAny($p['exclude'], $errorBlock) !== null) return $out;

        $summary = self::summarise($errorBlock);
        $code = preg_match('/(?<![\d.])(429|5\d\d)(?![\d.])/', $errorBlock, $m) ? $m[1] : 'none';
        $out['error'] = [
            'pattern' => $hitId,
            'fingerprint' => substr(hash('sha256', self::SOURCE . '|' . $agentId . '|' . $hitId . '|' . $code), 0, 16),
            'summary' => $summary,
        ];
        return $out;
    }

    /**
     * True when $text reaches (nearly) the widest row of its block, i.e. the
     * terminal broke it at the box edge. `WRAP_SLACK` allows for a break at a
     * word boundary a few columns short of the edge.
     */
    private static function rowLooksWrapped(string $text, int $widest): bool
    {
        $len = mb_strlen(rtrim($text));
        return $len >= self::WRAP_MIN_WIDTH && $len >= $widest - self::WRAP_SLACK;
    }

    /**
     * The text typed into the pane. $summary is re-sanitised here, because the
     * value comes back from the schedule sidecar at send time.
     */
    public static function continuePrompt(string $summary): string
    {
        $summary = self::sanitise($summary);
        $what = $summary !== '' ? " ($summary)" : '';
        return "The previous model call failed with a temporary provider error$what. Continue from where you stopped.";
    }

    /**
     * Unit-testable policy for one supervisor tick. Callbacks:
     *   $capture(agentId, sid): string           bounded raw pane tail
     *   $paneIdle(agentId, raw): bool            the readiness classifier's verdict
     *   $existing(sid): ?array                   the scheduled-continue entry, or null
     *   $schedule(sid, at, entry): bool          write a transient-error entry
     *   $clear(sid): void                        remove the entry
     *   $record(sid, agentId, event, info): void Activity tray + event ledger
     * $state is this service's per-session incident state; the new state is returned.
     *
     * @param array<int,array{id:string,agentId:string}> $sessions
     * @param array{mode:string,backoff:array<int,int>,max:int} $settings
     * @param array<string,array<string,mixed>> $state
     * @return array{state:array<string,array<string,mixed>>,scheduled:int,cleared:int,exhausted:int,decisions:array<string,string>}
     */
    public static function pollSessions(
        array $sessions,
        callable $capture,
        callable $paneIdle,
        callable $existing,
        callable $schedule,
        callable $clear,
        callable $record,
        array $state,
        array $settings,
        ?int $now = null
    ): array {
        $now ??= time();
        $result = ['state' => [], 'scheduled' => 0, 'cleared' => 0, 'exhausted' => 0, 'decisions' => []];
        $backoff = array_values(array_filter(array_map('intval', (array)($settings['backoff'] ?? [])), static fn(int $s): bool => $s > 0));
        if ($backoff === []) $backoff = array_map(static fn(int $m): int => $m * 60, self::DEFAULT_BACKOFF_MINUTES);
        $max = max(0, (int)($settings['max'] ?? self::DEFAULT_MAX_CONTINUES));

        foreach ($sessions as $session) {
            $sid = trim((string)($session['id'] ?? ''));
            $agentId = trim((string)($session['agentId'] ?? ''));
            if ($sid === '' || $agentId === '') continue;
            $st = is_array($state[$sid] ?? null) ? $state[$sid] : null;
            $entry = $existing($sid);
            $ours = is_array($entry) && ($entry['source'] ?? '') === self::SOURCE;

            if (!self::enabledFor($agentId, $settings)) {
                if ($ours) { $clear($sid); $result['cleared']++; }
                $result['decisions'][$sid] = 'disabled';
                continue;
            }

            // A pending plan that vanished was either sent (recordSent() moved it
            // to 'sent') or removed by the operator, who took over this incident.
            if ($st !== null && ($st['phase'] ?? '') === 'pending' && !$ours) {
                $st['phase'] = 'cancelled';
            }

            $raw = (string)$capture($agentId, $sid);
            if ($raw === '') { if ($st !== null) $result['state'][$sid] = $st; continue; }
            $a = self::analyse($agentId, $raw, $now);
            $idle = !$a['busy'] && (bool)$paneIdle($agentId, $raw);
            $err = $a['error'];

            if ($err === null) {
                $phase = (string)($st['phase'] ?? '');
                if ($phase === 'pending') {
                    // The error is no longer the last output: new output or a quota
                    // message replaced it. Drop the plan.
                    if ($ours) { $clear($sid); $result['cleared']++; }
                    $record($sid, $agentId, 'reset', ['fingerprint' => (string)($st['fp'] ?? '')]);
                    $result['decisions'][$sid] = 'reset';
                    continue;
                }
                if ($phase === 'sent' && !$idle) {
                    // The agent works on the continue. Keep the incident open, so the
                    // same error after this turn counts toward the cap.
                    $result['state'][$sid] = $st;
                    $result['decisions'][$sid] = 'working';
                    continue;
                }
                if ($st !== null) $result['decisions'][$sid] = 'reset';
                continue; // idle with newer output, or never an incident: nothing to keep
            }

            if ($st !== null && (string)($st['fp'] ?? '') !== $err['fingerprint']) {
                // A different error is a new incident.
                if ($ours) { $clear($sid); $result['cleared']++; $ours = false; }
                $st = null;
            }
            $phase = (string)($st['phase'] ?? '');

            if (in_array($phase, ['pending', 'exhausted', 'cancelled'], true)) {
                $result['state'][$sid] = $st;
                $result['decisions'][$sid] = $phase;
                continue;
            }
            if (!$idle) {
                if ($st !== null) $result['state'][$sid] = $st;
                $result['decisions'][$sid] = 'not-idle';
                continue;
            }
            if (is_array($entry) && !$ours) {
                // A quota or operator schedule is pending: never compete with it.
                if ($st !== null) $result['state'][$sid] = $st;
                $result['decisions'][$sid] = 'other-schedule';
                continue;
            }

            $attempts = (int)($st['attempts'] ?? 0);
            if ($attempts >= $max) {
                $st = ['fp' => $err['fingerprint'], 'agent' => $agentId, 'attempts' => $attempts, 'phase' => 'exhausted', 'summary' => $err['summary'], 'updated' => $now];
                $record($sid, $agentId, 'exhausted', ['attempts' => $attempts, 'summary' => $err['summary'], 'fingerprint' => $err['fingerprint']]);
                $result['exhausted']++;
                $result['state'][$sid] = $st;
                $result['decisions'][$sid] = 'exhausted';
                continue;
            }

            $delay = $backoff[min($attempts, count($backoff) - 1)];
            $at = $now + $delay;
            $newEntry = [
                'at' => $at,
                'repeat' => 'none',
                'source' => self::SOURCE,
                'created_at' => $now,
                'last_fired_at' => 0,
                'fingerprint' => $err['fingerprint'],
                'error_summary' => $err['summary'],
                'attempt' => $attempts + 1,
                'max' => $max,
            ];
            if (!$schedule($sid, $at, $newEntry)) {
                if ($st !== null) $result['state'][$sid] = $st;
                $result['decisions'][$sid] = 'schedule-failed';
                continue;
            }
            $st = ['fp' => $err['fingerprint'], 'agent' => $agentId, 'attempts' => $attempts + 1, 'phase' => 'pending', 'at' => $at, 'summary' => $err['summary'], 'updated' => $now];
            $record($sid, $agentId, 'scheduled', ['at' => $at, 'attempt' => $attempts + 1, 'max' => $max, 'delay' => $delay, 'summary' => $err['summary'], 'fingerprint' => $err['fingerprint']]);
            $result['scheduled']++;
            $result['state'][$sid] = $st;
            $result['decisions'][$sid] = 'scheduled';
        }
        return $result;
    }

    /**
     * Send-time check, used by scheduled-continue.php for a due transient-error
     * entry. The pane must still show the SAME error as its last output:
     *   'send'   the error is still the newest output
     *   'clear'  the error is gone or changed; drop the entry without sending
     *   'wait'   the agent shows a working or retrying marker; try next tick
     */
    public static function sendDecision(string $agentId, string $raw, array $entry, ?int $now = null): string
    {
        $a = self::analyse($agentId, $raw, $now);
        if ($a['error'] === null) return $a['busy'] ? 'wait' : 'clear';
        if ($a['error']['fingerprint'] !== (string)($entry['fingerprint'] ?? '')) return 'clear';
        return $a['busy'] ? 'wait' : 'send';
    }

    /** @param array{mode?:string} $settings */
    public static function enabledFor(string $agentId, array $settings): bool
    {
        $mode = (string)($settings['mode'] ?? 'recommended');
        if ($mode === 'off') return false;
        if ($mode === 'all') return true;
        if (in_array($agentId, self::RECOMMENDED_AGENTS, true)) return true;
        // AUTO_CONTINUE_PATTERNS.md: an agent the operator wrote an error or quota
        // pattern for is opted in by that choice.
        try { return AutoContinueRules::agentOptedIn($agentId); } catch (\Throwable $e) { return false; }
    }

    /**
     * The three Manager settings, read defensively.
     * @return array{mode:string,backoff:array<int,int>,max:int}
     */
    public static function settings(?array $cfg = null): array
    {
        $cfg ??= ConfigService::getConfig();
        $mode = strtolower(trim((string)($cfg['transient_error_continue'] ?? 'recommended')));
        if (!in_array($mode, self::MODES, true)) $mode = 'recommended';
        $minutes = [];
        foreach (preg_split('/[\s,;]+/', (string)($cfg['transient_error_backoff_minutes'] ?? '')) ?: [] as $v) {
            if ($v === '' || !ctype_digit($v)) continue;
            $minutes[] = max(1, min(240, (int)$v));
        }
        if ($minutes === []) $minutes = self::DEFAULT_BACKOFF_MINUTES;
        $maxRaw = trim((string)($cfg['transient_error_max_continues'] ?? ''));
        $max = ($maxRaw !== '' && ctype_digit($maxRaw)) ? min(10, (int)$maxRaw) : self::DEFAULT_MAX_CONTINUES;
        return ['mode' => $mode, 'backoff' => array_map(static fn(int $m): int => $m * 60, $minutes), 'max' => $max];
    }

    /**
     * Live tick. $captures lets QuotaDetectionService::pollAll() share the pane
     * captures it already took, so each session is captured once per tick.
     *
     * @param array<int,array{id:string,agentId:string}> $sessions
     * @param array<string,string> $captures
     */
    public static function pollAll(array $sessions, array $captures = []): array
    {
        $settings = self::settings();
        $names = [];
        foreach ((ConfigService::getWorkspaces()['sessions'] ?? []) as $s) {
            if (is_array($s) && isset($s['id'])) $names[(string)$s['id']] = (string)($s['name'] ?? $s['id']);
        }
        $map = ConfigService::getScheduledContinueMap();
        $result = self::pollSessions(
            $sessions,
            static fn(string $agentId, string $sid): string => $captures[$sid] ?? TmuxService::capturePaneRaw($agentId, $sid),
            static fn(string $agentId, string $raw): bool => !empty(TmuxService::paneStateFromCapture($raw, $agentId)['ready']),
            static fn(string $sid): ?array => isset($map[$sid]) && is_array($map[$sid]) ? $map[$sid] : null,
            static function (string $sid, int $at, array $entry): bool {
                return ConfigService::setScheduledContinue($sid, $entry);
            },
            static function (string $sid): void { ConfigService::clearScheduledContinue($sid); },
            static function (string $sid, string $agentId, string $event, array $info) use ($names): void {
                self::record($sid, $names[$sid] ?? $sid, $agentId, $event, $info);
            },
            self::readState(),
            $settings
        );
        self::writeState($result['state']);
        return $result;
    }

    /** scheduled-continue.php calls this after it typed the continue into the pane. */
    public static function recordSent(string $sid, string $name, string $agentId, array $entry): void
    {
        $state = self::readState();
        if (is_array($state[$sid] ?? null)) {
            $state[$sid]['phase'] = 'sent';
            $state[$sid]['updated'] = time();
            self::writeState($state);
        }
        self::record($sid, $name, $agentId, 'sent', [
            'attempt' => (int)($entry['attempt'] ?? 0),
            'max' => (int)($entry['max'] ?? self::DEFAULT_MAX_CONTINUES),
            'summary' => (string)($entry['error_summary'] ?? ''),
            'fingerprint' => (string)($entry['fingerprint'] ?? ''),
        ]);
    }

    /** scheduled-continue.php calls this when the send-time check dropped the entry. */
    public static function recordDropped(string $sid, string $name, string $agentId): void
    {
        $state = self::readState();
        if (isset($state[$sid])) { unset($state[$sid]); self::writeState($state); }
        self::record($sid, $name, $agentId, 'reset', []);
    }

    /**
     * Activity tray + event ledger. Best-effort: a failure here never stops the
     * tick. The tray entry id is per workspace, so one row tells the story.
     */
    public static function record(string $sid, string $name, string $agentId, string $event, array $info): void
    {
        $opId = 'transient_cont_' . preg_replace('/[^A-Za-z0-9_.-]/', '', $sid);
        $summary = self::sanitise((string)($info['summary'] ?? ''));
        $max = (int)($info['max'] ?? self::DEFAULT_MAX_CONTINUES);
        try {
            if ($event === 'scheduled') {
                $mins = max(1, (int)round(((int)($info['delay'] ?? 60)) / 60));
                ActivityService::register($opId, 'system', "Automatic continue for $name after a temporary model error", ['meta' => ['workspaceId' => $sid, 'agentId' => $agentId]]);
                ActivityService::wait($opId, "Continue " . (int)($info['attempt'] ?? 1) . " of $max planned in $mins min" . ($summary !== '' ? " ($summary)" : ''));
            } elseif ($event === 'sent') {
                ActivityService::update($opId, ['type' => 'system', 'label' => "Automatic continue for $name after a temporary model error"]);
                ActivityService::finish($opId, 'Continue ' . (int)($info['attempt'] ?? 1) . " of $max sent");
            } elseif ($event === 'exhausted') {
                ActivityService::fail(
                    $opId,
                    "Needs you: $name stopped again after $max automatic continues" . ($summary !== '' ? " ($summary)" : '') . '.',
                    'Open the workspace, check the model provider, and continue by hand.',
                    ['type' => 'system', 'label' => "Automatic continue for $name after a temporary model error"]
                );
            } elseif ($event === 'reset') {
                if (ActivityService::get($opId) !== null) ActivityService::finish($opId, 'Not needed: the workspace moved on');
            }
        } catch (\Throwable $e) {
            // Advisory only.
        }
        if (class_exists('\\AICliAgents\\Services\\EventLedger')) {
            try {
                EventLedger::append(
                    'workspace.transient_error_continue',
                    ['id' => $sid, 'agentId' => $agentId],
                    "Transient model error auto-continue: $event for '$name'",
                    array_merge(['event' => $event], array_intersect_key($info, array_flip(['at', 'attempt', 'max', 'delay', 'fingerprint', 'attempts'])))
                );
            } catch (\Throwable $e) {
                // Advisory only.
            }
        }
    }

    /**
     * AUTO_CONTINUE_PATTERNS.md: the text an error or chrome pattern is matched
     * against — the row's main column with borders and bullets removed. The
     * Settings sample check uses it, so a sample matches as the detector would.
     */
    public static function detectorLine(string $line): string
    {
        return self::stripLead(self::primaryColumn($line));
    }

    /** Cut a TUI row at the first wide gap, so a sidebar column is not read as output. */
    private static function primaryColumn(string $line): string
    {
        $line = rtrim($line);
        if (preg_match('/^(\s*\S.*?)\s{6,}\S/u', $line, $m)) return rtrim($m[1]);
        return $line;
    }

    /** Remove leading borders, prompt glyphs and bullets ("┃", "⎿", "■", "✕", "❯"). */
    private static function stripLead(string $text): string
    {
        return trim((string)preg_replace('/^[\s\x{2500}-\x{259F}•●⏺⎿■□▪✗✕✘×⚠❯›>]+|^\x{1F590}\x{FE0F}?\s*/u', '', $text));
    }

    /** @param array<string,string> $patterns */
    private static function matchesAny(array $patterns, string $text): ?string
    {
        foreach ($patterns as $id => $re) {
            if (PaneInputRules::safeMatch((string)$re, $text)) return (string)$id;
        }
        return null;
    }

    private static function summarise(string $block): string
    {
        $decoded = json_decode($block, true);
        if (is_array($decoded)) {
            $msg = $decoded['message'] ?? ($decoded['error']['message'] ?? null);
            if (is_string($msg) && $msg !== '') $block = $msg;
        } elseif (preg_match('/"message"\s*:\s*"([^"]{1,300})"/u', $block, $m)) {
            $status = preg_match('/(?<![\d.])(429|5\d\d)(?![\d.])/', $block, $c) ? $c[1] . ' ' : '';
            $block = $status . $m[1];
        }
        return self::sanitise($block);
    }

    /** Plain words only: this text is typed into an agent pane. */
    private static function sanitise(string $text): string
    {
        $text = (string)preg_replace('/[^A-Za-z0-9 .,:;()\[\]\/\'_-]+/', ' ', $text);
        $text = trim((string)preg_replace('/\s+/', ' ', $text));
        if (strlen($text) > 120) $text = rtrim(substr($text, 0, 117)) . '...';
        return $text;
    }

    private static function statePath(): string
    {
        $env = getenv('AICLI_TRANSIENT_ERROR_STATE');
        return ($env !== false && $env !== '') ? $env : self::STATE_PATH;
    }

    /** @return array<string,array<string,mixed>> */
    private static function readState(): array
    {
        $path = self::statePath();
        if (!is_file($path)) return [];
        $data = json_decode((string)@file_get_contents($path), true);
        return is_array($data['sessions'] ?? null) ? $data['sessions'] : [];
    }

    /** @param array<string,array<string,mixed>> $sessions */
    private static function writeState(array $sessions): void
    {
        $path = self::statePath();
        @mkdir(dirname($path), 0755, true);
        AtomicWriteService::writeJson($path, ['schema' => 1, 'sessions' => $sessions]);
    }
}
