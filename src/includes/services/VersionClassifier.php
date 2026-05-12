<?php
/**
 * <module_context>
 *     <name>VersionClassifier</name>
 *     <description>Pure classifier mapping a version-cache entry to one of stable | prerelease | platform_variant. Pure function; no I/O.</description>
 *     <dependencies>none</dependencies>
 *     <constraints>Must be deterministic — same input always returns same output.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class VersionClassifier
{
    private const PRERELEASE_TAGS = [
        'nightly', 'preview', 'alpha', 'beta', 'rc', 'next',
        'canary', 'dev', 'prerelease', 'experimental',
    ];

    public static function classify(array $entry, array $distTags = [], ?string $agentId = null): string
    {
        $tags    = $entry['tags']    ?? [];
        $version = (string)($entry['version'] ?? '');

        // 1. Platform-variant tags — never shown.
        foreach ($tags as $tag) {
            if (self::isPlatformTag((string)$tag)) return 'platform_variant';
        }

        // 1b. Platform-variant VERSION strings — never shown either. Older
        // platform-specific builds in the cache lose their tag once a newer
        // platform release lands (e.g. 0.126.0-alpha.7-linux-x64 becomes
        // untagged after alpha.8 ships), so the tag-only check above misses
        // them and they leak into the dropdown. Match the same suffix shape
        // applied to tags.
        if (self::isPlatformVersion($version)) return 'platform_variant';

        // 2. Per-agent override: opencode snapshot-*/ci/latest-N are too noisy
        // even for beta. Treated as platform_variant (never shown).
        if ($agentId === 'opencode') {
            foreach ($tags as $tag) {
                $t = (string)$tag;
                if (strpos($t, 'snapshot-') === 0 || $t === 'ci' || preg_match('/^latest-\d+$/', $t)) {
                    return 'platform_variant';
                }
            }
            if (strpos($version, 'snapshot-') !== false) return 'platform_variant';
        }

        // 3. Tag name says pre-release.
        foreach ($tags as $tag) {
            if (self::isPrereleaseTag((string)$tag)) return 'prerelease';
        }

        // 4. Version string itself has a pre-release suffix.
        if (preg_match('/-(alpha|beta|rc|nightly|preview|canary|dev|snapshot|prerelease|experimental)/i', $version)) {
            return 'prerelease';
        }

        // 5. Default: stable.
        return 'stable';
    }

    private static function isPlatformTag(string $tag): bool
    {
        return (bool)preg_match('/^(linux|darwin|win32|win64)(-|$)|-(x64|arm64|x86|x86_64)$/i', $tag);
    }

    /**
     * Match versions whose suffix is a -{os}-{arch} pair. Examples that should
     * match: 0.126.0-alpha.7-linux-x64, 0.125.0-darwin-arm64, 1.2.3-win32-x64.
     * Must NOT match: 1.2.3-linux (no arch), 1.2.3-x64 (no os), 1.2.3-foo-x64
     * (foo is not a known os).
     */
    private static function isPlatformVersion(string $version): bool
    {
        return (bool)preg_match(
            '/-(linux|darwin|win32|win64)-(x64|arm64|x86|x86_64|aarch64)$/i',
            $version
        );
    }

    private static function isPrereleaseTag(string $tag): bool
    {
        $lc = strtolower($tag);
        foreach (self::PRERELEASE_TAGS as $needle) {
            if ($lc === $needle) return true;
            if (strpos($lc, $needle . '-') === 0) return true;
            if (strpos($lc, $needle . '.') === 0) return true;
        }
        return false;
    }
}
