<?php
/**
 * <module_context>
 *     <name>TerminalGenerationService</name>
 *     <description>Returns an opaque identity for the ttyd process serving one terminal session.</description>
 *     <dependencies>Linux /proc and per-session runtime files.</dependencies>
 *     <constraints>Read-only. A PID alone is not an identity because Linux can reuse it.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class TerminalGenerationService
{
    /** Return the current ttyd identity, or null while no complete endpoint exists. */
    public static function current(string $id): ?string
    {
        $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
        if ($id === '') return null;

        return self::fromArtifacts(
            "/var/run/unraid-aicliagents-$id.pid",
            "/var/run/aicliterm-$id.sock",
            '/proc'
        );
    }

    /**
     * Testable seam for the runtime lookup. Linux field 22 (process start time
     * in clock ticks) makes the identity survive PID reuse safely.
     */
    public static function fromArtifacts(string $pidFile, string $sockFile, string $procRoot): ?string
    {
        if (!is_file($pidFile) || !file_exists($sockFile)) return null;

        $pid = trim((string) @file_get_contents($pidFile));
        if (!ctype_digit($pid) || $pid === '0') return null;

        $stat = @file_get_contents(rtrim($procRoot, '/') . "/$pid/stat");
        if (!is_string($stat) || $stat === '') return null;
        $closeParen = strrpos($stat, ')');
        if ($closeParen === false) return null;

        $tail = preg_split('/\s+/', trim(substr($stat, $closeParen + 1)));
        $startTicks = $tail[19] ?? null;
        if (!is_string($startTicks) || !ctype_digit($startTicks)) return null;

        return $pid . ':' . $startTicks;
    }
}
