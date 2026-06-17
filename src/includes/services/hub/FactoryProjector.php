<?php
/**
 * <module_context>
 *     <name>FactoryProjector</name>
 *     <description>Factory "Droid" CLI MCP projection into ~/.factory/mcp.json
 *     (global). Uses the de facto "mcpServers" JSON key. stdio entries are the de
 *     facto {command,args,env} with NO `type` field; only REMOTE entries carry a
 *     `type` ("http"|"sse") with `url`. Verified against
 *     docs.factory.ai/cli/configuration/mcp.</description>
 *     <dependencies>JsonMcpProjector</dependencies>
 *     <constraints>Managed keys only; unparseable file → write refused.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services\Hub;

class FactoryProjector extends JsonMcpProjector {

    public function agentId(): string { return 'factory-cli'; }
    public function relPath(): string { return '.factory/mcp.json'; }
    public function label(): string   { return 'Factory Droid'; }

    protected function vendorValue(array $def): array {
        $transport = $def['transport'] ?? 'stdio';
        if ($transport === 'stdio') return $this->stdioShape($def); // {command,args?,env?} — no type
        return ['type' => ($transport === 'http' ? 'http' : 'sse'), 'url' => (string)($def['url'] ?? '')];
    }
}
