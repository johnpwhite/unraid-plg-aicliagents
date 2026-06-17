<?php
/**
 * <module_context>
 *     <name>HubHandler</name>
 *     <description>AJAX handler for the Config Hub (OP #1362 / H-01 phase 1 — MCP
 *     servers; OP #1363 / H-02 phase 2 — instruction files; OP #1364 / H-03 phase 3 —
 *     skills/commands trees): canonical-store CRUD, target selection, projection,
 *     and drift resolution. enabledFor agent ids are validated against AgentRegistry
 *     ∩ the supported projector set (instruction targets against the
 *     instruction-projector set; skills/commands targets against the tree-surface
 *     set). hub_project covers MCP + instructions + skills/commands in one pass.
 *     After hub_project the response lists affected RUNNING sessions (via
 *     TerminalService) so the UI can offer signal_reload — sessions are NEVER
 *     auto-restarted. env values are masked on read; env values and
 *     instruction/skill/command content are never logged.</description>
 *     <dependencies>HubStore, HubProjector, AgentRegistry, TerminalService</dependencies>
 *     <constraints>Static methods only. All inputs validated before use. Follows
 *     the ActivityHandler registration shape in AICliAjax.php.</constraints>
 * </module_context>
 */

namespace AICliAgents\Handlers;

use AICliAgents\Services\AgentRegistry;
use AICliAgents\Services\Hub\HubProjector;
use AICliAgents\Services\Hub\HubStore;
use AICliAgents\Services\TerminalService;

class HubHandler
{
    public static function handle($action, $id): ?array
    {
        switch ($action) {
            case 'hub_get_state':               return self::getState();
            case 'hub_save_mcp_server':         return self::saveMcpServer();
            case 'hub_delete_mcp_server':       return self::deleteMcpServer();
            case 'hub_set_server_targets':      return self::setServerTargets();
            case 'hub_get_instructions':        return self::getInstructions();
            case 'hub_save_instructions':       return self::saveInstructions();
            case 'hub_set_instruction_targets': return self::setInstructionTargets();
            case 'hub_list_skills':             return self::listSkills();
            case 'hub_save_skill_file':         return self::saveSkillFile();
            case 'hub_delete_skill':            return self::deleteSkill();
            case 'hub_list_commands':           return self::listCommands();
            case 'hub_save_command':            return self::saveCommand();
            case 'hub_delete_command':          return self::deleteCommand();
            case 'hub_set_skills_targets':      return self::setTreeTargets('skills');
            case 'hub_set_commands_targets':    return self::setTreeTargets('commands');
            case 'hub_project':                 return self::project();
            case 'hub_get_drift':               return HubProjector::detectDrift();
            case 'hub_resolve_drift':           return self::resolveDrift();
            default:                            return null;
        }
    }

    /** Actions handled by this handler. */
    public static function actions(): array
    {
        return ['hub_get_state', 'hub_save_mcp_server', 'hub_delete_mcp_server',
                'hub_set_server_targets', 'hub_get_instructions', 'hub_save_instructions',
                'hub_set_instruction_targets', 'hub_list_skills', 'hub_save_skill_file',
                'hub_delete_skill', 'hub_list_commands', 'hub_save_command',
                'hub_delete_command', 'hub_set_skills_targets', 'hub_set_commands_targets',
                'hub_project', 'hub_get_drift', 'hub_resolve_drift'];
    }

    // ---------- reads ----------

    /**
     * Full hub state for the UI: servers (env values MASKED — key + set flag only),
     * vendor/agent metadata (which supported agents are installed), the projection
     * ledger, and home availability. Safe with zero agents installed and with the
     * home unmounted (homeAvailable=false; projection actions then refuse).
     */
    private static function getState(): array
    {
        $mcp = HubStore::getMcp();
        $servers = [];
        foreach ($mcp['servers'] as $name => $def) {
            $servers[$name] = self::maskServer($def);
        }

        $registry = AgentRegistry::getRegistry();
        $agents = [];
        foreach (HubProjector::vendorMeta() as $agentId => $meta) {
            $agents[] = [
                'id' => $agentId,
                'label' => $meta['label'],
                'file' => $meta['file'],
                'installed' => !empty($registry[$agentId]['is_installed']),
                'name' => $registry[$agentId]['name'] ?? $meta['label'],
            ];
        }

        // Instruction-targetable agents (H-02) — a vendor file may serve
        // several ids (~/.codex/AGENTS.md → codex-cli AND gh-copilot).
        $instructionAgents = [];
        foreach (HubProjector::instructionAgentMeta() as $agentId => $meta) {
            $instructionAgents[] = [
                'id' => $agentId,
                'label' => $meta['label'],
                'file' => $meta['file'],
                'installed' => !empty($registry[$agentId]['is_installed']),
                'name' => $registry[$agentId]['name'] ?? $meta['label'],
            ];
        }

        $state = HubStore::getState();
        $home = HubProjector::resolveHome();
        return [
            'status' => 'ok',
            'servers' => $servers,
            'agents' => $agents,
            'projections' => $state['projections'],
            'instructions' => [
                'targets' => $state['instructions_enabledFor'],
                'agents' => $instructionAgents,
                'maxBytes' => HubStore::INSTRUCTIONS_MAX_BYTES,
            ],
            'skills' => [
                'targets' => $state['skills_enabledFor'],
                'agents' => self::treeAgents('skills', $registry),
                'maxFileBytes' => HubStore::TREE_FILE_MAX_BYTES,
                'maxTotalBytes' => HubStore::TREE_TOTAL_MAX_BYTES,
                'maxDepth' => HubStore::TREE_MAX_DEPTH,
            ],
            'commands' => [
                'targets' => $state['commands_enabledFor'],
                'agents' => self::treeAgents('commands', $registry),
                'maxFileBytes' => HubStore::TREE_FILE_MAX_BYTES,
                'maxTotalBytes' => HubStore::TREE_TOTAL_MAX_BYTES,
            ],
            'homeAvailable' => $home['ok'],
        ];
    }

    /** Targetable tree-surface agents (H-03) for the UI, with install flags. */
    private static function treeAgents(string $surface, array $registry): array
    {
        $out = [];
        foreach (HubProjector::treeAgentMeta($surface) as $agentId => $meta) {
            $out[] = [
                'id' => $agentId,
                'label' => $meta['label'],
                'file' => $meta['file'],
                'installed' => !empty($registry[$agentId]['is_installed']),
                'name' => $registry[$agentId]['name'] ?? $meta['label'],
            ];
        }
        return $out;
    }

    /** Canonical def → UI shape with env values masked. */
    private static function maskServer(array $def): array
    {
        $out = [
            'transport' => $def['transport'] ?? 'stdio',
            'command' => $def['command'] ?? '',
            'args' => $def['args'] ?? [],
            'url' => $def['url'] ?? '',
            'enabledFor' => $def['enabledFor'] ?? [],
            'env' => [],
        ];
        foreach (($def['env'] ?? []) as $k => $v) {
            $out['env'][] = ['key' => (string)$k, 'set' => ((string)$v !== '')];
        }
        return $out;
    }

    // ---------- writes ----------

    /**
     * Create/update a server. POST: name, server (JSON). env entries are
     * [{key, value, keep}] — keep=true retains the already-stored value (the UI
     * shows masked values and only sends what the user re-typed).
     */
    private static function saveMcpServer(): array
    {
        $name = (string)($_REQUEST['name'] ?? '');
        if (!preg_match(HubStore::NAME_RE, $name)) {
            return ['status' => 'error', 'message' => 'invalid server name (allowed: letters, digits, _ and -, max 64)'];
        }
        $raw = json_decode((string)($_REQUEST['server'] ?? ''), true);
        if (!is_array($raw)) return ['status' => 'error', 'message' => 'server payload must be a JSON object'];

        $err = self::validateTargets($raw['enabledFor'] ?? []);
        if ($err !== null) return ['status' => 'error', 'message' => $err];

        // Merge masked env entries against the stored def.
        $existing = HubStore::getServer($name);
        $env = [];
        foreach ((array)($raw['env'] ?? []) as $row) {
            if (!is_array($row) || !isset($row['key'])) continue;
            $k = (string)$row['key'];
            if (!empty($row['keep'])) {
                if (isset($existing['env'][$k])) $env[$k] = $existing['env'][$k];
            } else {
                $env[$k] = (string)($row['value'] ?? '');
            }
        }
        $raw['env'] = $env;

        $errors = [];
        if (!HubStore::saveServer($name, $raw, $errors)) {
            return ['status' => 'error', 'message' => implode('; ', $errors) ?: 'save failed'];
        }
        return ['status' => 'ok', 'name' => $name, 'hint' => 'Run "Apply to agents" to project the change.'];
    }

    private static function deleteMcpServer(): array
    {
        $name = (string)($_REQUEST['name'] ?? '');
        if (!preg_match(HubStore::NAME_RE, $name)) return ['status' => 'error', 'message' => 'invalid server name'];
        if (!HubStore::deleteServer($name)) return ['status' => 'error', 'message' => 'delete failed'];
        return ['status' => 'ok', 'hint' => 'Run "Apply to agents" to remove it from vendor configs.'];
    }

    private static function setServerTargets(): array
    {
        $name = (string)($_REQUEST['name'] ?? '');
        if (!preg_match(HubStore::NAME_RE, $name)) return ['status' => 'error', 'message' => 'invalid server name'];
        $ids = json_decode((string)($_REQUEST['agentIds'] ?? '[]'), true);
        if (!is_array($ids)) return ['status' => 'error', 'message' => 'agentIds must be a JSON list'];
        $err = self::validateTargets($ids);
        if ($err !== null) return ['status' => 'error', 'message' => $err];
        $errors = [];
        if (!HubStore::setServerTargets($name, array_map('strval', $ids), $errors)) {
            return ['status' => 'error', 'message' => implode('; ', $errors) ?: 'save failed'];
        }
        return ['status' => 'ok'];
    }

    // ---------- instructions (H-02) ----------

    /** The canonical instruction doc + target set. Content is never logged. */
    private static function getInstructions(): array
    {
        return [
            'status' => 'ok',
            'content' => HubStore::getInstructions(),
            'targets' => HubStore::getInstructionTargets(),
            'maxBytes' => HubStore::INSTRUCTIONS_MAX_BYTES,
        ];
    }

    /** Save the canonical doc (POST: content). Size cap enforced in HubStore. */
    private static function saveInstructions(): array
    {
        $content = (string)($_REQUEST['content'] ?? '');
        $errors = [];
        if (!HubStore::saveInstructions($content, $errors)) {
            return ['status' => 'error', 'message' => implode('; ', $errors) ?: 'save failed'];
        }
        return ['status' => 'ok', 'hint' => 'Run "Apply to agents" to project the change.'];
    }

    /** Replace the instruction target set (POST: agentIds JSON list). */
    private static function setInstructionTargets(): array
    {
        $ids = json_decode((string)($_REQUEST['agentIds'] ?? '[]'), true);
        if (!is_array($ids)) return ['status' => 'error', 'message' => 'agentIds must be a JSON list'];
        $ids = array_map('strval', $ids);
        $err = self::validateInstructionTargets($ids);
        if ($err !== null) return ['status' => 'error', 'message' => $err];
        $errors = [];
        if (!HubStore::setInstructionTargets($ids, $errors)) {
            return ['status' => 'error', 'message' => implode('; ', $errors) ?: 'save failed'];
        }
        return ['status' => 'ok'];
    }

    // ---------- skills + commands trees (H-03) ----------

    /**
     * All hub skills WITH file contents (the v1 editor edits files inline; the
     * store is capped at 2 MB total so the payload is bounded). Content goes to
     * the UI only — it is never logged.
     */
    private static function listSkills(): array
    {
        $skills = [];
        foreach (HubStore::listSkills() as $name => $info) {
            $files = [];
            foreach ($info['files'] as $rel => $bytes) {
                $files[] = ['path' => $rel, 'bytes' => $bytes,
                            'content' => (string)HubStore::getSkillFile($name, $rel)];
            }
            $skills[$name] = ['files' => $files, 'bytes' => $info['bytes']];
        }
        return ['status' => 'ok', 'skills' => $skills,
                'targets' => HubStore::getSkillsTargets(),
                'totalBytes' => HubStore::treeTotalBytes(),
                'maxTotalBytes' => HubStore::TREE_TOTAL_MAX_BYTES];
    }

    /** Create/replace one skill file (POST: skill, path, content). */
    private static function saveSkillFile(): array
    {
        $skill = (string)($_REQUEST['skill'] ?? '');
        $path = (string)($_REQUEST['path'] ?? '');
        $content = (string)($_REQUEST['content'] ?? '');
        $errors = [];
        if (!HubStore::saveSkillFile($skill, $path, $content, $errors)) {
            return ['status' => 'error', 'message' => implode('; ', $errors) ?: 'save failed'];
        }
        return ['status' => 'ok', 'hint' => 'Run "Apply to agents" to project the change.'];
    }

    /**
     * Delete a whole skill (POST: name) or — with the optional path param —
     * one extra file inside it (SKILL.md itself cannot be removed per-file).
     */
    private static function deleteSkill(): array
    {
        $name = (string)($_REQUEST['name'] ?? '');
        if (!preg_match(HubStore::NAME_RE, $name)) return ['status' => 'error', 'message' => 'invalid skill name'];
        $path = (string)($_REQUEST['path'] ?? '');
        $errors = [];
        if ($path !== '') {
            if (!HubStore::deleteSkillFile($name, $path, $errors)) {
                return ['status' => 'error', 'message' => implode('; ', $errors) ?: 'delete failed'];
            }
        } elseif (!HubStore::deleteSkill($name)) {
            return ['status' => 'error', 'message' => 'delete failed'];
        }
        return ['status' => 'ok', 'hint' => 'Run "Apply to agents" to remove the projected copies.'];
    }

    /** All hub commands WITH content (bounded by the 2 MB total cap; never logged). */
    private static function listCommands(): array
    {
        $commands = [];
        foreach (HubStore::listCommands() as $name => $bytes) {
            $commands[$name] = ['bytes' => $bytes, 'content' => (string)HubStore::getCommand($name)];
        }
        return ['status' => 'ok', 'commands' => $commands,
                'targets' => HubStore::getCommandsTargets(),
                'totalBytes' => HubStore::treeTotalBytes(),
                'maxTotalBytes' => HubStore::TREE_TOTAL_MAX_BYTES];
    }

    /** Create/replace one command (POST: name, content). */
    private static function saveCommand(): array
    {
        $name = (string)($_REQUEST['name'] ?? '');
        $content = (string)($_REQUEST['content'] ?? '');
        $errors = [];
        if (!HubStore::saveCommand($name, $content, $errors)) {
            return ['status' => 'error', 'message' => implode('; ', $errors) ?: 'save failed'];
        }
        return ['status' => 'ok', 'hint' => 'Run "Apply to agents" to project the change.'];
    }

    /** Delete a command (POST: name). */
    private static function deleteCommand(): array
    {
        $name = (string)($_REQUEST['name'] ?? '');
        if (!preg_match(HubStore::NAME_RE, $name)) return ['status' => 'error', 'message' => 'invalid command name'];
        if (!HubStore::deleteCommand($name)) return ['status' => 'error', 'message' => 'delete failed'];
        return ['status' => 'ok', 'hint' => 'Run "Apply to agents" to remove the projected copies.'];
    }

    /** Replace a tree-surface target set (POST: agentIds JSON list). */
    private static function setTreeTargets(string $surface): array
    {
        $ids = json_decode((string)($_REQUEST['agentIds'] ?? '[]'), true);
        if (!is_array($ids)) return ['status' => 'error', 'message' => 'agentIds must be a JSON list'];
        $ids = array_map('strval', $ids);
        $err = self::validateTreeTargets($surface, $ids);
        if ($err !== null) return ['status' => 'error', 'message' => $err];
        $errors = [];
        $ok = ($surface === 'skills')
            ? HubStore::setSkillsTargets($ids, $errors)
            : HubStore::setCommandsTargets($ids, $errors);
        if (!$ok) return ['status' => 'error', 'message' => implode('; ', $errors) ?: 'save failed'];
        return ['status' => 'ok'];
    }

    // ---------- projection / drift ----------

    private static function project(): array
    {
        $only = null;
        if (!empty($_REQUEST['agentIds'])) {
            $ids = json_decode((string)$_REQUEST['agentIds'], true);
            if (!is_array($ids)) return ['status' => 'error', 'message' => 'agentIds must be a JSON list'];
            $only = array_map('strval', $ids);
        }

        // Snapshot which instruction files are projected BEFORE the run, so we can tell
        // 'added' (newly projected) from 'updated' (content changed) in the notification.
        $before = HubStore::getState();
        $managedBefore = [];
        foreach (HubProjector::instructionVendors() as $p) {
            if (!empty($before['projections'][$p->ledgerKey()]['managedKeys'])) $managedBefore[] = $p->ledgerKey();
        }

        $result = HubProjector::projectAll($only);
        if (($result['status'] ?? '') !== 'ok') return $result;

        // Per-agent instruction-change Unraid notification (e.g. "Kilo Code added;
        // OpenCode updated; Claude Code removed"). Fires only when an instruction file
        // actually changed — MCP/skills/commands changes stay in the in-card results.
        $changes = HubProjector::instructionChangeSummary($managedBefore, $result['results'] ?? []);
        if (!empty($changes)) {
            $parts = array_map(static fn($c) => $c['label'] . ' ' . $c['kind'], $changes);
            \AICliAgents\Services\UtilityService::notify(implode('; ', $parts), 'Config Hub — Global Instructions');
        }

        // Affected RUNNING sessions for the changed agents — the UI offers
        // signal_reload per session; we never auto-restart anything.
        $affected = [];
        foreach ($result['writtenAgents'] ?? [] as $agentId) {
            try {
                $sessions = TerminalService::listActiveSessionsForAgent($agentId);
            } catch (\Throwable $e) {
                $sessions = [];
            }
            if (!empty($sessions)) $affected[] = ['agentId' => $agentId, 'sessions' => $sessions];
        }
        $result['affectedSessions'] = $affected;
        return $result;
    }

    private static function resolveDrift(): array
    {
        $file = (string)($_REQUEST['file'] ?? '');
        $key  = (string)($_REQUEST['key'] ?? '');
        $mode = (string)($_REQUEST['mode'] ?? '');
        if ($file === '' || $key === '') return ['status' => 'error', 'message' => 'file and key required'];
        if (!preg_match('#^~/[A-Za-z0-9_./-]{1,128}$#', $file) || strpos($file, '..') !== false) {
            return ['status' => 'error', 'message' => 'invalid file'];
        }
        // Tree keys (H-03) are relative file paths (e.g. 'my-skill/SKILL.md') —
        // slashes allowed, traversal rejected outright.
        if (!preg_match('#^[A-Za-z0-9_.][A-Za-z0-9_./-]{0,200}$#', $key) || strpos($key, '..') !== false) {
            return ['status' => 'error', 'message' => 'invalid key'];
        }
        return HubProjector::resolveDrift($file, $key, $mode);
    }

    // ---------- validation ----------

    /** enabledFor ids must exist in AgentRegistry AND have a projector. */
    private static function validateTargets($ids): ?string
    {
        if (!is_array($ids)) return 'enabledFor must be a list';
        $registry = AgentRegistry::getRegistry();
        $supported = HubProjector::vendorMeta();
        foreach ($ids as $id) {
            $id = (string)$id;
            if (!isset($registry[$id])) return "unknown agent id '$id'";
            if (!isset($supported[$id])) return "agent '$id' has no MCP projection support yet";
        }
        return null;
    }

    /** Instruction target ids must exist in AgentRegistry AND have an instruction projector. */
    private static function validateInstructionTargets($ids): ?string
    {
        if (!is_array($ids)) return 'agentIds must be a list';
        $registry = AgentRegistry::getRegistry();
        $supported = HubProjector::instructionAgentMeta();
        foreach ($ids as $id) {
            $id = (string)$id;
            if (!isset($registry[$id])) return "unknown agent id '$id'";
            if (!isset($supported[$id])) return "agent '$id' has no instruction-file projection support";
        }
        return null;
    }

    /** Tree-surface target ids must exist in AgentRegistry AND have that surface (H-03). */
    private static function validateTreeTargets(string $surface, array $ids): ?string
    {
        $registry = AgentRegistry::getRegistry();
        $supported = HubProjector::treeAgentMeta($surface);
        foreach ($ids as $id) {
            $id = (string)$id;
            if (!isset($registry[$id])) return "unknown agent id '$id'";
            if (!isset($supported[$id])) return "agent '$id' has no $surface projection surface";
        }
        return null;
    }
}
