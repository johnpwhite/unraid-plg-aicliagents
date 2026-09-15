<?php
/**
 * <module_context>
 *     <name>VoiceMailService</name>
 *     <description>Keeps every spoken notice as voice mail, so a message an agent spoke
 *     while nobody was looking can be read or replayed afterwards, and shows which
 *     workspace spoke (docs/specs/VOICE_MAIL.md, Forgejo #210). Stores TEXT, not audio:
 *     the text is the message and the audio is only a rendering of it, so replay
 *     re-synthesises and the feature works in browser speech and engine mode alike.
 *     Owns the per-workspace three-way voice mode (speak / mail / off).</description>
 *     <dependencies>AtomicWriteService (temp+rename writes), ConfigService (workspace
 *     registry, for the mode and the display name), NchanService (the `voicemail`
 *     event), LogService.</dependencies>
 *     <constraints>
 *     HYBRID RAM-FLASH, per .claude/docs/unraid-patterns/HYBRID_PERSISTENCE.md. Unlike
 *     favourites, voice mail is written on every spoken message, so writing straight to
 *     the USB stick would wear it. The live store is on TMPFS; flush() copies it to
 *     Flash only when something changed, and is driven by the supervisor's heartbeat
 *     and by the array-stop event. A hard crash can lose the messages since the last
 *     flush — acceptable for voice mail, and the plugin's established trade-off.
 *
 *     Locking: a short LOCK_EX|LOCK_NB retry loop on a TMPFS lock file — never a
 *     blocking flock, never a lock file on Flash (the shfs/FUSE freeze rule).
 *
 *     A store that is not valid JSON is renamed aside to `.corrupt-<epoch>` and treated
 *     as empty; it is never deleted and never blocks a later write.
 *
 *     "Heard" means ONLY that a tab reported playback completed while that tab was
 *     visible. The plugin cannot know a human heard anything, and nothing in this
 *     service claims otherwise.
 *     </constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

require_once __DIR__ . '/AtomicWriteService.php';

final class VoiceMailService {

    public const SCHEMA_VERSION = 1;

    public const MODE_SPEAK = 'speak';
    public const MODE_MAIL  = 'mail';
    public const MODE_OFF   = 'off';

    /** Live store on TMPFS — written on every message. */
    public const REAL_RAM_PATH = '/tmp/unraid-aicliagents/voicemail.json';

    /** Durable copy on Flash — written only by flush(). */
    public const REAL_FLASH_PATH = '/boot/config/plugins/unraid-aicliagents/voicemail.json';

    /** Coordination lock on TMPFS. */
    public const REAL_LOCK_PATH = '/tmp/unraid-aicliagents/voicemail.lock';

    /** Marker meaning "RAM has changes Flash does not" — lives beside the RAM store. */
    private const DIRTY_SUFFIX = '.dirty';

    /** Retention defaults (R8), both overridable in settings. */
    public const DEFAULT_MAX_PER_WORKSPACE = 50;
    public const DEFAULT_MAX_AGE_DAYS = 7;

    /**
     * Hard ceiling across every workspace, independent of the settings. A runaway
     * caller must never be able to grow a file on the Flash drive without bound.
     */
    public const HARD_MAX_TOTAL = 2000;

    /** A message's text is capped so one oversized utterance cannot bloat the store. */
    public const MAX_TEXT_CHARS = 4000;

    private const LOCK_RETRIES = 10;
    private const LOCK_RETRY_DELAY_US = 20000;

    /** Test seams. Null means the REAL_* constant. A test never writes to /boot. */
    public static ?string $ramPath = null;
    public static ?string $flashPath = null;
    public static ?string $lockPath = null;
    /** @var callable|null  () => int, so retention is testable without sleeping. */
    public static $clock = null;
    /** @var callable|null  (string $channel, array $payload) => void */
    public static $publisher = null;

    public static function ramPath(): string   { return self::$ramPath ?? self::REAL_RAM_PATH; }
    public static function flashPath(): string { return self::$flashPath ?? self::REAL_FLASH_PATH; }
    public static function lockPath(): string  { return self::$lockPath ?? self::REAL_LOCK_PATH; }

    private static function now(): int {
        return self::$clock !== null ? (int)(self::$clock)() : time();
    }

    // ------------------------------------------------------------------
    // Per-workspace mode (R11: backwards compatible, no migration)
    // ------------------------------------------------------------------

    /**
     * The voice mode for one workspace record.
     *
     * An explicit `voice_mode` wins. Otherwise the existing boolean decides:
     * `voice === false` is `off`, and `true` or absent is `speak`. So every workspace
     * that existed before voice mail keeps behaving exactly as it did — a muted one
     * stays muted, an unmuted one keeps speaking — with no migration step.
     */
    public static function modeFor(?array $workspace): string {
        if ($workspace === null) return self::MODE_SPEAK;
        $explicit = (string)($workspace['voice_mode'] ?? '');
        if (in_array($explicit, [self::MODE_SPEAK, self::MODE_MAIL, self::MODE_OFF], true)) {
            return $explicit;
        }
        return (($workspace['voice'] ?? true) === false) ? self::MODE_OFF : self::MODE_SPEAK;
    }

    /** True for a value setMode() will accept. */
    public static function isValidMode(string $mode): bool {
        return in_array($mode, [self::MODE_SPEAK, self::MODE_MAIL, self::MODE_OFF], true);
    }

    /**
     * Set one workspace's voice mode (R6). Returns ['mode' => …] or ['error' => …].
     *
     * Saves the FULL workspace record back rather than a partial one, so this can
     * never wipe a workspace's name, path or agent regardless of how the registry
     * merges a write. Also keeps the old `voice` boolean in step (`off` → false,
     * otherwise true) for any reader still on it — the drawer's mute icon, the
     * favourites snapshot, the admin tools.
     */
    public static function setMode(string $workspaceId, string $mode): array {
        if (!self::isValidMode($mode)) {
            return ['error' => "Unknown voice mode '$mode'. Use speak, mail or off."];
        }
        try {
            $record = null;
            foreach (ConfigService::getWorkspaces()['sessions'] ?? [] as $w) {
                if (is_array($w) && (string)($w['id'] ?? '') === $workspaceId) { $record = $w; break; }
            }
            if ($record === null) {
                return ['error' => 'That workspace no longer exists.'];
            }
            $record['voice_mode'] = $mode;
            $record['voice'] = ($mode !== self::MODE_OFF);
            if (!ConfigService::saveWorkspaces(['sessions' => [$record]], [])) {
                return ['error' => ConfigService::lastWorkspaceSaveMessage() ?? 'Could not save the voice setting.'];
            }
            return ['mode' => $mode];
        } catch (\Throwable $e) {
            return ['error' => 'Could not save the voice setting.'];
        }
    }

    // ------------------------------------------------------------------
    // Recording (R1)
    // ------------------------------------------------------------------

    /**
     * Record one delivered utterance. Returns the new message id, or null if it
     * could not be recorded — which must never stop the message being spoken.
     *
     * @param array{workspaceId:string,agentId?:string,name?:string} $actor
     */
    public static function record(string $text, array $actor, string $mode, ?string $voice = null, ?string $clipId = null): ?string {
        $workspaceId = (string)($actor['workspaceId'] ?? '');
        $text = trim($text);
        if ($workspaceId === '' || $text === '') return null;
        if ($mode !== self::MODE_SPEAK && $mode !== self::MODE_MAIL) return null;

        if (mb_strlen($text) > self::MAX_TEXT_CHARS) {
            $text = mb_substr($text, 0, self::MAX_TEXT_CHARS) . '…';
        }

        $id = 'vm_' . bin2hex(random_bytes(8));
        $message = [
            'id'          => $id,
            'ts'          => self::now(),
            'workspaceId' => $workspaceId,
            'agentId'     => (string)($actor['agentId'] ?? ''),
            'name'        => (string)($actor['name'] ?? ''),
            'text'        => $text,
            'mode'        => $mode,
            'voice'       => $voice,
            'clipId'      => $clipId,
            'heard'       => false,
            'heardAt'     => null,
        ];

        $ok = self::mutate(static function (array $store) use ($message): array {
            $store['messages'][] = $message;
            return self::prune($store);
        });
        if (!$ok) return null;

        self::publishChange($workspaceId, $id, 'kept');
        return $id;
    }

    // ------------------------------------------------------------------
    // Reading
    // ------------------------------------------------------------------

    /**
     * Messages newest first, optionally for one workspace, plus unheard counts.
     *
     * @return array{messages:array,unheard:array<string,int>,total_unheard:int}
     */
    public static function list(?string $workspaceId = null): array {
        $messages = self::load()['messages'];
        $unheard = [];
        $total = 0;
        foreach ($messages as $m) {
            if (empty($m['heard'])) {
                $w = (string)$m['workspaceId'];
                $unheard[$w] = ($unheard[$w] ?? 0) + 1;
                $total++;
            }
        }
        if ($workspaceId !== null && $workspaceId !== '') {
            $messages = array_values(array_filter($messages,
                static fn (array $m): bool => (string)$m['workspaceId'] === $workspaceId));
        }
        usort($messages, static fn (array $a, array $b): int => $b['ts'] <=> $a['ts']);
        return ['messages' => $messages, 'unheard' => $unheard, 'total_unheard' => $total];
    }

    /** One message by id, or null. */
    public static function get(string $id): ?array {
        foreach (self::load()['messages'] as $m) {
            if ($m['id'] === $id) return $m;
        }
        return null;
    }

    // ------------------------------------------------------------------
    // Heard (R2, R10)
    // ------------------------------------------------------------------

    /**
     * Mark messages heard. One id, or every unheard message for a workspace.
     * Returns how many changed. Marking something already heard is not an error —
     * two devices finishing the same playback must both succeed quietly.
     */
    public static function markHeard(?string $id = null, ?string $workspaceId = null): int {
        if (($id === null || $id === '') && ($workspaceId === null || $workspaceId === '')) return 0;
        $changed = 0;
        $touchedWorkspace = $workspaceId;
        self::mutate(static function (array $store) use ($id, $workspaceId, &$changed, &$touchedWorkspace): array {
            $at = self::now();
            foreach ($store['messages'] as &$m) {
                if (!empty($m['heard'])) continue;
                $match = ($id !== null && $id !== '')
                    ? $m['id'] === $id
                    : (string)$m['workspaceId'] === $workspaceId;
                if (!$match) continue;
                $m['heard'] = true;
                $m['heardAt'] = $at;
                $touchedWorkspace = (string)$m['workspaceId'];
                $changed++;
            }
            unset($m);
            return $store;
        });
        if ($changed > 0) self::publishChange((string)$touchedWorkspace, $id, 'heard');
        return $changed;
    }

    /**
     * Remove every message for one workspace. For a workspace id that was never a real
     * workspace — the smoke test's own `smoke_voice_<pid>` speaks through the real
     * store and must leave no rows in the operator's panel. Returns how many went.
     */
    public static function forgetWorkspace(string $workspaceId): int {
        if ($workspaceId === '') return 0;
        $removed = 0;
        self::mutate(static function (array $store) use ($workspaceId, &$removed): array {
            $before = count($store['messages']);
            $store['messages'] = array_values(array_filter($store['messages'],
                static fn ($m): bool => (string)($m['workspaceId'] ?? '') !== $workspaceId));
            $removed = $before - count($store['messages']);
            return $store;
        });
        if ($removed > 0) self::publishChange($workspaceId, null, 'deleted');
        return $removed;
    }

    /**
     * Delete one message for good (R12). The text is the only record, so nothing
     * comes back. Returns 1 when it went, 0 when no message had that id.
     */
    public static function delete(string $id): int {
        if ($id === '') return 0;
        $removed = 0;
        $workspaceId = '';
        self::mutate(static function (array $store) use ($id, &$removed, &$workspaceId): array {
            $kept = [];
            foreach ($store['messages'] as $m) {
                if ((string)($m['id'] ?? '') === $id) { $removed++; $workspaceId = (string)($m['workspaceId'] ?? ''); continue; }
                $kept[] = $m;
            }
            $store['messages'] = $kept;
            return $store;
        });
        if ($removed > 0) self::publishChange($workspaceId, $id, 'deleted');
        return $removed;
    }

    /**
     * Delete every message for one workspace, or — with null — every message there is
     * (R12). Heard and unheard alike. Returns how many went.
     */
    public static function deleteAll(?string $workspaceId = null): int {
        if ($workspaceId === '') return 0;   // an empty id must never mean every workspace: null does, on purpose
        if ($workspaceId !== null) return self::forgetWorkspace($workspaceId);
        $removed = 0;
        self::mutate(static function (array $store) use (&$removed): array {
            $removed = count($store['messages']);
            $store['messages'] = [];
            return $store;
        });
        if ($removed > 0) self::publishChange('', null, 'deleted');
        return $removed;
    }

    // ------------------------------------------------------------------
    // Retention (R8)
    // ------------------------------------------------------------------

    /**
     * Apply the retention rules. Pure over the store, so it is testable directly.
     *
     * Order matters and is the whole point of the design: age first, then the
     * per-workspace cap removing HEARD messages before unheard ones, then the
     * hard global ceiling. An unheard message is the last thing to go, because it
     * is the one the operator has not had a chance to see.
     */
    public static function prune(array $store, ?int $maxPerWorkspace = null, ?int $maxAgeDays = null): array {
        $maxPer = $maxPerWorkspace ?? self::settingInt('voicemail_max_per_workspace', self::DEFAULT_MAX_PER_WORKSPACE);
        $maxAge = $maxAgeDays ?? self::settingInt('voicemail_max_age_days', self::DEFAULT_MAX_AGE_DAYS);
        $cutoff = self::now() - ($maxAge * 86400);

        $messages = array_values(array_filter($store['messages'] ?? [],
            static fn ($m): bool => is_array($m) && (int)($m['ts'] ?? 0) >= $cutoff));

        $byWorkspace = [];
        foreach ($messages as $m) $byWorkspace[(string)$m['workspaceId']][] = $m;

        $kept = [];
        foreach ($byWorkspace as $list) {
            if (count($list) > $maxPer) {
                // Heard sort before unheard; within each, oldest first — so the slice
                // that is dropped is the oldest heard, then the oldest unheard.
                usort($list, static function (array $a, array $b): int {
                    $h = (int)!empty($b['heard']) <=> (int)!empty($a['heard']);
                    return $h !== 0 ? $h : ($a['ts'] <=> $b['ts']);
                });
                $list = array_slice($list, count($list) - $maxPer);
            }
            foreach ($list as $m) $kept[] = $m;
        }

        if (count($kept) > self::HARD_MAX_TOTAL) {
            usort($kept, static fn (array $a, array $b): int => $a['ts'] <=> $b['ts']);
            $kept = array_slice($kept, count($kept) - self::HARD_MAX_TOTAL);
        }

        $store['messages'] = $kept;
        return $store;
    }

    // ------------------------------------------------------------------
    // Hybrid RAM-Flash sync
    // ------------------------------------------------------------------

    /**
     * Copy the RAM store to Flash if it has changed since the last flush.
     * Returns true when Flash is up to date afterwards (including "nothing to do").
     * Called by the supervisor's heartbeat and by the array-stop event.
     */
    public static function flush(): bool {
        $dirty = self::ramPath() . self::DIRTY_SUFFIX;
        if (!is_file($dirty)) return true;
        $ram = self::ramPath();
        if (!is_file($ram)) { @unlink($dirty); return true; }
        $data = json_decode((string)@file_get_contents($ram), true);
        if (!is_array($data)) return false;
        @mkdir(dirname(self::flashPath()), 0755, true);
        if (!AtomicWriteService::writeJson(self::flashPath(), $data)) return false;
        @unlink($dirty);
        return true;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Load the store. RAM is authoritative; when RAM is empty (first read after a
     * reboot) it is seeded from the Flash copy, so voice mail survives a restart.
     *
     * @return array{schema:int,messages:array}
     */
    private static function load(): array {
        $ram = self::ramPath();
        if (!is_file($ram) && is_file(self::flashPath())) {
            @mkdir(dirname($ram), 0755, true);
            @copy(self::flashPath(), $ram);
        }
        if (!is_file($ram)) return ['schema' => self::SCHEMA_VERSION, 'messages' => []];

        $raw = (string)@file_get_contents($ram);
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['messages']) || !is_array($data['messages'])) {
            if (trim($raw) !== '') {
                $aside = $ram . '.corrupt-' . time();
                @rename($ram, $aside);
                if (class_exists(LogService::class)) {
                    LogService::log("VoiceMailService: store was not valid JSON — renamed aside to $aside and started empty.",
                        LogService::LOG_WARN, 'VoiceMailService');
                }
            }
            return ['schema' => self::SCHEMA_VERSION, 'messages' => []];
        }
        return $data;
    }

    /**
     * Read-modify-write under the tmpfs lock. Returns false if the lock could not be
     * taken within its budget — never blocks.
     */
    private static function mutate(callable $fn): bool {
        @mkdir(dirname(self::lockPath()), 0755, true);
        $fh = @fopen(self::lockPath(), 'c');
        if ($fh === false) return false;
        $locked = false;
        for ($i = 0; $i < self::LOCK_RETRIES; $i++) {
            if (@flock($fh, LOCK_EX | LOCK_NB)) { $locked = true; break; }
            usleep(self::LOCK_RETRY_DELAY_US);
        }
        if (!$locked) { fclose($fh); return false; }
        try {
            $store = $fn(self::load());
            $store['schema'] = self::SCHEMA_VERSION;
            @mkdir(dirname(self::ramPath()), 0755, true);
            if (!AtomicWriteService::writeJson(self::ramPath(), $store)) return false;
            @touch(self::ramPath() . self::DIRTY_SUFFIX);
            return true;
        } finally {
            @flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /**
     * Announce a change. Subscribers RE-READ voicemail_list and never decide from the
     * payload alone — nchan replays on connect, and deciding from arrival is how the
     * plugin's earlier publisher feedback loops started.
     */
    private static function publishChange(string $workspaceId, ?string $changedId, string $reason = 'updated'): void {
        try {
            $counts = self::list();
            $payload = [
                // kept | heard — so the audit ledger can tell a message being filed
                // from a message being played. Without it a workspace set to "Voice
                // mail" would leave no record at all that its agent said anything.
                'reason'        => $reason,
                'workspaceId'   => $workspaceId,
                'unheard'       => (int)($counts['unheard'][$workspaceId] ?? 0),
                'total_unheard' => (int)$counts['total_unheard'],
            ];
            if ($changedId !== null && $changedId !== '') $payload['changedId'] = $changedId;
            if (self::$publisher !== null) {
                (self::$publisher)('voicemail', $payload);
            } elseif (class_exists(NchanService::class)) {
                NchanService::publish('voicemail', $payload);
            }
        } catch (\Throwable $e) {
            // Announcing is best-effort. The record is already written.
        }
    }

    private static function settingInt(string $key, int $default): int {
        try {
            $v = (int)(ConfigService::getConfig()[$key] ?? $default);
            return $v > 0 ? $v : $default;
        } catch (\Throwable $e) {
            return $default;
        }
    }
}
