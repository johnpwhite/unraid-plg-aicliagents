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

require_once __DIR__ . '/../AgentCaptiveStateService.php';

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
     * Source types whose install output is entirely reconstructible, and which
     * can therefore be installed into a layer of their own beside the version
     * that is running — docs/specs/SIDE_BY_SIDE_AGENT_INSTALLS.md Phase 3.
     *
     * The line is drawn where the spec's 2026-09-09 correction landed. The
     * vendor install HOME is only an install-time payload location; the runtime
     * shell exports the shared managed HOME, outside every agent layer, for
     * credentials, history and configuration. CurlInstallSource also honours
     * the same install-root override as the other sources, so its payload can
     * be staged into a generation of its own.
     *
     * `curl_install` qualifies only with a valid `source.captive_state`
     * declaration (see supportsSideBySideInstall and AgentCaptiveStateService).
     *
     * A storage backend may still decline staging (notably passthrough, which
     * has no versioned activation path). InstallerService then retains the
     * existing closed-set fallback instead of pretending that backend can do
     * concurrent activation.
     */
    private const SIDE_BY_SIDE_SOURCE_TYPES = ['npm', 'tarball', 'github_release', 'curl_install'];

    /**
     * True when a new version of this agent can be installed while the version
     * in service keeps running. Reads the same normalised descriptor resolve()
     * uses, so the legacy top-level npm_package shim is honoured here too — an
     * agent declared the old way is npm-sourced and qualifies.
     */
    public static function supportsSideBySideInstall(array $agent): bool {
        $desc = self::descriptor($agent);
        $type = (string)($desc['type'] ?? '');
        if ($type === '' || !in_array($type, self::SIDE_BY_SIDE_SOURCE_TYPES, true)) {
            return false;
        }
        // SIDE_BY_SIDE_AGENT_INSTALLS.md Phase 4 — 2026-09-23 (#270): a vendor
        // script can write user state into the captive home. Two generations
        // may share that state only through the version-independent store, and
        // only for the paths the registry declares after a check against the
        // vendor's installer and docs. No valid declaration: the upgrade waits.
        if ($type === 'curl_install') {
            return \AICliAgents\Services\AgentCaptiveStateService::declaredPaths($agent) !== null;
        }
        return true;
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
