<?php
/**
 * <module_context>
 *     <name>SpawnService</name>
 *     <description>An agent starts a sub-agent workspace, gives it a task, watches it and closes it
 *     (docs/specs/AGENT_SPAWN_WORKSPACE.md, issue #673). This service holds every rule of the four
 *     tools aicli_spawn_workspace, aicli_start_workspace, aicli_stop_workspace and aicli_finish_task,
 *     including reports from script-run workspaces (docs/specs/AGENT_RUN_ENTRY_POINT.md, issue #745),
 *     the parent's answer to a sub-agent's permission prompt (R13), the close of a sub-agent (R14, R17), the
 *     confirm text for a person who closes a sub-agent (R16) and the supervisor sweep that closes a sub-agent
 *     after its report. AdminService exposes thin methods that call this class; AdminMcpTools wraps their
 *     answers.</description>
 *     <dependencies>ConfigService, ArgsService, AgentLaunchProfile, AgentRegistry, AutoLaunchSuppression,
 *     ProcessManager, TerminalService, TerminalHandler, TmuxService, QuotaDetectionService,
 *     ValidationService, AgentRelayService, ActivityService, EventLedger, RedactionService, SecretPaths</dependencies>
 *     <constraints>Static methods only. Every method returns an array: the payload, or ['error' => sentence]
 *     when it refused and changed nothing (the AdminService convention). The rules here are TOOL-SURFACE
 *     rules (spec section "Which rules are only tool-surface rules"): every agent runs as root, so
 *     `createdBy` is a label the plugin writes, not a proof. A refusal comes BEFORE any side effect. The
 *     class never runs git push, merge or reset, and never deletes a file it did not make. All paths and
 *     processes have a test seam (the public static properties below), so a unit test never reaches the
 *     live store, a live tmux or a live git repository.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class SpawnService
{
    /** The folder inside the sub-agent's workspace folder that holds the task files. */
    public const TASK_DIR = '.aicli-task';

    /** The fixed first line the plugin gives a sub-agent. The caller never writes it (spec R22). */
    public const FIRST_LINE = 'Your task is in .aicli-task/TASK.md. Read it, do it, then call aicli_finish_task.';

    public const MAX_TASK_CHARS = 16000;
    public const MAX_SUMMARY_CHARS = 2000;
    public const MAX_TITLE_CHARS = 80;
    /** One spawn for each caller in this many seconds. */
    public const SPAWN_GAP_SECONDS = 10;
    /** One answer to a sub-agent prompt for each sub-agent in this many seconds. */
    public const ANSWER_GAP_SECONDS = 2;
    /** The longest the spawn call waits for the pane of an agent without a prompt flag to be ready. */
    public const PANE_WAIT_SECONDS = 30;
    /** A reported sub-agent or script run closes only after this many seconds, so its last reply can finish. */
    public const CLOSE_AFTER_REPORT_SECONDS = 5;
    /** The longest per-spawn time limit, in minutes (one day). */
    public const MAX_MINUTES_LIMIT = 1440;

    /** Defaults of the five settings. The values are the strings the .cfg file stores. */
    public const SETTING_DEFAULTS = [
        'spawn_enabled' => '0',
        'spawn_max_children' => '3',
        'spawn_max_total' => '6',
        'spawn_max_per_agent' => '2',
        'spawn_allow_unattended' => '0',
    ];

    /** Pane reasons (PaneInputRules ids) that mean "the agent waits for an answer to a question". */
    public const PROMPT_REASONS = ['option-selector', 'selector-cursor', 'menu-nav-hint', 'yes-no', 'do-you-want', 'proceed', 'opencode-permission'];

    /** Answers that reject. Never typed with Enter: Enter takes the highlighted option, which is "Allow once" (#900). */
    public const REJECT_WORDS = ['reject', 'deny', 'cancel', 'esc', 'escape'];
    /** Also a reject on the OpenCode permission box, where a typed word does nothing. */
    public const REJECT_WORDS_BOX = ['no', 'n'];
    /** Allow-once answers on the OpenCode permission box. */
    public const ALLOW_ONCE_WORDS_BOX = ['1', 'y', 'yes', 'ok', 'once', 'allow', 'allow once'];

    /** A prompt that shows one of these is never answered by a parent (spec R13): a person decides. */
    public const PROMPT_DENY_PATTERNS = [
        '/\bgit\s+push\b/i', '/--force\b/i', '/\bgit\s+reset\s+--hard\b/i', '/\/boot(\/|\s|$)/', '/\/tmp\/unraid-aicliagents/',
        '/workspaces\.json/', '/\baicli_[a-z_]+/', '/admin-agent|relay-agent/i', '/\b(ssh|scp|rsync)\s/i',
        '/\brm\s+-[a-z]*r/i', '/curl\b.*-X\s*(POST|PUT|PATCH|DELETE)/i',
    ];

    // ---- Test seams. Every one is null in production. ----

    /** @var array<string,mixed>|null Config values that replace the real config. */
    public static ?array $configForTests = null;
    /** @var callable|null fn(string $agentId): bool. Replaces the "is this agent installed" check. */
    public static $installedProbe = null;
    /** @var array<int,string>|null Replaces the allowed base folders of ValidationService::validatePath(). */
    public static ?array $allowedBases = null;
    /** @var array<int,string>|null Replaces the folders that a spawn may never use. */
    public static ?array $blockedPrefixes = null;
    /** @var callable|null fn(array $argv, string $cwd): array{rc:int,out:string}. Replaces every git call. */
    public static $gitRunner = null;
    /** @var callable|null fn(string $id, string $path, string $agentId, string $chatId): array. Replaces TerminalService::startTerminal(). */
    public static $startRunner = null;
    /** @var callable|null fn(string $id, string $agentId, string $path): array. Replaces the graceful close. */
    public static $closeRunner = null;
    /** @var callable|null fn(string $agentId, string $id): array{ready:bool,reason:string}. Replaces the pane gate. */
    public static $paneGate = null;
    /** @var callable|null fn(string $agentId, string $id): string. Replaces the pane capture. */
    public static $paneCapture = null;
    /** @var callable|null fn(string $agentId, string $id, string $text, bool $enter): array. Replaces the pane typer. */
    public static $paneTyper = null;
    /** @var callable|null fn(string $agentId, string $id, string $key): array. Replaces the single key press (Enter or Escape). */
    public static $paneKeyer = null;
    /** @var callable|null fn(int $milliseconds): void. Replaces the wait between two pane checks. */
    public static $sleeper = null;
    /** @var callable|null fn(): int. Replaces time(). */
    public static $now = null;
    /** @var string|null Replaces the folder of the gap markers. */
    public static ?string $rateDir = null;
    /** @var callable|null fn(string $sender, string $recipient, string $text): array. Replaces the Relay message to the parent. */
    public static $parentNotifier = null;
    /** @var callable|null fn(string $agentId): ?array. Replaces QuotaDetectionService::activeLimit(). */
    public static $quotaProbe = null;
    /** @var int|null Replaces PANE_WAIT_SECONDS. */
    public static ?int $paneWaitSeconds = null;

    /**
     * The two seams that decide which agent and which folder a start may use. They count only under PHPUnit (the
     * bootstrap defines AICLI_TEST_TMP). In production a stray value is ignored (security review of #745).
     *
     * @return callable|null
     */
    public static function installedProbe()
    {
        return defined('AICLI_TEST_TMP') ? self::$installedProbe : null;
    }

    /** @return array<int,string>|null */
    public static function allowedBases(): ?array
    {
        return defined('AICLI_TEST_TMP') ? self::$allowedBases : null;
    }

    // ======================================================================
    // Settings and identity
    // ======================================================================

    /** The five settings, with defaults filled in. @return array<string,string> */
    public static function settings(): array
    {
        $config = self::$configForTests;
        if ($config === null) {
            try {
                $config = \function_exists('getAICliConfig') ? getAICliConfig() : ConfigService::getConfig();
            } catch (\Throwable $e) {
                $config = [];
            }
        }
        $out = [];
        foreach (self::SETTING_DEFAULTS as $key => $default) {
            $value = $config[$key] ?? $default;
            $out[$key] = is_scalar($value) ? (string)$value : $default;
        }
        return $out;
    }

    /** True when a person turned the four sub-agent tools on. Compared with '1', never a truthy cast. */
    public static function enabled(): bool
    {
        return self::settings()['spawn_enabled'] === '1';
    }

    public static function disabledMessage(): string
    {
        return 'Sub-agent workspaces are turned off. A person turns them on in Settings, Sub-agent workspaces, "Let agents start sub-agent workspaces".';
    }

    private static function cap(string $key): int
    {
        $d = (int)self::SETTING_DEFAULTS[$key];
        $v = self::settings()[$key];
        return preg_match('/^\d{1,3}$/D', $v) === 1 && (int)$v > 0 ? (int)$v : $d;
    }

    /** The workspace id of the caller, from AICLI_SESSION_ID. '' means a person's shell or a remote client. */
    public static function callerId(): string
    {
        return (string)(getenv('AICLI_SESSION_ID') ?: '');
    }

    private static function now(): int
    {
        return self::$now !== null ? (int)(self::$now)() : time();
    }

    /** @return array<string,mixed>|null One saved workspace record, or null. */
    public static function findRecord(string $id): ?array
    {
        if ($id === '') return null;
        foreach ((ConfigService::getWorkspaces()['sessions'] ?? []) as $w) {
            if (is_array($w) && (string)($w['id'] ?? '') === $id) return $w;
        }
        return null;
    }

    /** True when the record is a sub-agent workspace that the spawn tool made. */
    public static function isHelper(?array $record): bool
    {
        return $record !== null && (!empty($record['spawned']) || is_array($record['spawn'] ?? null));
    }

    /**
     * Who is calling, for the spawn tool. Decision 6: a sub-agent cannot start another sub-agent. R19 and the
     * spec table: a call with no workspace behind it (a remote client) is refused in this version.
     *
     * @return array{id:string,record:array<string,mixed>}|array{error:string}
     */
    public static function spawnCaller(): array
    {
        $id = self::callerId();
        if ($id === '') {
            return ['error' => 'A sub-agent workspace can be started only from inside a workspace. A call with no workspace behind it is refused.'];
        }
        $record = self::findRecord($id);
        if ($record === null) {
            return ['error' => "This session ('$id') is not a known workspace, so it cannot start a sub-agent. Use a workspace from aicli_list_workspaces."];
        }
        if (self::isHelper($record)) {
            return ['error' => 'A sub-agent workspace cannot start another sub-agent workspace. Only a workspace that a person opened may do that.'];
        }
        return ['id' => $id, 'record' => $record];
    }

    /**
     * Decision 2: only the workspace that created a workspace (`createdBy`), or a person, may stop
     * it. (A start is open to any caller since #542.) A call with no workspace behind it is a person's shell. Null means allowed; else the refusal.
     *
     * @param array<string,mixed> $record
     */
    public static function refuseUnlessCreator(array $record): ?string
    {
        $caller = self::callerId();
        if ($caller === '') return null;
        if ((string)($record['createdBy'] ?? '') === $caller) return null;
        $name = (string)($record['name'] ?? $record['id'] ?? 'that workspace');
        return "Only the workspace that created '$name', or a person, may stop it.";
    }

    // ======================================================================
    // Small sub-agents: text, paths, gaps, git, events
    // ======================================================================

    /** The text with secrets replaced, cut to $max characters. '' when the redaction cannot run (fail closed). */
    public static function safeText(string $text, int $max): string
    {
        $text = trim($text);
        if ($text === '') return '';
        try {
            if (!class_exists('\\AICliAgents\\Services\\RedactionService')) return '';
            $text = RedactionService::redact($text, RedactionService::loadKnownSecrets());
        } catch (\Throwable $e) {
            return '';
        }
        return function_exists('mb_substr') ? mb_substr($text, 0, $max) : substr($text, 0, $max);
    }

    private static function length(string $text): int
    {
        return function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
    }

    /** The first line of the task, cut short, as the title of the task. */
    public static function titleFromTask(string $task): string
    {
        $first = trim((string)strtok(trim($task), "\n"));
        $first = (string)preg_replace('/^#+\s*/', '', $first);
        return function_exists('mb_substr') ? mb_substr($first, 0, self::MAX_TITLE_CHARS) : substr($first, 0, self::MAX_TITLE_CHARS);
    }

    /**
     * One call per $key in $seconds. Returns true and touches the marker when the call may go on. Same idea
     * as AdminService::sendInputGateOpen(): the marker's mtime is the time of the last allowed call.
     */
    private static function gapOpen(string $key, int $seconds): bool
    {
        $dir = self::$rateDir ?? SecretPaths::stateRoot() . '/spawn-rate';
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        $file = $dir . '/.last-' . preg_replace('/[^A-Za-z0-9_-]/', '_', $key);
        $now = self::now();
        $last = @filemtime($file);
        if ($last !== false && ($now - $last) < $seconds) return false;
        @touch($file, $now);
        return true;
    }

    /** @return array<int,string> Folders that a sub-agent may never use as its workspace folder. */
    private static function blockedPrefixes(): array
    {
        if (self::$blockedPrefixes !== null) return self::$blockedPrefixes;
        return ['/boot', '/usr/local/emhttp', SecretPaths::flashRoot(), SecretPaths::stateRoot()];
    }

    private static function pathIsBlocked(string $path): bool
    {
        // The live state roots (the plugin's flash and RAM folders), resolved through links (#675).
        if (self::$blockedPrefixes === null && SecretPaths::isUnderLiveRoot($path)) return true;
        foreach (self::blockedPrefixes() as $prefix) {
            $prefix = rtrim($prefix, '/');
            if ($prefix === '') continue;
            if ($path === $prefix || strpos($path, $prefix . '/') === 0) return true;
        }
        return false;
    }

    /**
     * A folder for a sub-agent: the same rule as the Manager's folder picker (ValidationService), and a folder
     * that exists, can be read, and is not a live state folder (spec section 8, path rules).
     *
     * @return array{path:string}|array{error:string}
     */
    private static function resolveFolder(string $path, string $what): array
    {
        $resolved = ValidationService::validatePath($path, self::allowedBases());
        if ($resolved === false || !is_dir($resolved) || !is_readable($resolved)) {
            return ['error' => "$what '$path' does not resolve to an existing, readable folder inside an allowed location."];
        }
        if (self::pathIsBlocked($resolved)) {
            return ['error' => "$what '$path' is a plugin state folder or the boot disk. A sub-agent workspace can never use it."];
        }
        return ['path' => $resolved];
    }

    /**
     * Run one git command without a shell. The array form means no argument is ever parsed by a shell.
     *
     * @param array<int,string> $argv The arguments after `git`.
     * @return array{rc:int,out:string}
     */
    private static function git(array $argv, string $cwd = '/'): array
    {
        if (self::$gitRunner !== null) return (self::$gitRunner)($argv, $cwd);
        $cmd = array_merge(['git'], $argv);
        $proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, ['GIT_TERMINAL_PROMPT' => '0', 'PATH' => (string)(getenv('PATH') ?: '/usr/bin:/bin'), 'HOME' => (string)(getenv('HOME') ?: '/root')]);
        if (!is_resource($proc)) return ['rc' => 127, 'out' => ''];
        $out = (string)stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['rc' => proc_close($proc), 'out' => $out];
    }

    /** One ledger event. Best effort: a missing ledger (a unit test) or a fault never changes the result. */
    private static function emit(string $kind, array $subject, string $summary, array $data = []): void
    {
        if (!class_exists('\\AICliAgents\\Services\\EventLedger')) return;
        try {
            EventLedger::append($kind, $subject, $summary, $data);
        } catch (\Throwable $e) {
            // Best effort.
        }
    }

    /**
     * One Activity tray record that names the parent (R15). Used by the paths that no tool call starts, for
     * example the supervisor sweep that closes a sub-agent. A tool call gets its tray record from
     * AdminMcpTools::recordChangeAudit(), so a tool path never calls this (one notice for each step).
     */
    public static function notify(string $title, string $detail, string $workspaceId = ''): void
    {
        try {
            $opId = 'spawn_notice_' . bin2hex(random_bytes(4));
            ActivityService::register($opId, 'admin', $title, ['meta' => [
                'tool' => 'aicli_spawn_workspace', 'kind' => 'record', 'workspaceId' => $workspaceId,
                'by' => ['agentName' => 'The plugin', 'workspaceName' => ''],
            ]]);
            ActivityService::finish($opId, $detail);
        } catch (\Throwable $e) {
            // Best effort.
        }
    }

    // ======================================================================
    // Workspace records
    // ======================================================================

    /**
     * Save one workspace record after $change edited it. Starts from the stored record, so no field is lost.
     * The merge writer keeps the record's place in the list.
     *
     * @param callable(array<string,mixed>):array<string,mixed> $change
     */
    private static function updateRecord(string $id, callable $change): bool
    {
        $record = self::findRecord($id);
        if ($record === null) return false;
        $record = $change($record);
        return ConfigService::saveWorkspaces(['sessions' => [$record]], []);
    }

    /** The sub-agent marks of a new record: who started it, for what, and how it ends. @return array<string,mixed> */
    public static function newSpawnMeta(string $parentId, string $parentName, string $taskTitle, bool $autoClose, bool $unattended, ?array $worktree, string $model, string $effort, int $maxMinutes = 0): array
    {
        return [
            'max_minutes' => $maxMinutes,
            'parent' => $parentId,
            'parent_name' => $parentName,
            'task_title' => $taskTitle,
            'started_at' => self::now(),
            'auto_close' => $autoClose,
            'unattended' => $unattended,
            'model' => $model,
            'effort' => $effort,
            'state' => 'starting',
            'worktree' => $worktree,
        ];
    }

    /**
     * What aicli_list_workspaces and aicli_get_workspace show for a sub-agent (spec section 6): the parent, the
     * task, the state and the report. A person-made workspace has no such block (null).
     *
     * @param array<string,mixed> $record
     * @return array<string,mixed>|null
     */
    public static function view(array $record, bool $withLiveState = true): ?array
    {
        if (!self::isHelper($record)) return null;
        $s = is_array($record['spawn'] ?? null) ? $record['spawn'] : [];
        $report = is_array($s['report'] ?? null) ? $s['report'] : null;
        $view = [
            'parent' => (string)($s['parent'] ?? $record['createdBy'] ?? ''),
            'parentName' => (string)($s['parent_name'] ?? ''),
            'taskTitle' => (string)($s['task_title'] ?? ''),
            'startedAt' => $s['started_at'] ?? null,
            'autoClose' => !empty($s['auto_close']),
            'model' => (string)($s['model'] ?? ''),
            'effort' => (string)($s['effort'] ?? ''),
            'maxMinutes' => (int)($s['max_minutes'] ?? 0),
            'overdueAt' => $s['overdue_at'] ?? null,
            'state' => $withLiveState ? self::state($record) : (string)($s['state'] ?? ''),
            'report' => $report,
        ];
        $wt = $s['worktree'] ?? null;
        if (is_array($wt)) $view['worktree'] = ['path' => (string)($wt['path'] ?? ''), 'branch' => (string)($wt['branch'] ?? '')];
        return $view;
    }

    /**
     * The state of a sub-agent (spec section 6): running, closed, idle, busy, waiting_approval, quota or
     * reported_done. `idle`, `busy` and `waiting_approval` come from the pane classifier that exists; no
     * new screen scraping. Never throws.
     *
     * @param array<string,mixed> $record
     */
    public static function state(array $record): string
    {
        try {
            $s = is_array($record['spawn'] ?? null) ? $record['spawn'] : [];
            if (($s['state'] ?? '') === 'reported_done') return 'reported_done';
            $id = (string)($record['id'] ?? '');
            $agentId = (string)($record['agentId'] ?? '');
            if ($id === '' || !ProcessManager::isRunning($id)) return 'closed';
            $limit = self::$quotaProbe !== null ? (self::$quotaProbe)($agentId) : QuotaDetectionService::activeLimit($agentId, null, null, null, null, (string)($s['model'] ?? ''));
            if ($limit !== null) return 'quota';
            $gate = self::gate($agentId, $id);
            if ($gate['ready']) return 'idle';
            return in_array($gate['reason'], self::PROMPT_REASONS, true) ? 'waiting_approval' : 'busy';
        } catch (\Throwable $e) {
            return 'running';
        }
    }

    /** @return array{ready:bool,reason:string} */
    public static function gate(string $agentId, string $id): array
    {
        if (self::$paneGate !== null) return (self::$paneGate)($agentId, $id);
        return TmuxService::paneAcceptsInput($agentId, $id);
    }

    // ======================================================================
    // Slice (b): aicli_spawn_workspace
    // ======================================================================

    /** Count the sub-agents that run now: [all, for one parent, for one agent kind]. @return array{0:int,1:int,2:int} */
    private static function runningHelpers(string $parentId, string $agentId): array
    {
        $all = $mine = $kind = 0;
        foreach ((ConfigService::getWorkspaces()['sessions'] ?? []) as $w) {
            if (!is_array($w) || !self::isHelper($w)) continue;
            $wid = (string)($w['id'] ?? '');
            if ($wid === '' || !ProcessManager::isRunning($wid)) continue;
            $all++;
            if ((string)($w['spawn']['parent'] ?? $w['createdBy'] ?? '') === $parentId) $mine++;
            if ((string)($w['agentId'] ?? '') === $agentId) $kind++;
        }
        return [$all, $mine, $kind];
    }

    /** "Mon 2 Nov, 14:05" in the server's local time, as the tray shows a schedule. */
    private static function whenText(int $at): string
    {
        return date('D j M, H:i', $at);
    }

    /**
     * Create a sub-agent workspace, deliver its task and start it, in one call (spec R1).
     *
     * $p keys: agentId, name, path OR worktree{repo,branch,base}, model, effort, task, taskTitle,
     * unattended (bool), autoClose (bool, default true), box (refused when set).
     *
     * @param array<string,mixed> $p
     * @return array<string,mixed>
     */
    public static function spawn(array $p): array
    {
        try {
            if (!self::enabled()) return ['error' => self::disabledMessage()];

            // R19: a sub-agent starts on this box only.
            if (trim((string)($p['box'] ?? '')) !== '') {
                return ['error' => 'A sub-agent workspace can start only on this box. Starting one on a linked box is not supported.'];
            }

            $caller = self::spawnCaller();
            if (isset($caller['error'])) return $caller;
            return self::spawnCore($p, (string)$caller['id'], (string)($caller['record']['name'] ?? $caller['id']), []);
        } catch (\Throwable $e) {
            return ['error' => 'The sub-agent workspace could not be started: ' . self::safeText($e->getMessage(), 300)];
        }
    }

    /**
     * Start the workspace of one script run (docs/specs/AGENT_RUN_ENTRY_POINT.md R28, issue #745). It skips only
     * spawnCaller(): a run has no parent workspace. The caller is the script that AgentRunService has already
     * checked. The run switch (`run_enabled`) replaces the sub-agent switch. No agent tool calls this method.
     *
     * $p keys are the keys of spawn() (agentId, name, path, model, effort, task, taskTitle, autoClose, maxMinutes).
     * $run keys: runId (required), label (the caller script label), readOnly (bool), unattended (bool, only for a
     * run that a person approved), mode ('read-only' or 'may-change'), script (path), key.
     *
     * @param array<string,mixed> $p
     * @param array<string,mixed> $run
     * @return array<string,mixed>
     */
    public static function spawnForRun(array $p, array $run): array
    {
        try {
            $runId = (string)($run['runId'] ?? '');
            if (preg_match('/^[A-Za-z0-9]{6,32}$/D', $runId) !== 1) return ['error' => 'A run needs a run id of 6 to 32 letters and digits.'];
            $label = self::safeText((string)($run['label'] ?? ''), 60);
            if ($label === '') $label = 'script';
            // A run has no worktree and no linked box. Both are refused here, not trusted from the caller.
            if (isset($p['worktree']) || trim((string)($p['box'] ?? '')) !== '') {
                return ['error' => 'A run uses a named folder. It cannot use a worktree or a linked box.'];
            }
            return self::spawnCore($p, 'run:' . $runId, 'script ' . $label, $run + ['isRun' => true]);
        } catch (\Throwable $e) {
            return ['error' => 'The run could not be started: ' . self::safeText($e->getMessage(), 300)];
        }
    }

    /**
     * R18b (Decision 9): open a normal workspace that runs as the run user, in a new temporary folder, inside the private
     * disk view, so a person can sign in to an agent once. The sign-in stays in the home of the run user. It does not
     * count as a script run, it takes no report and it does not close itself. A person closes it after sign-in.
     *
     * @return array<string,mixed> The workspace (workspaceId) or ['error' => sentence].
     */
    public static function spawnRunUserSignIn(string $agentId): array
    {
        try {
            $account = RunUserService::status();
            if (empty($account['ready'])) return ['error' => (string)$account['fault']];
            $agentId = trim($agentId);
            $registry = AgentRegistry::getRegistry();
            $installed = $agentId !== '' && isset($registry[$agentId])
                && (self::installedProbe() !== null ? (bool)(self::installedProbe())($agentId) : !empty($registry[$agentId]['is_installed']));
            if ($agentId === 'terminal' || !$installed) return ['error' => 'Choose an installed agent.'];
            $uid = (int)$account['uid'];
            $gid = (int)$account['gid'];
            // One restricted workspace is open at a time (finding 10).
            if (AgentRunService::restrictedRunOpen() || RunUserService::otherOpenRestricted('') !== '') {
                return ['error' => 'A script run as aicli-run is open. Only one restricted workspace is open at a time. Close it first, then sign in.'];
            }
            $runId = 's' . bin2hex(random_bytes(5));
            $temp = AgentRunService::makeRunUserTempFolder($runId, $uid, $gid);
            if (isset($temp['error'])) return ['error' => (string)$temp['error']];
            $task = RunUserService::createTaskFolder($runId);
            if (isset($task['error'])) {
                AgentRunService::discardRunTempFolder($runId);
                return ['error' => (string)$task['error']];
            }
            $label = (string)($registry[$agentId]['name'] ?? $agentId);
            $result = self::spawnCore([
                'agentId' => $agentId, 'name' => 'Sign in for script runs', 'path' => $temp['path'],
                'task' => 'A person signs in to ' . $label . ' here, for script runs. Wait for the person to finish. Do not read or change other files.',
                'taskTitle' => 'Sign in for script runs', 'autoClose' => false,
            ], 'person', 'person', [
                'runAs' => RunUserService::USER, 'runUid' => $uid, 'runGid' => $gid, 'taskDir' => (string)$task['path'],
                'runUserSignIn' => ['runId' => $runId, 'taskDir' => (string)$task['path'], 'mountWritable' => true, 'temp' => true],
            ]);
            if (isset($result['error'])) {
                RunUserService::removeTaskFolder($runId);
                AgentRunService::discardRunTempFolder($runId);
            }
            return $result;
        } catch (\Throwable $e) {
            return ['error' => 'The sign-in workspace could not be opened: ' . self::safeText($e->getMessage(), 300)];
        }
    }

    /**
     * A restricted workspace (a run or a sign-in) closed: free its persistent mount space, its root-only mark and its
     * private flags and environment. Best effort; the sweep repeats it.
     *
     * @param array<string,mixed> $record
     */
    public static function releaseRestricted(array $record): void
    {
        $id = (string)($record['id'] ?? '');
        $spawn = is_array($record['spawn'] ?? null) ? $record['spawn'] : [];
        $restricted = (string)($record['runAs'] ?? '') === RunUserService::USER
            || (string)($spawn['run']['runAs'] ?? '') === RunUserService::USER
            || is_array($spawn['runUserSignIn'] ?? null);
        if ($id === '' || !$restricted) return;
        $isSignInWorkspace = is_array($spawn['runUserSignIn'] ?? null);
        try { RunUserService::releaseWorkspace($id, $isSignInWorkspace); } catch (\Throwable $e) { /* the sweep tries again */ }
    }

    /** R18b: save known sign-in files before release removes the temporary home. @param array<string,mixed> $spawn */
    private static function afterSignInClosed(array $spawn, string $workspaceId): bool
    {
        $signIn = is_array($spawn['runUserSignIn'] ?? null) ? $spawn['runUserSignIn'] : null;
        if ($signIn === null) return true;
        if (!RunUserService::persistSigninFilesFromSession($workspaceId)) {
            error_log('aicli-run: sign-in files for workspace ' . $workspaceId . ' could not be saved; its private home stays in place');
            return false;
        }
        try { RunUserService::removeTaskFolder((string)($signIn['runId'] ?? '')); } catch (\Throwable $e) { /* the sweep or the next start tries again */ }
        return true;
    }

    /**
     * The shared part of spawn() and spawnForRun(): checks, then side effects.
     *
     * @param array<string,mixed> $p
     * @param array<string,mixed> $run Empty for a sub-agent that a workspace starts. Else the run keys of spawnForRun().
     * @return array<string,mixed>
     */
    private static function spawnCore(array $p, string $callerId, string $callerName, array $run): array
    {
        $isRun = !empty($run['isRun']);
        // R16: the run user. It is root or aicli-run. A run as aicli-run needs the account to be ready.
        $runAs = (string)($run['runAs'] ?? 'root');
        if (!in_array($runAs, ['root', RunUserService::USER], true)) return ['error' => 'The workspace run user must be root or aicli-run.'];
        $taskDir = (string)($run['taskDir'] ?? '');
        if ($runAs === RunUserService::USER) {
            $account = RunUserService::status();
            if (empty($account['ready'])) return ['error' => (string)$account['fault']];
            if ($taskDir === '' || (int)($run['runUid'] ?? 0) !== (int)$account['uid'] || (int)($run['runGid'] ?? 0) !== (int)$account['gid']) {
                return ['error' => 'A run as aicli-run needs its private task folder and the ids of the run user.'];
            }
        } elseif ($taskDir !== '') {
            return ['error' => 'Only a run as aicli-run uses a private task folder.'];
        }
        // For the undo in the catch below: what this call has made so far.
        $id = '';
        $madeWorktree = null;
        $recordSaved = false;
        try {
            $agentId = trim((string)($p['agentId'] ?? ''));
            if ($agentId === '' || $agentId === 'terminal') {
                return ['error' => 'Give an agentId of an installed agent. Ask aicli_list_agents for the ids.'];
            }
            $registry = AgentRegistry::getRegistry();
            if (!isset($registry[$agentId])) return ['error' => "Unknown agent '$agentId'. Ask aicli_list_agents for valid ids."];
            $installed = self::installedProbe() !== null ? (bool)(self::installedProbe())($agentId) : !empty($registry[$agentId]['is_installed']);
            if (!$installed) return ['error' => "The agent '$agentId' is not installed. Install it first, or choose an installed agent."];

            // The task. Its text is data: it is written to a file and never run.
            $task = (string)($p['task'] ?? '');
            $task = rtrim(str_replace("\r\n", "\n", $task));
            if (trim($task) === '') return ['error' => 'Give the task: what the sub-agent must do.'];
            if (self::length($task) > self::MAX_TASK_CHARS) {
                return ['error' => 'The task is too long. The limit is ' . self::MAX_TASK_CHARS . ' characters. Put the long brief in a file in the folder and name the file in the task.'];
            }
            if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $task)) {
                return ['error' => 'The task contains a control character other than a new line or a tab. That is not allowed.'];
            }
            $title = trim((string)($p['taskTitle'] ?? ''));
            $title = $title !== '' ? self::safeText($title, self::MAX_TITLE_CHARS) : self::safeText(self::titleFromTask($task), self::MAX_TITLE_CHARS);
            if ($title === '') $title = 'a task';

            // Folder or worktree: exactly one.
            $path = trim((string)($p['path'] ?? ''));
            $wtReq = is_array($p['worktree'] ?? null) ? $p['worktree'] : null;
            if (($path === '') === ($wtReq === null)) {
                return ['error' => 'Give a folder (path) or a worktree (repo and branch), not both and not neither.'];
            }

            // Launch flags. The unattended rule comes first because it depends on the worktree (decision 3).
            $unattended = !empty($p['unattended']);
            if ($unattended && $isRun) {
                // Decision 13 of the run spec: a run that a person approved may go unattended in a named folder.
                // AgentRunService sets this flag only after the approval. It is the named exception to the rule below.
                if (empty($run['unattended'])) return ['error' => 'A run goes unattended only after a person approved it.'];
            } elseif ($unattended) {
                if (self::settings()['spawn_allow_unattended'] !== '1') {
                    return ['error' => 'Unattended flags are turned off. A person turns them on in Settings, Sub-agent workspaces, "Let sub-agents run without asking".'];
                }
                if ($wtReq === null) {
                    return ['error' => 'Unattended flags are allowed only in a worktree that this tool makes. Give a worktree (repo and branch) instead of a folder.'];
                }
            }
            // The optional time limit (spec Edge Cases): a whole number of minutes, 1 to 1440. It never kills the sub-agent.
            $maxMinutes = 0;
            $rawMax = $p['maxMinutes'] ?? null;
            if ($rawMax !== null && trim((string)$rawMax) !== '') {
                if (preg_match('/^\d{1,4}$/D', trim((string)$rawMax)) !== 1 || (int)$rawMax < 1 || (int)$rawMax > self::MAX_MINUTES_LIMIT) {
                    return ['error' => 'maxMinutes is a whole number of minutes from 1 to ' . self::MAX_MINUTES_LIMIT . '.'];
                }
                $maxMinutes = (int)$rawMax;
            }
            $model = trim((string)($p['model'] ?? ''));
            $effort = trim((string)($p['effort'] ?? ''));
            $profile = AgentLaunchProfile::profileFor($agentId);
            $readOnly = $isRun && !empty($run['readOnly']);
            if ($readOnly) {
                // An older agent stops with "unknown option" for the read-only flags. Say which flag and which version.
                $versionError = AgentLaunchProfile::versionError($agentId, (string)($registry[$agentId]['version'] ?? ''), $profile);
                if ($versionError !== null) return ['error' => $versionError];
            }
            $flags =AgentLaunchProfile::flags($agentId, $model, $effort, $unattended, $profile, $readOnly, (string)($run["mcpConfig"] ?? ""), (string)($run["dataDir"] ?? ""), $runAs === RunUserService::USER);
            if (isset($flags['error'])) return $flags;
            $promptMode = AgentLaunchProfile::promptMode($agentId, $profile);

            // Resolve the place before any side effect.
            $worktree = null;
            if ($wtReq !== null) {
                $plan = self::planWorktree($wtReq, $agentId);
                if (isset($plan['error'])) return $plan;
                $worktree = $plan;
                $folder = $plan['path'];
            } else {
                $res = self::resolveFolder($path, 'The folder');
                if (isset($res['error'])) return $res;
                $folder = $res['path'];
                foreach ((ConfigService::getWorkspaces()['sessions'] ?? []) as $w) {
                    if (is_array($w) && (string)($w['agentId'] ?? '') === $agentId && (string)($w['path'] ?? '') === $folder) {
                        return ['error' => "A workspace of '$agentId' already exists for that folder (id " . (string)$w['id'] . '). Two workspaces on one folder share chat files. Use a worktree or another folder.', 'existingWorkspaceId' => (string)$w['id']];
                    }
                }
            }

            // Guard rails (spec section 8).
            [$total, $mine, $kind] = self::runningHelpers($callerId, $agentId);
            if ($mine >= self::cap('spawn_max_children')) {
                return ['error' => "You already have $mine running sub-agent workspaces. The limit is " . self::cap('spawn_max_children') . '. Close one first.'];
            }
            if ($total >= self::cap('spawn_max_total')) {
                return ['error' => "$total sub-agent workspaces run on this box. The limit is " . self::cap('spawn_max_total') . '. Wait until one is closed.'];
            }
            if ($kind >= self::cap('spawn_max_per_agent')) {
                return ['error' => "$kind sub-agent workspaces of '$agentId' already run. The limit is " . self::cap('spawn_max_per_agent') . ' for each agent kind.'];
            }
            $limit = self::$quotaProbe !== null ? (self::$quotaProbe)($agentId, $model) : QuotaDetectionService::activeLimit($agentId, self::now(), null, null, null, $model);
            if ($limit !== null) {
                $name = self::findRecord((string)$limit['workspaceId'])['name'] ?? (string)$limit['workspaceId'];
                return ['error' => "The agent '$agentId' shows a usage limit (workspace $name). Its limit resets at " . self::whenText((int)$limit['reset_at']) . '. A sub-agent may use the same quota, because the limit is not known to belong to a different provider than the model of this sub-agent. Start the sub-agent after that time.', 'resetAt' => (int)$limit['reset_at']];
            }
            if (!self::gapOpen('spawn-' . $callerId, self::SPAWN_GAP_SECONDS)) {
                return ['error' => 'You started a sub-agent too recently. Wait ' . self::SPAWN_GAP_SECONDS . ' seconds and try again.'];
            }

            // ---- Side effects start here. ----
            if ($worktree !== null) {
                $made = self::makeWorktree($worktree);
                if (isset($made['error'])) return $made;
                $madeWorktree = $worktree;
            }

            $id = 's' . bin2hex(random_bytes(4));
            if (self::findRecord($id) !== null) $id = 's' . bin2hex(random_bytes(4));
            // R3 / friction 4: the sweep must not start the sub-agent before its flags and task are in place.
            AutoLaunchSuppression::suppress($id);

            $name = trim((string)($p['name'] ?? ''));
            if ($name === '') $name = basename($folder) ?: 'Sub-agent';
            $autoClose = !array_key_exists('autoClose', $p) || !empty($p['autoClose']);
            $wtMeta = $worktree !== null ? ['repo' => $worktree['repo'], 'branch' => $worktree['branch'], 'base' => $worktree['base'], 'path' => $worktree['path'], 'tool_made' => true] : null;
            $record = [
                'id' => $id, 'name' => $name, 'path' => $folder, 'agentId' => $agentId, 'lastActive' => null,
                'createdBy' => $callerId, 'createdAt' => self::now(),
                'spawned' => true,
                'spawn' => self::newSpawnMeta($callerId, $callerName, $title, $autoClose, $unattended, $wtMeta, $model, $effort, $maxMinutes),
            ];
            if ($runAs !== 'root') $record['runAs'] = $runAs;
            if ($isRun) {
                // R28: the mark "started by script <label>" for the drawer. The label comes from the list, not from the caller.
                $record['createdBy'] = 'run:' . self::safeText((string)($run['label'] ?? 'script'), 60);
                $record['spawn']['run'] = [
                    'runId' => (string)$run['runId'], 'mode' => (string)($run['mode'] ?? 'read-only'),
                    'script' => self::safeText((string)($run['script'] ?? ''), 300), 'key' => self::safeText((string)($run['key'] ?? ''), 200),
                ];
                if ($runAs !== 'root') {
                    $record['spawn']['run'] += ['runAs' => $runAs, 'taskDir' => $taskDir, 'runUid' => (int)$run['runUid'], 'runGid' => (int)$run['runGid'],
                        'mountWritable' => !empty($run['mountWritable']), 'temp' => !empty($run['temp']),
                        'readFolders' => array_values(array_filter((array)($run['readFolders'] ?? []), 'is_string'))];
                }
            } elseif (is_array($run['runUserSignIn'] ?? null)) {
                // R18b: the sign-in workspace of the run user. It is a normal workspace that a person controls.
                $record['spawn']['runUserSignIn'] = $run['runUserSignIn'] + ['runAs' => $runAs];
            }
            if (!ConfigService::saveWorkspaces(['sessions' => [$record]], [])) {
                // #865 third review 1: no record exists, so only the mark, the worktree and the branch of this call are undone.
                return ['error' => (ConfigService::lastWorkspaceSaveMessage() ?? 'Could not save the sub-agent workspace.') . ' ' . self::undoWithoutRecord($id, $madeWorktree)];
            }
            $recordSaved = true;
            if (!ArgsService::saveIdArgs($id, (string)$flags['args'], $promptMode)) {
                return ['error' => 'The flags of the sub-agent workspace could not be written. ' . self::rollbackSpawn($id, $worktree)];
            }
            // R18: the first line of a run as the run user names the private task file and the report command.
            $firstLine = $taskDir === ''
                ? self::FIRST_LINE
                // Finding 13 of the second review: a read-only run has no shell, so it cannot call the command. It reports with the
                // report tool of the MCP server; a run that may change files reports with the command.
                : ('Your task is in ' . $taskDir . '/TASK.md. Read it, do it, then report with '
                    . (!empty($run['readOnly']) ? 'the report tool (mcp__aicli-run-report__report).' : 'aicli-run-report.'));
            if (is_array($run['runUserSignIn'] ?? null)) {
                $firstLine = 'Your task is in ' . $taskDir . '/TASK.md. Read it, then wait for the person to sign in. Do not call a report tool.';
            }
            $filesNote = self::writeTaskFiles($folder, $task, $promptMode !== AgentLaunchProfile::PROMPT_NONE, $worktree, $agentId,
                $taskDir, (int)($run['runUid'] ?? 0), (int)($run['runGid'] ?? 0), $firstLine);
            if (isset($filesNote['error'])) {
                // #865 review 3: undo what this call made, so no record with flags (unattended, prompt mode) is left over.
                return ['error' => $filesNote['error'] . ' ' . self::rollbackSpawn($id, $worktree)];
            }
            // The task folder is complete: hand it to the run user, once. The agent starts only after this.
            if ($taskDir !== '' && !RunUserService::finalizeTaskFolder($taskDir, (int)$run['runUid'], (int)$run['runGid'])) {
                return ['error' => 'The private task folder could not be handed to the run user. ' . self::rollbackSpawn($id, $worktree)];
            }

            // R20: no "Trust this folder?" question for a worktree that this tool made. R21: the agent's commands keep
            // the AICLI_* variables. Neither stops the spawn: a failure is reported in the reply.
            $trust = 'not_applicable';
            if ($wtMeta !== null && AgentLaunchProfile::trustMode($agentId, $profile) === 'codex_toml') {
                $trust = !empty(CodexTrustService::trustWorktree($wtMeta)['ok']) ? 'trusted' : 'failed';
            }
            // A run as the run user has no Relay and no admin command (R18), so its command environment is not written.
            $relayEnv = $runAs === RunUserService::USER ? 'restricted_run_user' : AgentCommandEnvService::ensure($agentId, CodexTrustService::$homeForTests)['state'];

            AutoLaunchSuppression::clear($id);
            $started = self::startWorkspaceProcess($id, $folder, $agentId, true);

            $delivery = $promptMode !== AgentLaunchProfile::PROMPT_NONE ? 'initial_prompt' : 'pane_input';
            $deliveryNote = '';
            if ($started['ok'] && $promptMode === AgentLaunchProfile::PROMPT_NONE) {
                $typed = self::typeFirstLine($agentId, $id, $firstLine);
                if (!$typed) {
                    $delivery = 'pane_input_pending';
                    $deliveryNote = 'The pane was not ready in time, so the first line was not typed. Use aicli_send_input to send: ' . $firstLine;
                }
            } elseif (!$started['ok']) {
                $delivery = 'not_started';
                $deliveryNote = 'The sub-agent workspace was saved but did not start (' . (string)($started['reason'] ?? 'unknown') . '). Use aicli_start_workspace to try again.';
            }

            $created = self::findRecord($id) ?? $record;
            self::emit('workspace.spawned', ['workspaceId' => $id, 'agentId' => $agentId],
                ($isRun ? "Run workspace '$name'" : "Sub-agent workspace '$name'") . " started by '$callerName'",
                ['parent' => $callerId, 'taskTitle' => $title, 'autoClose' => $autoClose, 'delivery' => $delivery]);

            $mine2 = self::runningHelpers($callerId, $agentId)[1];
            $result = [
                'id' => $id, 'workspaceId' => $id, 'name' => $name, 'path' => $folder, 'agentId' => $agentId,
                'started' => (bool)$started['ok'], 'taskDelivery' => $delivery,
                'children' => $mine2 . '/' . self::cap('spawn_max_children'),
                'autoClose' => $autoClose, 'taskFile' => ($taskDir !== '' ? $taskDir : $folder . '/' . self::TASK_DIR) . '/TASK.md',
                'parentName' => $callerName, 'taskTitle' => $title, 'model' => $model, 'effort' => $effort,
                'state' => (string)($created['spawn']['state'] ?? 'starting'),
                'trust' => $trust, 'relayEnv' => $relayEnv, 'maxMinutes' => $maxMinutes,
            ];
            if ($worktree !== null) $result['worktree'] = ['path' => $worktree['path'], 'branch' => $worktree['branch'], 'base' => $worktree['base'], 'sparse' => $worktree['sparse'] ?? []];
            if (!empty($filesNote['note'])) $result['note'] = $filesNote['note'];
            if ($deliveryNote !== '') $result['deliveryNote'] = $deliveryNote;
            return $result;
        } catch (\Throwable $e) {
            // An exception before the record was saved leaves a worktree, a branch and a hold mark that nothing else removes.
            $undo = !$recordSaved && ($id !== '' || $madeWorktree !== null) ? ' ' . self::undoWithoutRecord($id, $madeWorktree) : '';
            return ['error' => 'The sub-agent workspace could not be started: ' . self::safeText($e->getMessage(), 300) . $undo];
        }
    }

    /** @return array<string,mixed> */
    /**
     * #783: the FIRST start of a new sub-agent or script run is always fresh (`_fresh_`): it is a new task, and a
     * resume could only pick an older conversation of the folder (or, before #783, another workspace's). A later
     * start of a closed workspace (aicli_start_workspace) resumes its own conversation (`auto`).
     */
    private static function startWorkspaceProcess(string $id, string $path, string $agentId, bool $fresh = false): array
    {
        $chatId = $fresh ? '_fresh_' : 'auto';
        if (self::$startRunner !== null) return (self::$startRunner)($id, $path, $agentId, $chatId);
        return TerminalService::startTerminal($id, $path, $chatId, $agentId, 'spawn');
    }

    /**
     * Type the fixed first line into the pane of an agent that has no prompt flag (R22), as soon as the pane
     * is ready. The wait is bounded; the call does not block longer than PANE_WAIT_SECONDS (or $waitSeconds, 0 for
     * one check). CommandApprovalService types an approved command line through this method too.
     */
    public static function typeFirstLine(string $agentId, string $id, string $firstLine = self::FIRST_LINE, ?int $waitSeconds = null): bool
    {
        $wait = $waitSeconds ?? self::$paneWaitSeconds ?? self::PANE_WAIT_SECONDS;
        $deadline = self::now() + $wait;
        $sleep = self::$sleeper ?? static function (int $ms): void { usleep($ms * 1000); };
        do {
            $gate = self::gate($agentId, $id);
            if ($gate['ready']) {
                $res = self::$paneTyper !== null
                    ? (self::$paneTyper)($agentId, $id, $firstLine, true)
                    : TmuxService::pasteAndConfirm($agentId, $id, $firstLine, true);
                return ($res['status'] ?? '') === 'ok';
            }
            $sleep(1000);
        } while (self::now() < $deadline);
        return false;
    }

    /**
     * Check a worktree request and compute where the worktree goes. Changes nothing.
     *
     * @param array<string,mixed> $req repo, branch, base, sparse
     * @return array<string,mixed>|array{error:string}
     */
    private static function planWorktree(array $req, string $agentId = ''): array
    {
        $repoIn = trim((string)($req['repo'] ?? ''));
        $branch = trim((string)($req['branch'] ?? ''));
        $base = trim((string)($req['base'] ?? ''));
        if ($repoIn === '' || $branch === '') return ['error' => 'A worktree needs a repo (the folder of a git repository) and a branch name.'];
        $repoRes = self::resolveFolder($repoIn, 'The repo');
        if (isset($repoRes['error'])) return $repoRes;
        $repo = $repoRes['path'];

        foreach (['branch' => $branch, 'base' => $base] as $label => $ref) {
            if ($ref === '' && $label === 'base') continue;
            if (preg_match('/^[A-Za-z0-9._\/-]{1,100}$/D', $ref) !== 1 || strpos($ref, '..') !== false || $ref[0] === '-' || $ref[0] === '/'
                || substr($ref, -1) === '/' || substr($ref, -5) === '.lock') {
                return ['error' => "The $label name '$ref' is not allowed. Use letters, digits and the characters . _ / - only, up to 100 characters, and no '..'."];
            }
        }
        $inside = self::git(['rev-parse', '--is-inside-work-tree'], $repo);
        if ($inside['rc'] !== 0 || trim($inside['out']) !== 'true') {
            return ['error' => "The repo '$repoIn' is not a git work tree."];
        }
        // #865 item 2: the folder name comes from the agent's profile, with a neutral default.
        $dirRel = AgentLaunchProfile::worktreeDir($agentId);
        // #865 decision B: no part of the worktree folder path may be a link. A link in the repo (it can be committed)
        // would send `git worktree add` and the .gitignore write to a folder somewhere else. Nothing has changed yet.
        $walk = $repo;
        foreach (explode('/', $dirRel) as $part) {
            $walk .= '/' . $part;
            if (is_link($walk)) return ['error' => "The worktree folder '$walk' is a link. It was not used. Nothing was started."];
            if (file_exists($walk) && !is_dir($walk)) return ['error' => "The worktree folder '$walk' is not a folder. Nothing was started."];
        }
        $parentIgnore = $repo . '/' . $dirRel . '/.gitignore';
        if (is_link($parentIgnore)) return ['error' => "The file '$parentIgnore' is a link. It was not used. Nothing was started."];
        if (file_exists($parentIgnore) && !is_file($parentIgnore)) return ['error' => "The path '$parentIgnore' exists and is not a regular file (a folder or a special file). It was not used. Nothing was started."];
        if (is_file($parentIgnore) && !self::hasIgnoreAll((string)@file_get_contents($parentIgnore))) {
            return ['error' => "The file '$parentIgnore' exists and does not ignore its folder. Add the line * to it, or remove it. Nothing was started."];
        }
        $dir = $repo . '/' . $dirRel . '/' . str_replace('/', '-', $branch);
        if (file_exists($dir)) return ['error' => "The worktree folder '$dir' exists already. Choose another branch name."];
        $exists = self::git(['show-ref', '--verify', '--quiet', 'refs/heads/' . $branch], $repo);
        if ($exists['rc'] === 0) return ['error' => "The branch '$branch' exists already. Choose another branch name."];
        if ($base === '') {
            // Name the branch that is checked out now, so the later "is it merged" check has a fixed ref.
            $head = self::git(['symbolic-ref', '--short', '-q', 'HEAD'], $repo);
            $base = $head['rc'] === 0 && trim($head['out']) !== '' ? trim($head['out']) : 'HEAD';
        }
        // #865 review 3: a base that commits `.aicli-task` (a file, a folder or a link) is refused before any side effect.
        // The worktree folder is the top of the new work tree, so the path is the root-relative name.
        $task = self::git(['ls-tree', '--full-tree', $base, '--', self::TASK_DIR], $repo);
        if ($task['rc'] !== 0) return ['error' => "git could not read the base '$base' to check it for '" . self::TASK_DIR . "'. Nothing was started."];
        if (trim($task['out']) !== '') {
            return ['error' => "The base '$base' holds a path '" . self::TASK_DIR . "' in git. The plugin keeps the task of a sub-agent there, and a committed file or link in that place could be read as the first prompt. Remove it from the repo, or use another base. Nothing was started."];
        }
        // #865 item 3: the sparse list of the call, else the repo file .aicli/worktree-sparse.
        $sparse = self::sparseList($req['sparse'] ?? null, $repo, $base);
        if (isset($sparse['error'])) return $sparse;
        return ['repo' => $repo, 'branch' => $branch, 'base' => $base, 'path' => $dir, 'dir_rel' => $dirRel, 'sparse' => $sparse['paths'], 'prefix' => $sparse['prefix']];
    }

    /** The repo file with the default sparse folders, one folder for each line. */
    public const SPARSE_FILE = '.aicli/worktree-sparse';

    /** The most sparse folders a worktree may list. */
    public const MAX_SPARSE = 50;

    /** The largest sparse file, in bytes. A larger file is an error, never a silent full checkout. */
    public const MAX_SPARSE_FILE = 65536;

    /** A line of input that is shown in a reply: printable ASCII only, cut at 60 characters. */
    private static function shortLine(string $line): string
    {
        $line = (string)preg_replace('/[^\x20-\x7E]/', '?', $line);
        return strlen($line) > 60 ? substr($line, 0, 60) . '...' : $line;
    }

    /**
     * Check the sparse folder list of a worktree request. A folder is a repo-relative path of plain characters:
     * no dot-dot, no dot part, no leading dash or slash. The list from the call wins; without one the repo file is
     * read. An empty list means a full checkout. Every folder must be a folder (a tree) in the base ref: `git ls-tree
     * -d <base> -- <path>` (#865 decision F), so `.`, a file name and a missing folder are refused before any side effect.
     *
     * @param mixed $given the `sparse` value of the call (a list of strings) or null
     * @return array{paths:list<string>,prefix:string}|array{error:string}
     */
    private static function sparseList($given, string $repo, string $base): array
    {
        $raw = [];
        if (is_array($given)) {
            $raw = array_map('strval', array_values($given));
        } elseif ($given !== null && $given !== '') {
            return ['error' => 'The sparse value must be a list of folder names in the repo.'];
        } else {
            $file = $repo . '/' . self::SPARSE_FILE;
            if (is_link($file)) return ['error' => "The sparse file '$file' is a link. It was not used. Nothing was started."];
            if (is_file($file)) {
                if ((int)@filesize($file) > self::MAX_SPARSE_FILE) {
                    return ['error' => "The sparse file '$file' is larger than " . (self::MAX_SPARSE_FILE / 1024) . ' KB. Make it smaller, or give the sparse list in the call. Nothing was started.'];
                }
                $raw = preg_split('/\R/', (string)@file_get_contents($file)) ?: [];
            }
        }
        $paths = [];
        foreach ($raw as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            $line = rtrim($line, '/');
            $bad = $line === '' || preg_match('#^[A-Za-z0-9._][A-Za-z0-9._/ -]{0,199}$#D', $line) !== 1 || strpos($line, '//') !== false;
            foreach (explode('/', $line) as $seg) if ($seg === '.' || $seg === '..') $bad = true;
            if ($bad) {
                return ['error' => "The sparse folder '" . self::shortLine($line) . "' is not allowed. Use a folder path inside the repo: letters, digits and the characters . _ / - only, and no '.' or '..' part."];
            }
            $paths[] = $line;
        }
        if (count($paths) > self::MAX_SPARSE) return ['error' => 'The sparse list has more than ' . self::MAX_SPARSE . ' folders.'];
        $paths = array_values(array_unique($paths));
        foreach ($paths as $p) {
            $t = self::git(['ls-tree', '-d', $base, '--', $p], $repo);
            if ($t['rc'] !== 0) return ['error' => "git could not read the base '$base' to check the sparse folder '" . self::shortLine($p) . "'. Nothing was started."];
            $first = trim((string)strtok($t['out'], "\n"));
            if ($first === '') return ['error' => "The sparse folder '" . self::shortLine($p) . "' does not exist in '$base'. Nothing was started."];
            if (preg_match('/^\d+ tree /', $first) !== 1) return ['error' => "The sparse entry '" . self::shortLine($p) . "' is not a folder in '$base'. Nothing was started."];
        }
        // The paths of `sparse-checkout set` are relative to the top of the git work tree, the paths above to the repo folder.
        $prefix = '';
        if ($paths !== []) {
            $pre = self::git(['rev-parse', '--show-prefix'], $repo);
            if ($pre['rc'] === 0) $prefix = trim($pre['out']);
        }
        return ['paths' => $paths, 'prefix' => $prefix];
    }

    /**
     * Make the worktree: `git worktree add <dir> -b <branch> <base>`. Never push, merge or reset.
     *
     * @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    private static function makeWorktree(array $plan): array
    {
        $parent = dirname($plan['path']);
        if (!is_dir($parent) && !@mkdir($parent, 0775, true) && !is_dir($parent)) {
            return ['error' => "Could not create the folder '$parent' for the worktree."];
        }
        // #865 decision B: just before the first git write, the real path of the parent must be the repo plus the folder.
        if (defined('AICLI_TEST_TMP') && self::$afterCheck !== null) (self::$afterCheck)('worktree-parent');
        $expected = (string)@realpath($plan['repo']) . '/' . $plan['dir_rel'];
        if (@realpath($parent) !== $expected) {
            return ['error' => "The worktree folder '$parent' does not lead to '$expected' (a link in the path). It was not used. Nothing was started."];
        }
        // #865 review 4: the ignore file is written BEFORE `git worktree add`, so a failure leaves no orphan worktree or branch.
        $ign = self::writeParentIgnore($parent);
        if ($ign !== null) return ['error' => $ign . ' Nothing was started.'];
        $sparse = $plan['sparse'] ?? [];
        // #865 item 3: with a sparse list the worktree is made without a checkout, the sparse folders are set, and only
        // then the files are checked out, so the files outside the list are never written to the disk.
        $add = ['worktree', 'add'];
        if ($sparse !== []) $add[] = '--no-checkout';
        $r = self::git(array_merge($add, [$plan['path'], '-b', $plan['branch'], $plan['base']]), $plan['repo']);
        if ($r['rc'] !== 0) {
            // #865 third review 3: git can fail after it made the branch (a failing checkout filter). planWorktree showed that the
            // branch did not exist before, so it is ours: remove a half-made worktree and the branch, and say what is true.
            $left = '';
            $exists = self::git(['show-ref', '--verify', '--quiet', 'refs/heads/' . $plan['branch']], $plan['repo']);
            if (is_dir($plan['path'])) {
                $rmw = self::git(['worktree', 'remove', '--force', $plan['path']], $plan['repo']);
                if ($rmw['rc'] !== 0) $left .= ' The half-made worktree ' . $plan['path'] . ' could not be removed. Remove it by hand.';
            }
            self::git(['worktree', 'prune'], $plan['repo']);
            if ($exists['rc'] === 0) {
                $brd = self::git(['branch', '-D', $plan['branch']], $plan['repo']);
                $left .= $brd['rc'] === 0 ? ' The branch ' . $plan['branch'] . ' that git made was removed.' : ' The branch ' . $plan['branch'] . ' could not be removed. Remove it by hand.';
            }
            return ['error' => 'git could not make the worktree (base ' . $plan['base'] . '). Check that the base exists. Nothing was started.' . $left];
        }
        if ($sparse !== []) {
            $prefix = (string)($plan['prefix'] ?? '');
            $set = self::git(array_merge(['sparse-checkout', 'set', '--cone', '--'], array_map(static fn(string $p): string => $prefix . $p, $sparse)), $plan['path']);
            $co = $set['rc'] === 0 ? self::git(['checkout', '-f', $plan['branch']], $plan['path']) : $set;
            if ($co['rc'] !== 0) {
                // Take the half-made worktree away again: nothing is in it yet that a person wrote. Say what is true.
                $rm = self::git(['worktree', 'remove', '--force', $plan['path']], $plan['repo']);
                $br = $rm['rc'] === 0 ? self::git(['branch', '-D', $plan['branch']], $plan['repo']) : ['rc' => 1, 'out' => ''];
                if ($rm['rc'] !== 0) {
                    return ['error' => 'git could not apply the sparse folders, and could not remove the half-made worktree ' . $plan['path'] . ' (branch ' . $plan['branch'] . '). Remove them by hand. Nothing was started.'];
                }
                if ($br['rc'] !== 0) {
                    return ['error' => 'git could not apply the sparse folders. The worktree was removed, but the branch ' . $plan['branch'] . ' could not be removed. Remove it by hand. Nothing was started.'];
                }
                return ['error' => 'git could not apply the sparse folders to the worktree. The worktree and its branch were removed. Nothing was started.'];
            }
        }
        return ['ok' => true];
    }

    /**
     * Write a file without following a link at its name: refuse a link, write to a new temporary name in the same
     * folder (exclusive create, so an existing name is never opened), then rename it over the target. A rename
     * replaces a link and never follows it.
     */
    public static function writeNoFollow(string $file, string $body, int $mode = 0600): bool
    {
        if (is_link($file)) return false;
        $tmp = dirname($file) . '/.tmp-' . bin2hex(random_bytes(6));
        // The mode is set when the file is made (through the umask), never afterwards by its name: a name can be
        // swapped for a link between two calls, and a chmod by name would then change the target (fourth review, 1).
        $old = umask(0777 & ~$mode);
        try {
            $h = @fopen($tmp, 'x');
        } finally {
            umask($old);
        }
        if ($h === false) return false;
        $ok = fwrite($h, $body) === strlen($body);
        fclose($h);
        if (defined('AICLI_TEST_TMP') && self::$afterCheck !== null) (self::$afterCheck)('write:' . $tmp);
        if (!$ok || !@rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    /** @var callable|null fn(string $what): void. A test swaps a folder for a link in the gap between a check and its use. PHPUnit only. */
    public static $afterCheck = null;

    /**
     * Enter $dir and run $fn there, with relative names only. The folder of a run belongs to a person, so between a
     * check and its use the person could swap it for a link. After the chdir the plugin asks the kernel where it is:
     * getcwd() must be $expected (a path built from the resolved parent, not by following $dir), and the folder must
     * be a real folder, and, when $rootOwned, owned by the user that runs the plugin. Anything else gives
     * [false, null] and $fn does not run (security review of #745, finding C1).
     *
     * @param callable():mixed $fn
     * @return array{0:bool,1:mixed}
     */
    private static function inDir(string $dir, string $expected, bool $rootOwned, callable $fn): array
    {
        $previous = getcwd();
        if (!@chdir($dir)) return [false, null];
        try {
            if (getcwd() !== $expected) return [false, null];
            $st = @lstat('.');
            if ($st === false || ($st['mode'] & 0170000) !== 0040000) return [false, null];
            if ($rootOwned && \function_exists('posix_geteuid') && $st['uid'] !== posix_geteuid()) return [false, null];
            return [true, $fn()];
        } finally {
            if ($previous !== false) @chdir($previous);
        }
    }

    /**
     * Write files into <folder>/.aicli-task and nowhere else. Returns null on success, else one sentence.
     *
     * @param array<string,string> $files file name (no path) => content
     */
    private static function writeInTaskDir(string $folder, array $files): ?string
    {
        $real = @realpath($folder);
        if ($real === false || !is_dir($real)) return "The folder '$folder' cannot be used.";
        $dir = $real . '/' . self::TASK_DIR;
        // The task folder may be a link that someone planted (the folder of a run belongs to a person, and root writes
        // here). A link, a file or a folder of another owner is refused.
        if (is_link($dir)) return "The task folder '$dir' is a link. It was not used.";
        if (file_exists($dir) && !is_dir($dir)) return "The task folder '$dir' is not a folder.";
        if (is_dir($dir)) {
            $owner = @fileowner($dir);
            if ($owner === false || (\function_exists('posix_geteuid') && $owner !== posix_geteuid())) {
                return "The task folder '$dir' belongs to another user. It was not used.";
            }
        } elseif (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return "Could not create the task folder '$dir'.";
        }
        if (defined('AICLI_TEST_TMP') && self::$afterCheck !== null) (self::$afterCheck)('task');
        // Every later step uses names relative to the folder that was entered, and chmod('.').
        [$entered, $failed] = self::inDir($dir, $dir, true, static function () use ($files): ?string {
            @chmod('.', 0700);
            foreach ($files as $name => $body) {
                if (!self::writeNoFollow($name, $body)) return "Could not write '$name' in the task folder (a link in the way, or no permission).";
            }
            return null;
        });
        if (!$entered) return "The task folder '$dir' changed while it was used (it may have been replaced by a link). Nothing was written.";
        return is_string($failed) ? $failed : null;
    }

    /**
     * Take back the auto-launch mark that THIS call set. This is an undo, not a start: it clears the mark only when it was
     * not there before the call (a workspace stopped on purpose keeps its mark). The guard test of the marker lists this
     * method by name; no other part of closeHelper may clear it.
     */
    private static function undoOwnMark(string $id, bool $wasSuppressed): void
    {
        if (!$wasSuppressed) AutoLaunchSuppression::clear($id);
    }

    /**
     * Undo a spawn that failed after its record was saved: the record, the saved flags, the hold mark and, for a worktree
     * that this call made, the worktree and its branch (the same way as the sparse failure path). Says what is true.
     *
     * @param array<string,mixed>|null $worktree the plan of a worktree made by this call
     */
    private static function rollbackSpawn(string $id, ?array $worktree): string
    {
        $parts = [];
        $removed = false;
        try {
            $workspaces = ConfigService::getWorkspaces();
            $remaining = array_values(array_filter($workspaces['sessions'] ?? [], static fn($w): bool => is_array($w) && (string)($w['id'] ?? '') !== $id));
            $removed = (bool)ConfigService::saveWorkspaces(['sessions' => $remaining, 'activeId' => $workspaces['activeId'] ?? null], [$id]);
        } catch (\Throwable $e) {
            $removed = false;
        }
        ArgsService::deleteIdArgs($id);
        if ($removed) {
            $parts[] = 'The workspace record was removed.';
            self::undoOwnMark($id, false);
        } else {
            // #865 third review 2: the hold mark STAYS, so the auto-launch sweep does not start the leftover record.
            $parts[] = "The workspace record '$id' could not be removed, so it stays held and is not started. Remove it by hand.";
        }
        if ($worktree !== null) $parts[] = self::undoWorktree($worktree);
        return 'Nothing was started. ' . implode(' ', $parts);
    }

    /**
     * Undo a spawn that failed BEFORE its record was saved: the hold mark of this call, and the worktree and branch that
     * this call made. There is no record to remove. Says what is true.
     *
     * @param array<string,mixed>|null $worktree
     */
    private static function undoWithoutRecord(string $id, ?array $worktree): string
    {
        if ($id !== '') self::undoOwnMark($id, false);
        return 'Nothing was started.' . ($worktree !== null ? ' ' . self::undoWorktree($worktree) : '');
    }

    /**
     * Remove the worktree and the branch that this call made. Returns one sentence that says what is true.
     *
     * @param array<string,mixed> $worktree the plan of the worktree
     */
    private static function undoWorktree(array $worktree): string
    {
        $rm = self::git(['worktree', 'remove', '--force', (string)$worktree['path']], (string)$worktree['repo']);
        if ($rm['rc'] !== 0) {
            return 'The worktree ' . $worktree['path'] . ' (branch ' . $worktree['branch'] . ') could not be removed. Remove them by hand.';
        }
        $br = self::git(['branch', '-D', (string)$worktree['branch']], (string)$worktree['repo']);
        return $br['rc'] === 0 ? 'The worktree and its branch were removed.' : 'The worktree was removed, but the branch ' . $worktree['branch'] . ' could not be removed. Remove it by hand.';
    }

    /**
     * #865 item 4: the branch rules that the plugin adds to TASK.md of a worktree helper. The text names no agent.
     * The rule comes from the launch profile of the agent (`worktree.push`): the default lets the helper push its
     * own branch; a profile with `push: none` tells it not to push at all.
     *
     * @param array<string,mixed> $worktree
     */
    private static function branchRules(array $worktree, string $agentId): string
    {
        $branch = (string)$worktree['branch'];
        $base = (string)$worktree['base'];
        $out = "\n---\nBranch rules (written by the plugin):\n- Work only in this worktree, on the branch '$branch'. Commit your work on that branch.\n";
        // #896: a repo with no remote `origin` has nowhere to push, so that rule would stop the helper.
        $hasOrigin = self::git(['remote', 'get-url', 'origin'], (string)$worktree['repo'])['rc'] === 0;
        if (AgentLaunchProfile::pushRule($agentId) === AgentLaunchProfile::PUSH_NONE || !$hasOrigin) {
            $out .= "- Do not push. Your parent takes the branch from the local repo and merges it.\n";
        } else {
            $out .= "- Push your branch: git push -u origin $branch\n- Never push to '$base', to main or to master. Your parent merges your branch.\n";
        }
        return $out . "- Leave the folder " . self::TASK_DIR . "/ alone. Git ignores it.\n";
    }

    /**
     * Write the task files into the sub-agent's folder: TASK.md (the brief), a .gitignore with the line `*` (so git
     * ignores the whole task folder, also the .gitignore itself) and, when the agent takes a first prompt, PROMPT.once
     * (the fixed first line). #865 decision A: nothing is written to `.git/info/exclude` any more.
     *
     * @return array{note?:string}|array{error:string}
     */
    private static function writeTaskFiles(string $folder, string $task, bool $withPrompt, ?array $worktree = null, string $agentId = '',
        string $taskDir = '', int $uid = 0, int $gid = 0, string $firstLine = self::FIRST_LINE): array
    {
        if ($taskDir !== '') {
            // R18: the task of a run as the run user goes to its private folder (root owned until it is handed over).
            // Nothing is written into the run folder, which the run user can change.
            $private = ['TASK.md' => $task . "\n"];
            if ($withPrompt) $private['PROMPT.once'] = $firstLine;
            foreach ($private as $name => $body) {
                if (!RunUserService::writeTaskFile($taskDir, $name, $body, $uid, $gid)) {
                    return ['error' => "Could not write '$name' in the private task folder."];
                }
            }
            return [];
        }
        $files = ['TASK.md' => $task . "\n" . ($worktree !== null ? self::branchRules($worktree, $agentId) : ''), '.gitignore' => self::IGNORE_ALL];
        if ($withPrompt) $files['PROMPT.once'] = self::FIRST_LINE;
        $err = self::writeInTaskDir($folder, $files);
        if ($err !== null) return ['error' => $err];
        return [];
    }

    /** The content of every .gitignore that the plugin writes: ignore everything in the folder, itself included. */
    public const IGNORE_ALL = "*\n";

    /**
     * #865 decision A: put a .gitignore with `*` into the worktree parent folder (`<repo>/<worktree.dir>/`). Git then
     * ignores every worktree under it, so `git add -A` in the checkout of the repo, or in a larger repo that holds
     * it, records no gitlink. Why not `.git/info/exclude`: it is the file of the repo that holds `.git`, so it does
     * not work for a repo inside a larger repo, and writing it meant opening a folder that a person may control.
     * An existing .gitignore that is not exactly this one is refused by planWorktree() before any side effect.
     */
    private static function writeParentIgnore(string $parent): ?string
    {
        $file = $parent . '/.gitignore';
        if (is_link($file)) return "The file '$file' is a link. It was not used.";
        if (file_exists($file) && !is_file($file)) return "The path '$file' exists and is not a regular file. It was not used.";
        if (is_file($file) && self::hasIgnoreAll((string)@file_get_contents($file))) return null;
        if (!self::writeNoFollow($file, self::IGNORE_ALL, 0644)) return "Could not write '$file'.";
        return null;
    }

    private static function hasIgnoreAll(string $content): bool
    {
        foreach (preg_split('/\R/', $content) ?: [] as $line) if (trim($line) === '*') return true;
        return false;
    }
    // ======================================================================
    // Slice (c): aicli_start_workspace and aicli_stop_workspace
    // ======================================================================

    /**
     * Start a closed workspace. Any caller may start any existing workspace (John, 2026-10-24, issue #542;
     * "Start without the sub-agent switch" in the spec). It does not need the sub-agent switch
     * `spawn_enabled`: that switch guards the creation of sub-agents. It does not need the caller to be the
     * creator: a start only opens a closed workspace and deletes nothing. The stop rule is unchanged.
     * It clears the "closed on purpose" mark and starts the workspace once, under the normal launch lock.
     * The start path keeps every guard of the Manager start (agent upgrade, home merge, storage).
     *
     * @return array<string,mixed>
     */
    public static function startWorkspace(string $id): array
    {
        try {
            $id = trim($id);
            $record = self::findRecord($id);
            if ($record === null) return ['error' => "No workspace with id '$id' was found."];
            $name = (string)($record['name'] ?? $id);
            $path = (string)($record['path'] ?? '');
            $agentId = (string)($record['agentId'] ?? '');
            if ($path === '' || $agentId === '') return ['error' => "Workspace '$name' has no folder or agent on record, so it cannot start."];
            if (ConfigService::isWorkspaceRemoved($id)) return ['error' => "Workspace '$name' is being removed. It cannot start."];
            if (ProcessManager::isRunning($id)) {
                return ['workspaceId' => $id, 'name' => $name, 'started' => false, 'alreadyRunning' => true];
            }
            AutoLaunchSuppression::clear($id);
            $r = self::startWorkspaceProcess($id, $path, $agentId);
            if (empty($r['ok'])) {
                return ['error' => "Workspace '$name' did not start (" . (string)($r['reason'] ?? 'unknown') . ').', 'workspaceId' => $id];
            }
            if (self::isHelper($record)) {
                // A restarted sub-agent is not "done" any more, and the sweep must not close it again at once.
                self::updateRecord($id, static function (array $rec): array {
                    $rec['spawn']['state'] = 'starting';
                    unset($rec['spawn']['close_pending']);
                    return $rec;
                });
            }
            return ['workspaceId' => $id, 'name' => $name, 'started' => true, 'alreadyRunning' => false];
        } catch (\Throwable $e) {
            return ['error' => 'The workspace could not be started: ' . self::safeText($e->getMessage(), 300)];
        }
    }

    /**
     * Stop a workspace that the caller created (spec R2, decision 2). A person may stop any workspace.
     * A sub-agent with autoClose is closed for good (R14, R17): stopped, removed from the list, and its worktree
     * removed when merged and clean. No approval step: only the creator or a person gets here. Any other
     * workspace is stopped and kept; its record and files stay.
     *
     * @return array<string,mixed>
     */
    public static function stopWorkspace(string $id): array
    {
        try {
            // #779 (John, 2026-10-26): the creator may close a workspace it made with no approval, also while
            // "Let agents start sub-agent workspaces" is off. The switch guards making sub-agents, not closing them;
            // refuseUnlessCreator() below still refuses every other agent. A person may stop any workspace.
            $id = trim($id);
            $record = self::findRecord($id);
            if ($record === null) return ['error' => "No workspace with id '$id' was found. It may be closed already."];
            $refusal = self::refuseUnlessCreator($record);
            if ($refusal !== null) return ['error' => $refusal];
            $name = (string)($record['name'] ?? $id);

            if (self::isHelper($record) && !empty($record['spawn']['auto_close'])) {
                return self::closeHelper($id, false);
            }
            if (!ProcessManager::isRunning($id)) {
                return ['workspaceId' => $id, 'name' => $name, 'stopped' => false, 'alreadyStopped' => true, 'removed' => false];
            }
            AutoLaunchSuppression::suppress($id);
            $closed = self::closeProcess($id, (string)($record['agentId'] ?? ''), (string)($record['path'] ?? ''));
            if (($closed['status'] ?? '') !== 'ok') {
                return ['error' => (string)($closed['message'] ?? "Workspace '$name' could not be closed.")];
            }
            if (self::isHelper($record)) {
                self::updateRecord($id, static function (array $rec): array {
                    $rec['spawn']['state'] = 'closed';
                    return $rec;
                });
            }
            return ['workspaceId' => $id, 'name' => $name, 'stopped' => true, 'alreadyStopped' => false, 'removed' => false];
        } catch (\Throwable $e) {
            return ['error' => 'The workspace could not be stopped: ' . self::safeText($e->getMessage(), 300)];
        }
    }

    /**
     * The graceful close the drawer uses (Ctrl-C twice, scrape the resume id, stop), serialised by the launch lock.
     *
     * @return array<string,mixed>
     */
    public static function closeProcess(string $id, string $agentId, string $path): array
    {
        if (self::$closeRunner !== null) return (self::$closeRunner)($id, $agentId, $path);
        if (!class_exists('\\AICliAgents\\Handlers\\TerminalHandler')) {
            require_once __DIR__ . '/../handlers/TerminalHandler.php';
        }
        // The browser close sends the folder and the agent; the handler reads them from the request.
        $saved = [$_GET['path'] ?? null, $_GET['agentId'] ?? null];
        $_GET['path'] = $path;
        $_GET['agentId'] = $agentId;
        try {
            $r = \AICliAgents\Handlers\TerminalHandler::handle('graceful_close', $id);
        } finally {
            foreach (['path', 'agentId'] as $i => $k) {
                if ($saved[$i] === null) unset($_GET[$k]); else $_GET[$k] = $saved[$i];
            }
        }
        return is_array($r) ? $r : ['status' => 'error', 'message' => 'The close gave no answer.'];
    }

    // ======================================================================
    // Slice (d): aicli_finish_task, the close of a sub-agent, and autoClose
    // ======================================================================

    /**
     * A sub-agent or script-run workspace reports its task as finished. A sub-agent writes RESULT.json, records
     * `workspace.task_reported`, and sends its parent a Relay message. A run delegates to AgentRunService so it
     * updates the Notebook and the run records. Neither path closes its own pane; the supervisor does that later.
     *
     * @return array<string,mixed>
     */
    public static function finishTask(string $status, string $summary): array
    {
        try {
            $callerId = self::callerId();
            $record = self::findRecord($callerId);
            $spawn = is_array($record['spawn'] ?? null) ? $record['spawn'] : [];
            if (is_array($spawn['runUserSignIn'] ?? null)) {
                return ['error' => 'A sign-in workspace does not report a script run. Close it after sign-in.'];
            }
            $runId = (string)($spawn['run']['runId'] ?? '');
            if ($runId !== '') {
                if ($callerId === '' || !self::isHelper($record)) {
                    return ['error' => 'Only a run workspace can submit this report.'];
                }
                $status = strtolower(trim($status));
                if (!in_array($status, ['done', 'failed', 'blocked'], true)) {
                    return ['error' => "The status '$status' is not valid. Use done, failed or blocked."];
                }
                $summary = trim($summary);
                if ($summary === '') return ['error' => 'Give a short summary of what you did, or why you could not finish.'];
                // #915: a run report is kept whole (the Notebook card holds it), so its limit is the run limit.
                if (self::length($summary) > AgentRunService::REPORT_MAX_CHARS) {
                    return ['error' => 'The report is too long. The limit is ' . AgentRunService::REPORT_MAX_CHARS . ' characters. Put the detail in a file and name the file.'];
                }
                $safe = self::safeText($summary, AgentRunService::REPORT_MAX_CHARS);
                if ($safe === '') return ['error' => 'The summary could not be checked for secrets, so nothing was saved. Try again.'];
                return AgentRunService::reportFromWorkspace($callerId, $runId, $status, $safe);
            }
            if (!self::enabled()) return ['error' => self::disabledMessage()];
            if ($callerId === '' || !self::isHelper($record)) {
                return ['error' => 'Only a sub-agent workspace can report a finished task. This workspace was not started with aicli_spawn_workspace.'];
            }
            $status = strtolower(trim($status));
            if (!in_array($status, ['done', 'failed', 'blocked'], true)) {
                return ['error' => "The status '$status' is not valid. Use done, failed or blocked."];
            }
            $summary = trim($summary);
            if ($summary === '') return ['error' => 'Give a short summary of what you did, or why you could not finish.'];
            if (self::length($summary) > self::MAX_SUMMARY_CHARS) {
                return ['error' => 'The summary is too long. The limit is ' . self::MAX_SUMMARY_CHARS . ' characters. Put the detail in a file and name the file.'];
            }
            $safe = self::safeText($summary, self::MAX_SUMMARY_CHARS);
            if ($safe === '') return ['error' => 'The summary could not be checked for secrets, so nothing was saved. Try again.'];

            $parentId = (string)($spawn['parent'] ?? $record['createdBy'] ?? '');
            $autoClose = !empty($spawn['auto_close']);
            $at = self::now();
            $report = ['status' => $status, 'summary' => $safe, 'at' => $at];

            $dir = (string)($record['path'] ?? '') . '/' . self::TASK_DIR;
            // Written with the same no-follow step as the task files: the folder belongs to a person (finding C1).
            $json = json_encode($report + ['workspaceId' => $callerId, 'taskTitle' => (string)($spawn['task_title'] ?? '')], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if (is_string($json)) self::writeInTaskDir((string)($record['path'] ?? ''), ['RESULT.json' => $json . "\n"]);
            self::updateRecord($callerId, static function (array $rec) use ($report, $autoClose): array {
                $rec['spawn']['state'] = 'reported_done';
                $rec['spawn']['report'] = $report;
                if ($autoClose) $rec['spawn']['close_pending'] = true;
                return $rec;
            });

            $name = (string)($record['name'] ?? $callerId);
            self::emit('workspace.task_reported', ['workspaceId' => $callerId, 'agentId' => (string)($record['agentId'] ?? '')],
                "Sub-agent '$name' reported: $status", ['parent' => $parentId, 'status' => $status, 'autoClose' => $autoClose]);

            $notified = false;
            if ($parentId !== '') {
                $text = "Sub-agent '$name' reports $status: " . $safe;
                if (strlen($text) > 1800) $text = substr($text, 0, 1800);
                $res = self::$parentNotifier !== null
                    ? (self::$parentNotifier)($callerId, $parentId, $text)
                    : AgentRelayService::directMessage($callerId, $parentId, $text);
                $notified = in_array((string)($res['status'] ?? ''), ['ok', 'recipient_offline'], true);
            }
            return [
                'workspaceId' => $callerId, 'name' => $name, 'taskStatus' => $status, 'parent' => $parentId,
                'parentName' => (string)($spawn['parent_name'] ?? ''), 'taskTitle' => (string)($spawn['task_title'] ?? ''),
                'autoClose' => $autoClose, 'parentNotified' => $notified, 'resultFile' => $dir . '/RESULT.json',
            ];
        } catch (\Throwable $e) {
            return ['error' => 'The report could not be saved: ' . self::safeText($e->getMessage(), 300)];
        }
    }

    /**
     * Save a script-run result in its task folder and mark the workspace for auto-close.
     * This method accepts the timeout state as well as the three agent report states.
     * AgentRunService calls it while holding the run lock.
     *
     * @return array{resultFile:string,report:array<string,mixed>}|array{error:string}
     */
    public static function recordRunReport(string $workspaceId, string $runId, string $status, string $summary, int $at): array
    {
        if (!in_array($status, ['done', 'failed', 'blocked', 'timed_out'], true)) return ['error' => 'The run report status is not valid.'];
        $record = self::findRecord($workspaceId);
        if ($record === null || (string)($record['spawn']['run']['runId'] ?? '') !== $runId) {
            return ['error' => 'The run workspace is no longer open.'];
        }
        // R18, R22: a run as the run user has a private task folder that it owns. Root writes nothing there; the caller
        // keeps a result copy in the state folder of the plugin.
        $private = (string)($record['spawn']['run']['taskDir'] ?? '') !== '';
        $existing = $record['spawn']['report'] ?? null;
        if (is_array($existing)) {
            if ((string)($existing['status'] ?? '') === $status && ((string)($existing['summary'] ?? '') === $summary
                || (string)($existing['summary_sha'] ?? '') === hash('sha256', $summary))) {
                return ['resultFile' => $private ? '' : rtrim((string)$record['path'], '/') . '/' . self::TASK_DIR . '/RESULT.json', 'report' => $existing];
            }
            return ['error' => 'This run already has a report.'];
        }
        $report = ['status' => $status, 'summary' => $summary, 'at' => $at];
        $dir = rtrim((string)($record['path'] ?? ''), '/') . '/' . self::TASK_DIR;
        if (!$private) {
            $json = json_encode($report + ['workspaceId' => $workspaceId, 'taskTitle' => (string)($record['spawn']['task_title'] ?? '')], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if (!is_string($json)) return ['error' => 'The report could not be encoded.'];
            $writeError = self::writeInTaskDir((string)($record['path'] ?? ''), ['RESULT.json' => $json . "\n"]);
            if ($writeError !== null) return ['error' => $writeError];
        }
        // #915: the workspace list keeps a short copy. The whole report is in RESULT.json (or, for a run as the run user,
        // in the private result copy of the plugin) and on the Notebook card, so the list (saved often, and copied to
        // flash) does not grow by up to 200,000 characters per run.
        $stored = $report;
        if (self::length($summary) > self::MAX_SUMMARY_CHARS) {
            $stored['summary'] = mb_substr($summary, 0, self::MAX_SUMMARY_CHARS)
                . ($private ? ' [The whole report is on the Notebook card.]' : ' [The whole report is in RESULT.json and on the Notebook card.]');
            $stored['summary_sha'] = hash('sha256', $summary);
        }
        $saved = self::updateRecord($workspaceId, static function (array $rec) use ($runId, $stored): array {
            if ((string)($rec['spawn']['run']['runId'] ?? '') !== $runId || is_array($rec['spawn']['report'] ?? null)) return $rec;
            $rec['spawn']['state'] = 'reported_done';
            $rec['spawn']['report'] = $stored;
            if (!empty($rec['spawn']['auto_close'])) $rec['spawn']['close_pending'] = true;
            return $rec;
        });
        if (!$saved) return ['error' => 'The run workspace could not be updated.'];
        return ['resultFile' => $private ? '' : $dir . '/RESULT.json', 'report' => $report];
    }

    /**
     * Close a sub-agent or script run for good (R14, R17, R26): stop it and remove it from the list. Remove a
     * sub-agent worktree only when it is merged and clean. No approval. It never deletes a branch. $notify true
     * adds the sub-agent tray record (the sweep); a script run records its close through AgentRunService.
     *
     * @return array<string,mixed>
     */
    public static function closeHelper(string $id, bool $notify, bool $anyKind = false): array
    {
        $record = self::findRecord($id);
        if ($record === null) return ['error' => "No workspace with id '$id' was found. It may be closed already."];
        // $anyKind is true only for closeOwn(): the creator closes a workspace it made, helper or not (#866).
        if (!$anyKind && !self::isHelper($record)) return ['error' => 'Only a sub-agent workspace that the spawn tool made is closed this way.'];
        $name = (string)($record['name'] ?? $id);
        $agentId = (string)($record['agentId'] ?? '');
        $path = (string)($record['path'] ?? '');
        $spawn = is_array($record['spawn'] ?? null) ? $record['spawn'] : [];
        $parentName = (string)($spawn['parent_name'] ?? '');

        // #687: mark the removal BEFORE the close, so a browser tab that still shows the row cannot start it again.
        // #865 decision E: the marks are undone when the close fails, so the workspace is not left half-removed. An
        // auto-launch mark that was there before this call (a workspace stopped on purpose) stays.
        $wasSuppressed = AutoLaunchSuppression::isSuppressed($id);
        ConfigService::markWorkspaceDeleting($id);
        AutoLaunchSuppression::suppress($id);
        if (ProcessManager::isRunning($id)) {
            $closed = self::closeProcess($id, $agentId, $path);
            if (($closed['status'] ?? '') !== 'ok') {
                ConfigService::clearWorkspaceDeleting($id);
                self::undoOwnMark($id, $wasSuppressed);
                return ['error' => (string)($closed['message'] ?? "Workspace '$name' could not be closed.")];
            }
        }
        if (!self::afterSignInClosed($spawn, $id)) {
            ConfigService::clearWorkspaceDeleting($id);
            self::undoOwnMark($id, $wasSuppressed);
            return ['error' => 'The sign-in files could not be saved. The workspace and its private home stay available for another attempt.'];
        }
        try { AgentRelayService::forgetSessionRelayTraces($id); } catch (\Throwable $e) { /* best effort */ }

        $workspaces = ConfigService::getWorkspaces();
        $remaining = array_values(array_filter(
            $workspaces['sessions'] ?? [],
            static fn($w): bool => is_array($w) && (string)($w['id'] ?? '') !== $id
        ));
        $ok = ConfigService::saveWorkspaces(['sessions' => $remaining, 'activeId' => $workspaces['activeId'] ?? null], [$id]);
        if (!$ok) {
            ConfigService::clearWorkspaceDeleting($id);
            self::undoOwnMark($id, $wasSuppressed);
            return ['error' => ConfigService::lastWorkspaceSaveMessage() ?? 'Could not save the workspace removal.'];
        }
        $runId = (string)($spawn['run']['runId'] ?? '');
        if ($runId !== '') AgentRunService::workspaceClosed($runId, $id);
        self::releaseRestricted($record);
        if (class_exists('\\AICliAgents\\Services\\EventSubscriptionStore')) {
            try { EventSubscriptionStore::reapStale(array_column($remaining, 'id')); } catch (\Throwable $e) { /* best effort */ }
        }
        try { AgentRelayService::reAdoptOwnersByIdentity(); } catch (\Throwable $e) { /* best effort */ }
        ArgsService::deleteIdArgs($id);
        self::untrustWorktreeOf($spawn, $agentId);
        // #865 decision J: only a sub-agent is called a sub-agent. A plain workspace is a "workspace".
        $noun = self::isHelper($record) ? 'Sub-agent workspace' : 'Workspace';

        $worktree = self::cleanupWorktree(is_array($spawn['worktree'] ?? null) ? $spawn['worktree'] : null);
        $result = ['workspaceId' => $id, 'name' => $name, 'stopped' => true, 'removed' => true, 'worktree' => $worktree];

        $detail = "by $parentName";
        if ($worktree['kept']) {
            $detail .= ' · the worktree is kept: ' . $worktree['reason'];
            self::tellParentKept($id, $name, $spawn, $worktree);
        } elseif ($worktree['removed']) {
            $detail .= ' · the worktree was removed';
        }
        if ($runId === '') {
            self::emit('workspace.helper_closed', ['workspaceId' => $id, 'agentId' => $agentId],
                "$noun '$name' closed", ['parent' => (string)($spawn['parent'] ?? ''), 'worktreeKept' => $worktree['kept'], 'branch' => $worktree['branch']]);
            if ($notify) self::notify("$noun closed: " . $name, $detail, $id);
        }
        return $result;
    }

    /**
     * Tell the parent of a sub-agent that the worktree of the closed sub-agent is kept, and why (one Relay message).
     * Used by every close path, so the parent knows where the unmerged work is.
     *
     * @param array<string,mixed> $spawn
     * @param array{removed:bool,kept:bool,reason:string,branch:string} $worktree
     */
    public static function tellParentKept(string $id, string $name, array $spawn, array $worktree): void
    {
        $parentId = (string)($spawn['parent'] ?? '');
        if ($parentId === '' || empty($worktree['kept'])) return;
        $text = "Sub-agent '$name' was closed. Its worktree '" . $worktree['branch'] . "' is kept: " . $worktree['reason'];
        try {
            if (self::$parentNotifier !== null) (self::$parentNotifier)($id, $parentId, $text);
            else AgentRelayService::directMessage($id, $parentId, $text);
        } catch (\Throwable $e) {
            // Best effort.
        }
    }

    /** @var callable|null fn(string $subject, string $description, string $level): void. Replaces the Unraid notify script. */
    public static $systemNotifier = null;

    /** The Unraid notify script. The path is fixed: no variable can point it at another program. */
    public const NOTIFY_SCRIPT = '/usr/local/emhttp/webGui/scripts/notify';

    /**
     * An Unraid notification. Unraid's own notification settings decide whether and where it shows. Best effort.
     */
    public static function systemNotify(string $subject, string $description): void
    {
        try {
            if (self::$systemNotifier !== null) {
                (self::$systemNotifier)($subject, $description, 'normal');
                return;
            }
            if (!file_exists(self::NOTIFY_SCRIPT)) return;
            exec(escapeshellcmd(self::NOTIFY_SCRIPT) . " -e 'AICliAgents' -s " . escapeshellarg($subject) . ' -d ' . escapeshellarg($description) . ' -i normal 2>/dev/null');
        } catch (\Throwable $e) {
            // Best effort.
        }
    }

    /** @var callable|null fn(string $id): bool. Replaces the Relay actor check of closeOwn(). */
    public static $actorProbe = null;

    /** True when the workspace is a live Relay actor, or when that cannot be told (then the safe answer is yes). */
    private static function isRelayActor(string $id): bool
    {
        try {
            if (self::$actorProbe !== null) return (bool)(self::$actorProbe)($id);
            $actor = AgentRelayService::actorStatus($id);
            return !empty($actor['is_actor']);
        } catch (\Throwable $e) {
            return true;
        }
    }

    /**
     * #866 (John, 2026-10-31): an agent may close for good a workspace that it created, with no approval. The
     * person gets a notice, not a question. Runs only when ALL of these hold; in every other case it returns null and
     * the caller makes the normal Tier 3 proposal:
     * - the call comes from a workspace that has a real record (not a person's shell, not an unknown id);
     * - the record's `createdBy` (written by the plugin, never claimed by the caller) is that workspace, and is not
     *   `human` and not `run:*` (a person's workspace and a script run never qualify);
     * - the workspace is not the caller itself;
     * - the workspace is not a Relay actor (an actor is a standing member of the Relay, so a person decides).
     * Limit, stated plainly: `AICLI_SESSION_ID` is an environment variable. A process that runs as root can claim the id
     * of another real session. So this is a guard against mistakes, not against a hostile process.
     *
     * The close is graceful. The record and its flags go. The worktree goes only when it is merged and clean; the
     * branch is never deleted. One Activity tray row names the caller, the workspace and the worktree outcome, and one
     * Unraid notification is sent.
     *
     * @return array<string,mixed>|null
     */
    public static function closeOwn(string $id): ?array
    {
        $id = trim($id);
        $caller = self::callerId();
        $record = self::findRecord($id);
        if ($caller === '' || $record === null || $id === $caller) return null;
        $creator = (string)($record['createdBy'] ?? '');
        if ($creator !== $caller || $creator === 'human' || strpos($creator, 'run:') === 0) return null;
        $callerRecord = self::findRecord($caller);
        if ($callerRecord === null) return null;
        if (self::isRelayActor($id)) return null;
        $callerName = (string)($callerRecord['name'] ?? $caller);
        $name = (string)($record['name'] ?? $id);
        $noun = self::isHelper($record) ? 'sub-agent' : 'workspace';
        $r = self::closeHelper($id, false, true);
        if (isset($r['error'])) return $r;
        $wt = is_array($r['worktree'] ?? null) ? $r['worktree'] : ['removed' => false, 'kept' => false, 'reason' => '', 'branch' => ''];
        if (!empty($wt['kept'])) $outcome = 'the worktree is kept (' . $wt['reason'] . '); the work is on the branch ' . $wt['branch'];
        elseif (!empty($wt['removed'])) $outcome = 'the worktree was removed';
        else $outcome = 'no worktree';
        $title = "$callerName closed its $noun $name";
        self::notify($title, $outcome, $id);
        self::systemNotify('AICliAgents: ' . $title, $outcome);
        return $r + ['closedBy' => $callerName, 'approval' => 'not needed: you created this workspace'];
    }

    /**
     * #865 decision G: the closing steps that the approved delete shares with closeHelper, for a sub-agent: one event
     * and, when the worktree is kept, one Relay message to the parent. A script run is left to AgentRunService.
     *
     * @param array<string,mixed> $record the record, read before it was removed
     * @param array<string,mixed> $worktree
     */
    public static function reportApprovedRemoval(array $record, array $worktree): void
    {
        if (!self::isHelper($record)) return;
        $id = (string)($record['id'] ?? '');
        $name = (string)($record['name'] ?? $id);
        $spawn = is_array($record['spawn'] ?? null) ? $record['spawn'] : [];
        if ((string)($spawn['run']['runId'] ?? '') !== '') return;
        self::emit('workspace.helper_closed', ['workspaceId' => $id, 'agentId' => (string)($record['agentId'] ?? '')],
            "Sub-agent workspace '$name' closed by a person", ['parent' => (string)($spawn['parent'] ?? ''), 'by' => 'person',
                'worktreeKept' => !empty($worktree['kept']), 'branch' => (string)($worktree['branch'] ?? '')]);
        self::tellParentKept($id, $name, $spawn, $worktree + ['removed' => false, 'kept' => false, 'reason' => '', 'branch' => '']);
    }
    /**
     * #865 item 5: what a close does to the worktree of a helper that is removed by any path (the approved delete too).
     * Takes the Codex trust away and removes the worktree when it is merged and clean. The branch is never deleted.
     *
     * @param array<string,mixed> $record the workspace record, read before it was removed
     * @return array{removed:bool,kept:bool,reason:string,branch:string}
     */
    public static function finishRemoval(array $record): array
    {
        $spawn = is_array($record['spawn'] ?? null) ? $record['spawn'] : [];
        self::untrustWorktreeOf($spawn, (string)($record['agentId'] ?? ''));
        return self::cleanupWorktree(is_array($spawn['worktree'] ?? null) ? $spawn['worktree'] : null);
    }

    /** R20: take the Codex trust of the sub-agent's worktree away again. Best effort; a table the plugin did not add is not touched. */
    private static function untrustWorktreeOf(array $spawn, string $agentId): void
    {
        $wt = is_array($spawn['worktree'] ?? null) ? $spawn['worktree'] : null;
        if ($wt !== null && !empty($wt['tool_made']) && AgentLaunchProfile::trustMode($agentId) === 'codex_toml') {
            CodexTrustService::untrustWorktree($wt);
        }
    }

    /**
     * A PERSON closed a sub-agent or script run in the drawer (the browser saved the list without it). The browser
     * has stopped the session and removed the row; this finishes the clean-up. A sub-agent also loses its flags and
     * Codex trust, and its worktree goes only when it is merged and clean. A run records its close through
     * AgentRunService. The record is gone, so the caller passes the copy read before the save. Never throws.
     *
     * @param array<string,mixed> $record The sub-agent's record, read before it was removed.
     * @return array<string,mixed>
     */
    public static function afterPersonClosed(array $record): array
    {
        try {
            if (!self::isHelper($record)) return ['helper' => false];
            $id = (string)($record['id'] ?? '');
            $name = (string)($record['name'] ?? $id);
            $agentId = (string)($record['agentId'] ?? '');
            $spawn = is_array($record['spawn'] ?? null) ? $record['spawn'] : [];
            $parentName = (string)($spawn['parent_name'] ?? '');
            ArgsService::deleteIdArgs($id);
            $runId = (string)($spawn['run']['runId'] ?? '');
            if ($runId !== '') AgentRunService::workspaceClosed($runId, $id);
            if (!self::afterSignInClosed($spawn, $id)) {
                return ['helper' => true, 'error' => 'The sign-in files could not be saved. The private home stays in place.'];
            }
            self::releaseRestricted($record);
            self::untrustWorktreeOf($spawn, $agentId);
            $worktree = self::cleanupWorktree(is_array($spawn['worktree'] ?? null) ? $spawn['worktree'] : null);
            if ($runId === '') {
                self::emit('workspace.helper_closed', ['workspaceId' => $id, 'agentId' => $agentId],
                    "Sub-agent workspace '$name' closed by a person", ['parent' => (string)($spawn['parent'] ?? ''), 'by' => 'person',
                        'worktreeKept' => $worktree['kept'], 'branch' => $worktree['branch']]);
                $detail = 'closed by a person' . ($parentName !== '' ? " · started by $parentName" : '');
                if ($worktree['kept']) $detail .= ' · the worktree is kept: ' . $worktree['reason'];
                elseif ($worktree['removed']) $detail .= ' · the worktree was removed';
                self::notify('Sub-agent workspace closed: ' . $name, $detail, $id);
            }
            return ['helper' => true, 'workspaceId' => $id, 'worktree' => $worktree];
        } catch (\Throwable $e) {
            return ['helper' => true, 'error' => 'clean-up failed'];
        }
    }

    /** @var string|null Test seam: the folder of the queued clean-ups of sub-agents that a person closed. */
    public static ?string $cleanupDir = null;

    private static function cleanupDir(): string
    {
        return self::$cleanupDir ?? SecretPaths::stateRoot() . '/spawn-cleanup';
    }

    /**
     * Queue the clean-up of a sub-agent that a person closed. The browser saves the list while the close is still
     * under way, so the work waits until the session has stopped. The queue file holds the sub-agent's record, because
     * the record is gone from the list. Returns true when the file was written.
     *
     * @param array<string,mixed> $record
     */
    public static function queuePersonClosed(array $record): bool
    {
        $id = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($record['id'] ?? ''));
        if ($id === '' || !self::isHelper($record)) return false;
        $dir = self::cleanupDir();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) return false;
        return AtomicWriteService::writeJson($dir . '/' . $id . '.json', ['queued_at' => self::now(), 'record' => $record]);
    }

    /**
     * Run the queued clean-ups of sub-agents that a person closed, for each sub-agent whose session has stopped. A
     * sub-agent that still runs after a day is dropped from the queue and left alone. Never throws.
     *
     * @return array{done:array<int,string>,waiting:int}
     */
    public static function sweepPersonClosed(): array
    {
        $done = [];
        $waiting = 0;
        try {
            foreach (glob(self::cleanupDir() . '/*.json') ?: [] as $file) {
                $data = json_decode((string)@file_get_contents($file), true);
                $record = is_array($data['record'] ?? null) ? $data['record'] : null;
                if ($record === null) { @unlink($file); continue; }
                $id = (string)($record['id'] ?? '');
                if ($id !== '' && ProcessManager::isRunning($id)) {
                    if (self::now() - (int)($data['queued_at'] ?? 0) > 86400) { @unlink($file); } else { $waiting++; }
                    continue;
                }
                $result = self::afterPersonClosed($record);
                if (!empty($result['error'])) {
                    error_log('aicli-spawn: workspace ' . $id . ' close cleanup will retry: ' . (string)$result['error']);
                    $waiting++;
                    continue;
                }
                @unlink($file);
                $done[] = $id;
            }
        } catch (\Throwable $e) {
            // Advisory: the next tick tries again.
        }
        return ['done' => $done, 'waiting' => $waiting];
    }

    /**
     * The sub-agent records among these workspace ids, read now (before a save removes them).
     *
     * @param array<int,mixed> $ids
     * @return list<array<string,mixed>>
     */
    public static function helperRecordsAmong(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            if (!is_string($id) || $id === '') continue;
            $rec = self::findRecord($id);
            if ($rec !== null && self::isHelper($rec)) $out[] = $rec;
        }
        return $out;
    }

    /**
     * Spec Edge Cases: a sub-agent that has a time limit (`maxMinutes`) and has not reported when the limit passes gets
     * ONE event, `workspace.task_overdue`, and one message to its parent. It is never stopped or killed. Called by the
     * supervisor tick (spawn-sweep.php). Never throws.
     *
     * @return array{overdue:array<int,string>}
     */
    public static function sweepOverdue(): array
    {
        $overdue = [];
        try {
            foreach ((ConfigService::getWorkspaces()['sessions'] ?? []) as $w) {
                if (!is_array($w) || !self::isHelper($w)) continue;
                $s = is_array($w['spawn'] ?? null) ? $w['spawn'] : [];
                $max = (int)($s['max_minutes'] ?? 0);
                if ($max <= 0 || !empty($s['overdue_at']) || in_array($s['state'] ?? '', ['reported_done', 'closed'], true)) continue; // #897: a closed sub-agent is not overdue
                $startedAt = (int)($s['started_at'] ?? 0);
                if ($startedAt <= 0 || self::now() - $startedAt < $max * 60) continue;
                $id = (string)($w['id'] ?? '');
                $name = (string)($w['name'] ?? $id);
                $parentId = (string)($s['parent'] ?? $w['createdBy'] ?? '');
                $at = self::now();
                self::updateRecord($id, static function (array $rec) use ($at): array {
                    $rec['spawn']['overdue_at'] = $at;
                    return $rec;
                });
                self::emit('workspace.task_overdue', ['workspaceId' => $id, 'agentId' => (string)($w['agentId'] ?? '')],
                    "Sub-agent '$name' passed its time limit of $max minutes", ['parent' => $parentId, 'maxMinutes' => $max, 'minutes' => intdiv($at - $startedAt, 60)]);
                if ($parentId !== '') {
                    // #897: say what is true. A process that ended by itself has no screen to look at.
                    $text = !ProcessManager::isRunning($id)
                        ? "Sub-agent '$name' passed its time limit of $max minutes and has not reported. It no longer runs, so it ended without a report."
                        : "Sub-agent '$name' passed its time limit of $max minutes and has not reported. It still runs. Look at its screen, or stop it with aicli_stop_workspace.";
                    try {
                        if (self::$parentNotifier !== null) (self::$parentNotifier)($id, $parentId, $text);
                        else AgentRelayService::directMessage($id, $parentId, $text);
                    } catch (\Throwable $e) { /* the event is the record */ }
                }
                self::notify('Sub-agent over its time limit: ' . $name, 'by ' . (string)($s['parent_name'] ?? '') . " · limit $max minutes", $id);
                $overdue[] = $id;
            }
        } catch (\Throwable $e) {
            // Advisory: the next tick tries again.
        }
        return ['overdue' => $overdue];
    }

    /**
     * Remove the worktree of a closed sub-agent, but only a worktree that the tool made, and only when it is
     * clean and everything on its branch is merged into its base (R14). Otherwise keep it and say why.
     * The branch is never deleted. Nothing is pushed.
     *
     * @param array<string,mixed>|null $wt The `worktree` block of the sub-agent's `spawn` data.
     * @return array{removed:bool,kept:bool,reason:string,branch:string}
     */
    public static function cleanupWorktree(?array $wt): array
    {
        $none = ['removed' => false, 'kept' => false, 'reason' => '', 'branch' => ''];
        if ($wt === null || empty($wt['tool_made'])) return $none;
        $repo = (string)($wt['repo'] ?? '');
        $dir = (string)($wt['path'] ?? '');
        $branch = (string)($wt['branch'] ?? '');
        $base = (string)($wt['base'] ?? 'HEAD');
        $out = ['removed' => false, 'kept' => true, 'reason' => '', 'branch' => $branch];
        if ($repo === '' || $dir === '' || !is_dir($dir)) {
            return ['removed' => false, 'kept' => false, 'reason' => 'the worktree folder is gone already', 'branch' => $branch];
        }
        $state = self::worktreeState($wt);
        if ($state['dirty'] > 0) {
            $out['reason'] = $state['dirty'] . ' file(s) have changes that are not committed';
            return $out;
        }
        if ($state['ahead'] > 0) {
            $out['reason'] = $state['ahead'] . ' commit(s) on the branch are not merged into ' . $base;
            return $out;
        }
        if ($state['unknown']) {
            // #865 decision I: a base of HEAD names no branch, so `HEAD..HEAD` is always empty and proves nothing.
            $out['reason'] = $base === 'HEAD'
                ? 'the base is not a named branch, so the plugin cannot tell whether the work is merged'
                : 'git could not say whether the work is merged';
            return $out;
        }
        // The task folder is NOT emptied by hand: a repo can commit `.aicli-task` as a link, and an unlink under it would
        // delete files elsewhere. `git worktree remove --force` removes the folder and never follows a link.
        $r = self::git(['worktree', 'remove', '--force', $dir], $repo);
        if ($r['rc'] !== 0) {
            $out['reason'] = 'git could not remove the worktree folder';
            return $out;
        }
        return ['removed' => true, 'kept' => false, 'reason' => '', 'branch' => $branch];
    }

    /** The files that the plugin itself writes into `.aicli-task/`. */
    public const TASK_FILES = ['TASK.md', '.gitignore', 'PROMPT.once', 'PROMPT.consumed', 'RESULT.json'];

    /**
     * True for a `git status --porcelain --ignored` line that is the plugin's own task file: exactly
     * `!! .aicli-task/<a file the plugin writes>`. With `--untracked-files=all` git lists the ignored files one by one.
     * A whole `!! .aicli-task/` line is NOT skipped: it could hide files of the sub-agent, so it counts as a change.
     * Any other line, also ` M .aicli-task/notes.md`, ` M sub/.aicli-task/keep.md` or `?? my.aicli-task/draft.txt`, is
     * work of the sub-agent and counts as a change.
     */
    private static function isPluginTaskLine(string $line): bool
    {
        $line = rtrim($line, "\r\n");
        if (strpos($line, '!! ') !== 0) return false;
        $path = substr($line, 3);
        $prefix = self::TASK_DIR . '/';
        return strpos($path, $prefix) === 0 && in_array(substr($path, strlen($prefix)), self::TASK_FILES, true);
    }

    /**
     * How far a sub-agent's worktree is from "merged and clean": the number of changed files (the task folder
     * does not count) and the number of commits that are on the branch but not in the base.
     *
     * @param array<string,mixed> $wt
     * @return array{dirty:int,ahead:int,unknown:bool}
     */
    public static function worktreeState(array $wt): array
    {
        $dir = (string)($wt['path'] ?? '');
        $base = (string)($wt['base'] ?? 'HEAD');
        // #865 decision I: --ignored, so a file that the helper made and git ignores (a build folder) keeps the worktree.
        // The plugin's own task folder is skipped below.
        $st = self::git(['status', '--porcelain', '--untracked-files=all', '--ignored'], $dir);
        $unknown = $st['rc'] !== 0 || $base === 'HEAD';
        $dirty = 0;
        foreach (preg_split('/\R/', $st['out']) ?: [] as $line) {
            if (trim($line) === '' || self::isPluginTaskLine($line)) continue;
            $dirty++;
        }
        $ahead = 0;
        $cnt = self::git(['rev-list', '--count', $base . '..HEAD'], $dir);
        if ($cnt['rc'] === 0 && preg_match('/^\d+$/D', trim($cnt['out'])) === 1) $ahead = (int)trim($cnt['out']);
        else $unknown = true;
        return ['dirty' => $dirty, 'ahead' => $ahead, 'unknown' => $unknown];
    }

    /**
     * The supervisor sweep (every ~30 s). Closes each reported sub-agent or script run whose pane is idle again,
     * so the agent's last reply is never cut off. A workspace that is closed already, or whose pane is busy or
     * waits for an answer, is left for the next tick. Never throws.
     *
     * @param bool|null $runsOnly True selects script runs, false selects sub-agents, and null selects both.
     * @return array{closed:array<int,string>,waiting:int}
     */
    public static function sweepAutoClose(?bool $runsOnly = null): array
    {
        $closed = [];
        $waiting = 0;
        try {
            foreach ((ConfigService::getWorkspaces()['sessions'] ?? []) as $w) {
                if (!is_array($w) || !self::isHelper($w) || empty($w['spawn']['close_pending'])) continue;
                $isRun = (string)($w['spawn']['run']['runId'] ?? '') !== '';
                if ($runsOnly !== null && $isRun !== $runsOnly) continue;
                $id = (string)($w['id'] ?? '');
                if ($id === '') continue;
                $reportedAt = (int)($w['spawn']['report']['at'] ?? 0);
                if (self::now() - $reportedAt < self::CLOSE_AFTER_REPORT_SECONDS) { $waiting++; continue; }
                if (ProcessManager::isRunning($id) && !self::gate((string)($w['agentId'] ?? ''), $id)['ready']) { $waiting++; continue; }
                $r = self::closeHelper($id, true);
                if (isset($r['error'])) { $waiting++; continue; }
                $closed[] = $id;
            }
        } catch (\Throwable $e) {
            // Advisory: the next tick tries again.
        }
        return ['closed' => $closed, 'waiting' => $waiting];
    }

    // ======================================================================
    // Slice (e): answer a sub-agent's permission prompt (R13), tag a parent's input (R22), confirm text (R16)
    // ======================================================================

    /** The tag the plugin puts in front of every text a parent types into its own sub-agent (R22). */
    public static function parentTag(array $record): string
    {
        $name = (string)($record['spawn']['parent_name'] ?? 'parent');
        $name = trim((string)preg_replace('/[^A-Za-z0-9 ._-]/', '', $name));
        return '[from parent ' . ($name !== '' ? $name : 'workspace') . '] ';
    }

    /**
     * R22: text that the CREATOR of a sub-agent types into it gets the fixed tag in front, so the sub-agent knows the
     * words come from its parent and a person reading the terminal sees who typed them. Text from any other caller
     * gets the tag "[from <caller name>] " (or "[from an agent] "). Text for a workspace that is not a sub-agent
     * stays as it is.
     *
     * @param array<string,mixed> $record The target workspace record.
     */
    public static function tagForTarget(array $record, string $text): string
    {
        if (!self::isHelper($record)) return $text;
        $caller = self::callerId();
        if ($caller !== '' && (string)($record['createdBy'] ?? '') === $caller) {
            return self::parentTag($record) . $text;
        }
        // AGENT_COMMAND_INJECTION.md: text from any other caller is tagged too. Without a tag, a line such as /models
        // would run as a command of the agent CLI with no approval. A caller that is empty or unknown is "an agent".
        $name = '';
        if ($caller !== '') {
            $rec = self::findRecord($caller);
            $name = $rec !== null ? trim((string)preg_replace('/[^A-Za-z0-9 ._-]/', '', (string)($rec['name'] ?? ''))) : '';
        }
        return '[from ' . ($name !== '' ? $name : 'an agent') . '] ' . $text;
    }

    /**
     * R13: a parent answers a permission prompt of a sub-agent that it started. Allow-once answers only: a short
     * line of plain characters, never "always". The pane must show a question now. A prompt that mentions a
     * push, the boot disk, the plugin state, an aicli tool, a remote shell or a delete is never answered here:
     * a person decides. The prompt text and the answer are recorded in the ledger.
     *
     * @return array<string,mixed>
     */
    public static function answerChildPrompt(string $workspaceId, string $text): array
    {
        try {
            if (!self::enabled()) return ['error' => self::disabledMessage()];
            $record = self::findRecord($workspaceId);
            if ($record === null) return ['error' => "No workspace with id '$workspaceId' was found."];
            $caller = self::callerId();
            if ($caller === '' || !self::isHelper($record) || (string)($record['createdBy'] ?? '') !== $caller) {
                return ['error' => 'You may answer the prompt of a sub-agent workspace that you started yourself. This workspace is not one.'];
            }
            $name = (string)($record['name'] ?? $workspaceId);
            $agentId = (string)($record['agentId'] ?? '');
            $answer = trim($text);
            if ($answer === '' || preg_match('/^[A-Za-z0-9 ._-]{1,40}$/D', $answer) !== 1) {
                return ['error' => 'An answer is a short line of letters, digits, spaces, dots and dashes, up to 40 characters. For example: 1, y, yes.'];
            }
            if (preg_match('/\b(always|forever|every ?time|don\'?t ask|never ask|all|session|remember)\b/i', $answer)) {
                return ['error' => 'Answer allow-once only. An answer that allows more than this one action is for a person to give.'];
            }
            if (!ProcessManager::isRunning($workspaceId)) return ['error' => "Sub-agent '$name' is not running."];
            if (!self::gapOpen('answer-' . $workspaceId, self::ANSWER_GAP_SECONDS)) {
                return ['error' => "You answered sub-agent '$name' too recently. Wait a few seconds and try again."];
            }
            $gate = self::gate($agentId, $workspaceId);
            if ($gate['ready']) {
                return ['error' => "Sub-agent '$name' waits for no prompt. To send a message to it, use aicli_send_input without answerPrompt."];
            }
            if (!in_array($gate['reason'], self::PROMPT_REASONS, true)) {
                return ['error' => "Sub-agent '$name' is busy, not asking a question (" . $gate['reason'] . '). Wait and look at its screen with aicli_read_workspace_screen.'];
            }
            $raw = self::$paneCapture !== null
                ? (string)(self::$paneCapture)($agentId, $workspaceId)
                : TmuxService::capturePaneRaw($agentId, $workspaceId);
            $screen = self::$paneCapture !== null ? $raw : TmuxService::plainPaneText($raw);
            $tail = implode("\n", array_slice(preg_split('/\R/', $screen) ?: [], -24));
            $word = strtolower(preg_replace('/\s+/', ' ', $answer) ?? $answer);
            $isBox = $gate['reason'] === 'opencode-permission';

            // #900: a reject is one Escape key and never an Enter. Enter takes the highlighted option, and on a
            // permission box that is "Allow once". A reject needs no deny-list check: it allows nothing.
            if (in_array($word, self::REJECT_WORDS, true) || ($isBox && in_array($word, self::REJECT_WORDS_BOX, true))) {
                $res = self::pressKey($agentId, $workspaceId, 'Escape');
                if (($res['status'] ?? '') !== 'ok') return ['error' => (string)($res['message'] ?? 'Could not press the key.')];
                (self::$sleeper ?? static function (int $ms): void { usleep($ms * 1000); })(700);
                $after = self::gate($agentId, $workspaceId);
                $resolved = !($after['ready'] === false && in_array($after['reason'], self::PROMPT_REASONS, true));
                self::emit('workspace.prompt_answered', ['workspaceId' => $workspaceId, 'agentId' => $agentId],
                    "Parent rejected a prompt of sub-agent '$name'", ['answer' => 'reject', 'prompt' => self::safeText($tail, 400), 'resolved' => $resolved]);
                return ['workspaceId' => $workspaceId, 'name' => $name, 'answered' => true, 'answer' => 'reject', 'key' => 'Escape', 'resolved' => $resolved];
            }
            if ($isBox && !in_array($word, self::ALLOW_ONCE_WORDS_BOX, true)) {
                return ['error' => "The prompt of sub-agent '$name' is an OpenCode permission box. Answer 'yes' to allow it once, or 'reject' to refuse it."];
            }
            foreach (self::PROMPT_DENY_PATTERNS as $pattern) {
                if (preg_match($pattern, $tail)) {
                    return ['error' => "The prompt of sub-agent '$name' looks like it touches live state, a push, a delete or another workspace. A parent may not answer it. Ask a person."];
                }
            }
            // On the permission box a typed word does nothing, so the parent presses Enter alone, and only when the
            // mark is on "Allow once": a person may have moved it to "Allow always". It never sends an arrow key.
            if ($isBox && PaneInputRules::openCodeMarkedOption($raw) !== 'once') {
                return ['error' => "The mark on the prompt of sub-agent '$name' is not on \"Allow once\", or the plugin cannot see it. Nothing was pressed. Answer 'reject', or ask a person."];
            }
            $res = $isBox
                ? self::pressKey($agentId, $workspaceId, 'Enter')
                : (self::$paneTyper !== null
                    ? (self::$paneTyper)($agentId, $workspaceId, $answer, true)
                    : TmuxService::pasteAndConfirm($agentId, $workspaceId, $answer, true));
            if (($res['status'] ?? '') !== 'ok') return ['error' => (string)($res['message'] ?? 'Could not type the answer.')];
            self::emit('workspace.prompt_answered', ['workspaceId' => $workspaceId, 'agentId' => $agentId],
                "Parent answered a prompt of sub-agent '$name'", ['answer' => $answer, 'prompt' => self::safeText($tail, 400)]);
            return ['workspaceId' => $workspaceId, 'name' => $name, 'answered' => true, 'answer' => $answer];
        } catch (\Throwable $e) {
            return ['error' => 'The answer could not be sent: ' . self::safeText($e->getMessage(), 300)];
        }
    }

    /** One allow-listed key press into a pane (Enter or Escape). @return array<string,mixed> */
    private static function pressKey(string $agentId, string $id, string $key): array
    {
        return self::$paneKeyer !== null ? (self::$paneKeyer)($agentId, $id, $key) : TmuxService::sendKey($agentId, $id, $key);
    }

    /**
     * R16: what the confirm overlay says when a person closes a workspace in the Manager. For a workspace a
     * person made, or a sub-agent that reported done and has a clean, merged worktree, no confirm is needed.
     * Reads only.
     *
     * @return array<string,mixed>
     */
    public static function closeInfo(string $id): array
    {
        $record = self::findRecord($id);
        if (!self::isHelper($record)) return ['status' => 'ok', 'isHelper' => false, 'needsConfirm' => false, 'reasons' => []];
        $spawn = is_array($record['spawn'] ?? null) ? $record['spawn'] : [];
        $reasons = [];
        if (($spawn['state'] ?? '') !== 'reported_done') {
            $reasons[] = 'The task is not reported as done yet. The sub-agent has not called aicli_finish_task.';
        }
        $wt = is_array($spawn['worktree'] ?? null) ? $spawn['worktree'] : null;
        if ($wt !== null && !empty($wt['tool_made']) && is_dir((string)($wt['path'] ?? ''))) {
            $state = self::worktreeState($wt);
            if ($state['dirty'] > 0) $reasons[] = 'The worktree has ' . $state['dirty'] . ' file(s) with changes that are not committed.';
            if ($state['ahead'] > 0) $reasons[] = "The branch '" . (string)($wt['branch'] ?? '') . "' has " . $state['ahead'] . ' commit(s) that are not merged.';
        }
        return [
            'status' => 'ok', 'isHelper' => true, 'needsConfirm' => $reasons !== [],
            'parentName' => (string)($spawn['parent_name'] ?? ''), 'taskTitle' => (string)($spawn['task_title'] ?? ''),
            'name' => (string)($record['name'] ?? $id), 'reasons' => $reasons,
        ];
    }
}
