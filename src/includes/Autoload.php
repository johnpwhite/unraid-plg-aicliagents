<?php
/**
 * <module_context>
 *     <name>Autoload</name>
 *     <description>The class loader of the plugin. It turns a class name into the file that declares it, and loads that file when the class is first used. A request compiles only the classes it uses. The server has no opcache, so every file that loads costs compile time on every request.</description>
 *     <dependencies>None. This file must stay free of other plugin files.</dependencies>
 *     <constraints>The folder is the folder of this file (__DIR__), so a request stays in the plugin generation it started in (Forgejo #367). The map follows the namespace: AICliAgents\Services\X is services/X.php, AICliAgents\Services\Hub\X is services/hub/X.php, AICliAgents\Services\Sources\X is services/sources/X.php, AICliAgents\Handlers\X is handlers/X.php. A file that declares more than one class names the extra classes in EXTRA_CLASSES. A file with a global function or code that runs at load is not loadable by class name: the Manager loads it eagerly. Spec: docs/specs/AJAX_START_UP_COST.md (Forgejo #835).</constraints>
 * </module_context>
 */

namespace AICliAgents;

final class Autoload
{
    private const ROOT_NS = 'AICliAgents\\';

    /**
     * Namespace prefix => folder (relative to this file). The longest prefix is tested first.
     * @var array<string,string>
     */
    private const NAMESPACE_DIRS = [
        'AICliAgents\\Services\\Hub\\'     => 'services/hub',
        'AICliAgents\\Services\\Sources\\' => 'services/sources',
        'AICliAgents\\Services\\'          => 'services',
        'AICliAgents\\Handlers\\'          => 'handlers',
    ];

    /**
     * A class that shares a file with another class. The file name does not match the class name.
     * Class (full name) => file (relative to this file).
     * @var array<string,string>
     */
    public const EXTRA_CLASSES = [
        'AICliAgents\\Services\\FileStorageReadiness'       => 'services/FileStorage.php',
        'AICliAgents\\Services\\FileStorageExitResultTrait' => 'services/FileStorage.php',
        'AICliAgents\\Services\\FileStoragePersistResult'   => 'services/FileStorage.php',
        'AICliAgents\\Services\\FileStorageReleaseResult'   => 'services/FileStorage.php',
        'AICliAgents\\Services\\FileStorageMigrationResult' => 'services/FileStorage.php',
        'AICliAgents\\Services\\FileStorageStatus'          => 'services/FileStorage.php',
    ];

    private static bool $registered = false;

    /** @var array<string,array<string,string>> folder => [lower-case file name => real file name] */
    private static array $dirIndex = [];

    /** @var array<string,bool> classes whose missing file was logged already */
    private static array $reported = [];

    /** The path where the file of a class should be, or null when the name is not a plugin class. Reads no file. */
    private static function expectedPath(string $class): ?string
    {
        $class = ltrim($class, '\\');
        if (strncasecmp($class, self::ROOT_NS, strlen(self::ROOT_NS)) !== 0) {
            return null;
        }
        // Only name characters. A class name never reaches the file system in any other shape.
        if (preg_match('/^[A-Za-z0-9_\\\\]+$/', $class) !== 1) {
            return null;
        }
        foreach (self::EXTRA_CLASSES as $name => $rel) {
            if (strcasecmp($name, $class) === 0) {
                return __DIR__ . '/' . $rel;
            }
        }
        foreach (self::NAMESPACE_DIRS as $prefix => $dir) {
            if (strncasecmp($class, $prefix, strlen($prefix)) !== 0) {
                continue;
            }
            $short = substr($class, strlen($prefix));
            if ($short === '' || strpos($short, '\\') !== false) {
                return null;
            }
            return __DIR__ . '/' . $dir . '/' . $short . '.php';
        }
        return null;
    }

    /**
     * The file that declares a class of the plugin, or null when the name is not a plugin class
     * or no such file exists. Never loads anything. PHP class names are not case-sensitive, so the
     * file name is matched without case when the exact name is missing (a call written as
     * `Logservice::` still loads LogService.php).
     */
    public static function resolve(string $class): ?string
    {
        $path = self::expectedPath($class);
        if ($path === null) {
            return null;
        }
        if (is_file($path)) {
            return $path;
        }
        $dir = dirname($path);
        if (!isset(self::$dirIndex[$dir])) {
            $index = [];
            foreach (@scandir($dir) ?: [] as $entry) {
                $index[strtolower($entry)] = $entry;
            }
            self::$dirIndex[$dir] = $index;
        }
        $real = self::$dirIndex[$dir][strtolower(basename($path))] ?? null;
        return $real !== null && is_file($dir . '/' . $real) ? $dir . '/' . $real : null;
    }

    /**
     * Load the file of a plugin class. Used as the autoload callback. A class of the plugin whose file
     * is missing (for example the generation folder was removed under a running process) is logged
     * once, by name and path. Nothing is thrown: PHP reports "class not found" as before.
     */
    public static function load(string $class): void
    {
        $file = self::resolve($class);
        if ($file !== null) {
            require_once $file;
            return;
        }
        $expected = self::expectedPath($class);
        if ($expected !== null && !isset(self::$reported[$class])) {
            self::$reported[$class] = true;
            self::reportMissing($class, $expected);
        }
    }

    /** One log line. LogService is used only when it is loaded already (this file must not load it: it may be the missing one). */
    private static function reportMissing(string $class, string $path): void
    {
        $message = "Autoload: class $class not found, file missing: $path";
        if (class_exists('AICliAgents\\Services\\LogService', false)) {
            \AICliAgents\Services\LogService::log($message, \AICliAgents\Services\LogService::LOG_ERROR, 'Autoload');
        } else {
            @error_log('[AICliAgents][Autoload] ' . $message);
        }
    }
    /** Register the loader once for the request. */
    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;
        spl_autoload_register([self::class, 'load']);
    }
}
