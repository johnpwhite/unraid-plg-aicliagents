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
        // WP #1083: HTTPS-only at validation time. UrlValidator logs the
        // rejection with the offending URL; we just bail.
        if (!UrlValidator::requireHttps($scriptUrl, "CurlInstallSource::fetch script_url ($agentId)")) {
            return false;
        }

        $agentDir = AgentRegistry::agentPath($agentId);

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
        // CURL_INSTALL_VERSION_PIN_AND_TIMEOUT.md: the vendor script receives the
        // resolved target version (source.version_env / source.version_args) so a
        // pinned or channel-resolved install is honoured instead of the script's
        // own "latest" lookup; the budget is source.timeout_s (default 900 s — the
        // old fixed 300 s killed a slow 183 MB download) and the run is streamed
        // so the activity heartbeat stays fresh and the last script line is kept
        // for the failure reason.
        $run = self::buildRunCommand($src, $targetVersion, $scriptPath, $agentDir);
        if ($run === null) {
            LogService::log("CurlInstallSource: invalid source env key for $agentId", LogService::LOG_ERROR, "CurlInstallSource");
            self::$lastError = 'Invalid source.env / source.version_env key (letters, digits and underscores only)';
            return false;
        }
        $timeoutS = self::scriptTimeoutSeconds($src);
        $res = self::runScript($run, $timeoutS, function (int $elapsed, string $lastLine) use ($progress) {
            if (!is_callable($progress)) return;
            $progress("Running install script… {$elapsed}s" . ($lastLine !== '' ? " · $lastLine" : ''), 45);
        });
        $out = $res['out'];
        @unlink($scriptPath);
        if ($res['rc'] !== 0) {
            self::$lastError = $res['timedOut']
                ? "Install script timed out after {$timeoutS}s" . ($res['last'] !== '' ? " while: {$res['last']}" : '')
                  . ". Retry (a slow download), or raise source.timeout_s for this agent."
                : "Install script exited with code {$res['rc']}" . ($res['last'] !== '' ? ": {$res['last']}" : '');
            LogService::log("CurlInstallSource: install script failed for $agentId (" . self::$lastError . "):\n" . $out, LogService::LOG_ERROR, "CurlInstallSource");
            return false;
        }

        if (preg_match('/(\d+\.\d+\.\d+(?:[-+][\w.]+)?)/', $out, $m)) {
            @file_put_contents("$agentDir/VERSION", $m[1]);
        }
        return true;
    }


    /** Last human-readable failure reason from fetch(); surfaced by InstallerService. */
    private static string $lastError = '';

    public function lastError(): string {
        return self::$lastError;
    }

    /** Default and bounds for the vendor-script budget (source.timeout_s). */
    const DEFAULT_TIMEOUT_S = 900;
    const MIN_TIMEOUT_S     = 60;
    const MAX_TIMEOUT_S     = 3600;

    public static function scriptTimeoutSeconds(array $src): int {
        $t = (int)($src['timeout_s'] ?? self::DEFAULT_TIMEOUT_S);
        if ($t <= 0) $t = self::DEFAULT_TIMEOUT_S;
        return max(self::MIN_TIMEOUT_S, min(self::MAX_TIMEOUT_S, $t));
    }

    /**
     * The concrete version to hand to the vendor script, or null when the target
     * is a moving tag (latest/beta/…), empty, or not semver-like — the script then
     * resolves its own latest exactly as before.
     */
    public static function pinnedVersion(?string $targetVersion): ?string {
        $v = trim((string)$targetVersion);
        if ($v === '') return null;
        if (in_array(strtolower($v), ['latest', 'stable', 'beta', 'next', 'nightly'], true)) return null;
        $v = ltrim($v, 'vV');
        return preg_match('/^\d+\.\d+\.\d+(?:[-+][\w.]+)?$/', $v) ? $v : null;
    }

    /**
     * Build the shell command that runs the downloaded script under the captive
     * HOME/PREFIX with the source's env, the pinned version (via
     * source.version_env=NAME and/or source.version_args=[…"{version}"…]) and
     * the `timeout` budget. Returns null when an env key is invalid. Pure.
     */
    public static function buildRunCommand(array $src, ?string $targetVersion, string $scriptPath, string $agentDir, int $timeoutS = 0): ?string {
        $envPairs = [
            'HOME='   . escapeshellarg("$agentDir/home"),
            'PREFIX=' . escapeshellarg($agentDir),
            'PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
        ];
        if (!empty($src['env']) && is_array($src['env'])) {
            foreach ($src['env'] as $k => $v) {
                // Quote the VALUE, not the whole assignment. A shell does not
                // classify a fully-quoted `KEY=value` word as an environment
                // assignment and instead tries to execute it as a command.
                if (!is_string($k) || !preg_match('/^[A-Z_][A-Z0-9_]*$/', $k)) return null;
                $envPairs[] = $k . '=' . escapeshellarg((string)$v);
            }
        }
        $pinned = self::pinnedVersion($targetVersion);
        $args = '';
        if ($pinned !== null) {
            $venv = (string)($src['version_env'] ?? '');
            if ($venv !== '') {
                if (!preg_match('/^[A-Z_][A-Z0-9_]*$/', $venv)) return null;
                $envPairs[] = $venv . '=' . escapeshellarg($pinned);
            }
            if (!empty($src['version_args']) && is_array($src['version_args'])) {
                foreach ($src['version_args'] as $a) {
                    $args .= ' ' . escapeshellarg(str_replace('{version}', $pinned, (string)$a));
                }
            }
        }
        if ($timeoutS <= 0) $timeoutS = self::scriptTimeoutSeconds($src);
        // WP #963: timeout guard. A hung vendor handoff (e.g. an interactive
        // `agy install` shell-config step) must not stall the install. `timeout`
        // signals the script's whole process group at the budget (exit 124) and
        // hard-kills stragglers 15 s later.
        return implode(' ', $envPairs) . ' timeout -k 15 ' . (int)$timeoutS . ' bash ' . escapeshellarg($scriptPath) . $args . ' 2>&1';
    }

    /**
     * Run the command, streaming its output so a long download keeps the
     * activity heartbeat alive: $onTick(int $elapsedSeconds, string $lastLine)
     * fires every $tickEverySeconds while the script runs. Returns
     * ['rc' => int, 'out' => string, 'last' => string, 'timedOut' => bool].
     * A missing/ignored `timeout` is backstopped by a hard kill at budget + 60 s.
     */
    public static function runScript(string $cmd, int $timeoutS, ?callable $onTick = null, int $tickEverySeconds = 30): array {
        $desc = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $proc = @proc_open($cmd, $desc, $pipes);
        if (!is_resource($proc)) {
            return ['rc' => 127, 'out' => '', 'last' => 'could not start the install script', 'timedOut' => false];
        }
        @stream_set_blocking($pipes[1], false);
        @stream_set_blocking($pipes[2], false);
        $out = '';
        $start = time();
        $lastTick = $start;
        $rc = null;
        $killed = false;
        $tick = max(1, $tickEverySeconds);
        $drain = function () use (&$pipes, &$out): void {
            foreach ([1, 2] as $i) {
                if (!isset($pipes[$i]) || !is_resource($pipes[$i])) continue;
                while (($chunk = @fread($pipes[$i], 8192)) !== false && $chunk !== '') $out .= $chunk;
            }
        };
        while (true) {
            $r = array_values(array_filter([$pipes[1] ?? null, $pipes[2] ?? null], 'is_resource'));
            $w = null; $e = null;
            if ($r !== []) { @stream_select($r, $w, $e, 1); }
            else { usleep(200000); }
            $drain();
            $st = proc_get_status($proc);
            if (!$st['running']) {
                $rc = (int)$st['exitcode'];
                $drain();
                break;
            }
            $now = time();
            if ($onTick !== null && ($now - $lastTick) >= $tick) {
                $lastTick = $now;
                try { $onTick($now - $start, self::lastLine($out)); } catch (\Throwable $t) { /* never break the install */ }
            }
            if (!$killed && ($now - $start) > ($timeoutS + 60)) {
                @proc_terminate($proc, 9);
                $killed = true;
            }
        }
        foreach ([1, 2] as $i) { if (isset($pipes[$i]) && is_resource($pipes[$i])) @fclose($pipes[$i]); }
        @proc_close($proc);
        if ($killed) $rc = 124;
        return ['rc' => $rc, 'out' => $out, 'last' => self::lastLine($out), 'timedOut' => ($rc === 124)];
    }

    /** Last non-empty output line (CR-separated progress bars count as lines), ANSI-stripped, capped. */
    public static function lastLine(string $out): string {
        $clean = (string)preg_replace('/\x1b\[[0-9;?]*[ -\/]*[@-~]/', '', $out);
        $lines = preg_split('/[\r\n]+/', $clean) ?: [];
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $l = trim($lines[$i]);
            if ($l === '' || preg_match('/^__RC=\d+$/', $l) || preg_match('/^[#=\-\s.%0-9]+$/', $l)) continue;
            return strlen($l) > 120 ? substr($l, 0, 117) . '…' : $l;
        }
        return '';
    }

    public function stage(string $agentId, array $agent): string {
        $src = $agent['source'] ?? [];
        $executable = (string)($src['executable'] ?? '');
        if ($executable === '') return $agent['binary'] ?? '';
        $agentDir = AgentRegistry::agentPath($agentId);
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
        $agentDir = AgentRegistry::agentPath($agentId);
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
        // WP #1083: HTTPS-only at validation time. A non-HTTPS manifest could
        // be MITM'd to lie about a "newer version available", tricking the
        // user into upgrading to whatever the attacker serves.
        if (!UrlValidator::requireHttps($url, 'CurlInstallSource::probeManifestVersion manifest_url')) {
            return null;
        }
        $json = @shell_exec('curl -fsSL -m 20 ' . escapeshellarg($url) . ' 2>/dev/null');
        if (!is_string($json) || $json === '') return null;
        if (($src['manifest_format'] ?? 'json') === 'plain') {
            $v = trim($json);
            return preg_match('/^v?(\d+\.\d+\.\d+(?:[-+][\w.]+)?)/', $v, $m) ? $m[1] : null;
        }
        $data = json_decode($json, true);
        if (!is_array($data)) return null;
        $key = (string)($src['manifest_version_key'] ?? 'version');
        $v = $data[$key] ?? '';
        return (is_string($v) && preg_match('/^\d+\.\d+\.\d+/', $v)) ? $v : null;
    }
}
