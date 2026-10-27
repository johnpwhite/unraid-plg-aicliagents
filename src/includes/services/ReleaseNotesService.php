<?php
/**
 * <module_context>
 *     <name>ReleaseNotesService</name>
 *     <description>The release notes of an agent for the update overlay of the Agent Store card (docs/specs/AGENT_STORE_CARD_HEADER.md, "Where the release notes come from"). Reads GitHub release bodies or a CHANGELOG.md file, cuts each version to 8 KB, and caches the result per agent for 24 hours.</description>
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

    private static function fetch(string $url): ?string {
        if (self::$fetcher !== null) return (self::$fetcher)($url);
        if (!function_exists('curl_init')) return null;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => self::TIMEOUT, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_MAXFILESIZE => 3 * 1024 * 1024,
            CURLOPT_USERAGENT => 'unraid-aicliagents', CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json'],
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($body !== false && $code >= 200 && $code < 300) ? (string)$body : null;
    }

    /**
     * The entries of one agent: from the cache when it is under 24 hours old, else from the source.
     * @return array{entries:list<array{version:string,date:?string,body:string,truncated:bool}>,ok:bool}
     */
    public static function entries(string $agentId, array $source, ?int $now = null): array {
        $now = $now ?? time();
        $file = self::cacheFile($agentId);
        $c = json_decode((string)@file_get_contents($file), true);
        if (is_array($c) && isset($c['fetched_at'], $c['entries']) && ($c['source'] ?? null) === $source) {
            $age = $now - (int)$c['fetched_at'];
            if (!empty($c['ok']) && $age < self::TTL) return ['entries' => $c['entries'], 'ok' => true];
            if (empty($c['ok']) && $age < self::FAIL_TTL) return ['entries' => [], 'ok' => false];
        }
        $url = $source['type'] === 'github'
            ? 'https://api.github.com/repos/' . $source['repo'] . '/releases?per_page=' . self::MAX_ENTRIES
            : (string)($source['raw'] ?? '');
        $raw = $url !== '' ? self::fetch($url) : null;
        $entries = $raw === null ? [] : ($source['type'] === 'github' ? self::parseGithub($raw) : self::parseChangelog($raw));
        $ok = $raw !== null;
        $dir = dirname($file);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $tmp = $file . '.tmp.' . getmypid();
        if (@file_put_contents($tmp, json_encode(['fetched_at' => $now, 'ok' => $ok, 'source' => $source, 'entries' => $entries])) !== false) {
            @rename($tmp, $file);
        }
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
        $e = self::entries($agentId, $source, $now);
        $out['fetch_failed'] = !$e['ok'];
        $out['notes'] = self::between($e['entries'], $from, $to);
        return $out;
    }
}
