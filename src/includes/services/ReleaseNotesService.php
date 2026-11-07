<?php
/**
 * <module_context>
 *     <name>ReleaseNotesService</name>
 *     <description>The release notes of an agent for the update overlay of the Agent Store card (docs/specs/AGENT_STORE_CARD_HEADER.md, "Where the release notes come from"). Reads the GitHub release of each wanted version (one call per version, never the big /releases list) or a CHANGELOG.md file, cuts each version to 8 KB, and caches per agent and per version.</description>
 *     <dependencies>AgentRegistry, SecretPaths, VersionCheckService</dependencies>
 *     <constraints>Read-only. Only the overlay asks for it, through the action get_release_notes; the Store page never waits on it. 15 s timeout per fetch. Text only: the caller shows it as text. A failure returns an empty list and the link fallback; it never returns an error box.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class ReleaseNotesService {
    /** Seconds that a fetched answer stays in the cache. */
    const TTL = 86400;
    /** Seconds that a failed fetch is remembered, so a broken source is not asked again at once. */
    const FAIL_TTL = 600;
    /** The longest text kept for one version. */
    const MAX_BYTES = 8192;
    /** Seconds before a fetch stops. The same as the version check. */
    const TIMEOUT = 15;
    /** The most versions shown in the overlay. */
    const MAX_VERSIONS = 6;
    /** The most releases or sections read from one source. */
    const MAX_ENTRIES = 30;
    /** The most versions kept in the cache of one GitHub agent. */
    const MAX_CACHED = 30;
    /** The largest answer read for a list of tag names (openai/codex: 0.6 MB). */
    const MAX_ANSWER = 3145728;
    /** The largest answer read for one release (openai/codex: 0.3 to 0.6 MB, with every download file listed). */
    const MAX_RELEASE = 3145728;

    /** Test seam: fn(string $url): ?string replaces the network call. */
    public static $fetcher = null;

    /**
     * Where the notes of an agent come from, found from its registry entry.
     * @return array{type:string,repo?:string,raw?:string}|null null when the plugin has no source for it
     */
    public static function sourceFor(array $agent): ?array {
        $url = (string)($agent['changelog_url'] ?? '');
        if (preg_match('#^https://github\.com/([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+)/releases/?$#D', $url, $m)) {
            return ['type' => 'github', 'repo' => $m[1]];
        }
        if (preg_match('#^https://github\.com/([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+)/blob/([A-Za-z0-9_.-]+)/(CHANGELOG\.md)$#D', $url, $m)) {
            return ['type' => 'changelog', 'raw' => 'https://raw.githubusercontent.com/' . $m[1] . '/' . $m[2] . '/' . $m[3]];
        }
        $src = (array)($agent['source'] ?? []);
        if (($src['type'] ?? '') === 'github_release' && preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#D', (string)($src['repo'] ?? ''))) {
            return ['type' => 'github', 'repo' => (string)$src['repo']];
        }
        return null;
    }

    /** The first version in a tag or heading ("rust-v0.163.0" gives "0.163.0"), or null. */
    public static function versionOf(string $text): ?string {
        return preg_match('/(\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.+_-]*)?)/', $text, $m) === 1 ? rtrim($m[1], '.-') : null;
    }

    /** Cut a text to MAX_BYTES on a character border. @return array{0:string,1:bool} text and whether it was cut */
    public static function cut(string $text): array {
        $text = str_replace("\r\n", "\n", $text);
        if (strlen($text) <= self::MAX_BYTES) return [$text, false];
        $part = substr($text, 0, self::MAX_BYTES);
        // Drop an incomplete multi-byte character at the end.
        while ($part !== '' && !mb_check_encoding($part, 'UTF-8')) $part = substr($part, 0, -1);
        return [$part, true];
    }

    /**
     * PURE: the entries of a GitHub /releases answer.
     * @return list<array{version:string,date:?string,body:string,truncated:bool}>
     */
    public static function parseGithub(string $json): array {
        $rows = json_decode($json, true);
        if (!is_array($rows)) return [];
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r) || !empty($r['draft'])) continue;
            $v = self::versionOf((string)($r['tag_name'] ?? ''));
            if ($v === null || !VersionCheckService::isSafeVersion($v)) continue;
            [$body, $cut] = self::cut((string)($r['body'] ?? ''));
            $d = (string)($r['published_at'] ?? '');
            $out[] = ['version' => $v, 'date' => preg_match('/^\d{4}-\d{2}-\d{2}/', $d) ? substr($d, 0, 10) : null,
                      'body' => $body, 'truncated' => $cut];
            if (count($out) >= self::MAX_ENTRIES) break;
        }
        return $out;
    }

    /**
     * PURE: the sections of a CHANGELOG.md file. A section starts at a heading line that holds a
     * version ("## [1.2.3] - 2026-10-01", "# v1.2.3") and ends at the next heading of the same or
     * a higher level.
     * @return list<array{version:string,date:?string,body:string,truncated:bool}>
     */
    public static function parseChangelog(string $text): array {
        $lines = preg_split('/\R/', $text) ?: [];
        $out = [];
        $cur = null;
        $level = 0;
        foreach ($lines as $line) {
            if (preg_match('/^(#{1,4})\s+(.*)$/D', $line, $m)) {
                $lv = strlen($m[1]);
                $v = self::versionOf($m[2]);
                if ($v !== null && VersionCheckService::isSafeVersion($v) && ($cur === null || $lv <= $level)) {
                    self::closeSection($cur, $out);
                    $cur = null;
                    if (count($out) >= self::MAX_ENTRIES) return $out;
                    $date = preg_match('/(\d{4}-\d{2}-\d{2})/', $m[2], $dm) ? $dm[1] : null;
                    $cur = ['version' => $v, 'date' => $date, 'lines' => []];
                    $level = $lv;
                    continue;
                }
                if ($cur !== null && $lv <= $level) {
                    self::closeSection($cur, $out);
                    $cur = null;
                    continue;
                }
            }
            if ($cur !== null) $cur['lines'][] = $line;
        }
        self::closeSection($cur, $out);
        return $out;
    }

    /**
     * Close the open section of a changelog and add it to the list.
     * @param array{version:string,date:?string,lines:list<string>}|null $cur
     * @param list<array{version:string,date:?string,body:string,truncated:bool}> $out
     */
    private static function closeSection(?array $cur, array &$out): void {
        if ($cur === null) return;
        [$body, $cut] = self::cut(trim(implode("\n", $cur['lines'])));
        $out[] = ['version' => $cur['version'], 'date' => $cur['date'], 'body' => $body, 'truncated' => $cut];
    }
    /**
     * PURE: the entries whose version is newer than $from and not newer than $to, newest first.
     * With no $from only $to itself is kept.
     * @param list<array{version:string,date:?string,body:string,truncated:bool}> $entries
     * @return list<array{version:string,date:?string,body:string,truncated:bool}>
     */
    public static function between(array $entries, string $from, string $to): array {
        $keep = [];
        foreach ($entries as $e) {
            $v = $e['version'];
            $upper = version_compare($v, $to) <= 0;
            $lower = $from === '' ? version_compare($v, $to) === 0 : version_compare($v, $from) > 0;
            if ($upper && $lower) $keep[] = $e;
        }
        usort($keep, static fn(array $a, array $b): int => version_compare($b['version'], $a['version']));
        return array_slice($keep, 0, self::MAX_VERSIONS);
    }

    private static function cacheFile(string $agentId): string {
        return SecretPaths::stateRoot() . '/release-notes-' . $agentId . '.json';
    }

    /** @param int $maxBytes the largest answer that is read */
    private static function fetch(string $url, int $maxBytes = self::MAX_ANSWER): ?string {
        if (self::$fetcher !== null) return (self::$fetcher)($url);
        if (!function_exists('curl_init')) return null;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => self::TIMEOUT, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_MAXFILESIZE => $maxBytes,
            CURLOPT_USERAGENT => 'unraid-aicliagents', CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json'],
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($body !== false && $code >= 200 && $code < 300) ? (string)$body : null;
    }

    private static function saveCache(string $file, array $data): void {
        $dir = dirname($file);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $tmp = $file . '.tmp.' . getmypid();
        if (@file_put_contents($tmp, json_encode($data)) !== false) @rename($tmp, $file);
    }

    /**
     * PURE: the entry of one GitHub release answer (/releases/tags/<tag>), or null.
     * @return array{version:string,date:?string,body:string,truncated:bool}|null
     */
    public static function parseRelease(string $json): ?array {
        $r = json_decode($json, true);
        if (!is_array($r) || !empty($r['draft'])) return null;
        $one = self::parseGithub(json_encode([$r]));
        return $one[0] ?? null;
    }

    /** PURE: the part of a tag before its version ("rust-v0.1.0" gives "rust-v", "@a/b@2.5.2" gives "@a/b@"), or null. */
    public static function prefixOf(string $tag): ?string {
        $v = self::versionOf($tag);
        $pos = $v === null ? false : strpos($tag, $v);
        return $pos === false ? null : substr($tag, 0, $pos);
    }

    /** A tag or ref as a URL path: each segment is encoded ("@" becomes %40), "/" stays a separator. */
    public static function encodePath(string $path): string {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    /** PURE: true when a version has a pre-release suffix ("0.168.0-alpha.7"). */
    public static function isPrerelease(string $version): bool {
        return strpos($version, '-') !== false;
    }

    /**
     * PURE: the versions to fetch from a list of tag names: newer than $from, not newer than $to,
     * no pre-release unless $to is one, newest first, at most MAX_VERSIONS. A later tag of the
     * same version is dropped.
     * @param list<string> $tags
     * @return list<array{tag:string,version:string}>
     */
    public static function pickTags(array $tags, string $from, string $to): array {
        $byVersion = [];
        $toPre = self::isPrerelease($to);
        foreach ($tags as $t) {
            $v = self::versionOf((string)$t);
            if ($v === null || !VersionCheckService::isSafeVersion($v) || isset($byVersion[$v])) continue;
            if (self::isPrerelease($v) && !($toPre && $v === $to)) continue;
            if (version_compare($v, $to) > 0) continue;
            if ($from === '' ? $v !== $to : version_compare($v, $from) <= 0) continue;
            $byVersion[$v] = (string)$t;
        }
        uksort($byVersion, static fn($a, $b): int => version_compare((string)$b, (string)$a));
        $out = [];
        foreach ($byVersion as $v => $t) {
            $out[] = ['tag' => $t, 'version' => (string)$v];
            if (count($out) >= self::MAX_VERSIONS) break;
        }
        return $out;
    }

    /**
     * The entries of a GitHub source, cached per version. The big /releases list is never asked:
     * it lists every download file of every release (13 MB for openai/codex). The way is
     *   1. the tag prefix, learned from the newest release (GET /releases?per_page=1), then "v", then none; the prefix whose tag of $to answers is kept,
     *   2. the tag names with that prefix (git/matching-refs/tags/<prefix>), only when $from is set,
     *   3. one /releases/tags/<tag> for each wanted version that the cache does not hold.
     * A release note does not change, so a cached version is not fetched again.
     * @return array{entries:list<array{version:string,date:?string,body:string,truncated:bool}>,ok:bool}
     */
    private static function githubEntries(string $agentId, array $source, string $from, string $to, int $now): array {
        $file = self::cacheFile($agentId);
        $c = json_decode((string)@file_get_contents($file), true);
        if (!is_array($c) || ($c['source'] ?? null) !== $source || !isset($c['versions']) || !is_array($c['versions'])) {
            $c = ['source' => $source, 'versions' => []];
        }
        $versions = $c['versions'];
        $repo = 'https://api.github.com/repos/' . $source['repo'];
        $failed = isset($c['failed_at']) && ($now - (int)$c['failed_at']) < self::FAIL_TTL;
        $prefix = isset($c['prefix']) && is_string($c['prefix']) ? $c['prefix'] : null;
        $tagsFresh = isset($c['tags_at'], $c['tags']) && is_array($c['tags']) && ($now - (int)$c['tags_at']) < self::TTL;
        $ok = true;

        $get = function (string $tag) use ($repo, $now): ?array {
            $raw = self::fetch($repo . '/releases/tags/' . self::encodePath($tag), self::MAX_RELEASE);
            $e = $raw === null ? null : self::parseRelease($raw);
            return $e === null ? null : $e + ['fetched_at' => $now];
        };

        // 1. The target. A cached target needs no call at all.
        if (!isset($versions[$to])) {
            if ($failed) {
                $ok = false;
            } else {
                $cands = [];
                if ($prefix !== null) {
                    $cands = [$prefix];
                } else {
                    // Learn the tag form from the newest release (one release: 65 KB to 0.4 MB), then v, then none.
                    $one = self::fetch($repo . '/releases?per_page=1', self::MAX_RELEASE);
                    $rows = $one === null ? null : json_decode($one, true);
                    $learned = is_array($rows) && isset($rows[0]['tag_name']) ? self::prefixOf((string)$rows[0]['tag_name']) : null;
                    $cands = array_values(array_unique(array_merge($learned !== null ? [$learned] : [], ['v', ''])));
                }
                foreach ($cands as $p) {
                    $e = $get($p . $to);
                    if ($e !== null && $e['version'] === $to) { $versions[$to] = $e; $prefix = $p; break; }
                }
                if (!isset($versions[$to])) $ok = false;
            }
        }
        // 2. The versions between, from the tag names.
        $want = [];
        $have = false;
        if ($ok && $from !== '') {
            $tags = $tagsFresh ? $c['tags'] : [];
            foreach ($tags as $t) { if (self::versionOf((string)$t) === $to) { $have = true; break; } }
            if (!$have && $prefix !== null && !$failed) {
                $raw = self::fetch($repo . '/git/matching-refs/tags/' . self::encodePath($prefix), self::MAX_ANSWER);
                $rows = $raw === null ? null : json_decode($raw, true);
                if (is_array($rows)) {
                    $tags = [];
                    foreach ($rows as $r) {
                        if (is_array($r) && isset($r['ref']) && strpos((string)$r['ref'], 'refs/tags/') === 0) $tags[] = substr((string)$r['ref'], 10);
                    }
                    $c['tags'] = $tags;
                    $c['tags_at'] = $now;
                } else {
                    $ok = false;
                }
            }
            if ($ok || $tags) $want = self::pickTags($tags, $from, $to);
            if ($failed && !$have) $ok = false;
        }
        // 3. The notes of each wanted version that is not cached.
        if (!$failed) {
            foreach ($want as $w) {
                if (isset($versions[$w['version']])) continue;
                $e = $get($w['tag']);
                if ($e !== null && $e['version'] === $w['version']) $versions[$w['version']] = $e; else $ok = false;
            }
        }
        if ($failed && $want) $ok = false;

        // Keep the newest MAX_CACHED versions.
        uksort($versions, static fn($a, $b): int => version_compare((string)$b, (string)$a));
        $versions = array_slice($versions, 0, self::MAX_CACHED, true);
        $c['versions'] = $versions;
        $c['prefix'] = $prefix;
        // A call inside the pause asks nothing, so it keeps the first failure time: otherwise a
        // page that asks every few minutes would push the retry away for ever (#926).
        if ($ok) unset($c['failed_at']); elseif (!$failed) $c['failed_at'] = $now;
        self::saveCache($file, $c);

        $entries = [];
        foreach ($versions as $v => $e) {
            $entries[] = ['version' => (string)$v, 'date' => $e['date'] ?? null, 'body' => (string)($e['body'] ?? ''), 'truncated' => !empty($e['truncated'])];
        }
        return ['entries' => $entries, 'ok' => $ok];
    }

    /**
     * The entries of one agent. GitHub: per version, see githubEntries(). CHANGELOG.md: the file
     * is cached for 24 hours; a failed fetch is remembered for FAIL_TTL.
     * @return array{entries:list<array{version:string,date:?string,body:string,truncated:bool}>,ok:bool}
     */
    public static function entries(string $agentId, array $source, string $from = '', string $to = '', ?int $now = null): array {
        $now = $now ?? time();
        if ($source['type'] === 'github') return self::githubEntries($agentId, $source, $from, $to, $now);
        $file = self::cacheFile($agentId);
        $c = json_decode((string)@file_get_contents($file), true);
        if (is_array($c) && isset($c['fetched_at'], $c['entries']) && ($c['source'] ?? null) === $source) {
            $age = $now - (int)$c['fetched_at'];
            $newest = $c['entries'][0]['version'] ?? null;
            $stale = $to !== '' && $age >= self::FAIL_TTL && ($newest === null || version_compare($to, (string)$newest) > 0);
            if (!empty($c['ok']) && $age < self::TTL && !$stale) return ['entries' => $c['entries'], 'ok' => true];
            if (empty($c['ok']) && $age < self::FAIL_TTL) return ['entries' => [], 'ok' => false];
        }
        $url = (string)($source['raw'] ?? '');
        $raw = $url !== '' ? self::fetch($url) : null;
        $entries = $raw === null ? [] : self::parseChangelog($raw);
        $ok = $raw !== null;
        self::saveCache($file, ['fetched_at' => $now, 'ok' => $ok, 'source' => $source, 'entries' => $entries]);
        return ['entries' => $entries, 'ok' => $ok];
    }

    /**
     * The answer of get_release_notes. `notes` is the list of versions between $from and $to
     * (newest first); it is empty when the plugin has no notes, and the overlay then shows the link.
     * `changelog_url` is the vendor's page, https only.
     */
    public static function forAgent(string $agentId, string $from, string $to, ?int $now = null): array {
        $agents = AgentRegistry::getDefaultAgents();
        if (!isset($agents[$agentId])) return ['status' => 'error', 'message' => 'invalid_agent'];
        if (!VersionCheckService::isSafeVersion($to) || ($from !== '' && !VersionCheckService::isSafeVersion($from))) {
            return ['status' => 'error', 'message' => 'invalid_version'];
        }
        $url = (string)($agents[$agentId]['changelog_url'] ?? '');
        $out = ['status' => 'ok', 'agent' => $agentId, 'from' => $from, 'to' => $to,
                'changelog_url' => preg_match('#^https://#', $url) === 1 ? $url : '', 'source' => 'none', 'notes' => [], 'fetch_failed' => false];
        $source = self::sourceFor($agents[$agentId]);
        if ($source === null) return $out;
        $out['source'] = $source['type'];
        $e = self::entries($agentId, $source, $from, $to, $now);
        $out['fetch_failed'] = !$e['ok'];
        $out['notes'] = self::between($e['entries'], $from, $to);
        return $out;
    }
}
