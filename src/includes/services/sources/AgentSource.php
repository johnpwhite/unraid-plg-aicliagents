<?php
/**
 * <module_context>
 *     <name>AgentSource</name>
 *     <description>Interface implemented by each install-source strategy (NPM, GitHub Release, curl install, raw tarball). Decouples InstallerService / AgentRegistry from NPM-specific mechanics.</description>
 *     <dependencies>none</dependencies>
 *     <constraints>Four methods only. Implementations live under src/includes/services/sources/.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services\Sources;

interface AgentSource {
    /**
     * Download the agent's payload into AGENT_BASE/$agentId.
     * Progress callback signature: function(string $msg, int $pct): void
     * Returns true on success; false stops the install.
     */
    public function fetch(string $agentId, array $agent, ?string $targetVersion, $progress): bool;

    /**
     * Post-fetch layout: extract, move binaries, chmod +x as needed.
     * Returns the absolute path to the executable entry point, or '' if none discoverable.
     */
    public function stage(string $agentId, array $agent): string;

    /**
     * Probe the installed version string. Return null when unknown.
     */
    public function discoverVersion(string $agentId, array $agent): ?string;

    /**
     * Resolve latest/beta versions for a channel. Returns null when unsupported.
     * Shape on success:
     *   [
     *     'latest_version' => '1.2.3',
     *     'channel' => 'latest',
     *     'installed_version' => '1.2.0',
     *     'has_update' => true,
     *     'has_downgrade' => false,
     *     'version_mismatch' => true,
     *   ]
     */
    public function checkUpdates(string $agentId, array $agent, string $channel): ?array;
}
