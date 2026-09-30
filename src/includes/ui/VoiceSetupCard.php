<?php
/**
 * <module_context>
 *     <name>VoiceSetupCard</name>
 *     <description>The "Set up natural voice" row of the Settings > Agent voice
 *     card (docs/specs/AGENT_VOICE.md, "#323 guided setup"). One button, the
 *     download and memory cost, and a status line with Cancel while the page
 *     waits for the Kokoro engine to answer. Included by ManagerConfigTab.php
 *     inside the card's <dl>.</description>
 *     <dependencies>VoiceHandler actions voice_engine_prepare (POST),
 *     check_voice_engine (GET), voice_engine_finish (POST); voice.js
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
            <i class="fa fa-magic" aria-hidden="true"></i> Set up natural voice
        </button>
        <div class="aicli-vs-note">
            Installs the free Kokoro speech engine as a normal Docker app, then connects it here.
            You confirm it on Unraid's own Add Container page. The download is about 3.3 GB and already
            contains the voice model. The engine uses about 1.7 GB of memory while it runs.
        </div>
        <div class="aicli-vs-note" id="aicli-voice-setup-permission-help">
            If the voice engine container stops with Permission denied, remove its Model cache path in the container settings.
        </div>
        <div id="aicli-voice-setup-panel" class="aicli-vs-panel" hidden>
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
        var timer = null, startedAt = 0, active = false, finishing = false;

        function $id(id) { return document.getElementById(id); }
        function token() { return window.csrf_token || ''; }
        function pollMs() { return Number(window.aicliVoiceSetupPollMs) > 0 ? Number(window.aicliVoiceSetupPollMs) : 4000; }

        function post(action) {
            var t = token();
            return fetch(AJAX + '?action=' + encodeURIComponent(action) + '&csrf_token=' + encodeURIComponent(t), {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ csrf_token: t }).toString()
            }).then(function (r) { return r.json(); });
        }
        function get(action) {
            return fetch(AJAX + '?action=' + encodeURIComponent(action) + '&csrf_token=' + encodeURIComponent(token()))
                .then(function (r) { return r.json(); });
        }

        // kind: '' (working), 'error' (stopped), 'info' (cancelled), 'done' (connected)
        function show(text, kind) {
            $id('aicli-voice-setup-panel').hidden = false;
            var s = $id('aicli-voice-setup-status');
            s.textContent = text;
            s.className = 'aicli-vs-status' + (kind ? ' aicli-vs-' + kind : '');
            $id('aicli-voice-setup-spinner').hidden = kind !== '';
            $id('aicli-voice-setup-cancel').textContent = kind ? 'Close' : 'Cancel';
            $id('aicli-voice-setup-btn').disabled = !kind;
        }
        function stop() {
            active = false;
            if (timer) { clearTimeout(timer); timer = null; }
        }

        function schedule() {
            if (!active) return;
            if (Date.now() - startedAt > MAX_WAIT_MS) {
                stop();
                show('Kokoro did not answer within 20 minutes. If the image download failed or you closed the Add Container page, press "Set up natural voice" again.', 'error');
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
                if (r.state === 'ready') { finish(); return; }
                show(r.message || 'Waiting for Kokoro.', '');
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
            post('voice_engine_finish').then(function (r) {
                finishing = false;
                if (!active) return;
                if (!r || r.status !== 'ok') { show((r && r.message) || 'Kokoro is not ready yet.', ''); schedule(); return; }
                stop();
                var msg = 'Connected to Kokoro at ' + r.url + ' with the voice ' + r.voice + '. Playing the test sentence.';
                if (r.folder === 'added' || r.folder === 'already') msg += ' The container is in the aicliagents folder on the Docker tab.';
                show(msg, 'done');
                if (typeof window.aicliVoiceLoadSettings === 'function') window.aicliVoiceLoadSettings();
                if (window.aicliVoice && typeof window.aicliVoice.confirm === 'function') window.aicliVoice.confirm();
            }).catch(function () {
                finishing = false;
                if (!active) return;
                show('Network error while saving. Trying again.', '');
                schedule();
            });
        }

        window.aicliVoiceSetupStart = function () {
            if (active) return;
            // This click is the gesture that lets this tab play the test
            // sentence later (voice.js unlock), before any await.
            if (window.aicliVoice && typeof window.aicliVoice.enable === 'function') window.aicliVoice.enable();
            $id('aicli-voice-setup-link').hidden = true;
            show('Checking Docker…', '');
            active = true;
            startedAt = Date.now();
            post('voice_engine_prepare').then(function (r) {
                if (!active) return;
                if (!r || r.status !== 'ok') { stop(); show((r && r.message) || 'Setup could not start.', 'error'); return; }
                if (r.mode === 'existing') {
                    show('Found the ' + r.container + ' container. Connecting…', '');
                    poll();
                    return;
                }
                var link = $id('aicli-voice-setup-link');
                link.href = r.addContainerUrl;
                link.hidden = false;
                var w = null;
                try { w = window.open(r.addContainerUrl, '_blank'); } catch (e) { w = null; }
                show((w ? 'Unraid\'s Add Container page opened in a new tab. ' : 'Open the Add Container page with the link. ')
                    + 'Check the settings and press Apply there (port ' + r.port + '). This page connects when Kokoro answers.', '');
                schedule();
            }).catch(function () {
                stop();
                show('Network error. Setup did not start.', 'error');
            });
        };

        window.aicliVoiceSetupCancel = function () {
            var wasActive = active;
            stop();
            finishing = false;
            $id('aicli-voice-setup-btn').disabled = false;
            if (wasActive) {
                show('Setup cancelled. Nothing was saved. If a Kokoro container was created, it stays on the Docker tab.', 'info');
            } else {
                $id('aicli-voice-setup-panel').hidden = true;
            }
        };
    }());
    </script>
</dd>
