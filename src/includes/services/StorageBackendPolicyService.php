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
            ? 'Plain directory'
            : 'SquashFS layers + zram';
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
                ? 'This path is backed by removable/USB storage. Plain-directory mode writes every change directly to that device. SquashFS layers with zram are recommended to reduce wear.'
                : ($refused
                    ? 'The storage probe refused this target.'
                    : 'Plain-directory mode writes changes directly to the selected path.'),
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
        $homePath = (string)($config['home_storage_path'] ?? $agentPath);
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
