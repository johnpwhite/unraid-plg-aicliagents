<?php
/**
 * <module_context>
 *     <name>QwenProjector</name>
 *     <description>Qwen Code MCP projection into ~/.qwen/settings.json mcpServers
 *     (OP #1362 / H-01). Qwen Code is a Gemini CLI fork — identical mcpServers shape
 *     (stdio {command,args,env}; sse `url`; streamable HTTP `httpUrl`).</description>
 *     <dependencies>GeminiProjector</dependencies>
 *     <constraints>Managed keys only; unparseable file → write refused.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services\Hub;

class QwenProjector extends GeminiProjector {

    public function agentId(): string { return 'qwen-code'; }
    public function relPath(): string { return '.qwen/settings.json'; }
    public function label(): string   { return 'Qwen Code'; }
}
