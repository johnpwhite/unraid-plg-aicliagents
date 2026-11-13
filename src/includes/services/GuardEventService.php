<?php
/**
 * <module_context>
 *     <name>GuardEventService</name>
 *     <description>Events of the share-filesystem guard (docs/specs/SHFS_RECURSIVE_READ_GUARD.md R13 and R14, Forgejo #972). raiseRefusal() turns one refusal (from the shell wrapper or the agent hook) into the ledger event `guard.refused` and one Activity tray record, at most once for each workspace in 60 seconds. recordProtectionChange() (R18) writes one tray record and one ledger event for each protection that a person changes for a workspace.</description>
 *     <dependencies>EventLedger, ActivityService, ConfigService (workspace name), SecretPaths (state folder), LogService.</dependencies>
 *     <constraints>Never throws to its caller: a refusal must not fail because an event could not be written. The rate limit uses one small file for each workspace in the guard state folder (RAM). allowEvent() is a pure decision over a folder, a key and a clock, so a test can drive it. The command text is cut to 200 characters and passes the ledger's own secret scrub.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class GuardEventService
{
    /** At most one event for each workspace in this many seconds (R14). */
    public const RATE_WINDOW_SECONDS = 60;

    /** The command text in an event is cut to this many characters (R14). */
    public const COMMAND_MAX_CHARS = 200;

    /** The folder of the rate-limit files (RAM, under the state root). */
    public static function stateDir(): string
    {
        return rtrim(SecretPaths::stateRoot(), '/') . '/guard';
    }

    /** One line of text for an event or a tray row: control characters become spaces, then it is cut to 200 characters. */
    public static function cutCommand(string $cmd): string
    {
        $cmd = (string)preg_replace('/[\x00-\x1f\x7f]+/', ' ', $cmd);
        return function_exists('mb_substr') ? mb_substr($cmd, 0, self::COMMAND_MAX_CHARS) : substr($cmd, 0, self::COMMAND_MAX_CHARS);
    }

    /**
     * The rate-limit decision. True (and the stamp is written) when no event for $key was allowed in the last
     * $window seconds. $now is the clock (a test passes its own). A folder that cannot be written allows the event:
     * a lost limit gives one extra event, never a lost one.
     */
    public static function allowEvent(string $dir, string $key, int $now, int $window = self::RATE_WINDOW_SECONDS): bool
    {
        $safe = preg_replace('/[^A-Za-z0-9_-]/', '_', $key);
        $safe = $safe === '' ? 'none' : substr((string)$safe, 0, 80);
        $file = rtrim($dir, '/') . '/refused-' . $safe . '.ts';
        $last = 0;
        if (is_file($file)) {
            $raw = @file_get_contents($file);
            if ($raw !== false && ctype_digit(trim($raw))) $last = (int)trim($raw);
        }
        if ($last > 0 && $now >= $last && ($now - $last) < $window) {
            return false;
        }
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        @file_put_contents($file, (string)$now, LOCK_EX);
        return true;
    }

    /** The display name of a workspace, or its id when it has none. */
    private static function workspaceName(string $id): string
    {
        if ($id === '') return '';
        try {
            foreach ((ConfigService::getWorkspaces()['sessions'] ?? []) as $w) {
                if (is_array($w) && (string)($w['id'] ?? '') === $id) {
                    $n = trim((string)($w['name'] ?? ''));
                    return $n !== '' ? $n : $id;
                }
            }
        } catch (\Throwable $e) {
            // Fall through to the id.
        }
        return $id;
    }

    /**
     * Raise `guard.refused` for one refusal. Layer is `shell` (the wrapper) or `hook` (the agent pre-tool hook).
     * $workspaceId defaults to env AICLI_SESSION_ID, $agent to env AGENT_ID. Returns true when an event was written,
     * false when the rate limit or an error held it back.
     */
    public static function raiseRefusal(string $layer, string $agent, string $kind, string $command, ?string $workspaceId = null, ?int $now = null, ?string $dir = null): bool
    {
        try {
            $ws = $workspaceId ?? (string)(getenv('AICLI_SESSION_ID') ?: '');
            $agentId = (string)(getenv('AGENT_ID') ?: $agent);
            $now = $now ?? time();
            if (!self::allowEvent($dir ?? self::stateDir(), $ws === '' ? 'none' : $ws, $now)) {
                return false;
            }
            $cmd = self::cutCommand($command);
            $name = self::workspaceName($ws);
            $summary = 'The share guard refused a command' . ($name !== '' ? " in $name" : '') . ': ' . $cmd;
            $data = ['workspaceId' => $ws, 'agent' => $agentId, 'layer' => $layer, 'kind' => $kind, 'command' => $cmd];
            if (class_exists(EventLedger::class)) {
                EventLedger::append('guard.refused', ['workspaceId' => $ws], $summary, $data, ['type' => 'system', 'user' => 'shfs-guard']);
            }
            if (class_exists(ActivityService::class)) {
                $opId = 'guard_refused_' . preg_replace('/[^A-Za-z0-9]/', '', $ws === '' ? 'none' : $ws) . '_' . intdiv($now, self::RATE_WINDOW_SECONDS);
                ActivityService::register($opId, 'admin', 'The share guard refused a command', ['meta' => [
                    'tool' => 'shfs_guard', 'kind' => 'record', 'agentId' => $agentId, 'workspaceId' => $ws,
                    'by' => ['agentName' => $agentId !== '' ? $agentId : 'An agent', 'workspaceName' => $name],
                ]]);
                ActivityService::finish($opId, $cmd . ' (use the pool path or a smaller folder)');
            }
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** The plain-words name of a protection, for the tray. */
    public const PROTECTION_LABELS = [
        'shell' => 'shell guard',
        'hooks' => 'agent tool guard',
        'view' => 'hidden share view',
        'viewSsh' => 'hidden ssh keys',
        'viewDocker' => 'docker mount check',
        'viewCaps' => 'dropped admin powers',
    ];

    /**
     * R18: one tray record and one ledger event for ONE changed protection of a workspace (value: default, on or off). A
     * person made the change, in the "Protection settings" dialog. Best effort.
     */
    public static function recordProtectionChange(string $workspaceId, string $protection, string $from, string $to): void
    {
        try {
            $name = self::workspaceName($workspaceId);
            $label = self::PROTECTION_LABELS[$protection] ?? $protection;
            $word = ['default' => 'the server default', 'on' => 'On', 'off' => 'Off'];
            $title = ucfirst($label) . ' changed for a workspace';
            $detail = ucfirst($label) . ' is now ' . ($word[$to] ?? $to) . ' for this workspace (was ' . ($word[$from] ?? $from) . '). It applies when the workspace starts again.';
            if (class_exists(EventLedger::class)) {
                EventLedger::append('guard.protection.changed', ['workspaceId' => $workspaceId], "$title: $name", ['workspaceId' => $workspaceId, 'protection' => $protection, 'from' => $from, 'to' => $to]);
            }
            if (class_exists(ActivityService::class)) {
                $opId = 'guard_exempt_' . preg_replace('/[^A-Za-z0-9]/', '', $workspaceId) . '_' . bin2hex(random_bytes(3));
                ActivityService::register($opId, 'admin', $title, ['meta' => [
                    'tool' => 'shfs_guard_protection', 'kind' => 'record', 'workspaceId' => $workspaceId,
                    'by' => ['agentName' => 'You', 'workspaceName' => $name],
                ]]);
                ActivityService::finish($opId, $detail);
            }
        } catch (\Throwable $e) {
            LogService::log('guard protection record failed: ' . $e->getMessage(), LogService::LOG_WARN, 'GuardEventService');
        }
    }
}
