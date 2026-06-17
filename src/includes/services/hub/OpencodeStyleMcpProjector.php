<?php
/**
 * <module_context>
 *     <name>OpencodeStyleMcpProjector</name>
 *     <description>Shared base for agents that use the OpenCode/Kilo MCP config
 *     shape (verified 2026-06 from their SDK type defs + live config-load logs on
 *     the test box): a top-level "mcp" object (NOT "mcpServers"), and per-server
 *     entries shaped {type:"local", command:[bin, ...args], environment:{...}} for
 *     stdio and {type:"remote", url} for remote — i.e. command is a single ARRAY
 *     (bin + args merged) and env is keyed "environment". OpenCode reads
 *     ~/.config/opencode/opencode.json; Kilo reads ~/.config/kilo/kilo.jsonc.</description>
 *     <dependencies>JsonMcpProjector</dependencies>
 *     <constraints>Managed keys only; unparseable file → write refused. Inherits
 *     the never-clobber read-modify-write from JsonMcpProjector, overriding only the
 *     root key (topKey) and the per-entry shape (vendorValue). NOTE: writing a
 *     .jsonc file normalises it to comment-free JSON on first managed touch — valid
 *     JSONC, but inline user comments are not preserved (documented limitation).</constraints>
 * </module_context>
 */

namespace AICliAgents\Services\Hub;

abstract class OpencodeStyleMcpProjector extends JsonMcpProjector {

    protected function topKey(): string { return 'mcp'; }

    protected function vendorValue(array $def): array {
        $transport = $def['transport'] ?? 'stdio';
        if ($transport !== 'stdio') {
            return ['type' => 'remote', 'url' => (string)($def['url'] ?? '')];
        }
        // stdio: command is the binary + args, merged into a single array.
        $command = array_merge(
            [(string)($def['command'] ?? '')],
            array_values(array_map('strval', $def['args'] ?? []))
        );
        $v = ['type' => 'local', 'command' => $command];
        if (!empty($def['env'])) { $env = $def['env']; ksort($env); $v['environment'] = $env; }
        return $v;
    }
}
