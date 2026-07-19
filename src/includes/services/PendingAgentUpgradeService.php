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
        return rtrim(getenv('AICLI_TMP_BASE') ?: '/tmp/unraid-aicliagents', '/');
    }

    private static function safeId(string $agentId): string
    {
        return preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $agentId) ? $agentId : '';
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
                                 int $activeSessions, array $seams = []): array
    {
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
        self::setQueuedStatus($agentId, $activeSessions, $seams);
        if (isset($seams['wake'])) $seams['wake']();
        else SupervisorService::wake();
        return [
            'status' => 'queued',
            'message' => self::waitingMessage($activeSessions),
            'active_sessions' => $activeSessions,
        ];
    }

    /** @param array<string,callable> $seams */
    private static function setQueuedStatus(string $agentId, int $count, array $seams): void
    {
        $message = self::waitingMessage($count);
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
        ActivityService::update("install_$agentId", [
            'type' => 'install', 'label' => "Upgrade queued for $agentId",
            'step' => $message, 'progress' => 1,
        ]);
    }

    private static function waitingMessage(int $count): string
    {
        if ($count > 0) {
            return "Upgrade queued safely — waiting for $count active session" . ($count === 1 ? '' : 's') . ' to close';
        }
        return 'Upgrade queued safely — waiting for the session boundary';
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

        $lock = @fopen(self::baseDir() . "/pending-agent-upgrade-$agentId.lock", 'c');
        if ($lock === false || !@flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) @fclose($lock);
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
                $cmd = '/usr/bin/php /usr/local/emhttp/plugins/unraid-aicliagents/scripts/install-bg.php '
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
        }
    }

    public static function processAllReady(): void
    {
        foreach (self::pendingAgentIds() as $agentId) self::processReady($agentId);
    }

    private static function backgroundInstallRunning(string $agentId): bool
    {
        $cmd = "timeout 2 ps aux | grep 'install-bg.php " . escapeshellarg($agentId) . "' | grep -v grep";
        exec($cmd, $out, $rc);
        return $rc === 0;
    }
}
