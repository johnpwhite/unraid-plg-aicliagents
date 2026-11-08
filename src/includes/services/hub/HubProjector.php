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
     * Test seam (#667): projectAll() calls it once, after every vendor file is reconciled and
     * just before the ledger is saved. A test uses it to run a Settings save in that window.
     * Always null in production.
     * @var \Closure|null
     */
    public static ?\Closure $beforeLedgerSave = null;

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

    /** Agents with no verified global instruction-file surface. */
    const INSTRUCTION_EXEMPT = [
        'nanocoder' => 'Nanocoder only reads project-local AGENTS.md and non-standard project skills; the hub must not write an invented global file.',
        'grok-build' => 'Grok Build reads project AGENTS.md, not a global instruction file; its global Relay skill is projected instead.',
    ];

    /** Agents with no verified global SKILL.md-compatible surface. */
    const SKILL_EXEMPT = [
        'nanocoder' => 'Nanocoder exposes only project-local non-standard skill.yaml skills.',
    ];

    /** All per-vendor projectors, keyed by agent id. @return array<string,VendorProjector> */
    public static function supportedVendors(): array {
        if (self::$vendors === null) {
            self::$vendors = [];
            foreach ([new ClaudeProjector(), new GeminiProjector(), new QwenProjector(),
                      new OpencodeProjector(), new Opencode2Projector(), new KilocodeProjector(), new AntigravityProjector(),
                      new FactoryProjector(), new NanocoderProjector(),
                      new CopilotProjector(), new CodexProjector(), new GooseProjector(),
                      new GrokProjector(), new KimiCodeProjector()] as $p) {
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
     * one shared file. Claude Code and Kilo Code both AUTO-DISCOVER a rules DIRECTORY
     * instead of reading one shared file — Claude loads every ~/.claude/rules/*.md
     * natively (docs.claude.com), Kilo every ~/.kilo/rules/*.md (verified live via
     * `kilo debug config`) — so each owns one dedicated fence-free file there via
     * RulesFileInstructionProjector, and the hub never writes the user's own
     * ~/.claude/CLAUDE.md. Legacy Claude CLAUDE.md fences from before this migration
     * are stripped by cleanupLegacyClaudeFences() on the next projection.
     * @return array<string,InstructionProjector>
     */
    public static function instructionVendors(): array {
        if (self::$instructionVendors === null) {
            self::$instructionVendors = [];
            foreach ([
                // Claude Code auto-discovers ~/.claude/rules/*.md natively (docs.claude.com
                // "Organize rules with .claude/rules/"), so the hub owns one dedicated
                // fence-free file there and never touches the user's own ~/.claude/CLAUDE.md.
                // useImport=true keeps the content single-sourced via the @import line.
                new RulesFileInstructionProjector('claude-code', '.claude/rules/aicli-hub-global.md', 'Claude Code', ['claude-code'], true),
                new InstructionProjector('gemini-cli', '.gemini/GEMINI.md', 'Gemini CLI', ['gemini-cli', 'antigravity-cli'], false),
                new InstructionProjector('qwen-code', '.qwen/QWEN.md', 'Qwen Code', ['qwen-code'], false),
                new InstructionProjector('codex-cli', '.codex/AGENTS.md', 'Codex CLI', ['codex-cli'], false),
                new InstructionProjector('gh-copilot', '.copilot/copilot-instructions.md', 'GitHub Copilot CLI', ['gh-copilot'], false),
                new InstructionProjector('opencode', '.config/opencode/AGENTS.md', 'OpenCode', ['opencode'], false),
                // OpenCode 2 (#822): own XDG_CONFIG_HOME; global AGENTS.md per https://opencode.ai/v2/docs/instructions/
                new InstructionProjector('opencode2', Opencode2Projector::CONFIG_DIR . '/AGENTS.md', 'OpenCode 2', ['opencode2'], false),
                new InstructionProjector('goose', '.config/goose/.goosehints', 'Goose', ['goose'], false),
                new InstructionProjector('factory-cli', '.factory/AGENTS.md', 'Factory Droid', ['factory-cli'], false),
                new InstructionProjector('pi-coder', '.pi/agent/AGENTS.md', 'pi-coder', ['pi-coder'], false),
                new InstructionProjector('kimi-code', '.kimi-code/AGENTS.md', 'Kimi Code', ['kimi-code'], false),
                // Kilo: dedicated auto-discovered rules file (~/.kilo/rules/*.md), no fence.
                new RulesFileInstructionProjector('kilocode', '.kilo/rules/aicli-hub-global.md', 'Kilo Code', ['kilocode'], false),
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

    /** Text shown on a covered row of the Global Instructions card (#798). */
    const INSTRUCTION_COVERED_TEXT = 'Covered: already reads these instructions through ~/.claude';

    /**
     * Agents that already read the hub instruction document through ~/.claude, so a
     * tick would only duplicate it (#798). Claude Code reads ~/.claude/CLAUDE.md (which
     * imports the hub document) and ~/.claude/rules/common/*.md. OpenCode loads the same
     * files through the `instructions` list of ~/.config/opencode/opencode.json when that
     * list names ~/.claude/CLAUDE.md. Read-only: it never writes under $home.
     * @return array<string,string> agentId => reason text, only for covered agents
     */
    public static function instructionCoverage(string $home): array {
        $home = rtrim($home, '/');
        $out = [];
        if ($home === '') return $out;
        if (is_file($home . '/.claude/CLAUDE.md') || is_dir($home . '/.claude/rules/common')) {
            $out['claude-code'] = self::INSTRUCTION_COVERED_TEXT;
        }
        $json = @file_get_contents($home . '/.config/opencode/opencode.json');
        $cfg = is_string($json) ? json_decode($json, true) : null;
        if (is_array($cfg) && is_array($cfg['instructions'] ?? null)) {
            foreach ($cfg['instructions'] as $entry) {
                if (!is_string($entry)) continue;
                if ($entry === '~/.claude/CLAUDE.md' || $entry === $home . '/.claude/CLAUDE.md'
                    || $entry === '$HOME/.claude/CLAUDE.md' || $entry === '{env:HOME}/.claude/CLAUDE.md') {
                    $out['opencode'] = self::INSTRUCTION_COVERED_TEXT;
                    break;
                }
            }
        }
        return $out;
    }

    /** @var FilePathConventionProjector[]|null */
    private static $policyInstructionVendors = null;

    /** @var RelayInstructionProjector[]|null */
    private static $relayInstructionVendors = null;

    /**
     * Always-on Relay guidance. This intentionally mirrors only agents with a
     * verified global instruction surface. Nanocoder has project-local-only
     * AGENTS.md/skills and therefore cannot safely receive a global projection;
     * Grok Build has no global instruction file but receives the Relay skill.
     * @return array<string,RelayInstructionProjector>
     */
    public static function relayInstructionVendors(): array {
        if (self::$relayInstructionVendors === null) {
            self::$relayInstructionVendors = [];
            foreach ([
                new RulesFileRelayProjector('claude-code', '.claude/rules/aicli-relay.md', 'Claude Code', ['claude-code'], false),
                new RelayInstructionProjector('gemini-cli', '.gemini/GEMINI.md', 'Gemini CLI', ['gemini-cli', 'antigravity-cli'], false),
                new RelayInstructionProjector('qwen-code', '.qwen/QWEN.md', 'Qwen Code', ['qwen-code'], false),
                new RelayInstructionProjector('codex-cli', '.codex/AGENTS.md', 'Codex CLI', ['codex-cli'], false),
                new RelayInstructionProjector('gh-copilot', '.copilot/copilot-instructions.md', 'GitHub Copilot CLI', ['gh-copilot'], false),
                new RelayInstructionProjector('opencode', '.config/opencode/AGENTS.md', 'OpenCode', ['opencode'], false),
                new RelayInstructionProjector('opencode2', Opencode2Projector::CONFIG_DIR . '/AGENTS.md', 'OpenCode 2', ['opencode2'], false),
                new RelayInstructionProjector('goose', '.config/goose/.goosehints', 'Goose', ['goose'], false),
                new RelayInstructionProjector('factory-cli', '.factory/AGENTS.md', 'Factory Droid', ['factory-cli'], false),
                new RelayInstructionProjector('pi-coder', '.pi/agent/AGENTS.md', 'pi-coder', ['pi-coder'], false),
                new RelayInstructionProjector('kimi-code', '.kimi-code/AGENTS.md', 'Kimi Code', ['kimi-code'], false),
                new RulesFileRelayProjector('kilocode', '.kilo/rules/aicli-relay.md', 'Kilo Code', ['kilocode'], false),
            ] as $p) self::$relayInstructionVendors[$p->agentId()] = $p;
        }
        return self::$relayInstructionVendors;
    }

    /**
     * Always-on "file-path convention" policy projectors (see
     * docs/specs/AGENT_FILE_PATH_CONVENTION.md), one per agent MIRRORING
     * instructionVendors() (same primary agentId / relPath / servedAgentIds
     * — the fence lands in the SAME instruction file, right beside the
     * aicli-hub fence), EXCEPT Kilo, which has no shared fenced file and
     * gets its own dedicated ~/.kilo/rules/aicli-file-paths.md (distinct
     * from the hub-instructions dedicated ~/.kilo/rules/aicli-hub-global.md).
     * Unlike instructionVendors(), this content is policy, not user-supplied
     * — see FilePathConventionProjector::desired(), which ignores its input
     * and always returns the constant block.
     * @return array<string,FilePathConventionProjector>
     */
    public static function policyInstructionVendors(): array {
        if (self::$policyInstructionVendors === null) {
            self::$policyInstructionVendors = [];
            foreach ([
                new RulesFileFilePathProjector('claude-code', '.claude/rules/aicli-file-paths.md', 'Claude Code', ['claude-code'], false),
                new FilePathConventionProjector('gemini-cli', '.gemini/GEMINI.md', 'Gemini CLI', ['gemini-cli', 'antigravity-cli'], false),
                new FilePathConventionProjector('qwen-code', '.qwen/QWEN.md', 'Qwen Code', ['qwen-code'], false),
                new FilePathConventionProjector('codex-cli', '.codex/AGENTS.md', 'Codex CLI', ['codex-cli'], false),
                new FilePathConventionProjector('gh-copilot', '.copilot/copilot-instructions.md', 'GitHub Copilot CLI', ['gh-copilot'], false),
                new FilePathConventionProjector('opencode', '.config/opencode/AGENTS.md', 'OpenCode', ['opencode'], false),
                new FilePathConventionProjector('opencode2', Opencode2Projector::CONFIG_DIR . '/AGENTS.md', 'OpenCode 2', ['opencode2'], false),
                new FilePathConventionProjector('goose', '.config/goose/.goosehints', 'Goose', ['goose'], false),
                new FilePathConventionProjector('factory-cli', '.factory/AGENTS.md', 'Factory Droid', ['factory-cli'], false),
                new FilePathConventionProjector('pi-coder', '.pi/agent/AGENTS.md', 'pi-coder', ['pi-coder'], false),
                new FilePathConventionProjector('kimi-code', '.kimi-code/AGENTS.md', 'Kimi Code', ['kimi-code'], false),
                // Kilo: dedicated fence-free file, distinct from the hub-instructions dedicated file.
                new RulesFileFilePathProjector('kilocode', '.kilo/rules/aicli-file-paths.md', 'Kilo Code', ['kilocode'], false),
            ] as $p) {
                self::$policyInstructionVendors[$p->agentId()] = $p;
            }
        }
        return self::$policyInstructionVendors;
    }

    /** @var OpencodeClaudeInstructionsProjector[]|null */
    private static $claudeCompatVendors = null;

    /**
     * Forgejo #311 — agents that read the user's ~/.claude/CLAUDE.md only as a
     * fallback that the hub's own instruction file switches off. OpenCode loads
     * ~/.claude/CLAUDE.md only when ~/.config/opencode/AGENTS.md does not exist
     * (opencode.ai/docs/rules; packages/opencode/src/session/instruction.ts), and the
     * hub always writes that AGENTS.md. The projector re-adds the user's Claude
     * instructions (and every *.md under ~/.claude/rules/ except the plugin's own
     * aicli-*.md) through OpenCode's `instructions` array in opencode.json.
     * Kilo Code is NOT listed: it has the same fallback (Kilo-Org/kilocode
     * packages/opencode/src/session/instruction.ts: KILO_CONFIG_DIR/AGENTS.md, then
     * ~/.config/kilo/AGENTS.md, then ~/.claude/CLAUDE.md), but the hub writes Kilo's
     * guidance to ~/.kilo/rules/*.md and never creates ~/.config/kilo/AGENTS.md, so
     * the hub does not switch Kilo's fallback off.
     * @return array<string,OpencodeClaudeInstructionsProjector>
     */
    public static function claudeCompatVendors(): array {
        if (self::$claudeCompatVendors === null) {
            self::$claudeCompatVendors = [];
            foreach ([new OpencodeClaudeInstructionsProjector()] as $p) {
                self::$claudeCompatVendors[$p->agentId()] = $p;
            }
        }
        return self::$claudeCompatVendors;
    }

    /**
     * The environment that decides whether OpenCode's Claude compatibility is off:
     * the web process env, overlaid with the AGENT-WIDE general env vars the user set
     * for this agent (the file EnvService::getAgentEnvPath() names — read directly
     * from the projected home, which IS the user-state home, so this never triggers a
     * mount). A PER-WORKSPACE env var cannot be honoured here: opencode.json is one
     * file shared by every OpenCode workspace. That limit is documented in
     * docs/specs/AGENT_CONFIG_HUB.md (2026-09-23 section).
     * @return array<string,string>
     */
    private static function claudeCompatEnv(string $home, string $agentId): array {
        $env = [];
        foreach (OpencodeClaudeInstructionsProjector::DISABLE_ENV as $k) {
            $v = getenv($k);
            if ($v !== false) $env[$k] = (string)$v;
        }
        $file = rtrim($home, '/') . "/.aicli/envs/env_agent_{$agentId}.json";
        if (is_file($file)) {
            $map = json_decode((string)@file_get_contents($file), true);
            if (is_array($map)) {
                foreach ($map as $k => $v) {
                    if (is_string($k) && is_scalar($v)) $env[$k] = (string)$v;
                }
            }
        }
        return $env;
    }

    /**
     * Reconcile OpenCode's managed Claude instruction entries for every in-scope
     * agent. System-managed like the Relay guidance (restoreDeleted on write): a
     * lost entry is written again. To opt out, set OPENCODE_DISABLE_CLAUDE_CODE or
     * OPENCODE_DISABLE_CLAUDE_CODE_PROMPT for the agent — the desired set is then
     * empty and the ledger removes the entries this hub wrote.
     */
    private static function reconcileClaudeCompat(string $home, array &$state, array $scope, bool $write,
                                                  array &$results, array &$driftAll, array &$writtenAgents): void {
        foreach (self::claudeCompatVendors() as $agentId => $projector) {
            if (!in_array($agentId, $scope, true)) continue;
            $desired = $projector->desiredFor($home, self::claudeCompatEnv($home, $agentId));
            $r = self::reconcileVendor($projector, $home, $desired, $state, $write, $write);
            $results[$projector->ledgerKey()] = ['agentId' => $agentId, 'written' => $r['written'],
                                                'removed' => $r['removed'], 'drift' => $r['drift']];
            foreach ($r['drift'] as $d) $driftAll[] = $d;
            if ((!empty($r['written']) || !empty($r['removed'])) && !in_array($agentId, $writtenAgents, true)) {
                $writtenAgents[] = $agentId;
            }
        }
    }

    /** @var RelaySkillProjector[]|null */
    private static $relaySkillVendors = null;

    /**
     * Every registered agent except Nanocoder has a verified global skills
     * surface. Derive the Relay skill targets from the existing official-path
     * skill registry so a future skill-path change cannot silently diverge.
     * @return RelaySkillProjector[]
     */
    public static function relaySkillVendors(): array {
        if (self::$relaySkillVendors === null) {
            self::$relaySkillVendors = [];
            foreach (self::treeVendors() as $p) {
                if ($p->surface() !== 'skills') continue;
                self::$relaySkillVendors[] = new RelaySkillProjector(
                    $p->agentId(), $p->relPath(), $p->label(), 'skills', $p->servedAgentIds()
                );
            }
        }
        return self::$relaySkillVendors;
    }

    /** @var AdminSkillProjector[]|null */
    private static $adminSkillVendors = null;

    /**
     * Plugin-management skill targets. Derived from the same official-path skill
     * registry as the Relay skill, for the same reason: a future skill-path change
     * must not silently diverge between the two skills we ship.
     *
     * Unlike the Relay skill this one is NOT always-on. It describes tools that stay
     * off until an administrator turns them on, so projecting it unconditionally
     * would teach every agent on the box to call something that answers "turned
     * off". Both projection passes therefore hand it an EMPTY desired set while the
     * feature is disabled, which makes the ledger REMOVE a previously written copy
     * rather than strand it. Spec: docs/specs/PLUGIN_MANAGEMENT_TOOLS.md
     * @return AdminSkillProjector[]
     */
    public static function adminSkillVendors(): array {
        if (self::$adminSkillVendors === null) {
            self::$adminSkillVendors = [];
            foreach (self::treeVendors() as $p) {
                if ($p->surface() !== 'skills') continue;
                self::$adminSkillVendors[] = new AdminSkillProjector(
                    $p->agentId(), $p->relPath(), $p->label(), 'skills', $p->servedAgentIds()
                );
            }
        }
        return self::$adminSkillVendors;
    }

    /**
     * Is the plugin-management skill wanted right now? Kept as one helper so the
     * two projection passes cannot drift apart on the answer.
     */
    private static function adminSkillWanted(): bool {
        return class_exists('\AICliAgents\Services\AdminMcpTools')
            && \AICliAgents\Services\AdminMcpTools::anyEnabled(); // each skill then follows its own switch (AdminSkillProjector::filesFor)
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
                new TreeProjector('codex-cli', '.agents/skills', 'Agent Skills (Codex · Gemini · OpenCode · OpenCode 2 · Copilot · Goose)', 'skills',
                    ['codex-cli', 'gemini-cli', 'opencode', 'opencode2', 'gh-copilot', 'goose']),
                // Factory personal skills at ~/.factory/skills/<name>/SKILL.md (official docs).
                new TreeProjector('factory-cli', '.factory/skills', 'Factory Droid skills', 'skills'),
                // pi-coder global skills at ~/.pi/agent/skills/<name>/SKILL.md (official pi docs) —
                // pi is MCP-exempt but DOES read the SKILL.md standard.
                new TreeProjector('pi-coder', '.pi/agent/skills', 'pi-coder skills', 'skills'),
                new TreeProjector('grok-build', '.grok/skills', 'Grok Build skills', 'skills'),
                new TreeProjector('kimi-code', '.kimi-code/skills', 'Kimi Code skills', 'skills'),
                // OpenCode commands live under the XDG config root, not ~/.opencode (corrected 2026-06).
                new TreeProjector('opencode', '.config/opencode/commands', 'OpenCode commands', 'commands'),
                // OpenCode 2 (#822): commands folder of its own config root (https://opencode.ai/v2/docs/commands/).
                new TreeProjector('opencode2', Opencode2Projector::CONFIG_DIR . '/commands', 'OpenCode 2 commands', 'commands'),
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
                new TreeProjector('grok-build', '.agents/commands', 'Grok Build commands', 'commands'),
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
    public static function resolveHome(?string $homeOverride = null): array {
        if ($homeOverride !== null) {
            $home = rtrim($homeOverride, '/');
            if ($home === '' || !is_dir($home) || is_link($home)) {
                return ['ok' => false, 'home' => $home, 'error' => 'home_unavailable'];
            }
            if (defined('AICLI_TEST_TMP') || StorageMountService::isMounted($home)) {
                return ['ok' => true, 'home' => $home];
            }
            return ['ok' => false, 'home' => $home, 'error' => 'home_unavailable'];
        }
        $env = getenv('AICLI_HUB_HOME');
        if ($env !== false && $env !== '') {
            return ['ok' => true, 'home' => rtrim($env, '/')];
        }
        $config = ConfigService::getConfig();
        $user = (string)($config['user'] ?? 'root');
        if ($user === '') $user = 'root';
        $home = \AICliAgents\Services\SecretPaths::stateRoot() . "/work/$user/home";
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
    /**
     * Re-assert one agent's managed configuration immediately before its
     * workspace launches.
     *
     * MCP clients read their server list once, at startup. Projection otherwise
     * runs only when an administrator toggles a Hub or Relay setting, so a
     * workspace starting later inherits whatever happens to be on disk —
     * including nothing, when that toggle-time projection returned
     * 'home_unavailable' because the overlay was not mounted yet. Nothing
     * retried it, while the setting still read "enabled".
     *
     * Callers are launch paths, so this never throws and never blocks: a
     * failure here costs the workspace its managed tools, not its session.
     */
    public static function projectForLaunch(string $agentId, ?array $installedOverride = null): array {
        // The raw terminal has no registry entry and no managed config surface.
        if ($agentId === '' || $agentId === 'terminal') {
            return ['status' => 'skipped', 'reason' => 'no_managed_agent'];
        }
        try {
            $result = self::projectAll([$agentId], $installedOverride);
        } catch (\Throwable $e) {
            \AICliAgents\Services\LogService::log(
                "Hub projection before launch of '$agentId' failed: " . $e->getMessage(),
                \AICliAgents\Services\LogService::LOG_WARN,
                "HubProjector"
            );
            return ['status' => 'error', 'reason' => 'exception', 'message' => $e->getMessage()];
        }
        if (($result['status'] ?? '') !== 'ok') {
            \AICliAgents\Services\LogService::log(
                "Hub projection before launch of '$agentId' did not apply: " . (string)($result['reason'] ?? 'unknown'),
                \AICliAgents\Services\LogService::LOG_WARN,
                "HubProjector"
            );
        }
        return $result;
    }

    /** Project user Config Hub choices into the restricted run user's home only. */
    public static function projectRunUserForLaunch(string $agentId, ?array $installedOverride = null, ?string $homeOverride = null): array {
        if ($agentId === '' || $agentId === 'terminal') return ['status' => 'skipped', 'reason' => 'no_managed_agent'];
        $home = $homeOverride ?? (\AICliAgents\Services\SecretPaths::stateRoot() . '/work/aicli-run/home');
        try {
            $result = self::projectAll([$agentId], $installedOverride, $home, true);
        } catch (\Throwable $e) {
            \AICliAgents\Services\LogService::log(
                "Run-user Hub projection before launch of '$agentId' failed: " . $e->getMessage(),
                \AICliAgents\Services\LogService::LOG_WARN,
                'HubProjector'
            );
            return ['status' => 'error', 'reason' => 'exception', 'message' => $e->getMessage()];
        }
        if (($result['status'] ?? '') !== 'ok') {
            \AICliAgents\Services\LogService::log(
                "Run-user Hub projection before launch of '$agentId' did not apply: " . (string)($result['reason'] ?? 'unknown'),
                \AICliAgents\Services\LogService::LOG_WARN,
                'HubProjector'
            );
        }
        return $result;
    }

    /** True while projectAll() runs for the restricted run user: no target may pass a link (finding 4). */
    private static bool $refuseLinks = false;

    public static function projectAll(?array $targetAgentIds = null, ?array $installedOverride = null, ?string $homeOverride = null, bool $restrictedRunUser = false): array {
        $previous = self::$refuseLinks;
        self::$refuseLinks = $restrictedRunUser;
        try {
            return self::projectAllUnchecked($targetAgentIds, $installedOverride, $homeOverride, $restrictedRunUser);
        } finally {
            self::$refuseLinks = $previous;
        }
    }

    /** True when $abs, or any folder between $home and $abs, is a link. Nothing is followed. */
    private static function passesLink(string $home, string $abs): bool {
        $home = rtrim($home, '/');
        if ($abs === $home || strpos($abs, $home . '/') !== 0) return true;   // outside the home: refuse
        $path = $home;
        foreach (explode('/', substr($abs, strlen($home) + 1)) as $part) {
            if ($part === '' || $part === '.' || $part === '..') return true;
            $path .= '/' . $part;
            $st = @lstat($path);
            if ($st === false) return false;                                  // nothing there yet: nothing to follow
            if (($st['mode'] & 0170000) === 0120000) return true;
        }
        return false;
    }

    private static function projectAllUnchecked(?array $targetAgentIds, ?array $installedOverride, ?string $homeOverride, bool $restrictedRunUser): array {
        $homeInfo = self::resolveHome($homeOverride);
        if (!$homeInfo['ok']) {
            return ['status' => 'error', 'reason' => 'home_unavailable',
                    'message' => 'The agent home overlay is not mounted — start a session or the array, then retry. Projection never writes to an unmounted home.'];
        }
        $home = $homeInfo['home'];
        // A damaged store file reads as empty; projecting that would remove every
        // managed entry from every vendor file (#641). Stop and say why.
        $problem = HubStore::storeProblem();
        if ($problem !== null) {
            LogService::log('hub project stopped: ' . $problem, LogService::LOG_ERROR, 'HubProjector');
            return ['status' => 'error', 'reason' => 'store_corrupt', 'message' => $problem, 'drift' => []];
        }
        $servers = HubStore::getMcp()['servers'];
        $state = HubStore::getState();
        $runUserLedger = '';
        if ($restrictedRunUser) {
            $runUserLedger = rtrim($home, '/') . '/.aicli-hub-projections.json';
            $state['projections'] = HubStore::getLedgerFile($runUserLedger);
        }

        $installed = $installedOverride ?? self::installedSupportedAgentIds();
        $targets =($targetAgentIds === null) ? $installed : array_values(array_intersect($targetAgentIds, $installed));

        $results = [];
        $driftAll = [];
        $writtenAgents = [];

        // Migration: strip the pre-rules-dir Claude CLAUDE.md fences before projecting.
        if (in_array('claude-code', $targets, true) && self::cleanupLegacyClaudeFences($home, $state)) {
            $writtenAgents[] = 'claude-code';
        }

        foreach (self::supportedVendors() as $agentId => $projector) {
            if (!in_array($agentId, $targets, true)) continue;
            $agentServers = self::enabledServersFor($servers, $agentId);
            if ($restrictedRunUser) {
                unset($agentServers['aicli-admin'], $agentServers['aicli-relay']);
            }
            $desired = $projector->desired($agentServers);
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
            // #589: the fence is wanted when ANY installed reader of the file is
            // targeted, not only a reader in this launch scope. A one-agent scope
            // must never remove a fence that another agent still uses.
            $targeted = array_values(array_intersect($projector->servedAgentIds(), $installed, $instructionTargets));
            $desired = empty($targeted) ? [] : $projector->desired(['content' => $content]);
            // Forgejo #245: a DEDICATED rules file (~/.claude/rules/aicli-hub-global.md,
            // ~/.kilo/rules/…) is owned whole by the hub — the user has no content in
            // it, so a missing one was lost (a storage event, a home restore), not
            // deliberately deleted: write it again. A fenced block inside the user's
            // OWN instruction file keeps the "user deleted it" respect (drift).
            $restoreLost = $projector instanceof RulesFileInstructionProjector;
            $r = self::reconcileVendor($projector, $home, $desired, $state, true, $restoreLost);
            $results[$projector->ledgerKey()] = ['agentId' => $projector->agentId(), 'written' => $r['written'],
                                                'removed' => $r['removed'], 'drift' => $r['drift']];
            foreach ($r['drift'] as $d) $driftAll[] = $d;
            if (!empty($r['written']) || !empty($r['removed'])) {
                foreach ($serving as $id) {
                    if (!in_array($id, $writtenAgents, true)) $writtenAgents[] = $id;
                }
            }
        }

        // File-path-convention POLICY pass — always-on (NOT gated by
        // instructions_enabledFor), reconciled for every targeted agent that
        // has a policy surface. Own ledger key per projector (see
        // FilePathConventionProjector::ledgerKey()) so it never collides with
        // the instruction pass's bookkeeping for the SAME physical file.
        foreach (self::policyInstructionVendors() as $projector) {
            $serving = array_values(array_intersect($projector->servedAgentIds(), $targets));
            if (empty($serving)) continue;
            $desired = $projector->desired([]); // always-on: input ignored
            // Forgejo #245 (AGENT_FILE_PATH_CONVENTION.md: "re-asserted by the hub
            // projection so it self-heals"): system policy, like the Relay guidance,
            // so a lost file or block is written again. Without restoreDeleted a
            // rules file lost once stayed lost for ever: every run logged `drift=…`
            // and every Claude session ran without the rule.
            $r = self::reconcileVendor($projector, $home, $desired, $state, true, true);
            $results[$projector->ledgerKey()] = ['agentId' => $projector->agentId(), 'written' => $r['written'],
                                                'removed' => $r['removed'], 'drift' => $r['drift']];
            foreach ($r['drift'] as $d) $driftAll[] = $d;
            if (!empty($r['written']) || !empty($r['removed'])) {
                foreach ($serving as $id) {
                    if (!in_array($id, $writtenAgents, true)) $writtenAgents[] = $id;
                }
            }
        }

        foreach (self::relayInstructionVendors() as $projector) {
            $serving = array_values(array_intersect($projector->servedAgentIds(), $targets));
            if (empty($serving)) continue;
            $desired = $restrictedRunUser ? [] : $projector->desired([]);
            $r = self::reconcileVendor($projector, $home, $desired, $state, true, true);
            $results[$projector->ledgerKey()] = ['agentId' => $projector->agentId(), 'written' => $r['written'], 'removed' => $r['removed'], 'drift' => $r['drift']];
            foreach ($r['drift'] as $d) $driftAll[] = $d;
            if (!empty($r['written']) || !empty($r['removed'])) foreach ($serving as $id) if (!in_array($id, $writtenAgents, true)) $writtenAgents[] = $id;
        }

        // Forgejo #311: runs AFTER the passes that write ~/.config/opencode/AGENTS.md,
        // because that file's existence is what switches OpenCode's CLAUDE.md fallback off.
        self::reconcileClaudeCompat($home, $state, $targets, true, $results, $driftAll, $writtenAgents);

        foreach (self::relaySkillVendors() as $projector) {
            $serving = array_values(array_intersect($projector->servedAgentIds(), $targets));
            if (empty($serving)) continue;
            // R18: the run user gets no Relay skill (an empty desired set also removes one that was projected before).
            $r = self::reconcileVendor($projector, $home, $restrictedRunUser ? [] : $projector->desired([]), $state, true, true);
            $results[$projector->ledgerKey()] = ['agentId' => $projector->agentId(), 'written' => $r['written'], 'removed' => $r['removed'], 'drift' => $r['drift']];
            foreach ($r['drift'] as $d) $driftAll[] = $d;
            if (!empty($r['written']) || !empty($r['removed'])) foreach ($serving as $id) if (!in_array($id, $writtenAgents, true)) $writtenAgents[] = $id;
        }

        // Plugin-management skill. An empty desired set while the feature is off is
        // deliberate: it removes a copy written when it was on, so switching the
        // feature off also withdraws the instructions that describe it.
        $adminWanted = !$restrictedRunUser && self::adminSkillWanted();
        foreach (self::adminSkillVendors() as $projector) {
            $serving = array_values(array_intersect($projector->servedAgentIds(), $targets));
            if (empty($serving)) continue;
            $r = self::reconcileVendor($projector, $home, $adminWanted ? $projector->desired([]) : [], $state, true, true);
            $results[$projector->ledgerKey()] = ['agentId' => $projector->agentId(), 'written' => $r['written'], 'removed' => $r['removed'], 'drift' => $r['drift']];
            foreach ($r['drift'] as $d) $driftAll[] = $d;
            if (!empty($r['written']) || !empty($r['removed'])) foreach ($serving as $id) if (!in_array($id, $writtenAgents, true)) $writtenAgents[] = $id;
        }

        // Skills/commands tree pass (H-03) — same ledger/drift surface, third
        // set of vendor surfaces (mirrored file trees per agent dir).
        $tree = self::reconcileTrees($home, $state, $targets, true, $installed);
        foreach ($tree['results'] as $k => $r) $results[$k] = $r;
        foreach ($tree['drift'] as $d) $driftAll[] = $d;
        foreach ($tree['writtenAgents'] as $id) {
            if (!in_array($id, $writtenAgents, true)) $writtenAgents[] = $id;
        }

        if (self::$beforeLedgerSave !== null) (self::$beforeLedgerSave)();
        if ($restrictedRunUser) HubStore::saveLedgerFile($state, $runUserLedger);
        else HubStore::saveLedger($state);
        LogService::log('hub project: agents=[' . implode(',', $targets) . '] changed=[' . implode(',', $writtenAgents) . '] drift=' . count($driftAll), LogService::LOG_INFO, 'HubProjector');
        if (!$restrictedRunUser && !empty($writtenAgents)) {
            // H-04 (#1365): debounced auto-commit AFTER the vendor writes — agent ids only.
            GitHomeService::commitIfEnabled('hub: projected to [' . implode(',', $writtenAgents) . ']');
        }
        return ['status' => 'ok', 'results' => $results, 'drift' => $driftAll, 'writtenAgents' => $writtenAgents];
    }

    /**
     * Project ONLY the always-on file-path-convention policy block (see
     * docs/specs/AGENT_FILE_PATH_CONVENTION.md), scoped to the given agent
     * id(s) — used by InstallerService::installAgent() so a freshly
     * installed/upgraded agent gets the block immediately without running a
     * full projectAll() pass. Unconditional: never gated by
     * instructions_enabledFor (it is policy, not user-toggled content).
     * Same resolveHome()/reconcileVendor()/saveState() machinery as
     * projectAll(), so it no-ops safely (status=error/home_unavailable) on
     * an unmounted home, exactly like projectAll().
     *
     * @param string[]|null $targetAgentIds restrict to these agents (null = all installed).
     * @param string[]|null $installedOverride test hook — bypass the AgentRegistry install gate.
     * @return array status=ok|error; per-file results {written,removed,drift}; flat drift list; written agent ids.
     */
    public static function projectPolicy(?array $targetAgentIds = null, ?array $installedOverride = null): array {
        $homeInfo = self::resolveHome();
        if (!$homeInfo['ok']) {
            return ['status' => 'error', 'reason' => 'home_unavailable',
                    'message' => 'The agent home overlay is not mounted — start a session or the array, then retry. Projection never writes to an unmounted home.'];
        }
        $home = $homeInfo['home'];
        $problem = HubStore::stateProblem();
        if ($problem !== null) {
            LogService::log('hub policy projection stopped: ' . $problem, LogService::LOG_ERROR, 'HubProjector');
            return ['status' => 'error', 'reason' => 'store_corrupt', 'message' => $problem, 'drift' => []];
        }
        $state = HubStore::getState();

        $installed = $installedOverride ?? self::installedSupportedAgentIds();
        $targets = ($targetAgentIds === null) ? $installed : array_values(array_intersect($targetAgentIds, $installed));

        $results = [];
        $driftAll = [];
        $writtenAgents = [];

        // Migration: strip the pre-rules-dir Claude CLAUDE.md fences before projecting.
        if (in_array('claude-code', $targets, true) && self::cleanupLegacyClaudeFences($home, $state)) {
            $writtenAgents[] = 'claude-code';
        }

        foreach (self::policyInstructionVendors() as $projector) {
            $serving = array_values(array_intersect($projector->servedAgentIds(), $targets));
            if (empty($serving)) continue;
            $desired = $projector->desired([]); // always-on: input ignored
            // Forgejo #245 (AGENT_FILE_PATH_CONVENTION.md: "re-asserted by the hub
            // projection so it self-heals"): system policy, like the Relay guidance,
            // so a lost file or block is written again. Without restoreDeleted a
            // rules file lost once stayed lost for ever: every run logged `drift=…`
            // and every Claude session ran without the rule.
            $r = self::reconcileVendor($projector, $home, $desired, $state, true, true);
            $results[$projector->ledgerKey()] = ['agentId' => $projector->agentId(), 'written' => $r['written'],
                                                'removed' => $r['removed'], 'drift' => $r['drift']];
            foreach ($r['drift'] as $d) $driftAll[] = $d;
            if (!empty($r['written']) || !empty($r['removed'])) {
                foreach ($serving as $id) {
                    if (!in_array($id, $writtenAgents, true)) $writtenAgents[] = $id;
                }
            }
        }

        foreach (self::relayInstructionVendors() as $projector) {
            $serving = array_values(array_intersect($projector->servedAgentIds(), $targets));
            if (empty($serving)) continue;
            $r = self::reconcileVendor($projector, $home, $projector->desired([]), $state, true, true);
            $results[$projector->ledgerKey()] = ['agentId' => $projector->agentId(), 'written' => $r['written'], 'removed' => $r['removed'], 'drift' => $r['drift']];
            foreach ($r['drift'] as $d) $driftAll[] = $d;
            if (!empty($r['written']) || !empty($r['removed'])) foreach ($serving as $id) if (!in_array($id, $writtenAgents, true)) $writtenAgents[] = $id;
        }

        // Forgejo #311: runs AFTER the passes that write ~/.config/opencode/AGENTS.md,
        // because that file's existence is what switches OpenCode's CLAUDE.md fallback off.
        self::reconcileClaudeCompat($home, $state, $targets, true, $results, $driftAll, $writtenAgents);

        foreach (self::relaySkillVendors() as $projector) {
            $serving = array_values(array_intersect($projector->servedAgentIds(), $targets));
            if (empty($serving)) continue;
            $r = self::reconcileVendor($projector, $home, $projector->desired([]), $state, true, true);
            $results[$projector->ledgerKey()] = ['agentId' => $projector->agentId(), 'written' => $r['written'], 'removed' => $r['removed'], 'drift' => $r['drift']];
            foreach ($r['drift'] as $d) $driftAll[] = $d;
            if (!empty($r['written']) || !empty($r['removed'])) foreach ($serving as $id) if (!in_array($id, $writtenAgents, true)) $writtenAgents[] = $id;
        }

        // Plugin-management skill. An empty desired set while the feature is off is
        // deliberate: it removes a copy written when it was on, so switching the
        // feature off also withdraws the instructions that describe it.
        $adminWanted = self::adminSkillWanted();
        foreach (self::adminSkillVendors() as $projector) {
            $serving = array_values(array_intersect($projector->servedAgentIds(), $targets));
            if (empty($serving)) continue;
            $r = self::reconcileVendor($projector, $home, $adminWanted ? $projector->desired([]) : [], $state, true, true);
            $results[$projector->ledgerKey()] = ['agentId' => $projector->agentId(), 'written' => $r['written'], 'removed' => $r['removed'], 'drift' => $r['drift']];
            foreach ($r['drift'] as $d) $driftAll[] = $d;
            if (!empty($r['written']) || !empty($r['removed'])) foreach ($serving as $id) if (!in_array($id, $writtenAgents, true)) $writtenAgents[] = $id;
        }

        HubStore::saveLedger($state);
        LogService::log('hub policy project: agents=[' . implode(',', $targets) . '] changed=[' . implode(',', $writtenAgents) . '] drift=' . count($driftAll), LogService::LOG_INFO, 'HubProjector');
        if (!empty($writtenAgents)) {
            GitHomeService::commitIfEnabled('hub: file-path policy projected to [' . implode(',', $writtenAgents) . ']');
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
        $problem = HubStore::storeProblem();
        if ($problem !== null) {
            return ['status' => 'error', 'reason' => 'store_corrupt', 'message' => $problem, 'drift' => []];
        }
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

        // array_values() first: policyInstructionVendors() and relayInstructionVendors()
        // are BOTH keyed by agentId and cover the identical agent set, so a bare
        // array_merge() of the two associative arrays lets every relay projector
        // silently overwrite its same-agentId policy counterpart — detectDrift()
        // would then never reconcile (or report drift on) any file-path-convention
        // policy file, for any agent, ever. Found 2026-09-15: a policy file deleted
        // in a prior incident stayed invisible to this drift report indefinitely.
        foreach (array_merge(array_values(self::policyInstructionVendors()), array_values(self::relayInstructionVendors()), self::relaySkillVendors()) as $projector) {
            $serving = array_values(array_intersect($projector->servedAgentIds(), $installed));
            if (empty($serving)) continue;
            $r = self::reconcileVendor($projector, $home, $projector->desired([]), $state, false);
            foreach ($r['drift'] as $d) $driftAll[] = $d;
        }

        // Plugin-management (Admin) skill, read-only. Gated like the write pass, so a
        // feature that is off reports a leftover copy as drift (#640).
        $adminWanted = self::adminSkillWanted();
        foreach (self::adminSkillVendors() as $projector) {
            $serving = array_values(array_intersect($projector->servedAgentIds(), $installed));
            if (empty($serving)) continue;
            $r = self::reconcileVendor($projector, $home, $adminWanted ? $projector->desired([]) : [], $state, false);
            foreach ($r['drift'] as $d) $driftAll[] = $d;
        }

        // Forgejo #311: OpenCode's managed Claude instruction entries — read-only, same drift surface.
        $unusedResults = []; $unusedWritten = [];
        self::reconcileClaudeCompat($home, $state, $installed, false, $unusedResults, $driftAll, $unusedWritten);

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
    private static function reconcileTrees(string $home, array &$state, array $scope, bool $write, ?array $installed = null): array {
        $results = []; $drift = []; $writtenAgents = [];
        $installed = $installed ?? $scope; // every installed reader decides what is wanted (#589)
        $fileMaps = []; // surface => relPath=>content, built lazily
        foreach (self::treeVendors() as $projector) {
            // A shared dir (~/.agents/skills) lists several readers; reconcile it once
            // when ANY reader is in scope, and DESIRE files when ANY reader is targeted —
            // so un-targeting one reader never removes a file another still wants.
            $serving = array_values(array_intersect($projector->servedAgentIds(), $scope));
            if (empty($serving)) continue;
            $surface = $projector->surface();
            $surfaceTargets = $state[$surface . '_enabledFor'] ?? [];
            $targeted = array_values(array_intersect($projector->servedAgentIds(), $installed, $surfaceTargets));
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
     * Find the projector that owns one drift row, and read the hub value and the file value of
     * that row. resolveDrift() and driftDetail() both call this, so the two can never find a
     * different owner for the same row (#797).
     *
     * @return array<string,mixed> an error array (status error), or status ok with: projector,
     *   isRelay, isPolicy, isAdmin, isClaudeCompat, isInstruction, isTree, isEnvPolicy,
     *   serverName, want, theirs, abs, home, mcp
     */
    private static function locateRow(string $file, string $key): array {
        // Relay projectors FIRST: they share a ledger file with the user
        // instruction/tree projectors, and only they own the aicli-relay keys.
        // Matching the user projector for a Relay key made desired[key] null, and
        // the overwrite branch treats null as "remove" — so Overwrite reported
        // success while deleting the guidance it was asked to restore.
        $projector = null; $isRelay = false; $isPolicy = false; $isAdmin = false;
        foreach (array_merge(array_values(self::relayInstructionVendors()), self::relaySkillVendors()) as $p) {
            if ($p->ledgerKey() === $file && array_key_exists($key, $p->desired([]))) { $projector = $p; $isRelay = true; break; }
        }
        // The Admin skill is plugin-owned like the Relay guidance: its row must never
        // fall into the user skills branch (#640).
        if ($projector === null) foreach (self::adminSkillVendors() as $p) {
            if ($p->ledgerKey() === $file) { $projector = $p; $isAdmin = true; break; }
        }
        // Policy (file-path-convention) projectors BEFORE the generic instanceof
        // InstructionProjector check below: FilePathConventionProjector extends
        // InstructionProjector, so without this it would fall into the
        // instructions_enabledFor-gated branch and — for any agent not currently
        // instruction-targeted — resolve $desired to [], making Overwrite delete
        // the always-on policy block it was asked to restore. Same class of bug
        // the Relay-first ordering above already exists to avoid.
        if ($projector === null) foreach (self::policyInstructionVendors() as $p) {
            if ($p->ledgerKey() === $file) { $projector = $p; $isPolicy = true; break; }
        }
        // Forgejo #311: OpenCode's managed Claude instruction entries have their own
        // ledger row on opencode.json; their desired set comes from the home, not the store.
        $isClaudeCompat = false;
        if ($projector === null) foreach (self::claudeCompatVendors() as $p) {
            if ($p->ledgerKey() === $file) { $projector = $p; $isClaudeCompat = true; break; }
        }
        if ($projector === null) foreach (array_merge(array_values(self::supportedVendors()), array_values(self::instructionVendors()), self::treeVendors()) as $p) {
            if ($p->ledgerKey() === $file) { $projector = $p; break; }
        }
        if ($projector === null) return ['status' => 'error', 'message' => "unknown vendor file '$file'"];
        // Relay and Admin rows are plugin-owned: they are neither user instructions nor user trees (#640).
        $isInstruction = $projector instanceof InstructionProjector && !$isPolicy && !$isRelay;
        $isTree = $projector instanceof TreeProjector && !$isRelay && !$isAdmin;

        $homeInfo = self::resolveHome();
        if (!$homeInfo['ok']) return ['status' => 'error', 'reason' => 'home_unavailable', 'message' => 'agent home is not mounted'];
        $abs = $projector->absPath($homeInfo['home']);
        // An unmanaged-conflict drift (key never projected) is resolvable too: no managed-key
        // precondition here.

        $mcp = HubStore::getMcp();
        if ($isClaudeCompat) {
            /** @var OpencodeClaudeInstructionsProjector $projector */
            $serverName = null;
            $desired = $projector->desiredFor($homeInfo['home'], self::claudeCompatEnv($homeInfo['home'], $projector->agentId()));
        } elseif ($isRelay) {
            $serverName = null;
            $desired = $projector->desired([]);
        } elseif ($isAdmin) {
            // Empty while the feature is off: Overwrite then removes the copy.
            $serverName = null;
            $desired = self::adminSkillWanted() ? $projector->desired([]) : [];
        } elseif ($isPolicy) {
            // Always-on policy content — never gated by instructions_enabledFor,
            // and desired() ignores its argument entirely (see
            // FilePathConventionProjector::desired()).
            $serverName = null;
            $desired = $projector->desired([]);
        } elseif ($isInstruction) {
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
        // The Codex command-environment block depends on no server and has no target list (#800).
        $isEnvPolicy = $projector instanceof CodexProjector && $key === CodexProjector::POLICY_KEY;
        return [
            'status' => 'ok', 'projector' => $projector, 'isRelay' => $isRelay, 'isPolicy' => $isPolicy,
            'isAdmin' => $isAdmin, 'isClaudeCompat' => $isClaudeCompat, 'isInstruction' => $isInstruction,
            'isTree' => $isTree, 'isEnvPolicy' => $isEnvPolicy, 'serverName' => $serverName,
            'want' => $want, 'theirs' => $theirs, 'abs' => $abs, 'home' => $homeInfo['home'], 'mcp' => $mcp,
        ];
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
        $row = self::locateRow($file, $key);
        if (($row['status'] ?? '') !== 'ok') return $row;
        /** @var VendorProjector $projector */
        $projector = $row['projector'];
        $isRelay = $row['isRelay']; $isPolicy = $row['isPolicy']; $isAdmin = $row['isAdmin'];
        $isClaudeCompat = $row['isClaudeCompat']; $isInstruction = $row['isInstruction']; $isTree = $row['isTree'];
        $isEnvPolicy = $row['isEnvPolicy']; $serverName = $row['serverName'];
        $want = $row['want']; $theirs = $row['theirs']; $abs = $row['abs']; $mcp = $row['mcp'];
        $state = HubStore::getState();
        $entry = $state['projections'][$file] ?? ['managedKeys' => [], 'lastProjectedHash' => []];

        switch ($mode) {
            case 'release':
                unset($entry['lastProjectedHash'][$key]);
                $entry['managedKeys'] = array_values(array_diff($entry['managedKeys'] ?? [], [$key]));
                self::putLedgerEntry($state, $file, $entry);
                HubStore::saveLedger($state);
                if ($isPolicy || $isClaudeCompat || $isRelay || $isAdmin || $isEnvPolicy) {
                    // Relay and Admin rows are plugin-owned too: no user target list (#640). So is the
                    // Codex command-environment block: it depends on no server (#800).
                    // Always-on policy content has no target list to remove the
                    // agent from (it is unconditional for every installed agent,
                    // never gated by instructions_enabledFor) — falling through to
                    // the generic MCP-server untargetAgent() below would strip this
                    // agentId from every configured MCP server's enabledFor, which
                    // has nothing to do with this file. The ledger clear above is
                    // the whole effect: the next projection sees a fresh key
                    // (no recorded base hash) and re-writes the block normally.
                } elseif ($isInstruction) {
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
                // #849: Overwrite never writes or deletes through a link.
                if ($projector instanceof TreeProjector && ($linked = $projector->linkReason($abs, $key)) !== null) {
                    return ['status' => 'error', 'message' => $linked];
                }
                $ok = ($want !== null)
                    ? $projector->write($abs, [$key => $want], [])
                    : $projector->write($abs, [], [$key]);
                if (!$ok) {
                    $clash = ($want !== null) ? $projector->conflictsOutside($abs, $want) : [];
                    if (!empty($clash)) {
                        return ['status' => 'error', 'message' => 'the file already defines ' . implode(', ', $clash)
                            . ' outside the managed block; remove or rename that entry in the file, then apply again'];
                    }
                    return ['status' => 'error', 'message' => 'vendor file write failed: ' . self::writeFailReason($abs)];
                }
                if ($want !== null) {
                    $entry['lastProjectedHash'][$key] = VendorProjector::valueHash($want);
                    if (!in_array($key, $entry['managedKeys'] ?? [], true)) $entry['managedKeys'][] = $key;
                } else {
                    unset($entry['lastProjectedHash'][$key]);
                    $entry['managedKeys'] = array_values(array_diff($entry['managedKeys'] ?? [], [$key]));
                }
                $entry['lastRun'] = gmdate('c');
                self::putLedgerEntry($state, $file, $entry);
                HubStore::saveLedger($state);
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
                    HubStore::saveLedger($state);
                    self::untargetAgent($mcp, $projector->agentId(), $serverName);
                    return ['status' => 'ok', 'mode' => 'adopt', 'adopted' => 'deletion'];
                }
                $canonical = self::canonicalFromVendor($theirs, $mcp['servers'][$serverName] ?? null, $projector->agentId());
                if ($canonical !== null && !empty($mcp['servers'][$serverName]['disabled'])) $canonical['disabled'] = true;
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
                HubStore::saveLedger($state);
                return ['status' => 'ok', 'mode' => 'adopt', 'adopted' => 'value'];
        }
        // No trailing return: $mode is validated to release|overwrite|adopt
        // before the switch, and every case returns (phpstan-verified).
    }

    // ---------- drift view: labels, actions, detail (#797, #801) ----------

    /** Largest block text and whole-file text sent to the browser. */
    private const DETAIL_BLOCK_MAX = 262144;
    private const DETAIL_FILE_MAX = 1048576;

    /** "A", "A and B", "A, B and C". */
    private static function joinNames(array $names): string {
        $names = array_values(array_unique(array_map('strval', $names)));
        if (count($names) <= 1) return $names[0] ?? '';
        $last = array_pop($names);
        return implode(', ', $names) . ' and ' . $last;
    }

    /** The path as the row shows it: the ledger key without its '#surface' suffix. */
    private static function shownFile(string $file): string {
        return (string)preg_replace('/#.*$/', '', $file);
    }

    /** The plain name of the block a row is about. */
    private static function blockLabelFor(array $row, string $key): string {
        $p = $row['projector'];
        if ($row['isClaudeCompat']) return 'Claude instruction link "' . (strncmp($key, 'instructions:', 13) === 0 ? substr($key, 13) : $key) . '"';
        if ($row['isAdmin']) return 'Plugin management skill (' . $key . ')';
        if ($row['isRelay']) return $p instanceof TreeProjector ? 'Relay skill (' . $key . ')' : 'Relay guidance block';
        if ($row['isPolicy']) return 'File links block';
        if ($row['isInstruction']) return 'Global instructions block';
        if ($row['isTree']) {
            if ($p->surface() === 'commands') return 'Command "' . (string)preg_replace('/\.(md|toml)$/', '', $key) . '"';
            $slash = strpos($key, '/');
            return $slash === false ? 'Skill "' . $key . '"' : 'Skill "' . substr($key, 0, $slash) . '" (' . substr($key, $slash + 1) . ')';
        }
        if ($p instanceof JsonMcpProjector) {
            $name = $p->serverNameFromKey($key);
            return 'MCP server "' . ($name ?? $key) . '"';
        }
        if ($row['isEnvPolicy']) return 'Command environment block';
        if ($p instanceof CodexProjector || $p instanceof GooseProjector) return 'MCP servers block';
        return $key;
    }

    /** The plain words for the state of a row. $theirs is the value that the check reported. */
    public static function stateLabelFor(string $kind, $theirs, $ours): string {
        switch ($kind) {
            case 'modified':
                return $ours === null ? 'Changed in the file; the hub wants to remove it' : 'Changed in the file';
            case 'deleted':
                return 'Removed from the file';
            case 'unmanaged_conflict':
                return (is_string($theirs) && strncmp($theirs, 'Defined outside the managed block', 33) === 0)
                    ? "Same name outside the hub's block" : 'Not managed by the hub yet';
            case 'write_failed':
                return 'The hub could not write the file';
        }
        return $kind;
    }

    /** The block label in running text: first letter lower case, unless the word is an acronym. */
    private static function lowerBlock(string $label): string {
        return preg_match('/^[A-Z]{2}/', $label) === 1 ? $label : lcfirst($label);
    }

    /**
     * The three server actions for one row, with the sentence of each and its confirm text.
     * "available" is false where resolveDrift() would refuse (#801).
     * @param array<string,mixed> $row locateRow() result
     * @return array<int,array{mode:string,available:bool,help:string,confirm:?array}>
     */
    private static function actionsFor(array $row, string $file, string $key): array {
        $p = $row['projector'];
        $shown = self::shownFile($file);
        $agent = self::agentDisplayName($p->agentId());
        $label = self::blockLabelFor($row, $key);
        $block = self::lowerBlock($label);
        $isJson = $p instanceof JsonMcpProjector;
        $name = $isJson ? $p->serverNameFromKey($key) : null;
        $pluginOwned = $row['isRelay'] || $row['isAdmin'] || $row['isPolicy'] || $row['isClaudeCompat'] || $row['isEnvPolicy'];

        // Adopt
        if ($isJson && $name !== null && !$row['isTree'] && !$pluginOwned) {
            $adopt = [true, $row['theirs'] === null
                ? "Accept that server \"$name\" was removed from $shown. The hub stops sending it to $agent. The file does not change."
                : "Keep the entry for server \"$name\" that is in $shown and make it the hub's value. The file does not change. At the next Apply to agents, the hub sends this value to the other agents that use this server."];
        } elseif ($pluginOwned) {
            $adopt = [false, 'Adopt does not work for this block: the plugin writes it. Use Overwrite to put it back.'];
        } elseif ($row['isTree']) {
            $adopt = [false, 'Adopt does not work for skill and command files. Edit the file in the hub\'s Skills & Commands card, or use Overwrite or Release.'];
        } else {
            $adopt = [false, 'Adopt does not work for this file: the hub cannot read the text of a block back into its own settings. Copy your change into the hub by hand, or use Overwrite or Release.'];
        }

        // Overwrite
        $clash = [];
        if ($row['want'] !== null) {
            try { $clash = $p->conflictsOutside($row['abs'], $row['want']); } catch (\Throwable $e) { $clash = []; }
        }
        if (!empty($clash)) {
            $overwrite = [false, 'Overwrite does not work while ' . self::joinNames($clash) . " is also in $shown outside the hub's block. Open the file, then remove or rename that entry."];
        } elseif ($row['want'] === null) {
            $overwrite = [true, "Remove the $block from $shown, because the hub no longer uses it. The change in the file is lost."];
        } elseif ($isJson && $name !== null) {
            $overwrite = [true, "Replace the entry for server \"$name\" in $shown with the hub's value. The change in the file is lost. Other entries do not change."];
        } else {
            $overwrite = [true, "Replace the $block in $shown with the hub's text. The change in the file is lost. Text outside the block does not change."];
        }
        $overwriteConfirm = $overwrite[0] ? [
            'title' => 'Replace the change in the file?',
            'text' => $overwrite[1] . ' To get your text back, you must type it again. If config backup is on, the History card has the old file.',
            'button' => 'Replace',
        ] : null;

        // Release
        $releaseConfirm = null;
        if ($pluginOwned) {
            $release = 'Forget the hub\'s record of this block. The file keeps its text. The next Apply to agents or check writes the block again.';
        } elseif ($row['isInstruction']) {
            $readers = self::joinNames(array_map([self::class, 'agentDisplayName'], $p->servedAgentIds()));
            $release = "Stop managing the global instructions in $shown. The file keeps its text. The hub stops writing global instructions for $readers.";
            $releaseConfirm = ['title' => 'Stop writing instructions for these agents?',
                'text' => $release . ' To undo, tick the agents again in Global Instructions and press Apply to agents.', 'button' => 'Stop managing'];
        } elseif ($row['isTree']) {
            $commands = $p->surface() === 'commands';
            $readers = self::joinNames(array_map([self::class, 'agentDisplayName'], $p->servedAgentIds()));
            $release = "Stop managing the hub's " . ($commands ? 'commands' : 'skills') . ' in ' . $shown . '. The files keep their text. The hub stops copying any '
                . ($commands ? 'command' : 'skill') . " to $readers.";
            $releaseConfirm = ['title' => 'Stop copying ' . ($commands ? 'commands' : 'skills') . ' to these agents?',
                'text' => $release . ' To undo, tick the agents again under Project ' . ($commands ? 'commands' : 'skills') . ' to and press Apply to agents.', 'button' => 'Stop managing'];
        } elseif ($isJson && $name !== null) {
            $release = "Stop managing server \"$name\" in $shown. The file keeps its text. The hub stops sending server \"$name\" to $agent.";
        } else {
            $release = "Stop managing the MCP servers block in $shown. The file keeps its text. The hub stops sending all of its MCP servers to $agent.";
            $names = [];
            foreach (($row['mcp']['servers'] ?? []) as $sName => $def) {
                if (in_array($p->agentId(), (array)($def['enabledFor'] ?? []), true)) $names[] = (string)$sName;
            }
            $releaseConfirm = ['title' => "Stop sending MCP servers to $agent?",
                'text' => $release . (empty($names) ? '' : ' Servers: ' . self::joinNames($names) . '.')
                    . " To undo, tick $agent again on each server and press Apply to agents.", 'button' => 'Stop managing'];
        }

        return [
            ['mode' => 'adopt', 'available' => $adopt[0], 'help' => $adopt[1], 'confirm' => null],
            ['mode' => 'overwrite', 'available' => $overwrite[0], 'help' => $overwrite[1], 'confirm' => $overwriteConfirm],
            ['mode' => 'release', 'available' => true, 'help' => $release, 'confirm' => $releaseConfirm],
        ];
    }

    /**
     * Plain-word fields for one drift record: state, block, agent, the clash reason and the
     * three actions. A row that cannot be located keeps all actions on (the server then gives
     * its own error).
     * @return array<string,mixed>
     */
    private static function driftExtras(string $file, string $key, string $agentId, string $kind, $theirs, $ours): array {
        $extra = [
            'stateLabel' => self::stateLabelFor($kind, $theirs, $ours),
            'agentLabel' => self::agentDisplayName($agentId),
            'blockLabel' => $key,
            'compareHelp' => 'Show the text in ' . self::shownFile($file) . " next to the hub's text, with the changes marked.",
            'actions' => [],
        ];
        if ($kind === 'unmanaged_conflict' && is_string($theirs) && strncmp($theirs, 'Defined outside the managed block: ', 35) === 0) {
            $extra['reason'] = 'Your file also sets ' . substr($theirs, 35) . " outside the hub's block.";
        }
        try {
            $row = self::locateRow($file, $key);
        } catch (\Throwable $e) {
            $row = ['status' => 'error'];
        }
        if (($row['status'] ?? '') !== 'ok') {
            foreach (['adopt', 'overwrite', 'release'] as $mode) {
                $extra['actions'][] = ['mode' => $mode, 'available' => true, 'help' => '', 'confirm' => null];
            }
            return $extra;
        }
        $extra['blockLabel'] = self::blockLabelFor($row, $key);
        $extra['actions'] = self::actionsFor($row, $file, $key);
        return $extra;
    }

    /** Cut a text at the last full line when it is longer than $max bytes. @return array{0:string,1:bool} */
    private static function cutText(string $text, int $max): array {
        if (strlen($text) <= $max) return [$text, false];
        $cut = substr($text, 0, $max);
        $nl = strrpos($cut, "\n");
        return [$nl === false ? '' : substr($cut, 0, $nl), true];
    }

    /** The text of a value for the compare view: a string as it is, an array as pretty JSON. Masked for the browser. */
    private static function valueText($value, string $language): ?string {
        if ($value === null) return null;
        if (is_string($value)) return self::redactFileTextForBrowser($value, $language);
        $json = json_encode(self::redactForBrowser($value), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $json === false ? null : $json;
    }

    /** First line number (1 based) of $needle in $haystack, and the last line it covers. @return array{0:int,1:int}|null */
    private static function lineSpan(string $haystack, string $needle): ?array {
        if ($needle === '') return null;
        $pos = strpos($haystack, $needle);
        if ($pos === false) return null;
        $start = substr_count($haystack, "\n", 0, $pos) + 1;
        return [$start, $start + substr_count($needle, "\n")];
    }

    /**
     * Read-only detail of one drift row for the compare view: both texts with real line breaks,
     * the whole file with the hub's block marked, and the file as it would be after Overwrite.
     * Writes nothing. Every text is masked for the browser (#799, R11).
     * @param string[]|null $installedOverride test hook, like detectDrift()
     * @return array<string,mixed>
     */
    public static function driftDetail(string $file, string $key, ?array $installedOverride = null): array {
        $row = self::locateRow($file, $key);
        if (($row['status'] ?? '') !== 'ok') return $row;
        $p = $row['projector'];
        $language = self::languageForFile($file);
        $rec = null;
        $all = self::detectDrift($installedOverride);
        foreach (($all['drift'] ?? []) as $d) {
            if ($d['file'] === $file && $d['key'] === $key) { $rec = $d; break; }
        }
        $out = [
            'status' => 'ok', 'file' => self::shownFile($file), 'key' => $key, 'absPath' => $row['abs'],
            'agentLabel' => self::agentDisplayName($p->agentId()), 'language' => $language, 'kind' => $rec['kind'] ?? null,
        ];
        if ($rec === null) return $out + ['stateLabel' => '', 'blockLabel' => self::blockLabelFor($row, $key), 'actions' => [], 'truncated' => false];

        [$theirsText, $t1] = self::cutText((string)(self::valueText($row['theirs'], $language) ?? ''), self::DETAIL_BLOCK_MAX);
        [$oursText, $t2] = self::cutText((string)(self::valueText($row['want'], $language) ?? ''), self::DETAIL_BLOCK_MAX);
        $truncated = $t1 || $t2;
        $out += [
            'stateLabel' => $rec['stateLabel'], 'blockLabel' => $rec['blockLabel'], 'compareHelp' => $rec['compareHelp'],
            'theirsText' => $row['theirs'] === null ? null : $theirsText,
            'oursText' => $row['want'] === null ? null : $oursText,
            'wholeFile' => null, 'afterOverwrite' => null,
        ];

        // Whole-file view: only for a fenced text block (not a JSON entry, not a skill or command file).
        $fenced = !($p instanceof JsonMcpProjector) && !$row['isTree'] && (is_string($row['theirs']) || is_string($row['want']));
        if ($fenced && is_file($row['abs'])) {
            $raw = (string)@file_get_contents($row['abs']);
            [$rawCut, $t3] = self::cutText($raw, self::DETAIL_FILE_MAX);
            $truncated = $truncated || $t3;
            $blockText = is_string($row['theirs']) ? $row['theirs'] : null;
            $span = $blockText !== null ? self::lineSpan($raw, $blockText) : null;
            $clashLines = [];
            $clashNames = $row['want'] !== null ? $p->conflictsOutside($row['abs'], $row['want']) : [];
            if (!empty($clashNames)) {
                foreach (preg_split('/\n/', $raw) ?: [] as $i => $line) {
                    $n = $i + 1;
                    if ($span !== null && $n >= $span[0] && $n <= $span[1]) continue;
                    foreach ($clashNames as $nm) {
                        if (preg_match('/(^|[\s\[.\'"])' . preg_quote($nm, '/') . '([\s\].\'"=:]|$)/', $line) === 1) { $clashLines[] = [$n, $n]; break; }
                    }
                }
            }
            $out['wholeFile'] = [
                'text' => self::redactFileTextForBrowser($rawCut, $language),
                'blockStart' => $span[0] ?? null, 'blockEnd' => $span[1] ?? null, 'clashLines' => $clashLines,
            ];
            // The file as Overwrite would leave it, computed in memory with the projector's pure helpers.
            $markerSource = is_string($row['want']) ? $row['want'] : $blockText;
            if (is_string($markerSource) && $markerSource !== '') {
                $mLines = explode("\n", $markerSource);
                $open = $mLines[0]; $close = $mLines[count($mLines) - 1];
                if ($row['want'] === null) {
                    $after = CodexProjector::stripFence($raw, $open, $close);
                } elseif ($p instanceof GooseProjector) {
                    $after = GooseProjector::placeFence($raw, (string)$row['want']);
                } else {
                    $after = CodexProjector::replaceOrAppendFence($raw, (string)$row['want'], $open, $close);
                }
                [$afterCut] = self::cutText($after, self::DETAIL_FILE_MAX);
                $afterSpan = is_string($row['want']) ? self::lineSpan($after, (string)$row['want']) : null;
                $out['afterOverwrite'] = [
                    'text' => self::redactFileTextForBrowser($afterCut, $language),
                    'blockStart' => $afterSpan[0] ?? null, 'blockEnd' => $afterSpan[1] ?? null,
                ];
            }
        }
        $out['truncated'] = $truncated;
        $out['actions'] = $rec['actions'];
        return $out;
    }

    // ---------- internals ----------

    /**
     * One-time migration cleanup for Claude Code. Before this release the hub
     * spliced its three managed blocks (Config-Hub instructions, Relay guidance,
     * file-path convention) as HTML-comment FENCES into the user's own
     * ~/.claude/CLAUDE.md. They now live as dedicated fence-free files under
     * ~/.claude/rules/ (Claude auto-loads that directory natively). This strips
     * the three legacy fences from CLAUDE.md so the guidance is never loaded
     * TWICE (rules file + stale fence), and drops the orphaned ledger rows for
     * the CLAUDE.md file. Content OUTSIDE the fences is preserved
     * (CodexProjector::stripFence is boundary-scoped). Called before every Claude
     * projection so each home self-heals on its next launch; a no-op once the
     * fences are gone (one cheap read that finds no marker). Returns true if it
     * changed anything.
     */
    private static function cleanupLegacyClaudeFences(string $home, array &$state): bool {
        $changed = false;
        $abs = rtrim($home, '/') . '/.claude/CLAUDE.md';
        if (is_file($abs)) {
            $raw = (string)@file_get_contents($abs);
            $hasFence = strpos($raw, InstructionProjector::FENCE_OPEN) !== false
                     || strpos($raw, RelayInstructionProjector::FENCE_OPEN) !== false
                     || strpos($raw, FilePathConventionProjector::FENCE_OPEN) !== false;
            if ($hasFence) {
                // Each fenced projector's write(remove) strips only its own
                // markers (and preserves ownership); three passes remove all three.
                (new InstructionProjector('claude-code', '.claude/CLAUDE.md', 'Claude Code', ['claude-code'], true))
                    ->write($abs, [], [InstructionProjector::FENCE_KEY]);
                (new RelayInstructionProjector('claude-code', '.claude/CLAUDE.md', 'Claude Code', ['claude-code'], false))
                    ->write($abs, [], [RelayInstructionProjector::FENCE_KEY]);
                (new FilePathConventionProjector('claude-code', '.claude/CLAUDE.md', 'Claude Code', ['claude-code'], false))
                    ->write($abs, [], [FilePathConventionProjector::FENCE_KEY]);
                $changed = true;
                LogService::log('hub: stripped legacy Claude CLAUDE.md fences (migrated to ~/.claude/rules/)',
                    LogService::LOG_INFO, 'HubProjector');
            }
        }
        // Drop orphaned ledger rows for the old CLAUDE.md-based Claude projectors.
        foreach (['~/.claude/CLAUDE.md', '~/.claude/CLAUDE.md#aicli-relay-guidance',
                  '~/.claude/CLAUDE.md#aicli-file-paths'] as $k) {
            if (isset($state['projections'][$k])) { unset($state['projections'][$k]); $changed = true; }
        }
        return $changed;
    }

    /** Store a ledger entry, dropping it entirely when nothing is managed any more. */
    private static function putLedgerEntry(array &$state, string $file, array $entry): void {
        if (empty($entry['managedKeys']) && empty($entry['lastProjectedHash'])) {
            unset($state['projections'][$file]);
        } else {
            $state['projections'][$file] = $entry;
        }
    }

    /**
     * Why a projector write failed, in plain words, for the log line (#514).
     * The line used to say "unparseable files are never clobbered" for every
     * failure. On prod02 the real cause was a broken link: the Antigravity
     * skills folder pointed one folder too high, so no skill file could be made.
     * Walks up from $abs to the first part that exists and names a broken link
     * on the way, or a folder that is not writable.
     */
    public static function writeFailReason(string $abs): string {
        $foreign = Opencode2Projector::foreignLinkReason($abs);
        if ($foreign !== null) return $foreign;
        $path = rtrim($abs, '/');
        while ($path !== '' && $path !== '/' && $path !== '.') {
            if (is_link($path) && !file_exists($path)) {
                return "$path is a broken link to '" . (string)@readlink($path) . "'; fix or remove the link";
            }
            if (file_exists($path)) break;
            $path = dirname($path);
        }
        if (is_dir($path) && !is_writable($path)) return "$path is not writable";
        // #666 R-159: a JSON file with comments (OpenCode and Kilo allow them) is valid for the
        // agent but not for a strict reader. Say so, so the person knows what to change.
        if (is_file($abs)) {
            $raw = (string)@file_get_contents($abs);
            if (trim($raw) !== '' && json_decode($raw) === null && JsonMcpProjector::stripJsonComments($raw) !== $raw
                && json_decode(JsonMcpProjector::stripJsonComments($raw)) !== null) {
                return basename($abs) . ' has comments or trailing commas, and the Config Hub cannot rewrite such a file without losing them. '
                    . 'Remove the comments and trailing commas from the file, then project again';
            }
        }
        return 'the existing file could not be read safely (unparseable files are never clobbered)';
    }

    /**
     * Reconcile one vendor file against a precomputed desired managed-key map
     * (the caller derives it — from enabled MCP servers or from the instruction
     * doc + target set). Mutates $state's entry for the file. With $write=false
     * this is a pure drift probe.
     * @param array<string,mixed> $desired
     * @return array{written:string[],removed:string[],drift:array[]}
     */
    /**
     * $restoreDeleted: re-assert a managed key that has vanished from disk
     * instead of reporting it as drift. Only for system-managed surfaces (the
     * Relay guidance fence and Relay skill), which agents require in context and
     * which no user chose to delete — on this host they sit under paths
     * symlinked into a separate git repo, so a checkout removes them silently.
     * User content keeps the safe default: a deletion is respected, not undone.
     */
    private static function reconcileVendor(VendorProjector $projector, string $home, array $desired, array &$state, bool $write, bool $restoreDeleted = false): array {
        $file = $projector->ledgerKey();
        $abs = $projector->absPath($home);
        $agentId = $projector->agentId();
        if (self::$refuseLinks && self::passesLink($home, $abs)) {
            // The home of the run user belongs to the run user, who can plant a link at a target. A write through it
            // could change another file. Nothing is written and nothing is read for this target.
            LogService::log("Run-user projection skipped $file: the target or a folder above it is a link", LogService::LOG_WARN, 'HubProjector');
            return ['written' => [], 'removed' => [], 'drift' => []];
        }

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

            // #642: a fenced file (TOML, YAML) cannot hold two entries with one name.
            // When the user wrote the same name outside our fence, report it and write nothing.
            if ($want !== null && ($clash = $projector->conflictsOutside($abs, $want))) {
                $drift[] = self::driftRec($file, $key, $agentId, 'unmanaged_conflict',
                    'Defined outside the managed block: ' . implode(', ', $clash), $want);
                continue;
            }

            if ($want !== null) {
                if (!$hasCur) {
                    if ($base === null) { $set[$key] = $want; }                       // fresh key → write
                    elseif ($restoreDeleted) { $set[$key] = $want; }                  // system-managed → self-heal
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

        // #849: a skill or command path that sits behind a link is never written, overwritten
        // or deleted. The row says so in plain words; the ledger stays as it was.
        if ($projector instanceof TreeProjector) {
            foreach (array_keys($set) as $key) {
                if (($why = $projector->linkReason($abs, (string)$key)) === null) continue;
                $drift[] = self::linkedRec($file, (string)$key, $agentId, $current[$key] ?? null, $desired[$key] ?? null, $why);
                unset($set[$key]);
            }
            foreach ($remove as $i => $key) {
                if (($why = $projector->linkReason($abs, (string)$key)) === null) continue;
                $drift[] = self::linkedRec($file, (string)$key, $agentId, $current[$key] ?? null, null, $why);
                unset($remove[$i]);
            }
            $remove = array_values($remove);
            // Rows found above (a user file at a managed path) get the same reason.
            foreach ($drift as $i => $rec) {
                if (isset($rec['reason']) || $rec['kind'] === 'write_failed') continue;
                if (($why = $projector->linkReason($abs, (string)$rec['key'])) !== null) $drift[$i]['reason'] = $why;
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
                // #666 R-159: the reason goes into the row, so the tray says why, not only that it failed.
                $why = self::writeFailReason($abs);
                foreach (array_merge(array_keys($set), $remove) as $key) {
                    $rec = self::driftRec($file, $key, $agentId, 'write_failed', $current[$key] ?? null, $desired[$key] ?? null);
                    $rec['reason'] = $why;
                    $drift[] = $rec;
                }
                LogService::log("hub project: could not write $file: " . $why, LogService::LOG_WARN, 'HubProjector');
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

    /** Servers enabled for an agent, env and header {KEY} placeholders resolved for projection. */
    private static function enabledServersFor(array $servers, string $agentId): array {
        $out = [];
        foreach ($servers as $name => $def) {
            if (!empty($def['disabled'])) continue; // switched off for every agent
            if (!in_array($agentId, $def['enabledFor'] ?? [], true)) continue;
            if (!empty($def['env']) && is_array($def['env'])) {
                $def['env'] = self::resolvePlaceholders($def['env']);
            }
            if (!empty($def['headers']) && is_array($def['headers'])) {
                $def['headers'] = self::resolvePlaceholders($def['headers']);
            }
            // #590: a URL keeps its secret as {KEY} in the store, like env and headers.
            if (!empty($def['url']) && is_string($def['url'])) {
                $def['url'] = self::resolvePlaceholders([$def['url']])[0];
            }
            $out[$name] = $def;
        }
        return $out;
    }

    /**
     * Resolve {KEY} placeholders in env or header values from the SecretService vault at
     * projection time. Resolved values go to vendor files only — the canonical
     * store always keeps the placeholder form. Unknown keys stay literal.
     */
    public static function resolvePlaceholders(array $env): array {
        $secrets = null; // lazy — only read the vault if a placeholder exists
        foreach ($env as $k => $v) {
            $env[$k] = preg_replace_callback('/\{([A-Z][A-Z0-9_]{1,127})\}/', function ($m) use (&$secrets) {
                if ($secrets === null) $secrets = SecretService::getAgentSecrets();
                return array_key_exists($m[1], $secrets) ? $secrets[$m[1]] : $m[0];
            }, (string)$v);
        }
        return $env;
    }

    /** #849: a row for a path that sits behind a link, so it was not written. */
    private static function linkedRec(string $file, string $key, string $agentId, $theirs, $ours, string $why): array {
        $rec = self::driftRec($file, $key, $agentId, 'write_failed', $theirs, $ours);
        $rec['reason'] = $why;
        $rec['stateLabel'] = 'Linked folder, not written';
        return $rec;
    }

    private static function driftRec(string $file, string $key, string $agentId, string $kind, $theirs, $ours): array {
        // A drift record goes to the browser. "ours" is the projected value, so it
        // holds resolved vault secrets; "theirs" can hold them too (#588).
        // A fenced block or a skill file is a string. redactForBrowser() masks only arrays, so a
        // value that a person typed into a TOML or YAML block would pass (#799). A string goes
        // through the line masker as well.
        $lang = self::languageForFile($file);
        $safe = static fn($v) => is_string($v) ? self::redactFileTextForBrowser($v, $lang) : self::redactForBrowser($v);
        return ['file' => $file, 'key' => $key, 'agentId' => $agentId, 'kind' => $kind,
                'theirs' => $safe($theirs), 'ours' => $safe($ours)]
            + self::driftExtras($file, $key, $agentId, $kind, $theirs, $ours); // plain words and the accepted actions (#797, #801)
    }

    /** Map keys of a vendor MCP value whose values are secrets by nature. */
    private const SECRET_MAP_KEYS = ['env', 'envs', 'environment', 'headers', 'http_headers', 'envHttpHeaders'];

    /**
     * A copy of a vendor value that is safe to send to the browser (#588).
     * A vault value inside any string goes back to its {KEY} placeholder. Each
     * value in an env or header map that is not a bare placeholder becomes
     * "[masked:<hash>]": the browser can still see that the value changed, but
     * never the value. The shape of the value stays the same.
     */
    public static function redactForBrowser($value, ?array $vault = null, bool $secretMap = false) {
        if ($vault === null) {
            $vault = [];
            foreach (SecretService::getAgentSecrets() as $k => $v) {
                if (strlen((string)$v) >= 4) $vault[(string)$k] = (string)$v;
            }
            uasort($vault, static fn($a, $b) => strlen($b) <=> strlen($a));
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = self::redactForBrowser($v, $vault, $secretMap || in_array((string)$k, self::SECRET_MAP_KEYS, true));
            }
            return $out;
        }
        if (!is_string($value)) return $value;
        foreach ($vault as $name => $secret) {
            $value = str_replace($secret, '{' . $name . '}', $value);
        }
        if ($secretMap && !preg_match('/^(?:Bearer |Basic )?\{[A-Z][A-Z0-9_]{1,127}\}$/D', $value)) {
            return '[masked:' . substr(hash('sha256', $value), 0, 8) . ']';
        }
        // A secret in a URL query part.
        return preg_replace('/([?&][A-Za-z0-9_.-]*(?:key|token|secret|password|passwd|auth|sig)[A-Za-z0-9_.-]*=)(?!\{[A-Z][A-Z0-9_]{1,127}\}(?:[&#\s]|$))[^&#\s]+/i', '$1[masked]', $value);
    }

    /** Words in a key name that mark its value as a secret (the #799 line masker). */
    private const SECRET_NAME_RE = '/key|token|secret|password|passwd|auth|bearer/i';

    /** A value that is only a vault placeholder, with an optional scheme word. */
    private const PLACEHOLDER_RE = '/^(?:Bearer |Basic )?\{[A-Z][A-Z0-9_]{1,127}\}$/D';

    /** The language of a config file, from its name: toml, yaml, json, markdown or text. */
    public static function languageForFile(string $file): string {
        $path = strtolower((string)preg_replace('/#.*$/', '', $file));
        if (substr($path, -5) === '.toml') return 'toml';
        if (substr($path, -5) === '.yaml' || substr($path, -4) === '.yml') return 'yaml';
        if (substr($path, -5) === '.json') return 'json';
        if (substr($path, -3) === '.md') return 'markdown';
        return 'text';
    }

    /** The masked form of one secret value. The hash lets a diff show that the value changed. */
    private static function maskedValue(string $value): string {
        return '[masked:' . substr(hash('sha256', $value), 0, 8) . ']';
    }

    /** Mask every quoted string and every bare value that sits inside an inline table or list. */
    private static function maskInline(string $s): string {
        $re = '/("(?:[^"\\\\]|\\\\.)*"|\'[^\']*\')(?!\s*[=:])|([=:]\s*)([^\s,}\]"\'\[{#][^,}\]#]*[^\s,}\]#]|[^\s,}\]"\'\[{#])/';
        return (string)preg_replace_callback($re, static function (array $m): string {
            if ($m[1] !== '') {
                $inner = substr($m[1], 1, -1);
                if (preg_match(self::PLACEHOLDER_RE, $inner) === 1) return $m[1];
                return $m[1][0] . self::maskedValue($inner) . $m[1][0];
            }
            if (preg_match(self::PLACEHOLDER_RE, $m[3]) === 1) return $m[0];
            return $m[2] . self::maskedValue($m[3]);
        }, $s);
    }

    /** Mask the value part of one `key = value` or `key: value` line (the part after the separator). */
    private static function maskValuePart(string $rest): string {
        if ($rest === '' || $rest[0] === '#') return $rest;
        if ($rest[0] === '[' || $rest[0] === '{') return self::maskInline($rest);
        if (preg_match('/^("(?:[^"\\\\]|\\\\.)*"|\'[^\']*\')(.*)$/sD', $rest, $q) === 1) {
            $inner = substr($q[1], 1, -1);
            if (preg_match(self::PLACEHOLDER_RE, $inner) === 1) return $rest;
            return $q[1][0] . self::maskedValue($inner) . $q[1][0] . $q[2];
        }
        if (preg_match('/^[|>][-+0-9]*\s*(?:#.*)?$/D', $rest) === 1) return $rest; // YAML block scalar marker
        if (preg_match('/^([^\s#,]+)(.*)$/sD', $rest, $b) === 1) {
            if (preg_match(self::PLACEHOLDER_RE, $b[1]) === 1) return $rest;
            return self::maskedValue($b[1]) . $b[2];
        }
        return $rest;
    }

    /**
     * A copy of a config text (a TOML or YAML block, or a whole file) that is safe to send to
     * the browser (#799). redactForBrowser() masks only arrays; a text has no env or header
     * map to walk. This function reads the text line by line. It masks the value of each key
     * whose name holds key, token, secret, password, passwd, auth or bearer (any case), and
     * each value inside an env, environment, headers or http_headers table (a TOML table
     * header, a YAML map, or an inline table). A value that is only a {KEY} placeholder
     * stays. The masked form is "[masked:<8 hex>]", the same as the array masker. The number
     * of lines does not change.
     */
    public static function redactTextForBrowser(string $text, string $language = 'text'): string {
        $lines = explode("\n", $text);
        $tomlSecretTable = false;
        $yamlIndent = null; // indent of the YAML key (env:, headers:) whose children are secrets
        foreach ($lines as $i => $line) {
            $bare = rtrim($line, "\r");
            $tail = substr($line, strlen($bare));
            if (trim($bare) === '') continue;
            preg_match('/^[ \t]*/', $bare, $ind);
            $indent = strlen($ind[0]);
            if (($language === 'toml' || $language === 'text') && preg_match('/^[ \t]*\[\[?[ \t]*([^\]]+?)[ \t]*\]\]?[ \t]*(?:#.*)?$/D', $bare, $h) === 1) {
                $parts = preg_split('/\./', $h[1]) ?: [];
                $last = strtolower(trim((string)end($parts), " \t\"'"));
                $tomlSecretTable = in_array($last, array_map('strtolower', self::SECRET_MAP_KEYS), true);
                continue;
            }
            if ($yamlIndent !== null && ltrim($bare)[0] !== '#' && $indent <= $yamlIndent) $yamlIndent = null;
            if (preg_match('/^([ \t]*(?:-[ \t]+)?)(["\']?)([A-Za-z0-9_.\/-]+)\2([ \t]*[=:][ \t]*)(.*)$/D', $bare, $m) !== 1) continue;
            [, $pre, $q, $name, $sep, $rest] = $m;
            if ($rest === '' || $rest[0] === '#') {
                if (in_array(strtolower($name), array_map('strtolower', self::SECRET_MAP_KEYS), true)) $yamlIndent = $indent;
                continue;
            }
            $inSecretMap = $tomlSecretTable || $yamlIndent !== null;
            if ($inSecretMap || preg_match(self::SECRET_NAME_RE, $name) === 1) {
                $rest = self::maskValuePart($rest);
            } elseif (in_array(strtolower($name), array_map('strtolower', self::SECRET_MAP_KEYS), true) && $rest[0] === '{') {
                $rest = self::maskInline($rest);
            }
            $lines[$i] = $pre . $q . $name . $q . $sep . $rest . $tail;
        }
        return implode("\n", $lines);
    }

    /**
     * A config text for the browser: vault values back to {KEY}, a URL secret masked, and the
     * line masker above (#799). The drift record and the compare view both use this.
     */
    public static function redactFileTextForBrowser(string $text, string $language = 'text'): string {
        $text = (string)self::redactForBrowser($text);
        return self::redactTextForBrowser($text, $language);
    }

    /**
     * Server name behind a managed key (JSON vendors only; fence keys → null). The root key
     * belongs to the vendor: "mcpServers.<name>" for most, "mcp.<name>" for OpenCode and Kilo.
     */
    private static function serverNameForKey(VendorProjector $projector, string $key): ?string {
        return ($projector instanceof JsonMcpProjector) ? $projector->serverNameFromKey($key) : null;
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
     * {KEY} placeholders in env/header values that the SecretService vault does
     * not hold. Projection sends those literally, so the editor warns about them.
     * @return string[] sorted unique key names
     */
    public static function unresolvedPlaceholders(array $def): array {
        $values = array_merge(array_values((array)($def['env'] ?? [])), array_values((array)($def['headers'] ?? [])),
                              isset($def['url']) ? [(string)$def['url']] : []);
        $missing = [];
        $secrets = null;
        foreach ($values as $v) {
            if (!preg_match_all('/\{([A-Z][A-Z0-9_]{1,127})\}/', (string)$v, $m)) continue;
            if ($secrets === null) $secrets = SecretService::getAgentSecrets();
            foreach ($m[1] as $key) if (!array_key_exists($key, $secrets)) $missing[$key] = true;
        }
        $missing = array_keys($missing);
        sort($missing);
        return $missing;
    }

    /**
     * Replace every vault value found in a string (or in the strings of an array)
     * by its {KEY} placeholder. The reverse of resolvePlaceholders(). Values
     * shorter than 6 characters are skipped, as the git clean filter does.
     */
    public static function unresolveVaultValues($value, ?array $pairs = null) {
        if ($pairs === null) {
            $pairs = [];
            foreach (SecretService::getAgentSecrets() as $k => $v) {
                if (strlen((string)$v) >= 6 && preg_match('/^[A-Z][A-Z0-9_]{1,127}$/D', (string)$k)) $pairs[(string)$k] = (string)$v;
            }
            uasort($pairs, static fn($a, $b) => strlen($b) <=> strlen($a));
        }
        if (is_array($value)) {
            foreach ($value as $k => $v) $value[$k] = self::unresolveVaultValues($v, $pairs);
            return $value;
        }
        if (!is_string($value)) return $value;
        foreach ($pairs as $name => $secret) $value = str_replace($secret, '{' . $name . '}', $value);
        return $value;
    }

    /**
     * Map a vendor mcpServers value back to a canonical def (adopt path).
     * Preserves the existing enabledFor; returns null when the shape is not
     * recognisably stdio/http/sse.
     */
    private static function canonicalFromVendor($vendorValue, ?array $existing, string $agentId): ?array {
        // #590: the vendor file holds RESOLVED secrets. The canonical store must keep
        // the {KEY} form, so each value that equals a vault value goes back to its key.
        $vendorValue = self::unresolveVaultValues($vendorValue);
        $v = VendorProjector::canonicalize($vendorValue);
        if (!is_array($v)) return null;
        $enabledFor = $existing['enabledFor'] ?? [$agentId];
        if (!empty($v['command'])) {
            $command = $v['command'];
            $args = is_array($v['args'] ?? null) ? $v['args'] : [];
            if (is_array($command)) {
                // #647: OpenCode and Kilo keep the binary and its arguments in ONE list,
                // and the variables under "environment". Split them back apart.
                $list = array_values(array_filter($command, 'is_scalar'));
                if (empty($list)) return null;
                $command = array_shift($list);
                $args = array_merge($list, $args);
            }
            $env = $v['env'] ?? $v['environment'] ?? [];
            return ['transport' => 'stdio', 'command' => (string)$command,
                    'args' => array_values(array_map('strval', $args)),
                    'env' => is_array($env) ? $env : [],
                    'enabledFor' => $enabledFor];
        }
        $url = (string)($v['url'] ?? $v['httpUrl'] ?? '');
        if ($url !== '') {
            // OpenCode/Kilo say type "remote" for both http and sse: keep what the hub already holds.
            $kept = in_array($existing['transport'] ?? '', ['http', 'sse'], true) ? $existing['transport'] : 'http';
            $transport = isset($v['httpUrl']) ? 'http'
                : ((($v['type'] ?? '') === 'remote') ? $kept
                : ((($v['type'] ?? '') === 'sse') ? 'sse' : ((($v['type'] ?? '') === 'http') ? 'http' : 'sse')));
            $out =['transport' => $transport, 'url' => $url, 'enabledFor' => $enabledFor];
            // Codex-style TOML names the map http_headers; every JSON vendor uses headers.
            $headers = $v['headers'] ?? $v['http_headers'] ?? null;
            if (is_array($headers) && !empty($headers)) {
                $out['headers'] = array_map('strval', array_filter($headers, 'is_scalar'));
            }
            return $out;
        }
        return null;
    }
}
