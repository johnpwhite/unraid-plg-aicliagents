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
    /**
     * Recursively delete a directory tree. PHP-native (no shell) — the paths
     * are plugin-owned but a shell `rm -rf` is an unnecessary injection
     * surface for a pure filesystem operation.
     */
    private static function rrmdir(string $dir): void {
        if (!is_dir($dir)) return;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            if ($f->isDir()) {
                @rmdir($f->getPathname());
            } else {
                @unlink($f->getPathname());
            }
        }
        @rmdir($dir);
    }

    public function fetch(string $agentId, array $agent, ?string $targetVersion, $progress): bool {
        $src = $agent['source'] ?? [];
        $scriptUrl = (string)($src['script_url'] ?? '');
        if ($scriptUrl === '') {
            LogService::log("CurlInstallSource: missing script_url for $agentId", LogService::LOG_ERROR, "CurlInstallSource");
            return false;
        }

        $agentDir = AgentRegistry::AGENT_BASE . "/$agentId";

        // WP #963: clean-reinstall. Wipe the prior captive bin/ and home/
        // before re-running the vendor script. Vendor installers are commonly
        // idempotent — they short-circuit ("already installed", exit 0) when
        // their target binary is present — so a plugin-driven *upgrade* would
        // otherwise no-op. The captive home/ is install-script scratch space
        // ($HOME/.bashrc, $HOME/.cache staging), never user data; user data
        // lives in the separate workspace overlay. Wiping it on every fetch
        // is the correct semantic for an install-or-upgrade re-run.
        self::rrmdir("$agentDir/bin");
        self::rrmdir("$agentDir/home");
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
        // WP #963: timeout guard. A hung vendor handoff (e.g. an interactive
        // `agy install` shell-config step) must not stall the install. `timeout`
        // exit 124 propagates through the __RC capture and fails the fetch.
        $run = implode(' ', $envPairs) . ' timeout 300 bash ' . escapeshellarg($scriptPath) . ' 2>&1; echo __RC=$?';
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
        $src = $agent['source'] ?? [];
        $repo = (string)($src['repo'] ?? '');
        if ($repo !== '') return (new GithubReleaseSource())->checkUpdates($agentId, $agent, $channel);

        // WP #963: manifest-based latest-version probe. Vendors that distribute
        // via a self-updater (e.g. Antigravity) publish a single-version
        // manifest — {version,url,sha512} for "latest" — rather than a release
        // history. Probe it so the agent gets an update badge.
        $latest = self::probeManifestVersion($src);
        if ($latest === null) return null;
        $installed = AgentRegistry::getInstalledVersion($agentId);
        $cmp = version_compare($latest, $installed);
        return [
            'installed_version' => $installed,
            'latest_version'    => $latest,
            'channel'           => $channel,
            'has_update'        => ($cmp > 0),
            'has_downgrade'     => ($cmp < 0),
            'version_mismatch'  => ($cmp !== 0),
        ];
    }

    /**
     * WP #963: optional version-cache populator (same hook GithubReleaseSource
     * uses — VersionCheckService calls it when method_exists). For a manifest
     * source there is exactly one installable version (the vendor publishes no
     * archive), so the cache holds a single 'latest'-tagged entry. The 'latest'
     * tag makes getAvailableVersions include it unconditionally (no date cutoff).
     * Returns an empty cache when no manifest_url is configured.
     */
    public function populateCache(string $agentId, array $agent): array {
        $latest = self::probeManifestVersion($agent['source'] ?? []);
        if ($latest === null) return ['dist_tags' => [], 'versions' => []];
        return [
            'dist_tags' => ['latest' => $latest],
            'versions'  => [[
                'version'   => $latest,
                // The manifest carries no release date; "now" is fine — the
                // 'latest' tag bypasses the getAvailableVersions date filter.
                'timestamp' => time(),
                'date'      => date('Y-m-d'),
                'tags'      => ['latest'],
            ]],
        ];
    }

    /**
     * Fetch source.manifest_url and extract the version string. The version key
     * defaults to 'version' (override with source.manifest_version_key). Returns
     * null on any failure (no URL, network error, malformed JSON, non-semver).
     */
    private static function probeManifestVersion(array $src): ?string {
        $url = (string)($src['manifest_url'] ?? '');
        if ($url === '') return null;
        $json = @shell_exec('curl -fsSL -m 20 ' . escapeshellarg($url) . ' 2>/dev/null');
        if (!is_string($json) || $json === '') return null;
        $data = json_decode($json, true);
        if (!is_array($data)) return null;
        $key = (string)($src['manifest_version_key'] ?? 'version');
        $v = $data[$key] ?? '';
        return (is_string($v) && preg_match('/^\d+\.\d+\.\d+/', $v)) ? $v : null;
    }
}
