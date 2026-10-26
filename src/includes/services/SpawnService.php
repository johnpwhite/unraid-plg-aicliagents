<?php
/**
 * <module_context>
 *     <name>SpawnService</name>
 *     <description>An agent starts a helper workspace, gives it a task, watches it and closes it
 *     (docs/specs/AGENT_SPAWN_WORKSPACE.md, issue #673). This service holds every rule of the four
 *     tools aicli_spawn_workspace, aicli_start_workspace, aicli_stop_workspace and aicli_finish_task,
 *     including reports from script-run workspaces (docs/specs/AGENT_RUN_ENTRY_POINT.md, issue #745),
 *     the parent's answer to a helper's permission prompt (R13), the close of a helper (R14, R17), the
 *     confirm text for a person who closes a helper (R16) and the supervisor sweep that closes a helper
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
    /** The folder inside the helper's workspace folder that holds the task files. */
    public const TASK_DIR = '.aicli-task';

    /** The fixed first line the plugin gives a helper. The caller never writes it (spec R22). */
    public const FIRST_LINE = 'Your task is in .aicli-task/TASK.md. Read it, do it, then call aicli_finish_task.';

    public const MAX_TASK_CHARS = 16000;
    public const MAX_SUMMARY_CHARS = 2000;
    public const MAX_TITLE_CHARS = 80;
    /** One spawn for each caller in this many seconds. */
    public const SPAWN_GAP_SECONDS = 10;
    /** One answer to a helper prompt for each helper in this many seconds. */
    public const ANSWER_GAP_SECONDS = 2;
    /** The longest the spawn call waits for the pane of an agent without a prompt flag to be ready. */
    public const PANE_WAIT_SECONDS = 30;
    /** A reported helper or script run closes only after this many seconds, so its last reply can finish. */
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
    public const PROMPT_REASONS = ['option-selector', 'selector-cursor', 'menu-nav-hint', 'yes-no', 'do-you-want', 'proceed'];

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

    /** True when a person turned the four helper tools on. Compared with '1', never a truthy cast. */
    public static function enabled(): bool
    {
        return self::settings()['spawn_enabled'] === '1';
    }

    public static function disabledMessage(): string
    {
        return 'Helper workspaces are turned off. A person turns them on in Settings, General, Advanced, "Let agents start helper workspaces".';
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

    /** True when the record is a helper workspace that the spawn tool made. */
    public static function isHelper(?array $record): bool
    {
        return $record !== null && (!empty($record['spawned']) || is_array($record['spawn'] ?? null));
    }

    /**
     * Who is calling, for the spawn tool. Decision 6: a helper cannot start another helper. R19 and the
     * spec table: a call with no workspace behind it (a remote client) is refused in this version.
     *
     * @return array{id:string,record:array<string,mixed>}|array{error:string}
     */
    public static function spawnCaller(): array
    {
        $id = self::callerId();
        if ($id === '') {
            return ['error' => 'A helper workspace can be started only from inside a workspace. A call with no workspace behind it is refused.'];
        }
        $record = self::findRecord($id);
        if ($record === null) {
            return ['error' => "This session ('$id') is not a known workspace, so it cannot start a helper. Use a workspace from aicli_list_workspaces."];
        }
        if (self::isHelper($record)) {
            return ['error' => 'A helper workspace cannot start another helper workspace. Only a workspace that a person opened may do that.'];
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
    // Small helpers: text, paths, gaps, git, events
    // ======================================================================

    /** The text with secrets replaced, cut to $max characters. '' when the redaction cannot run (fail closed). */
    private static function safeText(string $text, int $max): string
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

    /** @return array<int,string> Folders that a helper may never use as its workspace folder. */
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
     * A folder for a helper: the same rule as the Manager's folder picker (ValidationService), and a folder
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
            return ['error' => "$what '$path' is a plugin state folder or the boot disk. A helper workspace can never use it."];
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
     * example the supervisor sweep that closes a helper. A tool call gets its tray record from
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

    /** The helper marks of a new record: who started it, for what, and how it ends. @return array<string,mixed> */
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
     * What aicli_list_workspaces and aicli_get_workspace show for a helper (spec section 6): the parent, the
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
     * The state of a helper (spec section 6): running, closed, idle, busy, waiting_approval, quota or
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
            $limit = self::$quotaProbe !== null ? (self::$quotaProbe)($agentId) : QuotaDetectionService::activeLimit($agentId);
            if ($limit !== null) return 'quota';
            $gate = self::gate($agentId, $id);
            if ($gate['ready']) return 'idle';
            return in_array($gate['reason'], self::PROMPT_REASONS, true) ? 'waiting_approval' : 'busy';
        } catch (\Throwable $e) {
            return 'running';
        }
    }

    /** @return array{ready:bool,reason:string} */
    private static function gate(string $agentId, string $id): array
    {
        if (self::$paneGate !== null) return (self::$paneGate)($agentId, $id);
        return TmuxService::paneAcceptsInput($agentId, $id);
    }

    // ======================================================================
    // Slice (b): aicli_spawn_workspace
    // ======================================================================

    /** Count the helpers that run now: [all, for one parent, for one agent kind]. @return array{0:int,1:int,2:int} */
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
     * Create a helper workspace, deliver its task and start it, in one call (spec R1).
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

            // R19: a helper starts on this box only.
            if (trim((string)($p['box'] ?? '')) !== '') {
                return ['error' => 'A helper workspace can start only on this box. Starting one on a linked box is not supported.'];
            }

            $caller = self::spawnCaller();
            if (isset($caller['error'])) return $caller;
            return self::spawnCore($p, (string)$caller['id'], (string)($caller['record']['name'] ?? $caller['id']), []);
        } catch (\Throwable $e) {
            return ['error' => 'The helper workspace could not be started: ' . self::safeText($e->getMessage(), 300)];
        }
    }

    /**
     * Start the workspace of one script run (docs/specs/AGENT_RUN_ENTRY_POINT.md R28, issue #745). It skips only
     * spawnCaller(): a run has no parent workspace. The caller is the script that AgentRunService has already
     * checked. The run switch (`run_enabled`) replaces the helper switch. No agent tool calls this method.
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
     * The shared part of spawn() and spawnForRun(): checks, then side effects.
     *
     * @param array<string,mixed> $p
     * @param array<string,mixed> $run Empty for a helper that a workspace starts. Else the run keys of spawnForRun().
     * @return array<string,mixed>
     */
    private static function spawnCore(array $p, string $callerId, string $callerName, array $run): array
    {
        $isRun = !empty($run['isRun']);
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
            if (trim($task) === '') return ['error' => 'Give the task: what the helper must do.'];
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
                    return ['error' => 'Unattended flags are turned off. A person turns them on in Settings, General, Advanced, "Let helpers run without asking".'];
                }
                if ($wtReq === null) {
                    return ['error' => 'Unattended flags are allowed only in a worktree that this tool makes. Give a worktree (repo and branch) instead of a folder.'];
                }
            }
            // The optional time limit (spec Edge Cases): a whole number of minutes, 1 to 1440. It never kills the helper.
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
            $flags =AgentLaunchProfile::flags($agentId, $model, $effort, $unattended, $profile, $readOnly, (string)($run["mcpConfig"] ?? ""), (string)($run["dataDir"] ?? ""));
            if (isset($flags['error'])) return $flags;
            $promptMode = AgentLaunchProfile::promptMode($agentId, $profile);

            // Resolve the place before any side effect.
            $worktree = null;
            if ($wtReq !== null) {
                $plan = self::planWorktree($wtReq);
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
                return ['error' => "You already have $mine running helper workspaces. The limit is " . self::cap('spawn_max_children') . '. Close one first.'];
            }
            if ($total >= self::cap('spawn_max_total')) {
                return ['error' => "$total helper workspaces run on this box. The limit is " . self::cap('spawn_max_total') . '. Wait until one is closed.'];
            }
            if ($kind >= self::cap('spawn_max_per_agent')) {
                return ['error' => "$kind helper workspaces of '$agentId' already run. The limit is " . self::cap('spawn_max_per_agent') . ' for each agent kind.'];
            }
            $limit = self::$quotaProbe !== null ? (self::$quotaProbe)($agentId) : QuotaDetectionService::activeLimit($agentId, self::now());
            if ($limit !== null) {
                $name = self::findRecord((string)$limit['workspaceId'])['name'] ?? (string)$limit['workspaceId'];
                return ['error' => "The agent '$agentId' shows a usage limit (workspace $name). Its limit resets at " . self::whenText((int)$limit['reset_at']) . '. A helper would burn the same quota. Start the helper after that time.', 'resetAt' => (int)$limit['reset_at']];
            }
            if (!self::gapOpen('spawn-' . $callerId, self::SPAWN_GAP_SECONDS)) {
                return ['error' => 'You started a helper too recently. Wait ' . self::SPAWN_GAP_SECONDS . ' seconds and try again.'];
            }

            // ---- Side effects start here. ----
            if ($worktree !== null) {
                $made = self::makeWorktree($worktree);
                if (isset($made['error'])) return $made;
            }

            $id = 's' . bin2hex(random_bytes(4));
            if (self::findRecord($id) !== null) $id = 's' . bin2hex(random_bytes(4));
            // R3 / friction 4: the sweep must not start the helper before its flags and task are in place.
            AutoLaunchSuppression::suppress($id);

            $name = trim((string)($p['name'] ?? ''));
            if ($name === '') $name = basename($folder) ?: 'Helper';
            $autoClose = !array_key_exists('autoClose', $p) || !empty($p['autoClose']);
            $wtMeta = $worktree !== null ? ['repo' => $worktree['repo'], 'branch' => $worktree['branch'], 'base' => $worktree['base'], 'path' => $worktree['path'], 'tool_made' => true] : null;
            $record = [
                'id' => $id, 'name' => $name, 'path' => $folder, 'agentId' => $agentId, 'lastActive' => null,
                'createdBy' => $callerId, 'createdAt' => self::now(),
                'spawned' => true,
                'spawn' => self::newSpawnMeta($callerId, $callerName, $title, $autoClose, $unattended, $wtMeta, $model, $effort, $maxMinutes),
            ];
            if ($isRun) {
                // R28: the mark "started by script <label>" for the drawer. The label comes from the list, not from the caller.
                $record['createdBy'] = 'run:' . self::safeText((string)($run['label'] ?? 'script'), 60);
                $record['spawn']['run'] = [
                    'runId' => (string)$run['runId'], 'mode' => (string)($run['mode'] ?? 'read-only'),
                    'script' => self::safeText((string)($run['script'] ?? ''), 300), 'key' => self::safeText((string)($run['key'] ?? ''), 200),
                ];
            }
            if (!ConfigService::saveWorkspaces(['sessions' => [$record]], [])) {
                return ['error' => ConfigService::lastWorkspaceSaveMessage() ?? 'Could not save the helper workspace.'];
            }
            if (!ArgsService::saveIdArgs($id, (string)$flags['args'], $promptMode)) {
                return ['error' => "The helper workspace '$id' was saved but its flags could not be written. It is held and not started.", 'id' => $id];
            }
            $filesNote = self::writeTaskFiles($folder, $task, $promptMode !== AgentLaunchProfile::PROMPT_NONE);
            if (isset($filesNote['error'])) {
                return ['error' => $filesNote['error'] . " The helper workspace '$id' is held and not started.", 'id' => $id];
            }

            // R20: no "Trust this folder?" question for a worktree that this tool made. R21: the agent's commands keep
            // the AICLI_* variables. Neither stops the spawn: a failure is reported in the reply.
            $trust = 'not_applicable';
            if ($wtMeta !== null && AgentLaunchProfile::trustMode($agentId, $profile) === 'codex_toml') {
                $trust = !empty(CodexTrustService::trustWorktree($wtMeta)['ok']) ? 'trusted' : 'failed';
            }
            $relayEnv = AgentCommandEnvService::ensure($agentId, CodexTrustService::$homeForTests)['state'];

            AutoLaunchSuppression::clear($id);
            $started = self::startWorkspaceProcess($id, $folder, $agentId, true);

            $delivery = $promptMode !== AgentLaunchProfile::PROMPT_NONE ? 'initial_prompt' : 'pane_input';
            $deliveryNote = '';
            if ($started['ok'] && $promptMode === AgentLaunchProfile::PROMPT_NONE) {
                $typed = self::typeFirstLine($agentId, $id);
                if (!$typed) {
                    $delivery = 'pane_input_pending';
                    $deliveryNote = 'The pane was not ready in time, so the first line was not typed. Use aicli_send_input to send: ' . self::FIRST_LINE;
                }
            } elseif (!$started['ok']) {
                $delivery = 'not_started';
                $deliveryNote = 'The helper workspace was saved but did not start (' . (string)($started['reason'] ?? 'unknown') . '). Use aicli_start_workspace to try again.';
            }

            $created = self::findRecord($id) ?? $record;
            self::emit('workspace.spawned', ['workspaceId' => $id, 'agentId' => $agentId],
                ($isRun ? "Run workspace '$name'" : "Helper workspace '$name'") . " started by '$callerName'",
                ['parent' => $callerId, 'taskTitle' => $title, 'autoClose' => $autoClose, 'delivery' => $delivery]);

            $mine2 = self::runningHelpers($callerId, $agentId)[1];
            $result = [
                'id' => $id, 'workspaceId' => $id, 'name' => $name, 'path' => $folder, 'agentId' => $agentId,
                'started' => (bool)$started['ok'], 'taskDelivery' => $delivery,
                'children' => $mine2 . '/' . self::cap('spawn_max_children'),
                'autoClose' => $autoClose, 'taskFile' => $folder . '/' . self::TASK_DIR . '/TASK.md',
                'parentName' => $callerName, 'taskTitle' => $title, 'model' => $model, 'effort' => $effort,
                'state' => (string)($created['spawn']['state'] ?? 'starting'),
                'trust' => $trust, 'relayEnv' => $relayEnv, 'maxMinutes' => $maxMinutes,
            ];
            if ($worktree !== null) $result['worktree'] = ['path' => $worktree['path'], 'branch' => $worktree['branch'], 'base' => $worktree['base']];
            if (!empty($filesNote['note'])) $result['note'] = $filesNote['note'];
            if ($deliveryNote !== '') $result['deliveryNote'] = $deliveryNote;
            return $result;
        } catch (\Throwable $e) {
            return ['error' => 'The helper workspace could not be started: ' . self::safeText($e->getMessage(), 300)];
        }
    }

    /** @return array<string,mixed> */
    /**
     * #783: the FIRST start of a new helper or script run is always fresh (`_fresh_`): it is a new task, and a
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
     * is ready. The wait is bounded; the call does not block longer than PANE_WAIT_SECONDS.
     */
    private static function typeFirstLine(string $agentId, string $id): bool
    {
        $wait = self::$paneWaitSeconds ?? self::PANE_WAIT_SECONDS;
        $deadline = self::now() + $wait;
        $sleep = self::$sleeper ?? static function (int $ms): void { usleep($ms * 1000); };
        do {
            $gate = self::gate($agentId, $id);
            if ($gate['ready']) {
                $res = self::$paneTyper !== null
                    ? (self::$paneTyper)($agentId, $id, self::FIRST_LINE, true)
                    : TmuxService::pasteAndConfirm($agentId, $id, self::FIRST_LINE, true);
                return ($res['status'] ?? '') === 'ok';
            }
            $sleep(1000);
        } while (self::now() < $deadline);
        return false;
    }

    /**
     * Check a worktree request and compute where the worktree goes. Changes nothing.
     *
     * @param array<string,mixed> $req repo, branch, base
     * @return array{repo:string,branch:string,base:string,path:string}|array{error:string}
     */
    private static function planWorktree(array $req): array
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
        $dir = $repo . '/.claude/worktrees/' . str_replace('/', '-', $branch);
        if (file_exists($dir)) return ['error' => "The worktree folder '$dir' exists already. Choose another branch name."];
        $exists = self::git(['show-ref', '--verify', '--quiet', 'refs/heads/' . $branch], $repo);
        if ($exists['rc'] === 0) return ['error' => "The branch '$branch' exists already. Choose another branch name."];
        if ($base === '') {
            // Name the branch that is checked out now, so the later "is it merged" check has a fixed ref.
            $head = self::git(['symbolic-ref', '--short', '-q', 'HEAD'], $repo);
            $base = $head['rc'] === 0 && trim($head['out']) !== '' ? trim($head['out']) : 'HEAD';
        }
        return ['repo' => $repo, 'branch' => $branch, 'base' => $base, 'path' => $dir];
    }

    /**
     * Make the worktree: `git worktree add <dir> -b <branch> <base>`. Never push, merge or reset.
     *
     * @param array{repo:string,branch:string,base:string,path:string} $plan
     * @return array<string,mixed>
     */
    private static function makeWorktree(array $plan): array
    {
        $parent = dirname($plan['path']);
        if (!is_dir($parent) && !@mkdir($parent, 0775, true) && !is_dir($parent)) {
            return ['error' => "Could not create the folder '$parent' for the worktree."];
        }
        $r = self::git(['worktree', 'add', $plan['path'], '-b', $plan['branch'], $plan['base']], $plan['repo']);
        if ($r['rc'] !== 0) {
            return ['error' => 'git could not make the worktree (base ' . $plan['base'] . '). Check that the base exists. Nothing was started.'];
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

    /** The largest exclude file that is read and written back, in bytes. A larger file is refused. */
    private const EXCLUDE_READ_LIMIT = 1048576;

    /**
     * Read a plain file without following a link at its name. lstat first, then open, then fstat the open file: the
     * two must name the same device and inode, the file must be regular, it must have one name only and it must not
     * be larger than the limit. Returns '' when the file does not exist, the content, or null when the file is refused.
     */
    private static function readPlainFile(string $name): ?string
    {
        $l = @lstat($name);
        if ($l === false) return '';
        if (($l['mode'] & 0170000) !== 0100000 || $l['nlink'] > 1) return null;
        if (defined('AICLI_TEST_TMP') && self::$afterCheck !== null) (self::$afterCheck)('read:' . $name);
        $h = @fopen($name, 'rb');
        if ($h === false) return null;
        $f = fstat($h);
        $data = null;
        // A file over the limit is refused: the copy would be cut and the rename would lose lines (fifth review, F2).
        if ($f !== false && ($f['mode'] & 0170000) === 0100000 && $f['nlink'] <= 1 && $f['size'] <= self::EXCLUDE_READ_LIMIT && $f['dev'] === $l['dev'] && $f['ino'] === $l['ino']) {
            $c = stream_get_contents($h, self::EXCLUDE_READ_LIMIT);
            $data = is_string($c) ? $c : null;
        }
        fclose($h);
        return $data;
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
     * Add ".aicli-task/" to the git exclude file of the repo. The exclude file is only written when its folder is
     * inside the run folder and is entered by the same check as the task folder. Returns a note for the reply.
     */
    private static function addToGitExclude(string $folder, string $gitPath): string
    {
        $line = self::TASK_DIR . '/';
        $real = @realpath($folder);
        if ($real === false) return '';
        $exclude = $gitPath[0] !== '/' ? $real . '/' . $gitPath : $gitPath;
        $excludeDir = dirname($exclude);
        // Only two places are used: a folder inside the run folder, and the folder .git/info of a repository that holds
        // the run folder (a worktree of the tool keeps its exclude file there). Anything else is not touched.
        $insideRun = strpos($excludeDir . '/', rtrim($real, '/') . '/') === 0;
        $inRepoAbove = preg_match('#/\.git/info$#D', $excludeDir) === 1 && strpos($real . '/', dirname($excludeDir, 2) . '/') === 0;
        if ((!$insideRun && !$inRepoAbove) || strpos($exclude, '..') !== false) {
            return 'The git exclude file is outside the folder of the run, so the folder ' . self::TASK_DIR . '/ was not added to it.';
        }
        if (is_link($exclude) || is_link($excludeDir)) {
            return 'The git exclude file is a link, so the folder ' . self::TASK_DIR . '/ was not added to it.';
        }
        // No mkdir: a missing folder is not made by root, because mkdir -p would follow a link in the path (fourth review, 3).
        if (!is_dir($excludeDir)) {
            return 'The git exclude folder does not exist, so the folder ' . self::TASK_DIR . '/ was not added to it.';
        }
        // The folder .git/info of a repository above the run folder may belong to a person. Root opens nothing there
        // unless the folder is owned by root and no group or other user can write to it (fifth review, F1): an open
        // of a name that a person controls follows a link, and a FIFO or a device file would hang or harm the host.
        if (!$insideRun) {
            $st = @lstat($excludeDir);
            if ($st === false || ($st['mode'] & 0170000) !== 0040000 || $st['uid'] !== 0 || ($st['mode'] & 0022) !== 0) {
                return 'The git exclude folder is not owned by root, so it was not opened. Add the line ' . self::TASK_DIR . '/ to .git/info/exclude of the repo yourself.';
            }
        }
        if (defined('AICLI_TEST_TMP') && self::$afterCheck !== null) (self::$afterCheck)('exclude');
        $name = basename($exclude);
        [$entered, $result] = self::inDir($excludeDir, $excludeDir, false, static function () use ($line, $name): string {
            $have = self::readPlainFile($name);
            if ($have === null) return 'refused';
            if (in_array($line, preg_split('/\R/', $have) ?: [], true)) return 'present';
            return self::writeNoFollow($name, $have . ($have !== '' && substr($have, -1) !== "\n" ? "\n" : '') . $line . "\n", 0644) ? 'added' : 'failed';
        });
        if (!$entered) return 'The git exclude folder changed while it was used, so the folder ' . self::TASK_DIR . '/ was not added to it.';
        if ($result === 'refused') return 'The git exclude file is not a plain file with one name, so the folder ' . self::TASK_DIR . '/ was not added to it.';
        return $result === 'added' ? 'The folder ' . self::TASK_DIR . '/ was added to the git exclude file of the repo.' : '';
    }

    /**
     * Write the task files into the helper's folder: TASK.md (the brief) and, when the agent takes a first
     * prompt, PROMPT.once (the fixed first line). Both mode 600. When the folder is inside a git repo, the
     * folder .aicli-task/ goes into the repo's info/exclude, so git ignores it.
     *
     * @return array{note?:string}|array{error:string}
     */
    private static function writeTaskFiles(string $folder, string $task, bool $withPrompt): array
    {
        $files = ['TASK.md' => $task . "\n"];
        if ($withPrompt) $files['PROMPT.once'] = self::FIRST_LINE;
        $err = self::writeInTaskDir($folder, $files);
        if ($err !== null) return ['error' => $err];
        $note = '';
        $ex = self::git(['rev-parse', '--git-path', 'info/exclude'], $folder);
        if ($ex['rc'] === 0 && trim($ex['out']) !== '') {
            $note = self::addToGitExclude($folder, trim($ex['out']));
        } else {
            $note = 'The folder is not in a git repository. The folder ' . self::TASK_DIR . '/ was added to it.';
        }
        return $note !== '' ? ['note' => $note] : [];
    }

    // ======================================================================
    // Slice (c): aicli_start_workspace and aicli_stop_workspace
    // ======================================================================

    /**
     * Start a closed workspace. Any caller may start any existing workspace (John, 2026-10-24, issue #542;
     * "Start without the helper switch" in the spec). It does not need the helper switch
     * `spawn_enabled`: that switch guards the creation of helpers. It does not need the caller to be the
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
                // A restarted helper is not "done" any more, and the sweep must not close it again at once.
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
     * A helper with autoClose is closed for good (R14, R17): stopped, removed from the list, and its worktree
     * removed when merged and clean. No approval step: only the creator or a person gets here. Any other
     * workspace is stopped and kept; its record and files stay.
     *
     * @return array<string,mixed>
     */
    public static function stopWorkspace(string $id): array
    {
        try {
            // #779 (John, 2026-10-26): the creator may close a workspace it made with no approval, also while
            // "Let agents start helper workspaces" is off. The switch guards making helpers, not closing them;
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
    private static function closeProcess(string $id, string $agentId, string $path): array
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
    // Slice (d): aicli_finish_task, the close of a helper, and autoClose
    // ======================================================================

    /**
     * A helper or script-run workspace reports its task as finished. A helper writes RESULT.json, records
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
                if (self::length($summary) > self::MAX_SUMMARY_CHARS) {
                    return ['error' => 'The summary is too long. The limit is ' . self::MAX_SUMMARY_CHARS . ' characters. Put the detail in a file and name the file.'];
                }
                $safe = self::safeText($summary, self::MAX_SUMMARY_CHARS);
                if ($safe === '') return ['error' => 'The summary could not be checked for secrets, so nothing was saved. Try again.'];
                return AgentRunService::reportFromWorkspace($callerId, $runId, $status, $safe);
            }
            if (!self::enabled()) return ['error' => self::disabledMessage()];
            if ($callerId === '' || !self::isHelper($record)) {
                return ['error' => 'Only a helper workspace can report a finished task. This workspace was not started with aicli_spawn_workspace.'];
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
                "Helper '$name' reported: $status", ['parent' => $parentId, 'status' => $status, 'autoClose' => $autoClose]);

            $notified = false;
            if ($parentId !== '') {
                $text = "Helper '$name' reports $status: " . $safe;
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
        $existing = $record['spawn']['report'] ?? null;
        if (is_array($existing)) {
            if ((string)($existing['status'] ?? '') === $status && (string)($existing['summary'] ?? '') === $summary) {
                return ['resultFile' => rtrim((string)$record['path'], '/') . '/' . self::TASK_DIR . '/RESULT.json', 'report' => $existing];
            }
            return ['error' => 'This run already has a report.'];
        }
        $report = ['status' => $status, 'summary' => $summary, 'at' => $at];
        $dir = rtrim((string)($record['path'] ?? ''), '/') . '/' . self::TASK_DIR;
        $json = json_encode($report + ['workspaceId' => $workspaceId, 'taskTitle' => (string)($record['spawn']['task_title'] ?? '')], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (!is_string($json)) return ['error' => 'The report could not be encoded.'];
        $writeError = self::writeInTaskDir((string)($record['path'] ?? ''), ['RESULT.json' => $json . "\n"]);
        if ($writeError !== null) return ['error' => $writeError];
        $saved = self::updateRecord($workspaceId, static function (array $rec) use ($runId, $report): array {
            if ((string)($rec['spawn']['run']['runId'] ?? '') !== $runId || is_array($rec['spawn']['report'] ?? null)) return $rec;
            $rec['spawn']['state'] = 'reported_done';
            $rec['spawn']['report'] = $report;
            if (!empty($rec['spawn']['auto_close'])) $rec['spawn']['close_pending'] = true;
            return $rec;
        });
        if (!$saved) return ['error' => 'The run workspace could not be updated.'];
        return ['resultFile' => $dir . '/RESULT.json', 'report' => $report];
    }

    /**
     * Close a helper or script run for good (R14, R17, R26): stop it and remove it from the list. Remove a
     * helper worktree only when it is merged and clean. No approval. It never deletes a branch. $notify true
     * adds the helper tray record (the sweep); a script run records its close through AgentRunService.
     *
     * @return array<string,mixed>
     */
    public static function closeHelper(string $id, bool $notify): array
    {
        $record = self::findRecord($id);
        if ($record === null) return ['error' => "No workspace with id '$id' was found. It may be closed already."];
        if (!self::isHelper($record)) return ['error' => 'Only a helper workspace that the spawn tool made is closed this way.'];
        $name = (string)($record['name'] ?? $id);
        $agentId = (string)($record['agentId'] ?? '');
        $path = (string)($record['path'] ?? '');
        $spawn = is_array($record['spawn'] ?? null) ? $record['spawn'] : [];
        $parentName = (string)($spawn['parent_name'] ?? '');

        // #687: mark the removal BEFORE the close, so a browser tab that still shows the row cannot start it again.
        ConfigService::markWorkspaceDeleting($id);
        AutoLaunchSuppression::suppress($id);
        if (ProcessManager::isRunning($id)) {
            $closed = self::closeProcess($id, $agentId, $path);
            if (($closed['status'] ?? '') !== 'ok') {
                ConfigService::clearWorkspaceDeleting($id);
                return ['error' => (string)($closed['message'] ?? "Workspace '$name' could not be closed.")];
            }
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
            return ['error' => ConfigService::lastWorkspaceSaveMessage() ?? 'Could not save the workspace removal.'];
        }
        $runId = (string)($spawn['run']['runId'] ?? '');
        if ($runId !== '') AgentRunService::workspaceClosed($runId, $id);
        if (class_exists('\\AICliAgents\\Services\\EventSubscriptionStore')) {
            try { EventSubscriptionStore::reapStale(array_column($remaining, 'id')); } catch (\Throwable $e) { /* best effort */ }
        }
        try { AgentRelayService::reAdoptOwnersByIdentity(); } catch (\Throwable $e) { /* best effort */ }
        ArgsService::deleteIdArgs($id);
        self::untrustWorktreeOf($spawn, $agentId);

        $worktree = self::cleanupWorktree(is_array($spawn['worktree'] ?? null) ? $spawn['worktree'] : null);
        $result = ['workspaceId' => $id, 'name' => $name, 'stopped' => true, 'removed' => true, 'worktree' => $worktree];

        $detail = "by $parentName";
        if ($worktree['kept']) {
            $detail .= ' · the worktree is kept: ' . $worktree['reason'];
            $parentId = (string)($spawn['parent'] ?? '');
            if ($parentId !== '') {
                $text = "Helper '$name' was closed. Its worktree '" . $worktree['branch'] . "' is kept: " . $worktree['reason'];
                if (self::$parentNotifier !== null) (self::$parentNotifier)($id, $parentId, $text);
                else AgentRelayService::directMessage($id, $parentId, $text);
            }
        } elseif ($worktree['removed']) {
            $detail .= ' · the worktree was removed';
        }
        if ($runId === '') {
            self::emit('workspace.helper_closed', ['workspaceId' => $id, 'agentId' => $agentId],
                "Helper workspace '$name' closed", ['parent' => (string)($spawn['parent'] ?? ''), 'worktreeKept' => $worktree['kept'], 'branch' => $worktree['branch']]);
            if ($notify) self::notify('Helper workspace closed: ' . $name, $detail, $id);
        }
        return $result;
    }

    /** R20: take the Codex trust of the helper's worktree away again. Best effort; a table the plugin did not add is not touched. */
    private static function untrustWorktreeOf(array $spawn, string $agentId): void
    {
        $wt = is_array($spawn['worktree'] ?? null) ? $spawn['worktree'] : null;
        if ($wt !== null && !empty($wt['tool_made']) && AgentLaunchProfile::trustMode($agentId) === 'codex_toml') {
            CodexTrustService::untrustWorktree($wt);
        }
    }

    /**
     * A PERSON closed a helper or script run in the drawer (the browser saved the list without it). The browser
     * has stopped the session and removed the row; this finishes the clean-up. A helper also loses its flags and
     * Codex trust, and its worktree goes only when it is merged and clean. A run records its close through
     * AgentRunService. The record is gone, so the caller passes the copy read before the save. Never throws.
     *
     * @param array<string,mixed> $record The helper's record, read before it was removed.
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
            self::untrustWorktreeOf($spawn, $agentId);
            $worktree = self::cleanupWorktree(is_array($spawn['worktree'] ?? null) ? $spawn['worktree'] : null);
            if ($runId === '') {
                self::emit('workspace.helper_closed', ['workspaceId' => $id, 'agentId' => $agentId],
                    "Helper workspace '$name' closed by a person", ['parent' => (string)($spawn['parent'] ?? ''), 'by' => 'person',
                        'worktreeKept' => $worktree['kept'], 'branch' => $worktree['branch']]);
                $detail = 'closed by a person' . ($parentName !== '' ? " · started by $parentName" : '');
                if ($worktree['kept']) $detail .= ' · the worktree is kept: ' . $worktree['reason'];
                elseif ($worktree['removed']) $detail .= ' · the worktree was removed';
                self::notify('Helper workspace closed: ' . $name, $detail, $id);
            }
            return ['helper' => true, 'workspaceId' => $id, 'worktree' => $worktree];
        } catch (\Throwable $e) {
            return ['helper' => true, 'error' => 'clean-up failed'];
        }
    }

    /** @var string|null Test seam: the folder of the queued clean-ups of helpers that a person closed. */
    public static ?string $cleanupDir = null;

    private static function cleanupDir(): string
    {
        return self::$cleanupDir ?? SecretPaths::stateRoot() . '/spawn-cleanup';
    }

    /**
     * Queue the clean-up of a helper that a person closed. The browser saves the list while the close is still
     * under way, so the work waits until the session has stopped. The queue file holds the helper's record, because
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
     * Run the queued clean-ups of helpers that a person closed, for each helper whose session has stopped. A
     * helper that still runs after a day is dropped from the queue and left alone. Never throws.
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
                self::afterPersonClosed($record);
                @unlink($file);
                $done[] = $id;
            }
        } catch (\Throwable $e) {
            // Advisory: the next tick tries again.
        }
        return ['done' => $done, 'waiting' => $waiting];
    }

    /**
     * The helper records among these workspace ids, read now (before a save removes them).
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
     * Spec Edge Cases: a helper that has a time limit (`maxMinutes`) and has not reported when the limit passes gets
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
                if ($max <= 0 || !empty($s['overdue_at']) || ($s['state'] ?? '') === 'reported_done') continue;
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
                    "Helper '$name' passed its time limit of $max minutes", ['parent' => $parentId, 'maxMinutes' => $max, 'minutes' => intdiv($at - $startedAt, 60)]);
                if ($parentId !== '') {
                    $text = "Helper '$name' passed its time limit of $max minutes and has not reported. It still runs. Look at its screen, or stop it with aicli_stop_workspace.";
                    try {
                        if (self::$parentNotifier !== null) (self::$parentNotifier)($id, $parentId, $text);
                        else AgentRelayService::directMessage($id, $parentId, $text);
                    } catch (\Throwable $e) { /* the event is the record */ }
                }
                self::notify('Helper over its time limit: ' . $name, 'by ' . (string)($s['parent_name'] ?? '') . " · limit $max minutes", $id);
                $overdue[] = $id;
            }
        } catch (\Throwable $e) {
            // Advisory: the next tick tries again.
        }
        return ['overdue' => $overdue];
    }

    /**
     * Remove the worktree of a closed helper, but only a worktree that the tool made, and only when it is
     * clean and everything on its branch is merged into its base (R14). Otherwise keep it and say why.
     * The branch is never deleted. Nothing is pushed.
     *
     * @param array<string,mixed>|null $wt The `worktree` block of the helper's `spawn` data.
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
            $out['reason'] = 'git could not say whether the work is merged';
            return $out;
        }
        $task = $dir . '/' . self::TASK_DIR;
        foreach (['TASK.md', 'PROMPT.once', 'PROMPT.consumed', 'RESULT.json'] as $f) @unlink($task . '/' . $f);
        @rmdir($task);
        $r = self::git(['worktree', 'remove', '--force', $dir], $repo);
        if ($r['rc'] !== 0) {
            $out['reason'] = 'git could not remove the worktree folder';
            return $out;
        }
        return ['removed' => true, 'kept' => false, 'reason' => '', 'branch' => $branch];
    }

    /**
     * How far a helper's worktree is from "merged and clean": the number of changed files (the task folder
     * does not count) and the number of commits that are on the branch but not in the base.
     *
     * @param array<string,mixed> $wt
     * @return array{dirty:int,ahead:int,unknown:bool}
     */
    public static function worktreeState(array $wt): array
    {
        $dir = (string)($wt['path'] ?? '');
        $base = (string)($wt['base'] ?? 'HEAD');
        $st = self::git(['status', '--porcelain', '--untracked-files=all'], $dir);
        $unknown = $st['rc'] !== 0;
        $dirty = 0;
        foreach (preg_split('/\R/', $st['out']) ?: [] as $line) {
            if (trim($line) === '' || strpos($line, self::TASK_DIR . '/') !== false) continue;
            $dirty++;
        }
        $ahead = 0;
        $cnt = self::git(['rev-list', '--count', $base . '..HEAD'], $dir);
        if ($cnt['rc'] === 0 && preg_match('/^\d+$/D', trim($cnt['out'])) === 1) $ahead = (int)trim($cnt['out']);
        else $unknown = true;
        return ['dirty' => $dirty, 'ahead' => $ahead, 'unknown' => $unknown];
    }

    /**
     * The supervisor sweep (every ~30 s). Closes each reported helper or script run whose pane is idle again,
     * so the agent's last reply is never cut off. A workspace that is closed already, or whose pane is busy or
     * waits for an answer, is left for the next tick. Never throws.
     *
     * @param bool|null $runsOnly True selects script runs, false selects helpers, and null selects both.
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
    // Slice (e): answer a helper's permission prompt (R13), tag a parent's input (R22), confirm text (R16)
    // ======================================================================

    /** The tag the plugin puts in front of every text a parent types into its own helper (R22). */
    public static function parentTag(array $record): string
    {
        $name = (string)($record['spawn']['parent_name'] ?? 'parent');
        $name = trim((string)preg_replace('/[^A-Za-z0-9 ._-]/', '', $name));
        return '[from parent ' . ($name !== '' ? $name : 'workspace') . '] ';
    }

    /**
     * R22: text that the CREATOR of a helper types into it gets the fixed tag in front, so the helper knows the
     * words come from its parent and a person reading the terminal sees who typed them. Any other text stays
     * as it is.
     *
     * @param array<string,mixed> $record The target workspace record.
     */
    public static function tagForTarget(array $record, string $text): string
    {
        $caller = self::callerId();
        if ($caller !== '' && self::isHelper($record) && (string)($record['createdBy'] ?? '') === $caller) {
            return self::parentTag($record) . $text;
        }
        return $text;
    }

    /**
     * R13: a parent answers a permission prompt of a helper that it started. Allow-once answers only: a short
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
                return ['error' => 'You may answer the prompt of a helper workspace that you started yourself. This workspace is not one.'];
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
            if (!ProcessManager::isRunning($workspaceId)) return ['error' => "Helper '$name' is not running."];
            if (!self::gapOpen('answer-' . $workspaceId, self::ANSWER_GAP_SECONDS)) {
                return ['error' => "You answered helper '$name' too recently. Wait a few seconds and try again."];
            }
            $gate = self::gate($agentId, $workspaceId);
            if ($gate['ready']) {
                return ['error' => "Helper '$name' waits for no prompt. To send a message to it, use aicli_send_input without answerPrompt."];
            }
            if (!in_array($gate['reason'], self::PROMPT_REASONS, true)) {
                return ['error' => "Helper '$name' is busy, not asking a question (" . $gate['reason'] . '). Wait and look at its screen with aicli_read_workspace_screen.'];
            }
            $screen = self::$paneCapture !== null
                ? (string)(self::$paneCapture)($agentId, $workspaceId)
                : TmuxService::plainPaneText(TmuxService::capturePaneRaw($agentId, $workspaceId));
            $tail = implode("\n", array_slice(preg_split('/\R/', $screen) ?: [], -24));
            foreach (self::PROMPT_DENY_PATTERNS as $pattern) {
                if (preg_match($pattern, $tail)) {
                    return ['error' => "The prompt of helper '$name' looks like it touches live state, a push, a delete or another workspace. A parent may not answer it. Ask a person."];
                }
            }
            $res = self::$paneTyper !== null
                ? (self::$paneTyper)($agentId, $workspaceId, $answer, true)
                : TmuxService::pasteAndConfirm($agentId, $workspaceId, $answer, true);
            if (($res['status'] ?? '') !== 'ok') return ['error' => (string)($res['message'] ?? 'Could not type the answer.')];
            self::emit('workspace.prompt_answered', ['workspaceId' => $workspaceId, 'agentId' => $agentId],
                "Parent answered a prompt of helper '$name'", ['answer' => $answer, 'prompt' => self::safeText($tail, 400)]);
            return ['workspaceId' => $workspaceId, 'name' => $name, 'answered' => true, 'answer' => $answer];
        } catch (\Throwable $e) {
            return ['error' => 'The answer could not be sent: ' . self::safeText($e->getMessage(), 300)];
        }
    }

    /**
     * R16: what the confirm overlay says when a person closes a workspace in the Manager. For a workspace a
     * person made, or a helper that reported done and has a clean, merged worktree, no confirm is needed.
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
            $reasons[] = 'The task is not reported as done yet. The helper has not called aicli_finish_task.';
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
