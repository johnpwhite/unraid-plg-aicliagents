<?php
/**
 * <module_context>
 *     <name>VoiceHandler</name>
 *     <description>AJAX handler for the Manager Settings "Voice" section
 *     (docs/specs/AGENT_VOICE.md): read/save the six plugin-cfg voice settings
 *     (three per direction — speak OUT, dictate IN) plus their two engine API
 *     keys, a "Test" button, and the raw `voice_clip` stream a browser's
 *     `&lt;audio&gt;` tag plays. `voice_clip` is a RAW-OUTPUT action —
 *     AICliAjax.php dispatches it before the standard JSON handler chain, the same
 *     way it already does for `get_install_status`/`diag_bundle_download`.
 *     `list_voices` (R13) is a plain read action: the engine's voice
 *     catalogue for the Settings "Voice id" dropdown. docs/specs/VOICE_INPUT.md
 *     adds the drawer mic's two actions: `voice_transcribe` (a recorded clip
 *     in, recognised text out) and `voice_dictate` (text in, pasted into a
 *     workspace's pane and optionally submitted).</description>
 *     <dependencies>VoiceService (settings/speak/listVoices/transcribe/dictate),
 *     AdminService (setSetting for the six allow-listed cfg keys, callerIdentity
 *     for the Test button's attribution), SecretService (the TTS_API_KEY/
 *     STT_API_KEY vault entries).</dependencies>
 *     <constraints>save_voice_settings never returns an API key — only whether one
 *     is stored (tts_api_key_set/stt_api_key_set). An empty `tts_api_key`/
 *     `stt_api_key` POST field leaves the stored key unchanged; the literal
 *     `__clear__` removes it. voice_clip validates `id` against
 *     `/^v_[0-9a-f]{16}$/` before it ever reaches a filesystem path — no other
 *     shape is read. voice_transcribe/voice_dictate never log the recognised
 *     or dictated text — only character counts and, for a clip, its
 *     duration.</constraints>
 * </module_context>
 */

namespace AICliAgents\Handlers;

use AICliAgents\Services\AdminService;
use AICliAgents\Services\SecretService;
use AICliAgents\Services\VoiceMailService;
use AICliAgents\Services\VoiceEngineSetupService;
use AICliAgents\Services\VoiceService;

class VoiceHandler
{
    /** The fixed sentence test_voice speaks for the Settings Test button (R6). */
    private const TEST_SENTENCE = 'Voice is working on this server.';

    /**
     * The fixed sentences for the other test_voice purposes. The page picks a
     * purpose, never the text: test_voice never speaks client-supplied text.
     * - `switch`: VOICE_SWITCHES.md, the confirmation when voice is turned on.
     * - `voice`: AGENT_VOICE.md R14, the Voice… dialog sample.
     * - `announce`: VOICE_MAIL.md "Test the spoken name"; the intro goes first.
     */
    private const PURPOSE_SENTENCES = [
        ''         => self::TEST_SENTENCE,
        'switch'   => 'Voice enabled.',
        'voice'    => 'This is how this voice sounds.',
        'announce' => 'This is how this workspace is announced.',
    ];

    public static function handle($action, $id): ?array
    {
        switch ($action) {
            case 'get_voice_settings':  return self::getVoiceSettings();
            case 'save_voice_settings': return self::saveVoiceSettings();
            case 'test_voice':          return self::testVoice();
            case 'list_voices':         return self::listVoices();
            case 'voice_transcribe':    return self::voiceTranscribe();
            case 'voice_dictate':       return self::voiceDictate();
            // docs/specs/VOICE_MAIL.md — Forgejo #210.
            case 'voicemail_list':      return self::voicemailList();
            case 'voicemail_mark_heard':return self::voicemailMarkHeard();
            case 'voicemail_replay':    return self::voicemailReplay();
            case 'voicemail_set_mode':  return self::voicemailSetMode();
            case 'voicemail_delete':    return self::voicemailDelete();
            case 'voicemail_delete_all':return self::voicemailDeleteAll();
            // VOICE_MAIL.md R14 — Forgejo #376.
            case 'voicemail_set_spoken_name': return self::voicemailSetSpokenName();
            // AGENT_VOICE.md R14 — Forgejo #377.
            case 'voice_set_workspace_voice': return self::voiceSetWorkspaceVoice();
            // AGENT_VOICE.md "#323 guided setup" — Forgejo #323.
            case 'voice_engine_prepare':return self::voiceEnginePrepare();
            case 'check_voice_engine':  return VoiceEngineSetupService::check(self::engineParam());
            // VOICE_ENGINE_SETUP.md — Forgejo #381 #382: what is installed, before any button.
            case 'voice_engine_status': return VoiceEngineSetupService::status(self::engineParam());
            case 'voice_engine_finish': return self::voiceEngineFinish();
            case 'voice_engine_download': return self::voiceEngineDownload();
            default:                    return null;
        }
    }

    /** Actions handled by this handler. */
    // ---- voice mail (docs/specs/VOICE_MAIL.md) --------------------------------
    // CSRF: enforced centrally in AICliAjax.php for every action except
    // `voice_clip`, so all four of these are protected with no per-action work.

    /** Messages newest first (optionally one workspace) plus unheard counts. */
    private static function voicemailList(): array
    {
        $workspaceId = trim((string)($_REQUEST['workspaceId'] ?? ''));
        return ['status' => 'ok'] + VoiceMailService::list($workspaceId !== '' ? $workspaceId : null);
    }

    /**
     * Mark one message, or every message for a workspace, heard.
     *
     * The browser calls this ONLY when playback finished while its tab was visible
     * (or when the operator plays one from voice mail). That is the entire
     * definition of "heard" — the server cannot know a human heard anything.
     */
    private static function voicemailMarkHeard(): array
    {
        $id = trim((string)($_REQUEST['id'] ?? ''));
        $workspaceId = trim((string)($_REQUEST['workspaceId'] ?? ''));
        $all = ((string)($_REQUEST['all'] ?? '0')) === '1';
        if ($id === '' && !($all && $workspaceId !== '')) {
            return ['status' => 'error', 'message' => 'Give a message id, or a workspace with all=1.'];
        }
        $updated = $id !== ''
            ? VoiceMailService::markHeard($id)
            : VoiceMailService::markHeard(null, $workspaceId);
        return ['status' => 'ok', 'updated' => $updated];
    }

    /**
     * What the page needs to play one stored message again (R7).
     *
     * Prefers a clip still alive on tmpfs — cheap and identical to what was heard.
     * Otherwise returns the text for the browser to speak, which works in BOTH
     * voice modes and needs no engine. The text is the message; audio is only a
     * rendering of it, which is why nothing had to be kept on Flash to allow this.
     */
    private static function voicemailReplay(): array
    {
        $id = trim((string)($_REQUEST['id'] ?? ''));
        $m = $id !== '' ? VoiceMailService::get($id) : null;
        if ($m === null) {
            return ['status' => 'error', 'message' => 'That voice mail has expired or was never kept.'];
        }
        // R14 (Forgejo #376): the same fixed intro as the live message, from the
        // workspace's CURRENT spoken name, or the stored name when it is gone.
        $record = VoiceMailService::resolveWorkspace((string)($m['workspaceId'] ?? ''))['record'];
        $intro = VoiceMailService::introFor(VoiceMailService::spokenNameFor($record, (string)($m['name'] ?? '')));
        $clipId = (string)($m['clipId'] ?? '');
        if ($clipId !== '' && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $clipId)
            && is_file(VoiceService::dir() . '/' . $clipId . '.mp3')) {
            return [
                'status' => 'ok', 'mode' => 'audio', 'text' => (string)$m['text'], 'intro' => $intro,
                'url' => '/plugins/unraid-aicliagents/AICliAjax.php?action=voice_clip&id=' . $clipId,
            ];
        }
        // The clip is gone (clips are short-lived tmpfs files). With an engine
        // configured, make a new one so replay sounds like the engine that is set,
        // not the browser's own voice (operator report 2026-09-15).
        $fresh = VoiceService::replayClip((string)$m['text'], isset($m['voice']) ? (string)$m['voice'] : null, $intro);
        if (($fresh['mode'] ?? '') === 'audio' && !empty($fresh['clipId'])) {
            return [
                'status' => 'ok', 'mode' => 'audio', 'text' => (string)$m['text'], 'intro' => $intro,
                'url' => '/plugins/unraid-aicliagents/AICliAjax.php?action=voice_clip&id=' . $fresh['clipId'],
            ];
        }
        $out = ['status' => 'ok', 'mode' => 'speech', 'text' => (string)$m['text'], 'intro' => $intro, 'voice' => $m['voice'] ?? null];
        if (!empty($fresh['message'])) $out['engine_error'] = (string)$fresh['message'];
        return $out;
    }

    /** Delete one message for good (R12). The page asked the operator first. */
    private static function voicemailDelete(): array
    {
        $id = trim((string)($_REQUEST['id'] ?? ''));
        if ($id === '') return ['status' => 'error', 'message' => 'Give the id of the message to delete.'];
        return ['status' => 'ok', 'deleted' => VoiceMailService::delete($id)];
    }

    /** Delete every message for one workspace, or every message with no workspaceId (R12). */
    private static function voicemailDeleteAll(): array
    {
        $workspaceId = trim((string)($_REQUEST['workspaceId'] ?? ''));
        return ['status' => 'ok', 'deleted' => VoiceMailService::deleteAll($workspaceId !== '' ? $workspaceId : null)];
    }

    /** Set a workspace to speak, mail or off (R6). */
    private static function voicemailSetMode(): array
    {
        $workspaceId = trim((string)($_REQUEST['workspaceId'] ?? ''));
        $mode = trim((string)($_REQUEST['mode'] ?? ''));
        if ($workspaceId === '') {
            return ['status' => 'error', 'message' => 'workspaceId is required.'];
        }
        $r = VoiceMailService::setMode($workspaceId, $mode);
        return isset($r['error'])
            ? ['status' => 'error', 'message' => (string)$r['error']]
            : ['status' => 'ok', 'mode' => $r['mode']];
    }

    /**
     * Set or clear one workspace's spoken name (R14, Forgejo #376). An empty
     * `spokenName` clears it, so the display name is spoken again.
     */
    private static function voicemailSetSpokenName(): array
    {
        $workspaceId = trim((string)($_REQUEST['workspaceId'] ?? ''));
        if ($workspaceId === '') {
            return ['status' => 'error', 'message' => 'workspaceId is required.'];
        }
        $r = VoiceMailService::setSpokenName($workspaceId, (string)($_REQUEST['spokenName'] ?? ''));
        return isset($r['error'])
            ? ['status' => 'error', 'message' => (string)$r['error']]
            : ['status' => 'ok', 'spokenName' => $r['spokenName']];
    }

    /**
     * Set or clear one workspace's own engine voice (AGENT_VOICE.md R14, Forgejo
     * #377). An empty `voice` clears it, so the Settings default is used again.
     */
    private static function voiceSetWorkspaceVoice(): array
    {
        $workspaceId = trim((string)($_REQUEST['workspaceId'] ?? ''));
        if ($workspaceId === '') {
            return ['status' => 'error', 'message' => 'workspaceId is required.'];
        }
        $r = VoiceService::setWorkspaceVoice($workspaceId, (string)($_REQUEST['voice'] ?? ''));
        return isset($r['error'])
            ? ['status' => 'error', 'message' => (string)$r['error']]
            : ['status' => 'ok', 'voice' => $r['voice']];
    }

    // ---- guided natural-voice setup (AGENT_VOICE.md "#323 guided setup") ------
    // The two writes accept POST only; CSRF is checked centrally in AICliAjax.php.
    // check_voice_engine is a read (the Settings page polls it). None of the three
    // takes a URL or a path from the request: the service builds both itself.

    private static function isPost(): bool
    {
        return strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST';
    }

    /**
     * VOICE_ENGINE_SETUP.md: which engine a setup action is about. Only 'tts' (the
     * default) or 'stt' is accepted; anything else means 'tts'. It picks a fixed
     * profile inside the service and is never a path, a URL or a container name.
     */
    private static function engineParam(): string
    {
        $e = (string)($_REQUEST['engine'] ?? $_POST['engine'] ?? $_GET['engine'] ?? '');
        return $e === 'stt' ? 'stt' : 'tts';
    }

    /** Check Docker, then connect to an existing engine or write the template. */
    private static function voiceEnginePrepare(): array
    {
        if (!self::isPost()) return ['status' => 'error', 'message' => 'This action needs a POST request.'];
        return VoiceEngineSetupService::prepare(self::engineParam());
    }

    /** Start the Whisper model download inside the running Speaches container (the model name is fixed in the service). */
    private static function voiceEngineDownload(): array
    {
        if (!self::isPost()) return ['status' => 'error', 'message' => 'This action needs a POST request.'];
        if (self::engineParam() !== 'stt') return ['status' => 'error', 'message' => 'Only the dictation engine downloads a model.'];
        return VoiceEngineSetupService::startModelDownload();
    }

    /** Connect: save tts_url/tts_voice (or stt_url/stt_model) for the running engine and file it in Folder View 3. */
    private static function voiceEngineFinish(): array
    {
        if (!self::isPost()) return ['status' => 'error', 'message' => 'This action needs a POST request.'];
        $r = VoiceEngineSetupService::finish(self::engineParam());
        if (($r['status'] ?? '') === 'ok') $r['settings'] = VoiceService::settings();
        return $r;
    }

    public static function actions(): array
    {
        return ['get_voice_settings', 'save_voice_settings', 'test_voice', 'list_voices', 'voice_transcribe', 'voice_dictate',
                'voicemail_list', 'voicemail_mark_heard', 'voicemail_replay', 'voicemail_set_mode',
                'voicemail_delete', 'voicemail_delete_all', 'voicemail_set_spoken_name',
                'voice_set_workspace_voice',
                'voice_engine_prepare', 'check_voice_engine', 'voice_engine_finish', 'voice_engine_status', 'voice_engine_download'];
    }

    private static function getVoiceSettings(): array
    {
        return ['status' => 'ok', 'settings' => VoiceService::settings()];
    }

    /**
     * R13: GET — the engine's own voice catalogue for the Manager Settings
     * "Voice id" dropdown. Read-only: never touches the stored settings, and
     * the engine key stays server-side inside VoiceService::listVoices().
     */
    private static function listVoices(): array
    {
        return ['status' => 'ok'] + VoiceService::listVoices();
    }

    /**
     * POST tts_url, tts_voice, tts_speed, and (VOICE_INPUT.md R1) their
     * input-side counterparts stt_url, stt_model, stt_language — all six go
     * through AdminService::setSetting() — the SAME Tier 2 allow-list
     * validation an agent's aicli_set_setting call would hit, so a human and
     * an agent can never disagree about what a valid value looks like — an
     * OPTIONAL voice_enabled (VOICE_SWITCHES.md R1/R3 — the Manager Settings
     * "Agent voice" card's own toggle posts it here too, alongside the
     * terminal drawer icon's own generic settings save), and OPTIONAL
     * tts_api_key/stt_api_key: absent/empty leaves the stored key unchanged,
     * the literal `__clear__` removes it, anything else replaces it.
     */
    private static function saveVoiceSettings(): array
    {
        // A field that is ABSENT from the request is left unchanged. The
        // Settings card sends all six engine fields (three per direction);
        // the drawer's speaker icon (VOICE_SWITCHES.md R2) sends only
        // voice_enabled, and before this guard every click on it wiped
        // tts_url and reset the voice and speed to their defaults (found
        // live 2026-09-12).
        $engineFields = [
            'tts_url'      => static fn (string $v): string => $v,
            'tts_voice'    => static fn (string $v): string => $v !== '' ? $v : 'af_heart',
            'tts_speed'    => static fn (string $v): string => $v !== '' ? $v : '1.0',
            // VOICE_INPUT.md R1: the input-side counterparts, same contract.
            'stt_url'      => static fn (string $v): string => $v,
            'stt_model'    => static fn (string $v): string => $v !== '' ? $v : 'whisper-1',
            'stt_language' => static fn (string $v): string => $v,
            // VOICE_MAIL.md R8: retention. An emptied field goes back to the default.
            'voicemail_max_per_workspace' => static fn (string $v): string => $v !== '' ? $v : (string)VoiceMailService::DEFAULT_MAX_PER_WORKSPACE,
            'voicemail_max_age_days'      => static fn (string $v): string => $v !== '' ? $v : (string)VoiceMailService::DEFAULT_MAX_AGE_DAYS,
        ];
        foreach ($engineFields as $key => $normalise) {
            if (!array_key_exists($key, $_REQUEST)) {
                continue;
            }
            $result = AdminService::setSetting($key, $normalise(trim((string)$_REQUEST[$key])));
            if (isset($result['error'])) {
                return ['status' => 'error', 'message' => (string)$result['error']];
            }
        }

        if (array_key_exists('voice_enabled', $_REQUEST)) {
            $result = AdminService::setSetting('voice_enabled', trim((string)$_REQUEST['voice_enabled']));
            if (isset($result['error'])) {
                return ['status' => 'error', 'message' => (string)$result['error']];
            }
        }

        // VOICE_INPUT.md R1: stt_api_key follows the exact tts_api_key
        // contract (empty leaves it unchanged, __clear__ removes it), so one
        // helper serves both keys instead of two copies of the same logic.
        foreach ([
            ['tts_api_key', VoiceService::TOKEN_KEY, 'engine'],
            ['stt_api_key', VoiceService::STT_TOKEN_KEY, 'transcription'],
        ] as [$field, $tokenKey, $label]) {
            $error = self::applyApiKeyField($field, $tokenKey, $label);
            if ($error !== null) {
                return $error;
            }
        }

        return ['status' => 'ok', 'settings' => VoiceService::settings()];
    }

    /**
     * One vault key's round trip from a save_voice_settings POST field:
     * absent from the request is a no-op, empty string leaves the stored
     * key unchanged, `__clear__` removes it, anything else (after the same
     * control-character/length check the key has always had) replaces it.
     * Returns null on success (including "nothing to do"), or an
     * `['status'=>'error', 'message'=>...]` array to return verbatim.
     *
     * @return array{status:string,message:string}|null
     */
    private static function applyApiKeyField(string $requestField, string $tokenKey, string $engineLabel): ?array
    {
        if (!array_key_exists($requestField, $_REQUEST)) {
            return null;
        }
        $key = (string)$_REQUEST[$requestField];
        if ($key === '') {
            // Empty leaves the stored key unchanged — the UI's own "unset"
            // affordance is the explicit __clear__ literal below.
            return null;
        }
        if ($key === '__clear__') {
            $vault = SecretService::getAgentSecrets();
            unset($vault[$tokenKey]);
            if (!SecretService::saveAgentSecrets($vault)) {
                return ['status' => 'error', 'message' => "Settings saved, but clearing the $engineLabel key failed."];
            }
            return null;
        }
        if (preg_match('/[\x00-\x1f\x7f]/', $key) || strlen($key) > 512) {
            return ['status' => 'error', 'message' => 'invalid API key value'];
        }
        $vault = SecretService::getAgentSecrets();
        $vault[$tokenKey] = $key;
        if (!SecretService::saveAgentSecrets($vault)) {
            return ['status' => 'error', 'message' => "Settings saved, but storing the $engineLabel key failed."];
        }
        $free = SecretService::getFreeformKeys();
        if (!in_array($tokenKey, $free, true)) {
            $free[] = $tokenKey;
            SecretService::setFreeformKeys($free);
        }
        return null;
    }

    /**
     * R6: a human clicking "Test" on the Manager Settings page speaks a fixed
     * sentence through whichever path is configured right now. Calls
     * VoiceService::speak() directly — this is a human clicking a button, not
     * an agent tool call, so the admin_tools_enabled gate (AdminMcpTools::enabled())
     * does not apply, the same way every other Manager UI action bypasses it.
     *
     * VOICE_SWITCHES.md "Settings Test": passes $bypassSwitches=true — an
     * operator must be able to hear the configured voice BEFORE deciding
     * whether to turn the global switch on at all, and this click has no
     * workspace of its own for a per-workspace mute to apply to anyway.
     *
     * Optional fields (the text is always one of PURPOSE_SENTENCES):
     * - `purpose`: '' (the Settings Test), `switch` (the voice-on confirmation,
     *   VOICE_SWITCHES.md), `voice` (the Voice… dialog sample, AGENT_VOICE.md R14)
     *   or `announce` (the Spoken name dialog Test, VOICE_MAIL.md). Any other
     *   value is refused.
     * - `voice`: an engine voice id to use (VOICE_ID_PATTERN). Invalid is refused.
     * - `workspaceId` + `spokenName` (`announce` only): the workspace to announce
     *   and the typed, unsaved name ('' = the display name). With no `voice`, the
     *   workspace's own voice is used.
     * `voice` and `announce` always answer directly (as if `direct=1`): they play
     * only in the browser that asked, and nothing is published or kept.
     */
    private static function testVoice(): array
    {
        $purpose = trim((string)($_REQUEST['purpose'] ?? ''));
        if (!array_key_exists($purpose, self::PURPOSE_SENTENCES)) {
            return ['status' => 'error', 'message' => 'Unknown test purpose.'];
        }
        $sentence = self::PURPOSE_SENTENCES[$purpose];
        $voice = trim((string)($_REQUEST['voice'] ?? ''));
        if ($voice !== '' && !VoiceService::isValidVoiceId($voice)) {
            return ['status' => 'error', 'message' => "'$voice' is not a valid voice id."];
        }
        if ($purpose === 'announce') {
            $workspaceId = trim((string)($_REQUEST['workspaceId'] ?? ''));
            $record = $workspaceId !== '' ? VoiceMailService::resolveWorkspace($workspaceId)['record'] : null;
            if ($record === null) {
                return ['status' => 'error', 'message' => 'That workspace no longer exists.'];
            }
            // The typed name, cleaned like a save; empty = the display name.
            $intro = VoiceMailService::introFor(VoiceMailService::spokenNameFor(
                ['spoken_name' => (string)($_REQUEST['spokenName'] ?? '')] + $record));
            $sentence = $intro !== '' ? $intro . ' ' . $sentence : $sentence;
            if ($voice === '') $voice = VoiceService::workspaceVoiceFor($record);
        }
        $caller = AdminService::callerIdentity();
        $actorContext = [
            'workspaceId' => $caller['workspaceId'] !== '' ? $caller['workspaceId'] : 'manager',
            'agentId'     => $caller['agentId'] !== '' ? $caller['agentId'] : 'manager',
            'name'        => $caller['name'] !== '' ? $caller['name'] : 'Manager',
        ];
        // The switch confirmation uses `direct=1`: the browser plays the
        // returned clip itself, so this one test must not also fan the same
        // confirmation out through the live voice channel to every open tab.
        $direct = ((string)($_REQUEST['direct'] ?? '0')) === '1' || $purpose === 'voice' || $purpose === 'announce';
        $result = VoiceService::speak($sentence, $actorContext, $voice !== '' ? $voice : null, true, !$direct);
        if ($direct) {
            $result['text'] = $sentence;
            if (($result['mode'] ?? '') === 'audio' && isset($result['clipId'])) {
                $result['url'] = '/plugins/unraid-aicliagents/AICliAjax.php?action=voice_clip&id=' . (string)$result['clipId'];
            }
        }
        return isset($result['error'])
            ? ['status' => 'error', 'message' => (string)$result['error']]
            : ['status' => 'ok'] + $result;
    }

    /**
     * RAW OUTPUT: streams the mp3 bytes for one engine clip. Never wrapped in
     * JSON and never stamped with `ts` — AICliAjax.php's raw-output branch
     * calls this directly, bypassing the standard handler-chain envelope.
     */
    public static function rawVoiceClip(): void
    {
        $clipId = (string)($_GET['id'] ?? '');
        if (!preg_match('/^v_[0-9a-f]{16}$/', $clipId)) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'invalid clip id']);
            return;
        }

        $path = VoiceService::dir() . "/$clipId.mp3";
        if (!is_file($path)) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'clip not found — it may have already expired']);
            return;
        }

        header('Content-Type: audio/mpeg');
        header('Cache-Control: no-store');
        header('Content-Length: ' . (string)(@filesize($path) ?: 0));
        readfile($path); // nosemgrep: php.lang.security.injection.echoed-request.echoed-request -- $clipId is shape-validated above
    }

    /**
     * VOICE_INPUT.md R2: POST filedata (base64), mime, seconds, workspaceId
     * — forwards a browser-recorded clip to the configured transcription
     * engine and returns the recognised text. Every validation (empty
     * stt_url, size/duration caps, mime allow-list, unknown/not-running
     * workspace) lives in VoiceService::transcribe(); this handler only
     * reads the POST fields and wraps the result.
     *
     * `workspaceId === 'manager'` bypasses the workspace check: the Manager
     * Settings page's "Test (records 3s)" button has no real workspace
     * behind it (ManagerConfigTab.php's `aicliVoiceInputTest()` sends this
     * exact fixed sentinel, mirroring `testVoice()`'s own 'manager' fallback
     * identity for the same "a human clicked a button" case) — see
     * VoiceService::transcribe()'s own doc comment for the Relay thread that
     * flagged this gap.
     */
    private static function voiceTranscribe(): array
    {
        $filedata = (string)($_POST['filedata'] ?? '');
        if ($filedata === '') {
            return ['status' => 'error', 'message' => 'There is no audio to transcribe.'];
        }
        $mime = (string)($_POST['mime'] ?? '');
        $seconds = (float)($_POST['seconds'] ?? 0);
        $workspaceId = (string)($_POST['workspaceId'] ?? '');

        $result = VoiceService::transcribe($filedata, $mime, $seconds, $workspaceId, $workspaceId === 'manager');
        return isset($result['error'])
            ? ['status' => 'error', 'message' => (string)$result['error']]
            : ['status' => 'ok'] + $result;
    }

    /**
     * VOICE_INPUT.md R3/R12: POST workspaceId, send ('0'|'1'), and EITHER
     * text OR key (never both) — pastes dictated text into the workspace's
     * pane and, when requested, presses Enter once the pane is idle; OR
     * (R12) sends one allow-listed control key ("press tab") with an
     * optional repeat count. The actor for the resulting `workspace.input`
     * ledger row is the calling operator, resolved the same automatic way
     * every other browser AJAX action's ledger row is (EventLedger::append()'s
     * own EventActor::current() default) — no special-casing needed here,
     * unlike testVoice()'s $actorContext, which only attributes a SPOKEN
     * clip, not a ledger actor.
     *
     * `key`/`count` validation lives here, not in VoiceService::dictate():
     * exactly one of `text`/`key` must be present and non-empty, and `count`
     * (default 1) must be a whole number from 1 to 500. The allow-list
     * itself is enforced server-side one layer down, in
     * TmuxService::sendKey().
     *
     * 2026-09-29: `submit_only=1` presses Enter on the text that is already
     * typed, and types nothing. It is accepted only with `send=1` and with
     * no `text` and no `key`. The Enter waits for the same idle gate as a
     * send (VoiceService::dictateSubmit()).
     */
    private static function voiceDictate(): array
    {
        $workspaceId = (string)($_POST['workspaceId'] ?? '');
        $send = ((string)($_POST['send'] ?? '0')) === '1';

        $hasText = array_key_exists('text', $_POST) && (string)$_POST['text'] !== '';
        $hasKey = array_key_exists('key', $_POST) && (string)$_POST['key'] !== '';
        if (((string)($_POST['submit_only'] ?? '0')) === '1') {
            if (!$send) {
                return ['status' => 'error', 'message' => 'submit_only needs send=1.'];
            }
            if ($hasText || $hasKey) {
                return ['status' => 'error', 'message' => 'submit_only takes no text and no key.'];
            }
            $result = VoiceService::dictateSubmit($workspaceId);
            return isset($result['error'])
                ? ['status' => 'error', 'message' => (string)$result['error']]
                : ['status' => 'ok'] + $result;
        }
        if ($hasText === $hasKey) {
            return ['status' => 'error', 'message' => 'Provide exactly one of text or key.'];
        }

        if ($hasKey) {
            $key = (string)$_POST['key'];
            $countRaw = (string)($_POST['count'] ?? '1');
            if (!preg_match('/^\d+$/', $countRaw)) {
                return ['status' => 'error', 'message' => 'count must be a whole number.'];
            }
            $count = (int)$countRaw;
            if ($count < 1 || $count > 500) {
                return ['status' => 'error', 'message' => 'count must be between 1 and 500.'];
            }
            $result = VoiceService::dictate($workspaceId, '', $send, $key, $count);
        } else {
            $text = (string)$_POST['text'];
            $result = VoiceService::dictate($workspaceId, $text, $send);
        }

        return isset($result['error'])
            ? ['status' => 'error', 'message' => (string)$result['error']]
            : ['status' => 'ok'] + $result;
    }
}
