<?php
/**
 * <module_context>
 *     <name>NpmSource</name>
 *     <description>NPM-registry-backed install source. Preserves the pre-#54 behavior: npm install into AGENT_BASE/$id, read node_modules/$pkg/package.json for version, resolve channels via npm dist-tags cached by VersionCheckService. An agent that moved to a new package (`replaces_npm_packages`) has the old package removed after the install; the start file and the version come from the INSTALLED package (NpmPackageLayout).</description>
 *     <dependencies>UtilityService, LogService, VersionCheckService, AgentRegistry, NpmPackageLayout</dependencies>
 *     <constraints>Under 150 lines (package-layout helpers live in NpmPackageLayout). The only source type backed by a persistent cache service.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services\Sources;

use AICliAgents\Services\AgentRegistry;
use AICliAgents\Services\LogService;
use AICliAgents\Services\UtilityService;
use AICliAgents\Services\VersionCheckService;

class NpmSource implements AgentSource {
    private static string $lastError = '';

    /** The plain reason of the last failed fetch ('' when npm gave no known cause). */
    public function lastError(): string { return self::$lastError; }

    public function fetch(string $agentId, array $agent, ?string $targetVersion, $progress): bool {
        self::$lastError = '';
        $package = NpmPackageLayout::currentPackage($agent);
        if ($package === '') {
            LogService::log("NpmSource::fetch: agent $agentId has no npm package specified.", LogService::LOG_ERROR, "NpmSource");
            return false;
        }

        $agentDir  = AgentRegistry::agentInstallPath($agentId);
        $pluginDir = "/usr/local/emhttp/plugins/unraid-aicliagents";
        $versionSpec = $targetVersion ? "@$targetVersion" : "@latest";

        // #549: a cache folder of its own, removed after the install (not ~/.npm in RAM).
        $npmCache = UtilityService::npmCacheDir("install-$agentId");
        $cmd = "export PATH=$pluginDir/bin:\$PATH; cd " . escapeshellarg($agentDir)
             . " && npm install " . escapeshellarg($package . $versionSpec)
             . " --no-audit --no-fund --loglevel info --cache " . escapeshellarg($npmCache) . " 2>&1";

        $currentProgress = 20;
        $tail = [];
        $res = UtilityService::execStreaming($cmd, function($line, $isError) use (&$currentProgress, $progress, &$tail) {
            $tail[] = $line;
            if (count($tail) > 200) array_shift($tail);
            if (strpos($line, 'npm http fetch') !== false || strpos($line, 'npm WARN') !== false || $isError) {
                LogService::log("[NPM] $line", LogService::LOG_INFO, "NpmSource");
            } else {
                LogService::log("[NPM] $line", LogService::LOG_DEBUG, "NpmSource");
            }

            if ($currentProgress < 75) {
                $currentProgress += 0.5;
                $msg = "Installing: $line";
                if (strlen($msg) > 60) $msg = substr($msg, 0, 57) . "...";
                if ($currentProgress > 30 && $currentProgress < 35) $msg = "Fetching packages... (Est. 45s)";
                if ($currentProgress > 50 && $currentProgress < 55) $msg = "Linking dependencies... (Est. 20s)";
                if (is_callable($progress)) $progress($msg, (int)$currentProgress);
                usleep(100000);
            }
        });
        if ($res === 0) {
            // #957: remove the package that this agent used to install, so its tree (and its
            // known-bad dependencies) does not stay. A failure is logged and does not fail the
            // install: the new package is already in place.
            $uninstall = NpmPackageLayout::uninstallCommand($agent, $agentDir, $pluginDir, $npmCache);
            if ($uninstall !== null) {
                $ures = UtilityService::execStreaming($uninstall, function($line, $isError) {
                    LogService::log("[NPM-uninstall] $line", LogService::LOG_INFO, "NpmSource");
                });
                if ($ures !== 0) LogService::log("NpmSource::fetch: could not remove the replaced package of $agentId (exit=$ures)", LogService::LOG_WARN, "NpmSource");
            }
        }
        UtilityService::removeNpmCacheDir($npmCache);

        if ($res !== 0) {
            self::$lastError = NpmPackageLayout::failureReason($tail);
            LogService::log("NpmSource::fetch: npm install failed for $agentId (exit=$res)" . (self::$lastError !== '' ? ': ' . self::$lastError : ''), LogService::LOG_ERROR, "NpmSource");
            return false;
        }
        return true;
    }

    /**
     * The start file of the install, or '' when none exists (the install then fails clearly, #618).
     * The registry `binary`, then `binary_fallback`, then the `bin` of the installed package.json:
     * the package layout can differ between versions (pi-coder, #957).
     */
    public function stage(string $agentId, array $agent): string {
        foreach (['binary', 'binary_fallback'] as $key) {
            $path = (string)($agent[$key] ?? '');
            if ($path !== '' && is_file(AgentRegistry::installBinaryPath($agentId, $path))) return $path;
        }
        return NpmPackageLayout::entryPoint(AgentRegistry::agentInstallPath($agentId), $agent);
    }

    public function discoverVersion(string $agentId, array $agent): ?string {
        // The installed package decides: the current name first, then each replaced name,
        // so a kept version from before a package move still shows its version.
        return NpmPackageLayout::installedVersion(AgentRegistry::agentInstallPath($agentId), $agent);
    }

    public function checkUpdates(string $agentId, array $agent, string $channel): ?array {
        if (AgentRegistry::normalizeChannel($channel) === 'pinned') return null;
        $cache = VersionCheckService::checkAllAgents(true);
        $agentCache = $cache[$agentId] ?? null;
        if (!$agentCache || empty($agentCache['dist_tags'])) return null;

        $installed = AgentRegistry::getInstalledVersion($agentId);
        $resolution = VersionCheckService::resolveChannelTarget(
            $channel,
            AgentRegistry::getPinned($agentId),
            (array)$agentCache['dist_tags']
        );
        $channelVersion = $resolution['target'];
        if ($channelVersion === null || $resolution['error'] !== null) return null;
        if ($resolution['fallback']) {
            LogService::log(
                "NpmSource: $agentId channel {$resolution['channel']} has no primary dist-tag; using {$resolution['resolved_tag']}.",
                LogService::LOG_WARN,
                "NpmSource"
            );
        }

        $cmp = version_compare($channelVersion, $installed);
        return [
            'installed_version' => $installed,
            'latest_version'    => $channelVersion,
            'channel'           => $channel,
            'has_update'        => ($cmp > 0),
            'has_downgrade'     => ($cmp < 0),
            'version_mismatch'  => ($cmp !== 0),
        ];
    }
}
