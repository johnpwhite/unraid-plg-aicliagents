<?php
/**
 * <module_context>
 *     <name>Opencode2HookProjector</name>
 *     <description>Share-filesystem guard, layer 3 for OpenCode 2 (Forgejo #972,
 *     docs/specs/SHFS_RECURSIVE_READ_GUARD.md). The hub owns ONE whole file, the directory plugin
 *     `<opencode2 global config dir>/plugins/aicli-shfs-guard/index.js`. The global config dir is
 *     the XDG root of Opencode2Projector plus `/opencode` (Opencode2Projector::CONFIG_DIR).
 *     The plugin is a plain default-export object `{ id, setup(ctx) }` with no package import. In
 *     setup it registers `ctx.tool.hook("execute.before", ...)`; the hook sends {tool, args, cwd} to
 *     `php <plugin src>/scripts/aicli-guard-hook.php --agent=opencode` and throws the refusal text
 *     when the checker prints {"deny":true,"reason":...}. It does nothing unless AICLI_GUARD_HOOKS=1 and
 *     it allows the call on any error. `shell` is sent to the checker as `bash`, the name its
 *     OpenCode contract knows.
 *     Official docs (read 2026-11-14):
 *     - https://opencode.ai/docs/build/plugins/migrate-v1 : "V1 plugin implementations do not run in V2."
 *       The V2 port of a blocking hook is
 *       `await ctx.tool.hook("execute.before", (event) => { const input = event.input as {...}; if (...) throw new Error("...") })`;
 *       "Configured local paths must name directories"; V2 reads the default export's `id` and `setup()`.
 *     - https://opencode.ai/docs/cli/plugins : "OpenCode also discovers plugins under the global config
 *       directory and project `.opencode` directories", example `<global-config>/plugins/status/index.ts`.
 *     - https://opencode.ai/docs/build/plugins : local plugin directories load `server` (else `index`)
 *       with `.ts`, `.tsx` or `.js`.
 *     - https://opencode.ai/docs/permissions : V2 names the shell action `shell` (V1: `bash`); the
 *       actions include `grep` and `glob`.
 *     Tool ids are NOT documented, so the plugin matches shell, bash, grep, glob and list, and reads
 *     the command or path from the common field names.</description>
 *     <dependencies>OpencodeHookProjector, Opencode2Projector</dependencies>
 *     <constraints>Whole-file ownership: a missing file is restored, an edit is drift.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services\Hub;

class Opencode2HookProjector extends OpencodeHookProjector {

    const KEY = 'aicli-shfs-guard/index.js';

    /** Plugin text. The placeholder is replaced by the JSON string of the checker path. */
    const TEMPLATE = <<<'JS'
// Managed by the AI CLI Agents plugin (Config Hub). Do not edit: the Hub rewrites this file.
// Share-filesystem guard, layer 3, OpenCode 2.
// Docs: https://opencode.ai/docs/build/plugins/migrate-v1 and https://opencode.ai/docs/cli/plugins
import { spawnSync } from "node:child_process"

const CHECKER = __CHECKER__
const GUARDED = new Set(["shell", "bash", "grep", "glob", "list"])

export default {
  id: "aicli-shfs-guard",
  async setup(ctx) {
    if (process.env.AICLI_GUARD_HOOKS !== "1") return
    await ctx.tool.hook("execute.before", (event) => {
      const tool = String(event && event.tool ? event.tool : "").toLowerCase()
      if (!GUARDED.has(tool)) return
      let reason = null
      try {
        const args = event.input && typeof event.input === "object" ? event.input : {}
        const run = spawnSync("php", [CHECKER, "--agent=opencode"], {
          input: JSON.stringify({ tool: tool === "shell" ? "bash" : tool, args, cwd: process.cwd() }),
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
    })
  },
}
JS;

    public function agentId(): string { return 'opencode2'; }
    public function relPath(): string { return Opencode2Projector::CONFIG_DIR . '/plugins/' . static::KEY; }
    public function label(): string   { return 'OpenCode 2 (share guard hook)'; }

    public function write(string $file, array $set, array $remove): bool {
        $ok = parent::write($file, $set, $remove);
        if ($ok && in_array(static::KEY, $remove, true)) @rmdir(dirname($file, 2)); // the plugins folder, only when empty
        return $ok;
    }
}
