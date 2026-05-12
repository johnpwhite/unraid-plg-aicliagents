<?php
/**
 * <module_context>
 *     <name>TarballSource</name>
 *     <description>Install source for agents distributed as a versioned URL template (e.g. https://example.com/foo-{version}-linux.tar.gz) outside GitHub Releases. Version resolution requires either a pinned target_version or a source.version_index_url returning plain text.</description>
 *     <dependencies>GithubReleaseSource (reused for archive extraction behavior), LogService, AgentRegistry</dependencies>
 *     <constraints>Beta channel is unsupported — logs WARN and falls back to latest.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services\Sources;

use AICliAgents\Services\AgentRegistry;
use AICliAgents\Services\LogService;

class TarballSource implements AgentSource {
    const DL_BASE = '/tmp/unraid-aicliagents/dl';

    public function fetch(string $agentId, array $agent, ?string $targetVersion, $progress): bool {
        $src = $agent['source'] ?? [];
        $urlTpl = (string)($src['url'] ?? '');
        if ($urlTpl === '') {
            LogService::log("TarballSource: missing url template for $agentId", LogService::LOG_ERROR, "TarballSource");
            return false;
        }

        $version = $targetVersion && $targetVersion !== 'latest' ? $targetVersion : $this->resolveLatest($src);
        if (!$version) {
            LogService::log("TarballSource: could not resolve a version for $agentId (no version_index_url, no pinned target)", LogService::LOG_ERROR, "TarballSource");
            return false;
        }

        $url = str_replace(['{version}', '{arch}'], [$version, $this->arch()], $urlTpl);
        $agentDir = AgentRegistry::AGENT_BASE . "/$agentId";
        @mkdir("$agentDir/pkg", 0755, true);

        if (is_callable($progress)) $progress("Downloading {$version}...", 35);
        $dlDir = self::DL_BASE . "/$agentId";
        @mkdir($dlDir, 0755, true);
        $dlPath = $dlDir . '/' . basename(parse_url($url, PHP_URL_PATH) ?: 'tarball.bin');
        $dl = 'curl -fL -m 300 -o ' . escapeshellarg($dlPath) . ' ' . escapeshellarg($url) . ' 2>&1';
        @shell_exec($dl);
        if (!file_exists($dlPath) || filesize($dlPath) === 0) {
            LogService::log("TarballSource: download failed for $agentId from $url", LogService::LOG_ERROR, "TarballSource");
            return false;
        }

        if (is_callable($progress)) $progress("Extracting…", 55);
        if (!self::extractArchive($dlPath, "$agentDir/pkg")) return false;
        @unlink($dlPath);

        @file_put_contents("$agentDir/VERSION", $version);
        return true;
    }

    public function stage(string $agentId, array $agent): string {
        return (new GithubReleaseSource())->stage($agentId, $agent);
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
        if ($channel === 'beta') {
            LogService::log("TarballSource: beta channel is unsupported for $agentId; falling back to latest.", LogService::LOG_WARN, "TarballSource");
        }
        $src = $agent['source'] ?? [];
        $latest = $this->resolveLatest($src);
        if (!$latest) return null;

        $installed = AgentRegistry::getInstalledVersion($agentId);
        $cmp = version_compare($latest, $installed);
        return [
            'installed_version' => $installed,
            'latest_version'    => $latest,
            'channel'           => 'latest',
            'has_update'        => ($cmp > 0),
            'has_downgrade'     => ($cmp < 0),
            'version_mismatch'  => ($cmp !== 0),
        ];
    }

    private function resolveLatest(array $src): ?string {
        $indexUrl = (string)($src['version_index_url'] ?? '');
        if ($indexUrl === '') return null;
        $cmd = 'curl -fsSL -m 15 ' . escapeshellarg($indexUrl) . ' 2>/dev/null';
        $body = @shell_exec($cmd);
        if (empty($body)) return null;
        $body = trim((string)$body);
        if (preg_match('/^(\d+\.\d+\.\d+(?:[-+][\w.]+)?)$/', $body, $m)) return $m[1];
        $data = json_decode($body, true);
        if (is_array($data) && !empty($data['latest'])) return (string)$data['latest'];
        return null;
    }

    private function arch(): string {
        $m = trim((string)@shell_exec('uname -m 2>/dev/null'));
        return $m ?: 'x86_64';
    }

    private static function extractArchive(string $archive, string $destDir): bool {
        $lower = strtolower($archive);
        $cmd = null;
        if (preg_match('/\.tar\.gz$|\.tgz$/', $lower))       $cmd = 'tar -xzf ' . escapeshellarg($archive) . ' -C ' . escapeshellarg($destDir);
        elseif (preg_match('/\.tar\.bz2$|\.tbz2$/', $lower)) $cmd = 'tar -xjf ' . escapeshellarg($archive) . ' -C ' . escapeshellarg($destDir);
        elseif (preg_match('/\.tar\.xz$|\.txz$/', $lower))   $cmd = 'tar -xJf ' . escapeshellarg($archive) . ' -C ' . escapeshellarg($destDir);
        elseif (preg_match('/\.zip$/', $lower))              $cmd = 'unzip -q ' . escapeshellarg($archive) . ' -d ' . escapeshellarg($destDir);
        if ($cmd === null) {
            return @copy($archive, $destDir . '/' . basename($archive));
        }
        $out = @shell_exec($cmd . ' 2>&1; echo __RC=$?');
        if (!preg_match('/__RC=(\d+)/', (string)$out, $m) || (int)$m[1] !== 0) {
            LogService::log("TarballSource::extract failed: " . trim((string)$out), LogService::LOG_ERROR, "TarballSource");
            return false;
        }
        return true;
    }
}
