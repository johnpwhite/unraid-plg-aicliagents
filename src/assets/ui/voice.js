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
 *   unlock (no sound), and the drawer/Settings toggle's own click, when it
 *   turns voice on, also speaks an audible "Voice on" so the click itself
 *   confirms the device can speak. Either satisfies Chrome/Safari's
 *   gesture-then-forever autoplay rule for the rest of the page's life.
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

    /** Workspace-name-prefixed, length-capped text for speechSynthesis. */
    function speechTextFor(msg) {
        var text = String(msg.text == null ? '' : msg.text).replace(/^\s+|\s+$/g, '');
        var full = msg.name ? (msg.name + ': ' + text) : text;
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

    function finishPlaying() {
        if (playingTimer) { clearTimeout(playingTimer); playingTimer = null; }
        currentUtt = null;
        playing = false;
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
            var ended = false;
            var finish = function (outcome) { if (ended) return; ended = true; done(outcome); };
            utt.onend = function () { finish('ended'); };
            utt.onerror = function (evt) {
                var code = evt && evt.error ? String(evt.error) : '';
                if (code === 'not-allowed') {
                    _queue.unshift(item);
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
    function playAudio(url, done) {
        done = done || function () {};
        try {
            var audio = new Audio(url);
            var settled = false;
            var finish = function (outcome) { if (settled) return; settled = true; done(outcome); };
            audio.onended = function () { finish('ended'); };
            audio.onerror = function () { finish('error'); };
            var p = audio.play();
            if (p && typeof p.catch === 'function') p.catch(function () { finish('error'); });
        } catch (e) {
            done('error');
        }
    }

    function pump() {
        if (playing || needsGesture) return;
        var item = queueNext();
        if (!item) return;
        playing = true;
        var done = function () { finishPlaying(); pump(); };
        if (item.mode === 'audio' && item.url) {
            playAudio(item.url, done);
        } else {
            playSpeech(item, done);
        }
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
            el.setAttribute('style', 'position:fixed;right:12px;bottom:12px;z-index:10002;padding:8px 12px;' +
                'font:12px/1.4 sans-serif;background:#1f2937;color:#ffffff;border:1px solid #f59e0b;' +
                'border-radius:6px;cursor:pointer;min-width:0;min-height:32px;');
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

    // Spoken synchronously inside the operator's own click on the toggle.
    // It is the speech-unlock gesture AND the confirmation that this device
    // can speak at all: a device that stays silent here has a local cause
    // (ringer switch, no voices, a browser without Web Speech).
    function confirmAloud() {
        try {
            if (!window.speechSynthesis) return;
            var u = new SpeechSynthesisUtterance('Voice on');
            try { window.speechSynthesis.resume(); } catch (e) { /* noop */ }
            window.speechSynthesis.speak(u);
        } catch (e) { /* best-effort */ }
    }

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
    // (no "Voice on" — that phrase is reserved for the operator's own click
    // on the toggle, VOICE_SWITCHES.md "Unlock"), then removes itself.
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

    function connect() {
        if (typeof EventSource === 'undefined') return;
        try {
            var es = new EventSource('/sub/aicli_voice');
            es.onmessage = function (evt) {
                var data;
                try { data = JSON.parse(evt.data); } catch (e) { return; }
                if (!data || !data.mode) return;
                if (data.mode !== 'state' && !claimForThisBrowser(data)) return;
                // State fan-out (VOICE_SWITCHES.md R7/R8): report the value,
                // never play it.
                if (data.mode === 'state') { applyState(data.enabled); return; }
                if (!shouldPlay(data)) return;
                queuePush(data);
                pump();
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

    function clearInputTimers() {
        if (inputSecondsTimer) { clearInterval(inputSecondsTimer); inputSecondsTimer = null; }
        if (inputAutoStopTimer) { clearTimeout(inputAutoStopTimer); inputAutoStopTimer = null; }
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
        clearInputTimers();
        var leftover = inputInterim.replace(/^\s+|\s+$/g, '');
        inputInterim = '';
        inputRecogniser = null;
        setInputState('idle', { text: leftover });
    }

    function startBrowserInput(gen) {
        var Ctor = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!Ctor) {
            refuseInput('This browser has no speech recognition. Use Safari or Chrome, or set a transcription endpoint in Settings, Agent voice.');
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
                        if (phrase) dispatchInput({ state: 'recording', phrase: phrase });
                    } else {
                        interim += (r[0] && r[0].transcript) || '';
                    }
                }
                inputInterim = interim;
                setInputState('recording', { interim: interim, seconds: inputSeconds });
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
            refuseInput('This browser has no speech recognition. Use Safari or Chrome, or set a transcription endpoint in Settings, Agent voice.');
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
        if (rms >= ENGINE_SILENCE_RMS) {
            inputHeardSpeech = true;
            inputSilenceSince = 0;
            return;
        }
        if (!inputHeardSpeech) return; // silence before any speech: not a pause to cut on
        var now = Date.now();
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
            var onDone = function (text, error) {
                if (gen !== inputGeneration) { resolve(); return; }
                if (isFinal) {
                    setInputState('idle', { text: text || '' });
                } else if (error) {
                    // R11: one piece's transcription failing must not end the
                    // recording — only report it and keep listening.
                    dispatchInput({ state: 'recording', error: error });
                } else if (text) {
                    dispatchInput({ state: 'recording', phrase: text });
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
                    '&mime=' + encodeURIComponent(mimeType || '') +
                    '&seconds=' + encodeURIComponent(String(pieceSeconds)) +
                    '&workspaceId=' + encodeURIComponent(inputOpts.workspaceId || '') +
                    '&csrf_token=' + encodeURIComponent(inputCsrf());
                fetch(inputAjaxUrl('voice_transcribe'), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body
                }).then(function (r) { return r.json(); }).then(function (data) {
                    if (data && data.status === 'ok') {
                        onDone(String(data.text || '').replace(/^\s+|\s+$/g, ''), null);
                    } else {
                        onDone('', (data && data.message) || 'The transcription server did not answer.');
                    }
                }).catch(function () {
                    onDone('', 'The transcription server did not answer.');
                });
            };
            reader.onerror = function () { onDone('', 'The transcription server did not answer.'); };
            reader.readAsDataURL(blob);
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

    function startEngineInput(gen) {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !window.MediaRecorder) {
            refuseInput('The browser refused microphone access; allow it in the site settings and try again.');
            return;
        }
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
        }).catch(function () {
            if (gen !== inputGeneration) return;
            refuseInput('The browser refused microphone access; allow it in the site settings and try again.');
        });
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
     * server already knows it from `stt_model`.
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
        clearInputTimers();
        inputSecondsTimer = setInterval(function () {
            if (gen !== inputGeneration) return;
            inputSeconds += 1;
            if (_inputState === 'recording') dispatchInput({ state: 'recording', interim: inputInterim, seconds: inputSeconds });
        }, 1000);
        inputAutoStopTimer = setTimeout(function () {
            if (gen !== inputGeneration) return;
            stopInput();
        }, INPUT_MAX_MS);
        if (opts.sttUrl) startEngineInput(gen);
        else startBrowserInput(gen);
    }

    /** Stop now. Always delivers whatever was captured — never discards it. */
    function stopInput() {
        if (_inputState !== 'recording') return;
        if (inputRecogniser) {
            try { inputRecogniser.stop(); } catch (e) { finishBrowserInput(inputGeneration); }
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
        // run synchronously inside that click, when it turns voice ON.
        // Unlocks this device and says "Voice on" so the click itself
        // confirms the device can speak. Writes nothing and changes no
        // state on its own — the caller saves `voice_enabled`, and every
        // tab (including this one) learns the new value from the `state`
        // message the save triggers.
        enable: function () {
            unlock();
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
