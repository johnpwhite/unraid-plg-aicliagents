<?php
/**
 * <module_context>
 *     <name>CommandApprovalService</name>
 *     <description>A parent agent sends one direct command line to a sub-agent that it started, and a person
 *     allows it (docs/specs/AGENT_COMMAND_INJECTION.md, issue #948). The command is typed as it is, with no
 *     "[from parent ...]" tag, so the agent CLI reads it as its own command (for example /models). This
 *     service holds the rules: the line check (R2), the caller check (R3), the "always allow" rules that are
 *     stored on the target workspace record (R4, R7), the pending Activity tray proposal with the three
 *     answers once, always and block (R5), the typing after approval (R6), the expiry (R9) and the event
 *     ledger events. AdminService::sendInput() calls submit(); AdminService::approvePending() calls
 *     executeApproved(); ActivityHandler calls rules() and removeRule().</description>
 *     <dependencies>ConfigService, SpawnService, ActivityService, ProcessManager, EventLedger,
 *     RedactionService, HumanIdentity, UtilityService, AdminService</dependencies>
 *     <constraints>Static methods only. A method returns the payload, or ['error' => sentence] when it
 *     refused and typed nothing. Only a person in the Manager UI can approve, block or remove a rule: no tool
 *     reaches executeApproved(), saveRule() or removeRule(). A rule covers one caller, one target workspace
 *     and one command key. A pane that is not ready never gets a command later by surprise. Every clock and
 *     pane call has a test seam (the SpawnService seams, and $now here).</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class CommandApprovalService
{
    /** The tool name of the pending proposal. */
    public const TOOL = 'send_command';
    /** R2: the longest command line. */
    public const MAX_CHARS = 300;
    /** R9: a request that nobody answers ends after this many seconds. */
    public const EXPIRE_SECONDS = 1800;
    /** The two ways to allow a command. Block is a reject. */
    public const MODES = ['once', 'always'];
    /** Characters of the line that go into an event ledger row. */
    private const EXCERPT_CHARS = 80;

    /** @var callable|null fn(): int. Replaces time(). */
    public static $now = null;

    private static function now(): int
    {
        return self::$now !== null ? (int)(self::$now)() : time();
    }

    // ======================================================================
    // The line (R2) and its key
    // ======================================================================

    /**
     * R2: ONE line. No newline, no control character other than tab, at most MAX_CHARS characters.
     *
     * @return array{line:string}|array{error:string}
     */
    public static function validateLine(string $text): array
    {
        $line = trim($text);
        if ($line === '') return ['error' => 'There is no command to send: the text is empty.'];
        if (preg_match('//u', $line) !== 1) return ['error' => 'The command is not valid text.'];
        if (preg_match('/[\x00-\x08\x0A-\x1F\x7F\x{0080}-\x{009F}\x{2028}\x{2029}\p{Cf}]/u', $line) === 1) {
            return ['error' => 'A command is one line. It may not hold a line break, a control character or a hidden format character (a tab is fine).'];
        }
        if (mb_strlen($line) > self::MAX_CHARS) {
            return ['error' => 'The command is too long. The limit is ' . self::MAX_CHARS . ' characters.'];
        }
        return ['line' => $line];
    }

    /** R2: the first word when the line starts with "/", else the whole line. */
    public static function commandKey(string $line): string
    {
        $line = trim($line);
        if ($line !== '' && $line[0] === '/') {
            $parts = preg_split('/\s+/', $line, 2);
            return (string)($parts[0] ?? $line);
        }
        return $line;
    }

    // ======================================================================
    // Entry point of aicli_send_input with command:true
    // ======================================================================

    /**
     * The whole path of one command from a tool call: check the line and the caller, then type at once when a
     * rule matches, or write one pending proposal and type nothing.
     *
     * @param callable():bool $gapOpen The per-target minimum gap of send-input. It is asked only before real typing.
     * @return array<string,mixed>
     */
    public static function submit(string $targetId, string $text, callable $gapOpen): array
    {
        try {
            if (!SpawnService::enabled()) return ['error' => SpawnService::disabledMessage()];
            $checked = self::validateLine($text);
            if (isset($checked['error'])) return $checked;
            $line = $checked['line'];

            $caller = SpawnService::callerId();
            $record = SpawnService::findRecord($targetId);
            if ($record === null) return ['error' => "No workspace with id '$targetId' was found."];
            if ($caller === '' || !SpawnService::isHelper($record) || (string)($record['createdBy'] ?? '') !== $caller) {
                return ['error' => 'You may send a command only to a sub-agent workspace that you started yourself. This workspace is not one.'];
            }
            $name = (string)($record['name'] ?? $targetId);
            $agentId = (string)($record['agentId'] ?? '');
            if ($agentId === '') return ['error' => "Workspace '$name' has no agent on record; cannot type into it."];
            if (!ProcessManager::isRunning($targetId)) {
                return ['error' => "Workspace '$name' is not running. Start it before you send it a command."];
            }

            $key = self::commandKey($line);
            $rule = self::findRule($record, $caller, $key);
            if ($rule !== null) {
                if (!$gapOpen()) return ['error' => "Typed into workspace '$name' too recently. Wait a few seconds and try again."];
                if (!SpawnService::typeFirstLine($agentId, $targetId, $line, 0)) {
                    self::record('failed', $targetId, $agentId, $name, $caller, $line, $key, ['viaRule' => true, 'reason' => 'The pane was not ready.']);
                    return ['workspaceId' => $targetId, 'delivered' => false, 'deferred' => true, 'command' => true,
                        'reason' => 'The workspace is not ready for input now. Nothing was typed. Try again later.', 'chars' => mb_strlen($line)];
                }
                self::markRuleUsed($targetId, $caller, $key);
                self::record('typed', $targetId, $agentId, $name, $caller, $line, $key, ['viaRule' => true]);
                return ['workspaceId' => $targetId, 'delivered' => true, 'deferred' => false, 'forced' => false, 'command' => true,
                    'viaRule' => true, 'chars' => mb_strlen($line)];
            }
            return self::propose($targetId, $record, $caller, $line, $key);
        } catch (\Throwable $e) {
            return ['error' => 'The command could not be sent: ' . SpawnService::safeText($e->getMessage(), 300)];
        }
    }

    // ======================================================================
    // The pending proposal (R5, R9)
    // ======================================================================

    /**
     * R5: nothing is typed. One tray row with three answers, and one Unraid notification. R9: a second identical
     * request while one is pending joins it.
     *
     * @param array<string,mixed> $record The target workspace record.
     * @return array<string,mixed>
     */
    private static function propose(string $targetId, array $record, string $caller, string $line, string $key): array
    {
        $name = (string)($record['name'] ?? $targetId);
        $agentId = (string)($record['agentId'] ?? '');
        $params = ['targetId' => $targetId, 'callerId' => $caller, 'command' => $line, 'commandKey' => $key];

        $pending = self::findPending($params);
        if ($pending !== null) {
            return self::pendingResult($pending, $targetId, true);
        }

        $callerRec = SpawnService::findRecord($caller);
        $callerName = $callerRec !== null ? trim((string)($callerRec['name'] ?? '')) : '';
        if ($callerName === '') $callerName = $caller;
        $callerKind = $callerRec !== null ? UtilityService::trayAgentName((string)($callerRec['agentId'] ?? '')) : '';
        $kind = UtilityService::trayAgentName($agentId);

        $shown = mb_strlen($line) > 60 ? mb_substr($line, 0, 59) . '…' : $line;
        $label = 'Run "' . $shown . '" in ' . $name . '?';
        $description = "$callerName wants to type this line into the $kind workspace \"$name\" and press Enter: $line";
        $opId = 'pending_send_command_' . bin2hex(random_bytes(4));
        $card = [
            'subject' => ['name' => $line, 'detail' => 'To be typed into ' . $name],
            'facts' => [
                ['label' => 'From', 'value' => $callerName . ($callerKind !== '' ? " ($callerKind)" : '')],
                ['label' => 'To', 'value' => "$name ($kind)"],
            ],
            'effect' => 'Allow once types the line now. Always allow types it now and lets ' . $callerName . ' send "' . $key
                . '" to ' . $name . ' without asking again. Block types nothing.',
            'actions' => ['once', 'always', 'block'],
        ];
        $entry = ActivityService::propose($opId, self::TOOL, $description, $params,
            ['workspaceId' => $caller, 'agentId' => (string)($callerRec['agentId'] ?? ''), 'name' => $callerName],
            ['label' => $label, 'card' => $card]);
        if ($entry === null) return ['error' => 'Could not create the pending approval item.'];

        self::record('requested', $targetId, $agentId, $name, $caller, $line, $key);
        // The notification text can reach a web page: the angle brackets and the ampersand are encoded.
        SpawnService::systemNotify('A command waits for your answer', htmlspecialchars("$callerName wants to run $line in $name ($kind)", ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        return self::pendingResult($entry, $targetId, false);
    }

    /** @param array<string,mixed> $entry @return array<string,mixed> */
    private static function pendingResult(array $entry, string $targetId, bool $joined): array
    {
        return [
            'status' => 'pending_approval',
            'opId' => (string)$entry['opId'],
            'workspaceId' => $targetId,
            'command' => true,
            'joined' => $joined,
            'message' => 'Nothing was typed. A person must allow this command in the Activity tray. The answer is recorded in the event ledger as workspace.command (outcome typed, blocked, failed or expired). Do not poll the tray.',
        ];
    }

    /**
     * R9: the open proposal for the same target, caller and command, if there is one.
     *
     * @param array<string,string> $params
     * @return array<string,mixed>|null
     */
    private static function findPending(array $params): ?array
    {
        foreach (ActivityService::listAll() as $e) {
            if ((string)($e['status'] ?? '') !== ActivityService::STATUS_PENDING_APPROVAL) continue;
            if ((string)($e['meta']['tool'] ?? '') !== self::TOOL) continue;
            $p = is_array($e['meta']['params'] ?? null) ? $e['meta']['params'] : [];
            if (($p['targetId'] ?? null) === $params['targetId'] && ($p['callerId'] ?? null) === $params['callerId']
                && ($p['command'] ?? null) === $params['command']) {
                if (self::isExpired($e)) continue;
                return $e;
            }
        }
        return null;
    }

    // ======================================================================
    // After the person answers
    // ======================================================================

    /**
     * R6, R7: the person chose Allow once (`once`) or Always allow (`always`). Run ONLY by
     * AdminService::approvePending(). It uses the params recorded at proposal time. `always` writes the rule
     * first, then types. A pane that does not become ready in the bounded wait ends the row with a reason and
     * types nothing.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public static function executeApproved(array $params, string $mode): array
    {
        $targetId = (string)($params['targetId'] ?? '');
        $caller = (string)($params['callerId'] ?? '');
        $command = (string)($params['command'] ?? '');
        $key = (string)($params['commandKey'] ?? '');
        $name = $targetId;
        $agentId = '';
        try {
            if (!in_array($mode, self::MODES, true)) return ['error' => "'$mode' is not a way to allow a command. Use once or always."];
            $checked = self::validateLine($command);
            if (isset($checked['error']) || $targetId === '' || $caller === '' || $key !== self::commandKey($checked['line'] ?? '')) {
                return ['error' => 'This request holds no valid command. Nothing was typed.'];
            }
            $line = $checked['line'];
            $record = SpawnService::findRecord($targetId);
            if ($record === null) {
                self::record('failed', $targetId, '', $targetId, $caller, $line, $key, ['reason' => 'The workspace is gone.']);
                return ['error' => 'The sub-agent workspace no longer exists. Nothing was typed.'];
            }
            $name = (string)($record['name'] ?? $targetId);
            $agentId = (string)($record['agentId'] ?? '');
            if (!SpawnService::isHelper($record) || (string)($record['createdBy'] ?? '') !== $caller) {
                self::record('failed', $targetId, $agentId, $name, $caller, $line, $key, ['reason' => 'The caller no longer started this sub-agent.']);
                return ['error' => 'The workspace that asked did not start this sub-agent. Nothing was typed.'];
            }
            if ($agentId === '' || !ProcessManager::isRunning($targetId)) {
                self::record('failed', $targetId, $agentId, $name, $caller, $line, $key, ['reason' => 'The workspace is not running.']);
                return ['error' => "Workspace '$name' is not running. Nothing was typed."];
            }
            if ($mode === 'always' && !self::saveRule($targetId, $caller, $key)) {
                self::record('failed', $targetId, $agentId, $name, $caller, $line, $key, ['reason' => 'The rule could not be saved.']);
                return ['error' => 'The rule could not be saved, so nothing was typed. Try again.'];
            }
            if (!SpawnService::typeFirstLine($agentId, $targetId, $line)) {
                self::record('failed', $targetId, $agentId, $name, $caller, $line, $key, ['reason' => 'The pane was not ready.']);
                return ['error' => "Nothing was typed: workspace '$name' was not ready for input in time."
                    . ($mode === 'always' ? ' The rule is saved.' : '')];
            }
            self::record('typed', $targetId, $agentId, $name, $caller, $line, $key, ['mode' => $mode]);
            return ['typed' => true, 'mode' => $mode, 'finishText' => 'typed into ' . $name];
        } catch (\Throwable $e) {
            return ['error' => 'The command could not be typed: ' . SpawnService::safeText($e->getMessage(), 300)];
        }
    }

    /**
     * The person chose Block. Called by AdminService::rejectPending() after the row is rejected.
     *
     * @param array<string,mixed> $params
     */
    public static function recordBlocked(array $params): void
    {
        $targetId = (string)($params['targetId'] ?? '');
        $record = SpawnService::findRecord($targetId);
        self::record('blocked', $targetId, (string)($record['agentId'] ?? ''), (string)($record['name'] ?? $targetId),
            (string)($params['callerId'] ?? ''), (string)($params['command'] ?? ''), (string)($params['commandKey'] ?? ''));
    }

    // ======================================================================
    // Expiry (R9)
    // ======================================================================

    /** @param array<string,mixed> $entry A pending send_command entry. */
    public static function isExpired(array $entry): bool
    {
        $at = (int)($entry['meta']['proposedAt'] ?? $entry['startedAt'] ?? 0);
        return $at > 0 && (self::now() - $at) > self::EXPIRE_SECONDS;
    }

    /**
     * End one pending request as "Expired". Nothing is typed. Returns true when this call ended it.
     *
     * @param array<string,mixed> $entry
     */
    public static function expire(array $entry): bool
    {
        $opId = (string)($entry['opId'] ?? '');
        if ($opId === '') return false;
        $done = ActivityService::transitionIf($opId, [ActivityService::STATUS_PENDING_APPROVAL],
            ['status' => 'done', 'error' => null, 'step' => 'Expired', 'meta' => ['kind' => 'record']]);
        if ($done === null) return false;
        $p = is_array($entry['meta']['params'] ?? null) ? $entry['meta']['params'] : [];
        $targetId = (string)($p['targetId'] ?? '');
        $record = SpawnService::findRecord($targetId);
        self::record('expired', $targetId, (string)($record['agentId'] ?? ''), (string)($record['name'] ?? $targetId),
            (string)($p['callerId'] ?? ''), (string)($p['command'] ?? ''), (string)($p['commandKey'] ?? ''));
        return true;
    }

    /**
     * R9: end every open command request that nobody answered in EXPIRE_SECONDS. Called by the supervisor sweep
     * (ActivityService::sweep()). Never throws.
     */
    public static function sweepExpired(): int
    {
        $n = 0;
        try {
            foreach (ActivityService::listAll() as $e) {
                if ((string)($e['status'] ?? '') !== ActivityService::STATUS_PENDING_APPROVAL) continue;
                if ((string)($e['meta']['tool'] ?? '') !== self::TOOL) continue;
                if (self::isExpired($e) && self::expire($e)) $n++;
            }
        } catch (\Throwable $e) {
            // The next sweep tries again.
        }
        return $n;
    }

    // ======================================================================
    // The "always allow" rules (R4, R7). They live on the TARGET workspace record, key commandAllow.
    // ======================================================================

    /**
     * @param array<string,mixed> $record
     * @return array<string,mixed>|null
     */
    public static function findRule(array $record, string $callerId, string $commandKey): ?array
    {
        // A rule of a caller workspace that is gone stays in the list (marked), but it never matches.
        if (SpawnService::findRecord($callerId) === null) return null;
        foreach ((is_array($record['commandAllow'] ?? null) ? $record['commandAllow'] : []) as $r) {
            if (is_array($r) && (string)($r['callerId'] ?? '') === $callerId && (string)($r['commandKey'] ?? '') === $commandKey) return $r;
        }
        return null;
    }

    /**
     * Run $change on the rule list of one workspace. ConfigService::updateCommandAllow() does the read, the change
     * and the write as one step under the workspaces lock, and it is the only writer of the list: every other save
     * of the record keeps the stored rules, so a stale tab cannot bring back a rule that a person removed.
     *
     * @param callable(list<array<string,mixed>>):list<array<string,mixed>> $change
     */
    private static function mutateRules(string $targetId, callable $change): bool
    {
        return ConfigService::updateCommandAllow($targetId, $change);
    }

    /** R7: write the rule for (caller, key) on the target. A rule that exists stays as it is. */
    public static function saveRule(string $targetId, string $callerId, string $commandKey): bool
    {
        $by = 'a person';
        try {
            $who = HumanIdentity::name();
            if ($who !== '') $by = $who;
        } catch (\Throwable $e) {
            // Keep the default.
        }
        $now = self::now();
        return self::mutateRules($targetId, static function (array $rules) use ($callerId, $commandKey, $by, $now): array {
            foreach ($rules as $r) {
                if ((string)($r['callerId'] ?? '') === $callerId && (string)($r['commandKey'] ?? '') === $commandKey) return $rules;
            }
            $rules[] = ['callerId' => $callerId, 'commandKey' => $commandKey, 'savedAt' => $now, 'savedBy' => $by, 'lastUsedAt' => 0, 'uses' => 0];
            return $rules;
        });
    }

    /** R4: a rule matched and the command was typed. Count it. */
    private static function markRuleUsed(string $targetId, string $callerId, string $commandKey): void
    {
        $now = self::now();
        self::mutateRules($targetId, static function (array $rules) use ($callerId, $commandKey, $now): array {
            foreach ($rules as $i => $r) {
                if ((string)($r['callerId'] ?? '') === $callerId && (string)($r['commandKey'] ?? '') === $commandKey) {
                    $rules[$i]['lastUsedAt'] = $now;
                    $rules[$i]['uses'] = (int)($r['uses'] ?? 0) + 1;
                }
            }
            return $rules;
        });
    }

    /**
     * The rules of one target for the workspace menu. A rule whose caller workspace is gone is listed with
     * "(closed workspace)" and can be removed; findRule() never matches it.
     *
     * @return list<array<string,mixed>>
     */
    public static function rules(string $targetId): array
    {
        $record = SpawnService::findRecord($targetId);
        if ($record === null) return [];
        $names = [];
        foreach ((ConfigService::getWorkspaces()['sessions'] ?? []) as $w) {
            if (is_array($w) && !empty($w['id'])) $names[(string)$w['id']] = (string)($w['name'] ?? $w['id']);
        }
        $out = [];
        foreach ((is_array($record['commandAllow'] ?? null) ? $record['commandAllow'] : []) as $r) {
            if (!is_array($r)) continue;
            $caller = (string)($r['callerId'] ?? '');
            if ($caller === '') continue;
            // A rule of a closed caller workspace is listed, marked, so that a person can remove it. It never matches.
            $closed = !isset($names[$caller]);
            $out[] = [
                'callerId' => $caller, 'callerName' => $closed ? $caller . ' (closed workspace)' : $names[$caller], 'callerClosed' => $closed, 'commandKey' => (string)($r['commandKey'] ?? ''),
                'savedAt' => (int)($r['savedAt'] ?? 0), 'savedBy' => (string)($r['savedBy'] ?? ''),
                'lastUsedAt' => (int)($r['lastUsedAt'] ?? 0), 'uses' => (int)($r['uses'] ?? 0),
            ];
        }
        return $out;
    }

    /** R8: a person removes one rule in the Manager UI. @return bool true when a rule was removed */
    public static function removeRule(string $targetId, string $callerId, string $commandKey): bool
    {
        $found = false;
        $ok = self::mutateRules($targetId, static function (array $rules) use ($callerId, $commandKey, &$found): array {
            $keep = [];
            foreach ($rules as $r) {
                if ((string)($r['callerId'] ?? '') === $callerId && (string)($r['commandKey'] ?? '') === $commandKey) { $found = true; continue; }
                $keep[] = $r;
            }
            return $keep;
        });
        return $ok && $found;
    }

    // ======================================================================
    // Event ledger
    // ======================================================================

    /**
     * One `workspace.command` row. The line goes in as a redacted, capped excerpt, never in full. Best effort.
     *
     * @param array<string,mixed> $extra
     */
    private static function record(string $outcome, string $targetId, string $agentId, string $name, string $callerId, string $line, string $key, array $extra = []): void
    {
        if (!class_exists('\\AICliAgents\\Services\\EventLedger')) return;
        try {
            $callerRec = $callerId !== '' ? SpawnService::findRecord($callerId) : null;
            $callerName = $callerRec !== null ? (string)($callerRec['name'] ?? $callerId) : $callerId;
            $summary = EventLedger::summaryFor('workspace.command', ['outcome' => $outcome, 'name' => $name, 'caller' => $callerName]);
            EventLedger::append('workspace.command', ['workspaceId' => $targetId, 'agentId' => $agentId], $summary, array_merge([
                'outcome' => $outcome, 'callerId' => $callerId, 'callerName' => $callerName, 'target' => $name,
                'commandKey' => self::excerpt($key), 'excerpt' => self::excerpt($line),
            ], $extra));
        } catch (\Throwable $e) {
            // Best effort.
        }
    }

    /** Redacted, length-capped text for the ledger. Fails closed: a broken redaction gives ''. */
    private static function excerpt(string $text): string
    {
        $safe = $text;
        try {
            $safe = RedactionService::redact($text, RedactionService::loadKnownSecrets());
        } catch (\Throwable $e) {
            return '';
        }
        return mb_substr($safe, 0, self::EXCERPT_CHARS);
    }
}
