<?php
/**
 * Coordinates the global storage-engine migration.  The shell storage
 * component owns each entity's mount, layer and manifest authority; this class
 * owns only policy validation, entity enumeration, session eviction and the
 * final config commit.
 */

namespace AICliAgents\Services;

final class StorageBackendMigrationService
{
    private const CONVERSION_RETRY_LIMIT = 6;
    private const CONVERSION_RETRY_DELAY_SECONDS = 5;
    private const LAUNCH_GUARD_WAIT_TIMEOUT_SECONDS = 60;
    private const LAUNCH_GUARD_WAIT_MICROSECONDS = 100000;
    private const SESSION_STOP_TIMEOUT_SECONDS = 30;
    private const INSTALL_WAIT_TIMEOUT_SECONDS = 1200;
    private const ACTIVITY_ID = 'storage_backend_migration';

    /**
     * Read-only: what a change of one kind to $target would do, and whether it is allowed.
     * $kind is "homes", "agents" or "all" (the one-engine call path of older callers).
     * The check compares with the REAL state of the kind, not only with the saved value:
     * a kind that is mixed can still be completed.
     */
    public static function preflight(string $target, ?array $config = null, array $seams = [], string $kind = StorageBackendPolicyService::KIND_ALL): array
    {
        $config = $config ?? ConfigService::getConfig();
        $kind = StorageBackendPolicyService::normalizeKind($kind);
        $target = StorageBackendPolicyService::normalizeMode($target);
        $current = StorageBackendPolicyService::kindMode($config, $kind);
        $allEntities = $seams['entities'] ?? self::entities($config, $seams);
        $entities = self::entitiesForTarget($allEntities, $target, $kind, $current, self::realBackendMap($seams));
        if ($target === $current && $entities === []) {
            return [
                'status' => 'error',
                'message' => self::alreadySetMessage($current, $kind),
                'target' => $target,
                'current' => $current,
                'kind' => $kind,
            ];
        }
        $verdicts = $seams['verdicts'] ?? StorageBackendPolicyService::targetVerdicts($config);
        $refused = [];
        $usb = [];
        foreach ($verdicts as $verdictKind => $verdict) {
            if ($kind === StorageBackendPolicyService::KIND_HOMES && $verdictKind !== 'home') continue;
            if ($kind === StorageBackendPolicyService::KIND_AGENTS && $verdictKind !== 'agent') continue;
            if (!empty($verdict['refuse'])) $refused[] = $verdictKind;
            if (!empty($verdict['is_usb_or_removable'])) $usb[] = $verdictKind;
        }
        if ($target === StorageBackendPolicyService::PASSTHROUGH && $refused !== []) {
            return [
                'status' => 'error',
                'message' => 'Plain folders cannot use the refused storage target for: ' . implode(', ', $refused) . '.',
                'target' => $target,
                'kind' => $kind,
                'targets' => $verdicts,
            ];
        }
        $capacity = self::capacityPreflight($target, $config, $verdicts, $entities, $seams);
        if (($capacity['status'] ?? '') !== 'ok') {
            return $capacity + ['target' => $target, 'kind' => $kind, 'targets' => $verdicts];
        }
        $noun = self::kindNoun($kind);
        $message = $target === StorageBackendPolicyService::PASSTHROUGH
            ? 'All ' . $noun . ' that are not plain folders yet will be converted to plain folders. Existing sessions will be closed safely, old SquashFS data will be retained in the migration archive, and new installs can activate side by side.'
            : 'All ' . $noun . ' that are not SquashFS layers yet will be converted to SquashFS layers with writable zram state. Existing plain folders will be archived only after the layer and manifest are verified.';
        if ($usb !== []) {
            $message .= ' The probe identified removable/USB-backed storage (' . implode(', ', $usb) . '); SquashFS layers are recommended to reduce wear.';
        }
        $message .= ' The migration needs about ' . BootSpaceService::human((int)$capacity['capacity']['forward_bytes'])
            . ' for the new storage and ' . BootSpaceService::human((int)$capacity['capacity']['rollback_bytes'])
            . ' for rollback copies. It must leave ' . BootSpaceService::human((int)$capacity['capacity']['reserve_bytes']) . ' free.';
        return [
            'status' => 'ok',
            'target' => $target,
            'kind' => $kind,
            'current' => $current,
            'targets' => $verdicts,
            'requires_acknowledgement' => $usb !== [],
            'message' => $message,
            'capacity' => $capacity['capacity'],
            'item_count' => count($entities),
        ];
    }

    /**
     * The real state of both kinds for the Configuration tab: `homes` and `agents`, each with
     * `state` (plain | layers | mixed | none), `label` (for example "Mixed: 1 of 2 homes are
     * plain folders"), the counts and the saved setting (`saved`). The label comes from the
     * storage status, not from the saved setting. A caller may pass the status (tests).
     *
     * @param array<string,mixed>|null $status storage status, or null to read it
     * @param list<string>|null $installedAgentIds installed agent ids, or null to read them
     */
    public static function kindStates(?array $status = null, ?array $installedAgentIds = null, ?array $config = null): array
    {
        $config = $config ?? ConfigService::getConfig();
        $status = $status ?? StorageMetricsService::getStatus();
        if ($installedAgentIds === null) {
            $installedAgentIds = [];
            if (class_exists(AgentRegistry::class)) {
                foreach (AgentRegistry::getRegistry() as $id => $agent) {
                    if (!empty($agent['is_installed'])) $installedAgentIds[] = (string)$id;
                }
            }
        }
        $real = StorageBackendPolicyService::realStates($status, $installedAgentIds);
        $out = ['status' => 'ok'];
        foreach (['homes', 'agents'] as $kind) {
            unset($real[$kind]['backends']);
            $out[$kind] = $real[$kind] + ['saved' => StorageBackendPolicyService::kindMode($config, $kind)];
        }
        return $out;
    }

    /** Plain words for the items of one kind. */
    private static function kindNoun(string $kind): string
    {
        $kind = StorageBackendPolicyService::normalizeKind($kind);
        return $kind === StorageBackendPolicyService::KIND_HOMES ? 'homes'
            : ($kind === StorageBackendPolicyService::KIND_AGENTS ? 'agents' : 'agents and homes');
    }

    private static function alreadySetMessage(string $mode, string $kind = StorageBackendPolicyService::KIND_ALL): string
    {
        $noun = ucfirst(self::kindNoun($kind));
        return $mode === StorageBackendPolicyService::PASSTHROUGH
            ? $noun . ' already use plain folders.'
            : $noun . ' already use SquashFS layers.';
    }

    /**
     * The backend of each item in the real storage status: "type/id" => "flash" | "passthrough".
     * "flash" is the layer engine. A caller that supplies its own entity list gets an empty map
     * unless it supplies `real_backends`: the live status is never read for it.
     *
     * @return array<string,string>
     */
    private static function realBackendMap(array $seams): array
    {
        if (isset($seams['real_backends']) && is_array($seams['real_backends'])) return $seams['real_backends'];
        if (isset($seams['entities'])) return [];
        try {
            $installed = [];
            if (class_exists(AgentRegistry::class)) {
                foreach (AgentRegistry::getRegistry() as $id => $agent) {
                    if (!empty($agent['is_installed'])) $installed[] = (string)$id;
                }
            }
            $real = StorageBackendPolicyService::realStates(StorageMetricsService::getStatus(), $installed);
        } catch (\Throwable $e) {
            return [];
        }
        $map = [];
        foreach ($real['homes']['backends'] as $id => $backend) $map['home/' . $id] = $backend;
        foreach ($real['agents']['backends'] as $id => $backend) $map['agent/' . $id] = $backend;
        return $map;
    }

    /**
     * The items one kind must convert for $target: the items of that kind that are not on
     * the target yet. An item with no entry in the real map counts as being on the saved
     * setting ($saved), so it needs conversion only when $target differs from $saved.
     *
     * @param array<string,string> $backends "type/id" => "flash" | "passthrough"
     */
    private static function entitiesForTarget(array $entities, string $target, string $kind, string $saved, array $backends): array
    {
        $kind = StorageBackendPolicyService::normalizeKind($kind);
        $out = [];
        foreach ($entities as $entity) {
            $type = (string)($entity['type'] ?? '');
            if ($kind === StorageBackendPolicyService::KIND_HOMES && $type !== 'home') continue;
            if ($kind === StorageBackendPolicyService::KIND_AGENTS && $type !== 'agent') continue;
            $key = $type . '/' . (string)($entity['id'] ?? '');
            $now = isset($backends[$key]) ? StorageBackendPolicyService::engineOfBackend((string)$backends[$key]) : $saved;
            if ($now !== $target) $out[] = $entity;
        }
        return $out;
    }

    /**
     * Check all new and rollback copies against one fresh measurement and live free space.
     * The old representation stays in place until the new one is verified, so it does not
     * free space for this check. Tests may supply snapshot, entities and disk seams.
     */
    private static function capacityPreflight(string $target, array $config, array $verdicts, array $entities, array $seams): array
    {
        $now = (int)($seams['now'] ?? time());
        $snapshot = $seams['snapshot'] ?? BootSpaceService::read();
        if (!is_array($snapshot) || BootSpaceService::isStale($snapshot, $now) || !array_key_exists('partial', $snapshot) || !empty($snapshot['partial'])) {
            return ['status' => 'error', 'message' => 'The Boot disk space report is missing, old or incomplete. Refresh that report before you try the engine change.'];
        }
        $agentPath = trim((string)($verdicts['agent']['path'] ?? ''));
        if ($agentPath === '') $agentPath = trim((string)($config['agent_storage_path'] ?? '')) ?: StoragePathResolver::pluginBase();
        $agentPath = rtrim($agentPath, '/');
        $homePath = rtrim((string)($verdicts['home']['path'] ?? StoragePathResolver::homePersistPathFromConfig($config)), '/');
        $measuredAgentPath = rtrim((string)($snapshot['persist'] ?? ''), '/');
        $measuredHomePath = rtrim((string)($snapshot['home_persist'] ?? ''), '/');
        if (($measuredAgentPath !== '' && $measuredAgentPath !== $agentPath)
            || ($measuredHomePath !== '' && $measuredHomePath !== $homePath)
            || (($measuredAgentPath === '' || $measuredHomePath === '') && $agentPath !== $homePath)) {
            return ['status' => 'error', 'message' => 'The Boot disk space report does not match both current storage paths. Refresh that report before you try the engine change.'];
        }

        $agents = [];
        foreach (($snapshot['agents'] ?? []) as $row) {
            if (is_array($row) && isset($row['id'])) $agents[(string)$row['id']] = $row;
        }
        $plainAgentEstimates = [];
        foreach (($snapshot['convert']['agents'] ?? []) as $row) {
            if (is_array($row) && isset($row['id'], $row['plain_bytes'])) $plainAgentEstimates[(string)$row['id']] = $row;
        }
        $homeParts = [];
        foreach (($snapshot['parts'] ?? []) as $part) {
            if (!is_array($part) || !isset($part['key'], $part['bytes'])) continue;
            $homeParts[(string)$part['key']] = max(0, (int)$part['bytes']);
        }

        $diskFor = $seams['disk'] ?? static function (string $path): array {
            $stat = @stat($path);
            return [
                'device' => $stat === false ? null : (string)($stat['dev'] ?? ''),
                'total' => (int)@disk_total_space($path),
                'free' => (int)@disk_free_space($path),
            ];
        };
        $groups = [];
        $forwardTotal = 0;
        $rollbackTotal = 0;
        foreach ($entities as $entity) {
            $type = (string)($entity['type'] ?? '');
            $id = (string)($entity['id'] ?? '');
            if (!in_array($type, ['agent', 'home'], true) || $id === '') continue;
            $forwardBytes = 0;
            $rollbackBytes = 0;
            if ($type === 'agent') {
                $row = $agents[$id] ?? null;
                // A complete report has scanned both persistence roots. An installed
                // agent with no measured row has no saved files to copy.
                if (!is_array($row)) continue;
                $storage = (string)($row['storage'] ?? '');
                if ($target === StorageBackendPolicyService::PASSTHROUGH && $storage === 'layers') {
                    $estimate = $plainAgentEstimates[$id] ?? null;
                    $forwardBytes = is_array($estimate)
                        ? max(0, (int)$estimate['plain_bytes'])
                        : (int)ceil(max(0, (int)($row['current_bytes'] ?? 0) + (int)($row['previous_bytes'] ?? 0)) * AgentStorageService::UNPACKED_RATIO);
                    $rollbackBytes = max(
                        max(0, (int)($row['current_bytes'] ?? 0) + (int)($row['previous_bytes'] ?? 0)),
                        (int)ceil($forwardBytes / AgentStorageService::UNPACKED_RATIO)
                    );
                } elseif ($target === StorageBackendPolicyService::LAYERING && $storage === 'plain') {
                    // The SquashFS output can approach the plain folder size. Use that
                    // full size for the new layer and again for a plain rollback copy.
                    $forwardBytes = max(0, (int)($row['current_bytes'] ?? 0));
                    $rollbackBytes = $forwardBytes;
                } elseif (!in_array($storage, ['layers', 'plain'], true)) {
                    return ['status' => 'error', 'message' => 'The Boot disk space report cannot identify the storage for agent ' . $id . '. Refresh that report before you try the engine change.'];
                }
            } else {
                $layerKey = 'home:' . $id . ':layers';
                $plainKeys = ['home:' . $id . ':history', 'home:' . $id . ':caches', 'home:' . $id . ':other'];
                if (array_key_exists($layerKey, $homeParts)) {
                    if ($target === StorageBackendPolicyService::PASSTHROUGH) {
                        // Layer files hold compressed content. The measured part uses allocated
                        // bytes, so the established 5.8 expansion estimate is conservative here.
                        $forwardBytes = (int)ceil($homeParts[$layerKey] * AgentStorageService::UNPACKED_RATIO);
                        $rollbackBytes = max($homeParts[$layerKey], (int)ceil($forwardBytes / AgentStorageService::UNPACKED_RATIO));
                    }
                } elseif (array_reduce($plainKeys, static fn(bool $found, string $key): bool => $found || array_key_exists($key, $homeParts), false)) {
                    if ($target === StorageBackendPolicyService::LAYERING) {
                        // A SquashFS layer may be close to the plain source size. The
                        // migration also needs a fresh plain copy if it must roll back.
                        $forwardBytes = array_sum(array_map(static fn(string $key): int => $homeParts[$key] ?? 0, $plainKeys));
                        $rollbackBytes = $forwardBytes;
                    }
                } else {
                    // Homes with no layer or plain-folder part have no saved files to copy.
                    continue;
                }
            }
            if ($forwardBytes === 0 && $rollbackBytes === 0) continue;
            $path = (string)($verdicts[$type]['path'] ?? ($type === 'agent' ? ($config['agent_storage_path'] ?? '') : ($config['home_storage_path'] ?? '')));
            if ($path === '') return ['status' => 'error', 'message' => 'The storage path for ' . $type . '/' . $id . ' is not available for the space check.'];
            $disk = $diskFor($path);
            $device = (string)($disk['device'] ?? '');
            $total = (int)($disk['total'] ?? 0);
            $free = (int)($disk['free'] ?? 0);
            if ($device === '' || $total <= 0 || $free < 0) {
                return ['status' => 'error', 'message' => 'Free space for ' . $type . '/' . $id . ' could not be checked. No storage was changed.'];
            }
            $key = $device;
            if (!isset($groups[$key])) $groups[$key] = ['total' => $total, 'free' => $free, 'forward' => 0, 'rollback' => 0];
            $groups[$key]['forward'] += $forwardBytes;
            $groups[$key]['rollback'] += $rollbackBytes;
            $forwardTotal += $forwardBytes;
            $rollbackTotal += $rollbackBytes;
        }

        $reserveTotal = 0;
        foreach ($groups as $group) {
            $reserve = max(AgentStorageService::RESERVE_BYTES, intdiv((int)$group['total'], AgentStorageService::RESERVE_DIVISOR));
            $reserveTotal += $reserve;
            $write = (int)$group['forward'] + (int)$group['rollback'];
            if ((int)$group['free'] < $write + $reserve) {
                $need = $write + $reserve;
                return [
                    'status' => 'error',
                    'message' => 'The migration needs about ' . BootSpaceService::human((int)$group['forward'])
                        . ' for new storage and ' . BootSpaceService::human((int)$group['rollback'])
                        . ' for rollback copies, plus ' . BootSpaceService::human($reserve) . ' free. It needs '
                        . BootSpaceService::human($need) . ' free, but only ' . BootSpaceService::human((int)$group['free'])
                        . ' is available. Free space, then try again. No storage was changed.',
                    'capacity' => ['forward_bytes' => (int)$group['forward'], 'rollback_bytes' => (int)$group['rollback'],
                        'write_bytes' => $write, 'reserve_bytes' => $reserve,
                        'required_bytes' => $need, 'available_bytes' => (int)$group['free'], 'fits' => false],
                ];
            }
        }
        return ['status' => 'ok', 'capacity' => [
            'forward_bytes' => $forwardTotal, 'rollback_bytes' => $rollbackTotal,
            'write_bytes' => $forwardTotal + $rollbackTotal, 'reserve_bytes' => $reserveTotal,
            'required_bytes' => $forwardTotal + $rollbackTotal + $reserveTotal,
            'available_bytes' => array_sum(array_map(static fn(array $g): int => (int)$g['free'], $groups)),
            'fits' => true,
        ]];
    }

    /** Lock file of the all-entity engine migration. Tests may override it. */
    private static function lockPath(): string
    {
        $e = getenv('AICLI_BACKEND_MIGRATE_LOCK');
        return ($e !== false && $e !== '') ? $e : SecretPaths::stateRoot() . '/.backend_migrate.lock';
    }

    /**
     * Shared by agent auto-launch and this migration. Auto-launch takes a
     * shared lock; migration takes the exclusive lock. This makes the two
     * operations mutually exclusive without stopping the supervisor.
     *
     * @return resource|false
     */
    public static function acquireLaunchGuard(bool $exclusive)
    {
        $lockFile = self::lockPath();
        @mkdir(dirname($lockFile), 0755, true);
        $lock = @fopen($lockFile, 'c');
        if ($lock === false) return false;
        $mode = $exclusive ? LOCK_EX : LOCK_SH;
        if (!@flock($lock, $mode | LOCK_NB)) { @fclose($lock); return false; }
        return $lock;
    }

    public static function releaseLaunchGuard($lock): void
    {
        if (!is_resource($lock)) return;
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }

    /** True while a queued or active global migration owns the storage policy. */
    public static function isMigrationInProgress(): bool
    {
        $status = self::status();
        if (in_array((string)($status['status'] ?? ''), ['queued', 'running'], true)) return true;
        return ($status['status'] ?? '') === 'interrupted' && !empty($status['migrated']);
    }

    /** Plain server refusal shared by agent install, removal and upgrade paths. */
    public static function migrationRefusal(): ?array
    {
        if (!self::isMigrationInProgress()) return null;
        return [
            'status' => 'error',
            'reason' => 'storage_migration',
            'message' => 'Wait for the storage migration to finish before installing, removing, or upgrading agents.',
        ];
    }

    /** Save a refusal so both status views retain the reason a migration did not start. */
    private static function recordPreflightFailure(array $check, string $target, array $config, array $entities, string $kind = StorageBackendPolicyService::KIND_ALL): array
    {
        $now = time();
        $state = [
            'status' => 'error', 'phase' => 'preflight',
            'kind' => StorageBackendPolicyService::normalizeKind($kind),
            'from' => StorageBackendPolicyService::kindMode($config, $kind),
            'target' => StorageBackendPolicyService::normalizeMode($target),
            'started_at' => $now, 'updated_at' => $now, 'completed' => 0,
            'total' => count($entities), 'current' => '',
            'message' => (string)($check['message'] ?? 'The migration could not start. No storage was changed.'),
        ];
        if (isset($check['capacity']) && is_array($check['capacity'])) $state['capacity'] = $check['capacity'];
        self::writeStatus($state);
        return $check;
    }

    /** Reserve the shared migration right, then start the worker without holding the page open. */
    public static function startAsync(string $target, bool $acknowledgeWear = false, ?array $config = null, array $seams = [], string $kind = StorageBackendPolicyService::KIND_ALL): array
    {
        $kind = StorageBackendPolicyService::normalizeKind($kind);
        $config = $config ?? ConfigService::getConfig();
        require_once __DIR__ . '/FileStorage.php';
        require_once __DIR__ . '/StoragePathMoveJobService.php';
        $migrationLock = FileStorage::acquireMigrationLock();
        if ($migrationLock === null) {
            return ['status' => 'error', 'message' => 'Another storage migration is already running. Wait for it to finish, then try again.'];
        }
        try {
            $conflict = self::pathMoveConflictMessage();
            if ($conflict !== null) return ['status' => 'error', 'message' => $conflict];
            return self::startAsyncWithMigrationLock($target, $acknowledgeWear, $config, $seams, $migrationLock, $kind);
        } finally {
            FileStorage::releaseMigrationLock($migrationLock);
        }
    }

    /** Queue continuation or rollback after a worker stops and its durable record is recovered. */
    public static function recoverInterruptedAsync(string $operation, ?array $config = null, array $seams = []): array
    {
        $operation = strtolower(trim($operation));
        if (!in_array($operation, ['continue', 'rollback'], true)) {
            return ['status' => 'error', 'message' => 'Choose Continue or Put back.'];
        }
        $config = $config ?? ConfigService::getConfig();
        $prior = self::status();
        if (($prior['status'] ?? '') !== 'interrupted') {
            return ['status' => 'error', 'message' => 'There is no interrupted engine change to recover.'];
        }
        $kind = StorageBackendPolicyService::normalizeKind((string)($prior['kind'] ?? ''));
        $from = StorageBackendPolicyService::normalizeMode((string)($prior['from'] ?? 'layering'));
        $target = StorageBackendPolicyService::normalizeMode((string)($prior['target'] ?? 'layering'));
        $migrated = is_array($prior['migrated'] ?? null) ? array_values(array_unique(array_map('strval', $prior['migrated']))) : [];
        if ($from === $target) return ['status' => 'error', 'message' => 'The interrupted engine change has no valid target. Check storage before you continue.'];
        if ($operation === 'continue') {
            $check = self::preflight($target, $config, $seams, $kind);
            if (($check['status'] ?? '') !== 'ok') return $check;
        }

        require_once __DIR__ . '/FileStorage.php';
        require_once __DIR__ . '/StoragePathMoveJobService.php';
        $migrationLock = FileStorage::acquireMigrationLock();
        if ($migrationLock === null) return ['status' => 'error', 'message' => 'Another storage operation is already running. Wait for it to finish, then try again.'];
        $launchLock = false;
        try {
            $conflict = self::pathMoveConflictMessage();
            if ($conflict !== null) return ['status' => 'error', 'message' => $conflict];
            $guardWait = $seams['launch_guard_wait'] ?? static function (int $microseconds): void { usleep($microseconds); };
            $waitStartedAt = microtime(true);
            do {
                $launchLock = self::acquireLaunchGuard(true);
                if ($launchLock !== false) break;
                if (microtime(true) - $waitStartedAt >= self::LAUNCH_GUARD_WAIT_TIMEOUT_SECONDS) {
                    return ['status' => 'error', 'message' => 'A workspace start did not finish in time. No storage was changed. Try again.'];
                }
                $guardWait(self::LAUNCH_GUARD_WAIT_MICROSECONDS);
            } while (true);

            $now = time();
            $entities = $operation === 'continue' ? self::entitiesForTarget($seams['entities'] ?? self::entities($config, $seams), $target, $kind, $from, self::realBackendMap($seams)) : array_map(static function (string $key): array {
                [$type, $id] = explode('/', $key, 2);
                return ['type' => $type, 'id' => $id];
            }, array_filter($migrated, static fn(string $key): bool => preg_match('#^(agent|home)/[A-Za-z0-9_.-]{1,128}$#D', $key) === 1));
            $state = [
                'status' => 'queued', 'phase' => 'queued', 'kind' => $kind, 'from' => $from, 'target' => $target,
                'operation' => $operation, 'started_at' => $now, 'updated_at' => $now,
                'completed' => 0, 'total' => count($entities), 'current' => '', 'worker_pid' => 0,
                'migrated' => $migrated, 'entities' => self::entityJournal($entities, $from, $target),
                'sessions_to_resume' => self::safeSessionIds((array)($prior['sessions_to_resume'] ?? [])),
                'message' => $operation === 'continue' ? 'Continuing the interrupted engine change.' : 'Putting back the items changed by the interrupted engine change.',
            ];
            if (!self::writeStatus($state) || !self::writeDurableStatus($state)) {
                return ['status' => 'error', 'message' => 'The recovery status could not be saved. No storage was changed.'];
            }
            FileStorage::releaseMigrationLock($migrationLock);
            $migrationLock = null;
            self::releaseLaunchGuard($launchLock);
            $launchLock = false;
            $spawn = $seams['spawn_recovery'] ?? static function (string $targetMode, string $action) use ($kind): int {
                require_once __DIR__ . '/UtilityService.php';
                $script = dirname(__DIR__, 2) . '/scripts/storage-backend-migrate.php';
                return UtilityService::spawnDetached(
                    [PHP_BINARY, $script, $targetMode, '0', $action, $kind],
                    self::statusPath() . '.log',
                    true
                );
            };
            $pid = (int)$spawn($operation === 'rollback' ? $from : $target, $operation);
            if ($pid < 1) {
                $state['status'] = 'error';
                $state['phase'] = 'error';
                $state['message'] = 'The recovery worker did not start. No storage was changed.';
                $state['updated_at'] = time();
                self::writeStatus($state);
                self::writeDurableStatus($state);
                return ['status' => 'error', 'message' => $state['message']];
            }
            $current = self::readStatus();
            if (($current['status'] ?? '') === 'queued') {
                $state['worker_pid'] = $pid;
                $state['updated_at'] = time();
                self::writeStatus($state);
                self::writeDurableStatus($state);
            }
            return ['status' => 'accepted', 'message' => 'Storage recovery started. Workspaces stay closed until it finishes.', 'worker_pid' => $pid];
        } finally {
            self::releaseLaunchGuard($launchLock);
            FileStorage::releaseMigrationLock($migrationLock);
        }
    }

    /** Restore only the entities recorded as changed by the interrupted migration. */
    public static function restoreInterrupted(array $seams = []): array
    {
        require_once __DIR__ . '/FileStorage.php';
        require_once __DIR__ . '/StoragePathMoveJobService.php';
        $migrationLock = FileStorage::acquireMigrationLock();
        if ($migrationLock === null) return ['status' => 'error', 'message' => 'Another storage operation is already running.'];
        $launchLock = false;
        try {
            $prior = self::readStatus();
            $from = StorageBackendPolicyService::normalizeMode((string)($prior['from'] ?? 'layering'));
            $target = StorageBackendPolicyService::normalizeMode((string)($prior['target'] ?? 'layering'));
            $migrated = is_array($prior['migrated'] ?? null) ? array_values(array_unique(array_map('strval', $prior['migrated']))) : [];
            if (($prior['operation'] ?? '') !== 'rollback' || $from === $target) {
                return ['status' => 'error', 'message' => 'The put-back request does not match the saved recovery record.'];
            }
            $guardWait = $seams['launch_guard_wait'] ?? static function (int $microseconds): void { usleep($microseconds); };
            $waitStartedAt = microtime(true);
            do {
                $launchLock = self::acquireLaunchGuard(true);
                if ($launchLock !== false) break;
                if (microtime(true) - $waitStartedAt >= self::LAUNCH_GUARD_WAIT_TIMEOUT_SECONDS) {
                    return ['status' => 'error', 'message' => 'A workspace start did not finish in time. Try again.'];
                }
                $guardWait(self::LAUNCH_GUARD_WAIT_MICROSECONDS);
            } while (true);

            $state = $prior;
            $state['status'] = 'running';
            $state['phase'] = 'rolling_back';
            $state['updated_at'] = time();
            $state['message'] = 'Putting back storage items changed by the interrupted engine change.';
            self::writeStatus($state);
            self::writeDurableStatus($state);
            self::updateMigrationActivity($state);
            if (isset($seams['evict']) && is_callable($seams['evict'])) {
                ($seams['evict'])(false, self::safeSessionIds((array)($state['sessions_to_resume'] ?? [])));
            } elseif (class_exists(TerminalService::class)) {
                TerminalService::captureResumeForShutdown(null, 60);
                ProcessManager::evictAllForStorageMigration();
            }
            $waitSessions = $seams['wait_sessions'] ?? static fn(array $ids): bool => ProcessManager::waitForSessionsStopped($ids, self::SESSION_STOP_TIMEOUT_SECONDS);
            if (!$waitSessions(self::safeSessionIds((array)($state['sessions_to_resume'] ?? [])))) {
                $state['status'] = 'interrupted';
                $state['phase'] = 'interrupted';
                $state['message'] = 'Some workspaces did not stop in time. No storage was changed. Check the Activity tray, then try Put back.';
                $state['updated_at'] = time();
                self::writeStatus($state);
                self::writeDurableStatus($state);
                self::failMigrationActivity($state['message']);
                return ['status' => 'interrupted', 'message' => $state['message'], 'migrated' => $migrated];
            }
            $convert = $seams['convert'] ?? static fn(string $type, string $id, string $mode, bool $direct, bool $rollback): array => FileStorage::migrateBackend($type, $id, $mode, $direct, $rollback);
            $failed = [];
            foreach (array_reverse($migrated) as $key) {
                if (preg_match('#^(agent|home)/([A-Za-z0-9_.-]{1,128})$#D', $key, $m) !== 1) continue;
                $state['current'] = $key;
                $state['message'] = 'Restoring ' . $key . ' to ' . StorageBackendPolicyService::label($from);
                $state['updated_at'] = time();
                self::writeStatus($state);
                self::updateMigrationActivity($state);
                $result = $convert($m[1], $m[2], $from, $from === StorageBackendPolicyService::PASSTHROUGH, true);
                if ((int)($result['exit'] ?? 1) !== 0) {
                    $failed[] = $key;
                } else {
                    $migrated = array_values(array_diff($migrated, [$key]));
                }
                $state['migrated'] = $migrated;
                $state['updated_at'] = time();
                self::writeStatus($state);
                self::writeDurableStatus($state);
                self::updateMigrationActivity($state);
            }
            $state['status'] = $migrated === [] ? 'complete' : 'interrupted';
            $state['phase'] = $state['status'];
            if ($migrated === []) {
                $state['from'] = $target;
                $state['target'] = $from;
            }
            $state['current'] = '';
            $state['message'] = $migrated === []
                ? 'The changed items are back on ' . StorageBackendPolicyService::label($from) . '.'
                : 'Some items could not be put back: ' . implode(', ', $failed) . '. Try again or continue the engine change.';
            $state['updated_at'] = time();
            self::writeStatus($state);
            self::writeDurableStatus($state);
            if ($migrated === []) {
                self::finishMigrationActivity('Storage items are back on the prior engine.');
            } else {
                self::failMigrationActivity($state['message']);
            }
            $result = ['status' => $state['status'], 'message' => $state['message'], 'migrated' => $migrated];
            if ($migrated === []) {
                self::releaseLaunchGuard($launchLock);
                $launchLock = false;
                FileStorage::releaseMigrationLock($migrationLock);
                $migrationLock = null;
                self::resumeMigrationSessions((array)($state['sessions_to_resume'] ?? []), $seams);
            }
            return $result;
        } finally {
            self::releaseLaunchGuard($launchLock);
            FileStorage::releaseMigrationLock($migrationLock);
        }
    }

    /** @param resource|null $migrationLock */
    private static function startAsyncWithMigrationLock(string $target, bool $acknowledgeWear, array $config, array $seams, &$migrationLock, string $kind = StorageBackendPolicyService::KIND_ALL): array
    {
        $kind = StorageBackendPolicyService::normalizeKind($kind);
        $target = StorageBackendPolicyService::normalizeMode($target);
        $from = StorageBackendPolicyService::kindMode($config, $kind);
        // Compare with the REAL state of the kind, not only with the saved value: a kind
        // that is mixed can still be completed, and only the items off the target convert.
        $allEntities = $seams['entities'] ?? self::entities($config, $seams);
        $entities = self::entitiesForTarget($allEntities, $target, $kind, $from, self::realBackendMap($seams));
        if ($target === $from && $entities === []) {
            return ['status' => 'error', 'message' => self::alreadySetMessage($from, $kind)];
        }
        if ($entities === [] && !isset($seams['preflight'])) {
            // Every item of this kind is on the target already. Save the setting; nothing converts.
            $saver = $seams['save_kind'] ?? static fn(string $k, string $mode): bool => (bool)ConfigService::saveConfig(self::settingsToSave($k, $mode), false);
            if (!$saver($kind, $target)) {
                return ['status' => 'error', 'message' => 'The setting could not be saved. Try again.'];
            }
            return ['status' => 'saved', 'kind' => $kind, 'target' => $target,
                'message' => ucfirst(self::kindNoun($kind)) . ' were already on ' . strtolower(StorageBackendPolicyService::kindLabel($target)) . '. The setting is saved.'];
        }
        $check = $seams['preflight'] ?? self::preflight($target, $config, $seams, $kind);
        if (($check['status'] ?? '') !== 'ok') {
            return self::recordPreflightFailure($check, $target, $config, $entities, $kind);
        }
        $target = (string)$check['target'];
        if (!empty($check['requires_acknowledgement']) && !$acknowledgeWear) {
            return ['status' => 'error', 'message' => 'Plain folders on removable/USB storage require an explicit wear acknowledgement.'];
        }

        $lock = self::acquireLaunchGuard(true);
        if ($lock === false) {
            return ['status' => 'error', 'message' => 'A storage-engine migration or workspace start is already running. Wait for it to finish, then try again.'];
        }
        try {
            if ((self::readStatus()['status'] ?? '') === 'queued') {
                return ['status' => 'error', 'message' => 'A storage-engine migration is already queued. Wait for it to finish, then try again.'];
            }
            $activeInstalls = self::activeInstallIds($seams);
            $busy = self::busyEntities($entities);
            if ($busy !== [] && $activeInstalls === []) {
                return ['status' => 'error', 'message' => 'Storage work is active for ' . implode(', ', $busy) . '. Wait for it to finish, then try the engine change again.'];
            }

            $waitingForInstalls = $activeInstalls !== [];
            $state = [
                'status' => 'queued', 'kind' => $kind, 'from' => $from, 'target' => $target,
                'started_at' => time(), 'updated_at' => time(), 'completed' => 0,
                'total' => count($entities), 'current' => $waitingForInstalls ? implode(', ', $activeInstalls) : '',
                'phase' => $waitingForInstalls ? 'waiting_for_installs' : 'queued',
                'message' => $waitingForInstalls
                    ? 'The migration is queued and waiting for agent installation to finish: ' . implode(', ', $activeInstalls) . '.'
                    : 'The migration is queued. Workspaces cannot open until it finishes.',
                'worker_pid' => 0,
                'migrated' => [],
                'sessions_to_resume' => [],
                'entities' => self::entityJournal($entities, $from, $target),
            ];
            if (!self::writeStatus($state)) {
                return ['status' => 'error', 'message' => 'The migration status could not be saved. No storage was changed.'];
            }
            if (!self::writeDurableStatus($state)) {
                $state['status'] = 'error';
                $state['phase'] = 'error';
                $state['message'] = 'The recovery status could not be saved to the Boot disk. No storage was changed.';
                $state['updated_at'] = time();
                self::writeStatus($state);
                return ['status' => 'error', 'message' => $state['message']];
            }
            self::updateMigrationActivity($state);
            // The queued state now refuses path moves. Free the shared lock
            // before spawning so the worker can take it on its first attempt.
            FileStorage::releaseMigrationLock($migrationLock);
            $migrationLock = null;
            // The launch guard also blocks workspace starts until the worker
            // records that it owns the storage policy.
            self::releaseLaunchGuard($lock);
            $lock = false;
            $spawn = $seams['spawn'] ?? static function (string $mode, bool $ack) use ($kind): int {
                require_once __DIR__ . '/UtilityService.php';
                $script = dirname(__DIR__, 2) . '/scripts/storage-backend-migrate.php';
                return UtilityService::spawnDetached(
                    [PHP_BINARY, $script, $mode, $ack ? '1' : '0', 'continue', $kind],
                    self::statusPath() . '.log',
                    true
                );
            };
            try {
                $pid = (int)$spawn($target, $acknowledgeWear);
            } catch (\Throwable $e) {
                $state['status'] = 'error';
                $state['phase'] = 'error';
                $state['message'] = 'The migration worker did not start. No storage was changed.';
                $state['updated_at'] = time();
                self::writeStatus($state);
                self::writeDurableStatus($state);
                self::failMigrationActivity($state['message']);
                return ['status' => 'error', 'message' => $state['message']];
            }
            if ($pid < 1) {
                $state['status'] = 'error';
                $state['phase'] = 'error';
                $state['message'] = 'The migration worker did not start. No storage was changed.';
                $state['updated_at'] = time();
                self::writeStatus($state);
                self::writeDurableStatus($state);
                self::failMigrationActivity($state['message']);
                return ['status' => 'error', 'message' => $state['message']];
            }
            return ['status' => 'accepted', 'message' => 'Migration started. Workspaces stay closed until it finishes.', 'worker_pid' => $pid];
        } finally {
            self::releaseLaunchGuard($lock);
        }
    }

    /** Refuse the engine change while a path move is queued or still has its marker. */
    private static function pathMoveConflictMessage(): ?string
    {
        if (FileStorage::isMigrationInProgress()) {
            return 'A storage path move is still running. Wait for it to finish before changing the storage engine.';
        }
        if (StoragePathMoveJobService::hasPending()) {
            return 'A storage path move is queued. Wait for it to finish before changing the storage engine.';
        }
        return null;
    }

    /** Run the accepted worker job and record a failure that happened before conversion began. */
    public static function runQueuedMigration(string $target, bool $acknowledgeWear, string $kind = StorageBackendPolicyService::KIND_ALL): array
    {
        $state = self::readStatus();
        if (($state['status'] ?? '') === 'queued') {
            $state['worker_pid'] = getmypid();
            $state['updated_at'] = time();
            self::writeStatus($state);
            self::writeDurableStatus($state);
        }
        try {
            $result = self::migrate($target, $acknowledgeWear, ConfigService::getConfig(), [], $kind);
        } catch (\Throwable $e) {
            $state = self::readStatus();
            if (($state['status'] ?? '') === 'queued') {
                $state['status'] = 'error';
                $state['phase'] = 'error';
                $state['message'] = 'The migration worker stopped before conversion began. Check the Debug Console for details.';
                $state['updated_at'] = time();
                self::writeStatus($state);
                self::writeDurableStatus($state);
            }
            throw $e;
        }
        $state = self::readStatus();
        if (($state['status'] ?? '') === 'queued') {
            $state['status'] = ($result['status'] ?? '') === 'ok' ? 'complete' : 'error';
            $state['phase'] = $state['status'];
            $state['message'] = (string)($result['message'] ?? 'The migration did not start.');
            $state['updated_at'] = time();
            self::writeStatus($state);
            self::writeDurableStatus($state);
        }
        return $result;
    }

    /** Probe every per-entity bake lock before any session closes or conversion begins. */
    private static function busyEntities(array $entities): array
    {
        $dir = getenv('AICLI_BACKEND_ENTITY_LOCK_DIR');
        if ($dir === false || $dir === '') $dir = '/var/run';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $busy = [];
        foreach ($entities as $entity) {
            $type = (string)($entity['type'] ?? '');
            $id = (string)($entity['id'] ?? '');
            if (!in_array($type, ['agent', 'home'], true) || $id === '') continue;
            $safeId = preg_replace('/[^A-Za-z0-9_-]/', '_', $id);
            $path = rtrim($dir, '/') . '/aicli-bake-' . $type . '-' . $safeId . '.lock';
            $fh = @fopen($path, 'c');
            if ($fh === false || !@flock($fh, LOCK_EX | LOCK_NB)) {
                if (is_resource($fh)) @fclose($fh);
                $busy[] = $type . '/' . $id;
                continue;
            }
            @flock($fh, LOCK_UN);
            @fclose($fh);
        }
        return $busy;
    }

    private static function statusPath(): string
    {
        $override = getenv('AICLI_BACKEND_MIGRATE_STATUS');
        return ($override !== false && $override !== '') ? $override : SecretPaths::stateRoot() . '/backend-migration-status.json';
    }

    /** Durable checkpoint that survives a reboot and the loss of the RAM state root. */
    private static function durableStatusPath(): string
    {
        $override = getenv('AICLI_BACKEND_MIGRATE_DURABLE_STATUS');
        return ($override !== false && $override !== '') ? $override : SecretPaths::flashRoot() . '/backend-migration-status.json';
    }

    /** Current page-independent migration status. */
    public static function status(): array
    {
        $status = self::readStatus();
        if (($status['status'] ?? 'idle') === 'idle') {
            $durable = self::readDurableStatus();
            $durableState = (string)($durable['status'] ?? 'idle');
            if (in_array($durableState, ['queued', 'running', 'interrupted'], true)) {
                $status = $durable;
                if ($durableState === 'running') {
                    $probe = self::acquireLaunchGuard(false);
                    if ($probe !== false) {
                        self::releaseLaunchGuard($probe);
                        $status['status'] = 'interrupted';
                        $status['message'] = self::interruptedMessage($status);
                    }
                } elseif ($durableState === 'queued'
                    && time() - (int)($durable['updated_at'] ?? 0) > 60
                    && !self::workerPidIsRunning($durable)) {
                    $status['status'] = 'interrupted';
                    $status['message'] = self::interruptedMessage($status);
                }
                if (($status['status'] ?? '') === 'interrupted') {
                    $status['updated_at'] = time();
                    self::writeStatus($status);
                    self::writeDurableStatus($status);
                }
            }
        }
        if (($status['status'] ?? '') === 'queued') {
            require_once __DIR__ . '/UtilityService.php';
        }
        if (($status['status'] ?? '') === 'queued'
            && time() - (int)($status['updated_at'] ?? 0) > 60
            && !self::workerPidIsRunning($status)) {
            $status['status'] = 'interrupted';
            $status['message'] = self::interruptedMessage($status);
            $status['updated_at'] = time();
            self::writeStatus($status);
            self::writeDurableStatus($status);
        }
        if (($status['status'] ?? '') === 'running') {
            $probe = self::acquireLaunchGuard(false);
            if ($probe !== false) {
                self::releaseLaunchGuard($probe);
                $status['status'] = 'interrupted';
                $status['message'] = self::interruptedMessage($status);
                $status['updated_at'] = time();
                self::writeStatus($status);
                self::writeDurableStatus($status);
            }
        }
        return $status;
    }

    private static function interruptedMessage(array $status): string
    {
        $migrated = is_array($status['migrated'] ?? null) ? $status['migrated'] : [];
        $total = max(0, (int)($status['total'] ?? 0));
        if ($migrated !== []) {
            $engine = ($status['target'] ?? '') === StorageBackendPolicyService::PASSTHROUGH ? 'plain folders' : 'SquashFS layers';
            return 'The ' . self::changeName((string)($status['kind'] ?? '')) . ' did not finish: ' . count($migrated) . ' of ' . $total . ' items are ' . $engine . '. Continue or put them back.';
        }
        return 'The ' . self::changeName((string)($status['kind'] ?? '')) . ' did not finish. No items changed. Continue or put them back.';
    }

    /** Name of the change in a message: "home storage change", "agent storage change" or "storage change". */
    private static function changeName(string $kind): string
    {
        $kind = StorageBackendPolicyService::normalizeKind($kind);
        return $kind === StorageBackendPolicyService::KIND_HOMES ? 'home storage change'
            : ($kind === StorageBackendPolicyService::KIND_AGENTS ? 'agent storage change' : 'storage change');
    }

    private static function workerPidIsRunning(array $status): bool
    {
        $pid = (int)($status['worker_pid'] ?? 0);
        if ($pid < 1) return false;
        require_once __DIR__ . '/UtilityService.php';
        return UtilityService::isPidRunning($pid);
    }

    private static function readStatus(): array
    {
        $json = @file_get_contents(self::statusPath());
        $data = is_string($json) ? json_decode($json, true) : null;
        return is_array($data) ? $data : ['status' => 'idle'];
    }

    private static function readDurableStatus(): array
    {
        $json = @file_get_contents(self::durableStatusPath());
        $data = is_string($json) ? json_decode($json, true) : null;
        return is_array($data) ? $data : ['status' => 'idle'];
    }

    /** Write recovery facts to flash without copying the high-frequency live progress record. */
    private static function writeDurableStatus(array $status): bool
    {
        $record = [];
        foreach (['status', 'phase', 'kind', 'from', 'target', 'migrated', 'sessions_to_resume', 'total', 'completed', 'current', 'message', 'worker_pid', 'operation', 'started_at', 'updated_at'] as $key) {
            if (array_key_exists($key, $status)) $record[$key] = $status[$key];
        }
        if (!isset($record['migrated']) || !is_array($record['migrated'])) $record['migrated'] = [];
        if (!isset($record['sessions_to_resume']) || !is_array($record['sessions_to_resume'])) $record['sessions_to_resume'] = [];
        $path = self::durableStatusPath();
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return false;
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $json = json_encode($record, JSON_UNESCAPED_SLASHES);
        if ($json !== false && @file_put_contents($tmp, $json, LOCK_EX) !== false && @rename($tmp, $path)) return true;
        @unlink($tmp);
        return false;
    }

    /** @param array<int|string,mixed> $sessionIds @return list<string> */
    private static function safeSessionIds(array $sessionIds): array
    {
        $safe = [];
        foreach ($sessionIds as $id) {
            if (!is_string($id) && !is_int($id)) continue;
            $id = (string)$id;
            if (preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $id) === 1) $safe[$id] = true;
        }
        return array_keys($safe);
    }

    /** Keep one plain progress item in the Activity tray for the full migration. */
    private static function updateMigrationActivity(array $state): void
    {
        if (!class_exists(ActivityService::class)) return;
        $total = max(0, (int)($state['total'] ?? 0));
        $completed = max(0, (int)($state['completed'] ?? 0));
        $progress = $total > 0 ? min(99, (int)floor(100 * $completed / $total)) : 1;
        ActivityService::update(self::ACTIVITY_ID, [
            'type' => 'migrate',
            'label' => 'Changing ' . self::activityName((string)($state['kind'] ?? '')),
            'status' => 'running',
            'step' => (string)($state['message'] ?? 'Changing storage.'),
            'progress' => $progress,
            'meta' => ['kind' => 'job'],
        ]);
    }

    /** What the Activity tray says is changing: home storage, agent storage or storage. */
    private static function activityName(string $kind): string
    {
        $kind = StorageBackendPolicyService::normalizeKind($kind);
        return $kind === StorageBackendPolicyService::KIND_HOMES ? 'home storage'
            : ($kind === StorageBackendPolicyService::KIND_AGENTS ? 'agent storage' : 'storage');
    }

    private static function finishMigrationActivity(string $step = ''): void
    {
        if (class_exists(ActivityService::class)) ActivityService::finish(self::ACTIVITY_ID, $step);
    }

    private static function failMigrationActivity(string $error, string $recovery = 'Check Configuration and the Boot disk space card before you try again.'): void
    {
        if (class_exists(ActivityService::class)) ActivityService::fail(self::ACTIVITY_ID, $error, $recovery, [
            'type' => 'migrate',
            'label' => 'Storage migration needs attention',
            'meta' => ['kind' => 'notice'],
        ]);
    }

    private static function writeStatus(array $status): bool
    {
        $path = self::statusPath();
        $dir = dirname($path);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $json = json_encode($status, JSON_UNESCAPED_SLASHES);
        if ($json !== false && @file_put_contents($tmp, $json, LOCK_EX) !== false && @rename($tmp, $path)) return true;
        @unlink($tmp);
        return false;
    }

    /**
     * R-122 (#662): one engine migration at a time (a non-blocking lock), and an all-or-nothing
     * result. When an entity fails, the entities that were already converted are converted back,
     * and the global mode is NOT saved, so the state is never a mix of both engines. When the
     * rollback of an entity fails too, the message names it.
     *
     * $seams (tests): preflight (array), entities (array), evict (callable),
     * convert (callable(type,id,mode,forceDirect): array),
     * wrap (callable(from,to,work): object with ->ok and ->error), save (callable(mode): bool).
     */
    public static function migrate(string $target, bool $acknowledgeWear = false, ?array $config = null, array $seams = [], string $kind = StorageBackendPolicyService::KIND_ALL): array
    {
        $config = $config ?? ConfigService::getConfig();
        $kind = StorageBackendPolicyService::normalizeKind($kind);
        require_once __DIR__ . '/FileStorage.php';
        require_once __DIR__ . '/StoragePathMoveJobService.php';
        $migrationLock = FileStorage::acquireMigrationLock();
        if ($migrationLock === null) {
            return ['status' => 'error', 'message' => 'Another storage migration is already running. Wait for it to finish, then try again.', 'migrated' => []];
        }
        try {
            $conflict = self::pathMoveConflictMessage();
            if ($conflict !== null) return ['status' => 'error', 'message' => $conflict, 'migrated' => []];
            return self::migrateWithMigrationLock($target, $acknowledgeWear, $config, $seams, $migrationLock, $kind);
        } finally {
            FileStorage::releaseMigrationLock($migrationLock);
        }
    }

    /** @param resource|null $migrationLock */
    private static function migrateWithMigrationLock(string $target, bool $acknowledgeWear, array $config, array $seams, &$migrationLock, string $kind = StorageBackendPolicyService::KIND_ALL): array
    {
        $kind = StorageBackendPolicyService::normalizeKind($kind);
        $from = StorageBackendPolicyService::kindMode($config, $kind);
        $allEntities = $seams['entities'] ?? self::entities($config, $seams);
        $entities = self::entitiesForTarget($allEntities, StorageBackendPolicyService::normalizeMode($target), $kind, $from, self::realBackendMap($seams));
        $check = $seams['preflight'] ?? self::preflight($target, $config, $seams, $kind);
        if (($check['status'] ?? '') !== 'ok') {
            return self::recordPreflightFailure($check, $target, $config, $entities, $kind);
        }
        $target = (string)$check['target'];
        if (!empty($check['requires_acknowledgement']) && !$acknowledgeWear) {
            return ['status' => 'error', 'message' => 'Plain folders on removable/USB storage require an explicit wear acknowledgement.'];
        }
        // The setting of THIS kind is saved only after every item of the kind has converted.
        $save = $seams['save'] ?? static fn(string $mode): bool => (bool)ConfigService::saveConfig(self::settingsToSave($kind, $mode), false);

        $waitForLaunchGuard = $seams['launch_guard_wait'] ?? static function (int $microseconds): void { usleep($microseconds); };
        $guardWaitStartedAt = microtime(true);
        do {
            $lock = self::acquireLaunchGuard(true);
            if ($lock !== false) break;
            // A migration owns the exclusive side. A workspace start owns the
            // shared side, which we can also take while that start finishes.
            $sharedProbe = self::acquireLaunchGuard(false);
            if ($sharedProbe === false) {
                return ['status' => 'error', 'message' => 'A storage-engine migration is already running. Wait for it to finish, then try again.', 'migrated' => []];
            }
            self::releaseLaunchGuard($sharedProbe);
            if (microtime(true) - $guardWaitStartedAt >= self::LAUNCH_GUARD_WAIT_TIMEOUT_SECONDS) {
                return ['status' => 'error', 'message' => 'A workspace start did not finish in time. No storage was changed. Try the migration again.', 'migrated' => []];
            }
            $waitForLaunchGuard(self::LAUNCH_GUARD_WAIT_MICROSECONDS);
        } while (true);
        $result = null;
        try {
            $queued = self::readStatus();
            $priorMigrated = (($queued['operation'] ?? '') === 'continue' && ($queued['status'] ?? '') === 'queued' && ($queued['target'] ?? '') === $target)
                ? (is_array($queued['migrated'] ?? null) ? array_values(array_unique(array_map('strval', $queued['migrated']))) : [])
                : [];
            $journal = self::entityJournal($entities, $from, $target);
            foreach ($priorMigrated as $key) {
                if (isset($journal[$key])) {
                    $journal[$key]['status'] = 'converted';
                    $journal[$key]['changed'] = true;
                }
            }
            $state = [
                'status' => 'running', 'kind' => $kind, 'from' => $from, 'target' => $target,
                'started_at' => time(), 'updated_at' => time(), 'completed' => 0,
                'total' => count($entities), 'current' => '', 'phase' => 'waiting_for_installs',
                'message' => 'Checking for agent installations in progress.',
                'migrated' => $priorMigrated,
                'entities' => $journal,
            ];
            self::writeStatus($state);
            self::writeDurableStatus($state);
            self::updateMigrationActivity($state);

            $now = $seams['now'] ?? static fn(): int => time();
            $installWait = $seams['install_wait'] ?? static function (int $seconds): void { sleep($seconds); };
            $waitStartedAt = $now();
            $activeInstalls = self::activeInstallIds($seams);
            while ($activeInstalls !== []) {
                if ($now() - $waitStartedAt >= self::INSTALL_WAIT_TIMEOUT_SECONDS) {
                    $message = 'An agent installation did not finish in time. No storage was changed. Try the migration again.';
                    $state['status'] = 'error';
                    $state['phase'] = 'error';
                    $state['current'] = '';
                    $state['message'] = $message;
                    $state['updated_at'] = $now();
                    self::writeStatus($state);
                    self::writeDurableStatus($state);
                    self::failMigrationActivity($message);
                    $result = ['status' => 'error', 'message' => $message, 'migrated' => []];
                    break;
                }
                $state['phase'] = 'waiting_for_installs';
                $state['current'] = implode(', ', $activeInstalls);
                $state['message'] = 'Waiting for agent installation to finish: ' . implode(', ', $activeInstalls) . '.';
                $state['updated_at'] = $now();
                self::writeStatus($state);
                $installWait(self::CONVERSION_RETRY_DELAY_SECONDS);
                $activeInstalls = self::activeInstallIds($seams);
            }

            if ($result === null) {
                $state['phase'] = 'converting';
                $state['current'] = '';
                $state['message'] = 'Preparing migration';
                $state['updated_at'] = $now();
                self::writeStatus($state);
                self::updateMigrationActivity($state);
                $busy = self::busyEntities($entities);
                if ($busy !== []) {
                    $message = 'Storage work is active for ' . implode(', ', $busy) . '. Wait for it to finish, then try the engine change again.';
                    $state['status'] = 'error';
                    $state['phase'] = 'preflight';
                    $state['current'] = $busy[0];
                    $state['message'] = $message;
                    $state['updated_at'] = $now();
                    self::writeStatus($state);
                    self::writeDurableStatus($state);
                    self::failMigrationActivity($message);
                    $result = ['status' => 'error', 'message' => $message, 'migrated' => []];
                } else {
                    $result = self::migrateLocked($from, $target, $entities, $seams, $save, $state);
                    $state['status'] = $result['status'] === 'ok'
                        ? 'complete'
                        : (!empty($state['migrated']) ? 'interrupted' : 'error');
                    $state['phase'] = $state['status'];
                    $state['current'] = '';
                    $state['message'] = (string)($result['message'] ?? 'Migration finished.');
                    $state['updated_at'] = time();
                    self::writeStatus($state);
                    self::writeDurableStatus($state);
                    if (($state['status'] ?? '') === 'complete') {
                        self::finishMigrationActivity(ucfirst(self::activityName((string)($state['kind'] ?? ''))) . ' changed.');
                    } else {
                        self::failMigrationActivity($state['message']);
                    }
                }
            }
        } finally {
            self::releaseLaunchGuard($lock);
        }
        FileStorage::releaseMigrationLock($migrationLock);
        $migrationLock = null;
        if (!empty($result['resume_after_migration'])) self::resumeMigrationSessions((array)($state['sessions_to_resume'] ?? []), $seams);
        unset($result['resume_after_migration']);
        return $result;
    }

    /** The cfg keys a finished conversion saves: the setting of its kind (both for the older one-engine path). */
    private static function settingsToSave(string $kind, string $mode): array
    {
        $mode = StorageBackendPolicyService::normalizeMode($mode);
        $kind = StorageBackendPolicyService::normalizeKind($kind);
        if ($kind === StorageBackendPolicyService::KIND_ALL) {
            return [StorageBackendPolicyService::KEY_HOMES => $mode, StorageBackendPolicyService::KEY_AGENTS => $mode];
        }
        return [StorageBackendPolicyService::keyForKind($kind) => $mode];
    }

    /** Resume only sessions that were live before the conversion closed them. */
    private static function resumeMigrationSessions(array $sessionIds, array $seams): void
    {
        $sessionIds = self::safeSessionIds($sessionIds);
        if ($sessionIds === []) return;
        if (isset($seams['resume']) && is_callable($seams['resume'])) {
            ($seams['resume'])($sessionIds);
        } elseif (class_exists(AutoLaunchService::class)) {
            AutoLaunchService::launchAllPending(null, 'storage_backend_migration', $sessionIds, true);
        }
    }

    /** @return list<string> active install ids from the tray and live worker processes */
    private static function activeInstallIds(array $seams): array
    {
        if (isset($seams['active_installs']) && is_callable($seams['active_installs'])) {
            $ids = (array)($seams['active_installs'])();
        } else {
            $ids = [];
            if (class_exists(ActivityService::class)) {
                foreach (ActivityService::listAll() as $entry) {
                    $opId = (string)($entry['opId'] ?? '');
                    if (in_array((string)($entry['status'] ?? ''), ['running', 'stalled'], true)
                        && preg_match('/^install_([A-Za-z0-9._-]{1,128})$/', $opId, $m)) {
                        $ids[] = $m[1];
                    }
                }
            }
            if (class_exists(PendingAgentUpgradeService::class)
                && method_exists(PendingAgentUpgradeService::class, 'runningInstallIds')) {
                $ids = array_merge($ids, PendingAgentUpgradeService::runningInstallIds());
            }
        }
        $ids = array_values(array_unique(array_filter(array_map('strval', $ids), static fn(string $id): bool => $id !== '')));
        sort($ids);
        return $ids;
    }

    /** @param array<int,array{type:string,id:string}> $entities */
    private static function migrateLocked(string $from, string $target, array $entities, array $seams, callable $save, array &$state): array
    {
        $convert = $seams['convert'] ?? static fn(string $type, string $id, string $mode, bool $direct): array => FileStorage::migrateBackend($type, $id, $mode, $direct);
        $wrap = $seams['wrap'] ?? static fn(string $f, string $t, callable $work) => FileStorage::migrateBackendPolicy($f, $t, $work);
        $retryWait = $seams['retry_wait'] ?? static function (int $seconds): void { sleep($seconds); };
        $migrated = is_array($state['migrated'] ?? null) ? array_values(array_unique(array_map('strval', $state['migrated']))) : [];
        $failure = null;
        $rollbackFailed = [];
        $result = $wrap($from, $target, function () use ($entities, $target, $from, $seams, $convert, $retryWait, &$migrated, &$failure, &$rollbackFailed, &$state): bool {
            // No workspace may keep writing while the authority of its storage
            // engine is changing. The shell operation still refuses a busy mount.
            $sessionIds = isset($seams['running_sessions']) && is_callable($seams['running_sessions'])
                ? (array)($seams['running_sessions'])()
                : ProcessManager::runningSessionIdsForStorageMigration();
            $state['sessions_to_resume'] = self::safeSessionIds($sessionIds);
            $state['updated_at'] = time();
            if (!self::writeStatus($state) || !self::writeDurableStatus($state)) {
                $failure = 'The workspace list could not be saved before migration. No storage was changed.';
                return false;
            }
            self::updateMigrationActivity($state);
            if (isset($seams['evict'])) {
                ($seams['evict'])(false, $state['sessions_to_resume']);
            } else {
                if (class_exists(TerminalService::class) && method_exists(TerminalService::class, 'captureResumeForShutdown')) {
                    TerminalService::captureResumeForShutdown($state['sessions_to_resume'], 60);
                }
                ProcessManager::evictAllForStorageMigration($state['sessions_to_resume']);
            }
            $waitSessions = $seams['wait_sessions'] ?? static fn(array $ids): bool => ProcessManager::waitForSessionsStopped($ids, self::SESSION_STOP_TIMEOUT_SECONDS);
            if (!$waitSessions($state['sessions_to_resume'])) {
                $failure = 'Some workspaces did not stop in time. No storage was changed.';
                return false;
            }
            $processed = 0;
            $rollbackConverted = static function () use ($from, $convert, &$migrated, &$rollbackFailed, &$state): void {
                foreach (array_reverse($migrated) as $done) {
                    $state['phase'] = 'rolling_back';
                    $state['current'] = $done;
                    $state['message'] = 'Restoring ' . $done . ' to ' . StorageBackendPolicyService::label($from);
                    if (isset($state['entities'][$done])) $state['entities'][$done]['status'] = 'rolling_back';
                    $state['updated_at'] = time();
                    try { self::writeStatus($state); self::updateMigrationActivity($state); } catch (\Throwable $e) { /* conversion rollback must continue */ }
                    [$t, $i] = explode('/', $done, 2);
                    try {
                        $result = $convert($t, $i, $from, $from === StorageBackendPolicyService::PASSTHROUGH, true);
                        $exit = (int)($result['exit'] ?? 1);
                    } catch (\Throwable $e) {
                        $exit = 1;
                    }
                    if ($exit !== 0) {
                        $rollbackFailed[] = $done;
                        if (isset($state['entities'][$done])) $state['entities'][$done]['status'] = 'rollback_failed';
                    } else {
                        $state['completed'] = max(0, (int)$state['completed'] - 1);
                        if (isset($state['entities'][$done])) $state['entities'][$done]['status'] = 'rolled_back';
                        $migrated = array_values(array_diff($migrated, [$done]));
                    }
                    $state['migrated'] = $migrated;
                    $state['updated_at'] = time();
                    try { self::writeStatus($state); self::writeDurableStatus($state); } catch (\Throwable $e) { /* best effort; keep restoring remaining entities */ }
                }
            };
            try {
                foreach ($entities as $entity) {
                    $key = $entity['type'] . '/' . $entity['id'];
                    $state['current'] = $key;
                    $state['message'] = 'Converting ' . $key;
                    $state['entities'][$key]['status'] = 'converting';
                    $state['updated_at'] = time();
                    self::writeStatus($state);
                    self::updateMigrationActivity($state);
                    $retry = 0;
                    do {
                        $r = $convert($entity['type'], $entity['id'], $target, $target === StorageBackendPolicyService::PASSTHROUGH);
                        $exit = (int)($r['exit'] ?? 1);
                        if ($exit !== 2 || $retry >= self::CONVERSION_RETRY_LIMIT) break;
                        $retry++;
                        $state['entities'][$key]['status'] = 'waiting';
                        $state['entities'][$key]['retry'] = $retry;
                        $state['message'] = 'Waiting for storage to become available for ' . $key . ' (retry ' . $retry . ' of ' . self::CONVERSION_RETRY_LIMIT . ').';
                        $state['updated_at'] = time();
                        self::writeStatus($state);
                        $retryWait(self::CONVERSION_RETRY_DELAY_SECONDS);
                        $state['entities'][$key]['status'] = 'converting';
                        $state['message'] = 'Converting ' . $key;
                        $state['updated_at'] = time();
                        self::writeStatus($state);
                    } while (true);
                    $changed = !empty($r['changed']);
                    $state['entities'][$key]['exit'] = $exit;
                    $state['entities'][$key]['changed'] = $changed;
                    if ($changed && !in_array($key, $migrated, true)) $migrated[] = $key;
                    $state['migrated'] = $migrated;
                    if ($exit !== 0) {
                        $state['entities'][$key]['status'] = 'failed';
                        $diagnostic = self::cleanDiagnostic((string)($r['diagnostic'] ?? ''));
                        $reason = self::conversionFailureReason((string)($r['reason'] ?? ''), $diagnostic);
                        $failure = $key . ': ' . $reason;
                        self::logConversionFailure($entity, $exit, (string)($r['reason'] ?? ''), $diagnostic, $target);
                        self::writeDurableStatus($state);
                        break;
                    }
                    $state['entities'][$key]['status'] = $changed ? 'converted' : 'unchanged';
                    $processed++;
                    $state['completed'] = $processed;
                    $state['updated_at'] = time();
                    self::writeStatus($state);
                    self::writeDurableStatus($state);
                    self::updateMigrationActivity($state);
                }
            } catch (\Throwable $e) {
                $key = (string)$state['current'];
                $failure = 'Storage conversion failed for ' . $key . ': ' . $e->getMessage();
                if (isset($state['entities'][$key])) $state['entities'][$key]['status'] = 'failed';
                $state['migrated'] = $migrated;
                $state['updated_at'] = time();
                try { self::writeStatus($state); self::writeDurableStatus($state); } catch (\Throwable $ignored) { /* rollback still runs */ }
            }
            if ($failure !== null) {
                $rollbackConverted();
                return false;
            }
            return true;
        });
        if (!$result->ok) {
            $message = $failure ?: ($result->error ?: 'Storage-engine migration failed.');
            if ($failure !== null) {
                $message .= $rollbackFailed === []
                    ? ' The entities that were already converted were converted back; the storage engine is unchanged.'
                    : ' These entities could not be converted back and are still in the new engine: ' . implode(', ', $rollbackFailed) . '.';
            }
            return ['status' => 'error', 'message' => $message, 'migrated' => $rollbackFailed, 'resume_after_migration' => $failure !== null && $rollbackFailed === []];
        }
        if (!$save($target)) {
            return ['status' => 'error', 'message' => 'Storage data migrated, but the global policy could not be saved. Re-open Settings and retry.', 'migrated' => $migrated];
        }
        return ['status' => 'ok', 'message' => 'Migrated ' . count($migrated) . ' storage entities to ' . StorageBackendPolicyService::label($target) . '.', 'migrated' => $migrated, 'resume_after_migration' => true];
    }

    /** Keep the source engine and conversion outcome for each entity in the saved migration state. */
    private static function entityJournal(array $entities, string $from, string $target): array
    {
        $journal = [];
        foreach ($entities as $entity) {
            $key = (string)($entity['type'] ?? '') . '/' . (string)($entity['id'] ?? '');
            $journal[$key] = ['from' => $from, 'target' => $target, 'status' => 'pending', 'changed' => null];
        }
        return $journal;
    }

    /** Return a short reason that fits the saved status and Settings page. */
    private static function conversionFailureReason(string $reason, string $diagnostic): string
    {
        $text = strtolower($reason . ' ' . $diagnostic);
        if (preg_match('/no space left|insufficient space|not enough (?:free )?space|disk full|no_room|too_small|space .* needed/', $text)) {
            return 'not enough free space on the storage disk';
        }
        if (preg_match('/busy|in use|lock|already running|mount .*busy/', $text)) {
            return 'storage is in use';
        }
        if (str_contains($text, 'backend_migration_deferred')) {
            return 'conversion was deferred. Check the Debug Console for details.';
        }
        if (str_contains($text, 'space_estimate_unavailable')) {
            return 'free space for the new layer could not be checked';
        }
        if (str_contains($text, 'pt_dir_occupied')) {
            return 'the plain folder already contains data';
        }
        $plainReason = trim(self::cleanDiagnostic($reason));
        if (strlen($plainReason) > 20 && preg_match('/[\s.]/', $plainReason)) {
            return strlen($plainReason) > 180 ? substr($plainReason, 0, 177) . '...' : $plainReason;
        }
        return 'storage conversion failed. Check the Debug Console for details.';
    }

    /** Keep control characters and oversized shell output out of status and log records. */
    private static function cleanDiagnostic(string $diagnostic): string
    {
        $diagnostic = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $diagnostic);
        $lines = preg_split('/\r\n|\r|\n/', $diagnostic) ?: [];
        $diagnostic = trim(implode("\n", array_slice($lines, -20)));
        return strlen($diagnostic) > 2000 ? substr($diagnostic, -2000) : $diagnostic;
    }

    /** Keep the shell cause in both durable lifecycle history and the Debug Console. */
    private static function logConversionFailure(array $entity, int $exit, string $reason, string $diagnostic, string $target): void
    {
        $reason = substr(self::cleanDiagnostic($reason), 0, 256);
        $payload = [
            'entity' => (string)($entity['type'] ?? '') . '/' . (string)($entity['id'] ?? ''),
            'target' => $target,
            'exit' => $exit,
            'reason' => $reason,
            'diagnostic' => $diagnostic,
        ];
        if (class_exists(LifecycleLogService::class)) {
            LifecycleLogService::log(LifecycleLogService::LEVEL_ERROR, 'backend_migration', 'conversion_failed', $payload);
        }
        if (class_exists(LogService::class)) {
            $detail = $diagnostic !== '' ? $diagnostic : ($reason !== '' ? $reason : 'no diagnostic output');
            LogService::log('Storage migration failed for ' . $payload['entity'] . ' (exit ' . $exit . '): ' . $detail,
                LogService::LOG_ERROR, 'StorageBackendMigrationService');
        }
    }

    /** Enumerate only candidate entities; the shell remains authoritative about presence. */
    public static function entities(array $config, array $seams = []): array
    {
        $paths = [
            'agent' => (string)($config['agent_storage_path'] ?? ''),
            'home' => (string)($config['home_storage_path'] ?? ''),
        ];
        $out = [];
        $seen = [];
        $add = static function (string $type, string $id) use (&$out, &$seen): void {
            if (!preg_match('/^[A-Za-z0-9_.-]{1,128}$/', $id) || $id === '.' || $id === '..') return;
            $key = $type . '/' . $id;
            if (isset($seen[$key])) return;
            $seen[$key] = true;
            $out[] = ['type' => $type, 'id' => $id];
        };
        $manifestEntities = $seams['manifest_entities'] ?? LayerManifestService::getAllEntities();
        foreach ($manifestEntities as $entity => $_entry) {
            if (preg_match('#^(agent|home)/([A-Za-z0-9_.-]+)$#', (string)$entity, $m) !== 1) continue;
            if ($m[1] === 'agent' || self::homeHasStorage($m[2], (string)$paths['home'], $seams)) $add($m[1], $m[2]);
        }
        foreach ($paths as $type => $path) {
            if (trim($path) === '') continue;
            foreach (glob(rtrim($path, '/') . '/' . $type . '_*.sqsh') ?: [] as $file) {
                if (preg_match('/^' . $type . '_(.+?)_(?:v\d+_)?(?:vol1|delta|consolidated)/', basename($file), $m)) $add($type, $m[1]);
            }
            foreach (glob(rtrim($path, '/') . '/passthrough/' . $type . 's/*') ?: [] as $dir) {
                $id = basename($dir);
                if ($id === '.versions' || $id === '.staging' || $id === '.staging-data') continue;
                if ($type === 'home' && (!is_dir($dir) || is_link($dir) || !self::directoryHasContent($dir))) continue;
                $add($type, $id);
            }
        }
        if (class_exists(AgentRegistry::class)) {
            foreach (AgentRegistry::getRegistry() as $id => $agent) {
                if (!empty($agent['is_installed'])) $add('agent', (string)$id);
            }
        }
        foreach (($seams['workspace_users'] ?? self::workspaceHomeUsers()) as $user) {
            $add('home', (string)$user);
        }
        $passwdUsers = $seams['passwd_users'] ?? (class_exists(UtilityService::class) ? UtilityService::getUnraidUsers() : []);
        foreach ($passwdUsers as $user) {
            $user = (string)$user;
            if (self::homeHasStorage($user, (string)$paths['home'], $seams)) $add('home', $user);
        }
        usort($out, static fn(array $a, array $b): int => strcmp($a['type'] . '/' . $a['id'], $b['type'] . '/' . $b['id']));
        return $out;
    }

    /** A user record counts only when a layer, non-empty plain home, or workspace exists. */
    private static function homeHasStorage(string $user, string $path, array $seams): bool
    {
        if (!preg_match('/^[A-Za-z0-9_.-]{1,128}$/', $user) || $user === '.' || $user === '..') return false;
        $layers = $seams['home_layer_files'] ?? (trim($path) === '' ? [] : (glob(rtrim($path, '/') . '/home_' . $user . '_*.sqsh') ?: []));
        if ($layers !== []) return true;
        $plain = rtrim($path, '/') . '/passthrough/homes/' . $user;
        if (isset($seams['plain_home_has_data']) && is_callable($seams['plain_home_has_data'])) {
            return (bool)($seams['plain_home_has_data'])($user, $plain);
        }
        return trim($path) !== '' && is_dir($plain) && !is_link($plain) && self::directoryHasContent($plain);
    }

    private static function directoryHasContent(string $path): bool
    {
        $items = @scandir($path);
        if (!is_array($items)) return false;
        foreach ($items as $item) if ($item !== '.' && $item !== '..') return true;
        return false;
    }

    /** @return list<string> users attached to saved workspaces */
    private static function workspaceHomeUsers(): array
    {
        if (!class_exists(ConfigService::class)) return [];
        try {
            $sessions = ConfigService::getWorkspaces()['sessions'] ?? [];
        } catch (\Throwable $e) {
            return [];
        }
        $users = [];
        foreach ($sessions as $session) {
            if (is_array($session) && isset($session['user'])) $users[] = (string)$session['user'];
        }
        return $users;
    }
}
