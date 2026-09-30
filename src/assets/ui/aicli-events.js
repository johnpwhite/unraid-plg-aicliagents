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

    var api = {
        status: 'connecting',
        lastTs: 0,
        channels: channels.slice(),
        on: on,
        reconcile: function () { fire('aicli-reconcile', { reason: 'manual' }); }
    };
    window.aicliEvents = api;

    function fire(name, detail) {
        try { window.dispatchEvent(new CustomEvent(name, { detail: detail })); } catch (e) { /* very old browser */ }
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
            fire('aicli-reconcile', { reason: 'unparsed-id' });
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

    function connect() {
        if (!channels.length) { setStatus('unsupported'); return; }
        if (typeof EventSource === 'undefined') { setStatus('unsupported'); return; }
        try {
            var url = '/sub/' + channels.map(function (c) { return PREFIX + c; }).join(',');
            var es = new EventSource(url);
            api._es = es;
            es.onopen = function () {
                setStatus('open');
                // One reconcile per (re)connect for EVERY consumer: it closes
                // the gap a depth-1 buffer cannot replay.
                fire('aicli-reconcile', { reason: 'open' });
            };
            es.onmessage = deliver;
            // EventSource reconnects by itself and resumes per channel with
            // Last-Event-ID; consumers tighten their fallback read meanwhile.
            es.onerror = function () { setStatus('broken'); };
        } catch (e) {
            setStatus('unsupported');
        }
    }

    // The replay buffer only serves consumers that mount in the first seconds.
    setTimeout(function () { buffer = []; }, BUFFER_MS + 50);

    connect();
})();
