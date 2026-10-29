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
    /** `<project>/opencode.json` (https://opencode.ai/v2/docs/config/); XDG_CONFIG_HOME moves only the global file. */
    public function projectRelPath(): ?string { return 'opencode.json'; }

    protected function containerPath(): array { return ['mcp', 'servers']; }

    /**
     * #832: never write through a link that leads out of the OpenCode 2 folder. On prod02 a
     * hand-made link pointed this file at the OpenCode 1 config, so a write would put
     * `mcp.servers` into the OpenCode 1 file, which OpenCode 1 cannot read.
     */
    public function write(string $file, array $set, array $remove): bool {
        if (self::foreignLinkReason($file) !== null) return false;
        return parent::write($file, $set, $remove);
    }

    /** Why $file may not be written (it is a link out of the OpenCode 2 folder), or null when it may. */
    public static function foreignLinkReason(string $file): ?string {
        $rel = '/' . self::CONFIG_DIR . '/opencode.json';
        if (substr($file, -strlen($rel)) !== $rel || !is_link($file)) return null;
        $root = realpath(substr($file, 0, -strlen($rel)) . '/' . self::XDG_ROOT);
        $target = realpath($file);
        if ($root !== false && $target !== false && strpos($target, $root . '/') === 0) return null;
        return "$file is a link to '" . (string)@readlink($file) . "', outside the OpenCode 2 folder. "
            . 'OpenCode 2 keeps its MCP servers in a different shape from OpenCode 1, so the Config Hub does not write through it. '
            . 'Remove the link; the Config Hub then makes the OpenCode 2 file again';
    }
}
