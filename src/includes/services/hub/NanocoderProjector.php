<?php
/**
 * <module_context>
 *     <name>NanocoderProjector</name>
 *     <description>Nanocoder MCP projection into ~/.config/nanocoder/.mcp.json (global
 *     XDG path on Linux — note the DOTFILE name, verified from the official
 *     docs/configuration/mcp-configuration.md + .mcp.example.json). Uses the
 *     "mcpServers" key; entries carry an explicit `transport` field: stdio
 *     {transport:"stdio", command, args, env}; remote {transport:"http", url}.</description>
 *     <dependencies>JsonMcpProjector</dependencies>
 *     <constraints>Managed keys only; unparseable file → write refused.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services\Hub;

class NanocoderProjector extends JsonMcpProjector {

    public function agentId(): string { return 'nanocoder'; }
    public function relPath(): string { return '.config/nanocoder/.mcp.json'; }
    public function label(): string   { return 'Nanocoder'; }

    protected function vendorValue(array $def): array {
        $transport = $def['transport'] ?? 'stdio';
        if ($transport === 'stdio') return ['transport' => 'stdio'] + $this->stdioShape($def);
        // remote: nanocoder uses transport "http" (de-facto) + url.
        return ['transport' => ($transport === 'sse' ? 'sse' : 'http'), 'url' => (string)($def['url'] ?? '')];
    }
}
