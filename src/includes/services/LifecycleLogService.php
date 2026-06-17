<?php
/**
 * <module_context>
 *     <name>LifecycleLogService</name>
 *     <description>Persistent, durable lifecycle log for AICliAgents storage events. Survives reboots.</description>
 *     <dependencies>StoragePathResolver, TraceContext</dependencies>
 *     <constraints>Must NOT depend on LayerManifestService. Never calls error_log or Unraid notify on failure — silent false return only. TraceContext is an allowed exception to the no-deps rule: it is tiny, static and dependency-free (R-06 trace correlation) — it must never grow dependencies of its own.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

require_once __DIR__ . '/TraceContext.php';

class LifecycleLogService {
    public const LEVEL_INFO     = 'info';
    public const LEVEL_WARN     = 'warn';
    public const LEVEL_ERROR    = 'error';
    public const LEVEL_CRITICAL = 'critical';

    /** Default rotation threshold: 1 MB */
    private const DEFAULT_MAX_BYTES = 1048576;

    /** Number of rotated generations to keep */
    private const ROTATE_GENERATIONS = 3;

    /**
     * Appends a structured line to the lifecycle log.
     *
     * Line format: <iso8601_ts> | <level> | <component> | <event> | <json_payload>
     *
     * Returns true on success, false on any write failure (silent — see constraints).
     */
    public static function log(
        string $level,
        string $component,
        string $event,
        array $payload = []
    ): bool {
        $path = StoragePathResolver::lifecycleLogPath();
        $dir  = dirname($path);

        // Ensure directory exists (flash path, low-frequency)
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        // Auto-rotate before writing if over threshold
        self::rotateIfNeeded();

        // R-06: merge the per-request trace id into the payload (via TraceContext
        // directly, NOT via LogService — preserves the no-LogService constraint).
        if (TraceContext::getId() !== null && !isset($payload['_trace'])) {
            $payload['_trace'] = TraceContext::getId();
        }

        $ts      = date('Y-m-d\TH:i:s\Z', time());
        $payJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payJson === false) {
            $payJson = '{}';
        }

        $line = implode(' | ', [$ts, $level, $component, $event, $payJson]) . "\n";

        $fd = @fopen($path, 'a');
        if ($fd === false) {
            return false;
        }

        $locked = @flock($fd, LOCK_EX);
        if (!$locked) {
            @fclose($fd);
            return false;
        }

        $written = @fwrite($fd, $line);
        @fflush($fd);
        @flock($fd, LOCK_UN);
        @fclose($fd);

        return ($written !== false && $written > 0);
    }

    /**
     * Rotates the log file if it exceeds the configured threshold.
     * Shift .1 → .2 → .3 (drop .3), rename current → .1.
     * Returns true if rotation was performed or not needed, false on error.
     */
    public static function rotateIfNeeded(): bool {
        $path     = StoragePathResolver::lifecycleLogPath();
        $maxBytes = self::DEFAULT_MAX_BYTES;

        if (!file_exists($path)) {
            return true;
        }

        $size = @filesize($path);
        if ($size === false || $size < $maxBytes) {
            return true;
        }

        // Shift generations: drop .3, move .2→.3, .1→.2, current→.1
        for ($gen = self::ROTATE_GENERATIONS; $gen >= 1; $gen--) {
            $src = $path . '.' . $gen;
            $dst = $path . '.' . ($gen + 1);
            if (file_exists($src)) {
                if ($gen === self::ROTATE_GENERATIONS) {
                    @unlink($src);
                } else {
                    @rename($src, $dst);
                }
            }
        }

        return (bool)@rename($path, $path . '.1');
    }

    /**
     * Returns the most recent N log entries, parsed into associative arrays.
     * Tolerates malformed lines (skips them with a warning to stderr).
     *
     * @param int $lines Number of tail lines to return.
     * @return array<int, array<string, mixed>>
     */
    public static function tail(int $lines = 100): array {
        $path = StoragePathResolver::lifecycleLogPath();
        if (!file_exists($path) || !is_readable($path)) {
            return [];
        }

        // Read the entire file and take last N lines
        // For a 1 MB log this is cheap; file is rotated before it grows larger.
        $content = @file_get_contents($path);
        if ($content === false || $content === '') {
            return [];
        }

        $rawLines = explode("\n", rtrim($content));
        $rawLines = array_slice($rawLines, -$lines);

        $result = [];
        foreach ($rawLines as $raw) {
            $raw = trim($raw);
            if ($raw === '') {
                continue;
            }
            $parsed = self::parseLine($raw);
            if ($parsed !== null) {
                $result[] = $parsed;
            }
        }

        return $result;
    }

    /**
     * Parses a single log line into an associative array.
     * Returns null (and emits a stderr warning) on malformed input.
     *
     * @return array<string, mixed>|null
     */
    private static function parseLine(string $line): ?array {
        $parts = explode(' | ', $line, 5);
        if (count($parts) !== 5) {
            fwrite(STDERR, "LifecycleLogService: malformed log line skipped: " . substr($line, 0, 120) . "\n");
            return null;
        }

        [$ts, $level, $component, $event, $payJson] = $parts;

        $payload = json_decode($payJson, true);
        if (!is_array($payload)) {
            $payload = ['_raw' => $payJson];
        }

        return [
            'ts'        => $ts,
            'level'     => $level,
            'component' => $component,
            'event'     => $event,
            'payload'   => $payload,
        ];
    }
}
