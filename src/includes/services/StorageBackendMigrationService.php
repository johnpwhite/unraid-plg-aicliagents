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
    public static function preflight(string $target, ?array $config = null): array
    {
        $config = $config ?? ConfigService::getConfig();
        $target = StorageBackendPolicyService::normalizeMode($target);
        $verdicts = StorageBackendPolicyService::targetVerdicts($config);
        $refused = [];
        $usb = [];
        foreach ($verdicts as $kind => $verdict) {
            if (!empty($verdict['refuse'])) $refused[] = $kind;
            if (!empty($verdict['is_usb_or_removable'])) $usb[] = $kind;
        }
        if ($target === StorageBackendPolicyService::PASSTHROUGH && $refused !== []) {
            return [
                'status' => 'error',
                'message' => 'Plain-directory mode cannot use the refused storage target for: ' . implode(', ', $refused) . '.',
                'target' => $target,
                'targets' => $verdicts,
            ];
        }
        $message = $target === StorageBackendPolicyService::PASSTHROUGH
            ? 'All installed agents and homes will be converted to plain directories. Existing sessions will be closed safely, old SquashFS data will be retained in the migration archive, and new installs can activate side by side.'
            : 'All installed agents and homes will be converted to SquashFS layers with writable zram state. Existing plain directories will be archived only after the layer and manifest are verified.';
        if ($usb !== []) {
            $message .= ' The probe identified removable/USB-backed storage (' . implode(', ', $usb) . '); SquashFS + zram is recommended to reduce wear.';
        }
        return [
            'status' => 'ok',
            'target' => $target,
            'current' => StorageBackendPolicyService::normalizeMode($config['storage_backend_mode'] ?? 'layering'),
            'targets' => $verdicts,
            'requires_acknowledgement' => $usb !== [],
            'message' => $message,
        ];
    }

    public static function migrate(string $target, bool $acknowledgeWear = false, ?array $config = null): array
    {
        $config = $config ?? ConfigService::getConfig();
        $check = self::preflight($target, $config);
        if (($check['status'] ?? '') !== 'ok') return $check;
        $target = (string)$check['target'];
        if (!empty($check['requires_acknowledgement']) && !$acknowledgeWear) {
            return ['status' => 'error', 'message' => 'Plain-directory mode on removable/USB storage requires an explicit wear acknowledgement.'];
        }
        $from = StorageBackendPolicyService::normalizeMode($config['storage_backend_mode'] ?? 'layering');
        $entities = self::entities($config);
        if ($from === $target && $entities === []) {
            ConfigService::saveConfig(['storage_backend_mode' => $target], false);
            return ['status' => 'ok', 'message' => 'Storage-engine policy saved; there was no entity data to migrate.', 'migrated' => []];
        }

        $migrated = [];
        $failure = null;
        $result = FileStorage::migrateBackendPolicy($from, $target, function () use ($entities, $target, &$migrated, &$failure): bool {
            // No workspace may keep writing while the authority of its storage
            // engine is changing. The shell operation still refuses a busy mount.
            if (class_exists(TerminalService::class) && method_exists(TerminalService::class, 'captureResumeForShutdown')) {
                TerminalService::captureResumeForShutdown(null, 60);
            }
            ProcessManager::evictAll();
            foreach ($entities as $entity) {
                $r = FileStorage::migrateBackend(
                    $entity['type'],
                    $entity['id'],
                    $target,
                    $target === StorageBackendPolicyService::PASSTHROUGH
                );
                if ((int)($r['exit'] ?? 1) !== 0) {
                    $failure = $entity['type'] . '/' . $entity['id'] . ': ' . (($r['reason'] ?? '') ?: 'storage conversion failed');
                    return false;
                }
                $migrated[] = $entity['type'] . '/' . $entity['id'];
            }
            return true;
        });
        if (!$result->ok) {
            return ['status' => 'error', 'message' => $failure ?: ($result->error ?: 'Storage-engine migration failed.'), 'migrated' => $migrated];
        }
        if (!ConfigService::saveConfig(['storage_backend_mode' => $target], false)) {
            return ['status' => 'error', 'message' => 'Storage data migrated, but the global policy could not be saved. Re-open Settings and retry.', 'migrated' => $migrated];
        }
        return ['status' => 'ok', 'message' => 'Migrated ' . count($migrated) . ' storage entities to ' . StorageBackendPolicyService::label($target) . '.', 'migrated' => $migrated];
    }

    /** Enumerate only candidate entities; the shell remains authoritative about presence. */
    public static function entities(array $config): array
    {
        $paths = [
            'agent' => (string)($config['agent_storage_path'] ?? ''),
            'home' => (string)($config['home_storage_path'] ?? ''),
        ];
        $out = [];
        $seen = [];
        $add = static function (string $type, string $id) use (&$out, &$seen): void {
            if (!preg_match('/^[A-Za-z0-9_.-]{1,128}$/', $id)) return;
            $key = $type . '/' . $id;
            if (isset($seen[$key])) return;
            $seen[$key] = true;
            $out[] = ['type' => $type, 'id' => $id];
        };
        foreach (LayerManifestService::getAllEntities() as $entity => $_entry) {
            if (preg_match('#^(agent|home)/([A-Za-z0-9_.-]+)$#', (string)$entity, $m)) $add($m[1], $m[2]);
        }
        foreach ($paths as $type => $path) {
            foreach (glob(rtrim($path, '/') . '/' . $type . '_*.sqsh') ?: [] as $file) {
                if (preg_match('/^' . $type . '_(.+?)_(?:v\d+_)?(?:vol1|delta|consolidated)/', basename($file), $m)) $add($type, $m[1]);
            }
            foreach (glob(rtrim($path, '/') . '/passthrough/' . $type . 's/*') ?: [] as $dir) {
                $id = basename($dir);
                if ($id !== '.versions' && $id !== '.staging' && $id !== '.staging-data') $add($type, $id);
            }
        }
        if (class_exists(AgentRegistry::class)) {
            foreach (AgentRegistry::getRegistry() as $id => $agent) {
                if (!empty($agent['is_installed'])) $add('agent', (string)$id);
            }
        }
        if (class_exists(UtilityService::class)) {
            foreach (UtilityService::getUnraidUsers() as $user) $add('home', (string)$user);
        }
        usort($out, static fn(array $a, array $b): int => strcmp($a['type'] . '/' . $a['id'], $b['type'] . '/' . $b['id']));
        return $out;
    }
}
