<?php
/**
 * <module_context>
 *     <name>KilocodeProjector</name>
 *     <description>Kilo Code MCP projection into ~/.config/kilo/kilo.jsonc. Kilo
 *     shares OpenCode's MCP shape (verified from @kilocode/sdk McpLocalConfig /
 *     McpRemoteConfig type defs + the live `kilo debug paths` config dir on the test
 *     box): a top-level "mcp" object with {type:"local", command:[bin, ...args],
 *     environment:{...}} / {type:"remote", url} entries.</description>
 *     <dependencies>OpencodeStyleMcpProjector</dependencies>
 *     <constraints>Managed keys only; unparseable file → write refused. The .jsonc
 *     file is normalised to comment-free JSON on first managed touch (still valid
 *     JSONC); inline user comments are not preserved — documented limitation.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services\Hub;

class KilocodeProjector extends OpencodeStyleMcpProjector {

    public function agentId(): string { return 'kilocode'; }
    public function relPath(): string { return '.config/kilo/kilo.jsonc'; }
    public function label(): string   { return 'Kilo Code'; }
}
