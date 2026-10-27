<?php
/**
 * <module_context>
 *     <name>Opencode2Projector</name>
 *     <description>OpenCode 2 MCP projection (Forgejo #822, docs/specs/OPENCODE_V2_AGENT.md).
 *     OpenCode 2 keeps the servers one level deeper than OpenCode 1: the config file has
 *     `mcp.servers.<name>`, not `mcp.<name>` (https://opencode.ai/v2/docs/mcp-servers/ and
 *     https://opencode.ai/v2/docs/config/). The entry shapes are the same as in OpenCode 1:
 *     {type:"local", command:[bin, ...args], environment} and {type:"remote", url, headers}.
 *     The plugin starts OpenCode 2 with XDG_CONFIG_HOME set to a folder of its own, so
 *     the file is `opencode.json` in that folder and OpenCode 1 never reads it.</description>
 *     <dependencies>OpencodeStyleMcpProjector</dependencies>
 *     <constraints>Managed keys only; an unparseable file, or a `mcp` or `servers` value that is
 *     not an object, is never rewritten.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services\Hub;

class Opencode2Projector extends OpencodeStyleMcpProjector {

    /** Home-relative root of every OpenCode 2 folder (data, state, config). Launch script: aicli-shell.sh. */
    const XDG_ROOT = '.local/share/opencode2-xdg';

    /** Home-relative folder that holds the global OpenCode 2 config (XDG_CONFIG_HOME/opencode). */
    const CONFIG_DIR = self::XDG_ROOT . '/config/opencode';

    public function agentId(): string { return 'opencode2'; }
    public function relPath(): string { return self::CONFIG_DIR . '/opencode.json'; }
    public function label(): string   { return 'OpenCode 2'; }

    protected function containerPath(): array { return ['mcp', 'servers']; }
}
