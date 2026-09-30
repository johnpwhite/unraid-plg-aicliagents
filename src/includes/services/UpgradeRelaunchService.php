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

    /** A closed set remains an active upgrade barrier until it is relaunched. */
    public static function hasPendingAgentUpgrade(string $agentId): bool
    {
        $manifest = self::readManifest($agentId);
        return !empty($manifest['closed']) && is_array($manifest['closed']);
    }

    /**
     * 2026-09-03 wedge guard: true while the install that owns the closed-set
     * manifest is still ALIVE, i.e. activation/relaunch must NOT run yet. The
     * manifest is written at session-close time — before install-bg finishes —
     * so "manifest exists" alone cannot distinguish a live install from a
     * crashed one. Two signals, either blocks:
     *   1. a live `install-bg.php <agentId>` process;
     *   2. a FRESH in-flight install-status marker (0 < progress < 100, age
     *      <= 180 s) whose phase is neither `queued` (sessions untouched, no
     *      manifest yet) nor `awaiting_activation` (install-bg finished and
     *      handed activation to the supervisor — the one in-flight state where
     *      activation MUST proceed). The marker is written by
     *      AgentHandler::install BEFORE sessions close, so it covers the gap
     *      before the install-bg process exists.
     * A stale marker with no process (SIGKILLed install) does not block, so
     * the crash-window self-heal keeps working.
     * Spec: docs/specs/AGENT_UPGRADE_SESSION_RELAUNCH.md (2026-09-03 correction).
     *
     * @param callable|null $installRunning fn(): bool — test seam; defaults to
     *        PendingAgentUpgradeService::backgroundInstallRunning($agentId).
     */
    public static function activationBlocked(string $agentId, ?callable $installRunning = null): bool
    {
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $agentId)) return false;
        if ($installRunning === null) {
            require_once __DIR__ . '/PendingAgentUpgradeService.php';
            $installRunning = static fn(): bool =>
                PendingAgentUpgradeService::backgroundInstallRunning($agentId);
        }
        if ($installRunning()) return true;

        $marker = self::baseDir() . "/install-status-$agentId";
        if (!is_file($marker)) return false;
        $status = json_decode((string)@file_get_contents($marker), true);
        if (!is_array($status)) return false;
        $phase = (string)($status['phase'] ?? '');
        if ($phase === 'queued' || $phase === 'awaiting_activation') return false;
        $progress = (int)($status['progress'] ?? 0);
        if ($progress <= 0 || $progress >= 100) return false;
        return (time() - (int)@filemtime($marker)) <= 180;
    }

    /**
     * docs/specs/EVENT_FIRST_RECONCILIATION.md 1b.2: unlink an install-status
     * marker whose install crashed (SIGKILL/OOM) without ever writing
     * progress=100 — moved OUT of AgentHandler::listActiveInstalls() (a read
     * path) so a stale marker is cleared even with no browser open. Reads the
     * SAME marker path activationBlocked() reads (self::baseDir(), which
     * honours AICLI_TMP_BASE for tests), and applies the EXACT SAME staleness
     * rule AgentHandler::isInstallInProgress() still applies on its own guard
     * path: age > 180 s AND no live install-bg.php process AND no retained
     * closed-set manifest (a manifest means the marker's `awaiting_activation`
     * wait is real, not a crash — that marker must survive).
     *
     * Never throws. @return int number of markers unlinked.
     */
    public static function reapStaleInstallMarkers(): int
    {
        $reaped = 0;
        foreach (glob(self::baseDir() . '/install-status-*') ?: [] as $file) {
            $base = basename($file);
            if (!preg_match('/^install-status-([a-z0-9][a-z0-9-]{0,63})$/', $base, $m)) continue;
            $agentId = $m[1];
            $status = @json_decode((string)@file_get_contents($file), true);
            if (!is_array($status)) continue;
            if (($status['phase'] ?? '') === 'queued') continue;
            $progress = (int)($status['progress'] ?? 0);
            if ($progress <= 0 || $progress >= 100) continue;

            $age = time() - (int)@filemtime($file);
            if ($age <= 180) continue; // fresh — still genuinely in progress

            // A retained closed set means activation is still pending on
            // purpose (awaiting_activation) — never reap that marker.
            if (self::hasPendingAgentUpgrade($agentId)) continue;

            $cmd = "timeout 2 ps aux | grep 'install-bg.php " . escapeshellarg($agentId) . "' | grep -v grep";
            exec($cmd, $ignored, $rc);
            if ($rc === 0) continue; // a live install-bg process is authoritative

            if (@unlink($file)) $reaped++;
        }
        return $reaped;
    }

    /**
     * Agent ids with a durable closed set waiting for layer activation.
     * Invalid/malformed manifests are ignored; callers must never infer an id
     * from a filename alone.
     *
     * @return array<int,string>
     */
    public static function pendingAgentIds(): array
    {
        $ids = [];
        foreach (glob(self::baseDir() . '/upgrade-relaunch-*.json') ?: [] as $file) {
            $data = json_decode((string)@file_get_contents($file), true);
            if (!is_array($data) || empty($data['closed']) || !is_array($data['closed'])) continue;
            $agentId = (string)($data['agentId'] ?? '');
            if (!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $agentId)) continue;
            // A browser/user may have recovered every closed workspace under
            // fresh session ids before a deferred layer activation succeeds.
            // Such a manifest can never make progress while those healthy
            // replacements hold the overlay, and it incorrectly marks every
            // session for the agent as "upgrading" after supervisor restart.
            if (self::retireSupersededManifest($agentId)) continue;
            $ids[$agentId] = true;
        }
        return array_keys($ids);
    }

    /**
     * Archive an obsolete closed-set manifest when every retired session has a
     * healthy replacement for the same workspace under a different session id.
     * Matching is one-to-one: one replacement cannot satisfy two closed entries.
     * Partial recovery deliberately leaves the whole manifest intact.
     *
     * @param array<int,array<string,mixed>>|null $activeSessions
     * @param callable|null $isHealthy fn(string $sessionId): bool
     */
    public static function retireSupersededManifest(
        string $agentId,
        ?array $activeSessions = null,
        ?callable $isHealthy = null
    ): bool {
        $manifest = self::readManifest($agentId);
        $closed = $manifest['closed'] ?? [];
        if (!is_array($closed) || $closed === []) return false;

        if ($activeSessions === null) {
            require_once __DIR__ . '/TerminalService.php';
            require_once __DIR__ . '/ProcessManager.php';
            $activeSessions = TerminalService::listActiveSessionsForAgent($agentId);
        }
        if ($isHealthy === null) {
            $isHealthy = static fn(string $sid): bool => ProcessManager::tmuxSessionHasLiveAgent($sid);
        }

        $available = [];
        foreach ($activeSessions as $session) {
            if (!is_array($session)) continue;
            $sid = trim((string)($session['id'] ?? ''));
            $path = self::normaliseWorkspacePath((string)($session['path'] ?? ''));
            if ($sid === '' || $path === '' || !$isHealthy($sid)) continue;
            $available[] = ['id' => $sid, 'path' => $path];
        }

        foreach ($closed as $entry) {
            if (!is_array($entry)) return false;
            $retiredId = trim((string)($entry['sessionId'] ?? ''));
            $path = self::normaliseWorkspacePath((string)($entry['workspacePath'] ?? ''));
            if ($retiredId === '' || $path === '') return false;

            $match = null;
            foreach ($available as $index => $candidate) {
                if ($candidate['path'] === $path && $candidate['id'] !== $retiredId) {
                    $match = $index;
                    break;
                }
            }
            if ($match === null) return false;
            unset($available[$match]);
        }

        $source = self::manifestPath($agentId);
        $archiveDir = self::baseDir() . '/archive/superseded-upgrades';
        if (!@mkdir($archiveDir, 0755, true) && !is_dir($archiveDir)) return false;
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $agentId);
        $archive = sprintf(
            '%s/%s-%d-%s.json',
            $archiveDir,
            gmdate('Ymd\\THis\\Z'),
            getmypid(),
            $safe
        );
        return @rename($source, $archive);
    }

    private static function normaliseWorkspacePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') return '';
        $path = (string)preg_replace('#/+#', '/', $path);
        return $path === '/' ? '/' : rtrim($path, '/');
    }

    /**
     * Enqueue the supervisor-owned layer activation using a stable job id.
     * The optional callables are deterministic test seams.
     */
    /**
     * #350 (docs/specs/UPGRADE_ACTIVATION_WITHOUT_CLOSED_SET.md "2026-09-29"):
     * the supervisor's retry state for one activation job (tries, failures in a
     * row, last outcome, halted), a key=value file beside the job's .retry file.
     */
    public static function activationStatePath(string $agentId, ?string $retryDir = null): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $agentId);
        $retryDir = $retryDir ?? ((class_exists(SupervisorService::class)
            ? SupervisorService::SUPERVISOR_DIR : '/tmp/unraid-aicliagents/supervisor') . '/jobs-retry');
        return rtrim($retryDir, '/') . "/upgrade-agent-$safe.activation";
    }

    public static function schedulePendingActivation(
        string $agentId,
        ?callable $enqueue = null,
        ?callable $wake = null
    ): bool {
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $agentId)) return false;
        if ($enqueue === null) {
            $enqueue = static function (string $id, string $jobId): bool {
                return SupervisorService::enqueue(
                    'agent', $id, 'mount', 'upgrade_relaunch', 1, $jobId
                );
            };
        }
        if ($wake === null) {
            $wake = static fn(): bool => SupervisorService::wake();
        }
        $jobId = 'upgrade-agent-' . preg_replace('/[^A-Za-z0-9._-]/', '_', $agentId);
        // #350: a new activation starts its backoff again at the first delay
        // and is no longer halted by the failures of an earlier one.
        @unlink(self::activationStatePath($agentId));
        $queued = (bool)$enqueue($agentId, $jobId);
        if ($queued) $wake();
        return $queued;
    }

    public static function deleteManifest(string $agentId): void
    {
        @unlink(self::manifestPath($agentId));
    }

    // --- Pending layer activation (docs/specs/UPGRADE_ACTIVATION_WITHOUT_CLOSED_SET.md)
    //
    // The closed-set manifest above only exists when the upgrade CLOSED sessions.
    // An upgrade with no session to close (or whose mount was pinned by something
    // else at refresh time) also ends with a freshly baked layer that is not live,
    // but had NO durable record of that: the supervisor's activation job found no
    // manifest, logged `upgrade_activation_superseded` and dropped it, so the new
    // consolidated layer was never activated until the next reboot and the tray
    // eventually timed the wait out as a failure (opencode 1.18.27, 2026-09-06).
    // This record is the manifest-independent activation barrier: it is written
    // by install-bg at hand-off and cleared by completeActivation() after the
    // layer is live (via the supervisor mount job) — or immediately, when the
    // layer turns out to be live already.

    public static function pendingActivationPath(string $agentId): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $agentId);
        $safe = preg_replace('/\.{2,}/', '_', $safe);
        return self::baseDir() . "/pending-activation-$safe.json";
    }

    public static function markActivationPending(string $agentId, string $layer, bool $hasClosedSet): bool
    {
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $agentId)) return false;
        @mkdir(self::baseDir(), 0755, true);
        $payload = [
            'agentId'       => $agentId,
            'layer'         => $layer,
            'closed_set'    => $hasClosedSet,
            'written_at'    => time(),
        ];
        return @file_put_contents(self::pendingActivationPath($agentId), json_encode($payload)) !== false;
    }

    public static function readPendingActivation(string $agentId): array
    {
        $f = self::pendingActivationPath($agentId);
        if (!is_file($f)) return [];
        $data = json_decode((string)@file_get_contents($f), true);
        return (is_array($data) && (string)($data['agentId'] ?? '') === $agentId) ? $data : [];
    }

    public static function hasPendingActivation(string $agentId): bool
    {
        return self::readPendingActivation($agentId) !== [];
    }

    public static function clearPendingActivation(string $agentId): void
    {
        @unlink(self::pendingActivationPath($agentId));
    }

    /**
     * True while ANY activation barrier exists for the agent: a closed set that
     * still has to be relaunched, or a baked layer that still has to go live.
     * This is what the supervisor's `upgrade_relaunch` mount job checks before
     * declaring itself superseded.
     */
    public static function activationPending(string $agentId): bool
    {
        return self::hasPendingAgentUpgrade($agentId) || self::hasPendingActivation($agentId);
    }

    /**
     * Agent ids with a pending-activation record and NO closed set (the closed-set
     * path is owned by pendingAgentIds()). Ids are validated from file content.
     *
     * @return array<int,string>
     */
    public static function pendingActivationOnlyIds(): array
    {
        $ids = [];
        foreach (glob(self::baseDir() . '/pending-activation-*.json') ?: [] as $file) {
            $data = json_decode((string)@file_get_contents($file), true);
            $agentId = is_array($data) ? (string)($data['agentId'] ?? '') : '';
            if (!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $agentId)) continue;
            // The content must name the agent the file is for (a foreign or
            // mis-filed record is ignored, never acted on).
            if (basename($file) !== basename(self::pendingActivationPath($agentId))) continue;
            if (self::hasPendingAgentUpgrade($agentId)) continue;
            $ids[$agentId] = true;
        }
        return array_keys($ids);
    }

    /**
     * The activation is done: publish 100% (which also finishes the tray entry
     * parked in `waiting`) and drop the record. Idempotent. The optional callable
     * is a test seam for the status publisher.
     */
    public static function completeActivation(string $agentId, ?callable $publishComplete = null): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $agentId)) return;
        $record = self::readPendingActivation($agentId);
        if ($publishComplete === null) {
            $publishComplete = static function (string $id): void {
                if (function_exists('setInstallStatus')) {
                    setInstallStatus('Installation complete', 100, $id);
                } elseif (class_exists('\AICliAgents\Services\UtilityService')) {
                    UtilityService::setInstallStatus('Installation complete', 100, $id);
                }
            };
        }
        $publishComplete($agentId);
        self::clearPendingActivation($agentId);
        if ($record !== [] && class_exists('\AICliAgents\Services\LifecycleLogService')) {
            LifecycleLogService::log(LifecycleLogService::LEVEL_INFO, 'installer',
                'upgrade_activation_complete',
                ['agent' => $agentId, 'layer' => (string)($record['layer'] ?? ''), 'closed_set' => (bool)($record['closed_set'] ?? false)]);
        }
    }

    /**
     * Close-event hook (UPGRADE_ACTIVATION_WITHOUT_CLOSED_SET.md §Event): a
     * session of $agentId just closed. If an activation is pending, pull its
     * parked supervisor retry forward to NOW (the backoff pen otherwise waits up
     * to 60 s) — or enqueue the stable job when nothing is parked or queued —
     * and wake the supervisor. The tick's idle probe + backoff remain the
     * fallback. Returns true when something was nudged. Never throws.
     *
     * @param string|null   $retryDir test seam; default supervisor jobs-retry dir
     * @param callable|null $wake     test seam; default SupervisorService::wake
     * @param callable|null $schedule test seam; default schedulePendingActivation
     */
    public static function nudgeActivation(
        string $agentId,
        ?string $retryDir = null,
        ?callable $wake = null,
        ?callable $schedule = null
    ): bool {
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $agentId)) return false;
        if (!self::activationPending($agentId)) return false;
        $safe   = preg_replace('/[^A-Za-z0-9._-]/', '_', $agentId);
        $jobId  = "upgrade-agent-$safe";
        $retryDir = $retryDir ?? (SupervisorService::SUPERVISOR_DIR . '/jobs-retry');
        $wake     = $wake ?? static fn(): bool => SupervisorService::wake();
        $schedule = $schedule ?? static fn(string $id): bool => self::schedulePendingActivation($id);

        // #350: a halted activation (repeated failures) is armed again by a
        // session close: the close can free what made the mount fail.
        $state = self::activationStatePath($agentId, $retryDir);
        if (is_file($state) && preg_match('/^halted=1$/m', (string)@file_get_contents($state))) {
            @unlink($state);
            if (class_exists('\AICliAgents\Services\LifecycleLogService')) {
                LifecycleLogService::log(LifecycleLogService::LEVEL_INFO, 'installer',
                    'upgrade_activation_rearmed', ['agent' => $agentId]);
            }
        }

        $retry = "$retryDir/$jobId.retry";
        if (is_file($retry)) {
            $data = json_decode((string)@file_get_contents($retry), true);
            if (is_array($data)) {
                $data['retry_at'] = time();
                $tmp = "$retry.tmp." . getmypid();
                if (@file_put_contents($tmp, json_encode($data)) !== false && @rename($tmp, $retry)) {
                    if (class_exists('\AICliAgents\Services\LifecycleLogService')) {
                        LifecycleLogService::log(LifecycleLogService::LEVEL_INFO, 'installer',
                            'upgrade_activation_nudged', ['agent' => $agentId, 'via' => 'retry_pulled_forward']);
                    }
                    $wake();
                    return true;
                }
                @unlink($tmp);
            }
        }
        $queued = glob(SupervisorService::QUEUE_DIR . "/*_agent_{$safe}_mount.req") ?: [];
        if ($queued !== []) { $wake(); return true; }   // already queued — just make the tick happen now
        $ok = (bool)$schedule($agentId);                // enqueues with the stable job id + wakes
        if ($ok && class_exists('\AICliAgents\Services\LifecycleLogService')) {
            LifecycleLogService::log(LifecycleLogService::LEVEL_INFO, 'installer',
                'upgrade_activation_nudged', ['agent' => $agentId, 'via' => 'enqueued']);
        }
        return $ok;
    }

    /**
     * Supervisor sweep helper for pending-activation-only agents: an agent whose
     * newest layer is ALREADY live (someone remounted meanwhile) is completed on
     * the spot — never enqueue a mount that a live session would make defer
     * forever for nothing. The rest are returned for the activation mount job.
     *
     * @param callable|null $layerLive fn(string $agentId): bool — test seam;
     *        defaults to InstallerService::isAgentLayerLive on the newest layer.
     * @param callable|null $publishComplete forwarded to completeActivation().
     * @return array<int,string> ids that still need the activation mount
     */
    public static function reconcilePendingActivations(?callable $layerLive = null, ?callable $publishComplete = null): array
    {
        if ($layerLive === null) {
            $layerLive = static function (string $agentId): bool {
                require_once __DIR__ . '/InstallerService.php';
                $persistDir = '/boot/config/plugins/unraid-aicliagents/persistence';
                $newest = InstallerService::newestAgentLayer($agentId, $persistDir);
                // No layer file: a plain-directory agent is live once its bind
                // shows the promoted generation (2026-09-24); any other agent
                // has nothing to activate.
                if ($newest === null) {
                    require_once __DIR__ . '/StorageMountService.php';
                    return !StorageMountService::passthroughActivationPending($agentId);
                }
                $mounts = (string)@file_get_contents('/proc/mounts');
                return InstallerService::isAgentLayerLive($agentId, $newest, $mounts);
            };
        }
        $needMount = [];
        foreach (self::pendingActivationOnlyIds() as $agentId) {
            if ($layerLive($agentId)) {
                self::completeActivation($agentId, $publishComplete);
                continue;
            }
            $needMount[] = $agentId;
        }
        return $needMount;
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
     * HOME_BACKUP.md R3/R4: close every open session of $user's home for a
     * COLD backup, recording each session's readiness — "working" or
     * "idle" — from the SAME pane classifier the Relay and Continue nudge use
     * (TmuxService::paneAcceptsInput), taken IMMEDIATELY BEFORE the close.
     * Writes the per-user relaunch manifest first (so a crash between the
     * manifest write and the actual close still leaves a recoverable set),
     * then closes the sessions. A session the classifier cannot read (no
     * session, no live agent, or the pane could not be captured) is recorded
     * `working: false` — the safe default is no Continue nudge.
     *
     * @param callable|null $listSessions fn(string $user): array<int,array<string,mixed>>
     *        default TerminalService::listActiveSessionsForHome.
     * @param callable|null $paneReady    fn(string $agentId, string $sessionId): array{ready:bool,reason:string}
     *        default TmuxService::paneAcceptsInput.
     * @param callable|null $closer       fn(string $user): int  default TerminalService::forceCloseHome.
     * @return array<int,array<string,mixed>> the closed-set entries written to the manifest
     *         ({sessionId, workspacePath, agentId, hadResume, working}), or []
     *         when there was nothing to close or the manifest could not be written.
     */
    public static function closeHomeSet(
        string $user,
        ?callable $listSessions = null,
        ?callable $paneReady = null,
        ?callable $closer = null
    ): array {
        if ($listSessions === null) {
            require_once __DIR__ . '/TerminalService.php';
            $listSessions = ['\AICliAgents\Services\TerminalService', 'listActiveSessionsForHome'];
        }
        if ($paneReady === null) {
            require_once __DIR__ . '/TmuxService.php';
            $paneReady = ['\AICliAgents\Services\TmuxService', 'paneAcceptsInput'];
        }
        if ($closer === null) {
            require_once __DIR__ . '/TerminalService.php';
            $closer = ['\AICliAgents\Services\TerminalService', 'forceCloseHome'];
        }

        $sessions = $listSessions($user);
        if (!is_array($sessions) || $sessions === []) {
            return [];
        }

        $manifest = [];
        foreach ($sessions as $s) {
            if (!is_array($s)) continue;
            $sid     = trim((string)($s['id']      ?? ''));
            $path    = trim((string)($s['path']    ?? ''));
            $agentId = trim((string)($s['agentId'] ?? ''));
            if ($sid === '' || $path === '' || $agentId === '') continue;

            // Readiness classifier BEFORE the close. "Cannot read" (no
            // session/no live agent/capture failed) is the safe default:
            // not working, no nudge. Any other not-ready reason (a live
            // decision, an unrecognised busy shape) means the agent WAS
            // doing something — record it working so the relaunch nudges it.
            $ready       = $paneReady($agentId, $sid);
            $readyReason = (string)($ready['reason'] ?? '');
            $unreadable  = in_array($readyReason, ['no-session', 'no-live-agent', 'capture-failed'], true);
            $working     = !$unreadable && (($ready['ready'] ?? true) === false);

            $manifest[] = [
                'sessionId'     => $sid,
                'workspacePath' => $path,
                'agentId'       => $agentId,
                'hadResume'     => true,
                'working'       => $working,
            ];
        }
        if ($manifest === []) {
            return [];
        }
        if (self::writeHomeManifest($user, $manifest) === false) {
            return [];
        }
        $closer($user);
        return $manifest;
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
                // WORKSPACE_LIFECYCLE_EVENTS.md: this close is part of an
                // agent-version upgrade, not an operator stop or evict.
                ? static function (string $sid): void {
                    \AICliAgents\Services\ProcessManager::stopTerminal($sid, true, 'upgrade');
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
     * @param string   $origin    passed through to $starter as its 5th arg (the
     *        `started` event's origin tag — see TerminalService::startTerminal).
     * @return string  'relaunched' | 'skipped'
     */
    private static function relaunchOne(
        array $entry,
        callable $starter,
        callable $isRunning,
        callable $isHealthy,
        callable $stopper,
        string $origin = 'upgrade_relaunch'
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
        $starter($sid, $path, $chatId, $agentId, $origin);
        return 'relaunched';
    }

    /**
     * Relaunch exactly the sessions closed for $user's home consolidate, across
     * ALL their agents (per-entry agentId). Mirrors relaunchClosedSet's seams +
     * self-healing skip logic via the shared relaunchOne. Deletes the per-user
     * manifest at the end, so a repeat supervisor tick is a no-op.
     *
     * HOME_BACKUP.md R6: an optional per-session Continue nudge for entries the
     * close phase recorded `working: true`. Passing $nudger opts in — every
     * existing caller (the consolidate relaunch bridge) passes none and keeps
     * the old behaviour (relaunch/skip only, never nudges).
     *
     * @param callable|null $starter   fn(string $sid, string $path, string $chatId, string $agentId, string $origin): void
     * @param callable|null $isRunning fn(string $sid): bool
     * @param callable|null $isHealthy fn(string $sid): bool
     * @param callable|null $stopper   fn(string $sid): void
     * @param callable|null $nudger    fn(string $agentId, string $sessionId): array — e.g.
     *        TmuxService::submitContinueNudge. Null (default) = never nudge.
     * @param callable|null $paneReady fn(string $agentId, string $sessionId): array{ready:bool,reason:string} —
     *        e.g. TmuxService::paneAcceptsInput. Required for a nudge to fire.
     * @param callable|null $sleeper   fn(int $seconds): void — test seam for the
     *        bounded wait between readiness probes (default real sleep()).
     * @param string         $origin   the `started` event origin tag passed to
     *        $starter for every relaunched entry (see TerminalService::startTerminal).
     * @return array{relaunched:int,skipped:int,nudged:int}
     */
    public static function relaunchHomeSet(
        string $user,
        ?callable $starter = null,
        ?callable $isRunning = null,
        ?callable $isHealthy = null,
        ?callable $stopper = null,
        ?callable $nudger = null,
        ?callable $paneReady = null,
        ?callable $sleeper = null,
        string $origin = 'upgrade_relaunch'
    ): array {
        $m = self::readHomeManifest($user);
        $closed = $m['closed'] ?? [];
        if (empty($closed)) {
            self::deleteHomeManifest($user);
            return ['relaunched' => 0, 'skipped' => 0, 'nudged' => 0];
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
                // WORKSPACE_LIFECYCLE_EVENTS.md: this close is part of an
                // agent-version upgrade, not an operator stop or evict.
                ? static function (string $sid): void {
                    \AICliAgents\Services\ProcessManager::stopTerminal($sid, true, 'upgrade');
                }
                : static function (string $sid): void {};
        }
        if ($sleeper === null) {
            $sleeper = static function (int $seconds): void { sleep($seconds); };
        }

        $relaunched = 0;
        $skipped    = 0;
        $nudged     = 0;
        foreach ($closed as $s) {
            $entry = is_array($s) ? $s : [];
            $r = self::relaunchOne($entry, $starter, $isRunning, $isHealthy, $stopper, $origin);
            if ($r === 'relaunched') $relaunched++; else $skipped++;

            if ($r === 'relaunched' && $nudger !== null && !empty($entry['working'])) {
                $agentId = (string)($entry['agentId']   ?? '');
                $sid     = (string)($entry['sessionId'] ?? '');
                if ($agentId !== '' && $sid !== '' && self::waitPaneReady($agentId, $sid, $paneReady, $sleeper)) {
                    $nudger($agentId, $sid);
                    $nudged++;
                }
            }
        }
        self::deleteHomeManifest($user);
        return ['relaunched' => $relaunched, 'skipped' => $skipped, 'nudged' => $nudged];
    }

    /**
     * HOME_BACKUP.md R6: "readiness gate, bounded wait, one retry" before a
     * post-relaunch Continue nudge — an initial settle wait, one probe, and
     * (if not yet ready) exactly one more wait + probe before giving up.
     * Never nudges a pane that never reported ready.
     */
    private static function waitPaneReady(string $agentId, string $sessionId, ?callable $paneReady, callable $sleeper): bool
    {
        if ($paneReady === null) return false;
        $sleeper(2); // let the freshly-started agent settle before the first probe
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $gate = $paneReady($agentId, $sessionId);
            if (!empty($gate['ready'])) return true;
            $sleeper(3); // the one retry's bounded wait
        }
        return false;
    }
}
