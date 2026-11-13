<?php
/**
 * <module_context>
 *     <name>GuardPolicy</name>
 *     <description>The one resolver of the share-filesystem guard protections (docs/specs/SHFS_RECURSIVE_READ_GUARD.md R18, Forgejo #972). Each protection is a separate choice: a server default on the Settings page and an override in the workspace record (`protections`, values on or off; a missing name means "use the server default"). effectiveProtections() turns the master switch, the server keys and one workspace record into six booleans: shell, hooks, view, viewSsh, viewDocker, viewCaps. The values are fixed when the workspace starts.</description>
 *     <dependencies>ConfigService (getConfig), SpawnService (hasParent), ShfsView (the config test seam)</dependencies>
 *     <constraints>Pure but for the config read. An unknown protection name or value is ignored (never "on" by accident). The legacy record field `guardExempt: true` without `protections` reads as shell off and hooks off. Only a person writes `protections` (ConfigService::setWorkspaceProtections); no agent tool reaches it.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class GuardPolicy
{
    /** The protection names, in the order of the UI. */
    public const NAMES = ['shell', 'hooks', 'view', 'viewSsh', 'viewDocker', 'viewCaps'];

    /** Protection name => the server key. `view` has two keys: see serverKey(). */
    public const SERVER_KEYS = [
        'shell'      => 'guard_shell',
        'hooks'      => 'guard_hooks',
        'viewSsh'    => 'guard_view_ssh',
        'viewDocker' => 'guard_view_docker',
        'viewCaps'   => 'guard_view_caps',
    ];
    public const KEY_VIEW_HELPERS = 'guard_view_helpers';
    public const KEY_VIEW_PARENTS = 'guard_view_parents';

    /** Server key => default ('1' on, '0' off). Also the list of every guard_* key of the config. */
    public const DEFAULTS = [
        'guard_shell'          => '1',
        'guard_hooks'          => '1',
        'guard_view_helpers'   => '1',
        'guard_view_parents'   => '0',
        'guard_view_ssh'       => '1',
        'guard_view_docker'    => '1',
        'guard_view_caps'      => '1',
    ];

    /** @return array<string,mixed> the plugin config (the ShfsView seam first, for tests) */
    private static function config(): array
    {
        if (ShfsView::$configForTests !== null) return ShfsView::$configForTests;
        try {
            return \function_exists('getAICliConfig') ? getAICliConfig() : ConfigService::getConfig();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** A config value as a boolean: off only for 0, false, off or no; the default when the value is missing. */
    private static function flag($value, bool $default): bool
    {
        if ($value === null) return $default;
        $v = strtolower(trim((string)$value));
        if ($v === '') return $default;
        return !in_array($v, ['0', 'false', 'off', 'no'], true);
    }

    /** The master switch `shfs_guard` (default on). */
    public static function masterOn(?array $config = null): bool
    {
        $config = $config ?? self::config();
        return self::flag($config['shfs_guard'] ?? null, true);
    }

    /** The server key that sets a protection for this record (the view has two). */
    public static function serverKey(string $name, bool $hasParent): string
    {
        if ($name === 'view') return $hasParent ? self::KEY_VIEW_HELPERS : self::KEY_VIEW_PARENTS;
        return self::SERVER_KEYS[$name] ?? '';
    }

    /**
     * The server default of each protection for one kind of workspace.
     * @return array<string,bool>
     */
    public static function serverDefaults(bool $hasParent, ?array $config = null): array
    {
        $config = $config ?? self::config();
        $out = [];
        foreach (self::NAMES as $name) {
            $key = self::serverKey($name, $hasParent);
            $out[$name] = self::flag($config[$key] ?? null, self::DEFAULTS[$key] === '1');
        }
        return $out;
    }

    /**
     * The overrides of a record: name => 'on'|'off'. A missing name is the server default. A record that has the legacy
     * `guardExempt: true` and no `protections` reads as shell off and hooks off.
     * @param array<string,mixed> $record
     * @return array<string,string>
     */
    public static function overrides(array $record): array
    {
        $out = [];
        if (is_array($record['protections'] ?? null)) {
            foreach ($record['protections'] as $name => $value) {
                if (!in_array($name, self::NAMES, true)) continue;
                $v = is_string($value) ? strtolower(trim($value)) : '';
                if ($v === 'on' || $v === 'off') $out[$name] = $v;
            }
        } elseif (!empty($record['guardExempt'])) {
            $out = ['shell' => 'off', 'hooks' => 'off'];
        }
        return $out;
    }

    /**
     * What is on for one workspace record. The master switch Off gives all false.
     * @param array<string,mixed> $workspaceRecord
     * @param array<string,mixed>|null $config the plugin config (null reads it)
     * @return array{shell:bool,hooks:bool,view:bool,viewSsh:bool,viewDocker:bool,viewCaps:bool}
     */
    public static function effectiveProtections(array $workspaceRecord, ?array $config = null): array
    {
        $config = $config ?? self::config();
        if (!self::masterOn($config)) {
            return ['shell' => false, 'hooks' => false, 'view' => false, 'viewSsh' => false, 'viewDocker' => false, 'viewCaps' => false];
        }
        $defaults = self::serverDefaults(SpawnService::hasParent($workspaceRecord), $config);
        $over = self::overrides($workspaceRecord);
        $out = [];
        foreach (self::NAMES as $name) {
            $out[$name] = isset($over[$name]) ? $over[$name] === 'on' : $defaults[$name];
        }
        /** @var array{shell:bool,hooks:bool,view:bool,viewSsh:bool,viewDocker:bool,viewCaps:bool} $out */
        return $out;
    }

    /**
     * Check a map of overrides that a person sent. Returns [clean map, error sentence or null]. 'default' (or an empty
     * value) removes an override. An unknown name or value is an error.
     * @param mixed $raw
     * @return array{0:array<string,string>,1:?string}
     */
    public static function validateOverrides($raw): array
    {
        if (!is_array($raw)) return [[], 'protections must be an object of name => default, on or off.'];
        $out = [];
        foreach ($raw as $name => $value) {
            if (!is_string($name) || !in_array($name, self::NAMES, true)) {
                return [[], 'Unknown protection: ' . (is_string($name) ? substr($name, 0, 40) : '?') . '.'];
            }
            $v = is_string($value) ? strtolower(trim($value)) : '';
            if ($v === '' || $v === 'default') continue;
            if ($v !== 'on' && $v !== 'off') return [[], "The value of $name must be default, on or off."];
            $out[$name] = $v;
        }
        return [$out, null];
    }

    /** The server keys that the guard owns. A person sets them on the Settings page only. @return list<string> */
    public static function serverKeys(): array
    {
        return array_keys(self::DEFAULTS);
    }
}
