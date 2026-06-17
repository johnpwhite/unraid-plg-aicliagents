<?php
/**
 * <module_context>
 *     <name>LogService</name>
 *     <description>Centralized plugin logging for AICliAgents. Bounded debug.log (R-05 rotation), trace-tagged lines (R-06), optional JSONL format (R-07).</description>
 *     <dependencies>TraceContext</dependencies>
 *     <constraints>Focuses on syslog and debug log. TraceContext is the only allowed service dependency (tiny, dependency-free). Test hooks: AICLI_DEBUG_LOG_DIR / AICLI_DEBUG_LOG_MAX_BYTES / AICLI_DEBUG_LOG_FORMAT env overrides (mirror the AICLI_MANIFEST_PATH precedent).</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

require_once __DIR__ . '/TraceContext.php';

class LogService {
    const LOG_ERROR = 0;
    const LOG_WARN  = 1;
    const LOG_INFO  = 2;
    const LOG_DEBUG = 3;

    /** R-05: default debug.log rotation threshold (5 MB, tmpfs — RAM pressure bound). */
    const DEFAULT_MAX_BYTES = 5242880;

    private static $currentLevel = null;
    private static $logFormat = null;
    private static $maxBytes = null;
    /** R-05: rotation-check cooldown (epoch seconds of last filesize() probe). */
    private static $lastRotateCheck = 0;

    /**
     * Central logging function.
     * @param string $message The log message.
     * @param int $level The log level.
     * @param string $context The component context (e.g., [TaskService]).
     */
    public static function log($message, $level = self::LOG_INFO, $context = "AICliAgents") {
        // 1. Determine current threshold
        if (self::$currentLevel === null) {
            self::$currentLevel = self::getStoredLogLevel();
        }

        // 2. Filter by level threshold
        if ($level > self::$currentLevel) {
            return;
        }

        $contextTag = "[$context]";
        $levelStr = "INFO";
        $syslogLevel = LOG_INFO;

        switch ($level) {
            case self::LOG_ERROR:
                $levelStr = "ERR!";
                break;
            case self::LOG_WARN:
                $levelStr = "WARN";
                break;
            case self::LOG_DEBUG:
                $levelStr = "DBUG";
                break;
        }

        // D-290: Standardize multiline logging
        $message = str_replace("\r", "\n", $message);
        // Strip ANSI color codes and control characters
        $message = preg_replace('/\x1b[[0-9;]*[mG]/', '', $message);
        $message = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $message);

        $lines = explode("\n", $message);
        $timestamp = date("Y-m-d H:i:s");
        $logDir = getenv('AICLI_DEBUG_LOG_DIR') ?: "/tmp/unraid-aicliagents";
        if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
        $logFile = "$logDir/debug.log";

        // R-05: rotate before append — bound debug.log to debug_log_max_bytes
        // (default 5 MB, 1 kept generation). Mirrors LifecycleLogService::
        // rotateIfNeeded()'s shift pattern, but keeps a single .1 generation:
        // /tmp is RAM-backed tmpfs, so this is an OOM-pressure bound, not a
        // durability store. filesize() is probed at most every 30 s per process.
        $now = time();
        // The AICLI_DEBUG_LOG_MAX_BYTES env hook (tests) bypasses the cooldown so
        // rotation is deterministic within a single test process.
        if ($now - self::$lastRotateCheck > 30 || getenv('AICLI_DEBUG_LOG_MAX_BYTES') !== false) {
            self::$lastRotateCheck = $now;
            if (file_exists($logFile) && @filesize($logFile) >= self::getMaxBytes()) {
                @rename($logFile, $logFile . '.1'); // overwrites previous .1 atomically
            }
        }

        // R-06: trace tag appended after the context tag when a request id is set.
        $traceId = TraceContext::getId();
        $traceTag = $traceId !== null ? " [t:$traceId]" : '';

        $jsonl = (self::getLogFormat() === 'jsonl');

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            // 1. Syslog (Only for non-debug lines to avoid spam)
            if ($level <= self::LOG_INFO) {
                @syslog($syslogLevel, "$contextTag$traceTag $line");
            }

            // 2. Persistent Debug Log
            if ($jsonl) {
                // R-07: structured JSONL — {"ts","lvl","ctx","trace","msg"}
                $entry = json_encode([
                    'ts'    => $timestamp,
                    'lvl'   => $levelStr,
                    'ctx'   => $context,
                    'trace' => $traceId,
                    'msg'   => $line,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
            } else {
                // Text format: [timestamp] [LEVL] [Context] [t:id] message
                $entry = "[$timestamp] [$levelStr] $contextTag$traceTag $line" . PHP_EOL;
            }
            @file_put_contents($logFile, $entry, FILE_APPEND);
        }
    }

    /**
     * Directly parses global config to avoid circular service dependencies.
     */
    private static function getStoredLogLevel() {
        $config = self::readRawConfig();
        if (isset($config['log_level'])) {
            return (int)$config['log_level'];
        }
        return self::LOG_INFO; // Default
    }

    /** R-07: debug_log_format config — 'text' (default) or 'jsonl'. Env hook for tests. */
    private static function getLogFormat() {
        $env = getenv('AICLI_DEBUG_LOG_FORMAT');
        if ($env === 'text' || $env === 'jsonl') {
            return $env;
        }
        if (self::$logFormat === null) {
            $config = self::readRawConfig();
            self::$logFormat = (($config['debug_log_format'] ?? 'text') === 'jsonl') ? 'jsonl' : 'text';
        }
        return self::$logFormat;
    }

    /** R-05: debug_log_max_bytes config (default 5 MB). Env hook for tests. */
    private static function getMaxBytes() {
        $env = getenv('AICLI_DEBUG_LOG_MAX_BYTES');
        if ($env !== false && is_numeric($env) && (int)$env > 0) {
            return (int)$env;
        }
        if (self::$maxBytes === null) {
            $config = self::readRawConfig();
            $raw = $config['debug_log_max_bytes'] ?? self::DEFAULT_MAX_BYTES;
            self::$maxBytes = (is_numeric($raw) && (int)$raw > 0) ? (int)$raw : self::DEFAULT_MAX_BYTES;
        }
        return self::$maxBytes;
    }

    /** Raw .cfg read shared by the level/format/max-bytes lookups (no ConfigService — circular). */
    private static function readRawConfig() {
        $configFile = "/boot/config/plugins/unraid-aicliagents/unraid-aicliagents.cfg";
        if (file_exists($configFile)) {
            $config = @parse_ini_file($configFile);
            if (is_array($config)) {
                return $config;
            }
        }
        return [];
    }

    /**
     * Returns a formatted timestamp according to plugin standards.
     */
    public static function getFormattedTimestamp($includeDate = true) {
        $format = $includeDate ? 'Y-m-d H:i:s' : 'H:i:s';
        return date($format);
    }
}
