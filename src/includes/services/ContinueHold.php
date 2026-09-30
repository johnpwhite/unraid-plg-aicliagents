<?php
/**
 * <module_context>
 *     <name>ContinueHold</name>
 *     <description>Tmpfs marker that stops the automatic Continue after a
 *     restart the operator started with "do not continue" (the Switch to the
 *     installed agent version menu item, reload_onto_current continue=0).
 *     (/tmp/unraid-aicliagents/continue-hold/&lt;safeId&gt;) Forgejo #349,
 *     2026-09-29: see docs/specs/CONTINUE_ON_RESTART.md, "2026-09-29: an
 *     operator switch holds the automatic Continue".</description>
 *     <dependencies>None.</dependencies>
 *     <constraints>Static methods only. Never throws: marker bookkeeping must
 *     not break a restart or a Continue. The hold ends by age
 *     (HOLD_SECONDS), so a later crash relaunch continues again as usual.
 *     Tmpfs only: a reboot clears every marker.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class ContinueHold
{
    /**
     * How long a hold lasts. It must cover every open page: the `started` push
     * arrives in a second or two, the page's retry ladder runs for 8 x 4 s, and a
     * page that missed the push sees the restart at its next 30 s poll. Three
     * minutes covers all of these with margin, and is short enough that a crash
     * relaunch later in the day still continues by itself.
     */
    public const HOLD_SECONDS = 180;

    /** Marker base dir. AICLI_TMP_BASE redirects it for tests (PHPUnit isolation). */
    private static function dir(): string
    {
        $env = getenv('AICLI_TMP_BASE');
        $base = ($env !== false && $env !== '') ? $env : '/tmp/unraid-aicliagents';
        return $base . '/continue-hold';
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
     * Hold the automatic Continue for the next launch of this workspace. Call it
     * BEFORE the restart: an open page can see the workspace run again, and ask
     * for a Continue, before the restart request itself returns.
     */
    public static function hold(string $id, ?int $now = null): void
    {
        $marker = self::markerPath($id);
        if ($marker === null) return;
        @mkdir(self::dir(), 0777, true);
        @file_put_contents($marker, (string)($now ?? time()));
    }

    /** Remove the hold (the restart failed, so no launch follows). */
    public static function clear(string $id): void
    {
        $marker = self::markerPath($id);
        if ($marker === null) return;
        @unlink($marker);
    }

    /** True while a hold set less than HOLD_SECONDS ago exists for this workspace. */
    public static function isHeld(string $id, ?int $now = null): bool
    {
        $marker = self::markerPath($id);
        if ($marker === null || !is_file($marker)) return false;
        $at = (int)trim((string)@file_get_contents($marker));
        if ($at <= 0) return false;
        $age = ($now ?? time()) - $at;
        return $age >= 0 && $age < self::HOLD_SECONDS;
    }

    /**
     * The held workspaces among $ids, as id => true. The drawer poll
     * (get_sessions_running) sends this map, so every open page can skip the
     * automatic Continue by itself too.
     *
     * @param array<int,string> $ids
     * @return array<string,bool>
     */
    public static function heldMap(array $ids, ?int $now = null): array
    {
        $held = [];
        foreach ($ids as $id) {
            if (self::isHeld((string)$id, $now)) $held[(string)$id] = true;
        }
        return $held;
    }
}
