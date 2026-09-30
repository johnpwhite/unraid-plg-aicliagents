<?php
/**
 * <module_context>
 *     <name>NchanService</name>
 *     <description>Publishes real-time status updates via Unraid's Nchan infrastructure.</description>
 *     <dependencies>Unraid's Nchan nginx module (built-in); AtomicWriteService (failure counter file);
 *     EventLedger (the ledger tee — docs/specs/PLUGIN_EVENT_LEDGER_AND_SUBSCRIPTIONS.md).</dependencies>
 *     <constraints>Fire-and-forget publishing — never blocks, never throws. Every payload is
 *     stamped with a server-epoch-ms `ts` (docs/specs/EVENT_PUBLISH_OBSERVABILITY.md R1). A
 *     publish failure is counted and rate-limit-logged, never silent (R2). Buffer depth comes
 *     from the per-channel CHANNELS registry unless the caller passes one explicitly (R4). A
 *     channel not in the registry still publishes (depth 1) but counts as a failure, so a typo
 *     shows up in HealthService rather than staying invisible. Every publish also appends one
 *     ledger event when EventLedger::kindForChannel() maps the channel, whether or not the push
 *     itself succeeded — the ledger append is its own try/catch and can never break a publish.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

require_once __DIR__ . '/EventLedger.php';
// EventBus.php is required BEFORE the class body below because CHANNELS is
// declared FROM its constant — a class-constant expression is resolved when
// this class is compiled, so EventBus must already be declared by then.
// EventBus.php itself requires this file back, AFTER its own class is
// declared, so neither file ever needs the other's class before it exists —
// see the require_once at the foot of EventBus.php.
require_once __DIR__ . '/EventBus.php';

class NchanService {

    /** Where publish() writes the failure counter (HealthService reads the same path). */
    public const STATUS_PATH = '/tmp/unraid-aicliagents/push.status.json';

    /**
     * Per-channel Nchan buffer depth (docs/specs/EVENT_PUBLISH_OBSERVABILITY.md R4).
     * Event-log channels replay several recent entries so a reconnect after a short
     * gap does not need a full reconcile. Snapshot channels only ever need the
     * newest message. A key ending in `_` matches any channel with that prefix
     * (e.g. `install_` matches `install_claude-code`, one channel per agent).
     *
     * docs/specs/EVENT_STREAM_MULTIPLEX.md R4: this is now an ALIAS of
     * EventBus::CHANNEL_DEPTHS — EventBus's registry is the one list; this
     * constant exists only because NchanService is the public, long-lived
     * name every caller and test already uses. EventBusTest pins that the
     * two agree.
     */
    public const CHANNELS = EventBus::CHANNEL_DEPTHS;

    /** Test seam: (string $url, string $body): array{errno:int,error:string,status:int}. Real curl when null. */
    public static $transport = null;

    /** Test seam: overrides STATUS_PATH. Null uses the real path. */
    public static ?string $statusPath = null;

    /** Test seam: overrides the Nchan unix socket path. Null uses the real socket. */
    public static ?string $socketPath = null;

    /** Current server time in epoch milliseconds — the one clock every `ts` field uses. */
    public static function nowMs(): int {
        return (int) round(microtime(true) * 1000);
    }

    /** Resolved failure-counter path (STATUS_PATH unless a test set $statusPath). */
    public static function statusPath(): string {
        return self::$statusPath ?? self::STATUS_PATH;
    }

    /** Resolved Nchan unix socket path (the real socket unless a test set $socketPath). */
    public static function socketPath(): string {
        return self::$socketPath ?? '/var/run/nginx.socket';
    }

    /**
     * Publish a message to an Nchan channel via localhost HTTP POST.
     * Uses curl directly to the nginx /pub/ endpoint (avoids publish.php include path issues).
     * Never throws and never blocks the caller for more than the curl timeout below.
     *
     * @param string $channel Channel suffix (e.g., 'install_gemini-cli', 'activity')
     * @param array $data Associative array to JSON-encode and publish. `ts` is
     *     always overwritten with the current server time in epoch ms — a caller
     *     must not rely on its own `ts` value surviving.
     * @param int|null $buffer Buffer depth override. Null looks up CHANNELS.
     */
    public static function publish(string $channel, array $data, ?int $buffer = null): void {
        try {
            $depth = $buffer ?? self::bufferDepth($channel);
            $data['ts'] = self::nowMs();
            $endpoint = "aicli_$channel";
            $message = (string) json_encode($data);
            // Unraid's Nchan publisher lives on the internal Unix socket server,
            // not the main HTTP server. Must publish via the socket.
            $url = "http://localhost/pub/$endpoint?buffer_length=$depth";

            if (!file_exists(self::socketPath())) {
                self::recordFailure($channel, 'socket_absent');
                self::tee($channel, $data);
                return;
            }

            $result = (self::$transport !== null)
                ? (self::$transport)($url, $message)
                : self::curlTransport($url, $message);

            $errno  = (int) ($result['errno'] ?? 0);
            $status = (int) ($result['status'] ?? 0);
            if ($errno !== 0) {
                self::recordFailure($channel, 'curl_errno_' . $errno);
            } elseif ($status < 200 || $status > 299) {
                self::recordFailure($channel, 'http_' . $status);
            }
            // PLUGIN_EVENT_LEDGER_AND_SUBSCRIPTIONS.md R1: every publish also
            // appends one ledger event, whether or not the push itself
            // succeeded — the ledger is the record, the push is best effort.
            self::tee($channel, $data);
        } catch (\Throwable $e) {
            // Fire and forget — never let Nchan failures break the caller.
            try { self::recordFailure($channel, 'exception'); } catch (\Throwable $ignored) { /* swallow */ }
            try { self::tee($channel, $data); } catch (\Throwable $ignored) { /* swallow */ }
        }
    }

    /**
     * Append one ledger event for a publish, when the channel maps to a
     * known kind. Never throws: a broken ledger must not break a publish.
     * $data already carries the `ts` this publish stamped.
     */
    private static function tee(string $channel, array $data): void {
        try {
            $kind = EventLedger::kindForChannel($channel, $data);
            if ($kind === null) {
                return;
            }
            EventLedger::append(
                $kind,
                EventLedger::subjectFromPayload($data),
                EventLedger::summaryFor($kind, $data),
                $data
            );
        } catch (\Throwable $e) {
            // Swallow — the ledger is best-effort diagnostics, not load-bearing.
        }
    }

    /** Real transport: POST over the nginx unix socket. Swappable via self::$transport for tests. */
    private static function curlTransport(string $url, string $body): array {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_UNIX_SOCKET_PATH, self::socketPath());
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
        curl_exec($ch);
        $errno  = curl_errno($ch);
        $error  = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['errno' => $errno, 'error' => $error, 'status' => $status];
    }

    /** Buffer depth for a channel: exact match, then prefix match, then 1 + a counted failure. */
    private static function bufferDepth(string $channel): int {
        if (array_key_exists($channel, self::CHANNELS)) {
            return self::CHANNELS[$channel];
        }
        foreach (self::CHANNELS as $key => $depth) {
            if (substr($key, -1) === '_' && strncmp($channel, $key, strlen($key)) === 0) {
                return $depth;
            }
        }
        self::recordFailure($channel, 'unlisted_channel');
        return 1;
    }

    /**
     * Count one publish failure and log one rate-limited WARN line. Never throws:
     * a broken counter must not take down the caller that is already failing.
     */
    private static function recordFailure(string $channel, string $reason): void {
        try {
            $path = self::statusPath();
            $now = time();
            // A missing socket fails every publish at once. Count it once per
            // minute (spec R2) so the hourly counter reflects the outage, not
            // the publish rate during it. The WARN marker below is the clock.
            if ($reason === 'socket_absent') {
                $last = @filemtime(dirname($path) . "/push.warn.$reason");
                if ($last !== false && ($now - $last) < 60) {
                    return;
                }
            }
            $state = self::readStatus($path);
            $history = array_values(array_filter(
                array_merge($state['history'] ?? [], [$now]),
                static fn($t) => is_int($t) && $t >= $now - 3600
            ));
            // Cap the ring so a runaway failure loop cannot grow the file without bound.
            if (count($history) > 200) {
                $history = array_slice($history, -200);
            }
            $state['history']      = $history;
            $state['failures_1h']  = count($history);
            $state['last_error']   = "$channel: $reason";
            $state['last_at']      = $now;
            $state['total']        = (int) ($state['total'] ?? 0) + 1;
            AtomicWriteService::writeJson($path, $state);
            self::maybeWarn($channel, $reason);
        } catch (\Throwable $e) {
            // Swallow — the counter is best-effort diagnostics, not load-bearing.
        }
    }

    /** @return array<string,mixed> */
    private static function readStatus(string $path): array {
        if (!is_file($path)) return [];
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') return [];
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** One WARN line per distinct reason per minute, rate-limited by an mtime marker file. */
    private static function maybeWarn(string $channel, string $reason): void {
        $warnPath = dirname(self::statusPath()) . "/push.warn.$reason";
        $now = time();
        $last = @filemtime($warnPath);
        if ($last !== false && ($now - $last) < 60) {
            return;
        }
        @touch($warnPath);
        aicli_log("Nchan publish to channel '$channel' failed. Reason: $reason.", AICLI_LOG_WARN, 'NchanService');
    }

    // publishInstallProgress() moved to EventBus::publishInstallProgress()
    // (docs/specs/EVENT_STREAM_MULTIPLEX.md R4) — it now resolves the
    // install_<agentId> channel through the ONE registry instead of building
    // the channel string inline. NchanService::publish() remains the single
    // transport every publish (including install progress) still goes through.
}
