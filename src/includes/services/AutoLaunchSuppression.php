<?php
/**
 * <module_context>
 *     <name>AutoLaunchSuppression</name>
 *     <description>Tmpfs marker that tells auto-launch to leave a workspace
 *     alone after an operator or an admin tool closed it on purpose.
 *     (/tmp/unraid-aicliagents/autolaunch-suppress/&lt;safeId&gt;) Fix 2026-09-12:
 *     see docs/specs/2026-04-27-auto-launch-workspaces-design.md, "Fix
 *     2026-09-12: a close suppresses auto-launch".</description>
 *     <dependencies>None.</dependencies>
 *     <constraints>Static methods only. Never throws — marker bookkeeping must
 *     not break a close or a start. Marker writes are best-effort
 *     (mkdir -p). Tmpfs only: a reboot clears every marker, which is the only
 *     expiry this class needs.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class AutoLaunchSuppression
{
    /** Marker base dir — AICLI_TMP_BASE redirects for tests (PHPUnit isolation). */
    private static function dir(): string
    {
        $env = getenv('AICLI_TMP_BASE');
        $base = ($env !== false && $env !== '') ? $env : '/tmp/unraid-aicliagents';
        return $base . '/autolaunch-suppress';
    }

    /** Same charset every other session-id sanitiser in this plugin uses. */
    private static function safeId(string $id): string
    {
        return (string)preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
    }

    private static function markerPath(string $id): ?string
    {
        $safe = self::safeId($id);
        return $safe === '' ? null : self::dir() . '/' . $safe;
    }

    /**
     * Marks a workspace as closed on purpose. Call this from an
     * operator-driven or tool-driven close/stop — never from the periodic
     * orphan-session sweep, which reaps an already-dead session and must
     * still be free to auto-relaunch it (AGENT_LAUNCH_RESILIENCE.md).
     */
    public static function suppress(string $id): void
    {
        $marker = self::markerPath($id);
        if ($marker === null) return;
        @mkdir(self::dir(), 0777, true);
        @touch($marker);
    }

    /**
     * Clears the marker. Call this from an explicit start of the same
     * workspace — the operator asking for it back overrides the earlier close.
     */
    public static function clear(string $id): void
    {
        $marker = self::markerPath($id);
        if ($marker === null) return;
        @unlink($marker);
    }

    /** True when the workspace was closed on purpose and has not been started since. */
    public static function isSuppressed(string $id): bool
    {
        $marker = self::markerPath($id);
        return $marker !== null && is_file($marker);
    }
}
