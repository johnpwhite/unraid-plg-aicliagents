<?php
/**
 * <module_context>
 *     <name>ClaudeProjector</name>
 *     <description>Claude Code user-scope MCP projection into ~/.claude.json (OP #1362 / H-01).
 *     CAREFUL: that file mixes OAuth/account state with user settings. This projector
 *     read-modify-writes ONLY mcpServers.&lt;managedName&gt; keys; every other key — OAuth
 *     tokens, projects, theme, … — passes through the stdClass round-trip untouched
 *     (values byte-identical; whitespace/key order may normalize on first touch only).
 *     The file is NEVER backed up / versioned by the hub (credential-leak hazard).</description>
 *     <dependencies>JsonMcpProjector</dependencies>
 *     <constraints>Never log file content. Unparseable file → write refused.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services\Hub;

class ClaudeProjector extends JsonMcpProjector {

    public function agentId(): string { return 'claude-code'; }
    public function relPath(): string { return '.claude.json'; }
    public function label(): string   { return 'Claude Code'; }

    /** stdio: {type, command, args?, env?}; http/sse: {type, url}. */
    protected function vendorValue(array $def): array {
        $transport = $def['transport'] ?? 'stdio';
        if ($transport === 'stdio') {
            return ['type' => 'stdio'] + $this->stdioShape($def);
        }
        return ['type' => $transport, 'url' => (string)($def['url'] ?? '')];
    }
}
