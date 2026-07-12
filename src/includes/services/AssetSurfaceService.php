<?php
/**
 * <module_context>
 *     <name>AssetSurfaceService</name>
 *     <description>#43 (docs/specs/WORKSPACE_ASSET_TREE.md): resolves an agent's
 *     "effective config surface" — the browsable tree of instruction/skill/command/
 *     hook/mcp/setting/state/log files across Global (captive home), Project
 *     (workspace cwd), and Ancestor (up-tree instruction files) scopes. Read-only:
 *     stats paths and lists directory children, never writes. Per-agent descriptors
 *     are held in a small static map; Claude Code is fully hand-authored per the
 *     spec's worked-reference table, every other agent auto-derives its
 *     instruction/skill/command/mcp entries from the already doc-verified Hub
 *     projector registries (HubProjector::instructionVendors()/treeVendors()/
 *     supportedVendors()) so this never drifts from those pinned paths.</description>
 *     <dependencies>ValidationService, HubProjector (+ its projector registries, for
 *     descriptor auto-derivation and the `managed` tag)</dependencies>
 *     <constraints>Read-only. Every returned path is allowlist-checked via
 *     ValidationService::validatePath — paths outside the allowlist are silently
 *     omitted (no oracle), same rule as check_path (#40). Directory expansion is a
 *     NESTED tree, bounded to AssetSurfaceService::MAX_DEPTH levels below the
 *     descriptor entry and capped per-directory at AssetSurfaceService::CHILD_CAP
 *     children, never unbounded — see the AssetNode `children`/`childCount`/
 *     `truncated` fields. The ancestor walk never reaches filesystem `/` and is
 *     capped at ANCESTOR_LEVEL_CAP levels. Response contract addendum
 *     ("+ Add &lt;file&gt;" create-on-save): every AssetNode now also carries a
 *     `creatable` bool (default false). Within `buildTypes()` (global/project
 *     scopes only — NOT the ancestor walk), for each FILE_CREATABLE_TYPES type
 *     (instruction/setting/mcp/hook) whose scope has ZERO existing nodes, the
 *     first (canonical, descriptor-order) node of that type is flipped to
 *     `creatable:true` while staying `exists:false` — exactly one placeholder
 *     per (scope,type), never one per candidate path. skill/command (tree
 *     dirs) and state/log (not text-scaffoldable) are never marked
 *     creatable.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

use AICliAgents\Services\Hub\HubProjector;

class AssetSurfaceService {

    /** Canonical type ordering for the response (and for skipping empty groups). */
    private const TYPE_ORDER = ['instruction', 'skill', 'command', 'hook', 'mcp', 'setting', 'state', 'log'];

    private const TYPE_LABELS = [
        'instruction' => 'Instructions',
        'skill'       => 'Skills',
        'command'     => 'Commands',
        'hook'        => 'Hooks',
        'mcp'         => 'MCP Servers',
        'setting'     => 'Settings',
        'state'       => 'State',
        'log'         => 'Logs',
    ];

    /** Bound on how many children of an expanded directory are enumerated. */
    private const CHILD_CAP = 50;

    /**
     * Bound on how many levels the recursive tree expansion descends below a
     * descriptor entry. The descriptor entry itself is depth 0; its children
     * are depth 1, grandchildren depth 2, ... down to depth MAX_DEPTH. A
     * directory node AT depth MAX_DEPTH still reports an accurate
     * childCount/truncated (a full scandir), it just never recurses into its
     * own children (children stays []).
     */
    private const MAX_DEPTH = 3;

    /** Bound on how many levels the ancestor walk climbs (never reaches '/'). */
    private const ANCESTOR_LEVEL_CAP = 8;

    /**
     * Types whose canonical (first) node may be offered as a "+ Add <file>"
     * create-on-save placeholder when the scope has zero existing nodes of
     * that type — see `markCreatable()`. skill/command are directories (not
     * scaffoldable as a blank text file); state/log are not user-authorable
     * config at all.
     */
    private const FILE_CREATABLE_TYPES = ['instruction', 'setting', 'mcp', 'hook'];

    /** Cache for allManagedRelPaths() — the Hub projector registries are static per request. */
    private static $managedRelPathsCache = null;

    // ------------------------------------------------------------------
    // Public entry point
    // ------------------------------------------------------------------

    /**
     * Resolve the config surface for one agent, optionally scoped to a running
     * workspace. $path empty/absent => GLOBAL scope only (Manager panel use case).
     * Never hard-fails on an unmounted home — nodes just report exists=false.
     *
     * @param string      $agentId AgentRegistry id (e.g. 'claude-code').
     * @param string|null $path    Workspace cwd, or '' / null for global-only.
     * @return array {status, agentId, workspace, groups} | {status:error, message}
     */
    public static function getSurface($agentId, $path): array {
        if (!is_string($agentId) || $agentId === '') {
            return ['status' => 'error', 'message' => 'agentId is required'];
        }
        $validAgentId = ValidationService::validateId($agentId);
        if ($validAgentId === false) {
            return ['status' => 'error', 'message' => 'invalid agentId'];
        }

        $descriptor = self::descriptorFor($validAgentId);

        // resolveHome() never hard-fails here: it always returns a 'home' path
        // even when the overlay is unmounted (ok=false) — we still resolve
        // paths under it so the Manager panel can query without a live session.
        $homeInfo = HubProjector::resolveHome();
        $home = rtrim((string)($homeInfo['home'] ?? ''), '/');

        $workspace = (is_string($path) && $path !== '') ? $path : null;

        $groups = [];
        $groups[] = [
            'scope'      => 'global',
            'scopeLabel' => 'Global (home)',
            'basePath'   => $home,
            'types'      => self::buildTypes($descriptor, 'global', $home, $home),
        ];

        if ($workspace !== null) {
            $groups[] = [
                'scope'      => 'project',
                'scopeLabel' => 'Project (workspace)',
                'basePath'   => $workspace,
                'types'      => self::buildTypes($descriptor, 'project', $workspace, $home),
            ];
            foreach (self::buildAncestorGroups($descriptor, $workspace, $home) as $g) {
                $groups[] = $g;
            }
        }

        return [
            'status'    => 'ok',
            'agentId'   => $validAgentId,
            'workspace' => $workspace,
            'groups'    => $groups,
        ];
    }

    // ------------------------------------------------------------------
    // Per-agent descriptors
    // ------------------------------------------------------------------

    /**
     * type => { global: [home-relative paths], project: [cwd-relative paths],
     *           ancestorFilenames: [filenames checked at every ancestor level] }.
     * A descriptor entry may point at a directory (skills/commands) — the
     * resolver auto-expands its immediate children (capped/truncated).
     * @return array<string,array{global:string[],project:string[],ancestorFilenames:string[]}>
     */
    public static function descriptorFor(string $agentId): array {
        if ($agentId === 'claude-code') {
            return self::claudeDescriptor();
        }
        return self::autoDescriptor($agentId);
    }

    private static function emptyDescriptor(): array {
        $d = [];
        foreach (self::TYPE_ORDER as $type) {
            $d[$type] = ['global' => [], 'project' => [], 'ancestorFilenames' => []];
        }
        return $d;
    }

    /**
     * Claude Code — FULLY populated per the spec's worked-reference table
     * (docs/specs/WORKSPACE_ASSET_TREE.md §Design). Every path here matches an
     * existing, doc-verified Hub projector relPath where one exists
     * (InstructionProjector '.claude/CLAUDE.md', TreeProjector '.claude/skills'
     * + '.claude/commands', ClaudeProjector '.claude.json'), so `managed`
     * detection lines up automatically. The spec's global mcp cell literally
     * reads "~/.claude.json, ~/.claude/mcp*" — the glob half is not a concrete,
     * verifiable path (no such file is documented/observed), so only the
     * verified '.claude.json' is listed; see the task report for this call-out.
     */
    private static function claudeDescriptor(): array {
        return [
            'instruction' => [
                'global'            => ['.claude/CLAUDE.md'],
                'project'           => ['CLAUDE.md', '.claude/CLAUDE.md'],
                'ancestorFilenames' => ['CLAUDE.md'],
            ],
            'skill' => [
                'global'            => ['.claude/skills'],
                'project'           => ['.claude/skills'],
                'ancestorFilenames' => [],
            ],
            'command' => [
                'global'            => ['.claude/commands'],
                'project'           => ['.claude/commands'],
                'ancestorFilenames' => [],
            ],
            'hook' => [
                // Hooks live inside settings.json's "hooks" block — same physical
                // file as `setting`, surfaced under its own type per the spec.
                'global'            => ['.claude/settings.json'],
                'project'           => ['.claude/settings.json', '.claude/settings.local.json'],
                'ancestorFilenames' => [],
            ],
            'mcp' => [
                'global'            => ['.claude.json'],
                'project'           => ['.mcp.json'],
                'ancestorFilenames' => [],
            ],
            'setting' => [
                'global'            => ['.claude/settings.json'],
                'project'           => ['.claude/settings.local.json'],
                'ancestorFilenames' => [],
            ],
            'state' => [
                // Session transcripts, per-session todo lists, and bash env
                // snapshots — the closest thing Claude Code has to "memory/
                // session files" at the home scope. No project-scope state dir
                // is separately addressable (it lives inside the same .claude/
                // tree the project instruction/hook/setting entries cover).
                'global'            => ['.claude/projects', '.claude/todos', '.claude/shell-snapshots'],
                'project'           => [],
                'ancestorFilenames' => [],
            ],
            'log' => [
                // Deliberately empty: "plugin session logs for this workspace"
                // (the spec's own log cell) means OUR plugin's per-SESSION log
                // (e.g. /tmp/unraid-aicliagents/ensure-<sessionId>.log), which
                // needs a session id this endpoint's (agentId, path) signature
                // does not carry. Left for a follow-on once the endpoint grows
                // an optional sessionId param.
                'global'            => [],
                'project'           => [],
                'ancestorFilenames' => [],
            ],
        ];
    }

    /**
     * Non-Claude agents: auto-derive instruction/skill/command/mcp GLOBAL
     * entries from the already doc-verified Hub projector registries, so this
     * can never drift from HubProjector's pinned paths. hook/setting/state/log
     * are intentionally left empty here — populating them needs the same
     * per-agent official-doc verification discipline as the 2026-06 Config-Hub
     * surface audit, which is out of scope for this pass (see the task brief:
     * "do NOT spend time web-researching hook/state/log paths for every
     * agent"). Project/ancestor scopes are also left empty for non-Claude
     * agents — the Hub only ever projects into the GLOBAL home, so a project-
     * scope equivalent isn't something this registry can derive safely.
     */
    private static function autoDescriptor(string $agentId): array {
        $d = self::emptyDescriptor();

        $instrBasenames = [];
        foreach (HubProjector::instructionVendors() as $p) {
            if (in_array($agentId, $p->servedAgentIds(), true)) {
                $rel = $p->relPath();
                $d['instruction']['global'][] = $rel;
                $instrBasenames[] = basename($rel);
            }
        }
        foreach (HubProjector::treeVendors() as $p) {
            if (!in_array($agentId, $p->servedAgentIds(), true)) continue;
            $key = ($p->surface() === 'skills') ? 'skill' : 'command';
            $rel = $p->relPath();
            $d[$key]['global'][]  = $rel;
            $d[$key]['project'][] = $rel;   // project mirror of the config-dir surface
        }
        $vendors = HubProjector::supportedVendors();
        if (isset($vendors[$agentId])) {
            $rel = $vendors[$agentId]->relPath();
            $d['mcp']['global'][]  = $rel;
            $d['mcp']['project'][] = $rel;
        }

        // Project + ancestor instruction files. An agent reads a project-level
        // instruction file at the workspace root (its own basename, e.g.
        // AGENTS.md / GEMINI.md / QWEN.md) and up the ancestor tree; a project
        // may also carry the cross-agent AGENTS.md / CLAUDE.md standards
        // regardless of which agent runs there, so include those too
        // (non-existent ones simply render greyed). This is what populates the
        // Project + Ancestor scopes for non-Claude agents.
        $crossAgent = ['AGENTS.md', 'CLAUDE.md'];
        $rootNames  = array_values(array_unique(array_merge($instrBasenames, $crossAgent)));
        $d['instruction']['project'] = array_values(array_unique(array_merge(
            $rootNames,
            $d['instruction']['global']   // e.g. a project-local .codex/AGENTS.md
        )));
        $d['instruction']['ancestorFilenames'] = $rootNames;

        return $d;
    }

    // ------------------------------------------------------------------
    // Tree building — global / project scopes
    // ------------------------------------------------------------------

    /**
     * Build the {type,typeLabel,nodes}[] list for one scope. Types with no
     * descriptor entries are skipped entirely (no empty type groups); types
     * whose every node is allowlist-omitted are likewise skipped. After a
     * type's nodes are built, `markCreatable()` may flip its canonical node
     * to `creatable:true` (see FILE_CREATABLE_TYPES doc + the class docblock
     * addendum) — global/project scopes only, never the ancestor walk (which
     * goes through `buildAncestorTypes()` instead).
     */
    private static function buildTypes(array $descriptor, string $scopeKey, string $baseDir, string $home): array {
        $types = [];
        foreach (self::TYPE_ORDER as $type) {
            $rels = $descriptor[$type][$scopeKey] ?? [];
            if (empty($rels)) continue;
            $nodes = [];
            foreach ($rels as $rel) {
                self::buildNode($home, $baseDir, $rel, true, $nodes);
            }
            if (empty($nodes)) continue;
            self::markCreatable($type, $nodes);
            $types[] = ['type' => $type, 'typeLabel' => self::TYPE_LABELS[$type], 'nodes' => $nodes];
        }
        return $types;
    }

    /**
     * Flip the canonical (first, descriptor-order) node of $nodes to
     * `creatable:true` when NONE of $type's nodes for this scope currently
     * exist — offering exactly one "+ Add <file>" placeholder per
     * (scope,type), never one per candidate path. No-op for a type outside
     * FILE_CREATABLE_TYPES, or when at least one node of the type already
     * exists (nothing to offer — the file is already there).
     */
    private static function markCreatable(string $type, array &$nodes): void {
        if (!in_array($type, self::FILE_CREATABLE_TYPES, true)) return;
        foreach ($nodes as $n) {
            if ($n['exists']) return;
        }
        $nodes[0]['creatable'] = true;
    }

    /**
     * Build (and append to $nodesOut) the node for $baseDir/$rel. Silently
     * omits the node when the resolved path falls outside the
     * ValidationService allowlist (no exists/not-exists oracle for paths the
     * plugin may not touch — same discipline as check_path #40). When the
     * node is an existing directory and $expandChildren is true, its
     * immediate children are recursively resolved into the node's own
     * `children` array (nested tree, NOT flattened into $nodesOut) — see
     * populateChildren() for the depth/cap/loop-guard rules.
     *
     * $depth is this node's own depth (0 for a top descriptor entry).
     * $visitedRealpaths is the set of realpath()s already visited on THIS
     * recursion branch (root-to-here), used to break symlink loops.
     */
    private static function buildNode(
        string $home,
        string $baseDir,
        string $rel,
        bool $expandChildren,
        array &$nodesOut,
        int $depth = 0,
        array $visitedRealpaths = []
    ): void {
        $abs = rtrim($baseDir, '/') . '/' . ltrim($rel, '/');
        // validateIntendedPath (not validatePath) so a descriptor entry whose
        // PARENT dir doesn't exist yet still resolves as an exists:false node —
        // that's what lets a "+ Add <file>" placeholder appear for e.g.
        // .claude/settings.json in a workspace that has no .claude/ dir.
        $resolved = ValidationService::validateIntendedPath($abs);
        if ($resolved === false) return;

        $exists = file_exists($resolved);
        $isDir = $exists && is_dir($resolved);

        $node = [
            'path'       => $resolved,
            'name'       => basename($resolved),
            'exists'     => $exists,
            'isDir'      => $isDir,
            'managed'    => self::isManagedPath($home, $resolved, $exists && !$isDir),
            'childCount' => 0,
            'truncated'  => false,
            'children'   => [],
            // Flipped to true post-hoc by markCreatable() for the canonical
            // node of a FILE_CREATABLE_TYPES type whose scope has zero
            // existing nodes — never true here at build time.
            'creatable'  => false,
        ];

        if ($isDir && $expandChildren) {
            self::populateChildren($home, $resolved, $node, $depth, $visitedRealpaths);
        }

        $nodesOut[] = $node;
    }

    /**
     * Fill in $node['childCount'] / ['truncated'] / ['children'] for the
     * directory $dirAbs (the node itself lives at $depth).
     *
     * - childCount/truncated are always computed from a FULL scandir (every
     *   immediate child), so they stay accurate even when the tree recursion
     *   stops short — either because of the CHILD_CAP (only the first
     *   CHILD_CAP entries, natcasesort'd, get a `children` entry) or the
     *   MAX_DEPTH cap (no `children` at all beyond that depth).
     * - Loop guard: if $dirAbs's realpath was already visited earlier on
     *   this same root-to-here branch (a symlink cycle), stop without
     *   recursing further — depth alone won't catch a loop that revisits a
     *   shallower ancestor.
     * - Children outside the ValidationService allowlist are silently
     *   omitted from `children` (buildNode() already enforces this), same
     *   "no oracle" rule as every other node.
     */
    private static function populateChildren(
        string $home,
        string $dirAbs,
        array &$node,
        int $depth,
        array $visitedRealpaths
    ): void {
        $real = @realpath($dirAbs);
        if ($real !== false) {
            if (in_array($real, $visitedRealpaths, true)) {
                // Symlink loop back onto an ancestor already on this branch —
                // stop here rather than recursing forever. childCount/children
                // are left at their zero/empty defaults; this directory's true
                // contents were already (or will be) reported at the level
                // where it was first visited.
                return;
            }
            $visitedRealpaths[] = $real;
        }

        $entries = @scandir($dirAbs);
        if (!is_array($entries)) return;
        $entries = array_values(array_filter($entries, static function ($e) {
            return $e !== '.' && $e !== '..';
        }));
        natcasesort($entries);
        $entries = array_values($entries);

        $total = count($entries);
        $node['childCount'] = $total;
        $node['truncated'] = $total > self::CHILD_CAP;

        if ($depth >= self::MAX_DEPTH) {
            // Depth cap reached: childCount/truncated above are still
            // accurate (full scandir), but we don't descend further.
            return;
        }

        foreach (array_slice($entries, 0, self::CHILD_CAP) as $entry) {
            $childNodes = [];
            self::buildNode($home, $dirAbs, $entry, true, $childNodes, $depth + 1, $visitedRealpaths);
            if (!empty($childNodes)) {
                $node['children'][] = $childNodes[0];
            }
        }
    }

    // ------------------------------------------------------------------
    // Ancestor walk
    // ------------------------------------------------------------------

    /**
     * Walk UP from $workspace collecting one group per level whose descriptor
     * has ancestorFilenames entries (only 'instruction' for Claude in v1).
     * Stops at the first of: a `.git` dir at that level, the computed
     * share/$HOME boundary, or ANCESTOR_LEVEL_CAP levels — and NEVER walks to
     * filesystem '/'. The boundary level itself IS included before stopping.
     * @return array[] scope=ancestor groups
     */
    private static function buildAncestorGroups(array $descriptor, string $workspace, string $home): array {
        $hasAncestorTypes = false;
        foreach (self::TYPE_ORDER as $type) {
            if (!empty($descriptor[$type]['ancestorFilenames'] ?? [])) { $hasAncestorTypes = true; break; }
        }
        if (!$hasAncestorTypes) return [];

        $groups = [];
        $floor = self::ancestorBoundaryFloor($workspace);
        $dir = dirname(rtrim($workspace, '/'));
        $levels = 0;

        while ($levels < self::ANCESTOR_LEVEL_CAP && $dir !== '/' && $dir !== '' && $dir !== '.') {
            // Never consider a level above the computed floor (share root / $HOME).
            if ($dir !== $floor && strncmp($dir . '/', rtrim($floor, '/') . '/', strlen(rtrim($floor, '/')) + 1) !== 0) {
                break;
            }

            $types = self::buildAncestorTypes($descriptor, $dir, $home);
            if (!empty($types)) {
                $groups[] = ['scope' => 'ancestor', 'scopeLabel' => $dir, 'basePath' => $dir, 'types' => $types];
            }
            $levels++;

            if (is_dir($dir . '/.git')) break; // repo root boundary — included above, then stop
            if ($dir === $floor) break;         // share/$HOME boundary — included above, then stop

            $parent = dirname($dir);
            if ($parent === $dir) break; // safety against dirname() fixed points
            $dir = $parent;
        }

        return $groups;
    }

    /** Build the {type,typeLabel,nodes}[] list for one ancestor level (files only, no expansion). */
    private static function buildAncestorTypes(array $descriptor, string $dir, string $home): array {
        $types = [];
        foreach (self::TYPE_ORDER as $type) {
            $filenames = $descriptor[$type]['ancestorFilenames'] ?? [];
            if (empty($filenames)) continue;
            $nodes = [];
            foreach ($filenames as $fn) {
                self::buildNode($home, $dir, $fn, false, $nodes);
            }
            if (empty($nodes)) continue;
            $types[] = ['type' => $type, 'typeLabel' => self::TYPE_LABELS[$type], 'nodes' => $nodes];
        }
        return $types;
    }

    /**
     * Lowest directory the ancestor walk may reach (inclusive), so it never
     * climbs into an unrelated share or above the user's home:
     *  - Unraid mounted-share layouts (/mnt/user/<share>, /mnt/user0/<share>,
     *    /mnt/cache/<share>, /mnt/disk<N>/<share>, /mnt/remotes/<share>) →
     *    the share root itself.
     *  - Everything else (e.g. /root/..., /home/<user>/...) → $HOME.
     */
    private static function ancestorBoundaryFloor(string $workspace): string {
        foreach (['/mnt/user0/', '/mnt/user/', '/mnt/cache/', '/mnt/remotes/'] as $prefix) {
            if (strncmp($workspace, $prefix, strlen($prefix)) === 0) {
                $rest = substr($workspace, strlen($prefix));
                $share = strtok($rest, '/');
                if ($share !== false && $share !== '') return rtrim($prefix, '/') . '/' . $share;
            }
        }
        if (preg_match('#^(/mnt/disk\d+/)#', $workspace, $m)) {
            $rest = substr($workspace, strlen($m[1]));
            $share = strtok($rest, '/');
            if ($share !== false && $share !== '') return rtrim($m[1], '/') . '/' . $share;
        }
        $home = getenv('HOME');
        if (is_string($home) && $home !== '') return rtrim($home, '/');
        return '/root';
    }

    // ------------------------------------------------------------------
    // `managed` detection
    // ------------------------------------------------------------------

    /**
     * True when $absPath is a Config-Hub-projected file/dir:
     *  - its home-relative form matches (or falls inside, for tree
     *    projector directories) a projector relPath from
     *    supportedVendors()/instructionVendors()/policyInstructionVendors()/
     *    treeVendors(), OR
     *  - (files only) its content contains an aicli-hub or
     *    aicli-file-paths fence marker.
     */
    private static function isManagedPath(string $home, string $absPath, bool $checkFenceContent): bool {
        if ($home !== '') {
            $rel = null;
            if (strncmp($absPath, $home . '/', strlen($home) + 1) === 0) {
                $rel = substr($absPath, strlen($home) + 1);
            } elseif ($absPath === $home) {
                $rel = '';
            }
            if ($rel !== null) {
                foreach (self::allManagedRelPaths() as $p) {
                    if ($rel === $p) return true;
                    if (strncmp($rel, $p . '/', strlen($p) + 1) === 0) return true; // inside a managed tree dir
                }
            }
        }

        if ($checkFenceContent && is_file($absPath)) {
            // Bounded read — fence markers sit near the top of small instruction
            // files; 256KB comfortably covers any real CLAUDE.md/AGENTS.md.
            $contents = @file_get_contents($absPath, false, null, 0, 262144);
            if ($contents !== false
                && (strpos($contents, '>>> aicli-hub') !== false || strpos($contents, '>>> aicli-file-paths') !== false)) {
                return true;
            }
        }

        return false;
    }

    /** Union of every projector relPath() across all four Hub registries. */
    private static function allManagedRelPaths(): array {
        if (self::$managedRelPathsCache !== null) return self::$managedRelPathsCache;
        $paths = [];
        foreach (HubProjector::supportedVendors() as $p) $paths[] = $p->relPath();
        foreach (HubProjector::instructionVendors() as $p) $paths[] = $p->relPath();
        foreach (HubProjector::policyInstructionVendors() as $p) $paths[] = $p->relPath();
        foreach (HubProjector::treeVendors() as $p) $paths[] = $p->relPath();
        self::$managedRelPathsCache = array_values(array_unique($paths));
        return self::$managedRelPathsCache;
    }
}
