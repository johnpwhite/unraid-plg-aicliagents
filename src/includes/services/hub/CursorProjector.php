<?php
/**
 * <module_context>
 *     <name>CursorProjector</name>
 *     <description>Cursor Agent CLI MCP projection into ~/.cursor/mcp.json
 *     (shared with the Cursor editor; official CLI docs confirm the same file).
 *     Uses the "mcpServers" JSON key with stdio {command,args,env} and remote
 *     {url} / {url,headers} shapes.</description>
 *     <dependencies>JsonMcpProjector</dependencies>
 *     <constraints>Managed keys only; unparseable file → write refused.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services\Hub;

class CursorProjector extends JsonMcpProjector {

    public function agentId(): string { return 'cursor-cli'; }
    public function relPath(): string { return '.cursor/mcp.json'; }
    public function label(): string   { return 'Cursor CLI'; }

    protected function vendorValue(array $def): array {
        $transport = $def['transport'] ?? 'stdio';
        if ($transport === 'stdio') {
            return $this->stdioShape($def);
        }
        $out = ['url' => (string)($def['url'] ?? '')];
        if (!empty($def['headers']) && is_array($def['headers'])) {
            $headers = $def['headers'];
            ksort($headers);
            $out['headers'] = $headers;
        }
        return $out;
    }
}
