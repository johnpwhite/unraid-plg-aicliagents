<?php
/**
 * <module_context>
 *     <name>PerfTrace</name>
 *     <description>One-line timing traces from PHP into /tmp/unraid-aicliagents/perf.log, the same file the shell and the browser write. A trace costs one microtime() call when the step is fast. A step that is slower than its threshold writes one perf line and one WARN line to debug.log. The traces are OFF by default: they write only while the setting perf_trace is 1 (Debug Console tab, "Performance trace").</description>
 *     <dependencies>LogService, TraceContext</dependencies>
 *     <constraints>Never throws. Never blocks: one append, no lock. Stage names are a fixed pattern and details are stripped of control characters, so a hostile value cannot forge a log line. Under test (AICLI_TEST_TMP defined) it writes nothing unless a sink is set. The file is rotated at 1 MB to perf.log.1. While the setting is off, record() returns at once and writes nothing. The setting is read from the plugin .cfg once per request, and only when a step is slow. Spec: docs/specs/NOTEBOOK_HANG_OBSERVABILITY.md (R12).</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

require_once __DIR__ . '/LogService.php';
require_once __DIR__ . '/TraceContext.php';

class PerfTrace {
    /** The trace file: /tmp/unraid-aicliagents/perf.log in production (the sandbox state root under test). */
    public static function file(): string { return SecretPaths::stateRoot() . '/perf.log'; }
    /** Rotate perf.log to perf.log.1 above this size (tmpfs: bounded RAM). */
    public const MAX_BYTES = 1048576;
    public const STAGE_PATTERN = '/^[a-z0-9][a-z0-9._-]{0,47}$/D';
    public const DETAIL_MAX = 200;

    /** @var callable|null  Test hook: receives the finished line (no newline). */
    private static $sink = null;

    /** Test hook: true or false forces the switch; null goes back to the setting. */
    private static ?bool $override = null;
    /** The setting, read once per request. */
    private static ?bool $cfgOn = null;

    public static function setSink(?callable $sink): void {
        self::$sink = $sink;
    }

    public static function setEnabled(?bool $on): void {
        self::$override = $on;
        self::$cfgOn = null;
    }

    /**
     * True while the plugin setting perf_trace is "1". Off by default.
     * A test sink counts as on, unless a test turned the switch off with setEnabled(false).
     * Under test (AICLI_TEST_TMP) the real .cfg is never read.
     */
    public static function enabled(): bool {
        if (self::$override !== null) return self::$override;
        if (self::$sink !== null) return true;
        if (defined('AICLI_TEST_TMP')) return false;
        if (self::$cfgOn === null) {
            $on = false;
            try {
                $file = SecretPaths::flashRoot() . '/unraid-aicliagents.cfg';
                $cfg = is_file($file) ? @parse_ini_file($file) : false;
                $on = is_array($cfg) && (string)($cfg['perf_trace'] ?? '0') === '1';
            } catch (\Throwable $e) {
                $on = false;
            }
            self::$cfgOn = $on;
        }
        return self::$cfgOn;
    }

    /** Start a timer. Pass the result to elapsedMs() or point(). */
    public static function start(): float {
        return microtime(true);
    }

    public static function elapsedMs(float $t0): int {
        return (int)round((microtime(true) - $t0) * 1000);
    }

    /**
     * Trace one step. Writes only when the step took $thresholdMs or longer.
     * Returns the elapsed milliseconds, so the caller can reuse the number.
     */
    public static function point(string $stage, float $t0, int $thresholdMs, string $detail = '', string $context = 'PerfTrace'): int {
        $ms = self::elapsedMs($t0);
        if ($ms >= $thresholdMs) self::record($stage, $ms, $detail, $context);
        return $ms;
    }

    /** Write one trace line now, and a WARN line to debug.log. Never throws. */
    public static function record(string $stage, int $ms, string $detail = '', string $context = 'PerfTrace'): void {
        try {
            if (!self::enabled()) return;
            if (!preg_match(self::STAGE_PATTERN, $stage)) return;
            $detail = self::cleanDetail($detail);
            $trace = TraceContext::getId() ?? '-';
            $line = (int)floor(microtime(true) * 1000) . " $stage php $trace ms=$ms" . ($detail !== '' ? " $detail" : '');
            self::write($line);
            LogService::log("slow: $stage took {$ms} ms" . ($detail !== '' ? " ($detail)" : ''), LogService::LOG_WARN, $context);
        } catch (\Throwable $e) {
            // A trace must never break the request it measures.
        }
    }

    /** Strip control characters and newlines, collapse spaces, cap the length. */
    public static function cleanDetail(string $detail): string {
        $detail = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $detail) ?? '';
        $detail = trim(preg_replace('/\s+/', ' ', $detail) ?? '');
        return strlen($detail) > self::DETAIL_MAX ? substr($detail, 0, self::DETAIL_MAX) : $detail;
    }

    private static function write(string $line): void {
        if (self::$sink !== null) { (self::$sink)($line); return; }
        if (defined('AICLI_TEST_TMP')) return;
        $file = self::file();
        $dir = dirname($file);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $size = @filesize($file);
        if ($size !== false && $size > self::MAX_BYTES) @rename($file, $file . '.1');
        @file_put_contents($file, $line . "\n", FILE_APPEND);
    }
}
