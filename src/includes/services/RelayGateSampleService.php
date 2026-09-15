<?php
/**
 * <module_context>
 *     <name>RelayGateSampleService</name>
 *     <description>Capture-on-deliver (RELAY_WAITING_PILL.md Part 2, 2026-09-09): when the
 *     operator clicks Deliver on a waiting-message pill AND the setting is on, records the pane
 *     capture the readiness gate had just judged, paired with the verdict it reached, at
 *     ~/.aicli/relay/gate-samples/&lt;agentId&gt;-&lt;timestamp&gt;.json. That pairing is the evidence
 *     needed to build a correct idle profile for an agent nobody has observed — see
 *     TmuxService::paneStateFromCapture(). This class only records; it never judges and never
 *     changes the gate's decision.</description>
 *     <dependencies>ConfigService (setting), RedactionService (scrub before write), AtomicWriteService</dependencies>
 *     <constraints>Off by default. NEVER throws — a sampling fault must never fail or block a
 *     delivery (spec Edge Cases: "the delivery is the point; the sample is a by-product"). A
 *     redaction failure (throw or otherwise) writes NO sample — a missing sample is fine, a
 *     leaked one is not. Escape sequences are KEPT in the capture (colour is what the structural
 *     box detector reads); only the plain-text content is redacted-through, same as every other
 *     RedactionService caller. Bounded: MAX_SAMPLES total (oldest evicted first) and
 *     MAX_CAPTURE_BYTES per sample. Samples live under the plugin's own state dir, never inside a
 *     workspace, and are never transmitted anywhere by this class.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class RelayGateSampleService {

    /** Hard cap on stored samples — oldest evicted first (spec "Privacy"). */
    const MAX_SAMPLES = 50;

    /** Hard cap on one sample's `capture` field, in bytes, after redaction. */
    const MAX_CAPTURE_BYTES = 65536;

    /** Whether the operator has turned sampling on. Off by default. */
    public static function enabled(): bool {
        $config = ConfigService::getConfig();
        return (string)($config['relay_gate_sampling_enabled'] ?? '0') === '1';
    }

    /**
     * Directory holding samples. Mirrors TmuxService::relayPendingDir()'s own
     * base-dir convention (same env override + state root) without taking a
     * dependency on it — TmuxService already depends on neither this class nor
     * ConfigService for that path, and this class should not force a coupling
     * the other direction either.
     */
    private static function dir(): string {
        $env = getenv('AICLI_RELAY_STATE_DIR');
        $base = ($env !== false && $env !== '') ? rtrim($env, '/')
              : ConfigService::getUserStatePath() . '/relay';
        return $base . '/gate-samples';
    }

    /** Monotonic per-process counter so two samples recorded in the same process
     *  (a burst of Deliver clicks, or a test) never race to the same filename. */
    private static int $seq = 0;

    /**
     * Test seam (ProcessManager::$tmuxLivenessProbe precedent): when set, called
     * INSTEAD of RedactionService::redact() so a test can simulate a redaction
     * fault ("if redaction throws or fails, write NO sample") without needing to
     * break RedactionService itself. Never used outside tests.
     * @var (callable(string):string)|null
     */
    public static $redactor = null;

    /** Reset the redactor test seam. Call from a test's tearDown(). */
    public static function resetRedactor(): void {
        self::$redactor = null;
    }

    /**
     * Record one sample. No-op when sampling is off. Never throws: any fault —
     * including one raised by RedactionService::redact() — is swallowed and
     * results in NO sample being written, per this class's own module
     * constraints. Called ONLY from the explicit, operator-initiated Deliver
     * action (ActivityHandler::deliverRelayWaiting()) — never from an
     * automatic background drain, so a sample is always something a human
     * chose to happen.
     *
     * @param array{ready:bool,reason:string} $verdict The gate's own verdict for this capture.
     */
    public static function record(string $agentId, array $verdict, string $capture): void {
        if (!self::enabled()) return;
        try {
            // Fail-closed: if redact() throws (or the vault it reads is unavailable),
            // no sample is written at all — never fall back to writing the raw capture.
            $redactor = is_callable(self::$redactor) ? self::$redactor : [RedactionService::class, 'redact'];
            $redacted = call_user_func($redactor, $capture);
            if (strlen($redacted) > self::MAX_CAPTURE_BYTES) {
                // Keep the TAIL: the structural box detector (boxInputState()) reads
                // the bottom of the pane, and capture-pane already gave us only the
                // last 24 lines, so trimming further should drop the oldest end.
                $redacted = substr($redacted, -self::MAX_CAPTURE_BYTES);
            }

            $agentSafe = preg_replace('/[^A-Za-z0-9_-]/', '', $agentId);
            if ($agentSafe === '' || $agentSafe === null) $agentSafe = 'agent';

            $sample = [
                'agentId' => $agentId,
                'verdict' => [
                    'ready'  => (bool)($verdict['ready'] ?? false),
                    'reason' => (string)($verdict['reason'] ?? ''),
                ],
                'capture'    => $redacted,
                'capturedAt' => gmdate('c'),
            ];

            $dir = self::dir();
            if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) return;

            self::$seq++;
            $file = sprintf(
                '%s/%s-%s-%04d.json',
                $dir,
                $agentSafe,
                gmdate('Ymd\THis\Z'),
                self::$seq % 10000
            );
            if (!AtomicWriteService::writeJson($file, $sample, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) {
                return;
            }
            self::pruneOldest($dir);
        } catch (\Throwable $e) {
            // Best-effort only. A missing sample is fine; a leaked one is not —
            // never let a sampling fault surface, and never write a partial/unredacted file.
        }
    }

    /** Keep only the newest MAX_SAMPLES files, oldest deleted first — same idiom
     *  as DiagnosticsService::pruneOldBundles(), but ordered by filename (which
     *  embeds an always-increasing timestamp+sequence) rather than filemtime, so
     *  eviction order is correct even when several samples land in one second. */
    private static function pruneOldest(string $dir): void {
        $files = glob($dir . '/*.json') ?: [];
        if (count($files) <= self::MAX_SAMPLES) return;
        usort($files, function ($a, $b) { return strcmp(basename($b), basename($a)); });
        foreach (array_slice($files, self::MAX_SAMPLES) as $old) {
            @unlink($old);
        }
    }
}
