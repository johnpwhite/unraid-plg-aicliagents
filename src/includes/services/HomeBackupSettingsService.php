<?php
/**
 * <module_context>
 *     <name>HomeBackupSettingsService</name>
 *     <description>HOME_BACKUP.md "2026-09-24 redesign (#287)": each home's OWN
 *       backup settings (target, quiesce, keep, schedule, excludes,
 *       nudge_working), one JSON file per home under
 *       /boot/config/plugins/unraid-aicliagents/backup-settings/. A home with no
 *       file inherits the old plugin-wide backup_* cfg values once, then keeps
 *       its own. Never writes the plugin cfg file (the global storage policy).</description>
 *     <dependencies>ConfigService, AtomicWriteService, StorageTargetService</dependencies>
 *     <constraints>A per-home write touches only that home's file. Every write is
 *       validated one key at a time and read back from disk before it is
 *       reported as saved.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class HomeBackupSettingsService {
    /** Flash, next to backup-last-<user>.json. AICLI_BACKUP_SETTINGS_DIR overrides it for tests. */
    public const DEFAULT_DIR = '/boot/config/plugins/unraid-aicliagents/backup-settings';

    /** The six per-home keys, as the UI and the AJAX action name them. */
    public const KEYS = ['target', 'quiesce', 'keep', 'schedule', 'excludes', 'nudge_working'];

    /** Old plugin-wide cfg key for each per-home key (the migration seed). */
    private const GLOBAL_KEY = [
        'target'        => 'backup_target',
        'quiesce'       => 'backup_quiesce',
        'keep'          => 'backup_keep',
        'schedule'      => 'backup_schedule',
        'excludes'      => 'backup_excludes',
        'nudge_working' => 'backup_nudge_working',
    ];

    public const DEFAULT_EXCLUDES = ".claude/plugins/**\n**/node_modules/**\n.grok/marketplace-cache/**\n.gemini/antigravity-cli/**\n.cache/**\n.claude/image-cache/**\n**/*.tmp";

    /** Test seam: a callable($path) => resolveBackupTarget()-shaped array. */
    private static $targetResolver = null;

    /** Test seam: a callable($user) => bool, "does this home exist on the box". */
    private static $homeExists = null;

    public static function dir(): string {
        $env = getenv('AICLI_BACKUP_SETTINGS_DIR');
        return ($env !== false && $env !== '') ? rtrim($env, '/') : self::DEFAULT_DIR;
    }

    public static function validUser(string $user): bool {
        return $user !== '' && (bool)preg_match('/^[A-Za-z0-9._-]+$/', $user) && $user !== '.' && $user !== '..';
    }

    public static function pathFor(string $user): string {
        return self::dir() . '/' . $user . '.json';
    }

    /** For tests only: replace the home-exists probe (null restores the real one). */
    public static function setHomeExistsForTests(?callable $probe): void {
        self::$homeExists = $probe;
    }

    /**
     * HOME_BACKUP.md "2026-09-24 follow-up": is this a home the box really
     * has? True when its mount folder exists, it has baked layers on the
     * persist path, it is the configured terminal user, or it already has a
     * settings file. A name that is none of these (a typo in a request, a
     * test home that was deleted) never gets a settings file on the flash.
     */
    public static function homeExists(string $user): bool {
        if (!self::validUser($user)) return false;
        if (self::$homeExists !== null) return (bool)call_user_func(self::$homeExists, $user);
        if (is_file(self::pathFor($user))) return true;
        try {
            $config = \function_exists('getAICliConfig') ? getAICliConfig() : ConfigService::getConfig();
            $configured = (string)($config['user'] ?? 'root');
            if ($configured === '0' || $configured === '') $configured = 'root';
            if ($configured === $user) return true;
            require_once __DIR__ . '/StoragePathResolver.php';
            if (is_dir(StoragePathResolver::homeMount($user))) return true;
            $layers = @glob(StoragePathResolver::homePersistPath($user) . '/home_' . $user . '_*.sqsh');
            return is_array($layers) && $layers !== [];
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** For tests only: replace the target resolver (null restores the real one). */
    public static function setTargetResolverForTests(?callable $resolver): void {
        self::$targetResolver = $resolver;
    }

    /**
     * The home's settings. When the home has no file yet, the old global
     * backup_* values are adopted ONCE and written to the home's own file
     * (inherited_from_global: true). Never writes the plugin cfg.
     *
     * @return array{user:string,target:string,quiesce:string,keep:int,schedule:string,excludes:string,nudge_working:bool,inherited_from_global:bool,updated_at:?string}
     */
    public static function get(string $user): array {
        if (!self::validUser($user)) {
            throw new \InvalidArgumentException('invalid_user');
        }
        $stored = self::readFile($user);
        if ($stored !== null) {
            return self::normalise($user, $stored);
        }
        // Migration: seed from the global cfg keys, once. Only a home the
        // box really has gets the file: an unknown name (a deleted smoke-test
        // home, a typo) is answered with the seed and nothing is written.
        $seed = self::globalSeed();
        $seed['inherited_from_global'] = true;
        $settings = self::normalise($user, $seed);
        if (self::homeExists($user)) {
            self::writeFile($user, $settings); // best-effort; a failed write re-seeds next time
        }
        return $settings;
    }

    /**
     * Like get(), but never writes: a home with no file gets the values it
     * WOULD inherit. For read-only sweeps (the Health check) that also see
     * users who have no home.
     */
    public static function peek(string $user): array {
        if (!self::validUser($user)) {
            throw new \InvalidArgumentException('invalid_user');
        }
        $stored = self::readFile($user);
        if ($stored !== null) return self::normalise($user, $stored);
        $seed = self::globalSeed();
        $seed['inherited_from_global'] = true;
        return self::normalise($user, $seed);
    }

    /** True when the home already has its own settings file. */
    public static function hasOwnSettings(string $user): bool {
        return self::validUser($user) && is_file(self::pathFor($user));
    }

    /**
     * Validate ONE key, write it to the home's file, and read the file back.
     *
     * @return array{status:string,key:string,value?:mixed,settings?:array,message?:string}
     */
    public static function set(string $user, string $key, $value): array {
        if (!self::validUser($user)) {
            return ['status' => 'error', 'key' => $key, 'message' => 'invalid_user'];
        }
        if (!in_array($key, self::KEYS, true)) {
            return ['status' => 'error', 'key' => $key, 'message' => "Unknown backup setting '$key'."];
        }
        if (!self::homeExists($user)) {
            return ['status' => 'error', 'key' => $key, 'message' => "There is no home named '$user' on this server. Nothing was saved."];
        }
        $checked = self::validate($key, $value);
        if (!$checked['ok']) {
            return ['status' => 'error', 'key' => $key, 'message' => $checked['message']];
        }
        $current = self::get($user);
        $current[$key] = $checked['value'];
        $current['inherited_from_global'] = false;
        $current['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
        if (!self::writeFile($user, $current)) {
            return ['status' => 'error', 'key' => $key, 'message' => 'The setting could not be written to the flash drive. Nothing was changed.'];
        }
        // Read back: report what is really on disk, never what was asked for.
        $onDisk = self::readFile($user);
        if ($onDisk === null) {
            return ['status' => 'error', 'key' => $key, 'message' => 'The setting was written but could not be read back.'];
        }
        $settings = self::normalise($user, $onDisk);
        if ($settings[$key] !== $checked['value']) {
            return ['status' => 'error', 'key' => $key, 'message' => 'The saved value does not match what was sent.', 'settings' => $settings];
        }
        $out = ['status' => 'ok', 'key' => $key, 'value' => $settings[$key], 'settings' => $settings];
        if ($key === 'target' && isset($checked['check'])) {
            $out['check'] = $checked['check'];
        }
        return $out;
    }

    /**
     * One key's validation. Same rules as AdminService::SETTINGS_ALLOWLIST's
     * backup_* entries; the target also passes resolveBackupTarget() and is
     * stored RESOLVED (a /mnt/user share becomes its pool/disk path).
     *
     * @return array{ok:bool,value?:mixed,message?:string,check?:array}
     */
    public static function validate(string $key, $value): array {
        $raw = is_bool($value) ? ($value ? '1' : '0') : trim((string)$value);
        switch ($key) {
            case 'target':
                if ($raw === '') return ['ok' => true, 'value' => ''];
                if (!preg_match('#^/\S+$#', $raw)) {
                    return ['ok' => false, 'message' => 'The backup folder must be an absolute path with no spaces.'];
                }
                $check = self::resolveTarget($raw);
                if (empty($check['ok'])) {
                    return ['ok' => false, 'message' => (string)($check['message'] ?? 'This folder cannot be used for backups.')];
                }
                $resolved = rtrim((string)($check['resolved'] ?? $raw), '/');
                return ['ok' => true, 'value' => $resolved !== '' ? $resolved : $raw, 'check' => $check];
            case 'quiesce':
                return in_array($raw, ['cold', 'warm'], true)
                    ? ['ok' => true, 'value' => $raw]
                    : ['ok' => false, 'message' => "Mode must be 'cold' or 'warm'."];
            case 'keep':
                if (!preg_match('/^\d+$/', $raw) || (int)$raw < 1 || (int)$raw > 50) {
                    return ['ok' => false, 'message' => 'Keep must be a whole number from 1 to 50.'];
                }
                return ['ok' => true, 'value' => (int)$raw];
            case 'schedule':
                return preg_match('#^(off|daily:([01]\d|2[0-3]):([0-5]\d)|weekly:[0-6]:([01]\d|2[0-3]):([0-5]\d))$#', $raw)
                    ? ['ok' => true, 'value' => $raw]
                    : ['ok' => false, 'message' => "Schedule must be 'off', 'daily:HH:MM' or 'weekly:D:HH:MM'."];
            case 'excludes':
                $norm = str_replace(["\r\n", "\r"], "\n", (string)$value);
                if (!preg_match("#^[A-Za-z0-9_./*\n -]*$#", $norm)) {
                    return ['ok' => false, 'message' => 'Excluded patterns may use only letters, digits, . / * - _ and spaces, one per line.'];
                }
                $lines = array_values(array_filter(array_map('trim', explode("\n", $norm)), static fn($l) => $l !== ''));
                return ['ok' => true, 'value' => implode("\n", $lines)];
            case 'nudge_working':
                if (in_array($raw, ['1', 'true', 'on'], true))  return ['ok' => true, 'value' => true];
                if (in_array($raw, ['0', 'false', 'off', ''], true)) return ['ok' => true, 'value' => false];
                return ['ok' => false, 'message' => 'Continue on relaunch must be on or off.'];
        }
        return ['ok' => false, 'message' => "Unknown backup setting '$key'."];
    }

    /** A home's excludes as a clean list (what the job's options sidecar wants). */
    public static function excludesList(array $settings): array {
        $lines = preg_split('/\r\n|\r|\n/', (string)($settings['excludes'] ?? '')) ?: [];
        return array_values(array_filter(array_map('trim', $lines), static fn($l) => $l !== ''));
    }

    // ------------------------------------------------------------------

    private static function resolveTarget(string $path): array {
        if (self::$targetResolver !== null) {
            return (array)call_user_func(self::$targetResolver, $path);
        }
        require_once __DIR__ . '/StorageTargetService.php';
        return StorageTargetService::resolveBackupTarget($path);
    }

    /** The old global backup_* cfg values, mapped to per-home keys. */
    private static function globalSeed(): array {
        $config = \function_exists('getAICliConfig') ? getAICliConfig() : ConfigService::getConfig();
        $seed = [];
        foreach (self::GLOBAL_KEY as $k => $g) {
            if (array_key_exists($g, $config)) $seed[$k] = $config[$g];
        }
        return $seed;
    }

    /** Coerce any stored/seeded array into the full, valid shape (bad values fall back to defaults). */
    private static function normalise(string $user, array $in): array {
        $out = [
            'version'               => 1,
            'user'                  => $user,
            'target'                => '',
            'quiesce'               => 'cold',
            'keep'                  => 5,
            'schedule'              => 'off',
            'excludes'              => self::DEFAULT_EXCLUDES,
            'nudge_working'         => true,
            'inherited_from_global' => (bool)($in['inherited_from_global'] ?? false),
            'updated_at'            => isset($in['updated_at']) ? (string)$in['updated_at'] : null,
        ];
        // The target is taken as stored (it was validated when saved, and a
        // seed from the old global key was validated by aicli_set_setting); a
        // run-time pre-flight in backupHome() re-checks it anyway.
        $t = trim((string)($in['target'] ?? ''));
        if ($t === '' || preg_match('#^/\S+$#', $t)) $out['target'] = rtrim($t, '/') === '' ? $t : rtrim($t, '/');
        foreach (['quiesce', 'keep', 'schedule', 'excludes', 'nudge_working'] as $k) {
            if (!array_key_exists($k, $in)) continue;
            $v = self::validate($k, $in[$k]);
            if ($v['ok']) $out[$k] = $v['value'];
        }
        return $out;
    }

    private static function readFile(string $user): ?array {
        $path = self::pathFor($user);
        if (!is_file($path)) return null;
        $data = json_decode((string)@file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }

    private static function writeFile(string $user, array $settings): bool {
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return false;
        }
        $json = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) return false;
        if (class_exists(AtomicWriteService::class)) {
            return AtomicWriteService::write(self::pathFor($user), $json . "\n");
        }
        return @file_put_contents(self::pathFor($user), $json . "\n") !== false;
    }
}
