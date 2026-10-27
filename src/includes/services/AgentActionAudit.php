<?php

namespace AICliAgents\Services;

/** Records agent actions in the live action file and rotated flash copies. */
final class AgentActionAudit
{
    public const MAX_FILE_BYTES = 5242880;
    public static ?string $pathOverride = null;
    public static ?string $flashDirOverride = null;
    /** Test seam: a callable that returns the current Unix time. */
    public static $clock = null;
    public static int $maxFileBytes = self::MAX_FILE_BYTES;

    private const FULL_CALLS_PER_MINUTE = 20;
    private const ROLLING_FILES = 5;
    private const FLASH_ROTATED_FILES = 4;

    /**
     * Record one action without changing the caller's result.
     *
     * The return value tells the admin wrapper when a normal success row must
     * give way to the single folded burst row.
     *
     * @return array{recorded:bool,folded:bool}
     */
    public static function record(
        string $surface,
        string $tool,
        string $tier,
        array $args,
        string $outcome,
        string $reason = '',
        array $result = [],
        string $actorSession = '',
        string $reasonCode = ''
    ): array {
        try {
            if ($tier === 'read' && $outcome === 'ok') return ['recorded' => false, 'folded' => false];
            if ($tier === 'read' && $outcome === 'attempt') return ['recorded' => false, 'folded' => false];

            $surface = in_array($surface, ['mcp', 'cli', 'relay-mcp', 'relay-cli'], true) ? $surface : 'cli';
            $outcome = in_array($outcome, ['ok', 'refused', 'error', 'pending', 'attempt', 'rate_limited'], true) ? $outcome : 'error';
            $identity = self::identity($actorSession);
            $reasonData = self::reason($reason, $outcome, $tool, $args, $reasonCode);
            $refusalReasonCode = (string)($reasonData['code'] ?? 'tool_refused');
            $safeArgs = self::safeArgs($args, $tool);
            $target = self::target($args, $result);
            $resultId = self::resultId($result);
            $now = self::now();
            $stateDir = self::stateDir();
            if (!is_dir($stateDir)) @mkdir($stateDir, 0700, true);
            $lock = @fopen($stateDir . '/agent-actions.lock', 'c');
            if ($lock === false || !self::lock($lock)) {
                if (is_resource($lock)) fclose($lock);
                $row = self::row(self::fallbackSeq($now), $now, $surface, $tool, $tier, $identity, $target,
                    $outcome, $reasonData, $safeArgs, $resultId);
                if (!self::appendLine($row)) return ['recorded' => false, 'folded' => false];
                self::afterWrite($row, $identity, $tool, $tier, $outcome, $refusalReasonCode, $now);
                return ['recorded' => true, 'folded' => false];
            }

            $after = [];
            $folded = false;
            $currentRecorded = false;
            $currentTrayCount = 0;
            try {
                $statePath = $stateDir . '/agent-actions.state.json';
                $state = json_decode((string)@file_get_contents($statePath), true);
                if (!is_array($state)) $state = ['seq' => 0, 'bursts' => [], 'refusalTray' => []];
                $state['bursts'] = is_array($state['bursts'] ?? null) ? $state['bursts'] : [];
                $state['refusalTray'] = is_array($state['refusalTray'] ?? null) ? $state['refusalTray'] : [];
                unset($state['refusals']);
                $minute = intdiv($now, 60);

                // Closed windows append their final count once. No action or ledger row is rewritten.
                foreach ($state['bursts'] as $key => $priorBurst) {
                    if (!is_array($priorBurst)) { unset($state['bursts'][$key]); continue; }
                    $priorMinute = (int)($priorBurst['minute'] ?? 0);
                    if ($minute > $priorMinute && empty($priorBurst['summaryWritten'])
                        && (int)($priorBurst['calls'] ?? 0) > self::FULL_CALLS_PER_MINUTE) {
                        $count = (int)$priorBurst['calls'] - self::FULL_CALLS_PER_MINUTE;
                        $template = is_array($priorBurst['template'] ?? null) ? $priorBurst['template'] : [];
                        $summary = self::summaryRow($state, $template, $now, 'rate_limited', $count,
                            ['code' => 'burst_folded', 'message' => 'These calls are included in the minute summary.']);
                        if (self::appendLine($summary)) {
                            $priorBurst['summaryWritten'] = true;
                            $after[] = ['row' => $summary, 'kind' => 'rate_limited', 'count' => $count];
                        }
                    }
                    if ($now - (int)($priorBurst['lastAt'] ?? 0) > 120
                        && (int)($priorBurst['calls'] ?? 0) <= self::FULL_CALLS_PER_MINUTE) {
                        unset($state['bursts'][$key]);
                    } elseif ($now - (int)($priorBurst['lastAt'] ?? 0) > 120 && !empty($priorBurst['summaryWritten'])) {
                        unset($state['bursts'][$key]);
                    } else {
                        $state['bursts'][$key] = $priorBurst;
                    }
                }

                foreach ($state['refusalTray'] as $key => $priorTray) {
                    if (!is_array($priorTray) || $now - (int)($priorTray['lastAt'] ?? 0) > 3600) unset($state['refusalTray'][$key]);
                }

                $workspaceId = (string)($identity['workspaceId'] ?? '');
                $workspaceKey = $workspaceId !== '' ? $workspaceId : 'no-workspace';
                $recordedOutcome = $outcome;

                if ($outcome === 'refused') {
                    $state['seq'] = (int)($state['seq'] ?? 0) + 1;
                    $row = self::row((int)$state['seq'], $now, $surface, $tool, $tier, $identity, $target,
                        'refused', $reasonData, $safeArgs, $resultId);
                    $currentRecorded = self::appendLine($row);
                    if ($currentRecorded) {
                        $after[] = ['row' => $row, 'kind' => 'event', 'count' => 1];
                        $currentTrayCount = self::incrementRefusalTray($state, $workspaceKey, $now);
                    }
                } elseif ($outcome === 'attempt') {
                    $state['seq'] = (int)($state['seq'] ?? 0) + 1;
                    $row = self::row((int)$state['seq'], $now, $surface, $tool, $tier, $identity, $target,
                        'attempt', $reasonData, $safeArgs, $resultId);
                    $currentRecorded = self::appendLine($row);
                    if ($currentRecorded) $after[] = ['row' => $row, 'kind' => 'event', 'count' => 1];
                } else {
                    $burstKey = hash('sha256', $workspaceKey . "\0" . $tool . "\0" . $minute);
                    $burst = $state['bursts'][$burstKey] ?? ['minute' => $minute, 'calls' => 0, 'lastAt' => $now];
                    $burst['calls'] = (int)($burst['calls'] ?? 0) + 1;
                    $burst['lastAt'] = $now;
                    if ($burst['calls'] <= self::FULL_CALLS_PER_MINUTE) {
                        $state['seq'] = (int)($state['seq'] ?? 0) + 1;
                        $row = self::row((int)$state['seq'], $now, $surface, $tool, $tier, $identity, $target,
                            $recordedOutcome, $reasonData, $safeArgs, $resultId);
                        $currentRecorded = self::appendLine($row);
                        if ($currentRecorded) $after[] = ['row' => $row, 'kind' => 'event', 'count' => 1];
                        if ($burst['calls'] === self::FULL_CALLS_PER_MINUTE) $burst['template'] = $row;
                    } else {
                        $folded = true;
                        if (!isset($burst['template']) || !is_array($burst['template'])) {
                            $burst['template'] = self::row(0, $now, $surface, $tool, $tier, $identity, $target,
                                $recordedOutcome, $reasonData, $safeArgs, $resultId);
                        }
                        $currentRecorded = true;
                    }
                    $state['bursts'][$burstKey] = $burst;
                }

                self::writeState($statePath, $state);
            } finally {
                @flock($lock, LOCK_UN);
                fclose($lock);
            }

            foreach ($after as $effect) {
                $row = $effect['row'];
                self::appendLedger($row, $effect['count']);
                if ($effect['kind'] === 'rate_limited') {
                    self::recordTrayRollup((array)($row['actor'] ?? $identity), (string)($row['tool'] ?? $tool), $effect['count'], $now,
                        'rate_limited');
                }
            }
            if ($outcome === 'refused' && $currentTrayCount > 0) {
                self::recordTrayRollup($identity, $tool, $currentTrayCount, $now, 'refused');
                if (self::isSecurityRefusal($tool, $tier, $refusalReasonCode)) {
                    self::warn($identity, $tool, $refusalReasonCode);
                }
            }
            return ['recorded' => $currentRecorded, 'folded' => $folded];
        } catch (\Throwable $e) {
            return ['recorded' => false, 'folded' => false];
        }
    }

    /** @return array<string,mixed> */
    private static function row(int $seq, int $now, string $surface, string $tool, string $tier, array $identity, array $target,
        string $outcome, array $reason, array $args, string $resultId): array
    {
        $row = [
            'ts' => gmdate('c', $now),
            'seq' => $seq,
            'surface' => $surface,
            'tool' => self::clip(self::safeText($tool), 100),
            'tier' => self::clip(self::safeText($tier), 32),
            'actor' => $identity,
            'target' => $target,
            'outcome' => $outcome,
            'reason' => $reason,
            'args' => $args,
        ];
        if ($resultId !== '') $row['resultId'] = $resultId;
        return $row;
    }

    /** @return array<string,mixed> */
    private static function summaryRow(array &$state, array $template, int $now, string $outcome, int $count, array $reason): array
    {
        $state['seq'] = (int)($state['seq'] ?? 0) + 1;
        $row = $template;
        $row['ts'] = gmdate('c', $now);
        $row['seq'] = (int)$state['seq'];
        $row['outcome'] = $outcome;
        $row['reason'] = $reason;
        $row['count'] = max(1, $count);
        unset($row['resultId']);
        return $row;
    }

    private static function appendLedger(array $row, int $count): void
    {
        $identity = (array)($row['actor'] ?? []);
        $workspaceId = (string)($identity['workspaceId'] ?? '');
        $tool = (string)($row['tool'] ?? '');
        $outcome = (string)($row['outcome'] ?? 'error');
        $reason = (array)($row['reason'] ?? []);
        $eventActor = $workspaceId !== '' ? [
            'type' => 'agent', 'sessionId' => (string)($identity['sessionId'] ?? $workspaceId),
            'agentId' => (string)($identity['agentId'] ?? ''),
        ] : null;
        EventLedger::append('agent.action', ['workspaceId' => $workspaceId, 'tool' => $tool],
            self::ledgerSummary($tool, $outcome), [
                'tool' => $tool,
                'tier' => (string)($row['tier'] ?? ''),
                'outcome' => $outcome,
                'reason' => $reason,
                'count' => max(1, $count),
            ], $eventActor);
    }

    private static function afterWrite(array $row, array $identity, string $tool, string $tier, string $outcome, string $reasonCode, int $now): void
    {
        self::appendLedger($row, (int)($row['count'] ?? 1));
        if ($outcome === 'refused') {
            self::recordTrayRollup($identity, $tool, 1, $now, 'refused');
            if (self::isSecurityRefusal($tool, $tier, $reasonCode)) self::warn($identity, $tool, $reasonCode);
        }
    }

    private static function now(): int
    {
        return is_callable(self::$clock) ? (int)(self::$clock)() : time();
    }

    private static function fallbackSeq(int $now): int
    {
        return ($now * 1000000) + random_int(0, 999999);
    }

    private static function stateDir(): string
    {
        if (self::$pathOverride !== null) return dirname(self::$pathOverride);
        return SecretPaths::stateRoot();
    }

    private static function path(): string
    {
        return self::$pathOverride ?? (SecretPaths::stateRoot() . '/agent-actions.jsonl');
    }

    private static function lock($handle): bool
    {
        for ($try = 0; $try < 20; $try++) {
            if (@flock($handle, LOCK_EX | LOCK_NB)) return true;
            usleep(5000);
        }
        return false;
    }

    private static function identity(string $sessionOverride): array
    {
        $session = $sessionOverride !== '' ? $sessionOverride : (string)(getenv('AICLI_SESSION_ID') ?: '');
        $workspaceId = $session;
        $workspaceName = $session;
        $agentId = '';
        $agentName = '';
        if ($session !== '') {
            try {
                foreach ((ConfigService::getWorkspaces()['sessions'] ?? []) as $workspace) {
                    if (!is_array($workspace) || (string)($workspace['id'] ?? '') !== $session) continue;
                    $workspaceName = (string)($workspace['name'] ?? $session);
                    $agentId = (string)($workspace['agentId'] ?? '');
                    break;
                }
                if ($agentId !== '') {
                    $agentName = (string)(AgentRegistry::getRegistry()[$agentId]['name'] ?? $agentId);
                }
            } catch (\Throwable $e) {
                // Keep the session id. Audit must not block the action.
            }
        }
        return [
            'sessionId' => self::clip(self::safeText($session), 128),
            'workspaceId' => self::clip(self::safeText($workspaceId), 128),
            'workspaceName' => self::clip(self::safeText($workspaceName), 100),
            'agentId' => self::clip(self::safeText($agentId), 80),
            'agentName' => self::clip(self::safeText($agentName), 100),
        ];
    }

    private static function safeArgs(array $args, string $tool): array
    {
        $allowed = [
            'id', 'workspaceId', 'agentId', 'key', 'recipient_session_id', 'thread_id', 'request_id',
            'client_request_id', 'topic', 'kind', 'scope', 'mode', 'enabled', 'pinned', 'severity',
            'ack_seconds', 'resolve_seconds', 'limit', 'lines', 'all', 'user', 'folder', 'quiesce',
            'target', 'snapshot', 'safety_snapshot', 'repeat', 'at',
        ];
        $safe = [];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $args) || !is_scalar($args[$key])) continue;
            $value = $args[$key];
            $safe[$key] = is_string($value) ? self::clip(self::safeText($value), 160) : $value;
        }
        if ($tool === 'relay_send_direct_message' && isset($args['summary']) && is_string($args['summary'])) {
            $safe['bodyBytes'] = strlen($args['summary']);
        }
        return $safe;
    }

    private static function target(array $args, array $result): array
    {
        foreach (['workspaceId', 'id', 'agentId', 'recipient_session_id', 'request_id', 'topic', 'user', 'key'] as $key) {
            $value = $args[$key] ?? $result[$key] ?? null;
            if (is_scalar($value) && trim((string)$value) !== '') {
                return ['type' => $key, 'id' => self::clip(self::safeText((string)$value), 160)];
            }
        }
        return ['type' => '', 'id' => ''];
    }

    private static function resultId(array $result): string
    {
        foreach (['opId', 'request_id', 'message_id', 'messageId', 'thread_id', 'threadId', 'id'] as $key) {
            if (isset($result[$key]) && is_scalar($result[$key])) return self::clip(self::safeText((string)$result[$key]), 160);
        }
        foreach (['activity', 'request', 'message', 'workspace'] as $key) {
            if (isset($result[$key]) && is_array($result[$key])) {
                foreach (['opId', 'request_id', 'id'] as $field) {
                    if (isset($result[$key][$field]) && is_scalar($result[$key][$field])) return self::clip(self::safeText((string)$result[$key][$field]), 160);
                }
            }
        }
        return '';
    }

    private static function reason(string $raw, string $outcome, string $tool, array $args, string $explicitCode = ''): array
    {
        $text = strtolower($raw);
        $code = 'none';
        $message = '';
        if ($outcome === 'attempt') {
            $code = 'action_attempt'; $message = 'The action was requested.';
        } elseif ($outcome === 'rate_limited') {
            $code = 'burst_folded'; $message = 'This call is included in the minute summary.';
        } elseif ($outcome === 'refused') {
            if ($explicitCode === 'locked_setting') {
                $code = 'locked_setting'; $message = 'This setting is locked for agents.';
            } elseif ($explicitCode === 'operator_inside_workspace') {
                $code = 'operator_inside_workspace'; $message = 'This operator command is not available inside a workspace.';
            } elseif ($explicitCode === 'not_creator') {
                $code = 'not_creator'; $message = 'Only the workspace creator can stop this workspace.';
            } elseif (strpos($text, 'disabled') !== false || strpos($text, 'turned off') !== false) {
                $code = 'feature_disabled'; $message = 'The required feature is turned off.';
            } elseif (strpos($text, 'unknown') !== false || strpos($text, 'not found') !== false) {
                $code = 'target_not_found'; $message = 'The requested target was not found.';
            } else {
                $code = 'tool_refused'; $message = 'The tool refused this request.';
            }
        } elseif ($outcome === 'error') {
            $code = 'tool_error'; $message = 'The tool failed while it ran.';
        }
        return ['code' => $code, 'message' => self::clip(self::safeText($message), 180)];
    }

    private static function isSecurityRefusal(string $tool, string $tier, string $reasonCode): bool
    {
        if (in_array($tool, ['restore-home', 'backup-home'], true)) return $reasonCode === 'operator_inside_workspace';
        if ($tier === 'destructive') return true;
        if (strpos($tool, 'stop_workspace') !== false) return $reasonCode === 'not_creator';
        if (strpos($tool, 'set_setting') !== false) return $reasonCode === 'locked_setting';
        return $reasonCode === 'operator_inside_workspace';
    }

    private static function warn(array $identity, string $tool, string $reasonCode): void
    {
        $who = (string)($identity['agentName'] ?? '');
        $where = (string)($identity['workspaceName'] ?? '');
        $by = $who !== '' ? $who : ((string)($identity['sessionId'] ?? '') ?: 'unknown caller');
        $in = $where !== '' ? ' in ' . $where : '';
        LogService::log("Agent action refused: $tool by $by$in ($reasonCode).", LogService::LOG_WARN, 'AgentActionAudit');
    }

    private static function recordTrayRollup(array $identity, string $tool, int $count, int $now, string $kind): void
    {
        $workspaceId = (string)($identity['workspaceId'] ?? '');
        if ($workspaceId === '') return;
        $workspaceName = (string)($identity['workspaceName'] ?? '') ?: $workspaceId;
        $agentName = (string)($identity['agentName'] ?? '') ?: 'an agent';
        $window = $kind === 'refused' ? intdiv($now, 600) : intdiv($now, 60);
        $group = $kind === 'refused' ? $workspaceId : $workspaceId . "\0" . $tool;
        $opId = 'agent_action_' . ($kind === 'refused' ? 'refused_' : 'burst_') . substr(hash('sha256', $group . "\0" . $window), 0, 32);
        if ($kind === 'refused') {
            $label = "$count refused calls by $agentName in $workspaceName";
        } else {
            $label = "$count calls by $agentName in $workspaceName were grouped";
        }
        ActivityService::register($opId, 'admin', $label, ['meta' => [
            'kind' => 'record', 'tool' => $tool, 'outcome' => $kind, 'count' => $count,
            'workspaceId' => $workspaceId, 'agentId' => (string)($identity['agentId'] ?? ''),
            'agentActionRollup' => true,
            'by' => ['agentName' => $agentName, 'workspaceName' => $workspaceName],
        ]]);
        ActivityService::finish($opId);
    }

    /** Count all refused calls in one workspace window, across tools and reasons. */
    private static function incrementRefusalTray(array &$state, string $workspaceKey, int $now): int
    {
        $window = intdiv($now, 600);
        $key = hash('sha256', $workspaceKey . "\0" . $window);
        $entry = $state['refusalTray'][$key] ?? ['count' => 0];
        $entry['count'] = (int)($entry['count'] ?? 0) + 1;
        $entry['lastAt'] = $now;
        $state['refusalTray'][$key] = $entry;
        return $entry['count'];
    }

    private static function ledgerSummary(string $tool, string $outcome): string
    {
        return self::clip(self::safeText("Agent action $outcome: $tool"), 180);
    }

    private static function safeText(string $text): string
    {
        if ($text === '') return '';
        try {
            if (!class_exists(RedactionService::class)) return '';
            return RedactionService::redact($text, RedactionService::loadKnownSecrets());
        } catch (\Throwable $e) {
            return '';
        }
    }

    private static function clip(string $text, int $bytes): string
    {
        return strlen($text) > $bytes ? substr($text, 0, $bytes) : $text;
    }

    /** Append one complete JSON row while serialising file rotation and writes. */
    private static function appendLine(array $row): bool
    {
        $json = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($json)) return false;
        $path = self::path();
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) return false;

        $writeLock = self::openSecureAppend($path . '.append-lock');
        if ($writeLock === false) return false;
        if (!@flock($writeLock, LOCK_EX)) {
            fclose($writeLock);
            return false;
        }

        $ok = false;
        try {
        $max = max(1024, self::$maxFileBytes);
        if (is_file($path) && (int)@filesize($path) + strlen($json) + 1 > $max) {
            for ($i = self::ROLLING_FILES - 1; $i >= 1; $i--) {
                $from = $i === 1 ? $path : self::rotatedPath($i - 1);
                $to = self::rotatedPath($i);
                if (is_file($from) && !@rename($from, $to)) return false;
            }
            self::persistRotatedToFlash(self::rotatedPath(1));
        }

        $handle = self::openSecureAppend($path);
        if ($handle === false) return false;
        try {
            $line = $json . "\n";
            $offset = 0;
            $length = strlen($line);
            while ($offset < $length) {
                $written = @fwrite($handle, substr($line, $offset));
                if ($written === false || $written === 0) return false;
                $offset += $written;
            }
            @fflush($handle);
            $ok = true;
        } finally {
            fclose($handle);
        }
        } finally {
            @flock($writeLock, LOCK_UN);
            fclose($writeLock);
        }
        return $ok;
    }

    /** Open a file so its mode is private before any content can be written. */
    private static function openSecureAppend(string $path)
    {
        $priorUmask = umask(0077);
        try {
            $handle = @fopen($path, 'a');
        } finally {
            umask($priorUmask);
        }
        if ($handle !== false) @chmod($path, 0600);
        return $handle;
    }

    private static function persistRotatedToFlash(string $closedPath): void
    {
        if (!is_file($closedPath)) return;
        $dir = self::$flashDirOverride ?? dirname(StoragePathResolver::lifecycleLogPath());
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) return;
        $temporary = rtrim($dir, '/') . '/agent-actions.jsonl.tmp.' . bin2hex(random_bytes(4));
        if (!@copy($closedPath, $temporary)) return;
        @chmod($temporary, 0600);

        $oldest = self::flashArchivePath(self::FLASH_ROTATED_FILES);
        if (is_file($oldest)) @unlink($oldest);
        for ($i = self::FLASH_ROTATED_FILES - 1; $i >= 1; $i--) {
            $from = self::flashArchivePath($i);
            $to = self::flashArchivePath($i + 1);
            if (is_file($from)) @rename($from, $to);
        }
        if (!@rename($temporary, self::flashArchivePath(1))) {
            @unlink($temporary);
        }
    }

    private static function flashArchivePath(int $index): string
    {
        $dir = self::$flashDirOverride ?? dirname(StoragePathResolver::lifecycleLogPath());
        return rtrim($dir, '/') . '/agent-actions.jsonl.' . $index;
    }

    private static function rotatedPath(int $index): string
    {
        $path = self::path();
        return dirname($path) . '/' . basename($path, '.jsonl') . '.' . $index . '.jsonl';
    }

    private static function writeState(string $path, array $state): void
    {
        $json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($json)) return;
        $tmp = $path . '.' . bin2hex(random_bytes(3)) . '.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) !== false) {
            @chmod($tmp, 0600);
            @rename($tmp, $path);
        }
    }
}
