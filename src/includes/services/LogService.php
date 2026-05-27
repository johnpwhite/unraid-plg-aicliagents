<?php
/**
 * <module_context>
 *     <name>LogService</name>
 *     <description>Centralized plugin logging for AICliAgents.</description>
 *     <dependencies>None</dependencies>
 *     <constraints>Under 100 lines. Focuses on syslog and debug log.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class LogService {
    const LOG_ERROR = 0;
    const LOG_WARN  = 1;
    const LOG_INFO  = 2;
    const LOG_DEBUG = 3;

    private static $currentLevel = null;

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
        $logDir = "/tmp/unraid-aicliagents";
        if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
        $logFile = "$logDir/debug.log";

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            $formatted = "[$levelStr] $contextTag $line";
            
            // 1. Syslog (Only for non-debug lines to avoid spam)
            if ($level <= self::LOG_INFO) {
                @syslog($syslogLevel, "$contextTag $line");
            }

            // 2. Persistent Debug Log — Format: [timestamp] [LEVL] [Context] message
            $entry = "[$timestamp] $formatted" . PHP_EOL;
            @file_put_contents($logFile, $entry, FILE_APPEND);
        }
    }

    /**
     * Directly parses global config to avoid circular service dependencies.
     */
    private static function getStoredLogLevel() {
        $configFile = "/boot/config/plugins/unraid-aicliagents/unraid-aicliagents.cfg";
        if (file_exists($configFile)) {
            $config = @parse_ini_file($configFile);
            if (isset($config['log_level'])) {
                return (int)$config['log_level'];
            }
        }
        return self::LOG_INFO; // Default
    }

    /**
     * Returns a formatted timestamp according to plugin standards.
     */
    public static function getFormattedTimestamp($includeDate = true) {
        $format = $includeDate ? 'Y-m-d H:i:s' : 'H:i:s';
        return date($format);
    }
}
