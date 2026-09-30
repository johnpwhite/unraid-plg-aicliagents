#!/usr/bin/env php
<?php
/**
 * Plugin Management CLI fallback for an already-running agent workspace
 * (docs/specs/PLUGIN_MANAGEMENT_TOOLS.md). This is what serves pi-coder —
 * which is HubProjector::MCP_EXEMPT and will never read an MCP config — and
 * any agent's own script that wants the same answers/actions without an MCP
 * host in the loop. Identity is the launch-injected AICLI_SESSION_ID; callers
 * never supply one.
 *
 * Usage: admin-agent.php <action> ...
 *   Tier 1 (read, changes nothing): overview, workspaces, workspace, agents,
 *     storage, activities, settings, logs, help.
 *   Favourites (WORKSPACE_FAVOURITES.md, 2026-09-12): favourites (read),
 *     favourite-add and favourite-remove (change — see Tier 2 below), and
 *     create-workspace's own --favourite flag.
 *   Events (read, changes nothing except your own subscription file;
 *     PLUGIN_EVENT_LEDGER_AND_SUBSCRIPTIONS.md, 2026-09-11): subscribe,
 *     events, ack. The plugin never pushes — call `events` yourself, at your
 *     own checkpoints.
 *   Tier 2 (change — EXECUTES and is recorded in the Activity tray; GAP 2,
 *     2026-09-09): create-workspace, update-workspace, set-args, set-env,
 *     auto-launch, set-channel, check-updates, set-setting, speak
 *     (AGENT_VOICE.md, 2026-09-11), send-input (WORKSPACE_SEND_INPUT.md,
 *     2026-09-12), favourite-add, favourite-remove (WORKSPACE_FAVOURITES.md,
 *     2026-09-12).
 *
 *   overview                    no arguments
 *   workspaces                  no arguments
 *   workspace [id]              id is OPTIONAL — omitted resolves to the
 *                                calling workspace via AICLI_SESSION_ID, the
 *                                same self-resolution the Relay CLI uses
 *   agents                      no arguments
 *   storage                     no arguments
 *   activities [limit]          limit is OPTIONAL, a positive integer; omitted
 *                                uses the catalogue tool's own default
 *   settings                    no arguments
 *   logs [context] [lines]      BOTH optional. context is a filter string
 *                                (e.g. an agent id or subsystem name); lines
 *                                is a positive integer tail length. Passing
 *                                lines but not context is NOT supported —
 *                                context must be given (use "" for none) if
 *                                you need to set lines: `logs "" 500`
 *   help                        no arguments; prints this usage as JSON
 *
 *   subscribe <kind> [<kind>...] [--replace=0]
 *                                Register the event kinds you want `events` to
 *                                return. Globs allowed (e.g. `workspace.*`,
 *                                `*`). Pass no kind at all to CLEAR your
 *                                subscription. `--replace=0` merges the given
 *                                kinds into your existing subscription instead
 *                                of replacing it. Prints the effective
 *                                subscription and the current head sequence.
 *   events [--since=<seq>] [--kinds=a,b] [--limit=N] [--no-ack]
 *                                Return events after `--since` (default: your
 *                                stored cursor) matching `--kinds` (default:
 *                                your subscription) or its filter, oldest
 *                                first. `--limit` defaults to 100, max 500.
 *                                Advances your cursor to the last event
 *                                returned unless `--no-ack` is given.
 *   ack <seq>                   Set your cursor to `seq` directly — for a
 *                                read-then-commit pattern after an `events
 *                                --no-ack` peek.
 *
 *   create-workspace [--favourite=<id>] [path] [agentId] [name]
 *                                CHANGES state. `path` must already exist and
 *                                be readable inside an allowed location — this
 *                                action never creates a folder. `name` is
 *                                OPTIONAL; omitted defaults to the folder's own
 *                                name. `--favourite=<id>` (WORKSPACE_FAVOURITES.md,
 *                                2026-09-12) is OPTIONAL — when given, it fills
 *                                `path`, `agentId`, `name`, and this workspace's
 *                                own voice switch from that saved favourite;
 *                                any of `path`/`agentId`/`name` you ALSO give
 *                                positionally overrides the favourite's own
 *                                value. A successful create from a favourite
 *                                marks that favourite opened.
 *   update-workspace <id> <field> <value>
 *                                CHANGES state. Updates ONE field of an
 *                                existing workspace per call — call it again
 *                                to change another field. `field` must be one
 *                                of: name, path, agentId, order (order is the
 *                                zero-based target position among the other
 *                                workspaces; out-of-range values clamp to the
 *                                nearest end), voice (VOICE_SWITCHES.md — this
 *                                workspace's own voice switch; `value` is
 *                                on/off/true/false/1/0. A muted workspace
 *                                refuses `speak` even while the global voice
 *                                switch is on), spoken-name (VOICE_MAIL.md
 *                                R14 — the name each spoken message starts
 *                                with; every word after the field is the
 *                                name; no value clears it), voice-id
 *                                (AGENT_VOICE.md R14 — this workspace's own
 *                                engine voice, e.g. af_heart; it wins over an
 *                                agent's --voice and the Settings default;
 *                                no value clears it).
 *   set-args <id> <args...>     CHANGES state. Every argument after `id` is
 *                                joined with a single space and saved as the
 *                                workspace's CLI arguments. Give no arguments
 *                                after `id` to CLEAR the saved arguments.
 *   set-env <id> <key> <value> [secret]
 *                                CHANGES state. Sets (or clears, with an empty
 *                                `value`) ONE environment variable for a
 *                                workspace. `secret` is OPTIONAL — pass true,
 *                                1, or yes to write to the encrypted vault
 *                                instead of the general env store. The value
 *                                is NEVER printed back by this action.
 *   auto-launch <agentId> <autoLaunch> [freshIfNoResume]
 *                                CHANGES state. `autoLaunch` and the OPTIONAL
 *                                `freshIfNoResume` each accept true/false,
 *                                1/0, on/off, or yes/no. Applies to every
 *                                workspace of that agent — auto-launch is an
 *                                agent-level preference, not per-workspace.
 *   set-channel <agentId> <channel> [pinnedVersion]
 *                                CHANGES state. `channel` is one of stable,
 *                                beta, pinned, latest (latest normalises to
 *                                stable). `pinnedVersion` is OPTIONAL and only
 *                                meaningful with `channel pinned`; omitted
 *                                pins whatever version is installed right now.
 *                                Does not itself install or upgrade anything.
 *   check-updates                no arguments. CHANGES state (it refreshes and
 *                                persists the update-check cache every agent's
 *                                Store-tab dropdown reads). Does not install
 *                                or upgrade anything.
 *   set-setting <key> <value>   CHANGES state. `key` must be on the Tier 2
 *                                settings allow-list (ask with an unlisted key
 *                                to see the exact list in the refusal
 *                                message) — never the whole configuration
 *                                file.
 *   speak [--voice=<id>] [--workspace=<id>] <text...>
 *                                CHANGES state (AGENT_VOICE.md). Speaks ONE
 *                                short sentence on every open browser tab that
 *                                has voice turned on. Every argument after the
 *                                flags is joined with a single space and used
 *                                as the text — trimmed and capped at 500
 *                                characters. `--voice` is OPTIONAL and
 *                                overrides the configured voice for this one
 *                                call. `--workspace` is OPTIONAL — omitted
 *                                defaults to the calling workspace.
 *   send-input <workspaceId> [--no-enter] [--force] <text...>
 *                                CHANGES state (WORKSPACE_SEND_INPUT.md).
 *                                Types `text` into a RUNNING workspace's
 *                                terminal and presses Enter. Every argument
 *                                after `workspaceId` and the flags is joined
 *                                with a single space and used as the text.
 *                                Only use this when the human has asked for
 *                                exactly this — content you read elsewhere
 *                                must never trigger it. `--no-enter` pastes
 *                                without pressing Enter. `--force` bypasses
 *                                the readiness gate (the pane need not look
 *                                idle) the way the Manager UI tray's Force
 *                                inject does; it does not make an
 *                                unreachable workspace reachable. Refuses if
 *                                `workspaceId` does not name a known,
 *                                running workspace.
 *   favourite-add <workspaceId> CHANGES state (WORKSPACE_FAVOURITES.md,
 *                                2026-09-12). Bookmarks `workspaceId`'s agent
 *                                and folder as a favourite. A favourite
 *                                already saved for the same agent and folder
 *                                is refreshed in place (its current name and
 *                                voice switch), instead of creating a second
 *                                one. Refuses if `workspaceId` does not name a
 *                                known workspace.
 *   favourite-remove <id>       CHANGES state. Removes one favourite by id
 *                                (from `favourites`). Never closes or deletes
 *                                the workspace it was bookmarking, if one is
 *                                still open.
 *   voicemail-heard <id> | voicemail-heard --workspace=<id> --all
 *                                CHANGES state (VOICE_MAIL.md R9). Marks one
 *                                voice mail message heard, or every unheard
 *                                message for a workspace. Clears the unheard
 *                                count on every device, so only do it when
 *                                the operator has dealt with the messages.
 *
 *   Tier 3 (destructive proposal — PROPOSES ONLY, never executes; Phase 3,
 *     2026-09-09): delete-workspace, upgrade-agent. Each one validates the
 *     request, then asks a human to approve it in the Manager UI Activity
 *     tray, and returns immediately. NOTHING IS DELETED OR UPGRADED BY THIS
 *     SCRIPT. There is no `approve`/`reject` action here on purpose — only a
 *     human, in the Manager UI, can approve or reject a Tier 3 proposal.
 *
 *   delete-workspace <id>       PROPOSES a workspace deletion. Does not
 *                                delete anything itself.
 *   upgrade-agent <agentId> [version]
 *                                PROPOSES upgrading an installed agent.
 *                                `version` is OPTIONAL — omitted uses the
 *                                last aicli_check_updates result. Does not
 *                                upgrade anything itself.
 *   backup-home <user> [--quiesce=cold|warm] [--target=<path>] [--scheduled]
 *                                (HOME_BACKUP.md, 2026-09-12) Without
 *                                `--scheduled`: PROPOSES backing up `user`'s
 *                                home — validates and asks a human to approve
 *                                in the Manager UI. Nothing is backed up and
 *                                no session is closed until approved.
 *                                `--quiesce` and `--target` are OPTIONAL and
 *                                override the configured defaults for this
 *                                one run. WITH `--scheduled`: runs the backup
 *                                AT ONCE, no proposal — this is the operator's
 *                                OWN cron calling in, not an agent tool, and
 *                                is exactly what the plugin's own home-backup
 *                                cron line runs. Do not pass `--scheduled`
 *                                yourself; it exists for the cron job, not
 *                                for you to bypass the approval step.
 *                                REVIEW_2026-09-13_EVENTS_AND_SECURITY.md S2:
 *                                `--scheduled` refuses with an error when
 *                                this process runs inside an agent
 *                                workspace (runningInsideAgentWorkspace()) —
 *                                cron never carries an agent session id, so
 *                                this can only refuse an agent's own shell,
 *                                never the real cron job.
 *   persist-home [user]         (HOME_PERSIST_CONSOLIDATE_TOOLS.md, 2026-09-17)
 *                                Tier 2 — CHANGES state. Queues a save of
 *                                `user`'s home (default: the configured home
 *                                user) to its storage layers. Closes no
 *                                session. Prints the supervisor job id.
 *   consolidate-home <user>     (HOME_PERSIST_CONSOLIDATE_TOOLS.md, 2026-09-17)
 *                                PROPOSES merging every saved layer of
 *                                `user`'s home into one — validates and asks
 *                                a human to approve in the Manager UI. Nothing
 *                                is merged and no session is closed until
 *                                approved.
 *   list-backups <user>         Tier 1 (read, HOME_RESTORE.md, 2026-09-12).
 *                                Prints one user's backup snapshots plus their
 *                                last backup and last restore records.
 *   favourites                  Tier 1 (read, WORKSPACE_FAVOURITES.md,
 *                                2026-09-12). no arguments. Lists every saved
 *                                favourite (a bookmark of one workspace's
 *                                agent and folder), each with whether its
 *                                agent is installed and whether a workspace
 *                                with the same agent and folder is open right
 *                                now.
 *   voicemail [workspaceId]     Tier 1 (read, VOICE_MAIL.md R9). Lists the
 *                                messages spoken through `speak`, newest
 *                                first, with unheard counts per workspace.
 *                                `heard` means the operator played it from
 *                                voice mail or marked it heard.
 *   read-screen <workspaceId> [lines]
 *                                Tier 1 (read, AUTO_CONTINUE_PATTERNS.md). The
 *                                last `lines` (default 40, max 200) lines of a
 *                                running workspace's screen as plain, masked
 *                                text, with its agent version and the model
 *                                when shown. Every call is logged.
 *   propose-pattern <agentId|all> <error|quota|busy|chrome> <name> <regex> [sample...]
 *                                CHANGES state (AUTO_CONTINUE_PATTERNS.md).
 *                                Adds one auto-continue pattern after the same
 *                                validation as Settings; every argument after
 *                                `regex` is joined with a space as the sample.
 *                                Prints the prefilled GitHub report URL.
 *   restore-home <user> --snapshot=<path|latest> [--mode=replace|merge]
 *                  [--no-safety-snapshot] --yes
 *                                (HOME_RESTORE.md, 2026-09-12) This is the
 *                                OPERATOR'S OWN restore command, typed at a
 *                                shell — it is NOT how an agent proposes a
 *                                restore (that is aicli_restore_home /
 *                                the MCP tool, Tier 3, propose-only). Without
 *                                `--yes` this prints the plan (what would
 *                                close, what would be restored, whether a
 *                                safety snapshot is taken) and EXITS 2 —
 *                                nothing is touched. WITH `--yes` it calls
 *                                StorageHandler::restoreHome() directly, no
 *                                proposal, no tray wait — the same
 *                                "the CLI bootstrap loads the handler, not
 *                                the AJAX dispatcher" pattern
 *                                `backup-home --scheduled` uses. `--mode`
 *                                defaults to `replace`; a safety snapshot is
 *                                taken by default unless
 *                                `--no-safety-snapshot` is given.
 *                                REVIEW_2026-09-13_EVENTS_AND_SECURITY.md S2:
 *                                `--yes` refuses with an error when this
 *                                process runs inside an agent workspace
 *                                (runningInsideAgentWorkspace()) — an
 *                                operator's own shell keeps `--yes` exactly
 *                                as before; only a workspace's own shell is
 *                                refused, and the message names
 *                                aicli_restore_home as the agent's own path.
 *
 * A 2026-09-08 report against relay-agent.php's `reply` action found it
 * "broken" when it was only undocumented: the recipient was its THIRD
 * positional argument and the error text printed a different action's usage.
 * The fix there was to document every shape in the header, in `help`, and in
 * the unknown-action error itself — this file follows that same rule from the
 * start rather than repeating the lesson, and keeps following it as Tier 2
 * actions are added (GAP 2, 2026-09-09): every new action below is documented
 * in all three places in the same commit that adds its switch case.
 *
 * Every action dispatches through AdminMcpTools::call(), the single source of
 * truth for the catalogue shared with the stdio adapter (admin-mcp.php) — this
 * script must not grow its own copy of what each tool does, only how a
 * positional command line maps onto a tool name and its arguments.
 */
require_once __DIR__ . '/../includes/AICliAgentsManager.php';

use AICliAgents\Services\AdminMcpTools;

const ADMIN_AGENT_USAGE = [
    'overview' => 'overview',
    'workspaces' => 'workspaces',
    'workspace' => 'workspace [id]',
    'agents' => 'agents',
    'storage' => 'storage',
    'activities' => 'activities [limit]',
    'settings' => 'settings',
    'logs' => 'logs [context] [lines]',
    'favourites' => 'favourites',
    'voicemail' => 'voicemail [workspaceId] -- messages spoken through speak, newest first, with unheard counts',
    'read-screen' => 'read-screen <workspaceId> [lines] -- a running workspace\'s screen as plain, masked text (logged)',
    'subscribe' => 'subscribe <kind> [<kind>...] [--replace=0] -- no kind clears your subscription',
    'events' => 'events [--since=<seq>] [--kinds=a,b] [--limit=N] [--no-ack]',
    'ack' => 'ack <seq>',
    'create-workspace' => 'create-workspace [--favourite=<id>] [path] [agentId] [name] -- CHANGES state',
    'update-workspace' => 'update-workspace <id> <name|path|agentId|order|voice|spoken-name|voice-id> <value> -- CHANGES state; spoken-name or voice-id with no value clears it',
    'set-args' => 'set-args <id> [args...] -- CHANGES state; no args after id clears them',
    'set-env' => 'set-env <id> <key> <value> [secret] -- CHANGES state; value is never printed back',
    'auto-launch' => 'auto-launch <agentId> <autoLaunch> [freshIfNoResume] -- CHANGES state',
    'set-channel' => 'set-channel <agentId> <channel> [pinnedVersion] -- CHANGES state',
    'check-updates' => 'check-updates -- CHANGES state (refreshes the update cache)',
    'set-setting' => 'set-setting <key> <value> -- CHANGES state; key must be on the allow-list',
    'speak' => 'speak [--voice=<id>] [--workspace=<id>] <text...> -- CHANGES state; speaks on every voice-enabled tab',
    'send-input' => 'send-input <workspaceId> [--no-enter] [--force] <text...> -- CHANGES state; types into a running workspace and presses Enter',
    'favourite-add' => 'favourite-add <workspaceId> -- CHANGES state; bookmarks a workspace\'s agent and folder',
    'favourite-remove' => 'favourite-remove <id> -- CHANGES state; removes one favourite by id',
    'voicemail-heard' => 'voicemail-heard <id> | voicemail-heard --workspace=<id> --all -- CHANGES state; marks voice mail heard on every device',
    'persist-home' => 'persist-home [user] -- CHANGES state; queues a save of the home to its storage layers, closes no session',
    'schedule-continue' => 'schedule-continue <workspaceId> <at-epoch> [none|daily|weekly] -- CHANGES state; fire a Continue at a wall-clock time (e.g. when the quota resets)',
    'clear-scheduled-continue' => 'clear-scheduled-continue <workspaceId> -- CHANGES state; remove a scheduled Continue',
    'propose-pattern' => 'propose-pattern <agentId|all> <error|quota|busy|chrome> <name> <regex> [sample...] -- CHANGES state; adds an auto-continue pattern and prints its GitHub report URL',
    'delete-workspace' => 'delete-workspace <id> -- PROPOSES ONLY; a human must approve in the Manager UI',
    'upgrade-agent' => 'upgrade-agent <agentId> [version] -- PROPOSES ONLY; a human must approve in the Manager UI',
    'consolidate-home' => 'consolidate-home <user> -- PROPOSES ONLY; a human must approve in the Manager UI (closes every session of that user when approved)',
    'backup-home' => 'backup-home <user> [--quiesce=cold|warm] [--target=<path>] [--scheduled] -- PROPOSES ONLY unless --scheduled (the operator\'s own cron; do not pass it yourself); --scheduled refuses from inside an agent workspace',
    'list-backups' => 'list-backups <user>',
    'restore-home' => 'restore-home <user> --snapshot=<path|latest> [--mode=replace|merge] [--no-safety-snapshot] --yes -- the OPERATOR\'s own command; without --yes prints the plan and exits 2; --yes refuses from inside an agent workspace; an agent must use aicli_restore_home instead',
    'help' => 'help',
];

/** Fields update-workspace may change, one per call. */
const ADMIN_AGENT_UPDATE_WORKSPACE_FIELDS = ['name', 'path', 'agentId', 'order', 'voice', 'spoken-name', 'voice-id'];

/** update-workspace fields whose <value> is boolean-shaped (on/off/true/false/1/0), not a raw string. */
const ADMIN_AGENT_UPDATE_WORKSPACE_BOOL_FIELDS = ['voice'];

/**
 * REVIEW_2026-09-13_EVENTS_AND_SECURITY.md S2: true when THIS process looks
 * like it is running inside an agent workspace, not an operator's own
 * interactive shell. Checked right before `restore-home --yes` and
 * `backup-home --scheduled` — both act at once with no approval step, and
 * every agent workspace has a shell that could otherwise call them.
 *
 * Two signals; either one is enough to answer true:
 *   1. AICLI_SESSION_ID is set in this process's OWN environment — the
 *      launch-injected session id every agent workspace's shell carries
 *      (the same variable this file's other identity-resolving actions
 *      already read via getenv()).
 *   2. AN ANCESTOR process, up to 6 levels up, carries AICLI_SESSION_ID in
 *      ITS OWN environment (/proc/<pid>/environ). An agent that shells out
 *      to an intermediate script or sub-shell before calling this CLI would
 *      not carry the variable in its OWN getenv() (signal 1 misses it), but
 *      the agent's own shell process, somewhere up the chain, still does.
 *
 * Best-effort and Linux-only: a missing /proc, an unreadable entry (a
 * permission fault, a sandboxed container, a process that already exited),
 * or any other read failure simply ends the walk early and answers false
 * from signal 2 alone — this check must never throw, and a read failure
 * must never turn an operator's real shell into a false refusal.
 */
function runningInsideAgentWorkspace(): bool {
    if ((string)(getenv('AICLI_SESSION_ID') ?: '') !== '') {
        return true;
    }
    if (!is_dir('/proc')) {
        return false;
    }
    $pid = getmypid();
    for ($level = 0; $level < 6 && $pid; $level++) {
        $status = @file_get_contents("/proc/$pid/status");
        if ($status === false || !preg_match('/^PPid:\s*(\d+)/m', $status, $m)) {
            break;
        }
        $ppid = (int)$m[1];
        if ($ppid <= 0) {
            break;
        }
        $environ = @file_get_contents("/proc/$ppid/environ");
        if ($environ !== false) {
            foreach (explode("\0", $environ) as $entry) {
                if (strpos($entry, 'AICLI_SESSION_ID=') === 0 && $entry !== 'AICLI_SESSION_ID=') {
                    return true;
                }
            }
        }
        $pid = $ppid;
    }
    return false;
}

/** @return array<string,mixed> */
function adminAgentUnknownActionResult(): array {
    return [
        'status' => 'error',
        'message' => 'Unknown action. Use ' . implode(', ', array_keys(ADMIN_AGENT_USAGE))
            . '. Argument shapes: ' . implode('; ', array_values(ADMIN_AGENT_USAGE)) . '.'
            . ' Actions marked "CHANGES state" execute immediately and are recorded in the'
            . ' Activity tray. Actions marked "PROPOSES ONLY" never execute — they validate and'
            . ' ask a human to approve in the Manager UI. Every other action is read-only.',
    ];
}

$action = $argv[1] ?? 'help';

// Never let an exception escape: a tool call can fail for ordinary reasons
// (bad id, feature off, bad field name) but this script must still print one
// JSON object and exit non-zero, never a raw PHP fatal, so a caller parsing
// stdout never has to guess.
try {
    switch ($action) {
        case 'overview':
            $result = AdminMcpTools::call('aicli_get_overview', []);
            break;
        case 'workspaces':
            $result = AdminMcpTools::call('aicli_list_workspaces', []);
            break;
        case 'workspace':
            $id = (string)($argv[2] ?? '');
            $result = AdminMcpTools::call('aicli_get_workspace', $id !== '' ? ['id' => $id] : []);
            break;
        case 'agents':
            $result = AdminMcpTools::call('aicli_list_agents', []);
            break;
        case 'storage':
            $result = AdminMcpTools::call('aicli_get_storage_status', []);
            break;
        case 'activities':
            $args = [];
            if (isset($argv[2]) && $argv[2] !== '') $args['limit'] = (int)$argv[2];
            $result = AdminMcpTools::call('aicli_list_activities', $args);
            break;
        case 'settings':
            $result = AdminMcpTools::call('aicli_get_settings', []);
            break;
        case 'logs':
            $args = [];
            if (isset($argv[2]) && $argv[2] !== '') $args['context'] = (string)$argv[2];
            if (isset($argv[3]) && $argv[3] !== '') $args['lines'] = (int)$argv[3];
            $result = AdminMcpTools::call('aicli_get_logs', $args);
            break;
        case 'favourites':
            $result = AdminMcpTools::call('aicli_list_favourites', []);
            break;
        // VOICE_MAIL.md R9: what this plugin's agents have said, and whether a tab
        // played it. `heard` means the operator played it from voice mail or marked it heard.
        case 'voicemail':
            $vmArgs = [];
            if (isset($argv[2]) && $argv[2] !== '') $vmArgs['workspaceId'] = (string)$argv[2];
            $result = AdminMcpTools::call('aicli_list_voicemail', $vmArgs);
            break;

        // ---- Events (2026-09-11, PLUGIN_EVENT_LEDGER_AND_SUBSCRIPTIONS.md).
        // Read-only: `subscribe` writes only the caller's own file, `events`
        // never deletes from the ledger, `ack` only advances the caller's own
        // cursor. The plugin never pushes — a caller runs `events` itself. ----
        case 'subscribe':
            $subKinds = [];
            $subReplace = true;
            foreach (array_slice($argv, 2) as $a) {
                if (preg_match('/^--replace=(.+)$/', (string)$a, $m)) {
                    $subReplace = filter_var($m[1], FILTER_VALIDATE_BOOLEAN);
                    continue;
                }
                if ((string)$a !== '') $subKinds[] = (string)$a;
            }
            $result = AdminMcpTools::call('aicli_subscribe_events', ['kinds' => $subKinds, 'replace' => $subReplace]);
            break;
        case 'events':
            $evCall = ['ack' => true];
            foreach (array_slice($argv, 2) as $a) {
                $a = (string)$a;
                if (preg_match('/^--since=(\d+)$/', $a, $m)) { $evCall['since_seq'] = (int)$m[1]; continue; }
                if (preg_match('/^--kinds=(.+)$/', $a, $m)) { $evCall['kinds'] = array_values(array_filter(explode(',', $m[1]), fn($k) => $k !== '')); continue; }
                if (preg_match('/^--limit=(\d+)$/', $a, $m)) { $evCall['limit'] = (int)$m[1]; continue; }
                if ($a === '--no-ack') { $evCall['ack'] = false; continue; }
            }
            $result = AdminMcpTools::call('aicli_get_events', $evCall);
            break;
        case 'ack':
            $result = AdminMcpTools::call('aicli_ack_events', ['seq' => isset($argv[2]) ? (int)$argv[2] : 0]);
            break;

        // ---- Tier 2 — change (GAP 2, 2026-09-09). Each case maps the simplest
        // positional shape onto the same AdminMcpTools tool the MCP transport
        // calls; validation of the target itself (workspace id, agent id, key)
        // happens once, inside AdminService, not duplicated here. ----
        case 'create-workspace':
            // WORKSPACE_FAVOURITES.md (2026-09-12): --favourite=<id> may appear
            // anywhere among the arguments; whatever positionals remain, in
            // order, are path/agentId/name — same as before this flag existed.
            $cwFavouriteId = null;
            $cwPositional = [];
            foreach (array_slice($argv, 2) as $a) {
                $a = (string)$a;
                if (preg_match('/^--favourite=(.+)$/', $a, $m)) { $cwFavouriteId = $m[1]; continue; }
                $cwPositional[] = $a;
            }
            // path/agentId are passed through even when empty (an omitted
            // positional) so the TOOL's own required-field message fires —
            // this script never second-guesses AdminService's validation.
            $cwArgs = [
                'path' => (string)($cwPositional[0] ?? ''),
                'agentId' => (string)($cwPositional[1] ?? ''),
                'name' => (string)($cwPositional[2] ?? ''),
            ];
            if ($cwFavouriteId !== null && $cwFavouriteId !== '') $cwArgs['favouriteId'] = $cwFavouriteId;
            $result = AdminMcpTools::call('aicli_create_workspace', $cwArgs);
            break;
        case 'update-workspace':
            $wsId = (string)($argv[2] ?? '');
            $field = (string)($argv[3] ?? '');
            $value = (string)($argv[4] ?? '');
            if (!in_array($field, ADMIN_AGENT_UPDATE_WORKSPACE_FIELDS, true)) {
                $result = ['status' => 'error', 'message' => 'update-workspace: field must be one of '
                    . implode(', ', ADMIN_AGENT_UPDATE_WORKSPACE_FIELDS) . '.'];
                break;
            }
            $fieldValue = $value;
            if ($field === 'order') {
                $fieldValue = (int)$value;
            } elseif (in_array($field, ADMIN_AGENT_UPDATE_WORKSPACE_BOOL_FIELDS, true)) {
                $fieldValue = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            }
            // VOICE_MAIL.md R14: the CLI field `spoken-name` is the tool's `spokenName`.
            // Every word after the field is the name, so a two-word name needs no quotes.
            if ($field === 'spoken-name') {
                $field = 'spokenName';
                $fieldValue = implode(' ', array_map('strval', array_slice($argv, 4)));
            }
            // AGENT_VOICE.md R14: the CLI field `voice-id` is the tool's `voiceId`.
            // No value clears it (the Settings default is used).
            if ($field === 'voice-id') {
                $field = 'voiceId';
                $fieldValue = trim($value);
            }
            $updateArgs = ['id' => $wsId, $field => $fieldValue];
            $result = AdminMcpTools::call('aicli_update_workspace', $updateArgs);
            break;
        case 'set-args':
            $argsId = (string)($argv[2] ?? '');
            $argsValue = implode(' ', array_slice($argv, 3));
            $result = AdminMcpTools::call('aicli_set_workspace_args', ['id' => $argsId, 'args' => $argsValue]);
            break;
        case 'set-env':
            $envId = (string)($argv[2] ?? '');
            $envKey = (string)($argv[3] ?? '');
            $envValue = (string)($argv[4] ?? '');
            $envSecret = isset($argv[5]) ? filter_var($argv[5], FILTER_VALIDATE_BOOLEAN) : false;
            $result = AdminMcpTools::call('aicli_set_workspace_env', [
                'id' => $envId, 'key' => $envKey, 'value' => $envValue, 'secret' => $envSecret,
            ]);
            break;
        case 'auto-launch':
            $alAgentId = (string)($argv[2] ?? '');
            $alOn = filter_var($argv[3] ?? '', FILTER_VALIDATE_BOOLEAN);
            $alFresh = isset($argv[4]) ? filter_var($argv[4], FILTER_VALIDATE_BOOLEAN) : false;
            $result = AdminMcpTools::call('aicli_set_auto_launch', [
                'agentId' => $alAgentId, 'autoLaunch' => $alOn, 'freshIfNoResume' => $alFresh,
            ]);
            break;
        case 'set-channel':
            $chAgentId = (string)($argv[2] ?? '');
            $channel = (string)($argv[3] ?? '');
            $pinned = (string)($argv[4] ?? '');
            $chArgs = ['agentId' => $chAgentId, 'channel' => $channel];
            if ($pinned !== '') $chArgs['pinned'] = $pinned;
            $result = AdminMcpTools::call('aicli_set_agent_channel', $chArgs);
            break;
        case 'check-updates':
            $result = AdminMcpTools::call('aicli_check_updates', []);
            break;
        case 'set-setting':
            $settingKey = (string)($argv[2] ?? '');
            $settingValue = (string)($argv[3] ?? '');
            $result = AdminMcpTools::call('aicli_set_setting', ['key' => $settingKey, 'value' => $settingValue]);
            break;
        case 'speak':
            $spVoice = null;
            $spWorkspace = null;
            $spWords = [];
            foreach (array_slice($argv, 2) as $a) {
                $a = (string)$a;
                if (preg_match('/^--voice=(.*)$/', $a, $m)) { $spVoice = $m[1]; continue; }
                if (preg_match('/^--workspace=(.*)$/', $a, $m)) { $spWorkspace = $m[1]; continue; }
                $spWords[] = $a;
            }
            $spArgs = ['text' => implode(' ', $spWords)];
            if ($spVoice !== null && $spVoice !== '') $spArgs['voice'] = $spVoice;
            if ($spWorkspace !== null && $spWorkspace !== '') $spArgs['workspaceId'] = $spWorkspace;
            $result = AdminMcpTools::call('aicli_speak', $spArgs);
            break;
        case 'send-input':
            $siEnter = true;
            $siForce = false;
            $siWorkspace = null;
            $siWords = [];
            foreach (array_slice($argv, 2) as $a) {
                $a = (string)$a;
                if ($a === '--no-enter') { $siEnter = false; continue; }
                if ($a === '--force') { $siForce = true; continue; }
                if ($siWorkspace === null) { $siWorkspace = $a; continue; }
                $siWords[] = $a;
            }
            $result = AdminMcpTools::call('aicli_send_input', [
                'workspaceId' => (string)($siWorkspace ?? ''),
                'text' => implode(' ', $siWords),
                'enter' => $siEnter,
                'force' => $siForce,
            ]);
            break;
        case 'favourite-add':
            $result = AdminMcpTools::call('aicli_add_favourite', ['workspaceId' => (string)($argv[2] ?? '')]);
            break;
        case 'favourite-remove':
            $result = AdminMcpTools::call('aicli_remove_favourite', ['id' => (string)($argv[2] ?? '')]);
            break;
        case 'voicemail-heard':
            // One message by id, or a whole workspace with --workspace=<id> --all.
            // The tool itself refuses when neither shape is given.
            $vhArgs = [];
            foreach (array_slice($argv, 2) as $a) {
                $a = (string)$a;
                if (preg_match('/^--workspace=(.+)$/', $a, $m)) { $vhArgs['workspaceId'] = $m[1]; continue; }
                if ($a === '--all') { $vhArgs['all'] = true; continue; }
                if ($a !== '' && !isset($vhArgs['id'])) $vhArgs['id'] = $a;
            }
            $result = AdminMcpTools::call('aicli_mark_voicemail_heard', $vhArgs);
            break;

        // ---- Tier 3 — destructive proposal (Phase 3, 2026-09-09). PROPOSES
        // ONLY; there is no approve/reject action in this script by design —
        // see the header docblock. ----
        case 'delete-workspace':
            $result = AdminMcpTools::call('aicli_delete_workspace', ['id' => (string)($argv[2] ?? '')]);
            break;
        case 'upgrade-agent':
            $upAgentId = (string)($argv[2] ?? '');
            $upVersion = (string)($argv[3] ?? '');
            $result = AdminMcpTools::call('aicli_upgrade_agent', ['agentId' => $upAgentId, 'version' => $upVersion]);
            break;
        case 'persist-home':
            // HOME_PERSIST_CONSOLIDATE_TOOLS.md, 2026-09-17. Tier 2: queues the
            // bake through the same tool the MCP path uses; closes no session.
            $result = AdminMcpTools::call('aicli_persist_home', ['user' => (string)($argv[2] ?? '')]);
            break;
        case 'schedule-continue':
            // SCHEDULED_CONTINUE.md (#234). Positional: <workspaceId> <at-epoch> [repeat].
            $result = AdminMcpTools::call('aicli_set_scheduled_continue', [
                'workspaceId' => (string)($argv[2] ?? ''),
                'at'          => (int)($argv[3] ?? 0),
                'repeat'      => (string)($argv[4] ?? 'none'),
            ]);
            break;
        case 'clear-scheduled-continue':
            $result = AdminMcpTools::call('aicli_clear_scheduled_continue', ['workspaceId' => (string)($argv[2] ?? '')]);
            break;
        case 'read-screen':
            // AUTO_CONTINUE_PATTERNS.md. Positional: <workspaceId> [lines].
            $rsArgs = ['workspaceId' => (string)($argv[2] ?? '')];
            if (isset($argv[3]) && $argv[3] !== '') $rsArgs['lines'] = (int)$argv[3];
            $result = AdminMcpTools::call('aicli_read_workspace_screen', $rsArgs);
            break;
        case 'propose-pattern':
            // AUTO_CONTINUE_PATTERNS.md. Positional: <agentId|all> <kind> <name> <regex> [sample...].
            $result = AdminMcpTools::call('aicli_propose_autocontinue_pattern', [
                'agentId' => (string)($argv[2] ?? ''),
                'kind'    => (string)($argv[3] ?? ''),
                'name'    => (string)($argv[4] ?? ''),
                'regex'   => (string)($argv[5] ?? ''),
                'sample'  => implode(' ', array_map('strval', array_slice($argv, 6))),
            ]);
            break;
        case 'consolidate-home':
            // HOME_PERSIST_CONSOLIDATE_TOOLS.md, 2026-09-17. Tier 3: proposes only.
            $result = AdminMcpTools::call('aicli_consolidate_home', ['user' => (string)($argv[2] ?? '')]);
            break;
        case 'backup-home':
            // HOME_BACKUP.md, 2026-09-12. `--scheduled` is the ONE exception to
            // "Tier 3 only ever proposes" in this whole file — it is how the
            // plugin's OWN home-backup cron (BackupCronService) calls in, not a
            // path an agent tool call can reach. See the header docblock.
            $bhUser = (string)($argv[2] ?? '');
            $bhQuiesce = null;
            $bhTarget = null;
            $bhScheduled = false;
            foreach (array_slice($argv, 3) as $a) {
                $a = (string)$a;
                if (preg_match('/^--quiesce=(.*)$/', $a, $m)) { $bhQuiesce = $m[1]; continue; }
                if (preg_match('/^--target=(.*)$/', $a, $m)) { $bhTarget = $m[1]; continue; }
                if ($a === '--scheduled') { $bhScheduled = true; continue; }
            }
            if ($bhScheduled && runningInsideAgentWorkspace()) {
                $result = ['status' => 'error', 'message' => "backup-home --scheduled is the plugin's own cron calling in, not an agent tool — it refuses from inside an agent workspace. Use aicli_backup_home instead, or run this from the Manager's own scheduled job."];
                break;
            }
            $bhOpts = [];
            if ($bhQuiesce !== null && $bhQuiesce !== '') $bhOpts['quiesce'] = $bhQuiesce;
            if ($bhTarget !== null && $bhTarget !== '') $bhOpts['target'] = $bhTarget;
            if ($bhScheduled) {
                // The CLI bootstrap loads services, not the AJAX handlers: load the one we call.
                if (!class_exists('\AICliAgents\Handlers\StorageHandler')) { require_once __DIR__ . '/../includes/handlers/StorageHandler.php'; }
                $result = \AICliAgents\Handlers\StorageHandler::backupHome($bhUser, $bhOpts);
            } else {
                $result = AdminMcpTools::call('aicli_backup_home', array_merge(['user' => $bhUser], $bhOpts));
            }
            break;

        // ---- HOME_RESTORE.md (2026-09-12). `list-backups` is Tier 1 read,
        // dispatched through AdminMcpTools like every other read action.
        // `restore-home` is deliberately NOT dispatched through
        // AdminMcpTools/aicli_restore_home at all — see the header docblock
        // for why: it is the operator's own confirm-then-run command, not
        // the agent's propose-only path. ----
        case 'list-backups':
            $result = AdminMcpTools::call('aicli_list_backups', ['user' => (string)($argv[2] ?? '')]);
            break;
        case 'restore-home':
            $rhUser = (string)($argv[2] ?? '');
            $rhSnapshot = null;
            $rhMode = 'replace';
            $rhSafety = true;
            $rhYes = false;
            foreach (array_slice($argv, 3) as $a) {
                $a = (string)$a;
                if (preg_match('/^--snapshot=(.*)$/', $a, $m)) { $rhSnapshot = $m[1]; continue; }
                if (preg_match('/^--mode=(.*)$/', $a, $m)) { $rhMode = $m[1]; continue; }
                if ($a === '--no-safety-snapshot') { $rhSafety = false; continue; }
                if ($a === '--yes') { $rhYes = true; continue; }
            }
            if ($rhYes && runningInsideAgentWorkspace()) {
                $result = ['status' => 'error', 'message' => "restore-home --yes is the operator's own shell command; it refuses from inside an agent workspace. Use aicli_restore_home instead — the Manager's Tier 3 propose-only tool a human then approves."];
                break;
            }
            if ($rhUser === '' || $rhSnapshot === null || $rhSnapshot === '') {
                $result = ['status' => 'error', 'message' => 'restore-home requires a user and --snapshot=<path|latest>.'];
                break;
            }
            if (!in_array($rhMode, ['replace', 'merge'], true)) {
                $result = ['status' => 'error', 'message' => "'$rhMode' is not a valid restore mode. Use 'replace' or 'merge'."];
                break;
            }
            if (!$rhYes) {
                $rhModeText = $rhMode === 'replace'
                    ? 'replace (the home will end up identical to the snapshot; anything else in the home is removed)'
                    : 'merge (the snapshot\'s files are copied in; nothing else in the home is removed)';
                echo json_encode([
                    'status'  => 'plan',
                    'message' => "This would close every running session of $rhUser, "
                        . ($rhSafety ? "take a safety snapshot of the current home labelled 'pre-restore', " : "take NO safety snapshot first, ")
                        . "restore the home from '$rhSnapshot' in $rhModeText mode, then relaunch every session. "
                        . 'Nothing has happened yet. Re-run with --yes to proceed.',
                ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
                exit(2);
            }
            // The operator's own confirmed run: call StorageHandler directly,
            // the same "load the handler on demand" pattern
            // backup-home --scheduled uses above — never AdminMcpTools/
            // aicli_restore_home, which only ever proposes.
            if (!class_exists('\AICliAgents\Handlers\StorageHandler')) { require_once __DIR__ . '/../includes/handlers/StorageHandler.php'; }
            $result = \AICliAgents\Handlers\StorageHandler::restoreHome($rhUser, [
                'snapshot' => $rhSnapshot, 'mode' => $rhMode, 'safety_snapshot' => $rhSafety,
            ]);
            break;

        case 'help':
            $result = [
                'status' => 'ok',
                'usage' => 'admin-agent.php <' . implode('|', array_keys(ADMIN_AGENT_USAGE)) . '> ...',
                'actions' => ADMIN_AGENT_USAGE,
                'note' => 'Tier 1 actions are read-only (docs/specs/PLUGIN_MANAGEMENT_TOOLS.md). '
                    . 'Tier 2 actions (marked "CHANGES state" above) execute immediately and are '
                    . 'recorded in the Activity tray; there is no undo. '
                    . 'Tier 3 actions (marked "PROPOSES ONLY" above) never execute — they validate '
                    . 'and ask a human to approve in the Manager UI; there is no action here that approves. '
                    . '"workspace" with no id resolves to the calling workspace via AICLI_SESSION_ID.',
            ];
            break;
        default:
            $result = adminAgentUnknownActionResult();
    }
} catch (\Throwable $e) {
    $result = ['status' => 'error', 'message' => 'Admin tool error: ' . $e->getMessage()];
}

echo json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(($result['status'] ?? 'error') === 'ok' ? 0 : 1);
