<?php
/**
 * <module_context>
 *     <name>TreeProjector</name>
 *     <description>Mirrored-tree skill/command projection (OP #1364 / H-03, phase 3).
 *     Projects hub-store file trees (hub/skills/&lt;name&gt;/…, hub/commands/&lt;name&gt;.md)
 *     into ONE vendor directory inside the agent home (e.g. ~/.claude/skills,
 *     ~/.claude/commands, ~/.opencode/commands). MANAGED-TREE discipline: the managed
 *     unit is the individual projected file (ledger managedKeys = the per-vendor-dir
 *     managed-paths list); user files alongside are NEVER touched, a pre-existing user
 *     file at a managed path surfaces as unmanaged_conflict drift, and removals prune
 *     only directories the projection emptied (@rmdir — fails closed on user content).
 *     Drift hashes raw file bytes — same three-way ledger model as MCP/instructions.
 *     Adopt is refused for tree keys (HubProjector) — overwrite/release only.</description>
 *     <dependencies>VendorProjector</dependencies>
 *     <constraints>Stateless. desired() input is ['files' =&gt; [relPath =&gt; content]],
 *     NOT an MCP server map. Keys are defensively re-validated against traversal even
 *     though the store validates them at save time. Never logs file content.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services\Hub;

class TreeProjector extends VendorProjector {

    /** @var string */
    private $id;
    /** @var string */
    private $relDir;
    /** @var string */
    private $name;
    /** @var string */
    private $surfaceKind;
    /** @var string[] */
    private $served;

    /**
     * @param string   $agentId        primary AgentRegistry id (ledger/drift records)
     * @param string   $relDir         vendor directory, home-relative (e.g. '.claude/skills')
     * @param string   $label          human label for the UI / drift rows
     * @param string   $surface        'skills' | 'commands' — which hub tree feeds this dir
     * @param string[] $servedAgentIds ALL agent ids that READ this dir (default [$agentId]).
     *        A SHARED dir (e.g. ~/.agents/skills, read by Codex/Gemini/OpenCode/Copilot/
     *        Goose) lists every reader here — it is reconciled ONCE (one ledger key) and
     *        is desired when ANY served agent is surface-targeted, exactly like the
     *        InstructionProjector's multi-served fence. Un-targeting one reader does NOT
     *        remove files another reader still wants.
     */
    public function __construct(string $agentId, string $relDir, string $label, string $surface, array $servedAgentIds = []) {
        $this->id = $agentId;
        $this->relDir = $relDir;
        $this->name = $label;
        $this->surfaceKind = $surface;
        $this->served = empty($servedAgentIds) ? [$agentId] : array_values($servedAgentIds);
    }

    public function agentId(): string { return $this->id; }
    public function relPath(): string { return $this->relDir; }
    public function label(): string   { return $this->name; }

    /** Which hub tree feeds this vendor dir: 'skills' or 'commands'. */
    public function surface(): string { return $this->surfaceKind; }

    /** All agent ids that READ this dir (a shared dir lists every reader). */
    public function servedAgentIds(): array { return $this->served; }

    /**
     * Input shape is ['files' => [relPath => content]] — the orchestrator
     * derives the map from the hub store + the surface's target set. An
     * untargeted agent gets [] (managed copies removed at the next reconcile).
     * @param array<string,mixed> $servers ['files' => array<string,string>]
     * @return array<string,string>
     */
    public function desired(array $servers): array {
        $files = $this->transformFiles((array)($servers['files'] ?? []));
        $out = [];
        foreach ($files as $rel => $content) {
            if (self::safeRel((string)$rel) === null) continue; // defense in depth
            $out[(string)$rel] = (string)$content;
        }
        return $out;
    }

    /**
     * Map the CANONICAL hub file map (skills: '<name>/SKILL.md'=>bytes; commands:
     * '<name>.md'=>bytes) to this vendor's ON-DISK file map. Default = identity:
     * the vendor reads the hub format verbatim (true for every SKILL.md skills dir
     * and for the Markdown-command agents). Override to transpile — e.g. Gemini CLI
     * commands are TOML, so its projector remaps '<name>.md' → '<name>.toml' with the
     * markdown wrapped as the TOML `prompt` field. The transformed relPaths become the
     * managed keys, so current()/write()/drift all operate on the real on-disk files.
     * @param array<string,string> $files canonical relPath => content
     * @return array<string,string> on-disk relPath => content
     */
    protected function transformFiles(array $files): array { return $files; }

    /** @param string $file absolute vendor DIRECTORY path (managed keys are file paths inside it) */
    public function current(string $file, array $keys): array {
        $out = [];
        foreach ($keys as $key) {
            $rel = self::safeRel((string)$key);
            if ($rel === null) continue;
            $abs = rtrim($file, '/') . '/' . $rel;
            if (is_file($abs)) $out[(string)$key] = (string)@file_get_contents($abs);
        }
        return $out;
    }

    /**
     * Write/remove managed files inside the vendor dir. Each write is atomic;
     * removals unlink ONLY the managed path (the orchestrator guarantees the
     * on-disk copy is clean) and then prune now-empty parent dirs up to the
     * vendor root — @rmdir refuses non-empty dirs, so user files alongside
     * keep their directories. Returns false when any write fails (the ledger
     * is then not advanced; converged keys re-baseline on the next pass).
     */
    public function write(string $file, array $set, array $remove): bool {
        $root = rtrim($file, '/');
        $ok = true;
        foreach ($set as $key => $content) {
            $rel = self::safeRel((string)$key);
            if ($rel === null) { $ok = false; continue; }
            // #849: never write through a link (the vendor folder or a folder inside it).
            if ($this->linkReason($root, $rel) !== null) { $ok = false; continue; }
            if (!$this->atomicWrite("$root/$rel", (string)$content)) $ok = false;
        }
        foreach ($remove as $key) {
            $rel = self::safeRel((string)$key);
            if ($rel === null) continue;
            if ($this->linkReason($root, $rel) !== null) { $ok = false; continue; } // #849
            $abs = "$root/$rel";
            if (is_file($abs) && !@unlink($abs)) { $ok = false; continue; }
            $this->pruneEmptyDirs($root, dirname($abs));
        }
        return $ok;
    }

    /**
     * May this projector write below a vendor folder that is itself a link? False for hub
     * user skills and commands. True for the plugin's own system skills (Relay, Admin): the
     * fleet hosts link ~/.claude/skills into claude-config by design and that repo git-ignores
     * aicli-*. The skill folder itself must still be a real folder (linkReason checks it).
     */
    public function allowLinkedVendorDir(): bool { return false; }

    /**
     * #849: why a managed path must not be written, or null when it is safe.
     * A write or a delete must never follow a symlink out of the vendor folder:
     * on the fleet hosts ~/.claude/skills is a link into the claude-config git
     * repo, and a projected skill would land in that working tree.
     * Refused: the vendor folder itself is a link (any target), or a folder or
     * the file below it is a link whose real path leaves the vendor folder
     * (or is broken). A link that stays inside the vendor folder is allowed.
     * @param string $root absolute vendor DIRECTORY; $rel a safe managed key
     */
    public function linkReason(string $root, string $rel): ?string {
        $root = rtrim($root, '/');
        if (is_link($root) && !$this->allowLinkedVendorDir()) {
            return self::linkText('folder', $root);
        }
        $rootReal = realpath($root);
        $path = $root;
        $segs = explode('/', $rel);
        foreach ($segs as $i => $seg) {
            $path .= '/' . $seg;
            if (is_link($path)) {
                $real = realpath($path);
                if ($rootReal === false || $real === false || strpos($real, $rootReal . '/') !== 0) {
                    return self::linkText($i === count($segs) - 1 ? 'file' : 'folder', $path);
                }
            } elseif (!file_exists($path)) {
                break; // nothing below a missing part can be a link
            }
        }
        return null;
    }

    private static function linkText(string $what, string $path): string {
        return "Linked $what, not written: $path is a link to '" . (string)@readlink($path) . "'. "
            . 'The Config Hub does not write through a link, so the files behind it stay as they are. '
            . 'Replace the link with a real folder, then apply again';
    }

    /** Remove now-empty dirs from $dir up to (not including) $root. Never crosses $root. */
    private function pruneEmptyDirs(string $root, string $dir): void {
        while ($dir !== $root && strncmp($dir, $root . '/', strlen($root) + 1) === 0) {
            if (!@rmdir($dir)) break; // non-empty (user content) or already gone
            $dir = dirname($dir);
        }
    }

    /** Defensive relpath check: store-validated keys only, never traversal. */
    private static function safeRel(string $key): ?string {
        if ($key === '' || strlen($key) > 200) return null;
        if ($key[0] === '/' || strpos($key, '\\') !== false) return null;
        foreach (explode('/', $key) as $seg) {
            if ($seg === '' || $seg[0] === '.') return null; // covers '.', '..', dotfiles
        }
        return $key;
    }
}
