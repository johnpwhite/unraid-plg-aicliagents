<?php
/**
 * <module_context>
 *     <name>AntigravityProjector</name>
 *     <description>Antigravity CLI MCP projection into ~/.gemini/antigravity-cli/mcp_config.json
 *     (the global config path per the official Antigravity CLI docs; the workspace
 *     path is .agents/mcp_config.json, out of scope for HOME projection). Uses the
 *     "mcpServers" JSON key; stdio is the de facto {command,args,env}. IMPORTANT:
 *     remote servers use a single `serverUrl` field — the docs state Gemini's legacy
 *     `url`/`httpUrl` are NOT supported — so this does NOT inherit GeminiProjector's
 *     remote mapping.</description>
 *     <dependencies>JsonMcpProjector</dependencies>
 *     <constraints>Managed keys only; unparseable file → write refused.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services\Hub;

class AntigravityProjector extends JsonMcpProjector {

    public function agentId(): string { return 'antigravity-cli'; }
    public function relPath(): string { return '.gemini/antigravity-cli/mcp_config.json'; }
    public function label(): string   { return 'Antigravity CLI'; }

    protected function vendorValue(array $def): array {
        $transport = $def['transport'] ?? 'stdio';
        if ($transport === 'stdio') return $this->stdioShape($def);
        // sse / websocket / http remote — Antigravity uses `serverUrl`, NOT url/httpUrl.
        return ['serverUrl' => (string)($def['url'] ?? '')];
    }
}
