<?php
/**
 * <module_context>
 *     <name>OpencodeHookProjector</name>
 *     <description>Share-filesystem guard, layer 3 for OpenCode (Forgejo #972,
 *     docs/specs/SHFS_RECURSIVE_READ_GUARD.md). The hub owns ONE whole file,
 *     ~/.config/opencode/plugins/aicli-shfs-guard.js, a plugin whose `tool.execute.before`
 *     handler sends {tool, args, cwd} to `php <plugin src>/scripts/aicli-guard-hook.php
 *     --agent=opencode` and throws the refusal text when the checker prints {"deny":true,"reason":...}.
 *     It does nothing unless AICLI_GUARD_HOOKS=1, and it allows the call on any error.
 *     Official docs (verified 2026-11-14): https://opencode.ai/docs/plugins/ (global folder
 *     ~/.config/opencode/plugins/, loads .js and .ts files) and https://opencode.ai/v1/docs/plugins/
 *     (export `async ({ project, client, $, directory, worktree }) => ({...hooks})`,
 *     `"tool.execute.before": async (input, output)` with input.tool and output.args, a throw blocks the call).
 *     V1 plugins do not run in OpenCode 2: see Opencode2HookProjector for its directory plugin.</description>
 *     <dependencies>VendorProjector, AgentRelayService::PLUGIN_SRC</dependencies>
 *     <constraints>Whole-file ownership, like a rules file: a missing file is restored, an edit is drift.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services\Hub;

class OpencodeHookProjector extends VendorProjector {

    const KEY = 'aicli-shfs-guard.js';

    /** Plugin text. The placeholder is replaced by the JSON string of the checker path. */
    const TEMPLATE = <<<'JS'
// Managed by the AI CLI Agents plugin (Config Hub). Do not edit: the Hub rewrites this file.
// Share-filesystem guard, layer 3. Docs: https://opencode.ai/docs/plugins/
import { spawnSync } from "node:child_process"

const CHECKER = __CHECKER__

export const AicliShfsGuard = async () => ({
  "tool.execute.before": async (input, output) => {
    if (process.env.AICLI_GUARD_HOOKS !== "1") return
    let reason = null
    try {
      const run = spawnSync("php", [CHECKER, "--agent=opencode"], {
        input: JSON.stringify({ tool: input.tool, args: output.args, cwd: process.cwd() }),
        encoding: "utf8",
        timeout: 5000,
      })
      const text = String(run.stdout || "").trim()
      if (text) {
        const verdict = JSON.parse(text)
        if (verdict && verdict.deny === true) reason = String(verdict.reason || "Refused by the share filesystem guard.")
      }
    } catch (e) {
      reason = null
    }
    if (reason !== null) throw new Error(reason)
  },
})
JS;

    /** @var string */
    private $srcDir;

    public function __construct(?string $srcDir = null) {
        $this->srcDir = $srcDir ?? \AICliAgents\Services\AgentRelayService::PLUGIN_SRC;
    }

    public function agentId(): string { return 'opencode'; }
    public function relPath(): string { return '.config/opencode/plugins/' . static::KEY; }
    public function label(): string   { return 'OpenCode (share guard hook)'; }
    public function projectRelPath(): ?string { return null; }

    public function checkerPath(): string { return rtrim($this->srcDir, '/') . '/scripts/aicli-guard-hook.php'; }

    /** The whole file text. */
    public function fileText(): string {
        $path = json_encode($this->checkerPath(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return str_replace('__CHECKER__', (string)$path, static::TEMPLATE) . "\n";
    }

    public function desired(array $servers): array {
        return [static::KEY => $this->fileText()];
    }

    public function current(string $file, array $keys): array {
        if (!in_array(static::KEY, $keys, true) || !is_file($file)) return [];
        return [static::KEY => (string)@file_get_contents($file)];
    }

    public function write(string $file, array $set, array $remove): bool {
        if (array_key_exists(static::KEY, $set)) return $this->atomicWrite($file, (string)$set[static::KEY]);
        if (in_array(static::KEY, $remove, true)) {
            if (is_file($file) && !@unlink($file)) return false;
            @rmdir(dirname($file)); // succeeds only when empty: the user's own plugins keep the folder
        }
        return true;
    }
}
