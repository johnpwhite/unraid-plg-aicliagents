<?php
/**
 * <module_context>
 *     <name>RelayNoticeLedger</name>
 *     <description>One Relay notice per unread batch. When the plugin types a Relay notice into a
 *     workspace it records the time here. Until the workspace reads its inbox (which calls clear()),
 *     or until the resend time passes, no second notice is typed for the same workspace.
 *     Spec: docs/specs/RELAY_TOKEN_SAVINGS.md (R9-R13, R15).</description>
 *     <dependencies>AgentRelayService::baseDir (relay folder), AtomicWriteService, ConfigService.</dependencies>
 *     <constraints>A bad workspace id is a no-op and never blocks a notice. A forced delivery
 *     does not call shouldSend (that rule lives in TmuxService).</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

final class RelayNoticeLedger
{
    public const DEFAULT_MINUTES = 10;
    public const MIN_MINUTES = 1;
    public const MAX_MINUTES = 240;

    /** Test seam: the folder that holds the ledger files. Null means the Relay folder. */
    public static ?string $dir = null;
    /** Test seam: the resend time in minutes. Null means read the setting. */
    public static ?int $minutesOverride = null;

    /** The resend time in minutes, from the setting, kept inside 1..240. */
    public static function resendMinutes(): int
    {
        if (self::$minutesOverride !== null) return self::clampMinutes(self::$minutesOverride);
        $cfg = ConfigService::getConfig();
        $raw = $cfg['relay_notice_resend_minutes'] ?? self::DEFAULT_MINUTES;
        return self::clampMinutes(is_numeric($raw) ? (int)$raw : self::DEFAULT_MINUTES);
    }

    public static function clampMinutes(int $m): int
    {
        return max(self::MIN_MINUTES, min(self::MAX_MINUTES, $m));
    }

    /**
     * May a new notice be typed for this workspace? False only when a notice was sent
     * less than the resend time ago and the inbox was not read since.
     */
    public static function shouldSend(string $sid, ?int $now = null): bool
    {
        $file = self::file($sid);
        if ($file === null || !is_file($file)) return true;
        $rec = json_decode((string)@file_get_contents($file), true);
        $sentAt = is_array($rec) ? ($rec['sent_at'] ?? null) : null;
        if (!is_int($sentAt) && !(is_string($sentAt) && ctype_digit($sentAt))) return true;   // unreadable record: do not block
        $now = $now ?? time();
        return ($now - (int)$sentAt) >= self::resendMinutes() * 60;
    }

    /** Write the record for a notice that was typed (or is queued) now. */
    public static function recordSent(string $sid, array $ids = [], ?int $now = null): void
    {
        $file = self::file($sid);
        if ($file === null) return;
        $dir = dirname($file);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $clean = [];
        foreach ($ids as $id) { if (is_string($id) || is_int($id)) $clean[] = (string)$id; }
        AtomicWriteService::writeJson($file, ['sent_at' => $now ?? time(), 'ids' => array_values($clean)]);
    }

    /** The workspace read its inbox: forget the record. */
    public static function clear(string $sid): void
    {
        $file = self::file($sid);
        if ($file !== null && is_file($file)) @unlink($file);
    }

    private static function file(string $sid): ?string
    {
        if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $sid)) return null;
        $base = self::$dir ?? (AgentRelayService::baseDir() . '/system/notice-ledger');
        return rtrim($base, '/') . '/' . $sid . '.json';
    }
}
