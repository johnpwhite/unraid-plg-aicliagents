<?php
/**
 * <module_context>
 *     <name>EnvService</name>
 *     <description>General env vars store: agent-wide defaults + per-(workspace,agent) overrides. Manifest-seeded defaults via AgentRegistry's `default_envs` field — additive, sidecar-tracked, never overwrites a user value, never auto-removes when the manifest entry disappears in a later version, honours user deletions across upgrades. Builds the launch-time effective-env merge across all 5 tiers (incl. SecretService secrets) — single source of truth that both TerminalService and aicli-shell.sh consume.</description>
 *     <dependencies>ConfigService, AtomicWriteService, SecretService, AgentRegistry, ValidationService, LogService</dependencies>
 *     <constraints>Static methods only. Follows ArgsService path-hashing conventions. Validates every key (shape + reserved-name deny-list); sanitises every value (strips control chars, caps at 4 KB).</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

require_once __DIR__ . '/FileLock.php';

class EnvService {

    private const KEY_REGEX  = '/^[A-Za-z_][A-Za-z0-9_]{0,127}$/D';
    private const VALUE_MAX  = 4096; // bytes
    private const SIDECAR_KEYS_LIST = '__seeded_keys';
    /** Vault keys with this prefix are Relay credentials and never enter an agent's environment. */
    public const RELAY_SECRET_PREFIX = 'AICLI_RELAY_';

    /**
     * Names the user MUST NOT set — anything our launch path manages internally.
     * Setting these would either be a no-op (we overwrite later) or break the
     * agent's runtime contract.
     */
    private const RESERVED_KEYS = [
        'PATH', 'HOME', 'USER', 'SHELL', 'TERM', 'LANG', 'LC_ALL', 'COLORTERM',
        'AGENT_ID', 'AGENT_NAME', 'BINARY', 'RESUME_CMD', 'RESUME_LATEST',
        'ENV_PREFIX', 'USER_NAME', 'HOME_DIR', 'NODE_PATH', 'NODE_OPTIONS',
        'NODE_COMPILE_CACHE', 'DEBUG_LOG', 'PWD', 'OLDPWD',
    ];

    /** Prefixes the user MUST NOT set — plugin-internal namespaces. */
    private const RESERVED_PREFIXES = ['AICLI_', 'frozen_'];

    // ---------- Paths ----------

    public static function getAgentEnvPath(string $agentId): string {
        return ConfigService::getUserStatePath() . "/envs/env_agent_{$agentId}.json";
    }

    public static function getWorkspaceEnvPath(string $path, string $agentId): string {
        $hash = md5($path . $agentId);
        return ConfigService::getUserStatePath() . "/envs/env_{$hash}.json";
    }

    public static function getSeededSidecarPath(string $agentId): string {
        return ConfigService::getUserStatePath() . "/envs/seeded_agent_{$agentId}.json";
    }

    // ---------- Validation ----------

    public static function validateKey(string $k): bool {
        if (!preg_match(self::KEY_REGEX, $k)) return false;
        if (in_array($k, self::RESERVED_KEYS, true)) return false;
        foreach (self::RESERVED_PREFIXES as $p) {
            if (strpos($k, $p) === 0) return false;
        }
        return true;
    }

    public static function isReservedKey(string $k): bool {
        if (in_array($k, self::RESERVED_KEYS, true)) return true;
        foreach (self::RESERVED_PREFIXES as $p) {
            if (strpos($k, $p) === 0) return true;
        }
        return false;
    }

    public static function sanitiseValue(string $v): string {
        // Strip null + line-terminators (would break the bash export and the JSON file).
        $v = str_replace(["\0", "\r", "\n"], '', $v);
        if (strlen($v) > self::VALUE_MAX) {
            $v = substr($v, 0, self::VALUE_MAX);
        }
        return $v;
    }

    /**
     * Validate + sanitise a key⇒value map. Returns [$cleanMap, $rejected]
     * where $rejected is a list of [key, reason] pairs the caller can surface
     * to the user. Does not throw — partial maps are typical (one bad key
     * shouldn't lose the user's other valid edits).
     */
    public static function validateMap(array $map): array {
        $clean = [];
        $rejected = [];
        foreach ($map as $k => $v) {
            $k = (string)$k;
            if (!preg_match(self::KEY_REGEX, $k)) {
                $rejected[] = [$k, 'invalid name (must be ALPHA / digit / _, not starting with digit)'];
                continue;
            }
            if (in_array($k, self::RESERVED_KEYS, true)) {
                $rejected[] = [$k, 'reserved name (managed by the plugin runtime)'];
                continue;
            }
            foreach (self::RESERVED_PREFIXES as $p) {
                if (strpos($k, $p) === 0) {
                    $rejected[] = [$k, "reserved prefix '$p' (managed by the plugin runtime)"];
                    continue 2;
                }
            }
            $sv = self::sanitiseValue((string)$v);
            $clean[$k] = $sv;
        }
        return [$clean, $rejected];
    }

    // ---------- Agent-tier (general) ----------

    public static function getAgentEnvs(string $agentId): array {
        return self::readJsonMap(self::getAgentEnvPath($agentId));
    }

    /**
     * Replace the agent-tier general envs wholesale with $map, under the env lock (#890).
     * Caller is responsible for any merge/diff semantics. A caller that starts from the
     * stored map (read, change, write back) must use updateAgentEnvs(): two saves at once
     * can otherwise lose a key. False means nothing was written; lastUpdateMessage() says why.
     */
    public static function saveAgentEnvs(string $agentId, array $map): bool {
        return self::updateAgentEnvs($agentId, static fn(array $cur) => $map);
    }

    // ---------- Locked read-modify-write (#890) ----------

    /** Seconds an update waits for the env lock before it gives up. Tests shorten it. */
    public static float $lockWaitSeconds = 10.0;
    private static ?string $lastUpdateMessage = null;
    private static ?string $lastUpdateNotice = null;

    /** Lock file for the general env files. In the RAM state root, never on flash or /mnt/user. */
    public static function lockPath(): string {
        return rtrim(SecretPaths::stateRoot(), '/') . '/envs.lock';
    }

    /** Plain-words reason the last update failed in this process, or null. Show it to the person. */
    public static function lastUpdateMessage(): ?string { return self::$lastUpdateMessage; }

    /** A message for a save that WORKED but needs attention (a damaged file was set aside), or null. */
    public static function lastUpdateNotice(): ?string { return self::$lastUpdateNotice; }

    /**
     * Run $fn under the env lock (non-blocking, 10 s deadline, re-entrant, close-on-exec).
     * When the lock is not taken $fn does not run and $busy is true.
     * @return mixed
     */
    public static function withLock(callable $fn, ?bool &$busy = null) {
        $busy = false;
        $path = self::lockPath();
        if (!FileLock::isHeld($path)) { self::$lastUpdateMessage = null; self::$lastUpdateNotice = null; }
        $result = FileLock::run($path, self::$lockWaitSeconds, $fn, $busy);
        if ($busy) {
            self::$lastUpdateMessage = 'The variable store is busy with another save, so this change was not saved. Retry in a moment.';
            LogService::log('Env store: NOT SAVED: the lock stayed busy for ' . self::$lockWaitSeconds . 's', LogService::LOG_ERROR, 'EnvService');
        }
        return $result;
    }

    /**
     * Change the agent-tier envs under the lock. $fn gets the stored map and returns the new
     * map, or null for "no change". An empty new map removes the file. A file that cannot be
     * parsed is copied to <file>.corrupt-* first (#636) and the update goes on with an empty map.
     *
     * @param callable(array):(?array) $fn
     */
    public static function updateAgentEnvs(string $agentId, callable $fn): bool {
        $file = self::getAgentEnvPath($agentId);
        return self::updateJsonFile($file, static fn(array $cur) => $fn($cur));
    }

    /**
     * Change the workspace-tier envs under the lock. $fn gets the stored delta and returns the
     * new map (it may hold keys that equal the agent value). The delta against the agent tier
     * is taken inside the lock. Empty delta removes the file.
     *
     * @param callable(array):(?array) $fn
     */
    public static function updateWorkspaceEnvs(string $path, string $agentId, callable $fn): bool {
        $file = self::getWorkspaceEnvPath($path, $agentId);
        return self::updateJsonFile($file, static function (array $cur) use ($fn, $agentId) {
            $map = $fn($cur);
            if ($map === null) return null;
            $agentMap = self::getAgentEnvs($agentId);
            $delta = [];
            foreach ($map as $k => $v) {
                $sv = (string)$v;
                $av = (string)($agentMap[$k] ?? '__AICLI_NOT_SET__');
                if ($av !== $sv) $delta[$k] = $sv;
            }
            return $delta;
        });
    }

    private static function updateJsonFile(string $file, callable $fn): bool {
        ConfigService::refuseLiveStateInTests($file);
        $ok = false;
        $busy = false;
        self::withLock(static function () use ($file, $fn, &$ok) {
            $damaged = false;
            $cur = [];
            if (file_exists($file)) {
                $decoded = json_decode((string)@file_get_contents($file), true);
                if (is_array($decoded)) $cur = $decoded; else $damaged = true;
            }
            $next = $fn($cur);
            if ($next === null) {
                $ok = true;
                if ($damaged) self::$lastUpdateNotice = 'A variables file is damaged and could not be read. Nothing was changed. The next save sets it aside as a copy and starts a new file.';
                return;
            }
            if ($damaged) {
                $copy = FileLock::keepCorruptCopy($file);
                if ($copy === null) {
                    self::$lastUpdateMessage = 'A variables file is damaged and a copy of it could not be made, so nothing was saved. Check the disk, then retry.';
                    return;
                }
                self::$lastUpdateNotice = 'A variables file was damaged and could not be read. It is kept as ' . $copy . ' next to it. Your change is saved in a new file. To get back an older value, open the copy in an editor and enter the value again.';
                LogService::log('Env store: the existing file could not be parsed; a copy was kept at ' . $copy, LogService::LOG_WARN, 'EnvService');
            }
            if (empty($next)) {
                if (file_exists($file)) @unlink($file);
                $ok = true;
                return;
            }
            $ok = AtomicWriteService::writeJson($file, $next);
            if (!$ok) self::$lastUpdateMessage = 'The variables could not be written.';
        }, $busy);
        return !$busy && $ok;
    }

    // ---------- Workspace-tier (general) — per-key delta over agent ----------

    public static function getWorkspaceEnvs(string $path, string $agentId): array {
        return self::readJsonMap(self::getWorkspaceEnvPath($path, $agentId));
    }

    /**
     * Save the workspace-tier general envs as a per-key delta against the
     * agent tier. Keys whose value matches the agent value are omitted (no
     * point persisting "this workspace agrees with the default"). Empty delta
     * → file unlinked (zero workspace overrides → no file). Runs under the env lock (#890);
     * a caller that starts from the stored map uses updateWorkspaceEnvs().
     */
    public static function saveWorkspaceEnvs(string $path, string $agentId, array $map): bool {
        return self::updateWorkspaceEnvs($path, $agentId, static fn(array $cur) => $map);
    }

    // ---------- Effective merge (the launch-time source of truth) ----------

    /**
     * Build the effective env map for (path, agentId). Tier order — later
     * wins per key:
     *   1. agent['source']['env']                — registry/agents.json defaults
     *   2. SecretService::getAgentSecrets()      — global agent vault
     *   3. SecretService::getWorkspaceSecrets()  — per-workspace secrets
     *   4. EnvService::getAgentEnvs()            — general agent vars
     *   5. EnvService::getWorkspaceEnvs()        — general workspace vars (delta)
     * Net: workspace beats agent across the board; within a scope, general
     * (more recent free-form edit) beats secret. Workspace path may be null
     * for agent-only contexts (e.g. install-time seed validation).
     */
    public static function buildEffectiveEnv(?string $path, string $agentId): array {
        $env = [];

        $registry = AgentRegistry::getRegistry();
        $agent    = $registry[$agentId] ?? null;

        // Tier 1: source defaults
        if (is_array($agent) && !empty($agent['source']['env']) && is_array($agent['source']['env'])) {
            foreach ($agent['source']['env'] as $k => $v) {
                $env[(string)$k] = (string)$v;
            }
        }

        // Tier 2: agent secrets vault (global flat secrets.cfg)
        foreach (SecretService::getAgentSecrets() as $k => $v) {
            // RELAY_LINKED_BOXES.md §7: Relay credentials (remote bearer tokens,
            // linked-box link keys, the one-time pairing secret) share this
            // vault, but no agent needs them. An agent reads untrusted DM text,
            // so it must never hold a key that lets it sign as this box.
            if (strpos((string)$k, self::RELAY_SECRET_PREFIX) === 0) continue;
            $env[$k] = $v;
        }

        // Tier 3 + 5: workspace tiers — only when we have a workspace
        if ($path !== null && $path !== '') {
            foreach (SecretService::getWorkspaceSecrets($path, $agentId) as $k => $v) {
                $env[$k] = $v;
            }
        }

        // Tier 4: agent general
        foreach (self::getAgentEnvs($agentId) as $k => $v) {
            $env[$k] = (string)$v;
        }

        // Tier 5: workspace general (delta over agent)
        if ($path !== null && $path !== '') {
            foreach (self::getWorkspaceEnvs($path, $agentId) as $k => $v) {
                $env[$k] = (string)$v;
            }
        }

        return $env;
    }

    /**
     * The env an agent process gets at launch: the effective merge plus launch-only
     * additions that the Settings page must not show as inherited values.
     * OpenCode reads BOTH ~/.claude/skills and ~/.agents/skills; the addition makes it
     * list each skill at one path only (docs/specs/OPENCODE_SKILL_SINGLE_PATH.md).
     */
    public static function buildLaunchEnv(?string $path, string $agentId): array {
        $env = self::buildEffectiveEnv($path, $agentId);
        if ($agentId === 'opencode') {
            $env = array_merge($env, self::opencodeSkillScanEnv(dirname(ConfigService::getUserStatePath()), $env));
        }
        return $env;
    }

    /**
     * Env additions that make OpenCode list each skill at ONE path.
     *
     * OpenCode scans ~/.claude/skills and ~/.agents/skills, puts all matches in one
     * list and loads them with unbounded concurrency; for a duplicate name the last
     * parse to finish wins. The Config Hub projects the aicli-* skills into both
     * folders, so the <location> line of the system prompt changed from session to
     * session and a local model could not reuse its prompt cache (#741).
     *
     * Fix: switch off only OpenCode's ~/.claude scan (OPENCODE_DISABLE_CLAUDE_CODE_SKILLS)
     * and give it every ~/.claude/skills entry that is NOT also in ~/.agents/skills
     * through `skills.paths` (OPENCODE_CONFIG_CONTENT). The personal skills keep loading.
     * A project's .claude/skills is added as a relative path (no walk-up to parents).
     *
     * Does nothing when the user already chose: any of the three OpenCode skill
     * switches set, or OPENCODE_CONFIG_CONTENT set.
     *
     * @param array<string,string> $env effective env so far
     * @return array<string,string> keys to add (empty = leave OpenCode as it is)
     */
    public static function opencodeSkillScanEnv(string $home, array $env): array {
        foreach (['OPENCODE_DISABLE_CLAUDE_CODE', 'OPENCODE_DISABLE_CLAUDE_CODE_SKILLS', 'OPENCODE_DISABLE_EXTERNAL_SKILLS'] as $k) {
            $v = strtolower(trim((string)($env[$k] ?? '')));
            if ($v !== '' && $v !== '0' && $v !== 'false') return [];
        }
        if (trim((string)($env['OPENCODE_CONFIG_CONTENT'] ?? '')) !== '') return [];

        $home = rtrim($home, '/');
        $claudeDir = $home . '/.claude/skills';
        $sharedDir = $home . '/.agents/skills';
        $paths = [];
        $names = is_dir($claudeDir) ? (@scandir($claudeDir) ?: []) : [];
        sort($names, SORT_STRING);
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') continue;
            if (!is_dir($claudeDir . '/' . $name)) continue;
            if (is_dir($sharedDir . '/' . $name)) continue; // OpenCode still reads this one from ~/.agents/skills
            $paths[] = $claudeDir . '/' . $name;
        }
        $paths[] = '.claude/skills';

        $json = json_encode(['skills' => ['paths' => $paths]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) return [];
        return [
            'OPENCODE_DISABLE_CLAUDE_CODE_SKILLS' => 'true',
            'OPENCODE_CONFIG_CONTENT'             => $json,
        ];
    }

    // ---------- Seed (manifest-driven defaults; additive + sidecar-tracked) ----------

    /**
     * For a single agent, ensure its `default_envs` manifest entries are
     * present in the user's agent-env file. Skips any key the user already
     * has set, and any key the sidecar records as previously-seeded (= the
     * user may have deleted it; we don't resurrect). Reserved-name collisions
     * fail loud — a `default_envs` value should never use a name our runtime
     * manages, so a typo gets caught early.
     *
     * Returns ['seeded' => [...], 'skipped' => [...], 'rejected' => [...]].
     */
    public static function seedAgentDefaults(string $agentId, ?array $defaultsOverride = null): array {
        // $defaultsOverride lets tests exercise the seed algorithm without
        // depending on a shipped manifest default (there are currently none —
        // the GEMINI_CLI_ENABLE_AUTO_UPDATE default was removed 2026-05-11).
        // Production callers (InstallerService, PLG INLINE) pass nothing →
        // read from the agent registry.
        if ($defaultsOverride !== null) {
            $defaults = $defaultsOverride;
        } else {
            $registry = AgentRegistry::getRegistry();
            $agent    = $registry[$agentId] ?? null;
            $defaults = is_array($agent) ? ($agent['default_envs'] ?? []) : [];
        }
        if (!is_array($defaults) || empty($defaults)) {
            return ['seeded' => [], 'skipped' => [], 'rejected' => []];
        }

        // #890: read the user's envs and write the seeded ones under the env lock. A busy lock
        // skips this seed; the next upgrade seeds again (nothing is lost).
        $busy = false;
        $res = self::withLock(static fn() => self::seedLocked($agentId, $defaults), $busy);
        return $busy ? ['seeded' => [], 'skipped' => [], 'rejected' => []] : $res;
    }

    private static function seedLocked(string $agentId, array $defaults): array {
        $userEnvs = self::getAgentEnvs($agentId);
        $sidecar  = self::readSeededSidecar($agentId);
        $seeded   = [];
        $skipped  = [];
        $rejected = [];

        foreach ($defaults as $k => $v) {
            $k = (string)$k;
            if (!self::validateKey($k)) {
                $rejected[] = [$k, 'invalid or reserved key (default_envs cannot ship a name the runtime manages)'];
                LogService::log("seed: rejected '$k' for $agentId — invalid/reserved", LogService::LOG_ERROR, 'EnvService');
                continue;
            }
            if (array_key_exists($k, $userEnvs)) {
                $skipped[] = [$k, 'user value present'];
                continue;
            }
            if (in_array($k, $sidecar, true)) {
                $skipped[] = [$k, 'previously seeded — user may have deleted'];
                continue;
            }
            $userEnvs[$k] = self::sanitiseValue((string)$v);
            $sidecar[]    = $k;
            $seeded[]     = $k;
        }

        if (!empty($seeded)) {
            self::saveAgentEnvs($agentId, $userEnvs);
            self::writeSeededSidecar($agentId, $sidecar);
            LogService::log("seed: $agentId — seeded " . implode(',', $seeded), LogService::LOG_INFO, 'EnvService');
        }

        return ['seeded' => $seeded, 'skipped' => $skipped, 'rejected' => $rejected];
    }

    /**
     * Walk the registry; for every installed agent, run seedAgentDefaults.
     * Called from the PLG INLINE upgrade block alongside recoverMissingVersions.
     */
    public static function seedAllInstalledAgentDefaults(): array {
        $registry = AgentRegistry::getRegistry();
        $summary  = ['agents' => 0, 'seeded_total' => 0, 'agents_touched' => []];
        foreach ($registry as $id => $agent) {
            if (empty($agent['is_installed'])) continue;
            $r = self::seedAgentDefaults($id);
            $summary['agents']++;
            if (!empty($r['seeded'])) {
                $summary['seeded_total'] += count($r['seeded']);
                $summary['agents_touched'][] = $id;
            }
        }
        return $summary;
    }

    // ---------- Internal ----------

    private static function readJsonMap(string $file): array {
        if (!file_exists($file)) return [];
        $raw = @file_get_contents($file);
        if ($raw === false) return [];
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function readSeededSidecar(string $agentId): array {
        $file = self::getSeededSidecarPath($agentId);
        if (!file_exists($file)) return [];
        $decoded = json_decode((string)@file_get_contents($file), true);
        if (!is_array($decoded)) return [];
        // Sidecar stores a flat list of key names. Defensive: tolerate the older
        // {keys: [...]} shape too in case we evolve it.
        if (isset($decoded[self::SIDECAR_KEYS_LIST]) && is_array($decoded[self::SIDECAR_KEYS_LIST])) {
            return array_values($decoded[self::SIDECAR_KEYS_LIST]);
        }
        return array_values($decoded);
    }

    private static function writeSeededSidecar(string $agentId, array $keys): bool {
        return AtomicWriteService::writeJson(self::getSeededSidecarPath($agentId), array_values(array_unique($keys)));
    }
}
