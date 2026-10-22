<?php
/**
 * <module_context>
 *     <name>StorageReclaimNoticeService</name>
 *     <description>The Activity tray entry for a storage tidy-up that waits on open
 *     workspaces. One entry per home user, derived from the supervisor's maintenance
 *     state (StorageHandler::forceReclaimState()), so the tray and the banner on the
 *     terminal page always agree. Phases: ask (workspaces open, with a button that
 *     closes them all and tidies), tidying (no workspace open, the tidy-up is starting
 *     or running), finished (state gone). Spec: docs/specs/STORAGE_RECLAIM_NOTICE.md.</description>
 *     <dependencies>ActivityService, TerminalService</dependencies>
 *     <constraints>Best effort: sync() never throws and never blocks the supervisor tick
 *     that calls it. The button runs the existing consolidate_storage flow
 *     (StorageHandler::consolidate): it closes every session of the home with its resume
 *     id, tidies, and relaunches them. For a layered home on a disk that is not a USB
 *     stick (`can_convert`, #496) the button runs convert_storage instead: the home
 *     becomes plain folders and needs no more tidy-ups. A person dismissing the ask ("Later") is
 *     remembered for that episode only (the started_at of the wait), so a new wait asks
 *     again. A dismissal also ends after 24 hours (DISMISS_TTL, #746): the file holds the
 *     episode and the time of the dismissal, and if the wait still goes on the notice comes
 *     back once a day. The banner on the terminal page has its own Convert button, so the
 *     page never depends on this notice being visible.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class StorageReclaimNoticeService
{
    public const OP_PREFIX = 'storage_reclaim_';

    /** AJAX action the tray button calls (the Storage tab's own Consolidate action). */
    public const ACTION = 'consolidate_storage';

    /** #496: AJAX action the tray's "Convert to plain folders" button calls. */
    public const ACTION_CONVERT = 'convert_storage';

    public static function opId(string $user): string
    {
        return self::OP_PREFIX . preg_replace('/[^A-Za-z0-9_-]/', '_', $user);
    }

    private static function dismissPath(string $user): string
    {
        $dir = getenv('AICLI_ACTIVITY_DIR');
        $base = ($dir !== false && $dir !== '') ? $dir : SecretPaths::stateRoot() . '/activity';
        return rtrim($base, '/') . '/.' . self::opId($user) . '.dismissed';
    }

    /** #746: a dismissal lasts this long. Then the notice comes back if the wait goes on. */
    public const DISMISS_TTL = 86400;

    /**
     * Remember that a person chose "Later" for the wait that began at $startedAt.
     * The file holds "<started_at>:<time of the dismissal>". $now is a test seam.
     */
    public static function markDismissed(string $opId, int $startedAt, ?int $now = null): void
    {
        try {
            if (strpos($opId, self::OP_PREFIX) !== 0) return;
            $user = substr($opId, strlen(self::OP_PREFIX));
            @file_put_contents(self::dismissPath($user), $startedAt . ':' . ($now ?? time()));
        } catch (\Throwable $e) {
            // best effort
        }
    }

    /**
     * started_at of the wait episode a person dismissed, or 0 when there is none or the
     * dismissal is older than DISMISS_TTL (#746). An old file without a time (written
     * before #746) counts from its modification time. $now is a test seam.
     */
    public static function dismissedEpisode(string $user, ?int $now = null): int
    {
        $path = self::dismissPath($user);
        $raw = @file_get_contents($path);
        if ($raw === false) return 0;
        $parts = explode(':', trim($raw));
        $episode = (int)$parts[0];
        $at = isset($parts[1]) ? (int)$parts[1] : (int)@filemtime($path);
        if ($episode <= 0) return 0;
        if ((($now ?? time()) - $at) >= self::DISMISS_TTL) return 0;
        return $episode;
    }

    /**
     * Decide what the tray should show. Pure: no I/O, so it is unit-testable.
     *
     * @param array<string,mixed> $maintenance StorageHandler::forceReclaimState()
     * @param list<string>        $openNames   names of the workspaces open on this home
     * @return array{phase:string,label:string,step:string,meta:array<string,mixed>}|null
     *         null = no entry should exist; phase 'handoff' = remove it, a job entry follows the work
     */
    public static function plan(array $maintenance, array $openNames, string $user, int $dismissedEpisode = 0): ?array
    {
        $waiting = (($maintenance['state'] ?? 'none') === 'waiting');
        if (!$waiting) return null;

        // A consolidate a person started (this notice's own button, or the Storage tab)
        // has its own tray entry that follows the real job. Hand over to it.
        if (!empty($maintenance['consolidating'])) {
            return ['phase' => 'handoff', 'label' => '', 'step' => '', 'meta' => []];
        }

        $startedAt = (int)($maintenance['started_at'] ?? 0);
        $n = count($openNames);
        $shown = array_slice($openNames, 0, 4);
        $list = implode(', ', $shown) . ($n > count($shown) ? ' and ' . ($n - count($shown)) . ' more' : '');
        $why = (($maintenance['reason'] ?? '') === 'space_pressure') ? 'Disk space is low.' : 'Workspace memory is nearly full.';

        // #496 (CONVERT_LAYERED_TO_PLAIN_FOLDERS.md R9): a layered home on a disk that is
        // not a USB stick gets the conversion, not a merge. No automatic tidy-up runs for
        // it, so the ask stays even when no workspace is open.
        if (!empty($maintenance['can_convert'])) {
            if ($dismissedEpisode > 0 && $dismissedEpisode === $startedAt) return null;
            $open = $n === 0 ? ''
                : " $n workspace" . ($n === 1 ? ' is' : 's are') . " open: $list. They close and open again by themselves.";
            return [
                'phase' => 'ask',
                'label' => 'Convert this home to plain folders',
                'step'  => "$why This home can change to plain folders. Then it needs no more tidy-ups.$open",
                'meta'  => [
                    'chip' => 'needs you', 'user' => $user, 'phase' => 'ask', 'count' => $n,
                    'startedAt' => $startedAt,
                    'action' => 'convert_home',
                ],
            ];
        }
        // No workspace is open and the tidy-up has not started yet, or the automatic
        // tidy-up is running: nothing else shows it, so this entry does.
        if (count($openNames) === 0) {
            return [
                'phase' => 'tidying',
                'label' => 'Tidying up storage',
                'step'  => 'All workspaces are closed; this can take a few minutes',
                'meta'  => ['chip' => 'in progress', 'user' => $user, 'phase' => 'tidying'],
            ];
        }

        if ($dismissedEpisode > 0 && $dismissedEpisode === $startedAt) return null;

        return [
            'phase' => 'ask',
            'label' => 'Close workspaces for a storage tidy-up',
            'step'  => "$why $n workspace" . ($n === 1 ? ' is' : 's are') . " open: $list. They are closed, saved and started again by themselves.",
            'meta'  => [
                'chip' => 'needs you', 'user' => $user, 'phase' => 'ask', 'count' => $n,
                'startedAt' => $startedAt,
                'action' => 'consolidate_home',
            ],
        ];
    }

    /**
     * Bring the tray entry in line with the maintenance state. Called by the
     * supervisor's storage-status publisher (publish-storage-status.php) every time
     * the state changes, and by tests.
     *
     * @param array<string,mixed> $maintenance
     * @param list<string>|null   $openNames   null = look them up
     */
    public static function sync(array $maintenance, string $user = 'root', ?array $openNames = null): void
    {
        try {
            $opId = self::opId($user);
            if ($openNames === null) {
                $openNames = [];
                foreach (TerminalService::listActiveSessionsForHome($user) as $s) {
                    $openNames[] = self::workspaceName((string)($s['id'] ?? ''), (string)($s['path'] ?? ''));
                }
            }
            $plan = self::plan($maintenance, $openNames, $user, self::dismissedEpisode($user));
            $existing = ActivityService::get($opId);

            if ($plan !== null && $plan['phase'] === 'handoff') {
                if ($existing !== null) ActivityService::dismiss($opId);
                return;
            }
            if ($plan === null) {
                if ($existing !== null && ($existing['status'] ?? '') !== 'done') {
                    // ACTIVITY_TRAY_PLAIN_NOTICES.md R1/R3: the end is a record of a change.
                    // The title says what happened, so there is no grey line. The title is
                    // fixed when an entry is made, so the record replaces the entry.
                    ActivityService::register($opId, 'storage', 'Storage tidy-up finished', [
                        'step' => '', 'progress' => 100,
                        'meta' => ['kind' => 'record', 'cancel' => 'none', 'user' => $user],
                    ]);
                    ActivityService::finish($opId, '');
                }
                return;
            }
            // A 'waiting' entry is exempt from the stall watchdog and stays until finished
            // or dismissed: right for both phases (the tidy-up has no heartbeat to send).
            // Write only when something changed, so repeated syncs do not flood the push channel.
            $progress = $plan['phase'] === 'ask' ? 1 : 50;
            // The title is fixed when an entry is made, and each phase has its own title
            // ("Close workspaces…", then "Tidying up storage"), so a new phase makes a new entry.
            if ($existing === null || ($existing['label'] ?? '') !== $plan['label']) {
                ActivityService::register($opId, 'storage', $plan['label'], [
                    'step' => $plan['step'], 'progress' => $progress, 'status' => ActivityService::STATUS_WAITING,
                    'meta' => $plan['meta'],
                ]);
                $existing = ActivityService::get($opId);
            }
            if ($existing !== null
                && ($existing['status'] ?? '') === ActivityService::STATUS_WAITING
                && ($existing['step'] ?? '') === $plan['step']
                && ($existing['meta'] ?? []) == $plan['meta']) {
                return;
            }
            ActivityService::update($opId, [
                'status' => ActivityService::STATUS_WAITING, 'step' => $plan['step'],
                'progress' => $progress, 'meta' => $plan['meta'], 'error' => null,
            ]);
        } catch (\Throwable $e) {
            // best effort: a tray fault must never wedge the supervisor tick
        }
    }

    private static function workspaceName(string $id, string $path): string
    {
        try {
            foreach (ConfigService::getWorkspaces()['sessions'] ?? [] as $w) {
                if ((string)($w['id'] ?? '') === $id && (string)($w['name'] ?? '') !== '') return (string)$w['name'];
            }
        } catch (\Throwable $e) {
            // fall through
        }
        return $path !== '' ? basename($path) : $id;
    }
}
