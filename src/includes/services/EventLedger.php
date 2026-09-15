<?php
/**
 * <module_context>
 *     <name>EventLedger</name>
 *     <description>A circular, tmpfs event log, appended by NchanService::publish()
 *     so every publisher gains one ledger line with no publisher-side code
 *     (docs/specs/PLUGIN_EVENT_LEDGER_AND_SUBSCRIPTIONS.md, R1/R6/R7). One line
 *     of JSON per event: {seq, ts, kind, actor, subject, summary, data}. Chunked
 *     by count (1 000 events per chunk); the supervisor unlinks the oldest chunk
 *     once more than the configured cap exist. A reboot clears it — that is
 *     correct, an event that predates a reboot is not one an agent should act
 *     on. Reads never delete; only rotation and reboot remove a line.</description>
 *     <dependencies>RedactionService (secret scrub before write); EventActor
 *     (actor resolution when the caller does not supply one); NchanService
 *     (the server clock, `nowMs()`).</dependencies>
 *     <constraints>Never throws to its caller — a broken ledger must not break
 *     a publish. The seq counter takes a non-blocking flock with a short bounded
 *     retry (tmpfs, never FUSE); every other write is a single append with no
 *     lock. A line is capped at 4 KB: summary is trimmed to 512 chars first,
 *     then `data` is dropped to {"truncated":true} if the line still does not
 *     fit.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

require_once __DIR__ . '/RedactionService.php';
require_once __DIR__ . '/EventActor.php';

final class EventLedger {

    /** Directory holding the seq counter, the boot marker, and every chunk. */
    public const DIR = '/tmp/unraid-aicliagents/events';

    /** Events per chunk file. A new chunk starts every CHUNK_SIZE-th event. */
    public const CHUNK_SIZE = 1000;

    /**
     * Default chunk retention (20 000 events). The supervisor is the actual
     * enforcer (it unlinks the oldest chunk); this constant documents the
     * default the cfg key `event_ledger_max_events` overrides.
     */
    public const MAX_CHUNKS = 20;

    /** Longest a single ledger line may be before `data` is dropped. */
    private const MAX_LINE_BYTES = 4096;

    /** `summary` is trimmed to this many characters before the line is built. */
    private const MAX_SUMMARY_CHARS = 512;

    /** How many times append() retries the seq-counter lock before giving up. */
    private const SEQ_LOCK_RETRIES = 20;

    /** Delay between seq-lock retries, in microseconds. */
    private const SEQ_LOCK_RETRY_DELAY_US = 5000;

    /** Test seam: overrides DIR. Null uses the real path. */
    public static ?string $dir = null;

    /** Test seam: overrides bootId(). Null reads /proc. */
    public static ?string $bootId = null;

    /** Resolved ledger directory (DIR unless a test set $dir). */
    private static function dir(): string {
        return self::$dir ?? self::DIR;
    }

    /** The live kernel boot id, trimmed. Empty string if it cannot be read. */
    public static function bootId(): string {
        if (self::$bootId !== null) {
            return self::$bootId;
        }
        $raw = @file_get_contents('/proc/sys/kernel/random/boot_id');
        return $raw !== false ? trim($raw) : '';
    }

    /** The seq of the newest event ever written this boot. 0 when empty. */
    public static function headSeq(): int {
        $raw = @file_get_contents(self::dir() . '/.seq');
        if ($raw === false) return 0;
        $raw = trim($raw);
        return ($raw !== '' && ctype_digit($raw)) ? (int)$raw : 0;
    }

    /** The seq of the oldest retained event. 0 when the ledger is empty. */
    public static function oldestSeq(): int {
        $chunks = self::chunkStarts();
        return $chunks === [] ? 0 : $chunks[0];
    }

    /**
     * Append one event. Returns the seq written, or null when nothing was
     * written (redaction failure, or the seq counter could not be locked).
     * $actor null resolves to EventActor::current().
     */
    public static function append(string $kind, array $subject = [], string $summary = '', array $data = [], ?array $actor = null): ?int {
        try {
            [$safeSummary, $safeData] = self::redactPayload($summary, $data);
        } catch (\Throwable $e) {
            // Spec edge case: a redaction failure means the line is not
            // written. The publish this tee rides on has already happened.
            return null;
        }

        $dir = self::dir();
        self::ensureDir($dir);
        self::ensureBoot($dir);

        $seq = self::allocateSeq($dir);
        if ($seq === null) {
            return null;
        }

        $resolvedActor = $actor ?? EventActor::current();
        $line = self::buildLine($seq, NchanService::nowMs(), $kind, $resolvedActor, $subject, $safeSummary, $safeData);
        self::writeLine($dir, $seq, $line);
        return $seq;
    }

    /**
     * Events with seq > $sinceSeq, oldest first, at most $limit (max 500).
     * $filter(array $event): bool keeps an event when it returns true.
     *
     * @return array{events:array,next_seq:int,truncated:bool,gap:bool,oldest_seq:int,head_seq:int,boot_id:string}
     */
    public static function read(int $sinceSeq, int $limit = 100, ?callable $filter = null): array {
        $limit = max(1, min(500, $limit));
        $dir = self::dir();
        $head = self::headSeq();
        $oldest = self::oldestSeq();
        $gap = ($head > 0) && (($sinceSeq + 1) < $oldest);

        $matches = [];
        $truncated = false;
        foreach (self::chunkStarts($dir) as $chunkStart) {
            // A chunk holds seqs [chunkStart, chunkStart + CHUNK_SIZE - 1].
            // Skip it entirely when every seq it could hold is <= sinceSeq.
            if ($chunkStart + self::CHUNK_SIZE - 1 <= $sinceSeq) {
                continue;
            }
            $lines = @file($dir . '/' . $chunkStart . '.jsonl', FILE_IGNORE_NEW_LINES) ?: [];
            foreach ($lines as $line) {
                if ($line === '') continue;
                $event = json_decode($line, true);
                if (!is_array($event) || !isset($event['seq'])) continue; // malformed line — skipped
                $seq = (int)$event['seq'];
                if ($seq <= $sinceSeq) continue;
                if ($filter !== null && !$filter($event)) continue;
                $matches[] = $event;
                if (count($matches) > $limit) {
                    $truncated = true;
                    break 2;
                }
            }
        }
        if ($truncated) {
            $matches = array_slice($matches, 0, $limit);
        }
        $nextSeq = $matches === [] ? $sinceSeq : (int)$matches[count($matches) - 1]['seq'];

        return [
            'events'     => $matches,
            'next_seq'   => $nextSeq,
            'truncated'  => $truncated,
            'gap'        => $gap,
            'oldest_seq' => $oldest,
            'head_seq'   => $head,
            'boot_id'    => self::bootId(),
        ];
    }

    /**
     * Channel suffix + payload => kind, or null when the channel has no
     * mapping (a publish on such a channel is not teed to the ledger).
     */
    public static function kindForChannel(string $channel, array $payload): ?string {
        if ($channel === 'activity') {
            if (($payload['dismissed'] ?? false) === true) {
                return 'activity.dismissed';
            }
            switch ((string)($payload['status'] ?? '')) {
                case 'pending_approval': return 'activity.proposed';
                case 'approved':         return 'activity.approved';
                case 'rejected':         return 'activity.rejected';
                case 'failed':
                case 'stalled':          return 'activity.failed';
                case 'done':             return 'activity.finished';
                default:                 return 'activity.updated';
            }
        }
        if ($channel === 'workspaces') {
            $event = (string)($payload['event'] ?? '');
            return 'workspace.' . ($event !== '' ? $event : 'updated');
        }
        if ($channel === 'storage_status') {
            return array_key_exists('maintenance', $payload) ? 'storage.maintenance' : 'storage.status';
        }
        if ($channel === 'deploy') {
            return 'deploy.activated';
        }
        if ($channel === 'migrate_progress') {
            return 'storage.migrate';
        }
        if (strncmp($channel, 'install_', 8) === 0) {
            return !empty($payload['completed']) ? 'install.complete' : 'install.progress';
        }
        if ($channel === 'voice') {
            // VOICE_SWITCHES.md R8: a `state` message announces the global
            // voice_enabled switch moving — nobody spoke, so it must never
            // count as `agent.spoke`. Not teed at all: it carries no
            // workspaceId/agentId worth a ledger row, and every open tab
            // already gets it straight off the channel.
            if ((string)($payload['mode'] ?? '') === 'state') {
                return null;
            }
            // AGENT_VOICE.md R9: every remaining publish on 'voice' (browser
            // speech or an engine clip) is one spoken utterance — always the
            // same kind. The published payload already carries
            // `excerpt`/`engine` alongside the wire fields a browser reads,
            // so this tee needs no special data shaping — see
            // VoiceService::speak()'s own doc comment for why the excerpt
            // (not the raw text) is what summaryFor() below surfaces.
            return 'agent.spoke';
        }
        if ($channel === 'voicemail') {
            // docs/specs/VOICE_MAIL.md: `kept` = a message was filed (in Voice mail
            // mode this is the ONLY row an utterance leaves, because nothing was
            // spoken aloud to tee as agent.spoke); `heard` = the operator played it
            // from voice mail or marked it heard (auto-play never does). An empty or garbled payload — the guard test probes
            // every channel with [] — still maps to a real kind.
            $reason = (string)($payload['reason'] ?? '');
            if ($reason === 'kept') return 'voicemail.kept';
            if ($reason === 'heard') return 'voicemail.heard';
            if ($reason === 'deleted') return 'voicemail.deleted';
            return 'voicemail.updated';
        }
        if ($channel === 'favourites') {
            // REVIEW_2026-09-13_EVENTS_AND_SECURITY.md E1: the payload IS the
            // kind — FavouritesHandler publishes {kind, id}, one of
            // favourite.added|updated|removed|opened. An empty/garbled payload
            // (the CHANNELS-vs-kind guard test probes every channel with []) still
            // maps to a real kind rather than dropping the tee.
            $kind = (string)($payload['kind'] ?? '');
            $known = ['favourite.added', 'favourite.updated', 'favourite.removed', 'favourite.opened'];
            return in_array($kind, $known, true) ? $kind : 'favourite.updated';
        }
        return null;
    }

    /** Glob match for subscriptions: 'workspace.*', '*', or an exact kind. */
    public static function kindMatches(string $pattern, string $kind): bool {
        if ($pattern === '*' || $pattern === $kind) {
            return true;
        }
        if (strpos($pattern, '*') === false) {
            return false;
        }
        $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/';
        return (bool)preg_match($regex, $kind);
    }

    /**
     * Generic subject extraction for the NchanService tee: keep only the
     * identifying keys a subscriber would filter on.
     */
    public static function subjectFromPayload(array $payload): array {
        $subject = [];
        foreach (['workspaceId', 'id', 'agentId', 'name', 'opId'] as $key) {
            if (!array_key_exists($key, $payload)) continue;
            $value = $payload[$key];
            if (!is_scalar($value)) continue;
            // The payload's own `id` becomes `workspaceId` when there is no
            // separate workspaceId already — the workspace/activity payloads
            // this tee serves use a bare `id` for the entity being described.
            if ($key === 'id') {
                if (!isset($subject['workspaceId'])) $subject['workspaceId'] = $value;
                continue;
            }
            $subject[$key] = $value;
        }
        return $subject;
    }

    /** A short, generic summary line for the tee. Never throws. */
    public static function summaryFor(string $kind, array $payload): string {
        $label = (string)($payload['name'] ?? $payload['id'] ?? $payload['workspaceId'] ?? $payload['opId'] ?? '');
        try {
            switch (true) {
                // WORKSPACE_SEND_INPUT.md: checked BEFORE the generic 'workspace.'
                // prefix case below (switch(true) takes the first match), since
                // this kind renders as a sentence, not the generic "workspace
                // <verb>: <label>" shape.
                case $kind === 'workspace.input':
                    $agentId = (string)($payload['agentId'] ?? 'an agent');
                    $where = (string)($payload['name'] ?? $payload['workspaceId'] ?? 'a workspace');
                    $chars = (int)($payload['chars'] ?? 0);
                    return trim("$agentId typed $chars chars into $where");
                case strncmp($kind, 'workspace.', 10) === 0:
                    return trim('workspace ' . substr($kind, 10) . ($label !== '' ? ": $label" : ''));
                case strncmp($kind, 'activity.', 9) === 0:
                    return trim('activity ' . substr($kind, 9) . ($label !== '' ? ": $label" : ''));
                case strncmp($kind, 'favourite.', 10) === 0:
                    return trim('favourite ' . substr($kind, 10) . ($label !== '' ? ": $label" : ''));
                case strncmp($kind, 'install.', 8) === 0:
                    $agentId = (string)($payload['agentId'] ?? $label);
                    $progress = (string)($payload['progress'] ?? '');
                    return trim("install $agentId" . ($progress !== '' ? " {$progress}%" : ''));
                case $kind === 'storage.status':
                    return 'storage status published';
                case $kind === 'storage.maintenance':
                    return 'storage maintenance state changed';
                case $kind === 'storage.migrate':
                    return 'storage migration progress';
                case $kind === 'deploy.activated':
                    $generation = (string)($payload['generation'] ?? '');
                    return trim('generation active' . ($generation !== '' ? ": $generation" : ''));
                case $kind === 'agent.spoke':
                    $agentId = (string)($payload['agentId'] ?? 'an agent');
                    $where = (string)($payload['name'] ?? $payload['workspaceId'] ?? '');
                    $excerpt = (string)($payload['excerpt'] ?? '');
                    return trim("$agentId spoke" . ($where !== '' ? " in $where" : '') . ($excerpt !== '' ? ": $excerpt" : ''));
                default:
                    return $kind;
            }
        } catch (\Throwable $e) {
            return $kind;
        }
    }

    // ------------------------------------------------------------------
    // Internal
    // ------------------------------------------------------------------

    private static function ensureDir(string $dir): void {
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
    }

    /**
     * Records the live boot id on first use. If a previously-recorded boot
     * id disagrees with the live one, every chunk and the seq counter are
     * cleared and the ledger starts fresh at seq 1. In production tmpfs is
     * wiped by the reboot itself; this branch exists for a soft-restart test
     * seam and for defence in depth.
     */
    private static function ensureBoot(string $dir): void {
        $bootFile = $dir . '/.boot';
        $live = self::bootId();
        $recorded = is_file($bootFile) ? trim((string)@file_get_contents($bootFile)) : '';
        if ($recorded !== '' && $recorded !== $live) {
            foreach (glob($dir . '/*.jsonl') ?: [] as $chunk) {
                @unlink($chunk);
            }
            @unlink($dir . '/.seq');
        }
        @file_put_contents($bootFile, $live);
    }

    /**
     * Increment and persist the seq counter under a non-blocking flock, with
     * a short bounded retry (tmpfs, never a blocking flock over FUSE).
     * Returns null when the lock could not be acquired in time.
     */
    private static function allocateSeq(string $dir): ?int {
        $path = $dir . '/.seq';
        $fh = @fopen($path, 'c+');
        if ($fh === false) {
            return null;
        }
        try {
            $tries = 0;
            while (!@flock($fh, LOCK_EX | LOCK_NB)) {
                $tries++;
                if ($tries >= self::SEQ_LOCK_RETRIES) {
                    return null;
                }
                usleep(self::SEQ_LOCK_RETRY_DELAY_US);
            }
            $raw = trim((string)stream_get_contents($fh));
            $current = ($raw !== '' && ctype_digit($raw)) ? (int)$raw : 0;
            $next = $current + 1;
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, (string)$next);
            fflush($fh);
            flock($fh, LOCK_UN);
            return $next;
        } finally {
            fclose($fh);
        }
    }

    /**
     * Pass `summary` and `data` through RedactionService recursively (every
     * string leaf, not the JSON blob as a whole — so a redaction match can
     * never corrupt the envelope's structure). Scalars other than strings
     * pass through unchanged.
     *
     * @return array{0:string,1:array}
     */
    private static function redactPayload(string $summary, array $data): array {
        $known = RedactionService::loadKnownSecrets();
        $safeSummary = RedactionService::redact($summary, $known);
        $safeData = self::redactValue($data, $known);
        return [$safeSummary, is_array($safeData) ? $safeData : []];
    }

    /** @param mixed $value */
    private static function redactValue($value, array $known) {
        if (is_string($value)) {
            return RedactionService::redact($value, $known);
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = self::redactValue($v, $known);
            }
            return $out;
        }
        return $value;
    }

    private static function buildLine(int $seq, int $ts, string $kind, array $actor, array $subject, string $summary, array $data): string {
        $summary = self::truncateSummary($summary);
        $envelope = [
            'seq'     => $seq,
            'ts'      => $ts,
            'kind'    => $kind,
            'actor'   => $actor,
            'subject' => $subject,
            'summary' => $summary,
            'data'    => $data,
        ];
        $line = self::encode($envelope);
        if ($line === null || strlen($line) > self::MAX_LINE_BYTES) {
            $envelope['data'] = ['truncated' => true];
            $line = self::encode($envelope);
        }
        if ($line === null || strlen($line) > self::MAX_LINE_BYTES) {
            // Last resort: the summary itself is unusually large (e.g. a
            // long multi-byte string) — cut it hard and try once more.
            $envelope['summary'] = substr($summary, 0, 120);
            $line = self::encode($envelope);
        }
        return $line ?? '{}';
    }

    private static function encode(array $envelope): ?string {
        $json = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $json === false ? null : $json;
    }

    private static function truncateSummary(string $summary): string {
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($summary) > self::MAX_SUMMARY_CHARS
                ? mb_substr($summary, 0, self::MAX_SUMMARY_CHARS)
                : $summary;
        }
        return strlen($summary) > self::MAX_SUMMARY_CHARS ? substr($summary, 0, self::MAX_SUMMARY_CHARS) : $summary;
    }

    private static function chunkPathFor(string $dir, int $seq): string {
        return $dir . '/' . self::chunkStartFor($seq) . '.jsonl';
    }

    private static function chunkStartFor(int $seq): int {
        return intdiv($seq - 1, self::CHUNK_SIZE) * self::CHUNK_SIZE + 1;
    }

    /** Tolerates the chunk file vanishing underneath it (rotation raced by the sweep). */
    private static function writeLine(string $dir, int $seq, string $line): void {
        $path = self::chunkPathFor($dir, $seq);
        @file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    /** Ascending list of chunk-start seqs (each chunk's filename, sorted numerically). */
    private static function chunkStarts(?string $dir = null): array {
        $dir = $dir ?? self::dir();
        $starts = [];
        foreach (glob($dir . '/*.jsonl') ?: [] as $file) {
            $base = basename($file, '.jsonl');
            if (ctype_digit($base)) {
                $starts[] = (int)$base;
            }
        }
        sort($starts);
        return $starts;
    }
}
