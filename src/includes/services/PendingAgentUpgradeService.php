<?php
/**
 * Non-destructive, pre-install agent-upgrade queue (issue #71).
 *
 * A queued request leaves every ttyd/tmux/agent/descendant PID untouched. The
 * storage supervisor calls processAllReady() each tick; installation begins
 * only after two consecutive session checks are empty with the install-status
 * start barrier already raised between them.
 */

namespace AICliAgents\Services;

class PendingAgentUpgradeService
{
    private static function baseDir(): string
    {
        $base = getenv('AICLI_TMP_BASE');
        return rtrim($base !== false && $base !== '' ? $base : SecretPaths::stateRoot(), '/');
    }

    private static function safeId(string $agentId): string
    {
        return preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/D', $agentId) ? $agentId : '';
    }

    public static function requestPath(string $agentId): string
    {
        $safe = self::safeId($agentId);
        return self::baseDir() . '/pending-agent-upgrade-' . ($safe !== '' ? $safe : 'invalid') . '.json';
    }

    /** @return array<string,mixed> */
    public static function read(string $agentId): array
    {
        if (self::safeId($agentId) === '') return [];
        $data = json_decode((string)@file_get_contents(self::requestPath($agentId)), true);
        return is_array($data) && ($data['agentId'] ?? '') === $agentId ? $data : [];
    }

    /** @return array<int,string> */
    public static function pendingAgentIds(): array
    {
        $ids = [];
        foreach (glob(self::baseDir() . '/pending-agent-upgrade-*.json') ?: [] as $path) {
            $data = json_decode((string)@file_get_contents($path), true);
            $id = is_array($data) ? (string)($data['agentId'] ?? '') : '';
            if (self::safeId($id) !== '') $ids[$id] = true;
        }
        $out = array_keys($ids);
        sort($out);
        return $out;
    }

    /**
     * @param array<string,callable> $seams
     * @return array{status:string,message:string,active_sessions:int}
     */
    public static function queue(string $agentId, string $version, string $backupDest,
                                 int $activeSessions, array $seams = [], string $reason = ''): array
    {
        $storageGuard = StorageBackendMigrationService::acquireLaunchGuard(false);
        if ($storageGuard === false) {
            $refusal = StorageBackendMigrationService::migrationRefusal();
            return $refusal !== null
                ? ($refusal + ['active_sessions' => $activeSessions])
                : ['status' => 'error', 'message' => 'A storage change is in progress. Wait for it to finish, then try again.', 'active_sessions' => $activeSessions];
        }
        try {
            $refusal = StorageBackendMigrationService::migrationRefusal();
            if ($refusal !== null) return $refusal + ['active_sessions' => $activeSessions];
            if (self::safeId($agentId) === '') {
                return ['status' => 'error', 'message' => 'Invalid agent ID', 'active_sessions' => 0];
            }
            @mkdir(self::baseDir(), 0755, true);
            $request = [
                'schema' => 1,
                'agentId' => $agentId,
                'version' => $version,
                'backup_dest' => $backupDest,
                'requested_at' => time(),
            ];
            if (!AtomicWriteService::writeJson(self::requestPath($agentId), $request)) {
                return ['status' => 'error', 'message' => 'Could not save queued upgrade', 'active_sessions' => $activeSessions];
            }
            self::setQueuedStatus($agentId, $activeSessions, $seams, $reason);
            if (isset($seams['wake'])) $seams['wake']();
            else SupervisorService::wake();
            return [
                'status' => 'queued',
                'message' => self::waitingMessage($activeSessions, $reason),
                'active_sessions' => $activeSessions,
            ];
        } finally {
            StorageBackendMigrationService::releaseLaunchGuard($storageGuard);
        }
    }

    /** @param array<string,callable> $seams */
    private static function setQueuedStatus(string $agentId, int $count, array $seams, string $reason = ''): void
    {
        $message = self::waitingMessage($count, $reason);
        if (isset($seams['status'])) {
            $seams['status']($message, 1, 'queued');
            return;
        }
        UtilityService::setInstallStatus($message, 1, $agentId, 'queued_for_active_sessions');
        $path = self::baseDir() . "/install-status-$agentId";
        $status = json_decode((string)@file_get_contents($path), true);
        if (is_array($status)) {
            $status['phase'] = 'queued';
            $status['active_sessions'] = $count;
            AtomicWriteService::writeJson($path, $status);
        }
        // ACTIVITY_TRAY_PLAIN_NOTICES.md row 13: "Upgrade of Claude Code queued" with
        // "waiting for 2 open workspaces to close" in the grey line. The Store card
        // keeps $message (and its reason). ActivityService::update() keeps the label
        // an entry was created with, and setInstallStatus() has just created this one
        // as "Installing ...", so the entry is registered again under the queued title.
        // A running install worker (it holds a pid for Cancel) is never replaced.
        $opId = "install_$agentId";
        $trayStep = self::queuedTrayStep($count);
        $cur = ActivityService::get($opId);
        $workerLive = is_array($cur) && (int)($cur['pid'] ?? 0) > 0 && in_array((string)($cur['status'] ?? ''), ['running', 'stalled'], true);
        if (!$workerLive) {
            ActivityService::register($opId, 'install', self::queuedTrayTitle($agentId), [
                'step' => $trayStep, 'progress' => 1,
            ]);
        } else {
            ActivityService::update($opId, ['step' => $trayStep, 'progress' => 1]);
        }
        // A queued request has no install worker yet. It can legitimately wait
        // beyond the install watchdog's hard cap while its final legacy session
        // reaches a safe boundary, so represent that fact with ActivityService's
        // explicit no-deadline state rather than leaving this entry `running`.
        // wait() also repairs an activity a previous watchdog pass had already
        // marked failed; processReady will relaunch it once the sessions are gone.
        ActivityService::wait("install_$agentId", $trayStep, 1);
    }

    /** The tray title of a queued upgrade, e.g. "Upgrade of Claude Code queued". */
    public static function queuedTrayTitle(string $agentId): string
    {
        return 'Upgrade of ' . UtilityService::trayAgentName($agentId) . ' queued';
    }

    /** The tray grey line of a queued upgrade, e.g. "waiting for 2 open workspaces to close". */
    public static function queuedTrayStep(int $count): string
    {
        if ($count <= 0) return 'waiting for open workspaces to close';
        return 'waiting for ' . $count . ' open workspace' . ($count === 1 ? '' : 's') . ' to close';
    }

    /**
     * What the Store card says while an upgrade waits.
     *
     * docs/specs/SIDE_BY_SIDE_AGENT_INSTALLS.md Phase 3 (2026-09-15): since most
     * agents no longer wait at all, an agent that still does owes the user the
     * reason. "Waiting for 2 active sessions to close" answers what is happening
     * but not why this agent, nor what the user could do about it — and with
     * other agents visibly upgrading straight away, that gap reads as a fault.
     */
    private static function waitingMessage(int $count, string $reason = ''): string
    {
        $because = $reason !== '' ? " — $reason" : '';
        if ($count > 0) {
            return "Upgrade queued safely — waiting for $count active session"
                . ($count === 1 ? '' : 's') . ' to close' . $because;
        }
        return 'Upgrade queued safely — waiting for the session boundary' . $because;
    }

    public static function cancel(string $agentId): void
    {
        if (self::safeId($agentId) === '') return;
        @unlink(self::requestPath($agentId));
    }

    /**
     * Start one queued request only when no session can be harmed.
     *
     * @param array<string,callable> $seams
     * @return 'missing'|'waiting'|'started'|'busy'|'error'
     */
    public static function processReady(string $agentId, array $seams = []): string
    {
        $request = self::read($agentId);
        if ($request === []) return 'missing';

        $storageGuard = StorageBackendMigrationService::acquireLaunchGuard(false);
        if ($storageGuard === false) {
            return StorageBackendMigrationService::isMigrationInProgress() ? 'waiting' : 'busy';
        }
        if (StorageBackendMigrationService::isMigrationInProgress()) {
            StorageBackendMigrationService::releaseLaunchGuard($storageGuard);
            return 'waiting';
        }

        $lock = @fopen(self::baseDir() . "/pending-agent-upgrade-$agentId.lock", 'c');
        if ($lock === false || !@flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) @fclose($lock);
            StorageBackendMigrationService::releaseLaunchGuard($storageGuard);
            return 'busy';
        }

        $admission = null;

        try {
            $admission = AgentUpgradeAdmissionService::acquire($agentId);
            if ($admission === null) return 'busy';
            $sessions = isset($seams['sessions'])
                ? (array)$seams['sessions']($agentId)
                : TerminalService::listActiveSessionsForAgent($agentId);
            if ($sessions !== []) {
                self::setQueuedStatus($agentId, count($sessions), $seams);
                return 'waiting';
            }

            $installRunning = isset($seams['install_running'])
                ? (bool)$seams['install_running']($agentId)
                : self::backgroundInstallRunning($agentId);
            if ($installRunning || UpgradeRelaunchService::hasPendingAgentUpgrade($agentId)) {
                return 'busy';
            }

            // Raise the same start barrier as AgentHandler::install before the
            // second session check. New TerminalHandler starts now refuse.
            if (isset($seams['status'])) $seams['status']('Starting queued upgrade…', 5, 'installing');
            else UtilityService::setInstallStatus('Starting queued upgrade…', 5, $agentId);

            $sessions = isset($seams['sessions'])
                ? (array)$seams['sessions']($agentId)
                : TerminalService::listActiveSessionsForAgent($agentId);
            if ($sessions !== []) {
                self::setQueuedStatus($agentId, count($sessions), $seams);
                return 'waiting';
            }

            $version = (string)($request['version'] ?? '');
            $backupDest = (string)($request['backup_dest'] ?? '');
            if (isset($seams['launch'])) {
                $seams['launch']($agentId, $version, $backupDest);
            } else {
                $config = ConfigService::getConfig();
                $user = (string)($config['user'] ?? 'root');
                if ($user === '' || $user === '0') $user = 'root';
                SupervisorService::enqueue('home', $user, 'bake', 'pre_agent_install', 5, null, true);
                // #176: strip AICLI_SESSION_ID so the install job is never reapable
                // as a session descendant (the supervisor caller has none today, but
                // this keeps the guarantee regardless of who launches it).
                $cmd = 'env -u AICLI_SESSION_ID /usr/bin/php /usr/local/emhttp/plugins/unraid-aicliagents/scripts/install-bg.php '
                    . escapeshellarg($agentId) . ' ' . escapeshellarg($version) . ' ' . escapeshellarg($backupDest);
                UtilityService::execBg($cmd);
            }
            @unlink(self::requestPath($agentId));
            if (isset($seams['lifecycle'])) $seams['lifecycle']($agentId);
            else LifecycleLogService::log(LifecycleLogService::LEVEL_INFO, 'installer',
                'queued_upgrade_started', ['agent' => $agentId]);
            return 'started';
        } catch (\Throwable $e) {
            LogService::log("Queued upgrade failed for $agentId: " . $e->getMessage(), LogService::LOG_ERROR, 'PendingAgentUpgradeService');
            self::setQueuedStatus($agentId, 0, $seams);
            return 'error';
        } finally {
            AgentUpgradeAdmissionService::release($admission);
            @flock($lock, LOCK_UN);
            @fclose($lock);
            StorageBackendMigrationService::releaseLaunchGuard($storageGuard);
        }
    }

    public static function processAllReady(): void
    {
        foreach (self::pendingAgentIds() as $agentId) self::processReady($agentId);
    }

    /** Public so the cancel handler can enforce the queued-only guard (#71 cancel). */
    public static function backgroundInstallRunning(string $agentId, string $procRoot = '/proc'): bool
    {
        // #591: no shell. The id was once placed inside a hand-written quoted
        // grep string, where a quote in the id ran commands as root. The process
        // list is read from /proc and compared in PHP. An id that is not an
        // agent id shape cannot name a running install.
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/D', $agentId)) return false;
        return in_array($agentId, self::runningInstallIds($procRoot), true);
    }

    /** @return list<string> ids passed to live install-bg.php worker processes */
    public static function runningInstallIds(string $procRoot = '/proc'): array
    {
        $self = (string)getmypid();
        $ids = [];
        foreach ((array)@glob(rtrim($procRoot, '/') . '/[0-9]*/cmdline', GLOB_NOSORT) as $file) {
            if (basename(dirname($file)) === $self) continue;
            $raw = @file_get_contents($file);
            if ($raw === false || $raw === '') continue;
            $args = explode("\0", rtrim($raw, "\0"));
            foreach ($args as $i => $arg) {
                $id = (string)($args[$i + 1] ?? '');
                if (substr($arg, -14) === 'install-bg.php' && preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/D', $id)) {
                    $ids[$id] = true;
                }
            }
        }
        $out = array_keys($ids);
        sort($out);
        return $out;
    }
}
