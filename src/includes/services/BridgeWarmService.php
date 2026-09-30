<?php
/**
 * <module_context>
 *     <name>BridgeWarmService</name>
 *     <description>TERMINAL_BACKGROUND_WARM.md (2026-09-29). (Re)attach the web
 *     terminal bridge (ttyd) of a workspace whose tmux session already runs, so
 *     a switch to that workspace shows its terminal at once. Used by the page's
 *     background warm-up (AJAX attach_bridge) and by the respawn that follows a
 *     bridge refresh sweep (src/scripts/bridge-warm.php).</description>
 *     <dependencies>SessionLaunchLock, ProcessManager, TerminalService,
 *     TerminalGenerationService, ConfigService, StorageMountService,
 *     ConsolidateState, UtilityService, LogService.</dependencies>
 *     <constraints>Never starts an agent: it launches only a ttyd, and only
 *     after it found a live agent in the tmux session while it held the
 *     workspace's SessionLaunchLock. Never waits for that lock: a start, close
 *     or restart that holds it makes its own bridge. Never throws.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

require_once __DIR__ . '/SessionLaunchLock.php';

class BridgeWarmService
{
    /** How long the respawn waits for the SIGTERMed ttyd of a refresh to exit. */
    public const OLD_BRIDGE_EXIT_WAIT_SECONDS = 3.0;

    /** Log of the detached respawn after a bridge refresh sweep. */
    public const RESPAWN_LOG = '/tmp/unraid-aicliagents/bridge-warm.log';

    /** @var array<string, callable>|null test seams: workspace, bound, running, held, launch, generation, spawn. */
    public static ?array $probes = null;

    /** Test seam reset. */
    public static function resetProbes(): void
    {
        self::$probes = null;
    }

    private static function probe(string $name): ?callable
    {
        $p = self::$probes[$name] ?? null;
        return is_callable($p) ? $p : null;
    }

    /**
     * Attach the bridge of one workspace if, and only if, its agent runs.
     *
     * @return array{status:string, result?:string, terminalGeneration?:?string, message?:string}
     *   status 'ok' (result 'bound' | 'attached'), 'busy', 'not_running',
     *   'held', 'unknown' or 'failed'.
     */
    public static function attach(string $id): array
    {
        $id = (string)preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
        if ($id === '') return ['status' => 'unknown', 'message' => 'No workspace id.'];
        $ws = self::workspace($id);
        if ($ws === null) return ['status' => 'unknown', 'message' => 'Not a saved workspace.'];

        // Never wait: a start, close or restart of this workspace holds the
        // lock and makes (or removes) the bridge itself (#352).
        if (!SessionLaunchLock::acquire($id, 0.0)) {
            return ['status' => 'busy'];
        }
        try {
            if (self::isBound($id)) {
                return ['status' => 'ok', 'result' => 'bound', 'terminalGeneration' => self::generation($id)];
            }
            if (!self::isRunning($id)) {
                return ['status' => 'not_running'];
            }
            $held = self::heldReason($ws['agentId'], $ws['path']);
            if ($held !== '') {
                return ['status' => 'held', 'message' => $held];
            }
            self::launch($id, $ws['path'], $ws['agentId']);
            if (!self::isBound($id)) {
                return ['status' => 'failed', 'message' => 'The terminal bridge did not come up.'];
            }
            return ['status' => 'ok', 'result' => 'attached', 'terminalGeneration' => self::generation($id)];
        } catch (\Throwable $e) {
            LogService::log("Bridge warm-up of $id failed: " . $e->getMessage(), LogService::LOG_WARN, 'BridgeWarmService');
            return ['status' => 'failed', 'message' => 'Bridge warm-up failed.'];
        } finally {
            SessionLaunchLock::release($id);
        }
    }

    /**
     * After a bridge refresh sweep: start the new bridge of every refreshed
     * workspace in the background, so no one waits for it on the next switch.
     * Returns the spawned pid (0 when nothing was spawned).
     *
     * @param string[] $ids
     */
    public static function spawnRespawn(array $ids): int
    {
        $ids = array_values(array_filter(array_map(
            static fn($v) => (string)preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$v),
            $ids
        ), static fn($v) => $v !== ''));
        if ($ids === []) return 0;
        $spawn = self::probe('spawn');
        if ($spawn) return (int)$spawn($ids);
        $script = dirname(__DIR__, 2) . '/scripts/bridge-warm.php';
        if (!is_file($script)) return 0;
        return UtilityService::spawnDetached(array_merge(['php', $script], $ids), self::RESPAWN_LOG);
    }

    /**
     * The respawn body (bridge-warm.php): for each id, wait for the old ttyd to
     * exit, then attach. One id after the other.
     *
     * @param string[] $ids
     * @return array<string, string> id => status/result
     */
    public static function respawnAfterRefresh(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $id = (string)preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$id);
            if ($id === '') continue;
            $deadline = microtime(true) + self::OLD_BRIDGE_EXIT_WAIT_SECONDS;
            while (self::isBound($id) && microtime(true) < $deadline) {
                usleep(100000);
            }
            $r = self::attach($id);
            $out[$id] = $r['status'] . (isset($r['result']) ? '/' . $r['result'] : '');
            LogService::log("Bridge warm-up after refresh: $id -> " . $out[$id], LogService::LOG_INFO, 'BridgeWarmService');
        }
        return $out;
    }

    /** @return array{path:string, agentId:string}|null */
    private static function workspace(string $id): ?array
    {
        $p = self::probe('workspace');
        if ($p) return $p($id);
        foreach (ConfigService::getWorkspaces()['sessions'] ?? [] as $w) {
            if (is_array($w) && (string)($w['id'] ?? '') === $id) {
                $agentId = (string)($w['agentId'] ?? '');
                if ($agentId === '') return null;
                return ['path' => (string)($w['path'] ?? ''), 'agentId' => $agentId];
            }
        }
        return null;
    }

    /** @phpstan-impure the answer changes when a launch binds a ttyd */
    private static function isBound(string $id): bool
    {
        $p = self::probe('bound');
        return $p ? (bool)$p($id) : ProcessManager::isTtydBound($id);
    }

    /** @phpstan-impure */
    private static function isRunning(string $id): bool
    {
        $p = self::probe('running');
        return $p ? (bool)$p($id) : ProcessManager::isRunning($id);
    }

    private static function generation(string $id): ?string
    {
        $p = self::probe('generation');
        if ($p) return $p($id);
        return class_exists(TerminalGenerationService::class) ? TerminalGenerationService::current($id) : null;
    }

    private static function launch(string $id, string $path, string $agentId): void
    {
        $p = self::probe('launch');
        if ($p) { $p($id, $path, $agentId); return; }
        TerminalService::attachBridgeLocked($id, $path === '' ? null : $path, $agentId);
    }

    /** '' when nothing holds the workspace; else a short reason. */
    private static function heldReason(string $agentId, string $path): string
    {
        $p = self::probe('held');
        if ($p) return (string)$p($agentId, $path);
        try {
            $handlerFile = dirname(__DIR__) . '/handlers/AgentHandler.php';
            if (!class_exists('\AICliAgents\Handlers\AgentHandler', false) && is_file($handlerFile)) {
                require_once $handlerFile;
            }
            if (class_exists('\AICliAgents\Handlers\AgentHandler', false)
                && \AICliAgents\Handlers\AgentHandler::isInstallInProgress($agentId)) {
                return 'agent install in progress';
            }
            $consolidate = __DIR__ . '/ConsolidateState.php';
            if (is_file($consolidate)) require_once $consolidate;
            $user = (string)(ConfigService::getConfig()['user'] ?? 'root');
            if ($user === '' || $user === '0') $user = 'root';
            if (class_exists(ConsolidateState::class) && ConsolidateState::isHomeConsolidating($user)) {
                return 'home consolidate in progress';
            }
            if ($path !== '' && !StorageMountService::isPathAvailable($path)) {
                return 'workspace folder not available';
            }
        } catch (\Throwable $e) {
            // On any doubt, do not attach.
            return 'state check failed';
        }
        return '';
    }
}
