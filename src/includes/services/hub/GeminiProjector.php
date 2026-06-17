<?php
/**
 * <module_context>
 *     <name>GeminiProjector</name>
 *     <description>Gemini CLI MCP projection into ~/.gemini/settings.json mcpServers
 *     (OP #1362 / H-01). stdio uses the de facto {command,args,env} shape; sse uses
 *     `url`, streamable HTTP uses `httpUrl` (Gemini's key naming). OAuth lives in the
 *     separate ~/.gemini/mcp-oauth-tokens.json which this projector never touches.</description>
 *     <dependencies>JsonMcpProjector</dependencies>
 *     <constraints>Managed keys only; unparseable file → write refused.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services\Hub;

class GeminiProjector extends JsonMcpProjector {

    public function agentId(): string { return 'gemini-cli'; }
    public function relPath(): string { return '.gemini/settings.json'; }
    public function label(): string   { return 'Gemini CLI'; }

    protected function vendorValue(array $def): array {
        $transport = $def['transport'] ?? 'stdio';
        if ($transport === 'stdio') return $this->stdioShape($def);
        if ($transport === 'http')  return ['httpUrl' => (string)($def['url'] ?? '')];
        return ['url' => (string)($def['url'] ?? '')]; // sse
    }
}
