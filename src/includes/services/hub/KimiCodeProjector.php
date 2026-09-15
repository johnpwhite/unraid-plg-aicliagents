<?php
/** Kimi Code user-scope MCP projection into ~/.kimi-code/mcp.json. */
namespace AICliAgents\Services\Hub;

class KimiCodeProjector extends JsonMcpProjector {
    public function agentId(): string { return 'kimi-code'; }
    public function relPath(): string { return '.kimi-code/mcp.json'; }
    public function label(): string   { return 'Kimi Code'; }

    protected function vendorValue(array $def): array {
        $transport = $def['transport'] ?? 'stdio';
        if ($transport === 'stdio') return ['transport' => 'stdio'] + $this->stdioShape($def);
        return ['transport' => $transport, 'url' => (string)($def['url'] ?? '')];
    }
}
