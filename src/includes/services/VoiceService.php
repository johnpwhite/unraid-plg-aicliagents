<?php
/**
 * <module_context>
 *     <name>VoiceService</name>
 *     <description>Turns one short line of text into a spoken clip for the operator's
 *     open browser tabs (docs/specs/AGENT_VOICE.md). Browser mode (`tts_url` empty)
 *     publishes {mode:"speech", text, voice, workspaceId, agentId, name, chars} on the
 *     `aicli_voice` nchan channel, so each tab with voice turned on reads the text
 *     aloud with the Web Speech API. Engine mode (`tts_url` set) calls
 *     `POST {tts_url}/v1/audio/speech` (the OpenAI audio API shape Kokoro-FastAPI and
 *     its siblings already speak), writes the clip to tmpfs, and publishes
 *     {mode:"audio", clipId, url, ...} — `url` is the plugin's own `voice_clip` AJAX
 *     action, so the engine URL and any API key never reach a browser. If the
 *     configured engine fails or times out, the same call publishes a browser-speech
 *     fallback instead of dropping the notice; the failure remains recorded for
 *     HealthService. The audio payload includes its capped text so a browser can
 *     recover if the clip request itself fails later.
 *     docs/specs/VOICE_SWITCHES.md adds two on/off switches `speak()` checks before
 *     any of the above: the global `voice_enabled` config key, and a per-workspace
 *     `voice` field on the caller's own workspace record. Either switch off refuses
 *     with `mode:'refused'` and a `reason` before the rate-limit/lock steps, unless
 *     the caller passes `bypassSwitches` (the Manager Settings "Test" button only).
 *     `publishState()` announces the global switch changing on the SAME channel, as
 *     a `{mode:'state'}` message a browser never speaks and the ledger never
 *     tees. `listVoices()` (R13) is unrelated to speaking: it reads
 *     `GET {tts_url}/v1/audio/voices` (a Kokoro-FastAPI extension, not the
 *     OpenAI audio API) so the Manager Settings "Voice id" field can offer a
 *     dropdown instead of a hand-typed id, caching a successful answer 60s
 *     per URL. docs/specs/VOICE_INPUT.md adds the INPUT side, unrelated to
 *     speaking: `transcribe()` posts a browser-recorded clip, multipart, to
 *     `POST {stt_url}/v1/audio/transcriptions` (also an OpenAI-shaped call,
 *     the same engine family as speech) and returns the recognised text —
 *     the clip is forwarded and never written to disk, only its byte count
 *     and duration are ever logged, never the text. `dictate()` pastes
 *     dictated text into a workspace's pane (TmuxService::pasteText(),
 *     UNCONDITIONALLY — the operator must see what was heard even while the
 *     agent is busy) and presses Enter only when the pane is idle at its own
 *     prompt. docs/specs/VOICE_INPUT.md R12: `dictate()` also accepts a
 *     `key` (one of a small server-side allow-list) instead of `text` — a
 *     spoken command like "press tab" — and sends it through
 *     `TmuxService::sendKey()` unconditionally, with no readiness-gate check
 *     at all, even for Enter.</description>
 *     <dependencies>ConfigService (`tts_url`/`tts_voice`/`tts_speed`/`voice_enabled`/
 *     `stt_url`/`stt_model`/`stt_language`, and the workspace registry for the
 *     per-workspace `voice` switch), SecretService (the `TTS_API_KEY`/`STT_API_KEY`
 *     vault entries — never the plugin cfg), NchanService (publish, which also tees
 *     one `agent.spoke` ledger event via EventLedger::kindForChannel — except a
 *     `state` message, which that mapping deliberately excludes), EventLedger
 *     (`dictate()`'s own `workspace.input` row — a direct append, not a tee),
 *     RedactionService (the spoken-text/dictated-text excerpt), AtomicWriteService
 *     (the clip and the status files), TmuxService (`dictate()`'s paste + Enter,
 *     and R12's `sendKey()` for a spoken key command),
 *     ProcessManager (`transcribe()`/`dictate()`'s "workspace is running"
 *     check).</dependencies>
 *     <constraints>Runtime files live on tmpfs under /tmp/unraid-aicliagents/voice/ —
 *     never /mnt/user. The single-flight lock and the per-workspace gap marker both
 *     take LOCK_EX|LOCK_NB with a short bounded retry, never a blocking flock over
 *     FUSE. The engine transport is a swappable test seam (self::$transport) so no
 *     test makes a real HTTP call. The engine API keys never appear in the plugin
 *     cfg, a log line, or a tool/ledger result — they live only in secrets.cfg under
 *     TTS_API_KEY/STT_API_KEY (SecretService's agent vault, the same file
 *     HUB_GIT_TOKEN uses). A dictated/transcribed clip is never written to disk —
 *     unlike a spoken clip (which the operator must be able to replay), a voice
 *     INPUT clip has no reason to persist even briefly.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class VoiceService {

    /** Runtime dir (tmpfs). self::$dir overrides for tests. */
    public const RUNTIME_DIR = '/tmp/unraid-aicliagents/voice';

    /**
     * Secrets-vault key for the engine API key (SecretService::getAgentSecrets()/
     * saveAgentSecrets() — the same flat, 600-perms secrets.cfg HUB_GIT_TOKEN uses).
     * UPPER_SNAKE_CASE is required: SecretService's own read/write shape gate
     * (`/^[A-Z][A-Z0-9_]{1,127}$/`) silently drops any other case.
     */
    public const TOKEN_KEY = 'TTS_API_KEY';

    /** R2: text is trimmed and capped at this many characters before it is spoken. */
    public const MAX_TEXT_CHARS = 500;

    /** R2: a workspace may speak at most once every this many seconds. */
    public const MIN_GAP_SECONDS = 3;

    /** R2: refuse a new clip once this many exist younger than QUEUE_WINDOW_SECONDS. */
    public const MAX_QUEUE = 3;

    /** R2: the queue-cap lookback window, in seconds. */
    public const QUEUE_WINDOW_SECONDS = 60;

    /** R5: an engine clip older than this is unlinked on the next speak(). */
    public const CLIP_MAX_AGE_SECONDS = 600;

    /** R13: a successful `list_voices` answer is cached this many seconds, per URL. */
    public const VOICES_CACHE_SECONDS = 60;

    /** R13: the voice-list HTTP call's hard timeout, in seconds (shorter than speech, R5's 20s — a Settings page dropdown must not hang on a slow engine). */
    private const LIST_VOICES_TIMEOUT_S = 5;

    /** S5 (REVIEW_2026-09-13_EVENTS_AND_SECURITY.md#S5): the speech engine's reply is capped at this many bytes. */
    private const MAX_SPEECH_RESPONSE_BYTES = 8 * 1024 * 1024; // 8 MiB

    /** S5: the voice-list engine's reply is capped at this many bytes — it is only ever a short JSON catalogue. */
    private const MAX_VOICES_RESPONSE_BYTES = 256 * 1024; // 256 KiB

    /**
     * Secrets-vault key for the transcription engine API key — mirrors
     * TOKEN_KEY exactly (same vault, same UPPER_SNAKE_CASE requirement).
     */
    public const STT_TOKEN_KEY = 'STT_API_KEY';

    /** VOICE_INPUT.md R2: refuse a clip longer than this many seconds. */
    public const MAX_STT_SECONDS = 60;

    /** VOICE_INPUT.md R2: refuse a clip whose decoded bytes exceed this size. */
    public const MAX_STT_AUDIO_BYTES = 4 * 1024 * 1024; // 4 MiB

    /** VOICE_INPUT.md R2: the only mime types (base type, ignoring a `;codecs=...` suffix) this plugin forwards. */
    private const ALLOWED_STT_MIME_TYPES = ['audio/webm', 'audio/ogg', 'audio/mp4', 'audio/wav'];

    /** VOICE_INPUT.md R2: the transcription engine's reply is a short JSON object — same cap as the voice list. */
    private const MAX_TRANSCRIBE_RESPONSE_BYTES = 256 * 1024; // 256 KiB

    /** VOICE_INPUT.md R2: the transcription engine HTTP call's hard timeout, in seconds. */
    private const STT_TIMEOUT_S = 30;

    /** VOICE_INPUT.md R3: hard cap on one voice_dictate call's text length (same cap WORKSPACE_SEND_INPUT.md uses). */
    public const MAX_DICTATE_CHARS = 4000;

    /** Excerpt length for the ledger-facing `excerpt` field (R9). */
    private const EXCERPT_CHARS = 80;

    /** Single-flight lock retry budget — tmpfs LOCK_NB, never a blocking flock. */
    private const LOCK_RETRIES = 10;

    /** Delay between lock retries, in microseconds (10 * 20ms = 200ms ceiling). */
    private const LOCK_RETRY_DELAY_US = 20000;

    /** R5/R6: the engine HTTP call's hard timeout, in seconds. */
    private const CURL_TIMEOUT_S = 20;

    /**
     * AGENT_VOICE.md R3/R14: the shape of an engine voice id. The same rule as the
     * Settings `tts_voice` field (AdminService::SETTINGS_ALLOWLIST), used for the
     * per-workspace voice and for a replay's stored voice.
     */
    public const VOICE_ID_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    /** Test seam: overrides RUNTIME_DIR. Null uses the real path. */
    public static ?string $dir = null;

    /** Test seam: callable(string $url, array $headers, string $jsonBody): array{errno,error,status,body}. Real curl when null. */
    public static $transport = null;

    /** Test seam: callable(): int returning "now" (epoch seconds). Null uses time(). */
    public static $now = null;

    /**
     * Test seam for the dictation Enter (VOICE_INPUT.md, 2026-09-29): an array
     * with optional callables `gate(agentId, workspaceId): array{ready,reason}`
     * and `enter(agentId, workspaceId): array{status,...}`. Null (or a missing
     * key) uses the real TmuxService call. There is no live tmux in the test
     * container, so this is how a test proves "Enter when ready, hold when busy".
     *
     * @var array<string,callable>|null
     */
    public static $tmux = null;

    /** Resolved runtime dir (RUNTIME_DIR unless a test set $dir). */
    public static function dir(): string {
        return self::$dir ?? self::RUNTIME_DIR;
    }

    /** Where HealthService's `voice` check (R11) reads the last synthesis result. */
    public static function statusPath(): string {
        return self::dir() . '/status.json';
    }

    /** VOICE_INPUT.md R7: where HealthService's `voice_input` check reads the last transcription result. */
    public static function sttStatusPath(): string {
        return self::dir() . '/status-stt.json';
    }

    private static function now(): int {
        return self::$now !== null ? (int)(self::$now)() : time();
    }

    // ------------------------------------------------------------------
    // Settings (R3)
    // ------------------------------------------------------------------

    /**
     * The voice settings a caller may read: the plugin-cfg keys for both
     * directions (speak OUT, dictate IN), whether an engine key is stored for
     * each (never the key itself), and the mode each side would use right
     * now. Shared by `get_voice_settings` and `save_voice_settings`' own
     * return value, so both surfaces always agree.
     *
     * VOICE_INPUT.md R1: `stt_url`/`stt_model`/`stt_language`/`stt_api_key_set`/
     * `stt_mode` are the input-side counterparts of the existing `tts_*`/`mode`
     * keys — same shape, same "key is set, never the key itself" rule.
     *
     * @return array{voice_enabled:bool,tts_url:string,tts_voice:string,tts_speed:string,tts_api_key_set:bool,mode:string,stt_url:string,stt_model:string,stt_language:string,stt_api_key_set:bool,stt_mode:string,voicemail_max_per_workspace:string,voicemail_max_age_days:string}
     */
    public static function settings(): array {
        $config = ConfigService::getConfig();
        $ttsUrl = trim((string)($config['tts_url'] ?? ''));
        $sttUrl = trim((string)($config['stt_url'] ?? ''));
        return [
            // VOICE_SWITCHES.md R8: a fresh tab/Settings load learns the
            // current global-switch state from here, without waiting for a
            // `state` message on the channel.
            'voice_enabled'   => (string)($config['voice_enabled'] ?? '0') === '1',
            'tts_url'         => $ttsUrl,
            'tts_voice'       => (string)($config['tts_voice'] ?? 'af_heart'),
            'tts_speed'       => (string)($config['tts_speed'] ?? '1.0'),
            'tts_api_key_set' => self::apiKey() !== '',
            'mode'            => $ttsUrl !== '' ? 'audio' : 'speech',
            'stt_url'         => $sttUrl,
            'stt_model'       => (string)($config['stt_model'] ?? '') ?: 'whisper-1',
            'stt_language'    => (string)($config['stt_language'] ?? ''),
            'stt_api_key_set' => self::sttApiKey() !== '',
            'stt_mode'        => $sttUrl !== '' ? 'engine' : 'browser',
            // VOICE_MAIL.md R8: retention, shown and edited on the same card.
            'voicemail_max_per_workspace' => (string)($config['voicemail_max_per_workspace'] ?? '') ?: (string)VoiceMailService::DEFAULT_MAX_PER_WORKSPACE,
            'voicemail_max_age_days'      => (string)($config['voicemail_max_age_days'] ?? '') ?: (string)VoiceMailService::DEFAULT_MAX_AGE_DAYS,
        ];
    }

    /** `"speech"` (browser mode) or `"audio"` (engine mode), from the current settings. */
    public static function mode(): string {
        return self::settings()['mode'];
    }

    /**
     * VOICE_SWITCHES.md R8: tell every open tab the global switch moved.
     * `{mode:'state', enabled}` on the SAME `aicli_voice` channel a spoken
     * clip uses — `voice.js` turns this into the `aicli-voice-state` window
     * event and never plays it as a clip. EventLedger::kindForChannel()
     * excludes a `state` message from the `agent.spoke` tee (nobody spoke,
     * the switch moved), so this never appears in the Activity tray or the
     * events ledger.
     */
    public static function publishState(bool $enabled): void {
        EventBus::publish('voice', [], ['mode' => 'state', 'enabled' => $enabled]);
    }

    /**
     * The workspace record `speak()` checks its own `voice` switch against —
     * a direct ConfigService lookup (not AdminService::findSession(), which
     * is private, and this class must not depend on AdminService). Returns
     * null when $id is empty or names no known workspace.
     *
     * @return array<string,mixed>|null
     */
    private static function findWorkspace(string $id): ?array {
        if ($id === '') return null;
        foreach ((ConfigService::getWorkspaces()['sessions'] ?? []) as $w) {
            if (is_array($w) && (string)($w['id'] ?? '') === $id) return $w;
        }
        return null;
    }

    // ------------------------------------------------------------------
    // Per-workspace voice — AGENT_VOICE.md R14 (Forgejo #377)
    // ------------------------------------------------------------------

    /** True when $voice has the shape of an engine voice id (VOICE_ID_PATTERN). */
    public static function isValidVoiceId(string $voice): bool {
        return (bool)preg_match(self::VOICE_ID_PATTERN, $voice);
    }

    /**
     * The workspace's own voice id, or '' when it has none. A stored value with a
     * wrong shape (a hand-edited file) is ignored, so the default applies and
     * speaking never breaks.
     */
    public static function workspaceVoiceFor(?array $workspace): string {
        if ($workspace === null) return '';
        $voice = trim((string)($workspace['tts_voice'] ?? ''));
        return ($voice !== '' && self::isValidVoiceId($voice)) ? $voice : '';
    }

    /**
     * Set (or clear, with '') one workspace's voice id. Saves the FULL record, like
     * VoiceMailService::setSpokenName(). Returns ['voice' => ...] or ['error' => ...].
     */
    public static function setWorkspaceVoice(string $workspaceId, string $voice): array {
        try {
            $voice = trim($voice);
            if ($voice !== '' && !self::isValidVoiceId($voice)) {
                return ['error' => "'$voice' is not a valid voice id. Use lowercase letters, digits and underscores, starting with a letter (for example af_heart)."];
            }
            $record = self::findWorkspace($workspaceId);
            if ($record === null) {
                return ['error' => 'That workspace no longer exists.'];
            }
            $record['tts_voice'] = $voice;
            if (!ConfigService::saveWorkspaces(['sessions' => [$record]], [])) {
                return ['error' => ConfigService::lastWorkspaceSaveMessage() ?? 'Could not save the voice.'];
            }
            return ['voice' => $voice];
        } catch (\Throwable $e) {
            return ['error' => 'Could not save the voice.'];
        }
    }

    /** The speech (TTS) engine API key, or '' when none is stored. Never returned by any tool/AJAX result. */
    private static function apiKey(): string {
        return (string)(SecretService::getAgentSecrets()[self::TOKEN_KEY] ?? '');
    }

    /** The transcription (STT) engine API key, or '' when none is stored. Never returned by any tool/AJAX result. */
    private static function sttApiKey(): string {
        return (string)(SecretService::getAgentSecrets()[self::STT_TOKEN_KEY] ?? '');
    }

    // ------------------------------------------------------------------
    // speak() — R2, R4, R5, R6, R9
    // ------------------------------------------------------------------

    /**
     * Speak one line of text. $actorContext carries the identity to attribute the
     * clip to — {workspaceId, agentId, name}, the same shape AdminService::callerIdentity()
     * returns — every field optional/empty-safe.
     *
     * Returns the bare payload on success (no 'status' key — AdminService's Tier 2
     * convention, matched here so AdminMcpTools::wrapChange() can wrap it like every
     * other Tier 2 tool) or ['error' => '...'] on refusal. Never throws.
     *
     * VOICE_SWITCHES.md R6: before anything else (even the rate-limit and lock
     * steps below), refuse when either switch is off — the global `voice_enabled`
     * setting, or the calling workspace's own `voice` field. A refusal from this
     * check carries `mode: 'refused'` and a `reason` ('global_off' or
     * 'workspace_off') so a caller can name which switch is off, and publishes
     * nothing and appends no ledger event (unlike every other refusal below,
     * which also publish nothing, but for a different reason — those simply
     * never reach the publish step). $bypassSwitches skips this check entirely
     * for VoiceHandler::testVoice() — an operator's own "Test" click must be
     * able to hear the configured voice before deciding whether to turn voice
     * on at all.
     *
     * @param array{workspaceId?:string,agentId?:string,name?:string} $actorContext
     * @return array{mode?:string,chars?:int,clipId?:string,error?:string,reason?:string,fallback?:bool,message?:string}
     */
    public static function speak(string $text, array $actorContext, ?string $voice = null, bool $bypassSwitches = false, bool $publish = true): array {
        try {
            if (!$bypassSwitches) {
                $switchConfig = ConfigService::getConfig();
                if ((string)($switchConfig['voice_enabled'] ?? '0') !== '1') {
                    return [
                        'error'  => 'Voice is off. Turn it on with the speaker icon in the drawer.',
                        'mode'   => 'refused',
                        'reason' => 'global_off',
                    ];
                }
                $callerWorkspaceId = (string)($actorContext['workspaceId'] ?? '');
                if ($callerWorkspaceId !== '') {
                    // VOICE_MAIL.md R13 (Forgejo #375): resolve the id first. An agent
                    // once passed its workspace's DISPLAY NAME as the id; it matched no
                    // record, the default `speak` applied, and a workspace set to Voice
                    // mail played aloud, with its messages kept under the name. A name
                    // that exactly one workspace has now resolves to that workspace's
                    // real id, and a name that several share takes the quietest of
                    // their modes, so a stored mode is never overridden by a default.
                    $resolved = VoiceMailService::resolveWorkspace($callerWorkspaceId);
                    $callerRecord = $resolved['record'];
                    if ($callerRecord !== null) {
                        $realId = (string)($callerRecord['id'] ?? $callerWorkspaceId);
                        if ($realId !== '' && $realId !== $callerWorkspaceId) {
                            $actorContext['workspaceId'] = $realId;
                            if ((string)($actorContext['name'] ?? '') === '') {
                                $actorContext['name'] = (string)($callerRecord['name'] ?? '');
                            }
                        }
                        $callerMode = VoiceMailService::modeFor($callerRecord);
                    } elseif ($resolved['matches'] !== []) {
                        $callerMode = VoiceMailService::quietestModeOf($resolved['matches']);
                    } else {
                        $callerMode = VoiceMailService::MODE_SPEAK;
                    }
                    // docs/specs/VOICE_MAIL.md: the old boolean is now a three-way
                    // mode. VoiceMailService::modeFor() reads `voice: false` as `off`,
                    // so a workspace muted before voice mail existed stays refused
                    // here exactly as it always was.
                    if ($callerMode === VoiceMailService::MODE_OFF) {
                        return [
                            'error'  => "Voice is off for this workspace. Turn it on from the workspace's ... menu.",
                            'mode'   => 'refused',
                            'reason' => 'workspace_off',
                        ];
                    }
                    $voiceMode = $callerMode;
                    $workspaceRecord = $callerRecord;
                }
            }
            $voiceMode = $voiceMode ?? VoiceMailService::MODE_SPEAK;
            $workspaceRecord = $workspaceRecord ?? null;

            $text = trim($text);
            if ($text === '') {
                return ['error' => 'There is nothing to speak — text is empty.'];
            }
            $text = self::capText($text);
            $chars = self::charCount($text);

            $workspaceId = (string)($actorContext['workspaceId'] ?? '');
            $agentId     = (string)($actorContext['agentId'] ?? '');
            $name        = (string)($actorContext['name'] ?? '');
            // VOICE_MAIL.md R14 (Forgejo #376): a fixed intro names the workspace,
            // "<spoken name> says:". The operator's own Test click has none.
            $intro = $bypassSwitches ? ''
                : VoiceMailService::introFor(VoiceMailService::spokenNameFor($workspaceRecord, $name));

            $dir = self::dir();
            if (!is_dir($dir)) @mkdir($dir, 0777, true);
            self::sweepOldClips($dir);

            // R2: per-workspace 3s gap.
            $gapKey = $workspaceId !== '' ? $workspaceId : 'global';
            $lastFile = $dir . '/.last-' . preg_replace('/[^A-Za-z0-9_-]/', '_', $gapKey);
            $now = self::now();
            $last = @filemtime($lastFile);
            if ($last !== false && ($now - $last) < self::MIN_GAP_SECONDS) {
                return ['error' => "Speaking too often — wait a few seconds before this workspace speaks again."];
            }

            // R2: queue cap — refuse a new clip once 3 were made in the last 60 s.
            // The cap is for agents. The operator's own Test click ($bypassSwitches)
            // is not capped: an operator who plays a few test clips in a row must
            // not be told to wait (found live 2026-09-12).
            if (!$bypassSwitches) {
                $recent = 0;
                foreach ((glob($dir . '/*.mp3') ?: []) as $clip) {
                    $age = $now - (int)(@filemtime($clip) ?: 0);
                    if ($age >= 0 && $age < self::QUEUE_WINDOW_SECONDS) $recent++;
                }
                if ($recent >= self::MAX_QUEUE) {
                    return ['error' => 'Too many clips in the last minute — wait a moment and try again.'];
                }
            }

            // R2: single-flight lock (tmpfs, LOCK_NB, bounded retry).
            $lockHandle = @fopen($dir . '/.lock', 'c');
            if ($lockHandle === false) {
                return ['error' => 'Could not open the voice lock file.'];
            }
            $locked = false;
            for ($tries = 0; $tries < self::LOCK_RETRIES; $tries++) {
                if (@flock($lockHandle, LOCK_EX | LOCK_NB)) { $locked = true; break; }
                usleep(self::LOCK_RETRY_DELAY_US);
            }
            if (!$locked) {
                fclose($lockHandle);
                return ['error' => 'Another clip is already being made — try again shortly.'];
            }

            try {
                $config = ConfigService::getConfig();
                $ttsUrl = trim((string)($config['tts_url'] ?? ''));

                // docs/specs/VOICE_MAIL.md — `mail` mode: keep it, do not play it.
                // Nothing is synthesised and nothing is published to the tabs, so a
                // workspace demoted to voice mail is genuinely silent; the message
                // waits as unheard until the operator chooses to play it. Audio is
                // produced only on replay, from the stored text.
                // AGENT_VOICE.md R14 (Forgejo #377): the workspace's own voice wins
                // over the agent's `voice`, which wins over the Settings default. It
                // applies to the engine only; browser speech keeps the old rule.
                $agentVoice = ($voice !== null && trim($voice) !== '') ? trim($voice) : '';
                $workspaceVoice = ($ttsUrl !== '' && !$bypassSwitches) ? self::workspaceVoiceFor($workspaceRecord) : '';
                if ($voiceMode === VoiceMailService::MODE_MAIL && !$bypassSwitches) {
                    // R14: Voice mail keeps the voice the message would have used,
                    // so a replay sounds like the workspace's own voice.
                    $keptVoice = $workspaceVoice !== '' ? $workspaceVoice : ($agentVoice !== '' ? $agentVoice : null);
                    $mailId = VoiceMailService::record($text, $actorContext, VoiceMailService::MODE_MAIL, $keptVoice);
                    @touch($lastFile, $now);
                    self::recordResult(true, 'ok', 'mail');
                    return ['mode' => 'mail', 'chars' => $chars, 'voicemailId' => $mailId];
                }
                // The voice without the workspace's own: the agent's, else the default.
                $baseVoiceId = $agentVoice !== '' ? $agentVoice : (string)($config['tts_voice'] ?? 'af_heart');
                $voiceId = $workspaceVoice !== '' ? $workspaceVoice : $baseVoiceId;
                $speed = (string)($config['tts_speed'] ?? '1.0');
                $excerpt = self::excerpt($text);

                // docs/specs/VOICE_MAIL.md — `speak` mode: play it exactly as before
                // AND keep it. Recorded BEFORE the publish so the id can travel in it:
                // the tab that plays it reports it heard only if playback ENDS while
                // that tab is visible. The operator's own Test click is not voice mail.
                //
                // Recorded inside each branch, not once above them, because engine
                // mode only knows its clipId after synthesis. Recording earlier left
                // every engine message without a clip, so replay could never reuse the
                // still-live audio and always fell back to the browser's own voice —
                // a different voice from the one that actually spoke.
                $voicemailId = null;

                // Keep the browser path in one place so an engine failure has the
                // same payload, voice-mail behaviour and rate-limit bookkeeping as
                // an intentionally empty tts_url. `$publish` is used only by the
                // Settings switch confirmation, which plays the returned answer
                // directly and must not also fan it out to every open tab.
                $publishSpeech = function (bool $fallback = false, string $fallbackMessage = '') use (
                    $text, $voiceId, $workspaceId, $agentId, $name, $chars, $excerpt,
                    $actorContext, $bypassSwitches, $lastFile, $now, $publish, $intro
                ): array {
                    $voicemailId = $bypassSwitches ? null
                        : VoiceMailService::record($text, $actorContext, VoiceMailService::MODE_SPEAK, $voiceId);
                    $payload = [
                        'voicemailId' => $voicemailId,
                        'mode'        => 'speech',
                        'text'        => $text,
                        // R14: the page speaks `intro + " " + text`; '' = no intro.
                        'intro'       => $intro,
                        'voice'       => $voiceId,
                        'workspaceId' => $workspaceId,
                        'agentId'     => $agentId,
                        'name'        => $name,
                        'chars'       => $chars,
                        // Ledger-facing only (R9) — a browser reads the fields
                        // above and ignores the rest.
                        'engine'      => 'browser',
                        'excerpt'     => $excerpt,
                    ];
                    if ($fallback) $payload['fallback'] = true;
                    if ($publish) EventBus::publish('voice', [], $payload);
                    @touch($lastFile, $now);
                    self::recordResult(!$fallback, $fallback ? $fallbackMessage : 'ok', $fallback ? 'speech-fallback' : 'speech');
                    $result = ['mode' => 'speech', 'chars' => $chars, 'voicemailId' => $voicemailId];
                    if ($fallback) {
                        $result['fallback'] = true;
                        $result['message'] = $fallbackMessage;
                    }
                    return $result;
                };

                if ($ttsUrl === '') {
                    // R4: browser mode.
                    return $publishSpeech();
                }

                // R5: engine mode.
                // R14: the engine says the intro and the message as one clip.
                $spokenText = $intro !== '' ? $intro . ' ' . $text : $text;
                $synth = self::synthesize($dir, $ttsUrl, $spokenText, $voiceId, $speed);
                // R14: an engine that does not know the workspace's voice answers with
                // an HTTP error. Try once more with the voice it would use without the
                // workspace voice. A transport error or timeout is not retried.
                if (($synth['status'] ?? '') !== 'ok' && $workspaceVoice !== ''
                    && $baseVoiceId !== $voiceId && (int)($synth['httpStatus'] ?? 0) >= 400) {
                    $retry = self::synthesize($dir, $ttsUrl, $spokenText, $baseVoiceId, $speed);
                    if (($retry['status'] ?? '') === 'ok') {
                        $synth = $retry;
                        $voiceId = $baseVoiceId;
                    }
                }
                if (($synth['status'] ?? '') !== 'ok') {
                    $message = (string)($synth['message'] ?? 'The voice engine failed.');
                    self::recordResult(false, $message, 'audio');
                    return $publishSpeech(true, $message);
                }
                $clipId = (string)$synth['clipId'];
                $voicemailId = $bypassSwitches ? null
                    : VoiceMailService::record($text, $actorContext, VoiceMailService::MODE_SPEAK, $voiceId, $clipId);
                $payload = [
                    'voicemailId' => $voicemailId,
                    'mode'        => 'audio',
                    'clipId'      => $clipId,
                    // S4 (REVIEW_2026-09-13_EVENTS_AND_SECURITY.md#S4): no csrf_token
                    // here. AICliAjax.php's CSRF_EXEMPT_READS list exempts
                    // `voice_clip` — the id is unguessable and expiring, and the
                    // page's own session cookie still gates every other read here.
                    'url'         => '/plugins/unraid-aicliagents/AICliAjax.php?action=voice_clip&id=' . $clipId,
                    'workspaceId' => $workspaceId,
                    'agentId'     => $agentId,
                    'name'        => $name,
                    'chars'       => $chars,
                    // Needed only if the browser cannot retrieve/play the clip
                    // and must fall back to Web Speech. The server already caps
                    // this text at MAX_TEXT_CHARS.
                    'text'        => $text,
                    // R14: the clip already holds the intro; a speech fallback adds it.
                    'intro'       => $intro,
                    'engine'      => self::hostOf($ttsUrl),
                    'excerpt'     => $excerpt,
                ];
                if ($publish) EventBus::publish('voice', [], $payload);
                @touch($lastFile, $now);
                self::recordResult(true, 'ok', 'audio');
                $result = ['mode' => 'audio', 'chars' => $chars, 'clipId' => $clipId, 'voicemailId' => $voicemailId];
                if ($publish === false) $result['url'] = '/plugins/unraid-aicliagents/AICliAjax.php?action=voice_clip&id=' . $clipId;
                return $result;
            } finally {
                @flock($lockHandle, LOCK_UN);
                fclose($lockHandle);
            }
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            if (class_exists('\\AICliAgents\\Services\\RedactionService')) {
                $message = RedactionService::redact($message);
            }
            return ['error' => 'Voice failed: ' . $message];
        }
    }

    // ------------------------------------------------------------------
    // listVoices() — R13
    // ------------------------------------------------------------------

    /**
     * R13: the engine's own voice catalogue, for the Manager Settings "Voice
     * id" dropdown. `GET {tts_url}/v1/audio/voices` is a Kokoro-FastAPI
     * extension, not part of the OpenAI audio API — a plain OpenAI-shaped
     * engine, or one with no such route, answers 404 and this method reports
     * `supported: false` with no error, so the Settings card falls back to
     * the plain text field without alarming the operator. Any other HTTP
     * error, an unreachable engine, or a body that is not the expected JSON
     * shape is also `supported: false`, but WITH a one-sentence error naming
     * the cause, because those are more likely a real misconfiguration.
     *
     * A successful answer is cached VOICES_CACHE_SECONDS in a tmpfs file
     * keyed by an md5 of the URL (self::dir()), so a Settings page that
     * calls this after every field save does not hammer the engine, and a
     * URL change reads a fresh cache key rather than needing an explicit
     * invalidation. The engine key travels only in the request headers,
     * never into the cache file or the return value.
     *
     * @return array{supported:bool,voices:array<int,array{id:string,name:string,grade:string}>,error:string}
     */
    public static function listVoices(): array {
        $config = ConfigService::getConfig();
        $ttsUrl = trim((string)($config['tts_url'] ?? ''));
        if ($ttsUrl === '') {
            return ['supported' => false, 'voices' => [], 'error' => ''];
        }

        $now = self::now();
        $cachePath = self::dir() . '/voices-' . md5($ttsUrl) . '.json';
        $cached = self::readVoicesCache($cachePath, $now);
        if ($cached !== null) {
            return $cached;
        }

        $headers = [];
        $key = self::apiKey();
        if ($key !== '') $headers[] = 'Authorization: Bearer ' . $key;
        $url = rtrim($ttsUrl, '/') . '/v1/audio/voices';
        $host = self::hostOf($ttsUrl);

        // Same seam speak() uses (self::$transport), called with the GET
        // method as an extra 4th argument — a test closure written for the
        // 3-argument POST shape simply never reads it and keeps working.
        $result = (self::$transport !== null)
            ? (self::$transport)($url, $headers, '', 'GET')
            : self::curlTransport($url, $headers, '', 'GET', self::MAX_VOICES_RESPONSE_BYTES);

        $errno  = (int)($result['errno'] ?? 0);
        $status = (int)($result['status'] ?? 0);
        if (!empty($result['too_large'])) {
            return ['supported' => false, 'voices' => [], 'error' => "The voice engine at $host sent a reply that was too large to read."];
        }
        if ($errno !== 0) {
            return ['supported' => false, 'voices' => [], 'error' => "Could not reach the voice engine at $host."];
        }
        if ($status === 404) {
            // Not an error: the engine simply has no voice list to offer.
            return ['supported' => false, 'voices' => [], 'error' => ''];
        }
        if ($status < 200 || $status > 299) {
            return ['supported' => false, 'voices' => [], 'error' => "The voice engine at $host returned HTTP $status."];
        }

        // S5: the voice list must be a JSON reply. A content type this plugin
        // cannot recognise as JSON (or none at all) is refused before the body
        // is even decoded, so a misconfigured or malicious engine cannot slip
        // arbitrary bytes past the parser.
        $contentType = (string)($result['content_type'] ?? '');
        if (stripos($contentType, 'json') === false) {
            return ['supported' => false, 'voices' => [], 'error' => "The voice engine at $host did not answer with JSON."];
        }

        $decoded = json_decode((string)($result['body'] ?? ''), true);
        if (!is_array($decoded) || !isset($decoded['voices']) || !is_array($decoded['voices'])) {
            return ['supported' => false, 'voices' => [], 'error' => "The voice engine at $host returned an answer this plugin could not read."];
        }

        $answer = ['supported' => true, 'voices' => self::normaliseVoices($decoded['voices']), 'error' => ''];
        self::writeVoicesCache($cachePath, $now, $answer['voices']);
        return $answer;
    }

    /** Sort: graded voices first (grade text ascending, ties by id), then the rest by id. */
    private static function normaliseVoices(array $raw): array {
        $graded = [];
        $ungraded = [];
        foreach ($raw as $v) {
            if (!is_array($v)) continue;
            $id = trim((string)($v['id'] ?? ''));
            if ($id === '') continue;
            $name = trim((string)($v['name'] ?? ''));
            $grade = trim((string)($v['overall_grade'] ?? ''));
            $entry = ['id' => $id, 'name' => $name !== '' ? $name : $id, 'grade' => $grade];
            if ($grade !== '') { $graded[] = $entry; } else { $ungraded[] = $entry; }
        }
        usort($graded, static fn(array $a, array $b): int => $a['grade'] <=> $b['grade'] ?: $a['id'] <=> $b['id']);
        usort($ungraded, static fn(array $a, array $b): int => $a['id'] <=> $b['id']);
        return array_merge($graded, $ungraded);
    }

    /** @return array{supported:bool,voices:array,error:string}|null */
    private static function readVoicesCache(string $path, int $now): ?array {
        $raw = @file_get_contents($path);
        if ($raw === false) return null;
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !is_array($decoded['voices'] ?? null)) return null;
        if (($now - (int)($decoded['at'] ?? 0)) >= self::VOICES_CACHE_SECONDS) return null;
        return ['supported' => true, 'voices' => $decoded['voices'], 'error' => ''];
    }

    private static function writeVoicesCache(string $path, int $now, array $voices): void {
        AtomicWriteService::writeJson($path, ['at' => $now, 'voices' => $voices]);
    }

    // ------------------------------------------------------------------
    // transcribe() — VOICE_INPUT.md R2
    // ------------------------------------------------------------------

    /**
     * Turn a browser-recorded clip into text. $filedataBase64 is the raw
     * clip, base64-encoded (the AJAX transport shape, D-405); $mime names
     * its container (`audio/webm`, `audio/ogg`, `audio/mp4` or `audio/wav`,
     * a `;codecs=...` suffix ignored); $seconds is the recorded length, for
     * the 60s cap and the log line only; $workspaceId names the workspace
     * the caller is dictating into — this method never types anything
     * itself, but a clip with nowhere to go (unknown or stopped workspace)
     * is refused before any engine call is made.
     *
     * Refuses (no engine call, no file ever written): `stt_url` empty,
     * $seconds over MAX_STT_SECONDS, decoded bytes over MAX_STT_AUDIO_BYTES,
     * an unrecognised $mime, or an unknown/not-running workspace — UNLESS
     * $bypassWorkspaceCheck is true. The clip itself is NEVER written to
     * disk — it lives only in the multipart request this method builds and
     * forwards.
     *
     * $bypassWorkspaceCheck mirrors speak()'s own $bypassSwitches: the
     * Manager Settings page's "Test (records 3s)" button (VoiceHandler's
     * `voice_transcribe` dispatch) has no real workspace behind it — a human
     * clicked a button, not an agent dictating into a pane — so
     * VoiceHandler passes true for the fixed `workspaceId: 'manager'`
     * sentinel the Settings page sends (never a real workspace id, which
     * always starts with `s` + 8 hex chars). Flagged over the Relay by the
     * browser-side agent 2026-09-13 (dm_81ace92d19f3f8999f4a4e3d /
     * dm_3412860e5d895af7c477775c): without this, the Settings-page engine
     * Test always failed with "No workspace with id 'manager' was found."
     *
     * The recognised text is NEVER logged; only its character count and the
     * clip's duration are (VOICE_INPUT.md "Logging"). The last outcome is
     * recorded for HealthService's `voice_input` check the same way
     * synthesize() records one for `voice`.
     *
     * @return array{text?:string,seconds?:float,chars?:int,error?:string}
     */
    public static function transcribe(string $filedataBase64, string $mime, float $seconds, string $workspaceId, bool $bypassWorkspaceCheck = false): array {
        try {
            $config = ConfigService::getConfig();
            $sttUrl = trim((string)($config['stt_url'] ?? ''));
            if ($sttUrl === '') {
                return ['error' => 'No transcription engine is set; the browser recognises speech on its own.'];
            }
            if ($seconds > self::MAX_STT_SECONDS) {
                return ['error' => 'That clip is longer than the ' . self::MAX_STT_SECONDS . '-second cap.'];
            }

            $bytes = base64_decode($filedataBase64, true);
            if ($bytes === false || $bytes === '') {
                return ['error' => 'There is no audio to transcribe.'];
            }
            if (strlen($bytes) > self::MAX_STT_AUDIO_BYTES) {
                return ['error' => 'That clip is larger than the ' . (self::MAX_STT_AUDIO_BYTES / (1024 * 1024)) . ' MB cap.'];
            }

            $baseMime = strtolower(trim(explode(';', $mime, 2)[0]));
            if (!in_array($baseMime, self::ALLOWED_STT_MIME_TYPES, true)) {
                return ['error' => "'$mime' is not an audio format this plugin accepts."];
            }

            if (!$bypassWorkspaceCheck) {
                $workspace = self::findWorkspace($workspaceId);
                if ($workspace === null) {
                    return ['error' => "No workspace with id '$workspaceId' was found."];
                }
                if (!ProcessManager::isRunning($workspaceId)) {
                    return ['error' => "Workspace '" . (string)($workspace['name'] ?? $workspaceId) . "' is not running."];
                }
            }

            $model = trim((string)($config['stt_model'] ?? '')) ?: 'whisper-1';
            $language = trim((string)($config['stt_language'] ?? ''));

            $result = self::doTranscribe($sttUrl, $bytes, $baseMime, $model, $language);
            if (isset($result['error'])) {
                self::recordSttResult(false, (string)$result['error']);
                return $result;
            }

            $text = trim((string)$result['text']);
            $chars = self::charCount($text);
            self::recordSttResult(true, 'ok');
            LogService::log("Transcribed a {$seconds}s clip ($chars chars)", LogService::LOG_INFO, 'VoiceService');
            return ['text' => $text, 'seconds' => $seconds, 'chars' => $chars];
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            if (class_exists('\\AICliAgents\\Services\\RedactionService')) {
                $message = RedactionService::redact($message);
            }
            return ['error' => 'Transcription failed: ' . $message];
        }
    }

    /**
     * The multipart POST to the transcription engine and its HTTP-error
     * mapping. Split out of transcribe() so the request-building and the
     * caller-facing validation stay easy to read separately, the same split
     * synthesize()/curlTransport() already use for speech.
     *
     * @return array{text?:string,error?:string}
     */
    private static function doTranscribe(string $sttUrl, string $bytes, string $mime, string $model, string $language): array {
        $headers = [];
        $key = self::sttApiKey();
        if ($key !== '') $headers[] = 'Authorization: Bearer ' . $key;

        // The real file bytes/mime/name travel as plain array entries here —
        // only curlMultipartTransport() (the REAL transport, never exercised
        // by a test) turns them into a CURLStringFile, so a test transport
        // closure never needs to touch a curl-specific class.
        $fields = [
            '__file_bytes'    => $bytes,
            '__file_mime'     => $mime,
            '__file_name'     => 'clip.' . self::extensionForMime($mime),
            'model'           => $model,
            'response_format' => 'json',
        ];
        if ($language !== '') $fields['language'] = $language;

        $url = rtrim($sttUrl, '/') . '/v1/audio/transcriptions';
        $host = self::hostOf($sttUrl);

        $result = (self::$transport !== null)
            ? (self::$transport)($url, $headers, $fields, 'MULTIPART')
            : self::curlMultipartTransport($url, $headers, $fields, self::MAX_TRANSCRIBE_RESPONSE_BYTES);

        $errno  = (int)($result['errno'] ?? 0);
        $status = (int)($result['status'] ?? 0);
        if (!empty($result['too_large'])) {
            return ['error' => "The transcription engine at $host sent a reply that was too large to read."];
        }
        if ($errno !== 0) {
            return ['error' => "Could not reach the transcription engine at $host."];
        }
        $body = (string)($result['body'] ?? '');
        if ($status < 200 || $status > 299) {
            // A wrong/unset model is the single most likely misconfiguration
            // (the default 'whisper-1' is an OpenAI name most self-hosted
            // engines do not ship): when the engine's own error body names
            // the model this plugin sent, say so plainly instead of a bare
            // HTTP status — that is obvious enough for the Test button to
            // catch on the first try.
            if (in_array($status, [400, 404, 422], true) && $model !== '' && stripos($body, $model) !== false) {
                return ['error' => "The engine does not know the model '$model'; set the Model field to a model the engine lists."];
            }
            return ['error' => "The transcription engine at $host returned HTTP $status."];
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !array_key_exists('text', $decoded)) {
            return ['error' => "The transcription engine at $host returned an answer this plugin could not read."];
        }
        return ['text' => (string)$decoded['text']];
    }

    /** File extension for a recognised STT mime type, for the multipart filename only. */
    private static function extensionForMime(string $mime): string {
        switch ($mime) {
            case 'audio/webm': return 'webm';
            case 'audio/ogg':  return 'ogg';
            case 'audio/mp4':  return 'mp4';
            case 'audio/wav':  return 'wav';
            default:           return 'bin';
        }
    }

    /** R7/R11 (input side): record the last transcription outcome for HealthService's `voice_input` check. */
    private static function recordSttResult(bool $ok, string $message): void {
        AtomicWriteService::writeJson(self::sttStatusPath(), [
            'ok'      => $ok,
            'message' => $message,
            'at'      => self::now(),
        ]);
    }

    // ------------------------------------------------------------------
    // dictate() — VOICE_INPUT.md R3
    // ------------------------------------------------------------------

    /**
     * Paste dictated text into a workspace's terminal pane and, when $send is
     * true, press Enter — but ONLY while the pane is idle at its own prompt.
     *
     * Unlike AdminService::sendInput() (which pastes nothing at all while the
     * readiness gate is closed), the paste here is UNCONDITIONAL: the
     * operator spoke this text and must see it land, even while the agent is
     * mid-task. Only the Enter that would SUBMIT it waits for the
     * readiness gate `AdminService::sendInput()` uses
     * (`TmuxService::paneAcceptsInput()`), through submitGate(): since
     * 2026-09-29 that gate does not count the operator's own typed text in
     * the input box as busy (`TmuxService::paneAcceptsTypedSubmit()`). When
     * the pane is busy, the text still lands, `sent` is false, and the
     * caller learns `deferred:true, reason:'busy'`.
     *
     * docs/specs/VOICE_INPUT.md R12: when $key is not null, this is a spoken
     * KEY command ("press tab", "delete that") instead of typed text — $text
     * and $send are ignored entirely, and the key goes straight to
     * `TmuxService::sendKey()`, which enforces the allow-list server-side.
     * A key is sent UNCONDITIONALLY, with no readiness-gate check at all —
     * even Enter, per R12 ("a spoken 'press enter' is the operator pressing
     * the key") — unlike the text path's gated Enter above.
     *
     * @return array{workspaceId?:string,delivered?:bool,sent?:bool,deferred?:bool,reason?:string,chars?:int,key?:string,count?:int,error?:string}
     */
    public static function dictate(string $workspaceId, string $text, bool $send, ?string $key = null, int $count = 1): array {
        try {
            $workspace = self::findWorkspace($workspaceId);
            if ($workspace === null) {
                return ['error' => "No workspace with id '$workspaceId' was found."];
            }
            $agentId = (string)($workspace['agentId'] ?? '');
            $name = (string)($workspace['name'] ?? $workspaceId);
            if ($agentId === '') {
                return ['error' => "Workspace '$name' has no agent on record; cannot type into it."];
            }
            if (!ProcessManager::isRunning($workspaceId)) {
                return ['error' => "Workspace '$name' is not running — start it before dictating into it."];
            }

            if ($key !== null) {
                $sent = TmuxService::sendKey($agentId, $workspaceId, $key, $count);
                if (($sent['status'] ?? '') !== 'ok') {
                    return ['error' => (string)($sent['message'] ?? 'Could not send that key.')];
                }
                $sentCount = (int)($sent['count'] ?? $count);
                self::recordDictateKeyLedger($workspaceId, $agentId, $key, $sentCount);
                return ['workspaceId' => $workspaceId, 'delivered' => true, 'key' => $key, 'count' => $sentCount];
            }

            // A trailing newline is not a caller instruction here (unlike
            // sendInput's own "press Enter" convention) — $send alone decides
            // whether Enter is pressed, so it is stripped for the emptiness
            // and length checks only, matching the pasted text exactly.
            $text = rtrim($text, "\n");
            if ($text === '') {
                return ['error' => 'There is nothing to type — text is empty.'];
            }
            $chars = self::charCount($text);
            if ($chars > self::MAX_DICTATE_CHARS) {
                return ['error' => 'Text is too long — the cap is ' . self::MAX_DICTATE_CHARS . ' characters.'];
            }
            if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text)) {
                return ['error' => 'Text contains a control character other than newline/tab, which is not allowed.'];
            }

            $pasted = TmuxService::pasteText($agentId, $workspaceId, $text);
            if (($pasted['status'] ?? '') !== 'ok') {
                return ['error' => (string)($pasted['message'] ?? 'Could not type into that workspace.')];
            }

            $sent = false;
            $deferred = false;
            if ($send) {
                $gate = self::submitGate($agentId, $workspaceId);
                if ($gate['ready'] === true) {
                    $enter = TmuxService::confirmEnterAfterPaste($agentId, $workspaceId, $text);
                    $sent = ($enter['status'] ?? '') === 'ok';
                } else {
                    $deferred = true;
                }
            }

            self::recordDictateLedger($workspaceId, $agentId, $name, $chars, $sent);

            $result = ['workspaceId' => $workspaceId, 'delivered' => true, 'sent' => $sent, 'chars' => $chars];
            if ($deferred) {
                $result['deferred'] = true;
                $result['reason'] = 'busy';
            }
            return $result;
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            if (class_exists('\\AICliAgents\\Services\\RedactionService')) {
                $message = RedactionService::redact($message);
            }
            return ['error' => 'Dictation failed: ' . $message];
        }
    }

    /**
     * VOICE_INPUT.md, 2026-09-29: press Enter on the dictated text that is
     * already typed in the workspace's input box, and type nothing.
     *
     * A stop with "Send when I stop talking" on and no text left to type
     * calls this. Before, the page sent the last phrase again with send=1,
     * and dictate() pasted that phrase a second time. This path runs the
     * same idle gate as the send path of dictate() and presses Enter only
     * when the agent is ready. When it is busy, nothing is pressed and the
     * caller gets `deferred:true, reason:'busy'`, exactly as the send path.
     * One `workspace.input` ledger row records the outcome.
     *
     * @return array{workspaceId?:string,delivered?:bool,sent?:bool,deferred?:bool,reason?:string,chars?:int,error?:string}
     */
    public static function dictateSubmit(string $workspaceId): array {
        try {
            $workspace = self::findWorkspace($workspaceId);
            if ($workspace === null) {
                return ['error' => "No workspace with id '$workspaceId' was found."];
            }
            $agentId = (string)($workspace['agentId'] ?? '');
            $name = (string)($workspace['name'] ?? $workspaceId);
            if ($agentId === '') {
                return ['error' => "Workspace '$name' has no agent on record; cannot type into it."];
            }
            if (!ProcessManager::isRunning($workspaceId)) {
                return ['error' => "Workspace '$name' is not running — start it before dictating into it."];
            }

            $sent = false;
            $deferred = false;
            $gate = self::submitGate($agentId, $workspaceId);
            if ($gate['ready'] === true) {
                $enter = self::submitEnter($agentId, $workspaceId);
                $sent = ($enter['status'] ?? '') === 'ok';
            } else {
                $deferred = true;
            }

            self::recordDictateSubmitLedger($workspaceId, $agentId, $name, $sent);

            $result = ['workspaceId' => $workspaceId, 'delivered' => true, 'sent' => $sent, 'chars' => 0];
            if ($deferred) {
                $result['deferred'] = true;
                $result['reason'] = 'busy';
            }
            return $result;
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            if (class_exists('\\AICliAgents\\Services\\RedactionService')) {
                $message = RedactionService::redact($message);
            }
            return ['error' => 'Dictation failed: ' . $message];
        }
    }

    /**
     * The idle gate before a dictation Enter. It is the Relay gate
     * (TmuxService::paneAcceptsInput()) with one change: the operator's own
     * typed text in the input box does not count as busy
     * (TmuxService::paneAcceptsTypedSubmit()). The plain gate always said
     * busy there, so "Send when I stop talking" could never press Enter.
     *
     * @return array{ready:bool,reason:string}
     */
    private static function submitGate(string $agentId, string $workspaceId): array {
        $seam = self::$tmux['gate'] ?? null;
        return $seam !== null ? $seam($agentId, $workspaceId) : TmuxService::paneAcceptsTypedSubmit($agentId, $workspaceId);
    }

    /**
     * The Enter of dictateSubmit(). No paste happened in this call, so it is
     * not confirmEnterAfterPaste() with a phrase: TmuxService::submitTypedInput()
     * reads the text that is on the input line now and presses Enter until
     * that text leaves the line (the same confirm ladder).
     *
     * @return array{status:string,message?:string,confirmed?:?bool}
     */
    private static function submitEnter(string $agentId, string $workspaceId): array {
        $seam = self::$tmux['enter'] ?? null;
        return $seam !== null ? $seam($agentId, $workspaceId) : TmuxService::submitTypedInput($agentId, $workspaceId);
    }

    /**
     * 2026-09-29: the ledger row of a submit-only dictation (dictateSubmit()).
     * Same `workspace.input` kind and `source:'voice'` as the other dictation
     * rows, with `submit:true` and whether Enter was pressed.
     */
    private static function recordDictateSubmitLedger(string $workspaceId, string $targetAgentId, string $name, bool $sent): void {
        if (!class_exists('\\AICliAgents\\Services\\EventLedger')) return;
        try {
            $summary = $sent ? "submitted dictation in $name" : "held dictation Enter in $name (busy)";
            EventLedger::append(
                'workspace.input',
                ['workspaceId' => $workspaceId, 'agentId' => $targetAgentId],
                $summary,
                ['source' => 'voice', 'submit' => true, 'sent' => $sent]
            );
        } catch (\Throwable $e) {
            // Best-effort — a ledger failure must never undo a pressed Enter.
        }
    }

    /**
     * VOICE_INPUT.md R3: one `workspace.input` ledger row per delivered
     * dictation, `data.source:'voice'` distinguishing it from
     * AdminService::sendInput()'s own rows on the same kind. The actor
     * resolves through EventLedger::append()'s own default
     * (EventActor::current()) — a browser AJAX call with no
     * AICLI_SESSION_ID resolves to the human/browser actor shape on its
     * own, the same as every other Manager/terminal-page action.
     */
    private static function recordDictateLedger(string $workspaceId, string $targetAgentId, string $name, int $chars, bool $sent): void {
        if (!class_exists('\\AICliAgents\\Services\\EventLedger')) return;
        try {
            $summary = trim("dictated $chars chars into $name" . ($sent ? ' (sent)' : ''));
            EventLedger::append(
                'workspace.input',
                ['workspaceId' => $workspaceId, 'agentId' => $targetAgentId],
                $summary,
                ['chars' => $chars, 'source' => 'voice', 'sent' => $sent]
            );
        } catch (\Throwable $e) {
            // Best-effort — a ledger failure must never undo a delivered paste.
        }
    }

    /**
     * docs/specs/VOICE_INPUT.md R12: one `workspace.input` ledger row per
     * delivered spoken KEY command, `data:{source:'voice', key, count}` —
     * the key-command sibling of recordDictateLedger()'s text-shaped row on
     * the same `workspace.input` kind.
     */
    private static function recordDictateKeyLedger(string $workspaceId, string $targetAgentId, string $key, int $count): void {
        if (!class_exists('\\AICliAgents\\Services\\EventLedger')) return;
        try {
            $summary = $count > 1 ? "sent key $key x$count" : "sent key $key";
            EventLedger::append(
                'workspace.input',
                ['workspaceId' => $workspaceId, 'agentId' => $targetAgentId],
                $summary,
                ['source' => 'voice', 'key' => $key, 'count' => $count]
            );
        } catch (\Throwable $e) {
            // Best-effort — a ledger failure must never undo a delivered key.
        }
    }

    /**
     * docs/specs/VOICE_MAIL.md R7: a fresh clip for a voice mail REPLAY, through the
     * configured engine. Returns ['mode' => 'speech'] in browser mode (no tts_url),
     * ['mode' => 'audio', 'clipId' => …] on success, or ['mode' => 'speech',
     * 'message' => …] when the engine fails, so the page still reads it aloud.
     *
     * The operator chose to play this, so neither switch, the 3-second gap nor the
     * queue cap applies, and nothing is published or recorded again: the one tab
     * that asked plays the clip. The stored voice is used when it looks like an
     * engine voice id; a message kept in browser mode falls back to tts_voice.
     * $intro (VOICE_MAIL.md R14, "<spoken name> says:") is spoken first when given.
     *
     * @return array{mode:string,clipId?:string,message?:string}
     */
    public static function replayClip(string $text, ?string $voice = null, string $intro = ''): array {
        try {
            $config = ConfigService::getConfig();
            $ttsUrl = trim((string)($config['tts_url'] ?? ''));
            if ($ttsUrl === '' || trim($text) === '') return ['mode' => 'speech'];
            $voiceId = ($voice !== null && self::isValidVoiceId($voice))
                ? $voice : (string)($config['tts_voice'] ?? 'af_heart');
            $dir = self::dir();
            if (!is_dir($dir)) @mkdir($dir, 0777, true);
            self::sweepOldClips($dir);
            // VOICE_MAIL.md R14: a replay starts with the same intro as the live message.
            $spoken = trim($intro) !== '' ? trim($intro) . ' ' . self::capText($text) : self::capText($text);
            $synth = self::synthesize($dir, $ttsUrl, $spoken, $voiceId, (string)($config['tts_speed'] ?? '1.0'));
            if (($synth['status'] ?? '') !== 'ok') {
                return ['mode' => 'speech', 'message' => (string)($synth['message'] ?? 'The voice engine failed.')];
            }
            return ['mode' => 'audio', 'clipId' => (string)$synth['clipId']];
        } catch (\Throwable $e) {
            return ['mode' => 'speech', 'message' => 'The voice engine failed.'];
        }
    }

    // ------------------------------------------------------------------
    // Engine transport (R5, R6)
    // ------------------------------------------------------------------

    /** @return array{status:string,clipId?:string,message?:string,httpStatus?:int} */
    private static function synthesize(string $dir, string $ttsUrl, string $text, string $voiceId, string $speed): array {
        $headers = ['Content-Type: application/json'];
        $key = self::apiKey();
        if ($key !== '') $headers[] = 'Authorization: Bearer ' . $key;

        $body = (string)json_encode([
            'model'           => 'kokoro',
            'input'           => $text,
            'voice'           => $voiceId,
            'response_format' => 'mp3',
            'speed'           => (float)$speed,
        ]);
        $url = rtrim($ttsUrl, '/') . '/v1/audio/speech';
        $host = self::hostOf($ttsUrl);

        $result = (self::$transport !== null)
            ? (self::$transport)($url, $headers, $body)
            : self::curlTransport($url, $headers, $body, 'POST', self::MAX_SPEECH_RESPONSE_BYTES);

        $errno  = (int)($result['errno'] ?? 0);
        $status = (int)($result['status'] ?? 0);
        if (!empty($result['too_large'])) {
            return ['status' => 'error', 'message' => "The voice engine at $host sent a clip that was too large."];
        }
        if ($errno !== 0) {
            return ['status' => 'error', 'message' => "Could not reach the voice engine at $host."];
        }
        if ($status < 200 || $status > 299) {
            return ['status' => 'error', 'message' => "The voice engine at $host returned HTTP $status.", 'httpStatus' => $status];
        }
        $bytes = (string)($result['body'] ?? '');
        if ($bytes === '') {
            return ['status' => 'error', 'message' => "The voice engine at $host returned an empty clip."];
        }
        // S5: the engine must actually answer with an MP3. Checking the first
        // bytes before anything is written to disk means a wrong or hostile
        // reply never becomes a file a browser's <audio> tag then tries to play.
        if (!self::looksLikeMp3($bytes)) {
            return ['status' => 'error', 'message' => "The voice engine at $host did not return an MP3 clip."];
        }

        $clipId = 'v_' . bin2hex(random_bytes(8));
        $clipPath = $dir . "/$clipId.mp3";
        if (!AtomicWriteService::write($clipPath, $bytes)) {
            return ['status' => 'error', 'message' => 'Could not write the voice clip to disk.'];
        }
        // S9: a clip carries whatever the caller asked the engine to say —
        // treat it like any other secret-adjacent artifact on this host.
        @chmod($clipPath, 0600);
        return ['status' => 'ok', 'clipId' => $clipId];
    }

    /**
     * S5: true when $bytes starts with a recognised MP3 signature — either an
     * `ID3` tag (most encoders, including Kokoro-FastAPI, prefix one) or a
     * bare MPEG audio frame sync (0xFF followed by a byte whose top three
     * bits are set). Anything else is not an MP3 this plugin will serve.
     */
    private static function looksLikeMp3(string $bytes): bool {
        if (strlen($bytes) < 2) return false;
        if (substr($bytes, 0, 3) === 'ID3') return true;
        return (ord($bytes[0]) === 0xFF) && ((ord($bytes[1]) & 0xE0) === 0xE0);
    }

    /**
     * Real transport: POST (speech) or GET (R13 voice list) to the configured
     * engine. Swappable via self::$transport for tests.
     *
     * S5: $maxBytes caps the reply. CURLOPT_MAXFILESIZE only rejects a
     * download whose server-declared Content-Length already exceeds the cap
     * — a chunked or streaming reply carries no such header, so the write
     * callback below also counts bytes as they arrive and aborts the
     * transfer the moment the cap is crossed, before the reply is ever
     * fully buffered.
     */
    private static function curlTransport(string $url, array $headers, string $body, string $method = 'POST', int $maxBytes = 0): array {
        $ch = curl_init($url);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, $method === 'POST' ? self::CURL_TIMEOUT_S : self::LIST_VOICES_TIMEOUT_S);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

        $received = '';
        $tooLarge = false;
        if ($maxBytes > 0) {
            curl_setopt($ch, CURLOPT_MAXFILESIZE, $maxBytes);
            curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($curlHandle, string $chunk) use (&$received, &$tooLarge, $maxBytes): int {
                $received .= $chunk;
                if (strlen($received) > $maxBytes) {
                    $tooLarge = true;
                    return 0; // less than strlen($chunk) tells curl to abort the transfer now
                }
                return strlen($chunk);
            });
        } else {
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        }

        $transferResult = curl_exec($ch);
        $errno  = curl_errno($ch);
        $error  = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        $responseBody = $maxBytes > 0
            ? $received
            : ($transferResult === false ? '' : $transferResult);

        return [
            'errno'        => $errno,
            'error'        => $error,
            'status'       => $status,
            'body'         => $responseBody,
            'content_type' => $contentType,
            'too_large'    => $tooLarge,
        ];
    }

    /**
     * VOICE_INPUT.md R2: the real multipart/form-data POST to the
     * transcription engine — the ONLY place a CURLStringFile is built, so a
     * test transport closure never needs curl-specific classes. $fields
     * carries the three `__file_*` entries doTranscribe() packed (bytes,
     * mime, filename) plus the plain string fields (model/language/
     * response_format); this method turns the file entries into one
     * CURLStringFile and leaves the rest untouched. S5-style cap: same
     * MAXFILESIZE + early-abort WRITEFUNCTION pattern curlTransport() uses.
     */
    private static function curlMultipartTransport(string $url, array $headers, array $fields, int $maxBytes): array {
        $fileBytes = (string)($fields['__file_bytes'] ?? '');
        $fileMime  = (string)($fields['__file_mime'] ?? 'application/octet-stream');
        $fileName  = (string)($fields['__file_name'] ?? 'clip');
        unset($fields['__file_bytes'], $fields['__file_mime'], $fields['__file_name']);
        $fields['file'] = new \CURLStringFile($fileBytes, $fileName, $fileMime);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::STT_TIMEOUT_S);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

        $received = '';
        $tooLarge = false;
        curl_setopt($ch, CURLOPT_MAXFILESIZE, $maxBytes);
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($curlHandle, string $chunk) use (&$received, &$tooLarge, $maxBytes): int {
            $received .= $chunk;
            if (strlen($received) > $maxBytes) {
                $tooLarge = true;
                return 0; // less than strlen($chunk) tells curl to abort the transfer now
            }
            return strlen($chunk);
        });

        curl_exec($ch);
        $errno  = curl_errno($ch);
        $error  = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return ['errno' => $errno, 'error' => $error, 'status' => $status, 'body' => $received, 'too_large' => $tooLarge];
    }

    /** The host[:port] of a URL, or the URL itself when it cannot be parsed — never the raw path/query. */
    private static function hostOf(string $url): string {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') return $url;
        $port = parse_url($url, PHP_URL_PORT);
        return $port !== null ? "$host:$port" : $host;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private static function capText(string $text): string {
        return self::charCount($text) > self::MAX_TEXT_CHARS
            ? (function_exists('mb_substr') ? mb_substr($text, 0, self::MAX_TEXT_CHARS) : substr($text, 0, self::MAX_TEXT_CHARS))
            : $text;
    }

    private static function charCount(string $text): int {
        return function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
    }

    /**
     * R9's "redacted 80-character excerpt": known secrets are scrubbed the same
     * way EventLedger scrubs every other summary field, then the result is cut to
     * EXCERPT_CHARS. Used for the ledger-facing `excerpt` field and the tray
     * summary (EventLedger::summaryFor()) — never sent to a browser as the thing
     * to speak, only as an audit trail of what was said.
     */
    private static function excerpt(string $text): string {
        $safe = $text;
        if (class_exists('\\AICliAgents\\Services\\RedactionService')) {
            try {
                $safe = RedactionService::redact($text, RedactionService::loadKnownSecrets());
            } catch (\Throwable $e) {
                // Fall back to the untruncated original — a broken redaction
                // pass must not block the excerpt entirely.
            }
        }
        return function_exists('mb_substr') ? mb_substr($safe, 0, self::EXCERPT_CHARS) : substr($safe, 0, self::EXCERPT_CHARS);
    }

    /** R5: unlink any clip older than CLIP_MAX_AGE_SECONDS. Runs on every speak(). */
    private static function sweepOldClips(string $dir): void {
        $now = self::now();
        foreach ((glob($dir . '/*.mp3') ?: []) as $clip) {
            $age = $now - (int)(@filemtime($clip) ?: 0);
            if ($age > self::CLIP_MAX_AGE_SECONDS) @unlink($clip);
        }
    }

    /** R6/R11: record the last synthesis outcome for HealthService's `voice` check. */
    private static function recordResult(bool $ok, string $message, string $mode): void {
        AtomicWriteService::writeJson(self::statusPath(), [
            'ok'      => $ok,
            'message' => $message,
            'mode'    => $mode,
            'at'      => self::now(),
        ]);
    }
}
