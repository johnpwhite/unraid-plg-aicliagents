<?php
/**
 * Global storage-engine policy and the user-facing interpretation of the
 * read-only storage probe.  The policy is global by design: an agent must not
 * silently choose a different persistence engine from the other agents.
 */

namespace AICliAgents\Services;

final class StorageBackendPolicyService
{
    public const LAYERING = 'layering';
    public const PASSTHROUGH = 'passthrough';

    public static function normalizeMode($mode): string
    {
        $mode = strtolower(trim((string)$mode));
        return in_array($mode, [self::LAYERING, self::PASSTHROUGH], true)
            ? $mode
            : self::LAYERING;
    }

    public static function label(string $mode): string
    {
        return self::normalizeMode($mode) === self::PASSTHROUGH
            ? 'Plain folders'
            : 'SquashFS layers';
    }

    // ---- Two kinds of storage: homes and agents (docs/specs/GLOBAL_STORAGE_BACKEND_MIGRATION.md) ----

    public const KIND_HOMES = 'homes';
    public const KIND_AGENTS = 'agents';
    /** Both kinds at once. Only the one-engine call path of older callers uses it. */
    public const KIND_ALL = 'all';
    public const KEY_HOMES = 'storage_backend_homes';
    public const KEY_AGENTS = 'storage_backend_agents';

    public static function normalizeKind($kind): string
    {
        $kind = strtolower(trim((string)$kind));
        return in_array($kind, [self::KIND_HOMES, self::KIND_AGENTS], true) ? $kind : self::KIND_ALL;
    }

    /** The cfg key that holds the saved setting of one kind (homes for "all"). */
    public static function keyForKind(string $kind): string
    {
        return self::normalizeKind($kind) === self::KIND_AGENTS ? self::KEY_AGENTS : self::KEY_HOMES;
    }

    /** True when the value is one of the two engines (no fallback applied). */
    public static function isMode($value): bool
    {
        return in_array(strtolower(trim((string)$value)), [self::LAYERING, self::PASSTHROUGH], true);
    }

    /**
     * Resolve the saved setting of both kinds from the raw cfg keys.
     * The two new keys win. When a new key is absent, read the old keys for one more release:
     * homes follow `storage_backend_mode`. Agents follow it too, except that
     * `keep_agents_compressed` = 1 keeps them in layers. On an internal boot disk with the
     * flag off, the old rule made new agents plain folders ($internalBoot).
     *
     * @param array<string,mixed> $raw  cfg keys as read from the file (may be empty)
     * @param bool $internalBoot        true when the agents live on an internal boot disk
     * @return array{homes:string,agents:string}
     */
    public static function resolveKindModes(array $raw, bool $internalBoot = false): array
    {
        $legacy = self::normalizeMode($raw['storage_backend_mode'] ?? self::LAYERING);
        $homes = self::isMode($raw[self::KEY_HOMES] ?? null) ? self::normalizeMode($raw[self::KEY_HOMES]) : $legacy;
        if (self::isMode($raw[self::KEY_AGENTS] ?? null)) {
            $agents = self::normalizeMode($raw[self::KEY_AGENTS]);
        } elseif ($legacy === self::PASSTHROUGH) {
            $agents = self::PASSTHROUGH;
        } elseif ((string)($raw['keep_agents_compressed'] ?? '0') === '1') {
            $agents = self::LAYERING;
        } else {
            $agents = $internalBoot ? self::PASSTHROUGH : self::LAYERING;
        }
        return ['homes' => $homes, 'agents' => $agents];
    }

    /** The saved setting of one kind from a resolved config (homes for "all"). */
    public static function kindMode(array $config, string $kind): string
    {
        return self::normalizeMode($config[self::keyForKind($kind)] ?? self::LAYERING);
    }

    /** Map a storage status `backend` value to an engine: "flash" is the layer engine. */
    public static function engineOfBackend(string $backend): string
    {
        return $backend === 'passthrough' ? self::PASSTHROUGH : self::LAYERING;
    }

    /**
     * The real state of one kind, from the backend of each item in the storage status.
     * `homes[*].backend` and `agents[*].backend` hold "flash" (SquashFS layers) or
     * "passthrough" (plain folders). The saved setting does not matter here: one home
     * that a person converted by hand makes the kind "mixed".
     *
     * @param array<string,string> $backends item id => backend
     * @return array{state:string,plain:int,layers:int,total:int,label:string}
     */
    public static function kindState(array $backends, string $kind): array
    {
        $plain = 0;
        $layers = 0;
        foreach ($backends as $backend) {
            if (self::engineOfBackend((string)$backend) === self::PASSTHROUGH) $plain++; else $layers++;
        }
        $total = $plain + $layers;
        $noun = self::normalizeKind($kind) === self::KIND_AGENTS ? 'agents' : 'homes';
        if ($total === 0) {
            $state = 'none';
            $label = 'No ' . $noun . ' yet';
        } elseif ($layers === 0) {
            $state = 'plain';
            $label = 'Plain folders';
        } elseif ($plain === 0) {
            $state = 'layers';
            $label = 'SquashFS layers';
        } else {
            $state = 'mixed';
            $label = 'Mixed: ' . $plain . ' of ' . $total . ' ' . $noun . ' are plain folders';
        }
        return ['state' => $state, 'plain' => $plain, 'layers' => $layers, 'total' => $total, 'label' => $label];
    }

    /**
     * The real state of both kinds from a storage status array (StorageMetricsService::getStatus()).
     * A plain-folder agent has no layer file, so the status does not list it. Pass the ids of
     * the installed agents: each one that the status does not list counts as plain folders.
     * Each result also holds `backends` (item id => backend) for the migration worker.
     *
     * @param array<string,mixed> $status
     * @param list<string> $installedAgentIds
     * @return array{homes:array,agents:array}
     */
    public static function realStates(array $status, array $installedAgentIds = []): array
    {
        $homes = [];
        foreach ((array)($status['homes'] ?? []) as $id => $row) {
            $homes[(string)$id] = (string)(is_array($row) ? ($row['backend'] ?? 'flash') : 'flash');
        }
        $agents = [];
        foreach ((array)($status['agents'] ?? []) as $id => $row) {
            $agents[(string)$id] = (string)(is_array($row) ? ($row['backend'] ?? 'flash') : 'flash');
        }
        foreach ($installedAgentIds as $id) {
            if (!isset($agents[(string)$id])) $agents[(string)$id] = 'passthrough';
        }
        return [
            'homes' => self::kindState($homes, self::KIND_HOMES) + ['backends' => $homes],
            'agents' => self::kindState($agents, self::KIND_AGENTS) + ['backends' => $agents],
        ];
    }

    /** Plain name of an engine, as the Configuration selects show it. */
    public static function kindLabel(string $mode): string
    {
        return self::normalizeMode($mode) === self::PASSTHROUGH ? 'Plain folders' : 'SquashFS layers';
    }

    /**
     * The probe's wear axis is derived from the actual backing device, not from
     * a path prefix.  `wear_sensitive` is emitted for removable/USB transport
     * and for intentionally uncertain facts, so direct mode is never silently
     * selected on a device we cannot identify safely.
     */
    public static function isRemovableOrUsb(array $probe): bool
    {
        return ($probe['wear'] ?? '') === 'wear_sensitive'
            || ($probe['mount_class'] ?? '') === 'boot_usb';
    }

    public static function directSelection(array $probe, string $path): array
    {
        $warnings = array_values(array_unique((array)($probe['warnings'] ?? [])));
        $usb = self::isRemovableOrUsb($probe);
        $refused = !empty($probe['refuse']);

        if ($usb) {
            $warnings[] = 'usb_direct_wear';
        }
        if (($probe['mount_class'] ?? '') === 'user_share') {
            $warnings[] = 'user_share_direct_risk';
        }
        if (($probe['durability'] ?? '') !== 'durable') {
            $warnings[] = 'non_durable_target';
        }

        $warnings = array_values(array_unique($warnings));
        return [
            'path' => $path,
            'is_usb_or_removable' => $usb,
            'requires_acknowledgement' => $usb,
            'recommended_mode' => $usb ? self::LAYERING : self::PASSTHROUGH,
            'warnings' => $warnings,
            'refuse' => $refused,
            'message' => $usb
                ? 'This path is backed by removable/USB storage. Plain folders write every change directly to that device. SquashFS layers are recommended to reduce wear.'
                : ($refused
                    ? 'The storage probe refused this target.'
                    : 'Plain folders write changes directly to the selected path.'),
        ];
    }

    /**
     * Return the two global persistence target verdicts used by the settings
     * confirmation.  Keeping this here prevents the UI and migration handler
     * from implementing different USB decisions.
     */
    public static function targetVerdicts(array $config): array
    {
        $agentPath = (string)($config['agent_storage_path'] ?? '');
        $homePath = StoragePathResolver::homePersistPathFromConfig($config);   // R-046: the one resolver
        $out = [];
        foreach (['agent' => $agentPath, 'home' => $homePath] as $kind => $path) {
            $probe = FileStorage::probeTarget($path);
            $out[$kind] = array_merge(
                ['kind' => $kind, 'path' => $path, 'probe' => $probe],
                self::directSelection($probe, $path)
            );
        }
        return $out;
    }
}
