<?php
/**
 * <module_context>
 *     <name>RulesFileFilePathProjector</name>
 *     <description>Dedicated rules-file variant of the always-on "file-path
 *     convention" policy block (docs/specs/AGENT_FILE_PATH_CONVENTION.md), for
 *     agents that auto-discover a rules directory instead of reading one shared
 *     instruction file:
 *       - Kilo Code  → ~/.kilo/rules/aicli-file-paths.md
 *       - Claude Code → ~/.claude/rules/aicli-file-paths.md
 *     Written WHOLE (no HTML-comment fence — the hub owns the entire file),
 *     distinct from the sibling aicli-hub-global.md (the hub-instructions file)
 *     and aicli-relay.md (the Relay guidance file) in the same directory. The
 *     body is single-sourced from FilePathConventionProjector::BODY so the fenced
 *     variant (used by single-instruction-file agents) and this rules-file
 *     variant stay in sync.</description>
 *     <dependencies>FilePathConventionProjector (BODY constant), RulesFileInstructionProjector (pattern)</dependencies>
 *     <constraints>Dedicated-file, fence-free: desired() ALWAYS returns the
 *     constant body under the managed key, ignoring $servers. Never
 *     logs content.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services\Hub;

class RulesFileFilePathProjector extends FilePathConventionProjector {

    /**
     * Raw constant body under the single managed key — NO fence (the hub
     * owns the whole dedicated file), and $servers is ignored entirely
     * (always-on policy, same contract as the fenced variant's desired()).
     */
    public function desired(array $servers): array {
        return [self::FENCE_KEY => rtrim(self::BODY, "\n") . "\n"];
    }

    /** Whole-file content as the managed key (absent file → key omitted). */
    public function current(string $file, array $keys): array {
        if (!in_array(self::FENCE_KEY, $keys, true) || !is_file($file)) return [];
        return [self::FENCE_KEY => (string)@file_get_contents($file)];
    }

    /**
     * Write the whole dedicated file (set) or delete it (remove). On delete,
     * prune the rules dir ONLY if now-empty — @rmdir refuses a dir that still
     * holds the user's own rule files (or our sibling aicli-*.md files), so
     * their content is never removed.
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
