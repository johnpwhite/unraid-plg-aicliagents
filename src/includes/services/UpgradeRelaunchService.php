<?php
/**
 * <module_context>
 *   <name>UpgradeRelaunchService</name>
 *   <description>Owns the upgrade closed-set manifest and the manifest-driven
 *   relaunch of exactly the sessions closed for an agent upgrade. Decoupled
 *   from the autoLaunch-flag sweep in AutoLaunchService.</description>
 *   <dependencies>ConfigService, ProcessManager, TerminalService</dependencies>
 *   <constraints>Static methods only. Manifest lives in tmpfs.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class UpgradeRelaunchService
{
    private static function baseDir(): string
    {
        $base = getenv('AICLI_TMP_BASE');
        return $base !== false && $base !== '' ? $base : '/tmp/unraid-aicliagents';
    }

    public static function manifestPath(string $agentId): string
    {
        // Replace any character that is not alphanumeric, dot, or hyphen with an
        // underscore, then collapse any run of two or more dots (path-traversal
        // sequences that survive the first pass, e.g. "../" → ".._") into a
        // single underscore so the resulting filename can never contain "..".
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $agentId);
        $safe = preg_replace('/\.{2,}/', '_', $safe);
        return self::baseDir() . "/upgrade-relaunch-$safe.json";
    }

    public static function writeManifest(string $agentId, array $closed): bool
    {
        @mkdir(self::baseDir(), 0755, true);
        $payload = [
            'agentId'    => $agentId,
            'written_at' => time(),
            'closed'     => array_values($closed),
        ];
        return @file_put_contents(self::manifestPath($agentId), json_encode($payload)) !== false;
    }

    public static function readManifest(string $agentId): array
    {
        $f = self::manifestPath($agentId);
        if (!is_file($f)) return [];
        $data = json_decode((string)@file_get_contents($f), true);
        return is_array($data) ? $data : [];
    }

    public static function deleteManifest(string $agentId): void
    {
        @unlink(self::manifestPath($agentId));
    }

    // --- Per-USER home manifest (approach B) -----------------------------
    // A home is shared across all of a user's agents, so its closed-set is
    // keyed by user and each entry carries its OWN agentId (unlike the per-agent
    // upgrade manifest above, whose agentId is manifest-level). The supervisor
    // consolidate-success hook is keyed by the single consolidate id = the user.

    public static function homeManifestPath(string $user): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $user);
        $safe = preg_replace('/\.{2,}/', '_', $safe);
        return self::baseDir() . "/upgrade-relaunch-home-$safe.json";
    }

    public static function writeHomeManifest(string $user, array $closed): bool
    {
        @mkdir(self::baseDir(), 0755, true);
        $payload = [
            'user'       => $user,
            'written_at' => time(),
            'closed'     => array_values($closed),
        ];
        return @file_put_contents(self::homeManifestPath($user), json_encode($payload)) !== false;
    }

    public static function readHomeManifest(string $user): array
    {
        $f = self::homeManifestPath($user);
        if (!is_file($f)) return [];
        $data = json_decode((string)@file_get_contents($f), true);
        return is_array($data) ? $data : [];
    }

    public static function deleteHomeManifest(string $user): void
    {
        @unlink(self::homeManifestPath($user));
    }

    /**
     * Relaunch exactly the sessions closed for $agentId's upgrade, guaranteeing
     * each closed entry ends as a LIVE session.
     *
     * Self-healing skip logic (R1 / ZOMBIE_SKIP spec): a session that
     * `isRunning()` reports as up is only SKIPPED when it is genuinely healthy —
     * `tmuxSessionHasLiveAgent()` confirms a live agent in the detached pane. A
     * ZOMBIE (session/ttyd present but agent dead — e.g. a `start` that raced the
     * binary swap mid-upgrade) is torn down (`stopTerminal`, killing tmux) so the
     * subsequent `$starter` rebuilds it live, instead of wrongly skipping it and
     * leaving a session that dies → "Terminal session not found".
     *
     * The ProcessManager probes are injectable for unit testing; in production
     * they default to the real static methods, gated behind a class_exists guard.
     *
     * @param callable|null $starter   fn(string $sid, string $path, string $chatId, string $agentId): void
     * @param callable|null $isRunning fn(string $sid): bool
     * @param callable|null $isHealthy fn(string $sid): bool   (tmuxSessionHasLiveAgent)
     * @param callable|null $stopper   fn(string $sid): void   (stopTerminal w/ killTmux)
     */
    public static function relaunchClosedSet(
        string $agentId,
        ?callable $starter = null,
        ?callable $isRunning = null,
        ?callable $isHealthy = null,
        ?callable $stopper = null
    ): array {
        $m = self::readManifest($agentId);
        $closed = $m['closed'] ?? [];
        if (empty($closed)) {
            self::deleteManifest($agentId);
            return ['relaunched' => 0, 'skipped' => 0];
        }

        if ($starter === null) {
            require_once __DIR__ . '/TerminalService.php';
            require_once __DIR__ . '/ProcessManager.php';
            $starter = ['\AICliAgents\Services\TerminalService', 'startTerminal'];
        }
        $pmAvailable = class_exists('\AICliAgents\Services\ProcessManager');
        if ($isRunning === null) {
            $isRunning = $pmAvailable
                ? ['\AICliAgents\Services\ProcessManager', 'isRunning']
                : static fn(string $sid): bool => false;
        }
        if ($isHealthy === null) {
            $isHealthy = $pmAvailable
                ? ['\AICliAgents\Services\ProcessManager', 'tmuxSessionHasLiveAgent']
                : static fn(string $sid): bool => false;
        }
        if ($stopper === null) {
            $stopper = $pmAvailable
                ? static function (string $sid): void {
                    \AICliAgents\Services\ProcessManager::stopTerminal($sid, true);
                }
                : static function (string $sid): void {};
        }

        $relaunched = 0;
        $skipped    = 0;
        foreach ($closed as $s) {
            // Per-agent upgrade manifest: every entry uses the manifest-level agentId.
            $entry = is_array($s) ? $s : [];
            $entry['agentId'] = $agentId;
            $r = self::relaunchOne($entry, $starter, $isRunning, $isHealthy, $stopper);
            if ($r === 'relaunched') $relaunched++; else $skipped++;
        }
        self::deleteManifest($agentId);
        return ['relaunched' => $relaunched, 'skipped' => $skipped];
    }

    /**
     * Relaunch (or skip) exactly ONE closed session, with the self-healing skip
     * logic shared by relaunchClosedSet (per-agent) and relaunchHomeSet (per-user).
     *
     * - not running                → start (relaunched)
     * - running + healthy          → skip (live agent reopened; don't tear down)
     * - running + zombie           → stop then start (relaunched)
     * - missing sessionId/path     → skip
     * `chatId='auto'` when the entry hadResume. Uses the entry's OWN agentId.
     *
     * @param array    $entry     {sessionId, workspacePath, agentId, hadResume}
     * @return string  'relaunched' | 'skipped'
     */
    private static function relaunchOne(
        array $entry,
        callable $starter,
        callable $isRunning,
        callable $isHealthy,
        callable $stopper
    ): string {
        $sid  = (string)($entry['sessionId']     ?? '');
        $path = (string)($entry['workspacePath'] ?? '');
        if ($sid === '' || $path === '') {
            return 'skipped';
        }
        if ($isRunning($sid)) {
            if ($isHealthy($sid)) {
                // Genuinely healthy (live agent) — e.g. a user reopened the
                // session. Leave it; don't tear it down.
                return 'skipped';
            }
            // Zombie: session/ttyd present but agent dead. Clean it so the
            // relaunch below lands (stopTerminal kills ttyd, making isTtydBound
            // false — the guard startTerminal checks before rebuilding the session).
            $stopper($sid);
        }
        $agentId = (string)($entry['agentId'] ?? '');
        $chatId  = !empty($entry['hadResume']) ? 'auto' : '';
        $starter($sid, $path, $chatId, $agentId);
        return 'relaunched';
    }

    /**
     * Relaunch exactly the sessions closed for $user's home consolidate, across
     * ALL their agents (per-entry agentId). Mirrors relaunchClosedSet's seams +
     * self-healing skip logic via the shared relaunchOne. Deletes the per-user
     * manifest at the end, so a repeat supervisor tick is a no-op.
     *
     * @param callable|null $starter   fn(string $sid, string $path, string $chatId, string $agentId): void
     * @param callable|null $isRunning fn(string $sid): bool
     * @param callable|null $isHealthy fn(string $sid): bool
     * @param callable|null $stopper   fn(string $sid): void
     * @return array{relaunched:int,skipped:int}
     */
    public static function relaunchHomeSet(
        string $user,
        ?callable $starter = null,
        ?callable $isRunning = null,
        ?callable $isHealthy = null,
        ?callable $stopper = null
    ): array {
        $m = self::readHomeManifest($user);
        $closed = $m['closed'] ?? [];
        if (empty($closed)) {
            self::deleteHomeManifest($user);
            return ['relaunched' => 0, 'skipped' => 0];
        }

        if ($starter === null) {
            require_once __DIR__ . '/TerminalService.php';
            require_once __DIR__ . '/ProcessManager.php';
            $starter = ['\AICliAgents\Services\TerminalService', 'startTerminal'];
        }
        $pmAvailable = class_exists('\AICliAgents\Services\ProcessManager');
        if ($isRunning === null) {
            $isRunning = $pmAvailable
                ? ['\AICliAgents\Services\ProcessManager', 'isRunning']
                : static fn(string $sid): bool => false;
        }
        if ($isHealthy === null) {
            $isHealthy = $pmAvailable
                ? ['\AICliAgents\Services\ProcessManager', 'tmuxSessionHasLiveAgent']
                : static fn(string $sid): bool => false;
        }
        if ($stopper === null) {
            $stopper = $pmAvailable
                ? static function (string $sid): void {
                    \AICliAgents\Services\ProcessManager::stopTerminal($sid, true);
                }
                : static function (string $sid): void {};
        }

        $relaunched = 0;
        $skipped    = 0;
        foreach ($closed as $s) {
            $entry = is_array($s) ? $s : [];
            $r = self::relaunchOne($entry, $starter, $isRunning, $isHealthy, $stopper);
            if ($r === 'relaunched') $relaunched++; else $skipped++;
        }
        self::deleteHomeManifest($user);
        return ['relaunched' => $relaunched, 'skipped' => $skipped];
    }
}
