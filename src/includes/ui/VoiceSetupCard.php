<?php
/**
 * <module_context>
 *     <name>VoiceSetupCard</name>
 *     <description>The "Set up natural voice" row of the Settings > Agent voice
 *     card (docs/specs/AGENT_VOICE.md, "#323 guided setup"). One button, the
 *     download and memory cost, and a status line with Cancel while the page
 *     waits for the Kokoro engine to answer. When the container is already
 *     installed the button reads "Connect natural voice" and a line shows the
 *     container state (docs/specs/VOICE_ENGINE_SETUP.md, #382). The script also
 *     defines window.aicliVoiceSetupCreate, which VoiceSttSetupCard.php uses for
 *     the local dictation row (#381). Included by ManagerConfigTab.php inside
 *     the card's <dl>.</description>
 *     <dependencies>VoiceHandler actions voice_engine_prepare (POST),
 *     check_voice_engine (GET), voice_engine_finish (POST),
 *     voice_engine_status (GET); voice.js
 *     (window.aicliVoice.enable/confirm); ManagerConfigTab's
 *     window.aicliVoiceLoadSettings.</dependencies>
 *     <constraints>Framework-free (no jQuery), so the Playwright spec in
 *     ui-build/tests/voice-setup-e2e/ can load this file's markup on a static,
 *     stubbed page. No PHP output below this block: the spec strips only this
 *     comment. Never sends a URL or a path to the server.</constraints>
 * </module_context>
 */
?>
<dt>Natural voice</dt>
<dd>
    <div id="aicli-voice-setup">
        <style>
            #aicli-voice-setup { width: 100%; }
            /* An author display rule (.fa, flex) would beat the hidden attribute. */
            #aicli-voice-setup [hidden] { display: none !important; }
            #aicli-voice-setup .aicli-vs-note { font-size: 10px; opacity: 0.65; margin-top: 3px; }
            #aicli-voice-setup .aicli-vs-panel { margin-top: 8px; padding: 8px 10px; border: 1px solid var(--border-color, rgba(128,128,128,0.35)); border-radius: 4px; font-size: 12px; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
            #aicli-voice-setup .aicli-vs-steps { flex: 1 1 100%; list-style: none; margin: 0; padding: 0; display: flex; flex-wrap: wrap; gap: 4px 16px; }
            #aicli-voice-setup .aicli-vs-steps li { display: inline-flex; align-items: center; gap: 6px; opacity: 0.55; }
            #aicli-voice-setup .aicli-vs-steps li.aicli-vs-step-done { opacity: 1; color: #4ade80; }
            #aicli-voice-setup .aicli-vs-steps li.aicli-vs-step-now { opacity: 1; font-weight: 600; }
            #aicli-voice-setup .aicli-vs-steps li.aicli-vs-step-bad { opacity: 1; color: #f87171; font-weight: 600; }
            #aicli-voice-setup .aicli-vs-status { flex: 1 1 200px; min-width: 0; overflow-wrap: anywhere; }
            #aicli-voice-setup .aicli-vs-status.aicli-vs-error { color: #f87171; }
            #aicli-voice-setup .aicli-vs-status.aicli-vs-done { color: #4ade80; }
            #aicli-voice-setup .aicli-vs-actions { display: flex; flex-wrap: wrap; gap: 8px; }
            #aicli-voice-setup a.aicli-vs-link { display: inline-flex; align-items: center; }
            @media (max-width: 600px) {
                #aicli-voice-setup button, #aicli-voice-setup a.aicli-vs-link { min-height: 44px; min-width: 44px; }
                #aicli-voice-setup .aicli-vs-actions { width: 100%; }
            }
        </style>
        <button type="button" id="aicli-voice-setup-btn" class="aicli-btn-slim" onclick="aicliVoiceSetupStart()">
            <i class="fa fa-magic" aria-hidden="true"></i> <span id="aicli-voice-setup-btn-label">Set up natural voice</span>
        </button>
        <div class="aicli-vs-note" id="aicli-voice-setup-state" role="status" aria-live="polite" hidden></div>
        <div class="aicli-vs-note">
            Installs the free Kokoro speech engine as a normal Docker app, then connects it here.
            You confirm it on Unraid's own Add Container page. The download is about 3.3 GB and already
            contains the voice model. The engine uses about 1.7 GB of memory while it runs.
        </div>
        <div class="aicli-vs-note" id="aicli-voice-setup-permission-help">
            If the voice engine container stops with Permission denied, remove its Model cache path in the container settings.
        </div>
        <div id="aicli-voice-setup-panel" class="aicli-vs-panel" hidden>
            <ol id="aicli-voice-setup-steps" class="aicli-vs-steps" aria-label="Setup progress" hidden></ol>
            <span id="aicli-voice-setup-spinner" class="fa fa-spinner fa-spin" aria-hidden="true"></span>
            <span id="aicli-voice-setup-status" class="aicli-vs-status" role="status" aria-live="polite"></span>
            <span class="aicli-vs-actions">
                <a id="aicli-voice-setup-link" class="aicli-vs-link" href="#" target="_blank" rel="noopener" hidden>Open Add Container</a>
                <button type="button" id="aicli-voice-setup-cancel" class="aicli-btn-slim" onclick="aicliVoiceSetupCancel()">Cancel</button>
            </span>
        </div>
    </div>
    <script>
    (function () {
        'use strict';
        var AJAX = '/plugins/unraid-aicliagents/AICliAjax.php';
        var MAX_WAIT_MS = 20 * 60 * 1000;

        function token() { return window.csrf_token || ''; }
        function pollMs() { return Number(window.aicliVoiceSetupPollMs) > 0 ? Number(window.aicliVoiceSetupPollMs) : 4000; }

        // One setup row. cfg.engine is 'tts' or 'stt'; cfg.prefix is the id prefix of
        // the row's elements (docs/specs/VOICE_ENGINE_SETUP.md R10).
        function create(cfg) {
            var timer = null, startedAt = 0, active = false, finishing = false;
            var installed = false, fromInstall = false, crashSeen = 0, stage = -1;

            function $id(suffix) { return document.getElementById(cfg.prefix + '-' + suffix); }
            function engineParams(params) {
                // The default engine sends nothing extra, so its requests stay exactly as before.
                if (cfg.engine === 'stt') params.engine = 'stt';
                return params;
            }
            function post(action) {
                var t = token();
                return fetch(AJAX + '?action=' + encodeURIComponent(action) + '&csrf_token=' + encodeURIComponent(t), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams(engineParams({ csrf_token: t })).toString()
                }).then(function (r) { return r.json(); });
            }
            function get(action) {
                var q = new URLSearchParams(engineParams({ action: action, csrf_token: token() })).toString();
                return fetch(AJAX + '?' + q).then(function (r) { return r.json(); });
            }

            // kind: '' (working), 'error' (stopped), 'info' (cancelled), 'done' (connected)
            function show(text, kind) {
                $id('panel').hidden = false;
                var s = $id('status');
                s.textContent = text;
                s.className = 'aicli-vs-status' + (kind ? ' aicli-vs-' + kind : '');
                $id('spinner').hidden = kind !== '';
                $id('cancel').textContent = kind ? 'Close' : 'Cancel';
                $id('btn').disabled = !kind;
                renderSteps(kind);
            }
            // The progress list. stage is the step being worked on (-1 hides the list);
            // earlier steps are done, later ones wait; a stopped run marks its step bad.
            function renderSteps(kind) {
                var ol = $id('steps');
                if (!ol) return;
                if (stage < 0 || !cfg.steps) { ol.hidden = true; return; }
                ol.hidden = false;
                ol.textContent = '';
                cfg.steps.forEach(function (label, i) {
                    var li = document.createElement('li');
                    var icon = 'fa-circle-o', cls = '';
                    if (kind === 'done' || i < stage) { icon = 'fa-check-circle'; cls = 'aicli-vs-step-done'; }
                    else if (i === stage) {
                        if (kind === 'error') { icon = 'fa-times-circle'; cls = 'aicli-vs-step-bad'; }
                        else if (kind === 'info') { icon = 'fa-pause-circle'; cls = 'aicli-vs-step-now'; }
                        else { icon = 'fa-spinner fa-spin'; cls = 'aicli-vs-step-now'; }
                    }
                    if (cls) li.className = cls;
                    var ic = document.createElement('i');
                    ic.className = 'fa ' + icon;
                    ic.setAttribute('aria-hidden', 'true');
                    li.appendChild(ic);
                    li.appendChild(document.createTextNode(label));
                    ol.appendChild(li);
                });
            }
            var STAGE_OF = { no_container: 0, stopped: 0, crashing: 0, starting: 1, needs_model: 2, downloading: 2, ready: 3 };
            function stop() {
                active = false;
                if (timer) { clearTimeout(timer); timer = null; }
            }

            // #382: what is installed, before any click. A failed or unknown answer keeps
            // the plain "Set up" button, so the row never gets worse than before.
            var STATE_TEXT = {
                ready: 'is running and answers.',
                starting: 'is running but does not answer yet.',
                stopped: 'is stopped.',
                crashing: 'keeps stopping.',
                needs_model: 'is running, but its model is not installed yet. Press Connect to install it.',
                downloading: 'is downloading its model. Press Connect to follow the progress.'
            };
            function renderInstalled(r) {
                installed = !!(r && r.status === 'ok' && r.installed);
                $id('btn-label').textContent = installed ? cfg.connectText : cfg.setupText;
                var line = $id('state');
                if (!installed) { line.hidden = true; line.textContent = ''; return; }
                var name = r.container || cfg.label;
                var text = STATE_TEXT[r.state] ? 'The ' + name + ' container ' + STATE_TEXT[r.state] : 'The ' + name + ' container is installed.';
                if ((r.state === 'stopped' || r.state === 'crashing') && r.message) text = r.message;
                line.textContent = text;
                line.hidden = false;
            }
            function refresh() {
                return get('voice_engine_status').then(renderInstalled).catch(function () { renderInstalled(null); });
            }

            function schedule() {
                if (!active) return;
                if (Date.now() - startedAt > MAX_WAIT_MS) {
                    stop();
                    show(cfg.label + ' did not answer within 20 minutes. If the image download failed or you closed the Add Container page, press "' + cfg.setupText + '" again.', 'error');
                    return;
                }
                timer = setTimeout(poll, pollMs());
            }

            function poll() {
                timer = null;
                if (!active) return;
                get('check_voice_engine').then(function (r) {
                    if (!active) return;
                    if (!r || r.status !== 'ok') { show((r && r.message) || 'Could not check the engine. Trying again.', ''); schedule(); return; }
                    stage = STAGE_OF[r.state] !== undefined ? STAGE_OF[r.state] : stage;
                    if (r.state === 'ready') { finish(); return; }
                    // #382: a container that was already there and is stopped or crashing is
                    // explained at once (with its last log line). Right after Apply it can be
                    // briefly "created", so a fresh install waits, and only a container that
                    // keeps crashing is reported.
                    if (r.state === 'crashing') crashSeen++; else crashSeen = 0;
                    if ((r.state === 'stopped' || r.state === 'crashing') && (!fromInstall || crashSeen >= 2)) {
                        stop();
                        show(r.message || 'The container is not running.', 'error');
                        refresh();
                        return;
                    }
                    // The engine runs but has no model: ask it to download one. The server
                    // starts the download once, so asking again on a later poll is harmless.
                    if (r.state === 'needs_model') {
                        show(r.message, '');
                        post('voice_engine_download').then(function (d) {
                            if (!active) return;
                            if (!d || d.status !== 'ok') { stop(); show((d && d.message) || 'The model download could not start.', 'error'); return; }
                            schedule();
                        }).catch(function () { if (active) schedule(); });
                        return;
                    }
                    show(r.message || 'Waiting for ' + cfg.label + '.', '');
                    schedule();
                }).catch(function () {
                    if (!active) return;
                    show('Network error while checking the engine. Trying again.', '');
                    schedule();
                });
            }

            function finish() {
                if (finishing) return;
                finishing = true;
                stage = 3;
                post('voice_engine_finish').then(function (r) {
                    finishing = false;
                    if (!active) return;
                    if (!r || r.status !== 'ok') {
                        if (r && (r.state === 'stopped' || r.state === 'crashing')) { stop(); show(r.message || 'The container is not running.', 'error'); refresh(); return; }
                        show((r && r.message) || cfg.label + ' is not ready yet.', ''); schedule(); return;
                    }
                    stop();
                    var msg = cfg.connectedText(r);
                    if (r.folder === 'added' || r.folder === 'already') msg += ' The container is in the aicliagents folder on the Docker tab.';
                    show(msg, 'done');
                    refresh();
                    if (typeof window.aicliVoiceLoadSettings === 'function') window.aicliVoiceLoadSettings();
                    if (cfg.playTest && window.aicliVoice && typeof window.aicliVoice.confirm === 'function') window.aicliVoice.confirm();
                }).catch(function () {
                    finishing = false;
                    if (!active) return;
                    show('Network error while saving. Trying again.', '');
                    schedule();
                });
            }

            function start() {
                if (active) return;
                // This click is the gesture that lets this tab play the test
                // sentence later (voice.js unlock), before any await.
                if (cfg.playTest && window.aicliVoice && typeof window.aicliVoice.enable === 'function') window.aicliVoice.enable();
                $id('link').hidden = true;
                show('Checking Docker…', '');
                active = true;
                fromInstall = false;
                crashSeen = 0;
                stage = -1;
                startedAt = Date.now();
                post('voice_engine_prepare').then(function (r) {
                    if (!active) return;
                    if (!r || r.status !== 'ok') { stop(); show((r && r.message) || 'Setup could not start.', 'error'); return; }
                    if (r.mode === 'existing') {
                        show('Found the ' + r.container + ' container. Connecting…', '');
                        poll();
                        return;
                    }
                    fromInstall = true;
                    var link = $id('link');
                    link.href = r.addContainerUrl;
                    link.hidden = false;
                    var w = null;
                    try { w = window.open(r.addContainerUrl, '_blank'); } catch (e) { w = null; }
                    show((w ? 'Unraid\'s Add Container page opened in a new tab. ' : 'Open the Add Container page with the link. ')
                        + 'Check the settings and press Apply there (port ' + r.port + '). This page connects when ' + cfg.label + ' answers.', '');
                    schedule();
                }).catch(function () {
                    stop();
                    show('Network error. Setup did not start.', 'error');
                });
            }

            function cancel() {
                var wasActive = active;
                stop();
                finishing = false;
                $id('btn').disabled = false;
                if (wasActive) {
                    stage = -1;
                    show('Setup cancelled. Nothing was saved. If a ' + cfg.label + ' container was created, it stays on the Docker tab.', 'info');
                } else {
                    $id('panel').hidden = true;
                }
            }

            refresh();
            return { start: start, cancel: cancel, refresh: refresh };
        }

        window.aicliVoiceSetupCreate = create;
        var tts = create({
            engine: 'tts', prefix: 'aicli-voice-setup', label: 'Kokoro', playTest: true,
            steps: ['Container running', 'Engine answers', 'Voices ready', 'Settings saved'],
            setupText: 'Set up natural voice', connectText: 'Connect natural voice',
            connectedText: function (r) { return 'Connected to Kokoro at ' + r.url + ' with the voice ' + r.voice + '. Playing the test sentence.'; }
        });
        window.aicliVoiceSetupStart = tts.start;
        window.aicliVoiceSetupCancel = tts.cancel;
    }());
    </script>
</dd>
