<?php

declare(strict_types=1);

namespace AICliAgents\Services;

/**
 * Fail-closed repair coordinator for the current FileStorage architecture.
 *
 * This is a storage-component internal maintenance coordinator: lifecycle
 * callers invoke run(), while integrity and manifest details stay inside the
 * storage owner boundary defined by ADR 0001.
 *
 * Repair never formats, deletes, migrates, consolidates, or reinstalls. It
 * classifies durable state first, refuses unsafe entities, and only asks the
 * authoritative FileStorage facade to ensure known-good entities are ready.
 */
final class RepairService
{
    /** @return array<int,string> */
    public static function targetEntities(): array
    {
        $targets = array_keys(LayerManifestService::getAllEntities());
        $config = ConfigService::getConfig();
        $user = trim((string)($config['user'] ?? 'root')) ?: 'root';
        $targets[] = "home/$user";

        foreach (AgentRegistry::getVersions() as $agentId => $entry) {
            $version = is_array($entry) ? (string)($entry['installed'] ?? '') : (string)$entry;
            if ($version !== '' && !in_array($version, ['0.0.0', 'unknown', 'installed'], true)) {
                $targets[] = 'agent/' . (string)$agentId;
            }
        }

        $targets = array_values(array_unique(array_filter($targets, static function ($entity): bool {
            return is_string($entity) && preg_match('#^(home|agent)/[A-Za-z0-9._-]+$#', $entity) === 1;
        })));
        sort($targets, SORT_STRING);
        return $targets;
    }

    /**
     * @param array<string,callable> $seams Test-only dependency seams.
     * @return array{ok:bool,mode:string,checked:int,ready:int,deferred:int,failures:array<int,array<string,string>>,supervisor:string}
     */
    public static function run(bool $apply = true, array $seams = []): array
    {
        $targets = $seams['targets'] ?? static fn(): array => self::targetEntities();
        $classify = $seams['classify'] ?? static fn(string $entity): array => BootIntegrityService::classifyEntity($entity);
        $status = $seams['status'] ?? static function (string $entity): array {
            $s = FileStorage::status($entity);
            return ['mounted' => $s->mounted, 'state' => $s->state];
        };
        $ensure = $seams['ensure'] ?? static function (string $entity): array {
            $r = FileStorage::ensureReady($entity, ['reason' => 'manual_repair']);
            return ['ok' => $r->ok, 'deferred' => $r->deferred, 'state' => $r->state];
        };
        $binaryPresent = $seams['binary_present'] ?? static fn(string $agentId): bool => self::binaryPresent($agentId);
        $ensureNginx = $seams['nginx'] ?? static fn() => ConfigService::ensureNginxConfig();
        $ensureSupervisor = $seams['supervisor'] ?? static fn(): string => SupervisorService::ensureHealthy();

        $result = [
            'ok' => true,
            'mode' => $apply ? 'apply' : 'check',
            'checked' => 0,
            'ready' => 0,
            'deferred' => 0,
            'failures' => [],
            'supervisor' => 'not_checked',
        ];

        foreach ($targets() as $entity) {
            $result['checked']++;
            $integrity = $classify($entity);
            $integrityState = (string)($integrity['state'] ?? BootIntegrityService::STATE_UNAVAILABLE);
            if (!in_array($integrityState, [BootIntegrityService::STATE_HEALTHY, BootIntegrityService::STATE_GENUINE_FRESH], true)) {
                $result['failures'][] = ['entity' => $entity, 'reason' => "integrity:$integrityState"];
                continue;
            }

            $storage = $status($entity);
            $entityReady = !empty($storage['mounted']);
            if (!empty($storage['mounted'])) {
                $result['ready']++;
            } elseif ($apply) {
                $readiness = $ensure($entity);
                if (empty($readiness['ok'])) {
                    $result['failures'][] = ['entity' => $entity, 'reason' => 'mount:' . (string)($readiness['state'] ?? 'unavailable')];
                    continue;
                }
                if (!empty($readiness['deferred'])) {
                    $result['deferred']++;
                } else {
                    $result['ready']++;
                    $entityReady = true;
                }
            }

            // In check mode an unmounted agent's binary is intentionally not
            // visible yet. Validate it only when its storage is actually ready.
            if ($entityReady && str_starts_with($entity, 'agent/')) {
                $agentId = substr($entity, 6);
                if (!$binaryPresent($agentId)) {
                    $result['failures'][] = ['entity' => $entity, 'reason' => 'binary_missing_reinstall_required'];
                }
            }
        }

        if ($apply) {
            $ensureNginx();
            $result['supervisor'] = (string)$ensureSupervisor();
            if (!in_array($result['supervisor'], ['ok', 'started', 'healed', 'suppressed'], true)) {
                $result['failures'][] = ['entity' => 'supervisor', 'reason' => 'not_healthy:' . $result['supervisor']];
            }
        }
        $result['ok'] = ($result['failures'] === []);
        return $result;
    }

    private static function binaryPresent(string $agentId): bool
    {
        $registry = AgentRegistry::getRegistry();
        $agent = $registry[$agentId] ?? null;
        if (!is_array($agent)) return false;
        foreach (['binary', 'binary_fallback'] as $key) {
            $path = (string)($agent[$key] ?? '');
            if ($path !== '' && (is_file($path) || is_link($path))) return true;
        }
        return false;
    }
}
