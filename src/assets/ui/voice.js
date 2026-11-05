/**
 * <module_context>
 * Description: Agent voice (docs/specs/AGENT_VOICE.md R4/R7/R8;
 *   docs/specs/VOICE_SWITCHES.md R7) — framework-free script, included by
 *   BOTH the React terminal page and the jQuery Manager page (same pattern
 *   as activity-tray.js). There is no per-device on/off state any more: the
 *   only switch is the server-side `voice_enabled` setting. This module owns
 *   the `EventSource('/sub/aicli_voice')` subscription, the last state it
 *   was told about, a small bounded queue, and playback (`speechSynthesis`
 *   for `mode:"speech"`, an `<audio>` element for `mode:"audio"`).
 * Dependencies: none (vanilla JS; EventSource for Nchan, Web Speech API,
 *   HTMLMediaElement). Never depends on jQuery — the terminal SPA page does
 *   not load it.
 * Constraints: the queue/replay-guard/spoken-text rules here MUST mirror
 *   ui-build/src/lib/voiceQueue.ts exactly (that file is the tested source of
 *   truth; this is a plain-JS copy because a framework-free page script
 *   cannot import a bundled module) — change both together.
 *   Replay guard: a message whose `ts` (server epoch ms) is older than this
 *   page's load time is dropped. This is the one channel where "older than
 *   page load" is correct (AGENT_VOICE.md "Browser" design section) — a
 *   voice clip is an one-shot attention signal, not state to reconcile, so a
 *   replay from the channel's one-message nchan buffer must never speak
 *   again on a fresh page load or reconnect.
 *   State: `{mode:"state", enabled, ts}` on the same channel reports the
 *   current `voice_enabled` value (VOICE_SWITCHES.md R8). This module never
 *   plays a `state` message — it only records the value and dispatches
 *   `aicli-voice-state`. A caller (the SPA or the Settings card) also feeds
 *   in the value it read on its own config load through `setState(bool)`,
 *   so a fresh tab knows the state before the first `state` message arrives.
 *   Unlock gesture: the first click anywhere in the page runs a silent
 *   unlock (no sound). The drawer/Settings toggle unlocks synchronously and
 *   then asks the same server-side test path used by the Settings card, so a
 *   configured TTS engine supplies the confirmation and browser speech is the
 *   bounded fallback.
 *   Input (docs/specs/VOICE_INPUT.md): the reverse direction — the operator
 *   dictates INTO a workspace. `startInput(opts)`/`stopInput()`/`inputState()`
 *   own the microphone, the recognizer or recorder, and a 5-minute auto-stop.
 *   R11 "Live typing": words go to the caller AS THEY ARE SPOKEN, phrase by
 *   phrase, never corrected afterwards. With `opts.sttUrl` empty, browser
 *   mode reads speech with `SpeechRecognition`/`webkitSpeechRecognition`
 *   entirely on this device; the moment a recogniser result becomes final it
 *   is dispatched at once as `{state:'recording', phrase:'<text>'}` — the
 *   caller types it right away — while the interim words of the phrase still
 *   in progress keep arriving on the existing `{interim, seconds}` fields.
 *   With `opts.sttUrl` set, engine mode records in PIECES with
 *   `MediaRecorder`: a Web Audio API `AnalyserNode` on the same stream
 *   measures loudness and cuts a piece after 700 ms of silence following
 *   speech, or after 8 s of continuous audio, whichever comes first; each
 *   piece is transcribed through `voice_transcribe` and, once recognised
 *   non-empty, dispatched the same `phrase` way, in order, while the next
 *   piece is already recording (an empty piece is dropped with no event). A
 *   stop — the operator's own tap, Escape, `Ctrl+Shift+M`, the recogniser
 *   ending on its own, or the 5-minute cap — flushes whatever is still
 *   pending (the interim words in progress, or the current engine piece) as
 *   one last `{state:'idle', text}` event, then a caller decides whether to
 *   press Enter (VOICE_INPUT.md's `decideStopDelivery`); it is never
 *   silently discarded. A failed piece transcription (engine mode only)
 *   reports `{state:'recording', error}` and recording continues — only a
 *   true refusal (no recogniser, no mic permission, no workspace) ends the
 *   recording. This module never types the text anywhere, never calls
 *   `voice_dictate`, and never decides to press Enter — the caller (the
 *   SPA's mic bubble, or the Manager Settings "Test" button) reads the
 *   phrases and the final text from the event and decides what to do with
 *   them, including how to show its OWN delivery failures (a phrase that
 *   failed to type).
 *   Not a vite build output: this file is committed directly into
 *   src/assets/ui/ (like activity-tray.js) and ci/run's "prune stale UI
 *   assets" build step must keep exempting it by name, or a build silently
 *   deletes it.
 * </module_context>
 */
(function () {
    'use strict';

    // Defensive: if this script is ever included twice on one page, the
    // second copy must not open a second EventSource or double-queue clips.
    if (window.__aicliVoiceInited) return;
    window.__aicliVoiceInited = true;

    var MAX_QUEUE = 3;
    var MAX_SPOKEN_CHARS = 500;
    // A stalled engine clip must never wedge the voice queue. This is a startup
    // watchdog; once play() reports that playback has started, the clip may run
    // for its full duration.
    var AUDIO_START_TIMEOUT_MS = 12000;
    // #670: a clip that started and then stops moving (the network stalls mid-clip, no ended
    // or error event) must not hold the queue for ever. A playing clip reports `timeupdate`
    // several times a second; this long without one ends it as heard.
    var AUDIO_STALL_MS = 30000;
    // #672: a voice_transcribe POST that never answers must not hold the ordered
    // piece queue, or the 'transcribing' state, for ever. The server's own call
    // to the engine is capped at 30 s (VOICE_INPUT.md R2); this is that bound
    // plus one round trip, so a wedged request ends like an engine failure.
    var TRANSCRIBE_TIMEOUT_MS = 45000;
    // #754: the engine failed on the LAST piece (after Stop). The browser cannot read a recorded clip,
    // so the same clip goes to the engine ONE more time after this short wait.
    var LAST_PIECE_RETRY_WAIT_MS = 2000;
    // VOICE_SWITCHES.md: the confirmation sentence when voice is turned on. The
    // server picks the same fixed text for purpose=switch (VoiceHandler).
    var VOICE_CONFIRM_TEXT = 'Voice enabled.';
    var pageLoadTs = Date.now();

    // ---- global on/off state (server-side voice_enabled) ------------------
    // No localStorage any more (VOICE_SWITCHES.md: the per-device switch is
    // removed). The last value this tab was told about, either through a
    // `state` message on the channel or through setState() from the page's
    // own config load. Starts false: a tab that has heard nothing yet stays
    // silent, same as `voice_enabled`'s own default.

    var lastKnownEnabled = false;

    function isEnabled() {
        return lastKnownEnabled;
    }
    function dispatchState() {
        try {
            window.dispatchEvent(new CustomEvent('aicli-voice-state', { detail: { enabled: lastKnownEnabled } }));
        } catch (e) { /* noop */ }
    }
    /** Record a new known state and tell every listener on this page. */
    function applyState(on) {
        lastKnownEnabled = !!on;
        dispatchState();
    }

    // ---- queue + rules -----------------------------------------------
    // Mirrors ui-build/src/lib/voiceQueue.ts createVoiceQueue/shouldPlay/
    // speechTextFor. Keep both copies in lock-step — see module header.

    var _queue = [];
    function queuePush(item) {
        // Drop the OLDEST queued item once at the cap, not the new arrival: a
        // voice notice is an attention signal, so the newest one is the one
        // still worth playing.
        if (_queue.length >= MAX_QUEUE) _queue.shift();
        _queue.push(item);
    }
    function queueNext() {
        return _queue.shift();
    }

    /** Drop when voice is off, or when the message predates this page load. */
    function shouldPlay(msg) {
        if (!isEnabled()) return false;
        if (typeof msg.ts === 'number' && msg.ts < pageLoadTs) return false;
        return true;
    }

    /** Intro-prefixed (VOICE_MAIL.md R14), length-capped text for speechSynthesis. */
    function speechTextFor(msg) {
        var text = String(msg.text == null ? '' : msg.text).replace(/^\s+|\s+$/g, '');
        var full;
        if (typeof msg.intro === 'string') {
            var intro = msg.intro.replace(/^\s+|\s+$/g, '');
            full = intro ? (intro + ' ' + text) : text;
        } else {
            full = msg.name ? (msg.name + ': ' + text) : text;
        }
        if (full.length <= MAX_SPOKEN_CHARS) return full;
        return full.slice(0, MAX_SPOKEN_CHARS - 1) + '…';
    }

    // ---- playback ----------------------------------------------------

    var playing = false;
    var playingTimer = null;
    // Chrome never fires `onend` on an utterance it has garbage-collected,
    // so the utterance in flight stays referenced here until it ends.
    var currentUtt = null;
    // Set when the browser refused speech. Chrome answers 'not-allowed' to
    // every speak() until the operator has clicked in this page since its
    // last load; a voice state that survived a reload in localStorage does
    // not carry that click with it. The refused clip goes back to the head
    // of the queue and plays inside the next click (see waitForGesture).
    var needsGesture = false;

    function pickVoice(name) {
        if (!name || !window.speechSynthesis) return null;
        try {
            var voices = window.speechSynthesis.getVoices() || [];
            for (var i = 0; i < voices.length; i++) {
                if (voices[i].name === name) return voices[i];
            }
        } catch (e) { /* fall through to the default voice */ }
        return null;
    }

    // Spoken messages are English. With no language the browser uses its own default voice,
    // which can be another language (a French voice reading English). The same rule as
    // ui-build/src/lib/voiceMail.ts: a voice the person chose keeps its own language;
    // with none, pick an English voice (British first), and name the language.
    var SPEECH_LANG = 'en-GB';
    function pickEnglishVoice() {
        if (!window.speechSynthesis) return null;
        try {
            var voices = window.speechSynthesis.getVoices() || [];
            for (var i = 0; i < voices.length; i++) {
                if (/^en[-_]gb$/i.test(voices[i].lang || '')) return voices[i];
            }
            for (var j = 0; j < voices.length; j++) {
                if (/^en([-_]|$)/i.test(voices[j].lang || '')) return voices[j];
            }
        } catch (e) { /* fall through to no voice */ }
        return null;
    }

    // ---- what is playing, and stop (docs/specs/VOICE_MAIL.md R15) ---------
    // The drawer shows a voice-wave stop button on the row of the workspace that
    // is speaking. It learns which one from the `aicli-voice-playing` event (and
    // playingWorkspace()), and stops it with stop(). `currentFinish` settles the
    // clip in flight; `currentAudio` is the element to pause.
    var playingWorkspaceId = null;
    var currentFinish = null;
    var currentAudio = null;
    // True while the piece in flight belongs to a note read aloud (see the reader section below).
    var currentReaderPiece = false;
    function setPlayingWorkspace(id) {
        var next = id ? String(id) : null;
        if (next === playingWorkspaceId) return;
        playingWorkspaceId = next;
        try {
            window.dispatchEvent(new CustomEvent('aicli-voice-playing', { detail: { workspaceId: playingWorkspaceId } }));
        } catch (e) { /* noop */ }
    }

    function finishPlaying() {
        if (playingTimer) { clearTimeout(playingTimer); playingTimer = null; }
        currentUtt = null;
        currentFinish = null;
        currentAudio = null;
        currentReaderPiece = false;
        playing = false;
        setPlayingWorkspace(null);
    }

    /**
     * The operator's own click on the row's stop button. Ends the clip at once:
     * no speech fallback, never "heard". Queued clips of the SAME workspace are
     * dropped; other workspaces still play. Returns true when something stopped.
     */
    function stopPlaying(workspaceId) {
        if (!playing) return false;
        var ws = playingWorkspaceId;
        if (workspaceId && ws !== String(workspaceId)) return false;
        if (ws) {
            for (var i = _queue.length - 1; i >= 0; i--) {
                if (_queue[i] && String(_queue[i].workspaceId || '') === ws) _queue.splice(i, 1);
            }
        }
        var finish = currentFinish;
        var audio = currentAudio;
        currentFinish = null;
        try { if (audio && typeof audio.pause === 'function') audio.pause(); } catch (e) { /* noop */ }
        // Cancel BEFORE the finish: the finish starts the next queued clip at
        // once, and a later cancel() would silence that one too. The cancelled
        // utterance reports 'interrupted' asynchronously, after the finish has
        // already settled it, so that report is ignored.
        if (!audio) {
            try { if (window.speechSynthesis) window.speechSynthesis.cancel(); } catch (e) { /* noop */ }
        }
        if (finish) finish('stopped');
        else { finishPlaying(); pump(); }
        return true;
    }

    // `done(outcome)` — outcome is 'ended' only when the utterance or clip
    // really ran to its end; 'error', 'timeout' and 'unsupported' otherwise.
    function playSpeech(item, done) {
        done = done || function () {};
        if (!window.speechSynthesis) { done('unsupported'); return; }
        var text = speechTextFor(item);
        if (!text) { done('unsupported'); return; }
        try {
            var utt = new SpeechSynthesisUtterance(text);
            var v = pickVoice(item.voice);
            if (v) utt.voice = v;
            // A message is English. A note read aloud (item.reader) keeps the note's own language.
            if (!item.reader) {
                utt.lang = (v && v.lang) || SPEECH_LANG;
                if (!v) {
                    var en = pickEnglishVoice();
                    if (en) { utt.voice = en; utt.lang = en.lang || SPEECH_LANG; }
                }
            }
            var ended = false;
            var finish = function (outcome) { if (ended) return; ended = true; done(outcome); };
            currentFinish = finish;
            currentAudio = null;
            utt.onend = function () { finish('ended'); };
            utt.onstart = function () { if (typeof item.onStart === 'function') { try { item.onStart(); } catch (e) { /* noop */ } } };
            utt.onerror = function (evt) {
                var code = evt && evt.error ? String(evt.error) : '';
                if (code === 'not-allowed') {
                    // A piece of a note read aloud is not a message: it stays with the reader (it plays again from the
                    // same place after the click), never in the message queue.
                    if (!item.reader) _queue.unshift(item);
                    waitForGesture();
                }
                // 'interrupted', 'canceled', 'not-allowed' … — never heard.
                finish('error');
            };
            currentUtt = utt;
            // Watchdog: a lost end event (Chrome bug, engine change, a tab
            // suspended mid-utterance) must never wedge the queue. A watchdog
            // finish is a timeout, not an end, so it never marks heard.
            playingTimer = setTimeout(function () { finish('timeout'); }, Math.min(60000, 5000 + text.length * 120));
            // Chrome can leave the engine paused after a tab suspend. resume()
            // is a no-op everywhere else.
            try { window.speechSynthesis.resume(); } catch (e) { /* noop */ }
            // Never cancel() another page's or another tab's speech — this
            // call only ever queues on top of whatever speechSynthesis is
            // already doing on this page.
            window.speechSynthesis.speak(utt);
        } catch (e) {
            done('error');
        }
    }

    // The clip URL is the plugin's own AJAX endpoint. It no longer needs the
    // page's CSRF token: AICliAjax.php exempts `voice_clip` from the CSRF
    // check because the clip id itself is unguessable and expiring, and the
    // session cookie still applies (REVIEW_2026-09-13_EVENTS_AND_SECURITY.md#S4).
    // Carrying the token here would only have put a live secret in the
    // browser's own network log and any proxy access log.
    function playAudio(url, done, onStart) {
        done = done || function () {};
        try {
            var audio = new Audio(url);
            var settled = false;
            var started = false;
            var stallTimer = null;
            var finish = function (outcome) {
                if (settled) return;
                settled = true;
                clearTimeout(startupTimer);
                if (stallTimer) { clearTimeout(stallTimer); stallTimer = null; }
                done(outcome);
            };
            var startupTimer = setTimeout(function () {
                if (started || settled) return;
                try { if (typeof audio.pause === 'function') audio.pause(); } catch (e) { /* noop */ }
                finish('timeout');
            }, AUDIO_START_TIMEOUT_MS);
            var armStall = function () {
                if (settled) return;
                if (stallTimer) clearTimeout(stallTimer);
                stallTimer = setTimeout(function () {
                    try { if (typeof audio.pause === 'function') audio.pause(); } catch (e) { /* noop */ }
                    // It started, so the person heard it: end it as heard, as an error after the start does.
                    finish('ended');
                }, AUDIO_STALL_MS);
            };
            var markStarted = function () {
                if (started) return;
                started = true;
                clearTimeout(startupTimer);
                armStall();
                if (typeof onStart === 'function') { try { onStart(); } catch (e) { /* noop */ } }
            };
            audio.ontimeupdate = armStall;
            audio.onended = function () { finish('ended'); };
            // An error AFTER the clip started means the person already heard it (a late network or
            // decode hiccup at the tail). Report that as ended, so the page does not repeat the same
            // words in the browser voice right after. Only a clip that never played falls back.
            audio.onerror = function () { finish(started ? 'ended' : 'error'); };
            audio.onplaying = markStarted;
            currentFinish = finish;
            currentAudio = audio;
            var p = audio.play();
            if (p && typeof p.then === 'function') {
                p.then(markStarted).catch(function () { finish(started ? 'ended' : 'error'); });
            }
        } catch (e) {
            done('error');
        }
    }

    function csrfToken() {
        if (window.csrf_token) return String(window.csrf_token);
        var el = document.querySelector('input[name="csrf_token"]');
        return el ? String(el.value || '') : '';
    }

    /**
     * Confirm the switch through the configured TTS engine. The request is
     * deliberately `direct=1`: test_voice returns the one clip to this tab
     * without publishing a duplicate live event to every other tab. A slow or
     * failed request falls back to browser speech after a bounded 12 seconds.
     */
    function confirmAloud() {
        var fallbackItem = { mode: 'speech', text: VOICE_CONFIRM_TEXT };
        var settled = false;
        var timer = null;
        var fallback = function () {
            if (settled) return;
            settled = true;
            if (timer) { clearTimeout(timer); timer = null; }
            playSpeech(fallbackItem, function () {});
        };
        try {
            var token = csrfToken();
            var controller = typeof AbortController === 'function' ? new AbortController() : null;
            timer = setTimeout(function () {
                if (controller) { try { controller.abort(); } catch (e) { /* noop */ } }
                fallback();
            }, AUDIO_START_TIMEOUT_MS);
            var url = '/plugins/unraid-aicliagents/AICliAjax.php?action=test_voice&direct=1&purpose=switch&csrf_token=' + encodeURIComponent(token);
            var request = fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ csrf_token: token, direct: '1', purpose: 'switch' }).toString(),
                signal: controller ? controller.signal : undefined
            });
            request.then(function (response) {
                if (!response || !response.ok) throw new Error('voice test failed');
                return response.json();
            }).then(function (data) {
                if (settled) return;
                if (timer) { clearTimeout(timer); timer = null; }
                settled = true;
                var item = { mode: 'speech', text: data && data.text ? String(data.text) : VOICE_CONFIRM_TEXT };
                if (data && data.status === 'ok' && data.mode === 'audio' && data.url) {
                    playAudio(String(data.url), function (outcome) {
                        if (outcome !== 'ended') playSpeech(item, function () {});
                    });
                    return;
                }
                // Browser mode is a successful, intentional response. An
                // error response (including an engine failure) uses the same
                // browser fallback, so the switch still gives feedback.
                playSpeech(item, function () {});
            }).catch(function () { fallback(); });
        } catch (e) {
            fallback();
        }
    }

    function pump() {
        if (playing || needsGesture) return;
        var item = queueNext();
        if (!item) { readerNext(); return; }
        playing = true;
        setPlayingWorkspace(item.workspaceId || null);
        var done = function (outcome) {
            // Audio events intentionally carry the capped text so a failed or
            // stalled clip can still be heard through the browser voice. A clip
            // the operator STOPPED gets no fallback: stop means silence now.
            if (item.mode === 'audio' && outcome !== 'ended' && outcome !== 'stopped' && (item.text || item.fallbackText)) {
                playSpeech({ mode: 'speech', text: item.text || item.fallbackText, name: item.name, intro: item.intro, voice: item.voice }, function () {
                    finishPlaying();
                    pump();
                });
                return;
            }
            finishPlaying();
            pump();
        };
        if (item.mode === 'audio' && item.url) {
            playAudio(item.url, done);
        } else {
            playSpeech(item, done);
        }
    }

    // ---- a note read aloud (docs/specs/NOTEBOOK_READ_ALOUD.md) -----------------
    // One speaker in the page. A read is a list of short pieces. pump() above plays every workspace message
    // FIRST and takes the next piece of a read only when no message waits, so the two never overlap: a message
    // waits for the end of the piece that plays (about one sentence), plays, then the read goes on. The state of
    // the read is sent as the `aicli-voice-reading` window event: idle | loading | playing | paused.

    var reader = null;

    function readerDispatch() {
        try {
            window.dispatchEvent(new CustomEvent('aicli-voice-reading', {
                detail: { id: reader ? reader.id : null, state: reader ? reader.state : 'idle' }
            }));
        } catch (e) { /* noop */ }
    }
    function readerSetState(st) {
        if (!reader || reader.state === st) return;
        reader.state = st;
        readerDispatch();
    }
    function readerEnd() {
        if (!reader) return;
        var held = reader.held;
        reader = null;
        if (held) { try { held.audio.pause(); } catch (e) { /* noop */ } }
        readerDispatch();
    }
    /** The piece now at `index`: ask for it, and ask for the next one while this one plays. */
    function readerClip(r, i) {
        if (!r.engine || i >= r.chunks.length) return null;
        if (!r.clips[i]) {
            r.clips[i] = Promise.resolve().then(function () { return r.fetchClip(i); }).then(function (url) {
                return url ? String(url) : null;
            }, function () { return null; });
        }
        return r.clips[i];
    }
    function readerNext() {
        var r = reader;
        if (!r || r.paused || playing || needsGesture) return;
        if (r.held) { readerResumeHeld(r); return; }
        if (r.index >= r.chunks.length) { readerEnd(); return; }
        playing = true;
        currentReaderPiece = true;
        setPlayingWorkspace(null);
        var i = r.index;
        var text = r.chunks[i];
        r.pieceDone = false;
        var onStart = function () { if (reader === r && !r.paused) readerSetState('playing'); };
        readerSetState('loading');
        var done = function (outcome) {
            if (reader !== r) { finishPlaying(); pump(); return; }
            if (outcome === 'paused') { finishPlaying(); pump(); return; }
            if (outcome === 'stopped') { finishPlaying(); readerEnd(); pump(); return; }
            // 'ended' goes on. A refused sound (waiting for a click) stays at this piece. Anything else skips it.
            if (!(outcome !== 'ended' && needsGesture)) r.index = i + 1;
            finishPlaying();
            pump();
        };
        var speak = function () {
            if (reader !== r) { done('stopped'); return; }
            playSpeech({ mode: 'speech', text: text, intro: '', voice: r.voice, reader: true, onStart: onStart }, function (o) {
                if (r.cancelForPause) { r.cancelForPause = false; done('paused'); return; }
                done(o);
            });
        };
        var p = readerClip(r, i);
        readerClip(r, i + 1);
        if (!p) { speak(); return; }
        // `currentFinish` lets the operator's stop button reach a piece that is still being made.
        var gone = false;
        currentFinish = function (o) { gone = true; done(o); };
        p.then(function (url) {
            if (gone) return;
            if (reader !== r) { currentFinish = null; done('stopped'); return; }
            if (r.paused) { currentFinish = null; done('paused'); return; }
            currentFinish = null;
            if (!url) { speak(); return; }
            playAudio(url, function (o) {
                if (r.cancelForPause) { r.cancelForPause = false; done('paused'); return; }
                // A piece that never played is spoken by the browser voice, so no sentence is lost.
                if (o !== 'ended' && o !== 'stopped' && !needsGesture) { r.index = i; speak(); return; }
                done(o);
            }, onStart);
        });
    }
    /** Go on with the audio that a pause kept, from the second where it stopped. */
    function readerResumeHeld(r) {
        var h = r.held;
        r.held = null;
        playing = true;
        currentReaderPiece = true;
        currentAudio = h.audio;
        currentFinish = h.finish;
        readerSetState('loading');
        try {
            var pr = h.audio.play();
            if (pr && typeof pr.then === 'function') pr.then(function () { readerSetState('playing'); }, function () { if (h.finish) h.finish('error'); });
        } catch (e) { if (h.finish) h.finish('error'); }
    }
    function readerPause() {
        var r = reader;
        if (!r || r.paused) return false;
        r.paused = true;
        readerSetState('paused');
        if (currentReaderPiece && playing) {
            if (currentAudio) {
                // Audio: stop the element where it is and keep it, so a click goes on mid-sentence.
                try { currentAudio.pause(); } catch (e) { /* noop */ }
                r.held = { audio: currentAudio, finish: currentFinish };
                currentAudio = null; currentFinish = null; currentReaderPiece = false;
                if (playingTimer) { clearTimeout(playingTimer); playingTimer = null; }
                playing = false;
                setPlayingWorkspace(null);
                pump(); // a message that waited may play now
            } else if (currentUtt) {
                r.cancelForPause = true; // the piece starts again from its first word after the pause
                try { window.speechSynthesis.cancel(); } catch (e) { /* noop */ }
            }
        }
        return true;
    }
    function readerResume() {
        var r = reader;
        if (!r || !r.paused) return false;
        r.paused = false;
        if (r.held && !playing) { readerResumeHeld(r); return true; }
        readerSetState('loading');
        pump();
        return true;
    }
    function readerStop() {
        var r = reader;
        if (!r) return false;
        if (currentReaderPiece && playing) {
            var f = currentFinish;
            var a = currentAudio;
            r.paused = false;
            try { if (a && typeof a.pause === 'function') a.pause(); } catch (e) { /* noop */ }
            if (!a) { try { if (window.speechSynthesis) window.speechSynthesis.cancel(); } catch (e) { /* noop */ } }
            currentFinish = null;
            readerEnd();
            if (f) f('stopped'); else { finishPlaying(); pump(); }
            return true;
        }
        readerEnd();
        pump();
        return true;
    }
    function readerStart(opts) {
        if (!opts || !opts.chunks || !opts.chunks.length) return false;
        if (reader) readerStop();
        unlock();
        reader = {
            id: String(opts.id || ''), chunks: opts.chunks.slice(), engine: !!opts.engine, fetchClip: opts.fetchClip || function () { return null; },
            voice: opts.voice || null, index: 0, paused: false, state: 'loading', clips: {}, held: null, cancelForPause: false
        };
        readerDispatch();
        pump();
        return true;
    }

    // ---- voice mail (docs/specs/VOICE_MAIL.md R2) ---------------------------
    // Auto-play NEVER marks a voice mail heard. A tab that played a message to
    // the end cannot tell whether anyone was listening, and the operator's
    // report (2026-09-15) was exactly that: the count vanished when playback
    // ended, so a message they missed left no trace. A message stays new until
    // the operator plays it from voice mail or marks it heard.

    // ---- gesture recovery ------------------------------------------------
    // A fixed notice (a real <button>, so it is itself a valid click target)
    // asks for one click in this page. The click plays the kept clip
    // synchronously inside the click handler, which is what Chrome needs.
    // Clicks and keys inside a terminal iframe never reach this document,
    // so the notice is the reliable target.

    var noticeEl = null;
    function showNotice() {
        if (noticeEl || !document.body) return;
        try {
            var el = document.createElement('button');
            el.type = 'button';
            el.textContent = 'Voice: click to allow speech in this tab';
            // Epic #307: a 44 px touch target, kept above the phone's home bar
            // and never wider than the screen. margin/text-transform/letter-
            // spacing undo Unraid's theme rule for every <button>.
            el.setAttribute('data-testid', 'voice-allow-notice');
            // SIDEBAR_THEME_LAYOUT.md: never under Unraid's fixed side menu
            // (--aicli-content-left/-right are 0px on the top-menu themes).
            el.setAttribute('style', 'position:fixed;right:calc(12px + var(--aicli-content-right, 0px));bottom:calc(12px + env(safe-area-inset-bottom, 0px));' +
                'z-index:10002;padding:8px 14px;margin:0;max-width:calc(100vw - 24px - var(--aicli-content-left, 0px) - var(--aicli-content-right, 0px));box-sizing:border-box;' +
                'font:13px/1.4 sans-serif;text-transform:none;letter-spacing:normal;white-space:normal;text-align:left;' +
                'background:#1f2937;color:#ffffff;border:1px solid #f59e0b;' +
                'border-radius:6px;cursor:pointer;min-width:44px;min-height:44px;touch-action:manipulation;');
            document.body.appendChild(el);
            noticeEl = el;
        } catch (e) { /* the document click listener still works without it */ }
    }
    function hideNotice() {
        if (noticeEl && noticeEl.parentNode) noticeEl.parentNode.removeChild(noticeEl);
        noticeEl = null;
    }

    var gestureListening = false;
    function waitForGesture() {
        needsGesture = true;
        showNotice();
        if (gestureListening) return;
        gestureListening = true;
        var onGesture = function () {
            document.removeEventListener('click', onGesture, true);
            document.removeEventListener('keydown', onGesture, true);
            gestureListening = false;
            needsGesture = false;
            hideNotice();
            pump();
        };
        document.addEventListener('click', onGesture, true);
        document.addEventListener('keydown', onGesture, true);
    }

    // A silent, valid 1-frame WAV — enough for a real HTMLMediaElement.play()
    // call to succeed (and count as this page's audio-unlock gesture) without
    // fetching anything or making a sound.
    var SILENT_WAV = 'data:audio/wav;base64,UklGRigAAABXQVZFZm10IBIAAAABAAEAQB8AAEAfAAABAAgAAABmYWN0BAAAAAAAAABkYXRhAAAAAA==';

    var unlocked = false;
    function unlock() {
        if (unlocked) return;
        unlocked = true;
        try {
            var a = new Audio(SILENT_WAV);
            var p = a.play();
            if (p && typeof p.catch === 'function') p.catch(function () {});
        } catch (e) { /* best-effort */ }
    }

    // A tab that loads with voice already on (a `state` message, or the
    // page's own setState(true) from its config load) still needs one click
    // in the page before the browser lets it speak — the toggle click that
    // turned voice on elsewhere was a different tab's gesture. This listener
    // catches the FIRST click anywhere in this page and unlocks silently
    // (no spoken confirmation — the toggle's private test request owns that),
    // then removes itself.
    document.addEventListener('click', function onFirstClick() {
        document.removeEventListener('click', onFirstClick, true);
        unlock();
    }, true);

    // ---- one clip per browser -------------------------------------------
    // Every open tab subscribes, so a browser with the terminal page AND the
    // Settings page open would play each clip twice (an echo, found live
    // 2026-09-12). Tabs of one browser share localStorage: the first tab to
    // claim a clip plays it, the others skip it. A different browser or
    // device has its own storage and still plays. Storage that is blocked
    // (private mode) just means every tab plays, as before.
    var CLAIM_PREFIX = 'aicli_voice_claim_';
    var CLAIM_TTL_MS = 30000;
    function claimForThisBrowser(msg) {
        var key = msg.clipId ? String(msg.clipId) : (typeof msg.ts === 'number' ? String(msg.ts) : '');
        if (!key) return true;
        try {
            var now = Date.now();
            var mine = CLAIM_PREFIX + key;
            var prev = localStorage.getItem(mine);
            if (prev && (now - parseInt(prev, 10)) < CLAIM_TTL_MS) return false;
            localStorage.setItem(mine, String(now));
            // Sweep old claims so the store never grows.
            for (var i = localStorage.length - 1; i >= 0; i--) {
                var k = localStorage.key(i);
                if (k && k.indexOf(CLAIM_PREFIX) === 0 && k !== mine) {
                    var t = parseInt(localStorage.getItem(k) || '0', 10);
                    if (!t || (now - t) > CLAIM_TTL_MS) localStorage.removeItem(k);
                }
            }
            return true;
        } catch (e) {
            return true;
        }
    }
    // ---- subscription --------------------------------------------------

    function onVoiceMessage(data) {
        if (!data || !data.mode) return;
        if (data.mode !== 'state' && !claimForThisBrowser(data)) return;
        // State fan-out (VOICE_SWITCHES.md R7/R8): report the value,
        // never play it.
        if (data.mode === 'state') { applyState(data.enabled); return; }
        if (!shouldPlay(data)) return;
        queuePush(data);
        pump();
    }

    function connect() {
        // EVENT_STREAM_MULTIPLEX.md R2: `voice` rides the page's ONE multiplexed
        // stream (aicli-events.js). shouldPlay() still drops the replayed,
        // older-than-this-page message, exactly as it did on its own stream.
        if (window.aicliEvents && typeof window.aicliEvents.on === 'function') {
            window.aicliEvents.on('voice', function (evt) { onVoiceMessage(evt.data); });
            return;
        }
        // Fallback: a page shell without aicli-events.js.
        if (typeof EventSource === 'undefined') return;
        try {
            var es = new EventSource('/sub/aicli_voice');
            es.onmessage = function (evt) {
                var data;
                try { data = JSON.parse(evt.data); } catch (e) { return; }
                onVoiceMessage(data);
            };
            // No onerror handling beyond EventSource's own auto-reconnect: a
            // clip missed while this tab was disconnected is a missed clip —
            // documented in AGENT_VOICE.md Edge Cases. No fallback polling.
        } catch (e) { /* this tab gets no voice, e.g. a very old browser */ }
    }

    // ---- input (dictation) — docs/specs/VOICE_INPUT.md --------------------
    // Records speech in THIS browser and turns it into text, for a caller to
    // deliver into a workspace (the SPA) or just show back (the Manager
    // Settings "Test" button, which never dictates). Browser mode (no
    // opts.sttUrl) uses the Web Speech API entirely on this device, typing
    // each recogniser result the moment it becomes final (R11). Engine mode
    // (opts.sttUrl set) records in pieces, cut at a pause or every 8 s, and
    // transcribes/types each one as it completes. Recording always
    // auto-stops after 5 minutes, and any stop (manual, the recognizer
    // ending on its own, or the cap) flushes whatever is still pending —
    // never discarded (the saas-acta lesson named in the spec's Edge Cases).

    var INPUT_MAX_MS = 300000; // R11: 5 minutes (was 60 s before live typing)
    // #345: iOS Safari/WebKit does not always fire the recogniser's onend
    // after stop(). If it has not ended this long after a stop, abort it
    // (which releases the microphone) and finish the recording ourselves.
    var INPUT_STOP_GRACE_MS = 1500;
    // R11 engine-mode piece cutting: a pause this long, following speech,
    // ends a piece; a piece is cut regardless after this many ms of audio.
    var ENGINE_SILENCE_MS = 700;
    var ENGINE_MAX_PIECE_MS = 8000;
    // How often the loudness analyser is read while cutting engine pieces.
    var ENGINE_RMS_POLL_MS = 100;
    // Root-mean-square loudness (0..1 on byte time-domain data) below this
    // counts as silence. A heuristic, not a calibrated voice-activity
    // detector — good enough to find a pause between phrases.
    var ENGINE_SILENCE_RMS = 0.01;
    // 2026-09-29 "Send when I stop talking": the caller can pass
    // opts.stopOnSilenceMs (the SPA passes SILENCE_SEND_MS, 2000, only when
    // that preference is on). After the operator has spoken, this much
    // silence ends the recording as a normal stop. Before anything is heard,
    // silence never ends it (the 5 min cap still applies).
    var inputSilenceStopTimer = null; // browser mode: re-armed on each result
    var inputHeardThisRecording = false; // any speech this recording (both modes)
    var inputQuietSince = 0; // engine mode: 0 while loud; else when the silence began

    var _inputState = 'idle'; // idle | recording | transcribing | refused
    var inputRecogniser = null;
    var inputStream = null;
    var inputInterim = '';
    var inputSeconds = 0;
    var inputOpts = {};
    var inputSecondsTimer = null;
    var inputAutoStopTimer = null;
    var inputRefuseTimer = null;
    // Bumped on every startInput() call. A callback from a previous
    // recording/transcription (a late fetch response, a stray recognizer
    // event) checks this before touching state, so it can never clobber a
    // recording that started after it.
    var inputGeneration = 0;

    // ---- R11 engine-mode piece recording (Web Audio silence cut) ---------
    var inputPieceRecorder = null;
    var inputPieceChunks = [];
    var inputPieceMaxTimer = null;
    var inputAudioCtx = null;
    var inputAnalyser = null;
    var inputRmsData = null;
    var inputRmsTimer = null;
    var inputHeardSpeech = false; // this piece has seen audio above threshold
    var inputSilenceSince = 0;    // 0 while not silent; else the ms timestamp silence began
    var inputEngineStopRequested = false; // stopInput() called — the piece in flight is the LAST one
    // #484: the engine is down, so this recording moved to the browser's own recognition.
    // The engine recorder's clips are dropped from then on.
    var inputEngineAbandoned = false;
    var inputEngineDownSeen = false; // the health check said the engine is down (it may answer before the microphone opens)
    // Serialises this session's piece transcriptions so a later piece's
    // reply can never type before an earlier piece's own reply — mirrors
    // ui-build/src/lib/voiceInput.ts's createPhraseQueue (a framework-free
    // page script cannot import that module, so this is a small inline copy
    // of the same one-at-a-time chaining, applied to voice_transcribe calls
    // instead of voice_dictate calls).
    var inputEngineQueue = null;

    function dispatchInput(detail) {
        try {
            window.dispatchEvent(new CustomEvent('aicli-voice-input', { detail: detail || {} }));
        } catch (e) { /* noop */ }
    }

    function setInputState(state, extra) {
        _inputState = state;
        var detail = { state: state };
        if (extra) {
            for (var k in extra) { if (Object.prototype.hasOwnProperty.call(extra, k)) detail[k] = extra[k]; }
        }
        dispatchInput(detail);
    }

    /** The state label for an event that carries a phrase, a note or a tick. Once a stop was
     *  requested the state is 'transcribing'; such an event must not move it back to
     *  'recording' (#485: the icon pulsed again when a piece answered after the click). */
    function liveInputState() {
        return _inputState === 'transcribing' ? 'transcribing' : 'recording';
    }

    /** #485: recording has stopped (a click, silence, the 5 minute cap or the Enter button).
     *  Every stop path goes through here, so the icon leaves its pulsing look at once, not when
     *  the last piece has been transcribed. Safe to call twice. */
    function markInputStopped() {
        if (_inputState !== 'recording') return;
        setInputState('transcribing', { seconds: inputSeconds });
    }

    function clearInputTimers() {
        if (inputSecondsTimer) { clearInterval(inputSecondsTimer); inputSecondsTimer = null; }
        if (inputAutoStopTimer) { clearTimeout(inputAutoStopTimer); inputAutoStopTimer = null; }
        if (inputSilenceStopTimer) { clearTimeout(inputSilenceStopTimer); inputSilenceStopTimer = null; }
    }

    /** The stop-on-silence time for this recording in ms, or 0 when it is off. */
    function stopOnSilenceMs() {
        var ms = Number(inputOpts.stopOnSilenceMs || 0);
        return ms > 0 ? ms : 0;
    }

    /** Browser mode: (re)start the silence timer after each recogniser
     *  result. When it fires and something was heard this recording, stop
     *  as if the operator tapped Stop. */
    function armBrowserSilenceStop(gen) {
        var ms = stopOnSilenceMs();
        if (!ms || !inputHeardThisRecording) return;
        if (inputSilenceStopTimer) clearTimeout(inputSilenceStopTimer);
        inputSilenceStopTimer = setTimeout(function () {
            inputSilenceStopTimer = null;
            if (gen !== inputGeneration || _inputState !== 'recording') return;
            stopInput();
        }, ms);
    }

    function inputCsrf() {
        if (window.csrf_token) return window.csrf_token;
        var el = document.querySelector('input[name="csrf_token"]');
        return el ? el.value : '';
    }

    function inputAjaxUrl(action) {
        return '/plugins/unraid-aicliagents/AICliAjax.php?action=' + encodeURIComponent(action) +
            '&csrf_token=' + encodeURIComponent(inputCsrf());
    }

    /** Shows the refusal sentence for 4 s (the caller's bubble reads it off
     *  the event), then returns to idle on its own. */
    function refuseInput(message) {
        clearInputTimers();
        if (inputRefuseTimer) { clearTimeout(inputRefuseTimer); inputRefuseTimer = null; }
        var gen = ++inputGeneration;
        setInputState('refused', { error: message });
        inputRefuseTimer = setTimeout(function () {
            if (gen !== inputGeneration) return;
            inputRefuseTimer = null;
            setInputState('idle');
        }, 4000);
    }

    function stopInputTracks() {
        if (inputStream) {
            try {
                var tracks = inputStream.getTracks();
                for (var i = 0; i < tracks.length; i++) tracks[i].stop();
            } catch (e) { /* noop */ }
            inputStream = null;
        }
    }

    // ---- browser mode (SpeechRecognition) ---------------------------------

    /** Stop flush: whatever interim text had not yet become final. */
    function finishBrowserInput(gen) {
        if (gen !== inputGeneration) return;
        // #345: once only. The stop watchdog (stopInput) and a late onend can
        // both arrive; the second must not report a second, empty stop.
        // (#485: after a stop request the state is 'transcribing', and the flush still has to run.)
        if (_inputState !== 'recording' && _inputState !== 'transcribing') return;
        clearInputTimers();
        var leftover = inputInterim.replace(/^\s+|\s+$/g, '');
        inputInterim = '';
        inputRecogniser = null;
        setInputState('idle', { text: leftover });
    }

    var NO_RECOGNITION_NOTE = 'This browser has no speech recognition. Use Safari or Chrome, or set a transcription endpoint in Settings, Dictation.';
    // #484: the sentence shown when dictation moves from the speech server to the browser.
    var ENGINE_DOWN_NOTE = "The transcription engine did not answer; using your browser's speech recognition.";
    var ENGINE_DOWN_NO_BROWSER_NOTE = 'The speech-to-text server is not answering, and this browser has no speech recognition of its own.';

    function startBrowserInput(gen, noSupportNote) {
        var Ctor = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!Ctor) {
            refuseInput(noSupportNote || NO_RECOGNITION_NOTE);
            return;
        }
        try {
            var rec = new Ctor();
            rec.continuous = true;
            rec.interimResults = true;
            if (inputOpts.sttLanguage) rec.lang = inputOpts.sttLanguage;
            inputInterim = '';
            var refused = false;
            rec.onresult = function (evt) {
                if (gen !== inputGeneration) return;
                var interim = '';
                for (var i = evt.resultIndex; i < evt.results.length; i++) {
                    var r = evt.results[i];
                    if (r.isFinal) {
                        // R11: type this phrase AT ONCE — do not wait for stop.
                        var phrase = String((r[0] && r[0].transcript) || '').replace(/^\s+|\s+$/g, '');
                        if (phrase) { inputHeardThisRecording = true; dispatchInput({ state: liveInputState(), phrase: phrase }); }
                    } else {
                        interim += (r[0] && r[0].transcript) || '';
                    }
                }
                inputInterim = interim;
                if (interim.replace(/\s+/g, '')) inputHeardThisRecording = true;
                setInputState(liveInputState(), { interim: interim, seconds: inputSeconds });
                armBrowserSilenceStop(gen);
            };
            rec.onerror = function (evt) {
                var code = evt && evt.error ? String(evt.error) : '';
                if (code === 'not-allowed' || code === 'permission-denied' || code === 'service-not-allowed') {
                    refused = true;
                    try { rec.stop(); } catch (e) { /* noop */ }
                    refuseInput('The browser refused microphone access; allow it in the site settings and try again.');
                }
                // Any other recognizer error (no-speech, network, aborted) is
                // left to onend below, which always delivers what was heard.
            };
            rec.onend = function () {
                if (refused) return; // already refused above — do not also finish
                finishBrowserInput(gen);
            };
            inputRecogniser = rec;
            rec.start();
            setInputState('recording', { interim: '', seconds: 0 });
        } catch (e) {
            refuseInput(noSupportNote || NO_RECOGNITION_NOTE);
        }
    }

    // ---- engine mode (MediaRecorder, cut into pieces) ---------------------

    function pickRecorderMime() {
        try {
            if (window.MediaRecorder && MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported('audio/webm;codecs=opus')) {
                return 'audio/webm;codecs=opus';
            }
        } catch (e) { /* fall through to the browser default container */ }
        return '';
    }

    /** Tears down the Web Audio graph and the loudness poll. Safe to call more than once. */
    function stopEngineAudioAnalysis() {
        if (inputRmsTimer) { clearInterval(inputRmsTimer); inputRmsTimer = null; }
        if (inputAudioCtx) {
            try { inputAudioCtx.close(); } catch (e) { /* noop */ }
            inputAudioCtx = null;
        }
        inputAnalyser = null;
        inputRmsData = null;
    }

    /** Root-mean-square loudness poll: cuts the current piece after
     *  ENGINE_SILENCE_MS of silence that follows speech. The ENGINE_MAX_PIECE_MS
     *  hard cap is its own timer (startEnginePiece), so it still cuts a piece
     *  even where Web Audio is unavailable and this poll never runs. */
    function pollEngineRms(gen) {
        if (gen !== inputGeneration || !inputAnalyser || !inputRmsData) return;
        inputAnalyser.getByteTimeDomainData(inputRmsData);
        var sumSq = 0;
        for (var i = 0; i < inputRmsData.length; i++) {
            var v = (inputRmsData[i] - 128) / 128;
            sumSq += v * v;
        }
        var rms = Math.sqrt(sumSq / inputRmsData.length);
        var now = Date.now();
        if (rms >= ENGINE_SILENCE_RMS) {
            inputHeardSpeech = true;
            inputSilenceSince = 0;
            inputHeardThisRecording = true;
            inputQuietSince = 0;
            return;
        }
        // "Send when I stop talking": this silence clock runs over the whole
        // recording, not one piece, so a piece cut does not reset it.
        var silenceStop = stopOnSilenceMs();
        if (silenceStop && inputHeardThisRecording) {
            if (!inputQuietSince) inputQuietSince = now;
            else if (now - inputQuietSince >= silenceStop) { inputHeardThisRecording = false; stopInput(); return; }
        }
        if (!inputHeardSpeech) return; // silence before any speech: not a pause to cut on
        if (!inputSilenceSince) { inputSilenceSince = now; return; }
        if (now - inputSilenceSince >= ENGINE_SILENCE_MS) cutEnginePiece(gen);
    }

    /** Ends the piece in flight (if any) — its own onstop decides what happens next. */
    function cutEnginePiece(gen) {
        if (gen !== inputGeneration || !inputPieceRecorder) return;
        if (inputPieceMaxTimer) { clearTimeout(inputPieceMaxTimer); inputPieceMaxTimer = null; }
        if (inputPieceRecorder.state === 'recording') {
            try { inputPieceRecorder.stop(); } catch (e) { /* noop — nothing more to do for this piece */ }
        }
    }

    // VOICE_INPUT.md "WAV upload" (2026-10-15): a self-hosted engine can refuse
    // the compressed clip a browser records (speaches 0.8.3 answered HTTP 400
    // to WebM/Opus, OGG and MP4/AAC, and read WAV, FLAC and MP3). Each piece is
    // decoded here and sent as 16 kHz mono 16-bit WAV, which every engine
    // reads. An 8 s piece is about 256 KB. When this browser cannot decode the
    // piece, the original clip goes as before.
    var STT_WAV_RATE = 16000;

    /** 16-bit PCM WAV bytes for mono float samples (-1..1). */
    function encodeWav16(samples, rate) {
        var buf = new ArrayBuffer(44 + samples.length * 2);
        var v = new DataView(buf);
        var str = function (off, s) { for (var i = 0; i < s.length; i++) v.setUint8(off + i, s.charCodeAt(i)); };
        str(0, 'RIFF'); v.setUint32(4, 36 + samples.length * 2, true); str(8, 'WAVE');
        str(12, 'fmt '); v.setUint32(16, 16, true); v.setUint16(20, 1, true); v.setUint16(22, 1, true);
        v.setUint32(24, rate, true); v.setUint32(28, rate * 2, true); v.setUint16(32, 2, true); v.setUint16(34, 16, true);
        str(36, 'data'); v.setUint32(40, samples.length * 2, true);
        for (var i = 0, off = 44; i < samples.length; i++, off += 2) {
            var s = Math.max(-1, Math.min(1, samples[i]));
            v.setInt16(off, s < 0 ? s * 0x8000 : s * 0x7FFF, true);
        }
        return buf;
    }

    /** Resolves {blob, mime}: the piece as 16 kHz mono WAV, or the original
     *  piece when this browser cannot decode it. Never rejects. */
    function toWavPiece(blob, mimeType) {
        var original = { blob: blob, mime: mimeType };
        return new Promise(function (resolve) {
            try {
                var Ctx = window.OfflineAudioContext || window.webkitOfflineAudioContext;
                if (!Ctx || !blob || typeof blob.arrayBuffer !== 'function') { resolve(original); return; }
                blob.arrayBuffer().then(function (bytes) {
                    // decodeAudioData resamples to the context's own rate.
                    var ctx = new Ctx(1, 1, STT_WAV_RATE);
                    var ok = function (audio) {
                        try {
                            var n = audio.length, ch = audio.numberOfChannels, mono = new Float32Array(n);
                            for (var c = 0; c < ch; c++) {
                                var d = audio.getChannelData(c);
                                for (var i = 0; i < n; i++) mono[i] += d[i] / ch;
                            }
                            if (!n) { resolve(original); return; }
                            resolve({ blob: new Blob([encodeWav16(mono, audio.sampleRate)], { type: 'audio/wav' }), mime: 'audio/wav' });
                        } catch (e) { resolve(original); }
                    };
                    var p = ctx.decodeAudioData(bytes, ok, function () { resolve(original); });
                    if (p && typeof p.catch === 'function') p.catch(function () { resolve(original); });
                }, function () { resolve(original); });
            } catch (e) { resolve(original); }
        });
    }

    /**
     * Transcribes one piece and, when it is the LAST piece (a stop was
     * requested), reports the flush text through the normal idle event;
     * otherwise types the piece live the moment it is recognised (R11).
     * Runs through inputEngineQueue so pieces are always typed in order,
     * even though the NEXT piece is already recording while this one is
     * still being transcribed.
     */
    function transcribeEnginePiece(gen, blob, mimeType, pieceSeconds, isFinal) {
        return new Promise(function (resolve) {
            if (gen !== inputGeneration) { resolve(); return; }
            if (isFinal) setInputState('transcribing', { seconds: inputSeconds });
            var reader = new FileReader();
            var sendMime = mimeType;
            var retried = false;
            var resend = null; // set by onloadend: sends the same clip once more
            var onDone = function (text, error, engineFailure) {
                if (gen !== inputGeneration) { resolve(); return; }
                if (isFinal && error && engineFailure && !retried && resend) {
                    // #754: keep the clip and try the engine once more. Nothing was typed yet.
                    retried = true;
                    setTimeout(function () {
                        if (gen !== inputGeneration) { resolve(); return; }
                        resend();
                    }, LAST_PIECE_RETRY_WAIT_MS);
                    return;
                }
                if (isFinal) {
                    setInputState('idle', error ? { text: text || '', error: error } : { text: text || '' });
                } else if (error) {
                    // #484: the engine failed (down, or it refused the clip): use the browser's own
                    // recognition for the rest of this recording, and say so.
                    if (engineFailure && fallBackToBrowser(gen)) { resolve(); return; }
                    // R11: one piece's transcription failing must not end the
                    // recording — only report it and keep listening.
                    if (!inputEngineAbandoned) dispatchInput({ state: liveInputState(), error: error });
                } else if (text) {
                    dispatchInput({ state: liveInputState(), phrase: text });
                }
                // else: an empty non-final piece is dropped silently (R11).
                resolve();
            };
            reader.onloadend = function () {
                if (gen !== inputGeneration) { resolve(); return; }
                var dataUrl = String(reader.result || '');
                var comma = dataUrl.indexOf(',');
                var b64 = comma >= 0 ? dataUrl.slice(comma + 1) : '';
                var body = 'filedata=' + encodeURIComponent(b64) +
                    '&mime=' + encodeURIComponent(sendMime || '') +
                    '&seconds=' + encodeURIComponent(String(pieceSeconds)) +
                    '&workspaceId=' + encodeURIComponent(inputOpts.workspaceId || '') +
                    '&csrf_token=' + encodeURIComponent(inputCsrf());
                var sendOnce = function () {
                var settled = false;
                var timer = null;
                var finish = function (text, error, engineFailure) {
                    if (settled) return;
                    settled = true;
                    if (timer) { clearTimeout(timer); timer = null; }
                    onDone(text, error, engineFailure);
                };
                var controller = typeof AbortController === 'function' ? new AbortController() : null;
                timer = setTimeout(function () {
                    if (controller) { try { controller.abort(); } catch (e) { /* noop */ } }
                    finish('', 'The transcription server did not answer.', true);
                }, TRANSCRIBE_TIMEOUT_MS);
                fetch(inputAjaxUrl('voice_transcribe'), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body,
                    signal: controller ? controller.signal : undefined
                }).then(function (r) { return r.json(); }).then(function (data) {
                    if (data && data.status === 'ok') {
                        finish(String(data.text || '').replace(/^\s+|\s+$/g, ''), null);
                    } else {
                        // `engine` (set by voice_transcribe) marks a failure of the engine itself, not a refusal.
                        finish('', (data && data.message) || 'The transcription server did not answer.', !!(data && data.engine));
                    }
                }).catch(function () {
                    finish('', 'The transcription server did not answer.', true);
                });
                };
                resend = sendOnce;
                sendOnce();
            };
            reader.onerror = function () { onDone('', 'The transcription server did not answer.'); };
            toWavPiece(blob, mimeType).then(function (piece) {
                if (gen !== inputGeneration) { resolve(); return; }
                sendMime = piece.mime;
                reader.readAsDataURL(piece.blob);
            });
        });
    }

    /** Adds one piece to the ordered transcription queue. */
    function queueEnginePiece(gen, blob, mimeType, pieceSeconds, isFinal) {
        var run = function () { return transcribeEnginePiece(gen, blob, mimeType, pieceSeconds, isFinal); };
        inputEngineQueue = (inputEngineQueue || Promise.resolve()).then(run, run);
    }

    /** Starts one MediaRecorder piece on the already-open inputStream. Its
     *  own onstop hands the finished piece to the transcription queue and,
     *  unless a stop was requested, immediately starts the next piece. */
    function startEnginePiece(gen) {
        if (gen !== inputGeneration) return;
        inputPieceChunks = [];
        inputHeardSpeech = false;
        inputSilenceSince = 0;
        if (inputPieceMaxTimer) { clearTimeout(inputPieceMaxTimer); inputPieceMaxTimer = null; }
        var pieceStartedAt = Date.now();
        var mimeType = pickRecorderMime();
        var mr;
        try {
            mr = mimeType ? new MediaRecorder(inputStream, { mimeType: mimeType }) : new MediaRecorder(inputStream);
        } catch (e) {
            stopEngineAudioAnalysis();
            stopInputTracks();
            refuseInput('The browser refused microphone access; allow it in the site settings and try again.');
            return;
        }
        mr.ondataavailable = function (evt) {
            if (evt.data && evt.data.size > 0) inputPieceChunks.push(evt.data);
        };
        mr.onstop = function () {
            // #484: the recording moved to the browser's recognition; this clip is dropped.
            if (inputEngineAbandoned) { inputPieceChunks = []; return; }
            var blob = new Blob(inputPieceChunks, { type: mr.mimeType || mimeType || 'audio/webm' });
            inputPieceChunks = [];
            var pieceSeconds = (Date.now() - pieceStartedAt) / 1000;
            var isFinal = inputEngineStopRequested;
            if (isFinal) { stopEngineAudioAnalysis(); stopInputTracks(); clearInputTimers(); }
            queueEnginePiece(gen, blob, blob.type, pieceSeconds, isFinal);
            if (!isFinal && gen === inputGeneration) startEnginePiece(gen);
        };
        inputPieceRecorder = mr;
        mr.start();
        // ENGINE_MAX_PIECE_MS: cut this piece regardless of loudness once it
        // has run this long, independent of pollEngineRms (still applies
        // even where Web Audio failed to initialise below).
        inputPieceMaxTimer = setTimeout(function () { cutEnginePiece(gen); }, ENGINE_MAX_PIECE_MS);
    }

    /**
     * #484: the speech-to-text engine failed or is down. Drop the engine capture and carry on with the
     * browser's own recognition on the same recording, with one plain sentence saying so. Returns true
     * when it switched (or tried to), false when it must not (a stop was requested, it already switched,
     * the caller turned it off, or nothing is recording).
     */
    function fallBackToBrowser(gen) {
        if (gen !== inputGeneration || inputOpts.noEngineFallback) return false;
        if (inputEngineAbandoned || inputEngineStopRequested || _inputState !== 'recording') return false;
        inputEngineAbandoned = true;
        stopEngineAudioAnalysis();
        if (inputPieceMaxTimer) { clearTimeout(inputPieceMaxTimer); inputPieceMaxTimer = null; }
        var mr = inputPieceRecorder;
        inputPieceRecorder = null;
        if (mr && mr.state === 'recording') {
            try { mr.stop(); } catch (e) { /* its onstop drops the clip */ }
        }
        stopInputTracks();
        var browserOpts = {};
        for (var k in inputOpts) { if (Object.prototype.hasOwnProperty.call(inputOpts, k)) browserOpts[k] = inputOpts[k]; }
        browserOpts.sttUrl = '';
        inputOpts = browserOpts;
        startBrowserInput(gen, ENGINE_DOWN_NO_BROWSER_NOTE);
        if (_inputState === 'recording') dispatchInput({ state: 'recording', error: ENGINE_DOWN_NOTE, engine: 'browser' });
        return true;
    }

    /** #484: ask the server whether the engine answers. A down answer moves this recording to the browser. */
    function probeEngine(gen) {
        inputEngineDownSeen = false;
        if (inputOpts.noEngineFallback) return;
        try {
            fetch(inputAjaxUrl('voice_stt_health'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'csrf_token=' + encodeURIComponent(inputCsrf())
            }).then(function (r) { return r.json(); }).then(function (data) {
                if (gen !== inputGeneration) return;
                if (data && data.status === 'ok' && data.engine && data.ok === false) {
                    inputEngineDownSeen = true;
                    fallBackToBrowser(gen); // false while the microphone is still opening: startEngineInput() checks the flag
                }
            }).catch(function () { /* no answer says nothing about the engine: the first piece decides */ });
        } catch (e) { /* same */ }
    }

    function startEngineInput(gen) {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !window.MediaRecorder) {
            refuseInput('The browser refused microphone access; allow it in the site settings and try again.');
            return;
        }
        probeEngine(gen);
        navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
            if (gen !== inputGeneration) {
                try { stream.getTracks().forEach(function (t) { t.stop(); }); } catch (e) { /* noop */ }
                return;
            }
            inputStream = stream;
            inputEngineStopRequested = false;
            inputEngineQueue = Promise.resolve();
            try {
                var Ctx = window.AudioContext || window.webkitAudioContext;
                inputAudioCtx = new Ctx();
                var source = inputAudioCtx.createMediaStreamSource(stream);
                inputAnalyser = inputAudioCtx.createAnalyser();
                inputAnalyser.fftSize = 512;
                inputRmsData = new Uint8Array(inputAnalyser.fftSize);
                source.connect(inputAnalyser);
                inputRmsTimer = setInterval(function () { pollEngineRms(gen); }, ENGINE_RMS_POLL_MS);
            } catch (e) {
                // No Web Audio API on this browser: pieces still cut on the
                // ENGINE_MAX_PIECE_MS hard cap alone, just never early on a pause.
            }
            setInputState('recording', { seconds: 0 });
            startEnginePiece(gen);
            if (inputEngineDownSeen) fallBackToBrowser(gen); // the health check answered "down" while the microphone opened
        }).catch(function () {
            if (gen !== inputGeneration) return;
            refuseInput('The browser refused microphone access; allow it in the site settings and try again.');
        });
    }

    // ---- live mode (a streaming engine over a websocket) -------------------
    // docs/specs/VOICE_LIVE_ENGINE.md (#731). The browser streams the microphone to
    // `opts.sttStreamUrl` as 16 kHz mono float32 frames of 100 ms. Each reply carries the
    // FULL text of the open segment; it goes out as an interim event (the terminal types and
    // corrects it, R13), and `is_final` sends it as a phrase (R11/R12), the same events as
    // browser recognition. A failure moves the recording to the piece engine (stt_url) or the
    // browser's recognition. The page reports each session's outcome for the Health check.
    var LIVE_RATE = 16000;
    var LIVE_FRAME = 1600;            // 0.1 s at 16 kHz
    var LIVE_OPEN_TIMEOUT_MS = 3000;
    var LIVE_EOF_WAIT_MS = 3000;
    var LIVE_TAIL_SECONDS = 0.3;      // silence before "Done", so the last word is not cut
    var LIVE_LEAD_SECONDS = 0.5;      // silence at the start of each stream, so the first word is not cut
    var LIVE_MAX_RECONNECTS = 2;      // the proxy closes a stream after 4 min with no traffic
    var LIVE_DOWN_NOTE = 'The live transcription engine is not answering, so dictation goes on without live words.';
    var inputLive = null;             // the live session of this recording, or null

    /** Linear resampler: float samples at `from` Hz to `to` Hz. Exposed for the unit test. */
    function liveResample(samples, from, to) {
        if (from === to) return Float32Array.from(samples);
        var n = Math.floor(samples.length * to / from);
        var out = new Float32Array(n);
        var ratio = from / to;
        for (var i = 0; i < n; i++) {
            var x = i * ratio, i0 = Math.floor(x), f = x - i0;
            var a = samples[i0] || 0, b = i0 + 1 < samples.length ? samples[i0 + 1] : a;
            out[i] = a + (b - a) * f;
        }
        return out;
    }

    /** One reply from the engine, folded into the session. Returns the event to send, or null. Exposed for the unit test. */
    function liveReduce(live, reply) {
        if (!reply || typeof reply !== 'object') return null;
        // The engine numbers every sentence 0 and starts a new one at a new start_time
        // (#733), so a segment is its number plus its start time.
        var key = String(Number(reply.segment || 0)) + '@' + String(reply.start_time == null ? '' : reply.start_time);
        if (!live.closed) live.closed = {};
        if (live.closed[key]) return null;                   // a late reply for a closed segment
        if (key !== live.segment) { live.segment = key; live.interim = ''; }
        // The engine puts two spaces after a full stop; one is typed.
        var text = String(reply.text || '').replace(/\s+/g, ' ').replace(/^ | $/g, '');
        if (reply.is_final) {
            live.interim = '';
            live.closed[key] = true;
            return { phrase: text };
        }
        if (text === live.interim) return null;
        live.interim = text;
        return { interim: text };
    }

    function reportLiveResult(ok, message) {
        try {
            fetch(inputAjaxUrl('voice_live_result'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'ok=' + (ok ? '1' : '0') + '&message=' + encodeURIComponent(message || '') + '&csrf_token=' + encodeURIComponent(inputCsrf())
            }).catch(function () { /* best effort */ });
        } catch (e) { /* best effort */ }
    }

    var LIVE_MAX_PENDING = LIVE_RATE * 10;   // audio kept while the socket is not open yet: 10 s at most

    /** Adds samples and sends every full frame while the socket is open. Audio from before the
     *  socket opened (or while it reconnects) waits and goes first, so the first word is not lost. */
    function liveSendFloat(live, samples) {
        if (samples && samples.length) live.pending = live.pending.length ? concatFloat(live.pending, samples) : samples;
        if (!live.ws || live.ws.readyState !== 1) {
            if (live.pending.length > LIVE_MAX_PENDING) live.pending = live.pending.slice(live.pending.length - LIVE_MAX_PENDING);
            return;
        }
        while (live.pending.length >= LIVE_FRAME) {
            var frame = live.pending.slice(0, LIVE_FRAME);
            live.pending = live.pending.slice(LIVE_FRAME);
            try { live.ws.send(frame.buffer); } catch (e) { return; /* the close handler decides */ }
        }
    }

    function concatFloat(a, b) {
        var c = new Float32Array(a.length + b.length);
        c.set(a, 0); c.set(b, a.length);
        return c;
    }

    function liveTeardownAudio(live) {
        if (live.node) { try { live.node.disconnect(); } catch (e) { /* noop */ } live.node.onaudioprocess = null; live.node = null; }
        if (live.source) { try { live.source.disconnect(); } catch (e) { /* noop */ } live.source = null; }
        if (live.ctx) { try { live.ctx.close(); } catch (e) { /* noop */ } live.ctx = null; }
        stopInputTracks();
    }

    function liveCloseSocket(live) {
        if (live.openTimer) { clearTimeout(live.openTimer); live.openTimer = null; }
        if (live.eofTimer) { clearTimeout(live.eofTimer); live.eofTimer = null; }
        var ws = live.ws;
        live.ws = null;
        if (ws) { ws.onopen = ws.onmessage = ws.onerror = ws.onclose = null; try { ws.close(); } catch (e) { /* noop */ } }
    }

    /** The end of a live recording: whatever is still open goes out as the stop flush. */
    function finishLiveInput(gen, live) {
        if (gen !== inputGeneration || inputLive !== live || live.done) return;
        live.done = true;
        liveCloseSocket(live);
        liveTeardownAudio(live);
        clearInputTimers();
        inputLive = null;
        var leftover = live.interim;
        live.interim = '';
        inputInterim = '';
        reportLiveResult(true, live.heard ? 'Live transcription worked.' : 'Live transcription connected (no speech heard).');
        setInputState('idle', { text: leftover });
    }

    /** The live engine failed: carry on with the piece engine or the browser, and say so once. */
    function liveFallBack(gen, live, reason) {
        if (gen !== inputGeneration || inputLive !== live || live.done) return;
        live.done = true;
        liveCloseSocket(live);
        liveTeardownAudio(live);
        inputLive = null;
        reportLiveResult(false, reason);
        // Words of the open segment that were already typed stay typed: the stop flush is empty.
        live.interim = '';
        inputInterim = '';
        // A stop was already requested: end here. (Before the microphone opened the state is
        // not 'recording' yet, and the recording must still move on, so the state is not checked.)
        if (live.stopping) { clearInputTimers(); setInputState('idle', { text: '' }); return; }
        var rest = {};
        for (var k in inputOpts) { if (Object.prototype.hasOwnProperty.call(inputOpts, k)) rest[k] = inputOpts[k]; }
        rest.sttStreamUrl = '';
        inputOpts = rest;
        if (rest.sttUrl) startEngineInput(gen); else startBrowserInput(gen, ENGINE_DOWN_NO_BROWSER_NOTE);
        if (_inputState !== 'refused') dispatchInput({ state: 'recording', error: LIVE_DOWN_NOTE });
    }

    function liveConnect(gen, live) {
        var url = String(inputOpts.sttStreamUrl || '');
        var ws;
        try { ws = new WebSocket(url); } catch (e) { liveFallBack(gen, live, 'The live transcription endpoint is not a valid address.'); return; }
        ws.binaryType = 'arraybuffer';
        live.ws = ws;
        live.openTimer = setTimeout(function () {
            if (live.ws === ws && ws.readyState !== 1) liveFallBack(gen, live, 'The live transcription engine did not answer in time.');
        }, LIVE_OPEN_TIMEOUT_MS);
        ws.onopen = function () {
            if (live.openTimer) { clearTimeout(live.openTimer); live.openTimer = null; }
            live.opened = true;
            // The engine drops a word spoken in the first moment of a stream (checked 2026-10-21:
            // "Hello" was lost with no lead, kept with 0.3 s): 0.5 s of silence goes first, then
            // the audio that waited for the socket.
            live.pending = live.pending.length ? concatFloat(new Float32Array(Math.round(LIVE_RATE * LIVE_LEAD_SECONDS)), live.pending)
                                               : new Float32Array(Math.round(LIVE_RATE * LIVE_LEAD_SECONDS));
            liveSendFloat(live, null);
        };
        ws.onmessage = function (evt) {
            if (gen !== inputGeneration || inputLive !== live) return;
            var reply = null;
            try { reply = JSON.parse(String(evt.data)); } catch (e) { return; }
            var out = liveReduce(live, reply);
            if (out && out.phrase) { live.heard = true; inputHeardThisRecording = true; inputInterim = ''; dispatchInput({ state: liveInputState(), phrase: out.phrase }); }
            else if (out && typeof out.interim === 'string') {
                if (out.interim) { live.heard = true; inputHeardThisRecording = true; }
                inputInterim = out.interim;
                setInputState(liveInputState(), { interim: out.interim, seconds: inputSeconds, live: true });
                armBrowserSilenceStop(gen);
            }
            if (reply && reply.is_eof) finishLiveInput(gen, live);
        };
        ws.onclose = function () {
            if (live.ws !== ws || live.done) return;
            live.ws = null;
            if (live.stopping) { finishLiveInput(gen, live); return; }
            // The proxy closes a stream that idled for 4 minutes; open a new one once or twice.
            if (live.opened && live.reconnects < LIVE_MAX_RECONNECTS) {
                live.reconnects++;
                live.opened = false;
                live.segment = '';
                live.closed = {};                            // a new stream starts its times at 0
                liveConnect(gen, live);
                return;
            }
            liveFallBack(gen, live, live.opened ? 'The live transcription engine closed the connection.' : 'The live transcription engine is not answering.');
        };
        ws.onerror = function () { /* onclose follows and decides */ };
    }

    /** #913: "Send when I stop talking" in live mode follows the microphone, the same clock as
     *  the piece engine. A timer on the engine's replies alone can miss the stop: a reply with new
     *  words restarts it. */
    function liveSilenceTick(samples) {
        var silenceStop = stopOnSilenceMs();
        if (!silenceStop || !samples || !samples.length) return;
        var sumSq = 0;
        for (var i = 0; i < samples.length; i++) sumSq += samples[i] * samples[i];
        var now = Date.now();
        if (Math.sqrt(sumSq / samples.length) >= ENGINE_SILENCE_RMS) {
            inputHeardThisRecording = true;
            inputQuietSince = 0;
            return;
        }
        if (!inputHeardThisRecording) return;
        if (!inputQuietSince) inputQuietSince = now;
        else if (now - inputQuietSince >= silenceStop) { inputHeardThisRecording = false; stopInput(); }
    }

    function startLiveInput(gen) {
        if (!window.WebSocket || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            liveFallBackBeforeStart(gen, 'This browser cannot stream to the live transcription engine.');
            return;
        }
        // An HTTPS page cannot open a plain ws:// socket (mixed content).
        if (window.location && window.location.protocol === 'https:' && /^ws:/i.test(String(inputOpts.sttStreamUrl || ''))) {
            liveFallBackBeforeStart(gen, 'This page is HTTPS, so it cannot use a ws:// live endpoint; set a wss:// address.');
            return;
        }
        var live = { ws: null, ctx: null, source: null, node: null, pending: new Float32Array(0), segment: '', closed: {}, interim: '',
                     opened: false, stopping: false, done: false, heard: false, reconnects: 0, openTimer: null, eofTimer: null };
        inputLive = live;
        liveConnect(gen, live);
        navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
            if (gen !== inputGeneration || inputLive !== live || live.done) {
                try { stream.getTracks().forEach(function (t) { t.stop(); }); } catch (e) { /* noop */ }
                return;
            }
            inputStream = stream;
            try {
                var Ctx = window.AudioContext || window.webkitAudioContext;
                live.ctx = new Ctx();
                live.source = live.ctx.createMediaStreamSource(stream);
                // ScriptProcessorNode: deprecated but in every browser, and it needs no module file.
                live.node = live.ctx.createScriptProcessor(4096, 1, 1);
                var rate = live.ctx.sampleRate;
                live.node.onaudioprocess = function (e) {
                    if (gen !== inputGeneration || live.done || live.stopping) return;
                    var liveInput = e.inputBuffer.getChannelData(0);
                    liveSendFloat(live, liveResample(liveInput, rate, LIVE_RATE));
                    liveSilenceTick(liveInput);
                };
                live.source.connect(live.node);
                live.node.connect(live.ctx.destination);   // a processor runs only while connected; its output is silent
            } catch (e) {
                liveFallBack(gen, live, 'The browser could not read the microphone for live transcription.');
                return;
            }
            setInputState('recording', { interim: '', seconds: 0, live: true });
        }).catch(function () {
            if (gen !== inputGeneration) return;
            liveCloseSocket(live);
            inputLive = null;
            live.done = true;
            refuseInput('The browser refused microphone access; allow it in the site settings and try again.');
        });
    }

    /** No live session could start: use the piece engine or the browser from the first word. */
    function liveFallBackBeforeStart(gen, reason) {
        reportLiveResult(false, reason);
        var rest = {};
        for (var k in inputOpts) { if (Object.prototype.hasOwnProperty.call(inputOpts, k)) rest[k] = inputOpts[k]; }
        rest.sttStreamUrl = '';
        inputOpts = rest;
        if (rest.sttUrl) startEngineInput(gen); else startBrowserInput(gen);
        if (_inputState !== 'refused') dispatchInput({ state: 'recording', error: reason });
    }

    /** Stop a live recording: a short tail of silence, "Done", then wait for the end of the stream. */
    function stopLiveInput(live) {
        var gen = inputGeneration;
        if (live.stopping) return;
        live.stopping = true;
        liveTeardownAudio(live);
        if (!live.ws || live.ws.readyState !== 1) { finishLiveInput(gen, live); return; }
        try {
            liveSendFloat(live, new Float32Array(Math.round(LIVE_RATE * LIVE_TAIL_SECONDS)));
            if (live.pending.length) { live.ws.send(live.pending.buffer); live.pending = new Float32Array(0); }
            live.ws.send('Done');
        } catch (e) { finishLiveInput(gen, live); return; }
        live.eofTimer = setTimeout(function () { finishLiveInput(gen, live); }, LIVE_EOF_WAIT_MS);
    }

    /**
     * Begin dictating. `opts.workspaceId` names where the text will go — the
     * Manager Settings "Test" button, which never dictates, still passes a
     * fixed non-empty id ('manager') so this one check stays in one place.
     * `opts.workspaceRunning === false` refuses immediately, before anything
     * opens a microphone. `opts.sttUrl` set switches to engine mode; empty
     * or absent stays in browser mode. `opts.sttLanguage` only applies in
     * browser mode (`SpeechRecognition.lang`); `opts.sttModel` is carried by
     * the caller's own settings load and does not need to travel here — the
     * server already knows it from `stt_model`. `opts.stopOnSilenceMs`
     * (a number above 0) ends the recording after that much silence that
     * follows speech ("Send when I stop talking"); absent or 0 turns it off.
     */
    function startInput(opts) {
        opts = opts || {};
        if (_inputState === 'recording' || _inputState === 'transcribing') return;
        if (inputRefuseTimer) { clearTimeout(inputRefuseTimer); inputRefuseTimer = null; }
        inputOpts = opts;
        if (!opts.workspaceId || opts.workspaceRunning === false) {
            refuseInput('There is no active workspace, or it is not running right now.');
            return;
        }
        var gen = ++inputGeneration;
        inputSeconds = 0;
        inputEngineAbandoned = false;
        inputEngineStopRequested = false;
        inputEngineDownSeen = false;
        inputHeardThisRecording = false;
        inputQuietSince = 0;
        clearInputTimers();
        inputSecondsTimer = setInterval(function () {
            if (gen !== inputGeneration) return;
            inputSeconds += 1;
            if (_inputState === 'recording') dispatchInput(inputLive ? { state: 'recording', interim: inputInterim, seconds: inputSeconds, live: true } : { state: 'recording', interim: inputInterim, seconds: inputSeconds });
        }, 1000);
        inputAutoStopTimer = setTimeout(function () {
            if (gen !== inputGeneration) return;
            stopInput();
        }, INPUT_MAX_MS);
        inputLive = null;
        // #731: a live (streaming) engine first; it falls back to the piece engine or the browser.
        if (opts.sttStreamUrl) startLiveInput(gen);
        else if (opts.sttUrl) startEngineInput(gen);
        else startBrowserInput(gen);
    }

    /** Stop now. Always delivers whatever was captured — never discards it. */
    function stopInput() {
        if (_inputState !== 'recording') return;
        // One stop only: a silence timer must not fire a second stop.
        if (inputSilenceStopTimer) { clearTimeout(inputSilenceStopTimer); inputSilenceStopTimer = null; }
        // #485: the icon stops pulsing now, whatever path asked for the stop.
        markInputStopped();
        if (inputLive) { stopLiveInput(inputLive); return; }   // #731
        if (inputRecogniser) {
            var rec = inputRecogniser;
            var gen = inputGeneration;
            try { rec.stop(); } catch (e) { finishBrowserInput(gen); return; }
            setTimeout(function () {
                if (gen !== inputGeneration || (_inputState !== 'transcribing' && _inputState !== 'recording')) return;
                try { rec.abort(); } catch (e) { /* noop */ }
                finishBrowserInput(gen);
            }, INPUT_STOP_GRACE_MS);
            return;
        }
        if (inputPieceRecorder) {
            inputEngineStopRequested = true;
            try {
                if (inputPieceRecorder.state === 'recording') inputPieceRecorder.stop();
                // else: an auto-cut already called stop() on this piece — its
                // pending onstop will see inputEngineStopRequested and treat
                // this piece as the last one.
            } catch (e) {
                stopEngineAudioAnalysis();
                stopInputTracks();
                refuseInput('The transcription server did not answer.');
            }
        }
    }

    // #485: leaving the page ends a recording through the same stop path, so the microphone is released.
    window.addEventListener('pagehide', function () {
        try { if (_inputState === 'recording') stopInput(); } catch (e) { /* the page is going away */ }
    });

    // ---- public API ------------------------------------------------------

    window.aicliVoice = {
        // The last state this tab was told about — see "global on/off state" above.
        enabled: function () { return isEnabled(); },
        // Called by the SPA and the Settings card right after their own
        // config load, so a fresh tab knows the state before the first
        // `state` message arrives. Also how a `state` message is applied
        // internally (see connect() above).
        setState: function (on) { applyState(on); },
        // The operator's own click on the drawer icon or Settings toggle,
        // run synchronously inside that click, when it turns voice ON. This
        // only unlocks autoplay; the caller invokes confirm() after the server
        // saves the switch, so the configured API voice is tried first.
        enable: function () {
            unlock();
        },
        // Test the configured voice for this tab only. The returned engine
        // clip is played directly; failures fall back to browser speech.
        confirm: function () {
            confirmAloud();
        },
        // Clears whatever is queued and dismisses the gesture-recovery
        // notice. Does not change the known state — that only ever comes
        // from setState()/a `state` message (VOICE_SWITCHES.md Edge Cases:
        // a clip already queued when voice turns off still plays).
        disable: function () {
            needsGesture = false;
            hideNotice();
            _queue.length = 0;
        },
        // Direct local speak, bypassing the on/off gate and the page-load
        // replay guard — used by the Manager Settings "Test" button so the
        // device under test hears the result immediately, whether or not
        // voice is on. Still goes through the same bounded queue as a
        // pushed clip, so it never talks over one already playing.
        speak: function (text) {
            queuePush({ mode: 'speech', text: text });
            pump();
        },
        // VOICE_MAIL.md R15: the workspace id whose clip plays now, or null.
        // Changes are also sent as the `aicli-voice-playing` window event.
        playingWorkspace: function () { return playingWorkspaceId; },
        // Stop the clip in flight at once (optionally only when it belongs to
        // `workspaceId`). Returns true when something stopped.
        stop: function (workspaceId) { return stopPlaying(workspaceId); },
        // ---- a note read aloud (docs/specs/NOTEBOOK_READ_ALOUD.md) ---------
        // read({id, chunks, engine, fetchClip(i) -> Promise<url|null>, voice?}) starts a read (it replaces an older one).
        // Workspace messages always play first; the read goes on after them. State: the `aicli-voice-reading` event.
        read: function (opts) { return readerStart(opts); },
        readPause: function () { return readerPause(); },
        readResume: function () { return readerResume(); },
        readStop: function () { return readerStop(); },
        readState: function () { return { id: reader ? reader.id : null, state: reader ? reader.state : 'idle' }; },
        // ---- input (docs/specs/VOICE_INPUT.md) ----------------------------
        // Begin dictating — see startInput() above for `opts`. No-op while
        // already recording or transcribing.
        startInput: function (opts) { startInput(opts); },
        // Stop now. Always finishes and reports whatever was captured so
        // far — a tap, Escape, or the 5-minute cap all take this same path.
        stopInput: function () { stopInput(); },
        // 'idle' | 'recording' | 'transcribing' | 'refused'.
        inputState: function () { return _inputState; }
    };

    connect();
})();
