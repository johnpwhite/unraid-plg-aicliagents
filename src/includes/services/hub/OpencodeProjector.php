<?php
/**
 * <module_context>
 *     <name>OpencodeProjector</name>
 *     <description>OpenCode MCP projection. CORRECTED 2026-06: OpenCode reads
 *     ~/.config/opencode/opencode.json with a top-level "mcp" object whose entries
 *     are {type:"local", command:[bin, ...args], environment:{...}} / {type:"remote",
 *     url} — NOT ~/.opencode.json with "mcpServers". (Verified from the live
 *     config-load log + @opencode-ai/sdk type defs on the test box; the old path/key
 *     wrote a file OpenCode never reads.)</description>
 *     <dependencies>OpencodeStyleMcpProjector</dependencies>
 *     <constraints>Managed keys only; unparseable file → write refused.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services\Hub;

class OpencodeProjector extends OpencodeStyleMcpProjector {

    public function agentId(): string { return 'opencode'; }
    public function relPath(): string { return '.config/opencode/opencode.json'; }
    public function label(): string   { return 'OpenCode'; }
}
