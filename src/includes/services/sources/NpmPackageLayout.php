<?php
/**
 * <module_context>
 *     <name>NpmPackageLayout</name>
 *     <description>Pure helpers for an npm-installed agent whose package can change name or layout between versions (pi-coder, #957): the package names to look for, the start file named by the installed package.json `bin`, the installed version under any of those names, the commands that remove a replaced package, and a plain reason for a failed npm install.</description>
 *     <dependencies>none (reads files only through the paths it is given)</dependencies>
 *     <constraints>No registry or storage access: callers pass the install folder. A feature follows the INSTALLED version, so every lookup tries the current package name first, then each name in `replaces_npm_packages`.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services\Sources;

final class NpmPackageLayout {
    private const NAME_RE = '#^(@[a-z0-9][a-z0-9._-]*/)?[a-z0-9][a-z0-9._-]*$#D';

    /** The package the registry installs now. */
    public static function currentPackage(array $agent): string {
        if (!empty($agent['source']['package'])) return (string)$agent['source']['package'];
        return (string)($agent['npm_package'] ?? '');
    }

    /** Valid names in `replaces_npm_packages`, without the current package. @return string[] */
    public static function replacedPackages(array $agent): array {
        $current = self::currentPackage($agent);
        $out = [];
        foreach ((array)($agent['replaces_npm_packages'] ?? []) as $p) {
            $p = (string)$p;
            if ($p !== $current && preg_match(self::NAME_RE, $p)) $out[] = $p;
        }
        return $out;
    }

    /** Current package first, then the replaced ones. @return string[] */
    public static function packageNames(array $agent): array {
        $cur = self::currentPackage($agent);
        return array_values(array_filter(array_merge([$cur], self::replacedPackages($agent)), static fn($p) => $p !== ''));
    }

    /** The shell command that removes the replaced packages, or null when there are none. */
    public static function uninstallCommand(array $agent, string $agentDir, string $pluginDir, string $npmCache): ?string {
        $old = self::replacedPackages($agent);
        if ($old === []) return null;
        return "export PATH=$pluginDir/bin:\$PATH; cd " . escapeshellarg($agentDir)
             . ' && npm uninstall ' . implode(' ', array_map('escapeshellarg', $old))
             . ' --no-audit --no-fund --loglevel info --cache ' . escapeshellarg($npmCache) . ' 2>&1';
    }

    /** package.json of one installed package as an array, or null. */
    private static function manifest(string $installDir, string $package): ?array {
        $json = @file_get_contents("$installDir/node_modules/$package/package.json");
        $d = is_string($json) ? json_decode($json, true) : null;
        return is_array($d) ? $d : null;
    }

    /** The installed version, from the first package name that has a package.json. */
    public static function installedVersion(string $installDir, array $agent): ?string {
        foreach (self::packageNames($agent) as $pkg) {
            $v = self::manifest($installDir, $pkg)['version'] ?? null;
            if (is_string($v) && $v !== '') return $v;
        }
        return null;
    }

    /**
     * The start file named by the `bin` field of the installed package.json (string, or a map:
     * the entry for the command name, else the first one). '' when no such file exists.
     * Only a path inside the package folder is accepted.
     */
    public static function entryPoint(string $installDir, array $agent): string {
        foreach (self::packageNames($agent) as $pkg) {
            $m = self::manifest($installDir, $pkg);
            $bin = $m['bin'] ?? null;
            if (is_array($bin)) $bin = reset($bin) ?: null;
            if (!is_string($bin) || $bin === '' || strpos($bin, '..') !== false) continue;
            $file = "$installDir/node_modules/$pkg/" . ltrim($bin, './');
            if (is_file($file)) return $file;
        }
        return '';
    }

    /**
     * A plain reason for a failed `npm install`, from its output lines, or '' when the output
     * shows no known cause. The tray shows this instead of "Check logs for details".
     * @param string[] $lines
     */
    public static function failureReason(array $lines): string {
        $text = implode("\n", $lines);
        if (preg_match('/No matching version found for ([^\s]+)/', $text, $m)) {
            return "npm has no version {$m[1]}. Choose a version that the package publishes.";
        }
        if (preg_match('/\b(ETARGET|notarget)\b/', $text)) return 'npm has no such version of the package.';
        if (preg_match('/\bE404\b|404 Not Found/', $text)) return 'The package was not found on the npm registry.';
        if (preg_match('/\b(ENOSPC)\b/', $text)) return 'There is no space left on the disk for the install.';
        if (preg_match('/\b(ENOTFOUND|EAI_AGAIN|ECONNREFUSED|ETIMEDOUT|ECONNRESET)\b/', $text)) {
            return 'The npm registry could not be reached. Check the network and try again.';
        }
        return '';
    }
}
