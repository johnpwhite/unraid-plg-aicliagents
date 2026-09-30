<?php
/**
 * <module_context>
 *     <name>ActivityHandler</name>
 *     <description>AJAX handler for the activity tray (T-08/T-09/T-10): list, cancel,
 *     dismiss, retry-auto-launch, (Phase 3, 2026-09-09, PLUGIN_MANAGEMENT_TOOLS.md)
 *     approve/reject a Tier 3 destructive-proposal item, and (RELAY_WAITING_PILL.md,
 *     2026-09-09) deliver a held Relay notice — over ActivityService entries plus a
 *     live projection of TmuxService's pending-relay queue.</description>
 *     <dependencies>ActivityService, TerminalService, ProcessManager, AdminService, TmuxService, RelayGateSampleService</dependencies>
 *     <constraints>Static methods only. All inputs validated before use. approve_activity/
 *     reject_activity are the ONLY path that can execute a Tier 3 proposal — there is
 *     deliberately no MCP tool or CLI action that reaches AdminService::approvePending()/
 *     rejectPending(), because this is a browser AJAX action a human clicks, never
 *     something an agent can call on its own behalf. deliver_relay_waiting calls the
 *     EXISTING TmuxService::drainPendingRelay() — it is not a second delivery path.
 *     It calls it with $force=true, and is the ONLY caller that may: forcing is a
 *     human's override of the readiness classifier, never an automatic path.</constraints>
 * </module_context>
 */

namespace AICliAgents\Handlers;

use AICliAgents\Services\ActivityService;
use AICliAgents\Services\AdminService;
use AICliAgents\Services\ProcessManager;
use AICliAgents\Services\RelayGateSampleService;
use AICliAgents\Services\TerminalService;
use AICliAgents\Services\TmuxService;

class ActivityHandler
{
    public static function handle($action, $id): ?array
    {
        switch ($action) {
            case 'list_activities':  return self::listActivities();
            case 'cancel_activity':  return self::cancelActivity();
            case 'dismiss_activity': return self::dismissActivity();
            case 'retry_auto_launch': return self::retryAutoLaunch();
            case 'approve_activity': return self::approveActivity();
            case 'reject_activity':  return self::rejectActivity();
            case 'deliver_relay_waiting': return self::deliverRelayWaiting();
            default:                 return null;
        }
    }

    /** Actions handled by this handler. */
    public static function actions(): array
    {
        return ['list_activities', 'cancel_activity', 'dismiss_activity', 'retry_auto_launch', 'approve_activity', 'reject_activity', 'deliver_relay_waiting'];
    }

    /**
     * All known activities, watchdog-evaluated. This call IS the watchdog
     * driver — the tray polls it, and each poll persists/publishes any
     * stalled/failed transitions (see ActivityService module constraints).
     */
    private static function listActivities(): array
    {
        // S-08 (#1353): mirror supervisor job-ledger state into any
        // `storage_job_*` tray entries before listing — the bash supervisor
        // cannot call PHP per transition, so the tray's poll IS the bridge
        // (same lazy pattern as the watchdog itself).
        \AICliAgents\Services\SupervisorService::syncJobActivities();
        $activities = ActivityService::listAll();
        // RELAY_WAITING_PILL.md Part 1: append one synthesized `relay_waiting`
        // entry per session with a non-empty pending queue. These are a LIVE
        // PROJECTION of TmuxService's own durable queue — never persisted to
        // ActivityService's own registry — so there is nothing here to get out
        // of sync or leak past the queue's own lifetime.
        foreach (self::relayWaitingActivities() as $entry) {
            $activities[] = $entry;
        }
        // RELAY_LINKED_BOXES.md (UI, Activity tray): one `relay_peer_queue`
        // entry per linked box whose outbox has waited over 5 minutes. Also a
        // live projection of the outbox, never stored in the registry.
        try {
            foreach (\AICliAgents\Services\RelayPeerService::queueActivities() as $entry) $activities[] = $entry;
        } catch (\Throwable $e) {
            // The tray must list even when the linked-box state cannot be read.
        }
        return ['status' => 'ok', 'activities' => $activities];
    }

    /**
     * Build the tray-shaped `relay_waiting` entries straight from
     * TmuxService::listPendingRelayQueues() — see that method's own doc
     * comment for why this is a projection, not a second store. The entry
     * shape itself is TmuxService::relayWaitingEntry() — the SAME builder
     * enqueuePendingRelay() uses for the arrival-time publish
     * (docs/specs/EVENT_FIRST_RECONCILIATION.md 1b.7), so a pill reads
     * identically whether it arrived by push or by this list-time projection.
     *
     * @return list<array<string,mixed>>
     */
    private static function relayWaitingActivities(): array
    {
        $out = [];
        foreach (TmuxService::listPendingRelayQueues() as $q) {
            $out[] = TmuxService::relayWaitingEntry((string)$q['agentId'], (string)$q['sessionId'], $q['senders']);
        }
        return $out;
    }

    /**
     * RELAY_WAITING_PILL.md Part 1 action: a human clicked Force inject on a
     * waiting-message pill, having already been taken to that workspace by the
     * pill's first click. This calls the EXISTING
     * TmuxService::drainPendingRelay() for that session — see this class's
     * own module constraint — never a new delivery path. An empty queue (it
     * drained by itself between render and click, or a session ended) is
     * reported as an ordinary, successful no-op, never an error (spec Edge
     * Cases).
     *
     * Part 2 (capture-on-deliver): when RelayGateSampleService::enabled() is
     * on, this ALSO records the pane the gate is about to judge, paired with
     * its verdict, before draining. Sampling is wrapped so any fault in it is
     * swallowed — the delivery below always proceeds regardless. A forced
     * delivery is the MOST valuable sample there is: a human has just declared
     * the classifier wrong about this exact screen.
     */
    private static function deliverRelayWaiting(): array
    {
        $opId = (string)($_REQUEST['opId'] ?? '');
        if (!preg_match('/^relay_waiting__([A-Za-z0-9_-]+)__([A-Za-z0-9_-]+)$/', $opId, $m)) {
            return ['status' => 'error', 'message' => 'invalid opId'];
        }
        $agentId   = $m[1];
        $sessionId = $m[2];

        try {
            if (RelayGateSampleService::enabled()) {
                $verdict = TmuxService::paneAcceptsInput($agentId, $sessionId);
                $capture = TmuxService::capturePaneRaw($agentId, $sessionId);
                RelayGateSampleService::record($agentId, $verdict, $capture);
            }
        } catch (\Throwable $e) {
            // Sampling is a by-product, never a gate — see RelayGateSampleService's
            // own module constraints. Fall through to the real delivery regardless.
        }

        // FORCED (RELAY_WAITING_PILL.md R2). This action only ever runs because a
        // human clicked Force inject on the pill, and the tray sends them to that
        // workspace first, so they have seen the pane they are typing into. The
        // classifier is advisory from here: it exists to stop an UNATTENDED paste,
        // and an agent that ships a new TUI can defer for ever on a shape no regex
        // knows yet. Every automatic drain (delivery path, drawer poll, supervisor
        // tick) still passes the full gate.
        $r = TmuxService::drainPendingRelay($agentId, $sessionId, true);
        switch ($r['status'] ?? '') {
            case 'ok':
                return ['status' => 'ok', 'delivered' => true, 'senders' => $r['senders'] ?? 0];
            case 'empty':
                return ['status' => 'ok', 'delivered' => false, 'message' => 'Nothing left to deliver'];
            case 'unconfirmed':
                // #320: the notice is in the input box and Enter was pressed, but the
                // agent did not take it. Nothing was typed twice; it stays queued and
                // the next drain presses Enter again.
                // 2026-09-30: after the bounded retry gave up, nothing presses Enter
                // again by itself, so do not promise that.
                return [
                    'status'    => 'ok',
                    'delivered' => false,
                    'message'   => ($r['reason'] ?? '') === 'own-notice-stuck'
                        ? 'Enter was pressed, but the agent did not take the notice. It stays in the input box. Open the workspace, then send it or clear it.'
                        : 'Enter was pressed, but the agent has not taken the notice yet. It stays in the input box and Enter is tried again soon.',
                    'reason'    => $r['reason'] ?? '',
                ];
            case 'deferred':
                // Only liveness can refuse a forced delivery now: there is no live
                // agent on that pane, so there is nothing to type into. Say that,
                // rather than the classifier wording the operator just overrode.
                return [
                    'status'    => 'ok',
                    'delivered' => false,
                    'message'   => 'Could not reach that workspace — ' . TmuxService::relayHeldReasonLabel((string)($r['reason'] ?? '')),
                    'reason'    => $r['reason'] ?? '',
                ];
            default:
                return ['status' => 'error', 'message' => $r['message'] ?? 'delivery failed'];
        }
    }

    private static function cancelActivity(): array
    {
        $opId = (string)($_REQUEST['opId'] ?? '');
        if ($opId === '') return ['status' => 'error', 'message' => 'opId required'];
        return ActivityService::cancel($opId);
    }

    private static function dismissActivity(): array
    {
        $opId = (string)($_REQUEST['opId'] ?? '');
        if ($opId === '') return ['status' => 'error', 'message' => 'opId required'];

        // RELAY_WAITING_PILL.md / EVENT_FIRST_RECONCILIATION.md R8: a
        // relay_waiting opId is a LIVE PROJECTION, never a stored
        // ActivityService entry — ActivityService::dismiss() would just see
        // "no such entry" and report success without recording anything.
        // TmuxService::dismissPendingRelay() records the dismissal itself
        // (server-wide: EVERY device stops showing this pill) and re-raises
        // on the next genuinely new arrival.
        if (preg_match('/^relay_waiting__([A-Za-z0-9_-]+)__([A-Za-z0-9_-]+)$/', $opId, $m)) {
            TmuxService::dismissPendingRelay($m[1], $m[2]);
            return ['status' => 'ok'];
        }

        return ActivityService::dismiss($opId)
            ? ['status' => 'ok']
            : ['status' => 'error', 'message' => 'cannot dismiss a running activity — cancel it first'];
    }

    /**
     * T-10 recovery hook: re-run ONE failed auto-launch workspace. The failed
     * `type:start` activity carries meta {sessionId, agentId, path, chatId}
     * recorded by AutoLaunchService at failure time.
     */
    private static function retryAutoLaunch(): array
    {
        $opId = (string)($_REQUEST['opId'] ?? '');
        if ($opId === '') return ['status' => 'error', 'message' => 'opId required'];

        $entry = ActivityService::get($opId);
        if ($entry === null) return ['status' => 'error', 'message' => 'no such activity'];
        if (($entry['type'] ?? '') !== 'start' || ($entry['recovery'] ?? '') !== 'retry') {
            return ['status' => 'error', 'message' => 'activity is not retryable'];
        }

        $meta    = is_array($entry['meta'] ?? null) ? $entry['meta'] : [];
        $sid     = (string)($meta['sessionId'] ?? '');
        $agentId = (string)($meta['agentId'] ?? '');
        $path    = (string)($meta['path'] ?? '');
        if ($sid === '' || $agentId === '' || $path === '') {
            return ['status' => 'error', 'message' => 'activity has no retry context'];
        }

        ActivityService::update($opId, ['status' => 'running', 'step' => 'retrying', 'progress' => 5, 'error' => null]);
        try {
            // startTerminal re-registers start_<sid> and steps it (T-09), so the
            // tray sees live progress for the retry as well.
            TerminalService::startTerminal($sid, $path, (string)($meta['chatId'] ?? 'auto'), $agentId, 'auto_launch');
        } catch (\Throwable $e) {
            ActivityService::fail($opId, 'retry failed: ' . $e->getMessage(), 'retry', ['meta' => $meta]);
            return ['status' => 'error', 'message' => $e->getMessage()];
        }

        if (!ProcessManager::isRunning($sid)) {
            ActivityService::fail($opId, 'retry failed: session did not start', 'retry', ['meta' => $meta]);
            return ['status' => 'error', 'message' => 'session did not start'];
        }
        return ['status' => 'ok', 'sessionId' => $sid];
    }

    /**
     * Tier 3 (PLUGIN_MANAGEMENT_TOOLS.md "Phase 3 as built", 2026-09-09): a
     * human clicked Approve on a pending destructive-proposal tray item. This
     * is the ONLY place in the whole plugin that can turn a Tier 3 proposal
     * into a real action — see this class's own module-context constraint.
     * Delegates to AdminService::approvePending(), which executes using ONLY
     * the params recorded when the tool proposed it (never re-reads args from
     * this request), so a browser cannot smuggle a different target in here.
     */
    private static function approveActivity(): array
    {
        $opId = (string)($_REQUEST['opId'] ?? '');
        if ($opId === '') return ['status' => 'error', 'message' => 'opId required'];
        $result = AdminService::approvePending($opId);
        return isset($result['error'])
            ? ['status' => 'error', 'message' => (string)$result['error']]
            : ['status' => 'ok'] + $result;
    }

    /**
     * Tier 3: a human clicked Reject. Discards the pending item without
     * executing it — see ActivityService::reject()'s own doc comment for why
     * this is distinct from the generic dismiss_activity action above (which
     * refuses on a pending_approval entry precisely so it cannot be used to
     * skip this explicit, audited path).
     */
    private static function rejectActivity(): array
    {
        $opId = (string)($_REQUEST['opId'] ?? '');
        if ($opId === '') return ['status' => 'error', 'message' => 'opId required'];
        $reason = (string)($_REQUEST['reason'] ?? '');
        $result = AdminService::rejectPending($opId, $reason);
        return isset($result['error'])
            ? ['status' => 'error', 'message' => (string)$result['error']]
            : ['status' => 'ok'] + $result;
    }
}
