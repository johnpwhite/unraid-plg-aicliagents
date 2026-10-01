<?php
/**
 * <module_context>
 *     <name>VoiceSttSetupCard</name>
 *     <description>The "Set up local dictation" row of the Settings > Agent voice
 *     card, in the Voice input section (docs/specs/VOICE_ENGINE_SETUP.md, #381
 *     and #382). The same guided flow as the natural voice row, for a local
 *     Speaches (Whisper) server: it writes the Docker template, opens Unraid's
 *     own Add Container page, waits for the engine, then saves stt_url and
 *     stt_model. When the container is already installed the button reads
 *     "Connect local dictation" and shows the container state. Included by
 *     ManagerConfigTab.php inside the card's dl, after VoiceSetupCard.php.</description>
 *     <dependencies>window.aicliVoiceSetupCreate, defined by VoiceSetupCard.php;
 *     VoiceHandler actions voice_engine_prepare, check_voice_engine,
 *     voice_engine_finish (POST/GET/POST) and voice_engine_status (GET), all with
 *     engine=stt; ManagerConfigTab's window.aicliVoiceLoadSettings.</dependencies>
 *     <constraints>Framework-free (no jQuery), so the Playwright spec in
 *     ui-build/tests/voice-setup-e2e/ can load this file's markup on a static,
 *     stubbed page. No PHP output below this block: the spec strips only this
 *     comment. Never sends a URL or a path to the server.</constraints>
 * </module_context>
 */
?>
<dt>Local dictation</dt>
<dd>
    <div id="aicli-stt-setup">
        <style>
            #aicli-stt-setup { width: 100%; }
            /* An author display rule (.fa, flex) would beat the hidden attribute. */
            #aicli-stt-setup [hidden] { display: none !important; }
            #aicli-stt-setup .aicli-vs-note { font-size: 10px; opacity: 0.65; margin-top: 3px; }
            #aicli-stt-setup .aicli-vs-panel { margin-top: 8px; padding: 8px 10px; border: 1px solid var(--border-color, rgba(128,128,128,0.35)); border-radius: 4px; font-size: 12px; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
            #aicli-stt-setup .aicli-vs-steps { flex: 1 1 100%; list-style: none; margin: 0; padding: 0; display: flex; flex-wrap: wrap; gap: 4px 16px; }
            #aicli-stt-setup .aicli-vs-steps li { display: inline-flex; align-items: center; gap: 6px; opacity: 0.55; }
            #aicli-stt-setup .aicli-vs-steps li.aicli-vs-step-done { opacity: 1; color: #4ade80; }
            #aicli-stt-setup .aicli-vs-steps li.aicli-vs-step-now { opacity: 1; font-weight: 600; }
            #aicli-stt-setup .aicli-vs-steps li.aicli-vs-step-bad { opacity: 1; color: #f87171; font-weight: 600; }
            #aicli-stt-setup .aicli-vs-status { flex: 1 1 200px; min-width: 0; overflow-wrap: anywhere; }
            #aicli-stt-setup .aicli-vs-status.aicli-vs-error { color: #f87171; }
            #aicli-stt-setup .aicli-vs-status.aicli-vs-done { color: #4ade80; }
            #aicli-stt-setup .aicli-vs-actions { display: flex; flex-wrap: wrap; gap: 8px; }
            #aicli-stt-setup a.aicli-vs-link { display: inline-flex; align-items: center; }
            @media (max-width: 600px) {
                #aicli-stt-setup button, #aicli-stt-setup a.aicli-vs-link { min-height: 44px; min-width: 44px; }
                #aicli-stt-setup .aicli-vs-actions { width: 100%; }
            }
        </style>
        <button type="button" id="aicli-stt-setup-btn" class="aicli-btn-slim" onclick="aicliSttSetupStart()">
            <i class="fa fa-microphone" aria-hidden="true"></i> <span id="aicli-stt-setup-btn-label">Set up local dictation</span>
        </button>
        <div class="aicli-vs-note" id="aicli-stt-setup-state" role="status" aria-live="polite" hidden></div>
        <div class="aicli-vs-note">
            Installs the free Speaches server (Whisper) as a normal Docker app, then connects it here.
            Dictation then stays on your own server and works in every browser. You confirm it on
            Unraid's own Add Container page. Connect then downloads the Whisper model
            (about 500 MB), which can take several minutes. You can leave this page; press Connect again later.
        </div>
        <div id="aicli-stt-setup-panel" class="aicli-vs-panel" hidden>
            <ol id="aicli-stt-setup-steps" class="aicli-vs-steps" aria-label="Setup progress" hidden></ol>
            <span id="aicli-stt-setup-spinner" class="fa fa-spinner fa-spin" aria-hidden="true"></span>
            <span id="aicli-stt-setup-status" class="aicli-vs-status" role="status" aria-live="polite"></span>
            <span class="aicli-vs-actions">
                <a id="aicli-stt-setup-link" class="aicli-vs-link" href="#" target="_blank" rel="noopener" hidden>Open Add Container</a>
                <button type="button" id="aicli-stt-setup-cancel" class="aicli-btn-slim" onclick="aicliSttSetupCancel()">Cancel</button>
            </span>
        </div>
    </div>
    <script>
    (function () {
        'use strict';
        if (typeof window.aicliVoiceSetupCreate !== 'function') return;
        var stt = window.aicliVoiceSetupCreate({
            engine: 'stt', prefix: 'aicli-stt-setup', label: 'Speaches', playTest: false,
            steps: ['Container running', 'Server answers', 'Whisper model downloaded', 'Settings saved'],
            setupText: 'Set up local dictation', connectText: 'Connect local dictation',
            connectedText: function (r) { return 'Connected to Speaches at ' + r.url + ' with the model ' + r.model + '. Try the Test button below.'; }
        });
        window.aicliSttSetupStart = stt.start;
        window.aicliSttSetupCancel = stt.cancel;
    }());
    </script>
</dd>
