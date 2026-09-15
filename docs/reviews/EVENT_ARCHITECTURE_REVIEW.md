# Review: Where the plugin should move from polling to events, and where it should not

## Status
Complete — 2026-09-11. Review only. No code was changed. Every claim below was
checked at the call site named beside it. Line numbers are from commit
`6632224b` (v2026.09.10.03). Paths are relative to `unraid-plg-aicliagents/`.

## Summary

The plugin already has the transport it needs. nchan is installed, wired, and
used by eight publishers and nine subscribers. The problem is not a missing
transport. The problem is that **the browser poll is load-bearing for server
state**: three read endpoints do server work as a side effect of being polled,
the activity watchdog only advances when someone polls it, and the supervisor,
the one process that can see storage change, never publishes to nchan at all.

The honest target is **event-first with reconciliation**, not "fully
event-based". PHP is request-scoped, nchan is best-effort with a one-message
buffer, and a browser can miss a message. Every fact that reaches the UI by push
needs a slow reconciling read behind it. The cadence of that read should be
30 to 60 seconds, not 5. Today ten polls run at 5 seconds or faster.

Top findings, in the order to act on them:

1. **Reads drive state.** `get_sessions_running` delivers Relay mail
   (`src/includes/handlers/TerminalHandler.php:87`). `list_activities` runs the
   stall/timeout watchdog (`src/includes/services/ActivityService.php:375-409`).
   `list_active_installs` deletes stale markers (`src/includes/handlers/AgentHandler.php:114-168`).
   If no tab is open, none of that happens. Move the work to the supervisor tick.
2. **The supervisor never publishes.** It completes every bake, consolidate,
   mount and activation, then writes a JSON file that the browser discovers at
   5 or 30 seconds. `C4-Documentation/c4-container.md:294-298` says it publishes
   `storage_status`. The code does not (`src/scripts/supervisor/aicli-supervisor.sh`,
   no `curl`/`nchan` call outside `queue_helpers.sh:41-49`, which only covers
   user-initiated jobs via a PHP child).
3. **Publish failures are invisible.** `NchanService::publish` discards the curl
   result and swallows every throwable with no log line
   (`src/includes/services/NchanService.php:37-41`). A broken socket looks
   exactly like a healthy one. Health has no check for it.
4. **The 30-second registry poll never fires.** A 10-second adapter clears and
   recreates the 30-second interval before it can elapse
   (`ui-build/src/components/AICliAgentsTerminal.tsx:857-864`). Confirmed by
   reading the effect. When storage is available, this poll runs once at load
   and never again. See Defects, D1.
5. **Every push subscriber goes deaf for 2 to 3 seconds after connect** to
   avoid nchan's replay of the last buffered message
   (`AICliAgentsTerminal.tsx:879,901,920`). A deploy or storage event inside that
   window is dropped and only the poll recovers it. Replace the timer with a
   payload timestamp.

The rest of the document gives the map, the ranked list, the argument for what
must stay a poll, the reliability contract, the cost of going further, and a
phased plan with the specs each phase needs.

---

## 1. Transport facts that shape every decision

These were read from the live nginx config on this box and the publish helper.
They are not in any spec, and two design choices in the code exist only because
of them.

| Fact | Where | Consequence |
| :-- | :-- | :-- |
| Publisher location `/pub/(.*)` sets `nchan_message_buffer_length $arg_buffer_length` and `nchan_message_timeout 0`. | `/etc/nginx/conf.d/servers.conf:13-17` (Unraid's, not the plugin's) | The publisher chooses the buffer depth per request. Buffered messages never expire by time. |
| Every plugin publisher sends `?buffer_length=1`. | `src/includes/services/NchanService.php:27`; `src/event/stopping:96`; `src/scripts/installer/generation.sh:144` | nchan keeps exactly the last message per channel. |
| Subscriber location `/sub/(.*)` has no `nchan_subscriber_first_message` override. nchan's default is `oldest`. | `/etc/nginx/conf.d/locations.conf:43-49` | A new subscriber receives the last buffered message at once. This is why every `EventSource` in the SPA ignores messages for its first 2 to 3 seconds. |
| Subscriber location sets `nchan_channel_id_split_delimiter ","`. | same | One `EventSource('/sub/a,b,c')` can carry several channels. The SPA opens three separate ones today, the tray a fourth. |
| `EventSource` auto-reconnects and sends `Last-Event-ID`. nchan resumes from that id, but only from what is still buffered. | browser + nchan behaviour | With `buffer_length=1`, a reconnect recovers at most the last message. Two publishes during a disconnect lose one. |
| `NchanService::publish` is fire-and-forget: 1 s connect, 2 s total, result discarded, exceptions swallowed, nothing logged. | `NchanService.php:21-42` | No publisher can know it failed. No health check can count failures. |
| Background tabs throttle timers, not network events. An `EventSource` in a hidden tab still receives messages. | browser behaviour | Push improves a background tab. Polls get worse in one. The server-side drain tick is needed for a different reason (section 4). |
| The plugin installs no nchan config. Only the ttyd proxy location. | `src/includes/services/ConfigService.php:396-430` | Buffer depth and first-message policy can only be changed per publish request, never in nginx. |

---

## 2. Component interaction map

Legend for the mechanism column: **nchan** = `NchanService::publish` or a shell
`curl` to `/pub/`; **poll** = browser `setInterval` to an AJAX action; **tick** =
supervisor `_work_tick` (5 s) or `_relay_drain_tick` (30 s rate-limited);
**signal** = `SIGUSR1` via `SupervisorService::wake()`; **file** = a marker or
JSON on tmpfs read by another process; **pm** = `postMessage`; **bc** =
`BroadcastChannel`; **ce** = window `CustomEvent`.

### 2.1 Facts and their routes

| Fact | Producer | Consumer | Route(s) | Duplicate or missing route |
| :-- | :-- | :-- | :-- | :-- |
| Activity entry changed (install, upgrade, storage job, start, proposal) | `ActivityService::save()` → nchan `aicli_activity` (`ActivityService.php:412-424`) | `src/assets/ui/activity-tray.js:219` | nchan + poll `list_activities` 5/10/30 s (`activity-tray.js:278`) | Duplicate by design. Poll is also the watchdog driver (see 2.2). |
| Activity entry removed | `ActivityService::dismiss()` `{opId,dismissed:true}` (`:314`) | tray `_merge` (`activity-tray.js:237`) | nchan + poll | OK. |
| Relay message waiting for a workspace | none. Projected at list time by `ActivityHandler::relayWaitingActivities()` (`src/includes/handlers/ActivityHandler.php:84-134`) from the pending dir | tray | poll only (10 s while a pill shows) | **Missing publisher** for arrival. Removal now published by `TmuxService::publishRelayWaitingCleared()` (`src/includes/services/TmuxService.php:1167`). Arrival still waits for the poll. |
| Relay message delivered | `TmuxService::drainPendingRelay()` (`:1138-1143`) | tray | nchan `{opId,dismissed:true}` + poll | OK since 2026-09-11. |
| Storage availability flipped (array start/stop, emergency) | `src/event/stopping:96`, `src/event/stopping_array:44`, `src/event/disks_mounted:121`, `InitService.php:253`, `StorageHandler.php:50` | SPA `AICliAgentsTerminal.tsx:876` (full reload), Manager `src/includes/ui/NchanSubscribers.php:21` (DOM patch) | nchan `aicli_storage_status` + SPA `debug` poll (dead, D1) + Manager `get_storage_status` poll 5 s | Same channel, two different reactions. The SPA reload is drastic. |
| Storage figures (homes, rootfs, artefacts) after a **user** storage action | `StorageHandler::handle()` tail (`:50`) | Manager | nchan + poll 5 s | OK. |
| Storage figures after a **supervisor** bake/consolidate/mount/graduate | supervisor `_job_finalize` writes the ledger only (`aicli-supervisor.sh:1782`) | Manager, SPA | poll only (5 s Manager, 30 s SPA dead) | **Missing publisher.** C4 says it exists. The only push is `_job_activity_push` for `user_*` jobs (`queue_helpers.sh:41-49`), and that carries an activity entry, not storage figures. |
| Home consolidate in progress / force-reclaim waiting | supervisor escalation file (`aicli-supervisor.sh:2618,2650`), `ConsolidateState::markHomeConsolidating` (`StorageHandler.php:656`) | SPA `get_force_reclaim_state` 5 s (`AICliAgentsTerminal.tsx:562`), Manager `pollConsolidate` 5 s (`ManagerStorageScripts.php:1506`) | poll only, two pollers | **Missing publisher.** Handler is annotated "read-only, no Nchan publish" (`StorageHandler.php:196`). |
| Storage job state (cold-home mount for `start`) | supervisor ledger `…/supervisor/jobs/<id>.json` | SPA `storage_job_status` 2 s (`AICliAgentsTerminal.tsx:1185`) | poll 2 s + tray push for `user_*` jobs only | Mount jobs from `start` are not `user_*`, so no push. |
| Install/upgrade progress for one agent | `UtilityService::setInstallStatus` → nchan `aicli_install_<id>` (`UtilityService.php:171`) | Manager `NchanSubscribers.php:67`; SPA emergency flow polls `get_install_status` 2 s (`AICliAgentsTerminal.tsx:1963`) | nchan + poll 5 s (1 s if subscribe failed) on Manager; poll only on SPA | SPA never subscribes to the channel it could use. |
| Install started / finished (cross-tab) | Manager `_broadcastInstall` (`ManagerStoreScripts.php:415`) | SPA `AICliAgentsTerminal.tsx:690` | bc + poll `list_active_installs` 2/8 s (`:660`) | bc is same-browser only. A queued upgrade started by the supervisor tick never broadcasts. The poll is the real route. |
| New plugin generation active | `generation.sh:144` → nchan `aicli_deploy` | SPA `:899` | nchan + poll `get_asset_version` 45 s (`:190`) | OK in principle. Poll has no leading call and never stops (D5). |
| Workspace started | `TerminalService::startTerminal` → nchan `aicli_workspaces` (`TerminalService.php:371`) | SPA `:918` (additive merge), Relay tab poll 5 s (`ManagerRelayScripts.php:470`) | nchan + Relay-tab poll | **Missing:** no `stopped` event. The SPA merge never removes a session. |
| Workspace running / stopped / stale / orphan | `TerminalHandler::getSessionsRunning` (`:71`) | Drawer `DrawerPanel.tsx:507` 5 s, drawer open only | poll only | **Missing publisher** for stop, exit, bridge refresh. The read also drains Relay (2.2). |
| Active session's terminal generation and chat id | `TerminalHandler::getSessionStatus` (`:1252`) | SPA `:1057` 5 s | poll only | Generation changes only on bridge respawn, which is a server action. Chat id is scraped, no event. |
| Registry, config, agent versions | `UtilityHandler::debug` | SPA `debug` (`:857`) | poll 30 s, **dead** (D1) | Install completion could trigger a refetch. Nothing does. |
| Health | `HealthService::get()` cache 60 s, cron `*/30` | Manager chip `ManagerHealthScripts.php:48` 60 s | poll | Acceptable (section 4). |
| Debug log tail | `TerminalHandler::getLog` | Manager `ManagerScripts.php:298` 5 s even when the tab is hidden | poll | Low value to push. Gate on visibility. |
| Supervisor job queued (bake, consolidate, mount, activation) | PHP `SupervisorService::enqueue` → `.req` file | supervisor `queue_pop_next` | file + tick 5 s; signal for user consolidate, session close, upgrade queue/activation | OK. The wake seam covers the paths that matter. |
| Deferred consolidate may resume | session close → `wake()` (`TerminalHandler.php:473,648`) | supervisor `_check_deferred_consolidate_resume` | signal + tick | OK. |
| Relay mail pending for a live session | `TmuxService::enqueuePendingRelay` (`:1091`) writes the queue file | drawer poll (`TerminalHandler.php:87`), supervisor `_relay_drain_tick` ≥30 s (`aicli-supervisor.sh:2788`), tray projection | file + poll + tick | **No wake.** Enqueue does not signal the supervisor. Worst case 30 s plus pane readiness. |
| Graceful close requested | PHP writes `close-<id>.flag` then sends Enter | `aicli-shell.sh:900,1408` | file + keystroke | OK. Ordering bug already fixed and commented. |
| ttyd ready / websocket open / escape / paste / drag | injected iframe script | SPA `handleMessage` (`:1319`) | pm | OK. No `event.origin`/`event.source` check (O3). |
| Cold-start step text | tray `_emit` (`activity-tray.js:305`) | SPA `:236` | ce `aicli-activity-change` | OK. Deliberately avoids a second `EventSource`. |
| Client-only auto-launch failure | SPA `:820` | tray `:174` | ce `aicli-activity-local` | OK. |

### 2.2 Reads that do work (the pattern behind the 2026-09-10 failure)

| Endpoint | Side effect on read | Who depends on it | What happens with no tab open |
| :-- | :-- | :-- | :-- |
| `get_sessions_running` (`TerminalHandler.php:87`) | `TmuxService::drainPendingRelay()` per running session | Relay delivery latency | Delivery waits for the 30 s supervisor tick. Acceptable, but the read should not do it at all. |
| `list_activities` (`ActivityHandler.php:58-75`) | `SupervisorService::syncJobActivities()`; `ActivityService::listAll()` runs the watchdog: running → stalled after 120 s, → failed at hard cap | Stall and timeout detection; orphan-job finish; `done` pruning | **No transition happens.** A hung install is never marked stalled. The spec accepts this ("the tray guarantees evaluation pressure", `docs/specs/ACTIVITY_TRAY.md`). It should not. |
| `list_active_installs` (`AgentHandler.php:114-168`) | unlinks install markers older than 180 s | `activationBlocked()` correctness (`UpgradeRelaunchService.php:81`), which reads the same marker | Stale markers persist until a tab polls. |
| `get_supervisor_status` (`StorageHandler.php:130`) | `du -sb` of every ZRAM upper | none | Cost only. |
| `health_status` | recompute on cache miss | cron also does this | Fine. |

The relay pill incident was one instance of this shape: derived state, no
publisher, and a poll cadence chosen by a status set that did not include the
derived status. The same shape recurs in every row of 2.1 marked "poll only".

---

## 3. Defects found during the review

Reported, not fixed. Each needs a spec update before a fix (section 8).

| # | Defect | Evidence | Effect |
| :-- | :-- | :-- | :-- |
| D1 | The 30 s `debug` poll is starved. `adaptPoll` runs every 10 s and does `clearInterval(regTimer); regTimer = setInterval(fetchRegistry, interval)`. At 30 s the interval is reset every 10 s and never elapses. At 5 s (storage down) it fires twice per reset. | `ui-build/src/components/AICliAgentsTerminal.tsx:855-864`, read in full | With storage available, registry/config/agent-version state in the SPA is loaded once and never refreshed. The storage available→unavailable transition is detected only by nchan; if that message is missed the tab never notices. |
| D2 | `migrate-btrfs-to-squashfs.sh` publishes to `http://localhost/pub/aicli_migration` over TCP. The `/pub/` location exists only on the unix socket server. The `.plg` changelog line 1275 records that this endpoint does not exist. | `src/scripts/installer/migrate-btrfs-to-squashfs.sh:44`; `/etc/nginx/conf.d/servers.conf:4-18` | The Manager's `/sub/aicli_migration` subscriber (`NchanSubscribers.php:44`) never receives a message. Migration progress reaches the UI only via the 2 s `get_storage_status` poll. Silent because of `|| true`. |
| D3 | `NchanService::publish` logs nothing on failure and never inspects the HTTP status. | `NchanService.php:37-41` | A dead socket, a 4xx from nginx, or a timeout is indistinguishable from success. No metric, no health check. |
| D4 | The tray's `_esBroken` flag is set on `onerror` and cleared only in `onmessage`. There is no `onopen` handler. | `src/assets/ui/activity-tray.js:216-232` | After one transient error the tray polls at 5 s until the next real message arrives, even though the stream reconnected. On an idle server that is indefinitely. |
| D5 | The 45 s asset-version poll has no leading call and does not stop after detection, contrary to its comment. | `AICliAgentsTerminal.tsx:167,190` | First check is at T+45 s. After detection it keeps fetching every 45 s for the life of the tab. Cosmetic cost. |
| D6 | The tray `ACTIVE` set is `{running, stalled}`. `pending_approval` (a proposal awaiting a human) and `failed` sit on the 30 s idle cadence. `relay_waiting` was special-cased after the 2026-09-10 incident; `pending_approval` was not. | `activity-tray.js:136,277` | A proposal approved or rejected from another tab shows for up to 30 s in this one if the push is missed. Same class as the pill bug. |
| D7 | `_check_wants_bake_flags` scans a directory no writer populates. `gracefulClose` removed the writer. | `aicli-supervisor.sh:2314-2357`; `TerminalHandler.php:630-636` | Dead code on every tick. Confuses the map. |
| D8 | C4 container doc claims the supervisor publishes `storage_status` after each bake/consolidate. It does not. | `C4-Documentation/c4-container.md:294-298` vs `aicli-supervisor.sh` (no publisher) | Anyone designing from the C4 map believes a push exists that does not. |
| D9 | Two migration channels with different publishers and subscribers: `aicli_migrate_progress` (PHP path migration ↔ `ManagerScripts.php:84`) and `aicli_migration` (shell Btrfs migration ↔ `NchanSubscribers.php:44`). | survey | Not a bug on its own, but D2 means one of the pair is dead and the split hides it. |
| D11 | The terminal page reloads on **every** `aicli_storage_status` message, without reading the payload. `StorageHandler::handle()` publishes the full status after every mutating storage action. So pressing Persist, Consolidate, Expand or Repair on the Manager reloads every open terminal tab on every device. Only the 3 s warm-up hides the replay on connect. | `AICliAgentsTerminal.tsx:880-885` (`onmessage` ignores `e.data`); `StorageHandler.php:50` | Today: a Manager storage click yanks every terminal tab. Tomorrow: rank 2 adds supervisor publishes on every bake, which would reload every tab several times an hour. **Ordering constraint:** the SPA must compare `home_available`, `agents_available` and `emergency_mode` against its last-known values and reload only on a change, before any new publisher is added to this channel. See section 5a. |
| D10 | `NchanService::publishStorageProgress` and `publishSessionStatus` have no callers. No subscriber exists for `aicli_storage_*` or `aicli_status_*`. C4 lists `aicli_session_{user}` as a live channel. | `NchanService.php:61,75`; `c4-container.md:146-155` | Dead API and stale doc. |

Observations that are not defects but matter to the design:

| # | Observation | Evidence |
| :-- | :-- | :-- |
| O1 | The premise "the supervisor is the only long-running process" is not exact. The Relay HTTP listener is a persistent PHP process: pid 49222, up 2 days 13 h, 38 MB RSS, pinned to generation `2026.09.07.02` while `2026.09.10.03` is active. The pinning is intentional (`src/scripts/relay-http-up.sh:20`). | `ps` on this box, 2026-09-11 |
| O2 | The per-agent relay `watch` mode (an `inotifywait` watcher inside the agent's process tree) was retired on 2026-08-22 because it died on every resume and duplicated the paste. The failure was lifetime coupling to the agent tree, not inotify. `inotifywait` is present on Unraid (`/usr/bin/inotifywait`). PHP has `pcntl`, `posix`, `sockets`, but no `inotify` extension. | `docs/specs/RELAY_DELIVERY_MODES.md`; `which` on this box |
| O3 | The SPA `message` listener accepts any origin. The ttyd iframe is same-origin through the nginx proxy, so `event.source === iframe.contentWindow` would be a cheap guard. | `AICliAgentsTerminal.tsx:1327` |
| O4 | `ArrayStopWarning.page` runs a 5 s interval on **every** Unraid WebGUI page for the life of each tab, to re-wrap `window.stopArray`. It is DOM work, not a data poll, but it is the plugin's most widely deployed timer. | `src/ArrayStopWarning.page:161` |
| O5 | The SPA and the tray open four `EventSource` connections per tab. On HTTP/1.1 each is a held connection against the browser's per-host limit of six, beside ttyd websockets and AJAX. On HTTP/2 this does not apply. The comma-split channel id makes one connection possible. | `locations.conf:48` |

---

## 4. Ranked list: polling that should become events

Rank is by benefit to the user divided by risk. "Reconcile" is the slow read
that must remain behind the event; section 6 argues the cadence.

| Rank | What is polled | Cost today | Event that replaces it | Publisher that must exist | Risk if the event is missed | Reconcile |
| :-- | :-- | :-- | :-- | :-- | :-- | :-- |
| 1 | **Activity watchdog** via `list_activities` (5/10/30 s from every open tab) | The state machine only moves when a browser asks. Two tabs double the work. No tab means no stall detection. | Not a UI event. Move `ActivityService::listAll()`'s evaluation into a supervisor tick (every 30 s, via the existing `sync-activity.php` child). Transitions publish on `aicli_activity` as they do now. | Supervisor tick calling `ActivityService::sweep()` (new) | None new. The tray still polls, but as a reconciler, not an engine. | Tray poll 60 s idle, 30 s while anything is not `done`. Drop the 10 s branch. |
| 2 | **Storage figures and job completion** (Manager `get_storage_status` 5 s, SPA `storage_job_status` 2 s) after supervisor-driven bake/consolidate/mount/graduate | Every Manager tab hits a `du`-backed projection every 5 s forever. Completion shows up to 5 s late. | `aicli_storage_status` with the existing payload, plus `aicli_activity` for every tracked job, not only `user_*` | `_job_finalize` in `aicli-supervisor.sh` calls a bash `nchan_notify` (copy the helper from `src/event/stopping:96`, unix socket, `--max-time 2`). Widen `_job_activity_push` to all ledger transitions. | Figures stale until reconcile. No data risk. | Manager 30 s. SPA `storage_job_status` keeps 2 s only while a `start` is blocked on a mount job (bounded by `capMs`). |
| 3 | **Force-reclaim / consolidate-in-progress** (`get_force_reclaim_state`, two pollers at 5 s) | Two timers per tab pair, 5 s each, for a state that changes a few times a day. | New payload on `aicli_storage_status`: `{maintenance:{state, reason, consolidating}}`, or a dedicated `aicli_maintenance` channel | Supervisor at escalation file write/delete (`:2618,2650`) and `_clear_home_consolidating` (`:2514`); `StorageHandler::consolidate` at `markHomeConsolidating` (`:656`) | A missed clear leaves the banner up and storage actions blocked until reconcile. A missed set lets the user press a button the handler will refuse anyway. | 30 s while a session is open. Manager same. |
| 4 | **Workspace lifecycle** (`get_sessions_running` 5 s while drawer open; Relay tab 5 s; no route at all for a row created, renamed or removed by another device or by an agent tool) | Dot and stale badge lag 5 s. Nothing at all while the drawer is closed. A workspace created by an agent or closed on another device is invisible until reload. The read drains Relay as a side effect. | `aicli_workspaces` gains `{event:'created'|'updated'|'removed'|'stopped'|'exited'|'bridge', id, agentId, generation, ts}` beside `started` | The writers, so every caller announces alike: `ConfigService::saveWorkspaces` (created/updated/removed, used by the drawer and by `AdminService`), `TerminalService::stopAICliTerminal`, `gracefulClose`, `agent-exit-recorder.sh` via `log-bridge.php`, `restartTerminalBridge`, `sweepOrphanSessions` | Dot wrong until reconcile. `auto_continue` on stopped→running is the one behaviour that must not fire twice; key it on `ts`. | Drawer 30 s while open. Remove the drain from the read (rank 6 covers it). |
| 5 | **Install active set** (`list_active_installs` 2 s during install, 8 s idle, always on) | 8 s idle poll from every SPA tab forever; 2 s during any install. The BroadcastChannel is same-browser only and misses supervisor-started upgrades. | The SPA subscribes to `aicli_install_<id>` for each installed agent through the multiplexed `EventSource` (section 5). `install-complete` is `progress >= 100`. | Exists (`UtilityService::setInstallStatus`). Nothing new. | iframe not remounted onto the new binary until reconcile. Same as today's 8 s window, just longer. | 30 s. Keep the `visibilitychange` immediate tick. |
| 6 | **Relay drain trigger** (drawer poll 5 s, supervisor tick ≥30 s) | A message enqueued at T waits for the next drawer poll or up to 30 s for the supervisor. | `SupervisorService::wake()` from `enqueuePendingRelay`, and a `_relay_drain_tick` that runs at the 5 s tick while any queue file exists, 30 s otherwise | `TmuxService::enqueuePendingRelay` (`:1091`) | A missed wake costs one tick. | The tick is the reconcile. Pane readiness is still polled (section 5). |
| 7 | **Relay pill arrival** (tray poll, 10 s once a pill exists, 30 s before the first one) | The first pill after a quiet period waits up to 30 s. | `aicli_activity` entry `relay_waiting__<agent>__<sid>` published at enqueue time, same shape the projection builds today | `enqueuePendingRelay` publishes; `ActivityHandler::relayWaitingActivities` stays as the reconcile source | Pill late until reconcile. | Tray reconcile as rank 1. |
| 8 | **Terminal generation of the active session** (`get_session_status` 5 s) | 5 s per active session per tab. Generation changes only when the server respawns ttyd. | `aicli_workspaces {event:'bridge'}` from rank 4 | as rank 4 | iframe keeps the old bridge until reconcile. | 30 s. Chat id has no event; it rides the same reconcile. |
| 9 | **Deploy detection** (`get_asset_version` 45 s) | Already pushed. Poll is the reconcile. | none | none | none | Fix D5. 60 s reconcile is fine. Replace the 3 s deafness with a `ts` guard. |
| 10 | **Registry/config** (`debug` 30 s, dead) | See D1. | Refetch on `aicli_install_<id>` completion and `aicli_deploy`. | none new | Stale agent list until reconcile. | 60 s, after fixing D1. |
| 11 | **Health chip** (60 s) | Fine. | Optional: `healthcheck.php` publishes `aicli_health` on state change. | cron script | none | Keep 60 s. Low value; do it only when the multiplexed stream exists. |
| 12 | **Relay tab owner table** (5 s while visible) | Fine while visible. | rank 4 covers it. | as rank 4 | none | 30 s. |

Not in the list because they should stay polls: section 5.

---

## 5. Where polling must stay, and why

Your three candidates, checked.

**tmux pane readiness.** Correct, with one refinement. There is no event for
"the agent is idle at a prompt". tmux 3.6a has `monitor-silence` and the
`alert-silence` hook, which fire after N seconds without pane output. That is
not the same fact: an agent that is thinking produces no output either, and the
structural input-box check would still be needed to tell the two apart. Silence
could become a cheap trigger for the capture, but the decision stays a capture
and a classifier, and a 400 ms stability re-capture on top. Keep the poll. Make
it run only while a queue file exists (rank 6), so its cost is zero when there
is nothing to deliver.

**External state the kernel will not notify us about.** Partly wrong. The kernel
notifies for two of the things the supervisor watches:

- Mount table changes: `poll()` on `/proc/self/mounts` returns `POLLPRI` when
  the table changes. systemd and udisks use it.
- File changes on tmpfs and on `/boot` (vfat): inotify works, and
  `inotifywait` is on the box. It is not reliable on the shfs FUSE mount, and
  the plugin must never watch `/mnt/user` anyway.

What the kernel does not give a bash process: the exit of a process that is
not its child (needs `pidfd`, not reachable from bash or PHP here), the byte
count of a dirty upper (a `du` is a sample by nature), and layer integrity
(a sweep by nature). So the reconcile pass, dirty-pressure check, schedule
check and health checks stay loops. The **queue pickup** could become inotify,
but the `SIGUSR1` wake already gives the same latency for every path that
matters, with no extra process. Not worth changing.

**A backgrounded browser tab.** Half right. Timers are throttled in a hidden
tab; an `EventSource` is not. So the drawer poll stalls in a hidden tab, and a
push would still arrive. The conclusion is right: the server drain tick must
exist. The reason is different and stronger: **Relay delivery is server work
and must never depend on a browser at all.** The drawer poll should not drain
anything (2.2). Once it does not, the drain tick is not a fallback, it is the
only path, woken by the enqueue (rank 6).

Polls that are correct as polls, beyond those three:

| Poll | Why it stays |
| :-- | :-- |
| Every reconcile read in section 4 | nchan is best-effort. A design where a missed message leaves the UI wrong is not acceptable. The reconcile is the correctness guarantee; the event is the latency win. |
| Activity stall detection (absence of heartbeat) | An absence has no event. It is a timer by definition. It belongs in the supervisor, not the browser (rank 1). |
| Health checks and version drift | Sampling external state. Cron plus a 60 s cache is the right shape. |
| Watchdog on a wedged child (`_watchdog_check_child`) | Same: an absence. |
| ttyd reconnect after websocket close (1.5 s, visible only) | Already an event (`close`), the timer is a debounce. Fine. |
| `osc52.ts` / `pathLinks.ts` 100 ms for 10 s | Bounded DOM readiness probes. Fine. |
| `storage_job_status` 2 s while `start` is blocked on a mount | Bounded, only during a cold start, and the user is looking at a spinner. Fine. |

---

## 5a. Reload versus patch on the terminal page: the history, and the rule

The terminal page's full reload on a storage event was a deliberate reversal,
made the day the feature shipped, and the reason still holds. Checked in git;
no Forgejo issue records it, the commit messages are the only record.

| Commit | Date | What it did |
| :-- | :-- | :-- |
| `bf392151` (v2026.04.15.64) | 2026-04-15 | First `EventSource('/sub/aicli_storage_status')`. The handler **patched React state**: `setStorageStatus`, `setStorageError`, `setEmergencyMode` from the payload. |
| `30447c05` (v2026.04.15.69) | same day, five versions later | Replaced the patch with `window.top.location.reload()` "instead of trying to update React state … so sessions, mounts, and UI reinitialize cleanly", and added the 3 s replay guard. |
| `756c1794` (v2026.04.15.73) | same day | Made the `debug` poll reload on an availability flip too, and called `InitService` "the reliable fallback"; event hooks "a bonus". |

Why the patch was not enough: an array stop is not a state change, it is a
world change. `src/event/stopping` captures resume ids, kills every ttyd and
tmux session, unmounts the home and agent overlays, and sets the emergency
flag. On the page that means every iframe is dead, every session is stopped,
the emergency-session branch appears (temporary home, emergency install), the
wizard and registry conditions change, and auto-launch pending must be
recomputed. The component has 22 conditions on storage availability. The April
patch updated three values. A reload re-runs the boot path and gets every
transition, in both directions, for free. That is the right call and this
review keeps it.

The rule that resolves item 5 is therefore not "patch instead of reload". It is
**reload on an availability flip, patch on everything else, and decide from
the payload, not from the arrival of a message.** Today the handler does not
read the payload at all (D11), so a Manager storage click reloads every
terminal tab, and any new publisher on the channel would multiply that. The
change is small: seed the page's last-known `{home_available, agents_available,
emergency_mode}` at load (the Manager already has `window.aicli_storage_available`),
compare on each message, reload only when one of the three changed, and
otherwise patch the figures. That also retires the 3 s warm-up, because the
replayed message on connect carries the same availability the page was
rendered with.

Risks of patching, stated so they are not rediscovered:

- **A partial patch is the pill bug in another coat.** State that reflects one
  fact while the world moved. Patch only what is cosmetic (figures, progress,
  maintenance banner); never patch a transition that changes which UI branch
  is shown.
- **Two routes must agree.** The `debug` poll (`:751`) and the nchan handler
  both decide to reload. Both must use the same comparison, or the UI depends
  on which route won the race. Put the comparison in one pure function,
  tested, the way `shouldReloadForDeploy` already is.
- **The channel carries two kinds of message.** Availability flips are rare
  and world-changing; figures and progress are frequent and cosmetic. Until
  the handler distinguishes them, every new publisher is a reload storm
  waiting to happen. Rank 2 is blocked on D11 for exactly this reason.
- **A reload during an emergency session is not free.** The emergency branch
  holds a temporary home; a reload re-derives it from the server, which is
  correct, but the operator loses scroll position and any half-typed input.
  That is the cost of correctness and is acceptable only for the rare flip.

---

## 6. Reliability: what each event needs behind it

Two rules, then the table.

**Rule 1: every push carries a timestamp and is idempotent.** Add `ts` (server
epoch, milliseconds) to every payload. A subscriber ignores a message whose `ts`
is older than the `ts` of the last reconcile snapshot it applied. Not the page
load time: a phone that was suspended for ten minutes must accept the replayed
last message when it is newer than what the phone last saw. This replaces the
2 to 3 second post-connect deafness, which drops real events. Every payload is a
full entry or a full snapshot, never a delta, so a replayed or duplicated
message is harmless.

**Rule 1a: reconcile on every reconnect and on every return to the foreground.**
`EventSource.onopen` and `visibilitychange` to `visible` each trigger one
immediate reconcile read. The buffer holds one message per channel, so a
reconnect after a long gap cannot replay what was missed; the read fills the
gap. This is the rule that makes a second device correct (section 10).

**Rule 2: every pushed fact has a reconcile read at 30 to 60 seconds, and the
read is the source of truth.** A poll response replaces the local state
wholesale, as the tray already does. Push patches between polls. That is the
whole contract. It is what the codebase already does for the tray; the change
is to apply it everywhere and to slow the polls down.

| Event | Buffer depth to publish with | Reconcile read | Cadence | Why that cadence |
| :-- | :-- | :-- | :-- | :-- |
| `aicli_activity` entries | 20 (`?buffer_length=20`), because it is an event log: a reconnect should replay a burst. Entries are keyed by `opId`, so replay is an upsert. Guard with `ts`. | `list_activities` | 60 s idle, 30 s while any entry is not `done` | The watchdog no longer needs the poll (rank 1). 30 s bounds a missed final state on a visible op. |
| `aicli_storage_status` snapshot | 1 (a snapshot; only the last matters) | `get_storage_status` (Manager), `debug` (SPA, after D1) | 30 s | Figures, not actions. |
| maintenance / force-reclaim | 1 | `get_force_reclaim_state` | 30 s while a session is open | A stale "clear" blocks buttons the handler refuses anyway; a stale "set" is a cosmetic banner. |
| `aicli_workspaces` started/stopped/bridge | 20, event log | `get_sessions_running` while drawer open; `get_workspaces` on SPA | 30 s | `auto_continue` must be keyed on the event `ts` so a replay cannot fire it twice. |
| `aicli_install_<id>` | 1 (progress; last value wins) | `list_active_installs`, `get_install_status` | 30 s idle; 5 s only while the Manager shows a progress bar | Marker deletion moves to the supervisor sweep. |
| `aicli_deploy` | 1 | `get_asset_version` | 60 s | Already pushed; poll is only insurance. |
| relay drain wake | n/a (signal) | supervisor tick | 5 s while queue files exist, 30 s otherwise | A lost signal costs one tick. |

Two transport improvements make every row cheaper and safer:

- **One multiplexed stream per page.** `EventSource('/sub/aicli_activity,aicli_storage_status,aicli_deploy,aicli_workspaces,aicli_install_x,…')`.
  nchan supports it (`nchan_channel_id_split_delimiter`). One `onopen`, one
  `onerror`, one `_esBroken`, one reconnect. The tray and the SPA share it
  through the existing `aicli-activity-change` pattern extended to a generic
  `aicli-event` CustomEvent. Fewer held connections (O5).
- **Publish observability.** `NchanService::publish` records failures (a counter
  file under `/tmp/unraid-aicliagents/`, one log line at WARN per distinct
  failure per minute). `HealthService` gains a `push` check: warn when failures
  in the last hour exceed a small number, fail when the socket is missing. Then
  a broken push is visible instead of silently degrading every tab to polling.

---

## 7. What a fully event-based version would cost

Not reachable with the current shape, and the reason is structural, not effort.

- **PHP is request-scoped.** A publisher exists only for the length of the
  request that changed the state. It cannot retry, sequence, or acknowledge.
  Every write path already publishes; that is as far as request-scoped PHP goes.
- **nchan is a relay, not a log.** It has no durable sequence the plugin
  controls, no ack, and the plugin cannot change its nginx config. A buffer of
  N messages with no time expiry is the most it offers.
- **The supervisor is bash.** It can `curl` a publish (the event scripts already
  do) and it can be woken, but it cannot host an event bus.

"Fully event-based" would mean: a persistent process that owns a durable event
log with monotonic sequence numbers, every state change written to that log
first and fanned out to nchan second, subscribers that track their last
sequence and ask for a gap replay instead of a periodic poll, and publishers
that go through the process rather than straight to nchan. The building blocks
exist: O1 shows a persistent PHP process is viable here (38 MB, days of uptime,
generation-pinned with an intentional hot-swap story). The cost is a third
daemon to supervise, pin, upgrade and health-check; a bus abstraction every
publisher must be rewired through; a client library that does gap replay; and
a new failure mode (the bus is down) that needs its own fallback, which is a
poll. The polls would not disappear. They would move into the client library
as "resync on gap".

The honest target is **event-first with reconciliation**: every fact the UI
shows has a publisher, the push carries `ts` and is idempotent, the reconcile
read is 30 to 60 s, and no read endpoint does server work. Sections 4 to 6
reach it without a new process. The only structural addition worth making is a
thin `EventBus` PHP facade in front of `NchanService::publish` (channel
registry, envelope with `ts`, failure counter, test seam) so that the day a
persistent process is justified, publishers do not change again.

---

## 8. Phased plan

### Phase 0: defects and observability (small, no design change)

Fix D1, D2, D3, D4, D5, D6, D7. Update the C4 container doc for D8 and D10.
Add the `ts` envelope and the publish failure counter. Add the health `push`
check. Each is a few lines and unit-testable. Do D3 first: until publish
failures are visible, nothing in Phase 1 can be verified honestly.

Specs: update `docs/specs/AUTO_RELOAD_ON_DEPLOY.md` (D1, D5, `ts` guard),
`docs/specs/ACTIVITY_TRAY.md` (D4, D6), `docs/specs/HEALTH_MONITORING.md`
(push check), `docs/specs/BOOT_STORAGE_CONSOLIDATION.md` or the migration spec
that owns `migrate-btrfs-to-squashfs.sh` (D2). New:
`docs/specs/EVENT_ENVELOPE_AND_PUBLISH_OBSERVABILITY.md`.

### Phase 1: reads stop doing work; the supervisor starts publishing (medium)

1. Move the activity watchdog into a 30 s supervisor sweep (rank 1). The
   `list_activities` path keeps calling `evaluate()` for now, so nothing
   regresses; the sweep just makes it unnecessary.
2. Supervisor publishes `aicli_storage_status` at `_job_finalize` and widens
   `_job_activity_push` to every tracked job (rank 2).
3. Maintenance state published from the supervisor escalation and consolidate
   marker paths (rank 3).
4. `aicli_workspaces` gains `stopped`, `exited`, `bridge` (ranks 4, 8). The SPA
   merge learns to remove and to replace a session's generation.
5. Relay: `enqueuePendingRelay` wakes the supervisor and publishes the pill
   entry; the drain tick runs at the tick rate while queues exist; the drain
   call is removed from `get_sessions_running` (ranks 6, 7).
6. Move stale-marker deletion from `list_active_installs` into the sweep.
7. Events for agents, pull only, outside the Relay (section 9): the event
   ledger as a tee on the publish path, with `seq`, `kind` and `actor`;
   `aicli_subscribe_events` and `aicli_get_events` on the admin MCP with CLI
   parity on `$AICLI_ADMIN_COMMAND`; a per-tab client id on every AJAX request
   so the actor audit can tell devices apart; `createdBy` on the workspace
   record. The args, env, auto-launch and channel writers publish their
   change, so the Settings pages, the ledger and the agent tools share one
   route. Spec: new `docs/specs/PLUGIN_EVENT_LEDGER_AND_SUBSCRIPTIONS.md`;
   update `docs/specs/PLUGIN_MANAGEMENT_TOOLS.md` (two tools, `createdBy`,
   hook pattern in the projected skill).
8. Slow every reconcile poll to the section 6 cadence. Do this last, after the
   publishers above are deployed and the failure counter reads zero for a day.

Order matters within the phase: publishers before poll slow-downs, always. Each
step ships on its own.

Specs: update `docs/specs/ACTIVITY_TRAY.md` (watchdog ownership, cadence),
`docs/specs/RELAY_WAITING_PILL.md` (arrival publish, drain trigger),
`docs/specs/DEPLOY_REACHES_OPEN_TABS.md` (bridge event replaces the 5 s
generation poll), `docs/specs/HOME_STORAGE_LIFECYCLE.md` (supervisor publish).
New: `docs/specs/EVENT_FIRST_RECONCILIATION.md` (the contract from section 6;
the rule that reads never drive state; the table of facts, publishers and
reconcile cadences, kept current as the source of truth). New:
`docs/specs/WORKSPACE_LIFECYCLE_EVENTS.md` (the `aicli_workspaces` event
vocabulary).

### Phase 2: one stream, one facade (larger, structural)

1. Single multiplexed `EventSource` per page with a generic `aicli-event`
   CustomEvent fan-out; the tray and the SPA both consume it. Per-channel
   `buffer_length` as in section 6.
2. `EventBus` PHP facade in front of `NchanService` with a channel registry and
   a unit-test seam; every publisher (PHP and the bash `nchan_notify` helper)
   goes through one code path. Regression guard: a channel name not in the
   registry fails CI, the way an agent without a Config Hub decision does.
3. The SPA subscribes to install channels instead of the BroadcastChannel and
   the 8 s poll (rank 5).
4. Health chip and Relay tab move onto the stream (ranks 11, 12).

Specs: new `docs/specs/EVENT_STREAM_MULTIPLEX.md`; update
`C4-Documentation/c4-container.md` Nchan interface sections and
`c4-component-react-terminal-spa.md`.

### Not worth doing

- inotify for the supervisor queue. The wake signal already gives ≤1 s.
- tmux hooks for pane readiness. The capture and classifier stay; a hook would
  only trigger them, and the queue-gated 5 s tick already bounds the wait.
- A persistent event daemon (section 7). Revisit only if a second consumer
  beyond the browser appears, for example agents wanting a push they can
  subscribe to without a paste.
- Pushing the debug log tail. Gate the 5 s `get_log` poll on tab visibility
  and stop there.
- Replacing the health cron with events. Health is a sample.

---

## 9. Several devices on the same server, tab and workspace

The operator uses a desktop and a phone, switches between them, and may act on
the same tray pill from either. The design above must make both devices show
the same result. Checked against the code, 2026-09-11:

### What already works across devices

| Fact | Why it works |
| :-- | :-- |
| Every nchan publish reaches every subscriber on every device. | nchan is server-side fan-out. A `BroadcastChannel` is not (same browser only), which is one more reason rank 5 replaces it with the install channel. |
| Approve, reject, cancel, dismiss of a persisted activity entry from either device. | The action runs on the server, the entry is rewritten or unlinked, and `{opId,…}` or `{opId,dismissed:true}` is published. The second device's tray patches itself. A second click on the other device is refused as already handled (`ActivityService::approve()` doc at `:224`; `reject()` at `:242`; `cancel()` returns "already finished" at `:329`). |
| Force inject from either device. | `deliver_relay_waiting` drains on the server and publishes the clear. A second force finds an empty queue and reports "nothing left". |
| A running/stopped/stale dot, install progress, deploy countdown. | All server facts, all reconciled per device. Two devices converge independently even when one misses a push. |
| Both devices attached to one workspace terminal. | tmux is the single session; ttyd accepts several websocket clients (`docs/specs/MULTI_CLIENT_TERMINAL.md`). A forced paste is visible on both. |

### What is per device today, and should not be

| Gap | Evidence | Fix |
| :-- | :-- | :-- |
| **Pill Dismiss is in-memory on one device.** Dismissing a `relay_waiting` pill on the phone leaves it on the desktop, and a reload brings it back on the phone. | `src/assets/ui/activity-tray.js:147,375,434` (`_relayDismissed` map, never sent to the server) | Once the pill is a published entry (rank 7), route Dismiss through `dismiss_activity` like every other entry: the server records `dismissedAtCount` beside the queue file and publishes `{opId,dismissed:true}`. A new arrival (count grows) re-raises it on every device, which is the spec's existing rule. |
| **Force-inject arming is per device.** Pressing "Go to workspace" on the desktop does not arm Force on the phone. | `activity-tray.js:153,443,475` (`_relayArmed`) | Keep this per device. The two-stage button is a safety: the device that forces must be the one whose operator just looked at the pane. Document it in `RELAY_WAITING_PILL.md`. |
| **"Not now" on the deploy banner is per page load.** | `AICliAgentsTerminal.tsx` `updateDismissed` | Keep per device. A reload is disruptive on the device the operator is using; the other device can reload on its own countdown. |
| **A suspended phone comes back stale.** Mobile browsers suspend a background tab entirely. On resume the `EventSource` reconnects and nchan replays only the last message per channel. The tray has no `visibilitychange` and no `onopen` handler, so it waits for its next timer (up to 30 s, and mobile timers are throttled). | `activity-tray.js` (no `visibilitychange`, no `onopen`); the SPA has `visibilitychange` only for `list_active_installs` (`:661`) and `get_session_status` (`:1058`) | Rule 1a in section 6: one immediate reconcile on `onopen` and on `visibilitychange` to `visible`, for the tray and the multiplexed stream alike. This is the single most important change for the phone. |
| **Active-workspace navigation.** "Go to workspace" drives a hash route on the clicking device only. | `docs/specs/RELAY_WAITING_PILL.md`, `ui-build/src/lib/workspaceRoute.ts` | Correct as is. Navigation is view state and stays per device. |
| **A workspace closed on one device stays listed on the other.** Close sends `graceful_close`/`stop` plus `save_workspaces` with `removed_ids`; the server tombstones the id for one hour (`src/includes/services/ConfigService.php:737-782`). Nothing is published. The `aicli_workspaces` channel carries only `started` (`TerminalService.php:371`) and the SPA subscriber only ever adds (`AICliAgentsTerminal.tsx:918-933`). The second device learns of the close only when it reloads, or when its own next save returns the server list without the row (`:993-998`). Its drawer dot turns grey within 5 s if the drawer is open; the row stays. If it is viewing that workspace, the iframe reconnects into a dead session. After the tombstone hour, a stale save from the second device re-adds the closed workspace. | confirmed 2026-09-11 | Rank 4: publish `{event:'closed', id, ts}` on `aicli_workspaces` from the close path, and teach the SPA merge to remove on `closed` and to replace on reconcile. Rule 1a covers a device that missed the event. Lengthen or remove the tombstone expiry once the close is pushed; a device that has heard the close will not resend the row. |
| **The active workspace is persisted as a server-wide fact.** Every `save_workspaces` sends `{sessions, activeId}` (`AICliAgentsTerminal.tsx:954`), re-fired 2 s after each tab switch (`:1018`). On page load a device adopts the server's `activeId` (`:792`). The handler returns the stored state after the caller's own write (`src/includes/handlers/UtilityHandler.php:200-220`), so a device cannot be yanked mid-session. But last writer wins, and a deploy reload on both devices sends both to the workspace the other device switched to last. | confirmed 2026-09-11 | Keep `activeId` on the server as "last used", for a device with no memory of its own. A reloading device restores its own last `activeId` from `sessionStorage` first and falls back to the server's only when it has none. Then a deploy countdown returns each device to where it was. Spec: `docs/specs/AUTO_RELOAD_ON_DEPLOY.md`. |

### Agents as producers: the admin tools

Since v2026.09.09.04 an agent can change plugin state through the `aicli-admin`
MCP tools (`docs/specs/PLUGIN_MANAGEMENT_TOOLS.md`). Checked 2026-09-11:

| Agent action | What is published | What is not | Effect on the browsers |
| :-- | :-- | :-- | :-- |
| Any tier-2 change (create, rename, args, env, auto-launch, channel) | `AdminMcpTools::call()` wraps every tool in `ActivityService::register()` then `finish()` (`src/includes/services/AdminMcpTools.php:391-394`), each of which publishes on `aicli_activity`. | The state itself. `AdminService::createWorkspace` and `updateWorkspace` write through `ConfigService::saveWorkspaces()` (`AdminService.php:711,779`), which never publishes. `aicli_workspaces` fires only from `startTerminal`. | Every tray on every device shows "create_workspace via <agent>" for 60 s, then the `done` entry is pruned. No drawer on any device gains the row until it reloads or its own next save round-trips. Settings pages show the old args, env and channel until reload. |
| Tier-3 proposal (delete workspace, upgrade agent) | `ActivityService::propose()` publishes the `pending_approval` entry (`AdminService.php:1273`). | Nothing else is needed for the browsers. | Both devices show the proposal at once when the push lands. If it is missed, the tray's idle cadence is 30 s because `pending_approval` is not in the `ACTIVE` set (D6). |
| Human approves or rejects on either device | `approve()` / `finish()` / `fail()` / `reject()` publish the transition (`AdminService.php:1297-1327`). A second click on the other device is refused as already handled. | **No route back to the proposing agent.** `approvePending()` and `rejectPending()` neither paste nor send a Relay message. The proposer's session id is recorded in the entry (`$caller`), so the route is cheap to add. | Both browsers converge. The agent that asked learns nothing and, by the tools' own doctrine, must not poll for it. |

One rule follows for the browsers: the publisher lives with the writer, not
with the caller. `ConfigService::saveWorkspaces()` (and the args, env,
auto-launch and channel writers) must publish, so the drawer's
`save_workspaces` path, the admin tool and any future caller all announce the
same fact the same way. The tray entry is an audit record; it is not a
substitute for the state event. Rank 4's `aicli_workspaces` vocabulary
therefore needs `created`, `updated` and `removed` beside `started`, `stopped`
and `bridge`.

The second gap, the proposer never hearing the outcome, is an agent-bound
event and is handled below. **Plugin events must not enter the Relay.** The
Relay is agent conversation: inboxes, topics, requests. A plugin lifecycle
event is not a message from a peer, and putting one in an inbox would make the
plugin a sender in the operator's conversation log. The precedent of
`publishHostHealth()` posting to a Relay topic is the exception that shows the
cost: it exists only when an administrator creates that topic by hand. This
review does not extend it.

### Agents as consumers: should the plugin MCP tell an agent what happened?

The scenario: agent `homelab` creates a workspace through `aicli_create_workspace`.
Later the operator closes that workspace in the drawer. The browsers get a
`removed` event (above). Should `homelab` be told, so it can act?

Three separate questions hide in that, and they have different answers.

**Can an MCP server tell an agent anything?** Not in a way the agent acts on.
The protocol's server-to-client notifications (`notifications/resources/updated`
after `resources/subscribe`, `notifications/tools/list_changed`) refresh a
client's cache. No agent CLI in the registry is documented as turning one into
a model turn, and the plugin's servers implement no resources. An agent learns
a fact only when it calls a tool, or when text lands in its pane.
`docs/specs/RELAY_DELIVERY_MODES.md` reached the same conclusion for mail: "MCP
is a pull". So "the MCP tells the agent" can only mean one of two things: the
next tool call returns it, or the plugin types a notice into the pane. The
stdio adapter's blocking `fgets(STDIN)` loop (`src/scripts/relay-mcp.php:69-76`,
text pinned by a guard after `RELAY_MCP_IDLE_TRANSPORT.md`) is a further reason
not to build a subscription into the adapter: there is nothing on the other
end that would use it.

**Which events is an agent a party to?** Not all of them. A drawer change
matters to a browser because a browser shows the drawer. An agent has no
drawer. It cares about an event only when the event answers a question it
asked or changes a thing it owns. Concretely:

| Event | Is the agent a party? | Why |
| :-- | :-- | :-- |
| Outcome of its own tier-3 proposal (approved, rejected, executed, failed) | **Yes, always.** | It asked, and was told "waiting for the user". It cannot poll for the answer by doctrine. This is the one event the agent is actively blocked on. |
| A workspace it created was closed, removed, or failed to start | **Yes, if it still holds the workspace as part of its task.** | It may have a plan that names that workspace. But the close was a human decision on a thing the agent could not delete itself; the agent's only correct action is bookkeeping, never resistance. Low urgency. |
| Its own agent binary is being upgraded | Yes, but the session is relaunched by the upgrade flow and resumes; the agent sees it as a restart, not a notice. Already handled. | |
| A workspace someone else created, a storage job, a deploy, another agent's proposal | **No.** | Not its question, not its thing. A firehose of plugin events into every pane is the paste-interrupt problem multiplied by every event in section 2. |

Today the workspace record has no creator field
(`AdminService::createWorkspace` writes `{id, name, path, agentId, lastActive}`,
`src/includes/services/AdminService.php:691`). The audit entry carries "via
<who>", but it is pruned after 60 s. So "a thing it owns" cannot be evaluated
at all right now. That is the first prerequisite.

The "party" distinction above decides who must be **told**. It does not decide
who may **look**. An agent automating the host may legitimately want every
event, including changes to workspaces and settings it never touched. Who did
what is audit; the audit is for everyone with read access.

**Decision (2026-09-11): pull only, for now.** The plugin does not notify an
agent. It records every event, lets an agent register what it wants to see,
and answers "what happened since I last asked" in one call. Autonomy is the
agent's side: a harness hook that calls the tool at its own checkpoints. No
pane notice, no MCP notification, nothing in the Relay.

**The event ledger.** One place, written by the same choke point that feeds
nchan. The cheapest build is a tee inside the publish path (today
`NchanService::publish`, later the `EventBus` facade): every publish also
appends one line to `/tmp/unraid-aicliagents/events/<day>.jsonl`, and every
publisher gains a ledger entry with no further change. Envelope:

```json
{"seq": 4812, "ts": 1789430123456,
 "kind": "workspace.removed",
 "actor": {"type": "human|agent|system", "sessionId": "s1", "agentId": "claude-code",
           "client": "browser:desktop-3f9a"},
 "subject": {"workspaceId": "abc", "name": "homelab", "agentId": "opencode"},
 "summary": "Workspace homelab closed by the operator",
 "data": {}}
```

- `seq` is monotonic across restarts (a counter file beside the ledger,
  written atomically). `ts` is server epoch milliseconds.
- `kind` is a fixed vocabulary that maps one-to-one onto the nchan channels
  and the workspace event vocabulary of rank 4: `workspace.created|updated|
  removed|started|stopped|bridge`, `activity.registered|updated|finished|
  failed|proposed|approved|rejected|dismissed`, `install.progress|complete`,
  `storage.status|job.done|job.failed|maintenance`, `deploy.activated`,
  `settings.changed` (key, old, new), `agent.channel|args|env|autolaunch`.
  A new channel without a `kind` fails the same CI guard as a channel outside
  the registry (Phase 2).
- `actor` is the audit. For a tool call it is the session behind
  `AICLI_SESSION_ID`, as `AdminMcpTools` already resolves it. For an AJAX
  action it is `human`, with a per-tab client id that the SPA and Manager
  send on every request (a random id minted at page load, kept in
  `sessionStorage`), so "closed from the phone" and "closed from the desktop"
  are distinguishable. For the supervisor and the event hooks it is `system`.
- Bounded: 7 days or 20 000 lines, whichever first; old day files unlinked by
  the supervisor sweep. It is tmpfs, so a reboot clears it, which is correct:
  no event survives a reboot in a form an agent could act on.
- Data, never instruction. The `aicli-admin` doctrine already says content
  from elsewhere must not by itself cause a tool call. The ledger is content
  from elsewhere.

**Retention, and why.** The ledger serves two needs that want different
lifetimes, and it should serve only one of them.

- *"What is new since I asked"* needs the ledger to outlast the longest gap
  between an agent's checks. A count bound does this well enough, because the
  cursor contract below tells an agent when it did not, and a reboot already
  bounds the age. No separate age bound (decided 2026-09-11; an earlier draft
  had 7 days).
- *"Who did what"* wants to survive a reboot. The plugin already has that
  surface: `lifecycle.log` on flash, which `AdminService` writes an audit line
  to for every tool change. The ledger must not become a second durable audit
  on flash: `/boot` is FAT32 with wear and a health check that warns below
  10 % free, and the home overlay's upperdir is on the same flash.
  So the ledger lives on tmpfs, like the activity registry, and a reboot
  clears it. That is correct for its purpose: no event that predates a reboot
  is one an agent should act on.

Rules that follow:

- **A circular log by count: 20 000 events.** An install publishes a progress
  event per step, and a storage job publishes a snapshot on every transition,
  so a busy hour is hundreds of lines; at roughly 400 bytes a line the cap is
  about 8 MB of RAM, under the `/tmp` health threshold with room to spare. The
  ring is built from chunks, not one file: append to `events/<seq-of-first>.jsonl`,
  start a new chunk every 1 000 events, and unlink the oldest chunk when more
  than 20 exist. Every write is one `O_APPEND` line, so PHP requests and the
  supervisor can append concurrently with no lock; only the `seq` counter
  takes a `flock` (tmpfs, `LOCK_NB` with a short retry). Rotation is an
  unlink, never a rewrite, and the overshoot is at most one chunk. The
  look-back window is therefore a function of event rate, not of time: weeks
  when quiet, hours during a storm. That is acceptable because the cursor
  contract tells the agent when it fell off the end.
- **Reads never delete.** The ledger has many readers with their own cursors,
  and it is the audit. `aicli_get_events` advances only the calling session's
  cursor (the `ack`), and only when asked; it removes nothing. The only
  deletions are rotation and reboot. There is no agent tool to clear it: an
  agent must not be able to destroy the record other agents and the operator
  rely on. If an operator ever needs a manual clear, it is a button on the
  Manager Debug tab beside "clear activities", not a tool.
- **Ack is explicit, not implicit in a read.** Keep `ack` as a parameter on
  `aicli_get_events` (default true, so a bare hook call is one round trip),
  and add `aicli_ack_events(seq)` for an agent that reads first and commits
  later, for example after it has finished acting on a batch. A read with
  `ack: false` is a peek.
- **Store the envelope, not the nchan payload.** A storage snapshot is
  kilobytes. The ledger line carries `kind`, `actor`, `subject`, `summary` and
  a small `data`; an agent that wants the full state calls the read tool.
- **Retention is a contract with the cursor.** When a session's cursor is
  older than the oldest retained `seq`, `aicli_get_events` must say so
  (`gap: true`, `oldest_seq`) rather than silently start from the oldest. The
  agent then decides whether a full read is needed.
- **A reboot resets `seq`.** The cursor is therefore `{boot_id, seq}`, with
  `boot_id` from `/proc/sys/kernel/random/boot_id`. On mismatch the tool
  returns `reset: true` and starts from the oldest retained event. A cursor
  stored in the per-user state dir survives the reboot; the ledger it points
  into does not, and the tool must never pretend otherwise.
- **Subscriptions and cursors die with the workspace.** They live beside the
  Relay pending queue in the per-user state dir and are removed by the same
  sweep that removes a closed workspace's files. Nothing accumulates.
- **No secret values, ever.** `settings.changed` and `agent.env` events carry
  key names and a changed flag, never values for keys the vault or the secret
  tier owns. The same `RedactionService` the diagnostics bundle uses runs on
  `summary` and `data` before the line is written.
- **Not now: durable history.** If a week on tmpfs proves too short, the
  answer is a daily compaction into the home overlay with its own cap, not a
  longer tmpfs window and not flash. Decide that from real usage.

**Two tools on the admin MCP, mirrored on `$AICLI_ADMIN_COMMAND` for hooks.**

`aicli_subscribe_events(kinds: string[], filter?: {workspaceId?, agentId?,
actor?}, replace?: bool)`. Registers what this session wants to see.
Stored per session in the same per-user state dir the Relay pending queue
uses, keyed by `AICLI_SESSION_ID`, so it survives an agent relaunch (the
session id is stable across relaunches) and dies with the workspace.
`kinds` accepts globs (`workspace.*`, `*`). `replace: false` merges.
`aicli_subscribe_events([])` clears it. Returns the effective subscription and
the current head `seq`, so the agent can start from now.

`aicli_get_events(since_seq?: int, kinds?: string[], limit?: int, ack?: bool)`.
Returns events after `since_seq` that match the session's subscription (or
`kinds` if given, which overrides it for that call), oldest first, with
`next_seq` and `truncated`. With no `since_seq` it uses the session's stored
cursor. With `ack: true` (default) it advances that cursor to the last event
returned, so a hook can call it with no arguments and get only what is new.
An agent that wants to re-read passes `since_seq` explicitly. Unsubscribed
sessions get nothing unless they pass `kinds`, so an agent that never
registered is never handed a firehose by accident.

`aicli_list_activities` and `aicli_get_overview` gain a `since` timestamp for
the same reason, but the ledger is the general answer.

**Autonomy is a harness hook, not a plugin feature.** An agent whose harness
supports hooks (Claude Code hooks on stop or prompt-submit; Gemini and Codex
equivalents where they exist) configures one line that runs
`$AICLI_ADMIN_COMMAND events` and prints the result into its context. The
plugin's part is CLI parity: `src/scripts/admin-agent.php` gets `subscribe`
and `events` verbs that call the same service methods as the MCP tools, the
way the Relay CLI mirrors its MCP. The projected `aicli-admin` skill documents
the pattern with one worked example per harness that has hooks, and says
plainly that an agent without hooks checks at its own checkpoints.

For the scenario: `homelab` creates the workspace, and the record gains
`createdBy: <sessionId>` (missing today; add it). `homelab` has earlier called
`aicli_subscribe_events(["workspace.*", "activity.proposed", "activity.approved",
"activity.rejected"])`. The operator closes the workspace from the phone. The
writer publishes `removed` to nchan for the browsers and the tee appends
`{kind: workspace.removed, actor: {type: human, client: browser:phone-…},
subject: {workspaceId, createdBy: homelab's session}}`. Nothing touches
`homelab`'s pane. At its next hook firing, `aicli_get_events()` returns that
one event; `homelab` sees who closed it and that it was its own creation, and
updates its plan. A proposal outcome reaches it the same way, by the same hook,
with no doctrine problem: it is pulling, not being pushed.

**What not to do, now.** No pane notices from the plugin. No MCP resource
subscriptions. No nchan subscriber inside the adapter. No plugin events in
Relay inboxes or topics. If a push to agents is ever wanted, the ledger and
the subscription registry are the parts it would be built on; nothing here
has to be undone.

### The rule to write down

Server facts are shared and pushed; view state is per device and never pushed.
A tray action is a server fact. A dismissal that hides a server fact is also a
server fact, because the operator is one person on two screens. Arming,
"Not now", the drawer's open state, and the active workspace are view state.
`docs/specs/EVENT_FIRST_RECONCILIATION.md` should carry this rule and the table
above, and `docs/specs/RELAY_WAITING_PILL.md` should say which of its controls
are which.

---

## 10. Verification for the review's claims

Each claim above names its call site. The three that were checked by more than
reading:

- D1 was traced through the effect body at `AICliAgentsTerminal.tsx:855-864`
  (CRLF file, read with `tr -d '\r'`). No test covers the adaptive poll; a
  vitest with fake timers asserting a second `debug` fetch by T+40 s would fail
  on current code.
- The nchan buffer and first-message behaviour were read from the live nginx
  config on this box, not from documentation.
- O1 was read from `ps` on this box.

Nothing was run under `ci/run`. No file under `src/` or `ui-build/` was
changed.
