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
    /** #882: a queued record older than this is dead whatever its process id says (a reboot can give the id to another process). */
    private const QUEUED_MAX_AGE_SECONDS = 600;

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
            : 'All ' . $noun . ' that are not compressed layers yet will be converted to compressed layers with writable zram state. Existing plain folders will be archived only after the layer and manifest are verified.';
        if ($usb !== []) {
            $message .= ' The probe identified removable/USB-backed storage (' . implode(', ', $usb) . '); Compressed layers are recommended to reduce wear.';
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
     * #880: the items counted are the items of itemLists(), the same list the migration converts, so the
     * card and the server never disagree. The storage status only tells the backend of each item; an item
     * the status does not know counts as being on the saved setting (as entitiesForTarget() counts it).
     * `orphans` lists stored homes of users that do not exist: they are not items and never convert.
     *
     * @param array<string,mixed>|null $status storage status, or null to read it
     * @param list<string>|null $installedAgentIds installed agent ids, or null to read them
     * @param array<string,mixed> $seams see itemLists(); `entities` replaces the whole list
     */
    public static function kindStates(?array $status = null, ?array $installedAgentIds = null, ?array $config = null, array $seams = []): array
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
        $lists = isset($seams['entities'])
            ? ['items' => $seams['entities'], 'orphans' => (array)($seams['orphans'] ?? [])]
            : self::itemLists($config, $seams + ['installed_agents' => $installedAgentIds]);
        $real = StorageBackendPolicyService::realStates($status, $installedAgentIds);
        $out = ['status' => 'ok'];
        foreach (['homes' => 'home', 'agents' => 'agent'] as $kind => $type) {
            $saved = StorageBackendPolicyService::kindMode($config, $kind);
            $fallback = $saved === StorageBackendPolicyService::PASSTHROUGH ? 'passthrough' : 'flash';
            $backends = [];
            foreach ($lists['items'] as $item) {
                if (($item['type'] ?? '') !== $type) continue;
                $id = (string)$item['id'];
                $backends[$id] = (string)($real[$kind]['backends'][$id] ?? $fallback);
            }
            $out[$kind] = StorageBackendPolicyService::kindState($backends, $kind) + ['saved' => $saved];
        }
        $out['orphans'] = array_values(array_map(
            static fn(array $o): array => ['kind' => 'homes', 'id' => (string)$o['id']],
            array_filter((array)$lists['orphans'], static fn($o): bool => is_array($o) && isset($o['id']))
        ));
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
            : $noun . ' already use compressed layers.';
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

    /**
     * True while a queued or active migration, or an interrupted one with changed items, owns the storage policy.
     * #880: a caller that cares about one kind passes it ("homes" or "agents"). The answer is then true only
     * when the job changes that kind (a job of the older one-engine path, kind "all", changes both).
     * A mount of a home that is already mounted and healthy never needs this check.
     */
    public static function isMigrationInProgress(?string $kind = null): bool
    {
        $status = self::status();
        $state = (string)($status['status'] ?? '');
        $active = in_array($state, ['queued', 'running'], true)
            || ($state === 'interrupted' && self::hasWork($status));
        if (!$active) {
            // #880 review: a move of the writable layer closes workspaces and remounts homes, so it holds back the same
            // work as a homes job (workspace starts, storage actions, path moves). The move worker itself is exempt.
            if ($kind === null || StorageBackendPolicyService::normalizeKind($kind) !== StorageBackendPolicyService::KIND_AGENTS) {
                return class_exists(UpperPlacementService::class) && UpperPlacementService::isJobActive();
            }
            return false;
        }
        if ($kind === null) return true;
        $jobKind = StorageBackendPolicyService::normalizeKind((string)($status['kind'] ?? ''));
        return $jobKind === StorageBackendPolicyService::KIND_ALL
            || $jobKind === StorageBackendPolicyService::normalizeKind($kind);
    }

    /**
     * #880: the plain reason why a new change of $kind cannot start now, or null when it can.
     * One storage job runs at a time (an engine change of either kind, or a writable-layer move).
     * An interrupted change with changed items blocks every new change until the person chooses
     * Continue or Put back. With $sameKindOnly (a save-only Apply) only a job that changes the same
     * kind conflicts, because the save touches only the setting of that kind.
     *
     * @return array{status:string,reason:string,message:string}|null
     */
    public static function refusalFor(string $kind, bool $sameKindOnly = false, array $seams = []): ?array
    {
        $kind = StorageBackendPolicyService::normalizeKind($kind);
        $s = $seams['job_status'] ?? self::status();
        $state = (string)($s['status'] ?? 'idle');
        $interruptedWithChanges = $state === 'interrupted' && self::hasWork($s);
        if (in_array($state, ['queued', 'running'], true) || $interruptedWithChanges) {
            $jobKind = StorageBackendPolicyService::normalizeKind((string)($s['kind'] ?? ''));
            $overlap = $jobKind === StorageBackendPolicyService::KIND_ALL
                || $kind === StorageBackendPolicyService::KIND_ALL
                || $jobKind === $kind;
            if ($sameKindOnly && !$overlap) return null;
            $name = self::changeName($jobKind);
            if ($interruptedWithChanges) {
                return ['status' => 'error', 'reason' => 'storage_change_interrupted',
                    'message' => 'An earlier ' . $name . ' did not finish: ' . count(self::changedItems($s))
                        . ' items are already changed or may be half converted. Choose Continue or Put back on the Storage card first.'];
            }
            return ['status' => 'error', 'reason' => 'storage_job_active',
                'message' => 'A ' . $name . ' is already ' . ($state === 'queued' ? 'waiting to start' : 'running')
                    . '. Wait for it to finish, then press Apply again.'];
        }
        if ($sameKindOnly) return null;
        if (class_exists(UpperPlacementService::class)) {
            $upper = UpperPlacementService::jobRefusal($seams);
            if ($upper !== null) return $upper;
        }
        return null;
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
        // #882: a Continue worker that is refused here holds a record with changed items. Replacing it with a plain
        // error record would remove Continue and Put back. Keep the record, as "interrupted", with the new reason.
        $held = self::readStatus();
        if (in_array((string)($held['status'] ?? ''), ['queued', 'running', 'interrupted'], true) && self::hasWork($held)) {
            $held['status'] = 'interrupted';
            $held['phase'] = 'interrupted';
            $held['current'] = '';
            $held['message'] = (string)($check['message'] ?? 'The migration could not start. No storage was changed.');
            $held['updated_at'] = $now;
            self::writeStatus($held);
            self::writeDurableStatus($held);
            self::failMigrationActivity($held['message']);
            return $check;
        }
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
        // #880: the tray item follows the record. A worker that is refused here has an open item.
        self::failOpenMigrationActivity($state['message']);
        return $check;
    }

    /** Reserve the shared migration right, then start the worker without holding the page open. */
    public static function startAsync(string $target, bool $acknowledgeWear = false, ?array $config = null, array $seams = [], string $kind = StorageBackendPolicyService::KIND_ALL, bool $saveOnly = false): array
    {
        $kind = StorageBackendPolicyService::normalizeKind($kind);
        $config = $config ?? ConfigService::getConfig();
        require_once __DIR__ . '/FileStorage.php';
        require_once __DIR__ . '/StoragePathMoveJobService.php';
        $migrationLock = FileStorage::acquireMigrationLock(FileStorage::MIGRATION_LOCK_WAIT_S);
        if ($migrationLock === null) {
            return self::refusalFor($kind, $saveOnly, $seams)
                ?? ['status' => 'error', 'message' => 'Another storage migration is already running. Wait for it to finish, then try again.'];
        }
        try {
            $conflict = self::pathMoveConflictMessage();
            if ($conflict !== null) return ['status' => 'error', 'message' => $conflict];
            return self::startAsyncWithMigrationLock($target, $acknowledgeWear, $config, $seams, $migrationLock, $kind, $saveOnly);
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
        $target = StorageBackendPolicyService::normalizeMode((string)($prior['target'] ?? 'layering'));
        // #882: a record saved before this rule can hold from == target (a mixed kind). The changed items
        // are on the other engine, so recover from that; do not refuse.
        $saved = StorageBackendPolicyService::normalizeMode((string)($prior['saved_mode'] ?? $prior['from'] ?? 'layering'));
        $from = self::sourceEngine((string)($prior['from'] ?? 'layering'), $target);
        $migrated = is_array($prior['migrated'] ?? null) ? array_values(array_unique(array_map('strval', $prior['migrated']))) : [];
        if ($operation === 'rollback' && $migrated === [] && !self::hasWork($prior)) {
            // #880: nothing was changed, so nothing is put back and no workspace is closed.
            self::settleDidNotStart($prior);
            return ['status' => 'cleared', 'message' => 'Nothing was changed, so there is nothing to put back.'];
        }
        if ($operation === 'continue') {
            $check = self::preflight($target, $config, $seams, $kind);
            if (($check['status'] ?? '') !== 'ok') return $check;
        }

        require_once __DIR__ . '/FileStorage.php';
        require_once __DIR__ . '/StoragePathMoveJobService.php';
        $migrationLock = FileStorage::acquireMigrationLock(FileStorage::MIGRATION_LOCK_WAIT_S);
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

            // #882: the record was read BEFORE the locks. Two near-simultaneous clicks both saw "interrupted"; the
            // second one must not overwrite the queued job of the first. Read it again under the locks.
            $fresh = self::status();
            if (($fresh['status'] ?? '') !== 'interrupted' || (string)($fresh['job_id'] ?? '') !== (string)($prior['job_id'] ?? '')) {
                return ['status' => 'error', 'message' => 'The interrupted engine change is already being recovered, or it ended. Reload the page to see where it is.'];
            }
            $prior = $fresh;
            $migrated = self::changedItems($prior);

            $now = time();
            $jobId = bin2hex(random_bytes(6));
            $entities = $operation === 'continue' ? self::entitiesForTarget($seams['entities'] ?? self::entities($config, $seams), $target, $kind, $saved, self::realBackendMap($seams)) : array_map(static function (string $key): array {
                [$type, $id] = explode('/', $key, 2);
                return ['type' => $type, 'id' => $id];
            }, array_filter($migrated, static fn(string $key): bool => preg_match('#^(agent|home)/[A-Za-z0-9_.-]{1,128}$#D', $key) === 1));
            $state = [
                'status' => 'queued', 'phase' => 'queued', 'kind' => $kind, 'from' => $from, 'saved_mode' => $saved, 'target' => $target,
                'operation' => $operation, 'started_at' => $now, 'updated_at' => $now,
                'completed' => 0, 'total' => count($entities), 'current' => '', 'worker_pid' => 0, 'job_id' => $jobId,
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
            $spawn = $seams['spawn_recovery'] ?? static fn(string $targetMode, string $action): int
                => self::spawnWorker($targetMode, false, $action, $kind, $seams, $jobId);
            $pid = (int)$spawn($operation === 'rollback' ? $from : $target, $operation);
            if ($pid < 1 || !self::workerStartConfirmed($seams, isset($seams['spawn_recovery']))) {
                // The earlier record had changed items: it stays interrupted, so Continue and Put back stay possible.
                return self::failLaunch($state, 'The recovery worker did not start. Nothing more was changed.', $migrated !== [] ? 'interrupted' : 'error');
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
    public static function restoreInterrupted(array $seams = [], string $jobId = ''): array
    {
        require_once __DIR__ . '/FileStorage.php';
        require_once __DIR__ . '/StoragePathMoveJobService.php';
        $migrationLock = FileStorage::acquireMigrationLock(FileStorage::MIGRATION_LOCK_WAIT_S);
        if ($migrationLock === null) return ['status' => 'error', 'message' => 'Another storage operation is already running.'];
        $launchLock = false;
        try {
            $prior = self::readStatus();
            $target = StorageBackendPolicyService::normalizeMode((string)($prior['target'] ?? 'layering'));
            // #882: an older record can hold from == target (a mixed kind). The changed items are on the other engine.
            $from = self::sourceEngine((string)($prior['from'] ?? 'layering'), $target);
            $migrated = is_array($prior['migrated'] ?? null) ? array_values(array_unique(array_map('strval', $prior['migrated']))) : [];
            if (($prior['operation'] ?? '') !== 'rollback') {
                return ['status' => 'error', 'message' => 'The put-back request does not match the saved recovery record.'];
            }
            // #880 review: only the queued record this worker was started for may run. A late worker (the launcher gave
            // up on it, or a newer job took the record) stops here and changes nothing.
            if (($prior['status'] ?? '') !== 'queued' || ($jobId !== '' && (string)($prior['job_id'] ?? '') !== $jobId)) {
                return ['status' => 'error', 'message' => 'The queued put-back is no longer waiting, so this worker stopped without changing anything.'];
            }
            // Record the process id BEFORE the wait for the launch guard (up to 60 s), so the launcher sees the start.
            $prior['worker_pid'] = getmypid();
            $prior['updated_at'] = time();
            self::writeStatus($prior);
            self::writeDurableStatus($prior);
            if ($migrated === []) {
                // #880: no item was changed, so nothing is put back and no workspace is closed.
                if (self::safeSessionIds((array)($prior['sessions_to_resume'] ?? [])) === []) {
                    self::settleDidNotStart($prior);
                    return ['status' => 'ok', 'message' => 'Nothing was changed, so there is nothing to put back.', 'migrated' => []];
                }
                // Workspaces were closed for the job: open them again.
                $prior['status'] = 'complete';
                $prior['phase'] = 'complete';
                $prior['message'] = 'Nothing was changed. Your workspaces open again.';
                $prior['updated_at'] = time();
                self::writeStatus($prior);
                self::writeDurableStatus($prior);
                self::finishMigrationActivity('Nothing was changed.');
                self::releaseLaunchGuard($launchLock);
                FileStorage::releaseMigrationLock($migrationLock);
                $migrationLock = null;
                self::resumeMigrationSessions((array)($prior['sessions_to_resume'] ?? []), $seams);
                return ['status' => 'ok', 'message' => $prior['message'], 'migrated' => []];
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
            $state['worker_pid'] = getmypid();
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
    private static function startAsyncWithMigrationLock(string $target, bool $acknowledgeWear, array $config, array $seams, &$migrationLock, string $kind = StorageBackendPolicyService::KIND_ALL, bool $saveOnly = false): array
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
            // #880: this is not a job. Only a job that changes the same kind can conflict with it.
            $refusal = self::refusalFor($kind, true, $seams);
            if ($refusal !== null) return $refusal;
            $saver = $seams['save_kind'] ?? static fn(string $k, string $mode): bool => (bool)ConfigService::saveConfig(self::settingsToSave($k, $mode), false);
            if (!$saver($kind, $target)) {
                return ['status' => 'error', 'message' => 'The setting could not be saved. Try again.'];
            }
            return ['status' => 'saved', 'kind' => $kind, 'target' => $target,
                'message' => ucfirst(self::kindNoun($kind)) . ' were already on ' . strtolower(StorageBackendPolicyService::kindLabel($target)) . '. The setting is saved.'];
        }
        if ($saveOnly) {
            // #880: the card thought every item was on the target, the server finds items to convert.
            // A save-only request never starts a job and never closes a workspace.
            return ['status' => 'error', 'reason' => 'storage_items_differ',
                'message' => count($entities) . ' of the ' . self::kindNoun($kind) . ' are not on ' . strtolower(StorageBackendPolicyService::kindLabel($target))
                    . ' yet, so this is not only a setting. Reload the page, then choose the change again.'];
        }
        $refusal = self::refusalFor($kind, false, $seams);
        if ($refusal !== null) return $refusal;
        $check = $seams['preflight'] ?? self::preflight($target, $config, $seams, $kind);
        if (($check['status'] ?? '') !== 'ok') {
            return self::recordPreflightFailure($check, $target, $config, $entities, $kind);
        }
        $target = (string)$check['target'];
        // #882: remember the saved setting; the record names the engine the items come FROM (see sourceEngine()).
        $saved = $from;
        $from = self::sourceEngine($saved, $target);
        if (!empty($check['requires_acknowledgement']) && !$acknowledgeWear) {
            return ['status' => 'error', 'message' => 'Plain folders on removable/USB storage require an explicit wear acknowledgement.'];
        }

        $lock = self::acquireLaunchGuard(true);
        if ($lock === false) {
            return ['status' => 'error', 'message' => 'A storage-engine migration or workspace start is already running. Wait for it to finish, then try again.'];
        }
        try {
            // #880 review: repeat the refusal after the slow check, just before the queued record is written. Both job
            // kinds reserve under the migration lock, so a job that was written meanwhile is seen here.
            if (isset($seams['after_preflight']) && is_callable($seams['after_preflight'])) ($seams['after_preflight'])();
            $late = self::refusalFor($kind, false, $seams);
            if ($late !== null) return $late;
            $activeInstalls = self::activeInstallIds($seams);
            $busy = self::busyEntities($entities);
            if ($busy !== [] && $activeInstalls === []) {
                return ['status' => 'error', 'message' => 'Storage work is active for ' . implode(', ', $busy) . '. Wait for it to finish, then try the engine change again.'];
            }

            $waitingForInstalls = $activeInstalls !== [];
            $jobId = bin2hex(random_bytes(6));
            $state = [
                'status' => 'queued', 'kind' => $kind, 'from' => $from, 'saved_mode' => $saved, 'target' => $target,
                'started_at' => time(), 'updated_at' => time(), 'completed' => 0,
                'total' => count($entities), 'current' => $waitingForInstalls ? implode(', ', $activeInstalls) : '',
                'phase' => $waitingForInstalls ? 'waiting_for_installs' : 'queued',
                'message' => $waitingForInstalls
                    ? 'The migration is queued and waiting for agent installation to finish: ' . implode(', ', $activeInstalls) . '.'
                    : 'The migration is queued. Workspaces cannot open until it finishes.',
                'worker_pid' => 0,
                'job_id' => $jobId,
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
            // A finished item of an earlier job (its label says "needs attention") must not carry over to this job.
            if (class_exists(ActivityService::class)) ActivityService::dismiss(self::ACTIVITY_ID);
            self::updateMigrationActivity($state);
            // The queued state now refuses path moves. Free the shared lock
            // before spawning so the worker can take it on its first attempt.
            FileStorage::releaseMigrationLock($migrationLock);
            $migrationLock = null;
            // The launch guard also blocks workspace starts until the worker
            // records that it owns the storage policy.
            self::releaseLaunchGuard($lock);
            $lock = false;
            $spawn = $seams['spawn'] ?? static fn(string $mode, bool $ack): int
                => self::spawnWorker($mode, $ack, 'continue', $kind, $seams, $jobId);
            try {
                $pid = (int)$spawn($target, $acknowledgeWear);
            } catch (\Throwable $e) {
                return self::failLaunch($state, 'The migration worker did not start. No storage was changed.');
            }
            // #880: the launch only counts when the worker records its process id. A worker that
            // exits at once (a wrong PHP, a syntax error) leaves the record queued for ever otherwise.
            if ($pid < 1 || !self::workerStartConfirmed($seams, isset($seams['spawn']))) {
                return self::failLaunch($state, 'The migration worker did not start. No storage was changed. Check the Debug Console, then try again.');
            }
            return ['status' => 'accepted', 'message' => 'Migration started. Workspaces stay closed until it finishes.', 'worker_pid' => $pid];
        } finally {
            self::releaseLaunchGuard($lock);
        }
    }

    /**
     * The command that starts the detached worker. The command-line PHP runs it, never the PHP of
     * the web server (#880). The same launcher serves the writable-layer move.
     *
     * @return list<string>
     */
    public static function workerArgv(string $mode, bool $acknowledgeWear, string $operation, string $kind, array $seams = [], string $jobId = ''): array
    {
        $script = (string)($seams['worker_script'] ?? dirname(__DIR__, 2) . '/scripts/storage-backend-migrate.php');
        return array_merge([UtilityService::cliPhpBinary((array)($seams['php'] ?? []))], [$script, $mode, $acknowledgeWear ? '1' : '0', $operation, $kind], $jobId !== '' ? [$jobId] : []);
    }

    /** Start the detached worker and return its process id (0 when it did not start). */
    private static function spawnWorker(string $mode, bool $acknowledgeWear, string $operation, string $kind, array $seams, string $jobId = ''): int
    {
        require_once __DIR__ . '/UtilityService.php';
        $argv = self::workerArgv($mode, $acknowledgeWear, $operation, $kind, $seams, $jobId);
        // $argv[0] is the command-line PHP, $argv[1] the worker script. The shared launcher builds the command.
        return UtilityService::launchPhpWorker((string)$argv[1], array_slice($argv, 2), self::statusPath() . '.log', $seams);
    }

    /**
     * Wait a few seconds until the worker has recorded that it runs: its process id is in the record,
     * or the record has moved on from "queued". A caller that supplies its own spawn seam skips the
     * wait unless it also supplies a wait seam (the older tests do not run a worker).
     */
    private static function workerStartConfirmed(array $seams, bool $customSpawn): bool
    {
        if (isset($seams['wait_worker']) && is_callable($seams['wait_worker'])) return (bool)($seams['wait_worker'])();
        if ($customSpawn && !isset($seams['worker_wait_seconds'])) return true;
        require_once __DIR__ . '/UtilityService.php';
        return UtilityService::awaitWorkerStart(static function (): bool {
            $s = self::readStatus();
            return (int)($s['worker_pid'] ?? 0) > 0 || (string)($s['status'] ?? 'queued') !== 'queued';
        }, $seams);
    }

    /**
     * Mark a job whose worker did not start. The default end state is "error". A recovery that had
     * changed items goes back to "interrupted", so Continue and Put back stay possible. The tray item follows.
     */
    private static function failLaunch(array $state, string $message, string $status = 'error'): array
    {
        $state['status'] = $status;
        $state['phase'] = $status;
        $state['current'] = '';
        $state['message'] = $message;
        $state['updated_at'] = time();
        self::writeStatus($state);
        self::writeDurableStatus($state);
        self::failMigrationActivity($message);
        return ['status' => 'error', 'message' => $message];
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

    /**
     * #880 review: claim the queued record for THIS worker under a short lock. It succeeds only while the record is
     * "queued" and (when the worker was given a job id) carries the same job id. A late worker, or one that meets a
     * newer job, gets null and must change nothing. The claim records the worker's process id.
     */
    private static function claimQueuedJob(string $jobId): ?array
    {
        $fh = @fopen(self::lockPath() . '.claim', 'c');
        $locked = false;
        if ($fh !== false) {
            $deadline = microtime(true) + 3.0;
            while (!($locked = @flock($fh, LOCK_EX | LOCK_NB)) && microtime(true) < $deadline) usleep(50000);
        }
        try {
            if (!$locked) return null;
            $state = self::readStatus();
            if (($state['status'] ?? '') !== 'queued') return null;
            if ($jobId !== '' && (string)($state['job_id'] ?? '') !== $jobId) return null;
            $state['worker_pid'] = getmypid();
            $state['updated_at'] = time();
            self::writeStatus($state);
            self::writeDurableStatus($state);
            return $state;
        } finally {
            if ($fh !== false) { if ($locked) @flock($fh, LOCK_UN); @fclose($fh); }
        }
    }

    /** Run the accepted worker job and record a failure that happened before conversion began. */
    public static function runQueuedMigration(string $target, bool $acknowledgeWear, string $kind = StorageBackendPolicyService::KIND_ALL, string $jobId = ''): array
    {
        $state = self::claimQueuedJob($jobId);
        if ($state === null) {
            // #880: the launcher gave up on this worker (it did not record its start in time), or a
            // person dismissed the job. A late worker must not change storage for a job that is gone.
            return ['status' => 'error', 'message' => 'The queued storage change is no longer waiting, so this worker stopped without changing anything.'];
        }
        try {
            $result = self::migrate($target, $acknowledgeWear, ConfigService::getConfig(), [], $kind);
        } catch (\Throwable $e) {
            $state = self::readStatus();
            if (($state['status'] ?? '') === 'queued') {
                $state['status'] = self::stoppedStatus($state);   // #882: a Continue that changed items stays recoverable
                $state['phase'] = $state['status'];
                $state['message'] = 'The migration worker stopped before conversion began. Check the Debug Console for details.';
                $state['updated_at'] = time();
                self::writeStatus($state);
                self::writeDurableStatus($state);
                self::failMigrationActivity($state['message']);
            }
            throw $e;
        }
        $state = self::readStatus();
        if (($state['status'] ?? '') === 'queued') {
            $state['status'] = ($result['status'] ?? '') === 'ok' ? 'complete' : self::stoppedStatus($state);   // #882
            $state['phase'] = $state['status'];
            $state['message'] = (string)($result['message'] ?? 'The migration did not start.');
            $state['updated_at'] = time();
            self::writeStatus($state);
            self::writeDurableStatus($state);
            if ($state['status'] === 'complete') {
                self::finishMigrationActivity(ucfirst(self::activityName((string)($state['kind'] ?? ''))) . ' changed.');
            } else {
                self::failMigrationActivity($state['message']);
            }
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
                        $status = self::markInterrupted($status);
                    }
                } elseif ($durableState === 'queued' && self::queuedWorkerIsGone($durable)) {
                    $status = self::markInterrupted($status);
                } elseif ($durableState === 'interrupted') {
                    // Copy the flash record back to RAM so every reader sees one state.
                    self::writeStatus($status);
                }
            }
        }
        if (($status['status'] ?? '') === 'queued') {
            require_once __DIR__ . '/UtilityService.php';
        }
        if (($status['status'] ?? '') === 'queued' && self::queuedWorkerIsGone($status)) {
            $status = self::markInterrupted($status);
        }
        if (($status['status'] ?? '') === 'running') {
            $probe = self::acquireLaunchGuard(false);
            if ($probe !== false) {
                self::releaseLaunchGuard($probe);
                $status = self::markInterrupted($status);
            }
        }
        if (($status['status'] ?? '') === 'interrupted' && !self::hasWork($status)) {
            // #880: a record from before this rule, or a race. Nothing changed, so nothing waits for Continue or Put back.
            $status = self::settleDidNotStart($status);
        }
        if (($status['status'] ?? '') === 'interrupted') $status['recoverable'] = true;
        return $status;
    }

    /** @return list<string> the items the job changed ("type/id"), from the saved record */
    private static function changedItems(array $status): array
    {
        return is_array($status['migrated'] ?? null) ? array_values(array_unique(array_map('strval', $status['migrated']))) : [];
    }

    /**
     * #882: the status of a record whose worker stopped without converting anything more. A record that holds
     * work (changed items, closed workspaces) stays "interrupted", so Continue and Put back stay possible; only
     * a record with none becomes "error".
     */
    private static function stoppedStatus(array $state): string
    {
        return self::hasWork($state) ? 'interrupted' : 'error';
    }

    private static function oppositeMode(string $mode): string
    {
        return StorageBackendPolicyService::normalizeMode($mode) === StorageBackendPolicyService::PASSTHROUGH
            ? StorageBackendPolicyService::LAYERING : StorageBackendPolicyService::PASSTHROUGH;
    }

    /**
     * #882: the engine the changed items are on BEFORE the job, which is where Put back returns them. When the saved
     * setting already equals the target (a mixed kind: the setting says one engine, some items sit on the other), the
     * items to change are on the OTHER engine. A record with from == target could be neither continued nor put back.
     */
    private static function sourceEngine(string $saved, string $target): string
    {
        $saved = StorageBackendPolicyService::normalizeMode($saved);
        $target = StorageBackendPolicyService::normalizeMode($target);
        return $saved === $target ? self::oppositeMode($target) : $saved;
    }

    /**
     * #880 review: does a stopped job hold work that must be recovered? True when an item is recorded as
     * changed, when any item has left "pending" (a conversion may be half done), or when workspaces were
     * closed for the job (they must open again). Only a record with none of these "did not start".
     */
    private static function hasWork(array $status): bool
    {
        if (self::changedItems($status) !== []) return true;
        if (self::safeSessionIds((array)($status['sessions_to_resume'] ?? [])) !== []) return true;
        foreach ((array)($status['entities'] ?? []) as $entry) {
            if (is_array($entry) && (string)($entry['status'] ?? 'pending') !== 'pending') return true;
        }
        return false;
    }

    /**
     * A job whose worker is gone. With work to recover it becomes "interrupted" (Continue or Put back); an item
     * that was being converted counts as changed, because it may be half done. With none it is cleared as
     * "did not start" (#880). The tray item follows, and the stale engine marker is removed in both cases.
     */
    private static function markInterrupted(array $status): array
    {
        FileStorage::clearBackendMigrationMarker();
        if (!self::hasWork($status)) return self::settleDidNotStart($status);
        $migrated = self::changedItems($status);
        foreach ((array)($status['entities'] ?? []) as $key => $entry) {
            if (is_array($entry) && in_array((string)($entry['status'] ?? ''), ['converting', 'waiting', 'rolling_back'], true) && !in_array((string)$key, $migrated, true)) {
                $migrated[] = (string)$key;
            }
        }
        $status['migrated'] = $migrated;
        $status['status'] = 'interrupted';
        $status['message'] = self::interruptedMessage($status);
        $status['updated_at'] = time();
        self::writeStatus($status);
        self::writeDurableStatus($status);
        self::failMigrationActivity($status['message']);
        return $status;
    }

    /**
     * #880: an interrupted job with no work to recover did not start and changed nothing. The record becomes a
     * plain "error" record in RAM (the card shows it with Dismiss), the flash copy is removed so it does not
     * return after a restart, the engine marker is removed, and the tray item fails with the same words.
     */
    private static function settleDidNotStart(array $status): array
    {
        FileStorage::clearBackendMigrationMarker();
        $status['status'] = 'error';
        $status['phase'] = 'error';
        $status['current'] = '';
        $status['migrated'] = [];
        $status['message'] = 'The ' . self::changeName((string)($status['kind'] ?? '')) . ' did not start. Nothing was changed.';
        $status['updated_at'] = time();
        self::writeStatus($status);
        self::clearDurableStatus();
        self::failMigrationActivity($status['message']);
        return $status;
    }

    private static function clearDurableStatus(): void
    {
        $path = self::durableStatusPath();
        if (is_file($path)) @unlink($path);
    }

    private static function interruptedMessage(array $status): string
    {
        $migrated = self::changedItems($status);
        $total = max(0, (int)($status['total'] ?? 0));
        $engine = ($status['target'] ?? '') === StorageBackendPolicyService::PASSTHROUGH ? 'plain folders' : 'compressed layers';
        $name = self::changeName((string)($status['kind'] ?? ''));
        if ($migrated === []) {
            return 'The ' . $name . ' stopped after your workspaces were closed. No item was changed. Continue, or put back to open the workspaces again.';
        }
        return 'The ' . $name . ' did not finish: ' . count($migrated) . ' of ' . $total . ' items are ' . $engine . ' or may be half converted. Continue or put them back.';
    }

    /** Name of the change in a message: "home storage change", "agent storage change" or "storage change". */
    private static function changeName(string $kind): string
    {
        $kind = StorageBackendPolicyService::normalizeKind($kind);
        return $kind === StorageBackendPolicyService::KIND_HOMES ? 'home storage change'
            : ($kind === StorageBackendPolicyService::KIND_AGENTS ? 'agent storage change' : 'storage change');
    }

    /**
     * #882: is the worker of a queued record gone? After 60 s without a running process id, yes. After
     * QUEUED_MAX_AGE_SECONDS, yes whatever the process id says: a reboot can hand the saved id to another
     * process, and that process would hold the job in "queued" (and block every workspace start) for as long as it lives.
     */
    private static function queuedWorkerIsGone(array $status): bool
    {
        $age = time() - (int)($status['updated_at'] ?? 0);
        if ($age > self::QUEUED_MAX_AGE_SECONDS) return true;
        return $age > 60 && !self::workerPidIsRunning($status);
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
        foreach (['status', 'phase', 'kind', 'from', 'saved_mode', 'target', 'migrated', 'sessions_to_resume', 'entities', 'job_id', 'total', 'completed', 'current', 'message', 'worker_pid', 'operation', 'started_at', 'updated_at'] as $key) {
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

    /** Fail the tray item only when one is open (a refused start has none and needs no notice). */
    private static function failOpenMigrationActivity(string $error): void
    {
        if (!class_exists(ActivityService::class)) return;
        $entry = ActivityService::get(self::ACTIVITY_ID);
        if ($entry === null || !in_array((string)($entry['status'] ?? ''), ['running', 'stalled', 'queued'], true)) return;
        self::failMigrationActivity($error);
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
        $migrationLock = FileStorage::acquireMigrationLock(FileStorage::MIGRATION_LOCK_WAIT_S);
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
        // #882: remember the saved setting; the record names the engine the items come FROM (see sourceEngine()).
        $saved = $from;
        $from = self::sourceEngine($saved, $target);
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
            $isContinue = (($queued['operation'] ?? '') === 'continue' && ($queued['status'] ?? '') === 'queued' && ($queued['target'] ?? '') === $target);
            $priorMigrated = $isContinue
                ? (is_array($queued['migrated'] ?? null) ? array_values(array_unique(array_map('strval', $queued['migrated']))) : [])
                : [];
            // #882: the workspaces the earlier run closed must open again when this run ends. Keep that list.
            $priorSessions = $isContinue ? self::safeSessionIds((array)($queued['sessions_to_resume'] ?? [])) : [];
            $journal = self::entityJournal($entities, $from, $target);
            foreach ($priorMigrated as $key) {
                if (isset($journal[$key])) {
                    $journal[$key]['status'] = 'converted';
                    $journal[$key]['changed'] = true;
                }
            }
            $state = [
                'status' => 'running', 'kind' => $kind, 'from' => $from, 'saved_mode' => $saved, 'target' => $target,
                'started_at' => time(), 'updated_at' => time(), 'completed' => 0,
                'total' => count($entities), 'current' => '', 'phase' => 'waiting_for_installs',
                'message' => 'Checking for agent installations in progress.',
                'migrated' => $priorMigrated,
                'sessions_to_resume' => $priorSessions,
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
                    $state['status'] = self::stoppedStatus($state);   // #882: items already changed keep Continue and Put back
                    $state['phase'] = $state['status'];
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
                    $state['status'] = self::stoppedStatus($state);   // #882: keep Continue and Put back when items changed
                    $state['phase'] = $state['status'] === 'interrupted' ? 'interrupted' : 'preflight';
                    $state['current'] = $state['status'] === 'interrupted' ? '' : $busy[0];
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
                        && preg_match('/^install_([A-Za-z0-9._-]{1,128})$/D', $opId, $m)) {
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
            // #882: merge with the list an earlier run saved (Continue): those workspaces are closed already, but they must open again at the end.
            $running = self::safeSessionIds($sessionIds);
            $state['sessions_to_resume'] = array_values(array_unique(array_merge(self::safeSessionIds((array)($state['sessions_to_resume'] ?? [])), $running)));
            $state['updated_at'] = time();
            if (!self::writeStatus($state) || !self::writeDurableStatus($state)) {
                $failure = 'The workspace list could not be saved before migration. No storage was changed.';
                return false;
            }
            self::updateMigrationActivity($state);
            if (isset($seams['evict'])) {
                ($seams['evict'])(false, $running);
            } else {
                if (class_exists(TerminalService::class) && method_exists(TerminalService::class, 'captureResumeForShutdown')) {
                    TerminalService::captureResumeForShutdown($running, 60);
                }
                ProcessManager::evictAllForStorageMigration($running);
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
                    // #880 review: the durable record says "converting" BEFORE the conversion starts. A crash or a
                    // reboot during the conversion then leaves a record that never claims "nothing was changed".
                    self::writeDurableStatus($state);
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

    /**
     * Enumerate only candidate entities; the shell remains authoritative about presence.
     * #880: this is the items list of itemLists(), the one list the Storage card and the migration share.
     */
    public static function entities(array $config, array $seams = []): array
    {
        return self::itemLists($config, $seams)['items'];
    }

    /**
     * #880: THE list of storage items, one for each kind. The Storage card (kindStates) and the migration
     * (entities) both read it, so the card never counts differently from the server.
     *
     * - `items`: `{type: agent|home, id}`. A home is an item only when its user exists (a Unix account or a
     *   user of a saved workspace) and it has stored data (a layer file or a non-empty plain folder) or a workspace.
     * - `orphans`: homes that have stored data for a user that does not exist (for example a layer file of a
     *   deleted account). They are not items: nothing converts them, and the card reports them in one line.
     *
     * Seams (tests): manifest_entities, workspace_users, passwd_users, home_layer_files, plain_home_has_data,
     * installed_agents (list of ids, instead of the agent registry).
     *
     * @return array{items:list<array{type:string,id:string}>,orphans:list<array{type:string,id:string}>}
     */
    public static function itemLists(array $config, array $seams = []): array
    {
        $paths = [
            'agent' => (string)($config['agent_storage_path'] ?? ''),
            'home' => (string)($config['home_storage_path'] ?? ''),
        ];
        $agents = [];
        $homeCandidates = [];
        $add = static function (string $type, string $id) use (&$agents, &$homeCandidates): void {
            if (!preg_match('/^[A-Za-z0-9_.-]{1,128}$/D', $id) || $id === '.' || $id === '..') return;
            if ($type === 'agent') $agents[$id] = true; else $homeCandidates[$id] = true;
        };
        $manifestEntities = $seams['manifest_entities'] ?? LayerManifestService::getAllEntities();
        foreach ($manifestEntities as $entity => $_entry) {
            if (preg_match('#^(agent|home)/([A-Za-z0-9_.-]+)$#D', (string)$entity, $m) !== 1) continue;
            if ($m[1] === 'agent' || self::homeHasStorage($m[2], (string)$paths['home'], $seams)) $add($m[1], $m[2]);
        }
        foreach ($paths as $type => $path) {
            if (trim($path) === '') continue;
            foreach (glob(rtrim($path, '/') . '/' . $type . '_*.sqsh') ?: [] as $file) {
                if ($type === 'agent' && ($agentId = StorageMetricsService::agentIdFromLayerFilename(basename($file))) !== null) {
                    $add($type, $agentId);
                } elseif (preg_match('/^' . $type . '_(.+?)_(?:v\d+_)?(?:vol1|delta|consolidated)/', basename($file), $m)) {
                    $add($type, $m[1]);
                }
            }
            foreach (glob(rtrim($path, '/') . '/passthrough/' . $type . 's/*') ?: [] as $dir) {
                $id = basename($dir);
                if ($id === '.versions' || $id === '.staging' || $id === '.staging-data') continue;
                if ($type === 'home' && (!is_dir($dir) || is_link($dir) || !self::directoryHasContent($dir))) continue;
                $add($type, $id);
            }
        }
        if (isset($seams['installed_agents'])) {
            foreach ((array)$seams['installed_agents'] as $id) $add('agent', (string)$id);
        } elseif (class_exists(AgentRegistry::class)) {
            foreach (AgentRegistry::getRegistry() as $id => $agent) {
                if (!empty($agent['is_installed'])) $add('agent', (string)$id);
            }
        }
        $workspaceUsers = array_map('strval', $seams['workspace_users'] ?? self::workspaceHomeUsers());
        foreach ($workspaceUsers as $user) {
            $add('home', $user);
        }
        $passwdUsers = array_map('strval', $seams['passwd_users'] ?? (class_exists(UtilityService::class) ? UtilityService::getUnraidUsers() : []));
        foreach ($passwdUsers as $user) {
            if (self::homeHasStorage($user, (string)$paths['home'], $seams)) $add('home', $user);
        }
        // #880 review: a user has an account whatever its UID (a service account outside 0 and 1000-59999 still has one),
        // is the configured terminal user, or has a home that is mounted now. When the account list is empty or cannot
        // be read, nothing can be called an orphan: every candidate is an item.
        $allAccounts = array_map('strval', $seams['all_passwd_users'] ?? (isset($seams['passwd_users']) ? $passwdUsers : (class_exists(UtilityService::class) ? UtilityService::getAllPasswdUsers() : [])));
        $realUsers = array_fill_keys(array_merge($workspaceUsers, $passwdUsers, $allAccounts, [(string)($config['user'] ?? '')]), true);
        $accountListKnown = $allAccounts !== [] || $passwdUsers !== [];
        $isMountedHome = $seams['is_mounted_home'] ?? static fn(string $user): bool => class_exists(StorageMountService::class) && StorageMountService::isMounted(UtilityService::getWorkDir($user) . '/home');
        $items = [];
        $orphans = [];
        foreach (array_keys($agents) as $id) $items[] = ['type' => 'agent', 'id' => (string)$id];
        foreach (array_keys($homeCandidates) as $id) {
            if (!$accountListKnown || isset($realUsers[(string)$id]) || $isMountedHome((string)$id)) $items[] = ['type' => 'home', 'id' => (string)$id];
            else $orphans[] = ['type' => 'home', 'id' => (string)$id];
        }
        $byKey = static fn(array $a, array $b): int => strcmp($a['type'] . '/' . $a['id'], $b['type'] . '/' . $b['id']);
        usort($items, $byKey);
        usort($orphans, $byKey);
        return ['items' => $items, 'orphans' => $orphans];
    }

    /** A user record counts only when a layer, non-empty plain home, or workspace exists. */
    private static function homeHasStorage(string $user, string $path, array $seams): bool
    {
        if (!preg_match('/^[A-Za-z0-9_.-]{1,128}$/D', $user) || $user === '.' || $user === '..') return false;
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
