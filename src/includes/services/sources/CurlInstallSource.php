<?php
/**
 * <module_context>
 *     <name>CurlInstallSource</name>
 *     <description>Install source that runs a vendor-provided install shell script (e.g. curl install.sh piped to bash) inside a sandboxed $HOME/$PREFIX pointing at AGENT_BASE/$id. Agents like Goose or Aider historically ship this way. Version probing uses {binary} --version or a VERSION file written at install time.</description>
 *     <dependencies>GithubReleaseSource (reused for checkUpdates when repo is set), LogService, AgentRegistry</dependencies>
 *     <constraints>Script must respect $HOME/$PREFIX; audit step verifies the expected binary exists in bin/ or aborts the install.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services\Sources;

use AICliAgents\Services\AgentRegistry;
use AICliAgents\Services\LogService;

class CurlInstallSource implements AgentSource {
    public function fetch(string $agentId, array $agent, ?string $targetVersion, $progress): bool {
        $src = $agent['source'] ?? [];
        $scriptUrl = (string)($src['script_url'] ?? '');
        if ($scriptUrl === '') {
            LogService::log("CurlInstallSource: missing script_url for $agentId", LogService::LOG_ERROR, "CurlInstallSource");
            return false;
        }

        $agentDir = AgentRegistry::AGENT_BASE . "/$agentId";
        @mkdir("$agentDir/home", 0755, true);
        @mkdir("$agentDir/bin", 0755, true);

        if (is_callable($progress)) $progress("Fetching install script…", 25);
        $scriptPath = "/tmp/unraid-aicliagents/dl/$agentId-install.sh";
        @mkdir(dirname($scriptPath), 0755, true);
        $dl = 'curl -fsSL -m 60 -o ' . escapeshellarg($scriptPath) . ' ' . escapeshellarg($scriptUrl) . ' 2>&1';
        @shell_exec($dl);
        if (!file_exists($scriptPath) || filesize($scriptPath) === 0) {
            LogService::log("CurlInstallSource: failed to download install script for $agentId from $scriptUrl", LogService::LOG_ERROR, "CurlInstallSource");
            return false;
        }

        if (is_callable($progress)) $progress("Running install script (captive HOME/PREFIX)…", 45);
        $envPairs = [
            'HOME='   . escapeshellarg("$agentDir/home"),
            'PREFIX=' . escapeshellarg($agentDir),
            'PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
        ];
        if (!empty($src['env']) && is_array($src['env'])) {
            foreach ($src['env'] as $k => $v) $envPairs[] = escapeshellarg("$k=$v");
        }
        $run = implode(' ', $envPairs) . ' bash ' . escapeshellarg($scriptPath) . ' 2>&1; echo __RC=$?';
        $out = @shell_exec($run) ?: '';
        @unlink($scriptPath);
        if (!preg_match('/__RC=(\d+)/', $out, $m) || (int)$m[1] !== 0) {
            LogService::log("CurlInstallSource: install script failed for $agentId:\n" . $out, LogService::LOG_ERROR, "CurlInstallSource");
            return false;
        }

        if (preg_match('/(\d+\.\d+\.\d+(?:[-+][\w.]+)?)/', $out, $m)) {
            @file_put_contents("$agentDir/VERSION", $m[1]);
        }
        return true;
    }

    public function stage(string $agentId, array $agent): string {
        $src = $agent['source'] ?? [];
        $executable = (string)($src['executable'] ?? '');
        if ($executable === '') return $agent['binary'] ?? '';
        $agentDir = AgentRegistry::AGENT_BASE . "/$agentId";
        $expected = "$agentDir/bin/$executable";
        if (file_exists($expected)) {
            @chmod($expected, 0755);
            return $expected;
        }
        foreach (glob("$agentDir/**/$executable") ?: [] as $cand) {
            if (is_file($cand) && is_executable($cand)) {
                @copy($cand, $expected);
                @chmod($expected, 0755);
                return $expected;
            }
        }
        LogService::log("CurlInstallSource::stage: expected binary '$executable' not found under $agentDir after install.", LogService::LOG_ERROR, "CurlInstallSource");
        return '';
    }

    public function discoverVersion(string $agentId, array $agent): ?string {
        $agentDir = AgentRegistry::AGENT_BASE . "/$agentId";
        $bin = $agent['binary'] ?? '';
        $probe = $agent['source']['version_probe'] ?? '{binary} --version';
        if ($bin !== '' && file_exists($bin)) {
            $cmd = str_replace('{binary}', escapeshellarg($bin), $probe) . ' 2>&1';
            $out = @shell_exec($cmd) ?: '';
            if (preg_match('/(\d+\.\d+\.\d+(?:[-+][\w.]+)?)/', $out, $m)) return $m[1];
        }
        if (file_exists("$agentDir/VERSION")) {
            $v = trim((string)@file_get_contents("$agentDir/VERSION"));
            if ($v !== '') return $v;
        }
        return null;
    }

    public function checkUpdates(string $agentId, array $agent, string $channel): ?array {
        $repo = (string)($agent['source']['repo'] ?? '');
        if ($repo !== '') return (new GithubReleaseSource())->checkUpdates($agentId, $agent, $channel);
        return null;
    }
}
