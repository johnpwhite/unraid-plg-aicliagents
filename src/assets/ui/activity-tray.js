/**
 * <module_context>
 * Description: <aicli-activity-tray> — framework-free web component surfacing
 *   in-flight slow operations (install/upgrade/storage/migrate/start) from the
 *   ActivityService registry. Included by BOTH the jQuery manager page and the
 *   React terminal page (T-08/T-09/T-10 — docs/specs/ACTIVITY_TRAY.md).
 * Dependencies: none (vanilla JS; EventSource for Nchan, fetch for AJAX).
 * Constraints: collapsed pill bottom-right, raised to bottom:48px so it clears
 *   the fixed Dynamix footer/status line (OP#1381); z-index 10003 (above Unraid
 *   headers per repo standard 10002+). Subscribes Nchan /sub/aicli_activity with a 5 s
 *   polling fallback to list_activities when the stream errors. The poll is also
 *   the watchdog driver: each list_activities call evaluates stall/timeout
 *   transitions server-side, so we keep a slow 10 s poll while ops are active
 *   even when the stream is healthy.
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
        + '.wrap { position: fixed; bottom: 48px; right: 14px; z-index: 10003;'
        + '  font-family: clear-sans, sans-serif; font-size: 12px; color: var(--text-color, #e0e0e0); }'
        + '.pill { display: flex; align-items: center; gap: 7px; cursor: pointer; user-select: none;'
        + '  padding: 7px 14px; border-radius: 16px; border: 1px solid var(--border-color, #444);'
        + '  background: var(--title-header-background-color, #1c1b1b); color: var(--text-color, #e0e0e0);'
        + '  box-shadow: 0 4px 14px rgba(0,0,0,0.4); font-weight: 600; }'
        + '.pill .dot { width: 8px; height: 8px; border-radius: 50%; background: var(--orange, #e68a00); }'
        + '.pill .dot.spin { animation: aicli-act-pulse 1.2s ease-in-out infinite; }'
        + '.pill .dot.bad { background: #d9534f; animation: none; }'
        + '.pill .dot.stall { background: #eab308; animation: none; }'
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
        + '.status.running { background: rgba(230,138,0,0.18); color: var(--orange, #e68a00); }'
        + '.status.stalled { background: rgba(234,179,8,0.18); color: #eab308; }'
        + '.status.failed  { background: rgba(217,83,79,0.18); color: #d9534f; }'
        + '.status.done    { background: rgba(0,128,64,0.2); color: #4caf50; }'
        + '.row .step { margin-top: 4px; font-family: monospace; font-size: 11px; opacity: 0.65; }'
        + '.row .err { margin-top: 4px; font-size: 11px; color: #d9534f; word-break: break-word; }'
        + '.bar { margin-top: 6px; height: 4px; border-radius: 2px; overflow: hidden;'
        + '  background: rgba(255,255,255,0.08); }'
        + '.bar > div { height: 100%; background: var(--orange, #e68a00); transition: width 0.3s ease-out; }'
        + '.bar.stalled > div { background: #eab308; }'
        + '.btns { margin-top: 7px; display: flex; gap: 6px; }'
        + '.btn { cursor: pointer; padding: 3px 10px; font-size: 11px; border-radius: 4px;'
        + '  border: 1px solid var(--border-color, #555); background: transparent; color: inherit; }'
        + '.btn:hover { background: rgba(255,255,255,0.06); }'
        + '.btn.retry { border-color: var(--orange, #e68a00); color: var(--orange, #e68a00); font-weight: 700; }'
        + '.empty { padding: 16px 12px; text-align: center; opacity: 0.5; }';

    var ACTIVE = { running: 1, stalled: 1 };

    class AicliActivityTray extends HTMLElement {
        constructor() {
            super();
            this._activities = [];   // server entries
            this._local = {};        // client-only entries (e.g. auto-launch fetch .catch) keyed by opId
            this._open = false;
            this._es = null;
            this._esBroken = false;
            this._pollTimer = null;
            this._onLocal = this._onLocal.bind(this);
            this.attachShadow({ mode: 'open' });
        }

        connectedCallback() {
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
            this._subscribe();
            this._poll();           // initial snapshot
            this._schedulePoll();
            this._render();
        }

        disconnectedCallback() {
            window.removeEventListener('aicli-activity-local', this._onLocal);
            if (this._onResize) window.removeEventListener('resize', this._onResize);
            if (this._es) { try { this._es.close(); } catch (e) { /* noop */ } this._es = null; }
            if (this._pollTimer) clearTimeout(this._pollTimer);
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
            this._root.style.bottom = bottom + 'px';
        }

        // ---- data flow ------------------------------------------------------

        _subscribe() {
            if (typeof EventSource === 'undefined') { this._esBroken = true; return; }
            try {
                var self = this;
                this._es = new EventSource('/sub/aicli_activity');
                this._es.onmessage = function (msg) {
                    self._esBroken = false;
                    var data;
                    try { data = JSON.parse(msg.data); } catch (e) { return; }
                    if (!data || !data.opId) return;
                    self._merge(data);
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

        _poll() {
            var self = this;
            fetch(ajaxUrl('list_activities'))
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data && data.status === 'ok' && Array.isArray(data.activities)) {
                        self._activities = data.activities;
                        // Drop local placeholders the server now knows about.
                        data.activities.forEach(function (a) { delete self._local[a.opId]; });
                        self._emit();
                        self._render();
                    }
                })
                .catch(function () { /* server unreachable — keep last state */ });
        }

        _schedulePoll() {
            var self = this;
            // 5 s when the Nchan stream is broken (fallback), 10 s while ops are
            // active (drives the server-side watchdog evaluation), 30 s idle.
            var hasActive = this._all().some(function (a) { return ACTIVE[a.status]; });
            var interval = this._esBroken ? 5000 : (hasActive ? 10000 : 30000);
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
                .then(function () { self._poll(); })
                .catch(function () { /* next poll re-syncs */ });
        }

        // ---- rendering -------------------------------------------------------

        _render() {
            var all = this._all();
            var running = all.filter(function (a) { return a.status === 'running'; }).length;
            var stalled = all.filter(function (a) { return a.status === 'stalled'; }).length;
            var failed = all.filter(function (a) { return a.status === 'failed'; }).length;

            // Invisible when idle: transient `done` entries (every successful
            // session start produces one for up to 60 s) must not summon the pill.
            if (all.length === 0 || (!this._open && running + stalled + failed === 0)) {
                this._root.innerHTML = '';
                return;
            }

            var pillText = running > 0 ? running + ' task' + (running > 1 ? 's' : '') + ' running' : '';
            if (stalled > 0) pillText += (pillText ? ', ' : '') + stalled + ' stalled';
            if (failed > 0) pillText += (pillText ? ', ' : '') + failed + ' failed';
            var dotClass = failed > 0 ? 'dot bad' : (stalled > 0 ? 'dot stall' : 'dot spin');

            var html = '';
            if (this._open) {
                html += '<div class="panel"><div class="panel-head"><span>Activity</span>'
                    + '<span class="close" data-act="toggle" title="Collapse">&#x2715;</span></div>';
                if (all.length === 0) {
                    html += '<div class="empty">No activity</div>';
                } else {
                    html += all.map(this._row, this).join('');
                }
                html += '</div>';
            }
            html += '<div class="pill" data-act="toggle"><span class="' + dotClass + '"></span>'
                + '<span>' + esc(pillText || all.length + ' item' + (all.length > 1 ? 's' : '')) + '</span></div>';

            this._root.innerHTML = html;
            this._positionAboveFooter();   // re-measure: footer height varies by viewport/version

            var self = this;
            this._root.querySelectorAll('[data-act]').forEach(function (el) {
                el.addEventListener('click', function (ev) {
                    ev.stopPropagation();
                    var act = el.getAttribute('data-act');
                    var opId = el.getAttribute('data-opid') || '';
                    var isLocal = el.getAttribute('data-local') === '1';
                    if (act === 'toggle') { self._open = !self._open; self._render(); return; }
                    self._action(act, opId, isLocal);
                });
            });
        }

        _row(a) {
            var isLocal = !!this._local[a.opId] && this._activities.every(function (sa) { return sa.opId !== a.opId; });
            var active = !!ACTIVE[a.status];
            var btns = '';
            if (active) {
                btns += '<button class="btn" data-act="cancel_activity" data-opid="' + esc(a.opId) + '">Cancel</button>';
            }
            if (!active || a.status === 'stalled') {
                btns += '<button class="btn" data-act="dismiss_activity" data-opid="' + esc(a.opId) + '"'
                    + (isLocal ? ' data-local="1"' : '') + '>Dismiss</button>';
            }
            // Recovery hook — currently `retry` (auto-launch re-run, T-10).
            if (a.status === 'failed' && a.recovery === 'retry' && !isLocal) {
                btns += '<button class="btn retry" data-act="retry_auto_launch" data-opid="' + esc(a.opId) + '">Retry</button>';
            }
            var pct = Math.max(0, Math.min(100, parseInt(a.progress, 10) || 0));
            return '<div class="row">'
                + '<div class="top"><span class="label" title="' + esc(a.label) + '">' + esc(a.label || a.opId) + '</span>'
                + '<span class="status ' + esc(a.status) + '">' + esc(a.status) + '</span></div>'
                + (a.step ? '<div class="step">' + esc(stepText(a)) + '</div>' : '')
                + (a.error && a.status === 'failed' ? '<div class="err">' + esc(a.error) + '</div>' : '')
                + (active ? '<div class="bar' + (a.status === 'stalled' ? ' stalled' : '') + '"><div style="width:' + pct + '%"></div></div>' : '')
                + (btns ? '<div class="btns">' + btns + '</div>' : '')
                + '</div>';
        }
    }

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
