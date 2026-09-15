<?php
/**
 * <module_context>
 *     <name>RulesFileInstructionProjector</name>
 *     <description>Global-instruction projection for agents that AUTO-DISCOVER a
 *     rules DIRECTORY (every markdown file in it is loaded as a global
 *     instruction), instead of reading one shared instruction file. Two agents
 *     use this:
 *       - Kilo Code  → ~/.kilo/rules/aicli-hub-global.md   (writes the content itself)
 *       - Claude Code → ~/.claude/rules/aicli-hub-global.md (writes the @import line)
 *     Both were verified against the agent's own tooling/docs: Kilo resolves
 *     every ~/.kilo/rules/*.md via `kilo debug config` with no kilo.jsonc entry;
 *     Claude Code natively loads every ~/.claude/rules/*.md at session start
 *     (docs.claude.com "Organize rules with .claude/rules/"). The hub owns ONE
 *     dedicated file, written WHOLE with NO comment fence. The user's OTHER rule
 *     files in the same directory are never touched (different filenames); a
 *     hand-edit to OUR file surfaces as drift like any other managed key, and
 *     untargeting removes only our file (pruning the dir only when it is left
 *     empty). Whether the body is the content or the @import line follows the
 *     constructor's $useImport flag (honoured via usesImport()) — Claude keeps
 *     its content single-sourced from ~/.aicli/hub/instructions/global.md, Kilo
 *     copies the content in.</description>
 *     <dependencies>InstructionProjector (servedAgentIds/label/usesImport plumbing), VendorProjector</dependencies>
 *     <constraints>Dedicated-file, fence-free: desired() returns the raw body under
 *     the single managed key; the hub owns the whole file. Never logs instruction content.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services\Hub;

class RulesFileInstructionProjector extends InstructionProjector {

    /**
     * Raw body under the single managed key — NO fence (the hub owns the whole
     * dedicated file). Claude ($useImport=true) → the native @import line, so its
     * content stays single-sourced; Kilo ($useImport=false) → the content itself.
     * Empty/whitespace content → [] (the file is removed next reconcile).
     */
    public function desired(array $servers): array {
        $content = (string)($servers['content'] ?? '');
        if (trim($content) === '') return [];
        $body = $this->usesImport() ? self::CLAUDE_IMPORT : rtrim($content, "\n");
        return [self::FENCE_KEY => $body . "\n"];
    }

    /** Whole-file content as the managed key (absent file → key omitted). */
    public function current(string $file, array $keys): array {
        if (!in_array(self::FENCE_KEY, $keys, true) || !is_file($file)) return [];
        return [self::FENCE_KEY => (string)@file_get_contents($file)];
    }

    /**
     * Write the whole dedicated file (set) or delete it (remove). On delete, prune
     * the rules dir ONLY if now-empty — @rmdir refuses a dir that still holds the
     * user's own rule files (or our sibling aicli-*.md files), so their content is
     * never removed.
     */
    public function write(string $file, array $set, array $remove): bool {
        if (array_key_exists(self::FENCE_KEY, $set)) {
            return $this->atomicWrite($file, (string)$set[self::FENCE_KEY]);
        }
        if (in_array(self::FENCE_KEY, $remove, true)) {
            if (is_file($file) && !@unlink($file)) return false;
            @rmdir(dirname($file));
            return true;
        }
        return true;
    }
}
