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
use AICliAgents\Services\VoiceService;

class VoiceHandler
{
    /** The fixed sentence test_voice speaks (R6). */
    private const TEST_SENTENCE = 'Voice is working on this server.';

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
        $clipId = (string)($m['clipId'] ?? '');
        if ($clipId !== '' && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $clipId)
            && is_file(VoiceService::dir() . '/' . $clipId . '.mp3')) {
            return [
                'status' => 'ok', 'mode' => 'audio', 'text' => (string)$m['text'],
                'url' => '/plugins/unraid-aicliagents/AICliAjax.php?action=voice_clip&id=' . $clipId,
            ];
        }
        // The clip is gone (clips are short-lived tmpfs files). With an engine
        // configured, make a new one so replay sounds like the engine that is set,
        // not the browser's own voice (operator report 2026-09-15).
        $fresh = VoiceService::replayClip((string)$m['text'], isset($m['voice']) ? (string)$m['voice'] : null);
        if (($fresh['mode'] ?? '') === 'audio' && !empty($fresh['clipId'])) {
            return [
                'status' => 'ok', 'mode' => 'audio', 'text' => (string)$m['text'],
                'url' => '/plugins/unraid-aicliagents/AICliAjax.php?action=voice_clip&id=' . $fresh['clipId'],
            ];
        }
        $out = ['status' => 'ok', 'mode' => 'speech', 'text' => (string)$m['text'], 'voice' => $m['voice'] ?? null];
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

    public static function actions(): array
    {
        return ['get_voice_settings', 'save_voice_settings', 'test_voice', 'list_voices', 'voice_transcribe', 'voice_dictate',
                'voicemail_list', 'voicemail_mark_heard', 'voicemail_replay', 'voicemail_set_mode',
                'voicemail_delete', 'voicemail_delete_all'];
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
     */
    private static function testVoice(): array
    {
        $caller = AdminService::callerIdentity();
        $actorContext = [
            'workspaceId' => $caller['workspaceId'] !== '' ? $caller['workspaceId'] : 'manager',
            'agentId'     => $caller['agentId'] !== '' ? $caller['agentId'] : 'manager',
            'name'        => $caller['name'] !== '' ? $caller['name'] : 'Manager',
        ];
        $result = VoiceService::speak(self::TEST_SENTENCE, $actorContext, null, true);
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
     */
    private static function voiceDictate(): array
    {
        $workspaceId = (string)($_POST['workspaceId'] ?? '');
        $send = ((string)($_POST['send'] ?? '0')) === '1';

        $hasText = array_key_exists('text', $_POST) && (string)$_POST['text'] !== '';
        $hasKey = array_key_exists('key', $_POST) && (string)$_POST['key'] !== '';
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
