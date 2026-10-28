<?php

declare(strict_types=1);

/**
 * scheduled-continue.php — SCHEDULED_CONTINUE.md (#234), Phase 1.
 *
 * Run every ~30 s by the supervisor's work tick. Reads the per-workspace
 * scheduled-continue sidecar and, for each entry that is DUE (fire epoch <= now
 * and not already fired for this occurrence), sends one Continue through the
 * SAME readiness-gated path the menu Continue and aicli_send_input use, so a
 * workspace that ran out of usage quota resumes on its own once the reset time
 * arrives. One-shot entries clear after firing; daily/weekly advance to the next
 * occurrence. A closed workspace whose agent has auto-launch on is launched and
 * resumed; the Continue is delivered on a later tick once the pane is ready.
 * An entry more than STALE_S in the past (missed while the box was down) is
 * dropped/advanced without firing. Best-effort throughout; never throws.
 *
 * #831: a workspace holds a LIST of entries. Every due entry is handled, but at most ONE
 * is typed per workspace per tick (oldest first), so two prompts never collide in the
 * pane; the others wait for the next tick. An entry with a `reason` types
 * `[Scheduled: <reason>] ` and then its message (or the standard continue prompt).
 */

$_SERVER['DOCUMENT_ROOT'] = '/usr/local/emhttp';
require_once dirname(__DIR__, 2) . '/includes/AICliAgentsManager.php';

use AICliAgents\Services\ConfigService;
use AICliAgents\Services\ProcessManager;
use AICliAgents\Services\TmuxService;
use AICliAgents\Services\TerminalService;
use AICliAgents\Services\AutoLaunchService;
use AICliAgents\Services\ActivityService;
use AICliAgents\Services\EventActor;
use AICliAgents\Services\TransientErrorService;
use AICliAgents\Services\QuotaDetectionService;

const STALE_S = 21600; // 6 h — an occurrence older than this fires nothing.

if (class_exists('\AICliAgents\Services\EventActor')) {
    EventActor::$override = ['type' => 'system'];
}

/** Next fire epoch for a recurrence, or null for a one-shot (which clears). */
function scr_advance(int $at, string $repeat, int $now): ?int {
    $step = $repeat === 'daily' ? 86400 : ($repeat === 'weekly' ? 604800 : 0);
    if ($step <= 0) return null;
    // Advance past now so a late tick never double-fires within one period.
    do { $at += $step; } while ($at <= $now);
    return $at;
}

try {
    if (!method_exists(ConfigService::class, 'getScheduledContinueMap')) {
        exit(0);
    }
    $map = ConfigService::getScheduledContinueMap();
    if (!is_array($map) || $map === []) {
        exit(0);
    }
    $now = time();

    // Resolve session id -> {path, agentId} from the workspace records once.
    $ws = ConfigService::getWorkspaces();
    $byId = [];
    foreach (($ws['sessions'] ?? []) as $s) {
        $sid = (string)($s['id'] ?? '');
        if ($sid !== '') $byId[$sid] = $s;
    }

    foreach ($map as $sid => $entries) {
        $sid = (string)$sid;
        if ($sid === '' || !is_array($entries)) continue;
        $entries = ConfigService::normalizeScheduledEntries($entries, $sid);
        // Oldest first: the entry that was due first is typed first.
        usort($entries, static fn(array $a, array $b): int =>
            ((int)$a['at'] <=> (int)$b['at']) ?: ((int)($a['created_at'] ?? 0) <=> (int)($b['created_at'] ?? 0)));
        $typedThisTick = false;   // one prompt per workspace per tick
        $launchedThisTick = false;

        foreach ($entries as $entry) {
            $entryId = (string)$entry['id'];
            $at = (int)($entry['at'] ?? 0);
            $repeat = (string)($entry['repeat'] ?? 'none');
            $lastFired = (int)($entry['last_fired_at'] ?? 0);
            if ($at <= 0) continue;
            if ($at > $now) continue;                 // not due yet
            if ($lastFired >= $at) continue;          // already fired this occurrence

            // Stale (missed while down): drop/advance without firing.
            if ($now - $at > STALE_S) {
                $next = scr_advance($at, $repeat, $now);
                if ($next === null) ConfigService::clearScheduledContinue($sid, $entryId);
                else ConfigService::replaceScheduledContinue($sid, $entryId, array_merge($entry, ['at' => $next]));
                // ACTIVITY_TRAY_PLAIN_NOTICES.md row 42: the title says what happened, the
                // grey line names the workspace (by name, never the id when a name exists)
                // and why. A finished record, not a job.
                $staleName = trim((string)($byId[$sid]['name'] ?? '')) !== '' ? (string)$byId[$sid]['name'] : $sid;
                @ActivityService::register("sched_cont_stale_$sid", 'system', 'Scheduled continue skipped', ['meta' => ['kind' => 'record', 'workspaceId' => $sid]]);
                @ActivityService::finish("sched_cont_stale_$sid", "$staleName · missed by more than 6 hours while the plugin was off");
                continue;
            }

            $rec = $byId[$sid] ?? null;
            if ($rec === null) {                      // workspace gone — drop every schedule of it
                ConfigService::clearScheduledContinue($sid);
                break;
            }
            $agentId = (string)($rec['agentId'] ?? '');
            $path    = (string)($rec['path'] ?? '');
            if ($agentId === '') { ConfigService::clearScheduledContinue($sid); break; }

            $running = ProcessManager::isRunning($sid);
            $name = trim((string)($rec['name'] ?? '')) !== '' ? (string)$rec['name'] : $sid;

            // TRANSIENT_ERROR_AUTO_CONTINUE.md (#312): an automatic continue after a
            // temporary model error. It is only for a pane that still shows the SAME
            // error as its newest output, so check again at send time, and never
            // launch a closed workspace for it.
            if (($entry['source'] ?? '') === TransientErrorService::SOURCE) {
                if (!$running) {
                    ConfigService::clearScheduledContinue($sid, $entryId);
                    TransientErrorService::recordDropped($sid, $name, $agentId);
                    continue;
                }
                $decision = TransientErrorService::sendDecision($agentId, TmuxService::capturePaneRaw($agentId, $sid), $entry, $now);
                if ($decision === 'clear') {
                    ConfigService::clearScheduledContinue($sid, $entryId);
                    TransientErrorService::recordDropped($sid, $name, $agentId);
                    continue;
                }
                if ($decision !== 'send') continue;                       // working or retrying: next tick
                if ($typedThisTick) continue;                             // one prompt per workspace per tick
                $gate = TmuxService::paneAcceptsInput($agentId, $sid);   // the same readiness gate as every Continue
                // #320: a plugin prompt typed earlier but unsent (its Enter did not take, or
                // the process died between paste and Enter) is finished with Enter only, by
                // submitContinueNudge() — never a second paste after it.
                $ownUnsent = ($gate['reason'] ?? '') === 'own-notice-unsent';
                if (empty($gate['ready']) && !$ownUnsent) continue;
                $res = $ownUnsent
                    ? TmuxService::submitContinueNudge($agentId, $sid)
                    : TmuxService::pasteAndConfirm($agentId, $sid, TransientErrorService::continuePrompt((string)($entry['error_summary'] ?? '')));
                if (($res['status'] ?? '') === 'ok') {
                    ConfigService::clearScheduledContinue($sid, $entryId);
                    TransientErrorService::recordSent($sid, $name, $agentId, $entry);
                    $typedThisTick = true;
                }
                continue;
            }

            // SCHEDULED_CONTINUE.md (2026-09-26): an OpenCode/Kilo usage-quota
            // schedule. OpenCode retries by itself after the wait it prints, so
            // check the pane again at send time: hold while its own "[retry in …]"
            // wait shows, drop when the task already resumed (or the workspace is
            // closed, which loses the stopped task), and type only when the quota
            // line is still the newest output.
            if (QuotaDetectionService::hasSendDecision($agentId, $entry)) {
                $decision = $running
                    ? QuotaDetectionService::sendDecision($agentId, TmuxService::capturePaneRaw($agentId, $sid), $entry, $now)
                    : 'clear';
                if ($decision === 'clear') {
                    ConfigService::clearScheduledContinue($sid, $entryId);
                    @ActivityService::register("sched_cont_quota_$sid", 'system', 'Scheduled continue dropped', ['meta' => ['kind' => 'record', 'workspaceId' => $sid]]);
                    @ActivityService::finish("sched_cont_quota_$sid", "$name · the agent resumed by itself");
                    continue;
                }
                if ($decision !== 'send') continue;                       // its own retry wait: next tick
            }

            if (!$running) {
                // Closed: launch+resume only if this agent's auto-launch is on. The
                // Continue itself waits for a later tick (pane not ready yet), so we
                // do NOT advance last_fired_at here — the schedule stays due.
                if (!$launchedThisTick) {
                    $al = ConfigService::getAgentAutoLaunch($agentId);
                    if (!empty($al['autoLaunch'])) {
                        @TerminalService::startTerminal($sid, $path, 'auto', $agentId, 'scheduled_continue');
                    }
                    $launchedThisTick = true;
                }
                continue;
            }

            // #831: a second due entry of this workspace waits for the next tick.
            if ($typedThisTick) continue;

            // Running: readiness-gated Continue, exactly like the menu path.
            $gate = TmuxService::paneAcceptsInput($agentId, $sid);
            $ownUnsent = ($gate['reason'] ?? '') === 'own-notice-unsent';
            // #320: submitContinueNudge() finishes our own unsent prompt with Enter only.
            if (empty($gate['ready']) && !$ownUnsent) {
                // Busy / mid-prompt — try again next tick; do not consume the occurrence.
                continue;
            }
            // #831: an entry with a reason (or an operator message) types its own text.
            $text = ConfigService::scheduledContinueText($entry);
            $res = ($text === null || $ownUnsent)
                ? TmuxService::submitContinueNudge($agentId, $sid)
                : TmuxService::pasteAndConfirm($agentId, $sid, $text);
            $ok = (($res['status'] ?? '') === 'ok');

            // Consume this occurrence whether the nudge was accepted or the pane just
            // turned busy at the last moment (ok/deferred both count as "fired"); a
            // hard error leaves it to retry next tick.
            if ($ok || ($res['status'] ?? '') === 'busy') {
                $typedThisTick = true;
                $next = scr_advance($at, $repeat, $now);
                if ($next === null) {
                    ConfigService::clearScheduledContinue($sid, $entryId);
                } else {
                    ConfigService::replaceScheduledContinue($sid, $entryId, array_merge($entry, ['at' => $next, 'last_fired_at' => $now]));
                }
                $why = trim((string)($entry['reason'] ?? ''));
                @ActivityService::register("sched_cont_$sid", 'system', 'Scheduled continue sent', ['meta' => ['kind' => 'record', 'workspaceId' => $sid]]);
                @ActivityService::finish("sched_cont_$sid", $why !== '' ? "$name · $why" : $name);
            }
        }
    }
} catch (\Throwable $e) {
    // best-effort tick — never disturb the supervisor loop
}
exit(0);
