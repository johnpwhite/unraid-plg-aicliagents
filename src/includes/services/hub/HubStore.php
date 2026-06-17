<?php
/**
 * <module_context>
 *     <name>HubStore</name>
 *     <description>Canonical Config Hub store (OP #1362 / finding H-01, phase 1 — MCP servers;
 *     OP #1363 / H-02, phase 2 — instructions; OP #1364 / H-03, phase 3 — skills +
 *     commands trees). CRUD over .aicli/hub/mcp.json (canonical MCP server registry),
 *     .aicli/hub/instructions/global.md (the ONE canonical instruction doc, 256 KB cap),
 *     .aicli/hub/skills/&lt;name&gt;/… + .aicli/hub/commands/&lt;name&gt;.md (mirrored-tree
 *     skill/command store: 256 KB per file, 2 MB total, tree depth ≤3) and
 *     .aicli/hub/state.json (per-vendor projection ledger + instructions/skills/commands
 *     enabledFor target sets) via AtomicWriteService.
 *     Server names validated [a-zA-Z0-9_-]{1,64}. env values may be secret-ish —
 *     this service NEVER logs values or instruction/skill/command content
 *     (key names / paths / counts only).</description>
 *     <dependencies>ConfigService, AtomicWriteService, LogService, GitHomeService (debounced auto-commit after successful writes — OP #1365 / H-04)</dependencies>
 *     <constraints>Static methods only. Shape validation here; AgentRegistry validation
 *     of enabledFor ids lives in HubHandler. Test hook: AICLI_HUB_STATE_DIR env override
 *     (mirrors the AICLI_MANIFEST_PATH precedent).</constraints>
 * </module_context>
 */

namespace AICliAgents\Services\Hub;

use AICliAgents\Services\AtomicWriteService;
use AICliAgents\Services\ConfigService;
use AICliAgents\Services\LogService;

class HubStore {

    const SCHEMA = 1;
    const NAME_RE = '/^[a-zA-Z0-9_-]{1,64}$/';
    const ENV_KEY_RE = '/^[A-Za-z_][A-Za-z0-9_]{0,127}$/';
    const TRANSPORTS = ['stdio', 'http', 'sse'];
    const AGENT_ID_RE = '/^[a-z0-9][a-z0-9-]{0,63}$/i';
    /** Size cap for the canonical instruction doc (H-02). */
    const INSTRUCTIONS_MAX_BYTES = 262144; // 256 KB
    /** Per-file size cap for skill/command files (H-03). */
    const TREE_FILE_MAX_BYTES = 262144; // 256 KB
    /** Total size cap across the skills/ + commands/ trees (H-03). */
    const TREE_TOTAL_MAX_BYTES = 2097152; // 2 MB
    /** Max path depth of a file inside a skill (SKILL.md=1, scripts/run.sh=2, …). */
    const TREE_MAX_DEPTH = 3;
    /** One path segment of a skill file: no leading dot, no traversal, ≤64 chars. */
    const TREE_SEGMENT_RE = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/';

    // ---------- Paths ----------

    public static function baseDir(): string {
        $env = getenv('AICLI_HUB_STATE_DIR');
        if ($env !== false && $env !== '') return rtrim($env, '/');
        return ConfigService::getUserStatePath() . '/hub';
    }

    public static function mcpFile(): string          { return self::baseDir() . '/mcp.json'; }
    public static function stateFile(): string        { return self::baseDir() . '/state.json'; }
    public static function instructionsFile(): string { return self::baseDir() . '/instructions/global.md'; }
    public static function skillsDir(): string        { return self::baseDir() . '/skills'; }
    public static function commandsDir(): string      { return self::baseDir() . '/commands'; }

    // ---------- mcp.json (canonical server registry) ----------

    /** @return array{schema:int,servers:array<string,array>} */
    public static function getMcp(): array {
        $file = self::mcpFile();
        $empty = ['schema' => self::SCHEMA, 'servers' => []];
        if (!is_file($file)) return $empty;
        $data = json_decode((string)@file_get_contents($file), true);
        if (!is_array($data) || !isset($data['servers']) || !is_array($data['servers'])) {
            LogService::log('mcp.json unreadable or malformed — treating as empty (file untouched)', LogService::LOG_WARN, 'HubStore');
            return $empty;
        }
        $data['schema'] = (int)($data['schema'] ?? self::SCHEMA);
        return $data;
    }

    public static function saveMcp(array $mcp): bool {
        $mcp['schema'] = self::SCHEMA;
        if (!isset($mcp['servers']) || !is_array($mcp['servers'])) $mcp['servers'] = [];
        ksort($mcp['servers']);
        // Empty map must encode as {} not [].
        $payload = ['schema' => $mcp['schema'], 'servers' => empty($mcp['servers']) ? new \stdClass() : $mcp['servers']];
        $ok = AtomicWriteService::writeJson(self::mcpFile(), $payload);
        if (!$ok) LogService::log('FAILED to write mcp.json', LogService::LOG_ERROR, 'HubStore');
        return $ok;
    }

    public static function getServer(string $name): ?array {
        $mcp = self::getMcp();
        return $mcp['servers'][$name] ?? null;
    }

    /**
     * Validate + normalize a canonical server definition. Returns the normalized
     * def, or null with $errors populated. NEVER log env values.
     * @param array $def {command|url, args[], env{}, transport, enabledFor[]}
     */
    public static function normalizeServer(array $def, array &$errors = []): ?array {
        $errors = [];
        $transport = (string)($def['transport'] ?? 'stdio');
        if (!in_array($transport, self::TRANSPORTS, true)) $errors[] = "invalid transport '$transport'";

        $out = ['transport' => $transport];
        if ($transport === 'stdio') {
            $cmd = trim((string)($def['command'] ?? ''));
            if ($cmd === '') $errors[] = 'command is required for stdio transport';
            $out['command'] = $cmd;
            $args = $def['args'] ?? [];
            if (!is_array($args)) $errors[] = 'args must be a list';
            $out['args'] = array_values(array_map('strval', is_array($args) ? $args : []));
            $env = $def['env'] ?? [];
            if (!is_array($env)) { $errors[] = 'env must be a map'; $env = []; }
            $cleanEnv = [];
            foreach ($env as $k => $v) {
                if (!preg_match(self::ENV_KEY_RE, (string)$k)) { $errors[] = "invalid env key '$k'"; continue; }
                $cleanEnv[(string)$k] = (string)$v;
            }
            ksort($cleanEnv);
            $out['env'] = $cleanEnv;
        } else {
            $url = trim((string)($def['url'] ?? ''));
            if ($url === '' || !preg_match('#^https?://#i', $url)) $errors[] = 'a http(s) url is required for http/sse transport';
            $out['url'] = $url;
        }

        $enabledFor = $def['enabledFor'] ?? [];
        if (!is_array($enabledFor)) { $errors[] = 'enabledFor must be a list'; $enabledFor = []; }
        $ids = [];
        foreach ($enabledFor as $id) {
            $id = (string)$id;
            if (!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/i', $id)) { $errors[] = "invalid agent id '$id'"; continue; }
            $ids[] = $id;
        }
        $out['enabledFor'] = array_values(array_unique($ids));
        sort($out['enabledFor']);

        return empty($errors) ? $out : null;
    }

    /** Create or replace one server. $def is validated/normalized. */
    public static function saveServer(string $name, array $def, array &$errors = []): bool {
        $errors = [];
        if (!preg_match(self::NAME_RE, $name)) { $errors[] = 'invalid server name (allowed: [a-zA-Z0-9_-]{1,64})'; return false; }
        $norm = self::normalizeServer($def, $errors);
        if ($norm === null) return false;
        $mcp = self::getMcp();
        $mcp['servers'][$name] = $norm;
        $ok = self::saveMcp($mcp);
        if ($ok) {
            LogService::log("saved MCP server '$name' (transport={$norm['transport']}, env keys=" . count($norm['env'] ?? []) . ', targets=' . count($norm['enabledFor']) . ')', LogService::LOG_INFO, 'HubStore');
            // H-04 (#1365): debounced auto-commit AFTER the successful write — name only, never values.
            GitHomeService::commitIfEnabled("hub: mcp server '$name' saved");
        }
        return $ok;
    }

    public static function deleteServer(string $name): bool {
        if (!preg_match(self::NAME_RE, $name)) return false;
        $mcp = self::getMcp();
        if (!isset($mcp['servers'][$name])) return true; // idempotent
        unset($mcp['servers'][$name]);
        $ok = self::saveMcp($mcp);
        if ($ok) {
            LogService::log("deleted MCP server '$name' from the hub store (vendor files reconcile on next project)", LogService::LOG_INFO, 'HubStore');
            GitHomeService::commitIfEnabled("hub: mcp server '$name' deleted");
        }
        return $ok;
    }

    /** Replace the enabledFor target list of an existing server. */
    public static function setServerTargets(string $name, array $agentIds, array &$errors = []): bool {
        $errors = [];
        $server = self::getServer($name);
        if ($server === null) { $errors[] = "no such server '$name'"; return false; }
        $server['enabledFor'] = $agentIds;
        return self::saveServer($name, $server, $errors);
    }

    // ---------- instructions/global.md (canonical instruction doc, H-02) ----------

    /** The canonical instruction doc, '' when absent. Content is NEVER logged. */
    public static function getInstructions(): string {
        $file = self::instructionsFile();
        if (!is_file($file)) return '';
        return (string)@file_get_contents($file);
    }

    /**
     * Atomic save of the canonical instruction doc. Enforces the 256 KB cap
     * and refuses content containing the managed-fence markers (which would
     * destabilise fence extraction in the projected vendor files).
     */
    public static function saveInstructions(string $content, array &$errors = []): bool {
        $errors = [];
        if (strlen($content) > self::INSTRUCTIONS_MAX_BYTES) {
            $errors[] = 'instructions exceed the ' . (self::INSTRUCTIONS_MAX_BYTES / 1024) . ' KB size cap';
            return false;
        }
        foreach ([InstructionProjector::FENCE_OPEN, InstructionProjector::FENCE_CLOSE] as $marker) {
            if (strpos($content, $marker) !== false) {
                $errors[] = 'instructions may not contain the aicli-hub managed-fence markers';
                return false;
            }
        }
        $ok = AtomicWriteService::write(self::instructionsFile(), $content);
        if ($ok) {
            LogService::log('saved hub instructions (' . strlen($content) . ' bytes)', LogService::LOG_INFO, 'HubStore');
            // H-04 (#1365): byte count only — instruction content is never logged or quoted.
            GitHomeService::commitIfEnabled('hub: instructions updated (' . strlen($content) . ' bytes)');
        } else {
            LogService::log('FAILED to write hub instructions/global.md', LogService::LOG_ERROR, 'HubStore');
        }
        return $ok;
    }

    /** Agent ids targeted for instruction projection (opt-in; defaults to none). */
    public static function getInstructionTargets(): array {
        return self::getState()['instructions_enabledFor'];
    }

    /** Replace the instruction target set (shape validation only — registry validation in HubHandler). */
    public static function setInstructionTargets(array $agentIds, array &$errors = []): bool {
        return self::setTargetSet('instructions_enabledFor', 'instruction', $agentIds, $errors);
    }

    /** Agent ids targeted for skill-tree projection (H-03; opt-in; defaults to none). */
    public static function getSkillsTargets(): array {
        return self::getState()['skills_enabledFor'];
    }

    /** Replace the skills target set (shape validation only — registry validation in HubHandler). */
    public static function setSkillsTargets(array $agentIds, array &$errors = []): bool {
        return self::setTargetSet('skills_enabledFor', 'skills', $agentIds, $errors);
    }

    /** Agent ids targeted for command projection (H-03; opt-in; defaults to none). */
    public static function getCommandsTargets(): array {
        return self::getState()['commands_enabledFor'];
    }

    /** Replace the commands target set (shape validation only — registry validation in HubHandler). */
    public static function setCommandsTargets(array $agentIds, array &$errors = []): bool {
        return self::setTargetSet('commands_enabledFor', 'commands', $agentIds, $errors);
    }

    /** Shared validate + persist for the three opt-in projection target sets. */
    private static function setTargetSet(string $field, string $label, array $agentIds, array &$errors): bool {
        $errors = [];
        $ids = [];
        foreach ($agentIds as $id) {
            $id = (string)$id;
            if (!preg_match(self::AGENT_ID_RE, $id)) { $errors[] = "invalid agent id '$id'"; continue; }
            $ids[] = $id;
        }
        if (!empty($errors)) return false;
        $ids = array_values(array_unique($ids));
        sort($ids);
        $state = self::getState();
        $state[$field] = $ids;
        $ok = self::saveState($state);
        if ($ok) LogService::log("$label targets set to [" . implode(',', $ids) . ']', LogService::LOG_INFO, 'HubStore');
        return $ok;
    }

    // ---------- skills/ + commands/ trees (H-03, phase 3) ----------

    /**
     * Validate a skill-relative file path: ≤TREE_MAX_DEPTH segments, each
     * matching TREE_SEGMENT_RE (no leading dot, so '.' / '..' / dotfiles are
     * structurally impossible), forward slashes only.
     */
    public static function validateTreePath(string $rel, array &$errors): bool {
        if ($rel === '' || strlen($rel) > 200) { $errors[] = 'invalid file path'; return false; }
        if (strpos($rel, '\\') !== false || $rel[0] === '/' || substr($rel, -1) === '/') {
            $errors[] = "invalid file path '$rel'"; return false;
        }
        $segments = explode('/', $rel);
        if (count($segments) > self::TREE_MAX_DEPTH) {
            $errors[] = 'file path exceeds the max depth of ' . self::TREE_MAX_DEPTH; return false;
        }
        foreach ($segments as $seg) {
            if (!preg_match(self::TREE_SEGMENT_RE, $seg)) {
                $errors[] = "invalid path segment '$seg' (no leading dot, no traversal, max 64 chars)";
                return false;
            }
        }
        return true;
    }

    /** Total bytes currently stored across the skills/ + commands/ trees. */
    public static function treeTotalBytes(): int {
        $total = 0;
        foreach (self::listSkills() as $info) $total += $info['bytes'];
        foreach (self::listCommands() as $bytes) $total += $bytes;
        return $total;
    }

    /**
     * All hub skills: name => {files: {relPath => bytes}, bytes: total}.
     * Only validly named dirs/paths are surfaced (anything else is ignored,
     * never deleted). Content is NOT included (see getSkillFile).
     * @return array<string,array{files:array<string,int>,bytes:int}>
     */
    public static function listSkills(): array {
        $out = [];
        $dir = self::skillsDir();
        if (!is_dir($dir)) return $out;
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..' || !preg_match(self::NAME_RE, $name) || !is_dir("$dir/$name")) continue;
            $files = [];
            self::collectTreeFiles("$dir/$name", '', 1, $files);
            ksort($files);
            $out[$name] = ['files' => $files, 'bytes' => array_sum($files)];
        }
        ksort($out);
        return $out;
    }

    /** Recursive file collection under a skill dir, depth-capped + name-validated. */
    private static function collectTreeFiles(string $abs, string $relPrefix, int $depth, array &$files): void {
        if ($depth > self::TREE_MAX_DEPTH) return;
        foreach (scandir($abs) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || !preg_match(self::TREE_SEGMENT_RE, $entry)) continue;
            $rel = ($relPrefix === '') ? $entry : "$relPrefix/$entry";
            if (is_dir("$abs/$entry")) {
                self::collectTreeFiles("$abs/$entry", $rel, $depth + 1, $files);
            } elseif (is_file("$abs/$entry")) {
                $files[$rel] = (int)filesize("$abs/$entry");
            }
        }
    }

    /** One skill file's content, or null when absent/invalid. Content is NEVER logged. */
    public static function getSkillFile(string $skill, string $rel): ?string {
        $errors = [];
        if (!preg_match(self::NAME_RE, $skill) || !self::validateTreePath($rel, $errors)) return null;
        $file = self::skillsDir() . "/$skill/$rel";
        if (!is_file($file)) return null;
        return (string)@file_get_contents($file);
    }

    /**
     * Create/replace one file inside a skill (atomic). A NEW skill must start
     * with SKILL.md (the one mandatory file). Enforces the per-file 256 KB cap
     * and the 2 MB total tree cap. Content is NEVER logged (path/bytes only).
     */
    public static function saveSkillFile(string $skill, string $rel, string $content, array &$errors = []): bool {
        $errors = [];
        if (!preg_match(self::NAME_RE, $skill)) { $errors[] = 'invalid skill name (allowed: [a-zA-Z0-9_-]{1,64})'; return false; }
        if (!self::validateTreePath($rel, $errors)) return false;
        if (strlen($content) > self::TREE_FILE_MAX_BYTES) {
            $errors[] = 'file exceeds the ' . (self::TREE_FILE_MAX_BYTES / 1024) . ' KB per-file cap';
            return false;
        }
        $skillDir = self::skillsDir() . "/$skill";
        if (!is_dir($skillDir) && $rel !== 'SKILL.md') {
            $errors[] = "new skill '$skill' must be created with SKILL.md first";
            return false;
        }
        $file = "$skillDir/$rel";
        $existing = is_file($file) ? (int)filesize($file) : 0;
        if (self::treeTotalBytes() - $existing + strlen($content) > self::TREE_TOTAL_MAX_BYTES) {
            $errors[] = 'skills/commands store exceeds the ' . (self::TREE_TOTAL_MAX_BYTES / 1048576) . ' MB total cap';
            return false;
        }
        $ok = AtomicWriteService::write($file, $content);
        if ($ok) {
            LogService::log("saved skill file $skill/$rel (" . strlen($content) . ' bytes)', LogService::LOG_INFO, 'HubStore');
            GitHomeService::commitIfEnabled("hub: skill file $skill/$rel saved");
        } else {
            LogService::log("FAILED to write skill file $skill/$rel", LogService::LOG_ERROR, 'HubStore');
        }
        return $ok;
    }

    /**
     * Delete one extra file from a skill. SKILL.md cannot be removed this way —
     * delete the whole skill instead (it is the skill's mandatory anchor).
     */
    public static function deleteSkillFile(string $skill, string $rel, array &$errors = []): bool {
        $errors = [];
        if (!preg_match(self::NAME_RE, $skill)) { $errors[] = 'invalid skill name'; return false; }
        if (!self::validateTreePath($rel, $errors)) return false;
        if ($rel === 'SKILL.md') { $errors[] = 'SKILL.md cannot be removed on its own — delete the whole skill'; return false; }
        $skillDir = self::skillsDir() . "/$skill";
        $file = "$skillDir/$rel";
        if (!is_file($file)) return true; // idempotent
        if (!@unlink($file)) { $errors[] = 'delete failed'; return false; }
        // Prune now-empty subdirs up to (not including) the skill root.
        $d = dirname($file);
        while ($d !== $skillDir && strncmp($d, $skillDir . '/', strlen($skillDir) + 1) === 0) {
            if (!@rmdir($d)) break; // non-empty — stop
            $d = dirname($d);
        }
        LogService::log("deleted skill file $skill/$rel", LogService::LOG_INFO, 'HubStore');
        GitHomeService::commitIfEnabled("hub: skill file $skill/$rel deleted");
        return true;
    }

    /** Delete a whole skill tree from the hub store (idempotent). */
    public static function deleteSkill(string $skill): bool {
        if (!preg_match(self::NAME_RE, $skill)) return false;
        $dir = self::skillsDir() . "/$skill";
        if (!is_dir($dir)) return true; // idempotent
        if (!self::removeTree($dir)) {
            LogService::log("FAILED to delete skill '$skill'", LogService::LOG_ERROR, 'HubStore');
            return false;
        }
        LogService::log("deleted skill '$skill' from the hub store (projected copies reconcile on next project)", LogService::LOG_INFO, 'HubStore');
        GitHomeService::commitIfEnabled("hub: skill '$skill' deleted");
        return true;
    }

    /** Bounded recursive rm of a hub-store subtree (store paths only — never vendor dirs). */
    private static function removeTree(string $dir): bool {
        $ok = true;
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $p = "$dir/$entry";
            if (is_dir($p) && !is_link($p)) $ok = self::removeTree($p) && $ok;
            else $ok = @unlink($p) && $ok;
        }
        return @rmdir($dir) && $ok;
    }

    /**
     * All hub commands: name => bytes (a command is ONE markdown file,
     * commands/&lt;name&gt;.md). Content is NOT included (see getCommand).
     * @return array<string,int>
     */
    public static function listCommands(): array {
        $out = [];
        $dir = self::commandsDir();
        if (!is_dir($dir)) return $out;
        foreach (scandir($dir) ?: [] as $entry) {
            if (substr($entry, -3) !== '.md') continue;
            $name = substr($entry, 0, -3);
            if (!preg_match(self::NAME_RE, $name) || !is_file("$dir/$entry")) continue;
            $out[$name] = (int)filesize("$dir/$entry");
        }
        ksort($out);
        return $out;
    }

    /** One command's content, or null when absent/invalid. Content is NEVER logged. */
    public static function getCommand(string $name): ?string {
        if (!preg_match(self::NAME_RE, $name)) return null;
        $file = self::commandsDir() . "/$name.md";
        if (!is_file($file)) return null;
        return (string)@file_get_contents($file);
    }

    /** Create/replace one command (atomic). Same per-file + total caps as skills. */
    public static function saveCommand(string $name, string $content, array &$errors = []): bool {
        $errors = [];
        if (!preg_match(self::NAME_RE, $name)) { $errors[] = 'invalid command name (allowed: [a-zA-Z0-9_-]{1,64})'; return false; }
        if (strlen($content) > self::TREE_FILE_MAX_BYTES) {
            $errors[] = 'file exceeds the ' . (self::TREE_FILE_MAX_BYTES / 1024) . ' KB per-file cap';
            return false;
        }
        $file = self::commandsDir() . "/$name.md";
        $existing = is_file($file) ? (int)filesize($file) : 0;
        if (self::treeTotalBytes() - $existing + strlen($content) > self::TREE_TOTAL_MAX_BYTES) {
            $errors[] = 'skills/commands store exceeds the ' . (self::TREE_TOTAL_MAX_BYTES / 1048576) . ' MB total cap';
            return false;
        }
        $ok = AtomicWriteService::write($file, $content);
        if ($ok) {
            LogService::log("saved command '$name' (" . strlen($content) . ' bytes)', LogService::LOG_INFO, 'HubStore');
            GitHomeService::commitIfEnabled("hub: command '$name' saved");
        } else {
            LogService::log("FAILED to write command '$name'", LogService::LOG_ERROR, 'HubStore');
        }
        return $ok;
    }

    /** Delete a command from the hub store (idempotent). */
    public static function deleteCommand(string $name): bool {
        if (!preg_match(self::NAME_RE, $name)) return false;
        $file = self::commandsDir() . "/$name.md";
        if (!is_file($file)) return true; // idempotent
        if (!@unlink($file)) return false;
        LogService::log("deleted command '$name' from the hub store (projected copies reconcile on next project)", LogService::LOG_INFO, 'HubStore');
        GitHomeService::commitIfEnabled("hub: command '$name' deleted");
        return true;
    }

    // ---------- state.json (projection ledger + projection target sets) ----------

    /** The three opt-in projection target-set fields stored in state.json. */
    const TARGET_FIELDS = ['instructions_enabledFor', 'skills_enabledFor', 'commands_enabledFor'];

    /** @return array{schema:int,projections:array<string,array>,instructions_enabledFor:string[],skills_enabledFor:string[],commands_enabledFor:string[]} */
    public static function getState(): array {
        $file = self::stateFile();
        $empty = ['schema' => self::SCHEMA, 'projections' => [],
                  'instructions_enabledFor' => [], 'skills_enabledFor' => [], 'commands_enabledFor' => []];
        if (!is_file($file)) return $empty;
        $data = json_decode((string)@file_get_contents($file), true);
        if (!is_array($data) || !isset($data['projections']) || !is_array($data['projections'])) return $empty;
        $data['schema'] = (int)($data['schema'] ?? self::SCHEMA);
        foreach (self::TARGET_FIELDS as $field) {
            $targets = is_array($data[$field] ?? null) ? $data[$field] : [];
            $data[$field] = array_values(array_map('strval', $targets));
        }
        return $data;
    }

    public static function saveState(array $state): bool {
        $state['schema'] = self::SCHEMA;
        if (!isset($state['projections']) || !is_array($state['projections'])) $state['projections'] = [];
        ksort($state['projections']);
        $payload = ['schema' => $state['schema'],
                    'projections' => empty($state['projections']) ? new \stdClass() : $state['projections']];
        foreach (self::TARGET_FIELDS as $field) {
            $payload[$field] = is_array($state[$field] ?? null) ? array_values(array_map('strval', $state[$field])) : [];
        }
        $ok = AtomicWriteService::writeJson(self::stateFile(), $payload);
        if (!$ok) LogService::log('FAILED to write state.json (projection ledger)', LogService::LOG_ERROR, 'HubStore');
        return $ok;
    }
}
