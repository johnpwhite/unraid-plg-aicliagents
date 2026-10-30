/**
 * <module_context>
 *     <name>aicli-events</name>
 *     <description>The ONE live-update connection of a plugin page
 *     (docs/specs/EVENT_STREAM_MULTIPLEX.md). It opens a single multiplexed
 *     Nchan EventSource for every channel the page needs and fans each message
 *     out to the tray, voice.js, the Manager scripts and the React SPA, so a
 *     page no longer holds one connection per feature (eight, before this).</description>
 *     <dependencies>None (vanilla JS). `window.AICLI_EVENT_CHANNELS` — the
 *     ordered short channel names, emitted by src/includes/ui/EventStream.php
 *     BEFORE this script.</dependencies>
 *     <constraints>Loaded as a classic script before every consumer. Never
 *     decides anything from a message: nchan replays each channel's buffer on
 *     the first connect, however old, so every consumer keeps its own rule
 *     (payload + `ts`, never arrival). The source channel of a message is the
 *     bracketed tag position in its id (`<time>:0,[1],-` = second channel in
 *     URL order) — verified on nginx 1.30.3 / nchan, 2026-09-17. An id that
 *     cannot be parsed fires `aicli-reconcile` instead of guessing.</constraints>
 * </module_context>
 *
 * Contract:
 *   window `aicli-event`         detail {channel, data, raw, id}   one per message
 *   window `aicli-reconcile`     detail {reason}                   stream (re)opened, or reconcile()
 *   window `aicli-event-status`  detail {status}                   connecting|open|broken|unsupported
 *   window.aicliEvents = {status, lastTs, channels, on(channel, fn) -> off, reconcile()}
 *   window.__aicliEventStats = {reconcile: {<reason>: n}, opens, reopens}   counters only, for the
 *     Notebook summary line; more than 10 reopens in 60 s sends one WARN via aicli_log_to_server
 *     (at most once per 10 minutes). Nothing is sent per event.
 *
 * `on()` replays to the new listener the events of that channel buffered in
 * the first BUFFER_MS after this script loaded: the SPA is a deferred module
 * and mounts after the stream's first-connect replay has already arrived.
 */
(function () {
    'use strict';

    // Defensive: a second include must not open a second connection.
    if (window.aicliEvents) return;

    var BUFFER_MS = 5000;
    var PREFIX = 'aicli_';
    var loadedAt = Date.now();
    var buffer = [];            // [{channel, data, raw, id}] — first BUFFER_MS only
    var listeners = {};         // channel -> [fn]

    var channels = [];
    (Array.isArray(window.AICLI_EVENT_CHANNELS) ? window.AICLI_EVENT_CHANNELS : []).forEach(function (c) {
        // Short names only ([A-Za-z0-9_.-]); a comma would split the URL and
        // shift every later bracket index onto the wrong channel.
        if (typeof c === 'string' && /^[A-Za-z0-9_.\-]+$/.test(c) && channels.indexOf(c) === -1) channels.push(c);
    });

    // Counters for the Notebook hang trace (docs/specs/NOTEBOOK_HANG_OBSERVABILITY.md R10).
    // Plain numbers only; a consumer reads them and keeps its own last-seen copy.
    //   reconcile: {<reason>: n}  aicli-reconcile events by reason
    //   opens: n                  how many times the stream opened (the first open included)
    //   reopens: n                opens after the first
    var stats = { reconcile: {}, opens: 0, reopens: 0 };
    window.__aicliEventStats = stats;
    var REOPEN_WINDOW_MS = 60000;   // more than REOPEN_LIMIT reopens in this window -> one WARN line
    var REOPEN_LIMIT = 10;
    var WARN_EVERY_MS = 600000;     // at most one such WARN per 10 minutes
    var reopenTimes = [];
    var lastReopenWarn = null;

    var api = {
        status: 'connecting',
        lastTs: 0,
        channels: channels.slice(),
        on: on,
        reconcile: function () { fireReconcile('manual'); }
    };
    window.aicliEvents = api;

    function fire(name, detail) {
        try { window.dispatchEvent(new CustomEvent(name, { detail: detail })); } catch (e) { /* very old browser */ }
    }

    function fireReconcile(reason) {
        stats.reconcile[reason] = (stats.reconcile[reason] || 0) + 1;
        fire('aicli-reconcile', { reason: reason });
    }

    /** One WARN line at once when the stream keeps reopening. Never one line per event. */
    function noteReopen() {
        stats.opens += 1;
        if (stats.opens < 2) return; // the first open is not a reopen
        stats.reopens += 1;
        var now = Date.now();
        reopenTimes.push(now);
        while (reopenTimes.length && now - reopenTimes[0] > REOPEN_WINDOW_MS) reopenTimes.shift();
        if (reopenTimes.length <= REOPEN_LIMIT) return;
        if (lastReopenWarn !== null && now - lastReopenWarn < WARN_EVERY_MS) return;
        lastReopenWarn = now;
        try {
            if (window.AICLI_PERF_TRACE === true && typeof window.aicli_log_to_server === 'function') {
                window.aicli_log_to_server('event stream reopened ' + reopenTimes.length + ' times in 60 s', 1, 'EventStream');
            }
        } catch (e) { /* diagnostics only */ }
    }

    function setStatus(status) {
        if (api.status === status) return;
        api.status = status;
        fire('aicli-event-status', { status: status });
    }

    function on(channel, fn) {
        if (typeof fn !== 'function') return function () {};
        (listeners[channel] = listeners[channel] || []).push(fn);
        if (Date.now() - loadedAt <= BUFFER_MS) {
            buffer.forEach(function (evt) {
                if (evt.channel === channel) { try { fn(evt); } catch (e) { /* one consumer must not break another */ } }
            });
        }
        return function off() {
            var list = listeners[channel] || [];
            var i = list.indexOf(fn);
            if (i !== -1) list.splice(i, 1);
        };
    }

    /**
     * Which channel produced this message? nchan writes one tag per channel,
     * in URL order, and brackets the producer's: `1789635490:0,[0],-` -> 1.
     * A single-channel subscription has no brackets at all -> 0.
     */
    function channelIndex(id) {
        if (typeof id !== 'string') return -1;
        var colon = id.indexOf(':');
        if (colon === -1) return -1;
        var tags = id.slice(colon + 1).split(',');
        if (tags.length === 1) return channels.length === 1 ? 0 : -1;
        for (var i = 0; i < tags.length; i++) {
            if (tags[i].charAt(0) === '[') return i;
        }
        return -1;
    }
    api._channelIndex = channelIndex; // test seam

    function deliver(msg) {
        var idx = channelIndex(msg.lastEventId);
        var channel = idx >= 0 ? channels[idx] : undefined;
        if (!channel) {
            // Cannot tell whose message this is: every consumer re-reads instead.
            fireReconcile('unparsed-id');
            return;
        }
        var data;
        try { data = JSON.parse(msg.data); } catch (e) { return; }
        if (data && typeof data.ts === 'number' && data.ts > api.lastTs) api.lastTs = data.ts;
        var evt = { channel: channel, data: data, raw: msg.data, id: msg.lastEventId };
        if (Date.now() - loadedAt <= BUFFER_MS) buffer.push(evt);
        fire('aicli-event', evt);
        (listeners[channel] || []).slice().forEach(function (fn) {
            try { fn(evt); } catch (e) { /* one consumer must not break another */ }
        });
    }

    // #857: when the Unraid sign-in of an open tab ends, nginx answers every plugin request
    // and every stream request with a redirect to /login. The tab went on polling, so each
    // poll and each stream reopen loaded the login page; in 11 s that hit nginx's login rate
    // limit (zone "authlimit"), which can refuse the person's own next sign-in. The first
    // plugin request that ends on /login marks the page signed out: no plugin request and no
    // stream reopen leaves the page after that, and one bar asks the person to sign in again.
    // This script loads before every consumer, so it sees every fetch and every XHR (jQuery).
    var signedOut = false;
    api.signedOut = false;

    function pathOf(url) {
        try { return new URL(String(url), window.location.href).pathname; } catch (e) { return ''; }
    }
    function isPluginUrl(url) {
        var p = pathOf(url);
        return p.indexOf('/plugins/unraid-aicliagents/') === 0 || p.indexOf('/sub/aicli_') === 0;
    }

    function showSignedOutBar() {
        if (!document.body || document.getElementById('aicli-signed-out-bar')) return;
        var bar = document.createElement('div');
        bar.id = 'aicli-signed-out-bar';
        bar.setAttribute('role', 'alert');
        bar.style.cssText = 'position:fixed;top:0;left:0;right:0;z-index:10002;display:flex;flex-wrap:wrap;'
            + 'align-items:center;justify-content:center;gap:12px;padding:10px 16px;'
            + 'background:#7a4b00;color:#fff;font-size:14px;line-height:1.4';
        var text = document.createElement('span');
        text.textContent = 'Your Unraid sign-in has ended. This page stopped asking the server for updates.';
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.textContent = 'Sign in again';
        btn.style.cssText = 'min-height:44px;padding:0 16px;margin:0';
        btn.addEventListener('click', function () { window.location.reload(); });
        bar.appendChild(text);
        bar.appendChild(btn);
        document.body.appendChild(bar);
    }

    function markSignedOut() {
        if (signedOut) return;
        signedOut = true;
        api.signedOut = true;
        if (reconnectTimer !== null) { clearTimeout(reconnectTimer); reconnectTimer = null; }
        try { if (api._es) api._es.close(); } catch (e) { /* already closed */ }
        setStatus('signed-out');
        fire('aicli-signed-out', {});
        if (document.body) showSignedOutBar();
        else document.addEventListener('DOMContentLoaded', showSignedOutBar);
    }
    api._markSignedOut = markSignedOut; // test seam

    // The wrappers are installed once per page and read the state from window.aicliEvents
    // when they run, never from the closure of the load that installed them.
    function pageSignedOut() { return !!(window.aicliEvents && window.aicliEvents.signedOut); }
    function notePageSignedOut() {
        if (window.aicliEvents && typeof window.aicliEvents._markSignedOut === 'function') window.aicliEvents._markSignedOut();
    }

    var nativeFetch = window.fetch;
    if (typeof nativeFetch === 'function' && nativeFetch.__aicliNative) nativeFetch = nativeFetch.__aicliNative;
    if (typeof window.fetch === 'function' && !window.fetch.__aicliNative) {
        var pageFetch = window.fetch;
        var guardedFetch = function (input) {
            var url = (input && typeof input === 'object' && 'url' in input) ? input.url : input;
            if (pageSignedOut() && isPluginUrl(url)) {
                // "Failed to fetch" is network noise to the page's error handler: no toast.
                return Promise.reject(new TypeError('Failed to fetch (signed out)'));
            }
            return pageFetch.apply(this, arguments).then(function (res) {
                if (res && res.redirected && pathOf(res.url) === '/login' && isPluginUrl(url)) notePageSignedOut();
                return res;
            });
        };
        guardedFetch.__aicliNative = pageFetch;
        window.fetch = guardedFetch;
    }

    var XHR = window.XMLHttpRequest;
    if (XHR && XHR.prototype && !XHR.prototype.__aicliSignedOutGuard) {
        var nativeOpen = XHR.prototype.open;
        var nativeSend = XHR.prototype.send;
        XHR.prototype.__aicliSignedOutGuard = true;
        XHR.prototype.open = function (method, url) {
            this.__aicliUrl = url;
            return nativeOpen.apply(this, arguments);
        };
        XHR.prototype.send = function () {
            var xhr = this;
            if (pageSignedOut() && isPluginUrl(xhr.__aicliUrl)) {
                // Never sent: the caller sees an aborted request, as after a lost network.
                try { xhr.abort(); } catch (e) { /* not open */ }
                return undefined;
            }
            if (isPluginUrl(xhr.__aicliUrl)) {
                xhr.addEventListener('loadend', function () {
                    if (xhr.responseURL && pathOf(xhr.responseURL) === '/login') notePageSignedOut();
                });
            }
            return nativeSend.apply(this, arguments);
        };
    }

    function connect() {
        if (signedOut) return;
        if (!channels.length) { setStatus('unsupported'); return; }
        if (typeof EventSource === 'undefined') { setStatus('unsupported'); return; }
        try {
            var url = '/sub/' + channels.map(function (c) { return PREFIX + c; }).join(',');
            var es = new EventSource(url);
            api._es = es;
            es.onopen = function () {
                setStatus('open');
                reconnectDelay = 2000;
                // One reconcile per (re)connect for EVERY consumer: it closes
                // the gap a depth-1 buffer cannot replay.
                noteReopen();
                fireReconcile('open');
            };
            es.onmessage = deliver;
            // EventSource reconnects by itself and resumes per channel with
            // Last-Event-ID; consumers tighten their fallback read meanwhile.
            // #657: after one non-200 answer (a deploy, an nginx reload) the browser sets
            // readyState CLOSED (2) and never retries. Then this script opens a new stream
            // itself, after 2 s, 4 s, 8 s ... up to 30 s. A good open resets the delay.
            es.onerror = function () {
                setStatus('broken');
                if (es.readyState === 2) scheduleReconnect(es);
            };
        } catch (e) {
            setStatus('unsupported');
        }
    }

    var reconnectDelay = 2000;
    var reconnectTimer = null;
    function scheduleReconnect(deadEs) {
        if (reconnectTimer !== null) return;
        try { deadEs.close(); } catch (e) { /* already closed */ }
        if (signedOut) return;
        reconnectTimer = setTimeout(function () {
            reconnectTimer = null;
            // #857: a stream request after the sign-in ended follows the redirect to /login
            // and gives the script no address to read. One small request that does not
            // follow redirects tells the two cases apart before the stream opens again.
            if (typeof nativeFetch !== 'function') { connect(); return; }
            nativeFetch.call(window, '/plugins/unraid-aicliagents/AICliAjax.php?action=get_asset_version',
                { redirect: 'manual', credentials: 'same-origin', cache: 'no-store' })
                .then(function (res) {
                    if (res && res.type === 'opaqueredirect') markSignedOut();
                    else connect();
                }, function () { connect(); });
        }, reconnectDelay);
        reconnectDelay = Math.min(reconnectDelay * 2, 30000);
    }

    // The replay buffer only serves consumers that mount in the first seconds.
    setTimeout(function () { buffer = []; }, BUFFER_MS + 50);

    connect();
})();
