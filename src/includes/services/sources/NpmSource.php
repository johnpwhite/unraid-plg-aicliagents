<?php
/**
 * <module_context>
 *     <name>NpmSource</name>
 *     <description>NPM-registry-backed install source. Preserves the pre-#54 behavior: npm install into AGENT_BASE/$id, read node_modules/$pkg/package.json for version, resolve channels via npm dist-tags cached by VersionCheckService.</description>
 *     <dependencies>UtilityService, LogService, VersionCheckService, AgentRegistry</dependencies>
 *     <constraints>Under 150 lines. The only source type backed by a persistent cache service.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services\Sources;

use AICliAgents\Services\AgentRegistry;
use AICliAgents\Services\LogService;
use AICliAgents\Services\UtilityService;
use AICliAgents\Services\VersionCheckService;

class NpmSource implements AgentSource {
    public function fetch(string $agentId, array $agent, ?string $targetVersion, $progress): bool {
        $package = $this->packageName($agent);
        if ($package === '') {
            LogService::log("NpmSource::fetch: agent $agentId has no npm package specified.", LogService::LOG_ERROR, "NpmSource");
            return false;
        }

        $agentDir  = AgentRegistry::AGENT_BASE . "/$agentId";
        $pluginDir = "/usr/local/emhttp/plugins/unraid-aicliagents";
        $versionSpec = $targetVersion ? "@$targetVersion" : "@latest";

        $cmd = "export PATH=$pluginDir/bin:\$PATH; cd " . escapeshellarg($agentDir)
             . " && npm install " . escapeshellarg($package . $versionSpec)
             . " --no-audit --no-fund --loglevel info 2>&1";

        $currentProgress = 20;
        $res = UtilityService::execStreaming($cmd, function($line, $isError) use (&$currentProgress, $progress) {
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

        if ($res !== 0) {
            LogService::log("NpmSource::fetch: npm install failed for $agentId (exit=$res)", LogService::LOG_ERROR, "NpmSource");
            return false;
        }
        return true;
    }

    public function stage(string $agentId, array $agent): string {
        // NPM lays files out where `binary` already points; nothing else to do.
        return $agent['binary'] ?? '';
    }

    public function discoverVersion(string $agentId, array $agent): ?string {
        $package = $this->packageName($agent);
        if ($package === '') return null;

        $pJson = AgentRegistry::AGENT_BASE . "/$agentId/node_modules/$package/package.json";
        if (file_exists($pJson)) {
            $data = json_decode(@file_get_contents($pJson), true);
            if (isset($data['version'])) return $data['version'];
        }

        // Legacy fallback: binary-relative package.json (matches old discoverVersion strategy 2)
        $bin = $agent['binary'] ?? '';
        if ($bin !== '') {
            $pJson = dirname($bin) . "/../package.json";
            if (file_exists($pJson)) {
                $data = json_decode(@file_get_contents($pJson), true);
                if (isset($data['version'])) return $data['version'];
            }
        }
        return null;
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

    private function packageName(array $agent): string {
        if (!empty($agent['source']['package'])) return (string)$agent['source']['package'];
        return (string)($agent['npm_package'] ?? '');
    }
}
