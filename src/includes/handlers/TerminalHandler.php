<?php
/**
 * <module_context>
 *     <name>TerminalHandler</name>
 *     <description>Handles terminal session AJAX actions: start, stop, restart, chat, logging.</description>
 *     <dependencies>AICliAgentsManager, ValidationService</dependencies>
 *     <constraints>Under 150 lines. Each method returns array for JSON encoding.</constraints>
 * </module_context>
 */

namespace AICliAgents\Handlers;

use AICliAgents\Services\ValidationService;

class TerminalHandler {

    public static function handle($action, $id) {
        switch ($action) {
            case 'start':            return self::start($id);
            case 'emergency_start':  return self::emergencyStart($id);
            case 'stop':             return self::stop($id);
            case 'graceful_close':   return self::gracefulCloseSerialized($id);
            case 'restart':          return self::restart($id);
            case 'restart_fresh':    return self::restartFresh($id);
            case 'reload_onto_current': return self::reloadOntoCurrent($id);
            case 'agent_signal_reload': return self::agentSignalReload($id);
            case 'get_chat_session': return self::getChatSession();
            case 'get_session_status': return self::getSessionStatus($id);
            case 'get_sessions_running': return self::getSessionsRunning();
            case 'get_asset_version': return self::getAssetVersion();
            case 'refresh_bridge':   return self::refreshBridge($id);
            case 'reconnect_stale_bridges': return self::reconnectStaleBridges();
            case 'attach_bridge':    return self::attachBridge($id);
            case 'continue_session': return self::continueSession($id);
            case 'set_scheduled_continue': return self::setScheduledContinueAction($id);
            case 'clear_scheduled_continue': return self::clearScheduledContinueAction($id);
            case 'get_resume_id':    return self::getResumeId();
            case 'log':              return self::log();
            case 'get_log':          return self::getLog();
            case 'get_log_contexts': return self::getLogContexts();
            case 'clear_log':        return self::clearLog();
            case 'list_sessions_for_agent': return self::listSessionsForAgent();
            case 'view_unreachable': return self::viewUnreachable($id);
            case 'stop_unreachable': return self::stopUnreachable($id);
            // AGENT_SPAWN_WORKSPACE.md R16: what the confirm overlay says when a person closes a helper. Reads only.
            case 'spawn_close_info': return \AICliAgents\Services\SpawnService::closeInfo((string)$id);
            default:                 return null;
        }
    }

    /** Actions handled by this handler. */
    public static function actions() {
        return ['start', 'emergency_start', 'stop', 'graceful_close', 'restart', 'restart_fresh', 'reload_onto_current', 'agent_signal_reload', 'get_chat_session', 'get_session_status', 'get_sessions_running', 'get_asset_version', 'refresh_bridge', 'reconnect_stale_bridges', 'attach_bridge', 'continue_session', 'set_scheduled_continue', 'clear_scheduled_continue', 'get_resume_id', 'log', 'get_log', 'get_log_contexts', 'clear_log', 'list_sessions_for_agent', 'view_unreachable', 'stop_unreachable', 'spawn_close_info'];
    }

    /**
     * #34: type a fixed "continue" nudge into a resumed workspace so it picks its
     * work back up. Operator-initiated (a drawer button); gated by the same pane
     * readiness check as relay delivery so it never answers a question/menu.
     */
    private static function continueSession($id): array {
        $id = (string)$id;
        // WHO asked. The row-menu click and the auto-continue poll call this SAME
        // action with the same id, so the log could not tell an operator's click
        // from the plugin acting by itself — the exact question an operator asks
        // when a continue arrives that they did not expect (2026-09-11: a new
        // workspace was auto-continued seconds after launch, and nothing on record
        // could show it was not a click). Allow-listed; anything else is recorded
        // as 'unstated' rather than trusted. Spec: docs/specs/CONTINUE_ON_RESTART.md
        $trigger = self::continueTrigger((string)($_REQUEST['trigger'] ?? ''));
        $agentId = '';
        foreach (\AICliAgents\Services\ConfigService::getWorkspaces()['sessions'] ?? [] as $w) {
            if ((string)($w['id'] ?? '') === $id) { $agentId = (string)($w['agentId'] ?? ''); break; }
        }
        if ($agentId === '') {
            $result = ['status' => 'error', 'message' => 'Unknown workspace session'];
        } elseif (!\AICliAgents\Services\ProcessManager::isRunning($id)) {
            $result = ['status' => 'error', 'message' => 'That workspace is not running.'];
        } elseif ($trigger === 'auto_restart' && \AICliAgents\Services\ContinueHold::isHeld($id)) {
            // #349 (2026-09-29): the operator restarted this workspace with "do not
            // continue" (Switch to the installed version). Every open page — each tab,
            // each device — runs its own auto-continue and asks here when it sees the
            // workspace run again; the server says no for all of them. The menu
            // Continue ('menu') is not held: a click is always the operator's choice.
            $result = ['status' => 'ok', 'delivered' => false, 'deferred' => true, 'reason' => 'held-after-switch'];
        } elseif (($slot = self::claimContinueSlot($id)) === null) {
            // #851: another page's request for this session is pasting right now.
            $result = ['status' => 'ok', 'delivered' => false, 'deferred' => true, 'reason' => 'in-flight'];
        } elseif (self::continueOnCooldown($id)) {
            self::releaseContinueSlot($slot);
            // REVIEW_2026-09-13_EVENTS_AND_SECURITY.md E3: the push guard (an
            // event handler) and the poll guard (DrawerPanel's own timer) can
            // both decide, within the same few seconds, that this session just
            // came back and needs a nudge — with no shared key between the two
            // call sites, both fired a real nudge. This per-session tmpfs
            // marker IS the shared key: a caller inside the window is told the
            // nudge already went out, and never gets a second one sent.
            $result = ['status' => 'ok', 'delivered' => false, 'deferred' => true, 'reason' => 'cooldown'];
        } else {
            $result = \AICliAgents\Services\TmuxService::submitContinueNudge($agentId, $id);
            // Only a REAL delivery arms the cooldown — a 'busy' (agent still
            // booting) must not block the auto-restart ladder's own retries
            // (recordContinue()'s doc comment: up to 8 tries, 4s apart).
            if (($result['status'] ?? '') === 'ok') {
                self::markContinueDelivered($id);
            }
            self::releaseContinueSlot($slot);
        }
        self::recordContinue($id, $agentId, $trigger, $result);
        return $result;
    }

    /**
     * SCHEDULED_CONTINUE.md (#234): the browser "Schedule…" dialog sets/clears a
     * timed Continue. Reuses AdminService's validated setter/clearer (the same
     * one the admin tool + CLI use), so the readiness-gated supervisor tick, the
     * validation and the sidecar storage are single-sourced. `at` is a unix epoch
     * the browser computes from the datetime picker.
     */
    private static function setScheduledContinueAction($id): array {
        $at = (int)($_REQUEST['at'] ?? 0);
        $repeat = (string)($_REQUEST['repeat'] ?? 'none');
        $message = (string)($_REQUEST['message'] ?? '');
        $source = (string)($_REQUEST['source'] ?? 'user');
        $reason = (string)($_REQUEST['reason'] ?? '');
        $r = \AICliAgents\Services\AdminService::setScheduledContinue((string)$id, $at, $repeat, $message, $source, $reason);
        if (isset($r['error'])) return ['status' => 'error', 'message' => (string)$r['error']];
        // The Drawer used to confirm with a page-corner popup. The Activity tray is
        // the one place a person looks, so the change is recorded there instead.
        // Only a person's save is recorded: a quota-detect schedule is the plugin
        // acting by itself and already has its own trail.
        if ($source === 'user') {
            $when = date('D j M, H:i', (int)($r['at'] ?? $at));
            $every = ($r['repeat'] ?? 'none') === 'none' ? '' : 'repeats ' . (($r['repeat'] ?? '') === 'daily' ? 'every day' : 'every week');
            self::recordScheduleActivity((string)$id, "Continue scheduled: {$when}", $every);
        }
        return array_merge(['status' => 'ok'], $r);
    }

    private static function clearScheduledContinueAction($id): array {
        // #831: with `entry` only that entry goes; without it every entry of the workspace goes.
        $entryId = (string)($_REQUEST['entry'] ?? '');
        $r = \AICliAgents\Services\AdminService::clearScheduledContinue((string)$id, $entryId);
        if (isset($r['error'])) return ['status' => 'error', 'message' => (string)$r['error']];
        self::recordScheduleActivity((string)$id, 'Scheduled continue cleared');
        return array_merge(['status' => 'ok'], $r);
    }

    /**
     * Write one finished Activity tray entry for a Scheduled Continue change made
     * from the Drawer. The title says what happened ("Continue scheduled: Tue 13 Oct,
     * 09:30"); the grey line names the workspace the way a person knows it, then
     * $extra ("repeats every day"). A record of a change, not a job.
     * Best effort: a tray write must never fail a save that already worked.
     * Spec: docs/specs/SCHEDULED_CONTINUE.md, docs/specs/ACTIVITY_TRAY_PLAIN_NOTICES.md rows 40-41
     */
    private static function recordScheduleActivity(string $id, string $title, string $extra = ''): void {
        try {
            $name = $id;
            foreach (\AICliAgents\Services\ConfigService::getWorkspaces()['sessions'] ?? [] as $w) {
                if ((string)($w['id'] ?? '') === $id) { $name = (string)($w['name'] ?? '') !== '' ? (string)$w['name'] : $id; break; }
            }
            $opId = 'schedule_continue_' . preg_replace('/[^a-z0-9_]/i', '_', $id) . '_' . bin2hex(random_bytes(4));
            \AICliAgents\Services\ActivityService::register($opId, 'admin', $title, ['meta' => ['tool' => 'schedule_continue', 'kind' => 'record', 'workspaceId' => $id]]);
            \AICliAgents\Services\ActivityService::finish($opId, $extra !== '' ? "$name · $extra" : $name);
        } catch (\Throwable $e) {
            // Swallowed on purpose — see doc comment above.
        }
    }

    /** The agent's display name ("Claude Code"), or its id when the registry has no name. */
    private static function agentDisplayName(string $agentId): string {
        try {
            $name = trim((string)(\AICliAgents\Services\AgentRegistry::getRegistry()[$agentId]['name'] ?? ''));
            if ($name !== '') return $name;
        } catch (\Throwable $e) {
            // Fall through to the id.
        }
        return $agentId;
    }

    /** How long a delivered continue nudge blocks a second one for the SAME session. */
    private const CONTINUE_COOLDOWN_SECONDS = 30;

    /** Test seam: overrides the cooldown folder. Null uses the real path. */
    public static ?string $continueCooldownDir = null;

    /** tmpfs directory holding one per-session cooldown marker file (the state root under test is the sandbox). */
    private static function continueCooldownDir(): string {
        return self::$continueCooldownDir ?? \AICliAgents\Services\SecretPaths::stateRoot() . '/continue';
    }

    private static function continueCooldownMarker(string $id): string {
        return self::continueCooldownDir() . '/.last-' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $id);
    }

    /**
     * True when a continue nudge was delivered to this session within the
     * last CONTINUE_COOLDOWN_SECONDS. Public: pinned directly by
     * ContinueCooldownTest without needing a live tmux session (this box's
     * unit container has none — the same documented constraint
     * TerminalHandlerTest's own class doc gives for getSessionsRunning()).
     */
    public static function continueOnCooldown(string $id): bool {
        $mtime = @filemtime(self::continueCooldownMarker($id));
        return $mtime !== false && (time() - $mtime) < self::CONTINUE_COOLDOWN_SECONDS;
    }

    /** Arms the cooldown after a nudge was actually delivered (status 'ok'). */
    public static function markContinueDelivered(string $id): void {
        $dir = self::continueCooldownDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        @touch(self::continueCooldownMarker($id));
    }

    /**
     * #851: the cooldown marker is armed only after the paste ends (about 3 s), so
     * two pages that ask in the same second both passed the check and both pasted
     * the continue message. The caller holds this per-session lock (non-blocking,
     * tmpfs) over the check, the paste and the mark. Returns the lock handle, or
     * null when another request for the same session holds it.
     *
     * @return resource|null
     */
    public static function claimContinueSlot(string $id) {
        $dir = self::continueCooldownDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $fh = @fopen($dir . '/.lock-' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $id), 'c');
        if ($fh === false) return null;
        if (!flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);
            return null;
        }
        return $fh;
    }

    /** @param resource $fh */
    public static function releaseContinueSlot($fh): void {
        flock($fh, LOCK_UN);
        fclose($fh);
    }

    /** Allow-listed origin of a continue request — never a free-form string in a log. */
    private static function continueTrigger(string $raw): string {
        return in_array($raw, ['menu', 'auto_restart'], true) ? $raw : 'unstated';
    }

    /**
     * Put a continue request on record, in words: always in the debug log, and in
     * the durable lifecycle log (which survives a reboot) for every outcome EXCEPT
     * an automatic retry that found the agent still booting. The lifecycle log
     * lives on the boot device — a USB stick on most servers — and the auto path
     * retries up to 8 times, 4 s apart; recording each 'busy' would write the stick
     * eight times per restart to say nothing new. The ladder's final outcome is
     * still recorded, and a user's click is recorded every time.
     */
    private static function recordContinue(string $id, string $agentId, string $trigger, array $result): void {
        $status = (string)($result['status'] ?? '');
        $who = [
            'menu'         => 'requested from the workspace menu (a user click)',
            'auto_restart' => 'sent automatically after the workspace restarted',
            'unstated'     => 'origin not stated (an older page, or not the workspace drawer)',
        ][$trigger];
        \AICliAgents\Services\LogService::log("Continue for $id ($agentId): $who — result: $status",
            \AICliAgents\Services\LogService::LOG_INFO, 'TerminalHandler');
        if ($trigger === 'auto_restart' && $status === 'busy') return;
        if (class_exists('\AICliAgents\Services\LifecycleLogService')) {
            \AICliAgents\Services\LifecycleLogService::log('info', 'workspace', 'continue_requested', [
                'session' => $id,
                'agent'   => $agentId,
                'trigger' => $trigger,
                'status'  => $status,
                'reason'  => (string)($result['reason'] ?? ''),
            ]);
        }
    }

    /**
     * Bulk running state for the drawer's live "running" dot: a map of every saved
     * workspace's session id -> whether its agent process is currently alive.
     *
     * Read-only (docs/specs/EVENT_FIRST_RECONCILIATION.md R3): this used to
     * also re-attempt any deferred Relay notice for each running session
     * (#148) — that drain now runs from the supervisor's own tick
     * (`_relay_drain_tick`, EVENT_FIRST_RECONCILIATION.md 1b.7), so delivery
     * no longer depends on the drawer being open or polling.
     */
    private static function getSessionsRunning(): array {
        $running = [];
        // #142: a running terminal executes the shell script from the generation
        // it LAUNCHED with, so a shipped fix does not reach an already-open
        // workspace. Report which sessions are on superseded code so the drawer
        // can say so and offer a bridge refresh.
        $stale = [];
        $active = \AICliAgents\Services\ProcessManager::activeGeneration();
        foreach (\AICliAgents\Services\ConfigService::getWorkspaces()['sessions'] ?? [] as $w) {
            $id = (string)($w['id'] ?? '');
            if ($id === '') continue;
            $running[$id] = \AICliAgents\Services\ProcessManager::isRunning($id);
            // HARD_STOP_RESILIENCE.md R4: only a workspace that has an actor_down state costs a probe.
            if ($running[$id] && \AICliAgents\Services\ActorDownAlertService::hasState($id)) {
                \AICliAgents\Services\ActorDownAlertService::recordHealthy($id, time(), \AICliAgents\Services\ProcessManager::tmuxSessionHasLiveAgent($id));
            }
            if (!$running[$id]) continue; // a stopped session picks up current code when it starts
            $launched = \AICliAgents\Services\ProcessManager::sessionLaunchGeneration($id);
            // Forgejo #364: stale = the BRIDGE code differs, the same test the
            // reconnect sweep uses, so the dot and the sweep never disagree and
            // a generation with identical bridge code shows no dot.
            if (\AICliAgents\Services\ProcessManager::bridgeIsStale($launched, $active)) {
                $stale[$id] = true;
            }
        }
        // Reconcile against ACTUAL live sessions so nothing runs invisibly: a live
        // terminal (ttyd socket) with no drawer entry is an orphan the UI must surface
        // + let the user close/adopt. Read-only (no reap side effects on this poll).
        // See DRAWER_ACTIVE_STATE_RECONCILE.md.
        $orphans = \AICliAgents\Services\TerminalService::reconcileOrphans(
            \AICliAgents\Services\TerminalService::listLiveSessions(),
            \AICliAgents\Services\ConfigService::getWorkspaces()['sessions'] ?? []
        );
        // #34 relocation, CONTINUE_ON_RESTART.md relocation (2026-09-09): the
        // auto-continue-on-restart preference is a server-side plugin config setting
        // (moved off the Relay's settings.json — this is session-restart behaviour,
        // not Relay messaging); surface it here so the drawer's restart-transition
        // logic reads the server value instead of a per-browser localStorage flag.
        // Never fatal.
        $autoContinue = false;
        if (class_exists('\AICliAgents\Services\ConfigService')) {
            try { $autoContinue = \AICliAgents\Services\ConfigService::autoContinueOnRestart(); }
            catch (\Throwable $e) { /* poll must not break on a settings read */ }
        }
        // WORKSPACE_APPLY_AGENT_VERSION.md: which workspaces are running an agent
        // version other than the installed one, and what that installed version
        // is called. Two separate facts because they answer different questions:
        // the map says WHICH workspaces can be switched, the versions say WHAT
        // they would be switched to. The UI names the target version rather than
        // calling it newer or older, because switching a release channel back to
        // Stable installs an OLDER version and makes it the current one.
        $otherVersion = [];
        $agentVersions = [];
        try {
            $otherVersion = \AICliAgents\Services\TerminalService::sessionsOnOtherAgentVersion();
            foreach (array_keys($otherVersion) as $sid) {
                $aFile = \AICliAgents\Services\UtilityService::getAgentIdPath((string)$sid);
                $aId = is_file($aFile) ? trim((string)@file_get_contents($aFile)) : '';
                if ($aId !== '' && !isset($agentVersions[$aId])) {
                    $agentVersions[$aId] = (string)\AICliAgents\Services\AgentRegistry::getInstalledVersion($aId);
                }
            }
        } catch (\Throwable $e) { /* a poll must never break on this */ }
        // #234: pending scheduled-continue per workspace, so the drawer can show a
        // clock tag on a workspace that will continue on its own at a set time.
        // #831: each value is a LIST of entries (id, at, repeat, reason, source, ...).
        $scheduledContinue = [];
        try { $scheduledContinue = \AICliAgents\Services\ConfigService::getScheduledContinueMap(); }
        catch (\Throwable $e) { /* a poll must never break on this */ }
        // #349: workspaces whose automatic Continue is held after an operator
        // switch, so every open page skips it by itself as well.
        $continueHeld = [];
        try { $continueHeld = \AICliAgents\Services\ContinueHold::heldMap(array_keys($running)); }
        catch (\Throwable $e) { /* a poll must never break on this */ }
        // 2026-09-29 (DRAWER_ACTIVE_STATE_RECONCILE.md, "unreachable copies"):
        // a second tmux server for a workspace, or one with no socket, is
        // invisible to the ttyd scan above. Read /proc only; never fatal.
        $unreachable = [];
        try { $unreachable = self::unreachableCopies(); }
        catch (\Throwable $e) { /* a poll must never break on this */ }
        return ['status' => 'ok', 'running' => $running, 'bound' => self::boundSockets($running), 'stale' => $stale, 'generation' => $active,
                'orphans' => $orphans, 'unreachable' => $unreachable, 'auto_continue' => $autoContinue, 'continue_held' => $continueHeld,
                'agent_version_differs' => $otherVersion, 'agent_installed_versions' => $agentVersions,
                'scheduled_continue' => $scheduledContinue];
    }

    /**
     * TERMINAL_FAST_ATTACH.md U2: which running workspaces have a ttyd socket now.
     * One `file_exists` per RUNNING id on the path `ProcessManager::isTtydBound`
     * uses (`/var/run/aicliterm-<id>.sock`); a stopped id costs nothing. The page
     * asks `attach_bridge` instead of `start` for an id that is true here. The
     * answer is a hint: `attach_bridge` checks again under the session lock.
     *
     * @param array<string,bool> $running id => runs
     * @return array<string,bool> id => socket exists (only the running ids)
     */
    public static function boundSockets(array $running, string $dir = '/var/run'): array {
        $bound = [];
        foreach ($running as $id => $runs) {
            if ($runs !== true) continue;
            $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$id);
            $bound[(string)$id] = $safe !== '' && file_exists($dir . '/aicliterm-' . $safe . '.sock');
        }
        return $bound;
    }

    /**
     * 2026-09-29: the unreachable tmux servers (TmuxDuplicateService::scan()),
     * each with the workspace name and whether the workspace is in the drawer.
     *
     * @return list<array<string,mixed>>
     */
    private static function unreachableCopies(): array {
        $rows = \AICliAgents\Services\TmuxDuplicateService::scan();
        if ($rows === []) return [];
        $names = [];
        foreach (\AICliAgents\Services\ConfigService::getWorkspaces()['sessions'] ?? [] as $w) {
            if (is_array($w) && ($w['id'] ?? '') !== '') $names[(string)$w['id']] = (string)($w['name'] ?? '');
        }
        foreach ($rows as &$r) {
            $r['inDrawer'] = isset($names[$r['sessionId']]);
            $r['name'] = $names[$r['sessionId']] ?? '';
        }
        unset($r);
        return $rows;
    }

    /** 2026-09-29: "Show screen" of an unreachable copy (read-only capture). */
    private static function viewUnreachable($id): array {
        return \AICliAgents\Services\TmuxDuplicateService::view((string)$id, (int)($_GET['serverPid'] ?? 0));
    }

    /**
     * 2026-09-29: "Stop this copy". A drawer action only (CSRF, the operator's
     * click); deliberately not an admin or Relay tool.
     */
    private static function stopUnreachable($id): array {
        return \AICliAgents\Services\TmuxDuplicateService::stop((string)$id, (int)($_GET['serverPid'] ?? 0));
    }

    /**
     * #142: restart only the web bridge for one session so it picks up the
     * current generation. The agent keeps running in its detached tmux session;
     * the browser's next `start` respawns ttyd against the live `src` symlink.
     */
    private static function refreshBridge($id): array {
        $ok = \AICliAgents\Services\ProcessManager::restartTerminalBridge((string)$id);
        return $ok
            ? ['status' => 'ok']
            : ['status' => 'error', 'message' => 'No live terminal bridge found for this workspace.'];
    }

    /**
     * AUTO_RECONNECT_ALL_ON_DEPLOY.md: reconnect EVERY terminal running
     * superseded code after a deploy, so the whole fleet moves onto the new
     * generation without the user clicking "Reconnect terminal" per tile. The
     * client calls this, then reloads; each terminal's next `start` respawns
     * ttyd against the live `src` symlink. Non-destructive — agents and their
     * tmux sessions keep running.
     */
    private static function reconnectStaleBridges(): array {
        $running = [];
        foreach (\AICliAgents\Services\ConfigService::getWorkspaces()['sessions'] ?? [] as $w) {
            $id = (string)($w['id'] ?? '');
            if ($id === '') continue;
            if (\AICliAgents\Services\ProcessManager::isRunning($id)) {
                $running[] = $id;
            }
        }
        $result = \AICliAgents\Services\ProcessManager::restartAllStaleBridges($running);
        // TERMINAL_BACKGROUND_WARM.md R2: start the new bridges now, in the
        // background, instead of one by one on each workspace's next switch.
        if ($result['ids'] !== []) {
            \AICliAgents\Services\BridgeWarmService::spawnRespawn($result['ids']);
        }
        return ['status' => 'ok', 'reconnected' => $result['reconnected'], 'ids' => $result['ids']];
    }

    /**
     * TERMINAL_BACKGROUND_WARM.md: the page's background warm-up. Attaches the
     * web bridge of a workspace whose agent already runs; never starts an agent
     * and never waits for the workspace lock (BridgeWarmService::attach).
     */
    private static function attachBridge($id): array {
        return \AICliAgents\Services\BridgeWarmService::attach((string)$id);
    }

    /**
     * Live bundle fingerprint for the client's auto-reload check: the max mtime of
     * the deployed index.js/index.css (the same value AICliAgents.page cache-busts
     * with). When a deploy — installer OR dev overlay — refreshes the bundle in
     * place, this rises above the value the open tab loaded with, and the app
     * reloads itself. Kept deliberately cheap (two filemtime stats).
     */
    private static function getAssetVersion(): array {
        $dir = '/usr/local/emhttp/plugins/unraid-aicliagents/assets/ui';
        $ver = (string) max((int)@filemtime("$dir/index.js"), (int)@filemtime("$dir/index.css"));
        // #143: the bundle mtime only moves when the UI changes, so a PHP- or
        // shell-only deploy produced NO reload prompt — the auto-reload covered
        // one class of fix while looking like it covered all of them. The active
        // generation changes on every promote whatever the payload touched, so
        // the client reloads when EITHER moves. One read of a ~40-byte file.
        return [
            'status' => 'ok',
            'asset_ver' => $ver,
            'generation' => \AICliAgents\Services\ProcessManager::activeGeneration(),
            'plugin_version' => \AICliAgents\Services\ConfigService::getVersion(),
        ];
    }

    /**
     * Return active workspace sessions whose agent matches ?agentId=... — used
     * by the Store card's install confirm dialog to list which sessions will
     * be gracefully closed before the upgrade runs, and by the New Workspace
     * overlay / Drawer to mark in-progress-upgrade agents busy.
     */
    /**
     * #463 R3: add `busy` (bool) and `busy_reason` to each session record. The Store
     * reads `busy` to decide which sessions a restart may touch. Any failure to read
     * the state gives busy=true.
     *
     * @param array<int,array<string,mixed>> $sessions
     * @return array<int,array<string,mixed>>
     */
    public static function withBusyFlag(string $agentId, array $sessions): array {
        foreach ($sessions as $i => $s) {
            $busy = ['busy' => true, 'reason' => 'unreadable'];
            try {
                if (!empty($s['id'])) {
                    $busy = \AICliAgents\Services\TmuxService::sessionBusy($agentId, (string)$s['id']);
                }
            } catch (\Throwable $e) {
                // keep the safe answer: busy
            }
            $sessions[$i]['busy'] = (bool)$busy['busy'];
            $sessions[$i]['busy_reason'] = (string)$busy['reason'];
        }
        return $sessions;
    }

    private static function listSessionsForAgent() {
        $agentId = $_GET['agentId'] ?? '';
        if (empty($agentId)) {
            return ['status' => 'error', 'message' => 'agentId required'];
        }
        // Defensive whitelist: agent ids in the registry are [a-z0-9-].
        // Anything else can't match a session so bail cheap.
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/iD', $agentId)) {
            return ['status' => 'error', 'message' => 'invalid agentId'];
        }
        // The Store calls this immediately before it asks the operator anything
        // about their open sessions, so it is the right place to say whether
        // those sessions are relevant at all. For a side-by-side agent they are
        // not: the install cannot touch them, so there is no question to ask and
        // nothing to warn about. docs/specs/UPGRADE_WITHOUT_INTERRUPTION.md
        // AgentHandler is NOT loaded by AICliAgentsManager — the AJAX entry point
        // loads handlers on demand, so this class may genuinely be absent here.
        // Calling it unguarded fataled the whole endpoint and returned an empty
        // body, which the Store read as "no sessions" (smoke [22], caught 2026-09-15).
        //
        // On any doubt the answer is the CAUTIOUS one: no side_by_side, so the
        // dialog warns about sessions and offers to close them exactly as it
        // always did. Safety is never inferred from a failure to answer.
        $waitReason = 'this agent\'s install strategy could not be determined';
        try {
            $handlerFile = __DIR__ . '/AgentHandler.php';
            if (!class_exists('\AICliAgents\Handlers\AgentHandler', false) && is_file($handlerFile)) {
                require_once $handlerFile;
            }
            if (class_exists('\AICliAgents\Handlers\AgentHandler', false)) {
                $waitReason = \AICliAgents\Handlers\AgentHandler::sideBySideInstallBlocker($agentId);
            }
        } catch (\Throwable $e) {
            \AICliAgents\Services\LogService::log(
                "listSessionsForAgent: could not determine the install strategy for $agentId — "
                . "falling back to the cautious flow: " . $e->getMessage(),
                \AICliAgents\Services\LogService::LOG_WARN, "TerminalHandler");
        }
        $unusedNote = null;
        if ($waitReason === null) {
            try {
                $unusedNote = \AICliAgents\Handlers\AgentHandler::unusedVersionNote($agentId);
            } catch (\Throwable $e) {
                $unusedNote = null;
            }
        }
        $sessions = \AICliAgents\Services\TerminalService::listActiveSessionsForAgent($agentId, false);
        // #463: the display name of each workspace, so the restart dialog names it by
        // the name the person gave it. '' when it is not known: the page picks a plain
        // fallback and never prints an id.
        foreach ($sessions as $i => $sess) {
            $name = '';
            try {
                $name = \AICliAgents\Services\ConfigService::workspaceName((string)($sess['id'] ?? ''));
            } catch (\Throwable $e) {
                // keep ''
            }
            $sessions[$i]['name'] = $name;
        }
        // #463: the busy check reads each pane (up to 0.4 s each), so only the
        // callers that ask for it with busy=1 pay for it. The install dialog does not.
        if (($_GET['busy'] ?? '') === '1') {
            $sessions = self::withBusyFlag($agentId, $sessions);
        }
        return [
            'status'       => 'ok',
            'agentId'      => $agentId,
            'sessions'     => $sessions,
            'side_by_side' => ($waitReason === null),
            'wait_reason'  => $waitReason,
            // #827: an unused older version that the update removes to make room.
            'unused_version_note' => $unusedNote,
        ];
    }

    private static function start($id) {
        if (\AICliAgents\Services\StorageBackendMigrationService::isMigrationInProgress()) {
            return [
                'status' => 'error',
                'reason' => 'storage_migration',
                'message' => 'Storage is being migrated. Workspaces open again when it finishes.',
            ];
        }
        // #910: the host shuts down. Refuse at once, before any mount job is queued, for every
        // storage class. The page stops its retry on this reason.
        if (\AICliAgents\Services\StorageMountService::refuseWhileHostShutsDown('a start request from the page')) {
            return [
                'status' => 'error',
                'reason' => 'host_shutdown',
                'message' => \AICliAgents\Services\StorageMountService::HOST_SHUTDOWN_MESSAGE,
                'emergency_possible' => false,
            ];
        }
        // #687: never start a session for a workspace that is being deleted or was
        // deleted. An open browser tab re-sends `start` when the delete closes its
        // session; without this the session came back as an untracked one. The page
        // drops the tab on this reason.
        if (\AICliAgents\Services\ConfigService::isWorkspaceRemoved((string)$id)) {
            return [
                'status' => 'error',
                'reason' => 'workspace_removed',
                'message' => 'This workspace was deleted. It cannot start again.',
                'id' => (string)$id,
            ];
        }
        $config = getAICliConfig();
        $persistPath = \AICliAgents\Services\StoragePathResolver::agentPersistPathFromConfig($config);
        // Never default the agent: starting the WRONG agent in a user's
        // workspace is worse than refusing to start at all.
        $agentId = \AICliAgents\Services\ConfigService::resolveAgentId($_GET['agentId'] ?? null, (string)$id, (string)($_GET['path'] ?? ''));
        if ($agentId === '') return \AICliAgents\Services\ConfigService::agentIdUnresolvedError('start', (string)$id, (string)($_GET['path'] ?? ''));
        $workspacePath = $_GET['path'] ?? null;

        // Forgejo #558: an open tab re-sends `start` when the stop hook closes its
        // workspace. While the array stops, refuse a workspace on the array or a
        // pool. The page shows the "workspace unavailable" card and retries every
        // 10 s, so the workspace comes back after the array starts.
        if (!empty($workspacePath) && \AICliAgents\Services\StorageMountService::startBlockedByArrayStop((string)$workspacePath)) {
            return [
                'status' => 'error',
                'reason' => 'workspace_unavailable',
                'message' => \AICliAgents\Services\StorageMountService::ARRAY_STOPPING_MESSAGE,
                'path' => $workspacePath,
                'classification' => \AICliAgents\Services\StorageMountService::classifyPath((string)$workspacePath),
                'emergency_possible' => false,
            ];
        }

        // R2 (UPGRADE_RELAUNCH_ZOMBIE_SKIP): never spawn a session while this
        // agent's binary is being swapped — a `start` that races the upgrade
        // creates a tmux session whose agent dies instantly (a ZOMBIE), which the
        // post-upgrade relaunch then wrongly skips → "Terminal session not found".
        // The manifest-driven relaunch resumes this session automatically when the
        // upgrade finishes, so refuse here without creating anything.
        require_once __DIR__ . '/AgentHandler.php';
        if (\AICliAgents\Handlers\AgentHandler::isInstallInProgress($agentId)) {
            return [
                'status'  => 'upgrade_in_progress',
                'message' => 'Upgrade in progress — this session will resume automatically when the upgrade finishes.',
            ];
        }

        // HOME_CONSOLIDATE_INPROGRESS_GUARD R2: never spawn a session while this
        // user's home is being consolidated — a `start` that races the consolidate
        // re-pins the home overlay (live merged mount) and the consolidate defers
        // (mount_busy) forever. StorageHandler::consolidate(home) set the per-user
        // marker before closing the sessions; refuse here without creating anything.
        // The consolidate-success hook (relaunchHomeSet) resumes the closed set
        // automatically, so the closed sessions come back on their own.
        require_once __DIR__ . '/../services/ConsolidateState.php';
        $consolidateUser = (string)($config['user'] ?? 'root');
        if ($consolidateUser === '' || $consolidateUser === '0') $consolidateUser = 'root';
        if (\AICliAgents\Services\ConsolidateState::isHomeConsolidating($consolidateUser)) {
            return [
                'status'  => 'consolidate_in_progress',
                'message' => 'Home consolidation in progress — this session will resume automatically when it finishes.',
            ];
        }

        // Check 1: Is the home storage path available?
        $homePath = \AICliAgents\Services\StoragePathResolver::homePersistPathFromConfig($config);   // R-046: the one resolver
        if (!\AICliAgents\Services\StorageMountService::isPathAvailable($homePath)) {
            $classification = \AICliAgents\Services\StorageMountService::classifyPath($homePath);

            // Can we mount the agent? Check if agent sqsh files exist (on Flash or another available path)
            $agentAvailable = \AICliAgents\Services\StorageMountService::isPathAvailable($persistPath)
                && count(glob("$persistPath/agent_{$agentId}_*.sqsh")) > 0;

            // 2026-09-10: split a GLOBAL storage failure from an AGENT-specific one.
            // The browser turns 'storage_unavailable'/'home_unavailable' into a GLOBAL
            // storageError, which rewrites the drawer's "+ New Workspace" button into
            // "Emergency Session" for the whole UI. That is right when the shared home or
            // the persistence root is down — every workspace is affected. It is WRONG when
            // only THIS agent's .sqsh is missing (the glob above is per-agent): clicking one
            // broken workspace then put the entire console into emergency mode.
            //
            // So: persistence root unreachable => 'storage_unavailable' (global, unchanged).
            // Root fine but no layer for THIS agent => 'agent_unavailable', which the UI
            // reports against the workspace that failed and nothing else.
            $persistAvailable = \AICliAgents\Services\StorageMountService::isPathAvailable($persistPath);
            $reason = $agentAvailable
                ? 'home_unavailable'
                : ($persistAvailable ? 'agent_unavailable' : 'storage_unavailable');
            return [
                'status' => 'error',
                'reason' => $reason,
                'message' => $agentAvailable
                    ? 'Home storage is not available. An emergency session with a temporary home is available.'
                    : ($persistAvailable
                        ? 'This agent has no installed storage layer yet. Install or repair ' . $agentId . ' from the Agents tab; other workspaces are unaffected.'
                        : 'Storage path is not currently accessible. Start the array or check your storage configuration.'),
                'path' => $homePath,
                'classification' => $classification,
                'emergency_possible' => $agentAvailable,
            ];
        }

        // Check 2: Is the workspace path (where the agent will work) available?
        if (!empty($workspacePath) && !\AICliAgents\Services\StorageMountService::isPathAvailable($workspacePath)) {
            $wsClassification = \AICliAgents\Services\StorageMountService::classifyPath($workspacePath);
            // #757: the storage is mounted but the folder is gone. Waiting does
            // not help, so say so and do not offer the RAM install.
            if (\AICliAgents\Services\StorageMountService::isBackingStorageMounted((string)$workspacePath, $wsClassification)) {
                return [
                    'status' => 'error',
                    'reason' => 'workspace_missing',
                    'message' => "This workspace's folder no longer exists: " . $workspacePath
                        . '. Close the workspace, or choose another folder for it.',
                    'path' => $workspacePath,
                    'classification' => $wsClassification,
                    'emergency_possible' => false,
                ];
            }
            return [
                'status' => 'error',
                'reason' => 'workspace_unavailable',
                'message' => "Workspace path is not currently accessible. The "
                    . ($wsClassification === 'array' ? 'array' : (strpos($wsClassification, 'pool:') === 0 ? substr($wsClassification, 5) . ' pool' : 'storage'))
                    . ' may need to be started.',
                'path' => $workspacePath,
                'classification' => $wsClassification,
                'emergency_possible' => false,
            ];
        }

        // Check 3 — S-08 (#1353, STORAGE_ASYNC_JOBS.md): never block this AJAX
        // response 10-30 s on a cold home mount. If the home overlay is not
        // mounted yet, FileStorage::ensureReadyAsync enqueues a supervisor
        // `mount` job and we return {status:'mounting', job_id} immediately;
        // the React cold-start flow polls `storage_job_status` and re-fires
        // `start` when the job lands (the sync ensureReady inside startTerminal
        // then takes its fast path). ONLY this browser-facing action goes
        // async: emergency_start, restart, the event scripts and
        // AutoLaunchService (headless at boot — nobody to poll a job) keep the
        // synchronous TerminalService path.
        if (!\AICliAgents\Services\StorageMountService::isEmergencyMode()
            && !\AICliAgents\Services\StorageMountService::isMigrationInProgress()) {
            $homeUser = (string)($config['user'] ?? 'root');
            if ($homeUser === '' || $homeUser === '0') $homeUser = 'root';
            if (function_exists('posix_getpwnam') && !is_array(@posix_getpwnam($homeUser))) $homeUser = 'root'; // Bug #1053 fallback
            $ready = \AICliAgents\Services\FileStorage::ensureReadyAsync("home/$homeUser", ['reason' => 'workspace_open']);
            if (($ready['state'] ?? '') === 'mounting') {
                // T-09: surface the wait in the start activity so the cold-start
                // overlay + tray show "mount queued" instead of a silent spinner.
                \AICliAgents\Services\ActivityService::update("start_$id", [
                    'type'  => 'start', 'label' => 'Starting ' . self::agentDisplayName((string)$agentId),
                    'step'  => 'mounting_home_queued', 'progress' => 10,
                    'meta'  => ['sessionId' => $id, 'agentId' => $agentId,
                                'path' => (string)($workspacePath ?? ''),
                                'jobId' => (string)($ready['job_id'] ?? '')],
                ]);
                return [
                    'status' => 'mounting',
                    'job_id' => (string)($ready['job_id'] ?? ''),
                    'wait_s' => (int)($ready['wait_s'] ?? 300),
                    'sock'   => "/webterminal/aicliterm-$id/",
                ];
            }
            if (($ready['state'] ?? '') === 'unavailable') {
                // The async path degraded to sync (queue unavailable) AND the
                // sync mount failed — same surface as the Check-1 classification.
                return [
                    'status'  => 'error',
                    'reason'  => 'home_unavailable',
                    'message' => 'Home storage could not be mounted (exit ' . (string)($ready['exit'] ?? '?') . '). Check the Storage tab.',
                    'path'    => $homePath,
                    'classification' => \AICliAgents\Services\StorageMountService::classifyPath($homePath),
                    'emergency_possible' => \AICliAgents\Services\StorageMountService::isPathAvailable($persistPath)
                        && count(glob("$persistPath/agent_{$agentId}_*.sqsh")) > 0,
                ];
            }
            // state 'ready' (or a deferred-but-usable sync fallback) → proceed.
        }

        // Resume flag: if the user clicked "Resume" in the new-session overlay,
        // pass the sentinel 'auto' so TerminalService looks up the ID saved at
        // the previous clean close. Explicit chatId (if any) still wins.
        $chatId = $_GET['chatId'] ?? null;
        if (empty($chatId) && !empty($_GET['resume'])) {
            $chatId = 'auto';
        }
        // #71: serialize the final status check + session registration with
        // queued-upgrade admission. The earlier check gives fast feedback;
        // this one closes the in-flight request race.
        $admission = \AICliAgents\Services\AgentUpgradeAdmissionService::acquire($agentId);
        if ($admission === null) {
            return [
                'status' => 'upgrade_in_progress',
                'message' => 'Upgrade transition in progress — retrying is safe.',
            ];
        }
        try {
            // @phpstan-ignore-next-line The supervisor can raise this external status barrier after the earlier check.
            if (\AICliAgents\Handlers\AgentHandler::isInstallInProgress($agentId)) {
                return [
                    'status' => 'upgrade_in_progress',
                    'message' => 'Upgrade in progress — this session will resume automatically when the upgrade finishes.',
                ];
            }
            $started = startAICliTerminal($id, $workspacePath, $chatId, $agentId, \AICliAgents\Services\SessionLaunchLock::WEB_START_WAIT_SECONDS);
            $refused = self::startRefusal($started, (string)$id);
            if ($refused !== null) return $refused;
            return self::startedResponse($id);
        } finally {
            \AICliAgents\Services\AgentUpgradeAdmissionService::release($admission);
        }
    }

    /**
     * Emergency session: agent storage available but home is not.
     * Creates a temporary RAM home and starts a single session.
     */
    private static function emergencyStart($id) {
        $config = getAICliConfig();
        // Never default the agent: starting the WRONG agent in a user's
        // workspace is worse than refusing to start at all.
        $agentId = \AICliAgents\Services\ConfigService::resolveAgentId($_GET['agentId'] ?? null, (string)$id, (string)($_GET['path'] ?? ''));
        if ($agentId === '') return \AICliAgents\Services\ConfigService::agentIdUnresolvedError('emergency_start', (string)$id, (string)($_GET['path'] ?? ''));
        $path = $_GET['path'] ?? '/mnt';

        // #614: the UI offers an Emergency Session only while home storage is down. Check it
        // here too, on the server: a direct call must not swap the home of a healthy box.
        $persistPathEm = \AICliAgents\Services\StoragePathResolver::agentPersistPathFromConfig($config);
        $homePathEm = \AICliAgents\Services\StoragePathResolver::homePersistPathFromConfig($config);   // R-046: the one resolver
        $homeUp = is_callable(self::$homeAvailableProbe)
            ? (bool)call_user_func(self::$homeAvailableProbe, (string)$homePathEm)
            : \AICliAgents\Services\StorageMountService::isPathAvailable((string)$homePathEm);
        if ($homeUp) {
            aicli_log("emergency_start refused for session=$id: home storage is available", AICLI_LOG_WARN, "TerminalHandler");
            return ['status' => 'error', 'reason' => 'emergency_not_needed',
                'message' => 'Home storage is available, so an emergency session is not needed. Start the workspace normally.'];
        }

        // Clean up any previous emergency state (allow starting fresh)
        if (\AICliAgents\Services\StorageMountService::isEmergencyMode()) {
            aicli_log("Cleaning previous emergency state before new session.", AICLI_LOG_INFO, "TerminalHandler");
            @unlink(\AICliAgents\Services\StorageMountService::emergencyFlag());
        }
        // Also clean up a stale ttyd/tmux of THIS workspace from a failed earlier attempt.
        // #614: this used to pkill every aicli-run and ttyd on the box and delete every
        // socket, which killed all other workspaces. Only this id is stopped now.
        $busy = self::takeLaunchLock((string)$id, 'emergencyStart');
        if ($busy !== null) return $busy;
        try {
            \AICliAgents\Services\ProcessManager::stopTerminal($id, true, 'stop');
        } finally {
            \AICliAgents\Services\SessionLaunchLock::release((string)$id);
        }
        usleep(500000); // 0.5s for process cleanup

        $user = $config['user'] ?? 'root';
        if ($user === '0' || empty($user)) $user = 'root';

        // Create temporary home directory structure
        $emergencyHome = \AICliAgents\Services\StorageMountService::emergencyHome();
        @mkdir("$emergencyHome/.aicli/envs", 0755, true);

        aicli_log("EMERGENCY MODE: Starting session with temp home at $emergencyHome", AICLI_LOG_WARN, "TerminalHandler");

        // Set up work dir → emergency home symlink
        // Must remove whatever is at work/root/home (stale mount point, old dir, or previous symlink)
        $workDir = \AICliAgents\Services\UtilityService::getWorkDir($user);
        @mkdir($workDir, 0755, true);
        $homeLink = "$workDir/home";

        if (is_link($homeLink)) {
            @unlink($homeLink);
        } elseif (is_dir($homeLink) && !\AICliAgents\Services\StorageMountService::isMounted($homeLink)) {
            // Stale directory from previous overlay (may contain ZRAM leftovers) — safe to remove
            exec("rm -rf " . escapeshellarg($homeLink));
        }

        if (!file_exists($homeLink)) {
            symlink($emergencyHome, $homeLink);
            aicli_log("Emergency home symlink: $homeLink → $emergencyHome", AICLI_LOG_INFO, "TerminalHandler");
        } else {
            aicli_log("WARNING: Could not create emergency home symlink — $homeLink still exists", AICLI_LOG_WARN, "TerminalHandler");
        }

        // #906: the mount below must not run while the agent is uninstalled. Hold the agent's
        // shared lock from here to the end of the start (startTerminal takes it again; shared
        // locks do not block each other).
        $agentLock = false;
        if ($agentId !== 'terminal' && $agentId !== '') {
            $agentLock = \AICliAgents\Services\AgentUninstallLock::acquireForStart((string)$agentId);
            if ($agentLock === false) {
                return self::startRefusal(['ok' => false, 'reason' => 'agent_uninstalling'], (string)$id);
            }
        }
        try {
        // Ensure agent is available — try sqsh mount first, fall back to checking if binary exists in RAM
        if ($agentId !== 'terminal') {
            $registry = \AICliAgents\Services\AgentRegistry::getRegistry();
            $agentBinary = $registry[$agentId]['binary'] ?? '';
            $binaryExists = !empty($agentBinary) && file_exists($agentBinary);

            if (!$binaryExists) {
                // Binary not in RAM — try normal sqsh mount
                $agentMounted = \AICliAgents\Services\FileStorage::ensureReady("agent/$agentId")->ok;   // Epic #1310: facade intent
                if (!$agentMounted) {
                    return ['status' => 'error', 'message' => "Agent $agentId is not available. Install it to RAM first via the emergency installer."];
                }
            } else {
                aicli_log("Emergency: Agent $agentId binary found in RAM, skipping sqsh mount.", AICLI_LOG_INFO, "TerminalHandler");
            }
        }

        // Set emergency flag BEFORE starting terminal — ensureHomeMounted checks this flag
        // to recognize the symlink as a valid home mount
        touch(\AICliAgents\Services\StorageMountService::emergencyFlag());

        // Start terminal (home is now symlinked to emergency dir, ensureHomeMounted sees the flag)
        $started = startAICliTerminal($id, $path, null, $agentId, \AICliAgents\Services\SessionLaunchLock::WEB_START_WAIT_SECONDS);
        $refused = self::startRefusal($started, (string)$id);
        if ($refused !== null) return $refused;

        return self::startedResponse($id, ['emergency' => true]);
        } finally {
            \AICliAgents\Services\AgentUninstallLock::release($agentLock);
        }
    }

    private static function stop($id) {
        // R5 (CAPTURE_RESUME_ALL_CLOSE_PATHS): the `stop` action is a purely
        // destructive hard-kill (no quiesce/scrape — that's gracefulClose's job).
        // Harden it with a fast disk-fallback resume capture BEFORE the kill so
        // resume isn't lost if this path is ever invoked on a live session.
        // Prefer the workspace/agent from $_GET (the close button sends them);
        // fall back to the session's /var/run metadata otherwise.
        $path    = $_GET['path'] ?? '';
        $agentId = $_GET['agentId'] ?? '';
        if ($path !== '' && $agentId !== '') {
            $diskId = self::discoverLatestSessionId($agentId, $path);
            // Disk-only (newest on disk): guarded on a shared folder (RESUME_IDENTITY_PER_WORKSPACE.md V3).
            if ($diskId !== null && $diskId !== '' && \AICliAgents\Services\ConfigService::saveDiskFallbackResumeId($path, $agentId, $diskId, (string)$id)) {
            }
        } else {
            \AICliAgents\Services\ProcessManager::captureFallbackBeforeKill((string)$id);
        }
        stopAICliTerminal($id, isset($_GET['hard']));
        // Fix 2026-09-12: the `stop` action is an operator/tool-driven close
        // (the drawer's hard-stop path, the CLI). Auto-launch must not bring it
        // straight back on the next page load. See
        // docs/specs/2026-04-27-auto-launch-workspaces-design.md.
        \AICliAgents\Services\AutoLaunchSuppression::suppress((string)$id);
        // Closing a session frees the home overlay — wake the supervisor so any
        // deferred consolidate/bake for that home resumes immediately (#1381).
        \AICliAgents\Services\SupervisorService::wake();
        return ['status' => 'ok'];
    }

    /**
     * Graceful close: sends Ctrl-C twice to let the agent flush state, scrapes
     * the exit screen for a resume ID, persists it for the (path, agent) pair,
     * then allows the shell's outer while-loop to exit via a sentinel flag.
     * Falls back to hard stop if the session does not exit within 3s.
     *
     * All shell arguments are either fixed constants or escapeshellarg'd. The
     * session id is preg_replace'd to alnum+_- only, so no unsafe data can
     * reach any command line.
     */
    /**
     * The session id inside a canonical tmux name `aicli-agent-<agentId>-<sessionId>`,
     * or '' when the name is not that shape. The resume record is per WORKSPACE
     * (RESUME_IDENTITY_PER_WORKSPACE.md), and captureResumeForClose() is handed only
     * the session NAME.
     */
    public static function sessionIdFromSessionName(string $sessName, string $agentId): string {
        $prefix = "aicli-agent-$agentId-";
        if ($agentId === '' || strncmp($sessName, $prefix, strlen($prefix)) !== 0) return '';
        $sid = substr($sessName, strlen($prefix));
        return preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $sid) ? $sid : '';
    }

    /**
     * Derive the agentId from a tmux session name of the form
     * `aicli-agent-<agentId>-<safeId>`. agentId may itself contain dashes
     * (claude-code, antigravity-cli, codex-cli), so we strip the fixed
     * `aicli-agent-` prefix and the trailing `-<safeId>` suffix. Returns '' if
     * the name doesn't match the expected shape.
     */
    public static function agentIdFromSessionName(string $sessName, string $safeId): string {
        $prefix = 'aicli-agent-';
        if (strncmp($sessName, $prefix, strlen($prefix)) !== 0) {
            return '';
        }
        $s = substr($sessName, strlen($prefix));
        if ($safeId !== '') {
            $suffix = '-' . $safeId;
            $slen = strlen($suffix);
            if (strlen($s) > $slen && substr($s, -$slen) === $suffix) {
                $s = substr($s, 0, -$slen);
            }
        }
        return $s;
    }

    /**
     * Forgejo #352: the graceful_close action, serialised with every start and
     * restart of the same workspace (SessionLaunchLock). The close removes the
     * session's runfiles and its tmux socket folder; a start that ran at the same
     * time lost its socket, and its agent ran on with no way to reach it.
     */
    private static function gracefulCloseSerialized($id): array {
        $busy = self::takeLaunchLock((string)$id, 'gracefulClose');
        if ($busy !== null) return $busy;
        try {
            return self::gracefulClose($id);
        } finally {
            \AICliAgents\Services\SessionLaunchLock::release((string)$id);
        }
    }

    /**
     * Forgejo #352 (2026-09-29): take the workspace's SessionLaunchLock for a
     * close or a restart. Returns null when this request holds the lock (the
     * caller must release it), or the 'busy' response when a start, close or
     * restart of the same workspace did not finish in time. Nothing is closed
     * or started on the busy path. See docs/specs/WORKSPACE_RELOAD_ONTO_CURRENT.md.
     *
     * @return array<string,mixed>|null
     */
    private static function takeLaunchLock(string $id, string $action): ?array {
        if (\AICliAgents\Services\SessionLaunchLock::acquire($id, \AICliAgents\Services\SessionLaunchLock::CLOSE_WAIT_SECONDS)) {
            return null;
        }
        aicli_log("$action: refused for session=$id: another start, close or restart of this workspace did not finish in time; nothing was closed or started",
            AICLI_LOG_WARN, "TerminalHandler");
        return ['status' => 'busy', 'reason' => 'launch_in_progress',
            'message' => 'This workspace is still starting, closing or restarting. Try again in a moment.'];
    }

    /**
     * #606: close a workspace for a consolidate, backup or restore. Same close as
     * graceful_close, but it does NOT mark the workspace "closed on purpose": the
     * plugin relaunches it afterwards, and crash recovery must still see it.
     */
    public static function closeForMaintenance($id): array {
        self::$suppressOnClose = false;
        try {
            return is_callable(self::$closeRunner)
                ? call_user_func(self::$closeRunner, $id)
                : self::gracefulCloseSerialized($id);
        } finally {
            self::$suppressOnClose = true;
        }
    }

    /**
     * Test seams (F17, #606). Both null in production.
     *   $closeRunner       fn($id): array — replaces the real close inside closeForMaintenance()
     *   $suppressionWriter fn(string $safeId): void — replaces AutoLaunchSuppression::suppress()
     */
    public static $closeRunner = null;
    public static $suppressionWriter = null;

    /**
     * Mark the workspace "closed on purpose" so auto-launch does not bring it straight back
     * (Fix 2026-09-12). A maintenance close (closeForMaintenance) writes no marker (#606):
     * the plugin relaunches that workspace itself, and crash recovery must still see it.
     * gracefulClose() calls this at the end of its teardown.
     */
    public static function recordCloseOnPurpose(string $safeId): void {
        if (!self::$suppressOnClose) return;
        if (is_callable(self::$suppressionWriter)) { call_user_func(self::$suppressionWriter, $safeId); return; }
        \AICliAgents\Services\AutoLaunchSuppression::suppress($safeId);
    }

    /**
     * #661: where the close sentinel of a session lives. aicli-shell.sh reads the default
     * path; AICLI_TMP_BASE redirects it for tests only (the same seam as TerminalService::markClosing).
     */
    public static function closeFlagPath(string $safeId): string {
        $base = rtrim((string)(getenv('AICLI_TMP_BASE') ?: \AICliAgents\Services\SecretPaths::stateRoot()), '/');
        return $base . '/close-' . $safeId . '.flag';
    }

    /**
     * #814: the "closing" sentinel. captureResumeForClose() sets it BEFORE the exit keys,
     * and aicli-shell.sh holds its relaunch loop while it exists (the close sentinel
     * comes about 3 seconds later, after the resume scrape). Same folder and seam as closeFlagPath().
     */
    public static function closingFlagPath(string $safeId): string {
        $base = rtrim((string)(getenv('AICLI_TMP_BASE') ?: \AICliAgents\Services\SecretPaths::stateRoot()), '/');
        return $base . '/closing-' . $safeId . '.flag';
    }

    /** Test seam: fn(string $homePath): bool. Null = StorageMountService::isPathAvailable. */
    public static $homeAvailableProbe = null;

    /** #606: false only while closeForMaintenance() runs. gracefulClose() reads it. */
    private static bool $suppressOnClose = true;

    private static function gracefulClose($id) {
        $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
        $path = $_GET['path'] ?? '';
        $agentId = $_GET['agentId'] ?? '';

        // Every log line in this flow gets the same (session, agent, workspace)
        // prefix so the close sequence is grep-able from /var/log without
        // cross-referencing unrelated timestamps.
        $ctx = sprintf("session=%s agent=%s workspace=%s",
            $safeId,
            $agentId !== '' ? $agentId : 'unknown',
            $path !== '' ? $path : 'unknown'
        );
        // #218: record that this close is deliberate BEFORE anything slow runs,
        // so the drawer never reports the workspace as an untracked orphan
        // during the ~9 seconds the close actually takes.
        \AICliAgents\Services\TerminalService::markClosing((string)$id);
        aicli_log("gracefulClose: START $ctx", AICLI_LOG_INFO, "TerminalHandler");
        // #661: a sentinel left by an earlier close that died half way must not leak into this one.
        $closeFlag = self::closeFlagPath($safeId);
        $closingFlag = self::closingFlagPath($safeId);
        @unlink($closeFlag);
        @unlink($closingFlag);
        try {
            // Non-root audit: shared multi-user lookup helper. Stays in lock-step
            // with every other tmux call-site (agentSignalReload, AgentHandler,
            // ProcessManager, InstallerService).
            [$sessName, $tmuxSock, $tmuxBin] = \AICliAgents\Services\ProcessManager::findTmuxSessionForId($safeId);

            // Bulk/supervisor close path (forceCloseHome → handle('graceful_close',$id),
            // shutdown-capture) carries NO $_GET, so workspace+agent arrive empty and
            // captureResumeForClose can SCRAPE the resume id but cannot SAVE it ("could
            // not save (missing workspace or agent)") — relaunch then loses precise
            // resume (e.g. a user-renamed chat). Resolve from the live session itself:
            // workspace from the per-session .workdir metadata, agentId from the tmux
            // session name (aicli-agent-<agentId>-<safeId>). Works even when the
            // registry metadata is 'unknown' (reconnect sessions).
            if ($path === '') {
                $wdf = \AICliAgents\Services\UtilityService::getWorkDirFilePath($safeId);
                if (is_file($wdf)) {
                    $path = trim((string)@file_get_contents($wdf));
                }
            }
            if ($agentId === '' && $sessName !== '') {
                $agentId = self::agentIdFromSessionName($sessName, $safeId);
            }
            // Refresh the grep-able context with whatever we resolved.
            $ctx = sprintf("session=%s agent=%s workspace=%s",
                $safeId,
                $agentId !== '' ? $agentId : 'unknown',
                $path !== '' ? $path : 'unknown'
            );

            $capturedId = null;

            if (!empty($sessName)) {
                $escSess = escapeshellarg($sessName);
                aicli_log("gracefulClose: tmux session resolved as '$sessName' (sock=$tmuxSock) | $ctx", AICLI_LOG_DEBUG, "TerminalHandler");

                @mkdir(\AICliAgents\Services\SecretPaths::stateRoot(), 0755, true);

                // Quiesce the agent and capture + persist its resume id. Extracted to
                // captureResumeForClose() so the pre-upgrade bulk close
                // (AgentHandler::_closeSessionsForUpgrade) runs the IDENTICAL pipeline:
                // the universal exit keys (Ctrl-C x2 + Ctrl-D x2 for agy), the 3-retry
                // exit-screen scrape (covers EVERY agent that prints a resume hint —
                // gemini, copilot, kilo, codex, …), then the disk-based fallback
                // (opencode/agy/claude). Single source of truth so the two close paths
                // can never drift on resume capture again (the upgrade path used to do
                // disk-only, silently dropping resume for all scrape-only agents).
                $capturedId = self::captureResumeForClose($sessName, $tmuxSock, $tmuxBin, $agentId, $path, $ctx);

                // NOW set the sentinel and unblock the shell's "Press ENTER" read
                // so the relaunch loop exits cleanly instead of timing out after
                // 10s. Order is critical: sentinel before Enter means the next
                // loop iteration breaks; the Enter just wakes the blocking read.
                @touch($closeFlag);
                @shell_exec("$tmuxBin send-keys -t $escSess Enter 2>/dev/null");

                // Poll for the tmux session to actually exit. Budget is per-session
                // configurable via `graceful_close_timeout` (seconds, default 3 —
                // the historical hardcoded value). Clamped to [1, 60] so a typo'd
                // config value can't hang the close path. See ACTIVITY_TRAY.md.
                $budget = (int)(getAICliConfig()['graceful_close_timeout'] ?? 3);
                $budget = max(1, min(60, $budget ?: 3));
                $exited = false;
                for ($i = 0; $i < $budget * 10; $i++) {
                    $still = trim((string) shell_exec("$tmuxBin has-session -t $escSess 2>/dev/null && echo y || echo n"));
                    if ($still === 'n') { $exited = true; break; }
                    usleep(100000);
                }
                if ($exited) {
                    aicli_log("gracefulClose: tmux session '$sessName' exited cleanly | $ctx", AICLI_LOG_INFO, "TerminalHandler");
                } else {
                    aicli_log("gracefulClose: tmux session '$sessName' did not exit within {$budget}s — falling back to hard stop | $ctx", AICLI_LOG_WARN, "TerminalHandler");
                }
            } else {
                aicli_log("gracefulClose: no tmux session found for $ctx — proceeding to hard stop (session may have already died)", AICLI_LOG_WARN, "TerminalHandler");
            }

            // Bug #1071 follow-up: always try the disk-based fallback when no
            // resume id was captured from the pane (covers the case where the
            // tmux session was already gone by the time gracefulClose ran).
            if (empty($capturedId)) {
                $diskId = self::discoverLatestSessionId($agentId, $path);
                if ($diskId) {
                    $capturedId = $diskId;
                    aicli_log("gracefulClose: no live tmux pane to scrape -- discovered id=$capturedId from agent metadata | $ctx", AICLI_LOG_INFO, "TerminalHandler");
                    if (!empty($path) && !empty($agentId)) {
                        // Disk-only (no pane to scrape): guarded on a shared folder (RESUME_IDENTITY_PER_WORKSPACE.md V3).
                        $resumeSaved = \AICliAgents\Services\ConfigService::saveDiskFallbackResumeId($path, $agentId, $capturedId, (string)$id);
                        if ($resumeSaved) aicli_log("gracefulClose: saved resume_id=$capturedId for (workspace=$path, agent=$agentId) | $ctx", AICLI_LOG_INFO, "TerminalHandler");
                    }
                }
            }

            // Whether the tmux session exited cleanly or not, reap every process
            // that inherited this workspace's exact environment before tearing down
            // ttyd + sockets + pid files. This catches an agent plugin that detached
            // from tmux and would otherwise outlive a user-closed workspace.
            $reapedPids = \AICliAgents\Services\ProcessManager::terminateSessionDescendants($safeId);
            if ($reapedPids !== []) {
                aicli_log("gracefulClose: reaped detached session descendants=" . implode(',', $reapedPids) . " | $ctx", AICLI_LOG_INFO, "TerminalHandler");
            }
            // WORKSPACE_LIFECYCLE_EVENTS.md: this is the graceful-close path, so
            // the `stopped` event this teardown emits carries that reason (not
            // the generic 'stop' default) even when the quiesce above fell back
            // to a hard kill.
            stopAICliTerminal($id, true, 'graceful_close');
            // Fix 2026-09-12: an operator closed this workspace on purpose (the
            // drawer's Close button, a delete, or the CLI's graceful_close action).
            // Auto-launch must not bring it straight back on the next page load.
            // See docs/specs/2026-04-27-auto-launch-workspaces-design.md.
            self::recordCloseOnPurpose($safeId);
        } finally {
            // #661: the sentinel and the close mark must not outlive this close, whether it ended
            // well or threw half way. A flag left behind makes the next start of this workspace
            // exit its relaunch loop at once (aicli-shell.sh reads it).
            @unlink($closeFlag);
            @unlink($closingFlag);
            \AICliAgents\Services\TerminalService::clearClosing((string)$id);
        }

        $resumeStr = $capturedId ? "resume_id=$capturedId" : "resume_id=none";
        aicli_log("gracefulClose: DONE $resumeStr | $ctx", AICLI_LOG_INFO, "TerminalHandler");

        // Quiescent-lifecycle (2026-05-31): workspace close NO LONGER forces a
        // bake (the old "wants-bake" flag is gone). The supervisor bakes home on
        // its own cadence (bake_schedule_minutes + dirty-pressure), and a full
        // server shutdown bakes home unconditionally (stop-plugin.sh Step 6), so
        // persistence is covered without a per-close Flash write. Reclaim happens
        // when the home goes idle (this close may BE that idle moment). We still
        // captured + saved the resume id above so the next open resumes the chat.
        \AICliAgents\Services\LifecycleLogService::log(
            \AICliAgents\Services\LifecycleLogService::LEVEL_INFO,
            'gracefulClose',
            'workspace_closed_no_forced_bake',
            ['session' => $safeId, 'resume_id' => $capturedId ?: '']
        );

        // Closing the workspace frees the home overlay — wake the supervisor NOW
        // so a deferred (mount_busy) consolidate/bake for this home resumes
        // immediately instead of up to one tick later (#1381 felt like nothing
        // fired on close).
        \AICliAgents\Services\SupervisorService::wake();

        return ['status' => 'ok', 'resume_id' => $capturedId, 'baking' => false];
    }

    /**
     * Send Ctrl-C to the running agent in a tmux session WITHOUT tearing down
     * the tmux session itself. The aicli-shell.sh while-loop catches the
     * agent exit, refreshes effective args from disk (WP #273), and relaunches
     * with the new args. Used by the "Apply now" button in the workspace args
     * confirmation modal (WP #274).
     */
    /**
     * #463 R4: the refusal for agentSignalReload, or null when the reload may go on.
     * Two refusals, both with no keys sent:
     *  - an install of the agent of this session runs (an install marker exists):
     *    status 'busy', reason 'install-running'. Force does not override this.
     *  - the session is busy (TmuxService::sessionBusy): status 'busy', reason is the
     *    busy reason. Only force=1 overrides this: the person pressed "Apply now"
     *    for this one workspace.
     * The agent id comes from the tmux session name "aicli-agent-<agent>-<session>".
     *
     * @return array{status:string,reason:string,message:string}|null
     */
    public static function reloadGuard(string $safeId, string $sessName, bool $force): ?array {
        $agentId = '';
        $prefix = 'aicli-agent-';
        $suffix = '-' . $safeId;
        if (strncmp($sessName, $prefix, strlen($prefix)) === 0
            && strlen($sessName) > strlen($prefix) + strlen($suffix)
            && substr($sessName, -strlen($suffix)) === $suffix) {
            $agentId = substr($sessName, strlen($prefix), strlen($sessName) - strlen($prefix) - strlen($suffix));
        }
        if ($agentId !== '') {
            $handlerFile = __DIR__ . '/AgentHandler.php';
            if (!class_exists('\\AICliAgents\\Handlers\\AgentHandler', false) && is_file($handlerFile)) {
                require_once $handlerFile;
            }
            if (class_exists('\\AICliAgents\\Handlers\\AgentHandler', false)
                && \AICliAgents\Handlers\AgentHandler::isInstallInProgress($agentId)) {
                return ['status' => 'busy', 'reason' => 'install-running',
                    'message' => 'An install of this agent is running. Try again when it ends.'];
            }
        }
        if ($force) return null;
        // An unknown agent id cannot be checked: count the session as busy.
        $busy = ($agentId === '')
            ? ['busy' => true, 'reason' => 'agent-unknown']
            : \AICliAgents\Services\TmuxService::sessionBusy($agentId, $safeId);
        if ($busy['busy']) {
            return ['status' => 'busy', 'reason' => $busy['reason'],
                'message' => 'The session is busy, so it was not restarted. Restart it yourself when it is idle.'];
        }
        return null;
    }

    private static function agentSignalReload($id) {
        $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
        // Same locate-by-suffix pattern as gracefulClose.
        $runShell = 'shell_exec';
        // Non-root audit: shared multi-user lookup helper.
        [$sessName, $tmuxSock, $tmuxBin] = \AICliAgents\Services\ProcessManager::findTmuxSessionForId($safeId);
        if (empty($sessName)) {
            aicli_log("agentSignalReload: no tmux session matching $id", AICLI_LOG_WARN, "TerminalHandler");
            return ['status' => 'error', 'message' => 'No active tmux session for ' . $safeId];
        }

        // #463 R4/R5: one log line with the caller, then the guards. The caller is
        // the action name, the page path of the Referer (never its query string,
        // which holds the CSRF token) and the session id.
        $force = (($_GET['force'] ?? '') === '1');
        $page = (string)(parse_url((string)($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_PATH) ?? '');
        aicli_log("agentSignalReload: action=agent_signal_reload page=" . ($page !== '' ? $page : '-') . " session=$safeId force=" . ($force ? '1' : '0'), AICLI_LOG_INFO, "TerminalHandler");
        $guard = self::reloadGuard($safeId, $sessName, $force);
        if ($guard !== null) {
            aicli_log("agentSignalReload: refused for $safeId: " . $guard['reason'], AICLI_LOG_INFO, "TerminalHandler");
            return $guard;
        }

        // WP #275: drop an auto-reload sentinel BEFORE the Ctrl-C so aicli-shell.sh
        // skips its "Press ENTER to reload" prompt. The user explicitly asked for
        // an immediate restart; they shouldn't have to confirm with a keypress.
        @mkdir(\AICliAgents\Services\SecretPaths::stateRoot(), 0755, true);
        $flagFile = \AICliAgents\Services\SecretPaths::stateRoot() . '/auto-reload-' . $safeId . '.flag';
        @touch($flagFile);

        $escSess = escapeshellarg($sessName);
        @$runShell("$tmuxBin send-keys -t $escSess C-c 2>/dev/null");

        // WP #275a: do NOT blindly fire a second Ctrl-C 200ms later. If the agent
        // exits cleanly on the first Ctrl-C, the wrapper script consumes the flag
        // (rm-then-continue) and starts the next iteration almost immediately.
        // A second Ctrl-C lands somewhere in that next iteration — possibly in
        // bash itself between commands — which kills the wrapper and tears down
        // the tmux session entirely (ttyd then shows "Press ⏎ to Reconnect").
        // Poll for flag-consumed instead. If still present after 500ms, the agent
        // ignored the first Ctrl-C (Claude Code behaviour requires two), so send a
        // second one targeted at the still-running agent.
        $consumed = false;
        for ($i = 0; $i < 25; $i++) {
            usleep(20000); // 20ms × 25 = 500ms ceiling
            // PHP caches stat() per-path. Without clearing, repeated file_exists
            // checks against the same path return the FIRST observation forever
            // — meaning we'd miss the bash wrapper rm-ing the flag and always
            // think the agent ignored Ctrl-C. Pass the path so only that one
            // entry is invalidated (cheaper than a full clearstatcache()).
            clearstatcache(true, $flagFile);
            if (!file_exists($flagFile)) { $consumed = true; break; }
        }
        if (!$consumed) {
            @$runShell("$tmuxBin send-keys -t $escSess C-c 2>/dev/null");
            aicli_log("agentSignalReload: agent ignored first Ctrl-C; sent second to $sessName for $id", AICLI_LOG_INFO, "TerminalHandler");
        } else {
            aicli_log("agentSignalReload: clean exit on first Ctrl-C for $sessName ($id), no second needed", AICLI_LOG_INFO, "TerminalHandler");
        }

        return ['status' => 'ok', 'session' => $sessName, 'second_ctrl_c' => !$consumed];
    }

    private static function getResumeId() {
        $path = $_GET['path'] ?? '';
        $agentId = $_GET['agentId'] ?? '';
        if (empty($path) || empty($agentId)) {
            return ['status' => 'ok', 'chatId' => null];
        }
        // No workspace exists yet in the new-workspace dialog, so no session id: the folder
        // pointer answers, and withholds a conversation an open workspace owns.
        $chatId = \AICliAgents\Services\ConfigService::getResumeId($path, $agentId, isset($_GET['id']) ? (string)$_GET['id'] : null);
        return ['status' => 'ok', 'chatId' => $chatId];
    }

    /**
     * #837: the agent's own documented exit command from the agent registry
     * (`exit_command`, e.g. "/quit"), or '' when the agent has none. Only a plain
     * slash command is accepted, so a bad registry value cannot type anything else.
     */
    public static function closeExitCommand(string $agentId): string {
        $cmd = (string)(\AICliAgents\Services\AgentRegistry::getRegistry()[$agentId]['exit_command'] ?? '');
        return preg_match('~^/[A-Za-z][A-Za-z0-9_-]{0,31}$~D', $cmd) ? $cmd : '';
    }

    /**
     * Quiesce ONE agent inside its tmux session and capture + persist its resume
     * id. Shared verbatim by gracefulClose() (UI "close" button) and
     * AgentHandler::_closeSessionsForUpgrade() (pre-upgrade bulk close) so resume
     * capture is implemented in exactly ONE place and behaves identically for
     * every agent type — closing the bug where the upgrade path did a disk-only
     * capture and silently dropped resume for agents that only print their hint
     * on the exit screen (gemini, copilot, kilo, codex, …).
     *
     * Does NOT tear down the session or touch the close sentinel — that is the
     * caller's job, because the teardown legitimately differs (gracefulClose lets
     * the shell loop break on the sentinel; the upgrade path hard-kills survivors
     * before the binary is replaced).
     *
     * @return string|null the captured resume id (also saved for (path,agent)), or null.
     */
    public static function captureResumeForClose(string $sessName, string $tmuxSock, string $tmuxBin, string $agentId, string $path, string $ctx = ''): ?string {
        if ($sessName === '') return null;
        // Where the saved id came from decides how it may be saved: a screen-scraped id is
        // this workspace's own; a disk id is the folder's newest (RESUME_IDENTITY_PER_WORKSPACE.md V3).
        $fromDisk = false;
        $escSess = escapeshellarg($sessName);

        // #814: tell the agent shell a close has started BEFORE any exit key. The close sentinel
        // is set only after the scrape below; without this mark a Ctrl-D that ends the shell's
        // "Press ENTER" read sends the loop round again and the agent starts a second time.
        $closingId = self::sessionIdFromSessionName($sessName, $agentId);
        if ($closingId !== '') {
            @mkdir(dirname(self::closingFlagPath($closingId)), 0755, true);
            @touch(self::closingFlagPath($closingId));
        }

        // #91: capture the agent's authoritative disk metadata BEFORE sending
        // exit keys. aicli-shell's retry loop can launch a fresh blank session
        // immediately after Ctrl-C; a post-exit "newest session" scan would
        // then save that blank id instead of the conversation being closed.
        $diskFallbackBeforeQuiesce = self::discoverLatestSessionId($agentId, $path);

        // Force the tmux window to 220 cols BEFORE Ctrl-C. After ttyd
        // disconnects, the window can shrink to the last negotiated size
        // (often 80 cols or narrower), which wraps copilot's UUID onto two
        // lines in a way tmux's -J flag cannot always re-join cleanly.
        // Resizing here guarantees the full resume line fits on one row.
        @shell_exec("$tmuxBin resize-window -t $escSess -x 220 -y 50 2>/dev/null");

        // #837: an agent that documents its own exit command (registry `exit_command`, e.g.
        // Factory droid `/quit`) gets it typed first. The generic keys below stay as the fallback.
        $exitCommand = self::closeExitCommand($agentId);
        if ($exitCommand !== '') {
            @shell_exec("$tmuxBin send-keys -t $escSess -l " . escapeshellarg($exitCommand) . " 2>/dev/null");
            usleep(150000);
            @shell_exec("$tmuxBin send-keys -t $escSess Enter 2>/dev/null");
            usleep(300000);
            aicli_log("captureResumeForClose: sent exit command '$exitCommand' (#837) | $ctx", AICLI_LOG_DEBUG, "TerminalHandler");
        }

        // Two Ctrl-Cs covers both single-press (opencode) and double-press
        // (claude/gemini/copilot/kilo) exit conventions. The second key on
        // single-press agents lands on the post-exit shell as a no-op.
        @shell_exec("$tmuxBin send-keys -t $escSess C-c 2>/dev/null");
        usleep(150000);
        @shell_exec("$tmuxBin send-keys -t $escSess C-c 2>/dev/null");
        // Bug #1071: Antigravity CLI ignores Ctrl-C and exits only on Ctrl-D.
        // Sending Ctrl-D x2 covers both the agy single-press (REPL line) and
        // double-press (exit confirmation) conventions. For agents that
        // already exited from the Ctrl-C pair, the Ctrl-Ds land on the
        // post-exit shell as a no-op.
        usleep(150000);
        @shell_exec("$tmuxBin send-keys -t $escSess C-d 2>/dev/null");
        usleep(150000);
        @shell_exec("$tmuxBin send-keys -t $escSess C-d 2>/dev/null");
        aicli_log("captureResumeForClose: sent Ctrl-C x2 + Ctrl-D x2 (Bug #1071) | $ctx", AICLI_LOG_DEBUG, "TerminalHandler");

        // Capture with up to 3 retries - agents that stream their exit
        // screen character-by-character can be caught mid-render on the
        // first attempt. If we get a short-looking id, wait and re-capture.
        //
        // Claude Code also supports custom session names (via /rename),
        // in which case the resume line prints the name instead of a UUID
        // - e.g.  claude --resume "John's coding session"  - so the regex
        // accepts three forms, tried in order per match attempt:
        //   1. Full-length bare token ({20,}) - classic UUIDs, strongest
        //      signal. Always preferred when present.
        //   2. Double-quoted string ("...") - custom names with spaces
        //      or apostrophes. Content captured without the surrounding
        //      quotes; consumer must shell-escape on reuse.
        //   3. Single-quoted string ('...') - rare but legal bash quoting.
        //   4. Permissive bare token ({8,}) - short session ids (opencode,
        //      kilocode ses_xxx) and legacy shapes.
        $pane = '';
        $m = null;
        // Bug #1071: Antigravity CLI's exit hint uses `--conversation <id>`,
        // not --resume. Accept either form in all three regex variants so
        // antigravity-cli benefits from the same pane-scrape pipeline as
        // the other agents. The leading flag list is the same shape:
        // {--resume|--conversation|-s} followed by `= ` or whitespace.
        // Forgejo #448: Codex prints no resume hint; its ids live on disk and the
        // scrape can only catch something that is not an id (it caught the
        // `-s --format` of a git command the agent had run). Disk is the source.
        $scrapePane = !in_array($agentId, self::DISK_RESUME_ID_AGENTS, true);
        for ($attempt = 0; $scrapePane && $attempt < 3; $attempt++) {
            usleep($attempt === 0 ? 1800000 : 1000000);
            $pane = (string) shell_exec("$tmuxBin capture-pane -p -J -S -200 -t $escSess 2>/dev/null");
            $found = self::resumeIdFromPane($pane, false);
            if ($found !== null) {
                $m = [$found, $found];
                aicli_log("captureResumeForClose: captured id on attempt " . ($attempt + 1) . " (" . strlen($pane) . " bytes of pane) | $ctx", AICLI_LOG_DEBUG, "TerminalHandler");
                break;
            }
            aicli_log("captureResumeForClose: attempt " . ($attempt + 1) . " did not yield a full id yet (pane " . strlen($pane) . " bytes) | $ctx", AICLI_LOG_DEBUG, "TerminalHandler");
        }
        // Fall back to the permissive 8+ regex if none of the attempts
        // yielded a full-length id - some agents use short session ids.
        if ($scrapePane && empty($m)) {
            $found = self::resumeIdFromPane($pane, true);
            if ($found !== null) {
                $m = [$found, $found];
                aicli_log("captureResumeForClose: captured short id via fallback regex | $ctx", AICLI_LOG_DEBUG, "TerminalHandler");
            }
        }

        // #1316: a renamed claude-code session's NAME is its resume id (may be short / contain
        // spaces); the scrape is NOT shape-validated — printf %q in the run-script escapes it.
        $capturedId = null;
        if (!empty($m)) {
            $capturedId = $m[1];
        } else {
            // Agent-specific disk-based fallback for CLIs that don't print
            // a resume hint on exit (opencode). Looks up the most recent
            // session id from the agent's own metadata store.
            $capturedId = $diskFallbackBeforeQuiesce
                ?? self::discoverLatestSessionId($agentId, $path);
            $fromDisk = true;
            if ($capturedId) {
                aicli_log("captureResumeForClose: exit screen had no resume hint — discovered id=$capturedId from agent metadata | $ctx", AICLI_LOG_INFO, "TerminalHandler");
            }
        }

        if (!empty($capturedId)) {
            if (!empty($path) && !empty($agentId)) {
                $sidForResume = self::sessionIdFromSessionName($sessName, $agentId);
                $resumeSaved = $fromDisk
                    ? \AICliAgents\Services\ConfigService::saveDiskFallbackResumeId($path, $agentId, $capturedId, $sidForResume)
                    : (bool)\AICliAgents\Services\ConfigService::saveResumeId($path, $agentId, $capturedId, $sidForResume);
                if ($resumeSaved) aicli_log("captureResumeForClose: saved resume_id=$capturedId for (workspace=$path, agent=$agentId) | $ctx", AICLI_LOG_INFO, "TerminalHandler");
            } else {
                aicli_log("captureResumeForClose: captured resume_id=$capturedId but could not save (missing workspace or agent) | $ctx", AICLI_LOG_WARN, "TerminalHandler");
            }
        } else {
            aicli_log("captureResumeForClose: no resume_id found in exit screen or agent metadata | $ctx", AICLI_LOG_INFO, "TerminalHandler");
        }

        return $capturedId !== '' ? $capturedId : null;
    }

    /**
     * Agents whose conversation id is read from their own files on disk, never
     * from the exit screen (Forgejo #448). Codex prints no resume hint, so a
     * screen scrape can only catch a token that is not an id.
     */
    public const DISK_RESUME_ID_AGENTS = ['codex-cli'];

    /**
     * The resume id an agent printed on its exit screen, or null. Three forms,
     * tried in order: a full-length bare token (UUIDs, 20+), a quoted name
     * (Claude's /rename), and — only when $permissive — a short bare token
     * (8+, opencode and kilocode ids). Forgejo #448: a token that starts with
     * `-` is a flag, never an id: the permissive form once returned `--format`
     * from `git show -s --format=…` in an agent's output, and four workspaces
     * could not resume.
     */
    public static function resumeIdFromPane(string $pane, bool $permissive): ?string {
        $lead = '(?:--resume[= ]|--conversation[= ]|-s\s+)';
        if (preg_match('/' . $lead . '([A-Za-z0-9_][A-Za-z0-9_-]{19,})/', $pane, $m)) return $m[1];
        if (preg_match('/' . $lead . '(?:"([^"\r\n]+)"|\'([^\'\r\n]+)\')/', $pane, $m)) {
            $name = !empty($m[1]) ? $m[1] : ($m[2] ?? '');
            if ($name !== '' && $name[0] !== '-') return $name;
        }
        if ($permissive && preg_match('/' . $lead . '([A-Za-z0-9_][A-Za-z0-9_-]{7,})/', $pane, $m)) return $m[1];
        return null;
    }

    /**
     * Agent-specific fallback for the most recent session id when the exit
     * screen doesn't print a resume hint. Used by captureResumeForClose() only
     * when the pane regex misses.
     *
     * Returns null if unavailable or unsupported for the agent.
     */
    public static function discoverLatestSessionId(string $agentId, string $workspacePath = '', ?string $homeDirOverride = null): ?string {
        $config = getAICliConfig();
        $username = $config['user'] ?? 'root';
        if (empty($username)) $username = 'root';
        $homeDir = $homeDirOverride
            ?? (\AICliAgents\Services\UtilityService::getWorkDir($username) . "/home");

        if ($agentId === 'opencode') {
            // #146: newest session FOR THIS WORKSPACE (directory-filtered across the
            // legacy + per-session isolated dbs) — NOT the globally-newest session,
            // which belongs to whichever workspace ran last and opened the wrong chat.
            return \AICliAgents\Services\TerminalService::opencodeResumeId($homeDir, $workspacePath);
        }

        if ($agentId === 'opencode2') {
            // #822: OpenCode 2 keeps its sessions in a database of its own (XDG folder of the launch script).
            return \AICliAgents\Services\TerminalService::opencode2ResumeId($homeDir, $workspacePath);
        }

        if ($agentId === 'kilocode') {
            // #147: Kilo (an OpenCode fork) had NO branch here, so resume fell back to
            // --continue with no chat id and started fresh. Same directory-filtered
            // discovery over its single kilo.db.
            return \AICliAgents\Services\TerminalService::kilocodeResumeId($homeDir, $workspacePath);
        }

        if ($agentId === 'antigravity-cli') {
            // agy maps {workspace cwd -> conversation id} in its own authoritative
            // index (cache/last_conversations.json). Prefer it — it is
            // workspace-correct. A global-newest .pb mtime scan picks a DIFFERENT
            // workspace's chat (the claude-code branch below documents exactly this
            // hazard; the stale resume_*.json entries observed on .4 — pointing at
            // conversations that no longer exist — are that scan misfiring).
            $byWorkspace = \AICliAgents\Services\TerminalService::antigravityResumeId($homeDir, $workspacePath);
            if ($byWorkspace !== null) return $byWorkspace;

            // Fallback: newest <uuid>.pb by mtime — covers a conversation not yet
            // in the index (or a blank $workspacePath). Validate the id shape.
            $dir = "$homeDir/.gemini/antigravity-cli/conversations";
            if (!is_dir($dir)) return null;
            $newestMtime = 0;
            $newestId    = null;
            foreach (glob("$dir/*.pb") ?: [] as $file) {
                $mtime = @filemtime($file) ?: 0;
                if ($mtime > $newestMtime) {
                    $newestMtime = $mtime;
                    $newestId    = basename($file, '.pb');
                }
            }
            if ($newestId !== null && preg_match('/^[A-Za-z0-9-]{20,}$/D', $newestId)) return $newestId;
            return null;
        }

        if ($agentId === 'kimi-code') {
            return \AICliAgents\Services\TerminalService::kimiCodeResumeId($homeDir, $workspacePath);
        }

        if ($agentId === 'grok-build') {
            return \AICliAgents\Services\TerminalService::grokBuildResumeId($homeDir, $workspacePath);
        }

        if ($agentId === 'claude-code') {
            // Claude organises sessions by project — `.claude/projects/<dasherised-cwd>/<uuid>.jsonl`.
            // A globally-newest scan would pick a session from a DIFFERENT
            // workspace and claude would later refuse the resume with
            // "No conversation found" (claude looks up the session under the
            // current cwd's project dir, not the originating one). So we MUST
            // restrict the search to the closing workspace's project subdir.
            $scanDirs = [];
            if (!empty($workspacePath)) {
                // #783: Claude turns EVERY character that is not a letter or digit into '-'
                // ('_' and '.' too), so /mnt/a_b -> -mnt-a-b. Try that key and the legacy one
                // ('/' only), the same rule as ClaudeSessionNameResolver::projectKeys (#609).
                foreach (\AICliAgents\Services\ClaudeSessionNameResolver::projectKeys(rtrim($workspacePath, '/')) as $proj) {
                    $candidate = "$homeDir/.claude/projects/$proj";
                    if (is_dir($candidate)) $scanDirs[] = $candidate;
                }
                // #783: a workspace with no conversation of its own has nothing to resume. Never
                // scan every project: the newest conversation on the box belongs to another
                // workspace, and a later start resumed it (a script run opened esp-teddy's live chat).
                if (empty($scanDirs)) return null;
            }
            // Legacy fallback only when no workspace context was provided
            // (e.g. older callers). Keeps backwards compat for any future
            // call-site we haven't audited.
            if (empty($scanDirs)) {
                foreach (["$homeDir/.claude/projects", "$homeDir/.claude/sessions"] as $dir) {
                    if (is_dir($dir)) $scanDirs[] = $dir;
                }
            }
            $newestMtime = 0;
            $newestId    = null;
            foreach ($scanDirs as $dir) {
                $it = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
                );
                foreach ($it as $file) {
                    if ($file->getExtension() !== 'jsonl') continue;
                    // #71: subagent transcripts are NOT resumable conversations.
                    // Claude stores them in the same project tree as the main
                    // session files (basename `agent-<id>.jsonl`, typically under
                    // a subagents/ dir). The recursive scan here once promoted
                    // `agent-a1cac446c74dc0fc2` to resume_id — resuming from it
                    // opens the wrong transcript. Filter to main-conversation
                    // ids only.
                    $base = $file->getBasename('.jsonl');
                    if (strpos($base, 'agent-') === 0) continue;
                    if (strpos(str_replace('\\', '/', $file->getPathname()), '/subagents/') !== false) continue;
                    $mtime = $file->getMTime();
                    if ($mtime > $newestMtime) {
                        $newestMtime = $mtime;
                        $newestId    = $file->getBasename('.jsonl');
                    }
                }
            }
            // #71: main-conversation ids are GUIDs (8-4-4-4-12 hex). Anything
            // else found on disk is agent metadata, not a resumable session.
            if ($newestId !== null && preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/D', $newestId)) return $newestId;
            return null;
        }

        if ($agentId === 'codex-cli') {
            // #91: Codex stores each resumable conversation as JSONL under a
            // date tree. The filename is not sufficient for workspace routing;
            // the first session_meta record carries both the authoritative id
            // and cwd. Restrict to the closing workspace so a newer Codex chat
            // elsewhere cannot be resumed into this drawer workspace.
            $dir = "$homeDir/.codex/sessions";
            if (!is_dir($dir)) return null;
            // Codex records the folder it was LAUNCHED in. A cache-only share launches in its pool path
            // (/mnt/cache/<share>/...), while the workspace is known as /mnt/user/<share>/...: match every form, or the
            // newest conversation is never found and Reload is refused (2026-10-06, model-qwen38-27b-ternary).
            $wantedCwds = [rtrim(str_replace('\\', '/', $workspacePath), '/')];
            if ($wantedCwds[0] !== '') {
                $launch = \AICliAgents\Services\PoolPathService::launchDirectory($workspacePath, $config);
                $pool = \AICliAgents\Services\PoolPathService::resolvePoolPath($workspacePath);
                foreach ([$launch, $pool] as $form) {
                    $form = rtrim(str_replace('\\', '/', (string)$form), '/');
                    if ($form !== '' && !in_array($form, $wantedCwds, true)) $wantedCwds[] = $form;
                }
            }
            if ($wantedCwds[0] === '') return null;
            $newestMtime = 0;
            $newestId = null;
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                if ($file->getExtension() !== 'jsonl') continue;
                $fh = @fopen($file->getPathname(), 'rb');
                if ($fh === false) continue;
                $line = fgets($fh);
                fclose($fh);
                if ($line === false) continue;
                $meta = json_decode($line, true);
                if (!is_array($meta) || ($meta['type'] ?? '') !== 'session_meta') continue;
                $payload = $meta['payload'] ?? null;
                if (!is_array($payload)) continue;
                $id = (string)($payload['id'] ?? '');
                $cwd = rtrim(str_replace('\\', '/', (string)($payload['cwd'] ?? '')), '/');
                if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/D', $id)) continue;
                if (!in_array($cwd, $wantedCwds, true)) continue;
                // Forgejo #448: since Codex 0.159 the sub-agent threads an agent
                // spawns are stored the same way, with `source: {subagent: …}`,
                // `thread_source: "subagent"` and `parent_thread_id`. Codex refuses
                // to resume one ("cannot resume an unloaded multi-agent v2
                // sub-agent through its parent"). Only a person's own thread counts.
                if (self::codexRecordIsSubThread($payload)) continue;
                $mtime = $file->getMTime();
                if ($mtime > $newestMtime) {
                    $newestMtime = $mtime;
                    $newestId = $id;
                }
            }
            return $newestId;
        }

        return null;
    }

    /** Forgejo #448: true for a Codex session_meta payload that is a sub-agent or forked thread. */
    public static function codexRecordIsSubThread(array $payload): bool {
        if (!empty($payload['parent_thread_id']) || !empty($payload['forked_from_id'])) return true;
        if (array_key_exists('source', $payload) && !is_string($payload['source'])) return true;
        $ts = $payload['thread_source'] ?? null;
        if ($ts !== null && $ts !== 'user') return true;
        return false;
    }

    private static function restart($id) {
        // Forgejo #352: hold the workspace's launch lock from the close until the
        // new agent runs. An open page sees the agent stop and sends `start`; that
        // start now waits here and then attaches to the new session, instead of
        // launching a second agent on the same conversation.
        $busy = self::takeLaunchLock((string)$id, 'restart');
        if ($busy !== null) return $busy;
        try {
            // Restart is a continue-current-conversation action. Reuse the same
            // quiesce + pane/disk capture pipeline as Close before replacing the
            // terminal, so agents whose id is not present in browser state still
            // resume precisely. `_fresh_` belongs exclusively to Start New Session
            // and is deliberately converted to auto-resume here.
            self::gracefulClose($id);
            // gracefulClose persisted the authoritative pane/disk capture for this
            // workspace. Resolve it through ConfigService at launch rather than
            // trusting a browser-held id that may predate an in-TUI /resume switch.
            $chatId = self::restartChatId($_GET['chatId'] ?? null);
            // Never default the agent: relaunching a workspace as a different agent
            // than it was created with is a silent, destructive surprise.
            $agentId = \AICliAgents\Services\ConfigService::resolveAgentId($_GET['agentId'] ?? null, (string)$id, (string)($_GET['path'] ?? ''));
            if ($agentId === '') return \AICliAgents\Services\ConfigService::agentIdUnresolvedError('restart', (string)$id, (string)($_GET['path'] ?? ''));
            $started = startAICliTerminal($id, $_GET['path'] ?? null, $chatId, $agentId);
            $refused = self::startRefusal($started, (string)$id);
            if ($refused !== null) return $refused;
            return self::startedResponse($id);
        } finally {
            \AICliAgents\Services\SessionLaunchLock::release((string)$id);
        }
    }

    /**
     * WORKSPACE_RELOAD_ONTO_CURRENT.md (2026-09-09): move ONE running workspace
     * onto the current plugin generation and resume its conversation, without
     * the operator having to close it, find its folder again, and click Resume.
     *
     * A running workspace runs the shell script generated at ITS launch time —
     * `PLUGIN_SRC` inside that script names a concrete `.generations/<id>/src`
     * directory, so a shipped fix never reaches an already-open workspace
     * (ProcessManager::generationIsStale). `refresh_bridge` reattaches the
     * browser only; the agent process itself stays on the old generation. This
     * action is the heavier, correct fix: quiesce, relaunch on the CURRENT
     * generation, resume the same conversation, nudge it back to work.
     *
     * ORDER OF OPERATIONS IS THE SAFETY PROPERTY. Every refusal below must run
     * BEFORE anything is closed — a refusal that fires after the close has
     * already destroyed the thing it was protecting. In particular the pane
     * READINESS GATE (step 4) must run before gracefulClose/restart are ever
     * reached; WorkspaceReloadTest::testGateRunsBeforeAnyCloseOrRestart pins
     * this with a source-text guard, the same technique
     * RelayReadinessGateTest::testGateRunsBeforeAnyPasteOrEnter uses for the
     * #148 relay gate.
     *
     * @return array<string,mixed>
     */
    private static function reloadOntoCurrent($id): array {
        // Forgejo #352 (2026-09-29): hold the workspace's launch lock for the
        // checks AND the restart. A second switch (another tab or device) then
        // waits, and its own checks run after the first switch is done: the
        // workspace is no longer behind, so it is refused as 'not_stale' instead
        // of a second close and resume. reloadOntoCurrentLocked() frees the lock
        // as soon as the new agent runs, so the Continue ladder never holds it.
        $busy = self::takeLaunchLock((string)$id, 'reloadOntoCurrent');
        if ($busy !== null) return $busy;
        try {
            return self::reloadOntoCurrentLocked((string)$id);
        } finally {
            if (\AICliAgents\Services\SessionLaunchLock::heldHere((string)$id)) {
                \AICliAgents\Services\SessionLaunchLock::release((string)$id);
            }
        }
    }

    /**
     * reloadOntoCurrent() body. Runs while this request holds the workspace's SessionLaunchLock.
     *
     * @return array<string,mixed>
     */
    private static function reloadOntoCurrentLocked(string $id): array {
        // `continue=0` suppresses the post-reload nudge. Moving a workspace onto
        // a different agent version is a deliberate, self-contained action the
        // operator chose — it is NOT the plugin recovering a workspace after an
        // upgrade it did to them. Their work may well be finished, and the next
        // thing they want to type is a new instruction, not a machine-sent
        // "continue" racing them to the prompt. Reported 2026-09-15: "I wouldn't
        // want you to continue, I'd want to give you the next instruction."
        //
        // Default stays ON so the automatic post-upgrade reload path, which the
        // operator did not ask for, still picks the work back up as before.
        $sendContinue = ($_GET['continue'] ?? '1') !== '0';
        $id = (string)$id;

        // 1. Resolve the workspace's agent + path from the saved registry — this
        // action is called with only a session id, and restart() reads
        // $_GET['agentId'] (it was built as a browser AJAX endpoint), so the
        // agent has to be supplied explicitly or the restart refuses. The old
        // 'gemini-cli' default that used to fill that gap is gone: it silently
        // targeted the wrong agent's tmux session and binary. See
        // ConfigService::resolveAgentId().
        $agentId = '';
        $path = '';
        foreach (\AICliAgents\Services\ConfigService::getWorkspaces()['sessions'] ?? [] as $w) {
            if ((string)($w['id'] ?? '') === $id) {
                $agentId = (string)($w['agentId'] ?? '');
                $path = (string)($w['path'] ?? '');
                break;
            }
        }
        if ($agentId === '') {
            return ['status' => 'error', 'reason' => 'unknown_workspace', 'message' => 'Unknown workspace session.'];
        }

        // Refuse if not running — reloading requires a live agent to quiesce
        // and a live pane to nudge; there is nothing here to move.
        if (!\AICliAgents\Services\ProcessManager::isRunning($id)) {
            return ['status' => 'error', 'reason' => 'not_running', 'message' => 'That workspace is not running.'];
        }

        // 2. Refuse only when there is genuinely nothing to move onto. There are
        // now TWO independent reasons a workspace can be behind, and checking
        // only the first refused exactly the case the Switch-to-version menu item
        // exists for (reported 2026-09-15: "it said you are already on this new
        // version, which I don't think is true" — the PLUGIN was current, the
        // AGENT was a version behind):
        //
        //   a) the PLUGIN generation it launched under has been superseded, or
        //   b) the AGENT version it is running is not the installed one
        //      (docs/specs/WORKSPACE_APPLY_AGENT_VERSION.md).
        //
        // Either is a real reason to close and relaunch. Both being current is
        // the only case where the reload is pure cost for zero benefit.
        $launched = \AICliAgents\Services\ProcessManager::sessionLaunchGeneration($id);
        $active = \AICliAgents\Services\ProcessManager::activeGeneration();
        $pluginStale = \AICliAgents\Services\ProcessManager::generationIsStale($launched, $active);
        $agentStale = false;
        try {
            $agentStale = array_key_exists($id, \AICliAgents\Services\TerminalService::sessionsOnOtherAgentVersion());
        } catch (\Throwable $e) { /* never let this check refuse a legitimate reload */ }
        if (!$pluginStale && !$agentStale) {
            return ['status' => 'error', 'reason' => 'not_stale',
                    'message' => 'This workspace is already on the current plugin and agent version — nothing to move it onto.'];
        }

        // 3. Refuse if an agent upgrade is queued or active for this agent.
        // Two relaunch pipelines must never race over the same binary/session:
        // an upgrade's own close-and-relaunch (AgentHandler::isInstallInProgress
        // — covers a live install-bg.php process, a fresh in-progress marker,
        // AND a completed install still waiting on UpgradeRelaunchService's
        // closed-set manifest to activate) would collide with this action
        // closing and relaunching the SAME session at the SAME time. That
        // check deliberately does NOT cover the #71 non-destructive PRE-install
        // queue (PendingAgentUpgradeService) — a request that is waiting for
        // every active session of this agent to close naturally BEFORE the
        // install begins — because elsewhere (TerminalHandler::start) a queued
        // upgrade must not block new sessions. Here it must: if we close this
        // session while the queue is watching for zero active sessions, the
        // supervisor can start the binary swap mid-relaunch. So both signals
        // are checked, reusing the existing predicates verbatim — no new
        // detection invented.
        require_once __DIR__ . '/AgentHandler.php';
        $upgradeQueuedOrActive = \AICliAgents\Handlers\AgentHandler::isInstallInProgress($agentId)
            || \AICliAgents\Services\PendingAgentUpgradeService::read($agentId) !== [];
        if ($upgradeQueuedOrActive) {
            return ['status' => 'error', 'reason' => 'upgrade_in_progress',
                'message' => 'An upgrade is queued or in progress for this agent — check the Activity tray and retry once it finishes.'];
        }

        // 4. READINESS GATE — BEFORE ANYTHING IS CLOSED. Reuses the #148 pane
        // classifier (the same one relay delivery and the Continue nudge use):
        // a pane mid-decision (question/menu/pager/parked) must never have a
        // close signalled into it — the trailing keys of a graceful close would
        // land on whatever is highlighted, not on the agent. Return 'busy' and
        // do nothing else; the operator can see the pane and retry.
        $gate = \AICliAgents\Services\TmuxService::paneAcceptsInput($agentId, $id);
        if (($gate['ready'] ?? false) !== true) {
            return ['status' => 'busy', 'reason' => (string)($gate['reason'] ?? 'not-ready'),
                'message' => 'The agent is busy or mid-prompt — try Reload again once it is idle.'];
        }

        // 5. Confirm a resume target exists BEFORE closing anything. restart()
        // always launches with the 'auto' resume sentinel, which
        // TerminalService resolves through ConfigService::getResumeId($path,
        // $agentId) — the persisted chat id from this workspace's last close.
        // Better a stale workspace than a lost conversation (R6): if nothing
        // is on record to resume, refuse rather than gamble that the
        // close-time pane scrape/disk-fallback in captureResumeForClose finds
        // something new.
        $resumeId = \AICliAgents\Services\ConfigService::getResumeId($path, $agentId, (string)$id);
        if (empty($resumeId)) {
            // Nothing is on record yet: a conversation id is saved only when a workspace is CLOSED, so a workspace that has
            // run since it started has none (Codex, 2026-10-06). Look in the agent's own session files first (read-only, the
            // same lookup the close uses). Refuse only when no conversation of this workspace can be found.
            $found = self::discoverLatestSessionId((string)$agentId, (string)$path);
            if (!empty($found)) {
                \AICliAgents\Services\ConfigService::saveDiskFallbackResumeId($path, $agentId, $found, (string)$id);
                $resumeId = \AICliAgents\Services\ConfigService::getResumeId($path, $agentId, (string)$id);
            }
        }
        if (empty($resumeId)) {
            return ['status' => 'error', 'reason' => 'no_resume_target',
                'message' => 'No saved conversation to resume for this workspace — reload was refused to avoid losing it.'];
        }

        // 6. Only now: perform the restart. Reuse restart()'s existing
        // quiesce -> relaunch-on-current -> resume path rather than
        // reimplementing close+start. restart()/gracefulClose() read
        // $_GET['path'] and $_GET['agentId'] (they were built as browser AJAX
        // endpoints), so this server-initiated call supplies both explicitly
        // from the workspace record resolved in step 1. Without them the agent
        // id is unresolvable and the restart refuses with a clear error rather
        // than guessing an agent (ConfigService::agentIdUnresolvedError()).
        $_GET['path'] = $path;
        $_GET['agentId'] = $agentId;
        // #349 (2026-09-29): hold the automatic Continue BEFORE the restart. An open
        // page sees the workspace stop and run again while restart() is still busy
        // (the live log showed its continue_session one second after the relaunch,
        // before this request returned), so a hold set after restart() is too late.
        if (!$sendContinue) {
            \AICliAgents\Services\ContinueHold::hold($id);
        }
        $result = self::restart($id);
        // The new agent runs (or the restart failed): free the launch lock now.
        // A page's `start` that waited for this switch attaches at once, and the
        // Continue ladder below (up to 32 s) does not keep it waiting.
        \AICliAgents\Services\SessionLaunchLock::release($id);
        if (!$sendContinue) {
            // R-101: stamp the hold again after a good restart (a slow restart can use most
            // of the first stamp); a failed restart leaves nothing to hold, so clear it.
            \AICliAgents\Services\ContinueHold::afterRestart($id, $result);
        }

        // 7. Nudge it back to work once it reaches idle. Mirrors DrawerPanel's
        // auto-continue retry ladder (CONTINUE_ON_RESTART.md): up to 8 attempts
        // spaced 4s apart, retried only while the gate reports 'busy' (still
        // booting). A reload that succeeds with a nudge that never lands is
        // reported as a SUCCESS with a note, not a failure — the workspace IS
        // on the current generation and IS resumed; Continue is one click away.
        if (!$sendContinue) {
            aicli_log("reloadOntoCurrent: continue nudge suppressed by request (operator-chosen switch) | session=$id agent=$agentId",
                AICLI_LOG_INFO, "TerminalHandler");
            return array_merge($result, ['reloaded' => true, 'nudge' => ['status' => 'skipped', 'reason' => 'not_requested']]);
        }
        $nudge = self::nudgeWithRetryLadder($agentId, $id);

        return array_merge($result, ['reloaded' => true, 'nudge' => $nudge]);
    }

    /**
     * Retry ladder for the post-reload continue nudge, mirroring
     * DrawerPanel's auto-continue-on-restart cadence (retriesLeft: 8, 4s
     * between attempts) so a just-relaunched agent that is still booting gets
     * nudged once it reaches idle, without retrying forever. Stops the moment
     * the nudge is delivered ('ok') or genuinely errors — only a 'busy' pane
     * (still mid-boot) is worth waiting out.
     *
     * @return array<string,mixed>
     */
    private static function nudgeWithRetryLadder(string $agentId, string $sessionId): array {
        $maxAttempts = 8;
        $delayUs = 4_000_000; // 4s — same cadence as DrawerPanel's setTimeout(...,4000)
        $result = ['status' => 'error', 'message' => 'Continue nudge was never attempted.'];
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $result = \AICliAgents\Services\TmuxService::submitContinueNudge($agentId, $sessionId);
            if (($result['status'] ?? '') !== 'busy') break;
            if ($attempt < $maxAttempts) usleep($delayUs);
        }
        return $result;
    }

    /**
     * Return the identity of the ttyd endpoint created by a successful launch.
     * The browser seeds its iframe key from this response before first render;
     * otherwise the first status poll changes `unknown` to the already-running
     * generation and needlessly replaces a healthy, newly attached terminal.
     */
    /**
     * #611: turn the result of a start into an error answer. Null when the start ran (or the
     * caller gave no result). A refused start must not be answered "ok" with a socket
     * address that does not exist.
     *
     * @param mixed $result  what TerminalService::startTerminal returned
     * @return array<string,mixed>|null
     */
    private static function startRefusal($result, string $id): ?array {
        if (!is_array($result) || !array_key_exists('ok', $result) || $result['ok']) return null;
        $reason = (string)($result['reason'] ?? 'start_failed');
        aicli_log("start refused for session=$id: $reason", AICLI_LOG_WARN, "TerminalHandler");
        if ($reason === 'lock_timeout') {
            return ['status' => 'busy', 'reason' => 'launch_in_progress',
                'message' => 'This workspace is still starting, closing or restarting. Try again in a moment.'];
        }
        if ($reason === 'agent_uninstalling' || $reason === 'host_shutdown') {
            // #906, #910: no tray row exists for this refusal, so the reply carries the plain sentence.
            return ['status' => 'error', 'reason' => $reason,
                'message' => \AICliAgents\Services\TerminalService::startFailureSentence($reason, '')];
        }
        return ['status' => 'error', 'reason' => $reason,
            'message' => 'The workspace did not start (' . $reason . '). Check the Activity tray for the cause.'];
    }

    private static function startedResponse($id, array $extra = []): array {
        return array_merge([
            'status' => 'ok',
            'sock' => "/webterminal/aicliterm-$id/",
            'terminalGeneration' => \AICliAgents\Services\TerminalGenerationService::current((string)$id),
        ], $extra);
    }

    /**
     * DRAWER_RESTART_AS_NEW.md: close the running session cleanly (the same
     * quiesce + resume-capture path as Close, so the old conversation stays in the
     * agent's own history), drop the workspace's saved resume point, and start the
     * agent fresh (`_fresh_` — the launcher skips every resume fallback). The
     * session keeps its id, so its Relay identity (actor ownership, inbox, DM
     * history) is untouched; peers learn about the reset passively, in the
     * response to their next send (AgentRelayService::freshContextNote).
     */
    private static function restartFresh($id) {
        $safeId  = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$id);
        $path    = (string)($_GET['path'] ?? '');
        // Never default the agent: starting the WRONG agent in a user's
        // workspace is worse than refusing to start at all.
        $agentId = \AICliAgents\Services\ConfigService::resolveAgentId($_GET['agentId'] ?? null, (string)$id, (string)($_GET['path'] ?? ''));
        if ($agentId === '') return \AICliAgents\Services\ConfigService::agentIdUnresolvedError('restart_fresh', (string)$id, (string)($_GET['path'] ?? ''));
        // Refuse BEFORE closing anything: a start would be blocked anyway and the
        // user would be left with a closed session (R6).
        if (\AICliAgents\Handlers\AgentHandler::isInstallInProgress($agentId)) {
            return ['status' => 'error', 'reason' => 'upgrade_in_progress',
                    'message' => 'Upgrade in progress — this workspace cannot be restarted until it finishes.'];
        }
        aicli_log("restartFresh: START session=$safeId agent=$agentId workspace=" . ($path !== '' ? $path : 'unknown'), AICLI_LOG_INFO, "TerminalHandler");
        // Forgejo #352: one lock from the close until the new agent runs.
        $busy = self::takeLaunchLock((string)$id, 'restartFresh');
        if ($busy !== null) return $busy;
        try {
            self::gracefulClose($id);
            $relay = [];
            if (class_exists('\AICliAgents\Services\AgentRelayService')) {
                try { $relay = \AICliAgents\Services\AgentRelayService::markConversationRestart($safeId, $agentId, $path); }
                catch (\Throwable $e) { $relay = ['status' => 'error', 'message' => $e->getMessage()]; }
            }
            $started = startAICliTerminal($id, $path !== '' ? $path : null, '_fresh_', $agentId);
            $refused = self::startRefusal($started, (string)$id);
            if ($refused !== null) {
                // #611: the start was refused, so the saved resume point stays. Only a start that ran may drop it.
                aicli_log("restartFresh: start refused for session=$safeId reason=" . (string)($refused['reason'] ?? '') . "; the saved resume id is kept", AICLI_LOG_WARN, "TerminalHandler");
                return $refused;
            }
            if ($path !== '') {
                \AICliAgents\Services\ConfigService::clearResumeId($path, $agentId, (string)$id);
                aicli_log("restartFresh: cleared saved resume id for (workspace=$path, agent=$agentId)", AICLI_LOG_INFO, "TerminalHandler");
            }
        } finally {
            \AICliAgents\Services\SessionLaunchLock::release((string)$id);
        }
        aicli_log("restartFresh: DONE session=$safeId started fresh (relay=" . (string)($relay['status'] ?? 'n/a') . ")", AICLI_LOG_INFO, "TerminalHandler");
        return self::startedResponse($id, ['fresh' => true, 'relay' => $relay]);
    }

    /** Resolve the resume selector for an explicit Restart request. */
    public static function restartChatId(?string $requested): string {
        return 'auto';
    }

    private static function getChatSession() {
        $path = $_GET['path'] ?? '';
        // Never default the agent: reporting another agent's state is a lie.
        $agentId = \AICliAgents\Services\ConfigService::resolveAgentId($_GET['agentId'] ?? null, (string)($_GET['id'] ?? ''), (string)($_GET['path'] ?? ''));
        // Degrade, do not refuse — see getSessionStatus. A null chatId is an honest
        // "unknown"; another agent's chatId would be a confident wrong answer.
        $chatId = $agentId === '' ? null : \AICliAgents\Services\TerminalService::findSession($path, $agentId);
        return ['status' => 'ok', 'chatId' => $chatId];
    }

    /** Cheap active-session probe used to replace stale ttyd iframes. */
    private static function getSessionStatus($id) {
        $path = (string)($_GET['path'] ?? '');
        // Never default the agent: reporting another agent's state is a lie.
        $agentId = \AICliAgents\Services\ConfigService::resolveAgentId($_GET['agentId'] ?? null, (string)$id, (string)($_GET['path'] ?? ''));
        // This endpoint is POLLED, and legitimately for ids that have no workspace
        // record yet. So an unresolved agent DEGRADES rather than refuses: report the
        // generation (which needs no agent) and return a null chatId instead of a
        // chatId belonging to some other agent. Refusing here broke the polling
        // contract asserted by TerminalGenerationServiceTest (2026-09-09).
        return [
            'status' => 'ok',
            // Preserve the status endpoint's original conversation-sync
            // contract while adding the ttyd identity used for reconnects.
            // Two separate reasons to answer "unknown", each its own branch. An
            // unresolved agent must never reach a lookup (AgentIdResolutionTest pins
            // this exact short-circuit). RESUME_IDENTITY_PER_WORKSPACE.md (V4):
            // findSession() is keyed by FOLDER, so on a folder another open workspace
            // shares it cannot say whose conversation it found — report none rather
            // than hand the drawer the sibling's id as this one's.
            'chatId' => $agentId === '' ? null : (
                ($path !== '' && \AICliAgents\Services\ConfigService::isSharedFolder($path, $agentId, (string)$id))
                    ? null
                    : \AICliAgents\Services\TerminalService::findSession($path, $agentId)
            ),
            'terminalGeneration' => \AICliAgents\Services\TerminalGenerationService::current((string)$id),
            // #365 (docs/specs/TERMINAL_BACKGROUND_WARM.md): true once ttyd's socket
            // exists. The page mounts the terminal frame only after this, so the
            // first request never finds no socket (nginx [crit] connect() failed).
            'terminalSocketReady' => (bool)preg_match('/^[A-Za-z0-9_-]{1,64}$/D', (string)$id)
                && file_exists('/var/run/aicliterm-' . $id . '.sock'),
        ];
    }

    private static function log() {
        $msg = $_POST['message'] ?? $_GET['message'] ?? '';
        $lvl = $_POST['level'] ?? $_GET['level'] ?? 2;
        $ctx = $_POST['context'] ?? $_GET['context'] ?? 'Frontend';
        $line = self::clientLogLine($msg, $lvl, $ctx);
        if ($line !== null) {
            aicli_log($line['message'], $line['level'], $line['context']);
        }
        return ['status' => 'ok'];
    }

    /**
     * R-169: a browser log line must not forge a server log line. The context is cut to the
     * safe tag characters, the level is held to 0-3, and a new line in the message is turned
     * into a space, so one request writes exactly one line tagged [JS].
     * @return array{message:string,level:int,context:string}|null null when there is nothing to log
     */
    public static function clientLogLine($msg, $lvl, $ctx): ?array {
        if (!is_scalar($msg)) return null;
        $msg = trim(preg_replace('/[\r\n]+/', ' ', (string)$msg));
        if ($msg === '') return null;
        $level = max(0, min(3, (int)(is_scalar($lvl) ? $lvl : 2)));
        $context = ValidationService::sanitizeContext($ctx);
        if ($context === '') $context = 'Frontend';
        return ['message' => "[JS] $msg", 'level' => $level, 'context' => $context];
    }

    /**
     * R-07 (#1370): server-side filtered log fetch. Optional params:
     *   ctx=<string>   — substring match on the [Context] field (or JSONL "ctx")
     *   trace=<hex>    — exact match on the [t:<id>] field (R-06 join key)
     *   level=<0-3>    — only lines at or below this level (0=ERR! … 3=DBUG)
     *   tail=<N>       — last N lines AFTER filtering (default 500, hard cap 2000)
     * Never ships the whole file: scans at most the last 2000 raw lines.
     */
    private static function getLog() {
        $type = $_GET['type'] ?? 'debug';
        $logFile = self::resolveLogFile($type);
        if (!file_exists($logFile)) {
            return ['status' => 'ok', 'content' => "No log entries found for [" . ucfirst($type) . "]."];
        }

        $tail = (int)($_GET['tail'] ?? 500);
        $tail = max(1, min(2000, $tail ?: 500));
        $ctx   = trim((string)($_GET['ctx'] ?? ''));
        $trace = trim((string)($_GET['trace'] ?? ''));
        if ($trace !== '' && !preg_match('/^[a-z0-9]{4,16}$/D', $trace)) $trace = '';
        $levelRaw = $_GET['level'] ?? '';
        $level = ($levelRaw !== '' && is_numeric($levelRaw)) ? max(0, min(3, (int)$levelRaw)) : null;

        $lines = aicli_tail($logFile, 2000);
        if ($ctx !== '' || $trace !== '' || $level !== null) {
            $lines = array_values(array_filter($lines, function ($line) use ($ctx, $trace, $level) {
                $f = self::parseLogLine($line);
                if ($f === null) return false; // filters active → unparseable lines drop
                if ($ctx !== '' && stripos($f['ctx'], $ctx) === false) return false;
                if ($trace !== '' && $f['trace'] !== $trace) return false;
                if ($level !== null && ($f['lvl'] === null || $f['lvl'] > $level)) return false;
                return true;
            }));
        }
        $lines = array_slice($lines, -$tail);
        $content = mb_convert_encoding(implode("\n", $lines), 'UTF-8', 'UTF-8');
        return ['status' => 'ok', 'content' => $content, 'lines' => count($lines)];
    }

    /**
     * R-07: distinct [Context] values from the recent tail of the debug log —
     * feeds the Debug Console context-filter dropdown.
     */
    private static function getLogContexts() {
        $logFile = self::resolveLogFile($_GET['type'] ?? 'debug');
        $contexts = [];
        if (file_exists($logFile)) {
            foreach (aicli_tail($logFile, 2000) as $line) {
                $f = self::parseLogLine($line);
                if ($f !== null && $f['ctx'] !== '') $contexts[$f['ctx']] = true;
            }
        }
        $list = array_keys($contexts);
        sort($list, SORT_NATURAL | SORT_FLAG_CASE);
        return ['status' => 'ok', 'contexts' => $list];
    }

    /** Levels as logged by LogService, in LOG_* numeric order. */
    private const LEVEL_STRINGS = ['ERR!' => 0, 'WARN' => 1, 'INFO' => 2, 'DBUG' => 3];

    /**
     * Parse one debug-log line into ['ctx','trace','lvl'] — handles BOTH the
     * text format "[ts] [LEVL] [Context] [t:id] msg" and JSONL
     * {"ts","lvl","ctx","trace","msg"} (debug_log_format=jsonl). Returns null
     * for lines in neither shape (raw shell echoes parse via the text regex
     * since they share the [ts] [LEVL] [ctx] prefix convention).
     * @return array{ctx:string,trace:?string,lvl:?int}|null
     */
    private static function parseLogLine(string $line): ?array {
        $line = trim($line);
        if ($line === '') return null;
        if ($line[0] === '{') {
            $j = json_decode($line, true);
            if (!is_array($j)) return null;
            return [
                'ctx'   => (string)($j['ctx'] ?? ''),
                'trace' => isset($j['trace']) && $j['trace'] !== null ? (string)$j['trace'] : null,
                'lvl'   => self::LEVEL_STRINGS[(string)($j['lvl'] ?? '')] ?? null,
            ];
        }
        if (!preg_match('/^\[[^\]]*\] \[([A-Z!]{4})\] \[([^\]]*)\](?: \[t:([a-z0-9]{4,16})\])?/', $line, $m)) {
            return null;
        }
        return [
            'ctx'   => $m[2],
            'trace' => isset($m[3]) && $m[3] !== '' ? $m[3] : null,
            'lvl'   => self::LEVEL_STRINGS[$m[1]] ?? null,
        ];
    }

    private static function clearLog() {
        $type = $_GET['type'] ?? 'debug';
        // resolveLogFile() is a hard-coded switch with a default fallback —
        // the return value cannot be influenced by $type beyond picking one
        // of four fixed file paths. No path-traversal surface here despite
        // Semgrep's tainted-url-to-connection heuristic.
        $logFile = self::resolveLogFile($type);
        // nosemgrep: php.lang.security.tainted-url-to-connection.tainted-url-to-connection
        if (file_exists($logFile)) @file_put_contents($logFile, "");
        return ['status' => 'ok', 'message' => ucfirst($type) . " log cleared."];
    }

    private static function resolveLogFile($type) {
        switch ($type) {
            case 'install':   return \AICliAgents\Services\SecretPaths::flashRoot() . "/install.log";
            case 'uninstall': return \AICliAgents\Services\SecretPaths::flashRoot() . "/uninstall.log";
            case 'migration': return \AICliAgents\Services\SecretPaths::stateRoot() . "/migration.log";
            default:          return \AICliAgents\Services\SecretPaths::stateRoot() . "/debug.log";
        }
    }
}
