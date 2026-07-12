<?php
/**
 * <module_context>
 *     <name>FilePathConventionProjector</name>
 *     <description>Always-on "file-path convention" managed block (see
 *     docs/specs/AGENT_FILE_PATH_CONVENTION.md). Nudges every agent to always
 *     refer to a workspace file by its workspace-relative path (root files
 *     prefixed with `./`) so the terminal path-link feature
 *     (docs/specs/TMUX_PATH_LINKS.md) can linkify it — bare filenames are
 *     deliberately never linkified (too many prose false positives). Reuses
 *     InstructionProjector's fence machinery but under a DISTINCT fence
 *     (`aicli-file-paths`, NOT `aicli-hub`) and a DISTINCT ledger key
 *     (`aicli-file-paths-fence`), so it coexists independently of the
 *     Config-Hub global-instruction fence in the SAME file. Unlike the base
 *     class, desired() ignores its input entirely and always returns the one
 *     constant guidance block — this is policy, not user-configurable
 *     content, so it is projected unconditionally (see
 *     HubProjector::policyInstructionVendors()/projectPolicy()), never gated
 *     by instructions_enabledFor.</description>
 *     <dependencies>InstructionProjector, CodexProjector (fence helpers)</dependencies>
 *     <constraints>Idempotent: projecting twice yields a byte-identical file.
 *     PHP `self::CONST` binds to the DEFINING class, not the runtime class —
 *     current()/write() are overridden here (not merely inherited) so their
 *     `self::FENCE_*` references resolve to THIS class's fence/key constants
 *     instead of the base's `aicli-hub` ones. Never logs content (it's a
 *     fixed constant, but keep the discipline consistent with the base).</constraints>
 * </module_context>
 */

namespace AICliAgents\Services\Hub;

class FilePathConventionProjector extends InstructionProjector {

    // Distinct fence + ledger key from the base class's aicli-hub fence —
    // these REDECLARE (not extend) the parent's same-named constants, which
    // PHP permits for class constants. Any method that must use these (rather
    // than the base's aicli-hub ones) has to be overridden below: self::FOO
    // resolves at the class where the *method body* is written, so simply
    // inheriting current()/write() from InstructionProjector would silently
    // keep reading/writing the aicli-hub fence.
    const FENCE_KEY   = 'aicli-file-paths-fence';
    const FENCE_OPEN  = '<!-- >>> aicli-file-paths managed — do not edit inside >>> -->';
    const FENCE_CLOSE = '<!-- <<< aicli-file-paths <<< -->';

    /**
     * DISTINCT ledger/state bookkeeping key from the co-located instruction
     * projector's ledgerKey() — both write into the SAME physical file
     * (absPath() still derives purely from relPath(), unchanged, so both
     * fences really do land in e.g. ~/.claude/CLAUDE.md) but each MUST own
     * a SEPARATE $state['projections'][...] ledger entry. HubProjector's
     * reconcileVendor() evicts any managed key its OWN current() call can't
     * see from the SHARED per-file entry's managedKeys/lastProjectedHash on
     * every reconcile — so two projectors reconciling one shared ledger
     * entry would repeatedly strip each other's fence out of the bookkeeping.
     * Traced consequence: a later legitimate hub-content change on the OTHER
     * fence would then look like a base-hash-less "unmanaged_conflict" drift
     * instead of a clean update, because the base hash it needs was just
     * evicted by this projector's own reconcile pass. Suffixing the ledger
     * key gives this projector its own bookkeeping row while still reading/
     * writing the identical on-disk file.
     */
    public function ledgerKey(): string {
        return parent::ledgerKey() . '#aicli-file-paths';
    }

    /**
     * The constant guidance body (single-sourced here; FilePathConventionKiloProjector
     * reuses it verbatim for its fence-free dedicated file).
     */
    const BODY = <<<'MD'
**Referring to files in this workspace**
Always write a file's **workspace-relative path**, never a bare name, so the
Unraid terminal can make it a clickable link that opens in Unraid's editor:
- Root-level file → prefix with `./` (e.g. `./README.md`, `./package.json`).
- File in a subdirectory → relative path (e.g. `docs/specs/feature.md`,
  `src/index.ts`).
This applies to casual mentions too, not only files you create for the user.
MD;

    /**
     * ALWAYS returns the constant block under the policy fence key — the
     * $servers argument (here, ['content' => ...]) is ignored entirely. This
     * is an always-on policy block, unlike the base class's content-gated
     * instruction fence, so it never returns [] (never removed by projection).
     */
    public function desired(array $servers): array {
        return [self::FENCE_KEY => self::FENCE_OPEN . "\n" . self::BODY . "\n" . self::FENCE_CLOSE];
    }

    /** Overridden so self::FENCE_* resolve to THIS class's markers (see class docblock). */
    public function current(string $file, array $keys): array {
        if (!in_array(self::FENCE_KEY, $keys, true) || !is_file($file)) return [];
        $block = CodexProjector::extractFence((string)@file_get_contents($file), self::FENCE_OPEN, self::FENCE_CLOSE);
        return $block === null ? [] : [self::FENCE_KEY => $block];
    }

    /** Overridden so self::FENCE_* resolve to THIS class's markers (see class docblock). */
    public function write(string $file, array $set, array $remove): bool {
        $raw = is_file($file) ? (string)@file_get_contents($file) : '';
        if (array_key_exists(self::FENCE_KEY, $set)) {
            $raw = CodexProjector::replaceOrAppendFence($raw, (string)$set[self::FENCE_KEY], self::FENCE_OPEN, self::FENCE_CLOSE);
        } elseif (in_array(self::FENCE_KEY, $remove, true)) {
            $raw = CodexProjector::stripFence($raw, self::FENCE_OPEN, self::FENCE_CLOSE);
        } else {
            return true; // nothing to do
        }
        return $this->atomicWrite($file, $raw);
    }
}
