<?php
/**
 * <module_context>
 *     <name>GithubReleaseSource</name>
 *     <description>Install source backed by GitHub Releases. Downloads an asset matching source.asset_pattern (with {arch}/{version} placeholders), unpacks tar.gz/tar.bz2/zip/raw, and lifts source.binary_in_archive to AGENT_BASE/$id/bin/$executable.</description>
 *     <dependencies>UtilityService, LogService, AgentRegistry</dependencies>
 *     <constraints>No external PHP libs; shells out to curl + tar/unzip. GitHub API is unauthenticated (60 req/hr per IP) which is fine for a single-home install.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services\Sources;

use AICliAgents\Services\AgentRegistry;
use AICliAgents\Services\LogService;

class GithubReleaseSource implements AgentSource {
    const DL_BASE = '/tmp/unraid-aicliagents/dl';
    const API_BASE = 'https://api.github.com';

    public function fetch(string $agentId, array $agent, ?string $targetVersion, $progress): bool {
        $src = $agent['source'] ?? [];
        $repo = (string)($src['repo'] ?? '');
        $pattern = (string)($src['asset_pattern'] ?? '');
        if ($repo === '' || $pattern === '') {
            LogService::log("GithubReleaseSource: missing repo or asset_pattern for $agentId", LogService::LOG_ERROR, "GithubReleaseSource");
            return false;
        }

        if (is_callable($progress)) $progress("Resolving release…", 22);
        $release = $this->resolveRelease($repo, $targetVersion);
        if (!$release) {
            LogService::log("GithubReleaseSource: no release found for $repo (target=$targetVersion)", LogService::LOG_ERROR, "GithubReleaseSource");
            return false;
        }

        $version = ltrim((string)($release['tag_name'] ?? ''), 'v');
        $resolvedPattern = $this->interpolate($pattern, ['arch' => $this->arch(), 'version' => $version]);
        $asset = $this->matchAsset($release['assets'] ?? [], $resolvedPattern);
        if (!$asset) {
            $names = array_map(fn($a) => $a['name'] ?? '', $release['assets'] ?? []);
            LogService::log("GithubReleaseSource: no asset matching '$resolvedPattern' in $repo@" . ($release['tag_name'] ?? '?') . ". Present: " . implode(', ', $names), LogService::LOG_ERROR, "GithubReleaseSource");
            return false;
        }

        $dlDir = self::DL_BASE . "/$agentId";
        if (!is_dir($dlDir)) @mkdir($dlDir, 0755, true);
        $dlPath = $dlDir . '/' . basename($asset['name']);

        if (is_callable($progress)) $progress("Downloading " . $asset['name'] . "…", 35);
        if (!$this->download($asset['browser_download_url'], $dlPath)) return false;

        if (is_callable($progress)) $progress("Extracting…", 55);
        $agentDir = AgentRegistry::AGENT_BASE . "/$agentId";
        $pkgDir = "$agentDir/pkg";
        @mkdir($pkgDir, 0755, true);
        if (!$this->extractArchive($dlPath, $pkgDir)) return false;
        @unlink($dlPath);

        @file_put_contents("$agentDir/VERSION", $version);
        return true;
    }

    public function stage(string $agentId, array $agent): string {
        $src = $agent['source'] ?? [];
        $inArchive = (string)($src['binary_in_archive'] ?? '');
        $executable = (string)($src['executable'] ?? basename($inArchive));
        if ($inArchive === '' || $executable === '') {
            LogService::log("GithubReleaseSource::stage: missing binary_in_archive/executable for $agentId", LogService::LOG_ERROR, "GithubReleaseSource");
            return '';
        }
        $agentDir = AgentRegistry::AGENT_BASE . "/$agentId";
        $pkgBin = "$agentDir/pkg/$inArchive";
        if (!file_exists($pkgBin)) {
            $glob = glob("$agentDir/pkg/*/$inArchive") ?: [];
            if (count($glob) > 0) $pkgBin = $glob[0];
        }
        if (!file_exists($pkgBin)) {
            LogService::log("GithubReleaseSource::stage: binary_in_archive '$inArchive' not found under $agentDir/pkg", LogService::LOG_ERROR, "GithubReleaseSource");
            return '';
        }

        @mkdir("$agentDir/bin", 0755, true);
        $binPath = "$agentDir/bin/$executable";
        if (!@copy($pkgBin, $binPath)) {
            LogService::log("GithubReleaseSource::stage: copy failed: $pkgBin -> $binPath", LogService::LOG_ERROR, "GithubReleaseSource");
            return '';
        }
        @chmod($binPath, 0755);
        return $binPath;
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
        if ($repo === '') return null;

        if ($channel === 'beta') {
            $pre = $this->resolvePrerelease($repo);
            $release = $pre ?: $this->resolveLatestStable($repo);
            if ($pre === null) LogService::log("GithubReleaseSource: no prerelease in $repo; falling back to latest stable.", LogService::LOG_INFO, "GithubReleaseSource");
        } else {
            $release = $this->resolveLatestStable($repo);
        }
        if (!$release) return null;

        $latest = ltrim((string)($release['tag_name'] ?? ''), 'v');
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
     * Build a version-cache entry compatible with the npm-shaped cache.
     *
     * Returns ['dist_tags' => ['latest' => v, 'prerelease' => v?], 'versions' => [...]].
     * Each version entry has shape {version, timestamp, tags, date}. Versions are
     * sorted ASCENDING by timestamp to match NpmSource's convention (the dropdown
     * filter calls array_reverse to walk newest-first).
     */
    public function populateCache(string $agentId, array $agent): array
    {
        $repo = (string)($agent['source']['repo'] ?? '');
        if ($repo === '') return ['dist_tags' => [], 'versions' => []];

        $releases = $this->apiGet("/repos/$repo/releases?per_page=30");
        if (!is_array($releases)) return ['dist_tags' => [], 'versions' => []];

        // Walk releases in API order (newest-first per GitHub default) so we can
        // pick up the freshest stable + prerelease in one pass.
        $latestStable = null;
        $latestPre    = null;
        $entries      = [];

        foreach ($releases as $r) {
            if (!empty($r['draft'])) continue;
            $tag = ltrim((string)($r['tag_name'] ?? ''), 'v');
            if ($tag === '') continue;

            $ts = isset($r['published_at']) ? strtotime((string)$r['published_at']) : 0;
            $isPre = !empty($r['prerelease']);
            $tags  = [];

            if ($isPre && $latestPre === null) {
                $latestPre = $tag;
                $tags[]    = 'prerelease';
            }
            if (!$isPre && $latestStable === null) {
                $latestStable = $tag;
                $tags[]       = 'latest';
            }

            $entries[] = [
                'version'   => $tag,
                'timestamp' => $ts ?: 0,
                'date'      => isset($r['published_at']) ? substr((string)$r['published_at'], 0, 10) : null,
                'tags'      => $tags,
            ];
        }

        // npm cache stores ascending; mirror that for consistency.
        usort($entries, fn($a, $b) => $a['timestamp'] - $b['timestamp']);

        $distTags = [];
        if ($latestStable !== null) $distTags['latest']     = $latestStable;
        if ($latestPre    !== null) $distTags['prerelease'] = $latestPre;

        return [
            'dist_tags' => $distTags,
            'versions'  => $entries,
        ];
    }

    private function resolveRelease(string $repo, ?string $targetVersion): ?array {
        if (!$targetVersion || $targetVersion === 'latest') return $this->resolveLatestStable($repo);
        if ($targetVersion === 'beta') return $this->resolvePrerelease($repo) ?: $this->resolveLatestStable($repo);
        foreach (["v$targetVersion", $targetVersion] as $tag) {
            $r = $this->apiGet("/repos/$repo/releases/tags/" . rawurlencode($tag));
            if ($r) return $r;
        }
        return null;
    }

    private function resolveLatestStable(string $repo): ?array {
        return $this->apiGet("/repos/$repo/releases/latest");
    }

    private function resolvePrerelease(string $repo): ?array {
        $releases = $this->apiGet("/repos/$repo/releases?per_page=20");
        if (!is_array($releases)) return null;
        foreach ($releases as $r) {
            if (!empty($r['prerelease'])) return $r;
        }
        return null;
    }

    private function apiGet(string $path) {
        $url = self::API_BASE . $path;
        $cmd = 'curl -fsSL -m 15 -H "Accept: application/vnd.github+json" -H "User-Agent: unraid-aicliagents" '
             . escapeshellarg($url) . ' 2>/dev/null';
        $out = @shell_exec($cmd);
        if (empty($out)) return null;
        return json_decode($out, true);
    }

    private function matchAsset(array $assets, string $pattern): ?array {
        $re = '/^' . str_replace(['\\*', '\\?'], ['.*', '.'], preg_quote($pattern, '/')) . '$/i';
        foreach ($assets as $a) {
            $name = $a['name'] ?? '';
            if ($name !== '' && preg_match($re, $name)) return $a;
        }
        return null;
    }

    private function download(string $url, string $dest): bool {
        $cmd = 'curl -fL -m 300 -o ' . escapeshellarg($dest) . ' ' . escapeshellarg($url) . ' 2>&1';
        $out = @shell_exec($cmd);
        if (!file_exists($dest) || filesize($dest) === 0) {
            LogService::log("GithubReleaseSource::download failed: $out", LogService::LOG_ERROR, "GithubReleaseSource");
            return false;
        }
        return true;
    }

    private function extractArchive(string $archive, string $destDir): bool {
        $lower = strtolower($archive);
        $cmd = null;
        if (preg_match('/\.tar\.gz$|\.tgz$/', $lower))       $cmd = 'tar -xzf ' . escapeshellarg($archive) . ' -C ' . escapeshellarg($destDir);
        elseif (preg_match('/\.tar\.bz2$|\.tbz2$/', $lower)) $cmd = 'tar -xjf ' . escapeshellarg($archive) . ' -C ' . escapeshellarg($destDir);
        elseif (preg_match('/\.tar\.xz$|\.txz$/', $lower))   $cmd = 'tar -xJf ' . escapeshellarg($archive) . ' -C ' . escapeshellarg($destDir);
        elseif (preg_match('/\.zip$/', $lower))              $cmd = 'unzip -q ' . escapeshellarg($archive) . ' -d ' . escapeshellarg($destDir);

        if ($cmd === null) {
            // Raw binary — just copy it into the staging dir.
            return @copy($archive, $destDir . '/' . basename($archive));
        }

        // Run through shell_exec + exit-code sentinel — matches codebase idiom elsewhere.
        $out = @shell_exec($cmd . ' 2>&1; echo __RC=$?');
        if (!preg_match('/__RC=(\d+)/', (string)$out, $m) || (int)$m[1] !== 0) {
            LogService::log("GithubReleaseSource::extract failed: " . trim((string)$out), LogService::LOG_ERROR, "GithubReleaseSource");
            return false;
        }
        return true;
    }

    private function interpolate(string $tpl, array $vars): string {
        foreach ($vars as $k => $v) $tpl = str_replace('{' . $k . '}', (string)$v, $tpl);
        return $tpl;
    }

    private function arch(): string {
        $m = trim((string)@shell_exec('uname -m 2>/dev/null'));
        return $m ?: 'x86_64';
    }
}
