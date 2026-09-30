/**
 * <module_context>
 * Description: <aicli-activity-tray> — framework-free web component surfacing
 *   in-flight slow operations (install/upgrade/storage/migrate/start) from the
 *   ActivityService registry. Included by BOTH the jQuery manager page and the
 *   React terminal page (T-08/T-09/T-10 — docs/specs/ACTIVITY_TRAY.md).
 * Dependencies: none (vanilla JS; EventSource for Nchan, fetch for AJAX).
 * Constraints: collapsed pill bottom-right, raised to bottom:48px so it clears
 *   the fixed Dynamix footer/status line (OP#1381); z-index 10003 (above Unraid
 *   headers per repo standard 10002+). Consumes the `activity` channel of the
 *   page's one multiplexed stream (aicli-events.js, EVENT_STREAM_MULTIPLEX.md);
 *   falls back to its own /sub/aicli_activity only when that script is absent.
 *   Reconcile-first on load: the first `list_activities` read lands before the
 *   stream opens. One more reconcile read fires on every stream `onopen` and on
 *   every tab `visibilitychange` to visible (EVENT_FIRST_RECONCILIATION.md R6).
 *   Poll cadence: 5 s when the stream is broken, 30 s while any entry is not
 *   `done`, 60 s idle (`_needsFastPoll`, ACTIVITY_TRAY.md "Planned changes"). A
 *   pushed entry older than the last reconcile snapshot's `ts` is dropped (R5).
 * </module_context>
 */
(function () {
    'use strict';

    var AJAX = '/plugins/unraid-aicliagents/AICliAjax.php';

    // Mirrors ui-build/src/lib/activityModel.ts STEP_LABELS — keep in sync.
    var STEP_LABELS = {
        preparing: 'Preparing environment',
        mounting_agent: 'Mounting agent storage',
        mounting_home: 'Mounting home storage',
        // S-08 (STORAGE_ASYNC_JOBS.md): cold home — mount queued as a supervisor job.
        mounting_home_queued: 'Mounting home storage (queued)',
        launching_ttyd: 'Starting terminal server',
        starting_agent: 'Launching agent',
        retrying: 'Retrying…'
    };

    function csrf() {
        if (window.csrf_token) return window.csrf_token;
        var el = document.querySelector('input[name="csrf_token"]');
        return el ? el.value : '';
    }

    function ajaxUrl(action, params) {
        var url = AJAX + '?action=' + encodeURIComponent(action) + '&csrf_token=' + encodeURIComponent(csrf());
        if (params) {
            Object.keys(params).forEach(function (k) {
                url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
            });
        }
        return url;
    }

    function stepText(entry) {
        var s = entry.step || '';
        return STEP_LABELS[s] || s;
    }

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    var TRAY_CSS = ''
        + ':host { all: initial; }'
        // #1: clear the Dynamix footer. The Unraid 7.3.1 #footer is fixed at
        // bottom:0 with a VARIABLE height (grid/flex + .5rem padding + 1rem gap;
        // taller with license/array-status content or a narrow viewport), so the
        // old hardcoded bottom:48px could not reliably clear it. The 48px here is
        // only the pre-measurement default — _positionAboveFooter() measures the
        // live #footer at runtime and sets bottom to sit just above it (both the
        // collapsed pill and the expanded panel are children of .wrap, so raising
        // .wrap clears both). z-index 10003 keeps it above Unraid headers (10002+)
        // and the footer (10000).
        // SIDEBAR_THEME_LAYOUT.md: --aicli-content-left/-right (aicli-content-box.js)
        // are the widths of Unraid's fixed side menu; 0px on the top-menu themes.
        // #328: while the panel is OPEN it must draw above the terminal page's
        // drawer and its icon strip (z-index 1000000-1000002), which crossed it
        // on a phone. Only while open: the collapsed pill stays at 10003 so it
        // never covers Unraid's own dialogs. Modal backdrops (1000004) still win.
        + '.wrap.open { z-index: 1000003; }'
        // 2026-09-26: the pill stays in its corner under the open panel (it
        // jumped to the panel's left edge, onto other controls).
        + '.wrap.open { display: flex; flex-direction: column; align-items: flex-end; }'
        + '.wrap { position: fixed; bottom: 48px; right: calc(14px + var(--aicli-content-right, 0px)); z-index: 10003;'
        + '  font-family: clear-sans, sans-serif; font-size: 12px; color: var(--text-color, #e0e0e0); }'
        + '.pill { display: flex; align-items: center; gap: 7px; cursor: pointer; user-select: none;'
        + '  padding: 7px 14px; border-radius: 16px; border: 1px solid var(--border-color, #444);'
        + '  background: var(--title-header-background-color, #1c1b1b); color: var(--text-color, #e0e0e0);'
        + '  box-shadow: 0 4px 14px rgba(0,0,0,0.4); font-weight: 600; }'
        + '.pill .dot { width: 8px; height: 8px; border-radius: 50%; background: var(--orange, #e68a00); }'
        // 2026-09-26 (ACTIVITY_TRAY.md): the pill carries its full text and a
        // short count; a phone shows only the dot and the count (the full text
        // stays the button's accessible name and is in the open panel).
        + '.pill .short { display: none; }'
        + '.pill .dot.spin { animation: aicli-act-pulse 1.2s ease-in-out infinite; }'
        + '.pill .dot.bad { background: #d9534f; animation: none; }'
        + '.pill .dot.stall { background: #eab308; animation: none; }'
        // waiting: parked on an external condition (e.g. an installed upgrade whose
        // storage layer activates when the last old-version process exits). Calm
        // blue, no pulse — nothing is running and nothing is wrong.
        + '.pill .dot.wait { background: #5b9bd5; animation: none; }'
        + '@keyframes aicli-act-pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.25; } }'
        + '.panel { width: 340px; max-height: 50vh; overflow-y: auto; margin-bottom: 8px;'
        + '  border: 1px solid var(--border-color, #444); border-radius: 8px;'
        + '  background: var(--background-color, #262626); box-shadow: 0 10px 30px rgba(0,0,0,0.5); }'
        + '.panel-head { display: flex; align-items: center; justify-content: space-between;'
        + '  padding: 8px 12px; border-bottom: 1px solid var(--border-color, #444); font-weight: 700; }'
        + '.panel-head .close { cursor: pointer; opacity: 0.6; padding: 2px 6px; }'
        + '.panel-head .close:hover { opacity: 1; }'
        + '.row { padding: 10px 12px; border-bottom: 1px solid var(--border-color, #3a3a3a); }'
        + '.row:last-child { border-bottom: none; }'
        + '.row .top { display: flex; align-items: center; justify-content: space-between; gap: 8px; }'
        + '.row .label { font-weight: 700; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }'
        + '.row .status { font-size: 10px; text-transform: uppercase; letter-spacing: 0.08em;'
        + '  padding: 1px 7px; border-radius: 9px; flex-shrink: 0; }'
        // Epic #307: each status label mixes its hue with the theme text colour,
        // so it reads at WCAG AA on the light theme (plain orange on its own
        // tint was 2:1) and stays bright on a dark one.
        + '.status.running { background: rgba(230,138,0,0.18); color: color-mix(in srgb, var(--orange, #e68a00) 50%, var(--text-color, #e0e0e0)); }'
        + '.status.stalled { background: rgba(234,179,8,0.18); color: color-mix(in srgb, #eab308 45%, var(--text-color, #e0e0e0)); }'
        + '.status.waiting { background: rgba(91,155,213,0.18); color: color-mix(in srgb, #5b9bd5 50%, var(--text-color, #e0e0e0)); }'
        + '.status.failed  { background: rgba(217,83,79,0.18); color: color-mix(in srgb, #d9534f 60%, var(--text-color, #e0e0e0)); }'
        + '.status.done    { background: rgba(0,128,64,0.2); color: color-mix(in srgb, #4caf50 50%, var(--text-color, #e0e0e0)); }'
        // pending_approval: Tier 3 (PLUGIN_MANAGEMENT_TOOLS.md "Phase 3 as built",
        // 2026-09-09) — a tool validated a destructive request and is waiting for a
        // human, not a worker. Distinct purple so it reads as "needs YOUR action",
        // not just another in-progress task.
        + '.status.pending_approval { background: rgba(155,89,182,0.2); color: #9b59b6; }'
        + '.pill .dot.approve { background: #9b59b6; animation: aicli-act-pulse 1.2s ease-in-out infinite; }'
        // relay_waiting (RELAY_WAITING_PILL.md, 2026-09-09): a Relay notice the
        // readiness gate held back, now visible and one click from delivered.
        // Calm teal, no pulse — nothing is broken, it just needs a human's OK.
        + '.status.relay_waiting { background: rgba(20,184,166,0.18); color: color-mix(in srgb, #14b8a6 45%, var(--text-color, #e0e0e0)); }'
        + '.pill .dot.relay { background: #14b8a6; animation: none; }'
        // Epic #307: text mixed with the theme text colour, as the other
        // status labels above (plain teal was 1.9:1 / 2.2:1 on the light theme).
        + '.btn.deliver { border-color: #14b8a6; color: color-mix(in srgb, #14b8a6 45%, var(--text-color, #e0e0e0)); font-weight: 700; }'
        // Force inject types into a pane the plugin still judges busy. It is the
        // operator overruling a safety check, so it is amber, not the calm teal of
        // the "go and look" step that precedes it.
        + '.btn.force { border-color: #e68a00; color: #e68a00; font-weight: 700; }'
        + '.row .step { margin-top: 4px; font-family: monospace; font-size: 11px; opacity: 0.65; }'
        + '.row .err { margin-top: 4px; font-size: 11px; color: #d9534f; word-break: break-word; }'
        + '.row .consequence { margin-top: 4px; font-size: 11px; opacity: 0.85; word-break: break-word; }'
        + '.btn.approve { border-color: #9b59b6; color: #9b59b6; font-weight: 700; }'
        + '.bar { margin-top: 6px; height: 4px; border-radius: 2px; overflow: hidden;'
        + '  background: rgba(255,255,255,0.08); }'
        + '.bar > div { height: 100%; background: var(--orange, #e68a00); transition: width 0.3s ease-out; }'
        + '.bar.stalled > div { background: #eab308; }'
        + '.btns { margin-top: 7px; display: flex; gap: 6px; }'
        + '.btn { cursor: pointer; padding: 3px 10px; font-size: 11px; border-radius: 4px;'
        + '  border: 1px solid var(--border-color, #555); background: transparent; color: inherit; }'
        + '.btn:hover { background: rgba(255,255,255,0.06); }'
        + '.btn.retry { border-color: var(--orange, #e68a00); color: var(--orange, #e68a00); font-weight: 700; }'
        + '.empty { padding: 16px 12px; text-align: center; opacity: 0.5; }'
        // Epic #307: the pill and the panel's close control are real <button>s
        // (keyboard and screen reader reachable). The shadow root keeps the
        // Unraid theme out, so only the UA button look is reset here.
        + 'button.pill, button.close { font: inherit; margin: 0; text-align: left; }'
        + 'button.close { border: 0; background: transparent; color: inherit; font-size: 13px; line-height: 1; }'
        // Epic #307: at phone width every control is a 44 px touch target and
        // the panel never runs off the left edge of the screen.
        + '@media (max-width: 640px) {'
        // ...and never under Unraid's side menu (sidebar themes, SIDEBAR_THEME_LAYOUT.md).
        + '  .panel { width: min(340px, calc(100vw - 28px - var(--aicli-content-left, 0px) - var(--aicli-content-right, 0px))); max-height: 60vh; }'
        + '  .pill { min-height: 44px; min-width: 44px; box-sizing: border-box; justify-content: center; padding: 7px 12px; }'
        + '  .pill .full { display: none; }'
        + '  .pill .short { display: inline; font-variant-numeric: tabular-nums; }'
        + '  .panel-head { padding: 0 0 0 12px; }'
        + '  .panel-head .close { min-width: 44px; min-height: 44px; padding: 0; opacity: 0.8;'
        + '    display: inline-flex; align-items: center; justify-content: center; }'
        + '  .btns { flex-wrap: wrap; }'
        + '  .btn { min-height: 44px; min-width: 44px; padding: 0 14px; font-size: 13px; }'
        + '}';

    // ACTIVITY_TRAY.md "When a start row appears" (2026-09-11): a start row appears only if
    // nobody watched the launch, or it went wrong. The entries still EXIST for everything
    // else — the page's cold-start overlay reads its live step text from them through
    // _emit(), which stays unfiltered. An entry with no origin (an older server) counts as
    // interactive.
    var SLOW_START_S = 10;
    function startRowVisible(a, nowS) {
        if (a.status === 'failed' || a.status === 'stalled') return true;
        var m = a.meta || {};
        if (m.reattach) return false;                                   // the agent was still running: nothing started
        if ((m.origin || 'interactive') !== 'interactive') return true; // unattended: show through its DONE
        // Watched on screen: only a SLOW launch earns a row (e.g. storage mount queued).
        return a.status === 'running' && (nowS - (parseInt(a.startedAt, 10) || nowS)) >= SLOW_START_S;
    }
    // ACTIVE marks a status with a live, cancellable worker — used only for the
    // Cancel button and the progress bar in _row(). It is NOT the poll-cadence
    // rule; see _needsFastPoll below (review D6).
    var ACTIVE = { running: 1, stalled: 1 };

    // EVENT_FIRST_RECONCILIATION.md R6 ("the tray ACTIVE set is replaced by 'any
    // entry not done'") and review D6: `pending_approval`, `failed`, `waiting` and
    // `relay_waiting` must not sit on the idle cadence. A pure function so the
    // smoke test can pin its name and behaviour by source text.
    function _needsFastPoll(entries) {
        return entries.some(function (a) { return a.status !== 'done'; });
    }

    // 2026-09-26 (ACTIVITY_TRAY.md "The pill never covers a control"): the short
    // pill text on a phone — the number of entries that are not done (or of all
    // entries, while the panel is open over done ones only).
    function _pillShortText(counts, total) {
        var n = 0;
        Object.keys(counts).forEach(function (k) { n += counts[k] || 0; });
        return String(n > 0 ? n : total);
    }

    // 2026-09-26: the pill's distance from the bottom of the window. `base` is
    // the footer clearance; `pill` is {left, right, height} of the pill; `rects`
    // are the boxes ({left, right, top, bottom}) of the controls it must not
    // cover (elements marked data-aicli-tray-avoid, e.g. the phone key row).
    // Each control in the pill's column that the pill (plus `gap`) would touch
    // lifts the pill to `gap` above that control's top; repeat until nothing
    // is touched, so stacked controls (key row, then the Latest pill) all stay
    // clear. Never lifts the pill above the top of the window.
    function _clearAvoid(base, pill, rects, vh, gap) {
        var bottom = base;
        var max = Math.max(base, vh - pill.height - gap);
        for (var pass = 0; pass < 12; pass++) {
            var moved = false;
            var pTop = vh - bottom - pill.height;
            var pBot = vh - bottom;
            for (var i = 0; i < rects.length; i++) {
                var r = rects[i];
                if (r.right <= pill.left || r.left >= pill.right) continue;   // another column
                if (r.top >= pBot + gap || r.bottom <= pTop - gap) continue;  // clear already
                var need = Math.ceil(vh - r.top + gap);
                if (need > bottom) { bottom = Math.min(need, max); moved = true; }
            }
            if (!moved || bottom >= max) break;
        }
        return bottom;
    }

    class AicliActivityTray extends HTMLElement {
        constructor() {
            super();
            this._activities = [];   // server entries
            this._local = {};        // client-only entries (e.g. auto-launch fetch .catch) keyed by opId
            // relay_waiting (RELAY_WAITING_PILL.md): opId -> count at the moment the
            // operator clicked Dismiss. NEVER discards the underlying message — the
            // durable queue is untouched — this only suppresses the pill until a new
            // arrival pushes the count past what was dismissed (spec Edge Cases).
            this._relayDismissed = {};
            // relay_waiting, "look then force" (RELAY_WAITING_PILL.md R2): opId ->
            // true once the operator has been taken to that workspace. Only then
            // does the pill offer Force inject, so nobody types into a pane they
            // have not looked at. Deliberately in-memory: a reload drops the arming
            // and the operator is asked to look again.
            this._relayArmed = {};
            // opId -> what the server said about the last forced injection, shown
            // in the row. The tray used to throw this reply away, so a refused
            // delivery looked exactly like a successful one.
            this._relayNote = {};
            this._open = false;
            this._es = null;
            this._esBroken = false;
            this._pollTimer = null;
            // Last reconcile snapshot time (server epoch ms). Starts at 0 so the
            // very first message, before any snapshot has landed, is never
            // dropped by the ts guard in _subscribe()'s onmessage (R5).
            this._snapshotTs = 0;
            this._onLocal = this._onLocal.bind(this);
            this._onVisibility = this._onVisibility.bind(this);
            this.attachShadow({ mode: 'open' });
        }

        connectedCallback() {
            var self = this;
            var style = document.createElement('style');
            style.textContent = TRAY_CSS;
            this.shadowRoot.appendChild(style);
            this._root = document.createElement('div');
            this._root.className = 'wrap';
            this.shadowRoot.appendChild(this._root);

            window.addEventListener('aicli-activity-local', this._onLocal);
            this._positionAboveFooter();
            this._onResize = this._positionAboveFooter.bind(this);
            window.addEventListener('resize', this._onResize);
            // 2026-09-26: a control the pill must avoid appeared or went away
            // (ui-build/src/lib/trayAvoid.ts), or the page scrolled under it.
            window.addEventListener('aicli-tray-layout', this._onResize);
            window.addEventListener('scroll', this._onResize, { passive: true });
            // R6: one reconcile read on every return to the foreground. Bound in
            // the constructor and removed in disconnectedCallback, so a tray that
            // is removed and reattached never double-registers.
            document.addEventListener('visibilitychange', this._onVisibility);
            // Reconcile-first on load (R6, "reconcile-first on load: poll, apply
            // the snapshot, then open the stream"): the stream opens only after
            // the initial list_activities read has landed (success or failure),
            // so _snapshotTs already holds a real value before the first message
            // can arrive.
            this._poll(function () { self._subscribe(); });
            this._schedulePoll();
            this._render();
        }

        disconnectedCallback() {
            window.removeEventListener('aicli-activity-local', this._onLocal);
            if (this._onResize) {
                window.removeEventListener('resize', this._onResize);
                window.removeEventListener('aicli-tray-layout', this._onResize);
                window.removeEventListener('scroll', this._onResize);
            }
            document.removeEventListener('visibilitychange', this._onVisibility);
            if (this._es) { try { this._es.close(); } catch (e) { /* noop */ } this._es = null; }
            if (this._pollTimer) clearTimeout(this._pollTimer);
        }

        // R6: a tab that comes back to the foreground gets one immediate
        // reconcile read, so a phone suspended for minutes is not left showing
        // a stale tray until the next scheduled poll.
        _onVisibility() {
            if (document.visibilityState === 'visible') this._poll();
        }

        // #1: the Unraid 7.3.1 #footer is position:fixed at bottom:0 with a
        // variable height, so measure it live and sit just above it. Falls back
        // to a small clearance when the footer is relative (mobile), hidden, or
        // absent. Cheap (one getBoundingClientRect); called on mount, resize, and
        // each render so a late-appearing footer is still cleared.
        _positionAboveFooter() {
            if (!this._root) return;
            var bottom = 14;
            try {
                var f = document.getElementById('footer');
                if (f) {
                    var cs = window.getComputedStyle(f);
                    if (cs && cs.position === 'fixed' && cs.display !== 'none' && cs.visibility !== 'hidden') {
                        var r = f.getBoundingClientRect();
                        var over = Math.max(0, window.innerHeight - r.top); // footer height above the viewport bottom
                        if (over > 0) bottom = Math.ceil(over) + 12;
                    }
                }
            } catch (e) { /* keep the default clearance */ }
            // 2026-09-26: never cover a control near the bottom of the page (the
            // terminal's phone key row, its Latest pill, the one-shot hint).
            try {
                var pillEl = this._root.querySelector('.pill');
                var avoid = document.querySelectorAll('[data-aicli-tray-avoid]');
                if (pillEl && avoid.length) {
                    var pr = pillEl.getBoundingClientRect();
                    var rects = [];
                    for (var i = 0; i < avoid.length; i++) {
                        var ar = avoid[i].getBoundingClientRect();
                        if (ar.width <= 0 || ar.height <= 0) continue;
                        if (ar.bottom <= 0 || ar.top >= window.innerHeight) continue;
                        var acs = window.getComputedStyle(avoid[i]);
                        if (acs.visibility === 'hidden' || acs.display === 'none') continue;
                        rects.push({ left: ar.left, right: ar.right, top: ar.top, bottom: ar.bottom });
                    }
                    if (pr.width > 0 && rects.length) {
                        bottom = _clearAvoid(bottom, { left: pr.left, right: pr.right, height: pr.height }, rects, window.innerHeight, 8);
                    }
                }
            } catch (e) { /* keep the footer clearance */ }
            this._root.style.bottom = bottom + 'px';
        }

        // ---- data flow ------------------------------------------------------

        _subscribe() {
            var self = this;
            // R5 (EVENT_FIRST_RECONCILIATION.md): one rule for a pushed entry,
            // whichever connection carried it.
            var apply = function (data) {
                self._esBroken = false;
                if (!data || !data.opId) return;
                // Drop a message older than the last reconcile snapshot. A
                // payload with no `ts` (an older server) is applied, for one
                // release, per EVENT_PUBLISH_OBSERVABILITY.md.
                if (typeof data.ts === 'number' && data.ts < self._snapshotTs) return;
                self._merge(data);
            };
            // EVENT_STREAM_MULTIPLEX.md R2/R3: the page's ONE multiplexed stream
            // (aicli-events.js) carries `activity`. The tray opens no connection
            // of its own when that script is on the page.
            if (window.aicliEvents && typeof window.aicliEvents.on === 'function') {
                this._esBroken = (window.aicliEvents.status === 'broken' || window.aicliEvents.status === 'unsupported');
                window.aicliEvents.on('activity', function (evt) { apply(evt.data); });
                // D4 (EVENT_ARCHITECTURE_REVIEW.md): one reconcile read on every
                // (re)connect closes the gap the Nchan buffer cannot replay.
                window.addEventListener('aicli-reconcile', function () { self._poll(); });
                window.addEventListener('aicli-event-status', function (e) {
                    var status = e && e.detail && e.detail.status;
                    // Broken or absent stream -> the fallback poll tightens to 5 s.
                    self._esBroken = (status === 'broken' || status === 'unsupported');
                });
                return;
            }
            // Fallback: a page shell without aicli-events.js (an older page
            // generation served with this newer tray).
            if (typeof EventSource === 'undefined') { this._esBroken = true; return; }
            try {
                this._es = new EventSource('/sub/aicli_activity');
                this._es.onopen = function () {
                    self._esBroken = false;
                    self._poll();
                };
                this._es.onmessage = function (msg) {
                    var data;
                    try { data = JSON.parse(msg.data); } catch (e) { return; }
                    apply(data);
                };
                this._es.onerror = function () {
                    // EventSource auto-reconnects; flag so the fallback poll tightens to 5 s.
                    self._esBroken = true;
                };
            } catch (e) {
                this._esBroken = true;
            }
        }

        _merge(entry) {
            if (entry.dismissed) {
                this._activities = this._activities.filter(function (a) { return a.opId !== entry.opId; });
            } else {
                var found = false;
                this._activities = this._activities.map(function (a) {
                    if (a.opId === entry.opId) { found = true; return entry; }
                    return a;
                });
                if (!found) this._activities.push(entry);
                delete this._local[entry.opId]; // server truth supersedes a local placeholder
            }
            this._emit();
            this._render();
        }

        /**
         * @param {Function} [onDone] Called once the read settles, success or
         *   failure, so a caller (connectedCallback's reconcile-first load, and
         *   _subscribe's onopen) can sequence work after a real attempt.
         */
        _poll(onDone) {
            var self = this;
            fetch(ajaxUrl('list_activities'))
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data && data.status === 'ok' && Array.isArray(data.activities)) {
                        // R4/R5: the read's own `ts` (server epoch ms) becomes the new
                        // reconcile snapshot time; a payload with no `ts` (an older
                        // server) falls back to now.
                        self._snapshotTs = (typeof data.ts === 'number') ? data.ts : Date.now();
                        self._activities = data.activities;
                        // Drop local placeholders the server now knows about.
                        data.activities.forEach(function (a) { delete self._local[a.opId]; });
                        self._emit();
                        self._render();
                    }
                })
                .catch(function () { /* server unreachable — keep last state */ })
                .then(function () { if (onDone) onDone(); });
        }

        _schedulePoll() {
            var self = this;
            // 5 s when the Nchan stream is broken (fallback), 30 s while any entry
            // is not `done` (drives the server-side watchdog evaluation and covers
            // review D6: a stale failed/pending_approval/waiting/relay_waiting
            // entry can no longer hide out on the idle cadence), 60 s idle.
            var interval = this._esBroken ? 5000 : (_needsFastPoll(this._all()) ? 30000 : 60000);
            this._pollTimer = setTimeout(function () {
                self._poll();
                self._schedulePoll();
            }, interval);
        }

        _onLocal(e) {
            var entry = e && e.detail;
            if (!entry || !entry.opId) return;
            this._local[entry.opId] = entry;
            this._emit();
            this._render();
        }

        _all() {
            var ids = {};
            this._activities.forEach(function (a) { ids[a.opId] = 1; });
            var locals = Object.keys(this._local).filter(function (k) { return !ids[k]; }, this);
            var self = this;
            return this._activities.concat(locals.map(function (k) { return self._local[k]; }));
        }

        /** Re-broadcast merged state for page-level consumers (React cold-start overlay, T-09). */
        _emit() {
            try {
                window.dispatchEvent(new CustomEvent('aicli-activity-change', { detail: { activities: this._all() } }));
            } catch (e) { /* noop */ }
        }

        // ---- actions ---------------------------------------------------------

        _action(action, opId, isLocal) {
            var self = this;
            if (isLocal && (action === 'dismiss_activity')) {
                delete this._local[opId];
                this._emit();
                this._render();
                return;
            }
            fetch(ajaxUrl(action, { opId: opId }))
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    // A forced injection is the one action whose ANSWER matters to
                    // the operator: the queue may have drained itself, or the pane
                    // may hold no live agent at all. Show what happened in the row
                    // instead of leaving an unchanged pill to be read as failure.
                    if (action === 'deliver_relay_waiting' && res) {
                        self._relayNote[opId] = res.delivered
                            ? 'Injected into the session.'
                            : (res.message || 'Nothing was injected.');
                    }
                    self._poll();
                })
                .catch(function () {
                    if (action === 'deliver_relay_waiting') {
                        self._relayNote[opId] = 'Could not reach the server — nothing was injected.';
                        self._render();
                    }
                    /* next poll re-syncs */
                });
        }

        /**
         * Take the operator to one workspace. The tray is mounted on the
         * AICliAgents tab AND on Settings, so this either drives the React app on
         * this page through its own hash route, or navigates to that tab carrying
         * the same hash. `#aicliagents-root` exists only on the tab itself, which
         * is what tells the two pages apart.
         */
        _gotoWorkspace(sessionId) {
            if (!sessionId) return;
            var hash = '#aicli-workspace=' + encodeURIComponent(sessionId);
            if (document.getElementById('aicliagents-root')) {
                // Same page: assigning an unchanged hash fires no hashchange, so
                // clear it first — the operator may be returning to the same
                // workspace a second time.
                if (window.location.hash === hash) {
                    window.history.replaceState(null, '', window.location.pathname + window.location.search);
                }
                window.location.hash = hash;
                return;
            }
            window.location.href = '/AICliAgents' + hash;
        }

        // ---- rendering -------------------------------------------------------

        _render() {
            var self = this;
            // relay_waiting entries a Dismiss click suppressed are filtered out
            // here — NOT deleted anywhere — so a new arrival (count grows past
            // what was dismissed) makes the pill reappear on its own (spec Edge
            // Cases: "Dismiss ... never discards the message").
            var nowS = Math.floor(Date.now() / 1000);
            var all = this._all().filter(function (a) {
                if (a.type === 'start' && !startRowVisible(a, nowS)) return false;
                if (a.status !== 'relay_waiting') return true;
                var dismissedAt = self._relayDismissed[a.opId];
                return dismissedAt === undefined || (a.count || 0) > dismissedAt;
            });
            var running = all.filter(function (a) { return a.status === 'running'; }).length;
            var stalled = all.filter(function (a) { return a.status === 'stalled'; }).length;
            var failed = all.filter(function (a) { return a.status === 'failed'; }).length;
            var waiting = all.filter(function (a) { return a.status === 'waiting'; }).length;
            var pending = all.filter(function (a) { return a.status === 'pending_approval'; }).length;
            var relayWaiting = all.filter(function (a) { return a.status === 'relay_waiting'; }).length;

            // Invisible when idle: transient `done` entries (every successful
            // session start produces one for up to 60 s) must not summon the pill.
            if (all.length === 0 || (!this._open && running + stalled + failed + waiting + pending + relayWaiting === 0)) {
                this._root.innerHTML = '';
                return;
            }

            var pillText = running > 0 ? running + ' task' + (running > 1 ? 's' : '') + ' running' : '';
            if (stalled > 0) pillText += (pillText ? ', ' : '') + stalled + ' stalled';
            if (failed > 0) pillText += (pillText ? ', ' : '') + failed + ' failed';
            if (waiting > 0) pillText += (pillText ? ', ' : '') + waiting + ' waiting';
            if (relayWaiting > 0) pillText += (pillText ? ', ' : '') + relayWaiting + ' message' + (relayWaiting > 1 ? 's' : '') + ' waiting';
            // pending_approval leads the pill text (before "failed") when present:
            // a proposal sitting unread is the one state that needs a human to act,
            // not just notice.
            if (pending > 0) pillText = pending + ' need' + (pending > 1 ? '' : 's') + ' approval' + (pillText ? ', ' + pillText : '');
            var dotClass = pending > 0 ? 'dot approve'
                : (failed > 0 ? 'dot bad'
                : (stalled > 0 ? 'dot stall'
                : (running > 0 ? 'dot spin'
                : (relayWaiting > 0 ? 'dot relay' : 'dot wait'))));

            var html = '';
            if (this._open) {
                html += '<div class="panel"><div class="panel-head"><span>Activity</span>'
                    + '<button type="button" class="close" data-act="toggle" title="Collapse" aria-label="Collapse activity">&#x2715;</button></div>';
                if (all.length === 0) {
                    html += '<div class="empty">No activity</div>';
                } else {
                    html += all.map(this._row, this).join('');
                }
                html += '</div>';
            }
            var fullText = pillText || all.length + ' item' + (all.length > 1 ? 's' : '');
            var shortText = _pillShortText({ running: running, stalled: stalled, failed: failed, waiting: waiting, pending: pending, relay: relayWaiting }, all.length);
            html += '<button type="button" class="pill" data-act="toggle" aria-expanded="' + (this._open ? 'true' : 'false') + '"'
                + ' aria-label="Activity: ' + esc(fullText) + '" title="' + esc(fullText) + '">'
                + '<span class="' + dotClass + '" aria-hidden="true"></span>'
                + '<span class="full">' + esc(fullText) + '</span>'
                + '<span class="short" aria-hidden="true">' + esc(shortText) + '</span></button>';

            this._root.classList.toggle('open', !!this._open);
            this._root.innerHTML = html;
            this._positionAboveFooter();   // re-measure: footer height varies by viewport/version

            this._root.querySelectorAll('[data-act]').forEach(function (el) {
                el.addEventListener('click', function (ev) {
                    ev.stopPropagation();
                    var act = el.getAttribute('data-act');
                    var opId = el.getAttribute('data-opid') || '';
                    var isLocal = el.getAttribute('data-local') === '1';
                    if (act === 'toggle') { self._open = !self._open; self._render(); return; }
                    // RELAY_WAITING_PILL.md "Planned changes (2026-09-11)": Dismiss is
                    // now server-wide, through the SAME dismiss_activity action every
                    // other row uses — the server records the dismissal beside the
                    // queue file and publishes {opId,dismissed:true}, which _merge()
                    // removes the row on for every device. Hide it optimistically here
                    // too, so this device does not wait on the round trip; the durable
                    // Relay queue (and the message inside it) is never touched either
                    // way — a new arrival re-raises the pill on every device.
                    if (act === 'dismiss_relay_waiting') {
                        self._relayDismissed[opId] = parseInt(el.getAttribute('data-count'), 10) || 0;
                        self._render();
                        self._action('dismiss_activity', opId, false);
                        return;
                    }
                    // "Look, then force": the first click never touches the pane. It
                    // takes the operator to the workspace the message is waiting for,
                    // and only then does the button become Force inject.
                    if (act === 'goto_relay_workspace') {
                        self._gotoWorkspace(el.getAttribute('data-session') || '');
                        self._relayArmed[opId] = true;
                        delete self._relayNote[opId];
                        self._render();
                        return;
                    }
                    self._action(act, opId, isLocal);
                });
            });
        }

        _row(a) {
            var isLocal = !!this._local[a.opId] && this._activities.every(function (sa) { return sa.opId !== a.opId; });
            var active = !!ACTIVE[a.status];
            // Tier 3 (PLUGIN_MANAGEMENT_TOOLS.md "Phase 3 as built", 2026-09-09): a
            // pending item gets Approve/Reject instead of the generic Cancel/Dismiss
            // — ActivityService refuses cancel()/dismiss() on this status precisely so
            // a human cannot bypass this explicit, recorded choice.
            var pending = a.status === 'pending_approval';
            // RELAY_WAITING_PILL.md (2026-09-09): a Relay notice the readiness gate
            // held back. Two clicks, never one: the first opens the workspace so the
            // operator can SEE the pane, the second forces the notice into it through
            // the EXISTING drain for that session. Dismiss is local-only (see
            // _render()'s filter) — it never discards the message.
            var relayWaiting = a.status === 'relay_waiting';
            var btns = '';
            if (pending) {
                btns += '<button class="btn approve" data-act="approve_activity" data-opid="' + esc(a.opId) + '">Approve</button>';
                btns += '<button class="btn" data-act="reject_activity" data-opid="' + esc(a.opId) + '">Reject</button>';
            }
            if (relayWaiting) {
                var session = (a.meta && a.meta.sessionId) || '';
                var ws = a.workspace || (a.meta && a.meta.workspace) || '';
                if (this._relayArmed[a.opId] && (a.reasonCode === 'own-notice-unsent' || a.reasonCode === 'own-notice-stuck')) {
                    // #320: the notice is already typed in the agent's input box. The
                    // server presses Enter only for this case, so say exactly that.
                    btns += '<button class="btn force" data-act="deliver_relay_waiting" data-opid="' + esc(a.opId) + '"'
                        + ' title="The notice is already typed in the agent\'s input box. This presses Enter only. Nothing is typed again.">Press Enter</button>';
                } else if (this._relayArmed[a.opId]) {
                    btns += '<button class="btn force" data-act="deliver_relay_waiting" data-opid="' + esc(a.opId) + '"'
                        + ' title="Types the message into the agent now and presses Enter, without waiting for the plugin to judge the screen idle.'
                        + ' Use this when you can see the agent is idle but the plugin cannot tell — an agent that changed its interface can hold a message for ever.'
                        + ' Anything half-typed in that box goes with it.">Force inject</button>';
                } else {
                    btns += '<button class="btn deliver" data-act="goto_relay_workspace" data-opid="' + esc(a.opId) + '"'
                        + ' data-session="' + esc(session) + '"'
                        + ' title="Opens this workspace so you can see what the agent is doing. Nothing is sent yet.">'
                        + esc(ws ? 'Go to ' + ws : 'Go to workspace') + '</button>';
                }
                btns += '<button class="btn" data-act="dismiss_relay_waiting" data-opid="' + esc(a.opId) + '" data-count="' + esc(a.count) + '"'
                    + ' title="Hides this until a new message arrives. Nothing is discarded — it stays in the inbox.">Dismiss</button>';
            }
            if (active) {
                btns += '<button class="btn" data-act="cancel_activity" data-opid="' + esc(a.opId) + '">Cancel</button>';
            }
            if (!pending && !relayWaiting && (!active || a.status === 'stalled')) {
                btns += '<button class="btn" data-act="dismiss_activity" data-opid="' + esc(a.opId) + '"'
                    + (isLocal ? ' data-local="1"' : '') + '>Dismiss</button>';
            }
            // Recovery hook — currently `retry` (auto-launch re-run, T-10).
            if (a.status === 'failed' && a.recovery === 'retry' && !isLocal) {
                btns += '<button class="btn retry" data-act="retry_auto_launch" data-opid="' + esc(a.opId) + '">Retry</button>';
            }
            var pct = Math.max(0, Math.min(100, parseInt(a.progress, 10) || 0));
            // The pending item's own label IS the full plain-language consequence
            // (AdminService's validateX() built it; ActivityService::propose() set
            // it as 'label') — show it in full below the (possibly truncated) title,
            // never just in the title's hover tooltip, since a human must read this
            // before clicking Approve.
            return '<div class="row">'
                + '<div class="top"><span class="label" title="' + esc(a.label) + '">' + esc(a.label || a.opId) + '</span>'
                + '<span class="status ' + esc(a.status) + '">' + esc(a.status === 'pending_approval' ? 'needs approval' : (relayWaiting ? 'waiting' : a.status)) + '</span></div>'
                + (pending && a.label ? '<div class="consequence">' + esc(a.label) + '</div>' : '')
                // Plain-language reason the notice was held (TmuxService::relayHeldReasonLabel()).
                + (relayWaiting && a.reason ? '<div class="consequence">Held because ' + esc(a.reason) + '.</div>' : '')
                // What the last Force inject actually did — see _action().
                + (relayWaiting && this._relayNote[a.opId] ? '<div class="consequence">' + esc(this._relayNote[a.opId]) + '</div>' : '')
                + (a.step && !pending ? '<div class="step">' + esc(stepText(a)) + '</div>' : '')
                + (a.error && a.status === 'failed' ? '<div class="err">' + esc(a.error) + '</div>' : '')
                + (active ? '<div class="bar' + (a.status === 'stalled' ? ' stalled' : '') + '"><div style="width:' + pct + '%"></div></div>' : '')
                + (btns ? '<div class="btns">' + btns + '</div>' : '')
                + '</div>';
        }
    }

    // Pure layout helpers, read by ui-build/src/__tests__/activityTrayLayout.test.ts.
    AicliActivityTray.layout = { clearAvoid: _clearAvoid, pillShortText: _pillShortText };

    if (!customElements.get('aicli-activity-tray')) {
        customElements.define('aicli-activity-tray', AicliActivityTray);
    }

    // Self-mount: pages only need the <script> include.
    function mount() {
        if (!document.querySelector('aicli-activity-tray')) {
            document.body.appendChild(document.createElement('aicli-activity-tray'));
        }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', mount);
    } else {
        mount();
    }
})();
