<?php
/**
 * <module_context>
 *     <name>FilePathConventionKiloProjector</name>
 *     <description>Kilo Code variant of the always-on "file-path convention"
 *     policy block (docs/specs/AGENT_FILE_PATH_CONVENTION.md). Kilo has no
 *     shared fenced instruction file (see KiloInstructionProjector) — it
 *     auto-discovers every markdown file in ~/.kilo/rules/ as a global
 *     instruction, so this owns a SECOND dedicated file there,
 *     ~/.kilo/rules/aicli-file-paths.md, written WHOLE (no HTML-comment
 *     fence — the hub owns the entire file), distinct from
 *     ~/.kilo/rules/aicli-hub-global.md (the hub-instructions dedicated
 *     file). The body content is single-sourced from
 *     FilePathConventionProjector::BODY so both variants stay in
 *     sync.</description>
 *     <dependencies>FilePathConventionProjector (BODY constant), KiloInstructionProjector (pattern)</dependencies>
 *     <constraints>Dedicated-file, fence-free: desired() ALWAYS returns the
 *     constant body under the managed key, ignoring $servers. Never
 *     logs content.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services\Hub;

class FilePathConventionKiloProjector extends FilePathConventionProjector {

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
     * prune ~/.kilo/rules ONLY if now-empty — @rmdir refuses a dir that still
     * holds the user's own rule files (or the sibling aicli-hub-global.md),
     * so their content is never removed.
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
