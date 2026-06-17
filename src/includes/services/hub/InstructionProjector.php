<?php
/**
 * <module_context>
 *     <name>InstructionProjector</name>
 *     <description>Global instruction-file projection (OP #1363 / H-02, phase 2).
 *     Projects the ONE canonical doc hub/instructions/global.md into a vendor
 *     Markdown instruction file inside the agent home, inside an HTML-comment
 *     fence (`&lt;!-- &gt;&gt;&gt; aicli-hub managed — do not edit inside &gt;&gt;&gt; --&gt;` …
 *     `&lt;!-- &lt;&lt;&lt; aicli-hub &lt;&lt;&lt; --&gt;`). Claude Code gets a single native
 *     `@~/.aicli/hub/instructions/global.md` import line (content stays
 *     single-sourced); other vendors get a full block copy. One file may serve
 *     several agent ids (~/.codex/AGENTS.md is read by Codex AND Copilot CLI).
 *     Text outside the fence is never touched. Drift hashes the full fence
 *     content (single managed key per file) — same ledger model as MCP.</description>
 *     <dependencies>VendorProjector, CodexProjector (fence helpers)</dependencies>
 *     <constraints>Idempotent: projecting twice yields a byte-identical file.
 *     desired() input is ['content' =&gt; string], NOT an MCP server map.
 *     Never logs instruction content.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services\Hub;

class InstructionProjector extends VendorProjector {

    const FENCE_KEY   = 'aicli-hub-fence';
    const FENCE_OPEN  = '<!-- >>> aicli-hub managed — do not edit inside >>> -->';
    const FENCE_CLOSE = '<!-- <<< aicli-hub <<< -->';

    /**
     * Claude Code native import directive (audit H-02): resolves at session
     * start, so the fence never changes when global.md content changes. The
     * path is HOME-relative — the hub store lives at ~/.aicli inside the same
     * agent home the instruction file sits in.
     */
    const CLAUDE_IMPORT = '@~/.aicli/hub/instructions/global.md';

    /** @var string */
    private $id;
    /** @var string */
    private $rel;
    /** @var string */
    private $name;
    /** @var string[] */
    private $served;
    /** @var bool */
    private $useImport;

    /**
     * @param string   $agentId        primary agent id (used for ledger/drift records)
     * @param string   $relPath        instruction file, home-relative
     * @param string   $label          human label for the UI
     * @param string[] $servedAgentIds ALL agent ids that read this file
     * @param bool     $useImport      true → emit the Claude @import line instead of a block copy
     */
    public function __construct(string $agentId, string $relPath, string $label, array $servedAgentIds, bool $useImport) {
        $this->id = $agentId;
        $this->rel = $relPath;
        $this->name = $label;
        $this->served = array_values($servedAgentIds);
        $this->useImport = $useImport;
    }

    public function agentId(): string { return $this->id; }
    public function relPath(): string { return $this->rel; }
    public function label(): string   { return $this->name; }

    /** All agent ids that READ this file (e.g. AGENTS.md serves codex-cli + gh-copilot). */
    public function servedAgentIds(): array { return $this->served; }

    /**
     * Input shape is ['content' => string] — instruction projection has one
     * canonical doc, not per-server keys. Empty/whitespace content → []
     * (the fence is removed at the next reconcile).
     * @param array<string,mixed> $servers ['content' => string]
     * @return array<string,string>
     */
    public function desired(array $servers): array {
        $content = (string)($servers['content'] ?? '');
        if (trim($content) === '') return [];
        $body = $this->useImport ? self::CLAUDE_IMPORT : rtrim($content, "\n");
        return [self::FENCE_KEY => self::FENCE_OPEN . "\n" . $body . "\n" . self::FENCE_CLOSE];
    }

    public function current(string $file, array $keys): array {
        if (!in_array(self::FENCE_KEY, $keys, true) || !is_file($file)) return [];
        $block = CodexProjector::extractFence((string)@file_get_contents($file), self::FENCE_OPEN, self::FENCE_CLOSE);
        return $block === null ? [] : [self::FENCE_KEY => $block];
    }

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
