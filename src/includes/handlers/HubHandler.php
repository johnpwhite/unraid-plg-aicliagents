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
use AICliAgents\Services\Hub\McpConnectionTester;
use AICliAgents\Services\Hub\HubStore;
use AICliAgents\Services\LogService;
use AICliAgents\Services\TerminalService;

class HubHandler
{
    /**
     * A drift key as the projectors make it: an MCP name path, a tree file path, or an
     * OpenCode-Claude entry such as "instructions:~/.claude/rules/my rule.md" (#666 R-163).
     */
    public const DRIFT_KEY_RE = '#^[A-Za-z0-9_.~][A-Za-z0-9_./:~ +@,=-]{0,200}$#D';

    /**
     * An OpenCode-Claude entry names a file the user made under ~/.claude, so the file name can
     * hold any printable character, such as "rule (1).md" or "Café.md" (#667). The key is only
     * matched against the projector's entries. It never becomes a path, so only control
     * characters are refused.
     */
    public const COMPAT_DRIFT_KEY_RE = '#^instructions:~/\.claude/[^\x00-\x1F\x7F]{1,400}$#uD';

    /** True when the drift key is one the projectors can make and the resolve action may take. */
    public static function driftKeyIsValid(string $key): bool
    {
        // An 'instructions:' key is only ever the OpenCode-Claude shape. The generic shape also
        // allows ':' and '/' (tree keys), so without this an entry outside ~/.claude such as
        // 'instructions:/etc/passwd' would pass the generic check.
        if (strncmp($key, 'instructions:', 13) === 0) return preg_match(self::COMPAT_DRIFT_KEY_RE, $key) === 1;
        if (preg_match(self::DRIFT_KEY_RE, $key) === 1 && strpos($key, '..') === false) return true;
        return preg_match(self::COMPAT_DRIFT_KEY_RE, $key) === 1;
    }

    public static function handle($action, $id): ?array
    {
        switch ($action) {
            case 'hub_get_state':               return self::getState();
            case 'hub_save_mcp_server':         return self::saveMcpServer();
            case 'hub_delete_mcp_server':       return self::deleteMcpServer();
            case 'hub_test_mcp_server':         return self::testMcpServer();
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
            case 'hub_set_server_disabled':     return self::setServerDisabled();
            case 'hub_move_server_secrets':     return self::moveServerSecrets();
            case 'hub_get_drift':               return HubProjector::detectDrift();
            case 'hub_resolve_drift':           return self::resolveDrift();
            case 'hub_get_drift_detail':        return self::driftDetail();
            default:                            return null;
        }
    }

    /** Actions handled by this handler. */
    public static function actions(): array
    {
        return ['hub_get_state', 'hub_save_mcp_server', 'hub_delete_mcp_server',
                'hub_set_server_targets', 'hub_set_server_disabled', 'hub_move_server_secrets', 'hub_test_mcp_server', 'hub_get_instructions', 'hub_save_instructions',
                'hub_set_instruction_targets', 'hub_list_skills', 'hub_save_skill_file',
                'hub_delete_skill', 'hub_list_commands', 'hub_save_command',
                'hub_delete_command', 'hub_set_skills_targets', 'hub_set_commands_targets',
                'hub_project', 'hub_get_drift', 'hub_get_drift_detail', 'hub_resolve_drift'];
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
        // #798: Claude Code and OpenCode already read the hub document through ~/.claude.
        $homeForCoverage = HubProjector::resolveHome();
        $covered = $homeForCoverage['ok'] ? HubProjector::instructionCoverage((string)$homeForCoverage['home']) : [];
        foreach (HubProjector::instructionAgentMeta() as $agentId => $meta) {
            $instructionAgents[] = [
                'covered' => $covered[$agentId] ?? '',
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
            'disabled' => !empty($def['disabled']),
            'command' => $def['command'] ?? '',
            'args' => $def['args'] ?? [],
            // #588: a legacy URL can still hold a raw credential in its query part.
            'url' => HubProjector::redactForBrowser((string)($def['url'] ?? '')),
            'enabledFor' => $def['enabledFor'] ?? [],
            'env' => [],
            'headers' => [],
            'authHeader' => $def['authHeader'] ?? '',
        ];
        foreach (($def['env'] ?? []) as $k => $v) {
            $out['env'][] = ['key' => (string)$k, 'set' => ((string)$v !== '')];
        }
        foreach (($def['headers'] ?? []) as $k => $v) {
            // `bearer` tells the editor it can show this as a token with a fixed "Bearer " prefix.
            // The value itself never leaves the server.
            $out['headers'][] = ['key' => (string)$k, 'set' => ((string)$v !== ''),
                                 'bearer' => (strcasecmp((string)$k, 'Authorization') === 0 && stripos((string)$v, 'Bearer ') === 0)];
        }
        return $out;
    }

    // ---------- writes ----------

    /**
     * Create/update a server. POST: name, server (JSON). env and headers entries are
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

        // The editor does not carry the on/off switch; an edit keeps the stored state.
        $existing = HubStore::getServer($name);
        if (!array_key_exists('disabled', $raw) && !empty($existing['disabled'])) $raw['disabled'] = true;
        $raw = self::mergeWithStored($name, $raw);

        $errors = [];
        if (!HubStore::saveServer($name, $raw, $errors)) {
            return self::saveFailure($name, $errors);
        }
        return ['status' => 'ok', 'name' => $name, 'hint' => 'Run "Apply to agents" to project the change.',
                'unresolved' => HubProjector::unresolvedPlaceholders(HubStore::getServer($name) ?? [])];
    }

    /**
     * Error reply for a failed server save. F10: a server saved before the raw-secret
     * refusal keeps its typed value (a "keep" row carries it forward), so every later
     * save is refused. Say what to do, and flag the editor to offer the one-click move.
     */
    private static function saveFailure(string $name, array $errors): array
    {
        $res = ['status' => 'error', 'message' => implode('; ', $errors) ?: 'save failed'];
        $stored = HubStore::getServer($name);
        if ($stored !== null && !empty(HubStore::rawSecretProblems([$name => $stored]))) {
            $res['canMoveToVault'] = true;
            $res['message'] .= ' This server was saved earlier with a typed secret. '
                . 'Press "Move to the secrets vault": the Config Hub puts each secret in the vault and keeps only its key in braces.';
        }
        return $res;
    }

    /**
     * POST: name. One-click fix for a server saved with a typed secret (F10): each raw
     * credential in an env variable, header or URL goes into the secrets vault and the
     * stored server keeps only its {KEY}. The reply names the places and the vault keys,
     * never a value.
     */
    private static function moveServerSecrets(): array
    {
        $name = (string)($_REQUEST['name'] ?? '');
        if (!preg_match(HubStore::NAME_RE, $name)) return ['status' => 'error', 'message' => 'invalid server name'];
        $stored = HubStore::getServer($name);
        if ($stored === null) return ['status' => 'error', 'message' => 'unknown server'];
        $errors = [];
        $moved = null;
        $result = null;
        // #890: the vault and the free-form list change under one lock. The new key names are
        // chosen against the vault as read inside the lock, so two moves cannot take one name.
        \AICliAgents\Services\SecretService::withLock(static function () use ($name, $stored, &$errors, &$moved, &$result) {
            $saved = \AICliAgents\Services\SecretService::updateAgentSecrets(static function (array $vault) use ($name, $stored, &$errors, &$moved, &$result) {
                $moved = HubStore::moveRawSecretsOut($name, $stored, $vault);
                if (empty($moved['fields'])) {
                    $result = ['status' => 'ok', 'moved' => [], 'keys' => [], 'message' => 'Nothing to move: this server holds no typed secret.'];
                    return null;
                }
                // Check the new definition first, so the vault is not changed for a server that still would not save.
                if (HubStore::normalizeServer($moved['def'], $errors) === null) {
                    $result = ['status' => 'error', 'message' => 'The server still cannot be saved: ' . implode('; ', $errors)];
                    return null;
                }
                return array_merge($vault, $moved['secrets']);
            });
            if (!$saved) {
                $result = ['status' => 'error', 'message' => 'The secrets vault could not be written. Nothing was changed. ' . (\AICliAgents\Services\SecretService::lastUpdateMessage() ?? '')];
                return;
            }
            if ($result !== null) return;
            $keys = array_keys($moved['secrets']);
            \AICliAgents\Services\SecretService::updateFreeformKeys(static fn(array $freeform) => array_values(array_unique(array_merge($freeform, $keys))));
        });
        if ($result !== null) return $result;
        if (!HubStore::saveServer($name, $moved['def'], $errors)) {
            return ['status' => 'error', 'message' => 'The secrets are in the vault, but the server could not be saved: ' . implode('; ', $errors)];
        }
        LogService::log("moved " . count($moved['fields']) . " typed secret(s) of MCP server '$name' to the vault", LogService::LOG_INFO, 'HubHandler');
        return ['status' => 'ok', 'moved' => $moved['fields'], 'keys' => array_keys($moved['secrets']),
                'hint' => 'Run "Apply to agents" to project the change.'];
    }

    /** Merge masked env and header entries against the stored def of $name (if any). */
    private static function mergeWithStored(string $name, array $raw): array
    {
        $existing = ($name !== '' && preg_match(HubStore::NAME_RE, $name)) ? HubStore::getServer($name) : null;
        $raw['env'] = self::mergeMasked((array)($raw['env'] ?? []), (array)($existing['env'] ?? []));
        $raw['headers'] = self::mergeMasked((array)($raw['headers'] ?? []), (array)($existing['headers'] ?? []));
        return $raw;
    }

    /**
     * Masked [{key, value, keep}] rows to a key => value map. keep=true retains the
     * stored value for that key (dropped if nothing is stored under it).
     */
    private static function mergeMasked(array $rows, array $stored): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['key'])) continue;
            $k = (string)$row['key'];
            if (!empty($row['keep'])) {
                if (isset($stored[$k])) $out[$k] = $stored[$k];
            } else {
                $out[$k] = (string)($row['value'] ?? '');
            }
        }
        return $out;
    }

    /**
     * Probe a remote server with the editor's current (unsaved) values. POST: name
     * (optional, lets masked "unchanged" values come from the stored def), server
     * (JSON, same shape as hub_save_mcp_server). Nothing is saved.
     *
     * This makes a request from the Unraid host to the address that the admin typed
     * (a server-side request, LAN addresses included). It runs behind the login and the
     * CSRF check, so it has the trust of an admin. The reply carries the HTTP status code
     * and a fixed plain reason only; the remote server's own text is never passed on.
     */
    private static function testMcpServer(): array
    {
        $raw = json_decode((string)($_REQUEST['server'] ?? ''), true);
        if (!is_array($raw)) return ['status' => 'error', 'message' => 'server payload must be a JSON object'];
        $raw['enabledFor'] = [];
        $raw = self::mergeWithStored((string)($_REQUEST['name'] ?? ''), $raw);
        $errors = [];
        $def = HubStore::normalizeServer($raw, $errors);
        if ($def === null) return ['status' => 'error', 'message' => implode('; ', $errors)];
        if ($def['transport'] === 'stdio') return ['status' => 'error', 'message' => 'Only remote (http/sse) servers can be tested here.'];
        $unresolved = HubProjector::unresolvedPlaceholders($def);
        if (!empty($def['headers'])) $def['headers'] = HubProjector::resolvePlaceholders($def['headers']);
        $result = McpConnectionTester::test($def);
        LogService::log("tested MCP server connection (ok=" . (!empty($result['ok']) ? '1' : '0') . ', status=' . (int)($result['status'] ?? 0) . ')', LogService::LOG_INFO, 'HubHandler');
        return ['status' => 'ok', 'result' => $result, 'unresolved' => $unresolved];
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
            return self::saveFailure($name, $errors);
        }
        return ['status' => 'ok'];
    }

    /** POST: name, disabled ('1'/'0'). Takes effect for every agent at the next projection. */
    private static function setServerDisabled(): array
    {
        $name = (string)($_REQUEST['name'] ?? '');
        if (!preg_match(HubStore::NAME_RE, $name)) return ['status' => 'error', 'message' => 'invalid server name'];
        $disabled = in_array((string)($_REQUEST['disabled'] ?? ''), ['1', 'true'], true);
        $errors = [];
        if (!HubStore::setServerDisabled($name, $disabled, $errors)) {
            return self::saveFailure($name, $errors);
        }
        return ['status' => 'ok', 'disabled' => $disabled,
                'hint' => 'Run "Apply to agents" to ' . ($disabled ? 'remove it from' : 'add it back to') . ' the agent configs.'];
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

    /**
     * Check the file and key of a drift request. Returns an error array, or null when both are
     * valid. The resolve action and the read-only detail action use the same two checks.
     */
    private static function driftTargetProblem(string $file, string $key): ?array
    {
        if ($file === '' || $key === '') return ['status' => 'error', 'message' => 'file and key required'];
        // Relay ledger keys carry a '#<surface>' suffix (e.g.
        // ~/.claude/CLAUDE.md#aicli-relay-guidance). Without '#' in the class this
        // rejected every Relay drift resolution as 'invalid file', so Adopt,
        // Overwrite and Release all silently did nothing for Relay keys.
        // The '#' must stay escaped: it is also the delimiter, so an unescaped one
        // closes the pattern early and preg_match returns false for *every* input,
        // which broke Adopt/Overwrite/Release for all agents, not just Relay keys.
        if (!preg_match('#^~/[A-Za-z0-9_./\#-]{1,160}$#D', $file) || strpos($file, '..') !== false) {
            return ['status' => 'error', 'message' => 'invalid file'];
        }
        // Tree keys (H-03) are relative file paths (e.g. 'my-skill/SKILL.md') —
        // slashes allowed, traversal rejected outright.
        if (!self::driftKeyIsValid($key)) {
            return ['status' => 'error', 'message' => 'invalid key'];
        }
        return null;
    }

    private static function resolveDrift(): array
    {
        $file = (string)($_REQUEST['file'] ?? '');
        $key  = (string)($_REQUEST['key'] ?? '');
        $mode = (string)($_REQUEST['mode'] ?? '');
        $problem = self::driftTargetProblem($file, $key);
        if ($problem !== null) return $problem;
        return HubProjector::resolveDrift($file, $key, $mode);
    }

    /** Read-only: both texts of one drift row for the compare view (#797). Writes nothing. */
    private static function driftDetail(): array
    {
        $file = (string)($_REQUEST['file'] ?? '');
        $key  = (string)($_REQUEST['key'] ?? '');
        $problem = self::driftTargetProblem($file, $key);
        if ($problem !== null) return $problem;
        return HubProjector::driftDetail($file, $key);
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
