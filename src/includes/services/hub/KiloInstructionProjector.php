<?php
/**
 * <module_context>
 *     <name>KiloInstructionProjector</name>
 *     <description>Kilo Code global-instruction projection (config-surface audit 2026-06).
 *     Kilo has NO single shared instruction file with a fence (unlike CLAUDE.md/GEMINI.md):
 *     it AUTO-DISCOVERS every markdown file in ~/.kilo/rules/ as a global instruction —
 *     verified live against the installed binary via `kilo debug config` (it resolves
 *     ~/.kilo/rules/*.md into the `instructions` array with NO kilo.jsonc entry needed,
 *     and `kilo config check` reports no warnings). So the hub owns ONE dedicated file,
 *     ~/.kilo/rules/aicli-hub-global.md, and writes the canonical instruction content into
 *     it whole — no comment fence. The user's OTHER rule files in ~/.kilo/rules/ are never
 *     touched (different filenames); a hand-edit to OUR file surfaces as drift like any
 *     other managed key, and untargeting removes only our file (pruning the dir only when
 *     it is left empty).</description>
 *     <dependencies>InstructionProjector (servedAgentIds/label plumbing), VendorProjector</dependencies>
 *     <constraints>Dedicated-file, fence-free: desired() returns the raw content under the
 *     single managed key; the hub owns the whole file. Never logs instruction content.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services\Hub;

class KiloInstructionProjector extends InstructionProjector {

    /**
     * Raw content under the single managed key — NO fence (the hub owns the whole
     * dedicated file). Empty/whitespace content → [] (the file is removed next reconcile).
     */
    public function desired(array $servers): array {
        $content = (string)($servers['content'] ?? '');
        if (trim($content) === '') return [];
        return [self::FENCE_KEY => rtrim($content, "\n") . "\n"];
    }

    /** Whole-file content as the managed key (absent file → key omitted). */
    public function current(string $file, array $keys): array {
        if (!in_array(self::FENCE_KEY, $keys, true) || !is_file($file)) return [];
        return [self::FENCE_KEY => (string)@file_get_contents($file)];
    }

    /**
     * Write the whole dedicated file (set) or delete it (remove). On delete, prune
     * ~/.kilo/rules ONLY if now-empty — @rmdir refuses a dir that still holds the
     * user's own rule files, so their content is never removed.
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
