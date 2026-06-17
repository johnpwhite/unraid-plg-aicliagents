<?php
/**
 * <module_context>
 *     <name>CopilotProjector</name>
 *     <description>GitHub Copilot CLI MCP projection into ~/.copilot/mcp-config.json
 *     mcpServers (OP #1362 / H-01). Copilot entries carry an explicit `type`
 *     ('local' for stdio, 'http'/'sse' for remote) and a `tools` allowlist —
 *     projected as ["*"] (all tools) since the hub does not model per-tool filters.</description>
 *     <dependencies>JsonMcpProjector</dependencies>
 *     <constraints>Managed keys only; unparseable file → write refused.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services\Hub;

class CopilotProjector extends JsonMcpProjector {

    public function agentId(): string { return 'gh-copilot'; }
    public function relPath(): string { return '.copilot/mcp-config.json'; }
    public function label(): string   { return 'GitHub Copilot CLI'; }

    protected function vendorValue(array $def): array {
        $transport = $def['transport'] ?? 'stdio';
        if ($transport === 'stdio') {
            return ['type' => 'local'] + $this->stdioShape($def) + ['tools' => ['*']];
        }
        return ['type' => $transport, 'url' => (string)($def['url'] ?? ''), 'tools' => ['*']];
    }
}
