<?php
/**
 * <module_context>
 *     <name>ClaudeHookProjector</name>
 *     <description>Share-filesystem guard, layer 3 for Claude Code (Forgejo #972,
 *     docs/specs/SHFS_RECURSIVE_READ_GUARD.md). Read-modify-write of the user-level
 *     ~/.claude/settings.json. It owns ONE matcher group in hooks.PreToolUse, found by the
 *     command text "aicli-guard-hook.php": matcher "Bash|Grep|Glob", one command hook that
 *     runs `php <plugin src>/scripts/aicli-guard-hook.php --agent=claude`. Every other key and
 *     every other hook entry (for example the user's own fuse-guard) passes through the
 *     stdClass round-trip untouched. Removal takes out only the managed group, and the empty
 *     PreToolUse array or empty hooks object that it leaves.
 *     Official docs (verified 2026-11-14): https://code.claude.com/docs/en/hooks
 *     (user file ~/.claude/settings.json; hooks.PreToolUse[] = {matcher, hooks:[{type:"command", command}]};
 *     stdin has tool_name, tool_input, cwd) and https://code.claude.com/docs/en/tools-reference
 *     (tool names Bash, Grep, Glob).</description>
 *     <dependencies>VendorProjector, AgentRelayService::PLUGIN_SRC</dependencies>
 *     <constraints>Never rewrites an unparseable file. A hooks or PreToolUse value of the wrong
 *     type is never replaced (write returns false).</constraints>
 * </module_context>
 */

namespace AICliAgents\Services\Hub;

class ClaudeHookProjector extends VendorProjector {

    /** The one managed key. */
    const KEY = 'hooks.PreToolUse.aicli-guard-hook';
    /** Text that marks the managed hook command. */
    const MARKER = 'aicli-guard-hook.php';
    const MATCHER = 'Bash|Grep|Glob';

    /** @var string */
    private $srcDir;

    public function __construct(?string $srcDir = null) {
        $this->srcDir = $srcDir ?? \AICliAgents\Services\AgentRelayService::PLUGIN_SRC;
    }

    public function agentId(): string { return 'claude-code'; }
    public function relPath(): string { return '.claude/settings.json'; }
    public function label(): string   { return 'Claude Code (share guard hook)'; }
    /** A hook in the project settings is the user's own business: the plugin writes the user file only. */
    public function projectRelPath(): ?string { return null; }

    /** The hook command. */
    public function command(): string {
        return 'php ' . rtrim($this->srcDir, '/') . '/scripts/' . self::MARKER . ' --agent=claude';
    }

    /** The server list is ignored: the guard does not depend on any MCP server. */
    public function desired(array $servers): array {
        return [self::KEY => [
            'matcher' => self::MATCHER,
            'hooks'   => [['type' => 'command', 'command' => $this->command()]],
        ]];
    }

    public function current(string $file, array $keys): array {
        if (!in_array(self::KEY, $keys, true)) return [];
        $data = $this->decode($file);
        if ($data === null) return [];
        $hooks = $data->hooks ?? null;
        $list = ($hooks instanceof \stdClass) ? ($hooks->PreToolUse ?? null) : null;
        if (!is_array($list)) return [];
        $i = self::findIndex($list);
        return $i === null ? [] : [self::KEY => self::canonicalize($list[$i])];
    }

    public function write(string $file, array $set, array $remove): bool {
        $data = $this->decode($file);
        if ($data === null) {
            if (is_file($file) && trim((string)@file_get_contents($file)) !== '') return false; // unparseable: never clobber
            $data = new \stdClass();
        }
        if (array_key_exists(self::KEY, $set)) {
            if (!isset($data->hooks)) $data->hooks = new \stdClass();
            if (!($data->hooks instanceof \stdClass)) return false;
            if (!isset($data->hooks->PreToolUse)) $data->hooks->PreToolUse = [];
            if (!is_array($data->hooks->PreToolUse)) return false;
            $group = json_decode(json_encode(self::canonicalize($set[self::KEY]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $i = self::findIndex($data->hooks->PreToolUse);
            if ($i === null) $data->hooks->PreToolUse[] = $group;
            else $data->hooks->PreToolUse[$i] = $group;
        } elseif (in_array(self::KEY, $remove, true)) {
            $hooks = $data->hooks ?? null;
            if ($hooks instanceof \stdClass && is_array($hooks->PreToolUse ?? null)) {
                $i = self::findIndex($hooks->PreToolUse);
                if ($i !== null) {
                    array_splice($hooks->PreToolUse, $i, 1);
                    if (empty($hooks->PreToolUse)) unset($hooks->PreToolUse);
                    if (!get_object_vars($hooks)) unset($data->hooks);
                }
            }
        }
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) return false;
        return $this->atomicWrite($file, $json . "\n");
    }

    /** Index of the matcher group whose hooks hold the managed command, or null. */
    private static function findIndex(array $list): ?int {
        foreach ($list as $i => $group) {
            if (!($group instanceof \stdClass) || !is_array($group->hooks ?? null)) continue;
            foreach ($group->hooks as $h) {
                if ($h instanceof \stdClass && is_string($h->command ?? null) && strpos($h->command, self::MARKER) !== false) return (int)$i;
            }
        }
        return null;
    }

    private function decode(string $file): ?\stdClass {
        if (!is_file($file)) return null;
        $raw = (string)@file_get_contents($file);
        if (trim($raw) === '') return null;
        $data = json_decode($raw);
        return ($data instanceof \stdClass) ? $data : null;
    }
}
