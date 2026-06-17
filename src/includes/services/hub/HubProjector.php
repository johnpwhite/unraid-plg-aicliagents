<?php
/**
 * <module_context>
 *     <name>HubProjector</name>
 *     <description>Config Hub projection orchestrator (OP #1362 / H-01 + OP #1363 / H-02
 *     + OP #1364 / H-03): one-way projection of the canonical MCP store, the canonical
 *     instruction doc (hub/instructions/global.md → fenced blocks in CLAUDE.md /
 *     GEMINI.md / AGENTS.md) AND the skills/commands trees (hub/skills + hub/commands →
 *     mirrored copies under ~/.claude/skills, ~/.claude/commands, ~/.opencode/commands)
 *     into per-vendor files with THREE-WAY
 *     drift detection (on-disk hash vs the state.json lastProjectedHash baseline vs
 *     the desired hub value). Drifted keys are NEVER overwritten — they are reported
 *     {file,key,theirs,ours} for the UI's adopt/overwrite/release resolution.
 *     User-created (unmanaged) keys are never touched. Vendor paths are relative to
 *     the agent home the plugin manages; projection requires the MOUNTED overlay
 *     home (writes must land in the overlay upper) and returns 'home_unavailable'
 *     when it is not mounted — no implicit mounting.</description>
 *     <dependencies>HubStore, VendorProjector subclasses, SecretService, ConfigService, StorageMountService, AgentRegistry, LogService, GitHomeService (debounced auto-commit after projection — OP #1365 / H-04)</dependencies>
 *     <constraints>Never logs config/env values (names+counts only). Test hooks:
 *     AICLI_HUB_HOME env override (home dir, skips the mount gate) and the
 *     $installedOverride param (skips the AgentRegistry install gate).</constraints>
 * </module_context>
 */

namespace AICliAgents\Services\Hub;

use AICliAgents\Services\AgentRegistry;
use AICliAgents\Services\ConfigService;
use AICliAgents\Services\LogService;
use AICliAgents\Services\SecretService;
use AICliAgents\Services\StorageMountService;

class HubProjector {

    /** @var VendorProjector[]|null */
    private static $vendors = null;

    /**
     * Agents with NO projectable MCP config surface. EVERY agent in AgentRegistry
     * must be EITHER a supportedVendors() entry OR listed here with a reason — so a
     * newly-added agent cannot silently lack hub MCP wiring. This is enforced by
     * RegressionGuardsTest::testEveryRegisteredAgentHasHubMcpDecision (adding an
     * agent without a decision fails CI). When an exempt agent gains a real,
     * CLI-readable MCP config file, replace its entry here with a projector.
     * @var array<string,string> agentId => reason
     */
    const MCP_EXEMPT = [
        'pi-coder' => 'Author intentionally omits MCP ("pi does not and will not support MCP" — mariozechner.at); no native config file to project. The third-party pi-mcp-adapter (~/.pi/agent/mcp.json) is opt-in and cannot be assumed installed.',
    ];

    /** All per-vendor projectors, keyed by agent id. @return array<string,VendorProjector> */
    public static function supportedVendors(): array {
        if (self::$vendors === null) {
            self::$vendors = [];
            foreach ([new ClaudeProjector(), new GeminiProjector(), new QwenProjector(),
                      new OpencodeProjector(), new KilocodeProjector(), new AntigravityProjector(),
                      new FactoryProjector(), new NanocoderProjector(),
                      new CopilotProjector(), new CodexProjector(),
                      new GooseProjector()] as $p) {
                self::$vendors[$p->agentId()] = $p;
            }
        }
        return self::$vendors;
    }

    /** Vendor metadata for the UI: agentId => {label, file}. */
    public static function vendorMeta(): array {
        $out = [];
        foreach (self::supportedVendors() as $id => $p) {
            $out[$id] = ['label' => $p->label(), 'file' => $p->ledgerKey()];
        }
        return $out;
    }

    /** @var InstructionProjector[]|null */
    private static $instructionVendors = null;

    /**
     * Instruction-file projectors (H-02), keyed by PRIMARY agent id. Each is the
     * GLOBAL/home instruction file the agent actually reads, verified against
     * official docs (2026-06). A file may serve several agent ids only when the
     * SAME file is read by both. NOTE: GitHub Copilot CLI does NOT read
     * ~/.codex/AGENTS.md — it has its own ~/.copilot/copilot-instructions.md (the
     * earlier shared-codex wiring was wrong). pi-coder reads a global ~/.pi/agent/AGENTS.md
     * (official pi docs) — added 2026-06 even though it is MCP-exempt. Antigravity CLI reads
     * the SAME global ~/.gemini/GEMINI.md as Gemini CLI (officially confirmed at
     * antigravity.google/docs/gcli-migration — "Global developer context ... ~/.gemini/GEMINI.md"),
     * so it is a SECOND served agent of the Gemini projector: projecting to either writes that
     * one shared file. Kilo Code is the odd one out: it has no fenced shared file — it
     * AUTO-DISCOVERS every ~/.kilo/rules/*.md as a global instruction (verified live via
     * `kilo debug config`), so KiloInstructionProjector owns one dedicated rules file there.
     * @return array<string,InstructionProjector>
     */
    public static function instructionVendors(): array {
        if (self::$instructionVendors === null) {
            self::$instructionVendors = [];
            foreach ([
                new InstructionProjector('claude-code', '.claude/CLAUDE.md', 'Claude Code', ['claude-code'], true),
                new InstructionProjector('gemini-cli', '.gemini/GEMINI.md', 'Gemini CLI', ['gemini-cli', 'antigravity-cli'], false),
                new InstructionProjector('qwen-code', '.qwen/QWEN.md', 'Qwen Code', ['qwen-code'], false),
                new InstructionProjector('codex-cli', '.codex/AGENTS.md', 'Codex CLI', ['codex-cli'], false),
                new InstructionProjector('gh-copilot', '.copilot/copilot-instructions.md', 'GitHub Copilot CLI', ['gh-copilot'], false),
                new InstructionProjector('opencode', '.config/opencode/AGENTS.md', 'OpenCode', ['opencode'], false),
                new InstructionProjector('goose', '.config/goose/.goosehints', 'Goose', ['goose'], false),
                new InstructionProjector('factory-cli', '.factory/AGENTS.md', 'Factory Droid', ['factory-cli'], false),
                new InstructionProjector('pi-coder', '.pi/agent/AGENTS.md', 'pi-coder', ['pi-coder'], false),
                // Kilo: dedicated auto-discovered rules file (~/.kilo/rules/*.md), no fence.
                new KiloInstructionProjector('kilocode', '.kilo/rules/aicli-hub-global.md', 'Kilo Code', ['kilocode'], false),
            ] as $p) {
                self::$instructionVendors[$p->agentId()] = $p;
            }
        }
        return self::$instructionVendors;
    }

    /** Instruction metadata for the UI: targetable agentId => {label, file}. */
    public static function instructionAgentMeta(): array {
        $out = [];
        foreach (self::instructionVendors() as $p) {
            foreach ($p->servedAgentIds() as $id) {
                $out[$id] = ['label' => $p->label(), 'file' => $p->ledgerKey()];
            }
        }
        return $out;
    }

    /**
     * Per-instruction-file change classification for the post-Apply Unraid notification
     * (the "Claude removed / OpenCode updated / Kilo added" summary). Compares the
     * PROJECTED state before projectAll (the instruction ledger keys that had a managed
     * entry) with the projectAll results: a file that gained a managed write is 'added'
     * (not projected before) or 'updated' (was), and a file whose managed entry was
     * removed is 'removed'. Each file's label is its served agents' display names (a
     * shared file like ~/.gemini/GEMINI.md lists every reader).
     * @param string[] $managedBefore ledger keys with managedKeys BEFORE projection
     * @param array<string,array> $results projectAll()['results']
     * @return array<int,array{label:string,kind:string}>
     */
    public static function instructionChangeSummary(array $managedBefore, array $results): array {
        $out = [];
        foreach (self::instructionVendors() as $p) {
            $key = $p->ledgerKey();
            $r = $results[$key] ?? null;
            if (!is_array($r)) continue;
            $label = implode(' / ', array_map([self::class, 'agentDisplayName'], $p->servedAgentIds()));
            if (!empty($r['removed'])) {
                $out[] = ['label' => $label, 'kind' => 'removed'];
            } elseif (!empty($r['written'])) {
                $out[] = ['label' => $label, 'kind' => in_array($key, $managedBefore, true) ? 'updated' : 'added'];
            }
        }
        return $out;
    }

    /** Human display name for an agent id (AgentRegistry name; falls back to the id). */
    public static function agentDisplayName(string $id): string {
        foreach (AgentRegistry::getDefaultAgents() as $a) {
            if (($a['id'] ?? '') === $id) return (string)($a['name'] ?? $id);
        }
        return $id;
    }

    /** @var TreeProjector[]|null */
    private static $treeVendors = null;

    /**
     * Skill/command tree projectors (H-03 + config-surface audit 2026-06), every path
     * read directly from the agent's OFFICIAL docs (see
     * docs/CONFIG_HUB_AGENT_SURFACE_AUDIT_2026-06.md for the citation list).
     *
     * SKILLS = `<name>/SKILL.md` folder bundles. Ten agents read this open standard.
     * Five of them (Codex, Gemini, OpenCode, Copilot, Goose) read the cross-agent
     * **`~/.agents/skills/`** path, so a SINGLE shared projector serves all five
     * (multi-served, reconciled once — Goose makes `.agents/skills` its primary path,
     * Codex's USER scope literally IS `.agents/skills`). The other five read only their
     * own dir, so they get native projectors: Claude (~/.claude/skills), Qwen
     * (~/.qwen/skills), Kilo (~/.kilo/skills), Factory (~/.factory/skills), pi-coder
     * (~/.pi/agent/skills).
     *
     * COMMANDS = flat `<name>.md` → slash command for Claude/OpenCode/Factory/Qwen/
     * pi-coder and Antigravity (whose "skills" ARE flat-md slash commands). Gemini is
     * the exception: it reads TOML, so GeminiCommandsProjector transpiles md→toml.
     * Codex's prompts dir is officially DEPRECATED ("use skills"), so Codex gets skills
     * (above), not a commands projector. Nanocoder has only PROJECT-level surfaces.
     * @return TreeProjector[]
     */
    public static function treeVendors(): array {
        if (self::$treeVendors === null) {
            self::$treeVendors = [
                new TreeProjector('claude-code', '.claude/skills', 'Claude Code skills', 'skills'),
                new TreeProjector('claude-code', '.claude/commands', 'Claude Code commands', 'commands'),
                // Qwen Code skills are folder bundles (~/.qwen/skills/<name>/SKILL.md), identical
                // to Claude's — verified from the official Qwen docs.
                new TreeProjector('qwen-code', '.qwen/skills', 'Qwen Code skills', 'skills'),
                // Kilo Code: global skills are folder bundles at ~/.kilo/skills/<name>/SKILL.md
                // (note ~/.kilo, NOT ~/.config/kilo where its MCP kilo.jsonc lives) — per the
                // official Kilo docs; same SKILL.md format as Claude/Qwen.
                new TreeProjector('kilocode', '.kilo/skills', 'Kilo Code skills', 'skills'),
                // SHARED cross-agent skills standard (~/.agents/skills/<name>/SKILL.md): read by
                // Codex (USER scope), Gemini (alias), OpenCode, Copilot, and Goose (primary) — one
                // projection feeds all five. Primary agentId codex-cli is for drift labelling only;
                // the dir is reconciled ONCE and survives until ALL five are un-targeted.
                new TreeProjector('codex-cli', '.agents/skills', 'Agent Skills (Codex · Gemini · OpenCode · Copilot · Goose)', 'skills',
                    ['codex-cli', 'gemini-cli', 'opencode', 'gh-copilot', 'goose']),
                // Factory personal skills at ~/.factory/skills/<name>/SKILL.md (official docs).
                new TreeProjector('factory-cli', '.factory/skills', 'Factory Droid skills', 'skills'),
                // pi-coder global skills at ~/.pi/agent/skills/<name>/SKILL.md (official pi docs) —
                // pi is MCP-exempt but DOES read the SKILL.md standard.
                new TreeProjector('pi-coder', '.pi/agent/skills', 'pi-coder skills', 'skills'),
                // OpenCode commands live under the XDG config root, not ~/.opencode (corrected 2026-06).
                new TreeProjector('opencode', '.config/opencode/commands', 'OpenCode commands', 'commands'),
                // Factory: global flat-.md commands at ~/.factory/commands/ (verified official docs).
                new TreeProjector('factory-cli', '.factory/commands', 'Factory Droid commands', 'commands'),
                // Antigravity skills are folder bundles (~/.gemini/antigravity-cli/skills/<name>/SKILL.md),
                // NOT flat-md slash commands — confirmed from the LIVE `agy /skills` output (which is more
                // authoritative than the docs; the earlier flat-md reading was wrong). Antigravity also reads
                // ~/.gemini/skills/ (shared with Gemini) and workspace .agents/skills/; this projects its
                // dedicated global skills dir.
                new TreeProjector('antigravity-cli', '.gemini/antigravity-cli/skills', 'Antigravity skills', 'skills'),
                // Qwen Code commands at ~/.qwen/commands/<name>.md — Markdown (recommended) per the
                // official Qwen docs, so the flat-md hub format projects directly.
                new TreeProjector('qwen-code', '.qwen/commands', 'Qwen Code commands', 'commands'),
                // Gemini CLI commands at ~/.gemini/commands/<name>.toml — TOML-ONLY (official docs),
                // so this projector transpiles the markdown body into the TOML `prompt` field.
                new GeminiCommandsProjector('gemini-cli', '.gemini/commands', 'Gemini CLI commands (TOML)', 'commands'),
                // pi-coder prompts (slash commands) at ~/.pi/agent/prompts/<name>.md — flat md.
                new TreeProjector('pi-coder', '.pi/agent/prompts', 'pi-coder commands', 'commands'),
            ];
        }
        return self::$treeVendors;
    }

    /**
     * Tree-surface metadata for the UI: targetable agentId => {label, file} per surface.
     * Iterates servedAgentIds() so EVERY reader of a shared dir (e.g. all five readers
     * of ~/.agents/skills) gets its own UI checkbox pointing at the shared file — same
     * pattern as instructionAgentMeta().
     */
    public static function treeAgentMeta(string $surface): array {
        $out = [];
        foreach (self::treeVendors() as $p) {
            if ($p->surface() !== $surface) continue;
            foreach ($p->servedAgentIds() as $id) {
                $out[$id] = ['label' => $p->label(), 'file' => $p->ledgerKey()];
            }
        }
        return $out;
    }

    /**
     * Resolve the agent home this plugin manages (the merged overlay mount).
     * NO implicit mounting: an unmounted home is an error — writes against the
     * bare tmpfs path would be lost at the next mount/bake.
     * @return array{ok:bool,home:string,error?:string}
     */
    public static function resolveHome(): array {
        $env = getenv('AICLI_HUB_HOME');
        if ($env !== false && $env !== '') {
            return ['ok' => true, 'home' => rtrim($env, '/')];
        }
        $config = ConfigService::getConfig();
        $user = (string)($config['user'] ?? 'root');
        if ($user === '') $user = 'root';
        $home = "/tmp/unraid-aicliagents/work/$user/home";
        if (!StorageMountService::isMounted($home)) {
            return ['ok' => false, 'home' => $home, 'error' => 'home_unavailable'];
        }
        return ['ok' => true, 'home' => $home];
    }

    /**
     * Every agent id with ANY hub surface (MCP, instruction, skills, or commands),
     * install-agnostic. Unions all three projector registries (MCP + instruction +
     * tree via servedAgentIds) so an MCP-EXEMPT agent is still a projection candidate:
     * pi-coder refuses MCP but reads ~/.pi/agent/AGENTS.md, ~/.pi/agent/skills, and
     * ~/.pi/agent/prompts. (When this union was MCP-only, pi-coder was filtered out of
     * the projection scope and its non-MCP surfaces silently never projected.)
     */
    public static function surfaceAgentIds(): array {
        $ids = array_keys(self::supportedVendors());
        foreach (self::instructionVendors() as $p) {
            foreach ($p->servedAgentIds() as $id) $ids[] = $id;
        }
        foreach (self::treeVendors() as $p) {
            foreach ($p->servedAgentIds() as $id) $ids[] = $id;
        }
        return array_values(array_unique($ids));
    }

    /** Installed subset of surfaceAgentIds() — the production projection gate. */
    public static function installedSupportedAgentIds(): array {
        $registry = AgentRegistry::getRegistry();
        $out = [];
        foreach (self::surfaceAgentIds() as $id) {
            if (!empty($registry[$id]['is_installed'])) $out[] = $id;
        }
        return $out;
    }

    /**
     * Project the canonical store into every targeted vendor file.
     *
     * @param string[]|null $targetAgentIds restrict to these agents (null = all).
     * @param string[]|null $installedOverride test hook — bypass the AgentRegistry
     *        install gate with an explicit installed set.
     * @return array status=ok|error; per-file results {written,removed,drift};
     *         flat drift list; written agent ids (for the session-reload prompt).
     */
    public static function projectAll(?array $targetAgentIds = null, ?array $installedOverride = null): array {
        $homeInfo = self::resolveHome();
        if (!$homeInfo['ok']) {
            return ['status' => 'error', 'reason' => 'home_unavailable',
                    'message' => 'The agent home overlay is not mounted — start a session or the array, then retry. Projection never writes to an unmounted home.'];
        }
        $home = $homeInfo['home'];
        $servers = HubStore::getMcp()['servers'];
        $state = HubStore::getState();

        $installed = $installedOverride ?? self::installedSupportedAgentIds();
        $targets = ($targetAgentIds === null) ? $installed : array_values(array_intersect($targetAgentIds, $installed));

        $results = [];
        $driftAll = [];
        $writtenAgents = [];

        foreach (self::supportedVendors() as $agentId => $projector) {
            if (!in_array($agentId, $targets, true)) continue;
            $desired = $projector->desired(self::enabledServersFor($servers, $agentId));
            $r = self::reconcileVendor($projector, $home, $desired, $state, true);
            $results[$projector->ledgerKey()] = ['agentId' => $agentId, 'written' => $r['written'],
                                                'removed' => $r['removed'], 'drift' => $r['drift']];
            foreach ($r['drift'] as $d) $driftAll[] = $d;
            if (!empty($r['written']) || !empty($r['removed'])) $writtenAgents[] = $agentId;
        }

        // Instruction-file pass (H-02) — same ledger/drift surface, second set of
        // vendor files. A file is reconciled when ANY agent that reads it is in
        // scope; the fence is desired only when one of them is instruction-targeted.
        $instructionTargets = $state['instructions_enabledFor'] ?? [];
        $content = HubStore::getInstructions();
        foreach (self::instructionVendors() as $projector) {
            $serving = array_values(array_intersect($projector->servedAgentIds(), $targets));
            if (empty($serving)) continue;
            $targeted = array_values(array_intersect($serving, $instructionTargets));
            $desired = empty($targeted) ? [] : $projector->desired(['content' => $content]);
            $r = self::reconcileVendor($projector, $home, $desired, $state, true);
            $results[$projector->ledgerKey()] = ['agentId' => $projector->agentId(), 'written' => $r['written'],
                                                'removed' => $r['removed'], 'drift' => $r['drift']];
            foreach ($r['drift'] as $d) $driftAll[] = $d;
            if (!empty($r['written']) || !empty($r['removed'])) {
                foreach ($serving as $id) {
                    if (!in_array($id, $writtenAgents, true)) $writtenAgents[] = $id;
                }
            }
        }

        // Skills/commands tree pass (H-03) — same ledger/drift surface, third
        // set of vendor surfaces (mirrored file trees per agent dir).
        $tree = self::reconcileTrees($home, $state, $targets, true);
        foreach ($tree['results'] as $k => $r) $results[$k] = $r;
        foreach ($tree['drift'] as $d) $driftAll[] = $d;
        foreach ($tree['writtenAgents'] as $id) {
            if (!in_array($id, $writtenAgents, true)) $writtenAgents[] = $id;
        }

        HubStore::saveState($state);
        LogService::log('hub project: agents=[' . implode(',', $targets) . '] changed=[' . implode(',', $writtenAgents) . '] drift=' . count($driftAll), LogService::LOG_INFO, 'HubProjector');
        if (!empty($writtenAgents)) {
            // H-04 (#1365): debounced auto-commit AFTER the vendor writes — agent ids only.
            GitHomeService::commitIfEnabled('hub: projected to [' . implode(',', $writtenAgents) . ']');
        }
        return ['status' => 'ok', 'results' => $results, 'drift' => $driftAll, 'writtenAgents' => $writtenAgents];
    }

    /**
     * Drift report without writing anything (read-only pass over managed keys).
     * @return array status=ok|error; drift: [{file,key,agentId,kind,theirs,ours}]
     */
    public static function detectDrift(?array $installedOverride = null): array {
        $homeInfo = self::resolveHome();
        if (!$homeInfo['ok']) {
            return ['status' => 'error', 'reason' => 'home_unavailable', 'drift' => []];
        }
        $home = $homeInfo['home'];
        $servers = HubStore::getMcp()['servers'];
        $state = HubStore::getState();
        $installed = $installedOverride ?? self::installedSupportedAgentIds();

        $driftAll = [];
        foreach (self::supportedVendors() as $agentId => $projector) {
            if (!in_array($agentId, $installed, true)) continue;
            $desired = $projector->desired(self::enabledServersFor($servers, $agentId));
            $r = self::reconcileVendor($projector, $home, $desired, $state, false);
            foreach ($r['drift'] as $d) $driftAll[] = $d;
        }

        // Instruction-file pass (H-02) — read-only, same drift surface.
        $instructionTargets = $state['instructions_enabledFor'] ?? [];
        $content = HubStore::getInstructions();
        foreach (self::instructionVendors() as $projector) {
            $serving = array_values(array_intersect($projector->servedAgentIds(), $installed));
            if (empty($serving)) continue;
            $targeted = array_values(array_intersect($serving, $instructionTargets));
            $desired = empty($targeted) ? [] : $projector->desired(['content' => $content]);
            $r = self::reconcileVendor($projector, $home, $desired, $state, false);
            foreach ($r['drift'] as $d) $driftAll[] = $d;
        }

        // Skills/commands tree pass (H-03) — read-only, same drift surface.
        $tree = self::reconcileTrees($home, $state, $installed, false);
        foreach ($tree['drift'] as $d) $driftAll[] = $d;
        return ['status' => 'ok', 'drift' => $driftAll];
    }

    /**
     * Reconcile every tree surface (H-03) whose agent is in $scope against the
     * hub skills/commands store and the per-surface target sets. With
     * $write=false this is a pure drift probe. Mutates $state ledger entries.
     * @return array{results:array<string,array>,drift:array[],writtenAgents:string[]}
     */
    private static function reconcileTrees(string $home, array &$state, array $scope, bool $write): array {
        $results = []; $drift = []; $writtenAgents = [];
        $fileMaps = []; // surface => relPath=>content, built lazily
        foreach (self::treeVendors() as $projector) {
            // A shared dir (~/.agents/skills) lists several readers; reconcile it once
            // when ANY reader is in scope, and DESIRE files when ANY reader is targeted —
            // so un-targeting one reader never removes a file another still wants.
            $serving = array_values(array_intersect($projector->servedAgentIds(), $scope));
            if (empty($serving)) continue;
            $surface = $projector->surface();
            $surfaceTargets = $state[$surface . '_enabledFor'] ?? [];
            $targeted = array_values(array_intersect($serving, $surfaceTargets));
            $files = [];
            if (!empty($targeted)) {
                if (!isset($fileMaps[$surface])) $fileMaps[$surface] = self::desiredTreeFiles($surface);
                $files = $fileMaps[$surface];
            }
            $desired = $projector->desired(['files' => $files]);
            $r = self::reconcileVendor($projector, $home, $desired, $state, $write);
            $results[$projector->ledgerKey()] = ['agentId' => $projector->agentId(), 'written' => $r['written'],
                                                'removed' => $r['removed'], 'drift' => $r['drift']];
            foreach ($r['drift'] as $d) $drift[] = $d;
            if (!empty($r['written']) || !empty($r['removed'])) {
                foreach ($serving as $id) {
                    if (!in_array($id, $writtenAgents, true)) $writtenAgents[] = $id;
                }
            }
        }
        return ['results' => $results, 'drift' => $drift, 'writtenAgents' => $writtenAgents];
    }

    /**
     * Desired relPath=>content map for one tree surface from the hub store:
     * skills → '<skillName>/<fileRel>' for every file of every skill;
     * commands → '<name>.md'. Content is read once per projection pass.
     */
    private static function desiredTreeFiles(string $surface): array {
        $out = [];
        if ($surface === 'skills') {
            foreach (HubStore::listSkills() as $name => $info) {
                foreach ($info['files'] as $rel => $bytes) {
                    $content = HubStore::getSkillFile($name, $rel);
                    if ($content !== null) $out["$name/$rel"] = $content;
                }
            }
        } else {
            foreach (HubStore::listCommands() as $name => $bytes) {
                $content = HubStore::getCommand($name);
                if ($content !== null) $out["$name.md"] = $content;
            }
        }
        return $out;
    }

    /**
     * Resolve one reported drift.
     *  - adopt:     accept the on-disk edit INTO the hub (JSON vendors: parse the
     *               vendor value back to canonical; deletion → release + untarget).
     *               Fenced vendors are not parseable → adopt is refused.
     *  - overwrite: re-assert the hub value onto disk (or remove an undesired key).
     *  - release:   stop managing the key; the vendor file is untouched and the
     *               agent is removed from the server's enabledFor so it is not
     *               re-adopted at the next projection.
     */
    public static function resolveDrift(string $file, string $key, string $mode): array {
        if (!in_array($mode, ['adopt', 'overwrite', 'release'], true)) {
            return ['status' => 'error', 'message' => "invalid mode '$mode'"];
        }
        $projector = null;
        foreach (array_merge(array_values(self::supportedVendors()), array_values(self::instructionVendors()), self::treeVendors()) as $p) {
            if ($p->ledgerKey() === $file) { $projector = $p; break; }
        }
        if ($projector === null) return ['status' => 'error', 'message' => "unknown vendor file '$file'"];
        $isInstruction = $projector instanceof InstructionProjector;
        $isTree = $projector instanceof TreeProjector;

        $homeInfo = self::resolveHome();
        if (!$homeInfo['ok']) return ['status' => 'error', 'reason' => 'home_unavailable', 'message' => 'agent home is not mounted'];
        $abs = $projector->absPath($homeInfo['home']);

        $state = HubStore::getState();
        $entry = $state['projections'][$file] ?? ['managedKeys' => [], 'lastProjectedHash' => []];
        // NOTE: an unmanaged-conflict drift (key never projected) is resolvable
        // too — no managed-key precondition here.

        $mcp = HubStore::getMcp();
        if ($isInstruction) {
            /** @var InstructionProjector $projector */
            $serverName = null;
            $targeted = array_intersect($projector->servedAgentIds(), HubStore::getInstructionTargets());
            $desired = empty($targeted) ? [] : $projector->desired(['content' => HubStore::getInstructions()]);
        } elseif ($isTree) {
            /** @var TreeProjector $projector */
            $serverName = null;
            $surfaceTargets = ($projector->surface() === 'skills') ? HubStore::getSkillsTargets() : HubStore::getCommandsTargets();
            $served = $projector->servedAgentIds();
            $files = !empty(array_intersect($served, $surfaceTargets)) ? self::desiredTreeFiles($projector->surface()) : [];
            $desired = $projector->desired(['files' => $files]);
        } else {
            $serverName = self::serverNameForKey($projector, $key);
            $enabled = self::enabledServersFor($mcp['servers'], $projector->agentId());
            $desired = $projector->desired($enabled);
        }
        $want = $desired[$key] ?? null;
        $cur = $projector->current($abs, [$key]);
        $theirs = array_key_exists($key, $cur) ? $cur[$key] : null;

        switch ($mode) {
            case 'release':
                unset($entry['lastProjectedHash'][$key]);
                $entry['managedKeys'] = array_values(array_diff($entry['managedKeys'] ?? [], [$key]));
                self::putLedgerEntry($state, $file, $entry);
                HubStore::saveState($state);
                if ($isInstruction) {
                    // Releasing an instruction fence untargets every agent that
                    // reads the file, so the next projection does not re-write it.
                    /** @var InstructionProjector $projector */
                    $errors = [];
                    HubStore::setInstructionTargets(
                        array_values(array_diff(HubStore::getInstructionTargets(), $projector->servedAgentIds())), $errors);
                } elseif ($isTree) {
                    // Releasing a tree file untargets EVERY reader from the surface's
                    // target set (per-file disables don't exist), so the next projection
                    // does not re-adopt the released path. For a shared dir that means all
                    // its readers — same coarseness as the instruction-fence release.
                    /** @var TreeProjector $projector */
                    $errors = [];
                    $served = $projector->servedAgentIds();
                    if ($projector->surface() === 'skills') {
                        HubStore::setSkillsTargets(
                            array_values(array_diff(HubStore::getSkillsTargets(), $served)), $errors);
                    } else {
                        HubStore::setCommandsTargets(
                            array_values(array_diff(HubStore::getCommandsTargets(), $served)), $errors);
                    }
                } else {
                    self::untargetAgent($mcp, $projector->agentId(), $serverName);
                }
                return ['status' => 'ok', 'mode' => 'release'];

            case 'overwrite':
                $ok = ($want !== null)
                    ? $projector->write($abs, [$key => $want], [])
                    : $projector->write($abs, [], [$key]);
                if (!$ok) return ['status' => 'error', 'message' => 'vendor file write failed (unparseable file is never clobbered)'];
                if ($want !== null) {
                    $entry['lastProjectedHash'][$key] = VendorProjector::valueHash($want);
                    if (!in_array($key, $entry['managedKeys'] ?? [], true)) $entry['managedKeys'][] = $key;
                } else {
                    unset($entry['lastProjectedHash'][$key]);
                    $entry['managedKeys'] = array_values(array_diff($entry['managedKeys'] ?? [], [$key]));
                }
                $entry['lastRun'] = gmdate('c');
                self::putLedgerEntry($state, $file, $entry);
                HubStore::saveState($state);
                return ['status' => 'ok', 'mode' => 'overwrite'];

            case 'adopt':
                if ($isTree) {
                    return ['status' => 'error', 'message' => 'adopt is not supported for projected skill/command trees — use overwrite (re-assert the hub copy) or release (stop managing it)'];
                }
                if (!($projector instanceof JsonMcpProjector)) {
                    return ['status' => 'error', 'message' => 'adopt is not supported for fenced files (TOML/YAML/Markdown fence edits cannot be parsed back into the hub) — use overwrite or release'];
                }
                if ($serverName === null) return ['status' => 'error', 'message' => 'cannot derive server name from key'];
                if ($theirs === null) {
                    // User deleted the key: adopt the deletion → release + untarget.
                    unset($entry['lastProjectedHash'][$key]);
                    $entry['managedKeys'] = array_values(array_diff($entry['managedKeys'] ?? [], [$key]));
                    self::putLedgerEntry($state, $file, $entry);
                    HubStore::saveState($state);
                    self::untargetAgent($mcp, $projector->agentId(), $serverName);
                    return ['status' => 'ok', 'mode' => 'adopt', 'adopted' => 'deletion'];
                }
                $canonical = self::canonicalFromVendor($theirs, $mcp['servers'][$serverName] ?? null, $projector->agentId());
                if ($canonical === null) {
                    return ['status' => 'error', 'message' => 'on-disk value could not be mapped back to a canonical server definition — use overwrite or release'];
                }
                $errors = [];
                if (!HubStore::saveServer($serverName, $canonical, $errors)) {
                    return ['status' => 'error', 'message' => 'adopt failed: ' . implode('; ', $errors)];
                }
                $entry['lastProjectedHash'][$key] = VendorProjector::valueHash($theirs);
                if (!in_array($key, $entry['managedKeys'] ?? [], true)) $entry['managedKeys'][] = $key;
                $entry['lastRun'] = gmdate('c');
                $state['projections'][$file] = $entry;
                HubStore::saveState($state);
                return ['status' => 'ok', 'mode' => 'adopt', 'adopted' => 'value'];
        }
        // No trailing return: $mode is validated to release|overwrite|adopt
        // before the switch, and every case returns (phpstan-verified).
    }

    // ---------- internals ----------

    /** Store a ledger entry, dropping it entirely when nothing is managed any more. */
    private static function putLedgerEntry(array &$state, string $file, array $entry): void {
        if (empty($entry['managedKeys']) && empty($entry['lastProjectedHash'])) {
            unset($state['projections'][$file]);
        } else {
            $state['projections'][$file] = $entry;
        }
    }

    /**
     * Reconcile one vendor file against a precomputed desired managed-key map
     * (the caller derives it — from enabled MCP servers or from the instruction
     * doc + target set). Mutates $state's entry for the file. With $write=false
     * this is a pure drift probe.
     * @param array<string,mixed> $desired
     * @return array{written:string[],removed:string[],drift:array[]}
     */
    private static function reconcileVendor(VendorProjector $projector, string $home, array $desired, array &$state, bool $write): array {
        $file = $projector->ledgerKey();
        $abs = $projector->absPath($home);
        $agentId = $projector->agentId();

        $entry = $state['projections'][$file] ?? [];
        $managed = is_array($entry['managedKeys'] ?? null) ? $entry['managedKeys'] : [];
        $hashes = is_array($entry['lastProjectedHash'] ?? null) ? $entry['lastProjectedHash'] : [];

        $keys = array_values(array_unique(array_merge(array_keys($desired), $managed)));
        $current = $projector->current($abs, $keys);

        $set = []; $remove = []; $drift = [];
        foreach ($keys as $key) {
            $want = $desired[$key] ?? null;
            $hasCur = array_key_exists($key, $current);
            $cur = $hasCur ? $current[$key] : null;
            $base = $hashes[$key] ?? null;
            $wantHash = ($want !== null) ? VendorProjector::valueHash($want) : null;
            $curHash = $hasCur ? VendorProjector::valueHash($cur) : null;

            if ($want !== null) {
                if (!$hasCur) {
                    if ($base === null) { $set[$key] = $want; }                       // fresh key → write
                    else { $drift[] = self::driftRec($file, $key, $agentId, 'deleted', null, $want); } // user deleted a managed key
                } elseif ($base === null) {
                    if ($curHash === $wantHash) { $hashes[$key] = $wantHash; $managed[] = $key; }      // converged independently
                    else { $drift[] = self::driftRec($file, $key, $agentId, 'unmanaged_conflict', $cur, $want); } // pre-existing user key
                } elseif ($curHash === $base) {
                    if ($wantHash !== $base) { $set[$key] = $want; }                  // hub changed, disk clean → update
                    // else: in sync, nothing to do
                } elseif ($curHash === $wantHash) {
                    $hashes[$key] = $wantHash;                                        // user edit converged with hub
                } else {
                    $drift[] = self::driftRec($file, $key, $agentId, 'modified', $cur, $want);          // genuine 3-way conflict
                }
            } else { // key no longer desired
                if (!$hasCur) {
                    unset($hashes[$key]); $managed = array_values(array_diff($managed, [$key]));        // already gone
                } elseif ($base !== null && $curHash === $base) {
                    $remove[] = $key;                                                 // clean removal of our own write
                } elseif ($base !== null) {
                    $drift[] = self::driftRec($file, $key, $agentId, 'modified', $cur, null);           // user edited what we want to remove
                } else {
                    unset($hashes[$key]); $managed = array_values(array_diff($managed, [$key]));        // never ours — leave the user's key
                }
            }
        }

        $written = []; $removed = [];
        if ($write && (!empty($set) || !empty($remove))) {
            if ($projector->write($abs, $set, $remove)) {
                foreach ($set as $key => $want) {
                    $hashes[$key] = VendorProjector::valueHash($want);
                    if (!in_array($key, $managed, true)) $managed[] = $key;
                    $written[] = $key;
                }
                foreach ($remove as $key) {
                    unset($hashes[$key]);
                    $managed = array_values(array_diff($managed, [$key]));
                    $removed[] = $key;
                }
            } else {
                foreach (array_merge(array_keys($set), $remove) as $key) {
                    $drift[] = self::driftRec($file, $key, $agentId, 'write_failed', $current[$key] ?? null, $desired[$key] ?? null);
                }
                LogService::log("hub project: write refused/failed for $file (unparseable files are never clobbered)", LogService::LOG_WARN, 'HubProjector');
            }
        }

        sort($managed);
        ksort($hashes);
        if (empty($managed) && empty($hashes)) {
            unset($state['projections'][$file]);
        } else {
            $state['projections'][$file] = [
                'managedKeys' => array_values(array_unique($managed)),
                'lastProjectedHash' => $hashes,
                'lastRun' => $write ? gmdate('c') : ($entry['lastRun'] ?? null),
            ];
        }
        return ['written' => $written, 'removed' => $removed, 'drift' => $drift];
    }

    /** Servers enabled for an agent, env {KEY} placeholders resolved for projection. */
    private static function enabledServersFor(array $servers, string $agentId): array {
        $out = [];
        foreach ($servers as $name => $def) {
            if (!in_array($agentId, $def['enabledFor'] ?? [], true)) continue;
            if (!empty($def['env']) && is_array($def['env'])) {
                $def['env'] = self::resolvePlaceholders($def['env']);
            }
            $out[$name] = $def;
        }
        return $out;
    }

    /**
     * Resolve {KEY} placeholders in env values from the SecretService vault at
     * projection time. Resolved values go to vendor files only — the canonical
     * store always keeps the placeholder form. Unknown keys stay literal.
     */
    private static function resolvePlaceholders(array $env): array {
        $secrets = null; // lazy — only read the vault if a placeholder exists
        foreach ($env as $k => $v) {
            $env[$k] = preg_replace_callback('/\{([A-Z][A-Z0-9_]{1,127})\}/', function ($m) use (&$secrets) {
                if ($secrets === null) $secrets = SecretService::getAgentSecrets();
                return array_key_exists($m[1], $secrets) ? $secrets[$m[1]] : $m[0];
            }, (string)$v);
        }
        return $env;
    }

    private static function driftRec(string $file, string $key, string $agentId, string $kind, $theirs, $ours): array {
        return ['file' => $file, 'key' => $key, 'agentId' => $agentId, 'kind' => $kind,
                'theirs' => $theirs, 'ours' => $ours];
    }

    /** Server name behind a managed key (JSON vendors only; fence keys → null). */
    private static function serverNameForKey(VendorProjector $projector, string $key): ?string {
        if (strncmp($key, 'mcpServers.', 11) === 0) {
            $name = substr($key, 11);
            return preg_match(HubStore::NAME_RE, $name) ? $name : null;
        }
        return null;
    }

    /** Remove $agentId from a server's enabledFor (or from ALL servers when $name is null — fence release). */
    private static function untargetAgent(array $mcp, string $agentId, ?string $name): void {
        $changed = false;
        foreach ($mcp['servers'] as $sName => &$def) {
            if ($name !== null && $sName !== $name) continue;
            $before = $def['enabledFor'] ?? [];
            $after = array_values(array_diff($before, [$agentId]));
            if ($after !== $before) { $def['enabledFor'] = $after; $changed = true; }
        }
        unset($def);
        if ($changed) HubStore::saveMcp($mcp);
    }

    /**
     * Map a vendor mcpServers value back to a canonical def (adopt path).
     * Preserves the existing enabledFor; returns null when the shape is not
     * recognisably stdio/http/sse.
     */
    private static function canonicalFromVendor($vendorValue, ?array $existing, string $agentId): ?array {
        $v = VendorProjector::canonicalize($vendorValue);
        if (!is_array($v)) return null;
        $enabledFor = $existing['enabledFor'] ?? [$agentId];
        if (!empty($v['command'])) {
            return ['transport' => 'stdio', 'command' => (string)$v['command'],
                    'args' => array_values(array_map('strval', $v['args'] ?? [])),
                    'env' => is_array($v['env'] ?? null) ? $v['env'] : [],
                    'enabledFor' => $enabledFor];
        }
        $url = (string)($v['url'] ?? $v['httpUrl'] ?? '');
        if ($url !== '') {
            $transport = isset($v['httpUrl']) ? 'http'
                : ((($v['type'] ?? '') === 'sse') ? 'sse' : ((($v['type'] ?? '') === 'http') ? 'http' : 'sse'));
            return ['transport' => $transport, 'url' => $url, 'enabledFor' => $enabledFor];
        }
        return null;
    }
}
