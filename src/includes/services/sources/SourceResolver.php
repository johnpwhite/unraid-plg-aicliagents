<?php
/**
 * <module_context>
 *     <name>SourceResolver</name>
 *     <description>Factory that returns the correct AgentSource implementation for an agent entry. Synthesises a {type:npm,...} source when the legacy top-level npm_package field is present and no explicit source block is set.</description>
 *     <dependencies>AgentSource impls, LogService</dependencies>
 *     <constraints>Under 100 lines. Legacy shim is permanent — NPM is a first-class source type.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services\Sources;

use AICliAgents\Services\LogService;

class SourceResolver {
    /**
     * Return the AgentSource implementation for the given agent entry.
     * Falls back to NpmSource when the agent has npm_package but no explicit source.
     * Returns null when no source can be resolved.
     */
    public static function resolve(array $agent): ?AgentSource {
        $source = $agent['source'] ?? null;

        // Legacy shim: synthesise a source from the top-level npm_package field.
        if (!is_array($source) && !empty($agent['npm_package'])) {
            $source = ['type' => 'npm', 'package' => $agent['npm_package']];
        }

        if (!is_array($source) || empty($source['type'])) {
            return null;
        }

        if (!empty($agent['source']) && !empty($agent['npm_package']) && $agent['source']['type'] !== 'npm') {
            LogService::log(
                "SourceResolver: agent '" . ($agent['id'] ?? '?') . "' declares both source.type=" . $source['type']
                . " and npm_package — source wins.",
                LogService::LOG_WARN,
                "SourceResolver"
            );
        }

        switch ($source['type']) {
            case 'npm':            return new NpmSource();
            case 'github_release': return new GithubReleaseSource();
            case 'curl_install':   return new CurlInstallSource();
            case 'tarball':        return new TarballSource();
            default:
                LogService::log("SourceResolver: unknown source type '" . $source['type'] . "'.", LogService::LOG_ERROR, "SourceResolver");
                return null;
        }
    }

    /**
     * Return the normalised source descriptor used by a resolved AgentSource.
     * Applies the same legacy-shim synthesis as resolve().
     */
    public static function descriptor(array $agent): ?array {
        if (!empty($agent['source']) && is_array($agent['source'])) return $agent['source'];
        if (!empty($agent['npm_package'])) return ['type' => 'npm', 'package' => $agent['npm_package']];
        return null;
    }
}
