<?php

declare(strict_types=1);

namespace AICliAgents\Services;

/**
 * SCHEDULED_CONTINUE.md / Forgejo #288.
 *
 * The browser can only see the selected terminal. The supervisor uses this
 * service to inspect the bounded tail of every live session, so a quota reset
 * in one workspace can schedule the other running sessions for the same agent
 * too. Only parsed metadata and a short fingerprint are persisted; terminal
 * output is never written to the state file.
 */
final class QuotaDetectionService
{
    public const POLL_INTERVAL_SECONDS = 30;
    public const CODEX_FALLBACK_SECONDS = 5 * 60 * 60;
    private const STATE_PATH = '/tmp/unraid-aicliagents/quota-detection.json';

    private const CODEX_QUOTA_MESSAGE = '~(?:you(?:\'|’)?ve\s+hit\s+your\s+usage\s+limit|usage\s+limit(?:\s+(?:reached|exceeded)\b|(?=\s*(?:[.!?]|$)))|quota\s+(?:reached|exceeded)\b)~iu';
    private const CODEX_CLOCK_RESET = '~(?:try\s+again|reset|resets)\s+(?:at\s+)?(\d{1,2})(?::(\d{2}))?\s*([ap])\.?m\.?(?:\s+\(([^)]+)\))?~iu';
    private const CODEX_DATE_RESET = '~(?:try\s+again|reset|resets)\s+(?:at\s+)?([A-Z][a-z]{2})\s+(\d{1,2}),\s*(\d{1,2})(?::(\d{2}))?\s*([ap])\.?m\.?(?:\s+\(([^)]+)\))?~iu';
    private const CLAUDE_RESET = '~You\'ve\s+hit\s+your\s+weekly\s+limit\s*·\s*resets\s+([A-Z][a-z]{2})\s+(\d{1,2}),\s*(\d{1,2})(?::(\d{2}))?\s*([ap])m\s+\(([^)]+)\)~iu';
    private const CLAUDE_NATIVE_WAIT = '~Claude\s+Code\s+will\s+continue\s+automatically\s+at\s+([A-Z][a-z]{2})\s+(\d{1,2}),\s*(\d{1,2})(?::(\d{2}))?\s*([ap])m(?:\s+\(([^)]+)\))?~iu';
    private const CLAUDE_NATIVE_CANCELLED = '~Automatic\s+continue\s+cancelled~iu';

    /**
     * OpenCode and its fork Kilo (SCHEDULED_CONTINUE.md, 2026-09-26). A usage
     * quota shows on the status line under the input box, and the TUI wraps it:
     *   "■Free⬝usage exceeded, subscribe to Go [retry" / "in 1h 21m attempt #1]"
     * (real capture: tests/fixtures/quota/opencode-free-usage-exceeded.txt).
     * OPENCODE_QUOTA finds the quota words on one row; OPENCODE_RETRY reads the
     * countdown after them from the rows joined with single spaces.
     */
    public const OPENCODE_AGENTS = ['opencode', 'kilocode'];
    /** The Continue is planned this long after the printed wait, so OpenCode's own retry goes first. */
    public const OPENCODE_MARGIN_SECONDS = 120;
    private const OPENCODE_QUOTA = '~(?:\bfree\W{0,3}usage\s+(?:exceeded|limit\s+reached)|\busage\s+(?:limit\s+)?exceeded|\bquota\s+exceeded|\busage\s+limit\s+reached)\b~iu';
    private const OPENCODE_RETRY = '~^[^\[]{0,200}?\[\s*retry(?:ing)?\s+in\s+((?:\d{1,4}\s*[dhms]\s*){1,4})(?:attempt\s+\#\s*\d+\s*)?\]~iu';

    /**
     * Parse only provider output that has been verified in fixtures. Other
     * agents deliberately return null rather than guessing from generic rate
     * limit wording.
     *
     * @return array{source:string,reason:string,fingerprint:string,at?:int,nativeState?:string,label?:string}|null
     */
    public static function detect(string $agentId, string $text, ?int $now = null): ?array
    {
        $now ??= time();
        $builtin = self::detectBuiltin($agentId, $text, $now);
        if ($builtin !== null) return $builtin;
        // AUTO_CONTINUE_PATTERNS.md: the operator's own quota patterns come after
        // the verified built-in parsers, never before them.
        try {
            return AutoContinueRules::quotaDetect($agentId, $text, $now);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * AUTO_CONTINUE_PATTERNS.md: the built-in quota expressions for the
     * Settings list (read-only there).
     * @return array<int,array{agent:string,kind:string,id:string,re:string}>
     */
    public static function builtinPatterns(): array
    {
        $rows = [
            ['agent' => 'codex-cli', 'kind' => 'quota', 'id' => 'codex-quota-message', 're' => self::CODEX_QUOTA_MESSAGE],
            ['agent' => 'codex-cli', 'kind' => 'quota', 'id' => 'codex-clock-reset', 're' => self::CODEX_CLOCK_RESET],
            ['agent' => 'codex-cli', 'kind' => 'quota', 'id' => 'codex-date-reset', 're' => self::CODEX_DATE_RESET],
            ['agent' => 'claude-code', 'kind' => 'quota', 'id' => 'claude-weekly-reset', 're' => self::CLAUDE_RESET],
            ['agent' => 'claude-code', 'kind' => 'quota', 'id' => 'claude-native-wait', 're' => self::CLAUDE_NATIVE_WAIT],
        ];
        foreach (self::OPENCODE_AGENTS as $a) {
            $rows[] = ['agent' => $a, 'kind' => 'quota', 'id' => 'opencode-usage-quota', 're' => self::OPENCODE_QUOTA];
            $rows[] = ['agent' => $a, 'kind' => 'quota', 'id' => 'opencode-retry-countdown', 're' => self::OPENCODE_RETRY];
        }
        return $rows;
    }

    /** The verified built-in parsers only. */
    private static function detectBuiltin(string $agentId, string $text, int $now): ?array
    {
        if ($agentId === 'claude-code') return self::detectClaude($text, $now);
        if (in_array($agentId, self::OPENCODE_AGENTS, true)) return self::detectOpencode($text, $now);
        if ($agentId !== 'codex-cli' || !preg_match(self::CODEX_QUOTA_MESSAGE, $text)) return null;

        if (preg_match(self::CODEX_DATE_RESET, $text, $m)) {
            $at = self::namedDateTime($now, (string)$m[1], (int)$m[2], (int)$m[3], (int)($m[4] ?: 0), (string)$m[5], (string)($m[6] ?? ''));
            if ($at !== null) {
                return [
                    'source' => 'quota-detect',
                    'at' => $at,
                    'reason' => 'Codex reported an explicit retry time.',
                    'fingerprint' => self::fingerprint($text, 'codex-date-reset'),
                ];
            }
        }

        if (preg_match(self::CODEX_CLOCK_RESET, $text, $m)) {
            $at = self::nextClockTime($now, (int)$m[1], (int)($m[2] ?: 0), (string)$m[3], (string)($m[4] ?? ''));
            if ($at !== null) {
                return [
                    'source' => 'quota-detect',
                    'at' => $at,
                    'reason' => 'Codex reported an explicit retry time.',
                    'fingerprint' => self::fingerprint($text, 'codex-clock-reset'),
                ];
            }
        }

        return [
            'source' => 'quota-detect',
            'at' => $now + self::CODEX_FALLBACK_SECONDS,
            'reason' => 'Codex reported a usage limit without an explicit retry time; using the five-hour fallback.',
            'fingerprint' => self::fingerprint($text, 'codex-fallback'),
        ];
    }

    /**
     * Unit-testable fan-out policy. `$capture` returns a bounded pane tail;
     * `$existing` returns the existing schedule (or null); `$schedule` and
     * `$clear` perform the side effects in the live supervisor path.
     *
     * @param array<int,array{id:string,agentId:string}> $sessions
     * @return array{polled:int,detected:int,native_owned:int,scheduled:int,cleared:int,detections:array<string,array<string,mixed>>}
     */
    public static function pollSessions(
        array $sessions,
        callable $capture,
        callable $existing,
        callable $schedule,
        callable $clear,
        ?int $now = null
    ): array {
        $now ??= time();
        $result = [
            'polled' => 0,
            'detected' => 0,
            'native_owned' => 0,
            'scheduled' => 0,
            'cleared' => 0,
            'detections' => [],
        ];
        $byAgent = [];
        $detectionsByAgent = [];
        $sessionOnly = [];

        foreach ($sessions as $session) {
            $sid = trim((string)($session['id'] ?? ''));
            $agentId = trim((string)($session['agentId'] ?? ''));
            if ($sid === '' || $agentId === '') continue;
            $result['polled']++;
            $byAgent[$agentId][] = $sid;
            $pane = (string)$capture($agentId, $sid);
            if ($pane === '') continue;

            $detected = self::detect($agentId, $pane, $now);
            if ($detected === null) continue;
            $result['detected']++;
            $detected['session_id'] = $sid;
            $detected['agent_id'] = $agentId;
            $detected['seen_at'] = $now;
            $result['detections'][$sid] = $detected;

            if (($detected['source'] ?? '') === 'native-agent-owned') {
                $result['native_owned']++;
                if (($detected['nativeState'] ?? '') === 'cancelled') {
                    $entry = $existing($sid);
                    if (is_array($entry) && ($entry['source'] ?? '') === 'quota-detect') {
                        $clear($sid);
                        $result['cleared']++;
                    }
                }
                continue;
            }
            // An OpenCode quota belongs to the selected provider and model, not
            // to the agent: schedule only the session that shows it.
            if (($detected['scope'] ?? '') === 'session') {
                $sessionOnly[$sid] = $detected;
                continue;
            }
            if (!isset($detectionsByAgent[$agentId])) $detectionsByAgent[$agentId] = $detected;
        }

        foreach ($sessionOnly as $sid => $detected) {
            $at = (int)($detected['at'] ?? 0);
            if ($at <= $now) continue;
            $entry = $existing($sid);
            if (is_array($entry) && (int)($entry['at'] ?? 0) > $now && ($entry['source'] ?? '') !== TransientErrorService::SOURCE) continue;
            if ($schedule($sid, $at, (string)($detected['reason'] ?? 'Quota reset detected.'), (string)($detected['fingerprint'] ?? ''))) $result['scheduled']++;
        }

        // A provider account normally applies its quota to every session for
        // that agent. Once one running session gives us a verified reset, fan
        // the same one-shot schedule out to every sibling that is running.
        foreach ($detectionsByAgent as $agentId => $detected) {
            $at = (int)($detected['at'] ?? 0);
            if ($at <= $now) continue;
            foreach ($byAgent[$agentId] ?? [] as $sid) {
                $ownDetection = $result['detections'][$sid] ?? null;
                if (is_array($ownDetection) && ($ownDetection['source'] ?? '') === 'native-agent-owned') continue;
                $entry = $existing($sid);
                // #312: a quota reset wins over a planned transient-error continue,
                // so only a pending quota or operator schedule is kept.
                if (is_array($entry) && (int)($entry['at'] ?? 0) > $now && ($entry['source'] ?? '') !== TransientErrorService::SOURCE) continue;
                $scheduled = $schedule($sid, $at, (string)($detected['reason'] ?? 'Quota reset detected.'), (string)($detected['fingerprint'] ?? ''));
                if ($scheduled) $result['scheduled']++;
            }
        }

        return $result;
    }

    /** Run the live poll for every currently running session. */
    public static function pollAll(): array
    {
        $interval = self::POLL_INTERVAL_SECONDS;
        $last = self::readState()['last_poll_at'] ?? 0;
        $now = time();
        if ((int)$last > 0 && ($now - (int)$last) < $interval) {
            return ['skipped' => true, 'interval' => $interval, 'polled' => 0, 'detected' => 0, 'scheduled' => 0, 'native_owned' => 0, 'cleared' => 0];
        }

        $sessions = self::runningSessions();
        $map = ConfigService::getScheduledContinueMap();
        // #312: capture each pane once; the transient-error pass reuses it.
        $captures = [];
        $result = self::pollSessions(
            $sessions,
            static function (string $agentId, string $sid) use (&$captures): string {
                return $captures[$sid] = TmuxService::capturePaneRaw($agentId, $sid);
            },
            static fn(string $sid): ?array => isset($map[$sid]) && is_array($map[$sid]) ? $map[$sid] : null,
            static function (string $sid, int $at, string $reason, string $fingerprint): bool {
                $r = AdminService::setScheduledContinue($sid, $at, 'none', $reason, 'quota-detect');
                return !isset($r['error']);
            },
            static function (string $sid): void { AdminService::clearScheduledContinue($sid); },
            $now
        );

        $state = self::readState();
        $state['last_poll_at'] = $now;
        foreach ($result['detections'] as $sid => $detection) {
            $state['sessions'][$sid] = [
                'agent_id' => (string)($detection['agent_id'] ?? ''),
                'state' => (string)($detection['nativeState'] ?? $detection['source'] ?? ''),
                'reset_at' => isset($detection['at']) ? (int)$detection['at'] : null,
                'fingerprint' => (string)($detection['fingerprint'] ?? ''),
                'seen_at' => (int)($detection['seen_at'] ?? $now),
            ];
        }
        self::writeState($state);

        // TRANSIENT_ERROR_AUTO_CONTINUE.md (#312): the second detection kind. It
        // runs AFTER the quota pass, so a quota schedule written above is seen as
        // "another schedule" and is never replaced.
        try {
            $transient = TransientErrorService::pollAll($sessions, $captures);
            $result['transient_scheduled'] = (int)($transient['scheduled'] ?? 0);
            $result['transient_exhausted'] = (int)($transient['exhausted'] ?? 0);
        } catch (\Throwable $e) {
            // Advisory: never disturb the quota result.
        }
        $result['interval'] = $interval;
        return $result;
    }

    /** @return array<int,array{id:string,agentId:string}> */
    public static function runningSessions(): array
    {
        $out = [];
        $seen = [];
        // AdminService's schedule sidecar is scoped to the configured terminal
        // user's home. Use the same workspace registry here so a live session
        // belonging to another Unraid user cannot be captured and then fail
        // noisily when the scheduler tries to write into this user's sidecar.
        $workspaces = ConfigService::getWorkspaces();
        $known = [];
        foreach (($workspaces['sessions'] ?? []) as $session) {
            if (!is_array($session)) continue;
            $sid = trim((string)($session['id'] ?? ''));
            $agentId = trim((string)($session['agentId'] ?? ''));
            if ($sid !== '' && $agentId !== '') $known[$sid] = $agentId;
        }
        foreach (TerminalService::listLiveSessions() as $session) {
            $sid = trim((string)($session['id'] ?? ''));
            $agentId = trim((string)($session['agentId'] ?? ''));
            if ($sid === '' || $agentId === '' || isset($seen[$sid]) || ($known[$sid] ?? $agentId) !== $agentId || !isset($known[$sid])) continue;
            $seen[$sid] = true;
            $out[] = ['id' => $sid, 'agentId' => $agentId];
        }
        foreach (($workspaces['sessions'] ?? []) as $session) {
            if (!is_array($session)) continue;
            $sid = trim((string)($session['id'] ?? ''));
            $agentId = trim((string)($session['agentId'] ?? ''));
            if ($sid === '' || $agentId === '' || isset($seen[$sid]) || !ProcessManager::isRunning($sid)) continue;
            $seen[$sid] = true;
            $out[] = ['id' => $sid, 'agentId' => $agentId];
        }
        return $out;
    }

    /**
     * OpenCode/Kilo: a quota line with a "[retry in …]" countdown must be the
     * newest output. The countdown is relative, so the reset time is the
     * capture time plus the countdown. The Continue is planned
     * OPENCODE_MARGIN_SECONDS after that, so OpenCode's own retry goes first.
     */
    private static function detectOpencode(string $text, int $now): ?array
    {
        $q = self::opencodeQuota($text);
        if ($q === null || $q['newer'] || $q['retry'] === null) return null;
        return [
            'source' => 'quota-detect',
            'scope' => 'session',
            'at' => $now + $q['retry'] + self::OPENCODE_MARGIN_SECONDS,
            'reset_at' => $now + $q['retry'],
            'reason' => 'OpenCode reported a usage quota and retries in ' . $q['label'] . '; a Continue follows if its own retry does not resume the task.',
            'fingerprint' => self::fingerprint($q['line'], 'opencode-usage-quota'),
            'label' => $q['label'],
        ];
    }

    /**
     * Send-time policy for an OpenCode/Kilo quota schedule:
     *   'wait'   OpenCode's own "[retry in …]" wait is on screen; it owns the
     *            retry, so keep the entry and look again on a later tick.
     *   'clear'  the task resumed: the quota line is gone, newer output follows
     *            it, or the agent is busy. Drop the Continue.
     *   'send'   the quota line is still the newest output, with no countdown
     *            and no busy marker: OpenCode gave up, so type the Continue.
     * The readiness gate (paneAcceptsInput) still decides "idle at its prompt".
     * Any other schedule returns 'send' (no extra check).
     */
    public static function sendDecision(string $agentId, string $raw, array $entry, ?int $now = null): string
    {
        if (!self::hasSendDecision($agentId, $entry)) return 'send';
        $q = self::opencodeQuota($raw);
        if ($q === null || $q['newer']) return 'clear';
        if ($q['retry'] !== null) return 'wait';
        $lines = preg_split('/\R/u', TmuxService::plainPaneText(rtrim($raw, "\r\n"))) ?: [];
        $p = TransientErrorService::patterns();
        $busy = [$p['busy']['esc-to-interrupt'], $p['agents']['opencode']['busy']['opencode-esc-interrupt']];
        foreach (array_slice($lines, -8) as $line) {
            foreach ($busy as $re) {
                if (PaneInputRules::safeMatch($re, (string)$line)) return 'clear';
            }
        }
        return 'send';
    }

    /** True for a detected OpenCode/Kilo quota schedule, which is checked again at send time. */
    public static function hasSendDecision(string $agentId, array $entry): bool
    {
        return in_array($agentId, self::OPENCODE_AGENTS, true) && ($entry['source'] ?? '') === 'quota-detect';
    }

    /**
     * The LAST OpenCode quota message in the bottom rows, or null.
     *   line   the message, rows joined, countdown removed (fingerprint input)
     *   retry  seconds of the "[retry in …]" countdown, or null when none follows
     *   label  the countdown as printed, for example "1h 21m"
     *   newer  true when a content row that is not status chrome follows it
     *
     * @return array{line:string,retry:?int,label:string,newer:bool}|null
     */
    private static function opencodeQuota(string $raw): ?array
    {
        if (trim($raw) === '') return null;
        $plain = TmuxService::plainPaneText(rtrim($raw, "\r\n"));
        $lines = array_slice(preg_split('/\R/u', $plain) ?: [], -TransientErrorService::TAIL_LINES);
        $hit = -1;
        foreach ($lines as $i => $line) {
            if (PaneInputRules::safeMatch(self::OPENCODE_QUOTA, (string)$line)) $hit = $i;
        }
        if ($hit < 0) return null;

        // The message and up to three wrapped rows under it.
        $rows = array_slice($lines, $hit, 4);
        $joined = trim(preg_replace('/\s+/u', ' ', implode(' ', $rows)) ?? '');
        $retry = null; $label = ''; $used = 1;
        if (preg_match(self::OPENCODE_QUOTA, $joined, $qm, PREG_OFFSET_CAPTURE)
            && preg_match(self::OPENCODE_RETRY, substr($joined, (int)$qm[0][1] + strlen($qm[0][0])), $m)) {
            $label = trim(preg_replace('/\s+/u', ' ', $m[1]) ?? '');
            $retry = self::durationSeconds($label);
            // The rows the message used end at the countdown's closing bracket.
            $acc = '';
            foreach ($rows as $k => $row) {
                $acc .= ' ' . $row;
                if (preg_match('/\[\s*retry(?:ing)?\b[^\]]*\]/iu', $acc)) { $used = $k + 1; break; }
            }
        }

        $p = TransientErrorService::patterns();
        $chrome = array_merge($p['chrome'], (array)($p['agents']['opencode']['chrome'] ?? []));
        $newer = false;
        foreach (array_slice($lines, $hit + $used) as $row) {
            $text = trim(preg_replace('/^[\s\x{2500}-\x{259F}\x{2B1D}■▣●•·]+/u', '', (string)$row) ?? '');
            if (!preg_match('/[\p{L}\p{N}]/u', $text)) continue;
            $isChrome = false;
            foreach ($chrome as $re) {
                if (PaneInputRules::safeMatch($re, $text)) { $isChrome = true; break; }
            }
            if (!$isChrome) { $newer = true; break; }
        }

        $line = trim(preg_replace('/\[\s*retry(?:ing)?\s+in[^\]]*\]/iu', '', $joined) ?? '');
        return ['line' => $line, 'retry' => $retry, 'label' => $label, 'newer' => $newer];
    }

    /** "1h 21m" -> 4860, "5m 30s" -> 330, "45s" -> 45, "2d 3h" -> 183600. */
    private static function durationSeconds(string $text): ?int
    {
        if (!preg_match_all('/(\d{1,4})\s*([dhms])/i', $text, $m, PREG_SET_ORDER)) return null;
        $mult = ['d' => 86400, 'h' => 3600, 'm' => 60, 's' => 1];
        $total = 0;
        foreach ($m as $part) $total += (int)$part[1] * $mult[strtolower($part[2])];
        return $total > 0 ? $total : null;
    }

    private static function detectClaude(string $text, int $now): ?array
    {
        if (preg_match(self::CLAUDE_NATIVE_CANCELLED, $text)) {
            return [
                'source' => 'native-agent-owned',
                'nativeState' => 'cancelled',
                'reason' => 'Claude cancelled its own automatic continue. No plugin schedule was created.',
                'fingerprint' => self::fingerprint($text, 'claude-cancelled'),
            ];
        }
        if (preg_match(self::CLAUDE_NATIVE_WAIT, $text, $m)) {
            $at = self::namedDateTime($now, (string)$m[1], (int)$m[2], (int)$m[3], (int)($m[4] ?: 0), (string)$m[5], (string)($m[6] ?? ''));
            $d = [
                'source' => 'native-agent-owned',
                'nativeState' => 'waiting',
                'reason' => 'Claude owns the automatic continue; the plugin must not send a duplicate Continue.',
                'fingerprint' => self::fingerprint($text, 'claude-native-wait'),
                'label' => trim((string)$m[1] . ' ' . $m[2] . ', ' . $m[3] . ($m[4] !== '' ? ':' . $m[4] : '') . strtolower((string)$m[5]) . 'm' . (!empty($m[6]) ? ' (' . $m[6] . ')' : '')),
            ];
            if ($at !== null) $d['at'] = $at;
            return $d;
        }
        if (preg_match(self::CLAUDE_RESET, $text, $m)) {
            $at = self::namedDateTime($now, (string)$m[1], (int)$m[2], (int)$m[3], (int)($m[4] ?: 0), (string)$m[5], (string)$m[6]);
            $d = [
                'source' => 'native-agent-owned',
                'nativeState' => 'reset',
                'reason' => 'Claude reported a native quota reset; provider-owned waiting remains separate from plugin scheduling.',
                'fingerprint' => self::fingerprint($text, 'claude-reset'),
                'label' => trim((string)$m[1] . ' ' . $m[2] . ', ' . $m[3] . ($m[4] !== '' ? ':' . $m[4] : '') . strtolower((string)$m[5]) . 'm (' . $m[6] . ')'),
            ];
            if ($at !== null) $d['at'] = $at;
            return $d;
        }
        return null;
    }

    private static function nextClockTime(int $now, int $hour, int $minute, string $meridiem, string $zone): ?int
    {
        if ($hour < 1 || $hour > 12 || $minute < 0 || $minute > 59) return null;
        $hour = strtolower($meridiem) === 'p' && $hour !== 12 ? $hour + 12 : $hour;
        if (strtolower($meridiem) === 'a' && $hour === 12) $hour = 0;
        try {
            $tz = $zone !== '' ? new \DateTimeZone($zone) : new \DateTimeZone(date_default_timezone_get());
            $current = (new \DateTimeImmutable('@' . $now))->setTimezone($tz);
            $candidate = $current->setTime($hour, $minute, 0);
            if ($candidate->getTimestamp() <= $now) $candidate = $candidate->modify('+1 day');
            return $candidate->getTimestamp();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function namedDateTime(int $now, string $monthName, int $day, int $hour, int $minute, string $meridiem, string $zone): ?int
    {
        $months = ['jan'=>1,'feb'=>2,'mar'=>3,'apr'=>4,'may'=>5,'jun'=>6,'jul'=>7,'aug'=>8,'sep'=>9,'oct'=>10,'nov'=>11,'dec'=>12];
        $month = $months[strtolower($monthName)] ?? 0;
        if ($month === 0 || $day < 1 || $day > 31) return null;
        if ($hour < 1 || $hour > 12 || $minute < 0 || $minute > 59) return null;
        $hour = strtolower($meridiem) === 'p' && $hour !== 12 ? $hour + 12 : $hour;
        if (strtolower($meridiem) === 'a' && $hour === 12) $hour = 0;
        try {
            $tz = $zone !== '' ? new \DateTimeZone($zone) : new \DateTimeZone(date_default_timezone_get());
            $year = (int)(new \DateTimeImmutable('@' . $now))->setTimezone($tz)->format('Y');
            $candidate = new \DateTimeImmutable(sprintf('%04d-%02d-%02d %02d:%02d:00', $year, $month, $day, $hour, $minute), $tz);
            if ($candidate->getTimestamp() <= $now) $candidate = $candidate->modify('+1 year');
            return $candidate->getTimestamp();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function fingerprint(string $text, string $kind): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($text)) ?: '';
        return substr(hash('sha256', $kind . '|' . strtolower($normalized)), 0, 16);
    }

    /** @return array{schema:int,last_poll_at:int,sessions:array<string,array<string,mixed>>} */
    private static function readState(): array
    {
        if (!is_file(self::STATE_PATH)) return ['schema' => 1, 'last_poll_at' => 0, 'sessions' => []];
        $data = json_decode((string)@file_get_contents(self::STATE_PATH), true);
        if (!is_array($data)) return ['schema' => 1, 'last_poll_at' => 0, 'sessions' => []];
        $data['schema'] = 1;
        $data['last_poll_at'] = (int)($data['last_poll_at'] ?? 0);
        $data['sessions'] = is_array($data['sessions'] ?? null) ? $data['sessions'] : [];
        return $data;
    }

    private static function writeState(array $state): void
    {
        @mkdir(dirname(self::STATE_PATH), 0755, true);
        AtomicWriteService::writeJson(self::STATE_PATH, $state);
    }
}
